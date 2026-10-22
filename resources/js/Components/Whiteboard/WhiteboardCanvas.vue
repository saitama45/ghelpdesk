<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, shallowRef, watch } from 'vue';
import WhiteboardElements from './WhiteboardElements.vue';
import { ASSET_DRAG_TYPE, RASTER_IMAGE, fitPicture } from '@/Components/SharedCanvas/assets';
import {
    CARD_DRAG_TYPE,
    FONT_FAMILY,
    INK_COLORS,
    STICKY_COLORS,
    STICKY_FONT,
    STICKY_LINE,
    STICKY_PADDING,
    STROKE_SIZES,
    TEXT_SIZES,
    boxesIntersect,
    canErasePart,
    clone,
    constrainShape,
    contentBounds,
    elementBounds,
    elementBox,
    elementCenter,
    eraserRuns,
    exportGroupToPng,
    fullyErased,
    hitTest,
    isRotatable,
    measureText,
    normalizeAngle,
    resizeElement,
    resizeHandles,
    rotateHandlePosition,
    rotatePoint,
    round1,
    stickyLayout,
    translateElement,
    uid,
    withErasure,
} from './whiteboard';

const props = defineProps({
    doc: { type: Object, required: true },
    pageId: { type: String, required: true },
    readonly: { type: Boolean, default: false },
    // Transparent annotation layer over other UI: fixed 1:1 view, no pan/zoom, no page background.
    overlay: { type: Boolean, default: false },
    tools: {
        type: Array,
        default: () => ['select', 'hand', 'pen', 'highlighter', 'laser', 'eraser', 'line', 'arrow', 'rect', 'ellipse', 'text', 'sticky'],
    },
    initialTool: { type: String, default: 'pen' },
    cardsById: { type: Object, default: () => ({}) },
    keyboard: { type: Boolean, default: true },
    showZoom: { type: Boolean, default: true },
    // 'bottom' keeps the toolbar off content that matters at the top (board column headers).
    toolbarPlacement: { type: String, default: 'top' },
    // async (File) => { url, width, height } — stores a pasted/dropped picture. Without it,
    // pictures can still be added from the Elements panel.
    uploadImage: { type: Function, default: null },
});

const emit = defineEmits(['open-card', 'drop-card', 'history', 'error']);

const TOOL_DEFS = {
    pointer: { label: 'Interact with the board', key: null },
    select: { label: 'Select & move (V)', key: 'v' },
    hand: { label: 'Pan (H, or hold Space)', key: 'h' },
    pen: { label: 'Pen (P)', key: 'p' },
    highlighter: { label: 'Highlighter (M)', key: 'm' },
    laser: { label: 'Laser pointer (K)', key: 'k' },
    eraser: { label: 'Eraser (E)', key: 'e' },
    line: { label: 'Line (L)', key: 'l' },
    arrow: { label: 'Arrow (A)', key: 'a' },
    rect: { label: 'Rectangle (R)', key: 'r' },
    ellipse: { label: 'Ellipse (O)', key: 'o' },
    text: { label: 'Text (T)', key: 't' },
    sticky: { label: 'Sticky note (S)', key: 's' },
};

const ICONS = {
    pointer: 'M9 11V5.5a1.5 1.5 0 0 1 3 0V11m0-1V8.5a1.5 1.5 0 0 1 3 0V11m0-.5a1.5 1.5 0 0 1 3 0V15a6 6 0 0 1-6 6h-1a6 6 0 0 1-5-2.7L4.3 15.6a1.5 1.5 0 0 1 2.4-1.8L9 16v-5',
    select: 'M5 3.5l6.5 16 2.3-6.7L20.5 10.5z',
    hand: 'M8 13V6.5a1.5 1.5 0 0 1 3 0V12m0-.5v-7a1.5 1.5 0 0 1 3 0V12m0-.5V6.5a1.5 1.5 0 0 1 3 0V13m0-3.5a1.5 1.5 0 0 1 3 0V15a6 6 0 0 1-6 6h-2a6 6 0 0 1-5-2.6L4.5 15a1.5 1.5 0 0 1 2.4-1.8L8 14.5',
    pen: 'M4 20l1.2-4.2L16.5 4.5l3 3L8.2 18.8zM14.5 6.5l3 3',
    highlighter: 'M9 14.5l6.5-10.5 4 2.5L13 17zm0 0l-1.5 4 3.5 1 2-2.5M4 21h8',
    laser: 'M12 9.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5zM12 3v3m0 12v3M3 12h3m12 0h3M5.6 5.6l2.1 2.1m8.6 8.6l2.1 2.1M5.6 18.4l2.1-2.1m8.6-8.6l2.1-2.1',
    eraser: 'M8 20h12M4.7 14.3l8.6-8.6a2 2 0 0 1 2.8 0l3.2 3.2a2 2 0 0 1 0 2.8L12 19.6H8.4l-3.7-3.7a1.1 1.1 0 0 1 0-1.6zM9.3 9.7l5 5',
    line: 'M5 19L19 5',
    arrow: 'M5 19L19 5M10 5h9v9',
    rect: 'M5.5 5h13A1.5 1.5 0 0 1 20 6.5v11a1.5 1.5 0 0 1-1.5 1.5h-13A1.5 1.5 0 0 1 4 17.5v-11A1.5 1.5 0 0 1 5.5 5z',
    ellipse: 'M12 5c4.4 0 8 3.1 8 7s-3.6 7-8 7-8-3.1-8-7 3.6-7 8-7z',
    text: 'M5 7V5h14v2M12 5v14m-3 0h6',
    sticky: 'M5 4h14v10l-5 6H5zM14 20v-6h5',
    undo: 'M9 14L4 9l5-5M4 9h11a5 5 0 0 1 0 10h-3',
    redo: 'M15 14l5-5-5-5m5 5H9a5 5 0 0 0 0 10h3',
    duplicate: 'M8 8h11v11H8zM5 16V5h11',
    trash: 'M5 7h14M10 11v6m4-6v6M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12M9 7V4h6v3',
    fill: 'M5 6.5A1.5 1.5 0 0 1 6.5 5h11A1.5 1.5 0 0 1 19 6.5v11a1.5 1.5 0 0 1-1.5 1.5h-11A1.5 1.5 0 0 1 5 17.5zM5 14l9-9m-9 14L19 5m-5 14l5-5',
    fit: 'M4 9V4h5M20 9V4h-5M4 15v5h5m11-5v5h-5',
    rotateLeft: 'M4 4v5h5M4.6 9A8 8 0 1 1 6 16.5',
    rotateRight: 'M20 4v5h-5M19.4 9A8 8 0 1 0 18 16.5',
};

const MIN_ZOOM = 0.1;
const MAX_ZOOM = 5;
const LASER_MS = 900;
const ERASER_MIN = 6;
const ERASER_MAX = 120;
const ERASER_MODES = [
    { key: 'object', label: 'Whole object', title: 'Erase whole objects you touch' },
    { key: 'part', label: 'Part', title: 'Rub out only the part you touch, like Paint' },
];
const ERASER_STORAGE_KEY = 'whiteboard.eraser';
const patternId = `wb-pattern-${uid('')}`;

const container = ref(null);
const svgEl = ref(null);
const contentGroup = ref(null);
const textInput = ref(null);
const toolbarEl = ref(null);

const canEdit = computed(() => !props.readonly);
const page = computed(() => props.doc.pageById(props.pageId));
const elements = computed(() => page.value?.elements || []);
const background = computed(() => page.value?.background || 'dots');

