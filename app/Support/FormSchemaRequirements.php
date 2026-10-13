<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

/**
 * Enforces the `required` flags of a request type's form schema
 * (request_types.form_schema) on a submitted POS / SAP request.
 *
 * A checkbox group with nothing ticked arrives as [] — or is dropped from a multipart
 * body altogether — so "empty" has to mean more than a blank string. The request forms
 * run the same rules in the browser (resources/js/lib/schemaRequired.js), but only this
 * check cannot be skipped. Keep the two in step.
 */
class FormSchemaRequirements
{
    /**
     * @param  array|null  $schema  request_types.form_schema
     * @param  string  $rowsKey  payload key holding the line items ("details" on POS, "items" on SAP)
     *
     * @throws ValidationException
     */
    public static function assert(?array $schema, array $data, string $rowsKey): void
    {
        $formData = is_array($data['form_data'] ?? null) ? $data['form_data'] : [];
        $errors = [];

        foreach ($schema['fields'] ?? [] as $field) {
            if (! self::isEnforced($field) || ! self::isShown($field, $formData)) {
                continue;
            }

            if (blank($formData[$field['key']] ?? null)) {
                $errors["form_data.{$field['key']}"] = '"'.($field['label'] ?? $field['key']).'" is required.';
            }
        }

        if (! empty($schema['has_items'])) {
            $rows = is_array($data[$rowsKey] ?? null) ? array_values($data[$rowsKey]) : [];

            foreach ($rows as $index => $row) {
                foreach ($schema['items_columns'] ?? [] as $col) {
                    if (! self::isEnforced($col)) {
                        continue;
                    }

                    if (blank(is_array($row) ? ($row[$col['key']] ?? null) : null)) {
                        $errors["{$rowsKey}.{$index}.{$col['key']}"] = 'Item #'.($index + 1).': "'.($col['label'] ?? $col['key']).'" is required.';
                    }
                }
            }
        }

        if (! empty($errors)) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * A toggle always carries an answer: left untouched it reads "No".
     */
    private static function isEnforced(array $definition): bool
    {
        return ! empty($definition['required']) && ($definition['type'] ?? '') !== 'toggle';
    }

    /**
     * Mirror of the form's show_when rule: a conditionally hidden field is never required.
     */
    private static function isShown(array $field, array $formData): bool
    {
        $condition = $field['show_when'] ?? null;
        if (empty($condition['field'])) {
            return true;
        }

        $asText = fn ($value) => is_bool($value) ? ($value ? 'true' : 'false') : (string) ($value ?? '');
        $current = $formData[$condition['field']] ?? null;
        $expected = $asText($condition['value'] ?? null);

        if (is_array($current)) {
            return in_array($expected, array_map($asText, $current), true);
        }

        return $asText($current) === $expected;
    }
}
