import { computed, reactive, ref } from 'vue';

// ── Palette & defaults ────────────────────────────────────────────────────────

export const INK_COLORS = ['#111827', '#ef4444', '#f97316', '#eab308', '#16a34a', '#0284c7', '#4f46e5', '#9333ea', '#ffffff'];
export const STICKY_COLORS = ['#fef08a', '#fed7aa', '#fecaca', '#bbf7d0', '#bae6fd', '#ddd6fe'];
export const STROKE_SIZES = [2, 4, 8, 14];
// Text uses the same four size steps, mapped to font sizes.
export const TEXT_SIZES = { 2: 16, 4: 24, 8: 36, 14: 56 };
export const FONT_FAMILY = 'ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif';
export const PAGE_BACKGROUNDS = ['dots', 'grid', 'plain'];
export const CARD_DRAG_TYPE = 'application/x-task-card';

export const STICKY_PADDING = 14;
export const STICKY_FONT = 18;
export const STICKY_LINE = 1.3;
const CARD_PADDING = 14;

export const uid = (prefix = 'e') => `${prefix}${Date.now().toString(36)}${Math.random().toString(36).slice(2, 8)}`;
export const clone = (value) => (value == null ? value : JSON.parse(JSON.stringify(value)));
export const round1 = (n) => Math.round(n * 10) / 10;

export const newPage = (number) => ({ id: uid('p'), name: `Page ${number}`, background: 'dots', elements: [] });

export const normalizeScene = (scene) => {
    const pages = (Array.isArray(scene?.pages) ? scene.pages : [])
        .filter((page) => page && page.id)
        .map((page) => ({
            id: page.id,
            name: page.name || 'Page',
            background: PAGE_BACKGROUNDS.includes(page.background) ? page.background : 'dots',
            elements: Array.isArray(page.elements) ? page.elements : [],
        }));

    if (!pages.length) pages.push(newPage(1));

    return { version: 1, pages };
};

// ── Document: scene + undo/redo + dirty tracking for merge-on-conflict ────────

const emptyDirty = () => ({
    elements: new Map(), // pageId -> Set(element ids upserted locally)
    deleted: new Map(), // pageId -> Set(element ids deleted locally)
    pages: new Set(), // pages created or renamed locally
    deletedPages: new Set(),
    order: false,
});

const setFor = (map, key) => {
    if (!map.has(key)) map.set(key, new Set());
    return map.get(key);
};

export const mergeDirty = (target, source) => {
    for (const [pageId, ids] of source.elements) ids.forEach((id) => setFor(target.elements, pageId).add(id));
    for (const [pageId, ids] of source.deleted) ids.forEach((id) => setFor(target.deleted, pageId).add(id));
    source.pages.forEach((id) => target.pages.add(id));
    source.deletedPages.forEach((id) => target.deletedPages.add(id));
    target.order = target.order || source.order;
    return target;
};

const hasDirty = (dirty) => dirty.pages.size > 0
    || dirty.deletedPages.size > 0
    || dirty.order
    || [...dirty.elements.values()].some((ids) => ids.size)
    || [...dirty.deleted.values()].some((ids) => ids.size);

/**
 * Rebase local edits onto the scene someone else saved. Element-level last-writer-wins:
 * the server scene is the base, and every element/page this client touched since its
 * last successful save is re-applied on top.
 */
export const mergeScenes = (serverScene, localScene, dirty) => {
    const server = normalizeScene(clone(serverScene));
    const localPages = new Map(localScene.pages.map((page) => [page.id, page]));
    let pages = server.pages.filter((page) => !dirty.deletedPages.has(page.id));

    for (const local of localScene.pages) {
        const touched = dirty.pages.has(local.id) || dirty.elements.get(local.id)?.size;
        if (touched && !pages.some((page) => page.id === local.id)) pages.push(clone(local));
    }

    pages = pages.map((page) => {
        const local = localPages.get(page.id);
        if (!local) return page;

        if (dirty.pages.has(page.id)) {
            page.name = local.name;
            page.background = local.background;
        }

        const deletes = dirty.deleted.get(page.id) || new Set();
        const upserts = dirty.elements.get(page.id) || new Set();
        const elements = page.elements.filter((element) => !deletes.has(element.id));

        for (const id of upserts) {
            const mine = local.elements.find((element) => element.id === id);
            if (!mine) continue;
            const index = elements.findIndex((element) => element.id === id);
            if (index === -1) elements.push(clone(mine));
            else elements.splice(index, 1, clone(mine));
        }

        page.elements = elements;
        return page;
    });

    if (dirty.order) {
        const rank = new Map(localScene.pages.map((page, index) => [page.id, index]));
        pages.sort((a, b) => (rank.get(a.id) ?? Number.MAX_SAFE_INTEGER) - (rank.get(b.id) ?? Number.MAX_SAFE_INTEGER));
    }

    return normalizeScene({ pages });
};

