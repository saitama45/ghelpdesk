<?php

namespace App\Mail;

use App\Models\SapRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SapRequestNotification extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public SapRequest $sapRequest,
        public string $action = 'created',
        public bool $isRequester = false
    ) {}

    public function envelope(): Envelope
    {
        if ($this->isRequester) {
            $subject = 'Confirmation: Your SAP Request has been received';
        } else {
            $subject = $this->action === 'created' ? 'New SAP Request Submitted' : 'SAP Request Updated';
        }

        return new Envelope(
            subject: "[SAP Request #{$this->sapRequest->id}] {$subject}: {$this->sapRequest->requestType->name}",
        );
    }

    public function content(): Content
    {
        $schema = $this->sapRequest->requestType->form_schema ?? [];

        return new Content(
            view: 'emails.sap-requests.notification',
            with: [
                // Pre-resolved form fields and item rows so the blade template
                // shows human-readable labels and option names, not raw keys/codes.
                'resolvedFormFields' => $this->resolveFields(
                    $schema['fields'] ?? [],
                    $this->sapRequest->form_data ?? []
                ),
                'resolvedItems' => $this->sapRequest->items->map(
                    fn($item) => $this->resolveFields(
                        $schema['items_columns'] ?? [],
                        $item->item_data ?? []
                    )
                )->values(),
            ],
        );
    }

    /**
     * Resolve a data array against its field definitions, returning
     * [['label' => ..., 'value' => ...], ...] with option codes replaced
     * by their human-readable labels.
     */
    private function resolveFields(array $fieldDefs, array $data): array
    {
        $fieldMap = collect($fieldDefs)->keyBy('key');
        $result   = [];

        foreach ($data as $key => $value) {
            $field = $fieldMap->get($key);

            // Prefer the schema label; fall back to title-casing the key.
            $label = $field['label'] ?? ucwords(str_replace('_', ' ', $key));

            if ($value === null || $value === '') {
                $result[] = ['label' => $label, 'value' => '—'];
                continue;
            }

            if (is_bool($value)) {
                $result[] = ['label' => $label, 'value' => $value ? 'Yes' : 'No'];
                continue;
            }

            // Resolve option values to their labels when the field has options.
            // Supports both a plain `options` array and a `option_map` (dependent options).
            $options = null;
            if ($field) {
                if (!empty($field['option_map']) && !empty($field['depends_on'])) {
                    $options = $field['option_map'][$data[$field['depends_on']] ?? ''] ?? null;
                }
                if ($options === null) {
                    $options = $field['options'] ?? null;
                }
            }

            if (!empty($options)) {
                $optMap = collect($options)->keyBy('value');
                if (is_array($value)) {
                    $displayVal = collect($value)
                        ->map(function($v) use ($optMap) {
                            if (!is_scalar($v)) return json_encode($v);
                            $opt = $optMap->get((string)$v);
                            return is_array($opt) ? ($opt['label'] ?? (string)$v) : (string)$v;
                        })
                        ->implode(', ');
                } else {
                    if (!is_scalar($value)) {
                        $displayVal = json_encode($value);
                    } else {
                        $opt = $optMap->get((string)$value);
                        $displayVal = is_array($opt) ? ($opt['label'] ?? (string)$value) : (string)$value;
                    }
                }
            } elseif (is_array($value)) {
                $displayVal = implode(', ', array_map(fn($v) => is_scalar($v) ? (string)$v : json_encode($v), $value));
            } else {
                $displayVal = is_scalar($value) ? (string)$value : json_encode($value);
            }

            $result[] = ['label' => $label, 'value' => $displayVal ?: '—'];
        }

        return $result;
    }

    public function attachments(): array
    {
        return [];
    }
}
