<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\AccountArchiveService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Stage 2 of a member's account deletion, run nightly by
 * `accounts:purge-expired`: archived loyalty customers past the retention
 * window are purged, or anonymized when they hold financial records.
 *
 * No archived row is created here. The command is driven against a faked
 * service, the lookup's shape is captured without executing it, and the
 * purge's reference cleanup is exercised on an active member.
 */
class PurgeExpiredAccountsTest extends TestCase
{
    use RefreshDatabase;

    private function customer(int $id, string $name): Customer
    {
        $customer = new Customer(['name' => $name]);
        $customer->id = $id;

        return $customer;
    }

    /**
     * @param  array<int, Customer>  $expired
     * @param  array<int, int>  $financialIds
     * @param  array<int, User>  $logins  keyed by customer id
     */
    private function service(array $expired, array $financialIds = [], array $logins = [], ?\Closure $extra = null): void
    {
        $this->mock(AccountArchiveService::class, function (MockInterface $mock) use ($expired, $financialIds, $logins, $extra) {
            $mock->shouldReceive('retention')->andReturn([
                'value' => 1, 'unit' => 'months', 'label' => '1 month', 'cutoff' => now('Asia/Manila')->subMonth(),
            ]);
            $mock->shouldReceive('expiredArchivedCustomers')->once()->andReturn(new Collection($expired));
            $mock->shouldReceive('userFor')->andReturnUsing(fn (Customer $c) => $logins[$c->id] ?? null);
            $mock->shouldReceive('holdsFinancialRecords')->andReturnUsing(fn (Customer $c) => in_array($c->id, $financialIds, true));

            if ($extra) {
                $extra($mock);
            }
        });
    }

    public function test_purges_plain_accounts_anonymizes_financial_ones_and_skips_an_active_login(): void
    {
        $activeLogin = new User(['name' => 'Restored', 'email' => 'restored@example.test']);

        $this->service(
            [$this->customer(1, 'Plain'), $this->customer(2, 'Redeemer'), $this->customer(3, 'Out of step')],
            financialIds: [2],
            logins: [3 => $activeLogin],
            extra: function (MockInterface $mock) {
                $mock->shouldReceive('purgeCustomer')->once()->withArgs(fn ($c, $actor) => $c->id === 1 && $actor === null);
                $mock->shouldReceive('anonymizeCustomer')->once()->withArgs(fn ($c, $actor) => $c->id === 2 && $actor === null);
            },
        );

        $this->artisan('accounts:purge-expired')
            ->expectsOutputToContain('Purged #1 Plain')
            ->expectsOutputToContain('Anonymized #2 Redeemer')
            ->expectsOutputToContain('Skipped #3 Out of step: its app login is still active')
            ->expectsOutputToContain('Purged 1, anonymized 1, skipped 1, failed 0')
            ->assertSuccessful();
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $this->service(
            [$this->customer(1, 'Plain'), $this->customer(2, 'Redeemer')],
            financialIds: [2],
            extra: function (MockInterface $mock) {
                $mock->shouldNotReceive('purgeCustomer');
                $mock->shouldNotReceive('anonymizeCustomer');
            },
        );

        $this->artisan('accounts:purge-expired', ['--dry-run' => true])
            ->expectsOutputToContain('Would purge #1 Plain')
            ->expectsOutputToContain('Would anonymize #2 Redeemer')
            ->assertSuccessful();
    }

    public function test_one_failure_does_not_stop_the_rest_and_fails_the_run(): void
    {
        $this->service(
            [$this->customer(1, 'Blocked'), $this->customer(2, 'Redeemer')],
            financialIds: [2],
            extra: function (MockInterface $mock) {
                $mock->shouldReceive('purgeCustomer')->once()
                    ->andThrow(ValidationException::withMessages(['purge' => 'Still referenced.']));
                $mock->shouldReceive('anonymizeCustomer')->once();
            },
        );

        $this->artisan('accounts:purge-expired')
            ->expectsOutputToContain('Could not purge #1 Blocked: Still referenced.')
            ->expectsOutputToContain('Anonymized #2 Redeemer')
            ->assertFailed();
    }

