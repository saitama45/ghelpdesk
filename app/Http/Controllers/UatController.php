<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\UatCase;
use App\Models\UatCaseResult;
use App\Models\UatCycle;
use App\Models\UatEvidence;
use App\Models\UatFinding;
use App\Models\UatParticipant;
use App\Models\UatSection;
use App\Models\UatSignoff;
use App\Models\User;
use App\Services\UatService;
use App\Services\UatWorkbook;
use App\Support\CompanyContext;
use App\Support\DepartmentContext;
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

class UatController extends Controller implements HasMiddleware
{
    /**
     * Case columns safe to ship with a full listing. `steps`,
     * `expected_results` and `description` are nvarchar(MAX) and a 96-case cycle
     * drags hundreds of KB over the link — they are fetched one case at a time
     * by caseDetail() instead.
     */
    private const CASE_LIST_COLUMNS = [
        'id', 'uat_cycle_id', 'uat_section_id', 'case_key', 'screen', 'title',
        'is_critical', 'priority', 'order',
    ];

    public function __construct(private UatService $uat, private UatWorkbook $workbook) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:uat.view', only: ['index', 'show', 'caseDetail']),
            new Middleware('can:uat.edit', only: ['editData']),
            new Middleware('can:uat.create', only: ['store', 'duplicate']),
            new Middleware('can:uat.edit', only: [
                'update', 'storeSection', 'updateSection', 'destroySection',
                'storeCase', 'updateCase', 'destroyCase', 'reorderCases',
                'storeParticipant', 'updateParticipant', 'destroyParticipant',
                'issueToken', 'revokeToken',
            ]),
            new Middleware('can:uat.execute', only: ['storeResult', 'bulkResults', 'storeEvidence', 'destroyEvidence']),
            new Middleware('can:uat.signoff', only: ['storeSignoff']),
            new Middleware('can:uat.approve', only: ['finalSignoff']),
            new Middleware('can:uat.delete', only: ['destroy']),
            new Middleware('can:uat.export', only: ['export', 'template']),
            new Middleware('can:uat.import', only: ['import']),
        ];
    }

    // ------------------------------------------------------------------
    // Cycles
    // ------------------------------------------------------------------

    public function index(Request $request)
    {
        $query = UatCycle::query()
            ->select([
                'id', 'code', 'title', 'system_name', 'cycle_no', 'environment', 'status',
                'company_id', 'department_id', 'qa_lead_id', 'start_date',
                'target_signoff_date', 'go_live_date', 'created_at',
            ])
            ->with([
                'company:id,name',
                'department:id,name',
                'qaLead:id,name',
            ])
            ->withCount('cases');

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

        // Department axis: a cycle owned by another department is somebody else's
        // work, so it is scoped out of the listing entirely rather than merely
        // filtered. Cycles with no owning department are shared and stay visible.
        //
        // Executive mode sees every department by design, and Dev/Admin/Solutions
        // Admin can already switch "I belong to" — that is the escape hatch, so
        // no separate override permission is needed here.
        $user = $request->user();

        if (!DepartmentContext::isExecutive($user)) {
            $homeDepartmentId = DepartmentContext::homeDepartmentId($user);

            $query->where(function ($q) use ($homeDepartmentId, $user) {
                $q->whereNull('department_id');

                if ($homeDepartmentId) {
                    $q->orWhere('department_id', $homeDepartmentId);
                }

                // The assigned Dev builds what is being tested and is routinely
                // from a different department to the one that owns the cycle.
                // Being named on it is the grant — without this they could not
                // see the cycle reporting bugs against their own work.
                $q->orWhere('dev_lead_id', $user->id);
            });
        }

        // Entity filter is a listing convenience, not an authorisation boundary.
        // Defaults to every entity: a UAT cycle is a project-shaped thing that
        // often spans entities, and scoping it to the active one by default hid
        // cycles the user had just created.
        $entity = $request->input('company_id', 'all');

        // Counted before the entity filter narrows things, so the page can say
        // how many rows it is hiding. Creating a cycle under one entity and then
        // not finding it under another is otherwise silent and baffling.
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

        // Headline tallies for every listed cycle, in two queries rather than N.
        $summaries = $this->listSummaries(collect($cycles->items())->pluck('id')->all());
        $cycles->getCollection()->transform(function (UatCycle $cycle) use ($summaries) {
            $cycle->summary = $summaries[$cycle->id] ?? null;

            return $cycle;
        });

        return Inertia::render('Uat/Index', [
            'cycles' => $cycles,
            'hiddenByEntity' => $hiddenByEntity,
            'activeCompanyId' => CompanyContext::activeCompanyId(),
            'filters' => [
                'search' => $request->input('search'),
                'status' => $request->input('status'),
                'company_id' => (string) $entity,
            ],
            'statuses' => $this->options(UatCycle::statuses()),
            'environments' => collect(UatCycle::environments())->map(fn ($e) => ['label' => $e, 'value' => $e])->all(),
            'companies' => CompanyContext::accessibleCompanies($request->user())
                ->map(fn ($c) => ['label' => $c->name, 'value' => $c->id])->values()->all(),
            'departments' => $this->departmentOptions(),
            'users' => $this->userOptions(),
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

        // Worst-wins per case: a case is failed if any participant failed it,
        // pending if any participant has not answered, otherwise passed.
        $rows = DB::table('uat_case_results as r')
            ->join('uat_cases as c', 'c.id', '=', 'r.uat_case_id')
            ->whereIn('r.uat_cycle_id', $cycleIds)
            ->groupBy('r.uat_cycle_id', 'r.uat_case_id')
            ->select([
                'r.uat_cycle_id',
                'r.uat_case_id',
                DB::raw("SUM(CASE WHEN r.result = 'failed' THEN 1 ELSE 0 END) as failed"),
                DB::raw("SUM(CASE WHEN r.result = 'blocked' THEN 1 ELSE 0 END) as blocked"),
                DB::raw("SUM(CASE WHEN r.result = 'pending' THEN 1 ELSE 0 END) as pending"),
                DB::raw("SUM(CASE WHEN r.result = 'passed' THEN 1 ELSE 0 END) as passed"),
            ])
            ->get();

        $summaries = [];
        foreach ($rows as $row) {
            $bucket = $summaries[$row->uat_cycle_id] ??= ['passed' => 0, 'failed' => 0, 'blocked' => 0, 'pending' => 0, 'total' => 0];
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

            $summaries[$row->uat_cycle_id] = $bucket;
        }

        $openFindings = DB::table('uat_findings')
            ->whereIn('uat_cycle_id', $cycleIds)
            ->whereIn('status', UatFinding::UNRESOLVED_STATUSES)
            ->groupBy('uat_cycle_id')
            ->select('uat_cycle_id', DB::raw('COUNT(*) as total'))
            ->pluck('total', 'uat_cycle_id');

        foreach ($summaries as $cycleId => $bucket) {
            $summaries[$cycleId]['open_findings'] = (int) ($openFindings[$cycleId] ?? 0);
        }

        return $summaries;
    }

    public function store(Request $request)
    {
        $validated = $request->validate($this->cycleRules());

        $cycle = $this->createWithUniqueCode($validated, $request->user()->id);

        return redirect()->route('uat.show', $cycle->id)
            ->with('success', "UAT cycle {$cycle->code} created.");
    }

    /** Retries once on the code unique-index race. */
    private function createWithUniqueCode(array $attributes, int $userId): UatCycle
    {
        foreach (range(1, 3) as $attempt) {
            try {
                return UatCycle::create(array_merge($attributes, [
                    'code' => UatCycle::nextCode(),
                    'created_by' => $userId,
                    'updated_by' => $userId,
                ]));
            } catch (Throwable $e) {
                if ($attempt === 3 || !str_contains(strtolower($e->getMessage()), 'duplicate')) {
                    throw $e;
                }
            }
        }

        throw ValidationException::withMessages(['title' => 'Could not allocate a cycle code. Please try again.']);
    }

    public function show(Request $request, UatCycle $cycle)
    {
        $cycle->load([
            'company:id,name',
            'department:id,name',
            'qaLead:id,name,email',
            'devLead:id,name,email',
            'creator:id,name',
        ]);

        $sections = $cycle->sections()->get();
        $cases = $cycle->cases()->get(self::CASE_LIST_COLUMNS);
        $participants = $cycle->participants()->with([
            'user:id,name,email',
            'department:id,name',
            'company:id,name',
            'currentSignoff',
        ])->get();
        $results = $cycle->results()->get([
            'id', 'uat_case_id', 'uat_participant_id', 'result', 'remarks',
            'executed_at', 'executed_by_user_id', 'executed_by_name',
        ]);
        $findings = $cycle->findings()
            ->with([
                'assignee:id,name', 'testCase:id,case_key,title',
                // created_at drives the "ticket raised" timestamp in the register.
                'ticket:id,ticket_key,status,created_at', 'participant:id,label',
                // Screenshots are mandatory on a finding, so the register shows them.
                'evidence',
            ])
            ->orderByRaw("CASE severity WHEN 'blocker' THEN 1 WHEN 'major' THEN 2 WHEN 'minor' THEN 3 ELSE 4 END")
            ->orderByDesc('id')
            ->get();

        // Evidence counts only — the files themselves load with the case drawer.
        $evidenceCounts = DB::table('uat_evidence')
            ->where('uat_cycle_id', $cycle->id)
            ->whereNotNull('uat_case_result_id')
            ->groupBy('uat_case_result_id')
            ->select('uat_case_result_id', DB::raw('COUNT(*) as total'))
            ->pluck('total', 'uat_case_result_id');

        $results->each(function ($result) use ($evidenceCounts) {
            $result->evidence_count = (int) ($evidenceCounts[$result->id] ?? 0);
        });

        $signoffs = $cycle->signoffs()
            ->with('confirmedBy:id,name,email')
            ->orderByDesc('confirmed_at')
            ->get();

        return Inertia::render('Uat/Show', [
            'cycle' => $cycle,
            'sections' => $sections,
            'cases' => $cases,
            'participants' => $participants->map(fn (UatParticipant $p) => array_merge($p->toArray(), [
                'display_name' => $p->display_name,
                'display_email' => $p->display_email,
                'has_token' => (bool) $p->getRawOriginal('access_token'),
                'portal_url' => $p->tokenIsValid()
                    ? route('public.uat.portal', $p->getRawOriginal('access_token'))
                    : null,
            ])),
            'results' => $results,
            'findings' => $findings,
            'signoffs' => $signoffs,
            'statistics' => $this->uat->statistics($cases, $results, $participants),
            'participantProgress' => $this->uat->participantProgress($participants, $cases, $results),
            'sectionProgress' => $this->uat->sectionProgress($cases, $results, $participants),
            // The matrix renders ONE column per department, not one per person.
            'columns' => $this->uat->columns($participants),
            'readiness' => $this->uat->readiness($cycle, $cases, $results, $findings, $participants),
            'acceptance' => $this->uat->acceptanceLedger($participants),
            'options' => [
                'statuses' => $this->options(UatCycle::statuses()),
                'environments' => collect(UatCycle::environments())->map(fn ($e) => ['label' => $e, 'value' => $e])->all(),
                'results' => $this->options(UatCaseResult::results()),
                'priorities' => $this->options(UatCase::priorities()),
                'severities' => $this->options(UatFinding::severities()),
                'findingStatuses' => $this->options(UatFinding::statuses()),
                'signoffResults' => $this->options(UatSignoff::results()),
                'participantKinds' => $this->options(UatParticipant::kinds()),
                'participantRoles' => $this->options(UatParticipant::roles()),
                'companies' => CompanyContext::accessibleCompanies($request->user())
                    ->map(fn ($c) => ['label' => $c->name, 'value' => $c->id])->values()->all(),
                'departments' => $this->departmentOptions(),
                'users' => $this->userOptions(),
            ],
        ]);
    }

    /**
     * Every editable field of a cycle, for the edit form.
     *
     * The index listing deliberately selects a narrow column set (it never needs
     * `description` or `links`, which are nvarchar(MAX)). Handing that partial
     * row to the edit form meant the missing fields posted back as blank and
     * silently wiped stored values, so the form fetches the whole record here
     * instead of trusting whatever the calling page happened to have.
     */
    public function editData(UatCycle $cycle)
    {
        return response()->json([
            'cycle' => array_merge($cycle->only([
                'id', 'code', 'title', 'system_name', 'description', 'cycle_no',
                'environment', 'links', 'company_id', 'department_id',
                'qa_lead_id', 'dev_lead_id', 'status',
                'signoff_requires_all', 'gate_on_critical_only',
            ]), [
                // Formatted, not handed over as Carbon. `only()` returns the raw
                // cast value, and json_encode renders a Carbon in UTC — which
                // moves an Asia/Manila calendar date back a day (2026-08-01
                // became 2026-07-31T16:00Z), so every save walked dates
                // backwards. These columns are calendar dates with no time.
                'start_date' => $cycle->start_date?->format('Y-m-d'),
                'target_signoff_date' => $cycle->target_signoff_date?->format('Y-m-d'),
                'go_live_date' => $cycle->go_live_date?->format('Y-m-d'),
            ]),
        ]);
    }

    /** Full text for one case, plus its verdicts, evidence and findings. */
    public function caseDetail(UatCycle $cycle, UatCase $case)
    {
        abort_unless($case->uat_cycle_id === $cycle->id, 404);

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

    public function update(Request $request, UatCycle $cycle)
    {
        $validated = $request->validate($this->cycleRules());
        $validated['updated_by'] = $request->user()->id;

        $cycle->update($validated);

        return redirect()->back()->with('success', 'UAT cycle updated.');
    }

    public function destroy(UatCycle $cycle)
    {
        $code = $cycle->code;
        $cycle->cascadeDelete();

        return redirect()->route('uat.index')->with('success', "UAT cycle {$code} deleted.");
    }

    /**
     * Clones the structure — sections, cases and the participant roster — into a
     * fresh cycle with empty verdicts. This is how a re-test round starts, and
     * why the source workbook was called "3rd cycle".
     */
    public function duplicate(Request $request, UatCycle $cycle)
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
                'status' => UatCycle::STATUS_DRAFT,
                'signoff_requires_all' => $cycle->signoff_requires_all,
                'gate_on_critical_only' => $cycle->gate_on_critical_only,
            ], $request->user()->id);

            $sectionMap = [];
            foreach ($cycle->sections as $section) {
                $sectionMap[$section->id] = UatSection::create([
                    'uat_cycle_id' => $new->id,
                    'name' => $section->name,
                    'description' => $section->description,
                    'is_critical' => $section->is_critical,
                    'order' => $section->order,
                ])->id;
            }

            foreach ($cycle->cases()->get() as $case) {
                UatCase::create([
                    'uat_cycle_id' => $new->id,
                    'uat_section_id' => $sectionMap[$case->uat_section_id] ?? null,
                    'case_key' => $case->case_key,
                    'screen' => $case->screen,
                    'title' => $case->title,
                    'description' => $case->description,
                    'steps' => $case->steps,
                    'expected_results' => $case->expected_results,
                    'is_critical' => $case->is_critical,
                    'priority' => $case->priority,
                    'order' => $case->order,
                    'created_by' => $request->user()->id,
                    'updated_by' => $request->user()->id,
                ]);
            }

            if ($request->boolean('copy_participants', true)) {
                foreach ($cycle->participants as $participant) {
                    UatParticipant::create([
                        'uat_cycle_id' => $new->id,
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
                        // Tokens are per-cycle credentials; the new round issues its own.
                        'access_token' => null,
                    ]);
                }
            }

            $this->uat->ensureCells($new);

            return $new;
        });

        return redirect()->route('uat.show', $copy->id)
            ->with('success', "Cycle duplicated as {$copy->code}.");
    }

    // ------------------------------------------------------------------
    // Sections
    // ------------------------------------------------------------------

    public function storeSection(Request $request, UatCycle $cycle)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:255',
            'is_critical' => 'boolean',
        ]);

        $validated['uat_cycle_id'] = $cycle->id;
        $validated['order'] = ((int) $cycle->sections()->max('order')) + 1;

        UatSection::create($validated);

        return redirect()->back()->with('success', 'Section added.');
    }

    public function updateSection(Request $request, UatCycle $cycle, UatSection $section)
    {
        abort_unless($section->uat_cycle_id === $cycle->id, 404);

        $section->update($request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:255',
            'is_critical' => 'boolean',
            'order' => 'nullable|integer|min:0',
        ]));

        return redirect()->back()->with('success', 'Section updated.');
    }

    public function destroySection(UatCycle $cycle, UatSection $section)
    {
        abort_unless($section->uat_cycle_id === $cycle->id, 404);

        // Cases outlive their section — they fall back to the ungrouped bucket
        // rather than being silently deleted with it.
        UatCase::where('uat_section_id', $section->id)->update(['uat_section_id' => null]);
        $section->delete();

        return redirect()->back()->with('success', 'Section removed. Its test cases were kept and ungrouped.');
    }

    // ------------------------------------------------------------------
    // Cases
    // ------------------------------------------------------------------

    public function storeCase(Request $request, UatCycle $cycle)
    {
        $validated = $request->validate($this->caseRules($cycle));

        $validated['uat_cycle_id'] = $cycle->id;
        $validated['case_key'] = $validated['case_key']
            ?: UatCase::nextKey($cycle->id, UatCase::keyPrefix($cycle->id));
        $validated['order'] = ((int) $cycle->cases()->max('order')) + 1;
        $validated['created_by'] = $request->user()->id;
        $validated['updated_by'] = $request->user()->id;

        UatCase::create($validated);
        $this->uat->ensureCells($cycle);

        return redirect()->back()->with('success', 'Test case added.');
    }

    public function updateCase(Request $request, UatCycle $cycle, UatCase $case)
    {
        abort_unless($case->uat_cycle_id === $cycle->id, 404);

        $validated = $request->validate($this->caseRules($cycle, $case->id));
        $validated['case_key'] = $validated['case_key'] ?: $case->case_key;
        $validated['updated_by'] = $request->user()->id;

        $case->update($validated);

        return redirect()->back()->with('success', 'Test case updated.');
    }

    public function destroyCase(UatCycle $cycle, UatCase $case)
    {
        abort_unless($case->uat_cycle_id === $cycle->id, 404);

        DB::transaction(function () use ($case) {
            $resultIds = UatCaseResult::where('uat_case_id', $case->id)->pluck('id');
            UatEvidence::whereIn('uat_case_result_id', $resultIds)->delete();
            UatCaseResult::whereIn('id', $resultIds)->delete();
            // Findings survive as cycle-level records; only the link is cleared.
            UatFinding::where('uat_case_id', $case->id)->update(['uat_case_id' => null]);
            $case->delete();
        });

        return redirect()->back()->with('success', 'Test case deleted.');
    }

    public function reorderCases(Request $request, UatCycle $cycle)
    {
        $validated = $request->validate([
            'order' => 'required|array',
            'order.*' => 'integer',
        ]);

        DB::transaction(function () use ($validated, $cycle) {
            foreach ($validated['order'] as $position => $caseId) {
                UatCase::where('uat_cycle_id', $cycle->id)
                    ->whereKey($caseId)
                    ->update(['order' => $position + 1]);
            }
        });

        return redirect()->back()->with('success', 'Order saved.');
    }

    // ------------------------------------------------------------------
    // Participants
    // ------------------------------------------------------------------

    public function storeParticipant(Request $request, UatCycle $cycle)
    {
        $validated = $request->validate($this->participantRules());

        $validated['uat_cycle_id'] = $cycle->id;
        $validated['order'] = ((int) $cycle->participants()->max('order')) + 1;

        UatParticipant::create($validated);
        $this->uat->ensureCells($cycle);

        return redirect()->back()->with('success', 'Participant added.');
    }

    public function updateParticipant(Request $request, UatCycle $cycle, UatParticipant $participant)
    {
        abort_unless($participant->uat_cycle_id === $cycle->id, 404);

        $participant->update($request->validate($this->participantRules()));
        $this->uat->ensureCells($cycle);

        return redirect()->back()->with('success', 'Participant updated.');
    }

    public function destroyParticipant(UatCycle $cycle, UatParticipant $participant)
    {
        abort_unless($participant->uat_cycle_id === $cycle->id, 404);

        DB::transaction(function () use ($participant) {
            $resultIds = UatCaseResult::where('uat_participant_id', $participant->id)->pluck('id');
            UatEvidence::whereIn('uat_case_result_id', $resultIds)->delete();
            UatCaseResult::whereIn('id', $resultIds)->delete();
            UatSignoff::where('uat_participant_id', $participant->id)->delete();
            UatFinding::where('uat_participant_id', $participant->id)->update(['uat_participant_id' => null]);
            $participant->delete();
        });

        return redirect()->back()->with('success', 'Participant removed along with their verdicts.');
    }

    public function issueToken(Request $request, UatCycle $cycle, UatParticipant $participant)
    {
        abort_unless($participant->uat_cycle_id === $cycle->id, 404);

        $validated = $request->validate([
            'valid_days' => 'nullable|integer|min:1|max:365',
        ]);

        $participant->issueToken($validated['valid_days'] ?? 60);

        return redirect()->back()->with('success', "Access link issued for {$participant->label}. Copy it from the roster and send it over.");
    }

    public function revokeToken(UatCycle $cycle, UatParticipant $participant)
    {
        abort_unless($participant->uat_cycle_id === $cycle->id, 404);

        $participant->revokeToken();

        return redirect()->back()->with('success', "Access link revoked for {$participant->label}.");
    }

    // ------------------------------------------------------------------
    // Verdicts
    // ------------------------------------------------------------------

    public function storeResult(Request $request, UatCycle $cycle)
    {
        $validated = $request->validate([
            'uat_case_id' => ['required', Rule::exists('uat_cases', 'id')->where('uat_cycle_id', $cycle->id)],
            'uat_participant_id' => ['required', Rule::exists('uat_participants', 'id')->where('uat_cycle_id', $cycle->id)],
            'result' => ['required', Rule::in(array_keys(UatCaseResult::results()))],
            'remarks' => 'nullable|string|max:8000',
        ]);

        abort_unless($cycle->isOpen(), 422, 'This cycle is closed to new verdicts.');

        UatCaseResult::updateOrCreate(
            [
                'uat_case_id' => $validated['uat_case_id'],
                'uat_participant_id' => $validated['uat_participant_id'],
            ],
            [
                'uat_cycle_id' => $cycle->id,
                'result' => $validated['result'],
                'remarks' => $validated['remarks'] ?? null,
                'executed_at' => $validated['result'] === UatCaseResult::PENDING ? null : now(),
                'executed_by_user_id' => $request->user()->id,
                'executed_by_name' => $request->user()->name,
                'source' => 'internal',
            ]
        );

        return redirect()->back()->with('success', 'Verdict saved.');
    }

    /**
     * Fills a whole row (one case, every participant) or column (one
     * participant, every case) in a single action — the matrix equivalent of
     * dragging a value down a spreadsheet.
     */
    public function bulkResults(Request $request, UatCycle $cycle)
    {
        $validated = $request->validate([
            'scope' => ['required', Rule::in(['case', 'participant', 'section'])],
            'uat_case_id' => ['nullable', Rule::exists('uat_cases', 'id')->where('uat_cycle_id', $cycle->id)],
            'uat_participant_id' => ['nullable', Rule::exists('uat_participants', 'id')->where('uat_cycle_id', $cycle->id)],
            'uat_section_id' => ['nullable', Rule::exists('uat_sections', 'id')->where('uat_cycle_id', $cycle->id)],
            'result' => ['required', Rule::in(array_keys(UatCaseResult::results()))],
            'only_pending' => 'boolean',
        ]);

        abort_unless($cycle->isOpen(), 422, 'This cycle is closed to new verdicts.');

        $this->uat->ensureCells($cycle);

        $query = UatCaseResult::where('uat_cycle_id', $cycle->id);

        if ($validated['scope'] === 'case') {
            abort_unless($validated['uat_case_id'] ?? null, 422, 'A test case is required.');
            $query->where('uat_case_id', $validated['uat_case_id']);
        } elseif ($validated['scope'] === 'participant') {
            abort_unless($validated['uat_participant_id'] ?? null, 422, 'A participant is required.');
            $query->where('uat_participant_id', $validated['uat_participant_id']);
        } else {
            abort_unless($validated['uat_section_id'] ?? null, 422, 'A section is required.');
            $caseIds = UatCase::where('uat_cycle_id', $cycle->id)
                ->where('uat_section_id', $validated['uat_section_id'])
                ->pluck('id');
            $query->whereIn('uat_case_id', $caseIds);

            if ($validated['uat_participant_id'] ?? null) {
                $query->where('uat_participant_id', $validated['uat_participant_id']);
            }
        }

        // Default protects verdicts somebody already recorded.
        if ($request->boolean('only_pending', true)) {
            $query->where('result', UatCaseResult::PENDING);
        }

        $updated = $query->update([
            'result' => $validated['result'],
            'executed_at' => $validated['result'] === UatCaseResult::PENDING ? null : now(),
            'executed_by_user_id' => $request->user()->id,
            'executed_by_name' => $request->user()->name,
            'source' => 'internal',
            'updated_at' => now(),
        ]);

        return redirect()->back()->with('success', "{$updated} verdict(s) updated.");
    }

    // ------------------------------------------------------------------
    // Evidence
    // ------------------------------------------------------------------

    public function storeEvidence(Request $request, UatCycle $cycle)
    {
        $validated = $request->validate([
            'uat_case_result_id' => ['nullable', Rule::exists('uat_case_results', 'id')->where('uat_cycle_id', $cycle->id)],
            'uat_finding_id' => ['nullable', Rule::exists('uat_findings', 'id')->where('uat_cycle_id', $cycle->id)],
            'label' => 'nullable|string|max:40',
            'files' => 'required|array|max:10',
            'files.*' => 'file|max:10240|mimes:png,jpg,jpeg,gif,webp,pdf,doc,docx,xlsx,txt,log',
        ]);

        if (!($validated['uat_case_result_id'] ?? null) && !($validated['uat_finding_id'] ?? null)) {
            throw ValidationException::withMessages([
                'files' => 'Evidence must be attached to either a verdict or a finding.',
            ]);
        }

        foreach ($request->file('files') as $file) {
            $path = $file->store("uat/{$cycle->id}", 'public');

            UatEvidence::create([
                'uat_cycle_id' => $cycle->id,
                'uat_case_result_id' => $validated['uat_case_result_id'] ?? null,
                'uat_finding_id' => $validated['uat_finding_id'] ?? null,
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

    public function destroyEvidence(UatCycle $cycle, UatEvidence $evidence)
    {
        abort_unless($evidence->uat_cycle_id === $cycle->id, 404);

        Storage::disk('public')->delete($evidence->file_path);
        $evidence->delete();

        return redirect()->back()->with('success', 'Evidence removed.');
    }

    // ------------------------------------------------------------------
    // Sign-off
    // ------------------------------------------------------------------

    public function storeSignoff(Request $request, UatCycle $cycle)
    {
        $validated = $request->validate([
            'uat_participant_id' => ['required', Rule::exists('uat_participants', 'id')->where('uat_cycle_id', $cycle->id)],
            'result' => ['required', Rule::in(array_keys(UatSignoff::results()))],
            'remarks' => 'nullable|string|max:4000',
        ]);

        $participant = UatParticipant::findOrFail($validated['uat_participant_id']);

        // A reservation must say what it is reserving — that was the whole point
        // of the column in the source pack.
        if ($validated['result'] !== UatSignoff::RESULT_PASSED && blank($validated['remarks'] ?? null)) {
            throw ValidationException::withMessages([
                'remarks' => 'Explain the reservation or the reason for not accepting.',
            ]);
        }

        $this->uat->recordSignoff($cycle, $participant, UatSignoff::STAGE_ACCEPTANCE, [
            'result' => $validated['result'],
            'remarks' => $validated['remarks'] ?? null,
            'confirmed_by_user_id' => $request->user()->id,
            'confirmed_name' => $request->user()->name,
            'confirmed_email' => $request->user()->email,
            'ip_address' => $request->ip(),
        ]);

        return redirect()->back()->with('success', "Acceptance recorded for {$participant->label}.");
    }

    public function finalSignoff(Request $request, UatCycle $cycle)
    {
        $validated = $request->validate([
            'result' => ['required', Rule::in(array_keys(UatSignoff::results()))],
            'remarks' => 'nullable|string|max:4000',
        ]);

        $cases = $cycle->cases()->get(self::CASE_LIST_COLUMNS);
        $results = $cycle->results()->get(['id', 'uat_case_id', 'uat_participant_id', 'result']);
        $findings = $cycle->findings()->get();
        $participants = $cycle->participants()->with('currentSignoff')->get();

        $readiness = $this->uat->readiness($cycle, $cases, $results, $findings, $participants);

        // Accepting is gated; recording a rejection never is.
        if ($validated['result'] !== UatSignoff::RESULT_NOT_ACCEPTED && !$readiness['is_ready']) {
            throw ValidationException::withMessages([
                'result' => 'This cycle is not ready for final sign-off yet. Clear the outstanding items listed on the Sign-off tab first.',
            ]);
        }

        DB::transaction(function () use ($cycle, $validated, $request) {
            $this->uat->recordSignoff($cycle, null, UatSignoff::STAGE_FINAL, [
                'result' => $validated['result'],
                'remarks' => $validated['remarks'] ?? null,
                'confirmed_by_user_id' => $request->user()->id,
                'confirmed_name' => $request->user()->name,
                'confirmed_email' => $request->user()->email,
                'ip_address' => $request->ip(),
            ]);

            if ($validated['result'] !== UatSignoff::RESULT_NOT_ACCEPTED) {
                $cycle->update([
                    'status' => UatCycle::STATUS_SIGNED_OFF,
                    'updated_by' => $request->user()->id,
                ]);
            }
        });

        return redirect()->back()->with('success', 'Final sign-off recorded.');
    }

    // ------------------------------------------------------------------
    // Workbook
    // ------------------------------------------------------------------

    public function template()
    {
        return $this->streamWorkbook($this->workbook->template(), 'uat-import-template.xlsx');
    }

    public function export(UatCycle $cycle)
    {
        $filename = str($cycle->code.'-'.$cycle->title)->slug()->value().'.xlsx';

        return $this->streamWorkbook($this->workbook->export($cycle), $filename);
    }

    public function import(Request $request, UatCycle $cycle)
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
        if ($summary['verdicts'] > 0) {
            $message .= " and {$summary['verdicts']} verdict(s)";
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
            'status' => ['required', Rule::in(array_keys(UatCycle::statuses()))],
            'start_date' => 'nullable|date',
            'target_signoff_date' => 'nullable|date|after_or_equal:start_date',
            'go_live_date' => 'nullable|date',
            'signoff_requires_all' => 'boolean',
            'gate_on_critical_only' => 'boolean',
        ];
    }

    private function caseRules(UatCycle $cycle, ?int $ignoreId = null): array
    {
        return [
            'uat_section_id' => ['nullable', Rule::exists('uat_sections', 'id')->where('uat_cycle_id', $cycle->id)],
            'case_key' => [
                'nullable', 'string', 'max:40',
                Rule::unique('uat_cases', 'case_key')
                    ->where(fn ($q) => $q->where('uat_cycle_id', $cycle->id))
                    ->ignore($ignoreId),
            ],
            'screen' => 'nullable|string|max:255',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:8000',
            'steps' => 'nullable|string|max:20000',
            'expected_results' => 'nullable|string|max:8000',
            'is_critical' => 'boolean',
            'priority' => ['required', Rule::in(array_keys(UatCase::priorities()))],
        ];
    }

    private function participantRules(): array
    {
        return [
            'kind' => ['required', Rule::in(array_keys(UatParticipant::kinds()))],
            'label' => 'required|string|max:80',
            'department_id' => 'nullable|exists:departments,id',
            'company_id' => 'nullable|exists:companies,id',
            'user_id' => 'nullable|exists:users,id',
            'contact_name' => 'nullable|string|max:255',
            'contact_email' => 'nullable|email|max:255',
            'role' => ['required', Rule::in(array_keys(UatParticipant::roles()))],
            'is_active' => 'boolean',
        ];
    }

    /** @return array<int,array{label:string,value:string}> */
    private function options(array $map): array
    {
        return collect($map)->map(fn ($label, $value) => ['label' => $label, 'value' => $value])->values()->all();
    }

    /**
     * Active departments, carrying the code separately so the participant form
     * can use it as the matrix column heading.
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
}
