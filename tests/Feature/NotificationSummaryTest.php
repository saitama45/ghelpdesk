<?php

namespace Tests\Feature;

use App\Models\Schedule;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class NotificationSummaryTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = Carbon::parse('2026-07-06 10:00:00', 'Asia/Manila');
        Carbon::setTestNow($this->now);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_attendance_reminders_identify_staff_and_missing_punch_days(): void
    {
        $manager = User::factory()->create([
            'name' => 'Morgan Manager',
            'is_manager' => true,
        ]);
        $alice = User::factory()->create(['name' => 'Alice Cruz']);
        $bob = User::factory()->create(['name' => 'Bob Lee']);
        $carol = User::factory()->create(['name' => 'Carol Tan']);
        $outsider = User::factory()->create(['name' => 'Outside User']);

        foreach ([$alice, $bob, $carol] as $staff) {
            $staff->managers()->attach($manager->id);
        }

        $this->schedule($manager, '2026-07-06 00:00:00', '2026-07-06 23:59:59', 'Holiday');
        $this->schedule($bob, '2026-07-05 08:00:00', '2026-07-06 17:00:00');
        $this->schedule($carol, '2026-07-06 08:00:00', '2026-07-06 17:00:00');

        $response = $this->actingAs($manager)
            ->getJson(route('notifications.summary'))
            ->assertOk();

        $reminders = collect($response->json('reminders'))->keyBy('type');

        $this->assertSame('No schedule today: Alice Cruz.', $reminders['missing_schedule']['message']);
        $this->assertSame(1, $reminders['missing_schedule']['count']);
        $this->assertSame(
            'No time-in: Bob Lee (Yesterday), Bob Lee (Today), Carol Tan (Today).',
            $reminders['missing_time_in']['message']
        );
        $this->assertSame(3, $reminders['missing_time_in']['count']);
        $this->assertSame('No time-out yesterday: Bob Lee.', $reminders['missing_time_out']['message']);
        $this->assertSame(1, $reminders['missing_time_out']['count']);
        $this->assertStringNotContainsString($outsider->name, $response->getContent());
    }

    public function test_non_manager_schedule_reminder_identifies_only_the_authenticated_user(): void
    {
        $user = User::factory()->create(['name' => 'Solo Staff']);
        $subordinate = User::factory()->create(['name' => 'Attached Staff']);
        $subordinate->managers()->attach($user->id);

        $response = $this->actingAs($user)
            ->getJson(route('notifications.summary'))
            ->assertOk();

        $reminder = collect($response->json('reminders'))->firstWhere('type', 'missing_schedule');

        $this->assertSame('No schedule today: Solo Staff.', $reminder['message']);
        $this->assertSame(1, $reminder['count']);
    }

    public function test_sla_reminders_list_every_in_scope_ticket_key(): void
    {
        $user = User::factory()->create();
        $outsider = User::factory()->create();

        $this->ticketWithSla('GH-1002', $user, $this->now->copy()->subHours(2));
        $this->ticketWithSla('GH-1001', $user, $this->now->copy()->subHour(), true);
        $this->ticketWithSla('GH-1003', $user, $this->now->copy()->addHours(12));
        $this->ticketWithSla('GH-1005', $user, $this->now->copy()->addHours(36));
        $this->ticketWithSla('GH-1004', $user, $this->now->copy()->addHours(30));

        $this->ticketWithSla('GH-2001', $outsider, $this->now->copy()->subHour());
        $this->ticketWithSla('GH-2002', $user, $this->now->copy()->addHours(6), false, 'closed');
        $this->ticketWithSla('GH-2003', $user, $this->now->copy()->addHours(6), false, 'open', $this->now);

        $response = $this->actingAs($user)
            ->getJson(route('notifications.summary'))
            ->assertOk();

        $reminders = collect($response->json('reminders'))->keyBy('type');

        $this->assertSame('Past due SLA: GH-1001, GH-1002.', $reminders['sla_breached']['message']);
        $this->assertSame(2, $reminders['sla_breached']['count']);
        $this->assertSame('GH-1001,GH-1002', $reminders['sla_breached']['params']['ticket_keys']);
        $this->assertSame(['all'], $reminders['sla_breached']['params']['status']);
        $this->assertSame('all', $reminders['sla_breached']['params']['ticket_scope']);
        $this->assertSame('Due within 24 hours: GH-1003.', $reminders['sla_due_1d']['message']);
        $this->assertSame(1, $reminders['sla_due_1d']['count']);
        $this->assertSame('GH-1003', $reminders['sla_due_1d']['params']['ticket_keys']);
        $this->assertSame('Due within 2 days: GH-1004, GH-1005.', $reminders['sla_due_2d']['message']);
        $this->assertSame(2, $reminders['sla_due_2d']['count']);
        $this->assertSame('GH-1004,GH-1005', $reminders['sla_due_2d']['params']['ticket_keys']);
        $this->assertStringNotContainsString('GH-200', $response->getContent());
    }

    private function schedule(
        User $user,
        string $start,
        string $end,
        string $status = 'WFH'
    ): Schedule {
        return Schedule::create([
            'user_id' => $user->id,
            'created_by' => $user->id,
            'updated_by' => $user->id,
            'status' => $status,
            'start_time' => $start,
            'end_time' => $end,
        ]);
    }

    private function ticketWithSla(
        string $key,
        User $assignee,
        Carbon $target,
        bool $breached = false,
        string $status = 'open',
        ?Carbon $resolvedAt = null
    ): Ticket {
        $ticket = Ticket::create([
            'ticket_key' => $key,
            'title' => "Ticket {$key}",
            'description' => 'Notification summary test ticket.',
            'status' => $status,
            'priority' => 'medium',
            'severity' => 'minor',
            'type' => 'task',
            'assignee_id' => $assignee->id,
            'is_deleted' => false,
        ]);

        $ticket->slaMetric()->update([
            'resolution_target_at' => $target,
            'resolved_at' => $resolvedAt,
            'is_resolution_breached' => $breached,
        ]);

        return $ticket;
    }
}
