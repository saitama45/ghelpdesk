<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\NpcStatus;
use App\Models\NpcStatusAttachment;
use App\Models\NpcStatusWorkflowStep;
use App\Models\Store;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class NpcStatusController extends Controller implements HasMiddleware
{
    private const MAX_ATTACHMENT_KILOBYTES = 1024000;

    public static function middleware(): array
    {
        return [
            new Middleware('can:npc_status.view', only: [
                'index',
                'downloadAttachment',
                'downloadStatusAttachment',
                'downloadCctvSealNotice',
            ]),
            new Middleware('can:npc_status.create', only: ['store']),
            new Middleware('can:npc_status.edit', only: [
                'update',
                'syncStores',
                'storeAttachment',
                'destroyAttachment',
                'updateWorkflow',
                'storeCctvSealNotice',
            ]),
            new Middleware('can:npc_status.delete', only: ['destroy']),
        ];
    }

    public function index(Request $request)
    {
        $validated = $request->validate([
            'year' => 'nullable|integer|min:2000|max:2100',
            'status' => ['nullable', Rule::in(NpcStatus::STATUSES)],
            'search' => 'nullable|string|max:255',
            'per_page' => 'nullable|integer|min:5|max:100',
            'page' => 'nullable|integer|min:1',
        ]);

        $year = (int) ($validated['year'] ?? now()->year);
        $status = $validated['status'] ?? null;
        $search = trim((string) ($validated['search'] ?? ''));
        $perPage = (int) ($validated['per_page'] ?? 10);
        $page = (int) ($validated['page'] ?? 1);

        $rows = Company::query()
            ->with(['npcStatuses' => function ($npcQuery) {
                $npcQuery->with([
                        'attachments' => fn ($query) => $query->latest('validity_from')->latest('created_at'),
                        'workflowSteps',
                    ])
                    ->withCount('stores');
            }])
            ->orderBy('name')
            ->get()
            ->map(fn (Company $company) => $this->serializeCompanyRow($company, $year));

        $filtered = $rows
            ->when($status, fn (Collection $items) => $items->filter(fn (array $row) => ($row['npc_status']['renewal_status'] ?? 'No Record') === $status))
            ->when($search !== '', function (Collection $items) use ($search) {
                $needle = Str::lower($search);

                return $items->filter(function (array $row) use ($needle) {
                    $haystack = [
                        $row['name'],
                        $row['code'],
                        $row['npc_status']['renewal_status'] ?? null,
                        $row['npc_status']['workflow_stage'] ?? null,
                    ];

                    return collect($haystack)
                        ->filter()
                        ->contains(fn ($value) => Str::contains(Str::lower((string) $value), $needle));
                });
            })
            ->values();

        $companies = new LengthAwarePaginator(
            $filtered->forPage($page, $perPage)->values(),
            $filtered->count(),
            $perPage,
            $page,
            [
                'path' => $request->url(),
                'query' => $request->query(),
            ]
        );

        return Inertia::render('NpcStatus/Index', [
            'npcStatuses' => $companies,
            'filters' => [
                'year' => $year,
                'status' => $status,
                'search' => $search,
                'per_page' => $perPage,
            ],
            'statuses' => NpcStatus::STATUSES,
            'statusCounts' => $this->statusCounts($year),
            'workflowSteps' => NpcStatus::WORKFLOW_STEPS,
            'stores' => $this->storeOptions($year),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validatePayload($request, true);
        $year = Carbon::parse($validated['validity_from'])->year;

        $exists = NpcStatus::where('company_id', $validated['company_id'])
            ->where('year', $year)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'validity_from' => 'An NPC renewal record already exists for this entity and validity year.',
            ]);
        }

        $npcStatus = new NpcStatus([
            'company_id' => $validated['company_id'],
            'year' => $year,
            'created_by' => $request->user()->id,
        ]);

        $this->fillStatusFields($npcStatus, $validated, $request->user()->id);
        $npcStatus->save();
        $this->ensureWorkflowSteps($npcStatus);

        if ($request->boolean('suppress_success_flash')) {
            return redirect()->back();
        }

        return redirect()->back()->with('success', 'NPC Status saved successfully');
    }

    public function update(Request $request, NpcStatus $npcStatus)
    {
        $validated = $this->validatePayload($request, false);
        $year = Carbon::parse($validated['validity_from'])->year;

        if ($year !== (int) $npcStatus->year) {
            throw ValidationException::withMessages([
                'validity_from' => 'Use Add Renewal to create a new validity year instead of moving this historical record.',
            ]);
        }

        $this->fillStatusFields($npcStatus, $validated, $request->user()->id);
        $npcStatus->save();
        $this->ensureWorkflowSteps($npcStatus);

        if ($request->boolean('suppress_success_flash')) {
            return redirect()->back();
        }

        return redirect()->back()->with('success', 'NPC Status updated successfully');
    }

    public function destroy(NpcStatus $npcStatus)
    {
        foreach ($npcStatus->attachments as $attachment) {
            $this->deleteAttachmentPath($attachment->file_path);
        }

        $this->deleteAttachmentPath($npcStatus->dpo_seal_path);
        $this->deleteAttachmentPath($npcStatus->dpo_registration_path);
        $npcStatus->delete();

        return redirect()->back()->with('success', 'NPC Status deleted successfully');
    }

    public function syncStores(Request $request, NpcStatus $npcStatus)
    {
        $validated = $request->validate([
            'store_ids' => 'nullable|array',
            'store_ids.*' => 'integer|exists:stores,id',
        ]);

        $storeIds = array_values(array_unique($validated['store_ids'] ?? []));

        $conflict = DB::table('npc_status_store')
            ->join('npc_statuses', 'npc_status_store.npc_status_id', '=', 'npc_statuses.id')
            ->join('companies', 'npc_statuses.company_id', '=', 'companies.id')
            ->join('stores', 'npc_status_store.store_id', '=', 'stores.id')
            ->where('npc_status_store.year', $npcStatus->year)
            ->where('npc_statuses.id', '<>', $npcStatus->id)
            ->whereIn('npc_status_store.store_id', $storeIds)
            ->select('stores.name as store_name', 'companies.name as company_name')
            ->first();

        if ($conflict) {
            throw ValidationException::withMessages([
                'store_ids' => "{$conflict->store_name} is already assigned to {$conflict->company_name} for {$npcStatus->year}.",
            ]);
        }

        $npcStatus->stores()->syncWithPivotValues($storeIds, ['year' => $npcStatus->year]);

        return redirect()->back()->with('success', 'Assigned stores updated successfully');
    }

    public function storeAttachment(Request $request, NpcStatus $npcStatus)
    {
        $validated = $request->validate([
            'type' => ['required', Rule::in(NpcStatusAttachment::TYPES)],
            'validity_from' => 'required|date',
            'file' => 'required|file|mimes:pdf,jpg,jpeg,png,webp|max:' . self::MAX_ATTACHMENT_KILOBYTES,
        ]);

        if (Carbon::parse($validated['validity_from'])->year !== (int) $npcStatus->year) {
            throw ValidationException::withMessages([
                'validity_from' => 'Attachment validity from must be within the parent renewal year.',
            ]);
        }

        $duplicate = $npcStatus->attachments()
            ->where('type', $validated['type'])
            ->whereYear('validity_from', Carbon::parse($validated['validity_from'])->year)
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'file' => 'An attachment is already uploaded for this DPO type and validity year.',
            ]);
        }

        $this->storeStatusAttachment(
            $npcStatus,
            $validated['type'],
            $validated['validity_from'],
            $request->file('file'),
            $request->user()->id
        );

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'NPC attachment uploaded successfully',
                'company' => $this->freshCompanyRow($npcStatus->company_id, $npcStatus->year),
            ]);
        }

        return redirect()->back()->with('success', 'NPC attachment uploaded successfully');
    }

    public function destroyAttachment(Request $request, NpcStatusAttachment $attachment)
    {
        $npcStatus = $attachment->npcStatus;
        $type = $attachment->type;
        $companyId = $npcStatus->company_id;
        $year = $npcStatus->year;

        $this->deleteAttachmentPath($attachment->file_path);
        $attachment->delete();
        $this->syncLatestLegacyAttachmentColumns($npcStatus, $type);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'NPC attachment deleted successfully',
                'company' => $this->freshCompanyRow($companyId, $year),
            ]);
        }

        return redirect()->back();
    }

    public function downloadStatusAttachment(NpcStatusAttachment $attachment)
    {
        return $this->downloadStoredFile($attachment->file_path, $attachment->file_name);
    }

    public function downloadAttachment(NpcStatus $npcStatus, string $type)
    {
        $attachmentType = $type === 'seal'
            ? NpcStatusAttachment::TYPE_DPO_SEAL
            : NpcStatusAttachment::TYPE_DPO_REGISTRATION;

        $attachment = $npcStatus->attachments()
            ->where('type', $attachmentType)
            ->latest('validity_from')
            ->latest('created_at')
            ->first();

        if ($attachment) {
            return $this->downloadStoredFile($attachment->file_path, $attachment->file_name);
        }

        $prefix = $type === 'seal' ? 'dpo_seal' : 'dpo_registration';

        return $this->downloadStoredFile(
            $npcStatus->getAttribute("{$prefix}_path"),
            $npcStatus->getAttribute("{$prefix}_name")
        );
    }

    public function updateWorkflow(Request $request, NpcStatus $npcStatus)
    {
        $validated = $request->validate([
            'steps' => 'required|array',
            'steps.*.key' => ['required', Rule::in(collect(NpcStatus::WORKFLOW_STEPS)->pluck('key')->all())],
            'steps.*.is_done' => 'required|boolean',
            'steps.*.completed_at' => 'nullable|date',
            'steps.*.remarks' => 'nullable|string|max:4000',
        ]);

        $this->ensureWorkflowSteps($npcStatus);
        $definitions = collect(NpcStatus::WORKFLOW_STEPS)->keyBy('key');

        foreach ($validated['steps'] as $step) {
            $definition = $definitions[$step['key']];
            NpcStatusWorkflowStep::updateOrCreate(
                ['npc_status_id' => $npcStatus->id, 'key' => $step['key']],
                [
                    'label' => $definition['label'],
                    'sort_order' => $definition['sort_order'],
                    'is_done' => (bool) $step['is_done'],
                    'completed_at' => $step['is_done'] ? ($step['completed_at'] ?? now()->toDateString()) : null,
                    'remarks' => $step['remarks'] ?? null,
                ]
            );
        }

        if ($request->boolean('suppress_success_flash')) {
            return redirect()->back();
        }

        return redirect()->back()->with('success', 'NPC workflow updated successfully');
    }

    public function storeCctvSealNotice(Request $request, Store $store)
    {
        $request->validate([
            'file' => 'required|file|mimes:pdf,jpg,jpeg,png,webp|max:' . self::MAX_ATTACHMENT_KILOBYTES,
        ]);

        $this->deleteAttachmentPath($store->cctv_seal_notice_path);
        $file = $request->file('file');
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $fileName = 'cctv-seal-notice-' . Str::uuid() . ($extension ? ".{$extension}" : '');
        $path = $file->storeAs("store-cctv-seal-notices/{$store->id}", $fileName, 'public');

        $store->forceFill([
            'cctv_seal_notice_path' => str_replace('\\', '/', $path),
            'cctv_seal_notice_name' => $file->getClientOriginalName(),
            'cctv_seal_notice_mime_type' => $file->getClientMimeType(),
            'cctv_seal_notice_size' => $file->getSize(),
            'cctv_seal_notice_uploaded_at' => now(),
            'cctv_seal_notice_uploaded_by' => $request->user()->id,
        ])->save();

        return redirect()->back()->with('success', 'CCTV Seal Notice saved successfully');
    }

    public function downloadCctvSealNotice(Store $store)
    {
        return $this->downloadStoredFile($store->cctv_seal_notice_path, $store->cctv_seal_notice_name);
    }

    private function validatePayload(Request $request, bool $isCreate): array
    {
        $rules = [
            'validity_from' => 'required|date',
            'validity_to' => 'required|date|after_or_equal:validity_from',
        ];

        if ($isCreate) {
            $rules = array_merge([
                'company_id' => 'required|integer|exists:companies,id',
            ], $rules);
        }

        return $request->validate($rules);
    }

    private function fillStatusFields(NpcStatus $npcStatus, array $validated, int $userId): void
    {
        $npcStatus->validity_from = $validated['validity_from'];
        $npcStatus->validity_to = $validated['validity_to'];
        $npcStatus->status = $this->renewalStatus($validated['validity_to']);
        $npcStatus->updated_by = $userId;
    }

    private function storeStatusAttachment(NpcStatus $npcStatus, string $type, string $validityFrom, UploadedFile $file, int $userId): NpcStatusAttachment
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $label = $type === NpcStatusAttachment::TYPE_DPO_SEAL ? 'seal' : 'registration';
        $fileName = "{$label}-" . Str::uuid() . ($extension ? ".{$extension}" : '');
        $path = $file->storeAs(
            "npc-statuses/{$npcStatus->year}/{$npcStatus->company_id}",
            $fileName,
            'public'
        );

        $attachment = $npcStatus->attachments()->create([
            'type' => $type,
            'validity_from' => $validityFrom,
            'file_path' => str_replace('\\', '/', $path),
            'file_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'file_size' => $file->getSize(),
            'uploaded_by' => $userId,
        ]);

        $this->syncLegacyAttachmentColumns($npcStatus, $attachment);

        return $attachment;
    }

    private function syncLegacyAttachmentColumns(NpcStatus $npcStatus, NpcStatusAttachment $attachment): void
    {
        $prefix = $attachment->type === NpcStatusAttachment::TYPE_DPO_SEAL ? 'dpo_seal' : 'dpo_registration';

        $npcStatus->forceFill([
            "{$prefix}_path" => $attachment->file_path,
            "{$prefix}_name" => $attachment->file_name,
            "{$prefix}_mime_type" => $attachment->mime_type,
            "{$prefix}_size" => $attachment->file_size,
        ])->save();
    }

    private function syncLatestLegacyAttachmentColumns(NpcStatus $npcStatus, string $type): void
    {
        $latest = $npcStatus->attachments()
            ->where('type', $type)
            ->latest('validity_from')
            ->latest('created_at')
            ->first();

        $prefix = $type === NpcStatusAttachment::TYPE_DPO_SEAL ? 'dpo_seal' : 'dpo_registration';

        $npcStatus->forceFill([
            "{$prefix}_path" => $latest?->file_path,
            "{$prefix}_name" => $latest?->file_name,
            "{$prefix}_mime_type" => $latest?->mime_type,
            "{$prefix}_size" => $latest?->file_size,
        ])->save();
    }

    private function deleteAttachmentPath(?string $path): void
    {
        if ($path) {
            Storage::disk('public')->delete($path);
        }
    }

    private function downloadStoredFile(?string $path, ?string $name)
    {
        if (!$path || !Storage::disk('public')->exists($path)) {
            abort(404, 'File not found.');
        }

        return Storage::disk('public')->download($path, $name ?: basename($path));
    }

    private function serializeCompanyRow(Company $company, int $year): array
    {
        $npcStatus = $company->npcStatuses->firstWhere('year', $year);

        return [
            'id' => $company->id,
            'name' => $company->name,
            'code' => $company->code,
            'description' => $company->description,
            'is_active' => $company->is_active,
            'npc_status' => $npcStatus ? $this->serializeNpcStatus($npcStatus) : null,
            'attachment_history' => $this->companyAttachmentHistoryPayload($company),
            'workflow_history' => $this->companyWorkflowHistoryPayload($company),
            'store_count' => $npcStatus ? (int) $npcStatus->stores_count : 0,
        ];
    }

    private function freshCompanyRow(int $companyId, int $year): array
    {
        $company = Company::query()
            ->with(['npcStatuses' => function ($npcQuery) {
                $npcQuery->with([
                        'attachments' => fn ($query) => $query->latest('validity_from')->latest('created_at'),
                        'workflowSteps',
                    ])
                    ->withCount('stores');
            }])
            ->findOrFail($companyId);

        return $this->serializeCompanyRow($company, $year);
    }

    private function serializeNpcStatus(NpcStatus $npcStatus): array
    {
        $workflowSteps = $this->workflowPayload($npcStatus);

        return [
            'id' => $npcStatus->id,
            'company_id' => $npcStatus->company_id,
            'year' => $npcStatus->year,
            'validity_from' => $npcStatus->validity_from?->format('Y-m-d'),
            'validity_to' => $npcStatus->validity_to?->format('Y-m-d'),
            'status' => $this->renewalStatus($npcStatus->validity_to),
            'renewal_status' => $this->renewalStatus($npcStatus->validity_to),
            'renewal_days' => $this->renewalDays($npcStatus->validity_to),
            'workflow_stage' => $this->workflowStage($workflowSteps),
            'workflow_progress' => $this->workflowProgress($workflowSteps),
            'workflow_steps' => $workflowSteps,
            'store_count' => (int) ($npcStatus->stores_count ?? 0),
            'attachments' => [
                NpcStatusAttachment::TYPE_DPO_SEAL => $this->attachmentsPayload($npcStatus, NpcStatusAttachment::TYPE_DPO_SEAL, $npcStatus->year),
                NpcStatusAttachment::TYPE_DPO_REGISTRATION => $this->attachmentsPayload($npcStatus, NpcStatusAttachment::TYPE_DPO_REGISTRATION, $npcStatus->year),
            ],
            'dpo_seal' => $this->latestAttachmentPayload($npcStatus, NpcStatusAttachment::TYPE_DPO_SEAL),
            'dpo_registration' => $this->latestAttachmentPayload($npcStatus, NpcStatusAttachment::TYPE_DPO_REGISTRATION),
        ];
    }

    private function attachmentsPayload(NpcStatus $npcStatus, string $type, ?int $validityYear = null): array
    {
        return $this->attachmentsPayloadFromCollection($npcStatus->attachments, $type, $validityYear);
    }

    private function attachmentsPayloadFromCollection(Collection $attachments, string $type, ?int $validityYear = null): array
    {
        return $attachments
            ->filter(function (NpcStatusAttachment $attachment) use ($type, $validityYear) {
                if ($attachment->type !== $type) {
                    return false;
                }

                if ($validityYear === null) {
                    return true;
                }

                return (int) $attachment->validity_from?->year === $validityYear;
            })
            ->sortByDesc(fn (NpcStatusAttachment $attachment) => $attachment->validity_from?->format('Y-m-d') . $attachment->created_at?->timestamp)
            ->map(fn (NpcStatusAttachment $attachment) => [
                'id' => $attachment->id,
                'type' => $attachment->type,
                'validity_from' => $attachment->validity_from?->format('Y-m-d'),
                'name' => $attachment->file_name,
                'mime_type' => $attachment->mime_type,
                'size' => $attachment->file_size,
                'url' => route('npc-status-attachments.download', $attachment),
                'uploaded_at' => $attachment->created_at?->toDateTimeString(),
            ])
            ->values()
            ->all();
    }

    private function latestAttachmentPayload(NpcStatus $npcStatus, string $type): ?array
    {
        return $this->attachmentsPayload($npcStatus, $type, $npcStatus->year)[0] ?? null;
    }

    private function companyAttachmentHistoryPayload(Company $company): array
    {
        $attachments = $company->npcStatuses
            ->flatMap(fn (NpcStatus $npcStatus) => $npcStatus->attachments);

        return $company->npcStatuses
            ->sortByDesc('year')
            ->map(fn (NpcStatus $npcStatus) => [
                'id' => $npcStatus->id,
                'year' => $npcStatus->year,
                'validity_from' => $npcStatus->validity_from?->format('Y-m-d'),
                'validity_to' => $npcStatus->validity_to?->format('Y-m-d'),
                'attachments' => [
                    NpcStatusAttachment::TYPE_DPO_SEAL => $this->attachmentsPayloadFromCollection($attachments, NpcStatusAttachment::TYPE_DPO_SEAL, $npcStatus->year),
                    NpcStatusAttachment::TYPE_DPO_REGISTRATION => $this->attachmentsPayloadFromCollection($attachments, NpcStatusAttachment::TYPE_DPO_REGISTRATION, $npcStatus->year),
                ],
            ])
            ->values()
            ->all();
    }

    private function companyWorkflowHistoryPayload(Company $company): array
    {
        return $company->npcStatuses
            ->sortByDesc('year')
            ->map(function (NpcStatus $npcStatus) {
                $steps = $this->workflowPayload($npcStatus);

                return [
                    'id' => $npcStatus->id,
                    'year' => $npcStatus->year,
                    'validity_from' => $npcStatus->validity_from?->format('Y-m-d'),
                    'validity_to' => $npcStatus->validity_to?->format('Y-m-d'),
                    'workflow_stage' => $this->workflowStage($steps),
                    'workflow_progress' => $this->workflowProgress($steps),
                    'workflow_steps' => $steps,
                ];
            })
            ->values()
            ->all();
    }

    private function storeOptions(int $year): array
    {
        return Store::query()
            ->with(['npcStatuses' => function ($query) use ($year) {
                $query->wherePivot('year', $year)->with('company:id,name,code');
            }])
            ->orderBy('name')
            ->get()
            ->map(function (Store $store) {
                $assignment = $store->npcStatuses->first();

                return [
                    'id' => $store->id,
                    'code' => $store->code,
                    'name' => $store->name,
                    'area' => $store->area,
                    'brand' => $store->brand,
                    'is_active' => $store->is_active,
                    'assigned_npc_status_id' => $assignment?->id,
                    'assigned_company_id' => $assignment?->company_id,
                    'assigned_company_name' => $assignment?->company?->name,
                    'cctv_seal_notice' => $store->cctv_seal_notice_path ? [
                        'name' => $store->cctv_seal_notice_name,
                        'mime_type' => $store->cctv_seal_notice_mime_type,
                        'size' => $store->cctv_seal_notice_size,
                        'uploaded_at' => $store->cctv_seal_notice_uploaded_at?->toDateTimeString(),
                        'url' => route('stores.cctv-seal-notice.download', $store),
                    ] : null,
                ];
            })
            ->values()
            ->all();
    }

    private function statusCounts(int $year): array
    {
        $rows = Company::query()
            ->with(['npcStatuses' => fn ($query) => $query->where('year', $year)->withCount('stores')])
            ->get()
            ->map(fn (Company $company) => $company->npcStatuses->first());

        $counts = collect(NpcStatus::STATUSES)
            ->mapWithKeys(fn ($status) => [$status => ['entities' => 0, 'stores' => 0]])
            ->all();

        foreach ($rows as $npcStatus) {
            $status = $npcStatus ? $this->renewalStatus($npcStatus->validity_to) : 'No Record';
            $counts[$status]['entities']++;
            $counts[$status]['stores'] += $npcStatus ? (int) $npcStatus->stores_count : 0;
        }

        return $counts;
    }

    private function renewalStatus($validityTo): string
    {
        $days = $this->renewalDays($validityTo);

        if ($days === null) {
            return 'No Record';
        }

        if ($days < 0) {
            return 'Overdue';
        }

        if ($days === 0) {
            return 'Due Today';
        }

        if ($days <= 30) {
            return 'Critical Renewal';
        }

        if ($days <= 90) {
            return 'Renewal Window';
        }

        return 'Active';
    }

    private function renewalDays($validityTo): ?int
    {
        if (!$validityTo) {
            return null;
        }

        return now()->startOfDay()->diffInDays(Carbon::parse($validityTo)->startOfDay(), false);
    }

    private function ensureWorkflowSteps(NpcStatus $npcStatus): void
    {
        foreach (NpcStatus::WORKFLOW_STEPS as $step) {
            NpcStatusWorkflowStep::firstOrCreate(
                ['npc_status_id' => $npcStatus->id, 'key' => $step['key']],
                [
                    'label' => $step['label'],
                    'sort_order' => $step['sort_order'],
                ]
            );
        }
    }

    private function workflowPayload(NpcStatus $npcStatus): array
    {
        $steps = $npcStatus->workflowSteps->keyBy('key');

        return collect(NpcStatus::WORKFLOW_STEPS)
            ->map(function (array $definition) use ($steps) {
                $step = $steps->get($definition['key']);

                return [
                    'key' => $definition['key'],
                    'label' => $definition['label'],
                    'sort_order' => $definition['sort_order'],
                    'is_done' => (bool) ($step?->is_done ?? false),
                    'completed_at' => $step?->completed_at?->format('Y-m-d'),
                    'remarks' => $step?->remarks,
                ];
            })
            ->values()
            ->all();
    }

    private function workflowStage(array $steps): string
    {
        $done = collect($steps)->where('is_done', true)->count();

        return match (true) {
            $done >= 6 => 'Complete',
            $done >= 5 => 'For Store Distribution',
            $done >= 4 => 'For Payment',
            $done >= 3 => 'Waiting for NPC Approval',
            default => 'Ongoing Application',
        };
    }

    private function workflowProgress(array $steps): int
    {
        if (count($steps) === 0) {
            return 0;
        }

        return (int) round((collect($steps)->where('is_done', true)->count() / count($steps)) * 100);
    }
}
