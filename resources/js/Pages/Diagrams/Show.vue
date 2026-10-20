<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import CanvasHeader from '@/Components/SharedCanvas/CanvasHeader.vue';
import DiagramCanvas from '@/Components/Diagram/DiagramCanvas.vue';
import DiagramElements from '@/Components/Diagram/DiagramElements.vue';
import FormatPanel from '@/Components/Diagram/FormatPanel.vue';
import ShapeLibrary from '@/Components/Diagram/ShapeLibrary.vue';
import { createDiagramDocument } from '@/Components/Diagram/templates';
import { contentBounds } from '@/Components/Diagram/geometry';
import { arrange, deleteSelection, duplicateSelection, groupSelection, ungroupSelection } from '@/Components/Diagram/actions';
import { exportDrawio, importDrawio, importNotes } from '@/Components/Diagram/drawio';
import { readFileText, uploadDiagramImage } from '@/Components/Diagram/upload';
import { downloadBlob } from '@/lib/svgExport';
import { useConfirm } from '@/Composables/useConfirm';
import { useToast } from '@/Composables/useToast';
import { useSceneSync } from '@/Composables/useSceneSync';
import { useFillViewport } from '@/Composables/useFillViewport';
import {
    ArrowDownTrayIcon,
    ArrowUpTrayIcon,
    ChevronLeftIcon,
    ChevronRightIcon,
    DocumentDuplicateIcon,
    PlayIcon,
    PlusIcon,
    TrashIcon,
    XMarkIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({
    diagram: { type: Object, required: true },
    can: { type: Object, default: () => ({}) },
});

const { confirm } = useConfirm();
const { showError, showSuccess } = useToast();

const doc = createDiagramDocument(props.diagram.scene);
const canvas = ref(null);
const pageRoot = ref(null);
let fittedOnce = false;
const { height: fitHeight, measure: measureHeight } = useFillViewport(pageRoot, {
    // The canvas fitted itself before the page reached its final height — fit again once.
    onResize: () => {
        if (fittedOnce) return;
        fittedOnce = true;
        canvas.value?.fitToContent();
    },
});
const editable = ref(!!props.can.edit);
const selection = ref([]);
const title = ref(props.diagram.title);
const visibility = ref(props.diagram.visibility);

// Per-viewer editor preferences.
const readPref = (key, fallback) => {
    try {
        return JSON.parse(localStorage.getItem(`ghelpdesk.diagram.${key}`) ?? 'null') ?? fallback;
    } catch {
        return fallback;
    }
};
const writePref = (key, value) => {
    try {
        localStorage.setItem(`ghelpdesk.diagram.${key}`, JSON.stringify(value));
    } catch {
        // Preferences are a convenience only.
    }
};
const snap = ref(readPref('snap', true));
const showFormat = ref(readPref('format', true));
const edgeStyle = reactive({ routing: readPref('routing', 'orthogonal') });
watch(snap, (value) => writePref('snap', value));
watch(showFormat, (value) => writePref('format', value));
watch(() => edgeStyle.routing, (value) => writePref('routing', value));

// ── Pages ────────────────────────────────────────────────────────────────────

const initialPageId = () => {
    const requested = new URLSearchParams(window.location.search).get('page');
    return doc.pageById(requested)?.id || doc.scene.pages[0].id;
};
const currentPageId = ref(initialPageId());
const currentPage = computed(() => doc.pageById(currentPageId.value) || doc.scene.pages[0]);
const currentIndex = computed(() => doc.scene.pages.indexOf(currentPage.value));

watch(() => doc.scene.pages.map((page) => page.id).join(','), () => {
    if (!doc.pageById(currentPageId.value)) currentPageId.value = doc.scene.pages[0]?.id;
});

watch(currentPageId, (id) => {
    selection.value = [];
    const url = new URL(window.location.href);
    url.searchParams.set('page', id);
    window.history.replaceState(window.history.state, '', url);
});

const stepPage = (delta) => {
    const next = doc.scene.pages[currentIndex.value + delta];
    if (next) currentPageId.value = next.id;
};

const addPage = () => {
    currentPageId.value = doc.addPage(currentPageId.value).id;
};

const duplicatePage = (page) => {
    const copy = doc.duplicatePage(page.id);
    if (copy) currentPageId.value = copy.id;
};

const deletePage = async (page) => {
    if (doc.scene.pages.length <= 1) return;
    const ok = await confirm({
        title: 'Delete page',
        message: `Delete "${page.name}" and everything on it? You can undo this with Ctrl+Z.`,
        confirmLabel: 'Delete page',
        variant: 'danger',
    });
    if (ok) doc.removePage(page.id);
};

const renamingPageId = ref(null);
const pageName = ref('');
const pageNameInput = ref(null);
const startRenamePage = (page) => {
    if (!editable.value) return;
    renamingPageId.value = page.id;
    pageName.value = page.name;
    nextTick(() => pageNameInput.value?.[0]?.select());
};
const finishRenamePage = () => {
    const id = renamingPageId.value;
    renamingPageId.value = null;
    const name = pageName.value.trim().slice(0, 120);
    if (id && name) doc.updatePage(id, { name });
};

const onHistory = (entry) => {
    const pageId = entry.pageId || entry.id || (entry.after || entry.before)?.id;
    if (pageId && doc.pageById(pageId)) currentPageId.value = pageId;
};

// ── Saving & live updates ────────────────────────────────────────────────────

const previewHost = ref(null);
const firstPage = computed(() => doc.scene.pages[0]);
const previewBox = computed(() => {
    const box = contentBounds(firstPage.value.elements);
    if (!box) return '0 0 320 200';
    const pad = Math.max(box.w, box.h) * 0.06 + 10;
    return `${box.x - pad} ${box.y - pad} ${box.w + pad * 2} ${box.h + pad * 2}`;
});

/**
 * Small SVG of page 1 for the list cards (shown via <img>, so it can never run script — and
 * cannot load pictures either, so each picture becomes a grey block of the same size).
 */
const pictureBlock = (attributes) => {
    const read = (name) => attributes.match(new RegExp(`\\b${name}="([^"]*)"`))?.[1] ?? '0';
    return `<rect x="${read('x')}" y="${read('y')}" width="${read('width')}" height="${read('height')}" fill="#e2e8f0"/>`;
};

const buildPreview = () => {
    const svg = previewHost.value?.querySelector('svg');
    if (!svg) return null;
    let markup = svg.outerHTML
        .replace(/<!--[\s\S]*?-->/g, '')
        .replace(/ data-[a-z-]+="[^"]*"/g, '')
        .replace(/<image\b([^>]*?)\/?>(<\/image>)?/g, (match, attributes) => pictureBlock(attributes));
    if (markup.length > 58000) markup = markup.replace(/<text[\s\S]*?<\/text>/g, '');
    return markup.length > 58000 ? null : markup;
};

const { saveState, saveError, lastSavedAt, lastSavedBy } = useSceneSync({
    doc,
    editable,
    initialRevision: props.diagram.revision || 0,
    initialSavedAt: props.diagram.updated_at,
    initialSavedBy: props.diagram.editor,
    saveUrl: () => route('diagrams.scene', props.diagram.id),
    stateUrl: () => route('diagrams.state', props.diagram.id),
    savePayload: () => ({ preview: buildPreview() }),
    onPoll: (data) => {
        if (data.title) title.value = data.title;
        if (data.visibility) visibility.value = data.visibility;
    },
    onError: (message) => showError(message),
});

const formatDate = (value) => {
    if (!value) return '';
    const date = new Date(String(value).replace(' ', 'T'));
    return Number.isNaN(date.getTime()) ? '' : date.toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
};

const saveLabel = computed(() => {
    if (!editable.value) return saveError.value || 'View only';
    if (saveState.value === 'saving') return 'Saving…';
    if (saveState.value === 'pending') return 'Unsaved changes';
    if (saveState.value === 'error') return saveError.value;
    if (!lastSavedAt.value) return 'All changes saved';
    return `Saved ${formatDate(lastSavedAt.value)}${lastSavedBy.value ? ` · ${lastSavedBy.value}` : ''}`;
});

// ── Toolbar actions ──────────────────────────────────────────────────────────

const deleteSelected = () => {
    deleteSelection(doc, currentPage.value.id, selection.value);
    selection.value = [];
};
const duplicateSelected = () => {
    selection.value = duplicateSelection(doc, currentPage.value.id, selection.value);
};

const exporting = ref(false);
const showExportMenu = ref(false);
const safeName = (name) => name.replace(/[\\/:*?"<>|]+/g, '-').slice(0, 120);
const exportAs = async (kind) => {
    showExportMenu.value = false;
    if (exporting.value) return;
    exporting.value = true;
    try {
        if (kind === 'drawio') {
            canvas.value?.commitEdit();
            const xml = await exportDrawio(doc.toJSON());
            downloadBlob(new Blob([xml], { type: 'application/xml' }), `${safeName(title.value)}.drawio`);
            return;
        }
        const name = safeName(`${title.value} - ${currentPage.value.name}`);
        if (kind === 'svg') await canvas.value?.exportSvg(`${name}.svg`);
        else await canvas.value?.exportPng(`${name}.png`);
    } catch {
        showError('Could not export this diagram.');
    } finally {
        exporting.value = false;
    }
};

// ── Pictures & draw.io import ────────────────────────────────────────────────

const pictureInput = ref(null);
const drawioInput = ref(null);
const importing = ref(false);

const onPicturesPicked = (event) => {
    const files = [...(event.target.files || [])];
    event.target.value = '';
    if (files.length) canvas.value?.insertImages(files);
};

/** Add the pages of a .drawio file after the current page (each page is one undo step). */
const onDrawioPicked = async (event) => {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file || importing.value) return;
    importing.value = true;
    try {
        const { pages, warnings } = await importDrawio(await readFileText(file), { uploadImage: uploadDiagramImage });
        const room = 30 - doc.scene.pages.length;
        if (room <= 0) {
            showError('A diagram can have at most 30 pages.');
            return;
        }
        const inserted = doc.insertPages(pages.slice(0, room), currentPage.value.id);
        currentPageId.value = inserted[0].id;
        const notes = importNotes(warnings);
        if (pages.length > room) notes.push(`${pages.length - room} page(s) over the 30-page limit were left out`);
        showSuccess(`Imported ${inserted.length} ${inserted.length === 1 ? 'page' : 'pages'} from ${file.name}${notes.length ? ` — ${notes.join('; ')}` : ''}.`);
    } catch (error) {
        showError(error?.message || 'Could not import this file.');
    } finally {
        importing.value = false;
    }
};

// ── Present mode ─────────────────────────────────────────────────────────────

const presenting = ref(false);
let enteredFullscreen = false;

const startPresenting = async () => {
    canvas.value?.commitEdit();
    selection.value = [];
    presenting.value = true;
    try {
        if (document.fullscreenEnabled && !document.fullscreenElement) {
            await document.documentElement.requestFullscreen();
            enteredFullscreen = true;
        }
    } catch {
        enteredFullscreen = false;
    }
    setTimeout(() => canvas.value?.fitToContent({ maxZoom: 2 }), 80);
};

const stopPresenting = async () => {
    if (!presenting.value) return;
    presenting.value = false;
    if (enteredFullscreen && document.fullscreenElement) await document.exitFullscreen().catch(() => {});
    enteredFullscreen = false;
    setTimeout(() => {
        measureHeight();
        canvas.value?.fitToContent();
    }, 80);
};

const onFullscreenChange = () => {
    if (!document.fullscreenElement && enteredFullscreen) {
        enteredFullscreen = false;
        stopPresenting();
    }
};

watch(currentPageId, () => {
    if (presenting.value) nextTick(() => canvas.value?.fitToContent({ maxZoom: 2 }));
}, { flush: 'post' });

const onKeyDown = (event) => {
    if (!presenting.value) return;
    const tag = event.target?.tagName;
    if (['INPUT', 'TEXTAREA', 'SELECT'].includes(tag) || document.querySelector('dialog[open]')) return;
    if (event.key === 'Escape') stopPresenting();
    else if (['ArrowRight', 'ArrowDown', 'PageDown'].includes(event.key)) {
        event.preventDefault();
        stepPage(1);
    } else if (['ArrowLeft', 'ArrowUp', 'PageUp'].includes(event.key)) {
        event.preventDefault();
        stepPage(-1);
    }
};

const closeMenus = (event) => {
    if (!event.target.closest?.('[data-menu]')) {
        showExportMenu.value = false;
    }
};

onMounted(() => {
    window.addEventListener('keydown', onKeyDown);
    document.addEventListener('fullscreenchange', onFullscreenChange);
    document.addEventListener('click', closeMenus);
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeyDown);
    document.removeEventListener('fullscreenchange', onFullscreenChange);
    document.removeEventListener('click', closeMenus);
    if (enteredFullscreen && document.fullscreenElement) document.exitFullscreen().catch(() => {});
});

const toolButton = 'inline-flex h-8 min-w-8 items-center justify-center gap-1 rounded-md px-1.5 text-gray-600 hover:bg-gray-100 disabled:opacity-30 disabled:hover:bg-transparent dark:text-gray-300 dark:hover:bg-gray-800';
const TOOL_ICONS = {
    undo: 'M9 14L4 9l5-5M4 9h11a5 5 0 0 1 0 10h-3',
    redo: 'M15 14l5-5-5-5m5 5H9a5 5 0 0 0 0 10h3',
    front: 'M8 8h12v12H8zM4 4h12v4M4 4v12h4',
    back: 'M4 4h12v12H4zM8 16v4h12V8h-4',
    fit: 'M4 9V4h5M20 9V4h-5M4 15v5h5m11-5v5h-5',
    format: 'M4 6h16M4 12h10M4 18h16',
    group: 'M3 3h18v18H3zM7 7h5v5H7zM12 12h5v5h-5z',
    ungroup: 'M3 3h8v8H3zM13 13h8v8h-8z',
};

const hasGroupInSelection = computed(() => {
    const ids = new Set(selection.value);
    return (currentPage.value?.elements || []).some((element) => ids.has(element.id) && element.groupId);
});
</script>

<template>
    <Head :title="`${title} · Diagram`" />

    <AppLayout content-class="max-w-none px-0 sm:px-0 lg:px-0">
        <template #header>Diagrams</template>

        <div
            ref="pageRoot"
            class="flex flex-col overflow-hidden bg-gray-100 text-gray-900 dark:bg-gray-950 dark:text-gray-100"
            :class="presenting ? 'fixed inset-0 z-[60] h-screen' : (fitHeight ? '' : 'h-[calc(100vh-6rem)]')"
            :style="!presenting && fitHeight ? { height: `${fitHeight}px` } : null"
            data-testid="diagram-page"
        >
            <!-- Header -->
            <CanvasHeader
                v-if="!presenting"
                v-model:title="title"
                v-model:visibility="visibility"
                :record-id="diagram.id"
                routes="diagrams"
                noun="diagram"
                :creator="diagram.creator"
                :can="can"
                :editable="editable"
                :save-state="saveState"
                :save-label="saveLabel"
                test-prefix="diagram"
            >
                <template #actions>
                    <div class="relative" data-menu>
                        <button type="button" class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-semibold text-gray-700 hover:bg-gray-100 disabled:opacity-50 dark:text-gray-200 dark:hover:bg-gray-800" :disabled="exporting" data-testid="diagram-export" @click="showExportMenu = !showExportMenu">
                            <ArrowDownTrayIcon class="h-4 w-4" />
                            Export
                        </button>
                        <div v-if="showExportMenu" class="absolute right-0 top-full z-30 mt-1 w-52 rounded-lg border border-gray-200 bg-white p-1 shadow-lg dark:border-gray-700 dark:bg-gray-800">
                            <button type="button" class="block w-full rounded-md px-3 py-1.5 text-left text-sm hover:bg-gray-50 dark:hover:bg-gray-700" data-testid="diagram-export-png" @click="exportAs('png')">PNG image <span class="text-xs text-gray-400">(this page)</span></button>
                            <button type="button" class="block w-full rounded-md px-3 py-1.5 text-left text-sm hover:bg-gray-50 dark:hover:bg-gray-700" data-testid="diagram-export-svg" @click="exportAs('svg')">SVG vector <span class="text-xs text-gray-400">(this page)</span></button>
                            <button type="button" class="block w-full rounded-md px-3 py-1.5 text-left text-sm hover:bg-gray-50 dark:hover:bg-gray-700" data-testid="diagram-export-drawio" @click="exportAs('drawio')">draw.io file <span class="text-xs text-gray-400">(all pages)</span></button>
                        </div>
                    </div>
                    <button v-if="editable" type="button" class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-semibold text-gray-700 hover:bg-gray-100 disabled:opacity-50 dark:text-gray-200 dark:hover:bg-gray-800" :disabled="importing" title="Add the pages of a .drawio file to this diagram" data-testid="diagram-import" @click="drawioInput?.click()">
                        <ArrowUpTrayIcon class="h-4 w-4" />
                        {{ importing ? 'Importing…' : 'Import .drawio' }}
                    </button>
                    <button type="button" class="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-3 py-1.5 text-sm font-bold text-white hover:bg-blue-700" data-testid="diagram-present" @click="startPresenting">
                        <PlayIcon class="h-4 w-4" />
                        Present
                    </button>
                </template>
            </CanvasHeader>

            <!-- Toolbar -->
            <div v-if="!presenting" class="flex shrink-0 flex-wrap items-center gap-0.5 border-b border-gray-200 bg-white px-2 py-1 text-sm dark:border-gray-800 dark:bg-gray-900" data-testid="diagram-toolbar">
                <template v-if="editable">
                    <button type="button" :class="toolButton" title="Undo (Ctrl+Z)" aria-label="Undo" :disabled="!doc.canUndo.value" @click="canvas?.undo()">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path :d="TOOL_ICONS.undo" /></svg>
                    </button>
                    <button type="button" :class="toolButton" title="Redo (Ctrl+Shift+Z)" aria-label="Redo" :disabled="!doc.canRedo.value" @click="canvas?.redo()">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path :d="TOOL_ICONS.redo" /></svg>
                    </button>
                    <span class="mx-1 h-5 w-px bg-gray-200 dark:bg-gray-700"></span>
                    <button type="button" :class="toolButton" title="Delete (Del)" aria-label="Delete selection" :disabled="!selection.length" @click="deleteSelected"><TrashIcon class="h-4 w-4" /></button>
                    <button type="button" :class="toolButton" title="Duplicate (Ctrl+D)" aria-label="Duplicate selection" :disabled="!selection.length" @click="duplicateSelected"><DocumentDuplicateIcon class="h-4 w-4" /></button>
                    <button type="button" :class="toolButton" title="To front (Ctrl+Shift+F)" aria-label="Bring to front" :disabled="!selection.length" @click="arrange(doc, currentPage.id, selection, 'front')">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"><path :d="TOOL_ICONS.front" /></svg>
                    </button>
                    <button type="button" :class="toolButton" title="To back (Ctrl+Shift+B)" aria-label="Send to back" :disabled="!selection.length" @click="arrange(doc, currentPage.id, selection, 'back')">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"><path :d="TOOL_ICONS.back" /></svg>
                    </button>
                    <button type="button" :class="toolButton" title="Group (Ctrl+G)" aria-label="Group" :disabled="selection.length < 2" data-testid="diagram-group" @click="groupSelection(doc, currentPage.id, selection)">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" stroke-dasharray="2 2"><path :d="TOOL_ICONS.group" /></svg>
                    </button>
                    <button type="button" :class="toolButton" title="Ungroup (Ctrl+Shift+U)" aria-label="Ungroup" :disabled="!hasGroupInSelection" data-testid="diagram-ungroup" @click="ungroupSelection(doc, currentPage.id, selection)">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"><path :d="TOOL_ICONS.ungroup" /></svg>
                    </button>
                    <span class="mx-1 h-5 w-px bg-gray-200 dark:bg-gray-700"></span>
                    <span class="px-1 text-xs font-semibold text-gray-500">New connectors</span>
                    <div class="flex rounded-md border border-gray-200 p-0.5 dark:border-gray-700">
                        <button
                            v-for="value in ['orthogonal', 'straight', 'curved']"
                            :key="value"
                            type="button"
                            class="rounded px-2 py-0.5 text-xs font-semibold"
                            :class="edgeStyle.routing === value ? 'bg-blue-600 text-white' : 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800'"
                            :data-routing="value"
                            @click="edgeStyle.routing = value"
                        >{{ value === 'orthogonal' ? 'Elbow' : value === 'straight' ? 'Straight' : 'Curved' }}</button>
                    </div>
                    <label class="ml-1 inline-flex items-center gap-1 px-1 text-xs font-semibold text-gray-600 dark:text-gray-300" title="Snap to grid and to other shapes (hold Alt to override)">
                        <input v-model="snap" type="checkbox" class="rounded border-gray-300 text-blue-600 dark:border-gray-600">
                        Snap
                    </label>
                    <span class="mx-1 h-5 w-px bg-gray-200 dark:bg-gray-700"></span>
                </template>
                <button type="button" :class="toolButton" title="Zoom out (Ctrl+-)" aria-label="Zoom out" @click="canvas?.zoomBy(1 / 1.2)">−</button>
                <button type="button" :class="toolButton" class="min-w-[3.25rem] text-xs font-bold tabular-nums" title="Reset to 100% (Ctrl+0)" data-testid="diagram-zoom" @click="canvas?.resetZoom()">{{ Math.round((canvas?.view.zoom || 1) * 100) }}%</button>
                <button type="button" :class="toolButton" title="Zoom in (Ctrl+=)" aria-label="Zoom in" @click="canvas?.zoomBy(1.2)">+</button>
                <button type="button" :class="toolButton" title="Fit (Shift+1)" aria-label="Fit to content" @click="canvas?.fitToContent()">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path :d="TOOL_ICONS.fit" /></svg>
                </button>
                <span v-if="!editable" class="ml-2 rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-bold uppercase tracking-wider text-gray-500 dark:bg-gray-800">View only</span>
                <button v-if="editable" type="button" :class="[toolButton, showFormat ? 'bg-gray-100 dark:bg-gray-800' : '']" class="ml-auto hidden lg:inline-flex" title="Format panel" aria-label="Toggle format panel" @click="showFormat = !showFormat">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path :d="TOOL_ICONS.format" /></svg>
                    <span class="text-xs font-semibold">Format</span>
                </button>
            </div>

            <div class="flex min-h-0 flex-1">
                <ShapeLibrary v-if="editable && !presenting" class="hidden md:flex" @insert="(key) => canvas?.insertShape(key)" @insert-image="pictureInput?.click()" />

                <div class="relative min-w-0 flex-1">
                    <DiagramCanvas
                        ref="canvas"
                        v-model:selection="selection"
                        :doc="doc"
                        :page-id="currentPage.id"
                        :readonly="!editable || presenting"
                        :snap="snap"
                        :edge-style="edgeStyle"
                        :laser="presenting"
                        :upload-image="uploadDiagramImage"
                        @history="onHistory"
                        @error="showError"
                    >
                        <div v-if="presenting" class="pointer-events-auto absolute bottom-4 left-1/2 z-20 flex -translate-x-1/2 items-center gap-1 rounded-full bg-gray-900/85 px-2 py-1.5 text-white shadow-xl" data-testid="diagram-present-bar">
                            <button type="button" aria-label="Previous page" class="rounded-full p-2 hover:bg-white/15 disabled:opacity-30" :disabled="currentIndex === 0" @click="stepPage(-1)"><ChevronLeftIcon class="h-5 w-5" /></button>
                            <span class="max-w-[16rem] truncate px-2 text-sm font-semibold"><span class="tabular-nums">{{ currentIndex + 1 }} / {{ doc.scene.pages.length }}</span> · {{ currentPage.name }}</span>
                            <button type="button" aria-label="Next page" class="rounded-full p-2 hover:bg-white/15 disabled:opacity-30" :disabled="currentIndex === doc.scene.pages.length - 1" @click="stepPage(1)"><ChevronRightIcon class="h-5 w-5" /></button>
                            <span class="mx-1 h-5 w-px bg-white/20"></span>
                            <button type="button" class="inline-flex items-center gap-1 rounded-full px-3 py-1.5 text-sm font-bold hover:bg-white/15" data-testid="diagram-exit-present" @click="stopPresenting">
                                <XMarkIcon class="h-4 w-4" />
                                Exit
                            </button>
                        </div>
                    </DiagramCanvas>
                </div>

                <FormatPanel
                    v-if="editable && showFormat && !presenting"
                    v-model:selection="selection"
                    class="hidden lg:flex"
                    :doc="doc"
                    :page-id="currentPage.id"
                />
            </div>

            <!-- Page tabs (draw.io keeps them along the bottom) -->
            <div v-if="!presenting" class="flex shrink-0 items-center gap-1 overflow-x-auto border-t border-gray-200 bg-white px-2 py-1 dark:border-gray-800 dark:bg-gray-900" data-testid="diagram-pages">
                <div
                    v-for="page in doc.scene.pages"
                    :key="page.id"
                    class="group flex shrink-0 items-center rounded-md border text-sm"
                    :class="page.id === currentPage.id ? 'border-blue-500 bg-blue-50 font-semibold text-blue-700 dark:bg-blue-900/30 dark:text-blue-300' : 'border-transparent text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800'"
                    data-testid="diagram-page-tab"
                >
                    <input
                        v-if="renamingPageId === page.id"
                        ref="pageNameInput"
                        v-model="pageName"
                        maxlength="120"
                        class="h-7 w-32 rounded border-gray-300 px-1 py-0 text-sm dark:border-gray-600 dark:bg-gray-800"
                        @keydown.enter.prevent="finishRenamePage"
                        @keydown.esc.prevent="renamingPageId = null"
                        @blur="finishRenamePage"
                    >
                    <button
                        v-else
                        type="button"
                        class="max-w-[12rem] truncate px-3 py-1"
                        :title="editable ? 'Double-click to rename' : page.name"
                        @click="currentPageId = page.id"
                        @dblclick="startRenamePage(page)"
                    >{{ page.name }}</button>
                    <template v-if="editable && page.id === currentPage.id">
                        <button type="button" title="Duplicate page" aria-label="Duplicate page" class="rounded p-1 text-gray-400 hover:text-blue-600" @click="duplicatePage(page)"><DocumentDuplicateIcon class="h-3.5 w-3.5" /></button>
                        <button v-if="doc.scene.pages.length > 1" type="button" title="Delete page" aria-label="Delete page" class="mr-1 rounded p-1 text-gray-400 hover:text-red-600" @click="deletePage(page)"><TrashIcon class="h-3.5 w-3.5" /></button>
                    </template>
                </div>
                <button v-if="editable" type="button" title="Add page" aria-label="Add page" class="ml-1 shrink-0 rounded-md p-1.5 text-gray-500 hover:bg-gray-100 hover:text-blue-600 dark:hover:bg-gray-800" data-testid="diagram-add-page" @click="addPage">
                    <PlusIcon class="h-4 w-4" />
                </button>
            </div>
        </div>

        <input ref="pictureInput" type="file" class="hidden" accept="image/png,image/jpeg,image/gif,image/webp" multiple data-testid="diagram-picture-input" @change="onPicturesPicked">
        <input ref="drawioInput" type="file" class="hidden" accept=".drawio,.xml,application/xml,text/xml" data-testid="diagram-drawio-input" @change="onDrawioPicked">

        <!-- Page 1 rendered off-screen: its markup becomes the list-card preview on save. -->
        <div ref="previewHost" class="hidden" aria-hidden="true">
            <svg xmlns="http://www.w3.org/2000/svg" width="320" height="200" :viewBox="previewBox" preserveAspectRatio="xMidYMid meet">
                <rect x="-100000" y="-100000" width="200000" height="200000" :fill="firstPage.background || '#ffffff'" />
                <DiagramElements :elements="firstPage.elements" :label-background="firstPage.background || '#ffffff'" />
            </svg>
        </div>
    </AppLayout>
</template>
