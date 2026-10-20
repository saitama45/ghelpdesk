import { clone, round1 } from '@/lib/sceneDocument';
import { measureText } from '@/lib/svgText';
import { facingPort, nodeCenter, nodePorts, perimeterPoint, rotatePoint, shapeOf } from './shapes';

const STUB = 18;

export const pairs = (flat = []) => {
    const points = [];
    for (let i = 0; i + 1 < flat.length; i += 2) points.push({ x: flat[i], y: flat[i + 1] });
    return points;
};
export const flatten = (points) => points.flatMap((point) => [round1(point.x), round1(point.y)]);

export const nodesIndex = (elements) => {
    const index = {};
    for (const element of elements) if (element.type === 'node') index[element.id] = element;
    return index;
};

const simplify = (points) => {
    const out = [];
    for (const point of points) {
        const last = out[out.length - 1];
        if (last && Math.abs(last.x - point.x) < 0.01 && Math.abs(last.y - point.y) < 0.01) continue;
        out.push(point);
        while (out.length >= 3) {
            const [a, b, c] = out.slice(-3);
            const collinear = (Math.abs(a.x - b.x) < 0.01 && Math.abs(b.x - c.x) < 0.01)
                || (Math.abs(a.y - b.y) < 0.01 && Math.abs(b.y - c.y) < 0.01);
            if (!collinear) break;
            out.splice(out.length - 2, 1);
        }
    }
    return out;
};

const orthogonal = (start, d1, end, d2, waypoints) => {
    if (waypoints.length) {
        const sequence = [start, ...waypoints, end];
        const out = [start];
        for (let i = 1; i < sequence.length; i++) {
            const a = out[out.length - 1];
            const b = sequence[i];
            if (Math.abs(a.x - b.x) > 0.01 && Math.abs(a.y - b.y) > 0.01) {
                const verticalFirst = i === 1 ? d1?.x === 0 : (i === sequence.length - 1 && d2 ? d2.x !== 0 : false);
                out.push(verticalFirst ? { x: a.x, y: b.y } : { x: b.x, y: a.y });
            }
            out.push(b);
        }
        return simplify(out);
    }

    const a = d1 ? { x: start.x + d1.x * STUB, y: start.y + d1.y * STUB } : start;
    const b = d2 ? { x: end.x + d2.x * STUB, y: end.y + d2.y * STUB } : end;
    const horizontal1 = d1 ? d1.x !== 0 : Math.abs(b.x - a.x) >= Math.abs(b.y - a.y);
    const horizontal2 = d2 ? d2.x !== 0 : horizontal1;
    let middle;

    if (horizontal1 && horizontal2) {
        const mx = (a.x + b.x) / 2;
        middle = [{ x: mx, y: a.y }, { x: mx, y: b.y }];
    } else if (!horizontal1 && !horizontal2) {
        const my = (a.y + b.y) / 2;
        middle = [{ x: a.x, y: my }, { x: b.x, y: my }];
    } else if (horizontal1) {
        middle = [{ x: b.x, y: a.y }];
    } else {
        middle = [{ x: a.x, y: b.y }];
    }

    return simplify([start, a, ...middle, b, end]);
};

/**
 * The connector's polyline in absolute coordinates, plus the outward direction at each end
 * (used by curved routing). Ends attach to a node port, float on the node outline, or sit at
 * the stored free point (x1,y1 / x2,y2) when unattached or the node is gone.
 */
export const edgeRoute = (edge, nodes) => {
    const source = edge.source ? nodes[edge.source] : null;
    const target = edge.target ? nodes[edge.target] : null;
    const waypoints = pairs(edge.points);
    const freeStart = { x: edge.x1, y: edge.y1 };
    const freeEnd = { x: edge.x2, y: edge.y2 };
    const sourceAim = waypoints[0] || (target ? nodeCenter(target) : freeEnd);
    const targetAim = waypoints[waypoints.length - 1] || (source ? nodeCenter(source) : freeStart);
    const routing = edge.routing || 'orthogonal';

    const end = (node, port, aim, free) => {
        if (!node) return { point: free, dir: null };
        const key = port || (routing === 'orthogonal' ? facingPort(node, aim) : null);
        if (key) {
            const p = nodePorts(node)[key];
            if (p) return { point: { x: p.x, y: p.y }, dir: p.dir };
        }
        const point = perimeterPoint(node, aim);
        const c = nodeCenter(node);
        const length = Math.hypot(point.x - c.x, point.y - c.y) || 1;
        return { point, dir: { x: (point.x - c.x) / length, y: (point.y - c.y) / length } };
    };

    const s = end(source, edge.sourcePort, sourceAim, freeStart);
    const t = end(target, edge.targetPort, targetAim, freeEnd);

    const points = routing === 'orthogonal'
        ? orthogonal(s.point, source ? s.dir : null, t.point, target ? t.dir : null, waypoints)
        : [s.point, ...waypoints, t.point];

    return { points, curved: routing === 'curved', startDir: s.dir, endDir: t.dir };
};

