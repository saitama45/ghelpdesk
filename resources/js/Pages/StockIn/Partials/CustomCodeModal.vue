<template>
    <Modal :show="show" @close="emit('close')" max-width="md">
        <form @submit.prevent="apply" class="p-5 space-y-4">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h3 class="text-sm font-black uppercase tracking-widest text-gray-900 dark:text-gray-100">Barcode &amp; QR Code</h3>
                    <p v-if="title" class="mt-0.5 text-xs text-gray-500 dark:text-gray-300">{{ title }}</p>
                </div>
                <button type="button" @click="emit('close')" class="text-gray-400 hover:text-gray-600 transition-colors dark:text-gray-400" aria-label="Close">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                </button>
            </div>

            <!-- Barcode -->
            <section class="space-y-2 rounded-xl border border-gray-200 p-3 dark:border-gray-700">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="text-[10px] font-black uppercase tracking-widest text-gray-500 dark:text-gray-300">Barcode</span>
                    <div class="inline-flex rounded-lg border border-gray-200 bg-gray-50 p-0.5 dark:border-gray-700 dark:bg-gray-900/50">
                        <button v-for="option in modeOptions" :key="option.value" type="button" @click="barcodeMode = option.value"
                                :class="modeButtonClass(barcodeMode === option.value)">{{ option.label }}</button>
                    </div>
                </div>

                <div v-if="barcodeMode === 'custom'" class="space-y-1">
                    <input ref="barcodeInput" v-model="customBarcode" type="text" maxlength="255" autocomplete="off" spellcheck="false"
                           placeholder="Type or scan the label's barcode"
                           class="block w-full rounded-md border-gray-300 shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm font-mono dark:border-gray-600 dark:bg-slate-900 dark:text-slate-100 dark:placeholder-slate-400">
                    <p v-if="barcodeError" class="text-[11px] font-semibold text-red-600 dark:text-red-400">{{ barcodeError }}</p>
                    <p v-else class="text-[11px] text-gray-500 dark:text-gray-400">Must be unique: no other stock unit may carry the same barcode.</p>
                </div>
                <p v-else-if="barcodeCustom" class="text-[11px] text-gray-500 dark:text-gray-400">A new system barcode is generated when you apply.</p>

                <div v-if="barcodePreview" class="flex justify-center rounded-lg bg-white p-2 dark:bg-white">
                    <img :src="barcodePreview" :alt="previewBarcodeText" class="max-h-20 max-w-full">
                </div>
            </section>

            <!-- QR code -->
            <section class="space-y-2 rounded-xl border border-gray-200 p-3 dark:border-gray-700">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="text-[10px] font-black uppercase tracking-widest text-gray-500 dark:text-gray-300">QR Code</span>
                    <div class="inline-flex rounded-lg border border-gray-200 bg-gray-50 p-0.5 dark:border-gray-700 dark:bg-gray-900/50">
                        <button v-for="option in modeOptions" :key="option.value" type="button" @click="qrMode = option.value"
                                :class="modeButtonClass(qrMode === option.value)">{{ option.label }}</button>
                    </div>
                </div>

                <div v-if="qrMode === 'custom'" class="space-y-1">
                    <textarea ref="qrInput" v-model="customQr" rows="3" spellcheck="false"
                              placeholder="Text, link or the content of the label's QR code"
                              class="block w-full rounded-md border-gray-300 shadow-sm focus:ring-blue-500 focus:border-blue-500 text-xs font-mono dark:border-gray-600 dark:bg-slate-900 dark:text-slate-100 dark:placeholder-slate-400"></textarea>
                    <div class="flex items-center justify-between gap-2">
                        <p v-if="qrError" class="text-[11px] font-semibold text-red-600 dark:text-red-400">{{ qrError }}</p>
                        <span v-else></span>
                        <button v-if="previewBarcodeText" type="button" @click="customQr = previewBarcodeText"
                                class="flex-shrink-0 text-[11px] font-semibold text-blue-600 hover:underline dark:text-blue-400">Use the barcode</button>
                    </div>
                </div>
                <p v-else class="text-[11px] text-gray-500 dark:text-gray-400">Built from the item, delivery and barcode details, and rebuilt when they change.</p>

                <div v-if="qrPreview" class="flex justify-center rounded-lg bg-white p-2 dark:bg-white">
                    <img :src="qrPreview" alt="QR code preview" class="h-28 w-28">
                </div>
            </section>

            <div class="flex justify-end gap-2">
                <button type="button" @click="emit('close')"
                        class="px-3 py-1.5 text-xs font-semibold text-gray-600 bg-gray-100 rounded-md hover:bg-gray-200 transition-colors dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700">
                    Cancel
                </button>
                <button type="submit" :disabled="!canApply"
                        class="px-3 py-1.5 text-xs font-bold text-white bg-blue-600 rounded-md hover:bg-blue-700 transition-colors disabled:cursor-not-allowed disabled:opacity-50">
                    Apply
                </button>
            </div>
        </form>
    </Modal>
