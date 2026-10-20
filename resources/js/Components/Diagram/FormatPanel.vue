<script setup>
import { computed } from 'vue';
import DiagramColorPicker from './DiagramColorPicker.vue';
import { ARROW_KINDS, arrowHead } from './geometry';
import { shapeOf } from './shapes';
import {
    align,
    arrange,
    deleteSelection,
    distribute,
    duplicateSelection,
    groupSelection,
    restyle,
    rotateSelection,
    selectedElements,
    ungroupSelection,
    updateElement,
} from './actions';

// draw.io-style "Format" panel: page settings when nothing is selected, otherwise style,
// text and arrangement for the selected shapes and/or connectors.
const props = defineProps({
    doc: { type: Object, required: true },
    pageId: { type: String, required: true },
    selection: { type: Array, default: () => [] },
});

const emit = defineEmits(['update:selection']);

const FILLS = ['#ffffff', '#f5f5f5', '#dae8fc', '#d5e8d4', '#ffe6cc', '#fff2cc', '#f8cecc', '#e1d5e7', '#1f2937'];
const LINES = ['#1f2937', '#666666', '#6c8ebf', '#82b366', '#d79b00', '#d6b656', '#b85450', '#9673a6', '#2563eb', '#dc2626'];
const TEXTS = ['#1f2937', '#ffffff', '#2563eb', '#16a34a', '#d97706', '#dc2626', '#7c3aed', '#666666'];
const BACKGROUNDS = ['#ffffff', '#f8fafc', '#fefce8', '#f0f9ff', '#f0fdf4', '#1f2937'];
const PRESETS = [
    { fill: '#ffffff', stroke: '#1f2937' },
    { fill: '#dae8fc', stroke: '#6c8ebf' },
    { fill: '#d5e8d4', stroke: '#82b366' },
    { fill: '#ffe6cc', stroke: '#d79b00' },
    { fill: '#fff2cc', stroke: '#d6b656' },
    { fill: '#f8cecc', stroke: '#b85450' },
    { fill: '#e1d5e7', stroke: '#9673a6' },
    { fill: '#f5f5f5', stroke: '#666666' },
];

const page = computed(() => props.doc.pageById(props.pageId));
const items = computed(() => selectedElements(props.doc, props.pageId, props.selection));
const nodes = computed(() => items.value.filter((element) => element.type === 'node'));
const edges = computed(() => items.value.filter((element) => element.type === 'edge'));
const node = computed(() => nodes.value[0] || null);
const edge = computed(() => edges.value[0] || null);
const nodeIds = computed(() => nodes.value.map((n) => n.id));
const edgeIds = computed(() => edges.value.map((e) => e.id));

const setNodes = (patch) => restyle(props.doc, props.pageId, nodeIds.value, patch, 'node');
const setEdges = (patch) => restyle(props.doc, props.pageId, edgeIds.value, patch, 'edge');

const numberFrom = (event, min, max) => {
    const value = Number(event.target.value);
    if (!Number.isFinite(value)) return null;
    return Math.min(max, Math.max(min, Math.round(value * 10) / 10));
};

const setGeometry = (key, event) => {
    const value = numberFrom(event, key === 'w' || key === 'h' ? 10 : -100000, 100000);
    if (value !== null && node.value) updateElement(props.doc, props.pageId, node.value.id, { [key]: value });
};

const roundable = computed(() => nodes.value.some((n) => n.shape === 'rectangle'));
const grouped = computed(() => items.value.some((element) => element.groupId));

const setRotation = (event) => {
    const value = numberFrom(event, -360, 360);
    if (value !== null) rotateSelection(props.doc, props.pageId, nodeIds.value, { to: value });
};

const arrowPreview = (kind) => arrowHead(kind, { x: 26, y: 8 }, { x: 0, y: 8 }, 1.5);

