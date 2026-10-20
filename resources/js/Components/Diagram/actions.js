import { clone, remapIds, round1, uid } from '@/lib/sceneDocument';
import { edgeRoute, nodesIndex, translateElement, unionBounds, nodeBox } from './geometry';
import { PORT_DIRECTIONS, makeEdge, makeNode, nodeCenter } from './shapes';

// Editing operations shared by the canvas (mouse + keyboard) and the format panel.
// Every function commits through the scene document, so each is one undo step.

const CLIPBOARD_KEY = 'ghelpdesk.diagram.clipboard';
const OPPOSITE = { n: 's', s: 'n', e: 'w', w: 'e' };
let memoryClipboard = null;
let pasteCount = 0;

const page = (doc, pageId) => doc.pageById(pageId);
const byId = (elements) => new Map(elements.map((element) => [element.id, element]));

export const selectedElements = (doc, pageId, ids) => {
    const index = byId(page(doc, pageId)?.elements || []);
    return ids.map((id) => index.get(id)).filter(Boolean);
};

/** Delete the selection and every connector attached to a deleted shape. */
export const deleteSelection = (doc, pageId, ids) => {
    const elements = page(doc, pageId)?.elements || [];
    const doomed = new Set(ids);
    for (const element of elements) {
        if (element.type === 'edge' && (doomed.has(element.source) || doomed.has(element.target))) doomed.add(element.id);
    }
    const changes = elements.filter((element) => doomed.has(element.id)).map((element) => ({ id: element.id, after: null }));
    if (changes.length) doc.commit(pageId, changes);
};

/**
 * Copy shapes plus connectors that are selected or run between two copied shapes. A copied
 * connector whose shape was not copied keeps its end as a free point where it was drawn.
 */
export const copySelection = (doc, pageId, ids) => {
    const elements = page(doc, pageId)?.elements || [];
    const nodes = nodesIndex(elements);
    const chosen = new Set(ids);
    const copied = elements.filter((element) => chosen.has(element.id)
        || (element.type === 'edge' && chosen.has(element.source) && chosen.has(element.target)));
    const copiedIds = new Set(copied.map((element) => element.id));

    const payload = copied.map((element) => {
        if (element.type !== 'edge') return clone(element);
        const edge = clone(element);
        const route = edgeRoute(element, nodes).points;
        if (edge.source && !copiedIds.has(edge.source)) Object.assign(edge, { source: null, sourcePort: null, x1: round1(route[0].x), y1: round1(route[0].y) });
        if (edge.target && !copiedIds.has(edge.target)) Object.assign(edge, { target: null, targetPort: null, x2: round1(route.at(-1).x), y2: round1(route.at(-1).y) });
        return edge;
    });

    if (!payload.length) return false;
    memoryClipboard = payload;
    pasteCount = 0;
    try {
        localStorage.setItem(CLIPBOARD_KEY, JSON.stringify(payload));
    } catch {
        // Clipboard still works within this tab.
    }
    return true;
};

export const hasClipboard = () => {
    if (memoryClipboard?.length) return true;
    try {
        return !!localStorage.getItem(CLIPBOARD_KEY);
    } catch {
        return false;
    }
};

/** Paste (offset a little more each time) and return the new ids. */
export const paste = (doc, pageId) => {
    let payload = memoryClipboard;
    try {
        payload = JSON.parse(localStorage.getItem(CLIPBOARD_KEY) || 'null') || payload;
    } catch {
        // Fall back to the in-memory copy.
    }
    if (!payload?.length) return [];
    pasteCount++;
    const offset = pasteCount * 20;
    const copies = remapIds(payload).map((element) => translateElement(element, offset, offset));
    doc.commit(pageId, copies.map((element) => ({ id: element.id, after: element })));
    return copies.map((element) => element.id);
};

export const duplicateSelection = (doc, pageId, ids) => {
    if (!copySelection(doc, pageId, ids)) return [];
    return paste(doc, pageId);
};

/** Patch every selected element that accepts the given keys (style changes from the panel). */
export const restyle = (doc, pageId, ids, patch, type = null) => {
    const changes = [];
    for (const element of selectedElements(doc, pageId, ids)) {
        if (type && element.type !== type) continue;
        const after = { ...clone(element), ...patch };
        if (JSON.stringify(after) !== JSON.stringify(element)) changes.push({ id: element.id, after });
    }
    if (changes.length) doc.commit(pageId, changes);
};

export const updateElement = (doc, pageId, id, patch) => {
    const [element] = selectedElements(doc, pageId, [id]);
    if (!element) return;
    doc.commit(pageId, [{ id, after: { ...clone(element), ...patch } }]);
};

// ── Groups ───────────────────────────────────────────────────────────────────
// A group is the set of elements sharing a `groupId` (flat — grouping groups merges them).

/** Expand ids so any grouped element brings its whole group along. */
export const expandGroups = (elements, ids) => {
    const chosen = new Set(ids);
    const groups = new Set(elements.filter((element) => chosen.has(element.id) && element.groupId).map((element) => element.groupId));
    if (!groups.size) return ids;
    return [...new Set([...ids, ...elements.filter((element) => groups.has(element.groupId)).map((element) => element.id)])];
};

/** The group id when the selection is exactly one whole group, else null. */
export const wholeGroup = (elements, ids) => {
    if (ids.length < 2) return null;
    const chosen = new Set(ids);
    const first = elements.find((element) => element.id === ids[0])?.groupId;
    if (!first) return null;
    const members = elements.filter((element) => element.groupId === first);
    return members.length === chosen.size && members.every((element) => chosen.has(element.id)) ? first : null;
};