const cubic = (p0, c1, c2, p1, steps = 16) => {
    const out = [];
    for (let i = 0; i <= steps; i++) {
        const t = i / steps;
        const u = 1 - t;
        out.push({
            x: u * u * u * p0.x + 3 * u * u * t * c1.x + 3 * u * t * t * c2.x + t * t * t * p1.x,
            y: u * u * u * p0.y + 3 * u * u * t * c1.y + 3 * u * t * t * c2.y + t * t * t * p1.y,
        });
    }
    return out;
};

/** Bezier segments for a curved route (Catmull-Rom through the points; port directions at the ends). */
const curveSegments = (route) => {
    const p = route.points;
    if (p.length < 2) return [];
    if (p.length === 2) {
        const reach = Math.max(30, Math.hypot(p[1].x - p[0].x, p[1].y - p[0].y) * 0.4);
        const d1 = route.startDir || { x: (p[1].x - p[0].x), y: (p[1].y - p[0].y) };
        const d2 = route.endDir || { x: (p[0].x - p[1].x), y: (p[0].y - p[1].y) };
        const n1 = Math.hypot(d1.x, d1.y) || 1;
        const n2 = Math.hypot(d2.x, d2.y) || 1;
        return [[p[0], { x: p[0].x + (d1.x / n1) * reach, y: p[0].y + (d1.y / n1) * reach }, { x: p[1].x + (d2.x / n2) * reach, y: p[1].y + (d2.y / n2) * reach }, p[1]]];
    }
    const segments = [];
    for (let i = 0; i < p.length - 1; i++) {
        const p0 = p[i - 1] || p[i];
        const p1 = p[i];
        const p2 = p[i + 1];
        const p3 = p[i + 2] || p2;
        segments.push([p1, { x: p1.x + (p2.x - p0.x) / 6, y: p1.y + (p2.y - p0.y) / 6 }, { x: p2.x - (p3.x - p1.x) / 6, y: p2.y - (p3.y - p1.y) / 6 }, p2]);
    }
    return segments;
};

/** Dense polyline of the rendered route (for hit testing, label placement, arrow angles). */
export const sampleRoute = (route) => {
    if (!route.curved) return route.points;
    const out = [];
    for (const [a, c1, c2, b] of curveSegments(route)) {
        const samples = cubic(a, c1, c2, b);
        if (out.length) samples.shift();
        out.push(...samples);
    }
    return out.length ? out : route.points;
};

const fmt = (point) => `${round1(point.x)} ${round1(point.y)}`;

/** SVG path for the route, with the ends pulled back so filled arrowheads cover the tip. */
export const routePath = (route, startInset = 0, endInset = 0) => {
    const points = route.points.map((point) => ({ ...point }));
    if (points.length < 2) return '';
    const pull = (from, to, by) => {
        const length = Math.hypot(to.x - from.x, to.y - from.y);
        if (!by || length <= by) return;
        to.x -= ((to.x - from.x) / length) * by;
        to.y -= ((to.y - from.y) / length) * by;
    };

    if (route.curved) {
        const segments = curveSegments({ ...route, points });
        if (startInset) pull(segments[0][1], segments[0][0], startInset);
        const last = segments[segments.length - 1];
        if (endInset) pull(last[2], last[3], endInset);
        return segments.map(([a, c1, c2, b], index) => `${index ? '' : `M${fmt(a)}`}C${fmt(c1)} ${fmt(c2)} ${fmt(b)}`).join('');
    }

    if (startInset) pull(points[1], points[0], startInset);
    if (endInset) pull(points[points.length - 2], points[points.length - 1], endInset);
    return `M${points.map(fmt).join('L')}`;
};

export const ARROW_KINDS = ['none', 'arrow', 'open', 'block', 'diamond', 'circle'];

const arrowSize = (strokeWidth) => 9 + (strokeWidth || 1.5) * 2;

/** How far the line is pulled back under a filled head. */
export const arrowInset = (kind, strokeWidth) => {
    const size = arrowSize(strokeWidth);
    return { arrow: size * 0.55, block: size * 0.8, diamond: size * 0.9, circle: size * 0.7, open: 0, none: 0 }[kind] || 0;
};

