<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import WhiteboardCanvas from './WhiteboardCanvas.vue';
import WhiteboardElements from './WhiteboardElements.vue';
import { contentBounds } from './whiteboard';
import { useConfirm } from '@/Composables/useConfirm';
import { useToast } from '@/Composables/useToast';
import { useFillViewport } from '@/Composables/useFillViewport';
import {
    ChevronLeftIcon,
    ChevronRightIcon,
    DocumentDuplicateIcon,
    PlusIcon,
    Squares2X2Icon,
    TrashIcon,
    XMarkIcon,
} from '@heroicons/vue/24/outline';

/**
 * Whiteboard editing surface shared by the task-board whiteboard and standalone whiteboards:
 * page sidebar, canvas, presenter mode (fullscreen, arrow-key paging, laser), PNG export,
 * and the current page kept in the URL. The page supplies the header (slot `header`, which
 * receives startPresenting / exportPng / exporting) and any right-hand panel (slot `aside`).
 */
const props = defineProps({
    doc: { type: Object, required: true },
    editable: { type: Boolean, default: false },
    cardsById: { type: Object, default: () => ({}) },
    exportName: { type: String, default: 'whiteboard' },
    // async (File) => { url, width, height } — enables pasting / dropping pictures.
    uploadImage: { type: Function, default: null },
});

const emit = defineEmits(['open-card', 'drop-card']);

const { confirm } = useConfirm();
const { showError } = useToast();

const EDIT_TOOLS = ['select', 'hand', 'pen', 'highlighter', 'laser', 'eraser', 'line', 'arrow', 'rect', 'ellipse', 'text', 'sticky'];
const PRESENT_TOOLS = ['laser', 'pen', 'highlighter', 'eraser', 'hand', 'select'];

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

// ── Pages ────────────────────────────────────────────────────────────────────

const initialPageId = () => {
    const requested = new URLSearchParams(window.location.search).get('page');
    return props.doc.pageById(requested)?.id || props.doc.scene.pages[0].id;
};

const currentPageId = ref(initialPageId());
const currentPage = computed(() => props.doc.pageById(currentPageId.value) || props.doc.scene.pages[0]);
const currentIndex = computed(() => props.doc.scene.pages.indexOf(currentPage.value));

watch(() => props.doc.scene.pages.map((page) => page.id).join(','), () => {
    if (!props.doc.pageById(currentPageId.value)) currentPageId.value = props.doc.scene.pages[0]?.id;
});

watch(currentPageId, (id) => {
    const url = new URL(window.location.href);
    url.searchParams.set('page', id);
    window.history.replaceState(window.history.state, '', url);
});

const goToPage = (id) => {
    if (props.doc.pageById(id)) currentPageId.value = id;
};

const stepPage = (delta) => {
    const next = props.doc.scene.pages[currentIndex.value + delta];
    if (next) currentPageId.value = next.id;
};

const addPage = () => {
    currentPageId.value = props.doc.addPage(currentPageId.value).id;
};

const duplicatePage = (page) => {
    const copy = props.doc.duplicatePage(page.id);
    if (copy) currentPageId.value = copy.id;
};

const deletePage = async (page) => {
    if (props.doc.scene.pages.length <= 1) return;
    const ok = await confirm({
        title: 'Delete page',
        message: `Delete "${page.name}" and everything drawn on it? You can undo this with Ctrl+Z.`,
        confirmLabel: 'Delete page',
        variant: 'danger',
    });
    if (ok) props.doc.removePage(page.id);
};

const cycleBackground = (page) => {
    const order = ['dots', 'grid', 'plain'];
    props.doc.updatePage(page.id, { background: order[(order.indexOf(page.background) + 1) % order.length] });
};

const renamingPageId = ref(null);
const renameValue = ref('');
const renameInput = ref(null);

const startRename = (page) => {
    if (!props.editable) return;
    renamingPageId.value = page.id;
    renameValue.value = page.name;
    nextTick(() => renameInput.value?.[0]?.select());
};

