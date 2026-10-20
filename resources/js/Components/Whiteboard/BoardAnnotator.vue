<script setup>
import WhiteboardCanvas from './WhiteboardCanvas.vue';
import { createWhiteboardDocument } from './whiteboard';

// Presenter ink drawn over the live board. Nothing is saved: it is screen annotation for
// a meeting, cleared when presenting ends. "Interact" lets clicks through to the board.
const emit = defineEmits(['close']);

const doc = createWhiteboardDocument(null);
const pageId = doc.scene.pages[0].id;
</script>

<template>
    <div class="pointer-events-none absolute inset-0 z-30" data-testid="board-annotator">
        <WhiteboardCanvas
            :doc="doc"
            :page-id="pageId"
            overlay
            :tools="['pointer', 'pen', 'highlighter', 'laser', 'arrow', 'rect', 'ellipse', 'eraser']"
            initial-tool="pen"
            :show-zoom="false"
            toolbar-placement="bottom"
        >
            <template #toolbar-end>
                <span class="mx-1 h-6 w-px shrink-0 bg-gray-200 dark:bg-gray-700"></span>
                <button
                    type="button"
                    class="inline-flex h-9 shrink-0 items-center rounded-lg px-3 text-sm font-bold text-gray-700 hover:bg-gray-100 disabled:opacity-40 dark:text-gray-200 dark:hover:bg-gray-700"
                    :disabled="!doc.scene.pages[0].elements.length"
                    @click="doc.clearPage(pageId)"
                >
                    Clear
                </button>
                <button
                    type="button"
                    class="inline-flex h-9 shrink-0 items-center rounded-lg bg-gray-900 px-3 text-sm font-bold text-white hover:bg-gray-700 dark:bg-gray-100 dark:text-gray-900 dark:hover:bg-white"
                    data-testid="board-annotator-exit"
                    @click="emit('close')"
                >
                    Exit
                </button>
            </template>
        </WhiteboardCanvas>
    </div>
</template>