    public function test_the_lookup_takes_only_archived_unanonymized_rows_past_the_cutoff(): void
    {
        // Captured, not executed; pretend mode inlines the bindings.
        $queries = DB::pretend(fn () => app(AccountArchiveService::class)
            ->expiredArchivedCustomers(now()->setDate(2026, 8, 28)->setTime(9, 0)));

        $sql = $queries[0]['query'];
        $this->assertStringContainsString('"customers"."deleted_at" is not null', $sql);
        $this->assertStringContainsString('"anonymized_at" is null', $sql);
        $this->assertStringContainsString('"deleted_at" <= \'2026-08-28 09:00:00\'', $sql);
    }

    public function test_purging_a_self_registered_member_is_not_blocked_by_their_own_customer_row(): void
    {
        // Api\RegisterController makes the member the creator of their own
        // customer row, and the blocker scan used to refuse the purge over it.
        // Run on an ACTIVE pair: the login is removed for good, the customer
        // (not archived) is left alone, and nothing is soft-deleted.
        $customer = Customer::create(['name' => 'Member', 'email' => 'member@example.test', 'is_active' => true]);
        $member = User::factory()->create(['email' => 'member@example.test', 'customer_id' => $customer->id]);
        $member->forceFill(['created_by' => $member->id, 'updated_by' => $member->id])->save();
        $customer->forceFill(['created_by' => $member->id, 'updated_by' => $member->id])->save();

        app(AccountArchiveService::class)->purgeUser($member, null);

        $this->assertNull(User::withTrashed()->find($member->id));
        $customer->refresh();
        $this->assertNull($customer->created_by);
        $this->assertNull($customer->updated_by);
    }

    public function test_a_members_spent_sign_in_codes_do_not_block_the_purge(): void
    {
        // Every app sign-in leaves a code in `otp_codes`; the scan counted it
        // and refused the purge of any member who had ever signed in. Run on
        // an ACTIVE pair, as above, so nothing is soft-deleted.
        $customer = Customer::create(['name' => 'Member', 'email' => 'member@example.test', 'is_active' => true]);
        $member = User::factory()->create(['email' => 'member@example.test', 'customer_id' => $customer->id]);
        OtpCode::create([
            'user_id' => $member->id, 'purpose' => OtpCode::PURPOSE_LOGIN, 'code_hash' => 'x',
            'attempts' => 0, 'expires_at' => now()->addMinutes(10), 'consumed_at' => now(),
        ]);

        app(AccountArchiveService::class)->purgeUser($member, null);

        $this->assertNull(User::withTrashed()->find($member->id));
        $this->assertSame(0, OtpCode::where('user_id', $member->id)->count());
    }

    public function test_a_customer_someone_else_created_still_blocks_the_purge(): void
    {
        // Only the member's OWN row is cleared; anything else they created is
        // still reported, exactly as it is for a staff account.
        $own = Customer::create(['name' => 'Member', 'email' => 'member@example.test', 'is_active' => true]);
        $member = User::factory()->create(['email' => 'member@example.test', 'customer_id' => $own->id]);
        $other = Customer::create(['name' => 'Walk-in', 'is_active' => true]);
        $other->forceFill(['created_by' => $member->id])->save();

        $this->expectException(ValidationException::class);

        try {
            app(AccountArchiveService::class)->purgeUser($member, null);
        } finally {
            $this->assertNotNull(User::find($member->id));
        }
    }

    public function test_anonymize_refuses_a_customer_that_is_not_archived(): void
    {
        $customer = Customer::create(['name' => 'Active', 'email' => 'active@example.test', 'phone' => '0917', 'is_active' => true]);

        try {
            app(AccountArchiveService::class)->anonymizeCustomer($customer, null);
            $this->fail('An active customer must not be anonymized.');
        } catch (ValidationException) {
            $customer->refresh();
            $this->assertSame('Active', $customer->name);
            $this->assertSame('active@example.test', $customer->email);
            $this->assertNull($customer->anonymized_at);
        }
    }
}
