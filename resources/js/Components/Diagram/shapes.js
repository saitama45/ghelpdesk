import { uid } from '@/lib/sceneDocument';

/**
 * Shape registry for the diagram editor. Every shape draws in LOCAL coordinates
 * (0,0)–(w,h); the renderer translates it to the node's x/y.
 *
 *   path(w, h, node)   outline (filled + stroked)
 *   extra(w, h)        decorative strokes drawn on top, never filled (cylinder rim, note fold…)
 *   text(w, h)         local box the label wraps inside (default: whole shape, padded)
 *   perimeter          'rect' | 'ellipse' | 'diamond' — where floating connectors meet the outline
 *   ports(w, h)        connection points; defaults to the four side midpoints
 *   container          moves the nodes inside it when dragged; drawn behind other shapes
 *   style              default style overrides for new nodes
 */

const r1 = (n) => Math.round(n * 10) / 10;
const poly = (...points) => `M${points.map(([x, y]) => `${r1(x)} ${r1(y)}`).join('L')}Z`;
const rect = (w, h) => `M0 0H${w}V${h}H0Z`;
const roundRect = (w, h, radius) => {
    const r = Math.max(0, Math.min(radius, w / 2, h / 2));
    if (!r) return rect(w, h);
    return `M${r} 0H${w - r}A${r} ${r} 0 0 1 ${w} ${r}V${h - r}A${r} ${r} 0 0 1 ${w - r} ${h}H${r}A${r} ${r} 0 0 1 0 ${h - r}V${r}A${r} ${r} 0 0 1 ${r} 0Z`;
};
const ellipse = (w, h) => `M0 ${h / 2}A${w / 2} ${h / 2} 0 1 1 ${w} ${h / 2}A${w / 2} ${h / 2} 0 1 1 0 ${h / 2}Z`;
const inset = (w, h, fx, fy = fx) => ({ x: w * fx, y: h * fy, w: w * (1 - fx * 2), h: h * (1 - fy * 2) });

const hexagon = (w, h) => poly([w * 0.2, 0], [w * 0.8, 0], [w, h / 2], [w * 0.8, h], [w * 0.2, h], [0, h / 2]);
const parallelogram = (w, h) => poly([w * 0.18, 0], [w, 0], [w * 0.82, h], [0, h]);
const diamond = (w, h) => poly([w / 2, 0], [w, h / 2], [w / 2, h], [0, h / 2]);
const cylinderRy = (h) => Math.min(14, h * 0.15);
const cylinder = (w, h) => {
    const ry = cylinderRy(h);
    return `M0 ${ry}A${w / 2} ${ry} 0 0 1 ${w} ${ry}V${h - ry}A${w / 2} ${ry} 0 0 1 0 ${h - ry}Z`;
};
const cylinderRim = (w, h) => {
    const ry = cylinderRy(h);
    return `M0 ${ry}A${w / 2} ${ry} 0 0 0 ${w} ${ry}`;
};
const sidePorts = (left, right) => (w, h) => ({
    n: [w / 2, 0], e: [w * right, h / 2], s: [w / 2, h], w: [w * left, h / 2],
});

export const SHAPE_DRAG_TYPE = 'application/x-diagram-shape';

export const SHAPE_GROUPS = [
    { id: 'general', label: 'General' },
    { id: 'flowchart', label: 'Flowchart' },
    { id: 'containers', label: 'Containers & lanes' },
];