const availableTools = computed(() => props.tools.filter((name) => canEdit.value || ['hand', 'laser', 'pointer'].includes(name)));
const tool = ref(availableTools.value.includes(props.initialTool) ? props.initialTool : availableTools.value[0]);
watch(availableTools, (list) => {
    if (!list.includes(tool.value)) tool.value = list[0];
});

const color = ref(props.overlay ? '#ef4444' : '#111827');
const stickyColor = ref(STICKY_COLORS[0]);
const size = ref(4);
const fillShapes = ref(false);

// Eraser: whole objects or Paint-style part erasing, sized in screen px. Remembered per viewer.
const clampEraser = (value) => Math.min(ERASER_MAX, Math.max(ERASER_MIN, Math.round(value)));
const storedEraser = (() => {
    try {
        return JSON.parse(window.localStorage.getItem(ERASER_STORAGE_KEY) || 'null') || {};
    } catch {
        return {};
    }
})();
const eraserMode = ref(storedEraser.mode === 'part' ? 'part' : 'object');
const eraserSize = ref(clampEraser(Number(storedEraser.size) || 24));
const eraserHead = ref(null);
watch([eraserMode, eraserSize], ([mode, value]) => {
    try {
        window.localStorage.setItem(ERASER_STORAGE_KEY, JSON.stringify({ mode, size: value }));
    } catch {
        // Storage blocked (private window): the choice just isn't remembered.
    }
});

const view = reactive({ x: 0, y: 0, zoom: 1 });
const selection = ref([]);
const overrides = shallowRef(null);
const faded = shallowRef(null);
const draft = ref(null);
const marquee = ref(null);
const laserTrail = ref([]);
const laserNow = ref(0);
const laserHead = ref(null);
const editing = ref(null);
const spaceHeld = ref(false);
const gestureKind = ref(null);

let gesture = null;
let pinch = null;
let laserFrame = null;
const pointers = new Map();
const viewsByPage = new Map();

// ── Coordinates ──────────────────────────────────────────────────────────────

const svgRect = () => svgEl.value.getBoundingClientRect();
const toWorld = (clientX, clientY) => {
    const r = svgRect();
    return { x: (clientX - r.left - view.x) / view.zoom, y: (clientY - r.top - view.y) / view.zoom };
};
const tolerance = computed(() => 6 / view.zoom);
const transform = computed(() => `translate(${view.x} ${view.y}) scale(${view.zoom})`);
const clampZoom = (zoom) => Math.min(MAX_ZOOM, Math.max(MIN_ZOOM, zoom));

const elementById = (id) => elements.value.find((element) => element.id === id) || null;

const hitAt = (point) => {
    for (let i = elements.value.length - 1; i >= 0; i--) {
        const element = elements.value[i];
        if (hitTest(element, point.x, point.y, tolerance.value, props.cardsById)) return element;
    }
    return null;
};

// ── View ─────────────────────────────────────────────────────────────────────

const zoomAt = (clientX, clientY, factor) => {
    if (props.overlay) return;
    const r = svgRect();
    const px = clientX - r.left;
    const py = clientY - r.top;
    const zoom = clampZoom(view.zoom * factor);
    view.x = px - (px - view.x) * (zoom / view.zoom);
    view.y = py - (py - view.y) * (zoom / view.zoom);
    view.zoom = zoom;
};

const zoomBy = (factor) => {
    const r = svgRect();
    zoomAt(r.left + r.width / 2, r.top + r.height / 2, factor);
};

const resetZoom = () => zoomBy(1 / view.zoom);

const fitToContent = ({ maxZoom = 1 } = {}) => {
    if (props.overlay || !svgEl.value) return;
    const bounds = contentBounds(elements.value, props.cardsById);
    if (!bounds) {
        Object.assign(view, { x: 0, y: 0, zoom: 1 });
        return;
    }
    const r = svgRect();
    const pad = 56;
    // Keep content clear of the floating toolbar (it wraps to two rows on narrower canvases).
    const top = props.toolbarPlacement === 'top' && toolbarEl.value ? Math.max(pad, toolbarEl.value.offsetHeight + 28) : pad;
    const zoom = clampZoom(Math.min((r.width - pad * 2) / Math.max(bounds.w, 1), (r.height - top - pad) / Math.max(bounds.h, 1), maxZoom));
    view.zoom = zoom;
    view.x = (r.width - bounds.w * zoom) / 2 - bounds.x * zoom;
    view.y = top + (r.height - top - pad - bounds.h * zoom) / 2 - bounds.y * zoom;
};

const viewportCenter = () => {
    const r = svgRect();
    return toWorld(r.left + r.width / 2, r.top + r.height / 2);
};

watch(() => props.pageId, (next, previous) => {
    if (previous) viewsByPage.set(previous, { ...view });
    cancelGesture();
    commitEdit();
    selection.value = [];
    const saved = viewsByPage.get(next);
    if (saved) Object.assign(view, saved);
    else nextTick(() => fitToContent());
});

// ── History & selection actions ──────────────────────────────────────────────

const commit = (changes) => props.doc.commit(props.pageId, changes);

const pruneSelection = () => {
    selection.value = selection.value.filter((id) => elementById(id));
};

const undo = () => {
    const entry = props.doc.undo();
    if (entry) emit('history', entry);
    pruneSelection();
};

const redo = () => {
    const entry = props.doc.redo();
    if (entry) emit('history', entry);
    pruneSelection();
};

const selectedElements = computed(() => selection.value.map(elementById).filter(Boolean));

const deleteSelection = () => {
    if (!selectedElements.value.length) return;
    commit(selectedElements.value.map((element) => ({ id: element.id, after: null })));
    selection.value = [];
};

const duplicateSelection = () => {
    if (!selectedElements.value.length) return;
    const copies = selectedElements.value.map((element) => ({ ...translateElement(element, 24, 24), id: uid() }));
    commit(copies.map((copy) => ({ id: copy.id, after: copy })));
    selection.value = copies.map((copy) => copy.id);
};

const usesStickyPalette = computed(() => tool.value === 'sticky'
    || (selectedElements.value.length > 0 && selectedElements.value.every((element) => element.type === 'sticky')));
const palette = computed(() => (usesStickyPalette.value ? STICKY_COLORS : INK_COLORS));
const activeColor = computed(() => (usesStickyPalette.value ? stickyColor.value : color.value));

const restyleSelection = (restyle) => {
    const changes = selectedElements.value
        .map((element) => {
            const next = restyle(clone(element));
            return next && JSON.stringify(next) !== JSON.stringify(element) ? { id: element.id, after: next } : null;
        })
        .filter(Boolean);
    if (changes.length) commit(changes);
};

const pickColor = (value) => {
    if (usesStickyPalette.value) stickyColor.value = value;
    else color.value = value;

    restyleSelection((element) => {
        if (element.type === 'card' || element.type === 'image') return null;
        if (element.type === 'sticky') {
            if (!STICKY_COLORS.includes(value)) return null;
        } else if (!INK_COLORS.includes(value)) {
            return null;
        }
        element.color = value;
        if (element.fill) element.fill = `${value}33`;
        return element;
    });
};

const pickSize = (value) => {
    size.value = value;
    restyleSelection((element) => {
        if (element.type === 'text') element.size = TEXT_SIZES[value];
        else if (element.type === 'pen') element.size = (element.opacity ?? 1) < 1 ? Math.max(12, value * 3) : value;
        else if (['line', 'arrow', 'rect', 'ellipse'].includes(element.type)) element.size = value;
        else return null;
        return element;
    });
};

const toggleFill = () => {
    fillShapes.value = !fillShapes.value;
    restyleSelection((element) => {
        if (element.type !== 'rect' && element.type !== 'ellipse') return null;
        element.fill = fillShapes.value ? `${element.color}33` : null;
        return element;
    });
};

