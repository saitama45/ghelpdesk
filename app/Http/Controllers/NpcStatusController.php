<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\NpcSealReceipt;
use App\Models\NpcStatus;
use App\Models\NpcStatusAttachment;
use App\Models\NpcStatusWorkflowStep;
use App\Models\Store;
use App\Models\User;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
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

    private const STATUS_GROUPS = [
        'active' => ['Active'],
        'for_renewal' => ['Renewal Window', 'Critical Renewal', 'Due Today', 'Overdue'],
    ];

    public function __construct(private NotificationService $notificationService)
    {
    }

    public static function middleware(): array
    {
        return [
            // index is reachable by admins (npc_status.view) and store users
            // (npc_status.download); the split is authorized inside index().
            new Middleware('can:npc_status.view', only: [
                'showCompany',
            ]),
            new Middleware('can:npc_status.create', only: ['store']),
            new Middleware('can:npc_status.edit', only: [
                'update',
                'syncStores',
                'storeAttachment',
                'destroyAttachment',
                'updateWorkflow',
                'storeCctvSealNotice',
                'downloadAttachment',
                'downloadStatusAttachment',
                'downloadCctvSealNotice',
                'confirmStoreSeal',
            ]),
            new Middleware('can:npc_status.delete', only: ['destroy']),
            new Middleware('can:npc_status.download', only: ['downloadStoreSeal']),
        ];
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $canAdmin = $user->can('npc_status.view');
        $canDownload = $user->can('npc_status.download');
        $restrictedStoreIds = $canDownload && !$user->can('npc_status.edit')
            ? $user->stores()->pluck('stores.id')->all()
            : null;

        abort_unless($canAdmin || $canDownload, 403);

        // Store users (download-only) see their assigned stores' seals per year.
        if (!$canAdmin) {
            return Inertia::render('NpcStatus/Index', [
                'viewMode' => 'store',
                'storeSeals' => $this->storeDownloadPayload($user),
                'canDownloadAssignedSeals' => true,
                'defaultNpcSection' => 'downloads',
            ]);
        }

        $validated = $request->validate([
            'status' => ['nullable', Rule::in(array_keys(self::STATUS_GROUPS))],
            'search' => 'nullable|string|max:255',
            'per_page' => 'nullable|integer|min:5|max:100',
            'page' => 'nullable|integer|min:1',
        ]);

        $year = (int) now()->year;
        $status = $validated['status'] ?? null;
        $search = trim((string) ($validated['search'] ?? ''));
        $perPage = (int) ($validated['per_page'] ?? 10);
        $page = (int) ($validated['page'] ?? 1);

        $rows = Company::query()
            // Only Entity-type companies are tracked for NPC statuses.
            ->where('type', 'Entity')
            ->when($restrictedStoreIds !== null, function ($query) use ($year, $restrictedStoreIds) {
                $query->whereHas('npcStatuses', function ($npcQuery) use ($year, $restrictedStoreIds) {
                    $npcQuery->where('year', $year)
                        ->whereHas('stores', fn ($storeQuery) => $storeQuery->whereIn('stores.id', $restrictedStoreIds));
                });
            })
            ->with(['npcStatuses' => function ($npcQuery) use ($restrictedStoreIds) {
                $npcQuery->with([
                        'attachments' => fn ($query) => $query->latest('validity_from')->latest('created_at'),
                        'workflowSteps',
                        'stores' => fn ($query) => $query->when(
                            $restrictedStoreIds !== null,
                            fn ($storeQuery) => $storeQuery->whereIn('stores.id', $restrictedStoreIds)
                        ),
                        'sealReceipts' => fn ($query) => $query->when(
                            $restrictedStoreIds !== null,
                            fn ($receiptQuery) => $receiptQuery->whereIn('store_id', $restrictedStoreIds)
                        ),
                    ])
                    ->withCount(['stores' => fn ($query) => $query->when(
                        $restrictedStoreIds !== null,
                        fn ($storeQuery) => $storeQuery->whereIn('stores.id', $restrictedStoreIds)
                    )]);
            }])
            ->orderBy('name')
            ->get()
            ->map(fn (Company $company) => $this->serializeCompanyRow($company, $year));

        $filtered = $rows
            ->when($status, fn (Collection $items) => $items->filter(fn (array $row) => in_array($row['npc_status']['renewal_status'] ?? 'No Record', self::STATUS_GROUPS[$status] ?? [], true)))
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
            'viewMode' => 'admin',
            'npcStatuses' => $companies,
            'filters' => [
                'status' => $status,
                'search' => $search,
                'per_page' => $perPage,
            ],
            'currentYear' => $year,
            'statusCounts' => $this->statusCounts($year, $restrictedStoreIds),
            'workflowSteps' => NpcStatus::WORKFLOW_STEPS,
            'stores' => $this->storeOptions($year, $restrictedStoreIds),
            'storeSeals' => $canDownload ? $this->storeDownloadPayload($user) : [],
            'canDownloadAssignedSeals' => $canDownload,
            'defaultNpcSection' => $restrictedStoreIds !== null ? 'downloads' : 'monitoring',
        ]);
    }

    public function showCompany(Request $request, Company $company)
    {
        $year = (int) now()->year;
        $validated = $request->validate([
            'year' => 'nullable|integer|min:2000|max:2100',
        ]);
        $requestedYear = (int) ($validated['year'] ?? $year);
        $user = $request->user();
        $restrictedStoreIds = $user->can('npc_status.download') && !$user->can('npc_status.edit')
            ? $user->stores()->pluck('stores.id')->all()
            : null;

        if ($restrictedStoreIds !== null) {
            abort_unless(
                $company->npcStatuses()
                    ->where('year', $year)
                    ->whereHas('stores', fn ($query) => $query->whereIn('stores.id', $restrictedStoreIds))
                    ->exists(),
                404
            );
        }

        $npcStatus = NpcStatus::query()
            ->where('company_id', $company->id)
            ->where('year', $year)
            ->first();

        if ($npcStatus) {
            $this->ensureWorkflowSteps($npcStatus);
        }

        return response()->json([
            'company' => $this->freshCompanyRow($company->id, $year, $restrictedStoreIds),
            'stores' => $this->storeOptions($requestedYear, $restrictedStoreIds),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validatePayload($request, true);
        $year = Carbon::parse($validated['validity_from'])->year;

        $duplicateMessage = 'An NPC renewal record already exists for this entity and validity year.';

        // Already saved (e.g. a stale/duplicate submit) — surface the existing
        // record so the client stops showing "Create Record".
        $existing = NpcStatus::where('company_id', $validated['company_id'])
            ->where('year', $year)
            ->first();

        if ($existing) {
            $this->ensureWorkflowSteps($existing);

            return $this->respondWithCompany($request, $existing, null, $duplicateMessage, true);
        }

        $npcStatus = new NpcStatus([
            'company_id' => $validated['company_id'],
            'year' => $year,
            'created_by' => $request->user()->id,
        ]);

        $this->fillStatusFields($npcStatus, $validated, $request->user()->id);

        try {
            $npcStatus->save();
        } catch (UniqueConstraintViolationException $e) {
            // A concurrent/double submit created the same (company, year) first.
            $existing = NpcStatus::where('company_id', $validated['company_id'])->where('year', $year)->first();

            if ($existing) {
                $this->ensureWorkflowSteps($existing);
            }

            return $this->respondWithCompany($request, $existing, null, $duplicateMessage, true);
        }

        $this->ensureWorkflowSteps($npcStatus);

        return $this->respondWithCompany($request, $npcStatus, 'NPC renewal created successfully');
    }

    public function update(Request $request, NpcStatus $npcStatus)
    {
        $this->ensureNpcStatusIsEditable($npcStatus);
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

        return $this->respondWithCompany($request, $npcStatus, 'NPC renewal dates updated successfully');
    }

    /**
     * Return the fresh serialized company row as JSON (for the modal's axios
     * flow), or fall back to a redirect for classic form posts.
     */
    private function respondWithCompany(
        Request $request,
        ?NpcStatus $npcStatus,
        ?string $message,
        ?string $nonJsonError = null,
        bool $existingRecord = false
    )
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'company' => $npcStatus ? $this->freshCompanyRow($npcStatus->company_id, (int) now()->year) : null,
                'existing_record' => $existingRecord,
            ]);
        }

        if ($nonJsonError) {
            throw ValidationException::withMessages(['validity_from' => $nonJsonError]);
        }

        return redirect()->back()->with('success', $message);
    }

    public function destroy(NpcStatus $npcStatus)
    {
        $this->ensureNpcStatusIsEditable($npcStatus);

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
        $this->ensureNpcStatusIsEditable($npcStatus);

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

        return $this->respondWithCompany($request, $npcStatus, 'Assigned stores updated successfully');
    }

    public function storeAttachment(Request $request, NpcStatus $npcStatus)
    {
        $this->ensureNpcStatusIsEditable($npcStatus);

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
                'company' => $this->freshCompanyRow($npcStatus->company_id, (int) now()->year),
            ]);
        }

        return redirect()->back()->with('success', 'NPC attachment uploaded successfully');
    }

    public function destroyAttachment(Request $request, NpcStatusAttachment $attachment)
    {
        $npcStatus = $attachment->npcStatus;
        $this->ensureNpcStatusIsEditable($npcStatus);
        $type = $attachment->type;
        $companyId = $npcStatus->company_id;
        $year = $npcStatus->year;

        $this->deleteAttachmentPath($attachment->file_path);
        $attachment->delete();
        $this->syncLatestLegacyAttachmentColumns($npcStatus, $type);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'NPC attachment deleted successfully',
                'company' => $this->freshCompanyRow($companyId, (int) now()->year),
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
        $this->ensureNpcStatusIsEditable($npcStatus);

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

        return $this->respondWithCompany($request, $npcStatus->fresh(), 'Workflow checklist saved successfully');
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

    private function freshCompanyRow(int $companyId, int $year, ?array $restrictedStoreIds = null): array
    {
        $company = Company::query()
            ->with(['npcStatuses' => function ($npcQuery) use ($restrictedStoreIds) {
                $npcQuery->with([
                        'attachments' => fn ($query) => $query->latest('validity_from')->latest('created_at'),
                        'workflowSteps',
                        'stores' => fn ($query) => $query->when(
                            $restrictedStoreIds !== null,
                            fn ($storeQuery) => $storeQuery->whereIn('stores.id', $restrictedStoreIds)
                        ),
                        'sealReceipts' => fn ($query) => $query->when(
                            $restrictedStoreIds !== null,
                            fn ($receiptQuery) => $receiptQuery->whereIn('store_id', $restrictedStoreIds)
                        ),
                    ])
                    ->withCount(['stores' => fn ($query) => $query->when(
                        $restrictedStoreIds !== null,
                        fn ($storeQuery) => $storeQuery->whereIn('stores.id', $restrictedStoreIds)
                    )]);
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
            'is_finalized' => $this->isNpcStatusFinalized($npcStatus),
            'store_count' => (int) ($npcStatus->stores_count ?? 0),
            'attachments' => [
                NpcStatusAttachment::TYPE_DPO_SEAL => $this->attachmentsPayload($npcStatus, NpcStatusAttachment::TYPE_DPO_SEAL, $npcStatus->year),
                NpcStatusAttachment::TYPE_DPO_REGISTRATION => $this->attachmentsPayload($npcStatus, NpcStatusAttachment::TYPE_DPO_REGISTRATION, $npcStatus->year),
                NpcStatusAttachment::TYPE_CCTV_SEAL => $this->attachmentsPayload($npcStatus, NpcStatusAttachment::TYPE_CCTV_SEAL, $npcStatus->year),
            ],
            'seals' => $this->sealAvailability($npcStatus),
            'store_receipts' => $this->storeReceiptGrid($npcStatus),
            'dpo_seal' => $this->latestAttachmentPayload($npcStatus, NpcStatusAttachment::TYPE_DPO_SEAL),
            'dpo_registration' => $this->latestAttachmentPayload($npcStatus, NpcStatusAttachment::TYPE_DPO_REGISTRATION),
        ];
    }

    /**
     * Availability of each downloadable seal (uploaded at Step 6) for the year.
     */
    private function sealAvailability(NpcStatus $npcStatus): array
    {
        $out = [];

        foreach (NpcStatusAttachment::SEAL_TYPES as $type) {
            $attachment = $this->attachmentsPayload($npcStatus, $type, $npcStatus->year)[0] ?? null;

            $out[$type] = [
                'type' => $type,
                'label' => NpcStatusAttachment::TYPE_LABELS[$type] ?? $type,
                'available' => (bool) $attachment,
                'name' => $attachment['name'] ?? null,
                'url' => $attachment['url'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * Per assigned store, the download + admin-confirmation state of each seal.
     */
    private function storeReceiptGrid(NpcStatus $npcStatus): array
    {
        if (!$npcStatus->relationLoaded('stores') || !$npcStatus->relationLoaded('sealReceipts')) {
            $npcStatus->loadMissing(['stores', 'sealReceipts']);
        }

        $receipts = $npcStatus->sealReceipts->groupBy('store_id');

        return $npcStatus->stores
            ->sortBy('name')
            ->map(function (Store $store) use ($receipts) {
                $storeReceipts = $receipts->get($store->id, collect());
                $seals = [];

                foreach (NpcStatusAttachment::SEAL_TYPES as $type) {
                    $receipt = $storeReceipts->firstWhere('seal_type', $type);
                    $seals[$type] = [
                        'downloaded_at' => $receipt?->downloaded_at?->toIso8601String(),
                        'confirmed_at' => $receipt?->confirmed_at?->toIso8601String(),
                    ];
                }

                return [
                    'store_id' => $store->id,
                    'store_name' => $store->name,
                    'store_code' => $store->code,
                    'seals' => $seals,
                ];
            })
            ->values()
            ->all();
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
            ->map(fn (NpcStatus $npcStatus) => $this->serializeNpcStatus($npcStatus))
            ->values()
            ->all();
    }

    private function storeOptions(int $year, ?array $restrictedStoreIds = null): array
    {
        return Store::query()
            ->when(
                $restrictedStoreIds !== null,
                fn ($query) => $query->whereIn('stores.id', $restrictedStoreIds)
            )
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

    private function statusCounts(int $year, ?array $restrictedStoreIds = null): array
    {
        $rows = Company::query()
            // Only Entity-type companies are tracked for NPC statuses.
            ->where('type', 'Entity')
            ->when($restrictedStoreIds !== null, function ($query) use ($year, $restrictedStoreIds) {
                $query->whereHas('npcStatuses', function ($npcQuery) use ($year, $restrictedStoreIds) {
                    $npcQuery->where('year', $year)
                        ->whereHas('stores', fn ($storeQuery) => $storeQuery->whereIn('stores.id', $restrictedStoreIds));
                });
            })
            ->with(['npcStatuses' => fn ($query) => $query
                ->where('year', $year)
                ->withCount(['stores' => fn ($storeQuery) => $storeQuery->when(
                    $restrictedStoreIds !== null,
                    fn ($restrictedQuery) => $restrictedQuery->whereIn('stores.id', $restrictedStoreIds)
                )])])
            ->get()
            ->map(fn (Company $company) => $company->npcStatuses->first());

        $counts = [
            'all' => ['entities' => 0, 'stores' => 0],
            'active' => ['entities' => 0, 'stores' => 0],
            'for_renewal' => ['entities' => 0, 'stores' => 0],
        ];

        foreach ($rows as $npcStatus) {
            $status = $npcStatus ? $this->renewalStatus($npcStatus->validity_to) : 'No Record';
            $stores = $npcStatus ? (int) $npcStatus->stores_count : 0;

            $counts['all']['entities']++;
            $counts['all']['stores'] += $stores;

            foreach (self::STATUS_GROUPS as $key => $statuses) {
                if (in_array($status, $statuses, true)) {
                    $counts[$key]['entities']++;
                    $counts[$key]['stores'] += $stores;
                }
            }
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
            $done >= 5 => 'For Store Receiving',
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

    // ── Store-side seal downloads ────────────────────────────────────────────

    /**
     * A store user downloads one of the year's seals. Records the download
     * (once), notifies page admins, and streams the file. Downloading does NOT
     * mark the store as checked — an admin confirms that separately.
     */
    public function downloadStoreSeal(Request $request, NpcStatus $npcStatus, Store $store, string $type)
    {
        abort_unless(in_array($type, NpcStatusAttachment::SEAL_TYPES, true), 404);

        $user = $request->user();

        // The store must belong to the requesting user and be assigned this year.
        abort_unless($user->stores()->whereKey($store->id)->exists(), 403);
        abort_unless($npcStatus->stores()->whereKey($store->id)->exists(), 404);

        // A status record is scoped to a single year, so the latest attachment
        // of this type is the seal for that year.
        $attachment = $npcStatus->attachments()
            ->where('type', $type)
            ->latest('validity_from')
            ->latest('created_at')
            ->first();

        abort_if(!$attachment, 404, 'This seal is not available yet.');

        $receipt = NpcSealReceipt::firstOrNew([
            'npc_status_id' => $npcStatus->id,
            'store_id' => $store->id,
            'seal_type' => $type,
        ]);

        if (!$receipt->downloaded_at) {
            $receipt->downloaded_at = now();
            $receipt->downloaded_by = $user->id;
            $receipt->save();

            $this->notificationService->notifyNpcSealDownload($npcStatus, $store, $type, $user->id);
        }

        // The store UI first requests JSON so it can update the receipt state,
        // then opens this URL normally to let the browser stream/save the file.
        if ($request->expectsJson()) {
            return response()->json([
                'download_url' => route('npc-statuses.stores.seal.download', [$npcStatus, $store, $type]),
                'downloaded_at' => $receipt->downloaded_at?->toIso8601String(),
            ]);
        }

        return $this->downloadStoredFile($attachment->file_path, $attachment->file_name);
    }

    /**
     * An admin marks (or unmarks) a store's seal as checked after confirming
     * receipt with the store through their own communication channel.
     */
    public function confirmStoreSeal(Request $request, NpcStatus $npcStatus, Store $store, string $type)
    {
        abort_unless(in_array($type, NpcStatusAttachment::SEAL_TYPES, true), 404);
        abort_unless($npcStatus->stores()->whereKey($store->id)->exists(), 404);
        $this->ensureNpcStatusIsEditable($npcStatus);

        $confirmed = $request->boolean('confirmed');

        $receipt = NpcSealReceipt::firstOrNew([
            'npc_status_id' => $npcStatus->id,
            'store_id' => $store->id,
            'seal_type' => $type,
        ]);

        $receipt->confirmed_at = $confirmed ? now() : null;
        $receipt->confirmed_by = $confirmed ? $request->user()->id : null;
        $receipt->save();

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $confirmed ? 'Store marked as checked.' : 'Store check removed.',
                'company' => $this->freshCompanyRow($npcStatus->company_id, (int) now()->year),
            ]);
        }

        return redirect()->back();
    }

    private function ensureNpcStatusIsEditable(NpcStatus $npcStatus): void
    {
        if ($this->isNpcStatusFinalized($npcStatus)) {
            throw ValidationException::withMessages([
                'npc_status' => 'This renewal is read-only because its workflow and all store seal confirmations are complete.',
            ]);
        }
    }

    private function isNpcStatusFinalized(NpcStatus $npcStatus): bool
    {
        $npcStatus->loadMissing(['workflowSteps', 'stores', 'sealReceipts']);

        $workflowComplete = collect(NpcStatus::WORKFLOW_STEPS)->every(
            fn (array $definition) => (bool) $npcStatus->workflowSteps
                ->firstWhere('key', $definition['key'])?->is_done
        );

        if (!$workflowComplete || $npcStatus->stores->isEmpty()) {
            return false;
        }

        return $npcStatus->stores->every(function (Store $store) use ($npcStatus) {
            return collect(NpcStatusAttachment::SEAL_TYPES)->every(function (string $type) use ($npcStatus, $store) {
                return $npcStatus->sealReceipts->contains(
                    fn (NpcSealReceipt $receipt) => (string) $receipt->store_id === (string) $store->id
                        && $receipt->seal_type === $type
                        && $receipt->confirmed_at !== null
                );
            });
        });
    }

    /**
     * Store-user view: their store(s), and per validity year the seals available
     * to download for the entity that assigned the store.
     */
    private function storeDownloadPayload(User $user): array
    {
        $stores = $user->stores()->orderBy('name')->get();

        if ($stores->isEmpty()) {
            return [];
        }

        $storeIds = $stores->pluck('id')->all();

        $statuses = NpcStatus::query()
            ->whereHas('stores', fn ($query) => $query->whereIn('stores.id', $storeIds))
            ->with([
                'company:id,name,code',
                'attachments' => fn ($query) => $query->latest('validity_from')->latest('created_at'),
                'stores' => fn ($query) => $query->whereIn('stores.id', $storeIds),
            ])
            ->orderByDesc('year')
            ->get();

        $receipts = NpcSealReceipt::whereIn('store_id', $storeIds)->get();

        return $stores->map(function (Store $store) use ($statuses, $receipts) {
            $years = $statuses
                ->filter(fn (NpcStatus $status) => $status->stores->contains('id', $store->id))
                ->map(function (NpcStatus $status) use ($store, $receipts) {
                    $seals = collect(NpcStatusAttachment::SEAL_TYPES)->map(function (string $type) use ($status, $store, $receipts) {
                        $attachment = $this->attachmentsPayload($status, $type, $status->year)[0] ?? null;
                        // SQL Server may hydrate BIGINT foreign keys as numeric
                        // strings while related model keys are integers.
                        $receipt = $receipts->first(fn (NpcSealReceipt $r) => (string) $r->npc_status_id === (string) $status->id
                            && (string) $r->store_id === (string) $store->id
                            && $r->seal_type === $type);

                        return [
                            'type' => $type,
                            'label' => NpcStatusAttachment::TYPE_LABELS[$type] ?? $type,
                            'available' => (bool) $attachment,
                            'name' => $attachment['name'] ?? null,
                            'download_url' => $attachment
                                ? route('npc-statuses.stores.seal.download', [$status->id, $store->id, $type])
                                : null,
                            'downloaded_at' => $receipt?->downloaded_at?->toIso8601String(),
                            'confirmed_at' => $receipt?->confirmed_at?->toIso8601String(),
                        ];
                    })->all();

                    return [
                        'npc_status_id' => $status->id,
                        'year' => $status->year,
                        'entity_name' => $status->company?->name,
                        'entity_code' => $status->company?->code,
                        'validity_from' => $status->validity_from?->format('Y-m-d'),
                        'validity_to' => $status->validity_to?->format('Y-m-d'),
                        'seals' => $seals,
                    ];
                })
                ->values()
                ->all();

            return [
                'store_id' => $store->id,
                'store_name' => $store->name,
                'store_code' => $store->code,
                'years' => $years,
            ];
        })->values()->all();
    }
}