const finishRename = () => {
    const id = renamingPageId.value;
    renamingPageId.value = null;
    const name = renameValue.value.trim().slice(0, 120);
    if (id && name) props.doc.updatePage(id, { name });
};

const draggedPageId = ref(null);
const dropPage = (target) => {
    const id = draggedPageId.value;
    draggedPageId.value = null;
    if (!id || id === target.id) return;
    props.doc.movePage(id, props.doc.scene.pages.indexOf(target));
};

const thumbBox = (page) => {
    const box = contentBounds(page.elements, props.cardsById);
    if (!box) return '0 0 400 260';
    const pad = Math.max(box.w, box.h) * 0.08 + 10;
    return `${box.x - pad} ${box.y - pad} ${box.w + pad * 2} ${box.h + pad * 2}`;
};

const onHistory = (entry) => {
    const pageId = entry.pageId || entry.id || (entry.after || entry.before)?.id;
    if (pageId && props.doc.pageById(pageId)) currentPageId.value = pageId;
};

/** Add an element to the current page and select it (used for dropped board cards). */
const addElement = (element) => {
    props.doc.commit(currentPage.value.id, [{ id: element.id, after: element }]);
    canvas.value?.select([element.id]);
};

// ── Present mode ─────────────────────────────────────────────────────────────

const presenting = ref(false);
let enteredFullscreen = false;
let toolBeforePresenting = null;

const startPresenting = async () => {
    canvas.value?.commitEdit();
    toolBeforePresenting = canvas.value?.getTool() || null;
    presenting.value = true;
    try {
        if (document.fullscreenEnabled && !document.fullscreenElement) {
            await document.documentElement.requestFullscreen();
            enteredFullscreen = true;
        }
    } catch {
        enteredFullscreen = false;
    }
    await nextTick();
    canvas.value?.setTool('laser');
    setTimeout(() => canvas.value?.fitToContent({ maxZoom: 2 }), 60);
};

const stopPresenting = async () => {
    if (!presenting.value) return;
    presenting.value = false;
    if (enteredFullscreen && document.fullscreenElement) await document.exitFullscreen().catch(() => {});
    enteredFullscreen = false;
    await nextTick();
    canvas.value?.setTool(toolBeforePresenting || (props.editable ? 'select' : 'hand'));
    setTimeout(() => {
        measureHeight();
        canvas.value?.fitToContent();
    }, 60);
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
    const tag = event.target?.tagName;
    if (['INPUT', 'TEXTAREA', 'SELECT'].includes(tag) || document.querySelector('dialog[open]')) return;
    if (!presenting.value) return;

    if (event.key === 'Escape') {
        stopPresenting();
    } else if (['ArrowRight', 'ArrowDown', 'PageDown'].includes(event.key)) {
        event.preventDefault();
        stepPage(1);
    } else if (['ArrowLeft', 'ArrowUp', 'PageUp'].includes(event.key)) {
        event.preventDefault();
        stepPage(-1);
    }
};

// ── Export ───────────────────────────────────────────────────────────────────

