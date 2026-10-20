import { round1, uid } from '@/lib/sceneDocument';
import { urlToDataUrl } from '@/lib/svgExport';
import { DEFAULT_EDGE_STYLE, DEFAULT_NODE_STYLE, SHAPES, makeEdge, makeNode } from './shapes';
import { newDiagramPage } from './templates';

/**
 * draw.io (.drawio / diagrams.net) import and export.
 *
 * Export writes an uncompressed <mxfile>: one <diagram> per page, shapes as vertices with
 * draw.io style strings, connectors as edges (ports → exit/entry, bends → points), groups
 * as draw.io "group" cells, pictures embedded as data URIs.
 *
 * Import reads compressed or plain files, maps draw.io's built-in and flowchart shapes onto
 * ours (anything unknown becomes a rectangle and is counted), resolves nested group /
 * container coordinates, and uploads embedded pictures so they live with the diagram.
 */

const PORT_XY = { n: [0.5, 0], e: [1, 0.5], s: [0.5, 1], w: [0, 0.5] };

const SHAPE_STYLES = {
    rectangle: (node) => `rounded=${node.rounded ? 1 : 0};whiteSpace=wrap;html=1;`,
    rounded: () => 'rounded=1;whiteSpace=wrap;html=1;',
    ellipse: () => 'ellipse;whiteSpace=wrap;html=1;',
    circle: () => 'ellipse;whiteSpace=wrap;html=1;aspect=fixed;',
    diamond: () => 'rhombus;whiteSpace=wrap;html=1;',
    triangle: () => 'triangle;whiteSpace=wrap;html=1;direction=north;',
    hexagon: () => 'shape=hexagon;perimeter=hexagonPerimeter2;whiteSpace=wrap;html=1;fixedSize=1;',
    parallelogram: () => 'shape=parallelogram;perimeter=parallelogramPerimeter;whiteSpace=wrap;html=1;fixedSize=1;',
    trapezoid: () => 'shape=trapezoid;perimeter=trapezoidPerimeter;whiteSpace=wrap;html=1;fixedSize=1;',
    cylinder: () => 'shape=cylinder3;whiteSpace=wrap;html=1;boundedLbl=1;backgroundOutline=1;size=15;',
    cloud: () => 'ellipse;shape=cloud;whiteSpace=wrap;html=1;',
    document: () => 'shape=document;whiteSpace=wrap;html=1;boundedLbl=1;',
    note: () => 'shape=note;whiteSpace=wrap;html=1;backgroundOutline=1;size=15;',
    callout: () => 'shape=callout;whiteSpace=wrap;html=1;perimeter=calloutPerimeter;',
    actor: () => 'shape=umlActor;verticalLabelPosition=bottom;verticalAlign=top;html=1;outlineConnect=0;',
    text: () => 'text;html=1;whiteSpace=wrap;',
    process: () => 'rounded=0;whiteSpace=wrap;html=1;',
    decision: () => 'rhombus;whiteSpace=wrap;html=1;',
    terminator: () => 'shape=mxgraph.flowchart.terminator;whiteSpace=wrap;html=1;',
    data: () => 'shape=parallelogram;perimeter=parallelogramPerimeter;whiteSpace=wrap;html=1;fixedSize=1;',
    predefined: () => 'shape=process;whiteSpace=wrap;html=1;backgroundOutline=1;',
    'manual-input': () => 'shape=manualInput;whiteSpace=wrap;html=1;',
    'manual-operation': () => 'shape=mxgraph.flowchart.manual_operation;whiteSpace=wrap;html=1;',
    preparation: () => 'shape=hexagon;perimeter=hexagonPerimeter2;whiteSpace=wrap;html=1;fixedSize=1;',
    delay: () => 'shape=delay;whiteSpace=wrap;html=1;',
    database: () => 'shape=cylinder3;whiteSpace=wrap;html=1;boundedLbl=1;backgroundOutline=1;size=15;',
    'internal-storage': () => 'shape=internalStorage;whiteSpace=wrap;html=1;backgroundOutline=1;',
    'off-page': () => 'shape=offPageConnector;whiteSpace=wrap;html=1;',
    connector: () => 'ellipse;whiteSpace=wrap;html=1;aspect=fixed;',
    merge: () => 'triangle;whiteSpace=wrap;html=1;direction=south;',
    display: () => 'shape=display;whiteSpace=wrap;html=1;',
    container: () => 'rounded=1;whiteSpace=wrap;html=1;container=1;collapsible=0;arcSize=4;',
    swimlane: () => 'swimlane;whiteSpace=wrap;html=1;startSize=32;',
    lane: () => 'swimlane;horizontal=0;whiteSpace=wrap;html=1;startSize=32;',
    image: () => 'shape=image;verticalLabelPosition=bottom;verticalAlign=top;imageAspect=1;aspect=fixed;',
};

