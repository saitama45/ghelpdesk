<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Ticket;
use App\Models\User;
use App\Support\UserDeletionBlockers;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Deleting a user used to reach the admin as a raw SQLSTATE[23000] page naming a
 * foreign key. It now names the records that are in the way, and where to go and
 * deal with them.
 *
 * Note on the delete itself: `users` is NOT soft-deleting, and these run against
 * the isolated sqlite :memory: connection forced by phpunit.xml.
 */
class UserDeletionBlockersTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->givePermissionTo(
            Permission::findOrCreate('users.view', 'web'),
            Permission::findOrCreate('users.delete', 'web'),
        );

        return $admin;
    }

    public function test_a_customer_created_by_the_user_is_reported_as_the_blocker(): void
    {
        $user = User::factory()->create(['name' => 'Ailene Estella']);
        Customer::create(['name' => 'Walk-in Guest', 'created_by' => $user->id]);

        $blockers = app(UserDeletionBlockers::class);
        $found = $blockers->for($user);

        $this->assertNotEmpty($found, 'The customers.created_by reference should be detected.');
        $this->assertContains('customers', array_column($found, 'table'));

        $message = $blockers->message($user, $found);
        $this->assertStringContainsString('Ailene Estella', $message);
        $this->assertStringContainsString('1 in Customers', $message);
        $this->assertStringContainsString('Stamps → Customers tab', $message);
    }

    public function test_the_delete_request_is_refused_with_that_message_and_keeps_the_user(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create(['name' => 'Ailene Estella']);
        Customer::create(['name' => 'Walk-in Guest', 'created_by' => $user->id]);

        $response = $this->actingAs($admin)
            ->from(route('users.index'))
            ->delete(route('users.destroy', $user->id));

        $response->assertSessionHasErrors('user');
        $this->assertStringContainsString(
            'Stamps → Customers tab',
            (string) session('errors')->first('user')
        );

        // The whole transaction rolls back: the account is untouched, and so is the
        // cleanup the method had already applied on its way to the blocker.
        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'Ailene Estella']);
        $this->assertDatabaseHas('customers', ['created_by' => $user->id]);
    }

    public function test_counts_are_grouped_per_table_not_per_column(): void
    {
        // "8 in Customers" is what a person can act on; "4 in customers.created_by,
        // 4 in customers.updated_by" is just the schema talking.
        $user = User::factory()->create();

        Customer::create(['name' => 'A', 'created_by' => $user->id]);
        Customer::create(['name' => 'B', 'created_by' => $user->id]);
        Customer::create(['name' => 'C', 'updated_by' => $user->id]);

        $found = collect(app(UserDeletionBlockers::class)->for($user))->keyBy('table');

        $this->assertSame(3, $found['customers']['count']);
        $this->assertSame(['created_by', 'updated_by'], $found['customers']['columns']);
    }

    public function test_the_message_names_the_biggest_areas_and_counts_the_rest(): void
    {
        // An account can be referenced from dozens of tables; a toast holding
        // seventy clauses tells the reader nothing.
        $user = User::factory()->create(['name' => 'Ailene Estella']);

        $blockers = [];
        foreach (['customers', 'stamp_cards', 'holidays', 'wigs_pcf', 'otp_codes'] as $i => $table) {
            $blockers[] = [
                'table' => $table,
                'columns' => ['created_by'],
                'count' => 50 - $i,
                'label' => $table === 'customers' ? 'Customers' : ucfirst($table),
                'location' => $table === 'customers' ? 'Stamps → Customers tab' : null,
            ];
        }

        $message = app(UserDeletionBlockers::class)->message($user, $blockers);

        $this->assertStringContainsString('50 in Customers — see Stamps → Customers tab', $message);
        $this->assertStringContainsString('and 1 other area', $message);
        $this->assertStringNotContainsString('Otp_codes', $message);
    }

    public function test_an_area_the_admin_can_act_on_outranks_a_bigger_one_they_cannot(): void
    {
        // Thousands of rows in a table the admin has no screen for must not push
        // "Customers — see Stamps → Customers tab" out of the message.
        $user = User::factory()->create(['name' => 'Ailene Estella']);

        Customer::create(['name' => 'Walk-in Guest', 'created_by' => $user->id]);

        foreach (range(1, 5) as $i) {
            DB::table('user_presence_logs')->insert([
                'user_id' => $user->id,
                'status' => 'online',
                'started_at' => now(),
                'duration_seconds' => 60,
            ]);
        }

        $found = app(UserDeletionBlockers::class)->for($user);

        $this->assertSame(5, collect($found)->firstWhere('table', 'user_presence_logs')['count']);
        $this->assertSame('customers', $found[0]['table'], 'The actionable area comes first, despite the smaller count.');
    }

    public function test_references_the_delete_already_clears_do_not_block_it(): void
    {
        // The scan runs after the cleanup, so a user whose only references are the
        // ones `destroy()` nulls out must still delete. Guarding the wrong way round
        // here would refuse deletions that work today.
        $admin = $this->admin();
        $user = User::factory()->create();

        Ticket::create([
            'title' => 'Terminal offline',
            'description' => 'Cannot connect.',
            'type' => 'task',
            'status' => 'open',
            'priority' => 'medium',
            'severity' => 'minor',
            'assignee_id' => $user->id,
        ]);

        $this->actingAs($admin)
            ->from(route('users.index'))
            ->delete(route('users.destroy', $user->id))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_an_unmapped_table_still_names_itself(): void
    {
        $user = User::factory()->create(['name' => 'Ailene Estella']);

        $message = app(UserDeletionBlockers::class)->message($user, [[
            'table' => 'wigs_pcf_entries',
            'columns' => ['created_by'],
            'count' => 4,
            'label' => 'Wigs Pcf Entries',
            'location' => null,
        ]]);

        $this->assertStringContainsString('4 in Wigs Pcf Entries', $message);
        $this->assertStringNotContainsString('see ', $message);
    }

    public function test_a_sql_server_constraint_error_is_translated_rather_than_shown_raw(): void
    {
        $user = User::factory()->create(['name' => 'Ailene Estella']);

        $exception = new QueryException(
            'sqlsrv',
            'delete from [users] where [id] = 250',
            [],
            new \RuntimeException('SQLSTATE[23000]: [Microsoft][ODBC Driver 17 for SQL Server][SQL Server]'
                .'The DELETE statement conflicted with the REFERENCE constraint "customers_created_by_foreign". '
                .'The conflict occurred in database "tashelpdeskdb", table "dbo.customers", column \'created_by\'.')
        );

        $message = app(UserDeletionBlockers::class)->messageFromException($user, $exception);

        $this->assertStringContainsString('Ailene Estella', $message);
        $this->assertStringContainsString('Customers', $message);
        $this->assertStringContainsString('Stamps → Customers tab', $message);
        $this->assertStringNotContainsString('SQLSTATE', $message);
        $this->assertStringNotContainsString('customers_created_by_foreign', $message);
    }
}
