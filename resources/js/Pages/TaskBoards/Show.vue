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
    ChevronDownIcon,
    ClipboardDocumentCheckIcon,
    DocumentDuplicateIcon,
    EllipsisHorizontalIcon,
    EyeIcon,
    FunnelIcon,
    LinkIcon,
    PaperClipIcon,
    PencilSquareIcon,
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
    projectsForLink: { type: Array, default: () => [] },
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
const showDuplicateBoardModal = ref(false);
const activeCardComposer = ref('');
const draggedCardId = ref(null);
const dragOverStatus = ref(null);
const isSaving = ref(false);
const isDuplicatingBoard = ref(false);
const isCreatingCard = ref(false);
const isCreatingSubTask = ref(false);
const isSyncingProject = ref(false);
const showLinkProjectModal = ref(false);
const linkProjectSearch = ref('');
const linkProjectId = ref(null);
const isLinkingProject = ref(false);
const attachmentInput = ref(null);
const filterInput = ref(null);
const cardComposerInputs = ref({});
const detailsSectionRef = ref(null);
const lastSavedDetailsSignature = ref('');
const pendingDetailsSave = ref(null);
const isAutoSavingDetails = ref(false);

// Column (list) management
const activeColumnMenu = ref(null);
const editingColumnId = ref(null);
const columnForm = reactive({ name: '', color: '#2563eb' });
const showAddColumn = ref(false);
const newColumnForm = reactive({ name: '', color: '#64748b' });
const isSavingColumn = ref(false);
const draggedColumnId = ref(null);
const dragOverColumnId = ref(null);
const columnNameInputs = ref({});
const addColumnInput = ref(null);

watch(() => props.board, (board) => {
    localBoard.value = JSON.parse(JSON.stringify(board));
}, { deep: true });

const filters = reactive({
    keyword: '',
    assignee_id: '',
    label_id: '',
    due: '',
    milestone: '',
    mine: false,
    showArchived: false,
});

const boardForm = reactive({
    title: localBoard.value.title,
    description: localBoard.value.description || '',
    background_type: localBoard.value.background_type || 'color',
    background_value: localBoard.value.background_value || '#0f766e',
});

const defaultDuplicateBoardTitle = () => `Copy of ${localBoard.value.title || 'Task Board'}`.slice(0, 255);

const duplicateBoardForm = reactive({
    title: defaultDuplicateBoardTitle(),
    copy_members: true,
});

const cardDraft = reactive({
    title: '',
    description: '',
    status: '',
    start_at: '',
    due_at: '',
    due_complete: false,
    cover_type: '',
    cover_value: '',
});

const projectDraft = reactive({
    category: 'General',
    progress: 0,
    assigned_to: '',
    support_by: '',
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
const editingChecklistId = ref(null);
const editingChecklistTitle = ref('');
const checklistTitleInputs = ref({});
const isUpdatingChecklist = ref(false);
const editingItemId = ref(null);
const editingItemTitle = ref('');
const itemTitleInputs = ref({});
const isUpdatingItem = ref(false);
const bulkPasteTarget = ref('');
const newSubTaskTitle = ref('');
const showSubTaskInput = ref(false);
const showChecklistItemInputs = reactive({});
const showSubtaskItemInputs = reactive({});
const newChecklistItems = reactive({});
const newCardTitles = reactive(Object.fromEntries((localBoard.value.columns || []).map((column) => [column.id, ''])));

const collapsedChecklists = reactive({});
const activitySectionOpen = ref(true);
const collapsedSubTaskItems = reactive({});

const colorOptions = [
    '#0f766e',
    '#1d4ed8',
    '#7c3aed',
    '#be123c',
    '#b45309',
    '#374151',
];

const boardColumns = computed(() => {
    return [...(localBoard.value.columns || [])].sort((a, b) => {
        if ((a.sort_order || 0) !== (b.sort_order || 0)) {
            return (a.sort_order || 0) - (b.sort_order || 0);
        }
        return a.id - b.id;
    });
});

const columnNames = computed(() => boardColumns.value.map((column) => column.name));

const backlogColumnName = computed(() => {
    const columns = boardColumns.value;
    return columns.find((column) => column.role === 'backlog')?.name || columns[0]?.name || 'Backlogs';
});

const columnHeaderStyle = (column) => {
    const color = normalizeHexColor(column?.color, '#64748b');
    return { backgroundColor: color, borderColor: color, color: readableTextColor(color) };
};

const columnFor = (name) => boardColumns.value.find((column) => column.name === name) || null;

const canEditBoard = computed(() => {
    return hasPermission('task_boards.edit') &&
        !localBoard.value.closed_at &&
        localBoard.value.my_role !== 'observer';
});

const canManageMembers = computed(() => {
    return hasPermission('task_boards.manage_members') &&
        localBoard.value.my_role === 'admin';
});

const canDeleteBoard = computed(() => {
    return hasPermission('task_boards.delete') &&
        localBoard.value.my_role === 'admin';
});

const canDuplicateBoard = computed(() => hasPermission('task_boards.create'));

const selectedCard = computed(() => {
    return (localBoard.value.cards || []).find((card) => card.id === selectedCardId.value) || null;
});

const boardMembers = computed(() => localBoard.value.members || []);

const isProjectBoard = computed(() => !!localBoard.value.project);

const projectMilestones = computed(() => {
    const milestones = localBoard.value.milestones?.length
        ? localBoard.value.milestones
        : (localBoard.value.cards || [])
            .map((card) => card.project_task?.category)
            .filter(Boolean);

    return [...new Set(milestones)].sort();
});

const topLevelProjectCards = computed(() => {
    return (localBoard.value.cards || []).filter((card) => {
        return card.project_task && !card.project_task.parent_task_id && !card.archived_at;
    });
});

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
        filters.milestone,
        filters.mine,
        filters.showArchived,
    ].filter(Boolean).length;
});

const initials = (name) => (name || 'U').split(' ').map((part) => part[0]).join('').slice(0, 2).toUpperCase();

const userAvatar = (user, size = 'h-8 w-8') => {
    return { user, size };
};

const toDateTimeInput = (value) => {
    if (!value) return '';
    const date = new Date(String(value).replace(' ', 'T'));
    if (isNaN(date.getTime())) return '';
    const pad = (n) => String(n).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
};

const detailsPayloadFromDraft = () => ({
    description: cardDraft.description || '',
    status: cardDraft.status || selectedCard.value?.status || columnNames.value?.[0] || '',
    start_at: cardDraft.start_at || null,
    due_at: cardDraft.due_at || null,
    due_complete: !!cardDraft.due_complete,
});

const detailsPayloadFromCard = (card) => ({
    description: card?.description || '',
    status: card?.status || columnNames.value?.[0] || '',
    start_at: toDateTimeInput(card?.start_at) || null,
    due_at: toDateTimeInput(card?.due_at) || null,
    due_complete: !!card?.due_complete,
});

const detailsSignature = (payload = detailsPayloadFromDraft()) => JSON.stringify(payload);

const hasPendingDetailsChanges = computed(() => {
    return !!selectedCard.value && detailsSignature() !== lastSavedDetailsSignature.value;
});

watch(selectedCard, (card, previousCard) => {
    if (!card) {
        lastSavedDetailsSignature.value = '';
        editingChecklistId.value = null;
        editingChecklistTitle.value = '';
        return;
    }

    const isDifferentCard = previousCard?.id !== card.id;

    if (isDifferentCard) {
        editingChecklistId.value = null;
        editingChecklistTitle.value = '';
    }

    cardDraft.title = card.title || '';

    if (isDifferentCard || !hasPendingDetailsChanges.value) {
        cardDraft.description = card.description || '';
        cardDraft.status = card.status || columnNames.value?.[0] || '';
        cardDraft.start_at = toDateTimeInput(card.start_at);
        cardDraft.due_at = toDateTimeInput(card.due_at);
        cardDraft.due_complete = !!card.due_complete;
        lastSavedDetailsSignature.value = detailsSignature(detailsPayloadFromCard(card));
    }

    cardDraft.cover_type = card.cover_type || '';
    cardDraft.cover_value = card.cover_value || '';
    projectDraft.category = card.project_task?.category || 'General';
    projectDraft.progress = card.project_task?.progress ?? 0;
    projectDraft.assigned_to = card.project_task?.assigned_to || '';
    projectDraft.support_by = card.project_task?.support_by || '';
    newSubTaskTitle.value = '';
    showSubTaskInput.value = false;
}, { immediate: true });

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
            card.project_task?.category,
            card.project_task?.parent_task?.name,
            ...(card.comments || []).map((comment) => comment.comment_text),
            ...(card.checklists || []).flatMap((checklist) => (checklist.items || []).map((item) => item.title)),
        ].join(' ').toLowerCase();

        if (!haystack.includes(term)) return false;
    }

    if (filters.assignee_id && !(card.assignees || []).some((user) => String(user.id) === String(filters.assignee_id))) return false;
    if (filters.label_id && !(card.labels || []).some((label) => String(label.id) === String(filters.label_id))) return false;
    if (filters.milestone && card.project_task?.category !== filters.milestone) return false;
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
    filters.milestone = '';
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

    const saved = await autoSaveDetails();
    if (saved === false) return false;

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

const openCardComposer = async (column) => {
    if (!canEditBoard.value) return;

    activeCardComposer.value = column.id;
    newCardTitles[column.id] = newCardTitles[column.id] || '';

    await nextTick();
    cardComposerInputs.value[column.id]?.focus();
};

const closeCardComposer = (columnId) => {
    if (columnId && activeCardComposer.value !== columnId) return;

    activeCardComposer.value = '';
    if (columnId) {
        newCardTitles[columnId] = '';
    }
};

const createCard = async (column) => {
    const title = newCardTitles[column.id]?.trim();
    if (!canEditBoard.value || isCreatingCard.value) return;

    if (!title) {
        showError('Enter a card title first.');
        await openCardComposer(column);
        return;
    }

    isCreatingCard.value = true;

    try {
        const payload = { title, status: column.name };

        if (isProjectBoard.value) {
            payload.category = filters.milestone || projectMilestones.value[0] || 'General';
        }

        const response = await axios.post(route('task-boards.cards.store', localBoard.value.id), payload);
        const card = response.data.card;
        replaceCard(card);
        newCardTitles[column.id] = '';
        activeCardComposer.value = '';
    } catch (error) {
        handleApiError(error, 'Unable to create card');
    } finally {
        isCreatingCard.value = false;
    }
};

const createSubTask = async () => {
    const title = newSubTaskTitle.value.trim();
    const parentTask = selectedCard.value?.project_task;

    if (!title || !parentTask || parentTask.parent_task_id || isCreatingSubTask.value || !canEditBoard.value) return;

    isCreatingSubTask.value = true;

    try {
        const response = await axios.post(route('task-boards.cards.store', localBoard.value.id), {
            title,
            status: backlogColumnName.value,
            category: parentTask.category || 'General',
            parent_project_task_id: parentTask.id,
        });
        const card = response.data.card;
        replaceCard(card);
        newSubTaskTitle.value = '';
        showSubTaskInput.value = false;
        selectedCardId.value = card.id;
    } catch (error) {
        handleApiError(error, 'Unable to create sub-task');
    } finally {
        isCreatingSubTask.value = false;
    }
};

const saveCardDetails = async ({ closeModal = true, silent = false, detailsOnly = false } = {}) => {
    if (!selectedCard.value || !canEditBoard.value) return false;

    const savingRef = detailsOnly ? isAutoSavingDetails : isSaving;
    if (savingRef.value) return false;
    savingRef.value = true;

    try {
        const payload = detailsOnly ? detailsPayloadFromDraft() : {
            title: cardDraft.title,
            ...detailsPayloadFromDraft(),
            cover_type: cardDraft.cover_type || null,
            cover_value: cardDraft.cover_value || null,
        };

        if (!detailsOnly && selectedCard.value.project_task) {
            payload.project_category = projectDraft.category || 'General';
            payload.project_progress = projectDraft.progress ?? 0;
            payload.project_assigned_to = projectDraft.assigned_to || null;
            payload.project_support_by = projectDraft.support_by || null;
        }

        const response = await axios.put(route('task-cards.update', selectedCard.value.id), payload);
        replaceCard(response.data.card);
        lastSavedDetailsSignature.value = detailsSignature(detailsPayloadFromCard(response.data.card));

        if (!silent) {
            showSuccess('Card updated');
        }

        if (closeModal) {
            selectedCardId.value = null;
        }

        return true;
    } catch (error) {
        handleApiError(error, 'Unable to update card');
        return false;
    } finally {
        savingRef.value = false;
    }
};

