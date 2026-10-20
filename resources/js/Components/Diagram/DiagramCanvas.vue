<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, shallowRef, watch } from 'vue';
import DiagramElements from './DiagramElements.vue';
import { clone, round1, uid } from '@/lib/sceneDocument';
import { FONT_FAMILY, wrapText } from '@/lib/svgText';
import { exportGroupToPng, exportGroupToSvg } from '@/lib/svgExport';
import {
    HANDLE_CURSORS,
    RESIZE_HANDLES,
    boxContains,
    boxesIntersect,
    contentBounds,
    edgeGeometry,
    elementBounds,
    flatten,
    handlePoint,
    hitEdge,
    hitNode,
    inBox,
    isContainer,
    nodeBounds,
    nodeBox,
    nodesIndex,
    pairs,
    resizeBox,
    resizeRotated,
    scaleElements,
    snapMove,
    translateElement,
    unionBounds,
} from './geometry';
import { SHAPES, SHAPE_DRAG_TYPE, makeEdge, makeNode, nodeCenter, nodePorts, nodeTextBox, rotatePoint } from './shapes';
import {
    arrange,
    connectNew,
    copySelection,
    deleteSelection,
    duplicateSelection,
    expandGroups,
    freeSpot,
    groupSelection,
    normalizeAngle,
    paste,
    rotateSelection,
    ungroupSelection,
    wholeGroup,
} from './actions';
import { compressImage } from '@/Composables/useImageCompressor';

const props = defineProps({
    doc: { type: Object, required: true },
    pageId: { type: String, required: true },
    selection: { type: Array, default: () => [] },
    readonly: { type: Boolean, default: false },
    snap: { type: Boolean, default: true },
    // Style for connectors drawn from now on (routing, arrows…), chosen in the toolbar.
    edgeStyle: { type: Object, default: () => ({}) },
    // Present mode: a laser dot follows the pointer instead of editing.
    laser: { type: Boolean, default: false },
    keyboard: { type: Boolean, default: true },
    // async (File) => { url, width, height } — stores a picture for an image shape.
    uploadImage: { type: Function, default: null },
});

const emit = defineEmits(['update:selection', 'history', 'error']);

const GRID = 10;
const MIN_ZOOM = 0.1;
const MAX_ZOOM = 4;
const LASER_MS = 900;
const patternId = `dg-grid-${uid('')}`;

const svgEl = ref(null);
const contentGroup = ref(null);
const textInput = ref(null);

const page = computed(() => props.doc.pageById(props.pageId));
const elements = computed(() => page.value?.elements || []);
const nodes = computed(() => nodesIndex(elements.value));
const geometries = computed(() => new Map(elements.value
    .filter((element) => element.type === 'edge')
    .map((edge) => [edge.id, edgeGeometry(edge, nodes.value)])));
const selected = computed(() => new Set(props.selection));
const editable = computed(() => !props.readonly);
const background = computed(() => page.value?.background || '#ffffff');
const showGrid = computed(() => page.value?.grid !== false);

const setSelection = (ids) => emit('update:selection', [...new Set(ids)]);

const view = reactive({ x: 40, y: 40, zoom: 1 });
const overrides = shallowRef(null);
const draft = ref(null);
const dropTarget = ref(null);
const guides = ref([]);
const marquee = ref(null);
const hoverNodeId = ref(null);
const hoverCursor = ref('default');
const editing = ref(null);
const spaceHeld = ref(false);
const gestureKind = ref(null);
const laserTrail = ref([]);
const laserNow = ref(0);
const laserHead = ref(null);

let gesture = null;
let pinch = null;
let laserFrame = null;
const pointers = new Map();
const viewsByPage = new Map();

// ── Coordinates & view ───────────────────────────────────────────────────────

const svgRect = () => svgEl.value.getBoundingClientRect();
const toWorld = (clientX, clientY) => {
    const r = svgRect();
    return { x: (clientX - r.left - view.x) / view.zoom, y: (clientY - r.top - view.y) / view.zoom };
};
const transform = computed(() => `translate(${view.x} ${view.y}) scale(${view.zoom})`);
const px = (n) => n / view.zoom;
const clampZoom = (zoom) => Math.min(MAX_ZOOM, Math.max(MIN_ZOOM, zoom));
const snapValue = (value, event) => (props.snap && !event?.altKey ? Math.round(value / GRID) * GRID : round1(value));

const zoomAt = (clientX, clientY, factor) => {
    const r = svgRect();
    const sx = clientX - r.left;
    const sy = clientY - r.top;
    const zoom = clampZoom(view.zoom * factor);
    view.x = sx - (sx - view.x) * (zoom / view.zoom);
    view.y = sy - (sy - view.y) * (zoom / view.zoom);
    view.zoom = zoom;
};

const zoomBy = (factor) => {
    const r = svgRect();
    zoomAt(r.left + r.width / 2, r.top + r.height / 2, factor);
};

const resetZoom = () => zoomBy(1 / view.zoom);

const fitToContent = ({ maxZoom = 1 } = {}) => {
    if (!svgEl.value) return;
    const bounds = contentBounds(elements.value);
    if (!bounds) {
        Object.assign(view, { x: 40, y: 40, zoom: 1 });
        return;
    }
    const r = svgRect();
    const pad = 48;
    const zoom = clampZoom(Math.min((r.width - pad * 2) / Math.max(bounds.w, 1), (r.height - pad * 2) / Math.max(bounds.h, 1), maxZoom));
    view.zoom = zoom;
    view.x = (r.width - bounds.w * zoom) / 2 - bounds.x * zoom;
    view.y = (r.height - bounds.h * zoom) / 2 - bounds.y * zoom;
};

const viewportCenter = () => {
    const r = svgRect();
    return toWorld(r.left + r.width / 2, r.top + r.height / 2);
};

watch(() => props.pageId, (next, previous) => {
    if (previous) viewsByPage.set(previous, { ...view });
    cancelGesture();
    commitEdit();
    setSelection([]);
    const saved = viewsByPage.get(next);
    if (saved) Object.assign(view, saved);
    else nextTick(() => fitToContent());
});

// ── Live (overridden) elements for overlays ──────────────────────────────────

const live = (id) => overrides.value?.get(id) || elements.value.find((element) => element.id === id) || null;
const liveNodes = computed(() => nodesIndex(elements.value.map((element) => overrides.value?.get(element.id) || element)));