export function createWhiteboardDocument(initialScene) {
    const scene = reactive(normalizeScene(clone(initialScene)));
    const undoStack = [];
    const redoStack = [];
    const historyVersion = ref(0);
    // Bumps on every local edit (not on remote replace) — the autosave trigger.
    const changeCount = ref(0);
    let dirty = emptyDirty();

    const pageById = (id) => scene.pages.find((page) => page.id === id) || null;

    const markElement = (pageId, id, removed) => {
        if (removed) {
            dirty.elements.get(pageId)?.delete(id);
            setFor(dirty.deleted, pageId).add(id);
        } else {
            dirty.deleted.get(pageId)?.delete(id);
            setFor(dirty.elements, pageId).add(id);
        }
    };

    const putElement = (page, element, index = -1) => {
        const current = page.elements.findIndex((item) => item.id === element.id);
        if (current !== -1) {
            page.elements.splice(current, 1, clone(element));
            return;
        }
        const at = index >= 0 ? Math.min(index, page.elements.length) : page.elements.length;
        page.elements.splice(at, 0, clone(element));
    };

    const removeElement = (page, id) => {
        const index = page.elements.findIndex((item) => item.id === id);
        if (index !== -1) page.elements.splice(index, 1);
    };

    const insertPage = (page, index) => {
        if (pageById(page.id)) return;
        scene.pages.splice(Math.min(Math.max(index, 0), scene.pages.length), 0, clone(page));
        dirty.pages.add(page.id);
        dirty.deletedPages.delete(page.id);
        dirty.order = true;
    };

    const dropPage = (id) => {
        const index = scene.pages.findIndex((page) => page.id === id);
        if (index === -1) return;
        scene.pages.splice(index, 1);
        dirty.deletedPages.add(id);
        dirty.pages.delete(id);
        dirty.order = true;
    };

    const placePage = (id, to) => {
        const from = scene.pages.findIndex((page) => page.id === id);
        if (from === -1) return;
        const [page] = scene.pages.splice(from, 1);
        scene.pages.splice(Math.min(Math.max(to, 0), scene.pages.length), 0, page);
        dirty.order = true;
    };

    const applyEntry = (entry, forward) => {
        if (entry.kind === 'elements') {
            const page = pageById(entry.pageId);
            if (!page) return;
            const changes = forward ? entry.changes : [...entry.changes].reverse();
            for (const change of changes) {
                const target = forward ? change.after : change.before;
                if (target) putElement(page, target, forward ? -1 : change.index);
                else removeElement(page, change.id);
                markElement(page.id, change.id, !target);
            }
        } else if (entry.kind === 'page') {
            const target = forward ? entry.after : entry.before;
            if (target) insertPage(target, entry.index);
            else dropPage((entry.after || entry.before).id);
        } else if (entry.kind === 'pageMeta') {
            const page = pageById(entry.id);
            if (!page) return;
            Object.assign(page, forward ? entry.after : entry.before);
            dirty.pages.add(page.id);
        } else if (entry.kind === 'pageMove') {
            placePage(entry.id, forward ? entry.to : entry.from);
        }
    };

    const record = (entry) => {
        undoStack.push(entry);
        if (undoStack.length > 200) undoStack.shift();
        redoStack.length = 0;
        historyVersion.value++;
        changeCount.value++;
    };

    /**
     * Apply element edits to a page and record them as one undo step.
     * Each change is { id, after } — `after: null` deletes; `before` is taken from the page.
     */
    const commit = (pageId, changes) => {
        const page = pageById(pageId);
        if (!page || !changes.length) return;

        const prepared = changes.map(({ id, after }) => {
            const index = page.elements.findIndex((item) => item.id === id);
            const change = { id, index, before: index === -1 ? null : clone(page.elements[index]), after: clone(after) };
            if (after) putElement(page, after);
            else removeElement(page, id);
            markElement(pageId, id, !after);
            return change;
        });

        record({ kind: 'elements', pageId, changes: prepared });
    };

    const addPage = (afterId = null) => {
        const index = afterId ? scene.pages.findIndex((page) => page.id === afterId) + 1 : scene.pages.length;
        const page = newPage(scene.pages.length + 1);
        insertPage(page, index);
        record({ kind: 'page', index, before: null, after: clone(page) });
        return page;
    };

    const duplicatePage = (id) => {
        const source = pageById(id);
        if (!source) return null;
        const index = scene.pages.indexOf(source) + 1;
        const page = {
            id: uid('p'),
            name: `${source.name} copy`.slice(0, 120),
            background: source.background,
            elements: source.elements.map((element) => ({ ...clone(element), id: uid() })),
        };
        insertPage(page, index);
        page.elements.forEach((element) => markElement(page.id, element.id, false));
        record({ kind: 'page', index, before: null, after: clone(page) });
        return page;
    };

    const removePage = (id) => {
        const index = scene.pages.findIndex((page) => page.id === id);
        if (index === -1 || scene.pages.length <= 1) return false;
        const before = clone(scene.pages[index]);
        dropPage(id);
        record({ kind: 'page', index, before, after: null });
        return true;
    };

    const updatePage = (id, patch) => {
        const page = pageById(id);
        if (!page) return;
        const before = { name: page.name, background: page.background };
        const after = { ...before, ...patch };
        if (before.name === after.name && before.background === after.background) return;
        Object.assign(page, after);
        dirty.pages.add(id);
        record({ kind: 'pageMeta', id, before, after });
    };

    const movePage = (id, to) => {
        const from = scene.pages.findIndex((page) => page.id === id);
        if (from === -1 || from === to) return;
        placePage(id, to);
        record({ kind: 'pageMove', id, from, to });
    };

    const undo = () => {
        const entry = undoStack.pop();
        if (!entry) return null;
        applyEntry(entry, false);
        redoStack.push(entry);
        historyVersion.value++;
        changeCount.value++;
        return entry;
    };

    const redo = () => {
        const entry = redoStack.pop();
        if (!entry) return null;
        applyEntry(entry, true);
        undoStack.push(entry);
        historyVersion.value++;
        changeCount.value++;
        return entry;
    };

    /** Replace the whole scene (remote update or merge result). Local history is kept. */
    const setScene = (next) => {
        const normalized = normalizeScene(clone(next));
        scene.pages.splice(0, scene.pages.length, ...normalized.pages);
    };

    const clearPage = (pageId) => {
        const page = pageById(pageId);
        if (!page?.elements.length) return;
        commit(pageId, page.elements.map((element) => ({ id: element.id, after: null })));
    };

    return {
        scene,
        changeCount,
        canUndo: computed(() => historyVersion.value >= 0 && undoStack.length > 0),
        canRedo: computed(() => historyVersion.value >= 0 && redoStack.length > 0),
        pageById,
        commit,
        clearPage,
        addPage,
        duplicatePage,
        removePage,
        updatePage,
        movePage,
        undo,
        redo,
        setScene,
        isDirty: () => hasDirty(dirty),
        takeDirty: () => {
            const taken = dirty;
            dirty = emptyDirty();
            return taken;
        },
        restoreDirty: (taken) => {
            dirty = mergeDirty(taken, dirty);
        },
        /** Someone else saved first: take their scene and re-apply everything still unsaved here. */
        rebase: (serverScene) => {
            setScene(mergeScenes(serverScene, { pages: scene.pages }, dirty));
        },
        toJSON: () => clone({ version: 1, pages: scene.pages }),
    };
}

