import { computed, reactive, ref } from 'vue';

/**
 * Shared editing model for page-based canvases (task-board whiteboard, diagrams).
 *
 * A scene is `{ version, pages: [{ id, ...meta, elements: [{ id, type, ... }] }] }`.
 * The document owns undo/redo and tracks what changed locally since the last
 * successful save, so a save refused with 409 can be rebased onto the scene
 * someone else saved (element-level last-writer-wins) instead of overwriting it.
 *
 * Each canvas supplies `newPage(number)` and `normalizePage(page)` for its own page meta.
 */

export const uid = (prefix = 'e') => `${prefix}${Date.now().toString(36)}${Math.random().toString(36).slice(2, 8)}`;
export const clone = (value) => (value == null ? value : JSON.parse(JSON.stringify(value)));
export const round1 = (n) => Math.round(n * 10) / 10;

export const normalizeScene = (scene, { newPage, normalizePage }) => {
    const pages = (Array.isArray(scene?.pages) ? scene.pages : [])
        .filter((page) => page && page.id)
        .map((page) => ({
            ...normalizePage(page),
            id: page.id,
            elements: Array.isArray(page.elements) ? page.elements : [],
        }));

    if (!pages.length) pages.push(newPage(1));

    return { version: 1, pages };
};

const pageMeta = (page) => {
    const { id, elements, ...meta } = page;
    return meta;
};

// ── Dirty tracking ───────────────────────────────────────────────────────────

const emptyDirty = () => ({
    elements: new Map(), // pageId -> Set(element ids upserted locally)
    deleted: new Map(), // pageId -> Set(element ids deleted locally)
    pages: new Set(), // pages created or whose meta changed locally
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
 * Rebase local edits onto the scene someone else saved: the server scene is the base,
 * and every element/page this client touched since its last successful save is
 * re-applied on top.
 */
export const mergeScenes = (serverScene, localScene, dirty, pageOptions) => {
    const server = normalizeScene(clone(serverScene), pageOptions);
    const localPages = new Map(localScene.pages.map((page) => [page.id, page]));
    let pages = server.pages.filter((page) => !dirty.deletedPages.has(page.id));

    for (const local of localScene.pages) {
        const touched = dirty.pages.has(local.id) || dirty.elements.get(local.id)?.size;
        if (touched && !pages.some((page) => page.id === local.id)) pages.push(clone(local));
    }

    pages = pages.map((page) => {
        const local = localPages.get(page.id);
        if (!local) return page;

        if (dirty.pages.has(page.id)) Object.assign(page, clone(pageMeta(local)));

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

    return normalizeScene({ pages }, pageOptions);
};

// ── Document ─────────────────────────────────────────────────────────────────

export function createSceneDocument(initialScene, pageOptions) {
    const scene = reactive(normalizeScene(clone(initialScene), pageOptions));
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
        } else if (entry.kind === 'reorder') {
            const page = pageById(entry.pageId);
            if (!page) return;
            const order = forward ? entry.after : entry.before;
            const rank = new Map(order.map((id, index) => [id, index]));
            page.elements.sort((a, b) => (rank.get(a.id) ?? Number.MAX_SAFE_INTEGER) - (rank.get(b.id) ?? Number.MAX_SAFE_INTEGER));
            page.elements.forEach((element) => markElement(page.id, element.id, false));
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

    /** Re-order a page's elements (z-order) to `ids` as one undo step. */
    const reorder = (pageId, ids) => {
        const page = pageById(pageId);
        if (!page) return;
        const before = page.elements.map((element) => element.id);
        if (before.join() === ids.join()) return;
        const entry = { kind: 'reorder', pageId, before, after: [...ids] };
        applyEntry(entry, true);
        record(entry);
    };

    const addPage = (afterId = null) => {
        const index = afterId ? scene.pages.findIndex((page) => page.id === afterId) + 1 : scene.pages.length;
        const page = pageOptions.newPage(scene.pages.length + 1);
        insertPage(page, index);
        record({ kind: 'page', index, before: null, after: clone(page) });
        return page;
    };

    /** Insert ready-made pages (e.g. from an imported file) after `afterId`; one undo step each. */
    const insertPages = (pages, afterId = null) => {
        let index = afterId ? scene.pages.findIndex((page) => page.id === afterId) + 1 : scene.pages.length;
        const inserted = [];
        for (const source of pages) {
            const page = { ...clone(source), id: uid('p') };
            insertPage(page, index);
            record({ kind: 'page', index, before: null, after: clone(page) });
            inserted.push(page);
            index++;
        }
        return inserted;
    };

    const duplicatePage = (id) => {
        const source = pageById(id);
        if (!source) return null;
        const index = scene.pages.indexOf(source) + 1;
        const page = {
            ...clone(pageMeta(source)),
            id: uid('p'),
            name: `${source.name} copy`.slice(0, 120),
            elements: remapIds(source.elements),
        };
        insertPage(page, index);
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
        const before = Object.fromEntries(Object.keys(patch).map((key) => [key, page[key]]));
        if (Object.keys(patch).every((key) => before[key] === patch[key])) return;
        Object.assign(page, patch);
        dirty.pages.add(id);
        record({ kind: 'pageMeta', id, before, after: { ...patch } });
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
        const normalized = normalizeScene(clone(next), pageOptions);
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
        reorder,
        clearPage,
        addPage,
        insertPages,
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
            setScene(mergeScenes(serverScene, { pages: scene.pages }, dirty, pageOptions));
        },
        toJSON: () => clone({ version: 1, pages: scene.pages }),
    };
}

/**
 * Copy elements with fresh ids, rewriting references between them (diagram edges point at
 * node ids through `source` / `target`; grouped elements share a `groupId`, which gets a
 * fresh id too so the copy is its own group).
 */
export const remapIds = (elements) => {
    const ids = new Map(elements.map((element) => [element.id, uid()]));
    const groups = new Map();
    return elements.map((element) => {
        const copy = { ...clone(element), id: ids.get(element.id) };
        if (copy.source && ids.has(copy.source)) copy.source = ids.get(copy.source);
        if (copy.target && ids.has(copy.target)) copy.target = ids.get(copy.target);
        if (copy.groupId) {
            if (!groups.has(copy.groupId)) groups.set(copy.groupId, uid('g'));
            copy.groupId = groups.get(copy.groupId);
        }
        return copy;
    });
};