const showStyle = computed(() => canEdit.value && (
    ['pen', 'highlighter', 'line', 'arrow', 'rect', 'ellipse', 'text', 'sticky'].includes(tool.value)
    || selectedElements.value.some((element) => element.type !== 'card' && element.type !== 'image')
));
// Sizes only mean something for strokes and text, so a lone picture or icon doesn't show them.
const showSizes = computed(() => showStyle.value && !usesStickyPalette.value && (!selectedElements.value.length
    || selectedElements.value.some((element) => ['pen', 'line', 'arrow', 'rect', 'ellipse', 'text'].includes(element.type))));

// ── Rotation (pictures & icons) ──────────────────────────────────────────────

const ROTATE_KNOB_GAP = 28; // screen px between the item's top edge and its rotate knob

const rotatableSelection = computed(() => selectedElements.value.filter(isRotatable));

// The angle shown in the toolbar follows the knob live while it is being dragged.
const rotationShown = computed(() => {
    const first = rotatableSelection.value[0];
    if (!first) return null;
    return Math.round((overrides.value?.get(first.id) || first).rotation || 0);
});

/** Turn the selected pictures/icons, each about its own centre: `by` degrees, or `to` an angle. */
const rotateSelection = ({ by = 0, to = null }) => restyleSelection((element) => {
    if (!isRotatable(element)) return null;
    const angle = normalizeAngle(to ?? (element.rotation || 0) + by);
    if (angle === (element.rotation || 0)) return null;
    element.rotation = angle;
    return element;
});

const onRotationInput = (event) => {
    const value = Number.parseFloat(event.target.value);
    if (Number.isFinite(value)) rotateSelection({ to: value });
    else event.target.value = rotationShown.value ?? 0;
};

// Free by default, easing into 0/45/90… within a few degrees; Shift = 15° steps, Alt = no easing.
const snapAngle = (raw, event) => {
    if (event.shiftKey) return normalizeAngle(Math.round(raw / 15) * 15);
    const nearest = Math.round(raw / 45) * 45;
    return normalizeAngle(!event.altKey && Math.abs(raw - nearest) <= 4 ? nearest : Math.round(raw));
};
const showFill = computed(() => canEdit.value && (tool.value === 'rect' || tool.value === 'ellipse'
    || selectedElements.value.some((element) => element.type === 'rect' || element.type === 'ellipse')));

// ── Text & sticky editing ────────────────────────────────────────────────────

const newText = (point) => {
    const fontSize = TEXT_SIZES[size.value] || 24;
    return { id: uid(), type: 'text', x: round1(point.x), y: round1(point.y - fontSize * 0.6), text: '', color: color.value, size: fontSize };
};

const newSticky = (point) => ({
    id: uid(), type: 'sticky', x: round1(point.x - 100), y: round1(point.y - 80), w: 200, h: 160, text: '', color: stickyColor.value,
});

const beginEdit = (element, isNew) => {
    editing.value = { id: element.id, type: element.type, pageId: props.pageId, isNew, element: clone(element), text: element.text || '' };
    // Focus on a later task: the pointerdown that started this edit is followed by a mousedown
    // whose default action moves focus to the body, which would blur (and commit) the editor.
    setTimeout(() => {
        const input = textInput.value;
        if (!input) return;
        input.focus();
        input.setSelectionRange(input.value.length, input.value.length);
    }, 0);
};

const commitEdit = () => {
    const state = editing.value;
    if (!state) return;
    editing.value = null;

    const text = state.text.replace(/\s+$/, '');
    const changed = state.isNew || text !== (state.element.text || '');

    if (state.type === 'text' && !text) {
        if (!state.isNew) props.doc.commit(state.pageId, [{ id: state.id, after: null }]);
    } else if (changed) {
        props.doc.commit(state.pageId, [{ id: state.id, after: { ...state.element, text } }]);
    }

    if (state.isNew && availableTools.value.includes('select')) {
        tool.value = 'select';
        if (state.type === 'sticky' || text) selection.value = [state.id];
    }
};

const onEditorKeydown = (event) => {
    if (event.key === 'Escape' || (event.key === 'Enter' && (event.ctrlKey || event.metaKey))) {
        event.preventDefault();
        commitEdit();
    }
};

const hiddenIds = computed(() => (editing.value && !editing.value.isNew ? new Set([editing.value.id]) : null));

const editingGhost = computed(() => {
    const state = editing.value;
    if (state?.type !== 'sticky') return null;
    return [{ ...state.element, text: '', h: stickyLayout({ ...state.element, text: state.text }).h }];
});

const editorStyle = computed(() => {
    const state = editing.value;
    if (!state) return {};
    const element = state.element;
    const zoom = view.zoom;

    if (state.type === 'sticky') {
        const layout = stickyLayout({ ...element, text: state.text });
        return {
            left: `${view.x + (element.x + STICKY_PADDING) * zoom}px`,
            top: `${view.y + (element.y + STICKY_PADDING) * zoom}px`,
            width: `${(layout.w - STICKY_PADDING * 2) * zoom}px`,
            height: `${(layout.h - STICKY_PADDING * 2) * zoom}px`,
            fontSize: `${STICKY_FONT * zoom}px`,
            lineHeight: STICKY_LINE,
            color: '#1f2937',
            whiteSpace: 'pre-wrap',
            overflowWrap: 'break-word',
            fontFamily: FONT_FAMILY,
        };
    }

    const lines = state.text.split('\n');
    const fontSize = element.size;
    const width = Math.max(fontSize, ...lines.map((line) => measureText(line, fontSize))) + fontSize;
    return {
        left: `${view.x + element.x * zoom}px`,
        top: `${view.y + element.y * zoom}px`,
        width: `${width * zoom}px`,
        height: `${lines.length * fontSize * 1.25 * zoom + 4}px`,
        fontSize: `${fontSize * zoom}px`,
        lineHeight: 1.25,
        color: element.color,
        whiteSpace: 'pre',
        fontFamily: FONT_FAMILY,
    };
});

// ── Selection frame & handles ────────────────────────────────────────────────

const showSelection = computed(() => canEdit.value && tool.value === 'select');

const selectionFrames = computed(() => {
    if (!showSelection.value) return [];
    return selection.value
        .map((id) => {
            const element = overrides.value?.get(id) || elementById(id);
            if (!element) return null;
            // A turned picture gets a frame that turns with it, not its wider bounding box.
            if (isRotatable(element) && element.rotation) {
                const center = elementCenter(element);
                return { id, box: elementBox(element), transform: `rotate(${element.rotation} ${center.x} ${center.y})` };
            }
            return { id, box: elementBounds(element, props.cardsById), transform: null };
        })
        .filter(Boolean);
});

const rotateKnob = computed(() => {
    if (!showSelection.value || selection.value.length !== 1) return null;
    const id = selection.value[0];
    const element = overrides.value?.get(id) || elementById(id);
    if (!isRotatable(element)) return null;
    const center = elementCenter(element);
    return {
        id,
        ...rotateHandlePosition(element, ROTATE_KNOB_GAP / view.zoom),
        stem: rotatePoint({ x: center.x, y: element.y }, center, element.rotation || 0),
        angle: Math.round(element.rotation || 0),
    };
});

const onRotateKnob = (point) => {
    const knob = rotateKnob.value;
    return knob && Math.hypot(knob.x - point.x, knob.y - point.y) <= 12 / view.zoom ? knob : null;
};

const handles = computed(() => {
    if (!showSelection.value || selection.value.length !== 1) return [];
    const id = selection.value[0];
    const element = overrides.value?.get(id) || elementById(id);
    return element ? resizeHandles(element, props.cardsById).map((handle) => ({ ...handle, id })) : [];
});