const singleNode = computed(() => {
    if (props.selection.length !== 1) return null;
    const element = live(props.selection[0]);
    return element?.type === 'node' ? element : null;
});

const singleEdge = computed(() => {
    if (props.selection.length !== 1) return null;
    const element = live(props.selection[0]);
    return element?.type === 'edge' ? element : null;
});

// ── Hit testing ──────────────────────────────────────────────────────────────

const orderedNodes = computed(() => {
    const list = elements.value.filter((element) => element.type === 'node');
    return [...list.filter((n) => !isContainer(n)).reverse(), ...list.filter((n) => isContainer(n)).reverse()];
});

const hitAt = (point, { nodesOnly = false, exclude = null } = {}) => {
    if (!nodesOnly) {
        const edges = elements.value.filter((element) => element.type === 'edge');
        for (let i = edges.length - 1; i >= 0; i--) {
            const geometry = geometries.value.get(edges[i].id);
            if (geometry && hitEdge(geometry, edges[i], point.x, point.y, px(6))) return edges[i];
        }
    }
    for (const node of orderedNodes.value) {
        if (node.id !== exclude && hitNode(node, point.x, point.y, nodesOnly ? 0 : px(2))) return node;
    }
    return null;
};

const portAt = (node, point, radius = px(8)) => Object.values(nodePorts(node))
    .map((port) => ({ ...port, distance: Math.hypot(port.x - point.x, port.y - point.y) }))
    .filter((port) => port.distance <= radius)
    .sort((a, b) => a.distance - b.distance)[0] || null;

const hoverPorts = computed(() => {
    if (!editable.value || gestureKind.value === 'move' || gestureKind.value === 'resize') return [];
    const id = dropTarget.value?.nodeId || hoverNodeId.value;
    const node = id ? liveNodes.value[id] : null;
    return node ? Object.values(nodePorts(node)).map((port) => ({ ...port, nodeId: id, active: dropTarget.value?.port === port.key })) : [];
});

const dropFrame = computed(() => {
    const node = dropTarget.value ? liveNodes.value[dropTarget.value.nodeId] : null;
    return node ? { x: node.x - px(3), y: node.y - px(3), width: node.w + px(6), height: node.h + px(6) } : null;
});

const resizeHandles = computed(() => {
    if (!editable.value || !singleNode.value || editing.value) return [];
    const node = singleNode.value;
    const box = nodeBox(node);
    const center = nodeCenter(node);
    return RESIZE_HANDLES.map((key) => ({ key, ...rotatePoint(handlePoint(box, key), center, node.rotation || 0) }));
});

const rotateHandle = computed(() => {
    if (!editable.value || !singleNode.value || editing.value || gestureKind.value === 'move') return null;
    const node = singleNode.value;
    const center = nodeCenter(node);
    return rotatePoint({ x: center.x, y: node.y - px(24) }, center, node.rotation || 0);
});

// A whole group selected: one frame around it, and its handles scale every member.
const selectedGroup = computed(() => wholeGroup(elements.value, props.selection));
const groupFrame = computed(() => {
    if (!selectedGroup.value) return null;
    return unionBounds(props.selection.map((id) => live(id)).filter(Boolean).map((element) => elementBounds(element, liveNodes.value)));
});
const groupHandles = computed(() => {
    if (!editable.value || !groupFrame.value || editing.value) return [];
    return RESIZE_HANDLES.filter((key) => key.length === 2).map((key) => ({ key, ...handlePoint(groupFrame.value, key) }));
});

const QUICK_OFFSET = 26;
const quickArrows = computed(() => {
    if (!editable.value || !singleNode.value || gestureKind.value || editing.value) return [];
    const n = nodeBounds(singleNode.value);
    const cx = n.x + n.w / 2;
    const cy = n.y + n.h / 2;
    const o = px(QUICK_OFFSET);
    return [
        { dir: 'n', x: cx, y: n.y - o, angle: -90 },
        { dir: 'e', x: n.x + n.w + o, y: cy, angle: 0 },
        { dir: 's', x: cx, y: n.y + n.h + o, angle: 90 },
        { dir: 'w', x: n.x - o, y: cy, angle: 180 },
    ];
});

const edgeHandles = computed(() => {
    if (!editable.value || !singleEdge.value || editing.value) return [];
    const edge = singleEdge.value;
    const geometry = edgeGeometry(edge, liveNodes.value);
    const route = geometry.route.points;
    const start = route[0];
    const end = route[route.length - 1];
    const waypoints = pairs(edge.points);
    const control = [start, ...waypoints, end];
    return [
        { kind: 'end', end: 'source', ...start },
        { kind: 'end', end: 'target', ...end },
        ...waypoints.map((point, index) => ({ kind: 'waypoint', index, ...point })),
        ...control.slice(1).map((point, index) => ({
            kind: 'virtual',
            index,
            x: (control[index].x + point.x) / 2,
            y: (control[index].y + point.y) / 2,
        })),
    ];
});

const handleAt = (list, point, radius = px(7)) => list.find((handle) => Math.hypot(handle.x - point.x, handle.y - point.y) <= radius) || null;

// ── Selection overlay ────────────────────────────────────────────────────────

const selectionFrames = computed(() => props.selection
    .map((id) => live(id))
    .filter((element) => element?.type === 'node')
    .map((node) => ({
        id: node.id,
        w: node.w,
        h: node.h,
        transform: `translate(${node.x} ${node.y})${node.rotation ? ` rotate(${node.rotation} ${node.w / 2} ${node.h / 2})` : ''}`,
    })));

const selectedEdgePaths = computed(() => props.selection
    .map((id) => live(id))
    .filter((element) => element?.type === 'edge')
    .map((edge) => ({ id: edge.id, d: edgeGeometry(edge, liveNodes.value).d, width: (edge.strokeWidth || 1.5) + px(6) })));

// ── Editing text ─────────────────────────────────────────────────────────────