</template>

<script setup>
import { computed, nextTick, ref, watch } from 'vue'
import JsBarcode from 'jsbarcode'
import QRCode from 'qrcode'
import Modal from '@/Components/Modal.vue'

const props = defineProps({
    show: { type: Boolean, default: false },
    title: { type: String, default: '' },
    barcode: { type: String, default: '' },
    qrcode: { type: String, default: '' },
    barcodeCustom: { type: Boolean, default: false },
    qrcodeCustom: { type: Boolean, default: false },
    // Lower-cased barcodes on the other units/boxes of this stock-in, mapped to a label naming them.
    takenBarcodes: { type: Object, default: () => ({}) },
})

const emit = defineEmits(['close', 'apply'])

const modeOptions = [
    { value: 'system', label: 'System generated' },
    { value: 'custom', label: 'Custom' },
]

const barcodeMode = ref('system')
const qrMode = ref('system')
const customBarcode = ref('')
const customQr = ref('')
const qrPreview = ref('')
const barcodeInput = ref(null)
const qrInput = ref(null)

const modeButtonClass = (active) => [
    'rounded-md px-2.5 py-1 text-[11px] font-bold transition-colors',
    active
        ? 'bg-white text-blue-700 shadow-sm dark:bg-gray-800 dark:text-blue-300'
        : 'text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200',
]

watch(() => props.show, (open) => {
    if (!open) return
    barcodeMode.value = props.barcodeCustom ? 'custom' : 'system'
    qrMode.value = props.qrcodeCustom ? 'custom' : 'system'
    customBarcode.value = props.barcodeCustom ? props.barcode : ''
    customQr.value = props.qrcodeCustom ? props.qrcode : ''
})

watch(barcodeMode, (mode) => { if (mode === 'custom') nextTick(() => barcodeInput.value?.focus()) })
watch(qrMode, (mode) => { if (mode === 'custom') nextTick(() => qrInput.value?.focus()) })

const trimmedBarcode = computed(() => customBarcode.value.trim())
const trimmedQr = computed(() => customQr.value.trim())

const barcodeError = computed(() => {
    if (barcodeMode.value !== 'custom' || !trimmedBarcode.value) return ''
    const code = trimmedBarcode.value
    // Code 128 (the printed label) only encodes printable ASCII.
    if (!/^[\x20-\x7E]+$/.test(code)) return 'Use letters, numbers and standard symbols only. Barcodes cannot print accents or emoji.'
    const key = code.toLowerCase()
    // Keeping the unit's own current code is always allowed, even where older imports repeat one.
    if (key !== props.barcode.trim().toLowerCase() && props.takenBarcodes[key]) {
        return `Already used by ${props.takenBarcodes[key]} in this stock-in.`
    }
    return ''
})

const qrError = computed(() => {
    if (qrMode.value !== 'custom' || !trimmedQr.value) return ''
    try {
        QRCode.create(trimmedQr.value, { errorCorrectionLevel: 'M' })
        return ''
    } catch {
        return 'Too long to fit in a QR code. Shorten the text.'
    }
})

/** The barcode the unit will carry, or '' when a new system code will be generated on apply. */
const previewBarcodeText = computed(() => {
    if (barcodeMode.value === 'custom') return barcodeError.value ? '' : trimmedBarcode.value
    return props.barcodeCustom ? '' : props.barcode
})

const barcodePreview = computed(() => {
    if (!previewBarcodeText.value) return ''
    try {
        const canvas = document.createElement('canvas')
        JsBarcode(canvas, previewBarcodeText.value, { format: 'CODE128', width: 2, height: 60, displayValue: true, fontSize: 12, margin: 8 })
        return canvas.toDataURL('image/png')
    } catch {
        return ''
    }
})

let qrRun = 0
watch([() => props.show, qrMode, trimmedQr, qrError], async () => {
    const run = ++qrRun
    // A system QR is rebuilt on apply, so only an existing system QR is worth previewing.
    const text = qrMode.value === 'custom'
        ? (qrError.value ? '' : trimmedQr.value)
        : (props.qrcodeCustom ? '' : props.qrcode)
    if (!props.show || !text) {
        qrPreview.value = ''
        return
    }
    try {
        const url = await QRCode.toDataURL(text, { width: 300, margin: 2, errorCorrectionLevel: 'M' })
        if (run === qrRun) qrPreview.value = url
    } catch {
        if (run === qrRun) qrPreview.value = ''
    }
}, { immediate: true })

const canApply = computed(() => {
    if (barcodeMode.value === 'custom' && (!trimmedBarcode.value || barcodeError.value)) return false
    if (qrMode.value === 'custom' && (!trimmedQr.value || qrError.value)) return false
    return true
})

const apply = () => {
    if (!canApply.value) return
    emit('apply', {
        barcodeCustom: barcodeMode.value === 'custom',
        barcode: trimmedBarcode.value,
        qrcodeCustom: qrMode.value === 'custom',
        qrcode: trimmedQr.value,
    })
}
</script>