const handleAt = (point) => handles.value.find((handle) => Math.hypot(handle.x - point.x, handle.y - point.y) <= 10 / view.zoom);

// ── Laser ────────────────────────────────────────────────────────────────────

const tickLaser = () => {
    const now = performance.now();
    laserNow.value = now;
    laserTrail.value = laserTrail.value.filter((point) => now - point.t < LASER_MS);
    laserFrame = laserTrail.value.length ? requestAnimationFrame(tickLaser) : null;
};

const pushLaser = (point) => {
    laserTrail.value.push({ x: point.x, y: point.y, t: performance.now() });
    if (laserTrail.value.length > 120) laserTrail.value.shift();
    if (!laserFrame) laserFrame = requestAnimationFrame(tickLaser);
};

const laserSegments = computed(() => {
    const points = laserTrail.value;
    const segments = [];
    for (let i = 1; i < points.length; i++) {
        segments.push({
            key: points[i].t,
            x1: points[i - 1].x, y1: points[i - 1].y, x2: points[i].x, y2: points[i].y,
            opacity: Math.max(0, 1 - (laserNow.value - points[i].t) / LASER_MS),
        });
    }
    return segments;
});

// ── Pointer handling ─────────────────────────────────────────────────────────

const startGesture = (kind, data = {}) => {
    gesture = { kind, ...data };
    gestureKind.value = kind;
};

const cancelGesture = () => {
    gesture = null;
    gestureKind.value = null;
    draft.value = null;
    overrides.value = null;
    faded.value = null;
    marquee.value = null;
};

const startPinch = () => {
    const [a, b] = [...pointers.values()];
    pinch = { dist: Math.hypot(a.x - b.x, a.y - b.y) || 1, mid: { x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 }, view: { ...view } };
};

const updatePinch = () => {
    const [a, b] = [...pointers.values()];
    const r = svgRect();
    const mid = { x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 };
    const zoom = clampZoom(pinch.view.zoom * (Math.hypot(a.x - b.x, a.y - b.y) / pinch.dist));
    const worldX = (pinch.mid.x - r.left - pinch.view.x) / pinch.view.zoom;
    const worldY = (pinch.mid.y - r.top - pinch.view.y) / pinch.view.zoom;
    view.zoom = zoom;
    view.x = mid.x - r.left - worldX * zoom;
    view.y = mid.y - r.top - worldY * zoom;
};

// World radius of the eraser circle; whole-object mode never grabs less than it used to.
const eraserReach = () => Math.max(tolerance.value * 1.5, eraserSize.value / 2 / view.zoom);

// Calls `visit(x, y)` at points along from→to, close enough together not to skip anything `reach` wide.
const sweep = (from, to, reach, visit) => {
    const steps = Math.max(1, Math.ceil(Math.hypot(to.x - from.x, to.y - from.y) / Math.max(reach / 2, 0.5 / view.zoom)));
    for (let step = 0; step <= steps; step++) {
        visit(from.x + ((to.x - from.x) * step) / steps, from.y + ((to.y - from.y) * step) / steps);
    }
};

const eraseAlong = (from, to) => {
    const hits = gesture.hits;
    const before = hits.size;
    const reach = eraserReach();
    sweep(from, to, reach, (x, y) => {
        for (const element of elements.value) {
            if (!hits.has(element.id) && hitTest(element, x, y, reach, props.cardsById)) hits.add(element.id);
        }
    });
    if (hits.size !== before) faded.value = new Set(hits);
};

// Part eraser: collect the elements the rub has touched, and preview each with the rub cut out.
const rubAlong = (from, to) => {
    const { touched, width } = gesture;
    sweep(from, to, width / 2, (x, y) => {
        for (const element of elements.value) {
            if (!touched.has(element.id) && canErasePart(element) && hitTest(element, x, y, width / 2, props.cardsById)) {
                touched.set(element.id, clone(element));
            }
        }
    });
    const preview = [];
    for (const [id, base] of touched) {
        const runs = eraserRuns(base, gesture.path, width, props.cardsById);
        const cut = runs.length ? withErasure(base, runs, width) : null;
        if (cut) preview.push([id, cut]);
    }
    overrides.value = preview.length ? new Map(preview) : null;
};

const openCardAt = (point) => {
    const hit = hitAt(point);
    if (hit?.type === 'card') emit('open-card', hit.cardId);
};

const onPointerDown = (event) => {
    if (event.button === 2) return;
    commitEdit();

    svgEl.value.setPointerCapture?.(event.pointerId);
    pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });

    if (pointers.size === 2 && !props.overlay && event.pointerType === 'touch') {
        cancelGesture();
        startPinch();
        return;
    }
    if (pointers.size > 1 || pinch) return;

    const point = toWorld(event.clientX, event.clientY);
    const active = (event.button === 1 || spaceHeld.value) && !props.overlay ? 'hand' : tool.value;

    if (active === 'hand') {
        startGesture('pan', { sx: event.clientX, sy: event.clientY, vx: view.x, vy: view.y, moved: false, origin: point });
        return;
    }
    if (active === 'laser') {
        startGesture('laser');
        laserHead.value = point;
        pushLaser(point);
        return;
    }
    if (!canEdit.value) return;

    switch (active) {
        case 'pen':
        case 'highlighter': {
            const highlight = active === 'highlighter';
            draft.value = {
                id: uid(),
                type: 'pen',
                points: [round1(point.x), round1(point.y)],
                color: color.value,
                size: highlight ? Math.max(12, size.value * 3) : size.value,
                opacity: highlight ? 0.35 : 1,
            };
            startGesture('draw');
            return;
        }
        case 'line':
        case 'arrow':
        case 'rect':
        case 'ellipse': {
            const shape = { id: uid(), type: active, x1: round1(point.x), y1: round1(point.y), x2: round1(point.x), y2: round1(point.y), color: color.value, size: size.value };
            if (active === 'rect' || active === 'ellipse') shape.fill = fillShapes.value ? `${color.value}33` : null;
            draft.value = shape;
            startGesture('shape');
            return;
        }
        case 'eraser':
            eraserHead.value = point;
            if (eraserMode.value === 'part') {
                startGesture('rub', { path: [round1(point.x), round1(point.y)], width: eraserSize.value / view.zoom, touched: new Map() });
                rubAlong(point, point);
            } else {
                startGesture('erase', { last: point, hits: new Set() });
                eraseAlong(point, point);
            }
            return;
        case 'text': {
            const hit = hitAt(point);
            beginEdit(hit?.type === 'text' ? hit : newText(point), hit?.type !== 'text');
            return;
        }
        case 'sticky':
            beginEdit(newSticky(point), true);
            return;
        case 'select': {
            const knob = onRotateKnob(point);
            if (knob) {
                const base = clone(elementById(knob.id));
                startGesture('rotate', { id: knob.id, base, center: elementCenter(base), angle: base.rotation || 0 });
                return;
            }
            const handle = handleAt(point);
            if (handle) {
                startGesture('resize', { id: handle.id, handle: handle.key, base: clone(elementById(handle.id)) });
                return;
            }
            const hit = hitAt(point);
            if (!hit) {
                if (!event.shiftKey) selection.value = [];
                startGesture('marquee', { origin: point, additive: event.shiftKey });
                marquee.value = { x: point.x, y: point.y, w: 0, h: 0 };
                return;
            }
            if (event.shiftKey) {
                if (selection.value.includes(hit.id)) {
                    selection.value = selection.value.filter((id) => id !== hit.id);
                    return;
                }
                selection.value = [...selection.value, hit.id];
            } else if (!selection.value.includes(hit.id)) {
                selection.value = [hit.id];
            }
            const base = new Map(selectedElements.value.map((element) => [element.id, clone(element)]));
            startGesture('move', { origin: point, base, moved: false });
            return;
        }
        default:
    }
};

