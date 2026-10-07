<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\Schedule;
use App\Models\Setting;
use App\Models\Store;
use App\Models\Ticket;
use App\Models\TicketHistory;
use App\Models\User;
use App\Services\AutoAssigneeService;
use App\Support\CompanyContext;
use Carbon\Carbon;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Ticket Duty: schedules tagged on /schedules decide who is auto-assigned new
 * tickets. Order: sender rule → on duty now → next duty shift of the same day
 * → unassigned. A ticket is never handed to tomorrow's shift.
 */
class TicketDutyAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Department $tas;

    private Department $fa;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::flushCache();
        Carbon::setTestNow(Carbon::parse('2026-09-29 09:00:00', 'Asia/Manila'));

        $this->company = Company::create(['name' => 'Table Group', 'code' => 'TGI', 'is_active' => true]);
        $this->tas = Department::create(['name' => 'TAS', 'code' => 'TAS', 'is_active' => true]);
        $this->fa = Department::create(['name' => 'Finance and Accounting', 'code' => 'F&A', 'is_active' => true]);

        Setting::set('auto_assignee_duty_enabled', '1', 'auto_assignee');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Setting::flushCache();

        parent::tearDown();
    }

    public function test_the_on_duty_person_with_the_fewest_active_tickets_gets_it(): void
    {
        $gilbert = $this->agent($this->tas);
        $johan = $this->agent($this->tas);
        $this->shift($gilbert, '2026-09-29 07:00', '2026-09-29 17:00');
        $this->shift($johan, '2026-09-29 07:00', '2026-09-29 17:00');

        $this->tickets($gilbert, 2);
        $this->tickets($johan, 1);
        // Resolved, closed and paused tickets are not load.
        $this->tickets($johan, 1, 'resolved');
        $this->tickets($johan, 2, 'closed');
        $this->tickets($johan, 1, 'waiting_client_feedback');

        $resolved = $this->resolve();

        $this->assertSame($johan->id, $resolved['assignee_id']);
        $this->assertSame('on ticket duty until 5:00 PM, 1 active ticket', $resolved['reason']);
    }

    public function test_equal_load_goes_to_whoever_was_assigned_longest_ago_then_alternates(): void
    {
        $gilbert = $this->agent($this->tas);
        $johan = $this->agent($this->tas);
        $this->shift($gilbert, '2026-09-29 07:00', '2026-09-29 17:00');
        $this->shift($johan, '2026-09-29 07:00', '2026-09-29 17:00');
        // Gilbert handled one yesterday; Johan never has.
        $this->tickets($gilbert, 1, 'closed', Carbon::parse('2026-09-28 10:00'));

        $picks = [];
        foreach (range(1, 3) as $_) {
            $picks[] = $this->resolve()['assignee_id'];
            Carbon::setTestNow(now()->addSeconds(5));
        }

        $this->assertSame([$johan->id, $gilbert->id, $johan->id], $picks);
    }

    public function test_other_desks_untagged_on_leave_and_inactive_people_are_never_picked(): void
    {
        $this->shift($this->agent($this->fa), '2026-09-29 07:00', '2026-09-29 17:00');
        $this->shift($this->agent($this->tas), '2026-09-29 07:00', '2026-09-29 17:00', duty: false);
        $this->shift($this->agent($this->tas), '2026-09-29 07:00', '2026-09-29 17:00', status: 'SL');
        $this->shift($this->agent($this->tas, ['is_active' => false]), '2026-09-29 07:00', '2026-09-29 17:00');
        $this->shift($this->agent($this->tas, ['is_vacant' => true]), '2026-09-29 07:00', '2026-09-29 17:00');

        // Nobody eligible now and no later duty shift today: stays unassigned.
        $this->assertNull($this->resolve()['assignee_id']);
    }

    public function test_a_ticket_after_the_days_last_shift_is_not_handed_to_tomorrows_shift(): void
    {
        // Raised at 10 PM; the only shift still ahead is tomorrow 7 AM - 5 PM.
        Carbon::setTestNow(Carbon::parse('2026-09-29 22:00:00', 'Asia/Manila'));
        $this->shift($this->agent($this->tas), '2026-09-29 07:00', '2026-09-29 17:00');
        $this->shift($this->agent($this->tas), '2026-09-30 07:00', '2026-09-30 17:00');

        $resolved = $this->resolve();

        $this->assertNull($resolved['assignee_id']);
        $this->assertNull($resolved['reason']);
    }

    public function test_a_ticket_before_the_days_first_shift_goes_to_that_shift(): void
    {
        // Raised at 12:01 AM; the day's shifts start at 7 AM and 8 AM.
        Carbon::setTestNow(Carbon::parse('2026-09-30 00:01:00', 'Asia/Manila'));
        $early = $this->agent($this->tas);
        $this->shift($this->agent($this->tas), '2026-09-29 07:00', '2026-09-29 17:00');
        $this->shift($this->agent($this->tas), '2026-09-30 08:00', '2026-09-30 17:00');
        $this->shift($early, '2026-09-30 07:00', '2026-09-30 16:00');
        // Earlier on the clock, but a day away: out of reach.
        $this->shift($this->agent($this->tas), '2026-10-01 06:00', '2026-10-01 15:00');

        $resolved = $this->resolve();

        $this->assertSame($early->id, $resolved['assignee_id']);
        $this->assertStringStartsWith('next on ticket duty, from Wed Sep 30, 7:00 AM', $resolved['reason']);
    }

    public function test_a_gap_between_two_shifts_of_the_same_day_goes_to_the_later_one(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-29 13:00:00', 'Asia/Manila'));
        $afternoon = $this->agent($this->tas);
        $this->shift($this->agent($this->tas), '2026-09-29 07:00', '2026-09-29 12:00');
        $this->shift($afternoon, '2026-09-29 14:00', '2026-09-29 22:00');

        $this->assertSame($afternoon->id, $this->resolve()['assignee_id']);
    }

    public function test_a_late_ticket_goes_to_whoever_is_on_duty_until_midnight(): void
    {
        // "Until 12 AM" is entered as 23:59 - the form cannot say 24:00.
        $evening = $this->agent($this->tas);
        $this->shift($evening, '2026-09-29 14:00', '2026-09-29 23:59');
        $this->shift($this->agent($this->tas), '2026-09-30 07:00', '2026-09-30 17:00');

        Carbon::setTestNow(Carbon::parse('2026-09-29 22:00:00', 'Asia/Manila'));
        $resolved = $this->resolve();
        $this->assertSame($evening->id, $resolved['assignee_id']);
        $this->assertStringStartsWith('on ticket duty until 11:59 PM', $resolved['reason']);

        // The last minute of the day is still theirs, not a hole before midnight.
        Carbon::setTestNow(Carbon::parse('2026-09-29 23:59:30', 'Asia/Manila'));
        $this->assertSame($evening->id, $this->resolve()['assignee_id']);
    }

    public function test_a_shift_that_ends_on_the_hour_stops_covering_at_that_moment(): void
    {
        // Only 23:59 is read as "until midnight"; 5:00 PM means 5:00 PM.
        Carbon::setTestNow(Carbon::parse('2026-09-29 17:00:30', 'Asia/Manila'));
        $this->shift($this->agent($this->tas), '2026-09-29 07:00', '2026-09-29 17:00');

        $this->assertNull($this->resolve()['assignee_id']);
    }

    public function test_a_shift_that_runs_past_midnight_still_covers_the_small_hours(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-30 01:00:00', 'Asia/Manila'));
        $night = $this->agent($this->tas);
        $this->shift($night, '2026-09-29 22:00', '2026-09-30 06:00');
        $this->shift($this->agent($this->tas), '2026-09-30 07:00', '2026-09-30 17:00');

        $resolved = $this->resolve();

        $this->assertSame($night->id, $resolved['assignee_id']);
        $this->assertStringStartsWith('on ticket duty until 6:00 AM', $resolved['reason']);
    }

    public function test_a_sender_email_rule_still_wins_over_the_roster(): void
    {
        $ruleAgent = $this->agent($this->tas);
        $onDuty = $this->agent($this->tas);
        $this->shift($onDuty, '2026-09-29 07:00', '2026-09-29 17:00');
        Setting::set('auto_assignee_rules', json_encode([
            ['email' => 'store.manager@example.com', 'assignee_ids' => [$ruleAgent->id]],
        ]), 'auto_assignee');

        $this->assertSame($ruleAgent->id, $this->resolve([], 'Store.Manager@example.com')['assignee_id']);
        $this->assertSame($onDuty->id, $this->resolve([], 'someone.else@example.com')['assignee_id']);
    }

    public function test_with_the_roster_off_the_default_agents_still_apply(): void
    {
        Setting::set('auto_assignee_duty_enabled', '0', 'auto_assignee');
        $default = $this->agent($this->tas);
        $this->shift($this->agent($this->tas), '2026-09-29 07:00', '2026-09-29 17:00');
        Setting::set('auto_assignee_defaults', json_encode([$default->id]), 'auto_assignee');

        $this->assertSame($default->id, $this->resolve([], 'requester@example.com')['assignee_id']);
        // No sender and no roster: unassigned, as before.
        $this->assertNull($this->resolve()['assignee_id']);
        $this->assertNull(app(AutoAssigneeService::class)->resolveFromDuty(['serving_department_id' => $this->tas->id])['assignee_id']);
    }

    public function test_same_store_first_prefers_whoever_is_scheduled_at_the_tickets_store(): void
    {
        $opus = $this->store('OPUS');
        $cfe = $this->store('CFE2');
        $atOpus = $this->agent($this->tas);
        $atCfe = $this->agent($this->tas);
        $this->shift($atOpus, '2026-09-29 07:00', '2026-09-29 17:00', store: $opus);
        $this->shift($atCfe, '2026-09-29 07:00', '2026-09-29 17:00', store: $cfe);
        $this->tickets($atOpus, 3);

        $context = ['serving_department_id' => $this->tas->id, 'store_id' => $opus->id];
        $this->assertSame($atCfe->id, $this->resolve($context)['assignee_id'], 'Off: fewest tickets wins.');

        Setting::set('auto_assignee_duty_store_first', '1', 'auto_assignee');
        $resolved = $this->resolve($context);
        $this->assertSame($atOpus->id, $resolved['assignee_id']);
        $this->assertStringContainsString('scheduled at this store', $resolved['reason']);
    }

    public function test_tickets_without_a_desk_use_the_intake_department(): void
    {
        $finance = $this->agent($this->fa);
        $tasAgent = $this->agent($this->tas);
        $this->shift($finance, '2026-09-29 07:00', '2026-09-29 17:00');
        $this->shift($tasAgent, '2026-09-29 07:00', '2026-09-29 17:00');
        $this->tickets($tasAgent, 2);

        $this->assertSame($finance->id, $this->resolve(['serving_department_id' => null])['assignee_id'], 'Blank intake = any desk.');

        Setting::set('auto_assignee_duty_intake_department_id', (string) $this->tas->id, 'auto_assignee');
        $this->assertSame($tasAgent->id, $this->resolve(['serving_department_id' => null])['assignee_id']);
    }

    public function test_the_requesters_entity_switcher_does_not_hide_the_desks_roster(): void
    {
        $gilbert = $this->agent($this->tas);
        $this->shift($gilbert, '2026-09-29 07:00', '2026-09-29 17:00')
            ->forceFill(['company_id' => $this->company->id])->save();

        // A requester working under another entity (ActiveEntityScope would
        // otherwise filter schedules to that entity).
        $cbtl = Company::create(['name' => 'Coffee Bean', 'code' => 'CBTL', 'is_active' => true]);
        $requester = $this->agent($this->fa, ['company_id' => $cbtl->id]);
        CompanyContext::flushMemo();
        $this->actingAs($requester)->withSession([CompanyContext::SESSION_KEY => $cbtl->id]);
        $this->assertSame($cbtl->id, CompanyContext::activeCompanyId());

        $this->assertSame($gilbert->id, $this->resolve()['assignee_id']);
        CompanyContext::flushMemo();
    }

    public function test_the_reason_is_written_to_the_ticket_activity(): void
    {
        $gilbert = $this->agent($this->tas, ['name' => 'Gilbert Taopa']);
        $this->shift($gilbert, '2026-09-29 07:00', '2026-09-29 17:00');
        $service = app(AutoAssigneeService::class);

        $resolved = $service->resolveAssignee('', ['serving_department_id' => $this->tas->id]);
        $ticket = $this->tickets($gilbert, 1)[0];
        $service->recordReason($ticket, $resolved);

        $history = TicketHistory::where('ticket_id', $ticket->id)->sole();
        $this->assertNull($history->user_id);
        $this->assertSame('assignee_id', $history->column_changed);
        $this->assertSame('Gilbert Taopa (auto-assigned: on ticket duty until 5:00 PM, 0 active tickets)', $history->new_value);
    }

    public function test_only_a_ticket_duty_tagger_can_toggle_and_only_on_a_working_status(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->permissions();
        $viewer = $this->agent($this->tas);
        $viewer->givePermissionTo('schedules.view');
        $tagger = $this->agent($this->tas);
        $tagger->givePermissionTo(['schedules.view', 'schedules.ticket_duty']);

        $working = $this->shift($this->agent($this->tas), '2026-09-29 07:00', '2026-09-29 17:00', duty: false);
        $leave = $this->shift($this->agent($this->tas), '2026-09-29 07:00', '2026-09-29 17:00', status: 'VL', duty: false);

        $this->actingAs($viewer)->post("/schedules/{$working->id}/ticket-duty", ['ticket_duty' => true])->assertForbidden();
        $this->assertFalse($working->fresh()->ticket_duty);

        $this->actingAs($tagger)->post("/schedules/{$working->id}/ticket-duty", ['ticket_duty' => true])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue($working->fresh()->ticket_duty);

        $this->actingAs($tagger)->post("/schedules/{$leave->id}/ticket-duty", ['ticket_duty' => true])
            ->assertSessionHasErrors('ticket_duty');
        $this->assertFalse($leave->fresh()->ticket_duty);
    }

    public function test_editing_keeps_the_tag_for_non_taggers_and_leave_clears_it(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->permissions();
        $store = $this->store('OPUS');

        // An editor without the tag permission can neither set nor clear it.
        $editor = $this->agent($this->tas, ['is_manager' => true]);
        $editor->givePermissionTo(['schedules.view', 'schedules.edit']);
        $tagged = $this->shift($editor, '2026-09-29 07:00', '2026-09-29 17:00', store: $store);
        $this->actingAs($editor)
            ->put("/schedules/{$tagged->id}", $this->updatePayload($editor, 'WFH', $store, false))
            ->assertSessionHasNoErrors();
        $this->assertTrue($tagged->fresh()->ticket_duty);

        // Moving the day to leave clears it, whoever saves.
        $this->actingAs($editor)
            ->put("/schedules/{$tagged->id}", $this->updatePayload($editor, 'SL', null, true))
            ->assertSessionHasNoErrors();
        $this->assertFalse($tagged->fresh()->ticket_duty);

        // A tagger with edit rights sets it from the form.
        $editor->givePermissionTo('schedules.ticket_duty');
        $this->actingAs($editor)
            ->put("/schedules/{$tagged->id}", $this->updatePayload($editor, 'On-site', $store, true))
            ->assertSessionHasNoErrors();
        $this->assertTrue($tagged->fresh()->ticket_duty);
    }

    /** Defaults to a TAS ticket; pass serving_department_id => null for a deskless one. */
    private function resolve(array $context = [], string $email = ''): array
    {
        $context += ['serving_department_id' => $this->tas->id];

        return app(AutoAssigneeService::class)->resolveAssignee($email, $context);
    }

    private function agent(Department $department, array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'department_id' => $department->id,
            'is_active' => true,
            'is_vacant' => false,
        ], $attributes));
    }

    private function shift(User $user, string $start, string $end, string $status = 'On-site', bool $duty = true, ?Store $store = null): Schedule
    {
        $schedule = Schedule::create([
            'user_id' => $user->id,
            'status' => $status,
            'start_time' => $start,
            'end_time' => $end,
            'ticket_duty' => $duty,
        ]);

        if ($store) {
            $schedule->scheduleStores()->create(['store_id' => $store->id, 'start_time' => $start, 'end_time' => $end]);
        }

        return $schedule;
    }

    /** @return list<Ticket> */
    private function tickets(User $assignee, int $count, string $status = 'open', ?Carbon $createdAt = null): array
    {
        return collect(range(1, $count))->map(fn () => Ticket::create([
            'title' => 'Existing work',
            'description' => 'x',
            'type' => 'task',
            'status' => $status,
            'priority' => 'medium',
            'severity' => 'minor',
            'assignee_id' => $assignee->id,
            'company_id' => $this->company->id,
            'created_at' => $createdAt ?? now(),
        ]))->all();
    }

    private function store(string $code): Store
    {
        return Store::create([
            'code' => $code,
            'name' => $code,
            'sector' => 1,
            'area' => 'Metro',
            'brand' => 'Brand',
            'cluster' => 'Cluster',
            'is_active' => true,
        ]);
    }

    private function permissions(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['schedules.view', 'schedules.edit', 'schedules.create', 'schedules.ticket_duty'] as $name) {
            Permission::firstOrCreate(['name' => $name]);
        }
    }

    private function updatePayload(User $user, string $status, ?Store $store, bool $ticketDuty): array
    {
        return [
            'user_id' => $user->id,
            'status' => $status,
            'stores' => [[
                'store_id' => $store?->id,
                'ticket_id' => null,
                'start_time' => '2026-09-29T07:00',
                'end_time' => '2026-09-29T17:00',
                'grace_period_minutes' => 30,
                'remarks' => null,
            ]],
            'pickup_start' => null,
            'pickup_end' => null,
            'backlogs_start' => null,
            'backlogs_end' => null,
            'ticket_duty' => $ticketDuty,
        ];
    }
}
