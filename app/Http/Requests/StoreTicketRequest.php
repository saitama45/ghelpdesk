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
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:65535',
            'type' => 'nullable|in:bug,feature,task,spike',
            'status' => 'required|in:open,in_progress,resolved,closed,waiting',
            'priority' => 'nullable|in:low,medium,high,urgent',
            'severity' => 'nullable|in:critical,major,minor,cosmetic',
            'assignee_id' => 'nullable|exists:users,id',
            'company_id' => 'required|exists:companies,id',
            'store_id' => 'nullable|exists:stores,id',
            'category_id' => 'nullable|exists:categories,id',
            'sub_category_id' => 'nullable|exists:sub_categories,id',
            'item_id' => 'nullable|exists:items,id',
            'attachments' => 'nullable|array',
            'attachments.*' => 'file|max:10240|mimes:jpg,jpeg,png,pdf,doc,docx,txt',
        ];
    }
}
