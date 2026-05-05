<script setup>
import { computed, nextTick, onMounted, onUnmounted, reactive, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import MultiAutocomplete from '@/Components/MultiAutocomplete.vue';
import { useConfirm } from '@/Composables/useConfirm';
import { usePermission } from '@/Composables/usePermission';
import { useToast } from '@/Composables/useToast';
import {
    ArchiveBoxIcon,
    ArrowLeftIcon,
    Bars3Icon,
    CalendarDaysIcon,
    ChatBubbleLeftRightIcon,
    CheckCircleIcon,
    CheckIcon,
    ClipboardDocumentCheckIcon,
    EyeIcon,
    FunnelIcon,
    LinkIcon,
    PaperClipIcon,
    PlusIcon,
    StarIcon,
    TrashIcon,
    UserGroupIcon,
    XMarkIcon,
} from '@heroicons/vue/24/outline';
import { StarIcon as StarSolidIcon } from '@heroicons/vue/24/solid';

const props = defineProps({
    board: Object,
    statuses: Array,
    users: Array,
});

const page = usePage();
const { confirm } = useConfirm();
const { showSuccess, showError } = useToast();
const { hasPermission } = usePermission();

const authUser = computed(() => page.props.auth?.user || {});
const localBoard = ref(JSON.parse(JSON.stringify(props.board)));
const selectedCardId = ref(null);
const showBoardMenu = ref(false);
const showMemberModal = ref(false);
const showLabelModal = ref(false);
const activeCardComposer = ref('');
const draggedCardId = ref(null);
const dragOverStatus = ref(null);
const isSaving = ref(false);
const isCreatingCard = ref(false);
const attachmentInput = ref(null);
const filterInput = ref(null);
const cardComposerInputs = ref({});

watch(() => props.board, (board) => {
    localBoard.value = JSON.parse(JSON.stringify(board));
}, { deep: true });

const filters = reactive({
    keyword: '',
    assignee_id: '',
    label_id: '',
    due: '',
    mine: false,
    showArchived: false,
});

const boardForm = reactive({
    title: localBoard.value.title,
    description: localBoard.value.description || '',
    background_type: localBoard.value.background_type || 'color',
    background_value: localBoard.value.background_value || '#0f766e',
});

const cardDraft = reactive({
    title: '',
    description: '',
    start_at: '',
    due_at: '',
    due_complete: false,
    cover_type: '',
    cover_value: '',
});

const memberForm = reactive({
    user_ids: [],
    role: 'member',
});

const labelForm = reactive({
    name: '',
    color: '#2563eb',
});

const editingLabelId = ref(null);
const editingLabelForm = reactive({
    name: '',
    color: '#2563eb',
});

const newComment = ref('');
const newChecklistTitle = ref('Checklist');
const newChecklistItems = reactive({});
const newCardTitles = reactive(Object.fromEntries((props.statuses || []).map((status) => [status, ''])));

const colorOptions = [
    '#0f766e',
    '#1d4ed8',
    '#7c3aed',
    '#be123c',
    '#b45309',
    '#374151',
];

const statusStyles = {
    Backlogs: 'border-slate-300 bg-slate-100 text-slate-700',
    'In Progress': 'border-blue-300 bg-blue-100 text-blue-700',
    'For Verification': 'border-amber-300 bg-amber-100 text-amber-800',
    Done: 'border-emerald-300 bg-emerald-100 text-emerald-700',
};

const canEditBoard = computed(() => {
    return hasPermission('task_lists.edit') &&
        !localBoard.value.closed_at &&
        localBoard.value.my_role !== 'observer';
});

const canManageMembers = computed(() => {
    return hasPermission('task_lists.manage_members') &&
        localBoard.value.my_role === 'admin';
});

const canDeleteBoard = computed(() => {
    return hasPermission('task_lists.delete') &&
        localBoard.value.my_role === 'admin';
});

const selectedCard = computed(() => {
    return (localBoard.value.cards || []).find((card) => card.id === selectedCardId.value) || null;
});

const boardMembers = computed(() => localBoard.value.members || []);

const availableMembers = computed(() => {
    const memberIds = new Set(boardMembers.value.map((member) => member.id));
    return (props.users || []).filter((user) => !memberIds.has(user.id));
});

const availableMemberOptions = computed(() => {
    return availableMembers.value.map((user) => ({
        ...user,
        label: `${user.name}${user.email ? ` - ${user.email}` : ''}`,
    }));
});

const boardStyle = computed(() => {
    if (localBoard.value.background_type === 'image' && localBoard.value.background_value) {
        return {
            backgroundImage: `linear-gradient(120deg, rgba(15, 23, 42, 0.7), rgba(15, 23, 42, 0.22)), url(${localBoard.value.background_value})`,
            backgroundSize: 'cover',
            backgroundPosition: 'center',
        };
    }

    return {
        background: localBoard.value.background_value || '#0f766e',
    };
});

const activeFilterCount = computed(() => {
    return [
        filters.keyword,
        filters.assignee_id,
        filters.label_id,
        filters.due,
        filters.mine,
        filters.showArchived,
    ].filter(Boolean).length;
});

watch(selectedCard, (card) => {
    if (!card) return;
    cardDraft.title = card.title || '';
    cardDraft.description = card.description || '';
    cardDraft.start_at = toDateTimeInput(card.start_at);
    cardDraft.due_at = toDateTimeInput(card.due_at);
    cardDraft.due_complete = !!card.due_complete;
    cardDraft.cover_type = card.cover_type || '';
    cardDraft.cover_value = card.cover_value || '';
}, { immediate: true });

const initials = (name) => (name || 'U').split(' ').map((part) => part[0]).join('').slice(0, 2).toUpperCase();

const userAvatar = (user, size = 'h-8 w-8') => {
    return { user, size };
};

const toDateTimeInput = (value) => {
    if (!value) return '';
    return String(value).replace(' ', 'T').slice(0, 16);
};

const assetUrl = (path) => {
    if (!path) return '';
    if (String(path).startsWith('http') || String(path).startsWith('/serve-storage')) return path;
    return '/serve-storage/' + String(path).replace(/^public\//, '').replace(/\\/g, '/');
};

const formatDate = (value) => {
    if (!value) return '';
    return new Date(String(value).replace(' ', 'T')).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
    });
};

