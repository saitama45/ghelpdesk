<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTicketRequest extends FormRequest
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
            'is_self_requester' => 'nullable|boolean',
            'sender_name' => 'nullable|string|max:255',
            'sender_email' => 'nullable|email|max:255',
            'department' => 'nullable|string|max:255',
            'notify_requester' => 'nullable|boolean',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:65535',
            'type' => 'nullable|in:bug,feature,task,spike',
            'status' => 'required|in:open,for_schedule,in_progress,resolved,closed,waiting_service_provider,waiting_client_feedback',
            'priority' => 'nullable|in:low,medium,high,urgent',
            'severity' => 'nullable|in:critical,major,minor,cosmetic',
            'assignee_id' => 'nullable|exists:users,id',
            'company_id' => 'required|exists:companies,id',
            'store_id' => 'nullable|exists:stores,id',
            'category_id' => 'nullable|exists:categories,id',
            'sub_category_id' => 'nullable|exists:sub_categories,id',
            'item_id' => 'nullable|exists:items,id',
            'vendor_id' => 'nullable|exists:vendors,id',
        ];
    }
}
