<x-mail::message>
# Reminder: Approval Required

Hello {{ $approverName ?? 'Approver' }},

This is a gentle reminder that **{{ $formDefinition->name }} #{{ $record->id }}** is awaiting your approval.

<x-mail::button :url="route('dynamic-form.index', ['slug' => $formDefinition->slug])">
View Request
</x-mail::button>

Please review the request at your earliest convenience to avoid delays.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