const ARROWS_OUT = { none: ['none', 0], arrow: ['classic', 1], open: ['open', 0], block: ['block', 1], diamond: ['diamond', 1], circle: ['oval', 1] };

const escapeHtml = (text) => String(text ?? '')
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

const labelHtml = (text) => escapeHtml(text).replace(/\n/g, '<br>');

// draw.io keeps data URIs in style strings without ";base64" (";" separates style keys).
const styleDataUri = (dataUrl) => dataUrl.replace(';base64,', ',');

// ── Export ───────────────────────────────────────────────────────────────────

const nodeStyle = (node, image) => {
    let style = (SHAPE_STYLES[node.shape] || SHAPE_STYLES.rectangle)(node);
    const add = (key, value) => { style += `${key}=${value};`; };
    if (!SHAPES[node.shape]?.image) {
        add('fillColor', node.fill || 'none');
        add('strokeColor', node.stroke || 'none');
    } else if (node.stroke) {
        add('imageBorder', node.stroke);
    }
    add('strokeWidth', node.strokeWidth ?? 1.5);
    if (node.dashed) add('dashed', 1);
    if ((node.opacity ?? 1) < 1) add('opacity', Math.round(node.opacity * 100));
    add('fontSize', node.fontSize || 14);
    add('fontColor', node.fontColor || '#1f2937');
    const fontStyle = (node.bold ? 1 : 0) | (node.italic ? 2 : 0);
    if (fontStyle) add('fontStyle', fontStyle);
    if (node.align && node.align !== 'center') add('align', node.align);
    if (node.valign && node.valign !== 'middle') add('verticalAlign', node.valign);
    if (node.rotation) add('rotation', node.rotation);
    if (image) add('image', image);
    return style;
};

const edgeStyle = (edge) => {
    let style = {
        orthogonal: 'edgeStyle=orthogonalEdgeStyle;rounded=0;orthogonalLoop=1;jettySize=auto;html=1;',
        curved: 'edgeStyle=orthogonalEdgeStyle;curved=1;orthogonalLoop=1;jettySize=auto;html=1;',
    }[edge.routing] || 'rounded=0;html=1;';
    const add = (key, value) => { style += `${key}=${value};`; };
    const [end, endFill] = ARROWS_OUT[edge.endArrow] || ARROWS_OUT.arrow;
    const [start, startFill] = ARROWS_OUT[edge.startArrow] || ARROWS_OUT.none;
    add('endArrow', end);
    add('endFill', endFill);
    add('startArrow', start);
    add('startFill', startFill);
    add('strokeColor', edge.stroke || '#1f2937');
    add('strokeWidth', edge.strokeWidth ?? 1.5);
    if (edge.dashed) add('dashed', 1);
    add('fontSize', edge.fontSize || 12);
    add('fontColor', edge.fontColor || '#1f2937');
    if (edge.source && edge.sourcePort) {
        const [x, y] = PORT_XY[edge.sourcePort];
        style += `exitX=${x};exitY=${y};exitDx=0;exitDy=0;`;
    }
    if (edge.target && edge.targetPort) {
        const [x, y] = PORT_XY[edge.targetPort];
        style += `entryX=${x};entryY=${y};entryDx=0;entryDy=0;`;
    }
    return style;
};