const onPointerMove = (event) => {
    if (pointers.has(event.pointerId)) pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });
    if (pinch) {
        if (pointers.size === 2) updatePinch();
        return;
    }

    const point = toWorld(event.clientX, event.clientY);

    if (tool.value === 'laser') {
        laserHead.value = point;
        if (gesture?.kind === 'laser' || event.pointerType === 'mouse') pushLaser(point);
    }
    if (tool.value === 'eraser') eraserHead.value = point;
    if (!gesture) return;

    switch (gesture.kind) {
        case 'pan':
            view.x = gesture.vx + event.clientX - gesture.sx;
            view.y = gesture.vy + event.clientY - gesture.sy;
            if (Math.hypot(event.clientX - gesture.sx, event.clientY - gesture.sy) > 3) gesture.moved = true;
            break;
        case 'draw': {
            const samples = event.getCoalescedEvents?.() || [];
            const points = draft.value.points;
            for (const sample of samples.length ? samples : [event]) {
                const next = toWorld(sample.clientX, sample.clientY);
                const lastX = points[points.length - 2];
                const lastY = points[points.length - 1];
                if (Math.hypot(next.x - lastX, next.y - lastY) * view.zoom >= 1.5) points.push(round1(next.x), round1(next.y));
            }
            break;
        }
        case 'shape': {
            let { x, y } = point;
            if (event.shiftKey) ({ x2: x, y2: y } = constrainShape(draft.value.type, draft.value.x1, draft.value.y1, x, y));
            draft.value.x2 = round1(x);
            draft.value.y2 = round1(y);
            break;
        }
        case 'erase':
            eraseAlong(gesture.last, point);
            gesture.last = point;
            break;
        case 'rub': {
            const path = gesture.path;
            const spacing = Math.max(1.5 / view.zoom, gesture.width * 0.15);
            const samples = event.getCoalescedEvents?.() || [];
            for (const sample of samples.length ? samples : [event]) {
                const next = toWorld(sample.clientX, sample.clientY);
                const last = { x: path[path.length - 2], y: path[path.length - 1] };
                if (Math.hypot(next.x - last.x, next.y - last.y) < spacing) continue;
                path.push(round1(next.x), round1(next.y));
                rubAlong(last, next);
            }
            break;
        }
        case 'move': {
            const dx = point.x - gesture.origin.x;
            const dy = point.y - gesture.origin.y;
            if (!gesture.moved && Math.hypot(dx, dy) * view.zoom < 3) return;
            gesture.moved = true;
            overrides.value = new Map([...gesture.base].map(([id, element]) => [id, translateElement(element, dx, dy)]));
            break;
        }
        case 'resize':
            overrides.value = new Map([[gesture.id, resizeElement(gesture.base, gesture.handle, point.x, point.y)]]);
            break;
        case 'rotate': {
            // Knob sits above the top edge, so pointing straight up from the centre is 0°.
            const raw = (Math.atan2(point.y - gesture.center.y, point.x - gesture.center.x) * 180) / Math.PI + 90;
            gesture.angle = snapAngle(raw, event);
            overrides.value = new Map([[gesture.id, { ...gesture.base, rotation: gesture.angle }]]);
            break;
        }
        case 'marquee':
            marquee.value = {
                x: Math.min(gesture.origin.x, point.x),
                y: Math.min(gesture.origin.y, point.y),
                w: Math.abs(point.x - gesture.origin.x),
                h: Math.abs(point.y - gesture.origin.y),
            };
            break;
        default:
    }
};

const onPointerUp = (event) => {
    pointers.delete(event.pointerId);
    if (pinch) {
        if (pointers.size < 2) pinch = null;
        return;
    }
    if (!gesture) return;

    const current = gesture;
    const shape = draft.value;
    const moved = overrides.value;
    const box = marquee.value;
    cancelGesture();

    switch (current.kind) {
        case 'pan':
            if (!current.moved) openCardAt(current.origin);
            break;
        case 'draw':
            if (shape.points.length === 2) shape.points.push(round1(shape.points[0] + 0.1), shape.points[1]);
            commit([{ id: shape.id, after: shape }]);
            break;
        case 'shape':
            if (Math.hypot(shape.x2 - shape.x1, shape.y2 - shape.y1) * view.zoom >= 4) commit([{ id: shape.id, after: shape }]);
            break;
        case 'erase':
            if (current.hits.size) {
                commit([...current.hits].map((id) => ({ id, after: null })));
                pruneSelection();
            }
            break;
        case 'rub': {
            // Ink or a line rubbed out end to end is removed rather than kept as an invisible element.
            const changes = [...(moved || [])].map(([id, after]) => ({ id, after: fullyErased(after) ? null : after }));
            if (changes.length) {
                commit(changes);
                pruneSelection();
            }
            break;
        }
        case 'move':
        case 'resize':
            if (moved && (current.kind === 'resize' || current.moved)) {
                commit([...moved].map(([id, after]) => ({ id, after })));
            }
            break;
        case 'rotate':
            if (moved && current.angle !== (current.base.rotation || 0)) {
                commit([...moved].map(([id, after]) => ({ id, after })));
            }
            break;
        case 'marquee':
            if (box && (box.w > 0 || box.h > 0)) {
                const ids = elements.value.filter((element) => boxesIntersect(elementBounds(element, props.cardsById), box)).map((element) => element.id);
                selection.value = current.additive ? [...new Set([...selection.value, ...ids])] : ids;
            }
            break;
        default:
    }
};

const onPointerCancel = (event) => {
    pointers.delete(event.pointerId);
    if (pointers.size < 2) pinch = null;
    cancelGesture();
};

const onPointerLeave = () => {
    if (gesture) return;
    laserHead.value = null;
    eraserHead.value = null;
};

const onDoubleClick = (event) => {
    const point = toWorld(event.clientX, event.clientY);
    const hit = hitAt(point);

    if (hit?.type === 'card') {
        emit('open-card', hit.cardId);
        return;
    }
    if (!canEdit.value || tool.value !== 'select') return;
    if (hit?.type === 'text' || hit?.type === 'sticky') beginEdit(hit, false);
    else if (!hit && availableTools.value.includes('text')) beginEdit(newText(point), true);
};

const onWheel = (event) => {
    if (props.overlay) return;
    event.preventDefault();
    if (event.ctrlKey || event.metaKey) {
        const delta = Math.max(-50, Math.min(50, event.deltaY));
        zoomAt(event.clientX, event.clientY, Math.exp(-delta * 0.008));
    } else {
        view.x -= event.deltaX;
        view.y -= event.deltaY;
    }
};

// ── Icons & pictures (Elements panel, paste, drop) ───────────────────────────

const uploading = ref(0);

/** Place an Elements-panel item ({ kind: 'icon', key } | { kind: 'image', url, width, height }). */
const addAsset = (asset, at = null) => {
    if (!canEdit.value || !asset) return null;
    const point = at || viewportCenter();
    let element = null;
    if (asset.kind === 'icon') {
        element = { id: uid(), type: 'icon', icon: asset.key, x: round1(point.x - 48), y: round1(point.y - 48), w: 96, h: 96, color: color.value };
    } else if (asset.kind === 'image' && asset.url) {
        const { w, h } = fitPicture(asset.width, asset.height);
        element = { id: uid(), type: 'image', src: asset.url, x: round1(point.x - w / 2), y: round1(point.y - h / 2), w, h, opacity: 1 };
    }
    if (!element) return null;
    commit([{ id: element.id, after: element }]);
    if (availableTools.value.includes('select')) tool.value = 'select';
    selection.value = [element.id];
    return element.id;
};

