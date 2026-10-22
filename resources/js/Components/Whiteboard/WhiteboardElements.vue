<script setup>
import { computed } from 'vue';
import {
    FONT_FAMILY,
    STICKY_FONT,
    STICKY_LINE,
    STICKY_PADDING,
    arrowHeadPath,
    cardLayout,
    penPath,
    stickyLayout,
    textLines,
} from './whiteboard';
import { iconOf } from '@/Components/SharedCanvas/canvasIcons';

// Pure renderer: everything is plain SVG with inline attributes so the same markup
// can be serialised straight into a PNG export.
const props = defineProps({
    elements: { type: Array, default: () => [] },
    cardsById: { type: Object, default: () => ({}) },
    // Map(id -> element) drawn in place of the stored element (live move / resize preview).
    overrides: { type: Map, default: null },
    // Set of ids drawn faded (eraser preview) or not at all (being edited in a textarea).
    faded: { type: Set, default: null },
    hidden: { type: Set, default: null },
});

const items = computed(() => props.elements
    .filter((element) => !props.hidden?.has(element.id))
    .map((element) => {
        const el = props.overrides?.get(element.id) || element;
        const item = { el, opacity: props.faded?.has(element.id) ? 0.2 : 1 };

        if (el.type === 'rect' || el.type === 'ellipse') {
            item.box = {
                x: Math.min(el.x1, el.x2),
                y: Math.min(el.y1, el.y2),
                w: Math.abs(el.x2 - el.x1),
                h: Math.abs(el.y2 - el.y1),
            };
        } else if (el.type === 'text') {
            item.lines = textLines(el);
        } else if (el.type === 'sticky') {
            item.layout = stickyLayout(el);
        } else if (el.type === 'card') {
            item.layout = cardLayout(el, props.cardsById[el.cardId]);
        } else if (el.type === 'icon') {
            item.icon = iconOf(el.icon);
        }

        return item;
    }));
</script>

<template>
    <g v-for="{ el, opacity, box, lines, layout, icon } in items" :key="el.id" :opacity="opacity" :data-type="el.type" :data-icon="el.icon || null">
        <path
            v-if="el.type === 'pen'"
            :d="penPath(el.points)"
            fill="none"
            :stroke="el.color"
            :stroke-width="el.size"
            :stroke-opacity="el.opacity ?? 1"
            stroke-linecap="round"
            stroke-linejoin="round"
        />
        <line
            v-else-if="el.type === 'line'"
            :x1="el.x1" :y1="el.y1" :x2="el.x2" :y2="el.y2"
            :stroke="el.color" :stroke-width="el.size" stroke-linecap="round"
        />
        <g v-else-if="el.type === 'arrow'" :stroke="el.color" :stroke-width="el.size" stroke-linecap="round" stroke-linejoin="round" fill="none">
            <line :x1="el.x1" :y1="el.y1" :x2="el.x2" :y2="el.y2" />
            <path :d="arrowHeadPath(el)" />
        </g>
        <rect
            v-else-if="el.type === 'rect'"
            :x="box.x" :y="box.y" :width="box.w" :height="box.h" rx="6"
            :fill="el.fill || 'none'" :stroke="el.color" :stroke-width="el.size" stroke-linejoin="round"
        />
        <ellipse
            v-else-if="el.type === 'ellipse'"
            :cx="box.x + box.w / 2" :cy="box.y + box.h / 2" :rx="box.w / 2" :ry="box.h / 2"
            :fill="el.fill || 'none'" :stroke="el.color" :stroke-width="el.size"
        />
        <text
            v-else-if="el.type === 'text'"
            :fill="el.color"
            :font-size="el.size"
            :font-family="FONT_FAMILY"
            style="white-space: pre"
        >
            <tspan v-for="(line, index) in lines" :key="index" :x="el.x" :y="el.y + el.size * 0.95 + index * el.size * 1.25">{{ line }}</tspan>
        </text>
        <g v-else-if="el.type === 'sticky'">
            <rect :x="el.x + 3" :y="el.y + 5" :width="layout.w" :height="layout.h" rx="4" fill="#0f172a" fill-opacity="0.12" />
            <rect :x="el.x" :y="el.y" :width="layout.w" :height="layout.h" rx="4" :fill="el.color" />
            <text :fill="'#1f2937'" :font-size="STICKY_FONT" :font-family="FONT_FAMILY" style="white-space: pre">
                <tspan
                    v-for="(line, index) in layout.lines"
                    :key="index"
                    :x="el.x + STICKY_PADDING"
                    :y="el.y + STICKY_PADDING + STICKY_FONT * 0.95 + index * STICKY_FONT * STICKY_LINE"
                >{{ line }}</tspan>
            </text>
        </g>
        <g v-else-if="el.type === 'card'">
            <rect :x="el.x + 2" :y="el.y + 4" :width="layout.w" :height="layout.h" rx="10" fill="#0f172a" fill-opacity="0.12" />
            <rect :x="el.x" :y="el.y" :width="layout.w" :height="layout.h" rx="10" fill="#ffffff" stroke="#e2e8f0" />
            <rect :x="el.x" :y="el.y" :width="layout.w" height="6" rx="3" :fill="layout.missing ? '#94a3b8' : layout.color" />
            <text :x="el.x + 14" :y="el.y + layout.statusY" font-size="11" font-weight="800" letter-spacing="0.6" :font-family="FONT_FAMILY" :fill="layout.missing ? '#94a3b8' : layout.color">
                {{ layout.missing ? 'NOT ON BOARD' : String(layout.status || '').toUpperCase() }}
            </text>
            <text font-size="15" font-weight="700" :font-family="FONT_FAMILY" :fill="layout.missing ? '#94a3b8' : '#0f172a'" style="white-space: pre">
                <tspan v-for="(line, index) in layout.titleLines" :key="index" :x="el.x + 14" :y="el.y + layout.titleY + index * 20">{{ line }}</tspan>
            </text>
            <text v-if="layout.meta" :x="el.x + 14" :y="el.y + layout.metaY" font-size="12" font-weight="600" :font-family="FONT_FAMILY" :fill="layout.overdue ? '#dc2626' : '#64748b'">
                {{ layout.meta }}
            </text>
        </g>
        <image
            v-else-if="el.type === 'image' && el.src"
            :href="el.src"
            :x="el.x" :y="el.y" :width="el.w" :height="el.h"
            :opacity="el.opacity ?? 1"
            preserveAspectRatio="xMidYMid meet"
        />
        <g v-else-if="el.type === 'icon' && icon" :transform="`translate(${el.x} ${el.y}) scale(${el.w / 24} ${el.h / 24})`">
            <path v-if="icon.d" :d="icon.d" fill="none" :stroke="el.color" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
            <path v-if="icon.fill" :d="icon.fill" :fill="el.color" />
        </g>
        <g v-else-if="el.type === 'image' || el.type === 'icon'">
            <rect :x="el.x" :y="el.y" :width="el.w" :height="el.h" fill="#f1f5f9" stroke="#cbd5e1" stroke-dasharray="4 3" />
            <text :x="el.x + el.w / 2" :y="el.y + el.h / 2 + 4" text-anchor="middle" font-size="12" fill="#94a3b8" :font-family="FONT_FAMILY">{{ el.type === 'image' ? 'Picture' : 'Icon' }}</text>
        </g>
    </g>
</template>