export const SHAPES = {
    // ── General ───────────────────────────────────────────────────────────────
    rectangle: { label: 'Rectangle', group: 'general', w: 120, h: 60, path: (w, h, node) => (node?.rounded ? roundRect(w, h, 10) : rect(w, h)) },
    rounded: { label: 'Rounded rectangle', group: 'general', w: 120, h: 60, path: (w, h) => roundRect(w, h, Math.min(12, h / 4)) },
    ellipse: { label: 'Ellipse', group: 'general', w: 120, h: 80, path: ellipse, perimeter: 'ellipse', text: (w, h) => inset(w, h, 0.12, 0.1) },
    circle: { label: 'Circle', group: 'general', w: 80, h: 80, path: ellipse, perimeter: 'ellipse', text: (w, h) => inset(w, h, 0.14) },
    diamond: { label: 'Diamond', group: 'general', w: 100, h: 100, path: diamond, perimeter: 'diamond', text: (w, h) => inset(w, h, 0.22) },
    triangle: { label: 'Triangle', group: 'general', w: 100, h: 90, path: (w, h) => poly([w / 2, 0], [w, h], [0, h]), text: (w, h) => ({ x: w * 0.22, y: h * 0.45, w: w * 0.56, h: h * 0.5 }) },
    hexagon: { label: 'Hexagon', group: 'general', w: 120, h: 70, path: hexagon, text: (w, h) => inset(w, h, 0.18, 0.06) },
    parallelogram: { label: 'Parallelogram', group: 'general', w: 120, h: 60, path: parallelogram, text: (w, h) => inset(w, h, 0.16, 0.06), ports: sidePorts(0.09, 0.91) },
    trapezoid: { label: 'Trapezoid', group: 'general', w: 120, h: 60, path: (w, h) => poly([w * 0.18, 0], [w * 0.82, 0], [w, h], [0, h]), text: (w, h) => inset(w, h, 0.16, 0.06), ports: sidePorts(0.09, 0.91) },
    cylinder: { label: 'Cylinder', group: 'general', w: 70, h: 90, path: cylinder, extra: cylinderRim, text: (w, h) => ({ x: 4, y: cylinderRy(h) * 2, w: w - 8, h: h - cylinderRy(h) * 3 }) },
    cloud: {
        label: 'Cloud',
        group: 'general',
        w: 130,
        h: 85,
        perimeter: 'ellipse',
        path: (w, h) => {
            const sx = w / 120;
            const sy = h / 80;
            const p = (x, y) => `${r1(x * sx)} ${r1(y * sy)}`;
            const a = (rad, x, y) => `A${r1(rad * sx)} ${r1(rad * sy)} 0 0 1 ${p(x, y)}`;
            return `M${p(28, 72)}${a(17, 18, 40)}${a(22, 50, 16)}${a(20, 88, 20)}${a(18, 104, 52)}${a(15, 92, 72)}Z`;
        },
        text: (w, h) => inset(w, h, 0.18, 0.22),
    },
    document: {
        label: 'Document',
        group: 'general',
        w: 120,
        h: 70,
        path: (w, h) => `M0 0H${w}V${r1(h * 0.84)}C${r1(w * 0.75)} ${r1(h * 0.66)} ${r1(w * 0.25)} ${r1(h * 1.04)} 0 ${r1(h * 0.84)}Z`,
        text: (w, h) => ({ x: 4, y: 2, w: w - 8, h: h * 0.78 }),
    },
    note: {
        label: 'Note',
        group: 'general',
        w: 120,
        h: 80,
        path: (w, h) => poly([0, 0], [w - 16, 0], [w, 16], [w, h], [0, h]),
        extra: (w) => `M${w - 16} 0V16H${w}`,
        style: { fill: '#fff2cc', stroke: '#d6b656' },
    },
    callout: {
        label: 'Callout',
        group: 'general',
        w: 130,
        h: 80,
        path: (w, h) => poly([0, 0], [w, 0], [w, h * 0.72], [w * 0.42, h * 0.72], [w * 0.22, h], [w * 0.26, h * 0.72], [0, h * 0.72]),
        text: (w, h) => ({ x: 4, y: 2, w: w - 8, h: h * 0.68 }),
    },
    actor: {
        label: 'Actor',
        group: 'general',
        w: 40,
        h: 70,
        path: (w, h) => {
            const r = Math.min(w, h) * 0.18;
            const cx = w / 2;
            return `M${cx + r} ${r}A${r} ${r} 0 1 1 ${cx - r} ${r}A${r} ${r} 0 1 1 ${cx + r} ${r}`
                + `M${cx} ${r * 2}V${r1(h * 0.62)}M0 ${r1(h * 0.36)}H${w}M${cx} ${r1(h * 0.62)}L0 ${h}M${cx} ${r1(h * 0.62)}L${w} ${h}`;
        },
        text: (w, h) => ({ x: -50, y: h + 2, w: w + 100, h: 22 }),
        style: { fill: null, valign: 'top' },
    },
    text: { label: 'Text', group: 'general', w: 100, h: 30, path: rect, style: { fill: null, stroke: null }, defaultText: 'Text' },
    // Uploaded picture (node.src). Added through the library's "Image…" button, paste or drop.
    image: {
        label: 'Image',
        group: 'general',
        w: 160,
        h: 120,
        path: rect,
        image: true,
        library: false,
        text: (w, h) => ({ x: -50, y: h + 2, w: w + 100, h: 22 }),
        style: { fill: null, stroke: null, valign: 'top' },
    },

    // ── Flowchart ─────────────────────────────────────────────────────────────
    process: { label: 'Process', group: 'flowchart', w: 120, h: 60, path: rect },
    decision: { label: 'Decision', group: 'flowchart', w: 120, h: 80, path: diamond, perimeter: 'diamond', text: (w, h) => inset(w, h, 0.2) },
    terminator: { label: 'Start / End', group: 'flowchart', w: 120, h: 50, path: (w, h) => roundRect(w, h, h / 2), text: (w, h) => inset(w, h, 0.1, 0.04) },
    data: { label: 'Data (input/output)', group: 'flowchart', w: 120, h: 60, path: parallelogram, text: (w, h) => inset(w, h, 0.16, 0.06), ports: sidePorts(0.09, 0.91) },
    predefined: {
        label: 'Predefined process',
        group: 'flowchart',
        w: 120,
        h: 60,
        path: rect,
        extra: (w, h) => `M${r1(w * 0.1)} 0V${h}M${r1(w * 0.9)} 0V${h}`,
        text: (w, h) => inset(w, h, 0.12, 0.06),
    },
    'manual-input': { label: 'Manual input', group: 'flowchart', w: 120, h: 60, path: (w, h) => poly([0, h * 0.3], [w, 0], [w, h], [0, h]), text: (w, h) => ({ x: 4, y: h * 0.3, w: w - 8, h: h * 0.7 }) },
    'manual-operation': { label: 'Manual operation', group: 'flowchart', w: 120, h: 60, path: (w, h) => poly([0, 0], [w, 0], [w * 0.82, h], [w * 0.18, h]), text: (w, h) => inset(w, h, 0.16, 0.06) },
    preparation: { label: 'Preparation', group: 'flowchart', w: 120, h: 60, path: hexagon, text: (w, h) => inset(w, h, 0.18, 0.06) },
    delay: {
        label: 'Delay',
        group: 'flowchart',
        w: 100,
        h: 60,
        path: (w, h) => {
            const r = Math.min(h / 2, w / 2);
            return `M0 0H${w - r}A${r} ${h / 2} 0 0 1 ${w - r} ${h}H0Z`;
        },
        text: (w, h) => ({ x: 4, y: 4, w: w * 0.8, h: h - 8 }),
    },
    database: { label: 'Database', group: 'flowchart', w: 80, h: 90, path: cylinder, extra: cylinderRim, text: (w, h) => ({ x: 4, y: cylinderRy(h) * 2, w: w - 8, h: h - cylinderRy(h) * 3 }) },
    'internal-storage': {
        label: 'Internal storage',
        group: 'flowchart',
        w: 120,
        h: 70,
        path: rect,
        extra: (w, h) => `M${r1(w * 0.15)} 0V${h}M0 ${r1(h * 0.2)}H${w}`,
        text: (w, h) => ({ x: w * 0.15, y: h * 0.2, w: w * 0.85, h: h * 0.8 }),
    },
    'off-page': { label: 'Off-page reference', group: 'flowchart', w: 60, h: 70, path: (w, h) => poly([0, 0], [w, 0], [w, h * 0.62], [w / 2, h], [0, h * 0.62]), text: (w, h) => ({ x: 2, y: 2, w: w - 4, h: h * 0.6 }) },
    connector: { label: 'On-page connector', group: 'flowchart', w: 40, h: 40, path: ellipse, perimeter: 'ellipse' },
    merge: { label: 'Merge', group: 'flowchart', w: 70, h: 60, path: (w, h) => poly([0, 0], [w, 0], [w / 2, h]), text: (w, h) => ({ x: w * 0.2, y: 2, w: w * 0.6, h: h * 0.5 }) },
    display: {
        label: 'Display',
        group: 'flowchart',
        w: 120,
        h: 60,
        path: (w, h) => `M0 ${h / 2}L${r1(w * 0.18)} 0H${r1(w * 0.82)}A${r1(w * 0.18)} ${h / 2} 0 0 1 ${r1(w * 0.82)} ${h}H${r1(w * 0.18)}Z`,
        text: (w, h) => inset(w, h, 0.18, 0.06),
    },

    // ── Containers ────────────────────────────────────────────────────────────
    container: {
        label: 'Container',
        group: 'containers',
        w: 280,
        h: 180,
        container: true,
        path: (w, h) => roundRect(w, h, 8),
        text: (w) => ({ x: 6, y: 4, w: w - 12, h: 24 }),
        style: { fill: null, stroke: '#64748b', dashed: true, align: 'left', valign: 'top', bold: true, fontSize: 13 },
        defaultText: 'Group',
    },
    swimlane: {
        label: 'Swimlane (vertical)',
        group: 'containers',
        w: 220,
        h: 320,
        container: true,
        path: rect,
        extra: (w) => `M0 32H${w}`,
        text: (w) => ({ x: 4, y: 0, w: w - 8, h: 32 }),
        style: { bold: true },
        defaultText: 'Lane',
    },
    lane: {
        label: 'Lane (horizontal)',
        group: 'containers',
        w: 560,
        h: 150,
        container: true,
        path: rect,
        extra: (w, h) => `M32 0V${h}`,
        text: (w, h) => ({ x: 0, y: 0, w: 32, h, vertical: true }),
        style: { bold: true },
        defaultText: 'Lane',
    },
};