/** Arrowhead at `tip`, pointing away from `from`. Returns { d, filled } or null. */
export const arrowHead = (kind, tip, from, strokeWidth) => {
    if (!kind || kind === 'none') return null;
    const length = Math.hypot(tip.x - from.x, tip.y - from.y) || 1;
    const ux = (tip.x - from.x) / length;
    const uy = (tip.y - from.y) / length;
    const size = arrowSize(strokeWidth);
    const half = size * 0.42;
    const at = (back, side) => ({ x: tip.x - ux * back - uy * side, y: tip.y - uy * back + ux * side });
    const path = (...points) => `M${points.map(fmt).join('L')}`;

    switch (kind) {
        case 'arrow':
            return { d: `${path(tip, at(size, half), at(size * 0.62, 0), at(size, -half))}Z`, filled: true };
        case 'block':
            return { d: `${path(tip, at(size, half), at(size, -half))}Z`, filled: true };
        case 'open':
            return { d: path(at(size, half), tip, at(size, -half)), filled: false };
        case 'diamond':
            return { d: `${path(tip, at(size / 2, half), at(size, 0), at(size / 2, -half))}Z`, filled: true };
        case 'circle': {
            const r = size * 0.35;
            const c = at(r, 0);
            return { d: `M${round1(c.x + r)} ${round1(c.y)}A${r} ${r} 0 1 1 ${round1(c.x - r)} ${round1(c.y)}A${r} ${r} 0 1 1 ${round1(c.x + r)} ${round1(c.y)}Z`, filled: true };
        }
        default:
            return null;
    }
};

/** Midpoint along the route's length — where the label sits. */
export const routeMidpoint = (samples) => {
    let total = 0;
    for (let i = 1; i < samples.length; i++) total += Math.hypot(samples[i].x - samples[i - 1].x, samples[i].y - samples[i - 1].y);
    let walked = 0;
    for (let i = 1; i < samples.length; i++) {
        const step = Math.hypot(samples[i].x - samples[i - 1].x, samples[i].y - samples[i - 1].y);
        if (walked + step >= total / 2 && step > 0) {
            const t = (total / 2 - walked) / step;
            return { x: samples[i - 1].x + (samples[i].x - samples[i - 1].x) * t, y: samples[i - 1].y + (samples[i].y - samples[i - 1].y) * t };
        }
        walked += step;
    }
    return samples[0] || { x: 0, y: 0 };
};

export const labelBox = (edge, samples) => {
    if (!edge.label) return null;
    const size = edge.fontSize || 12;
    const lines = String(edge.label).split('\n');
    const w = Math.max(...lines.map((line) => measureText(line, size))) + 10;
    const h = lines.length * size * 1.25 + 4;
    const mid = routeMidpoint(samples);
    return { x: mid.x - w / 2, y: mid.y - h / 2, w, h, lines, size };
};

/** Everything the renderer and hit tests need for one connector. */
export const edgeGeometry = (edge, nodes) => {
    const route = edgeRoute(edge, nodes);
    const samples = sampleRoute(route);
    const n = samples.length;
    const startHead = n > 1 ? arrowHead(edge.startArrow, samples[0], samples[1], edge.strokeWidth) : null;
    const endHead = n > 1 ? arrowHead(edge.endArrow, samples[n - 1], samples[n - 2], edge.strokeWidth) : null;
    return {
        route,
        samples,
        d: routePath(route, startHead?.filled ? arrowInset(edge.startArrow, edge.strokeWidth) : 0, endHead?.filled ? arrowInset(edge.endArrow, edge.strokeWidth) : 0),
        heads: [startHead, endHead].filter(Boolean),
        label: labelBox(edge, samples),
    };
};

// ── Hit testing & bounds ─────────────────────────────────────────────────────

const distToSegment = (px, py, a, b) => {
    const dx = b.x - a.x;
    const dy = b.y - a.y;
    const lengthSq = dx * dx + dy * dy;
    const t = lengthSq ? Math.max(0, Math.min(1, ((px - a.x) * dx + (py - a.y) * dy) / lengthSq)) : 0;
    return Math.hypot(px - (a.x + t * dx), py - (a.y + t * dy));
};

export const inBox = (box, x, y, pad = 0) => x >= box.x - pad && x <= box.x + box.w + pad && y >= box.y - pad && y <= box.y + box.h + pad;
export const boxesIntersect = (a, b) => a.x <= b.x + b.w && a.x + a.w >= b.x && a.y <= b.y + b.h && a.y + a.h >= b.y;
export const boxContains = (outer, inner) => inner.x >= outer.x && inner.y >= outer.y && inner.x + inner.w <= outer.x + outer.w && inner.y + inner.h <= outer.y + outer.h;
export const nodeBox = (node) => ({ x: node.x, y: node.y, w: node.w, h: node.h });

