<script setup>
import { computed } from 'vue';
import { FONT_FAMILY, wrapText } from '@/lib/svgText';
import { edgeGeometry, isContainer, nodesIndex } from './geometry';
import { nodeTextBox, shapeOf } from './shapes';

// Pure renderer: plain SVG with inline attributes, so the same markup serialises straight
// into PNG/SVG exports and list previews. Layering: containers, then shapes, then connectors
// (connectors stay visible and clickable above the shapes they join).
const props = defineProps({
    elements: { type: Array, default: () => [] },
    // Map(id -> element) drawn in place of the stored element (live move / resize / reconnect).
    overrides: { type: Map, default: null },
    // Nodes to route connectors against when rendering elements outside the page (draft edge).
    lookup: { type: Object, default: null },
    // Element ids whose label is hidden (it is being edited in a textarea).
    hideText: { type: Set, default: null },
    labelBackground: { type: String, default: '#ffffff' },
});

const resolved = computed(() => props.elements.map((element) => props.overrides?.get(element.id) || element));
const nodes = computed(() => props.lookup || nodesIndex(resolved.value));

const textLayout = (node) => {
    if (!node.text || props.hideText?.has(node.id)) return null;
    const box = nodeTextBox(node);
    const size = node.fontSize || 14;
    const weight = node.bold ? 700 : 400;
    const style = node.italic ? 'italic' : 'normal';
    const anchor = { left: 'start', right: 'end' }[node.align] || 'middle';

    if (box.vertical) {
        const cx = box.x + box.w / 2;
        const cy = box.y + box.h / 2;
        return { vertical: true, cx, cy, lines: [String(node.text).replace(/\n/g, ' ')], size, weight, style, anchor: 'middle', x: cx, ys: [cy + size * 0.35] };
    }

    const lines = wrapText(node.text, Math.max(box.w - 8, size), size, weight, style);
    const lineHeight = size * 1.25;
    const total = lines.length * lineHeight;
    const top = node.valign === 'top' ? box.y + 4 : node.valign === 'bottom' ? box.y + box.h - total - 4 : box.y + (box.h - total) / 2;
    const x = node.align === 'left' ? box.x + 4 : node.align === 'right' ? box.x + box.w - 4 : box.x + box.w / 2;
    return { lines, size, weight, style, anchor, x, ys: lines.map((_, i) => top + size * 0.93 + i * lineHeight) };
};

const nodeItems = computed(() => resolved.value
    .filter((element) => element.type === 'node')
    .sort((a, b) => Number(isContainer(b)) - Number(isContainer(a)))
    .map((node) => {
        const shape = shapeOf(node);
        return {
            node,
            d: shape.path(node.w, node.h, node),
            extra: shape.extra?.(node.w, node.h) || null,
            image: !!shape.image,
            dash: node.dashed ? `${Math.max(4, node.strokeWidth * 3)} ${Math.max(3, node.strokeWidth * 2)}` : null,
            text: textLayout(node),
            transform: `translate(${node.x} ${node.y})${node.rotation ? ` rotate(${node.rotation} ${node.w / 2} ${node.h / 2})` : ''}`,
        };
    }));

const edgeItems = computed(() => resolved.value
    .filter((element) => element.type === 'edge')
    .map((edge) => {
        const geometry = edgeGeometry(edge, nodes.value);
        return {
            edge,
            geometry,
            label: props.hideText?.has(edge.id) ? null : geometry.label,
            dash: edge.dashed ? `${Math.max(4, edge.strokeWidth * 3)} ${Math.max(3, edge.strokeWidth * 2)}` : null,
        };
    }));
</script>

<template>
    <g
        v-for="{ node, d, extra, image, dash, text, transform } in nodeItems"
        :key="node.id"
        :transform="transform"
        :opacity="node.opacity ?? 1"
        :data-id="node.id"
        data-kind="node"
        :data-shape="node.shape"
    >
        <template v-if="image">
            <image v-if="node.src" :href="node.src" x="0" y="0" :width="node.w" :height="node.h" preserveAspectRatio="xMidYMid meet" />
            <g v-else>
                <rect x="0" y="0" :width="node.w" :height="node.h" fill="#f1f5f9" stroke="#cbd5e1" stroke-dasharray="4 3" />
                <text :x="node.w / 2" :y="node.h / 2 + 4" text-anchor="middle" font-size="12" fill="#94a3b8" :font-family="FONT_FAMILY">Image</text>
            </g>
        </template>
        <path :d="d" :fill="image ? 'none' : (node.fill || 'none')" :stroke="node.stroke || 'none'" :stroke-width="node.strokeWidth" :stroke-dasharray="dash" stroke-linejoin="round" />
        <path v-if="extra" :d="extra" fill="none" :stroke="node.stroke || 'none'" :stroke-width="node.strokeWidth" />
        <text
            v-if="text"
            :transform="text.vertical ? `rotate(-90 ${text.cx - node.x} ${text.cy - node.y})` : null"
            :fill="node.fontColor"
            :font-size="text.size"
            :font-weight="text.weight"
            :font-style="text.style"
            :font-family="FONT_FAMILY"
            :text-anchor="text.anchor"
            style="white-space: pre"
        >
            <tspan v-for="(line, index) in text.lines" :key="index" :x="text.x - node.x" :y="text.ys[index] - node.y">{{ line }}</tspan>
        </text>
    </g>

    <g v-for="{ edge, geometry, label, dash } in edgeItems" :key="edge.id" :data-id="edge.id" data-kind="edge">
        <path :d="geometry.d" fill="none" :stroke="edge.stroke" :stroke-width="edge.strokeWidth" :stroke-dasharray="dash" stroke-linejoin="round" stroke-linecap="round" />
        <path
            v-for="(head, index) in geometry.heads"
            :key="index"
            :d="head.d"
            :fill="head.filled ? edge.stroke : 'none'"
            :stroke="edge.stroke"
            :stroke-width="edge.strokeWidth"
            stroke-linejoin="round"
            stroke-linecap="round"
        />
        <g v-if="label">
            <rect :x="label.x" :y="label.y" :width="label.w" :height="label.h" :fill="labelBackground" rx="3" />
            <text :font-size="label.size" :fill="edge.fontColor" :font-family="FONT_FAMILY" text-anchor="middle" style="white-space: pre">
                <tspan
                    v-for="(line, index) in label.lines"
                    :key="index"
                    :x="label.x + label.w / 2"
                    :y="label.y + 2 + label.size * 0.95 + index * label.size * 1.25"
                >{{ line }}</tspan>
            </text>
        </g>
    </g>
</template>
