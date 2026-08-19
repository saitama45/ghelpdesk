<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\QatCase;
use App\Models\QatCaseResult;
use App\Models\QatCycle;
use App\Models\QatEvidence;
use App\Models\QatFinding;
use App\Models\QatParticipant;
use App\Models\QatSection;
use App\Models\QatSignoff;
use App\Models\UatCycle;
use App\Models\User;
use App\Services\ManagerApproverResolver;
use App\Services\QatService;
use App\Services\QatWorkbook;
use App\Support\CompanyContext;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Throwable;

/**
 * QAT Tracker — internal quality test cycles.
 *
 * Sibling of {@see UatController}, minus the tokenised portal (QAT is internal)
 * and with the sign-off spine moved out to {@see QatSignoffController}, which also
 * owns promotion into a UAT cycle.
 */
class QatController extends Controller implements HasMiddleware
{
    /**
     * Case columns safe to ship with a full listing. `steps`, `expected_results`
     * and `description` are nvarchar(MAX) and a 96-case cycle drags hundreds of KB
     * over the link — they are fetched one case at a time by caseDetail() instead.
     */
    private const CASE_LIST_COLUMNS = [
        'id', 'qat_cycle_id', 'qat_section_id', 'case_key', 'screen', 'title',
        'is_critical', 'priority', 'order',
    ];