export const shapeOf = (node) => SHAPES[node?.shape] || SHAPES.rectangle;

export const DEFAULT_NODE_STYLE = {
    rotation: 0,
    fill: '#ffffff',
    stroke: '#1f2937',
    strokeWidth: 1.5,
    dashed: false,
    rounded: false,
    opacity: 1,
    fontSize: 14,
    fontColor: '#1f2937',
    bold: false,
    italic: false,
    align: 'center',
    valign: 'middle',
};

export const DEFAULT_EDGE_STYLE = {
    routing: 'orthogonal',
    stroke: '#1f2937',
    strokeWidth: 1.5,
    dashed: false,
    startArrow: 'none',
    endArrow: 'arrow',
    fontSize: 12,
    fontColor: '#1f2937',
};

export const makeNode = (shapeKey, x, y, overrides = {}) => {
    const shape = SHAPES[shapeKey] || SHAPES.rectangle;
    return {
        id: uid('n'),
        type: 'node',
        shape: SHAPES[shapeKey] ? shapeKey : 'rectangle',
        x: r1(x),
        y: r1(y),
        w: shape.w,
        h: shape.h,
        text: shape.defaultText || '',
        ...DEFAULT_NODE_STYLE,
        ...(shape.style || {}),
        ...overrides,
    };
};

