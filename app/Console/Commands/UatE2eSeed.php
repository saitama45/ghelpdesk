<?php

namespace App\Console\Commands;

use App\Models\UatCase;
use App\Models\UatCaseResult;
use App\Models\UatCycle;
use App\Models\UatParticipant;
use App\Models\UatSection;
use App\Models\User;
use App\Services\UatService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Builds a UAT cycle that is finished testing and waiting only to be signed.
 *
 * The browser suite uses this so the signing demo can start at the interesting
 * part. Authoring a cycle through the setup UI is already covered by the QAT
 * walkthrough, and repeating it here would only couple the signature test to
 * selectors that have nothing to do with signatures.
 *
 * Everything it creates is titled with the E2E- marker, so `qat:e2e-purge`
 * removes it. It refuses to run in production.
 */
class UatE2eSeed extends Command
{
    protected $signature = 'uat:e2e-seed {--approver= : User id who signs off} {--title=}';

    protected $description = 'Create a marked UAT cycle ready for acceptance and final sign-off (browser QA fixture)';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Refusing to run in production.');

            return self::FAILURE;
        }

        $approver = User::find((int) $this->option('approver'));

        if (! $approver) {
            $this->error('An existing --approver user id is required.');

            return self::FAILURE;
        }

        $title = $this->option('title') ?: 'E2E-UAT Signature '.now()->format('His');

        if (! str_starts_with($title, 'E2E-')) {
            $this->error('The title must start with E2E- so the purge can find it again.');

            return self::FAILURE;
        }

        $payload = DB::transaction(function () use ($approver, $title) {
            $cycle = UatCycle::create([
                'code' => UatCycle::nextCode(),
                'title' => $title,
                'system_name' => 'Planning Website',
                'cycle_no' => 1,
                'environment' => 'Web',
                'status' => UatCycle::STATUS_IN_PROGRESS,
                'signoff_requires_all' => true,
                'gate_on_critical_only' => true,
                'created_by' => $approver->id,
                'updated_by' => $approver->id,
            ]);

            $section = UatSection::create([
                'uat_cycle_id' => $cycle->id,
                'name' => 'Issuances',
                'order' => 1,
            ]);

            // Two approvers on purpose, because a UAT cycle is signed from two
            // different places and both must keep working:
            //  - the CLIENT, who has no account and signs on the tokenised portal;
            //  - an INTERNAL approver, signed for from the roster inside the app.
            // With signoff_requires_all the final sign-off stays locked until both
            // have accepted, which is exactly the state worth testing.
            $participant = UatParticipant::create([
                'uat_cycle_id' => $cycle->id,
                'kind' => UatParticipant::KIND_STAKEHOLDER,
                'label' => 'Client',
                'contact_name' => 'Maria Santos',
                'contact_email' => 'maria.santos@example.test',
                'role' => UatParticipant::ROLE_APPROVER,
                'is_active' => true,
                'order' => 1,
            ]);

            $internal = UatParticipant::create([
                'uat_cycle_id' => $cycle->id,
                'kind' => UatParticipant::KIND_DEPARTMENT,
                'label' => 'BD',
                'user_id' => $approver->id,
                'role' => UatParticipant::ROLE_APPROVER,
                'is_active' => true,
                'order' => 2,
            ]);

            $order = 0;
            foreach ([
                'Create a department order',
                'Publish a department order',
            ] as $caseTitle) {
                $case = UatCase::create([
                    'uat_cycle_id' => $cycle->id,
                    'uat_section_id' => $section->id,
                    'case_key' => sprintf('TC-%02d', ++$order),
                    'title' => $caseTitle,
                    'steps' => "1. Open the module\n2. Complete the form\n3. Save",
                    'expected_results' => 'The record is stored and appears in the list.',
                    'is_critical' => true,
                    'priority' => 'high',
                    'order' => $order,
                    'created_by' => $approver->id,
                    'updated_by' => $approver->id,
                ]);

                // Passed for both columns, so the go-live gate is clear and the
                // only thing left to do in the browser is the signing this fixture
                // exists to show.
                foreach ([$participant, $internal] as $answering) {
                    UatCaseResult::updateOrCreate(
                        ['uat_case_id' => $case->id, 'uat_participant_id' => $answering->id],
                        [
                            'uat_cycle_id' => $cycle->id,
                            'result' => UatCaseResult::PASSED,
                            'executed_at' => now(),
                            'executed_by_user_id' => $approver->id,
                            'executed_by_name' => $approver->name,
                            'source' => 'internal',
                        ]
                    );
                }
            }

            app(UatService::class)->ensureCells($cycle);

            // The tokenised portal is the primary way a UAT acceptance is signed:
            // the people accepting are the client's staff, who have no account
            // here. Issuing the link is part of the fixture so the browser run can
            // exercise that path rather than the internal roster screen.
            $token = $participant->issueToken(30);

            return [
                'cycle_id' => $cycle->id,
                'code' => $cycle->code,
                'title' => $cycle->title,
                'participant_id' => $participant->id,
                'internal_participant_id' => $internal->id,
                'approver' => $approver->name,
                'portal_token' => $token,
                'portal_url' => '/public/uat/'.$token,
            ];
        });

        $this->line(json_encode($payload));

        return self::SUCCESS;
    }
}
