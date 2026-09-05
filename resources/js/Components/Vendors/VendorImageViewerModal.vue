<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition duration-150 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="show"
                class="fixed inset-0 z-[70] flex flex-col bg-black/90 backdrop-blur-md select-none"
                @keydown.esc="close"
                tabindex="-1"
                ref="modalContainer"
            >
                <!-- Top Toolbar -->
                <div class="relative z-10 flex flex-wrap items-center justify-between gap-3 border-b border-white/10 bg-black/40 px-4 py-3 sm:px-6 backdrop-blur-sm">
                    <div class="flex items-center gap-3 min-w-0">
                        <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-white/10 text-white">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                            </svg>
                        </div>
                        <div class="min-w-0">
                            <h4 class="text-sm font-semibold text-white truncate max-w-xs sm:max-w-md">
                                {{ title || fileName || 'Image Preview' }}
                            </h4>
                            <p v-if="fileName" class="text-xs text-gray-400 truncate max-w-xs">
                                {{ fileName }}
                            </p>
                        </div>
                    </div>

                    <!-- Zoom Controls -->
                    <div class="flex items-center gap-1.5 bg-white/10 rounded-lg p-1 text-white backdrop-blur-sm border border-white/10">
                        <button
                            type="button"
                            @click="zoomOut"
                            class="p-1.5 rounded hover:bg-white/20 active:scale-95 transition-all text-white/90 hover:text-white"
                            title="Zoom Out"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4" />
                            </svg>
                        </button>

                        <button
                            type="button"
                            @click="resetZoom"
                            class="px-2.5 py-1 text-xs font-mono font-semibold rounded hover:bg-white/20 transition-all text-white/90 hover:text-white"
                            title="Click to reset (100%)"
                        >
                            {{ Math.round(zoom * 100) }}%
                        </button>

                        <button
                            type="button"
                            @click="zoomIn"
                            class="p-1.5 rounded hover:bg-white/20 active:scale-95 transition-all text-white/90 hover:text-white"
                            title="Zoom In"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                            </svg>
                        </button>

                        <div class="h-4 w-px bg-white/20 mx-1"></div>

                        <button
                            type="button"
                            @click="resetZoom"
                            class="px-2 py-1 text-xs font-semibold rounded hover:bg-white/20 transition-all text-white/80 hover:text-white flex items-center gap-1"
                            title="Reset View"
                        >
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                            </svg>
                            <span class="hidden sm:inline">Reset</span>
                        </button>
                    </div>

                    <!-- Right Actions -->
                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            @click="downloadImage"
                            class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-blue-600 hover:bg-blue-500 text-white text-xs font-semibold shadow-sm transition-all active:scale-95"
                            title="Download Image"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                            </svg>
                            <span class="hidden sm:inline">Download</span>
                        </button>

                        <button
                            type="button"
                            @click="close"
                            class="p-2 rounded-lg text-gray-400 hover:text-white hover:bg-white/10 transition-colors"
                            title="Close (ESC)"
                        >
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Canvas / Image Stage -->
                <div
                    ref="stageRef"
                    class="relative flex-1 overflow-hidden flex items-center justify-center p-4 touch-none select-none"
                    :class="isDragging ? 'cursor-grabbing' : 'cursor-grab'"
                    @mousedown="startDrag"
                    @mousemove="onDrag"
                    @mouseup="endDrag"
                    @mouseleave="endDrag"
                    @wheel.prevent="onWheel"
                    @touchstart="onTouchStart"
                    @touchmove="onTouchMove"
                    @touchend="onTouchEnd"
                >
                    <!-- Background Grid Pattern for Transparency Check -->
                    <div class="absolute inset-0 opacity-15 pointer-events-none bg-[radial-gradient(#ffffff_1px,transparent_1px)] [background-size:16px_16px]"></div>

                    <!-- Image with Transform -->
                    <div
                        class="relative inline-block transition-transform duration-75 ease-out"
                        :style="{
                            transform: `translate(${pan.x}px, ${pan.y}px) scale(${zoom})`,
                            transformOrigin: 'center center',
                        }"
                    >
                        <img
                            :src="imageUrl"
                            :alt="title || fileName || 'Document preview'"
                            class="max-h-[82vh] max-w-[90vw] rounded-lg shadow-2xl bg-white object-contain dark:bg-gray-800"
                            draggable="false"
                            @dblclick="toggleZoom"
                        />
                    </div>
                </div>

                <!-- Bottom Helper Bar -->
                <div class="relative z-10 flex items-center justify-between border-t border-white/10 bg-black/40 px-4 py-2 text-[11px] text-gray-400">
                    <div class="flex items-center gap-4">
                        <span class="hidden sm:inline">💡 Drag image to pan</span>
                        <span>• Scroll to zoom</span>
                        <span class="hidden sm:inline">• Double-click to toggle 2x</span>
                    </div>
                    <div>
                        <span>Press <kbd class="rounded bg-white/10 px-1.5 py-0.5 font-mono text-[10px] text-gray-300">ESC</kbd> to close</span>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<script setup>
