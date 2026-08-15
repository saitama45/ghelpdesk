<template>
    <div v-if="items.length" ref="root">
        <div class="flex flex-wrap gap-2">
            <div v-for="(file, index) in items" :key="file.id ?? index"
                 class="group relative overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700">
                <button v-if="file.is_image !== false" type="button" @click="open(index)"
                        :title="`${file.file_name} — click to preview`"
                        class="block cursor-zoom-in">
                    <img :src="file.url" :alt="file.file_name"
                         class="bg-gray-50 object-cover transition-transform group-hover:scale-105 dark:bg-gray-900"
                         :class="sizeClass"
                         loading="lazy" @error="onBroken($event, file)" />
                </button>

                <!-- Non-images (PDF, logs) can't be previewed inline. -->
                <a v-else :href="file.url" target="_blank" rel="noopener" :title="file.file_name"
                   class="flex items-center justify-center bg-gray-50 px-2 text-center text-[10px] text-gray-500 dark:bg-gray-900 dark:text-gray-300"
                   :class="sizeClass">
                    {{ file.file_name }}
                </a>

                <button v-if="removable" type="button" @click.stop="$emit('remove', file)"
                        title="Remove screenshot"
                        class="absolute right-1 top-1 rounded-full bg-white/90 p-1 text-red-600 opacity-0 transition-opacity group-hover:opacity-100 dark:bg-gray-800/90">
                    <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="3">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>

        <Teleport :to="teleportTarget">
            <div v-if="previewIndex !== null"
                 class="fixed inset-0 z-[200] flex flex-col bg-black/90"
                 @click.self="close">
                <!-- Toolbar: same controls and behaviour as the ticket image viewer -->
                <div class="flex items-center justify-between gap-3 bg-gradient-to-b from-black/70 to-transparent p-4">
                    <h3 class="ml-1 truncate text-xs font-medium text-white sm:text-sm">{{ current?.file_name }}</h3>

                    <div class="flex shrink-0 items-center space-x-2">
                        <button type="button" @click="handleZoom(-0.1)" title="Zoom out"
                                class="rounded-full p-1 text-white backdrop-blur-sm hover:bg-white/20 sm:p-2">
                            <svg class="h-5 w-5 sm:h-6 sm:w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4" />
                            </svg>
                        </button>
                        <span class="w-10 text-center font-mono text-[10px] text-white sm:w-12 sm:text-sm">
                            {{ Math.round(zoomLevel * 100) }}%
                        </span>
                        <button type="button" @click="handleZoom(0.1)" title="Zoom in"
                                class="rounded-full p-1 text-white backdrop-blur-sm hover:bg-white/20 sm:p-2">
                            <svg class="h-5 w-5 sm:h-6 sm:w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                            </svg>
                        </button>
                        <button type="button" @click="resetView" title="Reset zoom"
                                class="rounded-full p-1 text-white backdrop-blur-sm hover:bg-white/20 sm:p-2">
                            <svg class="h-5 w-5 sm:h-6 sm:w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                            </svg>
                        </button>

                        <div class="mx-1 h-6 w-px bg-white/30 sm:mx-2"></div>

                        <a :href="current?.url" target="_blank" rel="noopener" title="Open full size" @click.stop
                           class="rounded-full p-1 text-white backdrop-blur-sm hover:bg-white/20 sm:p-2">
                            <svg class="h-5 w-5 sm:h-6 sm:w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                            </svg>
                        </a>
                        <button type="button" @click="close" title="Close preview"
                                class="rounded-full p-1 text-white backdrop-blur-sm transition-colors hover:bg-red-500/80 sm:p-2">
                            <svg class="h-5 w-5 sm:h-6 sm:w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Drag to pan, wheel to zoom -->
                <div class="relative flex flex-grow cursor-move items-center justify-center overflow-hidden p-4"
                     @mousedown.prevent="isDragging = true"
                     @mouseup="isDragging = false"
                     @mouseleave="isDragging = false"
                     @mousemove="isDragging && (panOffset.x += $event.movementX, panOffset.y += $event.movementY)"
                     @wheel.prevent="handleWheel">

                    <button v-if="items.length > 1" type="button" @click.stop="step(-1)" title="Previous"
                            class="absolute left-2 top-1/2 z-20 -translate-y-1/2 rounded-full bg-black/20 p-2 text-white/70 backdrop-blur-sm transition-all hover:bg-black/40 hover:text-white sm:left-4 sm:p-3">
                        <svg class="h-6 w-6 sm:h-8 sm:w-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                        </svg>
                    </button>
                    <button v-if="items.length > 1" type="button" @click.stop="step(1)" title="Next"
                            class="absolute right-2 top-1/2 z-20 -translate-y-1/2 rounded-full bg-black/20 p-2 text-white/70 backdrop-blur-sm transition-all hover:bg-black/40 hover:text-white sm:right-4 sm:p-3">
                        <svg class="h-6 w-6 sm:h-8 sm:w-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                        </svg>
                    </button>

                    <img :src="current?.url" :alt="current?.file_name" draggable="false"
                         class="max-w-none origin-center transform shadow-2xl transition-transform duration-100 ease-linear"
                         :style="{ transform: `scale(${zoomLevel}) translate(${panOffset.x / zoomLevel}px, ${panOffset.y / zoomLevel}px)` }" />
                </div>

                <div v-if="items.length > 1" class="pb-4 text-center text-xs text-white/70">
                    {{ previewIndex + 1 }} / {{ items.length }}
                </div>
            </div>
        </Teleport>
    </div>

    <p v-else-if="emptyText" class="text-xs text-gray-400">{{ emptyText }}</p>