// ── Text measurement & wrapping ───────────────────────────────────────────────

let measureContext = null;
const wrapCache = new Map();

export const measureText = (text, size, weight = 400) => {
    if (typeof document === 'undefined') return text.length * size * 0.55;
    if (!measureContext) measureContext = document.createElement('canvas').getContext('2d');
    measureContext.font = `${weight} ${size}px ${FONT_FAMILY}`;
    return measureContext.measureText(text).width;
};

export const wrapText = (text, maxWidth, size, weight = 400) => {
    const key = `${size}|${weight}|${Math.round(maxWidth)}|${text}`;
    if (wrapCache.has(key)) return wrapCache.get(key);
    if (wrapCache.size > 3000) wrapCache.clear();

    const lines = [];
    for (const paragraph of String(text ?? '').split('\n')) {
        let line = '';
        for (const word of paragraph.split(/(\s+)/)) {
            const candidate = line + word;
            if (!line || measureText(candidate, size, weight) <= maxWidth) {
                line = candidate;
                continue;
            }
            lines.push(line.trimEnd());
            line = word.trimStart();
            // A single word wider than the box is broken by characters.
            while (line && measureText(line, size, weight) > maxWidth) {
                let cut = line.length - 1;
                while (cut > 1 && measureText(line.slice(0, cut), size, weight) > maxWidth) cut--;
                lines.push(line.slice(0, cut));
                line = line.slice(cut);
            }
        }
        lines.push(line);
    }

    wrapCache.set(key, lines);
    return lines;
};

