<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\ApprovalNotificationResolver;
use App\Services\NotificationService;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use ReflectionProperty;
use Tests\TestCase;

/**
 * "Needs approval" bell items leave once nobody needs this recipient's action:
 * the request left its pending state, or the level the ping was for was acted on.
 */
class ApprovalNotificationResolverTest extends TestCase
{
    use RefreshDatabase;

    private User $requester;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Manila'));
        $this->requester = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CompanyContext::flushMemo();

        parent::tearDown();
    }

    public function test_pos_ping_for_an_acted_level_leaves_while_the_next_level_stays(): void
    {
        $levelOne = User::factory()->create();
        $levelTwo = User::factory()->create();
        $request = $this->posRequest(currentLevel: 2, status: 'Approved Level 1');
        DB::table('pos_request_approvals')->insert([
            'pos_request_id' => $request, 'user_id' => $levelOne->id, 'level' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->ping('pos_request', $request, [$levelOne->id], 1);
        // Written before `level` was stored: the level is read from the message.
        $this->ping('pos_request', $request, [$levelOne->id], null, "POS Request #{$request} is awaiting your approval (Stage 1).");
        $this->ping('pos_request', $request, [$levelTwo->id], 2);

        $this->assertBell($levelOne, 0, 0);
        $this->assertBell($levelTwo, 1, 1);
    }

    public function test_every_pending_ping_leaves_once_the_request_is_decided(): void
    {
        $approver = User::factory()->create();
        $rejected = $this->posRequest(currentLevel: 0, status: 'Rejected');
        $open = $this->posRequest(currentLevel: 1, status: 'Open');

        $this->ping('pos_request', $rejected, [$approver->id], 1);
        $this->ping('pos_request', $open, [$approver->id], 1);

        $this->assertBell($approver, 1, 1);
    }

    public function test_payment_ping_leaves_once_its_level_is_approved(): void
    {
        $approver = User::factory()->create();
        $vendor = DB::table('vendors')->insertGetId(['name' => 'Telco', 'created_at' => now(), 'updated_at' => now()]);
        $record = DB::table('payment_records')->insertGetId([
            'payable_type' => 'invoice', 'payable_id' => 1, 'vendor_id' => $vendor, 'amount' => 100,
            'status' => 'pending', 'current_approval_level' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('payment_record_approvals')->insert([
            'payment_record_id' => $record, 'user_id' => $approver->id, 'level' => 1, 'action' => 'approved',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // The shared pool is pinged per level: level 1 is done, level 2 awaits.
        $this->ping('payment_record', $record, [$approver->id], 1);
        $this->ping('payment_record', $record, [$approver->id], 2);

        $this->assertBell($approver, 1, 1);
    }

    public function test_trip_ping_leaves_once_the_trip_is_no_longer_pending_approval(): void
    {
        $approver = User::factory()->create();
        $vehicle = DB::table('service_vehicles')->insertGetId(['name' => 'Van', 'plate_no' => 'ABC-123', 'created_at' => now(), 'updated_at' => now()]);
        $trip = fn (string $status) => DB::table('service_vehicle_trips')->insertGetId([
            'service_vehicle_id' => $vehicle, 'driver_id' => $this->requester->id, 'date_used' => '2026-07-07',
            'purpose_of_travel' => 'Delivery', 'start_point' => 'HQ', 'end_point' => 'Store',
            'planned_departure_time' => '08:00', 'planned_arrival_time' => '10:00', 'status' => $status,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->ping('service_vehicle_trip', $trip('Cancelled'), [$approver->id]);
        $this->ping('service_vehicle_trip', $trip('Pending Approval'), [$approver->id]);

        $this->assertBell($approver, 1, 1);
    }

    public function test_a_request_in_another_entity_is_not_mistaken_for_gone(): void
    {
        $approver = User::factory()->create();
        $request = $this->posRequest(currentLevel: 1, status: 'Open');
        $this->ping('pos_request', $request, [$approver->id], 1);

        // The approver is viewing a different entity than the request's.
        $otherEntity = DB::table('companies')->insertGetId(['name' => 'Other', 'code' => 'OTH', 'created_at' => now(), 'updated_at' => now()]);
        (new ReflectionProperty(CompanyContext::class, 'activeIdMemo'))->setValue(null, [$approver->id => $otherEntity]);
        $this->actingAs($approver);

        $this->assertCount(0, app(ApprovalNotificationResolver::class)->resolved($approver->notifications()->get()));
    }

    public function test_google_registration_ping_leaves_once_the_user_is_given_a_role(): void
    {
        $admin = User::factory()->create();
        $approved = User::factory()->create(['google_id' => 'g-approved', 'is_active' => true]);
        $approved->assignRole(Role::firstOrCreate(['name' => 'Employee', 'guard_name' => 'web']));
        $waiting = User::factory()->create(['google_id' => 'g-waiting', 'is_active' => false]);

        $this->ping('user_registration', $approved->id, [$admin->id]);
        $this->ping('user_registration', $waiting->id, [$admin->id]);

        $this->assertBell($admin, 1, 1);
    }

    public function test_qat_ping_leaves_once_signed_off_and_an_earlier_rounds_ping_stays_gone_on_resubmission(): void
    {
        $manager = User::factory()->create();
        $cycle = fn (string $code, string $status, ?Carbon $submittedAt) => DB::table('qat_cycles')->insertGetId([
            'code' => $code, 'title' => "Cycle {$code}", 'status' => $status, 'submitted_at' => $submittedAt,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $signedOff = $cycle('QAT-1', 'signed_off', now());
        $this->ping('qat_cycle', $signedOff, [$manager->id]);

        // Returned yesterday, resubmitted today under the same id.
        Carbon::setTestNow(now()->subDay());
        $resubmitted = $cycle('QAT-2', 'for_approval', now());
        $this->ping('qat_cycle', $resubmitted, [$manager->id]);
        Carbon::setTestNow(now()->addDay());
        DB::table('qat_cycles')->where('id', $resubmitted)->update(['submitted_at' => now()]);
        $this->ping('qat_cycle', $resubmitted, [$manager->id]);

        $this->assertBell($manager, 1, 1);
    }

    public function test_accounting_review_ping_leaves_once_decided_including_the_legacy_format(): void
    {
        $reviewer = User::factory()->create();
        $review = fn (string $key, string $status) => DB::table('acct_document_reviews')->insertGetId([
            'idempotency_key' => $key, 'source_document_id' => crc32($key), 'source_reference_no' => $key, 'document_type' => 'invoice',
            'status' => $status, 'received_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $decided = $review('lp-doc-1-s1', 'approved');
        $open = $review('lp-doc-2-s1', 'in_review');

        // Legacy shape: event `received`, subject "Document …", id only in the link.
        app(NotificationService::class)->dispatch([$reviewer->id], null, [
            'domain' => 'approval', 'event' => 'received', 'title' => 'Vendor document for review',
            'message' => 'Acme: INV-1 (invoice)', 'subject' => 'Document INV-1', 'url' => "/accounting-documents/{$decided}",
        ]);
        $this->ping('acct_document_review', $open, [$reviewer->id]);

        $this->assertBell($reviewer, 1, 1);
    }

    private function posRequest(int $currentLevel, string $status): int
    {
        $company = DB::table('companies')->insertGetId(['name' => 'Entity', 'code' => 'E' . uniqid(), 'created_at' => now(), 'updated_at' => now()]);
        $type = DB::table('request_types')->insertGetId(['code' => 'RT' . uniqid(), 'name' => 'Price Change', 'request_for' => 'POS', 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('pos_requests')->insertGetId([
            'company_id' => $company, 'request_type_id' => $type, 'user_id' => $this->requester->id,
            'launch_date' => '2026-07-10', 'effectivity_date' => '2026-07-10', 'stores_covered' => '[]',
            'status' => $status, 'current_approval_level' => $currentLevel,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function ping(string $workflow, int $id, array $recipients, ?int $level = null, string $message = 'Awaiting your approval.'): void
    {
        app(NotificationService::class)->notifyApproval(
            $recipients, $this->requester->id, 'pending', 'Approval needed', $message,
            '/approvals', "{$workflow}:{$id}", 'warning', $level
        );
    }

    private function assertBell(User $user, int $expectedUnread, int $expectedListed): void
    {
        $response = $this->actingAs($user)->getJson(route('notifications.summary'))->assertOk();

        $this->assertSame($expectedUnread, $response->json('unread'));
        $this->assertCount($expectedListed, $response->json('notifications'));
    }
}
