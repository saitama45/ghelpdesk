<script setup>
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import WhiteboardWorkspace from '@/Components/Whiteboard/WhiteboardWorkspace.vue';
import CanvasAssetsPanel from '@/Components/SharedCanvas/CanvasAssetsPanel.vue';
import { uploadCanvasAsset } from '@/Components/SharedCanvas/assets';
import { CARD_DRAG_TYPE, createWhiteboardDocument, round1, uid } from '@/Components/Whiteboard/whiteboard';
import { useToast } from '@/Composables/useToast';
import { useSceneSync } from '@/Composables/useSceneSync';
import {
    ArrowDownTrayIcon,
    ArrowLeftIcon,
    PlayIcon,
    PlusIcon,
    RectangleStackIcon,
    XMarkIcon,
} from '@heroicons/vue/24/outline';

// The task board's own whiteboard: one per board, follows board membership, and can hold
// live snapshots of the board's cards. Standalone whiteboards live in Pages/Whiteboards.
const props = defineProps({
    board: { type: Object, required: true },
    whiteboard: { type: Object, default: () => ({}) },
    cards: { type: Array, default: () => [] },
    canEdit: { type: Boolean, default: false },
});

const { showError } = useToast();

const CARD_REFRESH_EVERY = 5; // polls → live card data every ~20s

const doc = createWhiteboardDocument(props.whiteboard?.scene);
const workspace = ref(null);
const editable = ref(props.canEdit);
const liveCards = ref(props.cards || []);
const cardsById = computed(() => Object.fromEntries(liveCards.value.map((card) => [card.id, card])));

// ── Board cards ──────────────────────────────────────────────────────────────

const showCards = ref(props.canEdit);
const asideTab = ref('cards');
const cardSearch = ref('');

// Pictures pasted or dropped on the board go into the uploader's own library (not shared).
const uploadPicture = (file) => uploadCanvasAsset(file, { shared: false });

const cardsOnPage = computed(() => new Set((workspace.value?.currentPage?.elements || [])
    .filter((element) => element.type === 'card')
    .map((element) => element.cardId)));

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
    workspace.value?.addElement({
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
    });
};

const addCardAtCenter = (card) => {
    const center = workspace.value?.viewportCenter() || { x: 0, y: 0 };
    const offset = ((workspace.value?.currentPage?.elements.length || 0) % 6) * 24;
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

// ── Saving & live updates (useSceneSync: autosave, 409 merge, poll) ─────────

let pollCount = 0;
const { saveState, saveError, lastSavedAt, lastSavedBy } = useSceneSync({
    doc,
    editable,
    initialRevision: props.whiteboard?.revision || 0,
    initialSavedAt: props.whiteboard?.updated_at || null,
    initialSavedBy: props.whiteboard?.updated_by || null,
    saveUrl: () => route('task-boards.whiteboard.update', props.board.id),
    stateUrl: () => route('task-boards.whiteboard.state', props.board.id),
    // Live card data (column moves, due dates) every few polls, even when the drawing is idle.
    pollParams: () => ({ cards: ++pollCount % CARD_REFRESH_EVERY === 0 ? 1 : 0 }),
    onPoll: (data) => {
        if (data.cards) liveCards.value = data.cards;
    },
    onError: (message) => showError(message),
});

const saveLabel = computed(() => {
    if (!editable.value) return saveError.value || 'View only';
    if (saveState.value === 'saving') return 'Saving…';
    if (saveState.value === 'pending') return 'Unsaved changes';
    if (saveState.value === 'error') return saveError.value;
    if (!lastSavedAt.value) return 'Nothing saved yet';
    return `Saved ${formatDate(lastSavedAt.value)}${lastSavedBy.value ? ` · ${lastSavedBy.value}` : ''}`;
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

        <WhiteboardWorkspace
            ref="workspace"
            :doc="doc"
            :editable="editable"
            :cards-by-id="cardsById"
            :export-name="board.title"
            :upload-image="editable ? uploadPicture : null"
            @open-card="openCard"
            @drop-card="({ cardId, x, y }) => addCard(cardId, x, y)"
        >
            <template #header="{ startPresenting, exportPng, exporting }">
                <header class="shrink-0 px-4 py-3 text-white" :style="headerStyle">
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
                                Cards &amp; elements
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
            </template>

            <template #aside>
                <aside v-if="editable && showCards" class="hidden w-72 shrink-0 flex-col border-l border-gray-200 bg-white lg:flex dark:border-gray-800 dark:bg-gray-900" data-testid="whiteboard-cards-panel">
                    <div class="flex items-center gap-1 border-b border-gray-100 px-3 pt-2 dark:border-gray-800">
                        <button
                            v-for="option in [{ value: 'cards', label: 'Board cards' }, { value: 'elements', label: 'Elements' }]"
                            :key="option.value"
                            type="button"
                            class="-mb-px border-b-2 px-2 py-1.5 text-xs font-black uppercase tracking-wider"
                            :class="asideTab === option.value ? 'border-blue-600 text-blue-700 dark:text-blue-300' : 'border-transparent text-gray-500 hover:text-gray-800 dark:text-gray-400'"
                            :data-testid="`whiteboard-aside-${option.value}`"
                            @click="asideTab = option.value"
                        >{{ option.label }}</button>
                        <button type="button" aria-label="Hide side panel" class="ml-auto rounded p-1 text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800" @click="showCards = false"><XMarkIcon class="h-4 w-4" /></button>
                    </div>
                    <CanvasAssetsPanel v-if="asideTab === 'elements'" :can-upload="editable" class="min-h-0 flex-1" @add="(asset) => workspace?.addAsset(asset)" />
                    <template v-else>
                    <div class="border-b border-gray-100 p-3 dark:border-gray-800">
                        <p class="text-[11px] text-gray-500 dark:text-gray-400">Drag a card onto the page, or click +. It keeps showing the card’s live status.</p>
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
                    </template>
                </aside>
            </template>
        </WhiteboardWorkspace>

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