// ── Element layout ────────────────────────────────────────────────────────────

export const textLines = (element) => String(element.text ?? '').split('\n');

export const stickyLayout = (element) => {
    const w = Math.max(80, element.w || 200);
    const lines = wrapText(element.text || '', w - STICKY_PADDING * 2, STICKY_FONT);
    const needed = lines.length * STICKY_FONT * STICKY_LINE + STICKY_PADDING * 2;
    return { w, h: Math.max(element.h || 160, needed), lines };
};

const formatDue = (value) => {
    if (!value) return '';
    const date = new Date(String(value).replace(' ', 'T'));
    if (Number.isNaN(date.getTime())) return '';
    return `Due ${date.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })}`;
};

/** Card snapshot layout; `live` is the current card from the board when it still exists. */
export const cardLayout = (element, live) => {
    const w = Math.max(160, element.w || 260);
    const title = live?.title ?? element.title ?? 'Card';
    const assignees = live?.assignees || [];
    const dueDate = live?.due_at ? new Date(String(live.due_at).replace(' ', 'T')) : null;
    const overdue = !!dueDate && !live?.due_complete && dueDate.getTime() < Date.now();

    let titleLines = wrapText(title, w - CARD_PADDING * 2, 15, 700);
    if (titleLines.length > 4) titleLines = [...titleLines.slice(0, 3), `${titleLines[3].replace(/\s*\S{0,3}$/, '')}…`];

    const metaParts = [];
    if (live?.due_at) metaParts.push(`${formatDue(live.due_at)}${live.due_complete ? ' ✓' : ''}`);
    if (assignees.length) metaParts.push(assignees.length > 2 ? `${assignees.slice(0, 2).join(', ')} +${assignees.length - 2}` : assignees.join(', '));
    const meta = metaParts.join(' · ');

    const statusY = 6 + 12 + 10;
    const titleY = statusY + 22;
    const metaY = titleY + (titleLines.length - 1) * 20 + 22;
    const h = (meta ? metaY : titleY + (titleLines.length - 1) * 20) + 16;

    return {
        w,
        h,
        title,
        titleLines,
        status: live ? live.status : (element.status || ''),
        color: live?.color || element.color || '#64748b',
        meta,
        overdue,
        missing: !live,
        statusY,
        titleY,
        metaY,
    };
};

// ── Geometry ──────────────────────────────────────────────────────────────────

export const elementBounds = (element, cardsById = {}) => {
    const pad = (element.size || 0) / 2;
    switch (element.type) {
        case 'pen': {
            const p = element.points || [];
            let minX = Infinity; let minY = Infinity; let maxX = -Infinity; let maxY = -Infinity;
            for (let i = 0; i < p.length; i += 2) {
                minX = Math.min(minX, p[i]); maxX = Math.max(maxX, p[i]);
                minY = Math.min(minY, p[i + 1]); maxY = Math.max(maxY, p[i + 1]);
            }
            if (!Number.isFinite(minX)) return { x: 0, y: 0, w: 0, h: 0 };
            return { x: minX - pad, y: minY - pad, w: maxX - minX + pad * 2, h: maxY - minY + pad * 2 };
        }
        case 'line':
        case 'arrow':
        case 'rect':
        case 'ellipse': {
            const x = Math.min(element.x1, element.x2);
            const y = Math.min(element.y1, element.y2);
            return { x: x - pad, y: y - pad, w: Math.abs(element.x2 - element.x1) + pad * 2, h: Math.abs(element.y2 - element.y1) + pad * 2 };
        }
        case 'text': {
            const size = element.size || 24;
            const lines = textLines(element);
            const w = Math.max(size * 0.5, ...lines.map((line) => measureText(line, size)));
            return { x: element.x, y: element.y, w, h: lines.length * size * 1.25 };
        }
        case 'sticky': {
            const { w, h } = stickyLayout(element);
            return { x: element.x, y: element.y, w, h };
        }
        case 'card': {
            const { w, h } = cardLayout(element, cardsById[element.cardId]);
            return { x: element.x, y: element.y, w, h };
        }
        default:
            return { x: 0, y: 0, w: 0, h: 0 };
    }
};

