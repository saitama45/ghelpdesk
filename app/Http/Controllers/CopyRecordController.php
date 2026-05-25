<?php

namespace App\Http\Controllers;

use App\Models\FormDefinition;
use App\Models\RequestType;
use App\Services\DynamicForms\FormServiceFactory;
use App\Services\PosRequestService;
use App\Services\SapRequestService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Inertia\Inertia;

class CopyRecordController extends Controller
{
    /**
     * Provide lists of available copy targets (Request Types, Form Definitions).
     */
    public function targets()
    {
        return response()->json([
            'sap_types' => RequestType::where('is_active', true)
                ->whereJsonContains('request_for', 'SAP')
                ->get(['id', 'name', 'form_schema']),
            'pos_types' => RequestType::where('is_active', true)
                ->whereJsonContains('request_for', 'POS')
                ->get(['id', 'name', 'form_schema']),
            'form_definitions' => FormDefinition::where('is_active', true)
                ->get(['id', 'name', 'slug', 'form_schema']),
        ]);
    }

    /**
     * Store source record data in database and redirect to the newly created record.
     */
    public function transfer(Request $request)
    {
        $request->validate([
            'target_type' => 'required|in:sap,pos,dynamic',
            'target_id' => 'required',
            'payload' => 'required|array',
        ]);

        $type = $request->target_type;
        $targetId = $request->target_id;
        $payload = $request->payload;
        $redirectUrl = '';

        // Use the original requester's user_id so the record is attributed to them, not the copier
        $originalUserId = isset($payload['source_user_id']) ? (int) $payload['source_user_id'] : auth()->id();
        unset($payload['source_user_id']);

        try {
            return DB::transaction(function () use ($type, $targetId, $payload, $originalUserId) {
                if ($type === 'sap') {
                    $payload['request_type_id'] = $targetId;
                    if (!isset($payload['items'])) {
                        $payload['items'] = [];
                    }
                    $sapService = app(SapRequestService::class);
                    $newRecord = $sapService->createRequest($payload, $originalUserId);
                    $redirectUrl = route('sap-requests.edit', $newRecord->id);
                } elseif ($type === 'pos') {
                    $payload['request_type_id'] = $targetId;
                    if (empty($payload['launch_date'])) {
                        $payload['launch_date'] = now()->addDays(7)->format('Y-m-d');
                    }
                    if (empty($payload['stores_covered'])) {
                        $payload['stores_covered'] = ['Not Specified'];
                    }
                    $payload['details'] = $payload['items'] ?? [];

                    $posService = app(PosRequestService::class);
                    $newRecord = $posService->createRequest($payload, $originalUserId);
                    $redirectUrl = route('pos-requests.edit', $newRecord->id);
                } elseif ($type === 'dynamic') {
                    $formDefinition = FormDefinition::where('slug', $targetId)->firstOrFail();

                    $dynamicRequest = Request::create('', 'POST', [
                        'request_type_id' => $payload['request_type_id'] ?? null,
                        'form_data' => $payload['form_data'] ?? [],
                        'items' => $payload['items'] ?? [],
                    ]);
                    $dynamicRequest->setUserResolver(fn () => auth()->user());
                    $dynamicRequest->attributes->set('created_by', $originalUserId);

                    $dynamicService = app(FormServiceFactory::class)->make($formDefinition->slug);
                    $newRecord = $dynamicService->store($dynamicRequest, $formDefinition);

                    if (method_exists($dynamicService, 'notifyCurrentApprovers')) {
                        $dynamicService->notifyCurrentApprovers($formDefinition, $newRecord);
                    }

                    $redirectUrl = route('dynamic-form.show', ['slug' => $targetId, 'id' => $newRecord->id]);
                }

                return response()->json([
                    'redirect_url' => $redirectUrl,
                    'message' => 'Record copied and saved successfully.'
                ]);
            });
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to copy record: ' . $e->getMessage()
            ], 500);
        }
    }
}
