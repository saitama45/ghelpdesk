<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\NpcStatus;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
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
            new Middleware('can:npc_status.view', only: ['index', 'downloadAttachment']),
            new Middleware('can:npc_status.create', only: ['store']),
            new Middleware('can:npc_status.edit', only: ['update', 'syncStores']),
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
        ]);

        $year = (int) ($validated['year'] ?? now()->year);
        $status = $validated['status'] ?? null;
        $search = trim((string) ($validated['search'] ?? ''));

        $query = Company::query()
            ->with(['npcStatuses' => function ($npcQuery) use ($year, $status) {
                $npcQuery->where('year', $year)
                    ->when($status, fn ($query) => $query->where('status', $status))
                    ->withCount('stores');
            }])
            ->orderBy('name');

        if ($status) {
            $query->whereHas('npcStatuses', function ($npcQuery) use ($year, $status) {
                $npcQuery->where('year', $year)
                    ->where('status', $status);
            });
        }

        if ($search !== '') {
            $query->where(function ($companyQuery) use ($search, $year) {
                $companyQuery->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhereHas('npcStatuses', function ($npcQuery) use ($search, $year) {
                        $npcQuery->where('year', $year)
                            ->where('status', 'like', "%{$search}%");
                    });
            });
        }

        $companies = $query
            ->paginate((int) ($validated['per_page'] ?? 10))
            ->withQueryString()
            ->through(fn (Company $company) => $this->serializeCompanyRow($company));

        return Inertia::render('NpcStatus/Index', [
            'npcStatuses' => $companies,
            'filters' => [
                'year' => $year,
                'status' => $status,
                'search' => $search,
                'per_page' => (int) ($validated['per_page'] ?? 10),
            ],
            'statuses' => NpcStatus::STATUSES,
            'statusCounts' => $this->statusCounts($year),
            'stores' => $this->storeOptions($year),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validatePayload($request, true);
        $npcStatus = NpcStatus::where('company_id', $validated['company_id'])
            ->where('year', $validated['year'])
            ->first();

        if ($npcStatus && !$request->user()->can('npc_status.edit')) {
            abort(403);
        }

        if (!$npcStatus) {
            $npcStatus = new NpcStatus([
                'company_id' => $validated['company_id'],
                'year' => $validated['year'],
                'created_by' => $request->user()->id,
            ]);
        }

        $this->fillStatusFields($npcStatus, $validated, $request->user()->id);
        $npcStatus->save();
        $this->saveUploadedAttachments($npcStatus, $request);

        return redirect()->back()->with('success', 'NPC Status saved successfully');
    }

    public function update(Request $request, NpcStatus $npcStatus)
    {
        $validated = $this->validatePayload($request, false);

        $this->fillStatusFields($npcStatus, $validated, $request->user()->id);
        $npcStatus->save();
        $this->saveUploadedAttachments($npcStatus, $request);

        return redirect()->back()->with('success', 'NPC Status updated successfully');
    }

    public function destroy(NpcStatus $npcStatus)
    {
        $this->deleteAttachment($npcStatus->dpo_seal_path);
        $this->deleteAttachment($npcStatus->dpo_registration_path);
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

    public function downloadAttachment(NpcStatus $npcStatus, string $type)
    {
        $prefix = $type === 'seal' ? 'dpo_seal' : 'dpo_registration';
        $path = $npcStatus->getAttribute("{$prefix}_path");
        $name = $npcStatus->getAttribute("{$prefix}_name");

        if (!$path || !Storage::disk('public')->exists($path)) {
            abort(404, 'File not found.');
        }

        return Storage::disk('public')->download($path, $name ?: basename($path));
    }

    private function validatePayload(Request $request, bool $isCreate): array
    {
        $rules = [
            'validity_from' => 'required|date',
            'validity_to' => 'required|date|after_or_equal:validity_from',
            'status' => ['required', Rule::in(NpcStatus::STATUSES)],
            'dpo_seal' => 'nullable|file|mimes:pdf,jpg,jpeg,png,webp|max:' . self::MAX_ATTACHMENT_KILOBYTES,
            'dpo_registration' => 'nullable|file|mimes:pdf,jpg,jpeg,png,webp|max:' . self::MAX_ATTACHMENT_KILOBYTES,
        ];

        if ($isCreate) {
            $rules = array_merge([
                'company_id' => 'required|integer|exists:companies,id',
                'year' => 'required|integer|min:2000|max:2100',
            ], $rules);
        }

        return $request->validate($rules);
    }

    private function fillStatusFields(NpcStatus $npcStatus, array $validated, int $userId): void
    {
        $npcStatus->validity_from = $validated['validity_from'];
        $npcStatus->validity_to = $validated['validity_to'];
        $npcStatus->status = $validated['status'];
        $npcStatus->updated_by = $userId;
    }

    private function saveUploadedAttachments(NpcStatus $npcStatus, Request $request): void
    {
        $changed = false;

        foreach (['dpo_seal' => 'seal', 'dpo_registration' => 'registration'] as $field => $label) {
            if (!$request->hasFile($field)) {
                continue;
            }

            $this->deleteAttachment($npcStatus->getAttribute("{$field}_path"));
            $this->setAttachment($npcStatus, $field, $request->file($field), $label);
            $changed = true;
        }

        if ($changed) {
            $npcStatus->save();
        }
    }

    private function setAttachment(NpcStatus $npcStatus, string $field, UploadedFile $file, string $label): void
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $fileName = "{$label}-" . Str::uuid() . ($extension ? ".{$extension}" : '');
        $path = $file->storeAs(
            "npc-statuses/{$npcStatus->year}/{$npcStatus->company_id}",
            $fileName,
            'public'
        );

        $npcStatus->setAttribute("{$field}_path", str_replace('\\', '/', $path));
        $npcStatus->setAttribute("{$field}_name", $file->getClientOriginalName());
        $npcStatus->setAttribute("{$field}_mime_type", $file->getClientMimeType());
        $npcStatus->setAttribute("{$field}_size", $file->getSize());
    }

    private function deleteAttachment(?string $path): void
    {
        if ($path) {
            Storage::disk('public')->delete($path);
        }
    }

    private function serializeCompanyRow(Company $company): array
    {
        $npcStatus = $company->npcStatuses->first();

        return [
            'id' => $company->id,
            'name' => $company->name,
            'code' => $company->code,
            'description' => $company->description,
            'is_active' => $company->is_active,
            'npc_status' => $npcStatus ? $this->serializeNpcStatus($npcStatus) : null,
            'store_count' => $npcStatus ? (int) $npcStatus->stores_count : 0,
        ];
    }

    private function serializeNpcStatus(NpcStatus $npcStatus): array
    {
        return [
            'id' => $npcStatus->id,
            'company_id' => $npcStatus->company_id,
            'year' => $npcStatus->year,
            'validity_from' => $npcStatus->validity_from?->format('Y-m-d'),
            'validity_to' => $npcStatus->validity_to?->format('Y-m-d'),
            'status' => $npcStatus->status,
            'store_count' => (int) ($npcStatus->stores_count ?? 0),
            'dpo_seal' => $this->attachmentPayload($npcStatus, 'dpo_seal', 'seal'),
            'dpo_registration' => $this->attachmentPayload($npcStatus, 'dpo_registration', 'registration'),
        ];
    }

    private function attachmentPayload(NpcStatus $npcStatus, string $field, string $type): ?array
    {
        $path = $npcStatus->getAttribute("{$field}_path");

        if (!$path) {
            return null;
        }

        return [
            'name' => $npcStatus->getAttribute("{$field}_name"),
            'mime_type' => $npcStatus->getAttribute("{$field}_mime_type"),
            'size' => $npcStatus->getAttribute("{$field}_size"),
            'url' => route('npc-statuses.attachments.download', [$npcStatus, $type]),
        ];
    }

    private function storeOptions(int $year): array
    {
        return Store::query()
            ->with(['npcStatuses' => function ($query) use ($year) {
                $query->wherePivot('year', $year)->with('company:id,name,code');
            }])
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'area', 'brand', 'is_active'])
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
                ];
            })
            ->values()
            ->all();
    }

    private function statusCounts(int $year): array
    {
        $counts = NpcStatus::query()
            ->where('year', $year)
            ->withCount('stores')
            ->get(['id', 'status'])
            ->groupBy('status')
            ->map(fn ($rows) => [
                'entities' => $rows->count(),
                'stores' => $rows->sum('stores_count'),
            ]);

        return collect(NpcStatus::STATUSES)
            ->mapWithKeys(fn ($status) => [
                $status => [
                    'entities' => (int) ($counts[$status]['entities'] ?? 0),
                    'stores' => (int) ($counts[$status]['stores'] ?? 0),
                ],
            ])
            ->all();
    }
}
