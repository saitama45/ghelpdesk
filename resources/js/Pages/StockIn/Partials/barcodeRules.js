/**
 * Why a typed/scanned custom barcode cannot be used, or '' when it can.
 * Shared by the inline scan field and CustomCodeModal so both enforce the same rules.
 *
 * @param {string} code         the trimmed code
 * @param {string} currentCode  the unit's own current barcode (keeping it is always allowed,
 *                              even where older imports repeat one)
 * @param {Object} taken        lower-cased barcodes of the other units/boxes → label naming them
 */
export const customBarcodeError = (code, currentCode, taken) => {
    // Code 128 (the printed label) only encodes printable ASCII.
    if (!/^[\x20-\x7E]+$/.test(code)) return 'Use letters, numbers and standard symbols only. Barcodes cannot print accents or emoji.'
    const key = code.toLowerCase()
    if (key !== String(currentCode || '').trim().toLowerCase() && taken[key]) {
        return `Already used by ${taken[key]} in this stock-in.`
    }
    return ''
}
