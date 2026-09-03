<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTicketRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; 
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'is_self_requester' => 'boolean',
            'sender_name' => 'nullable|required_if:is_self_requester,false|string|max:255',
            'sender_email' => 'nullable|required_if:is_self_requester,false|email|max:255',
            'department' => 'nullable|string|max:255',
            // Which department SERVICES this request. Optional: left blank it is
            // derived from the assignee, so intake paths that don't ask stay valid.
            'serving_department_id' => 'nullable|exists:departments,id',
            'notify_requester' => 'boolean',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'type' => 'nullable|in:bug,feature,task,spike',
            'status' => 'required|in:open,for_schedule,in_progress,resolved,closed,waiting_service_provider,waiting_client_feedback',
            'priority' => 'nullable|in:low,medium,high,urgent',
            'severity' => 'nullable|in:critical,major,minor,cosmetic',
            'assignee_id' => 'nullable|exists:users,id',
            'company_id' => 'required|exists:companies,id',
            'store_id' => 'nullable|exists:stores,id',
            'category_id' => 'nullable|exists:categories,id',
            'sub_category_id' => 'nullable|exists:sub_categories,id',
            'item_id' => 'nullable|required_with:project_task_id|exists:items,id',
            'vendor_id' => 'nullable|exists:vendors,id',
            'project_task_id' => 'nullable|integer|exists:project_tasks,id',
            'attachments' => 'nullable|array',
            'attachments.*' => 'file|max:1024000',
        ];
    }
}