const beginEdit = (element, isNew = false, initialText = null) => {
    if (!editable.value) return;
    editing.value = {
        id: element.id,
        kind: element.type,
        pageId: props.pageId,
        isNew,
        element: clone(element),
        text: initialText ?? ((element.type === 'node' ? element.text : element.label) || ''),
    };
    // Focus on a later task: the pointerdown that started this is followed by a mousedown
    // whose default action moves focus to the body, which would blur (and commit) the editor.
    setTimeout(() => {
        const input = textInput.value;
        if (!input) return;
        input.focus();
        if (initialText === null) input.select();
        else input.setSelectionRange(input.value.length, input.value.length);
    }, 0);
};

const commitEdit = () => {
    const state = editing.value;
    if (!state) return;
    editing.value = null;
    const text = state.text.replace(/\s+$/, '');

    if (state.kind === 'node') {
        if (state.isNew) {
            if (text) {
                props.doc.commit(state.pageId, [{ id: state.id, after: { ...state.element, text } }]);
                setSelection([state.id]);
            }
        } else if (text !== (state.element.text || '')) {
            props.doc.commit(state.pageId, [{ id: state.id, after: { ...live(state.id) || state.element, text } }]);
        }
    } else if (text !== (state.element.label || '')) {
        props.doc.commit(state.pageId, [{ id: state.id, after: { ...live(state.id) || state.element, label: text } }]);
    }
};

const onEditorKeydown = (event) => {
    if (event.key === 'Escape' || (event.key === 'Enter' && (event.ctrlKey || event.metaKey))) {
        event.preventDefault();
        commitEdit();
    }
};

const hiddenText = computed(() => (editing.value && !editing.value.isNew ? new Set([editing.value.id]) : null));
const editingGhost = computed(() => (editing.value?.isNew ? [{ ...editing.value.element, text: '' }] : null));

const editorStyle = computed(() => {
    const state = editing.value;
    if (!state) return {};
    const zoom = view.zoom;

    if (state.kind === 'edge') {
        const edge = live(state.id) || state.element;
        const geometry = edgeGeometry({ ...edge, label: state.text || ' ' }, liveNodes.value);
        const box = geometry.label;
        const size = edge.fontSize || 12;
        const width = Math.max(120, box.w + 20);
        return {
            left: `${view.x + (box.x + box.w / 2 - width / 2) * zoom}px`,
            top: `${view.y + box.y * zoom}px`,
            width: `${width * zoom}px`,
            height: `${(box.h + 6) * zoom}px`,
            fontSize: `${size * zoom}px`,
            lineHeight: 1.25,
            textAlign: 'center',
            color: edge.fontColor,
            background: '#ffffff',
            fontFamily: FONT_FAMILY,
        };
    }

    const node = state.isNew ? state.element : (live(state.id) || state.element);
    const box = nodeTextBox(node);
    const size = node.fontSize || 14;
    const width = box.vertical ? Math.max(160, node.h) : box.w;
    const lines = wrapText(state.text || ' ', Math.max(width - 8, size), size, node.bold ? 700 : 400).length;
    const textHeight = lines * size * 1.25;
    const height = box.vertical ? textHeight + 8 : Math.max(box.h, textHeight + 8);
    const padTop = box.vertical || node.valign === 'top' ? 4 : node.valign === 'bottom' ? height - textHeight - 4 : (height - textHeight) / 2;
    return {
        left: `${view.x + box.x * zoom}px`,
        top: `${view.y + box.y * zoom}px`,
        width: `${width * zoom}px`,
        height: `${height * zoom}px`,
        paddingTop: `${Math.max(0, padTop) * zoom}px`,
        paddingLeft: `${4 * zoom}px`,
        paddingRight: `${4 * zoom}px`,
        fontSize: `${size * zoom}px`,
        fontWeight: node.bold ? 700 : 400,
        fontStyle: node.italic ? 'italic' : 'normal',
        lineHeight: 1.25,
        textAlign: node.align || 'center',
        color: node.fontColor,
        background: box.vertical ? '#ffffff' : 'transparent',
        fontFamily: FONT_FAMILY,
    };
});

// ── Laser (present mode) ─────────────────────────────────────────────────────

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

// ── Gestures ─────────────────────────────────────────────────────────────────

const startGesture = (kind, data = {}) => {
    gesture = { kind, ...data };
    gestureKind.value = kind;
};

const cancelGesture = () => {
    gesture = null;
    gestureKind.value = null;
    overrides.value = null;
    draft.value = null;
    dropTarget.value = null;
    guides.value = [];
    marquee.value = null;
};

/** Store where each end currently sits, so a connector whose shape vanishes still draws. */
const withFreeEnds = (edge) => {
    const route = edgeGeometry(edge, liveNodes.value).route.points;
    return {
        ...edge,
        x1: round1(route[0].x),
        y1: round1(route[0].y),
        x2: round1(route[route.length - 1].x),
        y2: round1(route[route.length - 1].y),
    };
};

const startMove = (ids, origin) => {
    const byId = new Map(elements.value.map((element) => [element.id, element]));
    const moving = new Set(ids);

    // Shapes inside a dragged container travel with it.
    for (const id of ids) {
        const container = byId.get(id);
        if (container?.type !== 'node' || !isContainer(container)) continue;
        for (const node of Object.values(nodes.value)) {
            if (node.id !== id && boxContains(nodeBox(container), nodeBox(node))) moving.add(node.id);
        }
    }
    // A connector whose two ends both move keeps its bends with them.
    for (const element of elements.value) {
        if (element.type === 'edge' && moving.has(element.source) && moving.has(element.target)) moving.add(element.id);
    }

    const base = new Map([...moving].map((id) => [id, clone(byId.get(id))]).filter(([, element]) => element));
    const box = unionBounds([...base.values()].filter((element) => element.type === 'node').map(nodeBox));
    const others = Object.values(nodes.value).filter((node) => !moving.has(node.id)).map(nodeBox);
    startGesture('move', { origin, base, box, others, moved: false });
};

const startConnect = (nodeId, port, point) => {
    const node = nodes.value[nodeId];
    const from = nodePorts(node)[port] || { x: point.x, y: point.y };
    draft.value = makeEdge({
        ...props.edgeStyle,
        source: nodeId,
        sourcePort: port,
        x1: round1(from.x),
        y1: round1(from.y),
        x2: round1(point.x),
        y2: round1(point.y),
    });
    startGesture('connect', { origin: point, sourceId: nodeId, moved: false });
};