export const exportDrawio = async (scene) => {
    const xml = document.implementation.createDocument(null, 'mxfile', null);
    const mxfile = xml.documentElement;
    mxfile.setAttribute('host', 'ghelpdesk');
    mxfile.setAttribute('type', 'device');

    const element = (tag, attributes = {}, parent = null) => {
        const node = xml.createElement(tag);
        for (const [key, value] of Object.entries(attributes)) {
            if (value !== null && value !== undefined) node.setAttribute(key, String(value));
        }
        if (parent) parent.appendChild(node);
        return node;
    };
    const point = (parent, x, y, as = null) => element('mxPoint', { x: round1(x), y: round1(y), as }, parent);

    const images = new Map();
    for (const page of scene.pages) {
        for (const node of page.elements) {
            if (node.type === 'node' && node.src && !images.has(node.src)) {
                const data = await urlToDataUrl(node.src);
                images.set(node.src, data ? styleDataUri(data) : null);
            }
        }
    }

    for (const page of scene.pages) {
        const diagram = element('diagram', { name: page.name || 'Page', id: page.id }, mxfile);
        const model = element('mxGraphModel', {
            dx: 1200, dy: 800, grid: page.grid === false ? 0 : 1, gridSize: 10, guides: 1, tooltips: 1, connect: 1,
            arrows: 1, fold: 1, page: 0, pageScale: 1, pageWidth: 1169, pageHeight: 827, math: 0, shadow: 0,
            background: page.background || '#ffffff',
        }, diagram);
        const root = element('root', {}, model);
        element('mxCell', { id: '0' }, root);
        element('mxCell', { id: '1', parent: '0' }, root);

        // Groups become draw.io group cells; members are placed relative to them.
        const groups = new Map();
        for (const item of page.elements) {
            if (!item.groupId || item.type !== 'node') continue;
            const box = groups.get(item.groupId) || { x1: Infinity, y1: Infinity, x2: -Infinity, y2: -Infinity };
            groups.set(item.groupId, {
                x1: Math.min(box.x1, item.x), y1: Math.min(box.y1, item.y),
                x2: Math.max(box.x2, item.x + item.w), y2: Math.max(box.y2, item.y + item.h),
            });
        }
        for (const [groupId, box] of groups) {
            const cell = element('mxCell', { id: groupId, value: '', style: 'group', vertex: 1, connectable: 0, parent: '1' }, root);
            element('mxGeometry', { x: round1(box.x1), y: round1(box.y1), width: round1(box.x2 - box.x1), height: round1(box.y2 - box.y1), as: 'geometry' }, cell);
        }
        const origin = (item) => {
            const box = item.groupId ? groups.get(item.groupId) : null;
            return box ? { parent: item.groupId, dx: box.x1, dy: box.y1 } : { parent: '1', dx: 0, dy: 0 };
        };

        for (const item of page.elements) {
            const { parent, dx, dy } = origin(item);
            if (item.type === 'node') {
                const cell = element('mxCell', {
                    id: item.id,
                    value: labelHtml(item.text),
                    style: nodeStyle(item, item.src ? images.get(item.src) : null),
                    vertex: 1,
                    parent,
                }, root);
                element('mxGeometry', { x: round1(item.x - dx), y: round1(item.y - dy), width: round1(item.w), height: round1(item.h), as: 'geometry' }, cell);
            } else if (item.type === 'edge') {
                const cell = element('mxCell', {
                    id: item.id,
                    value: labelHtml(item.label),
                    style: edgeStyle(item),
                    edge: 1,
                    parent,
                    source: item.source || null,
                    target: item.target || null,
                }, root);
                const geometry = element('mxGeometry', { relative: 1, as: 'geometry' }, cell);
                if (!item.source) point(geometry, item.x1 - dx, item.y1 - dy, 'sourcePoint');
                if (!item.target) point(geometry, item.x2 - dx, item.y2 - dy, 'targetPoint');
                const bends = item.points || [];
                if (bends.length) {
                    const array = element('Array', { as: 'points' }, geometry);
                    for (let i = 0; i + 1 < bends.length; i += 2) point(array, bends[i] - dx, bends[i + 1] - dy);
                }
            }
        }
    }

    return `<?xml version="1.0" encoding="UTF-8"?>\n${new XMLSerializer().serializeToString(xml)}`;
};