const formatDateTime = (value) => {
    if (!value) return '';
    return new Date(String(value).replace(' ', 'T')).toLocaleString('en-US', {
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
};

const dueState = (card) => {
    if (!card.due_at) return 'none';
    if (card.due_complete) return 'complete';

    const due = new Date(String(card.due_at).replace(' ', 'T')).getTime();
    const now = Date.now();
    if (due < now) return 'overdue';
    if (due - now <= 24 * 60 * 60 * 1000) return 'soon';
    return 'normal';
};

const dueBadgeClass = (card) => {
    switch (dueState(card)) {
        case 'complete': return 'bg-emerald-100 text-emerald-700 border-emerald-200';
        case 'overdue': return 'bg-red-100 text-red-700 border-red-200';
        case 'soon': return 'bg-amber-100 text-amber-800 border-amber-200';
        default: return 'bg-gray-100 text-gray-700 border-gray-200';
    }
};

const namedLabelColors = {
    red: '#dc2626',
    amber: '#d97706',
    emerald: '#059669',
    blue: '#2563eb',
    violet: '#7c3aed',
    pink: '#db2777',
    gray: '#4b5563',
};

const normalizeHexColor = (value, fallback = '#2563eb') => {
    const color = String(value || '').trim();
    return /^#[0-9a-f]{6}$/i.test(color) ? color : fallback;
};

const labelColorValue = (color) => {
    const value = String(color || '').trim();
    if (/^#[0-9a-f]{6}$/i.test(value)) return value;

    return namedLabelColors[value] || '#4b5563';
};

const readableTextColor = (hexColor) => {
    const normalized = normalizeHexColor(hexColor, '#4b5563').replace('#', '');
    const red = parseInt(normalized.slice(0, 2), 16);
    const green = parseInt(normalized.slice(2, 4), 16);
    const blue = parseInt(normalized.slice(4, 6), 16);
    const luminance = (0.299 * red + 0.587 * green + 0.114 * blue) / 255;

    return luminance > 0.62 ? '#111827' : '#ffffff';
};

const labelClass = (color) => {
    const map = {
        red: 'bg-red-100 text-red-700 border-red-200',
        amber: 'bg-amber-100 text-amber-800 border-amber-200',
        emerald: 'bg-emerald-100 text-emerald-700 border-emerald-200',
        blue: 'bg-blue-100 text-blue-700 border-blue-200',
        violet: 'bg-violet-100 text-violet-700 border-violet-200',
        pink: 'bg-pink-100 text-pink-700 border-pink-200',
        gray: 'bg-gray-100 text-gray-700 border-gray-200',
    };

    return map[color] || map.gray;
};

const labelStyle = (color) => {
    if (!String(color || '').startsWith('#')) return {};

    const backgroundColor = labelColorValue(color);

    return {
        backgroundColor,
        borderColor: backgroundColor,
        color: readableTextColor(backgroundColor),
    };
};

const coverStyle = (card) => {
    if (card.cover_type === 'image' && card.cover_value) {
        return {
            backgroundImage: `url(${assetUrl(card.cover_value)})`,
            backgroundSize: 'cover',
            backgroundPosition: 'center',
        };
    }

    if (card.cover_type === 'color' && card.cover_value) {
        return { background: card.cover_value };
    }

    return {};
};

const sortCards = (cards) => {
    return [...cards].sort((a, b) => {
        if ((a.sort_order || 0) !== (b.sort_order || 0)) {
            return (a.sort_order || 0) - (b.sort_order || 0);
        }

        return a.id - b.id;
    });
};

const allCardsForStatus = (status) => {
    return sortCards((localBoard.value.cards || []).filter((card) => {
        return card.status === status && !card.archived_at;
    }));
};

const visibleCardsForStatus = (status) => {
    return sortCards((localBoard.value.cards || []).filter((card) => {
        return card.status === status && matchesFilters(card);
    }));
};

const matchesFilters = (card) => {
    if (!filters.showArchived && card.archived_at) return false;

    if (filters.keyword) {
        const term = filters.keyword.toLowerCase();
        const haystack = [
            card.title,
            card.description,
            ...(card.comments || []).map((comment) => comment.comment_text),
            ...(card.checklists || []).flatMap((checklist) => (checklist.items || []).map((item) => item.title)),
        ].join(' ').toLowerCase();

        if (!haystack.includes(term)) return false;
    }

    if (filters.assignee_id && !(card.assignees || []).some((user) => String(user.id) === String(filters.assignee_id))) return false;
    if (filters.label_id && !(card.labels || []).some((label) => String(label.id) === String(filters.label_id))) return false;
    if (filters.mine && !(card.assignees || []).some((user) => Number(user.id) === Number(authUser.value.id))) return false;

    if (filters.due) {
        const state = dueState(card);
        if (filters.due === 'none' && card.due_at) return false;
        if (filters.due !== 'none' && state !== filters.due) return false;
    }

    return true;
};

const clearFilters = () => {
    filters.keyword = '';
    filters.assignee_id = '';
    filters.label_id = '';
    filters.due = '';
    filters.mine = false;
    filters.showArchived = false;
};

const replaceCard = (card) => {
    const cards = [...(localBoard.value.cards || [])];
    const index = cards.findIndex((item) => item.id === card.id);
    if (index === -1) {
        cards.push(card);
    } else {
        cards.splice(index, 1, card);
    }
    localBoard.value.cards = cards;

    if (selectedCardId.value === card.id) {
        selectedCardId.value = card.id;
    }
};

const removeCard = (cardId) => {
    localBoard.value.cards = (localBoard.value.cards || []).filter((card) => card.id !== cardId);
    if (selectedCardId.value === cardId) selectedCardId.value = null;
};

const handleApiError = (error, fallback = 'Action failed') => {
    showError(error.response?.data?.message || fallback);
};

const waitForCardDialogClose = () => new Promise((resolve) => {
    window.setTimeout(resolve, 250);
});

const closeSelectedCardBeforeConfirm = async (card) => {
    if (!card || selectedCardId.value !== card.id) return false;

    selectedCardId.value = null;
    await nextTick();
    await waitForCardDialogClose();

    return true;
};

const setCardComposerInput = (status, el) => {
    if (el) {
        cardComposerInputs.value[status] = el;
    } else {
        delete cardComposerInputs.value[status];
    }
};

const openCardComposer = async (status) => {
    if (!canEditBoard.value) return;

    activeCardComposer.value = status;
    newCardTitles[status] = newCardTitles[status] || '';

    await nextTick();
    cardComposerInputs.value[status]?.focus();
};

const closeCardComposer = (status) => {
    if (status && activeCardComposer.value !== status) return;

    activeCardComposer.value = '';
    if (status) {
        newCardTitles[status] = '';
    }
};

const createCard = async (status) => {
    const title = newCardTitles[status]?.trim();
    if (!canEditBoard.value || isCreatingCard.value) return;

    if (!title) {
        showError('Enter a card title first.');
        await openCardComposer(status);
        return;
    }

    isCreatingCard.value = true;

    try {
        const response = await axios.post(route('task-lists.cards.store', localBoard.value.id), { title, status });
        const card = response.data.card;
        replaceCard(card);
        newCardTitles[status] = '';
        activeCardComposer.value = '';
        selectedCardId.value = card.id;
    } catch (error) {
        handleApiError(error, 'Unable to create card');
    } finally {
        isCreatingCard.value = false;
    }
};

const saveCardDetails = async ({ closeModal = true } = {}) => {
    if (!selectedCard.value || !canEditBoard.value || isSaving.value) return;
    isSaving.value = true;

    try {
        const response = await axios.put(route('task-cards.update', selectedCard.value.id), {
            title: cardDraft.title,
            description: cardDraft.description,
            start_at: cardDraft.start_at || null,
            due_at: cardDraft.due_at || null,
            due_complete: cardDraft.due_complete,
            cover_type: cardDraft.cover_type || null,
            cover_value: cardDraft.cover_value || null,
        });
        replaceCard(response.data.card);
        showSuccess('Card updated');
        if (closeModal) {
            selectedCardId.value = null;
        }
    } catch (error) {
        handleApiError(error, 'Unable to update card');
    } finally {
        isSaving.value = false;
    }
};

const updateCard = async (card, payload) => {
    if (!canEditBoard.value) return;

    try {
        const response = await axios.put(route('task-cards.update', card.id), payload);
        replaceCard(response.data.card);
    } catch (error) {
        handleApiError(error, 'Unable to update card');
    }
};

const toggleCardAssignee = (card, member) => {
    const ids = new Set((card.assignees || []).map((user) => user.id));
    ids.has(member.id) ? ids.delete(member.id) : ids.add(member.id);
    updateCard(card, { assignee_ids: [...ids] });
};

const isCardAssignedTo = (card, member) => {
    return !!card?.assignees?.some((user) => Number(user.id) === Number(member.id));
};

const toggleCardLabel = (card, label) => {
    const ids = new Set((card.labels || []).map((item) => item.id));
    ids.has(label.id) ? ids.delete(label.id) : ids.add(label.id);
    updateCard(card, { label_ids: [...ids] });
};

const toggleCardWatch = async (card) => {
    try {
        const watching = !(card.watchers || []).some((user) => Number(user.id) === Number(authUser.value.id));
        const response = await axios.post(route('task-cards.watch', card.id), { watching });
        replaceCard(response.data.card);
    } catch (error) {
        handleApiError(error, 'Unable to update watch state');
    }
};

const archiveCard = async (card) => {
    if (!canEditBoard.value) return;
    const closedCardModal = await closeSelectedCardBeforeConfirm(card);
    const ok = await confirm({
        title: 'Archive Card',
        message: `Archive "${card.title}"?`,
        confirmLabel: 'Archive',
    });
    if (!ok) {
        if (closedCardModal) {
            selectedCardId.value = card.id;
        }
        return;
    }

    try {
        const response = await axios.post(route('task-cards.archive', card.id));
        replaceCard(response.data.card);
        selectedCardId.value = null;
    } catch (error) {
        handleApiError(error, 'Unable to archive card');
    }
};

const restoreCard = async (card) => {
    try {
        const response = await axios.post(route('task-cards.restore', card.id));
        replaceCard(response.data.card);
    } catch (error) {
        handleApiError(error, 'Unable to restore card');
    }
};

const deleteCard = async (card) => {
    if (!card.archived_at || !canDeleteBoard.value) return;
    const closedCardModal = await closeSelectedCardBeforeConfirm(card);
    const ok = await confirm({
        title: 'Delete Card',
        message: `Delete "${card.title}"?`,
        confirmLabel: 'Delete',
        variant: 'danger',
    });
    if (!ok) {
        if (closedCardModal) {
            selectedCardId.value = card.id;
        }
        return;
    }

    try {
        await axios.delete(route('task-cards.destroy', card.id));
        removeCard(card.id);
    } catch (error) {
        handleApiError(error, 'Unable to delete card');
    }
};

const startDrag = (event, card) => {
    if (!canEditBoard.value || card.archived_at) return;
    draggedCardId.value = card.id;
    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData('text/plain', String(card.id));
};

const endDrag = () => {
    draggedCardId.value = null;
    dragOverStatus.value = null;
};

const moveDraggedCard = async (targetStatus, targetCard = null) => {
    if (!draggedCardId.value || !canEditBoard.value) return;

    const movedCard = (localBoard.value.cards || []).find((card) => card.id === draggedCardId.value);
    if (!movedCard || movedCard.archived_at) return;
    if (targetCard && targetCard.id === movedCard.id) {
        endDrag();
        return;
    }

    const targetCards = allCardsForStatus(targetStatus).filter((card) => card.id !== movedCard.id);
    const targetIndex = targetCard ? Math.max(targetCards.findIndex((card) => card.id === targetCard.id), 0) : targetCards.length;
    targetCards.splice(targetIndex, 0, { ...movedCard, status: targetStatus });

    const oldStatus = movedCard.status;
    const oldCards = oldStatus === targetStatus
        ? []
        : allCardsForStatus(oldStatus).filter((card) => card.id !== movedCard.id);

    const nextCards = (localBoard.value.cards || []).map((card) => {
        const targetMatch = targetCards.findIndex((item) => item.id === card.id);
        if (targetMatch >= 0) {
            return { ...card, status: targetStatus, sort_order: (targetMatch + 1) * 1000 };
        }

        const oldMatch = oldCards.findIndex((item) => item.id === card.id);
        if (oldMatch >= 0) {
            return { ...card, sort_order: (oldMatch + 1) * 1000 };
        }

        return card;
    });

    localBoard.value.cards = nextCards;

    try {
        await axios.post(route('task-cards.move', movedCard.id), {
            status: targetStatus,
            ordered_card_ids: targetCards.map((card) => card.id),
        });
    } catch (error) {
        handleApiError(error, 'Unable to save card move');
        router.reload({ only: ['board'] });
    } finally {
        endDrag();
    }
};

const moveCardToStatus = (card, status) => {
    if (!card || card.status === status) return;
    draggedCardId.value = card.id;
    moveDraggedCard(status);
};

const syncBoardLabels = (labels) => {
    localBoard.value.labels = labels || [];
};

const applyUpdatedLabelToCards = (updatedLabel) => {
    if (!updatedLabel) return;

    localBoard.value.cards = (localBoard.value.cards || []).map((card) => ({
        ...card,
        labels: (card.labels || []).map((label) => (
            Number(label.id) === Number(updatedLabel.id) ? updatedLabel : label
        )),
    }));
};

const createLabel = async (card = null) => {
    if (!labelForm.name.trim() || !canEditBoard.value) return;

    try {
        const response = await axios.post(route('task-lists.labels.store', localBoard.value.id), {
            name: labelForm.name.trim(),
            color: normalizeHexColor(labelForm.color),
        });
        syncBoardLabels(response.data.labels);
        if (card?.id && response.data.label?.id) {
            const ids = new Set((card.labels || []).map((item) => item.id));
            ids.add(response.data.label.id);
            await updateCard(card, { label_ids: [...ids] });
        }
        labelForm.name = '';
    } catch (error) {
        handleApiError(error, 'Unable to create label');
    }
};

const startEditingLabel = (label) => {
    editingLabelId.value = label.id;
    editingLabelForm.name = label.name || '';
    editingLabelForm.color = labelColorValue(label.color);
};

const cancelEditingLabel = () => {
    editingLabelId.value = null;
    editingLabelForm.name = '';
    editingLabelForm.color = '#2563eb';
};

const updateLabel = async (label) => {
    if (!editingLabelForm.name.trim() || !canEditBoard.value) return;

    try {
        const response = await axios.put(route('task-labels.update', label.id), {
            name: editingLabelForm.name.trim(),
            color: normalizeHexColor(editingLabelForm.color),
        });
        syncBoardLabels(response.data.labels);
        applyUpdatedLabelToCards(response.data.label);
        cancelEditingLabel();
    } catch (error) {
        handleApiError(error, 'Unable to update label');
    }
};

const deleteLabel = async (label) => {
    try {
        const response = await axios.delete(route('task-labels.destroy', label.id));
        syncBoardLabels(response.data.labels);
        localBoard.value.cards = (localBoard.value.cards || []).map((card) => ({
            ...card,
            labels: (card.labels || []).filter((item) => item.id !== label.id),
        }));
        if (editingLabelId.value === label.id) {
            cancelEditingLabel();
        }
    } catch (error) {
        handleApiError(error, 'Unable to delete label');
    }
};

const addChecklist = async () => {
    if (!selectedCard.value || !newChecklistTitle.value.trim()) return;

    try {
        const response = await axios.post(route('task-cards.checklists.store', selectedCard.value.id), {
            title: newChecklistTitle.value,
        });
        replaceCard(response.data.card);
        newChecklistTitle.value = 'Checklist';
    } catch (error) {
        handleApiError(error, 'Unable to add checklist');
    }
};

const addChecklistItem = async (checklist) => {
    const title = (newChecklistItems[checklist.id] || '').trim();
    if (!title) return;

    try {
        const response = await axios.post(route('task-checklists.items.store', checklist.id), { title });
        replaceCard(response.data.card);
        newChecklistItems[checklist.id] = '';
    } catch (error) {
        handleApiError(error, 'Unable to add item');
    }
};

const toggleChecklistItem = async (item) => {
    try {
        const response = await axios.put(route('task-checklist-items.update', item.id), {
            is_complete: !item.is_complete,
        });
        replaceCard(response.data.card);
    } catch (error) {
        handleApiError(error, 'Unable to update item');
    }
};

const deleteChecklist = async (checklist) => {
    const ok = await confirm({
        title: 'Delete Checklist',
        message: `Delete "${checklist.title}"?`,
        confirmLabel: 'Delete',
        variant: 'danger',
    });
    if (!ok) return;

    try {
        const response = await axios.delete(route('task-checklists.destroy', checklist.id));
        replaceCard(response.data.card);
    } catch (error) {
        handleApiError(error, 'Unable to delete checklist');
    }
};

const deleteChecklistItem = async (item) => {
    try {
        const response = await axios.delete(route('task-checklist-items.destroy', item.id));
        replaceCard(response.data.card);
    } catch (error) {
        handleApiError(error, 'Unable to delete item');
    }
};

const addComment = async () => {
    if (!selectedCard.value || !newComment.value.trim()) return;

    try {
        const response = await axios.post(route('task-cards.comments.store', selectedCard.value.id), {
            comment_text: newComment.value,
        });
        replaceCard(response.data.card);
        newComment.value = '';
    } catch (error) {
        handleApiError(error, 'Unable to add comment');
    }
};

const deleteComment = async (comment) => {
    try {
        const response = await axios.delete(route('task-card-comments.destroy', comment.id));
        replaceCard(response.data.card);
    } catch (error) {
        handleApiError(error, 'Unable to delete comment');
    }
};

const uploadAttachment = async (event) => {
    const file = event.target.files?.[0];
    if (!file || !selectedCard.value) return;

    if (file.size > 50 * 1024 * 1024) {
        showError('Attachment exceeds the 50 MB limit.');
        event.target.value = '';
        return;
    }

    const formData = new FormData();
    formData.append('attachment', file);

    try {
        const response = await axios.post(route('task-cards.attachments.store', selectedCard.value.id), formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
        replaceCard(response.data.card);
    } catch (error) {
        handleApiError(error, 'Unable to upload attachment');
    } finally {
        event.target.value = '';
    }
};

const openAttachmentPicker = () => {
    attachmentInput.value?.click();
};

const deleteAttachment = async (attachment) => {
    try {
        const response = await axios.delete(route('task-card-attachments.destroy', attachment.id));
        replaceCard(response.data.card);
    } catch (error) {
        handleApiError(error, 'Unable to delete attachment');
    }
};

const saveBoardSettings = async () => {
    if (!canManageMembers.value && localBoard.value.my_role !== 'admin') return;

    try {
        const response = await axios.put(route('task-lists.update', localBoard.value.id), boardForm);
        localBoard.value = { ...localBoard.value, ...response.data.board };
        showSuccess('Board updated');
    } catch (error) {
        handleApiError(error, 'Unable to update board');
    }
};

const toggleStar = async () => {
    try {
        const response = await axios.post(route('task-lists.star', localBoard.value.id), {
            starred: !localBoard.value.starred,
        });
        localBoard.value.starred = response.data.starred;
    } catch (error) {
        handleApiError(error, 'Unable to update star');
    }
};

const toggleBoardWatch = async () => {
    try {
        const response = await axios.post(route('task-lists.watch', localBoard.value.id), {
            watching: !localBoard.value.watching,
        });
        localBoard.value.watching = response.data.watching;
    } catch (error) {
        handleApiError(error, 'Unable to update watch state');
    }
};

const addBoardMember = async () => {
    if (!memberForm.user_ids.length || !canManageMembers.value) return;

    try {
        const response = await axios.post(route('task-lists.members.store', localBoard.value.id), memberForm);
        localBoard.value.members = response.data.members;
        memberForm.user_ids = [];
        memberForm.role = 'member';
    } catch (error) {
        handleApiError(error, 'Unable to add member');
    }
};

const updateBoardMember = async (member, role) => {
    try {
        const response = await axios.put(route('task-lists.members.update', [localBoard.value.id, member.id]), { role });
        localBoard.value.members = response.data.members;
    } catch (error) {
        handleApiError(error, 'Unable to update member');
    }
};

const removeBoardMember = async (member) => {
    const ok = await confirm({
        title: 'Remove Member',
        message: `Remove ${member.name} from this board?`,
        confirmLabel: 'Remove',
        variant: 'danger',
    });
    if (!ok) return;

    try {
        const response = await axios.delete(route('task-lists.members.destroy', [localBoard.value.id, member.id]));
        localBoard.value.members = response.data.members;
    } catch (error) {
        handleApiError(error, 'Unable to remove member');
    }
};

const closeBoard = async () => {
    const ok = await confirm({
        title: 'Close Board',
        message: `Close "${localBoard.value.title}"?`,
        confirmLabel: 'Close board',
        variant: 'danger',
    });
    if (!ok) return;

    router.delete(route('task-lists.destroy', localBoard.value.id));
};

const restoreBoard = () => {
    router.post(route('task-lists.restore', localBoard.value.id));
};

const handleKeyboard = (event) => {
    const tag = event.target?.tagName?.toLowerCase();
    const isTyping = ['input', 'textarea', 'select'].includes(tag);

    if (event.key === 'Escape') {
        selectedCardId.value = null;
        showBoardMenu.value = false;
        showMemberModal.value = false;
        showLabelModal.value = false;
        return;
    }

    if (isTyping || event.metaKey || event.ctrlKey || event.altKey) return;

    if (event.key === 'f') {
        event.preventDefault();
        filterInput.value?.focus();
    }

    if (event.key === 'x') {
        event.preventDefault();
        clearFilters();
    }

    if (event.key === 'b') {
        event.preventDefault();
        router.visit(route('task-lists.index'));
    }

    if (event.key === 'c' && selectedCard.value && canEditBoard.value && !selectedCard.value.archived_at) {
        event.preventDefault();
        archiveCard(selectedCard.value);
    }
};

onMounted(() => {
    window.addEventListener('keydown', handleKeyboard);
});

onUnmounted(() => {
    window.removeEventListener('keydown', handleKeyboard);
});
</script>

<template>
    <Head :title="localBoard.title" />

    <AppLayout content-class="max-w-none px-0 sm:px-0 lg:px-0">
        <template #header>Task Lists</template>

        <div class="flex h-[calc(100vh-6rem)] min-h-[680px] flex-col overflow-hidden text-gray-900" :style="boardStyle">
            <header class="shrink-0 border-b border-white/10 bg-black/20 px-4 py-3 text-white backdrop-blur-md">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                    <div class="flex min-w-0 items-center gap-3">
                        <Link :href="route('task-lists.index')" class="rounded-lg p-2 text-white/80 transition-colors hover:bg-white/15 hover:text-white">
                            <ArrowLeftIcon class="h-5 w-5" />
                        </Link>
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <h1 class="truncate text-xl font-black">{{ localBoard.title }}</h1>
                                <span v-if="localBoard.closed_at" class="rounded-full bg-white/15 px-2 py-0.5 text-[10px] font-black uppercase tracking-wider">Closed</span>
                            </div>
                            <p class="truncate text-xs font-medium text-white/70">{{ localBoard.description || 'Services task board' }}</p>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <button type="button" @click="toggleStar" class="rounded-lg p-2 transition-colors hover:bg-white/15" title="Star board">
                            <StarSolidIcon v-if="localBoard.starred" class="h-5 w-5 text-yellow-300" />
                            <StarIcon v-else class="h-5 w-5 text-white/80" />
                        </button>
                        <button type="button" @click="toggleBoardWatch" class="inline-flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-semibold transition-colors hover:bg-white/15">
                            <EyeIcon class="h-4 w-4" />
                            {{ localBoard.watching ? 'Watching' : 'Watch' }}
                        </button>
                        <button type="button" @click="showMemberModal = true" class="inline-flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-semibold transition-colors hover:bg-white/15">
                            <UserGroupIcon class="h-4 w-4" />
                            {{ boardMembers.length }}
                        </button>
                        <button type="button" @click="showBoardMenu = !showBoardMenu" class="inline-flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-semibold transition-colors hover:bg-white/15">
                            <Bars3Icon class="h-4 w-4" />
                            Menu
                        </button>
                    </div>
                </div>

                <div class="mt-3 flex flex-col gap-2 lg:flex-row lg:items-center">
                    <div class="flex min-w-0 flex-1 items-center gap-2 rounded-lg bg-white/95 px-3 py-2 text-gray-800 shadow-sm">
                        <FunnelIcon class="h-4 w-4 shrink-0 text-gray-400" />
                        <input ref="filterInput" v-model="filters.keyword" type="search" class="h-8 min-w-0 flex-1 border-0 bg-transparent p-0 text-sm focus:ring-0" placeholder="Filter cards...">
                    </div>
                    <select v-model="filters.assignee_id" class="h-10 rounded-lg border-0 bg-white/95 text-sm text-gray-800 shadow-sm">
                        <option value="">All members</option>
                        <option v-for="member in boardMembers" :key="member.id" :value="member.id">{{ member.name }}</option>
                    </select>
                    <select v-model="filters.label_id" class="h-10 rounded-lg border-0 bg-white/95 text-sm text-gray-800 shadow-sm">
                        <option value="">All labels</option>
                        <option v-for="label in localBoard.labels" :key="label.id" :value="label.id">{{ label.name || label.color }}</option>
                    </select>
                    <select v-model="filters.due" class="h-10 rounded-lg border-0 bg-white/95 text-sm text-gray-800 shadow-sm">
                        <option value="">All due dates</option>
                        <option value="overdue">Overdue</option>
                        <option value="soon">Due soon</option>
                        <option value="complete">Complete</option>
                        <option value="none">No due date</option>
                    </select>
                    <label class="inline-flex h-10 items-center gap-2 rounded-lg bg-white/95 px-3 text-sm font-semibold text-gray-700 shadow-sm">
                        <input v-model="filters.mine" type="checkbox" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                        My cards
                    </label>
                    <label class="inline-flex h-10 items-center gap-2 rounded-lg bg-white/95 px-3 text-sm font-semibold text-gray-700 shadow-sm">
                        <input v-model="filters.showArchived" type="checkbox" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                        Archived
                    </label>
                    <button v-if="activeFilterCount" type="button" @click="clearFilters" class="h-10 rounded-lg bg-white/15 px-3 text-sm font-bold text-white hover:bg-white/20">
                        Clear
                    </button>
                </div>
            </header>

            <main class="flex-1 overflow-x-auto overflow-y-hidden px-4 py-4">
                <div class="flex h-full gap-4">
                    <section
                        v-for="status in statuses"
                        :key="status"
                        class="flex h-full w-[19rem] shrink-0 flex-col rounded-lg bg-gray-100 shadow-xl ring-1 ring-black/10"
                        @dragover.prevent="dragOverStatus = status"
                        @dragleave="dragOverStatus = null"
                        @drop.prevent="moveDraggedCard(status)"
                    >
                        <div class="flex items-center justify-between gap-3 border-b border-gray-200 px-3 py-3">
                            <div class="flex items-center gap-2">
                                <span class="rounded-full border px-2 py-1 text-[10px] font-black uppercase tracking-wider" :class="statusStyles[status]">
                                    {{ status }}
                                </span>
                                <span class="text-xs font-bold text-gray-500">{{ visibleCardsForStatus(status).length }}</span>
                            </div>
                        </div>

                        <div class="custom-scrollbar flex-1 space-y-2 overflow-y-auto p-2" :class="dragOverStatus === status ? 'bg-blue-50/70' : ''">
                            <article
                                v-for="card in visibleCardsForStatus(status)"
                                :key="card.id"
                                draggable="true"
                                class="group cursor-pointer overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm transition hover:border-blue-200 hover:shadow-md"
                                :class="draggedCardId === card.id ? 'opacity-40' : ''"
                                @click="selectedCardId = card.id"
                                @dragstart="startDrag($event, card)"
                                @dragend="endDrag"
                                @dragover.prevent
                                @drop.prevent.stop="moveDraggedCard(status, card)"
                            >
                                <div v-if="card.cover_type" class="h-20" :style="coverStyle(card)"></div>
                                <div class="p-3">
                                    <div v-if="card.labels?.length" class="mb-2 flex flex-wrap gap-1">
                                        <span
                                            v-for="label in card.labels"
                                            :key="label.id"
                                            class="rounded-full border px-2 py-0.5 text-[10px] font-black"
                                            :class="labelClass(label.color)"
                                        >
                                            {{ label.name || label.color }}
                                        </span>
                                    </div>
                                    <h3 class="text-sm font-bold leading-snug text-gray-900">{{ card.title }}</h3>
                                    <div class="mt-3 flex flex-wrap items-center gap-2 text-xs text-gray-500">
                                        <span v-if="card.due_at" class="inline-flex items-center gap-1 rounded-md border px-2 py-1 font-bold" :class="dueBadgeClass(card)">
                                            <CalendarDaysIcon class="h-3.5 w-3.5" />
                                            {{ formatDate(card.due_at) }}
                                        </span>
                                        <span v-if="card.checklist_totals?.total" class="inline-flex items-center gap-1 rounded-md bg-gray-100 px-2 py-1 font-bold text-gray-700">
                                            <ClipboardDocumentCheckIcon class="h-3.5 w-3.5" />
                                            {{ card.checklist_totals.complete }}/{{ card.checklist_totals.total }}
                                        </span>
                                        <span v-if="card.comments?.length" class="inline-flex items-center gap-1">
                                            <ChatBubbleLeftRightIcon class="h-3.5 w-3.5" />
                                            {{ card.comments.length }}
                                        </span>
                                        <span v-if="card.attachments?.length" class="inline-flex items-center gap-1">
                                            <PaperClipIcon class="h-3.5 w-3.5" />
                                            {{ card.attachments.length }}
                                        </span>
                                    </div>
                                    <div v-if="card.assignees?.length" class="mt-3 flex -space-x-2">
                                        <div v-for="member in card.assignees.slice(0, 5)" :key="member.id" class="flex h-7 w-7 items-center justify-center overflow-hidden rounded-full border-2 border-white bg-gray-100 text-[10px] font-bold text-gray-700" :title="member.name">
                                            <img v-if="member.profile_photo" :src="'/serve-storage/' + member.profile_photo" class="h-full w-full object-cover" :alt="member.name">
                                            <span v-else>{{ initials(member.name) }}</span>
                                        </div>
                                    </div>
                                </div>
                            </article>
                        </div>

                        <div v-if="canEditBoard" class="border-t border-gray-200 p-2">
                            <button
                                v-if="activeCardComposer !== status"
                                type="button"
                                class="inline-flex w-full items-center justify-start gap-2 rounded-lg px-3 py-2 text-sm font-bold text-gray-600 transition-colors hover:bg-gray-200 hover:text-gray-900"
                                @click="openCardComposer(status)"
                            >
                                <PlusIcon class="h-4 w-4" />
                                Add Card
                            </button>
                            <form v-else class="space-y-2" @submit.prevent="createCard(status)">
                                <textarea
                                    :ref="(el) => setCardComposerInput(status, el)"
                                    v-model="newCardTitles[status]"
                                    rows="3"
                                    class="w-full resize-none rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                    placeholder="Enter a title for this card..."
                                    @keydown.esc.prevent="closeCardComposer(status)"
                                    @keydown.ctrl.enter.prevent="createCard(status)"
                                    @keydown.meta.enter.prevent="createCard(status)"
                                ></textarea>
                                <div class="flex items-center gap-2">
                                    <button type="button" :disabled="isCreatingCard" class="inline-flex flex-1 items-center justify-center gap-2 rounded-lg bg-blue-600 px-3 py-2 text-sm font-bold text-white shadow-sm hover:bg-blue-700 disabled:opacity-50" @click="createCard(status)">
                                        <PlusIcon class="h-4 w-4" />
                                        {{ isCreatingCard ? 'Creating...' : 'Create' }}
                                    </button>
                                    <button type="button" class="rounded-lg p-2 text-gray-400 hover:bg-gray-200 hover:text-gray-600" @click="closeCardComposer(status)">
                                        <XMarkIcon class="h-5 w-5" />
                                    </button>
                                </div>
                            </form>
                        </div>
                    </section>
                </div>
            </main>
        </div>

        <aside v-if="showBoardMenu" class="fixed right-0 top-0 z-50 flex h-screen w-full max-w-md flex-col border-l border-gray-200 bg-white shadow-2xl">
            <div class="flex items-center justify-between border-b border-gray-200 p-4">
                <h2 class="text-lg font-bold text-gray-900">Board Menu</h2>
                <button type="button" @click="showBoardMenu = false" class="rounded-lg p-2 text-gray-400 hover:bg-gray-100 hover:text-gray-600">
                    <XMarkIcon class="h-5 w-5" />
                </button>
            </div>
            <div class="custom-scrollbar flex-1 space-y-6 overflow-y-auto p-4">
                <section v-if="localBoard.my_role === 'admin'" class="space-y-3">
                    <h3 class="text-xs font-black uppercase tracking-widest text-gray-500">Settings</h3>
                    <input v-model="boardForm.title" type="text" class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    <textarea v-model="boardForm.description" rows="3" class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"></textarea>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="color in colorOptions"
                            :key="color"
                            type="button"
                            class="h-8 w-12 rounded-md border-2"
                            :class="boardForm.background_value === color ? 'border-gray-900' : 'border-white ring-1 ring-gray-200'"
                            :style="{ background: color }"
                            @click="boardForm.background_type = 'color'; boardForm.background_value = color"
                        ></button>
                    </div>
                    <button type="button" @click="saveBoardSettings" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-bold text-white hover:bg-blue-700">Save Settings</button>
                </section>

                <section class="space-y-3">
                    <div class="flex items-center justify-between">
                        <h3 class="text-xs font-black uppercase tracking-widest text-gray-500">Labels</h3>
                        <button v-if="canEditBoard" type="button" @click="showLabelModal = true" class="text-xs font-bold text-blue-600">Manage</button>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <span v-for="label in localBoard.labels" :key="label.id" class="rounded-full border px-2 py-1 text-xs font-bold" :class="labelClass(label.color)" :style="labelStyle(label.color)">
                            {{ label.name || label.color }}
                        </span>
                    </div>
                </section>

                <section class="space-y-3">
                    <h3 class="text-xs font-black uppercase tracking-widest text-gray-500">Activity</h3>
                    <div class="space-y-3">
                        <div v-for="activity in localBoard.activities" :key="activity.id" class="flex gap-3">
                            <div class="flex h-8 w-8 shrink-0 items-center justify-center overflow-hidden rounded-full bg-gray-100 text-[10px] font-bold text-gray-600">
                                <img v-if="activity.actor?.profile_photo" :src="'/serve-storage/' + activity.actor.profile_photo" class="h-full w-full object-cover" :alt="activity.actor.name">
                                <span v-else>{{ initials(activity.actor?.name || 'System') }}</span>
                            </div>
                            <div class="min-w-0">
                                <p class="text-sm text-gray-700"><span class="font-bold">{{ activity.actor?.name || 'System' }}</span> {{ activity.description }}</p>
                                <p class="text-xs text-gray-400">{{ formatDateTime(activity.created_at) }}</p>
                            </div>
                        </div>
                    </div>
                </section>

                <section v-if="localBoard.closed_at || canDeleteBoard" class="space-y-3 border-t border-gray-100 pt-4">
                    <button v-if="localBoard.closed_at" type="button" @click="restoreBoard" class="w-full rounded-lg bg-emerald-600 px-4 py-2 text-sm font-bold text-white hover:bg-emerald-700">Reopen Board</button>
                    <button v-else-if="canDeleteBoard" type="button" @click="closeBoard" class="w-full rounded-lg bg-red-600 px-4 py-2 text-sm font-bold text-white hover:bg-red-700">Close Board</button>
                </section>
            </div>
        </aside>

        <Modal :show="!!selectedCard" @close="selectedCardId = null" maxWidth="4xl">
            <div v-if="selectedCard" class="max-h-[90vh] overflow-hidden rounded-lg bg-gray-50">
                <div v-if="selectedCard.cover_type" class="h-36" :style="coverStyle(selectedCard)"></div>
                <div class="flex items-center justify-between border-b border-gray-200 bg-white px-5 py-4">
                    <div class="min-w-0">
                        <input v-model="cardDraft.title" :disabled="!canEditBoard" class="w-full border-0 bg-transparent p-0 text-xl font-black text-gray-900 focus:ring-0">
                        <p class="mt-1 text-xs font-bold text-gray-500">in {{ selectedCard.status }}</p>
                    </div>
                    <button type="button" @click="selectedCardId = null" class="rounded-lg p-2 text-gray-400 hover:bg-gray-100 hover:text-gray-600">
                        <XMarkIcon class="h-5 w-5" />
                    </button>
                </div>

                <div class="custom-scrollbar grid max-h-[calc(90vh-7rem)] grid-cols-1 gap-6 overflow-y-auto p-5 lg:grid-cols-[minmax(0,1fr)_18rem]">
                    <div class="space-y-6">
                        <section class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                            <div class="mb-3 flex items-center justify-between">
                                <h3 class="text-sm font-black uppercase tracking-wider text-gray-700">Details</h3>
                                <button v-if="canEditBoard" type="button" @click="saveCardDetails" :disabled="isSaving" class="rounded-lg bg-blue-600 px-3 py-2 text-xs font-bold text-white hover:bg-blue-700 disabled:opacity-50">
                                    {{ isSaving ? 'Saving...' : 'Save' }}
                                </button>
                            </div>
                            <textarea v-model="cardDraft.description" :disabled="!canEditBoard" rows="5" class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="Description"></textarea>
                            <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-2">
                                <div>
                                    <label class="mb-1 block text-xs font-bold uppercase tracking-wider text-gray-500">Status</label>
                                    <select :value="selectedCard.status" :disabled="!canEditBoard || !!selectedCard.archived_at" class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" @change="moveCardToStatus(selectedCard, $event.target.value)">
                                        <option v-for="status in statuses" :key="status" :value="status">{{ status }}</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs font-bold uppercase tracking-wider text-gray-500">Start</label>
                                    <input v-model="cardDraft.start_at" :disabled="!canEditBoard" type="datetime-local" class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                </div>
                            </div>
                            <div class="mt-3 grid grid-cols-1 gap-3 md:grid-cols-2">
                                <div>
                                    <label class="mb-1 block text-xs font-bold uppercase tracking-wider text-gray-500">Due</label>
                                    <input v-model="cardDraft.due_at" :disabled="!canEditBoard" type="datetime-local" class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                </div>
                            </div>
                            <label class="mt-3 inline-flex items-center gap-2 text-sm font-semibold text-gray-700">
                                <input v-model="cardDraft.due_complete" :disabled="!canEditBoard" type="checkbox" class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500">
                                Mark complete
                            </label>
                        </section>

                        <section class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                            <div class="mb-3 flex items-center justify-between">
                                <h3 class="text-sm font-black uppercase tracking-wider text-gray-700">Checklists</h3>
                                <form v-if="canEditBoard" class="flex gap-2" @submit.prevent="addChecklist">
                                    <input v-model="newChecklistTitle" type="text" class="h-9 rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                    <button type="submit" class="rounded-lg bg-gray-900 px-3 text-xs font-bold text-white">Add</button>
                                </form>
                            </div>
                            <div class="space-y-4">
                                <div v-for="checklist in selectedCard.checklists" :key="checklist.id" class="rounded-lg border border-gray-100 bg-gray-50 p-3">
                                    <div class="mb-2 flex items-center justify-between">
                                        <h4 class="text-sm font-bold text-gray-900">{{ checklist.title }}</h4>
                                        <button v-if="canEditBoard" type="button" @click="deleteChecklist(checklist)" class="text-xs font-bold text-red-600">Delete</button>
                                    </div>
                                    <div class="space-y-2">
                                        <div v-for="item in checklist.items" :key="item.id" class="flex items-center gap-2 rounded-lg bg-white px-3 py-2 shadow-sm">
                                            <button type="button" @click="toggleChecklistItem(item)" :disabled="!canEditBoard" class="flex h-5 w-5 shrink-0 items-center justify-center rounded border" :class="item.is_complete ? 'border-emerald-500 bg-emerald-500 text-white' : 'border-gray-300 bg-white'">
                                                <CheckIcon v-if="item.is_complete" class="h-3.5 w-3.5" />
                                            </button>
                                            <span class="flex-1 text-sm" :class="item.is_complete ? 'text-gray-400 line-through' : 'text-gray-700'">{{ item.title }}</span>
                                            <button v-if="canEditBoard" type="button" @click="deleteChecklistItem(item)" class="text-gray-300 hover:text-red-600">
                                                <XMarkIcon class="h-4 w-4" />
                                            </button>
                                        </div>
                                    </div>
                                    <form v-if="canEditBoard" class="mt-2 flex gap-2" @submit.prevent="addChecklistItem(checklist)">
                                        <input v-model="newChecklistItems[checklist.id]" type="text" class="h-9 flex-1 rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="Add an item...">
                                        <button type="submit" class="rounded-lg bg-blue-600 px-3 text-xs font-bold text-white">Add</button>
                                    </form>
                                </div>
                            </div>
                        </section>

                        <section class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                            <div class="mb-3 flex items-center justify-between">
                                <h3 class="text-sm font-black uppercase tracking-wider text-gray-700">Attachments</h3>
                                <button v-if="canEditBoard" type="button" @click="openAttachmentPicker" class="rounded-lg bg-gray-100 px-3 py-2 text-xs font-bold text-gray-700 hover:bg-gray-200">
                                    Add
                                </button>
                                <input ref="attachmentInput" type="file" class="hidden" @change="uploadAttachment">
                            </div>
                            <div class="space-y-2">
                                <div v-for="attachment in selectedCard.attachments" :key="attachment.id" class="flex items-center justify-between gap-3 rounded-lg border border-gray-100 bg-gray-50 px-3 py-2">
                                    <a :href="assetUrl(attachment.file_storage_path)" target="_blank" class="flex min-w-0 items-center gap-2 text-sm font-semibold text-blue-700 hover:text-blue-800">
                                        <LinkIcon class="h-4 w-4 shrink-0" />
                                        <span class="truncate">{{ attachment.file_name }}</span>
                                    </a>
                                    <button v-if="canEditBoard" type="button" @click="deleteAttachment(attachment)" class="text-gray-300 hover:text-red-600">
                                        <TrashIcon class="h-4 w-4" />
                                    </button>
                                </div>
                            </div>
                        </section>

                        <section class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                            <h3 class="mb-3 text-sm font-black uppercase tracking-wider text-gray-700">Comments and Activity</h3>
                            <form class="mb-4 flex gap-2" @submit.prevent="addComment">
                                <input v-model="newComment" type="text" class="h-10 flex-1 rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="Write a comment...">
                                <button type="submit" class="rounded-lg bg-blue-600 px-4 text-sm font-bold text-white hover:bg-blue-700">Comment</button>
                            </form>
                            <div class="space-y-4">
                                <div v-for="comment in selectedCard.comments" :key="comment.id" class="flex gap-3">
                                    <div class="flex h-8 w-8 shrink-0 items-center justify-center overflow-hidden rounded-full bg-gray-100 text-[10px] font-bold text-gray-600">
                                        <img v-if="comment.user?.profile_photo" :src="'/serve-storage/' + comment.user.profile_photo" class="h-full w-full object-cover" :alt="comment.user.name">
                                        <span v-else>{{ initials(comment.user?.name) }}</span>
                                    </div>
                                    <div class="min-w-0 flex-1 rounded-lg bg-gray-50 p-3">
                                        <div class="mb-1 flex items-center justify-between gap-2">
                                            <p class="text-sm font-bold text-gray-900">{{ comment.user?.name }}</p>
                                            <button v-if="comment.user_id === authUser.id || canEditBoard" type="button" @click="deleteComment(comment)" class="text-xs font-bold text-red-600">Delete</button>
                                        </div>
                                        <p class="whitespace-pre-line text-sm text-gray-700">{{ comment.comment_text }}</p>
                                        <p class="mt-1 text-xs text-gray-400">{{ formatDateTime(comment.created_at) }}</p>
                                    </div>
                                </div>
                                <div v-for="activity in selectedCard.activities" :key="'activity-' + activity.id" class="flex gap-3">
                                    <div class="flex h-8 w-8 shrink-0 items-center justify-center overflow-hidden rounded-full bg-gray-100 text-[10px] font-bold text-gray-600">
                                        <img v-if="activity.actor?.profile_photo" :src="'/serve-storage/' + activity.actor.profile_photo" class="h-full w-full object-cover" :alt="activity.actor.name">
                                        <span v-else>{{ initials(activity.actor?.name || 'System') }}</span>
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <p class="text-sm text-gray-700"><span class="font-bold">{{ activity.actor?.name || 'System' }}</span> {{ activity.description }}</p>
                                        <p class="text-xs text-gray-400">{{ formatDateTime(activity.created_at) }}</p>
                                    </div>
                                </div>
                            </div>
                        </section>
                    </div>

                    <aside class="space-y-4">
                        <section class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                            <h3 class="mb-3 text-xs font-black uppercase tracking-widest text-gray-500">Members</h3>
                            <div class="space-y-2">
                                <button
                                    v-for="member in boardMembers"
                                    :key="member.id"
                                    type="button"
                                    :disabled="!canEditBoard"
                                    class="group flex w-full items-center justify-between gap-2 rounded-lg px-2 py-2 text-left transition-colors disabled:cursor-default"
                                    :class="canEditBoard ? 'hover:bg-blue-50' : 'opacity-80'"
                                    :aria-label="isCardAssignedTo(selectedCard, member) ? `Unassign ${member.name}` : `Assign ${member.name}`"
                                    :title="isCardAssignedTo(selectedCard, member) ? `Unassign ${member.name}` : `Assign ${member.name}`"
                                    @click="toggleCardAssignee(selectedCard, member)"
                                >
                                    <span class="flex min-w-0 items-center gap-2">
                                        <span class="flex h-7 w-7 items-center justify-center overflow-hidden rounded-full bg-gray-100 text-[10px] font-bold text-gray-700">
                                            <img v-if="member.profile_photo" :src="'/serve-storage/' + member.profile_photo" class="h-full w-full object-cover" :alt="member.name">
                                            <span v-else>{{ initials(member.name) }}</span>
                                        </span>
                                        <span class="truncate text-sm font-semibold text-gray-700">{{ member.name }}</span>
                                    </span>
                                    <span
                                        v-if="isCardAssignedTo(selectedCard, member)"
                                        class="inline-flex shrink-0 items-center gap-1 rounded-md bg-emerald-50 px-2 py-1 text-[11px] font-black uppercase tracking-wide text-emerald-700 ring-1 ring-emerald-100"
                                    >
                                        <CheckCircleIcon class="h-4 w-4" />
                                        Assigned
                                    </span>
                                    <span
                                        v-else-if="canEditBoard"
                                        class="inline-flex shrink-0 items-center gap-1 rounded-md bg-blue-50 px-2 py-1 text-[11px] font-black uppercase tracking-wide text-blue-700 ring-1 ring-blue-100 transition-colors group-hover:bg-blue-100"
                                    >
                                        <PlusIcon class="h-4 w-4" />
                                        Assign
                                    </span>
                                </button>
                            </div>
                        </section>

                        <section class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                            <h3 class="mb-3 text-xs font-black uppercase tracking-widest text-gray-500">Labels</h3>
                            <div class="space-y-2">
                                <button
                                    v-for="label in localBoard.labels"
                                    :key="label.id"
                                    type="button"
                                    :disabled="!canEditBoard"
                                    class="flex w-full items-center justify-between rounded-lg border px-3 py-2 text-sm font-bold"
                                    :class="labelClass(label.color)"
                                    :style="labelStyle(label.color)"
                                    @click="toggleCardLabel(selectedCard, label)"
                                >
                                    {{ label.name || label.color }}
                                    <CheckIcon v-if="selectedCard.labels?.some((item) => item.id === label.id)" class="h-4 w-4" />
                                </button>
                            </div>
                            <form v-if="canEditBoard" class="mt-3 grid grid-cols-[minmax(0,1fr)_2.5rem_auto] gap-2 border-t border-gray-100 pt-3" @submit.prevent="createLabel(selectedCard)">
                                <input v-model="labelForm.name" type="text" required class="h-10 rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="New label">
                                <input v-model="labelForm.color" type="color" class="h-10 w-10 rounded-lg border border-gray-300 bg-white p-1">
                                <button type="submit" class="rounded-lg bg-blue-600 px-3 text-xs font-bold text-white hover:bg-blue-700">Add</button>
                            </form>
                        </section>

                        <section class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                            <h3 class="mb-3 text-xs font-black uppercase tracking-widest text-gray-500">Cover</h3>
                            <div class="flex flex-wrap gap-2">
                                <button
                                    v-for="color in colorOptions"
                                    :key="color"
                                    type="button"
                                    :disabled="!canEditBoard"
                                    class="h-8 w-12 rounded-md border-2"
                                    :class="cardDraft.cover_value === color ? 'border-gray-900' : 'border-white ring-1 ring-gray-200'"
                                    :style="{ background: color }"
                                    @click="cardDraft.cover_type = 'color'; cardDraft.cover_value = color; saveCardDetails({ closeModal: false })"
                                ></button>
                                <button v-if="canEditBoard" type="button" class="h-8 rounded-md border border-gray-200 px-3 text-xs font-bold text-gray-600" @click="cardDraft.cover_type = ''; cardDraft.cover_value = ''; saveCardDetails({ closeModal: false })">
                                    None
                                </button>
                            </div>
                        </section>

                        <section class="space-y-2">
                            <button type="button" @click="toggleCardWatch(selectedCard)" class="flex w-full items-center justify-center gap-2 rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm font-bold text-gray-700 shadow-sm hover:bg-gray-50">
                                <EyeIcon class="h-4 w-4" />
                                {{ selectedCard.watchers?.some((user) => user.id === authUser.id) ? 'Watching' : 'Watch' }}
                            </button>
                            <button v-if="canEditBoard && !selectedCard.archived_at" type="button" @click="archiveCard(selectedCard)" class="flex w-full items-center justify-center gap-2 rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm font-bold text-gray-700 shadow-sm hover:bg-gray-50">
                                <ArchiveBoxIcon class="h-4 w-4" />
                                Archive
                            </button>
                            <button v-if="selectedCard.archived_at && canEditBoard" type="button" @click="restoreCard(selectedCard)" class="flex w-full items-center justify-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-bold text-white shadow-sm hover:bg-emerald-700">
                                Restore
                            </button>
                            <button v-if="selectedCard.archived_at && canDeleteBoard" type="button" @click="deleteCard(selectedCard)" class="flex w-full items-center justify-center gap-2 rounded-lg bg-red-600 px-4 py-2 text-sm font-bold text-white shadow-sm hover:bg-red-700">
                                <TrashIcon class="h-4 w-4" />
                                Delete
                            </button>
                        </section>
                    </aside>
                </div>
            </div>
        </Modal>

        <Modal :show="showMemberModal" @close="showMemberModal = false" maxWidth="4xl">
            <div class="p-6">
                <div class="mb-6 flex items-center justify-between">
                    <h2 class="text-xl font-bold text-gray-900">Board Members</h2>
                    <button type="button" @click="showMemberModal = false" class="rounded-lg p-2 text-gray-400 hover:bg-gray-100 hover:text-gray-600">
                        <XMarkIcon class="h-5 w-5" />
                    </button>
                </div>

                <div class="space-y-3">
                    <div v-for="member in boardMembers" :key="member.id" class="flex flex-col gap-3 rounded-lg border border-gray-200 p-3 sm:flex-row sm:items-center sm:justify-between">
                        <div class="flex min-w-0 items-center gap-3">
                            <div class="flex h-9 w-9 items-center justify-center overflow-hidden rounded-full bg-gray-100 text-xs font-bold text-gray-700">
                                <img v-if="member.profile_photo" :src="'/serve-storage/' + member.profile_photo" class="h-full w-full object-cover" :alt="member.name">
                                <span v-else>{{ initials(member.name) }}</span>
                            </div>
                            <div class="min-w-0">
                                <p class="truncate text-sm font-bold text-gray-900">{{ member.name }}</p>
                                <p class="truncate text-xs text-gray-500">{{ member.email }}</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <select :value="member.role" :disabled="!canManageMembers" class="h-9 rounded-lg border-gray-300 text-xs font-semibold" @change="updateBoardMember(member, $event.target.value)">
                                <option value="admin">Admin</option>
                                <option value="member">Member</option>
                                <option value="observer">Observer</option>
                            </select>
                            <button v-if="canManageMembers && member.id !== localBoard.created_by" type="button" @click="removeBoardMember(member)" class="rounded-lg p-2 text-red-600 hover:bg-red-50">
                                <TrashIcon class="h-4 w-4" />
                            </button>
                        </div>
                    </div>
                </div>

                <form v-if="canManageMembers && availableMembers.length" class="mt-6 grid grid-cols-1 gap-3 border-t border-gray-100 pt-5 sm:grid-cols-[minmax(0,1fr)_9rem_auto]" @submit.prevent="addBoardMember">
                    <MultiAutocomplete
                        v-model="memberForm.user_ids"
                        :options="availableMemberOptions"
                        label-key="label"
                        value-key="id"
                        placeholder="Select one or more members..."
                    />
                    <select v-model="memberForm.role" class="h-10 rounded-lg border-gray-300 text-sm">
                        <option value="member">Member</option>
                        <option value="admin">Admin</option>
                        <option value="observer">Observer</option>
                    </select>
                    <button type="submit" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-bold text-white hover:bg-blue-700">Add Member</button>
                </form>
            </div>
        </Modal>

        <Modal :show="showLabelModal" @close="showLabelModal = false" maxWidth="2xl">
            <div class="p-6">
                <div class="mb-6 flex items-center justify-between">
                    <h2 class="text-xl font-bold text-gray-900">Labels</h2>
                    <button type="button" @click="showLabelModal = false" class="rounded-lg p-2 text-gray-400 hover:bg-gray-100 hover:text-gray-600">
                        <XMarkIcon class="h-5 w-5" />
                    </button>
                </div>
                <div class="space-y-2">
                    <div v-for="label in localBoard.labels" :key="label.id" class="rounded-lg border border-gray-200 p-3">
                        <form v-if="editingLabelId === label.id" class="grid grid-cols-1 gap-2 sm:grid-cols-[minmax(0,1fr)_3rem_auto_auto]" @submit.prevent="updateLabel(label)">
                            <input v-model="editingLabelForm.name" type="text" required class="h-10 rounded-lg border-gray-300 text-sm" placeholder="Label name">
                            <input v-model="editingLabelForm.color" type="color" class="h-10 w-12 rounded-lg border border-gray-300 bg-white p-1">
                            <button type="submit" class="rounded-lg bg-blue-600 px-3 py-2 text-xs font-bold text-white hover:bg-blue-700">Save</button>
                            <button type="button" class="rounded-lg bg-gray-100 px-3 py-2 text-xs font-bold text-gray-700 hover:bg-gray-200" @click="cancelEditingLabel">Cancel</button>
                        </form>
                        <div v-else class="flex items-center justify-between gap-3">
                            <span class="rounded-full border px-3 py-1 text-xs font-bold" :class="labelClass(label.color)" :style="labelStyle(label.color)">{{ label.name || label.color }}</span>
                            <div v-if="canEditBoard" class="flex items-center gap-3">
                                <button type="button" @click="startEditingLabel(label)" class="text-xs font-bold text-blue-600">Edit</button>
                                <button type="button" @click="deleteLabel(label)" class="text-xs font-bold text-red-600">Delete</button>
                            </div>
                        </div>
                    </div>
                </div>
                <form v-if="canEditBoard" class="mt-5 grid grid-cols-1 gap-3 border-t border-gray-100 pt-5 sm:grid-cols-[minmax(0,1fr)_3rem_auto]" @submit.prevent="createLabel">
                    <input v-model="labelForm.name" type="text" required class="h-10 rounded-lg border-gray-300 text-sm" placeholder="Label name">
                    <input v-model="labelForm.color" type="color" class="h-10 w-12 rounded-lg border border-gray-300 bg-white p-1">
                    <button type="submit" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-bold text-white hover:bg-blue-700">Create</button>
                </form>
            </div>
        </Modal>
    </AppLayout>
</template>

<style scoped>
.custom-scrollbar::-webkit-scrollbar {
    height: 10px;
    width: 8px;
}

.custom-scrollbar::-webkit-scrollbar-track {
    background: transparent;
}

.custom-scrollbar::-webkit-scrollbar-thumb {
    background: rgba(148, 163, 184, 0.7);
    border-radius: 999px;
}
</style>
