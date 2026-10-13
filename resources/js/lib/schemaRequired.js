// Required-field rules for the schema-driven POS / SAP request forms
// (request_types.form_schema). The server repeats them in
// App\Support\FormSchemaRequirements — keep the two in step.

// Blank text, or a group with nothing picked. A checkbox group is [] when empty —
// never '' — so it has to be checked by length.
export const isBlank = (val) =>
    val === null || val === undefined
    || (typeof val === 'string' && val.trim() === '')
    || (Array.isArray(val) && val.length === 0)

// Same show_when rule DynamicFormRenderer applies: a hidden field is never required
export const isFieldShown = (field, formData) => {
    if (!field.show_when) return true
    const { field: depKey, value: depVal } = field.show_when
    const current = formData[depKey]
    if (Array.isArray(current)) return current.includes(depVal)
    return String(current ?? '') === String(depVal)
}

// A toggle always carries an answer: left untouched it reads "No"
const isEnforced = (definition) => !!definition.required && definition.type !== 'toggle'

// Required schema fields still unanswered, keyed the way the server reports them
export const missingFieldErrors = (fields, formData) => {
    const errors = {}
    fields.forEach(field => {
        if (isEnforced(field) && isFieldShown(field, formData) && isBlank(formData[field.key])) {
            errors[`form_data.${field.key}`] = `"${field.label}" is required.`
        }
    })
    return errors
}

// Required line-item cells still unanswered. rowsKey is the payload key holding the
// rows ("details" on POS, "items" on SAP).
export const missingItemErrors = (columns, rows, rowsKey) => {
    const errors = {}
    rows.forEach((row, rowIdx) => {
        columns.forEach(col => {
            if (isEnforced(col) && isBlank(row?.[col.key])) {
                errors[`${rowsKey}.${rowIdx}.${col.key}`] = `Item #${rowIdx + 1}: "${col.label}" is required.`
            }
        })
    })
    return errors
}