const duplicate = () => emit('update:selection', duplicateSelection(props.doc, props.pageId, props.selection));
const remove = () => {
    deleteSelection(props.doc, props.pageId, props.selection);
    emit('update:selection', []);
};

const toggleClass = (on) => (on
    ? 'bg-blue-600 text-white border-blue-600'
    : 'border-gray-200 text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800');

const ALIGN_ICONS = {
    left: 'M4 4v16M8 7h10v4H8zM8 13h6v4H8z',
    center: 'M12 4v16M6 7h12v4H6zM9 13h6v4H9z',
    right: 'M20 4v16M6 7h10v4H6zM10 13h6v4h-6z',
    top: 'M4 4h16M7 8h4v10H7zM13 8h4v6h-4z',
    middle: 'M4 12h16M7 6h4v12H7zM13 9h4v6h-4z',
    bottom: 'M4 20h16M7 6h4v10H7zM13 10h4v6h-4z',
};
</script>

<template>
    <aside class="flex h-full w-64 shrink-0 flex-col border-l border-gray-200 bg-white text-sm dark:border-gray-800 dark:bg-gray-900" data-testid="diagram-format-panel">
        <div class="border-b border-gray-100 px-3 py-2 text-[11px] font-black uppercase tracking-wider text-gray-500 dark:border-gray-800 dark:text-gray-400">
            {{ items.length ? (nodes.length && edges.length ? `${items.length} selected` : nodes.length ? (nodes.length === 1 ? shapeOf(node).label : `${nodes.length} shapes`) : (edges.length === 1 ? 'Connector' : `${edges.length} connectors`)) : 'Diagram' }}
        </div>

        <div class="min-h-0 flex-1 space-y-4 overflow-y-auto p-3">
            <!-- Page -->
            <template v-if="!items.length && page">
                <label class="block">
                    <span class="mb-1 block text-[11px] font-semibold text-gray-500 dark:text-gray-400">Page name</span>
                    <input :value="page.name" maxlength="120" class="h-8 w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800" @change="doc.updatePage(page.id, { name: $event.target.value.trim() || page.name })">
                </label>
                <DiagramColorPicker label="Background" :colors="BACKGROUNDS" :model-value="page.background" @update:model-value="(value) => doc.updatePage(page.id, { background: value || '#ffffff' })" />
                <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                    <input type="checkbox" class="rounded border-gray-300 text-blue-600 dark:border-gray-600" :checked="page.grid !== false" @change="doc.updatePage(page.id, { grid: $event.target.checked })">
                    Show grid
                </label>
                <div class="rounded-lg bg-gray-50 p-3 text-xs leading-relaxed text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                    <p class="mb-1 font-bold text-gray-700 dark:text-gray-300">Shortcuts</p>
                    <p>Drag from a shape's blue points to connect. Click a blue arrow to add a connected copy. Drag the round handle above a shape to rotate it.</p>
                    <p class="mt-1">Double-click to edit text · Del delete · Ctrl+C / V / D copy, paste, duplicate · Ctrl+G group, Ctrl+Shift+U ungroup (click a group again to pick one shape) · Ctrl+R rotate 90° · Ctrl+Z / Y undo, redo · arrows nudge (Shift = 10px) · Alt while dragging = no snap · Space + drag or right-drag to pan · Ctrl + wheel to zoom · paste or drop a picture to add it</p>
                </div>
            </template>

            <!-- Shapes -->
            <template v-if="node">
                <section>
                    <div class="mb-1 text-[11px] font-semibold text-gray-500 dark:text-gray-400">Style</div>
                    <div class="grid grid-cols-8 gap-1">
                        <button
                            v-for="preset in PRESETS"
                            :key="preset.fill"
                            type="button"
                            :title="`${preset.fill} / ${preset.stroke}`"
                            :aria-label="`Style ${preset.fill}`"
                            class="h-6 rounded border-2"
                            :style="{ backgroundColor: preset.fill, borderColor: preset.stroke }"
                            @click="setNodes(preset)"
                        ></button>
                    </div>
                </section>
                <DiagramColorPicker label="Fill" :colors="FILLS" allow-none :model-value="node.fill" @update:model-value="(fill) => setNodes({ fill })" />
                <DiagramColorPicker label="Line" :colors="LINES" allow-none :model-value="node.stroke" @update:model-value="(stroke) => setNodes({ stroke })" />
                <div class="grid grid-cols-2 gap-2">
                    <label class="block">
                        <span class="mb-1 block text-[11px] font-semibold text-gray-500 dark:text-gray-400">Line width</span>
                        <input type="number" min="0" max="12" step="0.5" :value="node.strokeWidth" class="h-8 w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800" @change="(e) => { const v = numberFrom(e, 0, 12); if (v !== null) setNodes({ strokeWidth: v }); }">
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-[11px] font-semibold text-gray-500 dark:text-gray-400">Opacity {{ Math.round((node.opacity ?? 1) * 100) }}%</span>
                        <input type="range" min="10" max="100" step="5" :value="Math.round((node.opacity ?? 1) * 100)" class="mt-2 w-full" @change="setNodes({ opacity: Number($event.target.value) / 100 })">
                    </label>
                </div>
                <div class="flex flex-wrap gap-1">
                    <button type="button" class="rounded-lg border px-2 py-1 text-xs font-semibold" :class="toggleClass(node.dashed)" @click="setNodes({ dashed: !node.dashed })">Dashed</button>
                    <button v-if="roundable" type="button" class="rounded-lg border px-2 py-1 text-xs font-semibold" :class="toggleClass(node.rounded)" @click="setNodes({ rounded: !node.rounded })">Rounded</button>
                </div>

                <section class="space-y-2 border-t border-gray-100 pt-3 dark:border-gray-800">
                    <div class="text-[11px] font-black uppercase tracking-wider text-gray-500 dark:text-gray-400">Text</div>
                    <div class="flex items-center gap-1">
                        <input type="number" min="6" max="96" :value="node.fontSize" title="Font size" aria-label="Font size" class="h-8 w-16 rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800" @change="(e) => { const v = numberFrom(e, 6, 96); if (v !== null) setNodes({ fontSize: v }); }">
                        <button type="button" title="Bold" aria-label="Bold" class="h-8 w-8 rounded-lg border text-sm font-black" :class="toggleClass(node.bold)" @click="setNodes({ bold: !node.bold })">B</button>
                        <button type="button" title="Italic" aria-label="Italic" class="h-8 w-8 rounded-lg border text-sm italic" :class="toggleClass(node.italic)" @click="setNodes({ italic: !node.italic })">I</button>
                    </div>
                    <div class="flex gap-1">
                        <button v-for="value in ['left', 'center', 'right']" :key="value" type="button" :title="`Align ${value}`" :aria-label="`Align text ${value}`" class="h-8 flex-1 rounded-lg border text-xs font-semibold capitalize" :class="toggleClass(node.align === value)" @click="setNodes({ align: value })">{{ value }}</button>
                    </div>
                    <div class="flex gap-1">
                        <button v-for="value in ['top', 'middle', 'bottom']" :key="value" type="button" :title="`Text ${value}`" :aria-label="`Text ${value}`" class="h-8 flex-1 rounded-lg border text-xs font-semibold capitalize" :class="toggleClass(node.valign === value)" @click="setNodes({ valign: value })">{{ value }}</button>
                    </div>
                    <DiagramColorPicker label="Font colour" :colors="TEXTS" :model-value="node.fontColor" @update:model-value="(fontColor) => setNodes({ fontColor: fontColor || '#1f2937' })" />
                </section>

                <section class="space-y-2 border-t border-gray-100 pt-3 dark:border-gray-800">
                    <div class="text-[11px] font-black uppercase tracking-wider text-gray-500 dark:text-gray-400">Arrange</div>
                    <div v-if="nodes.length === 1" class="grid grid-cols-2 gap-2">
                        <label v-for="key in ['x', 'y', 'w', 'h']" :key="key" class="flex items-center gap-1">
                            <span class="w-4 text-xs font-bold uppercase text-gray-400">{{ key === 'w' ? 'W' : key === 'h' ? 'H' : key }}</span>
                            <input type="number" :value="Math.round(node[key])" class="h-8 w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800" :data-field="key" @change="setGeometry(key, $event)">
                        </label>
                    </div>
                    <div class="flex items-center gap-1">
                        <label class="flex flex-1 items-center gap-1" title="Rotation (degrees). Drag the round handle above a shape, or Ctrl+R for 90°.">
                            <span class="w-4 text-xs font-bold text-gray-400">↻</span>
                            <input type="number" min="-360" max="360" step="1" :value="Math.round(node.rotation || 0)" class="h-8 w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800" data-field="rotation" aria-label="Rotation in degrees" @change="setRotation">
                            <span class="text-xs text-gray-400">°</span>
                        </label>
                        <button type="button" title="Rotate 90° left" aria-label="Rotate 90 degrees left" class="h-8 w-9 rounded-lg border border-gray-200 text-sm text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800" @click="rotateSelection(doc, pageId, nodeIds, { by: -90 })">⟲</button>
                        <button type="button" title="Rotate 90° right (Ctrl+R)" aria-label="Rotate 90 degrees right" class="h-8 w-9 rounded-lg border border-gray-200 text-sm text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800" @click="rotateSelection(doc, pageId, nodeIds, { by: 90 })">⟳</button>
                    </div>
                    <div v-if="nodes.length > 1" class="space-y-1">
                        <div class="grid grid-cols-6 gap-1">
                            <button v-for="(path, how) in ALIGN_ICONS" :key="how" type="button" :title="`Align ${how}`" :aria-label="`Align ${how}`" class="flex h-8 items-center justify-center rounded-lg border border-gray-200 text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800" @click="align(doc, pageId, nodeIds, how)">
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"><path :d="path" /></svg>
                            </button>
                        </div>
                        <div v-if="nodes.length >= 3" class="grid grid-cols-2 gap-1">
                            <button type="button" class="h-8 rounded-lg border border-gray-200 text-xs font-semibold text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800" @click="distribute(doc, pageId, nodeIds, 'horizontal')">Distribute ↔</button>
                            <button type="button" class="h-8 rounded-lg border border-gray-200 text-xs font-semibold text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800" @click="distribute(doc, pageId, nodeIds, 'vertical')">Distribute ↕</button>
                        </div>
                    </div>
                </section>
            </template>

            <!-- Connectors -->
            <template v-if="edge">
                <section class="space-y-3" :class="node ? 'border-t border-gray-100 pt-3 dark:border-gray-800' : ''">
                    <div v-if="node" class="text-[11px] font-black uppercase tracking-wider text-gray-500 dark:text-gray-400">Connector</div>
                    <DiagramColorPicker label="Line" :colors="LINES" :model-value="edge.stroke" @update:model-value="(stroke) => setEdges({ stroke: stroke || '#1f2937' })" />
                    <div class="flex items-center gap-2">
                        <label class="flex items-center gap-1 text-xs text-gray-500">
                            Width
                            <input type="number" min="0.5" max="12" step="0.5" :value="edge.strokeWidth" class="h-8 w-16 rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800" @change="(e) => { const v = numberFrom(e, 0.5, 12); if (v !== null) setEdges({ strokeWidth: v }); }">
                        </label>
                        <button type="button" class="rounded-lg border px-2 py-1 text-xs font-semibold" :class="toggleClass(edge.dashed)" @click="setEdges({ dashed: !edge.dashed })">Dashed</button>
                    </div>
                    <div>
                        <div class="mb-1 text-[11px] font-semibold text-gray-500 dark:text-gray-400">Routing</div>
                        <div class="flex gap-1">
                            <button v-for="value in ['orthogonal', 'straight', 'curved']" :key="value" type="button" :aria-label="`Routing ${value}`" class="h-8 flex-1 rounded-lg border text-xs font-semibold capitalize" :class="toggleClass(edge.routing === value)" @click="setEdges({ routing: value })">{{ value === 'orthogonal' ? 'Elbow' : value }}</button>
                        </div>
                    </div>
                    <div v-for="end in ['startArrow', 'endArrow']" :key="end">
                        <div class="mb-1 text-[11px] font-semibold text-gray-500 dark:text-gray-400">{{ end === 'startArrow' ? 'Start' : 'End' }}</div>
                        <div class="grid grid-cols-6 gap-1">
                            <button
                                v-for="kind in ARROW_KINDS"
                                :key="kind"
                                type="button"
                                :title="kind"
                                :aria-label="`${end === 'startArrow' ? 'Start' : 'End'} ${kind}`"
                                class="flex h-8 items-center justify-center rounded-lg border"
                                :class="toggleClass(edge[end] === kind)"
                                @click="setEdges({ [end]: kind })"
                            >
                                <svg viewBox="0 0 30 16" class="h-4 w-7" :class="end === 'startArrow' ? 'rotate-180' : ''">
                                    <line x1="0" y1="8" x2="24" y2="8" stroke="currentColor" stroke-width="1.5" />
                                    <path v-if="arrowPreview(kind)" :d="arrowPreview(kind).d" :fill="arrowPreview(kind).filled ? 'currentColor' : 'none'" stroke="currentColor" stroke-width="1.5" />
                                </svg>
                            </button>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <label class="flex items-center gap-1 text-xs text-gray-500">
                            Label size
                            <input type="number" min="6" max="48" :value="edge.fontSize" class="h-8 w-16 rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800" @change="(e) => { const v = numberFrom(e, 6, 48); if (v !== null) setEdges({ fontSize: v }); }">
                        </label>
                        <button v-if="edges.some((e) => e.points?.length)" type="button" class="rounded-lg border border-gray-200 px-2 py-1 text-xs font-semibold text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300" @click="setEdges({ points: [] })">Clear bends</button>
                    </div>
                </section>
            </template>

            <!-- Common actions -->
            <section v-if="items.length" class="grid grid-cols-2 gap-1 border-t border-gray-100 pt-3 dark:border-gray-800">
                <button v-if="items.length > 1" type="button" class="h-8 rounded-lg border border-gray-200 text-xs font-semibold text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800" title="Group (Ctrl+G)" data-testid="diagram-format-group" @click="groupSelection(doc, pageId, selection)">Group</button>
                <button v-if="grouped" type="button" class="h-8 rounded-lg border border-gray-200 text-xs font-semibold text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800" title="Ungroup (Ctrl+Shift+U)" data-testid="diagram-format-ungroup" @click="ungroupSelection(doc, pageId, selection)">Ungroup</button>
                <button type="button" class="h-8 rounded-lg border border-gray-200 text-xs font-semibold text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800" @click="arrange(doc, pageId, selection, 'front')">To front</button>
                <button type="button" class="h-8 rounded-lg border border-gray-200 text-xs font-semibold text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800" @click="arrange(doc, pageId, selection, 'back')">To back</button>
                <button type="button" class="h-8 rounded-lg border border-gray-200 text-xs font-semibold text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800" @click="duplicate">Duplicate</button>
                <button type="button" class="h-8 rounded-lg border border-red-200 text-xs font-semibold text-red-600 hover:bg-red-50 dark:border-red-900 dark:hover:bg-red-900/30" data-testid="diagram-format-delete" @click="remove">Delete</button>
            </section>
        </div>
    </aside>
</template>