const exporting = ref(false);
const exportPng = async () => {
    if (exporting.value) return;
    exporting.value = true;
    try {
        const name = `${props.exportName} - ${currentPage.value.name}`.replace(/[\\/:*?"<>|]+/g, '-').slice(0, 120);
        await canvas.value?.exportPng(`${name}.png`);
    } catch {
        showError('Could not export this page.');
    } finally {
        exporting.value = false;
    }
};

onMounted(() => {
    window.addEventListener('keydown', onKeyDown);
    document.addEventListener('fullscreenchange', onFullscreenChange);
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeyDown);
    document.removeEventListener('fullscreenchange', onFullscreenChange);
    if (enteredFullscreen && document.fullscreenElement) document.exitFullscreen().catch(() => {});
});

defineExpose({
    currentPage,
    addElement,
    addAsset: (asset) => canvas.value?.addAsset(asset),
    insertImages: (files) => canvas.value?.insertImages(files),
    startPresenting,
    exportPng,
    viewportCenter: () => canvas.value?.viewportCenter() || { x: 0, y: 0 },
    commitEdit: () => canvas.value?.commitEdit(),
});
</script>

<template>
    <div
        ref="pageRoot"
        class="flex flex-col overflow-hidden bg-gray-100 text-gray-900 dark:bg-gray-950 dark:text-gray-100"
        :class="presenting ? 'fixed inset-0 z-[60] h-screen' : (fitHeight ? '' : 'h-[calc(100vh-6rem)]')"
        :style="!presenting && fitHeight ? { height: `${fitHeight}px` } : null"
        data-testid="whiteboard-page"
    >
        <slot v-if="!presenting" name="header" :start-presenting="startPresenting" :export-png="exportPng" :exporting="exporting" />

        <div class="flex min-h-0 flex-1">
            <!-- Pages -->
            <aside v-if="!presenting" class="hidden w-48 shrink-0 flex-col border-r border-gray-200 bg-white md:flex dark:border-gray-800 dark:bg-gray-900">
                <div class="flex items-center justify-between px-3 py-2">
                    <span class="text-xs font-black uppercase tracking-wider text-gray-500 dark:text-gray-400">Pages</span>
                    <button v-if="editable" type="button" title="Add page" aria-label="Add page" class="rounded-lg p-1 text-gray-500 hover:bg-gray-100 hover:text-blue-600 dark:text-gray-400 dark:hover:bg-gray-800" data-testid="whiteboard-add-page" @click="addPage">
                        <PlusIcon class="h-4 w-4" />
                    </button>
                </div>
                <ol class="min-h-0 flex-1 space-y-2 overflow-y-auto px-3 pb-3">
                    <li
                        v-for="(page, index) in doc.scene.pages"
                        :key="page.id"
                        class="group rounded-lg border-2 p-1 transition-colors"
                        :class="[
                            page.id === currentPage.id ? 'border-blue-500' : 'border-transparent hover:border-gray-200 dark:hover:border-gray-700',
                            draggedPageId === page.id ? 'opacity-50' : '',
                        ]"
                        :draggable="editable && renamingPageId !== page.id"
                        data-testid="whiteboard-page-item"
                        @dragstart="draggedPageId = page.id"
                        @dragend="draggedPageId = null"
                        @dragover.prevent
                        @drop.prevent="dropPage(page)"
                    >
                        <button type="button" class="block w-full overflow-hidden rounded-md border border-gray-200 bg-white dark:border-gray-700" :title="page.name" @click="goToPage(page.id)">
                            <svg :viewBox="thumbBox(page)" class="pointer-events-none h-20 w-full" preserveAspectRatio="xMidYMid meet">
                                <WhiteboardElements :elements="page.elements" :cards-by-id="cardsById" />
                            </svg>
                        </button>
                        <div class="mt-1 flex items-center gap-1">
                            <span class="w-4 shrink-0 text-[10px] font-bold text-gray-400">{{ index + 1 }}</span>
                            <input
                                v-if="renamingPageId === page.id"
                                ref="renameInput"
                                v-model="renameValue"
                                maxlength="120"
                                class="h-6 min-w-0 flex-1 rounded border-gray-300 px-1 py-0 text-xs dark:border-gray-600 dark:bg-gray-800"
                                @keydown.enter.prevent="finishRename"
                                @keydown.esc.prevent="renamingPageId = null"
                                @blur="finishRename"
                            >
                            <span v-else class="min-w-0 flex-1 truncate text-xs font-semibold text-gray-700 dark:text-gray-300" :title="editable ? 'Double-click to rename' : page.name" @dblclick="startRename(page)">{{ page.name }}</span>
                        </div>
                        <div v-if="editable" class="mt-1 hidden items-center justify-end gap-0.5 group-hover:flex">
                            <button type="button" :title="`Background: ${page.background}`" :aria-label="`Change background (${page.background})`" class="rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-gray-800" @click="cycleBackground(page)">
                                <Squares2X2Icon class="h-3.5 w-3.5" />
                            </button>
                            <button type="button" title="Duplicate page" aria-label="Duplicate page" class="rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-blue-600 dark:hover:bg-gray-800" @click="duplicatePage(page)">
                                <DocumentDuplicateIcon class="h-3.5 w-3.5" />
                            </button>
                            <button v-if="doc.scene.pages.length > 1" type="button" title="Delete page" aria-label="Delete page" class="rounded p-1 text-gray-400 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-900/30" @click="deletePage(page)">
                                <TrashIcon class="h-3.5 w-3.5" />
                            </button>
                        </div>
                    </li>
                </ol>
            </aside>

            <!-- Canvas -->
            <div class="relative min-w-0 flex-1">
                <WhiteboardCanvas
                    ref="canvas"
                    :doc="doc"
                    :page-id="currentPage.id"
                    :readonly="!editable"
                    :tools="presenting ? PRESENT_TOOLS : EDIT_TOOLS"
                    :initial-tool="editable ? 'pen' : 'hand'"
                    :cards-by-id="cardsById"
                    :upload-image="uploadImage"
                    @open-card="(cardId) => emit('open-card', cardId)"
                    @drop-card="(payload) => emit('drop-card', payload)"
                    @history="onHistory"
                    @error="showError"
                >
                    <template v-if="presenting" #toolbar-end>
                        <span class="mx-1 h-6 w-px shrink-0 bg-gray-200 dark:bg-gray-700"></span>
                        <button type="button" class="inline-flex h-9 shrink-0 items-center gap-1 rounded-lg px-3 text-sm font-bold text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-700" data-testid="whiteboard-exit-present" @click="stopPresenting">
                            <XMarkIcon class="h-4 w-4" />
                            Exit
                        </button>
                    </template>

                    <!-- Mobile page switcher (the sidebar is hidden below md) -->
                    <div v-if="!presenting && doc.scene.pages.length > 1" class="pointer-events-auto absolute bottom-3 right-3 z-20 flex items-center gap-1 rounded-xl border border-gray-200 bg-white/95 p-1 text-gray-600 shadow-lg md:hidden dark:border-gray-700 dark:bg-gray-800/95 dark:text-gray-300">
                        <button type="button" aria-label="Previous page" class="rounded-lg p-1.5 hover:bg-gray-100 disabled:opacity-30 dark:hover:bg-gray-700" :disabled="currentIndex === 0" @click="stepPage(-1)"><ChevronLeftIcon class="h-4 w-4" /></button>
                        <span class="px-1 text-xs font-bold tabular-nums">{{ currentIndex + 1 }} / {{ doc.scene.pages.length }}</span>
                        <button type="button" aria-label="Next page" class="rounded-lg p-1.5 hover:bg-gray-100 disabled:opacity-30 dark:hover:bg-gray-700" :disabled="currentIndex === doc.scene.pages.length - 1" @click="stepPage(1)"><ChevronRightIcon class="h-4 w-4" /></button>
                    </div>

                    <!-- Presenter controls -->
                    <div v-if="presenting" class="pointer-events-auto absolute bottom-4 left-1/2 z-20 flex -translate-x-1/2 items-center gap-1 rounded-full bg-gray-900/85 px-2 py-1.5 text-white shadow-xl" data-testid="whiteboard-present-bar">
                        <button type="button" aria-label="Previous page" class="rounded-full p-2 hover:bg-white/15 disabled:opacity-30" :disabled="currentIndex === 0" @click="stepPage(-1)"><ChevronLeftIcon class="h-5 w-5" /></button>
                        <span class="max-w-[16rem] truncate px-2 text-sm font-semibold"><span class="tabular-nums">{{ currentIndex + 1 }} / {{ doc.scene.pages.length }}</span> · {{ currentPage.name }}</span>
                        <button type="button" aria-label="Next page" class="rounded-full p-2 hover:bg-white/15 disabled:opacity-30" :disabled="currentIndex === doc.scene.pages.length - 1" @click="stepPage(1)"><ChevronRightIcon class="h-5 w-5" /></button>
                    </div>
                </WhiteboardCanvas>
            </div>

            <slot v-if="!presenting" name="aside" />
        </div>
    </div>
</template>