export const makeEdge = (overrides = {}) => ({
    id: uid('c'),
    type: 'edge',
    source: null,
    sourcePort: null,
    target: null,
    targetPort: null,
    x1: 0,
    y1: 0,
    x2: 0,
    y2: 0,
    points: [],
    label: '',
    ...DEFAULT_EDGE_STYLE,
    ...overrides,
});

// ── Node geometry (absolute coordinates) ─────────────────────────────────────

export const PORT_DIRECTIONS = { n: { x: 0, y: -1 }, e: { x: 1, y: 0 }, s: { x: 0, y: 1 }, w: { x: -1, y: 0 } };

export const nodeCenter = (node) => ({ x: node.x + node.w / 2, y: node.y + node.h / 2 });

/** Rotate a point about `center` by `degrees` (clockwise on screen, like SVG rotate()). */
export const rotatePoint = (point, center, degrees) => {
    if (!degrees) return { x: point.x, y: point.y };
    const radians = (degrees * Math.PI) / 180;
    const cos = Math.cos(radians);
    const sin = Math.sin(radians);
    const dx = point.x - center.x;
    const dy = point.y - center.y;
    return { x: center.x + dx * cos - dy * sin, y: center.y + dx * sin + dy * cos };
};

// Elbow routing needs an axis-aligned exit; a rotated port leaves along its closest axis.
const axisOf = (vector) => (Math.abs(vector.x) >= Math.abs(vector.y)
    ? { x: Math.sign(vector.x) || 1, y: 0 }
    : { x: 0, y: Math.sign(vector.y) || 1 });