</template>

<script setup>
import { ref, reactive, computed, onMounted, onBeforeUnmount } from 'vue'

/**
 * Screenshot thumbnails with a zoomable preview. Shared by the findings
 * register, the finding and verdict modals, and the client portal, so evidence
 * behaves the same everywhere. Zoom/pan mirrors the ticket image viewer.
 */
const props = defineProps({
    items: { type: Array, default: () => [] },
    size: { type: String, default: 'md' },
    removable: { type: Boolean, default: false },
    emptyText: { type: String, default: '' },
})

defineEmits(['remove'])

const root = ref(null)
const previewIndex = ref(null)
const teleportTarget = ref('body')

const zoomLevel = ref(1)
const isDragging = ref(false)
const panOffset = reactive({ x: 0, y: 0 })

const sizeClass = computed(() => (props.size === 'sm' ? 'h-12 w-16' : 'h-20 w-28'))
const current = computed(() => (previewIndex.value === null ? null : props.items[previewIndex.value]))

const resetView = () => {
    zoomLevel.value = 1
    panOffset.x = 0
    panOffset.y = 0
}

const open = (index) => {
    // Modal.vue opens its <dialog> with showModal(), which puts it in the
    // browser's TOP LAYER — that paints above every normal element regardless of
    // z-index, so a viewer teleported to <body> sits invisibly behind the modal.
    // Rendering inside that dialog is the only way to appear above it.
    const dialog = root.value?.closest('dialog')
    teleportTarget.value = dialog || 'body'

    resetView()
    previewIndex.value = index
}

const close = () => {
    previewIndex.value = null
    isDragging.value = false
}

const step = (delta) => {
    if (previewIndex.value === null || !props.items.length) return
    previewIndex.value = (previewIndex.value + delta + props.items.length) % props.items.length
    resetView()
}

const handleZoom = (delta) => {
    const next = zoomLevel.value + delta
    if (next >= 0.1 && next <= 5) {
        zoomLevel.value = Math.round(next * 10) / 10
    }
}

const handleWheel = (event) => {
    handleZoom(event.deltaY > 0 ? -0.1 : 0.1)
}

/** A thumbnail that 404s is more confusing than an explicit placeholder. */
const onBroken = (event, file) => {
    event.target.classList.add('opacity-40')
    event.target.alt = `${file.file_name} (unavailable)`
}

const onKey = (event) => {
    if (previewIndex.value === null) return

    // Swallow the key so Esc closes the preview and not the modal behind it.
    if (['Escape', 'ArrowLeft', 'ArrowRight', '+', '=', '-'].includes(event.key)) {
        event.preventDefault()
        event.stopPropagation()
    }

    if (event.key === 'Escape') close()
    if (event.key === 'ArrowLeft') step(-1)
    if (event.key === 'ArrowRight') step(1)
    if (event.key === '+' || event.key === '=') handleZoom(0.1)
    if (event.key === '-') handleZoom(-0.1)
}

// Capture phase: the dialog's own Esc handler would otherwise fire first.
onMounted(() => window.addEventListener('keydown', onKey, true))
onBeforeUnmount(() => window.removeEventListener('keydown', onKey, true))
</script>
