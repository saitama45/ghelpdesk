<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import WhiteboardCanvas from '@/Components/Whiteboard/WhiteboardCanvas.vue';
import WhiteboardElements from '@/Components/Whiteboard/WhiteboardElements.vue';
import {
    CARD_DRAG_TYPE,
    contentBounds,
    createWhiteboardDocument,
    round1,
    uid,
} from '@/Components/Whiteboard/whiteboard';
import { useConfirm } from '@/Composables/useConfirm';
import { useToast } from '@/Composables/useToast';
import {
    ArrowDownTrayIcon,
    ArrowLeftIcon,
    ChevronLeftIcon,
    ChevronRightIcon,
    DocumentDuplicateIcon,
    PlayIcon,
    PlusIcon,
    RectangleStackIcon,
    Squares2X2Icon,
    TrashIcon,
    XMarkIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({
    board: { type: Object, required: true },
    whiteboard: { type: Object, default: () => ({}) },
    cards: { type: Array, default: () => [] },
    canEdit: { type: Boolean, default: false },
});

const { confirm } = useConfirm();
const { showError } = useToast();

const POLL_MS = 4000;
const CARD_REFRESH_EVERY = 5; // polls → live card data every ~20s
const EDIT_TOOLS = ['select', 'hand', 'pen', 'highlighter', 'laser', 'eraser', 'line', 'arrow', 'rect', 'ellipse', 'text', 'sticky'];
const PRESENT_TOOLS = ['laser', 'pen', 'highlighter', 'eraser', 'hand', 'select'];

const doc = createWhiteboardDocument(props.whiteboard?.scene);
const canvas = ref(null);
const editable = ref(props.canEdit);
const revision = ref(props.whiteboard?.revision || 0);
const lastSavedAt = ref(props.whiteboard?.updated_at || null);
const lastSavedBy = ref(props.whiteboard?.updated_by || null);
const liveCards = ref(props.cards || []);
const cardsById = computed(() => Object.fromEntries(liveCards.value.map((card) => [card.id, card])));

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
    const url = new URL(window.location.href);
    url.searchParams.set('page', id);
    window.history.replaceState(window.history.state, '', url);
});

const goToPage = (id) => {
    if (doc.pageById(id)) currentPageId.value = id;
};

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
        message: `Delete "${page.name}" and everything drawn on it? You can undo this with Ctrl+Z.`,
        confirmLabel: 'Delete page',
        variant: 'danger',
    });
    if (ok) doc.removePage(page.id);
};

const cycleBackground = (page) => {
    const order = ['dots', 'grid', 'plain'];
    doc.updatePage(page.id, { background: order[(order.indexOf(page.background) + 1) % order.length] });
};

const renamingPageId = ref(null);
const renameValue = ref('');
const renameInput = ref(null);

const startRename = (page) => {
    if (!editable.value) return;
    renamingPageId.value = page.id;
    renameValue.value = page.name;
    nextTick(() => renameInput.value?.[0]?.select());
};

const finishRename = () => {
    const id = renamingPageId.value;
    renamingPageId.value = null;
    const name = renameValue.value.trim().slice(0, 120);
    if (id && name) doc.updatePage(id, { name });
};

const draggedPageId = ref(null);
const dropPage = (target) => {
    const id = draggedPageId.value;
    draggedPageId.value = null;
    if (!id || id === target.id) return;
    doc.movePage(id, doc.scene.pages.indexOf(target));
};

const thumbBox = (page) => {
    const box = contentBounds(page.elements, cardsById.value);
    if (!box) return '0 0 400 260';
    const pad = Math.max(box.w, box.h) * 0.08 + 10;
    return `${box.x - pad} ${box.y - pad} ${box.w + pad * 2} ${box.h + pad * 2}`;
};

const onHistory = (entry) => {
    const pageId = entry.pageId || entry.id || (entry.after || entry.before)?.id;
    if (pageId && doc.pageById(pageId)) currentPageId.value = pageId;
};