/** Axis-aligned box around a (possibly rotated) node. */
export const nodeBounds = (node) => {
    if (!node.rotation) return nodeBox(node);
    const c = nodeCenter(node);
    const corners = [[node.x, node.y], [node.x + node.w, node.y], [node.x + node.w, node.y + node.h], [node.x, node.y + node.h]]
        .map(([x, y]) => rotatePoint({ x, y }, c, node.rotation));
    const xs = corners.map((p) => p.x);
    const ys = corners.map((p) => p.y);
    const x = Math.min(...xs);
    const y = Math.min(...ys);
    return { x, y, w: Math.max(...xs) - x, h: Math.max(...ys) - y };
};

/** Point-in-node test done in the node's own (unrotated) frame. */
export const hitNode = (node, x, y, pad = 0) => {
    const local = rotatePoint({ x, y }, nodeCenter(node), -(node.rotation || 0));
    return inBox(nodeBox(node), local.x, local.y, pad);
};

export const hitEdge = (geometry, edge, x, y, tolerance) => {
    if (geometry.label && inBox(geometry.label, x, y)) return true;
    const reach = tolerance + (edge.strokeWidth || 1.5) / 2;
    const s = geometry.samples;
    for (let i = 1; i < s.length; i++) if (distToSegment(x, y, s[i - 1], s[i]) <= reach) return true;
    return false;
};

export const edgeBounds = (geometry) => {
    const xs = geometry.samples.map((p) => p.x);
    const ys = geometry.samples.map((p) => p.y);
    if (geometry.label) {
        xs.push(geometry.label.x, geometry.label.x + geometry.label.w);
        ys.push(geometry.label.y, geometry.label.y + geometry.label.h);
    }
    const x = Math.min(...xs);
    const y = Math.min(...ys);
    return { x, y, w: Math.max(...xs) - x, h: Math.max(...ys) - y };
};

export const unionBounds = (boxes) => {
    const list = boxes.filter(Boolean);
    if (!list.length) return null;
    const x = Math.min(...list.map((b) => b.x));
    const y = Math.min(...list.map((b) => b.y));
    return { x, y, w: Math.max(...list.map((b) => b.x + b.w)) - x, h: Math.max(...list.map((b) => b.y + b.h)) - y };
};

export const elementBounds = (element, nodes) => (element.type === 'node'
    ? nodeBounds(element)
    : edgeBounds(edgeGeometry(element, nodes)));

export const contentBounds = (elements) => {
    const nodes = nodesIndex(elements);
    return unionBounds(elements.map((element) => elementBounds(element, nodes)));
};

// ── Transforms ───────────────────────────────────────────────────────────────

export const translateElement = (element, dx, dy) => {
    const moved = clone(element);
    if (moved.type === 'node') {
        moved.x = round1(moved.x + dx);
        moved.y = round1(moved.y + dy);
    } else {
        moved.points = (moved.points || []).map((value, index) => round1(value + (index % 2 === 0 ? dx : dy)));
        moved.x1 = round1(moved.x1 + dx); moved.y1 = round1(moved.y1 + dy);
        moved.x2 = round1(moved.x2 + dx); moved.y2 = round1(moved.y2 + dy);
    }
    return moved;
};

export const RESIZE_HANDLES = ['nw', 'n', 'ne', 'e', 'se', 's', 'sw', 'w'];

export const handlePoint = (box, key) => ({
    x: box.x + (key.includes('w') ? 0 : key.includes('e') ? box.w : box.w / 2),
    y: box.y + (key.includes('n') ? 0 : key.includes('s') ? box.h : box.h / 2),
});

export const HANDLE_CURSORS = { nw: 'nwse-resize', se: 'nwse-resize', ne: 'nesw-resize', sw: 'nesw-resize', n: 'ns-resize', s: 'ns-resize', e: 'ew-resize', w: 'ew-resize' };

const snapTo = (value, grid) => (grid ? Math.round(value / grid) * grid : value);

export const resizeBox = (base, key, x, y, { keepRatio = false, grid = 0, min = 10 } = {}) => {
    let left = base.x;
    let top = base.y;
    let right = base.x + base.w;
    let bottom = base.y + base.h;
    if (key.includes('w')) left = Math.min(snapTo(x, grid), right - min);
    if (key.includes('e')) right = Math.max(snapTo(x, grid), left + min);
    if (key.includes('n')) top = Math.min(snapTo(y, grid), bottom - min);
    if (key.includes('s')) bottom = Math.max(snapTo(y, grid), top + min);

    if (keepRatio && key.length === 2 && base.h) {
        const ratio = base.w / base.h;
        const w = right - left;
        const h = bottom - top;
        if (w / h > ratio) {
            const nh = w / ratio;
            if (key.includes('n')) top = bottom - nh; else bottom = top + nh;
        } else {
            const nw = h * ratio;
            if (key.includes('w')) left = right - nw; else right = left + nw;
        }
    }
    return { x: round1(left), y: round1(top), w: round1(right - left), h: round1(bottom - top) };
};

