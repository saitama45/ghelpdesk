<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, shallowRef, watch } from 'vue';
import WhiteboardElements from './WhiteboardElements.vue';
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
    clone,
    constrainShape,
    contentBounds,
    elementBounds,
    exportGroupToPng,
    hitTest,
    measureText,
    resizeElement,
    resizeHandles,
    round1,
    stickyLayout,
    translateElement,
    uid,
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
});

const emit = defineEmits(['open-card', 'drop-card', 'history']);

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
};

const MIN_ZOOM = 0.1;
const MAX_ZOOM = 5;
const LASER_MS = 900;
const ERASER_CURSOR = `url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='22' height='22'><circle cx='11' cy='11' r='9' fill='white' fill-opacity='0.6' stroke='black' stroke-width='1.5'/></svg>") 11 11, auto`;
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
        if (element.type === 'card') return null;
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
    || selectedElements.value.some((element) => element.type !== 'card')
));
const showSizes = computed(() => showStyle.value && !usesStickyPalette.value);
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
            return element ? { id, box: elementBounds(element, props.cardsById) } : null;
        })
        .filter(Boolean);
});

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

const eraseAlong = (from, to) => {
    const hits = gesture.hits;
    const before = hits.size;
    const steps = Math.max(1, Math.ceil(Math.hypot(to.x - from.x, to.y - from.y) / tolerance.value));
    for (let step = 0; step <= steps; step++) {
        const x = from.x + ((to.x - from.x) * step) / steps;
        const y = from.y + ((to.y - from.y) * step) / steps;
        for (const element of elements.value) {
            if (!hits.has(element.id) && hitTest(element, x, y, tolerance.value * 1.5, props.cardsById)) hits.add(element.id);
        }
    }
    if (hits.size !== before) faded.value = new Set(hits);
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
            startGesture('erase', { last: point, hits: new Set() });
            eraseAlong(point, point);
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
        case 'move':
        case 'resize':
            if (moved && (current.kind === 'resize' || current.moved)) {
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
    if (!gesture) laserHead.value = null;
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

const onDragOver = (event) => {
    if (!canEdit.value || !event.dataTransfer?.types?.includes(CARD_DRAG_TYPE)) return;
    event.preventDefault();
    event.dataTransfer.dropEffect = 'copy';
};

const onDrop = (event) => {
    const cardId = event.dataTransfer?.getData(CARD_DRAG_TYPE);
    if (!canEdit.value || !cardId) return;
    event.preventDefault();
    const point = toWorld(event.clientX, event.clientY);
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

    const match = Object.entries(TOOL_DEFS).find(([name, def]) => def.key === key && availableTools.value.includes(name));
    if (match) tool.value = match[0];
};

const onKeyUp = (event) => {
    if (event.key === ' ') spaceHeld.value = false;
};

watch(tool, (next) => {
    if (next !== 'select') selection.value = [];
    if (next !== 'laser') laserHead.value = null;
});

const cursor = computed(() => {
    if (gestureKind.value === 'pan') return 'grabbing';
    if (spaceHeld.value || tool.value === 'hand') return 'grab';
    return {
        select: 'default',
        pointer: 'default',
        text: 'text',
        laser: 'none',
        eraser: ERASER_CURSOR,
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
    nextTick(() => fitToContent());
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeyDown);
    window.removeEventListener('keyup', onKeyUp);
    if (laserFrame) cancelAnimationFrame(laserFrame);
});

defineExpose({
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

                <g v-for="frame in selectionFrames" :key="`sel-${frame.id}`" pointer-events="none">
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
                <rect
                    v-if="marquee"
                    :x="marquee.x" :y="marquee.y" :width="marquee.w" :height="marquee.h"
                    fill="#3b82f6" fill-opacity="0.08" stroke="#3b82f6" :stroke-width="1 / view.zoom"
                    pointer-events="none"
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