/** Upload pasted/dropped picture files, then place them (cascading from `at`). */
const insertImages = async (files, at = null) => {
    if (!canEdit.value || !props.uploadImage) return;
    const pictures = [...files].filter((file) => RASTER_IMAGE.test(file.type));
    if (!pictures.length) {
        emit('error', 'Only PNG, JPG, GIF or WebP pictures can be added.');
        return;
    }
    const origin = at || viewportCenter();
    uploading.value += pictures.length;
    for (const [index, file] of pictures.entries()) {
        try {
            const asset = await props.uploadImage(file);
            addAsset({ kind: 'image', url: asset.url, width: asset.width, height: asset.height }, { x: origin.x + index * 24, y: origin.y + index * 24 });
        } catch (error) {
            emit('error', error?.response?.data?.message || `Could not add ${file.name || 'the picture'}.`);
        } finally {
            uploading.value--;
        }
    }
};

const onPaste = (event) => {
    if (!props.keyboard || props.overlay || !canEdit.value || !props.uploadImage) return;
    const target = event.target;
    if (['INPUT', 'TEXTAREA', 'SELECT'].includes(target?.tagName) || target?.isContentEditable) return;
    if (document.querySelector('dialog[open]')) return;
    const pictures = [...(event.clipboardData?.files || [])].filter((file) => file.type.startsWith('image/'));
    if (!pictures.length) return;
    event.preventDefault();
    insertImages(pictures);
};

const onDragOver = (event) => {
    const types = event.dataTransfer?.types || [];
    const accepts = types.includes(CARD_DRAG_TYPE) || types.includes(ASSET_DRAG_TYPE) || (types.includes('Files') && props.uploadImage);
    if (!canEdit.value || !accepts) return;
    event.preventDefault();
    event.dataTransfer.dropEffect = 'copy';
};

const onDrop = (event) => {
    if (!canEdit.value) return;
    const point = toWorld(event.clientX, event.clientY);

    const asset = event.dataTransfer?.getData(ASSET_DRAG_TYPE);
    if (asset) {
        event.preventDefault();
        try {
            addAsset(JSON.parse(asset), point);
        } catch {
            // Not one of ours.
        }
        return;
    }

    if (event.dataTransfer?.files?.length && props.uploadImage) {
        event.preventDefault();
        insertImages(event.dataTransfer.files, point);
        return;
    }

    const cardId = event.dataTransfer?.getData(CARD_DRAG_TYPE);
    if (!cardId) return;
    event.preventDefault();
    emit('drop-card', { cardId: Number(cardId), x: point.x, y: point.y });
};

// ── Keyboard ─────────────────────────────────────────────────────────────────

const onKeyDown = (event) => {
    if (!props.keyboard) return;
    const target = event.target;
    if (['INPUT', 'TEXTAREA', 'SELECT'].includes(target?.tagName) || target?.isContentEditable) return;
    if (document.querySelector('dialog[open]')) return;

    const key = event.key.toLowerCase();
    const mod = event.ctrlKey || event.metaKey;

    if (event.key === ' ' && !props.overlay) {
        spaceHeld.value = true;
        event.preventDefault();
        return;
    }
    if (mod && key === 'z' && canEdit.value) {
        event.preventDefault();
        if (event.shiftKey) redo();
        else undo();
        return;
    }
    if (mod && key === 'y' && canEdit.value) {
        event.preventDefault();
        redo();
        return;
    }
    if (!props.overlay && mod && (key === '=' || key === '+' || key === '-' || key === '0')) {
        event.preventDefault();
        if (key === '0') resetZoom();
        else zoomBy(key === '-' ? 1 / 1.2 : 1.2);
        return;
    }
    if (!props.overlay && event.shiftKey && event.code === 'Digit1') {
        event.preventDefault();
        fitToContent();
        return;
    }
    if (canEdit.value && mod && key === 'a' && availableTools.value.includes('select')) {
        event.preventDefault();
        tool.value = 'select';
        selection.value = elements.value.map((element) => element.id);
        return;
    }
    // Only taken over while a picture/icon is selected; otherwise Ctrl+R still reloads the page.
    if (canEdit.value && mod && key === 'r' && rotatableSelection.value.length) {
        event.preventDefault();
        rotateSelection({ by: event.shiftKey ? -90 : 90 });
        return;
    }
    if (canEdit.value && mod && key === 'd' && selection.value.length) {
        event.preventDefault();
        duplicateSelection();
        return;
    }
    if (canEdit.value && (key === 'delete' || key === 'backspace') && selection.value.length) {
        event.preventDefault();
        deleteSelection();
        return;
    }
    if (key === 'escape') {
        selection.value = [];
        return;
    }
    if (mod || event.altKey) return;
    if (tool.value === 'eraser' && (event.key === '[' || event.key === ']')) {
        eraserSize.value = clampEraser(eraserSize.value + (event.key === ']' ? 6 : -6));
        return;
    }

    const match = Object.entries(TOOL_DEFS).find(([name, def]) => def.key === key && availableTools.value.includes(name));
    if (match) tool.value = match[0];
};

const onKeyUp = (event) => {
    if (event.key === ' ') spaceHeld.value = false;
};

watch(tool, (next) => {
    if (next !== 'select') selection.value = [];
    if (next !== 'laser') laserHead.value = null;
    if (next !== 'eraser') eraserHead.value = null;
});

const cursor = computed(() => {
    if (gestureKind.value === 'pan' || gestureKind.value === 'rotate') return 'grabbing';
    if (spaceHeld.value || tool.value === 'hand') return 'grab';
    return {
        select: 'default',
        pointer: 'default',
        text: 'text',
        laser: 'none',
        eraser: 'none', // the sized eraser circle is drawn instead
        sticky: 'copy',
    }[tool.value] || 'crosshair';
});

const passThrough = computed(() => props.overlay && tool.value === 'pointer');

const exportPng = async (filename = 'whiteboard.png') => {
    commitEdit();
    selection.value = [];
    await nextTick();
    if (!contentGroup.value) return;
    await exportGroupToPng(contentGroup.value, contentBounds(elements.value, props.cardsById), filename);
};

onMounted(() => {
    window.addEventListener('keydown', onKeyDown);
    window.addEventListener('keyup', onKeyUp);
    window.addEventListener('paste', onPaste);
    nextTick(() => fitToContent());
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeyDown);
    window.removeEventListener('keyup', onKeyUp);
    window.removeEventListener('paste', onPaste);
    if (laserFrame) cancelAnimationFrame(laserFrame);
});

defineExpose({
    addAsset,
    insertImages,
    fitToContent,
    zoomBy,
    resetZoom,
    exportPng,
    viewportCenter,
    commitEdit,
    select: (ids) => {
        if (availableTools.value.includes('select')) tool.value = 'select';
        selection.value = ids;
    },
    setTool: (name) => {
        if (availableTools.value.includes(name)) tool.value = name;
    },
    getTool: () => tool.value,
});
</script>

