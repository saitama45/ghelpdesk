<?php

namespace App\Mail;

use App\Models\PosRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PosRequestNotification extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public PosRequest $posRequest,
        public string $action = 'created',
        public bool $isRequester = false
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        if ($this->isRequester) {
            $subject = 'Confirmation: Your POS Request has been received';
        } else {
            $subject = $this->action === 'created' ? 'New POS Request Submitted' : 'POS Request Updated';
        }
        
        return new Envelope(
            subject: "[POS Request #{$this->posRequest->id}] {$subject}: {$this->posRequest->requestType->name}",
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        $schema      = $this->posRequest->requestType->form_schema ?? [];
        $itemCols    = $schema['items_columns'] ?? [];
        $schemaItems = $this->posRequest->form_data['items'] ?? [];
        $hasSchema   = !empty($schema['has_items']) && !empty($itemCols);

        $resolvedItems = collect();
        if ($hasSchema) {
            $resolvedItems = collect($schemaItems)->map(
                fn($item) => $this->resolveFields($itemCols, $item)
            )->values();
        }

        return new Content(
            view: 'emails.pos-requests.notification',
            with: [
                'hasSchemaItems' => $hasSchema && count($schemaItems) > 0,
                'resolvedItems'  => $resolvedItems,
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

            $label = $field['label'] ?? ucwords(str_replace('_', ' ', $key));

            if ($value === null || $value === '') {
                $result[] = ['label' => $label, 'value' => '—'];
                continue;
            }

            if (is_bool($value)) {
                $result[] = ['label' => $label, 'value' => $value ? 'Yes' : 'No'];
                continue;
            }

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

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