export const unionBounds = (boxes) => {
    if (!boxes.length) return null;
    const x = Math.min(...boxes.map((b) => b.x));
    const y = Math.min(...boxes.map((b) => b.y));
    const x2 = Math.max(...boxes.map((b) => b.x + b.w));
    const y2 = Math.max(...boxes.map((b) => b.y + b.h));
    return { x, y, w: x2 - x, h: y2 - y };
};

export const contentBounds = (elements, cardsById = {}) => unionBounds(elements.map((element) => elementBounds(element, cardsById)));

const distToSegment = (px, py, x1, y1, x2, y2) => {
    const dx = x2 - x1;
    const dy = y2 - y1;
    const lengthSq = dx * dx + dy * dy;
    const t = lengthSq ? Math.max(0, Math.min(1, ((px - x1) * dx + (py - y1) * dy) / lengthSq)) : 0;
    return Math.hypot(px - (x1 + t * dx), py - (y1 + t * dy));
};

const inBox = (box, x, y, tolerance) => x >= box.x - tolerance && x <= box.x + box.w + tolerance && y >= box.y - tolerance && y <= box.y + box.h + tolerance;

/** True when world point (x, y) touches the element within `tolerance` world units. */
export const hitTest = (element, x, y, tolerance, cardsById = {}) => {
    const reach = tolerance + (element.size || 0) / 2;
    switch (element.type) {
        case 'pen': {
            const p = element.points || [];
            if (!inBox(elementBounds(element), x, y, tolerance)) return false;
            if (p.length <= 2) return Math.hypot(x - p[0], y - p[1]) <= reach;
            for (let i = 0; i < p.length - 2; i += 2) {
                if (distToSegment(x, y, p[i], p[i + 1], p[i + 2], p[i + 3]) <= reach) return true;
            }
            return false;
        }
        case 'line':
        case 'arrow':
            return distToSegment(x, y, element.x1, element.y1, element.x2, element.y2) <= reach;
        case 'ellipse': {
            const rx = Math.abs(element.x2 - element.x1) / 2 + reach;
            const ry = Math.abs(element.y2 - element.y1) / 2 + reach;
            const cx = (element.x1 + element.x2) / 2;
            const cy = (element.y1 + element.y2) / 2;
            return ((x - cx) / rx) ** 2 + ((y - cy) / ry) ** 2 <= 1;
        }
        default:
            return inBox(elementBounds(element, cardsById), x, y, tolerance);
    }
};

export const boxesIntersect = (a, b) => a.x <= b.x + b.w && a.x + a.w >= b.x && a.y <= b.y + b.h && a.y + a.h >= b.y;

export const translateElement = (element, dx, dy) => {
    const moved = clone(element);
    if (moved.type === 'pen') {
        moved.points = moved.points.map((value, index) => round1(value + (index % 2 === 0 ? dx : dy)));
    } else if ('x1' in moved) {
        moved.x1 = round1(moved.x1 + dx); moved.x2 = round1(moved.x2 + dx);
        moved.y1 = round1(moved.y1 + dy); moved.y2 = round1(moved.y2 + dy);
    } else {
        moved.x = round1(moved.x + dx);
        moved.y = round1(moved.y + dy);
    }
    return moved;
};

/** Resize handles a single selected element exposes, in world coordinates. */
export const resizeHandles = (element, cardsById = {}) => {
    if (element.type === 'line' || element.type === 'arrow') {
        return [{ key: 'start', x: element.x1, y: element.y1 }, { key: 'end', x: element.x2, y: element.y2 }];
    }
    if (['rect', 'ellipse', 'sticky', 'card'].includes(element.type)) {
        const box = elementBounds({ ...element, size: 0 }, cardsById);
        return [{ key: 'se', x: box.x + box.w, y: box.y + box.h }];
    }
    return [];
};