/**
 * Resize a rotated node: the pointer is taken into the node's own frame, the box is resized
 * there, and the new centre is rotated back so the opposite corner stays put on screen.
 */
export const resizeRotated = (base, key, x, y, options = {}) => {
    const rotation = base.rotation || 0;
    if (!rotation) return resizeBox(nodeBox(base), key, x, y, options);
    const c0 = nodeCenter(base);
    const local = rotatePoint({ x, y }, c0, -rotation);
    const box = resizeBox(nodeBox(base), key, local.x, local.y, { ...options, grid: 0 });
    const c1 = rotatePoint({ x: box.x + box.w / 2, y: box.y + box.h / 2 }, c0, rotation);
    return { x: round1(c1.x - box.w / 2), y: round1(c1.y - box.h / 2), w: box.w, h: box.h };
};

/** Scale a group's elements from one bounding box to another (group resize). */
export const scaleElements = (elements, from, to) => {
    const sx = from.w ? to.w / from.w : 1;
    const sy = from.h ? to.h / from.h : 1;
    const mapX = (x) => round1(to.x + (x - from.x) * sx);
    const mapY = (y) => round1(to.y + (y - from.y) * sy);
    return elements.map((element) => {
        const next = clone(element);
        if (next.type === 'node') {
            const c = { x: next.x + next.w / 2, y: next.y + next.h / 2 };
            next.w = round1(Math.max(4, next.w * sx));
            next.h = round1(Math.max(4, next.h * sy));
            next.x = round1(mapX(c.x) - next.w / 2);
            next.y = round1(mapY(c.y) - next.h / 2);
        } else {
            next.points = (next.points || []).map((value, index) => (index % 2 === 0 ? mapX(value) : mapY(value)));
            Object.assign(next, { x1: mapX(next.x1), y1: mapY(next.y1), x2: mapX(next.x2), y2: mapY(next.y2) });
        }
        return next;
    });
};

/**
 * Snap a moving box: grid first, then smart guides to the edges/centres of other nodes
 * (a guide wins over the grid on its axis). Returns the corrected delta and guide lines.
 */
export const snapMove = (box, dx, dy, others, { grid = 0, threshold = 5 } = {}) => {
    let sx = grid ? snapTo(box.x + dx, grid) - box.x : dx;
    let sy = grid ? snapTo(box.y + dy, grid) - box.y : dy;
    const guides = [];

    const lines = (b) => ({ v: [b.x, b.x + b.w / 2, b.x + b.w], h: [b.y, b.y + b.h / 2, b.y + b.h] });
    const raw = lines({ ...box, x: box.x + dx, y: box.y + dy });

    let bestV = null;
    let bestH = null;
    for (const other of others) {
        const target = lines(other);
        for (const [i, value] of raw.v.entries()) {
            for (const candidate of target.v) {
                const diff = candidate - value;
                if (Math.abs(diff) <= threshold && (!bestV || Math.abs(diff) < Math.abs(bestV.diff))) bestV = { diff, at: candidate, other, i };
            }
        }
        for (const [i, value] of raw.h.entries()) {
            for (const candidate of target.h) {
                const diff = candidate - value;
                if (Math.abs(diff) <= threshold && (!bestH || Math.abs(diff) < Math.abs(bestH.diff))) bestH = { diff, at: candidate, other, i };
            }
        }
    }

    if (bestV) {
        sx = dx + bestV.diff;
        const y1 = Math.min(box.y + sy, bestV.other.y);
        const y2 = Math.max(box.y + sy + box.h, bestV.other.y + bestV.other.h);
        guides.push({ x1: bestV.at, y1, x2: bestV.at, y2 });
    }
    if (bestH) {
        sy = dy + bestH.diff;
        const x1 = Math.min(box.x + sx, bestH.other.x);
        const x2 = Math.max(box.x + sx + box.w, bestH.other.x + bestH.other.w);
        guides.push({ x1, y1: bestH.at, x2, y2: bestH.at });
    }

    return { dx: round1(sx), dy: round1(sy), guides };
};

export const isContainer = (node) => !!shapeOf(node).container;