// ── Import ───────────────────────────────────────────────────────────────────

const parseStyle = (style = '') => {
    const parsed = { _names: [] };
    for (const part of String(style).split(';')) {
        if (!part) continue;
        const at = part.indexOf('=');
        if (at === -1) parsed._names.push(part.trim());
        else parsed[part.slice(0, at).trim()] = part.slice(at + 1).trim();
    }
    return parsed;
};

const has = (style, name) => style._names.includes(name);

const htmlToText = (value) => {
    if (!value) return '';
    const html = String(value)
        .replace(/<br\s*\/?>/gi, '\n')
        .replace(/<\/(div|p|li|h[1-6])>/gi, '\n');
    // DOMParser documents never run scripts; textContent decodes entities.
    const text = new DOMParser().parseFromString(`<body>${html}</body>`, 'text/html').body.textContent || '';
    return text.replace(/ /g, ' ').replace(/\n{3,}/g, '\n\n').trim();
};

const color = (value, fallback) => {
    if (!value || value === 'default' || value === 'inherit') return fallback;
    if (value === 'none') return null;
    return /^#[0-9a-fA-F]{3,8}$/.test(value) ? value : fallback;
};

const number = (value, fallback) => {
    const n = Number(value);
    return Number.isFinite(n) ? n : fallback;
};

/** Which of our shapes a draw.io style maps to; `approximated` when there is no real match. */
const shapeFor = (style, width, height) => {
    const shape = style.shape || '';
    const flow = shape.startsWith('mxgraph.flowchart.') ? shape.slice('mxgraph.flowchart.'.length) : null;
    const flowMap = {
        terminator: 'terminator', process: 'process', decision: 'decision', data: 'data', predefined_process: 'predefined',
        manual_input: 'manual-input', manual_operation: 'manual-operation', preparation: 'preparation', delay: 'delay',
        database: 'database', stored_data: 'database', internal_storage: 'internal-storage', 'off-page_reference': 'off-page',
        'on-page_reference': 'connector', merge_or_storage: 'merge', extract_or_measurement: 'triangle', display: 'display',
        document: 'document', multi_document: 'document', start_1: 'ellipse', start_2: 'circle',
    };
    if (flow && flowMap[flow]) return { key: flowMap[flow] };

    const byShape = {
        cloud: 'cloud', hexagon: 'hexagon', parallelogram: 'parallelogram', trapezoid: 'trapezoid',
        cylinder: 'cylinder', cylinder2: 'cylinder', cylinder3: 'cylinder', datastore: 'database',
        document: 'document', note: 'note', note2: 'note', callout: 'callout', umlActor: 'actor',
        process: 'predefined', manualInput: 'manual-input', delay: 'delay', internalStorage: 'internal-storage',
        offPageConnector: 'off-page', display: 'display', image: 'image', rhombus: 'diamond',
        ellipse: 'ellipse', doubleEllipse: 'ellipse', triangle: 'triangle', swimlane: 'swimlane',
    };
    if (shape === 'trapezoid' && style.flipV === '1') return { key: 'manual-operation' };
    if (byShape[shape]) return { key: byShape[shape] };

    if (has(style, 'image')) return { key: 'image' };
    if (has(style, 'swimlane')) return { key: style.horizontal === '0' ? 'lane' : 'swimlane' };
    if (has(style, 'text')) return { key: 'text' };
    if (has(style, 'rhombus')) return { key: 'diamond' };
    if (has(style, 'triangle')) {
        // Ours points up; draw.io's points east unless told otherwise.
        const rotation = { north: 0, east: 90, south: 180, west: 270 }[style.direction || 'east'];
        return { key: 'triangle', rotation };
    }
    if (has(style, 'ellipse')) return { key: style.aspect === 'fixed' || Math.abs(width - height) < 1 ? 'circle' : 'ellipse' };
    if (style.container === '1') return { key: 'container' };
    if (shape && shape !== 'label') return { key: style.rounded === '1' ? 'rounded' : 'rectangle', approximated: true };
    return { key: style.rounded === '1' ? 'rounded' : 'rectangle' };
};