export const groupSelection = (doc, pageId, ids) => {
    const items = selectedElements(doc, pageId, ids);
    if (items.length < 2) return null;
    const groupId = uid('g');
    doc.commit(pageId, items.map((element) => ({ id: element.id, after: { ...clone(element), groupId } })));
    return groupId;
};

export const ungroupSelection = (doc, pageId, ids) => {
    const items = selectedElements(doc, pageId, ids).filter((element) => element.groupId);
    if (!items.length) return false;
    doc.commit(pageId, items.map((element) => ({ id: element.id, after: { ...clone(element), groupId: null } })));
    return true;
};

// ── Rotation ─────────────────────────────────────────────────────────────────

export const normalizeAngle = (degrees) => round1((((degrees % 360) + 360) % 360));

/** Rotate the selected shapes, each about its own centre: `by` degrees, or `to` an angle. */
export const rotateSelection = (doc, pageId, ids, { by = null, to = null } = {}) => {
    const nodes = selectedElements(doc, pageId, ids).filter((element) => element.type === 'node');
    const changes = nodes
        .map((node) => ({ node, angle: normalizeAngle(to ?? ((node.rotation || 0) + (by || 0))) }))
        .filter(({ node, angle }) => angle !== (node.rotation || 0))
        .map(({ node, angle }) => ({ id: node.id, after: { ...clone(node), rotation: angle } }));
    if (changes.length) doc.commit(pageId, changes);
};

/** Bring to front / send to back (z-order within the page). */
export const arrange = (doc, pageId, ids, where) => {
    const elements = page(doc, pageId)?.elements || [];
    const chosen = new Set(ids);
    const picked = elements.filter((element) => chosen.has(element.id)).map((element) => element.id);
    const rest = elements.filter((element) => !chosen.has(element.id)).map((element) => element.id);
    doc.reorder(pageId, where === 'front' ? [...rest, ...picked] : [...picked, ...rest]);
};

export const align = (doc, pageId, ids, how) => {
    const nodes = selectedElements(doc, pageId, ids).filter((element) => element.type === 'node');
    if (nodes.length < 2) return;
    const box = unionBounds(nodes.map(nodeBox));
    const place = {
        left: () => ({ x: box.x }),
        center: (n) => ({ x: round1(box.x + box.w / 2 - n.w / 2) }),
        right: (n) => ({ x: round1(box.x + box.w - n.w) }),
        top: () => ({ y: box.y }),
        middle: (n) => ({ y: round1(box.y + box.h / 2 - n.h / 2) }),
        bottom: (n) => ({ y: round1(box.y + box.h - n.h) }),
    }[how];
    doc.commit(pageId, nodes.map((n) => ({ id: n.id, after: { ...clone(n), ...place(n) } })));
};

export const distribute = (doc, pageId, ids, axis) => {
    const nodes = selectedElements(doc, pageId, ids).filter((element) => element.type === 'node');
    if (nodes.length < 3) return;
    const key = axis === 'horizontal' ? 'x' : 'y';
    const size = axis === 'horizontal' ? 'w' : 'h';
    const sorted = [...nodes].sort((a, b) => (a[key] + a[size] / 2) - (b[key] + b[size] / 2));
    const first = sorted[0][key] + sorted[0][size] / 2;
    const last = sorted.at(-1)[key] + sorted.at(-1)[size] / 2;
    const step = (last - first) / (sorted.length - 1);
    doc.commit(pageId, sorted.map((n, i) => ({ id: n.id, after: { ...clone(n), [key]: round1(first + step * i - n[size] / 2) } })));
};

const GAP = 60;

/** Where a new shape goes when placed next to `node` in direction `dir`. */
const besides = (node, shapeKey, dir, template) => {
    const fresh = template ? { ...clone(template), id: uid('n'), text: '', groupId: null } : makeNode(shapeKey, 0, 0);
    const c = nodeCenter(node);
    const d = PORT_DIRECTIONS[dir];
    const x = d.x ? (d.x > 0 ? node.x + node.w + GAP : node.x - GAP - fresh.w) : c.x - fresh.w / 2;
    const y = d.y ? (d.y > 0 ? node.y + node.h + GAP : node.y - GAP - fresh.h) : c.y - fresh.h / 2;
    return { ...fresh, x: round1(x), y: round1(y) };
};

/**
 * draw.io's blue arrows: clone the shape on that side and connect it. With `shapeKey`, a new
 * shape of that kind is placed and connected instead (clicking the library with one shape
 * selected). Returns the new node id.
 */
export const connectNew = (doc, pageId, node, dir, { shapeKey = null, edgeStyle = {} } = {}) => {
    const next = besides(node, shapeKey, dir, shapeKey ? null : node);
    // A rotated shape's side ports no longer face n/e/s/w, so its end floats instead.
    const edge = makeEdge({ ...edgeStyle, source: node.id, sourcePort: node.rotation ? null : dir, target: next.id, targetPort: OPPOSITE[dir] });
    doc.commit(pageId, [{ id: next.id, after: next }, { id: edge.id, after: edge }]);
    return next.id;
};

/** Pick a free spot: nudge down-right until no shape sits exactly there. */
export const freeSpot = (elements, x, y) => {
    const taken = new Set(elements.filter((element) => element.type === 'node').map((element) => `${element.x}|${element.y}`));
    let px = x;
    let py = y;
    while (taken.has(`${px}|${py}`)) {
        px += 20;
        py += 20;
    }
    return { x: px, y: py };
};