export const resizeElement = (element, handle, x, y) => {
    const next = clone(element);
    if (handle === 'start') { next.x1 = round1(x); next.y1 = round1(y); return next; }
    if (handle === 'end') { next.x2 = round1(x); next.y2 = round1(y); return next; }
    if (next.type === 'rect' || next.type === 'ellipse') {
        const left = Math.min(next.x1, next.x2);
        const top = Math.min(next.y1, next.y2);
        Object.assign(next, { x1: left, y1: top, x2: round1(Math.max(left + 4, x)), y2: round1(Math.max(top + 4, y)) });
        return next;
    }
    if (next.type === 'sticky') {
        next.w = round1(Math.max(100, x - next.x));
        next.h = round1(Math.max(80, y - next.y));
        return next;
    }
    if (next.type === 'card') next.w = round1(Math.max(180, x - next.x));
    return next;
};

/** Constrain a shape drag: squares/circles, and 45° steps for lines. */
export const constrainShape = (type, x1, y1, x2, y2) => {
    const dx = x2 - x1;
    const dy = y2 - y1;
    if (type === 'rect' || type === 'ellipse') {
        const side = Math.max(Math.abs(dx), Math.abs(dy));
        return { x2: x1 + Math.sign(dx || 1) * side, y2: y1 + Math.sign(dy || 1) * side };
    }
    const angle = Math.round(Math.atan2(dy, dx) / (Math.PI / 4)) * (Math.PI / 4);
    const length = Math.hypot(dx, dy);
    return { x2: x1 + Math.cos(angle) * length, y2: y1 + Math.sin(angle) * length };
};

// ── SVG path helpers ──────────────────────────────────────────────────────────

/** Smooth freehand path through the points using midpoint quadratic curves. */
export const penPath = (points = []) => {
    if (points.length < 2) return '';
    if (points.length <= 4) {
        const [x1, y1, x2 = x1 + 0.1, y2 = y1] = points;
        return `M${x1} ${y1}L${x2 === x1 && y2 === y1 ? x1 + 0.1 : x2} ${y2}`;
    }
    let d = `M${points[0]} ${points[1]}`;
    for (let i = 2; i < points.length - 2; i += 2) {
        const mx = round1((points[i] + points[i + 2]) / 2);
        const my = round1((points[i + 1] + points[i + 3]) / 2);
        d += `Q${points[i]} ${points[i + 1]} ${mx} ${my}`;
    }
    return `${d}L${points[points.length - 2]} ${points[points.length - 1]}`;
};

export const arrowHeadPath = (element) => {
    const angle = Math.atan2(element.y2 - element.y1, element.x2 - element.x1);
    const length = Math.max(12, (element.size || 4) * 3.5);
    const spread = Math.PI / 7;
    const ax = element.x2 - length * Math.cos(angle - spread);
    const ay = element.y2 - length * Math.sin(angle - spread);
    const bx = element.x2 - length * Math.cos(angle + spread);
    const by = element.y2 - length * Math.sin(angle + spread);
    return `M${round1(ax)} ${round1(ay)}L${element.x2} ${element.y2}L${round1(bx)} ${round1(by)}`;
};

// ── Export ────────────────────────────────────────────────────────────────────

/**
 * Render the content group of a whiteboard SVG to a PNG download. Elements are pure SVG
 * (no foreignObject), so the canvas is never tainted.
 */
export const exportGroupToPng = async (group, bounds, filename, background = '#ffffff') => {
    const pad = 40;
    const box = bounds && bounds.w > 0 && bounds.h > 0 ? bounds : { x: 0, y: 0, w: 800, h: 600 };
    const vb = { x: box.x - pad, y: box.y - pad, w: box.w + pad * 2, h: box.h + pad * 2 };
    const scale = Math.min(2, 8000 / Math.max(vb.w, vb.h));

    const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${vb.w}" height="${vb.h}" viewBox="${vb.x} ${vb.y} ${vb.w} ${vb.h}">`
        + `<rect x="${vb.x}" y="${vb.y}" width="${vb.w}" height="${vb.h}" fill="${background}"/>`
        + `${group.innerHTML}</svg>`;

    const image = new Image();
    image.src = `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}`;
    await image.decode();

    const canvas = document.createElement('canvas');
    canvas.width = Math.ceil(vb.w * scale);
    canvas.height = Math.ceil(vb.h * scale);
    const context = canvas.getContext('2d');
    context.scale(scale, scale);
    context.drawImage(image, 0, 0, vb.w, vb.h);

    const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/png'));
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = filename;
    link.click();
    setTimeout(() => URL.revokeObjectURL(link.href), 1000);
};