<template>
    <div ref="container" class="relative h-full w-full select-none overflow-hidden" :class="overlay ? 'pointer-events-none' : 'bg-white'">
        <svg
            ref="svgEl"
            class="absolute inset-0 h-full w-full touch-none"
            :style="{ cursor, pointerEvents: passThrough ? 'none' : 'auto' }"
            data-testid="whiteboard-surface"
            @pointerdown="onPointerDown"
            @pointermove="onPointerMove"
            @pointerup="onPointerUp"
            @pointercancel="onPointerCancel"
            @pointerleave="onPointerLeave"
            @dblclick="onDoubleClick"
            @wheel="onWheel"
            @dragover="onDragOver"
            @drop="onDrop"
        >
            <defs v-if="!overlay">
                <pattern
                    :id="patternId"
                    :width="24"
                    :height="24"
                    patternUnits="userSpaceOnUse"
                    :patternTransform="transform"
                >
                    <circle v-if="background === 'dots'" cx="1" cy="1" r="1.1" fill="#cbd5e1" />
                    <path v-else-if="background === 'grid'" d="M24 0H0V24" fill="none" stroke="#e2e8f0" stroke-width="1" />
                </pattern>
            </defs>
            <rect v-if="!overlay && background !== 'plain' && view.zoom >= 0.3" width="100%" height="100%" :fill="`url(#${patternId})`" />

            <g :transform="transform">
                <g ref="contentGroup">
                    <WhiteboardElements :elements="elements" :cards-by-id="cardsById" :overrides="overrides" :faded="faded" :hidden="hiddenIds" />
                </g>
                <WhiteboardElements v-if="editingGhost" :elements="editingGhost" :cards-by-id="cardsById" />
                <WhiteboardElements v-if="draft" :elements="[draft]" :cards-by-id="cardsById" />

                <g v-for="frame in selectionFrames" :key="`sel-${frame.id}`" :transform="frame.transform" pointer-events="none">
                    <rect
                        :x="frame.box.x - 4 / view.zoom"
                        :y="frame.box.y - 4 / view.zoom"
                        :width="frame.box.w + 8 / view.zoom"
                        :height="frame.box.h + 8 / view.zoom"
                        fill="none"
                        stroke="#2563eb"
                        :stroke-width="1.5 / view.zoom"
                        :stroke-dasharray="`${5 / view.zoom} ${4 / view.zoom}`"
                    />
                </g>
                <rect
                    v-for="handle in handles"
                    :key="`handle-${handle.key}`"
                    :x="handle.x - 5 / view.zoom"
                    :y="handle.y - 5 / view.zoom"
                    :width="10 / view.zoom"
                    :height="10 / view.zoom"
                    :rx="2 / view.zoom"
                    fill="#ffffff"
                    stroke="#2563eb"
                    :stroke-width="1.5 / view.zoom"
                    style="cursor: nwse-resize"
                />
                <template v-if="rotateKnob">
                    <line
                        :x1="rotateKnob.stem.x" :y1="rotateKnob.stem.y" :x2="rotateKnob.x" :y2="rotateKnob.y"
                        stroke="#2563eb" :stroke-width="1.25 / view.zoom" pointer-events="none"
                    />
                    <g :transform="`translate(${rotateKnob.x} ${rotateKnob.y}) scale(${1 / view.zoom})`" :style="{ cursor: gestureKind === 'rotate' ? 'grabbing' : 'grab' }" data-testid="whiteboard-rotate-handle">
                        <title>Drag to rotate (Shift = 15° steps, Alt = no snapping)</title>
                        <circle r="8" fill="#ffffff" stroke="#2563eb" stroke-width="1.5" />
                        <path d="M-3.6 -1.4A4 4 0 1 1 -3 2.7M-3.6 -1.4V-4.6M-3.6 -1.4H-0.6" fill="none" stroke="#2563eb" stroke-width="1.4" stroke-linecap="round" />
                        <g v-if="gestureKind === 'rotate'" pointer-events="none" data-testid="whiteboard-rotate-angle">
                            <rect x="13" y="-10" width="42" height="20" rx="10" fill="#1e293b" />
                            <text x="34" y="4" text-anchor="middle" font-size="11" font-weight="700" fill="#ffffff" :font-family="FONT_FAMILY">{{ rotateKnob.angle }}°</text>
                        </g>
                    </g>
                </template>
                <rect
                    v-if="marquee"
                    :x="marquee.x" :y="marquee.y" :width="marquee.w" :height="marquee.h"
                    fill="#3b82f6" fill-opacity="0.08" stroke="#3b82f6" :stroke-width="1 / view.zoom"
                    pointer-events="none"
                />

                <circle
                    v-if="eraserHead && tool === 'eraser' && canEdit"
                    :cx="eraserHead.x" :cy="eraserHead.y"
                    :r="eraserMode === 'part' ? eraserSize / 2 / view.zoom : eraserReach()"
                    fill="#ffffff" fill-opacity="0.35"
                    stroke="#475569" :stroke-width="1.25 / view.zoom"
                    :stroke-dasharray="eraserMode === 'object' ? `${4 / view.zoom} ${3 / view.zoom}` : null"
                    pointer-events="none"
                    data-testid="whiteboard-eraser-cursor"
                />
                <g v-if="laserSegments.length || laserHead" pointer-events="none">
                    <line
                        v-for="segment in laserSegments"
                        :key="segment.key"
                        :x1="segment.x1" :y1="segment.y1" :x2="segment.x2" :y2="segment.y2"
                        stroke="#ef4444" :stroke-width="5 / view.zoom" stroke-linecap="round" :stroke-opacity="segment.opacity"
                    />
                    <template v-if="laserHead">
                        <circle :cx="laserHead.x" :cy="laserHead.y" :r="14 / view.zoom" fill="#ef4444" fill-opacity="0.25" />
                        <circle :cx="laserHead.x" :cy="laserHead.y" :r="6 / view.zoom" fill="#ef4444" stroke="#ffffff" :stroke-width="1.5 / view.zoom" />
                    </template>
                </g>
            </g>
        </svg>

        <div v-if="uploading" class="pointer-events-none absolute bottom-16 left-1/2 z-20 -translate-x-1/2 rounded-full bg-gray-900/80 px-3 py-1 text-xs font-semibold text-white shadow" data-testid="whiteboard-uploading">
            Adding {{ uploading === 1 ? 'picture' : `${uploading} pictures` }}…
        </div>

        <textarea
            v-if="editing"
            ref="textInput"
            v-model="editing.text"
            class="pointer-events-auto absolute z-10 m-0 resize-none select-text overflow-hidden border-0 bg-transparent p-0 outline-none ring-0 focus:ring-0"
            :style="editorStyle"
            :placeholder="editing.type === 'sticky' ? 'Write a note…' : 'Type…'"
            spellcheck="false"
            data-testid="whiteboard-text-editor"
            @keydown.stop="onEditorKeydown"
            @blur="commitEdit"
        ></textarea>

        <!-- Toolbar -->
        <div
            ref="toolbarEl"
            class="pointer-events-auto absolute left-1/2 z-20 flex w-max max-w-[calc(100%-1.5rem)] -translate-x-1/2 flex-wrap items-center justify-center gap-1 rounded-xl border border-gray-200 bg-white/95 p-1 text-gray-600 shadow-lg dark:border-gray-700 dark:bg-gray-800/95 dark:text-gray-300"
            :class="toolbarPlacement === 'bottom' ? 'bottom-4' : 'top-3'"
            data-testid="whiteboard-toolbar"
        >
            <button
                v-for="name in availableTools"
                :key="name"
                type="button"
                :title="TOOL_DEFS[name].label"
                :aria-label="TOOL_DEFS[name].label"
                :aria-pressed="tool === name"
                :data-tool="name"
                class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg transition-colors"
                :class="tool === name ? 'bg-blue-600 text-white shadow-sm' : 'hover:bg-gray-100 dark:hover:bg-gray-700'"
                @click="tool = name"
            >
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path :d="ICONS[name]" /></svg>
            </button>

            <template v-if="showStyle">
                <span class="mx-1 h-6 w-px shrink-0 bg-gray-200 dark:bg-gray-700"></span>
                <button
                    v-for="swatch in palette"
                    :key="swatch"
                    type="button"
                    :title="swatch"
                    :aria-label="`Colour ${swatch}`"
                    class="h-6 w-6 shrink-0 rounded-full border border-gray-300 transition-transform dark:border-gray-600"
                    :class="activeColor === swatch ? 'scale-110 ring-2 ring-blue-500 ring-offset-1 dark:ring-offset-gray-800' : 'hover:scale-110'"
                    :style="{ backgroundColor: swatch }"
                    @click="pickColor(swatch)"
                ></button>
            </template>

            <template v-if="showSizes">
                <span class="mx-1 h-6 w-px shrink-0 bg-gray-200 dark:bg-gray-700"></span>
                <button
                    v-for="step in STROKE_SIZES"
                    :key="step"
                    type="button"
                    :title="`Size ${step}`"
                    :aria-label="`Size ${step}`"
                    class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg"
                    :class="size === step ? 'bg-gray-200 dark:bg-gray-700' : 'hover:bg-gray-100 dark:hover:bg-gray-700'"
                    @click="pickSize(step)"
                >
                    <span class="rounded-full bg-current" :style="{ width: `${Math.min(18, step + 3)}px`, height: `${Math.min(18, step + 3)}px` }"></span>
                </button>
            </template>

            <button
                v-if="showFill"
                type="button"
                title="Fill shapes"
                aria-label="Fill shapes"
                class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg"
                :class="fillShapes ? 'bg-gray-200 dark:bg-gray-700' : 'hover:bg-gray-100 dark:hover:bg-gray-700'"
                @click="toggleFill"
            >
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path :d="ICONS.fill" /></svg>
            </button>

            <div v-if="canEdit && tool === 'eraser'" class="flex shrink-0 items-center gap-1" data-testid="whiteboard-eraser-options">
                <span class="mx-1 h-6 w-px shrink-0 bg-gray-200 dark:bg-gray-700"></span>
                <div class="flex rounded-lg bg-gray-100 p-0.5 dark:bg-gray-700" role="group" aria-label="Eraser mode">
                    <button
                        v-for="mode in ERASER_MODES"
                        :key="mode.key"
                        type="button"
                        :title="mode.title"
                        :aria-pressed="eraserMode === mode.key"
                        :data-eraser-mode="mode.key"
                        class="h-8 whitespace-nowrap rounded-md px-2.5 text-xs font-bold transition-colors"
                        :class="eraserMode === mode.key ? 'bg-white text-blue-700 shadow-sm dark:bg-gray-800 dark:text-blue-300' : 'text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-100'"
                        @click="eraserMode = mode.key"
                    >{{ mode.label }}</button>
                </div>
                <label class="flex h-9 shrink-0 items-center gap-2 pl-1" title="Eraser size — [ and ] change it too">
                    <span class="text-xs font-semibold text-gray-400">Size</span>
                    <input
                        v-model.number="eraserSize"
                        type="range"
                        :min="ERASER_MIN"
                        :max="ERASER_MAX"
                        step="2"
                        class="w-24 accent-blue-600"
                        aria-label="Eraser size"
                        data-testid="whiteboard-eraser-size"
                    >
                    <span class="w-10 text-right text-xs font-semibold tabular-nums text-gray-500">{{ eraserSize }}px</span>
                </label>
            </div>

            <div v-if="canEdit && rotatableSelection.length" class="flex shrink-0 items-center gap-1">
                <span class="mx-1 h-6 w-px shrink-0 bg-gray-200 dark:bg-gray-700"></span>
                <button type="button" title="Rotate 90° left (Ctrl+Shift+R)" aria-label="Rotate 90 degrees left" class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700" @click="rotateSelection({ by: -90 })">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path :d="ICONS.rotateLeft" /></svg>
                </button>
                <label class="flex h-9 shrink-0 items-center gap-0.5" title="Angle in degrees — or drag the round handle above the picture">
                    <input
                        type="number"
                        step="1"
                        :value="rotationShown"
                        class="h-8 w-16 rounded-lg border-gray-300 px-1.5 text-center text-sm tabular-nums dark:border-gray-600 dark:bg-gray-800"
                        aria-label="Rotation in degrees"
                        data-testid="whiteboard-rotation"
                        @change="onRotationInput"
                        @keydown.enter="$event.target.blur()"
                    >
                    <span class="text-xs font-semibold text-gray-400">°</span>
                </label>
                <button type="button" title="Rotate 90° right (Ctrl+R)" aria-label="Rotate 90 degrees right" class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700" @click="rotateSelection({ by: 90 })">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path :d="ICONS.rotateRight" /></svg>
                </button>
            </div>

            <template v-if="canEdit">
                <span class="mx-1 h-6 w-px shrink-0 bg-gray-200 dark:bg-gray-700"></span>
                <button type="button" title="Undo (Ctrl+Z)" aria-label="Undo" :disabled="!doc.canUndo.value" class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg hover:bg-gray-100 disabled:opacity-30 dark:hover:bg-gray-700" @click="undo">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path :d="ICONS.undo" /></svg>
                </button>
                <button type="button" title="Redo (Ctrl+Shift+Z)" aria-label="Redo" :disabled="!doc.canRedo.value" class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg hover:bg-gray-100 disabled:opacity-30 dark:hover:bg-gray-700" @click="redo">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path :d="ICONS.redo" /></svg>
                </button>
            </template>

            <template v-if="canEdit && selection.length">
                <button type="button" title="Duplicate (Ctrl+D)" aria-label="Duplicate selection" class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700" @click="duplicateSelection">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path :d="ICONS.duplicate" /></svg>
                </button>
                <button type="button" title="Delete (Del)" aria-label="Delete selection" class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-red-600 hover:bg-red-50 dark:hover:bg-red-900/30" @click="deleteSelection">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path :d="ICONS.trash" /></svg>
                </button>
            </template>

            <slot name="toolbar-end" />
        </div>

        <!-- Zoom controls -->
        <div v-if="!overlay && showZoom" class="pointer-events-auto absolute bottom-3 left-3 z-20 flex items-center gap-0.5 rounded-xl border border-gray-200 bg-white/95 p-1 text-gray-600 shadow-lg dark:border-gray-700 dark:bg-gray-800/95 dark:text-gray-300">
            <button type="button" title="Zoom out (Ctrl+-)" aria-label="Zoom out" class="h-8 w-8 rounded-lg text-lg font-bold leading-none hover:bg-gray-100 dark:hover:bg-gray-700" @click="zoomBy(1 / 1.2)">−</button>
            <button type="button" title="Reset to 100% (Ctrl+0)" class="h-8 min-w-[3.5rem] rounded-lg px-1 text-xs font-bold tabular-nums hover:bg-gray-100 dark:hover:bg-gray-700" data-testid="whiteboard-zoom" @click="resetZoom">{{ Math.round(view.zoom * 100) }}%</button>
            <button type="button" title="Zoom in (Ctrl+=)" aria-label="Zoom in" class="h-8 w-8 rounded-lg text-lg font-bold leading-none hover:bg-gray-100 dark:hover:bg-gray-700" @click="zoomBy(1.2)">+</button>
            <button type="button" title="Fit to content (Shift+1)" aria-label="Fit to content" class="inline-flex h-8 w-8 items-center justify-center rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700" @click="fitToContent()">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path :d="ICONS.fit" /></svg>
            </button>
        </div>

        <slot />
    </div>
</template>