export const nodePorts = (node) => {
    const local = shapeOf(node).ports?.(node.w, node.h) || {
        n: [node.w / 2, 0], e: [node.w, node.h / 2], s: [node.w / 2, node.h], w: [0, node.h / 2],
    };
    const center = nodeCenter(node);
    const rotation = node.rotation || 0;
    return Object.fromEntries(Object.entries(local).map(([key, [x, y]]) => {
        const point = rotatePoint({ x: node.x + x, y: node.y + y }, center, rotation);
        const dir = rotation ? axisOf(rotatePoint(PORT_DIRECTIONS[key], { x: 0, y: 0 }, rotation)) : PORT_DIRECTIONS[key];
        return [key, { key, x: point.x, y: point.y, dir }];
    }));
};

export const nodeTextBox = (node) => {
    const box = shapeOf(node).text?.(node.w, node.h) || { x: 4, y: 2, w: node.w - 8, h: node.h - 4 };
    return { ...box, x: node.x + box.x, y: node.y + box.y };
};

/** Where a straight line from the node's centre toward `toward` crosses its outline. */
export const perimeterPoint = (node, toward) => {
    const c = nodeCenter(node);
    const rotation = node.rotation || 0;
    // Work in the shape's own (unrotated) frame, then rotate the answer back.
    const local = rotatePoint(toward, c, -rotation);
    const dx = local.x - c.x;
    const dy = local.y - c.y;
    if (!dx && !dy) return c;
    const hw = node.w / 2;
    const hh = node.h / 2;
    let t;
    switch (shapeOf(node).perimeter) {
        case 'ellipse':
            t = 1 / Math.sqrt((dx / hw) ** 2 + (dy / hh) ** 2);
            break;
        case 'diamond':
            t = 1 / (Math.abs(dx) / hw + Math.abs(dy) / hh);
            break;
        default:
            t = Math.min(dx ? hw / Math.abs(dx) : Infinity, dy ? hh / Math.abs(dy) : Infinity);
    }
    return rotatePoint({ x: c.x + dx * t, y: c.y + dy * t }, c, rotation);
};

/** The side a connector should leave from to head toward a point (used when no port is fixed). */
export const facingPort = (node, toward) => {
    const c = nodeCenter(node);
    const local = rotatePoint(toward, c, -(node.rotation || 0));
    const dx = (local.x - c.x) / Math.max(node.w, 1);
    const dy = (local.y - c.y) / Math.max(node.h, 1);
    if (Math.abs(dx) >= Math.abs(dy)) return dx >= 0 ? 'e' : 'w';
    return dy >= 0 ? 's' : 'n';
};
