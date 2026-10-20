<script setup>
import { computed, ref } from 'vue';
import { SHAPES, SHAPE_DRAG_TYPE, SHAPE_GROUPS } from './shapes';

// draw.io-style shape sidebar: drag a shape onto the canvas, or click it to drop it in the
// view (with one shape selected, the click places the new one beside it and connects them).
const emit = defineEmits(['insert', 'insert-image']);

const search = ref('');
const collapsed = ref({});

const groups = computed(() => {
    const q = search.value.trim().toLowerCase();
    return SHAPE_GROUPS.map((group) => ({
        ...group,
        shapes: Object.entries(SHAPES)
            .filter(([, shape]) => shape.group === group.id && shape.library !== false)
            .filter(([key, shape]) => !q || shape.label.toLowerCase().includes(q) || key.includes(q))
            .map(([key, shape]) => {
                const scale = Math.min(36 / shape.w, 26 / shape.h);
                const w = shape.w * scale;
                const h = shape.h * scale;
                return {
                    key,
                    label: shape.label,
                    w,
                    h,
                    d: shape.path(w, h, {}),
                    extra: shape.extra?.(w, h) || null,
                    open: key === 'actor',
                    text: key === 'text',
                    dashed: !!shape.style?.dashed,
                    fill: shape.style?.fill === undefined ? '#ffffff' : (shape.style.fill || 'none'),
                };
            }),
    })).filter((group) => group.shapes.length);
});

const onDragStart = (event, key) => {
    event.dataTransfer.setData(SHAPE_DRAG_TYPE, key);
    event.dataTransfer.effectAllowed = 'copy';
};
</script>

<template>
    <aside class="flex h-full w-56 shrink-0 flex-col border-r border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900" data-testid="diagram-library">
        <div class="border-b border-gray-100 p-2 dark:border-gray-800">
            <input
                v-model="search"
                type="search"
                placeholder="Search shapes…"
                class="h-8 w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800"
            >
            <button
                type="button"
                class="mt-2 flex w-full items-center justify-center gap-1.5 rounded-lg border border-dashed border-gray-300 py-1.5 text-xs font-semibold text-gray-600 hover:border-blue-300 hover:bg-blue-50 hover:text-blue-700 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-blue-900/30"
                title="Add a picture (you can also paste one with Ctrl+V or drop a file on the canvas)"
                data-testid="diagram-insert-image"
                @click="emit('insert-image')"
            >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M4 5h16v14H4zM4 16l5-5 4 4 3-3 4 4M15 9.5a1.5 1.5 0 1 0 0-.01" /></svg>
                Insert picture…
            </button>
        </div>
        <div class="min-h-0 flex-1 overflow-y-auto p-2">
            <p v-if="!groups.length" class="p-3 text-center text-xs text-gray-400">No shapes match.</p>
            <section v-for="group in groups" :key="group.id" class="mb-2">
                <button
                    type="button"
                    class="flex w-full items-center justify-between rounded px-1 py-1 text-[11px] font-black uppercase tracking-wider text-gray-500 hover:bg-gray-50 dark:text-gray-400 dark:hover:bg-gray-800"
                    @click="collapsed[group.id] = !collapsed[group.id]"
                >
                    {{ group.label }}
                    <span class="text-gray-400">{{ collapsed[group.id] ? '+' : '−' }}</span>
                </button>
                <div v-show="!collapsed[group.id]" class="mt-1 grid grid-cols-4 gap-1">
                    <button
                        v-for="shape in group.shapes"
                        :key="shape.key"
                        type="button"
                        draggable="true"
                        :title="shape.label"
                        :aria-label="`Add ${shape.label}`"
                        :data-shape="shape.key"
                        class="flex h-11 items-center justify-center rounded-md border border-transparent text-gray-700 hover:border-blue-200 hover:bg-blue-50 dark:text-gray-300 dark:hover:border-blue-800 dark:hover:bg-blue-900/30"
                        @dragstart="onDragStart($event, shape.key)"
                        @click="emit('insert', shape.key)"
                    >
                        <span v-if="shape.text" class="text-xs font-bold">Text</span>
                        <svg v-else :viewBox="`-2 -2 ${shape.w + 4} ${shape.h + 4}`" :width="shape.w + 4" :height="shape.h + 4" class="overflow-visible">
                            <path :d="shape.d" :fill="shape.open ? 'none' : shape.fill" stroke="currentColor" stroke-width="1.2" :stroke-dasharray="shape.dashed ? '3 2' : null" stroke-linejoin="round" />
                            <path v-if="shape.extra" :d="shape.extra" fill="none" stroke="currentColor" stroke-width="1.2" />
                        </svg>
                    </button>
                </div>
            </section>
        </div>
        <p class="border-t border-gray-100 p-2 text-[11px] leading-snug text-gray-400 dark:border-gray-800">
            Tip: select a shape, then click another here to add it next to it, already connected.
        </p>
    </aside>
</template>