import { ref, watch, nextTick, onMounted, onUnmounted } from 'vue'

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    title: {
        type: String,
        default: '',
    },
    fileName: {
        type: String,
        default: '',
    },
    imageUrl: {
        type: String,
        required: true,
    },
})

const emit = defineEmits(['close'])

const modalContainer = ref(null)
const stageRef = ref(null)

const zoom = ref(1)
const pan = ref({ x: 0, y: 0 })
const isDragging = ref(false)
const dragStart = { x: 0, y: 0 }
const panStart = { x: 0, y: 0 }

const zoomIn = () => {
    zoom.value = Math.min(5, Math.round((zoom.value + 0.25) * 100) / 100)
}

const zoomOut = () => {
    zoom.value = Math.max(0.25, Math.round((zoom.value - 0.25) * 100) / 100)
}

const resetZoom = () => {
    zoom.value = 1
    pan.value = { x: 0, y: 0 }
}

const toggleZoom = () => {
    if (zoom.value > 1.1) {
        resetZoom()
    } else {
        zoom.value = 2
    }
}

const onWheel = (e) => {
    const delta = e.deltaY > 0 ? -0.15 : 0.15
    const newZoom = Math.max(0.25, Math.min(5, zoom.value + delta))
    zoom.value = Math.round(newZoom * 100) / 100
}

const startDrag = (e) => {
    if (e.button !== 0) return // Left click only
    isDragging.value = true
    dragStart.x = e.clientX
    dragStart.y = e.clientY
    panStart.x = pan.value.x
    panStart.y = pan.value.y
}

const onDrag = (e) => {
    if (!isDragging.value) return
    const dx = e.clientX - dragStart.x
    const dy = e.clientY - dragStart.y
    pan.value = {
        x: panStart.x + dx,
        y: panStart.y + dy,
    }
}

const endDrag = () => {
    isDragging.value = false
}

// Mobile touch handlers
let touchStartDist = 0
const onTouchStart = (e) => {
    if (e.touches.length === 1) {
        isDragging.value = true
        dragStart.x = e.touches[0].clientX
        dragStart.y = e.touches[0].clientY
        panStart.x = pan.value.x
        panStart.y = pan.value.y
    } else if (e.touches.length === 2) {
        touchStartDist = Math.hypot(
            e.touches[0].clientX - e.touches[1].clientX,
            e.touches[0].clientY - e.touches[1].clientY
        )
    }
}

const onTouchMove = (e) => {
    if (e.touches.length === 1 && isDragging.value) {
        const dx = e.touches[0].clientX - dragStart.x
        const dy = e.touches[0].clientY - dragStart.y
        pan.value = {
            x: panStart.x + dx,
            y: panStart.y + dy,
        }
    } else if (e.touches.length === 2 && touchStartDist > 0) {
        const currentDist = Math.hypot(
            e.touches[0].clientX - e.touches[1].clientX,
            e.touches[0].clientY - e.touches[1].clientY
        )
        const scaleChange = (currentDist - touchStartDist) * 0.005
        zoom.value = Math.max(0.25, Math.min(5, Math.round((zoom.value + scaleChange) * 100) / 100))
        touchStartDist = currentDist
    }
}

const onTouchEnd = () => {
    isDragging.value = false
    touchStartDist = 0
}

const close = () => {
    emit('close')
}

const downloadImage = () => {
    const link = document.createElement('a')
    link.href = props.imageUrl
    link.download = props.fileName || 'vendor_document_image.png'
    document.body.appendChild(link)
    link.click()
    document.body.removeChild(link)
}

const onKeyDown = (e) => {
    if (!props.show) return
    if (e.key === 'Escape') {
        close()
    } else if (e.key === '+' || e.key === '=') {
        zoomIn()
    } else if (e.key === '-') {
        zoomOut()
    } else if (e.key === '0') {
        resetZoom()
    }
}

watch(
    () => props.show,
    (val) => {
        if (val) {
            resetZoom()
            nextTick(() => {
                modalContainer.value?.focus()
            })
        }
    }
)

onMounted(() => {
    window.addEventListener('keydown', onKeyDown)
})

onUnmounted(() => {
    window.removeEventListener('keydown', onKeyDown)
})
</script>