const ARROWS_IN = {
    none: 'none', classic: 'arrow', classicThin: 'arrow', open: 'open', openThin: 'open', openAsync: 'open',
    block: 'block', blockThin: 'block', diamond: 'diamond', diamondThin: 'diamond', oval: 'circle', circle: 'circle',
    circlePlus: 'circle', dot: 'circle',
};

const arrowFrom = (kind, fill, fallback) => {
    if (kind === undefined) return fallback;
    const mapped = ARROWS_IN[kind] || (kind ? 'arrow' : 'none');
    return mapped === 'block' && fill === '0' ? 'open' : mapped;
};

const portFrom = (x, y) => {
    if (x === undefined || y === undefined) return null;
    const px = Number(x);
    const py = Number(y);
    return Object.entries(PORT_XY).find(([, [ex, ey]]) => Math.abs(ex - px) < 0.01 && Math.abs(ey - py) < 0.01)?.[0] || null;
};

const inflate = async (base64) => {
    const bytes = Uint8Array.from(atob(base64), (char) => char.charCodeAt(0));
    const stream = new Blob([bytes]).stream().pipeThrough(new DecompressionStream('deflate-raw'));
    const text = await new Response(stream).text();
    try {
        return decodeURIComponent(text);
    } catch {
        return text;
    }
};

const dataUriToBlob = (uri) => {
    const match = uri.match(/^data:([^,;]+)(;base64)?,(.*)$/s);
    if (!match) return null;
    const [, type, , payload] = match;
    if (!/^image\/(png|jpe?g|gif|webp)$/.test(type)) return null;
    try {
        const bytes = Uint8Array.from(atob(payload), (char) => char.charCodeAt(0));
        return new Blob([bytes], { type });
    } catch {
        return null;
    }
};

const readModels = async (text) => {
    const xml = new DOMParser().parseFromString(text, 'application/xml');
    if (xml.querySelector('parsererror')) throw new Error('This is not a draw.io file (the XML could not be read).');

    const root = xml.documentElement;
    if (root.nodeName === 'mxGraphModel') return [{ name: 'Page 1', model: root }];
    if (root.nodeName !== 'mxfile') throw new Error('This is not a draw.io file.');

    const models = [];
    for (const diagram of root.getElementsByTagName('diagram')) {
        let model = diagram.getElementsByTagName('mxGraphModel')[0];
        if (!model && diagram.textContent.trim()) {
            const inflated = await inflate(diagram.textContent.trim());
            model = new DOMParser().parseFromString(inflated, 'application/xml').documentElement;
        }
        if (model?.nodeName === 'mxGraphModel') models.push({ name: diagram.getAttribute('name') || `Page ${models.length + 1}`, model });
    }
    if (!models.length) throw new Error('No pages were found in this draw.io file.');
    return models;
};

/**
 * Parse a .drawio file into diagram pages.
 * `uploadImage(File)` stores an embedded picture and resolves to { url }.
 * Returns { pages, warnings: { approximated, images, truncated } }.
 */