    public function __construct(
        private QatService $qat,
        private QatWorkbook $workbook,
        private ManagerApproverResolver $approvers,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:qat.view', only: ['index', 'show', 'caseDetail']),
            new Middleware('can:qat.edit', only: ['editData']),
            new Middleware('can:qat.create', only: ['store', 'duplicate', 'seedFromUat']),
            new Middleware('can:qat.edit', only: [
                'update', 'storeSection', 'updateSection', 'destroySection',
                'storeCase', 'updateCase', 'destroyCase', 'reorderCases',
                'storeParticipant', 'updateParticipant', 'destroyParticipant',
            ]),
            new Middleware('can:qat.execute', only: ['storeResult', 'bulkResults', 'storeEvidence', 'destroyEvidence']),
            new Middleware('can:qat.delete', only: ['destroy']),
            new Middleware('can:qat.export', only: ['export', 'template']),
            new Middleware('can:qat.import', only: ['import']),
        ];
    }

    // ------------------------------------------------------------------
    // Cycles
    // ------------------------------------------------------------------

    public function index(Request $request)
    {
        $user = $request->user();

        $query = QatCycle::query()
            ->select([
                'id', 'code', 'title', 'system_name', 'cycle_no', 'environment', 'status',
                'company_id', 'department_id', 'qa_lead_id', 'start_date',
                'target_signoff_date', 'go_live_date', 'submitted_at',
                'uat_cycle_id', 'promoted_uat_cycle_id', 'created_at',
            ])
            ->with([
                'company:id,name',
                'department:id,name',
                'qaLead:id,name',
                'promotedUatCycle:id,code,title',
            ])
            ->withCount('cases')
            // The department axis, plus the named-on-it and assigned-approver
            // escape hatches. Defined once on the model so the middleware that
            // guards every {cycle} route cannot drift away from this listing.
            ->visibleTo($user);

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('system_name', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        // Entity filter is a listing convenience, not an authorisation boundary.
        // Defaults to every entity: a test cycle often spans entities, and scoping
        // it to the active one by default hides cycles the user just created.
        $entity = $request->input('company_id', 'all');

        // Counted before the entity filter narrows things, so the page can say how
        // many rows it is hiding — otherwise a missing cycle is silent and baffling.
        $totalBeforeEntityFilter = (clone $query)->count();

        if ($entity === 'active') {
            $activeId = CompanyContext::activeCompanyId();
            if ($activeId) {
                $query->where(function ($q) use ($activeId) {
                    $q->where('company_id', $activeId)->orWhereNull('company_id');
                });
            }
        } elseif ($entity !== 'all' && is_numeric($entity)) {
            $query->where('company_id', (int) $entity);
        }

        $hiddenByEntity = max(0, $totalBeforeEntityFilter - (clone $query)->count());

        $cycles = $query->orderByDesc('id')
            ->paginate($request->get('per_page', 12))
            ->withQueryString();

        $summaries = $this->listSummaries(collect($cycles->items())->pluck('id')->all());
        $cycles->getCollection()->transform(function (QatCycle $cycle) use ($summaries) {
            $cycle->summary = $summaries[$cycle->id] ?? null;

            return $cycle;
        });

        return Inertia::render('Qat/Index', [
            'cycles' => $cycles,
            'hiddenByEntity' => $hiddenByEntity,
            'activeCompanyId' => CompanyContext::activeCompanyId(),
            'filters' => [
                'search' => $request->input('search'),
                'status' => $request->input('status'),
                'company_id' => (string) $entity,
            ],
            'statuses' => $this->options(QatCycle::statuses()),
            'environments' => collect(QatCycle::environments())->map(fn ($e) => ['label' => $e, 'value' => $e])->all(),
            'companies' => CompanyContext::accessibleCompanies($user)
                ->map(fn ($c) => ['label' => $c->name, 'value' => $c->id])->values()->all(),
            'departments' => $this->departmentOptions(),
            'users' => $this->userOptions(),
            // Source cycles for "start a QAT re-test from a UAT cycle".
            'uatCycles' => $this->uatCycleOptions($user),
        ]);
    }

    /**
     * Roll-up counts for the index cards. Computed in SQL because the listing
     * never needs the individual verdicts.
     *
     * @param  array<int,int>  $cycleIds
     */
    private function listSummaries(array $cycleIds): array
    {
        if ($cycleIds === []) {
            return [];
        }

        // Worst-wins per case: failed if any participant failed it, pending if any
        // participant has not answered, otherwise passed.
        $rows = DB::table('qat_case_results as r')
            ->join('qat_cases as c', 'c.id', '=', 'r.qat_case_id')
            ->whereIn('r.qat_cycle_id', $cycleIds)
            ->groupBy('r.qat_cycle_id', 'r.qat_case_id')
            ->select([
                'r.qat_cycle_id',
                'r.qat_case_id',
                DB::raw("SUM(CASE WHEN r.result = 'failed' THEN 1 ELSE 0 END) as failed"),
                DB::raw("SUM(CASE WHEN r.result = 'blocked' THEN 1 ELSE 0 END) as blocked"),
                DB::raw("SUM(CASE WHEN r.result = 'pending' THEN 1 ELSE 0 END) as pending"),
                DB::raw("SUM(CASE WHEN r.result = 'passed' THEN 1 ELSE 0 END) as passed"),
            ])
            ->get();

        $summaries = [];
        foreach ($rows as $row) {
            $bucket = $summaries[$row->qat_cycle_id] ??= ['passed' => 0, 'failed' => 0, 'blocked' => 0, 'pending' => 0, 'total' => 0];
            $bucket['total']++;

            if ($row->failed > 0) {
                $bucket['failed']++;
            } elseif ($row->blocked > 0) {
                $bucket['blocked']++;
            } elseif ($row->pending > 0) {
                $bucket['pending']++;
            } elseif ($row->passed > 0) {
                $bucket['passed']++;
            }

            $summaries[$row->qat_cycle_id] = $bucket;
        }

        $openFindings = DB::table('qat_findings')
            ->whereIn('qat_cycle_id', $cycleIds)
            ->whereIn('status', QatFinding::UNRESOLVED_STATUSES)
            ->groupBy('qat_cycle_id')
            ->select('qat_cycle_id', DB::raw('COUNT(*) as total'))
            ->pluck('total', 'qat_cycle_id');

        // Shown as its own badge: an unwaived blocker/major is what will stop the
        // manager signing the cycle off, so it belongs on the card.
        $blockingFindings = DB::table('qat_findings')
            ->whereIn('qat_cycle_id', $cycleIds)
            ->whereIn('status', QatFinding::UNRESOLVED_STATUSES)
            ->whereIn('severity', QatFinding::BLOCKING_SEVERITIES)
            ->whereNull('waived_at')
            ->groupBy('qat_cycle_id')
            ->select('qat_cycle_id', DB::raw('COUNT(*) as total'))
            ->pluck('total', 'qat_cycle_id');

        foreach ($summaries as $cycleId => $bucket) {
            $summaries[$cycleId]['open_findings'] = (int) ($openFindings[$cycleId] ?? 0);
            $summaries[$cycleId]['blocking_findings'] = (int) ($blockingFindings[$cycleId] ?? 0);
        }

        return $summaries;
    }

    public function store(Request $request)
    {
        $validated = $request->validate($this->cycleRules());

        $cycle = $this->createWithUniqueCode($validated, $request->user()->id);

        return redirect()->route('qat.show', $cycle->id)
            ->with('success', "QAT cycle {$cycle->code} created.");
    }

    /** Retries on the code unique-index race. */
    private function createWithUniqueCode(array $attributes, int $userId): QatCycle
    {
        foreach (range(1, 3) as $attempt) {
            try {
                return QatCycle::create(array_merge($attributes, [
                    'code' => QatCycle::nextCode(),
                    'created_by' => $userId,
                    'updated_by' => $userId,
                ]));
            } catch (Throwable $e) {
                if ($attempt === 3 || ! str_contains(strtolower($e->getMessage()), 'duplicate')) {
                    throw $e;
                }
            }
        }

        throw ValidationException::withMessages(['title' => 'Could not allocate a cycle code. Please try again.']);
    }

    public function show(Request $request, QatCycle $cycle)
    {
        $cycle->load([
            'company:id,name',
            'department:id,name',
            'qaLead:id,name,email',
            'devLead:id,name,email',
            'creator:id,name',
            'submitter:id,name,email',
            'managerSignoff.confirmedBy:id,name',
            'uatCycle:id,code,title,status',
            'promotedUatCycle:id,code,title,status',
        ]);

        $sections = $cycle->sections()->get();
        $cases = $cycle->cases()->get(self::CASE_LIST_COLUMNS);
        $participants = $cycle->participants()->with([
            'user:id,name,email',
            'department:id,name',
            'company:id,name',
        ])->get();
        $results = $cycle->results()->get([
            'id', 'qat_case_id', 'qat_participant_id', 'result', 'remarks',
            'executed_at', 'executed_by_user_id', 'executed_by_name',
        ]);
        $findings = $cycle->findings()
            ->with([
                'assignee:id,name', 'testCase:id,case_key,title',
                'ticket:id,ticket_key,status,created_at', 'participant:id,label',
                'waivedBy:id,name',
                'evidence',
            ])
            ->orderByRaw("CASE severity WHEN 'blocker' THEN 1 WHEN 'major' THEN 2 WHEN 'minor' THEN 3 ELSE 4 END")
            ->orderByDesc('id')
            ->get();

        // Evidence counts only — the files themselves load with the case drawer.
        $evidenceCounts = DB::table('qat_evidence')
            ->where('qat_cycle_id', $cycle->id)
            ->whereNotNull('qat_case_result_id')
            ->groupBy('qat_case_result_id')
            ->select('qat_case_result_id', DB::raw('COUNT(*) as total'))
            ->pluck('total', 'qat_case_result_id');

        $results->each(function ($result) use ($evidenceCounts) {
            $result->evidence_count = (int) ($evidenceCounts[$result->id] ?? 0);
        });

        $signoffs = $cycle->signoffs()
            ->with('confirmedBy:id,name,email')
            ->orderByDesc('confirmed_at')
            ->get();

        $readiness = $this->qat->readiness($cycle, $cases, $results, $findings, $participants);

        // Who the cycle is waiting on, and whether the person looking at the page
        // is one of them. Both drive the sign-off tab, which stays visible to
        // everyone — hiding it would make the gate itself invisible.
        $readiness['assigned_approvers'] = $this->approvers->names($cycle->approver_user_ids ?? []);
        $readiness['is_approver'] = $cycle->isAssignedApprover($request->user());

        return Inertia::render('Qat/Show', [
            'cycle' => $cycle,
            'sections' => $sections,
            'cases' => $cases,
            'participants' => $participants->map(fn (QatParticipant $p) => array_merge($p->toArray(), [
                'display_name' => $p->display_name,
                'display_email' => $p->display_email,
            ])),
            'results' => $results,
            'findings' => $findings,
            'signoffs' => $signoffs,
            'statistics' => $this->qat->statistics($cases, $results, $participants),
            'participantProgress' => $this->qat->participantProgress($participants, $cases, $results),
            'sectionProgress' => $this->qat->sectionProgress($cases, $results, $participants),
            // The matrix renders ONE column per department, not one per person.
            'columns' => $this->qat->columns($participants),
            'readiness' => $readiness,
            'options' => [
                'statuses' => $this->options(QatCycle::statuses()),
                'environments' => collect(QatCycle::environments())->map(fn ($e) => ['label' => $e, 'value' => $e])->all(),
                'results' => $this->options(QatCaseResult::results()),
                'priorities' => $this->options(QatCase::priorities()),
                'severities' => $this->options(QatFinding::severities()),
                'findingStatuses' => $this->options(QatFinding::statuses()),
                'signoffResults' => $this->options(QatSignoff::results()),
                'participantKinds' => $this->options(QatParticipant::kinds()),
                'participantRoles' => $this->options(QatParticipant::roles()),
                'companies' => CompanyContext::accessibleCompanies($request->user())
                    ->map(fn ($c) => ['label' => $c->name, 'value' => $c->id])->values()->all(),
                'departments' => $this->departmentOptions(),
                'users' => $this->userOptions(),
                'uatCycles' => $this->uatCycleOptions($request->user()),
            ],
        ]);
    }

    /**
     * Every editable field of a cycle, for the edit form.
     *
     * The index listing deliberately selects a narrow column set (it never needs
     * `description` or `links`, which are nvarchar(MAX)). Handing that partial row
     * to the edit form meant the missing fields posted back blank and silently
     * wiped stored values, so the form fetches the whole record here.
     */
    public function editData(QatCycle $cycle)
    {
        return response()->json([
            'cycle' => array_merge($cycle->only([
                'id', 'code', 'title', 'system_name', 'description', 'cycle_no',
                'environment', 'links', 'company_id', 'department_id',
                'qa_lead_id', 'dev_lead_id', 'uat_cycle_id', 'status',
                'gate_on_critical_only',
            ]), [
                // Formatted, not handed over as Carbon. `only()` returns the raw
                // cast value, and json_encode renders a Carbon in UTC — which moves
                // an Asia/Manila calendar date back a day, so every save walked
                // dates backwards. These columns are calendar dates with no time.
                'start_date' => $cycle->start_date?->format('Y-m-d'),
                'target_signoff_date' => $cycle->target_signoff_date?->format('Y-m-d'),
                'go_live_date' => $cycle->go_live_date?->format('Y-m-d'),
            ]),
        ]);
    }

    /** Full text for one case, plus its verdicts, evidence and findings. */
    public function caseDetail(QatCycle $cycle, QatCase $case)
    {
        abort_unless($case->qat_cycle_id === $cycle->id, 404);

        $results = $case->results()
            ->with(['participant:id,label,role,kind', 'executedBy:id,name', 'evidence'])
            ->get();

        return response()->json([
            'case' => $case->load('section:id,name'),
            'results' => $results,
            'findings' => $case->findings()
                ->with(['assignee:id,name', 'ticket:id,ticket_key,status'])
                ->orderByDesc('id')
                ->get(),
        ]);
    }

    public function update(Request $request, QatCycle $cycle)
    {
        $validated = $request->validate($this->cycleRules());
        $validated['updated_by'] = $request->user()->id;

        $cycle->update($validated);

        return redirect()->back()->with('success', 'QAT cycle updated.');
    }

    public function destroy(QatCycle $cycle)
    {
        $code = $cycle->code;
        $cycle->cascadeDelete();

        return redirect()->route('qat.index')->with('success', "QAT cycle {$code} deleted.");
    }

    /**
     * Clones the structure — sections, cases and the participant roster — into a
     * fresh cycle with empty verdicts. This is how a re-test round starts.
     */
    public function duplicate(Request $request, QatCycle $cycle)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'cycle_no' => 'required|integer|min:1|max:99',
            'copy_participants' => 'boolean',
        ]);

        $copy = DB::transaction(function () use ($cycle, $validated, $request) {
            $new = $this->createWithUniqueCode([
                'title' => $validated['title'],
                'system_name' => $cycle->system_name,
                'description' => $cycle->description,
                'cycle_no' => $validated['cycle_no'],
                'environment' => $cycle->environment,
                'links' => $cycle->links,
                'company_id' => $cycle->company_id,
                'department_id' => $cycle->department_id,
                'qa_lead_id' => $cycle->qa_lead_id,
                'dev_lead_id' => $cycle->dev_lead_id,
                'uat_cycle_id' => $cycle->uat_cycle_id,
                // A new round starts unsubmitted and unsigned, whatever the source
                // reached. Carrying the sign-off over would be forging it.
                'status' => QatCycle::STATUS_DRAFT,
                'gate_on_critical_only' => $cycle->gate_on_critical_only,
            ], $request->user()->id);

            $sectionMap = [];
            foreach ($cycle->sections as $section) {
                $sectionMap[$section->id] = QatSection::create([
                    'qat_cycle_id' => $new->id,
                    'name' => $section->name,
                    'description' => $section->description,
                    'is_critical' => $section->is_critical,
                    'order' => $section->order,
                ])->id;
            }

            foreach ($cycle->cases()->get() as $case) {
                QatCase::create([
                    'qat_cycle_id' => $new->id,
                    'qat_section_id' => $sectionMap[$case->qat_section_id] ?? null,
                    'case_key' => $case->case_key,
                    'screen' => $case->screen,
                    'title' => $case->title,
                    'description' => $case->description,
                    'steps' => $case->steps,
                    'expected_results' => $case->expected_results,
                    'is_critical' => $case->is_critical,
                    'priority' => $case->priority,
                    'order' => $case->order,
                    'source_uat_case_id' => $case->source_uat_case_id,
                    'created_by' => $request->user()->id,
                    'updated_by' => $request->user()->id,
                ]);
            }

            if ($request->boolean('copy_participants', true)) {
                foreach ($cycle->participants as $participant) {
                    QatParticipant::create([
                        'qat_cycle_id' => $new->id,
                        'kind' => $participant->kind,
                        'label' => $participant->label,
                        'department_id' => $participant->department_id,
                        'company_id' => $participant->company_id,
                        'user_id' => $participant->user_id,
                        'contact_name' => $participant->contact_name,
                        'contact_email' => $participant->contact_email,
                        'role' => $participant->role,
                        'is_active' => $participant->is_active,
                        'order' => $participant->order,
                    ]);
                }
            }

            $this->qat->ensureCells($new);

            return $new;
        });

        return redirect()->route('qat.show', $copy->id)
            ->with('success', "Cycle duplicated as {$copy->code}.");
    }

    /**
     * Starts a QAT cycle from an existing UAT cycle's test script — the re-test
     * direction of the bridge. Copies structure only: verdicts, findings and the
     * stakeholder roster stay behind, because a UAT roster is clients and a QAT
     * roster is internal QA staff.
     */
    public function seedFromUat(Request $request)
    {
        $validated = $request->validate([
            'uat_cycle_id' => 'required|exists:uat_cycles,id',
            'title' => 'required|string|max:255',
            'department_id' => 'nullable|exists:departments,id',
        ]);

        $source = UatCycle::findOrFail($validated['uat_cycle_id']);

        $cycle = DB::transaction(function () use ($source, $validated, $request) {
            $new = $this->createWithUniqueCode([
                'title' => $validated['title'],
                'system_name' => $source->system_name,
                'description' => $source->description,
                'cycle_no' => 1,
                'environment' => $source->environment,
                'links' => $source->links,
                'company_id' => $source->company_id,
                'department_id' => $validated['department_id'] ?? $source->department_id,
                'qa_lead_id' => $source->qa_lead_id,
                'dev_lead_id' => $source->dev_lead_id,
                'uat_cycle_id' => $source->id,
                'status' => QatCycle::STATUS_DRAFT,
                'gate_on_critical_only' => $source->gate_on_critical_only,
            ], $request->user()->id);

            $sectionMap = [];
            foreach ($source->sections as $section) {
                $sectionMap[$section->id] = QatSection::create([
                    'qat_cycle_id' => $new->id,
                    'name' => $section->name,
                    'description' => $section->description,
                    'is_critical' => $section->is_critical,
                    'order' => $section->order,
                ])->id;
            }

            foreach ($source->cases()->get() as $case) {
                QatCase::create([
                    'qat_cycle_id' => $new->id,
                    'qat_section_id' => $sectionMap[$case->uat_section_id] ?? null,
                    'case_key' => $case->case_key,
                    'screen' => $case->screen,
                    'title' => $case->title,
                    'description' => $case->description,
                    'steps' => $case->steps,
                    'expected_results' => $case->expected_results,
                    'is_critical' => $case->is_critical,
                    'priority' => $case->priority,
                    'order' => $case->order,
                    'source_uat_case_id' => $case->id,
                    'created_by' => $request->user()->id,
                    'updated_by' => $request->user()->id,
                ]);
            }

            return $new;
        });

        return redirect()->route('qat.show', $cycle->id)
            ->with('success', "QAT cycle {$cycle->code} created from {$source->code}. Add your internal testers on the Setup tab.");
    }

    // ------------------------------------------------------------------
    // Sections
    // ------------------------------------------------------------------

    public function storeSection(Request $request, QatCycle $cycle)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:255',
            'is_critical' => 'boolean',
        ]);

        $validated['qat_cycle_id'] = $cycle->id;
        $validated['order'] = ((int) $cycle->sections()->max('order')) + 1;

        QatSection::create($validated);

        return redirect()->back()->with('success', 'Section added.');
    }

    public function updateSection(Request $request, QatCycle $cycle, QatSection $section)
    {
        abort_unless($section->qat_cycle_id === $cycle->id, 404);

        $section->update($request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:255',
            'is_critical' => 'boolean',
            'order' => 'nullable|integer|min:0',
        ]));

        return redirect()->back()->with('success', 'Section updated.');
    }

    public function destroySection(QatCycle $cycle, QatSection $section)
    {
        abort_unless($section->qat_cycle_id === $cycle->id, 404);

        // Cases outlive their section — they fall back to the ungrouped bucket
        // rather than being silently deleted with it.
        QatCase::where('qat_section_id', $section->id)->update(['qat_section_id' => null]);
        $section->delete();

        return redirect()->back()->with('success', 'Section removed. Its test cases were kept and ungrouped.');
    }

    // ------------------------------------------------------------------
    // Cases
    // ------------------------------------------------------------------

    public function storeCase(Request $request, QatCycle $cycle)
    {
        $validated = $request->validate($this->caseRules($cycle));

        $validated['qat_cycle_id'] = $cycle->id;
        $validated['case_key'] = $validated['case_key']
            ?: QatCase::nextKey($cycle->id, QatCase::keyPrefix($cycle->id));
        $validated['order'] = ((int) $cycle->cases()->max('order')) + 1;
        $validated['created_by'] = $request->user()->id;
        $validated['updated_by'] = $request->user()->id;

        QatCase::create($validated);
        $this->qat->ensureCells($cycle);

        return redirect()->back()->with('success', 'Test case added.');
    }

    public function updateCase(Request $request, QatCycle $cycle, QatCase $case)
    {
        abort_unless($case->qat_cycle_id === $cycle->id, 404);

        $validated = $request->validate($this->caseRules($cycle, $case->id));
        $validated['case_key'] = $validated['case_key'] ?: $case->case_key;
        $validated['updated_by'] = $request->user()->id;

        $case->update($validated);

        return redirect()->back()->with('success', 'Test case updated.');
    }

    public function destroyCase(QatCycle $cycle, QatCase $case)
    {
        abort_unless($case->qat_cycle_id === $cycle->id, 404);

        DB::transaction(function () use ($case) {
            $resultIds = QatCaseResult::where('qat_case_id', $case->id)->pluck('id');
            QatEvidence::whereIn('qat_case_result_id', $resultIds)->delete();
            QatCaseResult::whereIn('id', $resultIds)->delete();
            // Findings survive as cycle-level records; only the link is cleared.
            QatFinding::where('qat_case_id', $case->id)->update(['qat_case_id' => null]);
            $case->delete();
        });

        return redirect()->back()->with('success', 'Test case deleted.');
    }

    public function reorderCases(Request $request, QatCycle $cycle)
    {
        $validated = $request->validate([
            'order' => 'required|array',
            'order.*' => 'integer',
        ]);

        DB::transaction(function () use ($validated, $cycle) {
            foreach ($validated['order'] as $position => $caseId) {
                QatCase::where('qat_cycle_id', $cycle->id)
                    ->whereKey($caseId)
                    ->update(['order' => $position + 1]);
            }
        });

        return redirect()->back()->with('success', 'Order saved.');
    }

    // ------------------------------------------------------------------
    // Participants
    // ------------------------------------------------------------------

    public function storeParticipant(Request $request, QatCycle $cycle)
    {
        $validated = $request->validate($this->participantRules());

        $validated['qat_cycle_id'] = $cycle->id;
        $validated['order'] = ((int) $cycle->participants()->max('order')) + 1;

        QatParticipant::create($validated);
        $this->qat->ensureCells($cycle);

        return redirect()->back()->with('success', 'Participant added.');
    }

    public function updateParticipant(Request $request, QatCycle $cycle, QatParticipant $participant)
    {
        abort_unless($participant->qat_cycle_id === $cycle->id, 404);

        $participant->update($request->validate($this->participantRules()));
        $this->qat->ensureCells($cycle);

        return redirect()->back()->with('success', 'Participant updated.');
    }

    public function destroyParticipant(QatCycle $cycle, QatParticipant $participant)
    {
        abort_unless($participant->qat_cycle_id === $cycle->id, 404);

        DB::transaction(function () use ($participant) {
            $resultIds = QatCaseResult::where('qat_participant_id', $participant->id)->pluck('id');
            QatEvidence::whereIn('qat_case_result_id', $resultIds)->delete();
            QatCaseResult::whereIn('id', $resultIds)->delete();
            QatSignoff::where('qat_participant_id', $participant->id)->delete();
            QatFinding::where('qat_participant_id', $participant->id)->update(['qat_participant_id' => null]);
            $participant->delete();
        });

        return redirect()->back()->with('success', 'Participant removed along with their verdicts.');
    }

    // ------------------------------------------------------------------
    // Verdicts
    // ------------------------------------------------------------------

    public function storeResult(Request $request, QatCycle $cycle)
    {
        $validated = $request->validate([
            'qat_case_id' => ['required', Rule::exists('qat_cases', 'id')->where('qat_cycle_id', $cycle->id)],
            'qat_participant_id' => ['required', Rule::exists('qat_participants', 'id')->where('qat_cycle_id', $cycle->id)],
            'result' => ['required', Rule::in(array_keys(QatCaseResult::results()))],
            'remarks' => 'nullable|string|max:8000',
        ]);

        // A cycle awaiting a decision is frozen: the manager must be deciding on
        // the same evidence they were shown.
        abort_unless($cycle->isOpen(), 422, 'This cycle is closed to new verdicts.');

        QatCaseResult::updateOrCreate(
            [
                'qat_case_id' => $validated['qat_case_id'],
                'qat_participant_id' => $validated['qat_participant_id'],
            ],
            [
                'qat_cycle_id' => $cycle->id,
                'result' => $validated['result'],
                'remarks' => $validated['remarks'] ?? null,
                'executed_at' => $validated['result'] === QatCaseResult::PENDING ? null : now(),
                'executed_by_user_id' => $request->user()->id,
                'executed_by_name' => $request->user()->name,
            ]
        );

        return redirect()->back()->with('success', 'Verdict saved.');
    }

    /**
     * Fills a whole row (one case, every participant) or column (one participant,
     * every case) in a single action — the matrix equivalent of dragging a value
     * down a spreadsheet.
     */
    public function bulkResults(Request $request, QatCycle $cycle)
    {
        $validated = $request->validate([
            'scope' => ['required', Rule::in(['case', 'participant', 'section'])],
            'qat_case_id' => ['nullable', Rule::exists('qat_cases', 'id')->where('qat_cycle_id', $cycle->id)],
            'qat_participant_id' => ['nullable', Rule::exists('qat_participants', 'id')->where('qat_cycle_id', $cycle->id)],
            'qat_section_id' => ['nullable', Rule::exists('qat_sections', 'id')->where('qat_cycle_id', $cycle->id)],
            'result' => ['required', Rule::in(array_keys(QatCaseResult::results()))],
            'only_pending' => 'boolean',
        ]);

        abort_unless($cycle->isOpen(), 422, 'This cycle is closed to new verdicts.');

        $this->qat->ensureCells($cycle);

        $query = QatCaseResult::where('qat_cycle_id', $cycle->id);

        if ($validated['scope'] === 'case') {
            abort_unless($validated['qat_case_id'] ?? null, 422, 'A test case is required.');
            $query->where('qat_case_id', $validated['qat_case_id']);
        } elseif ($validated['scope'] === 'participant') {
            abort_unless($validated['qat_participant_id'] ?? null, 422, 'A participant is required.');
            $query->where('qat_participant_id', $validated['qat_participant_id']);
        } else {
            abort_unless($validated['qat_section_id'] ?? null, 422, 'A section is required.');
            $caseIds = QatCase::where('qat_cycle_id', $cycle->id)
                ->where('qat_section_id', $validated['qat_section_id'])
                ->pluck('id');
            $query->whereIn('qat_case_id', $caseIds);

            if ($validated['qat_participant_id'] ?? null) {
                $query->where('qat_participant_id', $validated['qat_participant_id']);
            }
        }

        // Default protects verdicts somebody already recorded.
        if ($request->boolean('only_pending', true)) {
            $query->where('result', QatCaseResult::PENDING);
        }

        $updated = $query->update([
            'result' => $validated['result'],
            'executed_at' => $validated['result'] === QatCaseResult::PENDING ? null : now(),
            'executed_by_user_id' => $request->user()->id,
            'executed_by_name' => $request->user()->name,
            'updated_at' => now(),
        ]);

        return redirect()->back()->with('success', "{$updated} verdict(s) updated.");
    }

    // ------------------------------------------------------------------
    // Evidence
    // ------------------------------------------------------------------

    public function storeEvidence(Request $request, QatCycle $cycle)
    {
        $validated = $request->validate([
            'qat_case_result_id' => ['nullable', Rule::exists('qat_case_results', 'id')->where('qat_cycle_id', $cycle->id)],
            'qat_finding_id' => ['nullable', Rule::exists('qat_findings', 'id')->where('qat_cycle_id', $cycle->id)],
            'label' => 'nullable|string|max:40',
            'files' => 'required|array|max:10',
            'files.*' => 'file|max:10240|mimes:png,jpg,jpeg,gif,webp,pdf,doc,docx,xlsx,txt,log',
        ]);

        if (! ($validated['qat_case_result_id'] ?? null) && ! ($validated['qat_finding_id'] ?? null)) {
            throw ValidationException::withMessages([
                'files' => 'Evidence must be attached to either a verdict or a finding.',
            ]);
        }

        foreach ($request->file('files') as $file) {
            $path = $file->store("qat/{$cycle->id}", 'public');

            QatEvidence::create([
                'qat_cycle_id' => $cycle->id,
                'qat_case_result_id' => $validated['qat_case_result_id'] ?? null,
                'qat_finding_id' => $validated['qat_finding_id'] ?? null,
                'label' => $validated['label'] ?? null,
                'file_name' => $file->getClientOriginalName(),
                'file_path' => $path,
                'mime_type' => $file->getClientMimeType(),
                'file_size' => $file->getSize(),
                'uploaded_by_user_id' => $request->user()->id,
                'uploaded_by_name' => $request->user()->name,
            ]);
        }

        return redirect()->back()->with('success', 'Evidence uploaded.');
    }

    public function destroyEvidence(QatCycle $cycle, QatEvidence $evidence)
    {
        abort_unless($evidence->qat_cycle_id === $cycle->id, 404);

        Storage::disk('public')->delete($evidence->file_path);
        $evidence->delete();

        return redirect()->back()->with('success', 'Evidence removed.');
    }

    // ------------------------------------------------------------------
    // Workbook
    // ------------------------------------------------------------------

    public function template()
    {
        return $this->streamWorkbook($this->workbook->template(), 'qat-import-template.xlsx');
    }

    public function export(QatCycle $cycle)
    {
        $filename = str($cycle->code.'-'.$cycle->title)->slug()->value().'.xlsx';

        return $this->streamWorkbook($this->workbook->export($cycle), $filename);
    }

    public function import(Request $request, QatCycle $cycle)
    {
        $request->validate(['file' => 'required|file|mimes:xlsx,xls|max:10240']);

        try {
            $summary = $this->workbook->import($cycle, $request->file('file')->getRealPath());
        } catch (Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages([
                'file' => 'The workbook could not be read. Check that it is a valid .xlsx file and try again.',
            ]);
        }

        if ($summary['errors'] !== [] && $summary['cases'] === 0) {
            throw ValidationException::withMessages(['file' => $summary['errors'][0]]);
        }

        $message = "Imported {$summary['cases']} test case(s)";
        if ($summary['participants'] > 0) {
            $message .= ", {$summary['participants']} participant column(s)";
        }
        if ($summary['skipped'] > 0) {
            $message .= ". {$summary['skipped']} row(s) skipped as duplicates";
        }

        return redirect()->back()->with('success', $message.'.');
    }

    private function streamWorkbook($spreadsheet, string $filename)
    {
        $writer = new Xlsx($spreadsheet);

        return response()->stream(function () use ($writer) {
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'max-age=0',
        ]);
    }

    // ------------------------------------------------------------------
    // Shared
    // ------------------------------------------------------------------

    private function cycleRules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'system_name' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:4000',
            'cycle_no' => 'required|integer|min:1|max:99',
            'environment' => 'required|string|max:60',
            'links' => 'nullable|array|max:10',
            'links.*.label' => 'nullable|string|max:80',
            'links.*.url' => 'required_with:links.*.label|nullable|url|max:500',
            'company_id' => 'nullable|exists:companies,id',
            'department_id' => 'nullable|exists:departments,id',
            'qa_lead_id' => 'nullable|exists:users,id',
            'dev_lead_id' => 'nullable|exists:users,id',
            'uat_cycle_id' => 'nullable|exists:uat_cycles,id',
            'status' => ['required', Rule::in(array_keys(QatCycle::statuses()))],
            'start_date' => 'nullable|date',
            'target_signoff_date' => 'nullable|date|after_or_equal:start_date',
            'go_live_date' => 'nullable|date',
            'gate_on_critical_only' => 'boolean',
        ];
    }

    private function caseRules(QatCycle $cycle, ?int $ignoreId = null): array
    {
        return [
            'qat_section_id' => ['nullable', Rule::exists('qat_sections', 'id')->where('qat_cycle_id', $cycle->id)],
            'case_key' => [
                'nullable', 'string', 'max:40',
                Rule::unique('qat_cases', 'case_key')
                    ->where(fn ($q) => $q->where('qat_cycle_id', $cycle->id))
                    ->ignore($ignoreId),
            ],
            'screen' => 'nullable|string|max:255',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:8000',
            'steps' => 'nullable|string|max:20000',
            'expected_results' => 'nullable|string|max:8000',
            'is_critical' => 'boolean',
            'priority' => ['required', Rule::in(array_keys(QatCase::priorities()))],
        ];
    }

    private function participantRules(): array
    {
        return [
            'kind' => ['required', Rule::in(array_keys(QatParticipant::kinds()))],
            'label' => 'required|string|max:80',
            'department_id' => 'nullable|exists:departments,id',
            'company_id' => 'nullable|exists:companies,id',
            'user_id' => 'nullable|exists:users,id',
            'contact_name' => 'nullable|string|max:255',
            'contact_email' => 'nullable|email|max:255',
            'role' => ['required', Rule::in(array_keys(QatParticipant::roles()))],
            'is_active' => 'boolean',
        ];
    }

    /** @return array<int,array{label:string,value:string}> */
    private function options(array $map): array
    {
        return collect($map)->map(fn ($label, $value) => ['label' => $label, 'value' => $value])->values()->all();
    }

    /**
     * Active departments, carrying the code separately so the participant form can
     * use it as the matrix column heading.
     */
    private function departmentOptions(): array
    {
        return Department::where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code'])
            ->map(fn ($d) => [
                'label' => $d->code ? "{$d->code} — {$d->name}" : $d->name,
                'value' => $d->id,
                'code' => $d->code,
                'name' => $d->name,
            ])
            ->all();
    }

    private function userOptions(): array
    {
        return User::where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->map(fn ($u) => ['label' => $u->name, 'value' => $u->id, 'email' => $u->email])
            ->all();
    }

    /**
     * UAT cycles offered as a copy source or a link target.
     *
     * Only shown to users who can see the UAT module at all — the picker would
     * otherwise leak cycle titles to somebody with no access to them.
     */
    private function uatCycleOptions(?User $user): array
    {
        if (! $user || ! $user->can('uat.view')) {
            return [];
        }

        return UatCycle::query()
            ->orderByDesc('id')
            ->limit(200)
            ->get(['id', 'code', 'title'])
            ->map(fn ($c) => ['label' => "{$c->code} — {$c->title}", 'value' => $c->id])
            ->all();
    }
}
