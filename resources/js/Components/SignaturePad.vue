<template>
    <div>
        <div class="flex items-center justify-between gap-2">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">
                {{ label }}
                <span v-if="required" class="text-rose-600">*</span>
            </label>
            <button v-if="hasInk" type="button" @click="clear"
                    class="text-xs font-semibold text-gray-500 transition-colors hover:text-rose-600 dark:text-gray-400 dark:hover:text-rose-400">
                Clear
            </button>
        </div>

        <div ref="wrap"
             class="relative mt-1 overflow-hidden rounded-lg border-2 border-dashed bg-white transition-colors dark:bg-slate-900"
             :class="hasInk
                 ? 'border-emerald-400 dark:border-emerald-500/50'
                 : 'border-gray-300 dark:border-gray-600'">
            <canvas
                ref="canvas"
                class="block w-full touch-none"
                :style="{ height: height + 'px', cursor: 'crosshair' }"
                @pointerdown="start"
                @pointermove="draw"
                @pointerup="stop"
                @pointerleave="stop"
                @pointercancel="stop"
            />

            <!-- Placeholder and baseline, drawn over the canvas so clearing the
                 bitmap never wipes them. pointer-events-none keeps them from
                 swallowing the very first stroke. -->
            <div class="pointer-events-none absolute inset-0 flex flex-col items-center justify-end pb-3">
                <div v-if="!hasInk" class="mb-auto mt-auto text-center text-sm text-gray-400 dark:text-gray-500">
                    {{ placeholder }}
                </div>
                <div class="w-4/5 border-t border-gray-300 dark:border-gray-600"></div>
                <span class="mt-1 text-[10px] uppercase tracking-wider text-gray-400">Sign above</span>
            </div>
        </div>

        <p v-if="hint" class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ hint }}</p>
    </div>
</template>

<script setup>
import { ref, onMounted, onBeforeUnmount, watch } from 'vue'

/**
 * Hand-drawn signature capture.
 *
 * Emits a PNG data URL through v-model, or null when nothing has been drawn. The
 * controller decodes it and stores a real file — see App\Support\SignatureImage.
 *
 * Pointer events rather than separate mouse/touch handlers: one code path covers
 * a mouse, a finger and a stylus, which matters because stakeholders sign these
 * on a phone. `touch-none` stops the browser panning the page mid-stroke.
 */
const props = defineProps({
    modelValue: { type: String, default: null },
    label: { type: String, default: 'Signature' },
    placeholder: { type: String, default: 'Draw your signature here' },
    hint: { type: String, default: '' },
    height: { type: Number, default: 160 },
    required: { type: Boolean, default: false },
})

const emit = defineEmits(['update:modelValue'])

const canvas = ref(null)
const wrap = ref(null)
const hasInk = ref(false)

let ctx = null
let drawing = false
let last = null
let ratio = 1

/**
 * Sizes the backing store to the device pixel ratio, so strokes are not blurry
 * on a retina screen or a phone. Resizing clears the bitmap, so an existing
 * drawing is re-rendered from the emitted data URL afterwards.
 */
const size = () => {
    const el = canvas.value
    if (!el) return

    const previous = hasInk.value ? el.toDataURL('image/png') : null

    ratio = window.devicePixelRatio || 1
    const width = el.clientWidth || wrap.value?.clientWidth || 480

    el.width = Math.floor(width * ratio)
    el.height = Math.floor(props.height * ratio)

    ctx = el.getContext('2d')
    ctx.scale(ratio, ratio)
    ctx.lineWidth = 2
    ctx.lineCap = 'round'
    ctx.lineJoin = 'round'
    ctx.strokeStyle = '#111827'

    if (previous) {
        const img = new Image()
        img.onload = () => ctx.drawImage(img, 0, 0, width, props.height)
        img.src = previous
    }
}

const point = (event) => {
    const rect = canvas.value.getBoundingClientRect()
    return { x: event.clientX - rect.left, y: event.clientY - rect.top }
}

const start = (event) => {
    drawing = true
    last = point(event)
    canvas.value.setPointerCapture?.(event.pointerId)

    // A tap with no movement should still leave a mark.
    ctx.beginPath()
    ctx.arc(last.x, last.y, 1, 0, Math.PI * 2)
    ctx.fillStyle = '#111827'
    ctx.fill()
    hasInk.value = true
}

const draw = (event) => {
    if (!drawing) return

    const now = point(event)
    ctx.beginPath()
    ctx.moveTo(last.x, last.y)
    ctx.lineTo(now.x, now.y)
    ctx.stroke()
    last = now
}

const stop = (event) => {
    if (!drawing) return
    drawing = false
    canvas.value?.releasePointerCapture?.(event.pointerId)
    commit()
}

const commit = () => {
    emit('update:modelValue', hasInk.value ? canvas.value.toDataURL('image/png') : null)
}

const clear = () => {
    if (!ctx || !canvas.value) return
    ctx.clearRect(0, 0, canvas.value.width, canvas.value.height)
    hasInk.value = false
    emit('update:modelValue', null)
}

// The parent resetting the model (after a successful save) must visibly clear
// the pad too, or the next signer inherits the previous person's signature.
watch(() => props.modelValue, (value) => {
    if (!value && hasInk.value) clear()
})

onMounted(() => {
    size()
    window.addEventListener('resize', size)
})

onBeforeUnmount(() => window.removeEventListener('resize', size))

defineExpose({ clear })
</script>