export const importDrawio = async (text, { uploadImage = null } = {}) => {
    const warnings = { approximated: 0, images: 0, truncated: 0 };
    const pages = [];

    for (const { name, model } of (await readModels(text)).slice(0, 30)) {
        const rootNode = model.getElementsByTagName('root')[0];
        if (!rootNode) continue;

        // Cells, unwrapping <UserObject>/<object> (draw.io's custom-property wrapper).
        const cells = [];
        for (const child of rootNode.children) {
            if (child.nodeName === 'mxCell') {
                cells.push({ el: child, id: child.getAttribute('id'), value: child.getAttribute('value') });
            } else if (child.nodeName === 'UserObject' || child.nodeName === 'object') {
                const inner = child.getElementsByTagName('mxCell')[0];
                if (inner) cells.push({ el: inner, id: child.getAttribute('id'), value: child.getAttribute('label') });
            }
        }

        const byId = new Map();
        for (const cell of cells) {
            const geometryEl = [...cell.el.children].find((el) => el.nodeName === 'mxGeometry');
            const pointsOf = (as) => {
                const point = geometryEl ? [...geometryEl.children].find((el) => el.nodeName === 'mxPoint' && el.getAttribute('as') === as) : null;
                return point ? { x: number(point.getAttribute('x'), 0), y: number(point.getAttribute('y'), 0) } : null;
            };
            const array = geometryEl ? [...geometryEl.children].find((el) => el.nodeName === 'Array' && el.getAttribute('as') === 'points') : null;
            byId.set(cell.id, {
                id: cell.id,
                parent: cell.el.getAttribute('parent'),
                value: cell.value ?? '',
                style: parseStyle(cell.el.getAttribute('style') || ''),
                vertex: cell.el.getAttribute('vertex') === '1',
                edge: cell.el.getAttribute('edge') === '1',
                source: cell.el.getAttribute('source'),
                target: cell.el.getAttribute('target'),
                geometry: geometryEl ? {
                    x: number(geometryEl.getAttribute('x'), 0),
                    y: number(geometryEl.getAttribute('y'), 0),
                    w: number(geometryEl.getAttribute('width'), 0),
                    h: number(geometryEl.getAttribute('height'), 0),
                    points: array ? [...array.children].filter((el) => el.nodeName === 'mxPoint').map((el) => ({ x: number(el.getAttribute('x'), 0), y: number(el.getAttribute('y'), 0) })) : [],
                    sourcePoint: pointsOf('sourcePoint'),
                    targetPoint: pointsOf('targetPoint'),
                } : null,
            });
        }

        // Absolute origin of a cell's coordinate space (vertices nest: groups, containers, lanes).
        const originCache = new Map();
        const originOf = (id) => {
            if (originCache.has(id)) return originCache.get(id);
            const cell = byId.get(id);
            let origin = { x: 0, y: 0 };
            if (cell?.vertex && cell.geometry) {
                const parentOrigin = originOf(cell.parent);
                origin = { x: parentOrigin.x + cell.geometry.x, y: parentOrigin.y + cell.geometry.y };
            }
            originCache.set(id, origin);
            return origin;
        };
        const groupOf = (id) => {
            let cell = byId.get(byId.get(id)?.parent);
            while (cell) {
                if (cell.vertex && has(cell.style, 'group')) return cell.id;
                cell = byId.get(cell.parent);
            }
            return null;
        };

        const ids = new Map();
        const newId = (cellId, prefix) => {
            if (!ids.has(cellId)) ids.set(cellId, uid(prefix));
            return ids.get(cellId);
        };
        const groupIds = new Map();
        const groupIdOf = (cellId) => {
            const group = groupOf(cellId);
            if (!group) return null;
            if (!groupIds.has(group)) groupIds.set(group, uid('g'));
            return groupIds.get(group);
        };

        const elements = [];
        const nodeBoxes = new Map();
        const edgeLabels = new Map();

        // Edge labels stored as child cells of the edge.
        for (const cell of byId.values()) {
            if (cell.vertex && has(cell.style, 'edgeLabel') && byId.get(cell.parent)?.edge) {
                const text = cell.style.html === '1' ? htmlToText(cell.value) : cell.value;
                if (text) edgeLabels.set(cell.parent, [...(edgeLabels.get(cell.parent) || []), text]);
            }
        }

        for (const cell of byId.values()) {
            if (!cell.vertex || !cell.geometry || has(cell.style, 'group') || has(cell.style, 'edgeLabel')) continue;
            const origin = originOf(cell.parent);
            const x = origin.x + cell.geometry.x;
            const y = origin.y + cell.geometry.y;
            const w = Math.max(cell.geometry.w, 1);
            const h = Math.max(cell.geometry.h, 1);
            const text = cell.style.html === '1' ? htmlToText(cell.value) : String(cell.value || '');

            // A horizontal "line" vertex becomes a plain connector.
            if (has(cell.style, 'line')) {
                elements.push(makeEdge({
                    id: newId(cell.id, 'c'), x1: round1(x), y1: round1(y + h / 2), x2: round1(x + w), y2: round1(y + h / 2),
                    routing: 'straight', endArrow: 'none', stroke: color(cell.style.strokeColor, '#000000') || '#000000', groupId: groupIdOf(cell.id),
                }));
                continue;
            }

            const mapped = shapeFor(cell.style, w, h);
            if (mapped.approximated) warnings.approximated++;
            const shape = SHAPES[mapped.key];
            const isText = mapped.key === 'text';
            const fontStyle = number(cell.style.fontStyle, 0);

            const node = makeNode(mapped.key, x, y, {
                id: newId(cell.id, 'n'),
                w: round1(w),
                h: round1(h),
                text,
                rotation: round1(((number(cell.style.rotation, 0) + (mapped.rotation || 0)) % 360 + 360) % 360),
                fill: shape?.image ? null : color(cell.style.fillColor, isText ? null : '#ffffff'),
                stroke: shape?.image ? color(cell.style.imageBorder, null) : color(cell.style.strokeColor, isText ? null : '#000000'),
                strokeWidth: number(cell.style.strokeWidth, 1),
                dashed: cell.style.dashed === '1',
                rounded: cell.style.rounded === '1',
                opacity: Math.min(1, Math.max(0.05, number(cell.style.opacity, 100) / 100)),
                fontSize: number(cell.style.fontSize, 12),
                fontColor: color(cell.style.fontColor, '#000000') || '#000000',
                bold: (fontStyle & 1) === 1,
                italic: (fontStyle & 2) === 2,
                align: ['left', 'right'].includes(cell.style.align) ? cell.style.align : 'center',
                valign: ['top', 'bottom'].includes(cell.style.verticalAlign) ? cell.style.verticalAlign : (shape?.style?.valign || DEFAULT_NODE_STYLE.valign),
                groupId: groupIdOf(cell.id),
            });

            if (shape?.image) {
                node.src = null;
                const source = cell.style.image || '';
                let blob = null;
                if (source.startsWith('data:')) {
                    // draw.io drops ";base64" from style data URIs.
                    blob = dataUriToBlob(source.includes(';base64,') ? source : source.replace(/^data:([^,]+),/, 'data:$1;base64,'));
                } else if (/^https?:\/\//.test(source)) {
                    try {
                        const response = await fetch(source);
                        if (response.ok) blob = await response.blob();
                    } catch {
                        blob = null;
                    }
                }
                if (blob && uploadImage && /^image\/(png|jpe?g|gif|webp)$/.test(blob.type)) {
                    try {
                        const ext = blob.type.split('/')[1].replace('jpeg', 'jpg');
                        node.src = (await uploadImage(new File([blob], `drawio-image.${ext}`, { type: blob.type }))).url;
                    } catch {
                        node.src = null;
                    }
                }
                if (!node.src) warnings.images++;
            }

            elements.push(node);
            nodeBoxes.set(cell.id, { x, y, w, h });
        }

        for (const cell of byId.values()) {
            if (!cell.edge) continue;
            const origin = originOf(cell.parent);
            const geometry = cell.geometry || { points: [], sourcePoint: null, targetPoint: null };
            const source = nodeBoxes.has(cell.source) ? cell.source : null;
            const target = nodeBoxes.has(cell.target) ? cell.target : null;
            // A terminal we did not import (e.g. a group) still anchors where it was.
            const centerOf = (id) => {
                const cellBox = byId.get(id);
                if (!cellBox?.geometry) return null;
                const o = originOf(cellBox.parent);
                return { x: o.x + cellBox.geometry.x + cellBox.geometry.w / 2, y: o.y + cellBox.geometry.y + cellBox.geometry.h / 2 };
            };
            const start = geometry.sourcePoint ? { x: origin.x + geometry.sourcePoint.x, y: origin.y + geometry.sourcePoint.y } : (centerOf(cell.source) || { x: 0, y: 0 });
            const end = geometry.targetPoint ? { x: origin.x + geometry.targetPoint.x, y: origin.y + geometry.targetPoint.y } : (centerOf(cell.target) || { x: 0, y: 0 });
            const routing = cell.style.curved === '1'
                ? 'curved'
                : (cell.style.edgeStyle && cell.style.edgeStyle !== 'none' ? 'orthogonal' : 'straight');
            const label = [cell.style.html === '1' ? htmlToText(cell.value) : String(cell.value || ''), ...(edgeLabels.get(cell.id) || [])].filter(Boolean).join('\n');

            elements.push(makeEdge({
                id: newId(cell.id, 'c'),
                source: source ? newId(source, 'n') : null,
                target: target ? newId(target, 'n') : null,
                sourcePort: source ? portFrom(cell.style.exitX, cell.style.exitY) : null,
                targetPort: target ? portFrom(cell.style.entryX, cell.style.entryY) : null,
                x1: round1(start.x), y1: round1(start.y), x2: round1(end.x), y2: round1(end.y),
                points: geometry.points.slice(0, 200).flatMap((point) => [round1(origin.x + point.x), round1(origin.y + point.y)]),
                routing,
                label,
                stroke: color(cell.style.strokeColor, '#000000') || '#000000',
                strokeWidth: number(cell.style.strokeWidth, 1),
                dashed: cell.style.dashed === '1',
                startArrow: arrowFrom(cell.style.startArrow, cell.style.startFill, 'none'),
                endArrow: arrowFrom(cell.style.endArrow, cell.style.endFill, DEFAULT_EDGE_STYLE.endArrow),
                fontSize: number(cell.style.fontSize, 11),
                fontColor: color(cell.style.fontColor, '#000000') || '#000000',
                groupId: groupIdOf(cell.id),
            }));
        }

        // A group of one is not a group.
        const groupSizes = new Map();
        for (const item of elements) if (item.groupId) groupSizes.set(item.groupId, (groupSizes.get(item.groupId) || 0) + 1);
        for (const item of elements) if (item.groupId && groupSizes.get(item.groupId) < 2) item.groupId = null;

        if (elements.length > 3000) warnings.truncated += elements.length - 3000;

        const background = model.getAttribute('background');
        pages.push({
            ...newDiagramPage(pages.length + 1),
            name: name.slice(0, 120),
            background: /^#[0-9a-fA-F]{3,8}$/.test(background || '') ? background : '#ffffff',
            grid: model.getAttribute('grid') !== '0',
            elements: elements.slice(0, 3000),
        });
    }

    if (!pages.length) throw new Error('No pages were found in this draw.io file.');
    return { pages, warnings };
};

/** Human summary of what an import could not carry over exactly. */
export const importNotes = (warnings) => [
    warnings.approximated ? `${warnings.approximated} shape${warnings.approximated === 1 ? '' : 's'} with no match here became rectangles` : null,
    warnings.images ? `${warnings.images} picture${warnings.images === 1 ? '' : 's'} could not be brought in` : null,
    warnings.truncated ? `${warnings.truncated} element${warnings.truncated === 1 ? '' : 's'} over the 3,000-per-page limit were left out` : null,
].filter(Boolean);