// ── Board cards ──────────────────────────────────────────────────────────────

const showCards = ref(props.canEdit);
const cardSearch = ref('');

const cardsOnPage = computed(() => new Set(currentPage.value.elements.filter((element) => element.type === 'card').map((element) => element.cardId)));

const cardGroups = computed(() => {
    const q = cardSearch.value.trim().toLowerCase();
    const groups = new Map();
    for (const card of liveCards.value) {
        if (q && !card.title.toLowerCase().includes(q)) continue;
        if (!groups.has(card.status)) groups.set(card.status, { status: card.status, color: card.color, cards: [] });
        groups.get(card.status).cards.push(card);
    }
    return [...groups.values()];
});

const addCard = (cardId, x, y) => {
    const card = cardsById.value[cardId];
    if (!card || !editable.value) return;
    const element = {
        id: uid(),
        type: 'card',
        x: round1(x - 130),
        y: round1(y - 40),
        w: 260,
        h: 0,
        cardId: card.id,
        title: card.title,
        status: card.status,
        color: card.color,
    };
    doc.commit(currentPage.value.id, [{ id: element.id, after: element }]);
    canvas.value?.select([element.id]);
};

const addCardAtCenter = (card) => {
    const center = canvas.value?.viewportCenter() || { x: 0, y: 0 };
    const offset = (currentPage.value.elements.length % 6) * 24;
    addCard(card.id, center.x + offset, center.y + offset);
};

const onCardDragStart = (event, card) => {
    event.dataTransfer.setData(CARD_DRAG_TYPE, String(card.id));
    event.dataTransfer.effectAllowed = 'copy';
};

// The id is kept after closing so the modal's fade-out keeps showing the same card.
const openedCardId = ref(null);
const showCardModal = ref(false);
const openedCard = computed(() => (openedCardId.value ? cardsById.value[openedCardId.value] || null : null));
const openCard = (cardId) => {
    openedCardId.value = cardId;
    showCardModal.value = true;
};
const cardBoardUrl = (cardId) => `${route('task-boards.show', props.board.id)}?card=${cardId}`;

const formatDate = (value) => {
    if (!value) return '';
    const date = new Date(String(value).replace(' ', 'T'));
    return Number.isNaN(date.getTime()) ? '' : date.toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
};

// ── Saving (optimistic, merge on conflict) ───────────────────────────────────

const saveState = ref('saved'); // saved | pending | saving | error
const saveError = ref('');
let saving = false;
let saveAgain = false;
let saveTimer = null;
let conflictStreak = 0;

const scheduleSave = (delay = 900) => {
    clearTimeout(saveTimer);
    saveTimer = setTimeout(save, delay);
};

const save = async () => {
    if (!editable.value) return;
    if (saving) {
        saveAgain = true;
        return;
    }
    if (!doc.isDirty()) {
        saveState.value = 'saved';
        return;
    }

    saving = true;
    saveState.value = 'saving';
    const inflight = doc.takeDirty();

    try {
        const { data } = await axios.put(route('task-boards.whiteboard.update', props.board.id), {
            revision: revision.value,
            scene: doc.toJSON(),
        });
        revision.value = data.revision;
        lastSavedAt.value = data.updated_at;
        lastSavedBy.value = data.updated_by;
        saveError.value = '';
        conflictStreak = 0;
        saveState.value = doc.isDirty() ? 'pending' : 'saved';
    } catch (error) {
        doc.restoreDirty(inflight);
        const status = error.response?.status;

        if (status === 409 && error.response.data && conflictStreak < 5) {
            conflictStreak++;
            doc.rebase(error.response.data.scene);
            revision.value = error.response.data.revision;
            lastSavedBy.value = error.response.data.updated_by;
            saveAgain = true;
        } else if (status === 403) {
            editable.value = false;
            saveState.value = 'error';
            saveError.value = 'You can no longer edit this whiteboard.';
        } else if (status === 422) {
            saveState.value = 'error';
            saveError.value = error.response.data?.message || 'This whiteboard could not be saved.';
            showError(saveError.value);
        } else {
            saveState.value = 'error';
            saveError.value = 'Couldn’t save — retrying…';
            scheduleSave(5000);
        }
    } finally {
        saving = false;
        if (saveAgain) {
            saveAgain = false;
            scheduleSave(150);
        }
    }
};