/** Attach the end of a connector being drawn/re-routed to whatever shape is under the pointer. */
const aimEnd = (edge, end, point, exclude) => {
    const target = hitAt(point, { nodesOnly: true, exclude });
    const next = { ...edge };
    if (target) {
        const port = portAt(target, point, px(14));
        next[end] = target.id;
        next[`${end}Port`] = port?.key || null;
        dropTarget.value = { nodeId: target.id, port: port?.key || null };
    } else {
        next[end] = null;
        next[`${end}Port`] = null;
        if (end === 'source') Object.assign(next, { x1: round1(point.x), y1: round1(point.y) });
        else Object.assign(next, { x2: round1(point.x), y2: round1(point.y) });
        dropTarget.value = null;
    }
    return next;
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

const onPointerDown = (event) => {
    commitEdit();
    svgEl.value.setPointerCapture?.(event.pointerId);
    pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });

    if (pointers.size === 2 && event.pointerType === 'touch') {
        cancelGesture();
        startPinch();
        return;
    }
    if (pointers.size > 1 || pinch) return;

    const point = toWorld(event.clientX, event.clientY);

    if (props.laser) {
        startGesture('laser');
        laserHead.value = point;
        pushLaser(point);
        return;
    }

    if (event.button === 1 || event.button === 2 || spaceHeld.value || props.readonly) {
        startGesture('pan', { sx: event.clientX, sy: event.clientY, vx: view.x, vy: view.y });
        return;
    }

    const edgeHandle = handleAt(edgeHandles.value.filter((handle) => handle.kind !== 'virtual'), point)
        || handleAt(edgeHandles.value.filter((handle) => handle.kind === 'virtual'), point, px(6));
    if (edgeHandle) {
        const base = clone(singleEdge.value);
        if (edgeHandle.kind === 'end') startGesture('edgeEnd', { id: base.id, end: edgeHandle.end, base, origin: point, moved: false });
        else startGesture(edgeHandle.kind === 'waypoint' ? 'waypoint' : 'newWaypoint', { id: base.id, index: edgeHandle.index, base, origin: point, moved: false });
        return;
    }

    if (rotateHandle.value && Math.hypot(rotateHandle.value.x - point.x, rotateHandle.value.y - point.y) <= px(8)) {
        const base = clone(singleNode.value);
        startGesture('rotate', { id: base.id, base, center: nodeCenter(base) });
        return;
    }

    const resize = handleAt(resizeHandles.value, point);
    if (resize) {
        startGesture('resize', { id: singleNode.value.id, key: resize.key, base: clone(singleNode.value) });
        return;
    }

    const groupHandle = handleAt(groupHandles.value, point);
    if (groupHandle) {
        const base = new Map(props.selection.map((id) => [id, clone(live(id))]).filter(([, element]) => element));
        startGesture('groupResize', { key: groupHandle.key, base, from: { ...groupFrame.value } });
        return;
    }

    const quick = handleAt(quickArrows.value, point, px(11));
    if (quick) {
        startGesture('quick', { nodeId: singleNode.value.id, dir: quick.dir, origin: point });
        return;
    }

    const hoverNode = hoverNodeId.value ? nodes.value[hoverNodeId.value] : null;
    const port = hoverNode ? portAt(hoverNode, point, px(6)) : null;
    if (port) {
        startConnect(hoverNode.id, port.key, point);
        return;
    }

    const hit = hitAt(point);
    if (!hit) {
        if (event.pointerType === 'touch') {
            startGesture('pan', { sx: event.clientX, sy: event.clientY, vx: view.x, vy: view.y });
            return;
        }
        const additive = event.shiftKey || event.ctrlKey || event.metaKey;
        if (!additive) setSelection([]);
        startGesture('marquee', { origin: point, additive });
        marquee.value = { x: point.x, y: point.y, w: 0, h: 0 };
        return;
    }

    // A grouped shape brings its whole group; clicking a selected group again picks the one
    // shape under the pointer (on release, if it was a click and not a drag) — like draw.io.
    const members = hit.groupId ? expandGroups(elements.value, [hit.id]) : [hit.id];
    let ids = props.selection;
    let drill = null;
    if (event.shiftKey || event.ctrlKey || event.metaKey) {
        if (selected.value.has(hit.id)) {
            const dropped = new Set(members);
            setSelection(ids.filter((id) => !dropped.has(id)));
            return;
        }
        ids = [...ids, ...members];
        setSelection(ids);
    } else if (!selected.value.has(hit.id)) {
        ids = members;
        setSelection(ids);
    } else if (hit.groupId && selectedGroup.value === hit.groupId) {
        drill = hit.id;
    }
    startMove(ids, point);
    gesture.drill = drill;
};

const updateHover = (point) => {
    if (!editable.value || props.laser) {
        hoverNodeId.value = null;
        hoverCursor.value = props.readonly ? 'grab' : 'default';
        return;
    }
    const current = hoverNodeId.value ? nodes.value[hoverNodeId.value] : null;
    const hit = hitAt(point, { nodesOnly: true });
    hoverNodeId.value = hit?.id || (current && inBox(nodeBox(current), point.x, point.y, px(12)) ? current.id : null);

    const resize = handleAt(resizeHandles.value, point) || handleAt(groupHandles.value, point);
    const hoverNode = hoverNodeId.value ? nodes.value[hoverNodeId.value] : null;
    if (rotateHandle.value && Math.hypot(rotateHandle.value.x - point.x, rotateHandle.value.y - point.y) <= px(8)) hoverCursor.value = 'grab';
    else if (resize) hoverCursor.value = HANDLE_CURSORS[resize.key];
    else if (handleAt(edgeHandles.value, point) || handleAt(quickArrows.value, point, px(11))) hoverCursor.value = 'pointer';
    else if (hoverNode && portAt(hoverNode, point, px(6))) hoverCursor.value = 'crosshair';
    else if (hitAt(point)) hoverCursor.value = 'move';
    else hoverCursor.value = 'default';
};