const autoSaveDetails = () => {
    if (!selectedCard.value || !canEditBoard.value || !hasPendingDetailsChanges.value) {
        return Promise.resolve(true);
    }

    if (pendingDetailsSave.value) {
        return pendingDetailsSave.value;
    }

    pendingDetailsSave.value = saveCardDetails({
        closeModal: false,
        silent: true,
        detailsOnly: true,
    }).finally(() => {
        pendingDetailsSave.value = null;
    });

    return pendingDetailsSave.value;
};

const closeSelectedCardModal = async () => {
    const saved = await autoSaveDetails();
    if (saved !== false) {
        selectedCardId.value = null;
    }
};

const isInsideDetailsSection = (target) => {
    return !!detailsSectionRef.value && !!target && detailsSectionRef.value.contains(target);
};

const handleDetailsBoundaryPointerDown = (event) => {
    if (!selectedCard.value || isInsideDetailsSection(event.target)) return;
    autoSaveDetails();
};

const handleDetailsBoundaryFocusIn = (event) => {
    if (!selectedCard.value || isInsideDetailsSection(event.target)) return;
    autoSaveDetails();
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

const duplicatingCardId = ref(null);

const duplicateCard = async (card) => {
    if (!canEditBoard.value || duplicatingCardId.value) return;
    const ok = await confirm({
        title: 'Duplicate Card',
        message: `Create a copy of "${card.title}"? The duplicate will include all checklists, items, subtasks, assignees, labels, and dates.`,
        confirmLabel: 'Duplicate',
        cancelLabel: 'Cancel',
        variant: 'info',
    });
    if (!ok) return;
    duplicatingCardId.value = card.id;
    try {
        const response = await axios.post(route('task-cards.duplicate', card.id));
        const newCard = response.data.card;
        // Insert the duplicate right after the original in the local card list.
        const cards = localBoard.value.cards || [];
        const idx = cards.findIndex(c => c.id === card.id);
        if (idx !== -1) {
            cards.splice(idx + 1, 0, newCard);
        } else {
            cards.push(newCard);
        }
        localBoard.value.cards = [...cards];
    } catch (error) {
        handleApiError(error, 'Unable to duplicate card');
    } finally {
        duplicatingCardId.value = null;
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
        const response = await axios.post(route('task-boards.labels.store', localBoard.value.id), {
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

const setColumnNameInput = (columnId, el) => {
    if (el) {
        columnNameInputs.value[columnId] = el;
    } else {
        delete columnNameInputs.value[columnId];
    }
};

const toggleColumnMenu = (columnId) => {
    activeColumnMenu.value = activeColumnMenu.value === columnId ? null : columnId;
};

const openAddColumn = async () => {
    if (!canEditBoard.value) return;
    showAddColumn.value = true;
    newColumnForm.name = '';
    await nextTick();
    addColumnInput.value?.focus();
};

const closeAddColumn = () => {
    showAddColumn.value = false;
    newColumnForm.name = '';
};

const createColumn = async () => {
    const name = newColumnForm.name.trim();
    if (!name || !canEditBoard.value || isSavingColumn.value) return;

    isSavingColumn.value = true;

    try {
        const response = await axios.post(route('task-boards.columns.store', localBoard.value.id), {
            name,
            color: normalizeHexColor(newColumnForm.color, '#64748b'),
        });
        localBoard.value.columns = response.data.columns;
        closeAddColumn();
    } catch (error) {
        handleApiError(error, 'Unable to add column');
    } finally {
        isSavingColumn.value = false;
    }
};

const startEditingColumn = async (column) => {
    if (!canEditBoard.value) return;

    activeColumnMenu.value = null;
    editingColumnId.value = column.id;
    columnForm.name = column.name || '';
    columnForm.color = normalizeHexColor(column.color, '#64748b');

    await nextTick();
    columnNameInputs.value[column.id]?.focus();
    columnNameInputs.value[column.id]?.select();
};

const cancelEditingColumn = () => {
    editingColumnId.value = null;
};

const saveColumn = async (column) => {
    const name = columnForm.name.trim();
    if (!name || !canEditBoard.value || isSavingColumn.value) return;

    const color = normalizeHexColor(columnForm.color, '#64748b');
    if (name === column.name && color === normalizeHexColor(column.color, '#64748b')) {
        cancelEditingColumn();
        return;
    }

    isSavingColumn.value = true;

    try {
        const response = await axios.put(route('task-columns.update', column.id), { name, color });
        localBoard.value.columns = response.data.columns;

        // Keep the cards already in this column matched after a rename.
        if (name !== column.name) {
            localBoard.value.cards = (localBoard.value.cards || []).map((card) => (
                card.column_id === column.id ? { ...card, status: name } : card
            ));
        }

        cancelEditingColumn();
    } catch (error) {
        handleApiError(error, 'Unable to update column');
    } finally {
        isSavingColumn.value = false;
    }
};

const deleteColumn = async (column) => {
    if (!canEditBoard.value) return;

    activeColumnMenu.value = null;

    const ok = await confirm({
        title: 'Delete Column',
        message: `Delete the "${column.name}" column?`,
        confirmLabel: 'Delete',
        variant: 'danger',
    });
    if (!ok) return;

    try {
        const response = await axios.delete(route('task-columns.destroy', column.id));
        localBoard.value.columns = response.data.columns;
    } catch (error) {
        handleApiError(error, 'Unable to delete column');
    }
};

const startColumnDrag = (event, column) => {
    if (!column || !canEditBoard.value || editingColumnId.value === column.id) return;
    draggedColumnId.value = column.id;
    activeColumnMenu.value = null;
    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData('text/plain', `column:${column.id}`);
};

const dragColumnOver = (column) => {
    if (!draggedColumnId.value || !column) return;
    dragOverColumnId.value = column.id;
};

const endColumnDrag = () => {
    draggedColumnId.value = null;
    dragOverColumnId.value = null;
};

const dropColumn = async (targetColumn) => {
    if (!targetColumn || !draggedColumnId.value || draggedColumnId.value === targetColumn.id) {
        endColumnDrag();
        return;
    }

    const ordered = boardColumns.value.map((column) => column.id);
    const from = ordered.indexOf(draggedColumnId.value);
    const to = ordered.indexOf(targetColumn.id);
    if (from === -1 || to === -1) {
        endColumnDrag();
        return;
    }

    ordered.splice(to, 0, ordered.splice(from, 1)[0]);

    const byId = Object.fromEntries((localBoard.value.columns || []).map((column) => [column.id, column]));
    localBoard.value.columns = ordered.map((id, index) => ({ ...byId[id], sort_order: index }));
    endColumnDrag();

    try {
        const response = await axios.post(route('task-boards.columns.reorder', localBoard.value.id), {
            ordered_column_ids: ordered,
        });
        localBoard.value.columns = response.data.columns;
    } catch (error) {
        handleApiError(error, 'Unable to reorder columns');
        router.reload({ only: ['board'] });
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

const setChecklistTitleInput = (checklistId, el) => {
    if (el) {
        checklistTitleInputs.value[checklistId] = el;
    } else {
        delete checklistTitleInputs.value[checklistId];
    }
};

const startEditingChecklist = async (checklist) => {
    if (!canEditBoard.value) return;

    editingChecklistId.value = checklist.id;
    editingChecklistTitle.value = checklist.title || '';

    await nextTick();
    checklistTitleInputs.value[checklist.id]?.focus();
    checklistTitleInputs.value[checklist.id]?.select();
};

const cancelEditingChecklist = () => {
    editingChecklistId.value = null;
    editingChecklistTitle.value = '';
};

const updateChecklist = async (checklist) => {
    const title = editingChecklistTitle.value.trim();
    if (!checklist || !canEditBoard.value || isUpdatingChecklist.value) return;

    if (!title) {
        showError('Enter a checklist title first.');
        return;
    }

    if (title === checklist.title) {
        cancelEditingChecklist();
        return;
    }

    isUpdatingChecklist.value = true;

    try {
        const response = await axios.put(route('task-checklists.update', checklist.id), { title });
        replaceCard(response.data.card);
        cancelEditingChecklist();
    } catch (error) {
        handleApiError(error, 'Unable to rename checklist');
    } finally {
        isUpdatingChecklist.value = false;
    }
};

const parseClipboardList = (event) => {
    const text = event.clipboardData?.getData('text/plain') || '';
    if (!text.includes('\n') && !text.includes('\r')) return [];

    return text
        .split(/\r\n|\n|\r/)
        .map((row) => {
            return row
                .split('\t')
                .map((cell) => cell.trim())
                .find(Boolean) || '';
        })
        .filter(Boolean);
};

const pastedBulkRows = (event) => {
    const rows = parseClipboardList(event);
    if (rows.length <= 1) return [];

    event.preventDefault();

    if (rows.some((row) => row.length > 255)) {
        showError('Pasted checklist rows must be 255 characters or less.');
        return [];
    }

    return rows;
};

const checklistPasteTargetKey = (checklist = null, parentItem = null) => {
    if (!checklist) return 'checklists';
    return parentItem ? `subtasks:${checklist.id}:${parentItem.id}` : `items:${checklist.id}`;
};

const isBulkPastingChecklistTarget = (checklist = null, parentItem = null) => {
    return bulkPasteTarget.value === checklistPasteTargetKey(checklist, parentItem);
};

const pasteChecklistTitles = async (event) => {
    const titles = pastedBulkRows(event);
    if (!titles.length || !selectedCard.value || !canEditBoard.value || bulkPasteTarget.value) return;

    let lastCard = null;
    bulkPasteTarget.value = checklistPasteTargetKey();

    try {
        for (const title of titles) {
            const response = await axios.post(route('task-cards.checklists.store', selectedCard.value.id), { title });
            lastCard = response.data.card;
        }

        if (lastCard) replaceCard(lastCard);
        newChecklistTitle.value = 'Checklist';
    } catch (error) {
        if (lastCard) replaceCard(lastCard);
        handleApiError(error, 'Unable to paste checklists');
    } finally {
        bulkPasteTarget.value = '';
    }
};

const checklistInputKey = (checklist, parentItem = null) => parentItem ? `${checklist.id}:${parentItem.id}` : `${checklist.id}`;

const addChecklistItem = async (checklist, parentItem = null) => {
    const key = checklistInputKey(checklist, parentItem);
    const title = (newChecklistItems[key] || '').trim();
    if (!title) return;

    try {
        const response = await axios.post(route('task-checklists.items.store', checklist.id), {
            title,
            parent_item_id: parentItem?.id || null,
        });
        replaceCard(response.data.card);
        newChecklistItems[key] = '';
        if (parentItem) {
            showSubtaskItemInputs[parentItem.id] = false;
        } else {
            showChecklistItemInputs[checklist.id] = false;
        }
    } catch (error) {
        handleApiError(error, 'Unable to add item');
    }
};

const pasteChecklistItems = async (event, checklist, parentItem = null) => {
    const titles = pastedBulkRows(event);
    if (!titles.length || !checklist || !canEditBoard.value || bulkPasteTarget.value) return;

    const targetKey = checklistPasteTargetKey(checklist, parentItem);
    let lastCard = null;
    bulkPasteTarget.value = targetKey;

    try {
        for (const title of titles) {
            const response = await axios.post(route('task-checklists.items.store', checklist.id), {
                title,
                parent_item_id: parentItem?.id || null,
            });
            lastCard = response.data.card;
        }

        if (lastCard) replaceCard(lastCard);
        newChecklistItems[checklistInputKey(checklist, parentItem)] = '';
    } catch (error) {
        if (lastCard) replaceCard(lastCard);
        handleApiError(error, parentItem ? 'Unable to paste subtasks' : 'Unable to paste items');
    } finally {
        bulkPasteTarget.value = '';
    }
};

const updateChecklistItem = async (item, payload) => {
    try {
        const response = await axios.put(route('task-checklist-items.update', item.id), payload);
        replaceCard(response.data.card);
    } catch (error) {
        handleApiError(error, 'Unable to update item');
    }
};

const toggleChecklistItem = async (item) => {
    const done = !item.is_complete;
    // Done checkbox and the % stay in lock-step (100% when done, 0% when not).
    await updateChecklistItem(item, { is_complete: done, weight: done ? 100 : 0 });
};

const updateChecklistItemAssignee = async (item, value) => {
    await updateChecklistItem(item, { assigned_to: value || null });
};

// ── Per-item / per-subtask progress (%) — syncs straight to the project Gantt ──
const updateItemWeight = async (item, value) => {
    const weight = Math.max(0, Math.min(100, Math.round(Number(value) || 0)));
    // The % is the item/subtask progress; keep the done checkbox in sync.
    await updateChecklistItem(item, { weight, is_complete: weight >= 100 });
};

const setItemTitleInput = (itemId, el) => {
    if (el) {
        itemTitleInputs.value[itemId] = el;
    } else {
        delete itemTitleInputs.value[itemId];
    }
};

const startEditingItem = async (item) => {
    if (!canEditBoard.value) return;

    editingItemId.value = item.id;
    editingItemTitle.value = item.title || '';

    await nextTick();
    itemTitleInputs.value[item.id]?.focus();
    itemTitleInputs.value[item.id]?.select();
};

const cancelEditingItem = () => {
    editingItemId.value = null;
    editingItemTitle.value = '';
};

const saveItemTitle = async (item) => {
    const title = editingItemTitle.value.trim();
    if (!item || !canEditBoard.value || isUpdatingItem.value) return;

    if (!title) {
        showError('Enter an item title first.');
        return;
    }

    if (title === item.title) {
        cancelEditingItem();
        return;
    }

    isUpdatingItem.value = true;

    try {
        const response = await axios.put(route('task-checklist-items.update', item.id), { title });
        replaceCard(response.data.card);
        cancelEditingItem();
    } catch (error) {
        handleApiError(error, 'Unable to rename item');
    } finally {
        isUpdatingItem.value = false;
    }
};

const itemSubUnit = (item) => item.assignee?.sub_unit || 'No Sub-Unit';

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

const toggleChecklist = (id) => {
    collapsedChecklists[id] = !collapsedChecklists[id];
};

const isChecklistOpen = (id) => !collapsedChecklists[id];

const toggleSubTaskItems = (id) => {
    collapsedSubTaskItems[id] = !collapsedSubTaskItems[id];
};

const isSubTaskOpen = (id) => collapsedSubTaskItems[id] === undefined || !collapsedSubTaskItems[id];

const duplicateChecklist = async (checklist) => {
    if (!selectedCard.value || !canEditBoard.value) return;

    try {
        const response = await axios.post(route('task-checklists.duplicate', checklist.id));
        replaceCard(response.data.card);
    } catch (error) {
        handleApiError(error, 'Unable to duplicate checklist');
    }
};

const duplicateChecklistItem = async (item) => {
    if (!selectedCard.value || !canEditBoard.value) return;

    try {
        const response = await axios.post(route('task-checklist-items.duplicate', item.id));
        replaceCard(response.data.card);
    } catch (error) {
        handleApiError(error, 'Unable to duplicate item');
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
        const response = await axios.put(route('task-boards.update', localBoard.value.id), boardForm);
        localBoard.value = { ...localBoard.value, ...response.data.board };
        showSuccess('Board updated');
    } catch (error) {
        handleApiError(error, 'Unable to update board');
    }
};

const toggleStar = async () => {
    try {
        const response = await axios.post(route('task-boards.star', localBoard.value.id), {
            starred: !localBoard.value.starred,
        });
        localBoard.value.starred = response.data.starred;
    } catch (error) {
        handleApiError(error, 'Unable to update star');
    }
};

const toggleBoardWatch = async () => {
    try {
        const response = await axios.post(route('task-boards.watch', localBoard.value.id), {
            watching: !localBoard.value.watching,
        });
        localBoard.value.watching = response.data.watching;
    } catch (error) {
        handleApiError(error, 'Unable to update watch state');
    }
};

const openDuplicateBoardModal = () => {
    if (!canDuplicateBoard.value) return;

    duplicateBoardForm.title = defaultDuplicateBoardTitle();
    duplicateBoardForm.copy_members = true;
    showBoardMenu.value = false;
    showDuplicateBoardModal.value = true;
};

const closeDuplicateBoardModal = () => {
    if (isDuplicatingBoard.value) return;
    showDuplicateBoardModal.value = false;
};

const duplicateBoard = () => {
    const title = duplicateBoardForm.title.trim();
    if (!title || !canDuplicateBoard.value || isDuplicatingBoard.value) return;

    router.post(route('task-boards.duplicate', localBoard.value.id), {
        title,
        copy_members: duplicateBoardForm.copy_members,
    }, {
        onStart: () => { isDuplicatingBoard.value = true; },
        onError: (errors) => {
            showError(Object.values(errors)[0] || 'Unable to duplicate board');
        },
        onFinish: () => { isDuplicatingBoard.value = false; },
    });
};

const addBoardMember = async () => {
    if (!memberForm.user_ids.length || !canManageMembers.value) return;

    try {
        const response = await axios.post(route('task-boards.members.store', localBoard.value.id), memberForm);
        localBoard.value.members = response.data.members;
        memberForm.user_ids = [];
        memberForm.role = 'member';
    } catch (error) {
        handleApiError(error, 'Unable to add member');
    }
};

const updateBoardMember = async (member, role) => {
    try {
        const response = await axios.put(route('task-boards.members.update', [localBoard.value.id, member.id]), { role });
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
        const response = await axios.delete(route('task-boards.members.destroy', [localBoard.value.id, member.id]));
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

    router.delete(route('task-boards.destroy', localBoard.value.id));
};

const restoreBoard = () => {
    router.post(route('task-boards.restore', localBoard.value.id));
};

const syncProjectBoard = () => {
    if (!isProjectBoard.value || isSyncingProject.value) return;

    router.post(route('task-boards.sync-project', localBoard.value.id), {}, {
        preserveScroll: true,
        onStart: () => { isSyncingProject.value = true; },
        onFinish: () => { isSyncingProject.value = false; },
    });
};

const filteredProjectsForLink = computed(() => {
    const q = linkProjectSearch.value.toLowerCase();
    return (props.projectsForLink ?? []).filter(p =>
        !q || p.name.toLowerCase().includes(q)
    );
});

const canLinkProject = computed(() =>
    !isProjectBoard.value &&
    localBoard.value.board_source === 'manual' &&
    canEditBoard.value &&
    (props.projectsForLink ?? []).length > 0
);

const confirmLinkProject = () => {
    if (!linkProjectId.value || isLinkingProject.value) return;
    isLinkingProject.value = true;
    router.post(
        route('task-boards.link-to-project', localBoard.value.id),
        { project_id: linkProjectId.value },
        { onFinish: () => { isLinkingProject.value = false; } }
    );
};

const handleKeyboard = (event) => {
    const tag = event.target?.tagName?.toLowerCase();
    const isTyping = ['input', 'textarea', 'select'].includes(tag);

    if (event.key === 'Escape') {
        closeSelectedCardModal();
        showBoardMenu.value = false;
        showMemberModal.value = false;
        showLabelModal.value = false;
        activeColumnMenu.value = null;
        cancelEditingColumn();
        closeAddColumn();
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
        router.visit(route('task-boards.index'));
    }

    if (event.key === 'c' && selectedCard.value && canEditBoard.value && !selectedCard.value.archived_at) {
        event.preventDefault();
        archiveCard(selectedCard.value);
    }
};

const closeColumnMenu = () => {
    activeColumnMenu.value = null;
};

// ── Auto-sync (project boards) ────────────────────────────────────────────────
// Replaces the manual "Sync" click: a quiet sync runs on an idle interval while the
// tab is visible, on tab re-focus, and ~4s after a card is closed (debounced after
// edits). It is skipped while a card modal is open so it never disrupts active edits.
const AUTO_SYNC_INTERVAL_MS = 45000;
let autoSyncTimer = null;
let autoSyncDebounce = null;

const runAutoSync = async () => {
    if (!isProjectBoard.value || !canEditBoard.value) return;
    if (typeof document !== 'undefined' && document.visibilityState !== 'visible') return;
    if (selectedCardId.value) return;
    if (isSyncingProject.value) return;

    isSyncingProject.value = true;
    try {
        await axios.post(route('task-boards.sync-project', localBoard.value.id));
        router.reload({ only: ['board'], preserveScroll: true });
    } catch (error) {
        // Background sync — stay quiet on failure rather than nagging the user.
    } finally {
        isSyncingProject.value = false;
    }
};

const scheduleAutoSync = (delay = 4000) => {
    if (!isProjectBoard.value) return;
    clearTimeout(autoSyncDebounce);
    autoSyncDebounce = setTimeout(runAutoSync, delay);
};

const handleVisibilitySync = () => {
    if (document.visibilityState === 'visible') scheduleAutoSync(1000);
};

// Closing a card (after edits) triggers a debounced sync.
watch(selectedCardId, (val, oldVal) => {
    if (oldVal && !val) scheduleAutoSync();
});

onMounted(() => {
    window.addEventListener('keydown', handleKeyboard);
    document.addEventListener('pointerdown', handleDetailsBoundaryPointerDown, true);
    document.addEventListener('focusin', handleDetailsBoundaryFocusIn, true);
    document.addEventListener('click', closeColumnMenu);

    if (isProjectBoard.value) {
        autoSyncTimer = setInterval(runAutoSync, AUTO_SYNC_INTERVAL_MS);
        document.addEventListener('visibilitychange', handleVisibilitySync);
    }
});

onUnmounted(() => {
    window.removeEventListener('keydown', handleKeyboard);
    document.removeEventListener('pointerdown', handleDetailsBoundaryPointerDown, true);
    document.removeEventListener('focusin', handleDetailsBoundaryFocusIn, true);
    document.removeEventListener('click', closeColumnMenu);

    clearInterval(autoSyncTimer);
    clearTimeout(autoSyncDebounce);
    document.removeEventListener('visibilitychange', handleVisibilitySync);
});
</script>

<template>
    <Head :title="localBoard.title" />

    <AppLayout content-class="max-w-none px-0 sm:px-0 lg:px-0">
        <template #header>Task Board</template>

        <div class="flex h-[calc(100vh-6rem)] min-h-[680px] flex-col overflow-hidden text-gray-900 dark:text-gray-100" :style="boardStyle">
            <header class="shrink-0 border-b border-white/10 bg-black/20 px-4 py-3 text-white backdrop-blur-md">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                    <div class="flex min-w-0 items-center gap-3">
                        <Link :href="route('task-boards.index')" class="rounded-lg p-2 text-white/80 transition-colors hover:bg-white/15 hover:text-white">
                            <ArrowLeftIcon class="h-5 w-5" />
                        </Link>
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <h1 class="truncate text-xl font-black">{{ localBoard.title }}</h1>
                                <span v-if="localBoard.closed_at" class="rounded-full bg-white/15 px-2 py-0.5 text-[10px] font-black uppercase tracking-wider">Closed</span>
                            </div>
                            <p class="truncate text-xs font-medium text-white/70">{{ localBoard.description || 'Services task board' }}</p>
                            <div v-if="isProjectBoard" class="mt-2 flex flex-wrap items-center gap-2 text-[11px] font-bold text-white/85">
                                <span class="rounded-full bg-white/15 px-2 py-1 uppercase tracking-wider">Project Board</span>
                                <span>{{ localBoard.project?.store?.name || localBoard.project?.name }}</span>
                                <span>{{ localBoard.project?.progress || 0 }}%</span>
                                <span>{{ localBoard.project?.activity_count || 0 }} activities</span>
                                <span>{{ localBoard.project?.subtask_count || 0 }} sub-tasks</span>
                            </div>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <Link v-if="isProjectBoard" :href="route('projects.show', localBoard.project.id)" class="inline-flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-semibold transition-colors hover:bg-white/15">
                            <LinkIcon class="h-4 w-4" />
                            Project
                        </Link>
                        <button v-if="isProjectBoard && canEditBoard" type="button" @click="syncProjectBoard" title="Auto-syncs periodically — click to sync now" class="inline-flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-semibold transition-colors hover:bg-white/15">
                            <ClipboardDocumentCheckIcon class="h-4 w-4" :class="isSyncingProject ? 'animate-spin' : ''" />
                            {{ isSyncingProject ? 'Syncing...' : 'Sync now' }}
                        </button>
                        <button v-if="canLinkProject" type="button" @click="showLinkProjectModal = true" class="inline-flex items-center gap-2 rounded-lg border border-white/30 bg-white/10 px-3 py-2 text-sm font-semibold transition-colors hover:bg-white/20">
                            <LinkIcon class="h-4 w-4" />
                            Link to Project
                        </button>
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
                    <div class="flex min-w-0 flex-1 items-center gap-2 rounded-lg bg-white/95 px-3 py-2 text-gray-800 shadow-sm dark:bg-gray-800/95 dark:text-gray-200">
                        <FunnelIcon class="h-4 w-4 shrink-0 text-gray-400 dark:text-gray-400" />
                        <input ref="filterInput" v-model="filters.keyword" type="search" class="h-8 min-w-0 flex-1 border-0 bg-transparent py-0 pl-2 pr-0 text-sm focus:ring-0" placeholder="Filter cards...">
                    </div>
                    <select v-model="filters.assignee_id" class="h-10 rounded-lg border-0 bg-white/95 text-sm text-gray-800 shadow-sm dark:bg-gray-800/95 dark:text-gray-200">
                        <option value="">All members</option>
                        <option v-for="member in boardMembers" :key="member.id" :value="member.id">{{ member.name }}</option>
                    </select>
                    <select v-model="filters.label_id" class="h-10 rounded-lg border-0 bg-white/95 text-sm text-gray-800 shadow-sm dark:bg-gray-800/95 dark:text-gray-200">
                        <option value="">All labels</option>
                        <option v-for="label in localBoard.labels" :key="label.id" :value="label.id">{{ label.name || label.color }}</option>
                    </select>
                    <select v-if="isProjectBoard" v-model="filters.milestone" class="h-10 rounded-lg border-0 bg-white/95 text-sm text-gray-800 shadow-sm dark:bg-gray-800/95 dark:text-gray-200">
                        <option value="">All milestones</option>
                        <option v-for="milestone in projectMilestones" :key="milestone" :value="milestone">{{ milestone }}</option>
                    </select>
                    <select v-model="filters.due" class="h-10 rounded-lg border-0 bg-white/95 text-sm text-gray-800 shadow-sm dark:bg-gray-800/95 dark:text-gray-200">
                        <option value="">All due dates</option>
                        <option value="overdue">Overdue</option>
                        <option value="soon">Due soon</option>
                        <option value="complete">Complete</option>
                        <option value="none">No due date</option>
                    </select>
                    <label class="inline-flex h-10 items-center gap-2 rounded-lg bg-white/95 px-3 text-sm font-semibold text-gray-700 shadow-sm dark:bg-gray-800/95 dark:text-gray-300">
                        <input v-model="filters.mine" type="checkbox" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 dark:border-gray-600">
                        My cards
                    </label>
                    <label class="inline-flex h-10 items-center gap-2 rounded-lg bg-white/95 px-3 text-sm font-semibold text-gray-700 shadow-sm dark:bg-gray-800/95 dark:text-gray-300">
                        <input v-model="filters.showArchived" type="checkbox" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 dark:border-gray-600">
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
                        v-for="column in boardColumns"
                        :key="column.id"
                        class="flex h-full w-[19rem] shrink-0 flex-col rounded-lg bg-gray-100 shadow-xl ring-1 ring-black/10 dark:bg-gray-800"
                        :class="[
                            dragOverColumnId === column.id ? 'ring-2 ring-blue-400' : '',
                            draggedColumnId === column.id ? 'opacity-60' : '',
                        ]"
                        @dragover.prevent="draggedColumnId ? dragColumnOver(column) : (dragOverStatus = column.name)"
                        @dragleave="draggedColumnId ? null : (dragOverStatus = null)"
                        @drop.prevent="draggedColumnId ? dropColumn(column) : moveDraggedCard(column.name)"
                    >
                        <div
                            class="flex items-center justify-between gap-2 border-b border-gray-200 px-3 py-3 dark:border-gray-700"
                            @dragover.prevent="draggedColumnId ? dragColumnOver(column) : (dragOverStatus = column.name)"
                            @drop.prevent.stop="draggedColumnId ? dropColumn(column) : moveDraggedCard(column.name)"
                        >
                            <form v-if="editingColumnId === column.id" class="w-full space-y-2" @submit.prevent="saveColumn(column)">
                                <input
                                    :ref="(el) => setColumnNameInput(column.id, el)"
                                    v-model="columnForm.name"
                                    type="text"
                                    maxlength="60"
                                    class="h-8 w-full rounded-md border-gray-300 text-sm font-bold shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600"
                                    @keydown.esc.prevent="cancelEditingColumn"
                                >
                                <div class="flex items-center justify-between gap-2">
                                    <div class="flex flex-wrap items-center gap-1">
                                        <button
                                            v-for="swatch in colorOptions"
                                            :key="swatch"
                                            type="button"
                                            class="h-5 w-5 rounded-full border-2"
                                            :style="{ backgroundColor: swatch, borderColor: columnForm.color === swatch ? '#111827' : 'transparent' }"
                                            @click="columnForm.color = swatch"
                                        ></button>
                                    </div>
                                    <div class="flex shrink-0 items-center gap-1">
                                        <button type="button" :disabled="isSavingColumn" class="rounded-md p-1.5 text-emerald-600 hover:bg-emerald-50 disabled:opacity-50" title="Save" @click="saveColumn(column)">
                                            <CheckIcon class="h-4 w-4" />
                                        </button>
                                        <button type="button" class="rounded-md p-1.5 text-gray-400 hover:bg-gray-200 dark:text-gray-400 dark:hover:bg-gray-700" title="Cancel" @click="cancelEditingColumn">
                                            <XMarkIcon class="h-4 w-4" />
                                        </button>
                                    </div>
                                </div>
                            </form>
                            <template v-else>
                                <div class="flex min-w-0 items-center gap-2">
                                    <button
                                        v-if="canEditBoard"
                                        type="button"
                                        class="shrink-0 rounded-md p-1 text-gray-400 transition-colors hover:bg-gray-200 hover:text-gray-700 dark:text-gray-400 dark:hover:bg-gray-700"
                                        draggable="true"
                                        title="Drag to reorder column"
                                        @dragstart="startColumnDrag($event, column)"
                                        @dragend="endColumnDrag"
                                    >
                                        <Bars3Icon class="h-4 w-4" />
                                    </button>
                                    <span
                                        class="truncate rounded-full border px-2 py-1 text-[10px] font-black uppercase tracking-wider"
                                        :style="columnHeaderStyle(column)"
                                    >
                                        {{ column.name }}
                                    </span>
                                    <span class="text-xs font-bold text-gray-500 dark:text-gray-300">{{ visibleCardsForStatus(column.name).length }}</span>
                                </div>
                                <div v-if="canEditBoard" class="relative shrink-0">
                                    <button type="button" class="rounded-full p-1.5 text-gray-400 transition-colors hover:bg-gray-200 hover:text-gray-700 dark:text-gray-400 dark:hover:bg-gray-700" title="Column options" @click.stop="toggleColumnMenu(column.id)">
                                        <EllipsisHorizontalIcon class="h-4 w-4" />
                                    </button>
                                    <div v-if="activeColumnMenu === column.id" class="absolute right-0 top-9 z-20 w-40 overflow-hidden rounded-lg border border-gray-200 bg-white py-1 shadow-xl dark:bg-gray-800 dark:border-gray-700" @click.stop>
                                        <button type="button" class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-gray-700" @click="startEditingColumn(column)">
                                            <PencilSquareIcon class="h-4 w-4" />
                                            Rename
                                        </button>
                                        <button type="button" class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm font-semibold text-red-600 hover:bg-red-50" @click="deleteColumn(column)">
                                            <TrashIcon class="h-4 w-4" />
                                            Delete
                                        </button>
                                    </div>
                                </div>
                            </template>
                        </div>

                        <div class="custom-scrollbar flex-1 space-y-2 overflow-y-auto p-2" :class="dragOverStatus === column.name ? 'bg-blue-50/70' : ''">
                            <article
                                v-for="card in visibleCardsForStatus(column.name)"
                                :key="card.id"
                                draggable="true"
                                class="group relative cursor-pointer overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm transition hover:border-blue-200 hover:shadow-md dark:bg-gray-800 dark:border-gray-700"
                                :class="draggedCardId === card.id ? 'opacity-40' : ''"
                                @click="selectedCardId = card.id"
                                @dragstart="startDrag($event, card)"
                                @dragend="endDrag"
                                @dragover.prevent
                                @drop.prevent.stop="draggedColumnId ? dropColumn(column) : moveDraggedCard(column.name, card)"
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
                                    <h3 class="text-sm font-bold leading-snug text-gray-900 dark:text-gray-100">{{ card.title }}</h3>
                                    <div v-if="card.project_task" class="mt-2 space-y-1">
                                        <div class="flex flex-wrap items-center gap-1.5 text-[10px] font-black uppercase tracking-wide">
                                            <span class="rounded bg-blue-50 px-1.5 py-0.5 text-blue-700">{{ card.project_task.category || 'General' }}</span>
                                            <span class="rounded px-1.5 py-0.5" :class="card.project_task.is_subtask ? 'bg-slate-100 text-slate-600' : 'bg-emerald-50 text-emerald-700'">
                                                {{ card.project_task.is_subtask ? 'Sub-task' : 'Activity' }}
                                            </span>
                                            <span class="rounded bg-gray-100 px-1.5 py-0.5 text-gray-600 dark:bg-gray-800 dark:text-gray-300">{{ card.project_task.progress }}%</span>
                                        </div>
                                        <p v-if="card.project_task.parent_task" class="truncate text-[11px] font-semibold text-gray-500 dark:text-gray-300">
                                            Under {{ card.project_task.parent_task.name }}
                                        </p>
                                    </div>
                                    <div class="mt-3 flex flex-wrap items-center gap-2 text-xs text-gray-500 dark:text-gray-300">
                                        <span v-if="card.due_at" class="inline-flex items-center gap-1 rounded-md border px-2 py-1 font-bold" :class="dueBadgeClass(card)">
                                            <CalendarDaysIcon class="h-3.5 w-3.5" />
                                            <span class="text-[9px] uppercase tracking-wider opacity-75">Due</span>
                                            <span>·</span>
                                            {{ formatDate(card.due_at) }}
                                        </span>
                                        <span v-if="card.checklist_totals?.total" class="inline-flex items-center gap-1 rounded-md bg-gray-100 px-2 py-1 font-bold text-gray-700 dark:bg-gray-800 dark:text-gray-300">
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
                                        <div v-for="member in card.assignees.slice(0, 5)" :key="member.id" class="flex h-7 w-7 items-center justify-center overflow-hidden rounded-full border-2 border-white bg-gray-100 text-[10px] font-bold text-gray-700 dark:bg-gray-800 dark:text-gray-300" :title="member.name">
                                            <img v-if="member.profile_photo" :src="'/serve-storage/' + member.profile_photo" class="h-full w-full object-cover" :alt="member.name">
                                            <span v-else>{{ initials(member.name) }}</span>
                                        </div>
                                    </div>
                                </div>
                                <!-- Hover action: duplicate -->
                                <div v-if="canEditBoard && !card.archived_at" class="absolute right-2 top-2 opacity-0 transition-opacity group-hover:opacity-100">
                                    <button
                                        type="button"
                                        title="Duplicate card"
                                        :disabled="duplicatingCardId === card.id"
                                        class="flex h-7 w-7 items-center justify-center rounded-md bg-white/90 text-gray-500 shadow-sm hover:bg-blue-50 hover:text-blue-600 disabled:opacity-50 dark:bg-gray-700/90 dark:text-gray-300 dark:hover:bg-gray-600 dark:hover:text-blue-400"
                                        @click.stop="duplicateCard(card)"
                                    >
                                        <DocumentDuplicateIcon class="h-3.5 w-3.5" />
                                    </button>
                                </div>
                            </article>
                        </div>

                        <div v-if="canEditBoard" class="border-t border-gray-200 p-2 dark:border-gray-700">
                            <button
                                v-if="activeCardComposer !== column.id"
                                type="button"
                                class="inline-flex w-full items-center justify-start gap-2 rounded-lg px-3 py-2 text-sm font-bold text-gray-600 transition-colors hover:bg-gray-200 hover:text-gray-900 dark:text-gray-300 dark:hover:bg-gray-700"
                                @click="openCardComposer(column)"
                            >
                                <PlusIcon class="h-4 w-4" />
                                Add Card
                            </button>
                            <form v-else class="space-y-2" @submit.prevent="createCard(column)">
                                <textarea
                                    :ref="(el) => setCardComposerInput(column.id, el)"
                                    v-model="newCardTitles[column.id]"
                                    rows="3"
                                    class="w-full resize-none rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600"
                                    placeholder="Enter a title for this card..."
                                    @keydown.esc.prevent="closeCardComposer(column.id)"
                                    @keydown.ctrl.enter.prevent="createCard(column)"
                                    @keydown.meta.enter.prevent="createCard(column)"
                                ></textarea>
                                <div class="flex items-center gap-2">
                                    <button type="button" :disabled="isCreatingCard" class="inline-flex flex-1 items-center justify-center gap-2 rounded-lg bg-blue-600 px-3 py-2 text-sm font-bold text-white shadow-sm hover:bg-blue-700 disabled:opacity-50" @click="createCard(column)">
                                        <PlusIcon class="h-4 w-4" />
                                        {{ isCreatingCard ? 'Creating...' : 'Create' }}
                                    </button>
                                    <button type="button" class="rounded-lg p-2 text-gray-400 hover:bg-gray-200 hover:text-gray-600 dark:text-gray-400 dark:hover:bg-gray-700" @click="closeCardComposer(column.id)">
                                        <XMarkIcon class="h-5 w-5" />
                                    </button>
                                </div>
                            </form>
                        </div>
                    </section>

                    <div v-if="canEditBoard" class="w-[17rem] shrink-0">
                        <button
                            v-if="!showAddColumn"
                            type="button"
                            class="flex w-full items-center gap-2 rounded-lg border-2 border-dashed border-white/40 bg-white/10 px-4 py-3 text-sm font-bold text-white/90 backdrop-blur transition-colors hover:bg-white/20"
                            @click="openAddColumn"
                        >
                            <PlusIcon class="h-4 w-4" />
                            Add Column
                        </button>
                        <form v-else class="space-y-2 rounded-lg bg-white p-3 shadow-xl ring-1 ring-black/10 dark:bg-gray-800" @submit.prevent="createColumn">
                            <input
                                ref="addColumnInput"
                                v-model="newColumnForm.name"
                                type="text"
                                maxlength="60"
                                class="w-full rounded-md border-gray-300 text-sm font-semibold shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600"
                                placeholder="Column name..."
                                @keydown.esc.prevent="closeAddColumn"
                            >
                            <div class="flex items-center gap-1.5">
                                <button
                                    v-for="swatch in colorOptions"
                                    :key="swatch"
                                    type="button"
                                    class="h-6 w-6 rounded-full border-2"
                                    :style="{ backgroundColor: swatch, borderColor: newColumnForm.color === swatch ? '#111827' : 'transparent' }"
                                    @click="newColumnForm.color = swatch"
                                ></button>
                            </div>
                            <div class="flex items-center gap-2">
                                <button type="submit" :disabled="isSavingColumn" class="inline-flex flex-1 items-center justify-center gap-2 rounded-lg bg-blue-600 px-3 py-2 text-sm font-bold text-white shadow-sm hover:bg-blue-700 disabled:opacity-50">
                                    <PlusIcon class="h-4 w-4" />
                                    {{ isSavingColumn ? 'Adding...' : 'Add' }}
                                </button>
                                <button type="button" class="rounded-lg p-2 text-gray-400 hover:bg-gray-200 hover:text-gray-600 dark:text-gray-400 dark:hover:bg-gray-700" @click="closeAddColumn">
                                    <XMarkIcon class="h-5 w-5" />
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </main>
        </div>

        <aside v-if="showBoardMenu" class="fixed right-0 top-0 z-50 flex h-screen w-full max-w-md flex-col border-l border-gray-200 bg-white shadow-2xl dark:bg-gray-800 dark:border-gray-700">
            <div class="flex items-center justify-between border-b border-gray-200 p-4 dark:border-gray-700">
                <h2 class="text-lg font-bold text-gray-900 dark:text-gray-100">Board Menu</h2>
                <button type="button" @click="showBoardMenu = false" class="rounded-lg p-2 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:text-gray-400 dark:hover:bg-gray-700">
                    <XMarkIcon class="h-5 w-5" />
                </button>
            </div>
            <div class="custom-scrollbar flex-1 space-y-6 overflow-y-auto p-4">
                <section v-if="canDuplicateBoard" class="space-y-3">
                    <h3 class="text-xs font-black uppercase tracking-widest text-gray-500 dark:text-gray-300">Board Actions</h3>
                    <button type="button" @click="openDuplicateBoardModal" class="flex w-full items-center justify-center gap-2 rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm font-bold text-gray-700 shadow-sm hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-300 dark:border-gray-700 dark:hover:bg-gray-700">
                        <DocumentDuplicateIcon class="h-4 w-4" />
                        Duplicate Board
                    </button>
                </section>

                <section v-if="isProjectBoard" class="space-y-3">
                    <h3 class="text-xs font-black uppercase tracking-widest text-gray-500 dark:text-gray-300">Project</h3>
                    <div class="rounded-lg border border-blue-100 bg-blue-50 p-3">
                        <p class="text-sm font-black text-blue-950">{{ localBoard.project?.name }}</p>
                        <p class="mt-1 text-xs font-semibold text-blue-700">{{ localBoard.project?.store?.name || 'No store' }}</p>
                        <div class="mt-3 grid grid-cols-3 gap-2 text-center text-xs font-bold text-blue-900">
                            <div class="rounded-md bg-white/70 p-2">{{ localBoard.project?.activity_count || 0 }} activities</div>
                            <div class="rounded-md bg-white/70 p-2">{{ localBoard.project?.subtask_count || 0 }} sub-tasks</div>
                            <div class="rounded-md bg-white/70 p-2">{{ localBoard.project?.progress || 0 }}%</div>
                        </div>
                    </div>
                    <button v-if="canEditBoard" type="button" @click="syncProjectBoard" class="w-full rounded-lg bg-blue-600 px-4 py-2 text-sm font-bold text-white hover:bg-blue-700">
                        {{ isSyncingProject ? 'Syncing...' : 'Sync Activities' }}
                    </button>
                </section>

                <section v-if="localBoard.my_role === 'admin'" class="space-y-3">
                    <h3 class="text-xs font-black uppercase tracking-widest text-gray-500 dark:text-gray-300">Settings</h3>
                    <input v-model="boardForm.title" type="text" class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600">
                    <textarea v-model="boardForm.description" rows="3" class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600"></textarea>
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
                        <h3 class="text-xs font-black uppercase tracking-widest text-gray-500 dark:text-gray-300">Labels</h3>
                        <button v-if="canEditBoard" type="button" @click="showLabelModal = true" class="text-xs font-bold text-blue-600">Manage</button>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <span v-for="label in localBoard.labels" :key="label.id" class="rounded-full border px-2 py-1 text-xs font-bold" :class="labelClass(label.color)" :style="labelStyle(label.color)">
                            {{ label.name || label.color }}
                        </span>
                    </div>
                </section>

                <section class="space-y-3">
                    <h3 class="text-xs font-black uppercase tracking-widest text-gray-500 dark:text-gray-300">Activity</h3>
                    <div class="space-y-3">
                        <div v-for="activity in localBoard.activities" :key="activity.id" class="flex gap-3">
                            <div class="flex h-8 w-8 shrink-0 items-center justify-center overflow-hidden rounded-full bg-gray-100 text-[10px] font-bold text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                                <img v-if="activity.actor?.profile_photo" :src="'/serve-storage/' + activity.actor.profile_photo" class="h-full w-full object-cover" :alt="activity.actor.name">
                                <span v-else>{{ initials(activity.actor?.name || 'System') }}</span>
                            </div>
                            <div class="min-w-0">
                                <p class="text-sm text-gray-700 dark:text-gray-300"><span class="font-bold">{{ activity.actor?.name || 'System' }}</span> {{ activity.description }}</p>
                                <p class="text-xs text-gray-400 dark:text-gray-400">{{ formatDateTime(activity.created_at) }}</p>
                            </div>
                        </div>
                    </div>
                </section>

                <section v-if="localBoard.closed_at || canDeleteBoard" class="space-y-3 border-t border-gray-100 pt-4 dark:border-gray-700">
                    <button v-if="localBoard.closed_at" type="button" @click="restoreBoard" class="w-full rounded-lg bg-emerald-600 px-4 py-2 text-sm font-bold text-white hover:bg-emerald-700">Reopen Board</button>
                    <button v-else-if="canDeleteBoard" type="button" @click="closeBoard" class="w-full rounded-lg bg-red-600 px-4 py-2 text-sm font-bold text-white hover:bg-red-700">Close Board</button>
                </section>
            </div>
        </aside>

        <Modal :show="showDuplicateBoardModal" @close="closeDuplicateBoardModal" maxWidth="lg">
            <form class="rounded-lg bg-white p-6 shadow-xl dark:bg-gray-800" @submit.prevent="duplicateBoard">
                <div class="mb-5 flex items-start justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-black text-gray-900 dark:text-gray-100">Duplicate Board</h2>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-300">Create an independent manual copy of this board.</p>
                    </div>
                    <button type="button" :disabled="isDuplicatingBoard" class="rounded-lg p-2 text-gray-400 hover:bg-gray-100 hover:text-gray-600 disabled:opacity-50 dark:text-gray-400 dark:hover:bg-gray-700" @click="closeDuplicateBoardModal">
                        <XMarkIcon class="h-5 w-5" />
                    </button>
                </div>

                <div class="space-y-4">
                    <label class="block">
                        <span class="mb-1 block text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-gray-300">New board title</span>
                        <input v-model="duplicateBoardForm.title" type="text" required maxlength="255" class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600">
                    </label>

                    <label class="flex items-start gap-3 rounded-lg border border-gray-200 bg-gray-50 p-3 text-sm font-semibold text-gray-700 dark:bg-gray-900/50 dark:text-gray-300 dark:border-gray-700">
                        <input v-model="duplicateBoardForm.copy_members" type="checkbox" class="mt-0.5 rounded border-gray-300 text-blue-600 focus:ring-blue-500 dark:border-gray-600">
                        <span>
                            <span class="block text-gray-900 dark:text-gray-100">Copy board members and roles</span>
                            <span class="mt-1 block text-xs font-medium text-gray-500 dark:text-gray-300">You will be an admin on the copied board either way.</span>
                        </span>
                    </label>

                    <p class="rounded-lg bg-blue-50 px-3 py-2 text-xs font-semibold text-blue-800 dark:bg-blue-950/40 dark:text-blue-200">
                        Cards, columns, labels, checklists, comments, archived state, and attachment references will be copied. Project and monthly sync links will be removed.
                    </p>
                </div>

                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" :disabled="isDuplicatingBoard" class="rounded-lg bg-gray-100 px-4 py-2 text-sm font-bold text-gray-700 hover:bg-gray-200 disabled:opacity-50 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600" @click="closeDuplicateBoardModal">Cancel</button>
                    <button type="submit" :disabled="isDuplicatingBoard || !duplicateBoardForm.title.trim()" class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-bold text-white shadow-sm hover:bg-blue-700 disabled:opacity-50">
                        <DocumentDuplicateIcon class="h-4 w-4" />
                        {{ isDuplicatingBoard ? 'Duplicating...' : 'Duplicate Board' }}
                    </button>
                </div>
            </form>
        </Modal>

        <Modal :show="!!selectedCard" @close="closeSelectedCardModal" maxWidth="4xl">
            <div v-if="selectedCard" class="max-h-[90vh] overflow-hidden rounded-lg bg-gray-50 dark:bg-gray-900/50">
                <div v-if="selectedCard.cover_type" class="h-36" :style="coverStyle(selectedCard)"></div>
                <div class="flex items-center justify-between gap-3 border-b border-gray-200 bg-white px-5 py-4 dark:bg-gray-800 dark:border-gray-700">
                    <div class="min-w-0 flex-1">
                        <input v-model="cardDraft.title" :disabled="!canEditBoard" class="w-full border-0 bg-transparent p-0 text-xl font-black text-gray-900 focus:ring-0 dark:text-gray-100">
                        <p class="mt-1 text-xs font-bold text-gray-500 dark:text-gray-300">in {{ cardDraft.status || selectedCard.status }}</p>
                    </div>
                    <button type="button" @click="closeSelectedCardModal" class="shrink-0 rounded-lg p-2 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:text-gray-400 dark:hover:bg-gray-700">
                        <XMarkIcon class="h-5 w-5" />
                    </button>
                </div>

                <div class="custom-scrollbar grid max-h-[calc(90vh-7rem)] grid-cols-1 gap-6 overflow-y-auto p-5 lg:grid-cols-[minmax(0,1fr)_18rem]">
                    <div class="space-y-6">
                        <section v-if="selectedCard.project_task" class="rounded-lg border border-blue-100 bg-white p-4 shadow-sm dark:bg-gray-800">
                            <div class="mb-3 flex items-center justify-between">
                                <h3 class="text-sm font-black uppercase tracking-wider text-blue-900">Project Activity</h3>
                                <button v-if="canEditBoard" type="button" @click="saveCardDetails({ closeModal: false })" :disabled="isSaving" class="rounded-lg bg-blue-600 px-3 py-2 text-xs font-bold text-white hover:bg-blue-700 disabled:opacity-50">
                                    {{ isSaving ? 'Saving...' : 'Save Project Fields' }}
                                </button>
                            </div>
                            <div class="grid grid-cols-1 gap-3 md:grid-cols-4">
                                <div>
                                    <label class="mb-1 block text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-gray-300">Milestone</label>
                                    <input v-model="projectDraft.category" :disabled="!canEditBoard || selectedCard.project_task.is_subtask" type="text" class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 disabled:bg-gray-50 dark:border-gray-600" @change="saveCardDetails({ closeModal: false, silent: true })">
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-gray-300">Progress</label>
                                    <input v-model="projectDraft.progress" :disabled="!canEditBoard" type="number" min="0" max="100" class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600" @change="saveCardDetails({ closeModal: false, silent: true })">
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-gray-300">Assigned</label>
                                    <select v-model="projectDraft.assigned_to" :disabled="!canEditBoard || !!selectedCard.project_task.external_assignment" class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 disabled:bg-gray-50 dark:border-gray-600" @change="saveCardDetails({ closeModal: false, silent: true })">
                                        <option value="">{{ selectedCard.project_task.external_assignment || 'Unassigned' }}</option>
                                        <option v-for="member in boardMembers" :key="member.id" :value="member.id">{{ member.name }}</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-gray-300">Support</label>
                                    <select v-model="projectDraft.support_by" :disabled="!canEditBoard" class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600" @change="saveCardDetails({ closeModal: false, silent: true })">
                                        <option value="">No support</option>
                                        <option v-for="member in boardMembers" :key="member.id" :value="member.id">{{ member.name }}</option>
                                    </select>
                                </div>
                            </div>
                            <p v-if="selectedCard.project_task.parent_task" class="mt-3 text-xs font-semibold text-gray-500 dark:text-gray-300">
                                Parent activity: {{ selectedCard.project_task.parent_task.name }}
                            </p>
                            <div v-if="canEditBoard && !selectedCard.project_task.parent_task_id" class="mt-4 border-t border-blue-50 pt-4">
                                <form v-if="showSubTaskInput" class="flex gap-2" @submit.prevent="createSubTask">
                                    <input v-model="newSubTaskTitle" type="text" class="h-10 flex-1 rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600" placeholder="Add a sub-task under this activity">
                                    <button type="submit" :disabled="isCreatingSubTask || !newSubTaskTitle.trim()" class="rounded-lg bg-gray-900 px-4 text-sm font-bold text-white hover:bg-gray-800 disabled:opacity-50">
                                        {{ isCreatingSubTask ? 'Adding...' : 'Add' }}
                                    </button>
                                    <button type="button" class="rounded-lg p-2 text-gray-400 hover:bg-blue-50 hover:text-gray-600 dark:text-gray-400" @click="showSubTaskInput = false; newSubTaskTitle = ''">
                                        <XMarkIcon class="h-5 w-5" />
                                    </button>
                                </form>
                                <button v-else type="button" class="inline-flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-bold text-blue-700 transition-colors hover:bg-blue-50" @click="showSubTaskInput = true">
                                    <PlusIcon class="h-4 w-4" />
                                    Add Sub-task
                                </button>
                            </div>
                        </section>

                        <section ref="detailsSectionRef" class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:bg-gray-800 dark:border-gray-700">
                            <div class="mb-3 flex items-center justify-between">
                                <h3 class="text-sm font-black uppercase tracking-wider text-gray-700 dark:text-gray-300">Details</h3>
                                <button v-if="canEditBoard" type="button" @click="saveCardDetails" :disabled="isSaving" class="rounded-lg bg-blue-600 px-3 py-2 text-xs font-bold text-white hover:bg-blue-700 disabled:opacity-50">
                                    {{ isSaving ? 'Saving...' : 'Save' }}
                                </button>
                            </div>
                            <textarea v-model="cardDraft.description" :disabled="!canEditBoard" rows="5" class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600" placeholder="Description"></textarea>
                            <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-2">
                                <div>
                                    <label class="mb-1 block text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-gray-300">Status</label>
                                    <select v-model="cardDraft.status" :disabled="!canEditBoard || !!selectedCard.archived_at" class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600">
                                        <option v-for="status in columnNames" :key="status" :value="status">{{ status }}</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-gray-300">Start</label>
                                    <input v-model="cardDraft.start_at" :disabled="!canEditBoard" type="datetime-local" class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600">
                                </div>
                            </div>
                            <div class="mt-3 grid grid-cols-1 gap-3 md:grid-cols-2">
                                <div>
                                    <label class="mb-1 block text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-gray-300">Due</label>
                                    <input v-model="cardDraft.due_at" :disabled="!canEditBoard" type="datetime-local" class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600">
                                </div>
                            </div>
                            <label class="mt-3 inline-flex items-center gap-2 text-sm font-semibold text-gray-700 dark:text-gray-300">
                                <input v-model="cardDraft.due_complete" :disabled="!canEditBoard" type="checkbox" class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500 dark:border-gray-600">
                                Mark complete
                            </label>
                        </section>

                        <section class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:bg-gray-800 dark:border-gray-700">
                            <div class="mb-3 flex items-center justify-between">
                                <h3 class="text-sm font-black uppercase tracking-wider text-gray-700 dark:text-gray-300">Checklists</h3>
                                <form v-if="canEditBoard" class="flex gap-2" @submit.prevent="addChecklist">
                                    <input v-model="newChecklistTitle" :disabled="isBulkPastingChecklistTarget()" type="text" maxlength="255" class="h-9 rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 disabled:bg-gray-50 dark:border-gray-600" @paste="pasteChecklistTitles">
                                    <button type="submit" :disabled="isBulkPastingChecklistTarget()" class="rounded-lg bg-gray-900 px-3 text-xs font-bold text-white disabled:opacity-50">{{ isBulkPastingChecklistTarget() ? 'Adding...' : 'Add' }}</button>
                                </form>
                            </div>

                            <div class="space-y-4">
                                <div v-for="checklist in selectedCard.checklists" :key="checklist.id" class="rounded-lg border border-gray-100 bg-gray-50 p-3 dark:bg-gray-900/50 dark:border-gray-700">
                                    <div class="mb-2 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                        <form v-if="editingChecklistId === checklist.id" class="flex min-w-0 flex-1 items-center gap-2" @submit.prevent="updateChecklist(checklist)">
                                            <input
                                                :ref="(el) => setChecklistTitleInput(checklist.id, el)"
                                                v-model="editingChecklistTitle"
                                                :disabled="isUpdatingChecklist"
                                                type="text"
                                                maxlength="255"
                                                class="h-9 min-w-0 flex-1 rounded-lg border-gray-300 text-sm font-bold text-gray-900 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:text-gray-100 dark:border-gray-600"
                                                @keydown.esc.prevent="cancelEditingChecklist"
                                            >
                                            <button type="submit" :disabled="isUpdatingChecklist || !editingChecklistTitle.trim()" title="Save checklist title" class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-blue-600 text-white hover:bg-blue-700 disabled:opacity-50">
                                                <CheckIcon class="h-4 w-4" />
                                            </button>
                                            <button type="button" :disabled="isUpdatingChecklist" title="Cancel rename" class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-gray-200 bg-white text-gray-500 hover:bg-gray-100 disabled:opacity-50 dark:bg-gray-800 dark:text-gray-300 dark:border-gray-700 dark:hover:bg-gray-700" @click="cancelEditingChecklist">
                                                <XMarkIcon class="h-4 w-4" />
                                            </button>
                                        </form>
                                        <div v-else class="flex min-w-0 flex-1 items-center gap-2">
                                            <button type="button" class="shrink-0 text-gray-400 hover:text-gray-600 dark:text-gray-400" @click="toggleChecklist(checklist.id)">
                                                <ChevronDownIcon class="h-4 w-4 transition-transform duration-200" :class="isChecklistOpen(checklist.id) ? '' : '-rotate-90'" />
                                            </button>
                                            <h4 class="min-w-0 flex-1 cursor-pointer truncate text-sm font-bold text-gray-900 dark:text-gray-100" @click="toggleChecklist(checklist.id)">{{ checklist.title }}</h4>
                                            <button v-if="canEditBoard" type="button" title="Rename checklist" class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-gray-400 hover:bg-white hover:text-blue-600 dark:text-gray-400 dark:hover:bg-gray-700" @click="startEditingChecklist(checklist)">
                                                <PencilSquareIcon class="h-4 w-4" />
                                            </button>
                                        </div>
                                        <div v-if="canEditBoard && editingChecklistId !== checklist.id" class="flex items-center gap-2 self-start sm:self-auto">
                                            <button type="button" title="Duplicate checklist" class="inline-flex items-center gap-1 text-xs font-bold text-blue-600 hover:text-blue-800" @click="duplicateChecklist(checklist)">
                                                <DocumentDuplicateIcon class="h-3.5 w-3.5" />
                                                Duplicate
                                            </button>
                                            <button type="button" @click="deleteChecklist(checklist)" class="text-xs font-bold text-red-600">Delete</button>
                                        </div>
                                    </div>
                                    <div v-show="isChecklistOpen(checklist.id)" class="space-y-3">
                                        <div v-for="item in checklist.items" :key="item.id" class="space-y-2">
                                            <div class="rounded-lg bg-white px-3 py-2 shadow-sm dark:bg-gray-800">
                                                <div class="flex items-start gap-2">
                                                    <button type="button" @click="toggleChecklistItem(item)" :disabled="!canEditBoard" class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded border" :class="item.is_complete ? 'border-emerald-500 bg-emerald-500 text-white' : 'border-gray-300 bg-white'">
                                                        <CheckIcon v-if="item.is_complete" class="h-3.5 w-3.5" />
                                                    </button>
                                                    <form v-if="editingItemId === item.id" class="flex min-w-0 flex-1 items-center gap-2" @submit.prevent="saveItemTitle(item)">
                                                        <input
                                                            :ref="(el) => setItemTitleInput(item.id, el)"
                                                            v-model="editingItemTitle"
                                                            :disabled="isUpdatingItem"
                                                            type="text"
                                                            maxlength="255"
                                                            class="h-8 min-w-0 flex-1 rounded-lg border-gray-300 text-sm font-semibold text-gray-900 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:text-gray-100 dark:border-gray-600"
                                                            @keydown.esc.prevent="cancelEditingItem"
                                                        >
                                                        <button type="submit" :disabled="isUpdatingItem || !editingItemTitle.trim()" title="Save" class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-blue-600 text-white hover:bg-blue-700 disabled:opacity-50">
                                                            <CheckIcon class="h-4 w-4" />
                                                        </button>
                                                        <button type="button" :disabled="isUpdatingItem" title="Cancel" class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-gray-200 bg-white text-gray-500 hover:bg-gray-100 disabled:opacity-50 dark:bg-gray-800 dark:text-gray-300 dark:border-gray-700" @click="cancelEditingItem">
                                                            <XMarkIcon class="h-4 w-4" />
                                                        </button>
                                                    </form>
                                                    <span v-else class="min-w-0 flex-1 break-words text-sm font-semibold" :class="item.is_complete ? 'text-gray-400 line-through' : 'text-gray-700'">{{ item.title }}</span>
                                                </div>
                                                <div v-if="editingItemId !== item.id" class="ml-7 mt-1.5 flex flex-wrap items-center gap-1.5">
                                                    <button v-if="item.children?.length" type="button" class="text-gray-400 hover:text-gray-600 dark:text-gray-400" @click="toggleSubTaskItems(item.id)">
                                                        <ChevronDownIcon class="h-4 w-4 transition-transform duration-200" :class="isSubTaskOpen(item.id) ? '' : '-rotate-90'" />
                                                    </button>
                                                    <select
                                                        :value="item.assigned_to || ''"
                                                        :disabled="!canEditBoard"
                                                        class="h-8 max-w-[7rem] rounded-lg border-gray-300 text-xs shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600"
                                                        @change="updateChecklistItemAssignee(item, $event.target.value)"
                                                    >
                                                        <option value="">Unassigned</option>
                                                        <option v-for="member in boardMembers" :key="member.id" :value="member.id">{{ member.name }}</option>
                                                    </select>
                                                    <span class="rounded-md bg-blue-50 px-2 py-1 text-[10px] font-black uppercase tracking-wide text-blue-700">{{ itemSubUnit(item) }}</span>
                                                    <div v-if="canEditBoard" class="flex items-center gap-0.5" title="Progress (%) — syncs to the project Gantt activity">
                                                        <input
                                                            :value="item.weight ?? 0"
                                                            type="number" min="0" max="100" step="1"
                                                            class="h-8 w-16 rounded-lg border-gray-300 text-xs font-bold shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
                                                            @change="updateItemWeight(item, $event.target.value)"
                                                        >
                                                        <span class="text-[10px] font-black text-gray-400">%</span>
                                                    </div>
                                                    <button v-if="canEditBoard" type="button" title="Rename item" class="text-gray-300 hover:text-blue-600" @click="startEditingItem(item)">
                                                        <PencilSquareIcon class="h-4 w-4" />
                                                    </button>
                                                    <button v-if="canEditBoard" type="button" title="Duplicate item" class="text-gray-300 hover:text-blue-600" @click="duplicateChecklistItem(item)">
                                                        <DocumentDuplicateIcon class="h-4 w-4" />
                                                    </button>
                                                    <button v-if="canEditBoard" type="button" @click="deleteChecklistItem(item)" class="text-gray-300 hover:text-red-600">
                                                        <XMarkIcon class="h-4 w-4" />
                                                    </button>
                                                </div>
                                            </div>

                                            <div v-show="item.children?.length && isSubTaskOpen(item.id)" class="ml-5 space-y-2 border-l border-gray-200 pl-3 dark:border-gray-700">
                                                <div v-for="child in item.children" :key="child.id" class="rounded-lg bg-white px-3 py-2 shadow-sm dark:bg-gray-800">
                                                    <div class="flex items-start gap-2">
                                                        <button type="button" @click="toggleChecklistItem(child)" :disabled="!canEditBoard" class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded border" :class="child.is_complete ? 'border-emerald-500 bg-emerald-500 text-white' : 'border-gray-300 bg-white'">
                                                            <CheckIcon v-if="child.is_complete" class="h-3.5 w-3.5" />
                                                        </button>
                                                        <form v-if="editingItemId === child.id" class="flex min-w-0 flex-1 items-center gap-2" @submit.prevent="saveItemTitle(child)">
                                                            <input
                                                                :ref="(el) => setItemTitleInput(child.id, el)"
                                                                v-model="editingItemTitle"
                                                                :disabled="isUpdatingItem"
                                                                type="text"
                                                                maxlength="255"
                                                                class="h-8 min-w-0 flex-1 rounded-lg border-gray-300 text-sm text-gray-900 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:text-gray-100 dark:border-gray-600"
                                                                @keydown.esc.prevent="cancelEditingItem"
                                                            >
                                                            <button type="submit" :disabled="isUpdatingItem || !editingItemTitle.trim()" title="Save" class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-blue-600 text-white hover:bg-blue-700 disabled:opacity-50">
                                                                <CheckIcon class="h-4 w-4" />
                                                            </button>
                                                            <button type="button" :disabled="isUpdatingItem" title="Cancel" class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-gray-200 bg-white text-gray-500 hover:bg-gray-100 disabled:opacity-50 dark:bg-gray-800 dark:text-gray-300 dark:border-gray-700" @click="cancelEditingItem">
                                                                <XMarkIcon class="h-4 w-4" />
                                                            </button>
                                                        </form>
                                                        <span v-else class="min-w-0 flex-1 break-words text-sm" :class="child.is_complete ? 'text-gray-400 line-through' : 'text-gray-700'">{{ child.title }}</span>
                                                    </div>
                                                    <div v-if="editingItemId !== child.id" class="ml-7 mt-1.5 flex flex-wrap items-center gap-1.5">
                                                        <select
                                                            :value="child.assigned_to || ''"
                                                            :disabled="!canEditBoard"
                                                            class="h-8 max-w-[7rem] rounded-lg border-gray-300 text-xs shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600"
                                                            @change="updateChecklistItemAssignee(child, $event.target.value)"
                                                        >
                                                            <option value="">Unassigned</option>
                                                            <option v-for="member in boardMembers" :key="member.id" :value="member.id">{{ member.name }}</option>
                                                        </select>
                                                        <span class="rounded-md bg-blue-50 px-2 py-1 text-[10px] font-black uppercase tracking-wide text-blue-700">{{ itemSubUnit(child) }}</span>
                                                        <div v-if="canEditBoard" class="flex items-center gap-0.5" title="Progress (%) — syncs to the project Gantt subtask">
                                                            <input
                                                                :value="child.weight ?? 0"
                                                                type="number" min="0" max="100" step="1"
                                                                class="h-8 w-16 rounded-lg border-gray-300 text-xs font-bold shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
                                                                @change="updateItemWeight(child, $event.target.value)"
                                                            >
                                                            <span class="text-[10px] font-black text-gray-400">%</span>
                                                        </div>
                                                        <button v-if="canEditBoard" type="button" title="Rename sub-task" class="text-gray-300 hover:text-blue-600" @click="startEditingItem(child)">
                                                            <PencilSquareIcon class="h-4 w-4" />
                                                        </button>
                                                        <button v-if="canEditBoard" type="button" title="Duplicate sub-task" class="text-gray-300 hover:text-blue-600" @click="duplicateChecklistItem(child)">
                                                            <DocumentDuplicateIcon class="h-4 w-4" />
                                                        </button>
                                                        <button v-if="canEditBoard" type="button" @click="deleteChecklistItem(child)" class="text-gray-300 hover:text-red-600">
                                                            <XMarkIcon class="h-4 w-4" />
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>

                                            <div v-if="canEditBoard" class="ml-5 border-l border-gray-200 pl-3 dark:border-gray-700">
                                                <form v-if="showSubtaskItemInputs[item.id]" class="flex gap-2" @submit.prevent="addChecklistItem(checklist, item)">
                                                    <input v-model="newChecklistItems[checklistInputKey(checklist, item)]" :disabled="isBulkPastingChecklistTarget(checklist, item)" type="text" maxlength="255" class="h-8 flex-1 rounded-lg border-gray-300 text-xs shadow-sm focus:border-blue-500 focus:ring-blue-500 disabled:bg-gray-50 dark:border-gray-600" placeholder="Add a subtask..." @paste="pasteChecklistItems($event, checklist, item)">
                                                    <button type="submit" :disabled="isBulkPastingChecklistTarget(checklist, item)" class="rounded-lg bg-blue-50 px-3 text-xs font-bold text-blue-700 hover:bg-blue-100 disabled:opacity-50">{{ isBulkPastingChecklistTarget(checklist, item) ? 'Adding...' : 'Add' }}</button>
                                                    <button type="button" class="rounded-lg p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:text-gray-400 dark:hover:bg-gray-700" @click="showSubtaskItemInputs[item.id] = false; newChecklistItems[checklistInputKey(checklist, item)] = ''">
                                                        <XMarkIcon class="h-4 w-4" />
                                                    </button>
                                                </form>
                                                <button v-else type="button" class="inline-flex items-center gap-1 rounded px-2 py-1 text-xs font-bold text-blue-600 transition-colors hover:bg-blue-50" @click="showSubtaskItemInputs[item.id] = true">
                                                    <PlusIcon class="h-3.5 w-3.5" />
                                                    Add subtask
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                    <div v-if="canEditBoard && isChecklistOpen(checklist.id)" class="mt-2">
                                        <form v-if="showChecklistItemInputs[checklist.id]" class="flex gap-2" @submit.prevent="addChecklistItem(checklist)">
                                            <input v-model="newChecklistItems[checklistInputKey(checklist)]" :disabled="isBulkPastingChecklistTarget(checklist)" type="text" maxlength="255" class="h-9 flex-1 rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 disabled:bg-gray-50 dark:border-gray-600" placeholder="Add an item..." @paste="pasteChecklistItems($event, checklist)">
                                            <button type="submit" :disabled="isBulkPastingChecklistTarget(checklist)" class="rounded-lg bg-blue-600 px-3 text-xs font-bold text-white disabled:opacity-50">{{ isBulkPastingChecklistTarget(checklist) ? 'Adding...' : 'Add' }}</button>
                                            <button type="button" class="rounded-lg p-2 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:text-gray-400 dark:hover:bg-gray-700" @click="showChecklistItemInputs[checklist.id] = false; newChecklistItems[checklistInputKey(checklist)] = ''">
                                                <XMarkIcon class="h-4 w-4" />
                                            </button>
                                        </form>
                                        <button v-else type="button" class="inline-flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-bold text-gray-600 transition-colors hover:bg-gray-100 hover:text-gray-800 dark:text-gray-300 dark:hover:bg-gray-700" @click="showChecklistItemInputs[checklist.id] = true">
                                            <PlusIcon class="h-4 w-4" />
                                            Add an item
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </section>

                        <section class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:bg-gray-800 dark:border-gray-700">
                            <div class="mb-3 flex items-center justify-between">
                                <h3 class="text-sm font-black uppercase tracking-wider text-gray-700 dark:text-gray-300">Attachments</h3>
                                <button v-if="canEditBoard" type="button" @click="openAttachmentPicker" class="rounded-lg bg-gray-100 px-3 py-2 text-xs font-bold text-gray-700 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700">
                                    Add
                                </button>
                                <input ref="attachmentInput" type="file" class="hidden" @change="uploadAttachment">
                            </div>
                            <div class="space-y-2">
                                <div v-for="attachment in selectedCard.attachments" :key="attachment.id" class="flex items-center justify-between gap-3 rounded-lg border border-gray-100 bg-gray-50 px-3 py-2 dark:bg-gray-900/50 dark:border-gray-700">
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

                        <section class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:bg-gray-800 dark:border-gray-700">
                            <button type="button" class="mb-3 flex w-full items-center gap-2 text-left" @click="activitySectionOpen = !activitySectionOpen">
                                <ChevronDownIcon class="h-4 w-4 shrink-0 text-gray-500 transition-transform duration-200 dark:text-gray-300" :class="activitySectionOpen ? '' : '-rotate-90'" />
                                <h3 class="text-sm font-black uppercase tracking-wider text-gray-700 dark:text-gray-300">Comments and Activity</h3>
                            </button>
                            <div v-show="activitySectionOpen">
                            <form class="mb-4 flex gap-2" @submit.prevent="addComment">
                                <input v-model="newComment" type="text" class="h-10 flex-1 rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600" placeholder="Write a comment...">
                                <button type="submit" class="rounded-lg bg-blue-600 px-4 text-sm font-bold text-white hover:bg-blue-700">Comment</button>
                            </form>
                            <div class="space-y-4">
                                <div v-for="comment in selectedCard.comments" :key="comment.id" class="flex gap-3">
                                    <div class="flex h-8 w-8 shrink-0 items-center justify-center overflow-hidden rounded-full bg-gray-100 text-[10px] font-bold text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                                        <img v-if="comment.user?.profile_photo" :src="'/serve-storage/' + comment.user.profile_photo" class="h-full w-full object-cover" :alt="comment.user.name">
                                        <span v-else>{{ initials(comment.user?.name) }}</span>
                                    </div>
                                    <div class="min-w-0 flex-1 rounded-lg bg-gray-50 p-3 dark:bg-gray-900/50">
                                        <div class="mb-1 flex items-center justify-between gap-2">
                                            <p class="text-sm font-bold text-gray-900 dark:text-gray-100">{{ comment.user?.name }}</p>
                                            <button v-if="comment.user_id === authUser.id || canEditBoard" type="button" @click="deleteComment(comment)" class="text-xs font-bold text-red-600">Delete</button>
                                        </div>
                                        <p class="whitespace-pre-line text-sm text-gray-700 dark:text-gray-300">{{ comment.comment_text }}</p>
                                        <p class="mt-1 text-xs text-gray-400 dark:text-gray-400">{{ formatDateTime(comment.created_at) }}</p>
                                    </div>
                                </div>
                                <div v-for="activity in selectedCard.activities" :key="'activity-' + activity.id" class="flex gap-3">
                                    <div class="flex h-8 w-8 shrink-0 items-center justify-center overflow-hidden rounded-full bg-gray-100 text-[10px] font-bold text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                                        <img v-if="activity.actor?.profile_photo" :src="'/serve-storage/' + activity.actor.profile_photo" class="h-full w-full object-cover" :alt="activity.actor.name">
                                        <span v-else>{{ initials(activity.actor?.name || 'System') }}</span>
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <p class="text-sm text-gray-700 dark:text-gray-300"><span class="font-bold">{{ activity.actor?.name || 'System' }}</span> {{ activity.description }}</p>
                                        <p class="text-xs text-gray-400 dark:text-gray-400">{{ formatDateTime(activity.created_at) }}</p>
                                    </div>
                                </div>
                            </div>
                            </div>
                        </section>
                    </div>

                    <aside class="space-y-4">
                        <section v-if="!selectedCard.project_task" class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:bg-gray-800 dark:border-gray-700">
                            <h3 class="mb-3 text-xs font-black uppercase tracking-widest text-gray-500 dark:text-gray-300">Members</h3>
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
                                        <span class="flex h-7 w-7 items-center justify-center overflow-hidden rounded-full bg-gray-100 text-[10px] font-bold text-gray-700 dark:bg-gray-800 dark:text-gray-300">
                                            <img v-if="member.profile_photo" :src="'/serve-storage/' + member.profile_photo" class="h-full w-full object-cover" :alt="member.name">
                                            <span v-else>{{ initials(member.name) }}</span>
                                        </span>
                                        <span class="truncate text-sm font-semibold text-gray-700 dark:text-gray-300">{{ member.name }}</span>
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

                        <section class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:bg-gray-800 dark:border-gray-700">
                            <h3 class="mb-3 text-xs font-black uppercase tracking-widest text-gray-500 dark:text-gray-300">Labels</h3>
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
                            <form v-if="canEditBoard" class="mt-3 grid grid-cols-[minmax(0,1fr)_2.5rem_auto] gap-2 border-t border-gray-100 pt-3 dark:border-gray-700" @submit.prevent="createLabel(selectedCard)">
                                <input v-model="labelForm.name" type="text" required class="h-10 rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600" placeholder="New label">
                                <input v-model="labelForm.color" type="color" class="h-10 w-10 rounded-lg border border-gray-300 bg-white p-1 dark:bg-gray-800 dark:border-gray-600">
                                <button type="submit" class="rounded-lg bg-blue-600 px-3 text-xs font-bold text-white hover:bg-blue-700">Add</button>
                            </form>
                        </section>

                        <section class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:bg-gray-800 dark:border-gray-700">
                            <h3 class="mb-3 text-xs font-black uppercase tracking-widest text-gray-500 dark:text-gray-300">Cover</h3>
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
                                <button v-if="canEditBoard" type="button" class="h-8 rounded-md border border-gray-200 px-3 text-xs font-bold text-gray-600 dark:text-gray-300 dark:border-gray-700" @click="cardDraft.cover_type = ''; cardDraft.cover_value = ''; saveCardDetails({ closeModal: false })">
                                    None
                                </button>
                            </div>
                        </section>

                        <section class="space-y-2">
                            <button type="button" @click="toggleCardWatch(selectedCard)" class="flex w-full items-center justify-center gap-2 rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm font-bold text-gray-700 shadow-sm hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-300 dark:border-gray-700 dark:hover:bg-gray-700">
                                <EyeIcon class="h-4 w-4" />
                                {{ selectedCard.watchers?.some((user) => user.id === authUser.id) ? 'Watching' : 'Watch' }}
                            </button>
                            <button v-if="canEditBoard && !selectedCard.archived_at" type="button" @click="archiveCard(selectedCard)" class="flex w-full items-center justify-center gap-2 rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm font-bold text-gray-700 shadow-sm hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-300 dark:border-gray-700 dark:hover:bg-gray-700">
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
                    <h2 class="text-xl font-bold text-gray-900 dark:text-gray-100">Board Members</h2>
                    <button type="button" @click="showMemberModal = false" class="rounded-lg p-2 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:text-gray-400 dark:hover:bg-gray-700">
                        <XMarkIcon class="h-5 w-5" />
                    </button>
                </div>

                <div class="space-y-3">
                    <div v-for="member in boardMembers" :key="member.id" class="flex flex-col gap-3 rounded-lg border border-gray-200 p-3 sm:flex-row sm:items-center sm:justify-between dark:border-gray-700">
                        <div class="flex min-w-0 items-center gap-3">
                            <div class="flex h-9 w-9 items-center justify-center overflow-hidden rounded-full bg-gray-100 text-xs font-bold text-gray-700 dark:bg-gray-800 dark:text-gray-300">
                                <img v-if="member.profile_photo" :src="'/serve-storage/' + member.profile_photo" class="h-full w-full object-cover" :alt="member.name">
                                <span v-else>{{ initials(member.name) }}</span>
                            </div>
                            <div class="min-w-0">
                                <p class="truncate text-sm font-bold text-gray-900 dark:text-gray-100">{{ member.name }}</p>
                                <p class="truncate text-xs text-gray-500 dark:text-gray-300">{{ member.email }}</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <select :value="member.role" :disabled="!canManageMembers" class="h-9 rounded-lg border-gray-300 text-xs font-semibold dark:border-gray-600" @change="updateBoardMember(member, $event.target.value)">
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

                <form v-if="canManageMembers && availableMembers.length" class="mt-6 grid grid-cols-1 gap-3 border-t border-gray-100 pt-5 sm:grid-cols-[minmax(0,1fr)_9rem_auto] dark:border-gray-700" @submit.prevent="addBoardMember">
                    <MultiAutocomplete
                        v-model="memberForm.user_ids"
                        :options="availableMemberOptions"
                        label-key="label"
                        value-key="id"
                        placeholder="Select one or more members..."
                    />
                    <select v-model="memberForm.role" class="h-10 rounded-lg border-gray-300 text-sm dark:border-gray-600">
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
                    <h2 class="text-xl font-bold text-gray-900 dark:text-gray-100">Labels</h2>
                    <button type="button" @click="showLabelModal = false" class="rounded-lg p-2 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:text-gray-400 dark:hover:bg-gray-700">
                        <XMarkIcon class="h-5 w-5" />
                    </button>
                </div>
                <div class="space-y-2">
                    <div v-for="label in localBoard.labels" :key="label.id" class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                        <form v-if="editingLabelId === label.id" class="grid grid-cols-1 gap-2 sm:grid-cols-[minmax(0,1fr)_3rem_auto_auto]" @submit.prevent="updateLabel(label)">
                            <input v-model="editingLabelForm.name" type="text" required class="h-10 rounded-lg border-gray-300 text-sm dark:border-gray-600" placeholder="Label name">
                            <input v-model="editingLabelForm.color" type="color" class="h-10 w-12 rounded-lg border border-gray-300 bg-white p-1 dark:bg-gray-800 dark:border-gray-600">
                            <button type="submit" class="rounded-lg bg-blue-600 px-3 py-2 text-xs font-bold text-white hover:bg-blue-700">Save</button>
                            <button type="button" class="rounded-lg bg-gray-100 px-3 py-2 text-xs font-bold text-gray-700 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700" @click="cancelEditingLabel">Cancel</button>
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
                <form v-if="canEditBoard" class="mt-5 grid grid-cols-1 gap-3 border-t border-gray-100 pt-5 sm:grid-cols-[minmax(0,1fr)_3rem_auto] dark:border-gray-700" @submit.prevent="createLabel">
                    <input v-model="labelForm.name" type="text" required class="h-10 rounded-lg border-gray-300 text-sm dark:border-gray-600" placeholder="Label name">
                    <input v-model="labelForm.color" type="color" class="h-10 w-12 rounded-lg border border-gray-300 bg-white p-1 dark:bg-gray-800 dark:border-gray-600">
                    <button type="submit" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-bold text-white hover:bg-blue-700">Create</button>
                </form>
            </div>
        </Modal>

        <!-- Link to Project Modal -->
        <Modal :show="showLinkProjectModal" @close="showLinkProjectModal = false" maxWidth="md">
            <div class="p-6">
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="text-lg font-bold text-gray-900 dark:text-gray-100">Link Board to Project</h2>
                    <button type="button" @click="showLinkProjectModal = false" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                        <XMarkIcon class="h-5 w-5" />
                    </button>
                </div>

                <p class="mb-4 text-sm text-gray-600 dark:text-gray-400">
                    Select a project to link this board to. All task cards on this board will be imported as project tasks (only if the project has no tasks yet).
                </p>

                <!-- Search -->
                <div class="relative mb-3">
                    <input
                        v-model="linkProjectSearch"
                        type="text"
                        placeholder="Search projects…"
                        class="w-full rounded-lg border border-gray-300 py-2 pl-9 pr-3 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"
                    />
                    <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-gray-400">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M17 11A6 6 0 1 1 5 11a6 6 0 0 1 12 0z" /></svg>
                    </span>
                </div>

                <!-- Project list -->
                <div class="max-h-60 overflow-y-auto rounded-lg border border-gray-200 dark:border-gray-600">
                    <button
                        v-for="project in filteredProjectsForLink"
                        :key="project.id"
                        type="button"
                        @click="linkProjectId = project.id"
                        :class="[
                            'flex w-full items-center justify-between px-4 py-2.5 text-left text-sm transition-colors',
                            linkProjectId === project.id
                                ? 'bg-blue-50 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300'
                                : 'hover:bg-gray-50 dark:hover:bg-gray-700'
                        ]"
                    >
                        <span class="font-medium">{{ project.name }}</span>
                        <span class="ml-2 shrink-0 rounded-full border px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider">{{ project.status }}</span>
                    </button>
                    <p v-if="filteredProjectsForLink.length === 0" class="px-4 py-6 text-center text-sm text-gray-400">No projects found.</p>
                </div>

                <div class="mt-4 flex items-center justify-end gap-3">
                    <button type="button" @click="showLinkProjectModal = false" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700">
                        Cancel
                    </button>
                    <button
                        type="button"
                        :disabled="!linkProjectId || isLinkingProject"
                        @click="confirmLinkProject"
                        class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-40"
                    >
                        {{ isLinkingProject ? 'Linking…' : 'Link & Import Tasks' }}
                    </button>
                </div>
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