watch(doc.changeCount, () => {
    if (!editable.value) return;
    saveState.value = 'pending';
    scheduleSave();
});

const saveLabel = computed(() => {
    if (!editable.value) return saveError.value || 'View only';
    if (saveState.value === 'saving') return 'Saving…';
    if (saveState.value === 'pending') return 'Unsaved changes';
    if (saveState.value === 'error') return saveError.value;
    if (!lastSavedAt.value) return 'Nothing saved yet';
    return `Saved ${formatDate(lastSavedAt.value)}${lastSavedBy.value ? ` · ${lastSavedBy.value}` : ''}`;
});

// ── Live updates (poll) ──────────────────────────────────────────────────────

let pollTimer = null;
let polling = false;
let pollCount = 0;

const poll = async () => {
    if (polling || document.visibilityState !== 'visible') return;
    polling = true;
    pollCount++;
    try {
        const { data } = await axios.get(route('task-boards.whiteboard.state', props.board.id), {
            params: { revision: revision.value, cards: pollCount % CARD_REFRESH_EVERY === 0 ? 1 : 0 },
        });
        if (data.cards) liveCards.value = data.cards;
        if (!data.changed) return;

        if (saving || doc.isDirty()) {
            // Our pending save will hit 409 and merge their scene with our edits.
            scheduleSave(0);
            return;
        }

        doc.setScene(data.scene);
        revision.value = data.revision;
        lastSavedAt.value = data.updated_at;
        lastSavedBy.value = data.updated_by;
    } catch {
        // Background refresh — stay quiet; the next poll retries.
    } finally {
        polling = false;
    }
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
    canvas.value?.setTool(toolBeforePresenting || (editable.value ? 'select' : 'hand'));
    setTimeout(() => canvas.value?.fitToContent(), 60);
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
        const name = `${props.board.title} - ${currentPage.value.name}`.replace(/[\\/:*?"<>|]+/g, '-').slice(0, 120);
        await canvas.value?.exportPng(`${name}.png`);
    } catch {
        showError('Could not export this page.');
    } finally {
        exporting.value = false;
    }
};

// ── Lifecycle ────────────────────────────────────────────────────────────────

const onBeforeUnload = (event) => {
    if (editable.value && (saving || doc.isDirty())) {
        event.preventDefault();
        event.returnValue = '';
    }
};

const onVisibility = () => {
    if (document.visibilityState === 'visible') poll();
};

onMounted(() => {
    pollTimer = setInterval(poll, POLL_MS);
    window.addEventListener('keydown', onKeyDown);
    window.addEventListener('beforeunload', onBeforeUnload);
    document.addEventListener('fullscreenchange', onFullscreenChange);
    document.addEventListener('visibilitychange', onVisibility);
});

onBeforeUnmount(() => {
    clearInterval(pollTimer);
    clearTimeout(saveTimer);
    window.removeEventListener('keydown', onKeyDown);
    window.removeEventListener('beforeunload', onBeforeUnload);
    document.removeEventListener('fullscreenchange', onFullscreenChange);
    document.removeEventListener('visibilitychange', onVisibility);
    if (enteredFullscreen && document.fullscreenElement) document.exitFullscreen().catch(() => {});
    // Leaving through an Inertia link: flush the last edits (the request outlives the page).
    if (editable.value && doc.isDirty()) save();
});

const headerStyle = computed(() => {
    if (props.board.background_type === 'image' && props.board.background_value) {
        return {
            backgroundImage: `linear-gradient(120deg, rgba(15, 23, 42, 0.78), rgba(15, 23, 42, 0.45)), url(${props.board.background_value})`,
            backgroundSize: 'cover',
            backgroundPosition: 'center',
        };
    }
    return { background: props.board.background_value || '#0f766e' };
});
</script>

<template>
    <Head :title="`Whiteboard · ${board.title}`" />

    <AppLayout content-class="max-w-none px-0 sm:px-0 lg:px-0">
        <template #header>Task Board</template>

        <div
            class="flex flex-col overflow-hidden bg-gray-100 text-gray-900 dark:bg-gray-950 dark:text-gray-100"
            :class="presenting ? 'fixed inset-0 z-[60] h-screen' : 'h-[calc(100vh-6rem)] min-h-[560px]'"
            data-testid="whiteboard-page"
        >
            <header v-if="!presenting" class="shrink-0 px-4 py-3 text-white" :style="headerStyle">
                <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                    <div class="flex min-w-0 items-center gap-3">
                        <Link :href="route('task-boards.show', board.id)" class="rounded-lg p-2 text-white/80 transition-colors hover:bg-white/15 hover:text-white" title="Back to the board">
                            <ArrowLeftIcon class="h-5 w-5" />
                        </Link>
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <h1 class="truncate text-xl font-black">{{ board.title }}</h1>
                                <span class="rounded-full bg-white/15 px-2 py-0.5 text-[10px] font-black uppercase tracking-wider">Whiteboard</span>
                                <span v-if="!editable" class="rounded-full bg-white/15 px-2 py-0.5 text-[10px] font-black uppercase tracking-wider">View only</span>
                            </div>
                            <p class="flex items-center gap-2 truncate text-xs font-medium text-white/75" data-testid="whiteboard-save-state">
                                <span class="inline-block h-2 w-2 shrink-0 rounded-full" :class="{
                                    'bg-emerald-300': saveState === 'saved',
                                    'bg-amber-300': saveState === 'pending' || saveState === 'saving',
                                    'bg-red-400': saveState === 'error',
                                }"></span>
                                {{ saveLabel }}
                                <span class="text-white/50">· Live — changes from others appear automatically</span>
                            </p>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <button v-if="editable" type="button" class="hidden items-center gap-2 rounded-lg px-3 py-2 text-sm font-semibold transition-colors hover:bg-white/15 lg:inline-flex" :class="showCards ? 'bg-white/15' : ''" @click="showCards = !showCards">
                            <RectangleStackIcon class="h-4 w-4" />
                            Board cards
                        </button>
                        <button type="button" class="inline-flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-semibold transition-colors hover:bg-white/15 disabled:opacity-50" :disabled="exporting" @click="exportPng">
                            <ArrowDownTrayIcon class="h-4 w-4" />
                            Export PNG
                        </button>
                        <button type="button" class="inline-flex items-center gap-2 rounded-lg bg-white px-3 py-2 text-sm font-bold text-gray-900 shadow-sm transition-colors hover:bg-gray-100" data-testid="whiteboard-present" @click="startPresenting">
                            <PlayIcon class="h-4 w-4" />
                            Present
                        </button>
                    </div>
                </div>
            </header>

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
                        @open-card="openCard"
                        @drop-card="({ cardId, x, y }) => addCard(cardId, x, y)"
                        @history="onHistory"
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

                <!-- Board cards -->
                <aside v-if="!presenting && editable && showCards" class="hidden w-72 shrink-0 flex-col border-l border-gray-200 bg-white lg:flex dark:border-gray-800 dark:bg-gray-900" data-testid="whiteboard-cards-panel">
                    <div class="border-b border-gray-100 p-3 dark:border-gray-800">
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-black uppercase tracking-wider text-gray-500 dark:text-gray-400">Board cards</span>
                            <button type="button" aria-label="Hide board cards" class="rounded p-1 text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800" @click="showCards = false"><XMarkIcon class="h-4 w-4" /></button>
                        </div>
                        <p class="mt-1 text-[11px] text-gray-500 dark:text-gray-400">Drag a card onto the page, or click +. It keeps showing the card’s live status.</p>
                        <input v-model="cardSearch" type="search" placeholder="Search cards…" class="mt-2 h-8 w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800">
                    </div>
                    <div class="min-h-0 flex-1 space-y-4 overflow-y-auto p-3">
                        <p v-if="!cardGroups.length" class="text-center text-xs text-gray-400">{{ liveCards.length ? 'No cards match.' : 'This board has no cards yet.' }}</p>
                        <section v-for="group in cardGroups" :key="group.status">
                            <h3 class="mb-1.5 flex items-center gap-1.5 text-[11px] font-black uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                <span class="h-2 w-2 rounded-full" :style="{ backgroundColor: group.color }"></span>
                                {{ group.status }}
                                <span class="font-semibold text-gray-400">{{ group.cards.length }}</span>
                            </h3>
                            <ul class="space-y-1.5">
                                <li
                                    v-for="card in group.cards"
                                    :key="card.id"
                                    draggable="true"
                                    class="flex cursor-grab items-start gap-2 rounded-lg border border-gray-200 bg-white p-2 text-sm shadow-sm hover:border-blue-300 active:cursor-grabbing dark:border-gray-700 dark:bg-gray-800"
                                    data-testid="whiteboard-card-option"
                                    @dragstart="onCardDragStart($event, card)"
                                >
                                    <span class="mt-1 h-2 w-2 shrink-0 rounded-full" :style="{ backgroundColor: card.color }"></span>
                                    <span class="min-w-0 flex-1">
                                        <span class="line-clamp-2 font-semibold text-gray-800 dark:text-gray-200">{{ card.title }}</span>
                                        <span v-if="cardsOnPage.has(card.id)" class="text-[10px] font-bold uppercase text-blue-600 dark:text-blue-400">On this page</span>
                                    </span>
                                    <button type="button" title="Add to page" :aria-label="`Add ${card.title} to page`" class="rounded p-1 text-gray-400 hover:bg-blue-50 hover:text-blue-600 dark:hover:bg-blue-900/30" @click="addCardAtCenter(card)">
                                        <PlusIcon class="h-4 w-4" />
                                    </button>
                                </li>
                            </ul>
                        </section>
                    </div>
                </aside>
            </div>
        </div>

        <Modal :show="showCardModal" max-width="md" @close="showCardModal = false">
            <div class="p-5" data-testid="whiteboard-card-modal">
                <template v-if="openedCard">
                    <div class="flex items-center gap-2">
                        <span class="h-2.5 w-2.5 rounded-full" :style="{ backgroundColor: openedCard.color }"></span>
                        <span class="text-xs font-black uppercase tracking-wider" :style="{ color: openedCard.color }">{{ openedCard.status }}</span>
                    </div>
                    <h2 class="mt-2 text-lg font-black text-gray-900 dark:text-gray-100">{{ openedCard.title }}</h2>
                    <dl class="mt-3 space-y-1 text-sm text-gray-600 dark:text-gray-300">
                        <div v-if="openedCard.due_at" class="flex gap-2">
                            <dt class="w-20 shrink-0 font-semibold text-gray-500">Due</dt>
                            <dd>{{ formatDate(openedCard.due_at) }}<span v-if="openedCard.due_complete" class="ml-1 font-bold text-emerald-600">✓ complete</span></dd>
                        </div>
                        <div v-if="openedCard.assignees.length" class="flex gap-2">
                            <dt class="w-20 shrink-0 font-semibold text-gray-500">Assigned</dt>
                            <dd>{{ openedCard.assignees.join(', ') }}</dd>
                        </div>
                    </dl>
                </template>
                <p v-else class="text-sm text-gray-600 dark:text-gray-300">This card is no longer on the board (it was archived or deleted).</p>
                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" class="rounded-lg border border-gray-200 px-4 py-2 text-sm font-bold text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800" @click="showCardModal = false">Close</button>
                    <a v-if="openedCard" :href="cardBoardUrl(openedCard.id)" target="_blank" rel="noopener" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-bold text-white hover:bg-blue-700">Open on board</a>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>