const onPointerMove = (event) => {
    if (pointers.has(event.pointerId)) pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });
    if (pinch) {
        if (pointers.size === 2) updatePinch();
        return;
    }

    const point = toWorld(event.clientX, event.clientY);

    if (props.laser) {
        laserHead.value = point;
        if (gesture?.kind === 'laser' || event.pointerType === 'mouse') pushLaser(point);
    }
    if (!gesture) {
        updateHover(point);
        return;
    }

    const travelled = gesture.origin ? Math.hypot(point.x - gesture.origin.x, point.y - gesture.origin.y) * view.zoom : 0;

    switch (gesture.kind) {
        case 'pan':
            view.x = gesture.vx + event.clientX - gesture.sx;
            view.y = gesture.vy + event.clientY - gesture.sy;
            break;
        case 'move': {
            if (!gesture.moved && travelled < 3) return;
            gesture.moved = true;
            let dx = point.x - gesture.origin.x;
            let dy = point.y - gesture.origin.y;
            guides.value = [];
            if (gesture.box && props.snap && !event.altKey) {
                const snapped = snapMove(gesture.box, dx, dy, gesture.others, { grid: GRID, threshold: px(5) });
                ({ dx, dy } = snapped);
                guides.value = snapped.guides;
            }
            overrides.value = new Map([...gesture.base].map(([id, element]) => [id, translateElement(element, dx, dy)]));
            break;
        }
        case 'resize': {
            const box = resizeRotated(gesture.base, gesture.key, point.x, point.y, {
                keepRatio: event.shiftKey,
                grid: props.snap && !event.altKey ? GRID : 0,
            });
            overrides.value = new Map([[gesture.id, { ...gesture.base, ...box }]]);
            break;
        }
        case 'rotate': {
            const raw = (Math.atan2(point.y - gesture.center.y, point.x - gesture.center.x) * 180) / Math.PI + 90;
            const angle = normalizeAngle(props.snap && !event.altKey ? Math.round(raw / 15) * 15 : raw);
            overrides.value = new Map([[gesture.id, { ...gesture.base, rotation: angle }]]);
            break;
        }
        case 'groupResize': {
            const to = resizeBox(gesture.from, gesture.key, point.x, point.y, {
                keepRatio: event.shiftKey,
                grid: props.snap && !event.altKey ? GRID : 0,
            });
            overrides.value = new Map(scaleElements([...gesture.base.values()], gesture.from, to).map((element) => [element.id, element]));
            break;
        }
        case 'quick':
            if (travelled < 6) return;
            startConnect(gesture.nodeId, gesture.dir, point);
            gesture.moved = true;
        // falls through: keep drawing as a connection
        case 'connect': {
            if (travelled >= 4) gesture.moved = true;
            draft.value = aimEnd(draft.value, 'target', point, gesture.sourceId);
            break;
        }
        case 'edgeEnd': {
            if (travelled >= 3) gesture.moved = true;
            const other = gesture.end === 'source' ? gesture.base.target : gesture.base.source;
            overrides.value = new Map([[gesture.id, aimEnd(gesture.base, gesture.end, point, other)]]);
            break;
        }
        case 'waypoint':
        case 'newWaypoint': {
            if (!gesture.moved && travelled < 3) return;
            gesture.moved = true;
            const points = pairs(gesture.base.points);
            const at = { x: snapValue(point.x, event), y: snapValue(point.y, event) };
            if (gesture.kind === 'waypoint') points[gesture.index] = at;
            else points.splice(gesture.index, 0, at);
            overrides.value = new Map([[gesture.id, { ...gesture.base, points: flatten(points) }]]);
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
    const preview = overrides.value;
    const edge = draft.value;
    const box = marquee.value;
    cancelGesture();

    switch (current.kind) {
        case 'move':
        case 'resize':
        case 'rotate':
        case 'groupResize':
            if (preview && (current.kind !== 'move' || current.moved)) {
                props.doc.commit(props.pageId, [...preview].map(([id, after]) => ({ id, after })));
            } else if (current.kind === 'move' && !current.moved && current.drill) {
                setSelection([current.drill]);
            }
            break;
        case 'quick': {
            const id = connectNew(props.doc, props.pageId, nodes.value[current.nodeId], current.dir, { edgeStyle: props.edgeStyle });
            setSelection([id]);
            break;
        }
        case 'connect':
            if (current.moved && edge && (edge.target || Math.hypot(edge.x2 - edge.x1, edge.y2 - edge.y1) > 8)) {
                const finished = withFreeEnds(edge);
                props.doc.commit(props.pageId, [{ id: finished.id, after: finished }]);
                setSelection([finished.id]);
            }
            break;
        case 'edgeEnd':
        case 'waypoint':
        case 'newWaypoint':
            if (preview && current.moved) {
                const [[id, after]] = [...preview];
                props.doc.commit(props.pageId, [{ id, after: withFreeEnds(after) }]);
            }
            break;
        case 'marquee':
            if (box && (box.w > 2 || box.h > 2)) {
                const ids = elements.value
                    .filter((element) => boxesIntersect(elementBounds(element, nodes.value), box))
                    .filter((element) => element.type === 'edge' || !isContainer(element) || boxContains(box, nodeBox(element)))
                    .map((element) => element.id);
                const grouped = expandGroups(elements.value, ids);
                setSelection(current.additive ? [...props.selection, ...grouped] : grouped);
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
    hoverNodeId.value = null;
    laserHead.value = null;
};

const onDoubleClick = (event) => {
    if (!editable.value || props.laser) return;
    const point = toWorld(event.clientX, event.clientY);

    const waypoint = handleAt(edgeHandles.value.filter((handle) => handle.kind === 'waypoint'), point);
    if (waypoint) {
        const edge = singleEdge.value;
        const points = pairs(edge.points);
        points.splice(waypoint.index, 1);
        props.doc.commit(props.pageId, [{ id: edge.id, after: withFreeEnds({ ...edge, points: flatten(points) }) }]);
        return;
    }

    const hit = hitAt(point);
    if (hit) {
        setSelection([hit.id]);
        beginEdit(hit);
        return;
    }
    beginEdit(makeNode('text', snapValue(point.x - 50), snapValue(point.y - 15), { text: '' }), true);
};

const onWheel = (event) => {
    event.preventDefault();
    if (event.ctrlKey || event.metaKey) {
        const delta = Math.max(-50, Math.min(50, event.deltaY));
        zoomAt(event.clientX, event.clientY, Math.exp(-delta * 0.008));
    } else {
        view.x -= event.shiftKey && !event.deltaX ? event.deltaY : event.deltaX;
        view.y -= event.shiftKey && !event.deltaX ? 0 : event.deltaY;
    }
};

// ── Inserting shapes ─────────────────────────────────────────────────────────

/**
 * Library click: with one shape selected, place the new one beside it and connect them
 * (like draw.io); otherwise drop it at the centre of the view.
 */
const insertShape = (shapeKey, at = null) => {
    if (!editable.value) return;
    if (!at && singleNode.value && !isContainer(singleNode.value) && !SHAPES[shapeKey]?.container) {
        const id = connectNew(props.doc, props.pageId, singleNode.value, 'e', { shapeKey, edgeStyle: props.edgeStyle });
        setSelection([id]);
        return;
    }
    const shape = SHAPES[shapeKey] || SHAPES.rectangle;
    const center = at || viewportCenter();
    const spot = freeSpot(elements.value, snapValue(center.x - shape.w / 2), snapValue(center.y - shape.h / 2));
    const node = makeNode(shapeKey, spot.x, spot.y);
    props.doc.commit(props.pageId, [{ id: node.id, after: node }]);
    setSelection([node.id]);
};

// ── Pictures (image shapes) ──────────────────────────────────────────────────

const uploading = ref(0);
const RASTER = /^image\/(png|jpe?g|gif|webp)$/;

/** Upload pictures (auto-compressed first) and drop them as image shapes at `at` / the view centre. */
const insertImages = async (files, at = null) => {
    if (!editable.value || !props.uploadImage) return;
    const pictures = [...files].filter((file) => RASTER.test(file.type));
    if (!pictures.length) {
        emit('error', 'Only PNG, JPG, GIF or WebP pictures can be added.');
        return;
    }

    const pageId = props.pageId;
    const origin = at || viewportCenter();
    const added = [];
    uploading.value += pictures.length;

    for (const [index, original] of pictures.entries()) {
        try {
            const { file } = await compressImage(original);
            const { url, width, height } = await props.uploadImage(file);
            const scale = Math.min(1, 320 / Math.max(width || 160, 1), 320 / Math.max(height || 120, 1));
            const w = Math.round((width || 160) * scale);
            const h = Math.round((height || 120) * scale);
            added.push(makeNode('image', snapValue(origin.x - w / 2 + index * 24), snapValue(origin.y - h / 2 + index * 24), { w, h, src: url }));
        } catch (error) {
            emit('error', error?.response?.data?.message || `Could not add ${original.name || 'the picture'}.`);
        } finally {
            uploading.value--;
        }
    }

    if (added.length) {
        props.doc.commit(pageId, added.map((node) => ({ id: node.id, after: node })));
        if (pageId === props.pageId) setSelection(added.map((node) => node.id));
    }
};

const onPaste = (event) => {
    if (!props.keyboard || !editable.value || props.laser) return;
    const target = event.target;
    if (['INPUT', 'TEXTAREA', 'SELECT'].includes(target?.tagName) || target?.isContentEditable) return;
    if (document.querySelector('dialog[open]')) return;

    event.preventDefault();
    const pictures = [...(event.clipboardData?.files || [])].filter((file) => file.type.startsWith('image/'));
    if (pictures.length) {
        insertImages(pictures);
        return;
    }
    const ids = paste(props.doc, props.pageId);
    if (ids.length) setSelection(ids);
};

const onDragOver = (event) => {
    const types = event.dataTransfer?.types || [];
    if (!editable.value || !(types.includes(SHAPE_DRAG_TYPE) || types.includes('Files'))) return;
    event.preventDefault();
    event.dataTransfer.dropEffect = 'copy';
};

const onDrop = (event) => {
    if (!editable.value) return;
    const point = toWorld(event.clientX, event.clientY);
    const files = event.dataTransfer?.files;
    if (files?.length) {
        event.preventDefault();
        insertImages(files, point);
        return;
    }
    const shapeKey = event.dataTransfer?.getData(SHAPE_DRAG_TYPE);
    if (!shapeKey) return;
    event.preventDefault();
    insertShape(shapeKey, point);
};

// ── Keyboard ─────────────────────────────────────────────────────────────────

const undo = () => {
    const entry = props.doc.undo();
    if (entry) emit('history', entry);
};
const redo = () => {
    const entry = props.doc.redo();
    if (entry) emit('history', entry);
};

const nudge = (dx, dy) => {
    const items = props.selection.map((id) => live(id)).filter(Boolean);
    if (!items.length) return;
    props.doc.commit(props.pageId, items.map((element) => ({ id: element.id, after: translateElement(element, dx, dy) })));
};

const onKeyDown = (event) => {
    if (!props.keyboard) return;
    const target = event.target;
    if (['INPUT', 'TEXTAREA', 'SELECT'].includes(target?.tagName) || target?.isContentEditable) return;
    if (document.querySelector('dialog[open]')) return;

    const key = event.key;
    const lower = key.toLowerCase();
    const mod = event.ctrlKey || event.metaKey;

    if (key === ' ') {
        spaceHeld.value = true;
        event.preventDefault();
        return;
    }
    if (mod && ['=', '+', '-', '0'].includes(key)) {
        event.preventDefault();
        if (key === '0') resetZoom();
        else zoomBy(key === '-' ? 1 / 1.2 : 1.2);
        return;
    }
    if (event.shiftKey && event.code === 'Digit1') {
        event.preventDefault();
        fitToContent();
        return;
    }
    if (key === 'Escape') {
        if (gesture) cancelGesture();
        else setSelection([]);
        return;
    }
    if (!editable.value || props.laser) return;

    if (mod && lower === 'z') {
        event.preventDefault();
        if (event.shiftKey) redo();
        else undo();
        return;
    }
    if (mod && lower === 'y') {
        event.preventDefault();
        redo();
        return;
    }
    if (mod && lower === 'a') {
        event.preventDefault();
        setSelection(elements.value.map((element) => element.id));
        return;
    }
    // Ctrl+V is handled by the paste event: it can carry a picture from the system clipboard.
    if (mod && lower === 'v') return;
    if (!props.selection.length) return;

    if (mod && !event.shiftKey && lower === 'g') {
        event.preventDefault();
        groupSelection(props.doc, props.pageId, props.selection);
        return;
    }
    if (mod && event.shiftKey && lower === 'u') {
        event.preventDefault();
        ungroupSelection(props.doc, props.pageId, props.selection);
        return;
    }
    if (mod && lower === 'r') {
        event.preventDefault();
        rotateSelection(props.doc, props.pageId, props.selection, { by: event.shiftKey ? -90 : 90 });
        return;
    }

    if (mod && event.shiftKey && (lower === 'f' || lower === 'b')) {
        event.preventDefault();
        arrange(props.doc, props.pageId, props.selection, lower === 'f' ? 'front' : 'back');
        return;
    }
    if (mod && lower === 'c') {
        copySelection(props.doc, props.pageId, props.selection);
        return;
    }
    if (mod && lower === 'x') {
        event.preventDefault();
        if (copySelection(props.doc, props.pageId, props.selection)) deleteSelection(props.doc, props.pageId, props.selection);
        setSelection([]);
        return;
    }
    if (mod && lower === 'd') {
        event.preventDefault();
        const ids = duplicateSelection(props.doc, props.pageId, props.selection);
        if (ids.length) setSelection(ids);
        return;
    }
    if (key === 'Delete' || key === 'Backspace') {
        event.preventDefault();
        deleteSelection(props.doc, props.pageId, props.selection);
        setSelection([]);
        return;
    }
    if (key.startsWith('Arrow')) {
        event.preventDefault();
        const step = event.shiftKey ? GRID : 1;
        nudge(key === 'ArrowLeft' ? -step : key === 'ArrowRight' ? step : 0, key === 'ArrowUp' ? -step : key === 'ArrowDown' ? step : 0);
        return;
    }
    const only = props.selection.length === 1 ? live(props.selection[0]) : null;
    if (only && (key === 'F2' || key === 'Enter')) {
        event.preventDefault();
        beginEdit(only);
        return;
    }
    // Typing on a selected shape replaces its label, like draw.io.
    if (only && !mod && !event.altKey && key.length === 1) {
        event.preventDefault();
        beginEdit(only, false, key);
    }
};

const onKeyUp = (event) => {
    if (event.key === ' ') spaceHeld.value = false;
};

const cursor = computed(() => {
    if (props.laser) return 'none';
    if (gestureKind.value === 'pan') return 'grabbing';
    if (spaceHeld.value || props.readonly) return 'grab';
    if (gestureKind.value === 'connect' || gestureKind.value === 'quick') return 'crosshair';
    return hoverCursor.value;
});

const isEmpty = computed(() => !elements.value.length);

// ── Export ───────────────────────────────────────────────────────────────────

const exportPng = async (filename) => {
    commitEdit();
    await nextTick();
    await exportGroupToPng(contentGroup.value, contentBounds(elements.value), filename, background.value);
};

const exportSvg = async (filename) => {
    commitEdit();
    await nextTick();
    await exportGroupToSvg(contentGroup.value, contentBounds(elements.value), filename, background.value);
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

defineExpose({ fitToContent, zoomBy, resetZoom, viewportCenter, insertShape, insertImages, exportPng, exportSvg, commitEdit, undo, redo, view });
</script>

<template>
    <div class="relative h-full w-full select-none overflow-hidden" :style="{ backgroundColor: background }">
        <svg
            ref="svgEl"
            class="absolute inset-0 h-full w-full touch-none"
            :style="{ cursor }"
            data-testid="diagram-surface"
            @pointerdown="onPointerDown"
            @pointermove="onPointerMove"
            @pointerup="onPointerUp"
            @pointercancel="onPointerCancel"
            @pointerleave="onPointerLeave"
            @dblclick="onDoubleClick"
            @wheel="onWheel"
            @dragover="onDragOver"
            @drop="onDrop"
            @contextmenu.prevent
        >
            <defs>
                <pattern :id="`${patternId}-minor`" width="10" height="10" patternUnits="userSpaceOnUse" :patternTransform="transform">
                    <path d="M10 0H0V10" fill="none" stroke="#e5e7eb" :stroke-width="px(1)" />
                </pattern>
                <pattern :id="`${patternId}-major`" width="50" height="50" patternUnits="userSpaceOnUse" :patternTransform="transform">
                    <rect width="50" height="50" :fill="view.zoom >= 0.5 ? `url(#${patternId}-minor)` : 'none'" />
                    <path d="M50 0H0V50" fill="none" stroke="#d1d5db" :stroke-width="px(1)" />
                </pattern>
            </defs>
            <rect v-if="showGrid && !laser" width="100%" height="100%" :fill="`url(#${patternId}-major)`" />

            <g :transform="transform">
                <g ref="contentGroup">
                    <DiagramElements :elements="elements" :overrides="overrides" :hide-text="hiddenText" :label-background="background" />
                </g>
                <DiagramElements v-if="editingGhost" :elements="editingGhost" />
                <DiagramElements v-if="draft" :elements="[draft]" :lookup="liveNodes" :label-background="background" />

                <!-- selection -->
                <path
                    v-for="item in selectedEdgePaths"
                    :key="`sel-edge-${item.id}`"
                    :d="item.d"
                    fill="none"
                    stroke="#3b82f6"
                    stroke-opacity="0.35"
                    :stroke-width="item.width"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    pointer-events="none"
                />
                <g v-for="frame in selectionFrames" :key="`sel-${frame.id}`" :transform="frame.transform" pointer-events="none">
                    <rect
                        :x="-px(3)"
                        :y="-px(3)"
                        :width="frame.w + px(6)"
                        :height="frame.h + px(6)"
                        fill="none"
                        :stroke="groupFrame ? '#93c5fd' : '#2563eb'"
                        :stroke-width="px(1.25)"
                        :stroke-dasharray="`${px(4)} ${px(3)}`"
                    />
                </g>
                <template v-if="groupFrame">
                    <rect
                        :x="groupFrame.x - px(6)"
                        :y="groupFrame.y - px(6)"
                        :width="groupFrame.w + px(12)"
                        :height="groupFrame.h + px(12)"
                        fill="none"
                        stroke="#2563eb"
                        :stroke-width="px(1.5)"
                        :stroke-dasharray="`${px(6)} ${px(3)}`"
                        pointer-events="none"
                        data-testid="diagram-group-frame"
                    />
                    <rect
                        v-for="handle in groupHandles"
                        :key="`group-${handle.key}`"
                        :x="handle.x - px(4)"
                        :y="handle.y - px(4)"
                        :width="px(8)"
                        :height="px(8)"
                        fill="#ffffff"
                        stroke="#2563eb"
                        :stroke-width="px(1.25)"
                        :style="{ cursor: HANDLE_CURSORS[handle.key] }"
                    />
                </template>
                <g v-if="rotateHandle" :transform="`translate(${rotateHandle.x} ${rotateHandle.y}) scale(${px(1)})`" style="cursor: grab" data-testid="diagram-rotate-handle">
                    <circle r="7" fill="#ffffff" stroke="#2563eb" stroke-width="1.25" />
                    <path d="M-3.2 -1.2A3.5 3.5 0 1 1 -2.6 2.4M-3.2 -1.2V-4M-3.2 -1.2H-0.6" fill="none" stroke="#2563eb" stroke-width="1.2" stroke-linecap="round" />
                </g>

                <!-- connection points of the hovered / targeted shape -->
                <g v-if="hoverPorts.length" pointer-events="none">
                    <rect
                        v-if="dropFrame"
                        v-bind="dropFrame"
                        fill="none"
                        stroke="#16a34a"
                        :stroke-width="px(2)"
                        rx="3"
                    />
                    <circle
                        v-for="port in hoverPorts"
                        :key="`port-${port.key}`"
                        :cx="port.x"
                        :cy="port.y"
                        :r="px(port.active ? 6 : 4)"
                        :fill="port.active ? '#16a34a' : '#ffffff'"
                        :stroke="port.active ? '#16a34a' : '#2563eb'"
                        :stroke-width="px(1.5)"
                    />
                </g>

                <!-- draw.io-style quick connect arrows -->
                <g v-for="arrow in quickArrows" :key="`quick-${arrow.dir}`" :transform="`translate(${arrow.x} ${arrow.y}) rotate(${arrow.angle}) scale(${px(1)})`" style="cursor: pointer">
                    <circle r="11" fill="#ffffff" fill-opacity="0.01" />
                    <path d="M-7 -6L5 0L-7 6Z" fill="#3b82f6" fill-opacity="0.75" data-testid="diagram-quick-arrow" :data-dir="arrow.dir" />
                </g>

                <rect
                    v-for="handle in resizeHandles"
                    :key="`resize-${handle.key}`"
                    :x="handle.x - px(4)"
                    :y="handle.y - px(4)"
                    :width="px(8)"
                    :height="px(8)"
                    fill="#ffffff"
                    stroke="#2563eb"
                    :stroke-width="px(1.25)"
                    :style="{ cursor: HANDLE_CURSORS[handle.key] }"
                />
                <template v-for="handle in edgeHandles" :key="`eh-${handle.kind}-${handle.end || handle.index}`">
                    <rect
                        v-if="handle.kind === 'end'"
                        :x="handle.x - px(4.5)"
                        :y="handle.y - px(4.5)"
                        :width="px(9)"
                        :height="px(9)"
                        fill="#2563eb"
                        stroke="#ffffff"
                        :stroke-width="px(1.5)"
                    />
                    <circle
                        v-else
                        :cx="handle.x"
                        :cy="handle.y"
                        :r="px(handle.kind === 'waypoint' ? 4.5 : 3.5)"
                        :fill="handle.kind === 'waypoint' ? '#ffffff' : '#93c5fd'"
                        :stroke="handle.kind === 'waypoint' ? '#2563eb' : 'none'"
                        :stroke-width="px(1.5)"
                        :fill-opacity="handle.kind === 'virtual' ? 0.85 : 1"
                    />
                </template>

                <line
                    v-for="(guide, index) in guides"
                    :key="`guide-${index}`"
                    :x1="guide.x1" :y1="guide.y1" :x2="guide.x2" :y2="guide.y2"
                    stroke="#ec4899"
                    :stroke-width="px(1)"
                    :stroke-dasharray="`${px(4)} ${px(3)}`"
                    pointer-events="none"
                />
                <rect
                    v-if="marquee"
                    :x="marquee.x" :y="marquee.y" :width="marquee.w" :height="marquee.h"
                    fill="#3b82f6" fill-opacity="0.08" stroke="#3b82f6" :stroke-width="px(1)"
                    pointer-events="none"
                />

                <g v-if="laser && (laserSegments.length || laserHead)" pointer-events="none">
                    <line
                        v-for="segment in laserSegments"
                        :key="segment.key"
                        :x1="segment.x1" :y1="segment.y1" :x2="segment.x2" :y2="segment.y2"
                        stroke="#ef4444" :stroke-width="px(5)" stroke-linecap="round" :stroke-opacity="segment.opacity"
                    />
                    <template v-if="laserHead">
                        <circle :cx="laserHead.x" :cy="laserHead.y" :r="px(14)" fill="#ef4444" fill-opacity="0.25" />
                        <circle :cx="laserHead.x" :cy="laserHead.y" :r="px(6)" fill="#ef4444" stroke="#ffffff" :stroke-width="px(1.5)" />
                    </template>
                </g>
            </g>
        </svg>

        <div v-if="uploading" class="pointer-events-none absolute left-1/2 top-3 z-20 -translate-x-1/2 rounded-full bg-gray-900/80 px-3 py-1 text-xs font-semibold text-white shadow" data-testid="diagram-uploading">
            Adding {{ uploading === 1 ? 'picture' : `${uploading} pictures` }}…
        </div>

        <div v-if="isEmpty && editable && !laser" class="pointer-events-none absolute inset-0 flex items-center justify-center p-6">
            <div class="max-w-sm rounded-xl border border-dashed border-gray-300 bg-white/80 px-6 py-5 text-center text-sm text-gray-500 shadow-sm">
                <p class="font-bold text-gray-700">Start your diagram</p>
                <p class="mt-1">Drag a shape from the library, or click one to drop it here. Hover a shape and drag from its blue points to connect it. Double-click anywhere to add text.</p>
            </div>
        </div>

        <textarea
            v-if="editing"
            ref="textInput"
            v-model="editing.text"
            class="absolute z-10 m-0 resize-none select-text overflow-hidden rounded border-0 p-0 shadow-none outline-none ring-2 ring-blue-400 focus:ring-2 focus:ring-blue-500"
            :style="editorStyle"
            spellcheck="false"
            data-testid="diagram-text-editor"
            @keydown.stop="onEditorKeydown"
            @blur="commitEdit"
        ></textarea>

        <slot />
    </div>
</template>
