<?php

namespace App\Http\Controllers;

use App\Models\RequestType;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class RequestTypeController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:request_types.view', only: ['index']),
            new Middleware('can:request_types.create', only: ['store']),
            new Middleware('can:request_types.edit', only: ['update', 'updateSchema']),
            new Middleware('can:request_types.delete', only: ['destroy']),
        ];
    }

    public function index(Request $request)
    {
        $query = RequestType::query();
        
        if ($request->filled('search')) {
            $query->where(function($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")
                  ->orWhere('code', 'like', "%{$request->search}%")
                  ->orWhere('request_for', 'like', "%{$request->search}%");
            });
        }
        
        $requestTypes = $query->paginate($request->get('per_page', 10))->withQueryString();
        
        return Inertia::render('RequestTypes/Index', [
            'requestTypes' => $requestTypes,
            'users' => User::active()->orderBy('name')->get(['id', 'name', 'email']),
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'code' => 'required|string|max:50|unique:request_types,code',
            'name' => 'required|string|max:255',
            'request_for' => 'required|array|min:1',
            'request_for.*' => 'in:SAP,POS',
            'approval_levels' => 'required|integer|min:0',
            'approver_matrix' => 'nullable|array',
            'approver_matrix.*.level' => 'required|integer|min:1',
            'approver_matrix.*.user_ids' => 'nullable|array',
            'approver_matrix.*.user_ids.*' => 'exists:users,id',
            'cc_emails' => 'nullable|string',
            'form_schema' => 'nullable|array',
        ]);

        RequestType::create([
            'code' => $request->code,
            'name' => $request->name,
            'request_for' => $request->request_for,
            'approval_levels' => $request->approval_levels,
            'approver_matrix' => $this->normalizeApproverMatrix(
                $request->input('approver_matrix', []),
                (int) $request->approval_levels
            ),
            'cc_emails' => $request->cc_emails,
            'form_schema' => $request->form_schema,
            'is_active' => true,
        ]);

        return redirect()->back()->with('success', 'Request Type created successfully');
    }

    public function update(Request $request, RequestType $requestType)
    {
        $request->validate([
            'code' => 'required|string|max:50|unique:request_types,code,' . $requestType->id,
            'name' => 'required|string|max:255',
            'request_for' => 'required|array|min:1',
            'request_for.*' => 'in:SAP,POS',
            'approval_levels' => 'required|integer|min:0',
            'approver_matrix' => 'nullable|array',
            'approver_matrix.*.level' => 'required|integer|min:1',
            'approver_matrix.*.user_ids' => 'nullable|array',
            'approver_matrix.*.user_ids.*' => 'exists:users,id',
            'cc_emails' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $requestType->update([
            'code' => $request->code,
            'name' => $request->name,
            'request_for' => $request->request_for,
            'approval_levels' => $request->approval_levels,
            'approver_matrix' => $this->normalizeApproverMatrix(
                $request->input('approver_matrix', []),
                (int) $request->approval_levels
            ),
            'cc_emails' => $request->cc_emails,
            'is_active' => $request->boolean('is_active'),
        ]);

        return redirect()->back()->with('success', 'Request Type updated successfully');
    }

    public function updateSchema(Request $request, RequestType $requestType)
    {
        $request->validate([
            'form_schema' => 'nullable|array',
        ]);

        $requestType->update(['form_schema' => $request->form_schema]);

        return redirect()->back()->with('success', 'Form schema saved successfully');
    }

    public function destroy(RequestType $requestType)
    {
        $requestType->delete();
        return redirect()->back()->with('success', 'Request Type deleted successfully');
    }

    private function normalizeApproverMatrix(array $approverMatrix, int $approvalLevels): array
    {
        if ($approvalLevels <= 0) {
            return [];
        }

        return collect(range(1, $approvalLevels))
            ->map(function (int $level) use ($approverMatrix) {
                $match = collect($approverMatrix)->firstWhere('level', $level);

                return [
                    'level' => $level,
                    'user_ids' => collect($match['user_ids'] ?? [])
                        ->map(fn ($id) => (int) $id)
                        ->filter()
                        ->unique()
                        ->values()
                        ->all(),
                ];
            })
            ->all();
    }
}
