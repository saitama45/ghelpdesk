<script setup>
import { Head, Link, useForm, usePage, router } from '@inertiajs/vue3';
import { ref, computed, reactive, watch, nextTick, onMounted, onUnmounted } from 'vue';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import CustomSelect from '@/Components/CustomSelect.vue';
import Autocomplete from '@/Components/Autocomplete.vue';
import HierarchySelector from '@/Components/HierarchySelector.vue';
import { useConfirm } from '@/Composables/useConfirm';
import { useErrorHandler } from '@/Composables/useErrorHandler';
import { useToast } from '@/Composables/useToast';
import { usePermission } from '@/Composables/usePermission';
import { useDateFormatter } from '@/Composables/useDateFormatter';
import { ArrowDownTrayIcon, ChatBubbleBottomCenterTextIcon, CheckIcon, ChevronDownIcon, ClockIcon, DocumentDuplicateIcon, XMarkIcon, LockClosedIcon, AdjustmentsHorizontalIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    ticket: Object,
    staff: Array,
    companies: Array,
    users: Array,
    stores: Array,
    vendors: Array,
    cannedMessages: Array,
    businessHours: Object,
});

const page = usePage();
const { confirm } = useConfirm();
const { put, destroy, post } = useErrorHandler();
const { showSuccess, showError } = useToast();
const { hasPermission } = usePermission();
const { formatDate, parseDate } = useDateFormatter();
const authUser = computed(() => page.props.auth.user);
const isManager = computed(() => !!authUser.value?.is_manager);

// Computed property for available companies based on user roles
const availableCompanies = computed(() => {
    const user = page.props.auth.user;
    if (!user || !user.roles) return [];

    // If Admin, show all companies
    if (user.roles.some(role => role.name === 'Admin')) {
        return props.companies;
    }

    // Get all company IDs from user's roles
    const allowedCompanyIds = user.roles.reduce((ids, role) => {
        if (role.companies) {
            role.companies.forEach(company => ids.add(company.id));
        }
        return ids;
    }, new Set());

    // Also include direct company assignment
    if (user.company_id) {
        allowedCompanyIds.add(user.company_id);
    }

    // Filter available companies
    return props.companies.filter(company => allowedCompanyIds.has(company.id));
});

const sameUserId = (left, right) => Number(left) === Number(right);

const isCurrentUserRequester = (ticket) => {
    return !!ticket?.reporter_id && !!authUser.value?.id && sameUserId(ticket.reporter_id, authUser.value.id);
};

const hasDifferentInternalRequester = (ticket) => {
    return !!ticket?.reporter_id && !!authUser.value?.id && !sameUserId(ticket.reporter_id, authUser.value.id);
};

// Canned Messages State
const showCannedMessages = ref(false);
const cannedMessageSearch = ref('');
const filteredCannedMessages = computed(() => {
    if (!cannedMessageSearch.value) return props.cannedMessages;
    const s = cannedMessageSearch.value.toLowerCase();
    return (props.cannedMessages || []).filter(m => 
        (m.title && m.title.toLowerCase().includes(s)) || 
        (m.content && m.content.toLowerCase().includes(s))
    );
});

watch(showCannedMessages, (newVal) => {
    if (!newVal) {
        cannedMessageSearch.value = '';
    }
});

const applyCannedMessage = (message) => {
    if (commentForm.comment_text) {
        commentForm.comment_text += '\n' + message.content;
    } else {
        commentForm.comment_text = message.content;
    }
    showCannedMessages.value = false;
};

// Internal Notes State
const showInternalNotesPopover = ref(false);
const noteFileInput = ref(null);
const noteForm = useForm({
    comment_text: '',
    status: '',
    is_internal: true,
    attachments: [],
});

const handleNoteFileSelect = (event) => {
    const files = Array.from(event.target.files);
    const maxSize = 1000 * 1024 * 1024; // 1GB for notes
    const validFiles = [];

    files.forEach(file => {
        if (isMedia(file.name)) {
            if (file.size <= maxSize) {
                validFiles.push(createFileObject(file));
            } else {
                showError(`File ${file.name} exceeds 1GB limit.`);
            }
        } else {
            showError(`File ${file.name} is not an image or video.`);
        }
    });

    noteForm.attachments = [...noteForm.attachments, ...validFiles];
    event.target.value = '';
};

const handleNotePaste = (event) => {
    const items = Array.from((event.clipboardData || event.originalEvent?.clipboardData)?.items || []);
    const maxSize = 1000 * 1024 * 1024; // 1GB for notes
    const validFiles = [];

    items.forEach((item) => {
        if (!item.type?.startsWith('image/')) return;

        const blob = item.getAsFile();
        if (!blob) return;

        if (blob.size > maxSize) {
            showError('Pasted image exceeds the 1GB limit.');
            return;
        }

        const extension = item.type.split('/')[1] || 'png';
        const file = new File([blob], `internal-note-image-${Date.now()}.${extension}`, { type: blob.type });
        validFiles.push(createFileObject(file));
    });

    if (!validFiles.length) return;

    event.preventDefault();
    noteForm.attachments = [...noteForm.attachments, ...validFiles];
};

const removeNoteAttachment = (index) => {
    const attachment = noteForm.attachments[index];
    if (attachment.preview) URL.revokeObjectURL(attachment.preview);
    noteForm.attachments.splice(index, 1);
};

const saveInternalNote = () => {
    if (!noteForm.comment_text.trim() && noteForm.attachments.length === 0) return;

    const attachmentsToUpload = noteForm.attachments.map(a => a.file);

    noteForm.transform((data) => ({
        ...data,
        attachments: attachmentsToUpload
    })).post(route('tickets.comments.store', props.ticket.id), {
        preserveScroll: true,
        onSuccess: () => {
            noteForm.attachments.forEach(a => {
                if (a.preview) URL.revokeObjectURL(a.preview);
            });
            noteForm.reset();
            if (noteFileInput.value) noteFileInput.value.value = '';
            showSuccess('Internal note added.');
        },
        onError: (errors) => {
            const errorMessage = Object.values(errors).flat().join(', ') || 'Failed to save note';
            showError(errorMessage);
        }
    });
};

const internalNotes = computed(() => {
    return (props.ticket.comments || [])
        .filter(c => c.is_internal)
        .sort((a, b) => new Date(b.created_at) - new Date(a.created_at));
});

const defaultChildStoreId = () => props.ticket.store_id || props.ticket.schedule_store?.store_id || props.ticket.scheduleStore?.store_id || null;

// Child Ticket State
const showChildModal = ref(false);
const childForm = useForm({
    user_id: isManager.value ? authUser.value?.id ?? null : null,
    store_id: defaultChildStoreId(),
    status: 'On-site',
    set_schedule: true,
    start_time: '',
    end_time: '',
    pickup_start: '',
    pickup_end: '',
    backlogs_start: '',
    backlogs_end: '',
    remarks: ''
});

const scheduleStatuses = [
    'On-site', 'Off-site', 'WFH', 'SL', 'VL', 'Restday', 'Offset', 'Holiday'
];

const childOptionalLocationStatuses = new Set(['SL', 'VL', 'Restday', 'Holiday']);
const isChildLocationRequired = computed(() => !childOptionalLocationStatuses.has(childForm.status));
const childStoreOptions = computed(() => {
    return isChildLocationRequired.value
        ? storesWithLabel.value
        : [{ id: null, display_name: 'No location' }, ...storesWithLabel.value];
});

const formatDateForInput = (date) => {
    const d = new Date(date);
    d.setMinutes(d.getMinutes() - d.getTimezoneOffset());
    return d.toISOString().slice(0, 16);
};

const formatTime = (timeStr) => {
    if (!timeStr) return '';
    try {
        const [hours, minutes] = timeStr.split(':');
        if (!hours || !minutes) return timeStr;
        let h = parseInt(hours, 10);
        const ampm = h >= 12 ? 'PM' : 'AM';
        h = h % 12 || 12;
        return `${h}:${minutes} ${ampm}`;
    } catch (e) {
        return timeStr;
    }
};

const buildScheduleContext = (activity = {}) => {
    const scheduleStore = activity.schedule_store || activity.scheduleStore || null;
    const schedule = activity.schedule || scheduleStore?.schedule || null;
    const descriptionRemarks = typeof activity.description === 'string'
        ? activity.description.match(/Remarks:\s*(.*)$/s)?.[1]?.trim() || ''
        : '';

    return {
        schedule,
        store: activity.store || scheduleStore?.store || null,
        remarks: scheduleStore?.remarks || schedule?.remarks || activity.remarks || descriptionRemarks,
        start_time: schedule?.start_time || scheduleStore?.start_time || activity.start_time || null,
        end_time: schedule?.end_time || scheduleStore?.end_time || activity.end_time || null,
        pickup_start: schedule?.pickup_start || scheduleStore?.pickup_start || activity.pickup_start || null,
        pickup_end: schedule?.pickup_end || scheduleStore?.pickup_end || activity.pickup_end || null,
        backlogs_start: schedule?.backlogs_start || scheduleStore?.backlogs_start || activity.backlogs_start || null,
        backlogs_end: schedule?.backlogs_end || scheduleStore?.backlogs_end || activity.backlogs_end || null,
    };
};

const openChildModal = () => {
    childForm.reset();
    
    // Set default times
    const start = new Date();
    start.setHours(7, 0, 0, 0);
    childForm.start_time = formatDateForInput(start);
    
    const end = new Date(start);
    end.setHours(17, 0, 0, 0);
    childForm.end_time = formatDateForInput(end);
    childForm.user_id = isManager.value ? authUser.value?.id ?? null : null;
    childForm.store_id = defaultChildStoreId();
    childForm.set_schedule = true;

    showChildModal.value = true
};

const submitChildTicket = () => {
    if (isChildLocationRequired.value && !childForm.store_id) {
        showError('Store is required before creating a child ticket.');
        return;
    }

    childForm.post(route('tickets.store-child', props.ticket.id), {
        onSuccess: () => {
            showChildModal.value = false;
        },
        onError: (errors) => {
            showChildModal.value = false;
            const errorMessage = Object.values(errors).flat().join(', ') || 'An error occurred';
            showError(errorMessage);
        }
    });
};

// Assign Schedule (to existing child ticket without schedule)
const hasSchedule = computed(() => {
    const ss = props.ticket.schedule_store || props.ticket.scheduleStore;
    return !!(ss && ss.schedule);
});
const canAssignSchedule = computed(() => !!props.ticket.parent_id && !hasSchedule.value && isManager.value);
const canEditSchedule = computed(() => !!props.ticket.parent_id && hasSchedule.value && isManager.value);

const assignScheduleMode = ref('assign'); // 'assign' | 'edit'
const showAssignScheduleModal = ref(false);
const assignScheduleForm = useForm({
    user_id: null,
    store_id: null,
    status: 'On-site',
    start_time: '',
    end_time: '',
    pickup_start: '',
    pickup_end: '',
    backlogs_start: '',
    backlogs_end: '',
    remarks: ''
});

const isAssignLocationRequired = computed(() => !childOptionalLocationStatuses.has(assignScheduleForm.status));
const assignStoreOptions = computed(() => {
    return isAssignLocationRequired.value
        ? storesWithLabel.value
        : [{ id: null, display_name: 'No location' }, ...storesWithLabel.value];
});

const openAssignScheduleModal = () => {
    assignScheduleMode.value = 'assign';
    assignScheduleForm.reset();
    const start = new Date();
    start.setHours(7, 0, 0, 0);
    assignScheduleForm.start_time = formatDateForInput(start);
    const end = new Date(start);
    end.setHours(17, 0, 0, 0);
    assignScheduleForm.end_time = formatDateForInput(end);
    assignScheduleForm.user_id = props.ticket.assignee_id || props.ticket.assignee?.id || null;
    assignScheduleForm.store_id = props.ticket.store_id || null;
    showAssignScheduleModal.value = true;
};

const openEditScheduleModal = () => {
    const ss = props.ticket.schedule_store || props.ticket.scheduleStore;
    const sch = ss?.schedule;
    if (!sch) return;
    assignScheduleMode.value = 'edit';
    assignScheduleForm.reset();
    assignScheduleForm.user_id = sch.user_id || props.ticket.assignee_id || null;
    assignScheduleForm.store_id = ss.store_id ?? props.ticket.store_id ?? null;
    assignScheduleForm.status = sch.status || 'On-site';
    assignScheduleForm.start_time = sch.start_time ? formatDateForInput(sch.start_time) : '';
    assignScheduleForm.end_time = sch.end_time ? formatDateForInput(sch.end_time) : '';
    assignScheduleForm.pickup_start = sch.pickup_start || '';
    assignScheduleForm.pickup_end = sch.pickup_end || '';
    assignScheduleForm.backlogs_start = sch.backlogs_start || '';
    assignScheduleForm.backlogs_end = sch.backlogs_end || '';
    assignScheduleForm.remarks = sch.remarks || ss.remarks || '';
    showAssignScheduleModal.value = true;
};

const submitAssignSchedule = () => {
    if (isAssignLocationRequired.value && !assignScheduleForm.store_id) {
        showError('Store is required before saving the schedule.');
        return;
    }
    const handlers = {
        onSuccess: () => { showAssignScheduleModal.value = false; },
        onError: (errors) => {
            showAssignScheduleModal.value = false;
            const errorMessage = Object.values(errors).flat().join(', ') || 'An error occurred';
            showError(errorMessage);
        }
    };
    if (assignScheduleMode.value === 'edit') {
        assignScheduleForm.put(route('tickets.update-schedule', props.ticket.id), handlers);
    } else {
        assignScheduleForm.post(route('tickets.assign-schedule', props.ticket.id), handlers);
    }
};

// CC Recipients (parent ticket only — children inherit)
const isChildTicket = computed(() => !!props.ticket.parent_id);
const inheritedCcs = computed(() => props.ticket.parent?.ccs || []);
const initialCcs = computed(() => (props.ticket.ccs || []).map(c => ({
    email: c.email,
    name: c.name || c.user?.name || '',
    user_id: c.user_id || c.user?.id || null,
})));

const ccForm = useForm({ ccs: [] });
const ccDraftEmail = ref('');
const ccDraftName = ref('');
const ccUserSearch = ref('');
const showCcUserDropdown = ref(false);

const syncCcFormFromProps = () => {
    ccForm.ccs = initialCcs.value.map(c => ({ ...c }));
};
syncCcFormFromProps();

const isValidEmail = (email) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test((email || '').trim());

const addCcEmail = () => {
    const email = ccDraftEmail.value.trim().toLowerCase();
    if (!email) return;
    if (!isValidEmail(email)) {
        showError('Please enter a valid email address.');
        return;
    }
    if (ccForm.ccs.some(c => c.email.toLowerCase() === email)) {
        showError('That email is already in the CC list.');
        return;
    }
    ccForm.ccs.push({ email, name: ccDraftName.value.trim() || null, user_id: null });
    ccDraftEmail.value = '';
    ccDraftName.value = '';
};

const addCcUser = (user) => {
    if (!user?.email) return;
    const email = user.email.toLowerCase();
    if (ccForm.ccs.some(c => c.email.toLowerCase() === email)) {
        showError(`${user.name} is already in the CC list.`);
        return;
    }
    ccForm.ccs.push({ email, name: user.name || null, user_id: user.id || null });
    ccUserSearch.value = '';
    showCcUserDropdown.value = false;
};

const removeCc = (index) => {
    ccForm.ccs.splice(index, 1);
};

const hideCcDropdownSoon = () => {
    setTimeout(() => { showCcUserDropdown.value = false; }, 150);
};

const ccUserOptions = computed(() => {
    const term = ccUserSearch.value.trim().toLowerCase();
    if (!term) return [];
    const existing = new Set(ccForm.ccs.map(c => c.email.toLowerCase()));
    return (props.staff || [])
        .filter(u => u.email && !existing.has(u.email.toLowerCase()))
        .filter(u => (u.name || '').toLowerCase().includes(term) || (u.email || '').toLowerCase().includes(term))
        .slice(0, 8);
});

const saveCcList = () => {
    ccForm.put(route('tickets.sync-ccs', props.ticket.id), {
        preserveScroll: true,
        onError: (errors) => {
            const errorMessage = Object.values(errors).flat().join(', ') || 'Failed to save CC list.';
            showError(errorMessage);
        },
    });
};

watch(() => props.ticket.ccs, () => {
    syncCcFormFromProps();
});

// Image Viewer State
const showImageViewer = ref(false);
const currentImage = ref(null);
const zoomLevel = ref(1);
const isDragging = ref(false);
const startPan = ref({ x: 0, y: 0 });
const panOffset = ref({ x: 0, y: 0 });
const failedImages = reactive(new Set());

const openImageViewer = (attachment) => {
    currentImage.value = attachment;
    zoomLevel.value = 1;
    panOffset.value = { x: 0, y: 0 };
    showImageViewer.value = true;
};

const closeImageViewer = () => {
    showImageViewer.value = false;
    currentImage.value = null;
};

const handleImageError = (attachmentId) => {
    failedImages.add(attachmentId);
};

const handleZoom = (delta) => {
    const newZoom = zoomLevel.value + delta;
    if (newZoom >= 0.1 && newZoom <= 5) {
        zoomLevel.value = Math.round(newZoom * 10) / 10;
    }
};

const handleWheel = (event) => {
    const delta = event.deltaY > 0 ? -0.1 : 0.1;
    handleZoom(delta);
};

const isImage = (filename) => {
    return /\.(jpg|jpeg|png|gif|webp|svg|bmp)$/i.test(filename);
};

const isVideo = (filename) => {
    return /\.(mp4|webm|ogg|mov)$/i.test(filename);
};

const isMedia = (filename) => {
    return isImage(filename) || isVideo(filename);
};

const createFileObject = (file) => {
    return {
        id: 'local-' + Date.now() + '-' + Math.random(),
        file: file,
        file_name: file.name,
        file_size_bytes: file.size,
        preview: isMedia(file.name) ? URL.createObjectURL(file) : null,
        is_local: true
    };
};

const getThumbnailUrl = (attachment) => {
    if (attachment.preview) return attachment.preview;
    if (!attachment.file_storage_path) return '';
    
    // Normalize backslashes to forward slashes (common on Windows local dev)
    const normalizedPath = attachment.file_storage_path.replace(/\\/g, '/');
    
    // Check if path already starts with /serve-storage (not expected but safe)
    if (normalizedPath.startsWith('/serve-storage')) {
        return normalizedPath;
    }

    // Legacy: if path starts with public/, remove it and prepend /serve-storage/
    if (normalizedPath.startsWith('public/')) {
        return '/serve-storage/' + normalizedPath.replace('public/', '');
    }

    // New standard: path is relative to public disk root (ticket-attachments/filename)
    // Map to /serve-storage/ticket-attachments/filename
    return '/serve-storage/' + normalizedPath;
};

const getAttachmentDownloadUrl = (attachment) => {
    if (!attachment?.id || String(attachment.id).startsWith('local-')) return '';

    return route('tickets.attachments.download', attachment.id);
};

const editForm = useForm({
    company_id: props.ticket.company_id || '',
    store_id: props.ticket.store_id || '',
    item_id: props.ticket.item_id || '',
    vendor_id: props.ticket.vendor_id || null,
    title: props.ticket.title,
    description: props.ticket.description,
    type: props.ticket.type,
    priority: props.ticket.priority ? String(props.ticket.priority).toLowerCase() : '',
    status: props.ticket.status,
    severity: props.ticket.severity,
    assignee_id: props.ticket.assignee_id || '',
    
    is_self_requester: isCurrentUserRequester(props.ticket),
    sender_name: props.ticket.sender_name || '',
    sender_email: props.ticket.sender_email || '',
    department: props.ticket.department || '',
});

const requesterDisplayName = computed(() => {
    return props.ticket.reporter?.name || props.ticket.sender_name || 'No requester set';
});

const requesterDisplayEmail = computed(() => {
    return props.ticket.reporter?.email || props.ticket.sender_email || '';
});

const showExternalRequesterFields = computed(() => {
    return !editForm.is_self_requester && !hasDifferentInternalRequester(props.ticket);
});

const getRequesterValues = (ticket) => ({
    sender_name: ticket?.sender_name || '',
    sender_email: ticket?.sender_email || '',
    department: ticket?.department || '',
});

const initialRequesterValues = getRequesterValues(props.ticket);
const requesterDraft = reactive({ ...initialRequesterValues });
const requesterBaseline = reactive({ ...initialRequesterValues });
const requesterDetailsProcessing = ref(false);

const setRequesterValues = (target, values) => {
    target.sender_name = values.sender_name;
    target.sender_email = values.sender_email;
    target.department = values.department;
};

const requesterDetailsDirty = computed(() => {
    return requesterDraft.sender_name !== requesterBaseline.sender_name
        || requesterDraft.sender_email !== requesterBaseline.sender_email
        || requesterDraft.department !== requesterBaseline.department;
});

const canEditRequesterDetails = computed(() => {
    return hasPermission('tickets.edit') && !editForm.is_self_requester;
});

const slaNow = ref(new Date());
let slaTimerInterval = null;

const items = ref([]);

const fetchItems = async () => {
    try {
        const response = await axios.get(route('tickets.data.items', undefined, false));
        items.value = response.data;
    } catch (error) {
        console.error('Error fetching items:', error);
    }
};

onMounted(async () => {
    slaTimerInterval = window.setInterval(() => {
        slaNow.value = new Date();
    }, 1000);

    await fetchItems();
    // Sync priority from the current item's latest value (in case it was changed in Items management)
    if (props.ticket.item_id) {
        const item = items.value.find(i => i.id == props.ticket.item_id);
        if (item) {
            editForm.priority = item.priority.toLowerCase();
            editForm.defaults(editForm.data()); // reset dirty state so it doesn't auto-save on load
        }
    }
    window.addEventListener('keydown', handleKeydown);
});

onUnmounted(() => {
    window.removeEventListener('keydown', handleKeydown);
    if (slaTimerInterval) {
        window.clearInterval(slaTimerInterval);
    }
});

const commentForm = useForm({
    comment_text: '',
    status: '',
    is_internal: false,
    action_taken: '',
    root_cause_analysis: '',
    attachments: [],
});

const showStatusDropdown = ref(false);
const showResolutionModal = ref(false);
const syncingTicketState = ref(false);

const selectedItem = computed(() => {
    return items.value.find(item => String(item.id) === String(editForm.item_id))
        || props.ticket.item
        || null;
});

const canResolveTicket = computed(() => availableStatuses.value.includes('resolved'));
const requiresRcaOnResolve = computed(() => !!selectedItem.value?.requires_rca_on_resolve);
const requiresResolutionDetails = (targetStatus) => ['resolved', 'closed'].includes(targetStatus);

const hasValidResolutionDetails = () => {
    if (!commentForm.action_taken.trim()) return false;
    if (requiresRcaOnResolve.value && !commentForm.root_cause_analysis.trim()) return false;
    return true;
};

const validateResolutionDetails = (contextLabel = 'continue') => {
    if (!commentForm.action_taken.trim()) {
        showError(`Action Taken is required before ${contextLabel}.`);
        return false;
    }

    if (requiresRcaOnResolve.value && !commentForm.root_cause_analysis.trim()) {
        showError(`Root Cause Analysis (RCA) is required for the selected item before ${contextLabel}.`);
        return false;
    }

    return true;
};

const validateResolutionBeforeSubmit = (newStatus) => {
    if (!requiresResolutionDetails(newStatus)) return true;

    return validateResolutionDetails(`setting the ticket to ${getStatusLabel(newStatus)}`);
};

const canSubmitCurrentComment = () => {
    if (commentForm.comment_text.trim() || commentForm.attachments.length > 0) {
        return true;
    }

    if (requiresResolutionDetails(commentForm.status)) {
        return validateResolutionBeforeSubmit(commentForm.status);
    }

    return false;
};

const submitWithStatus = (newStatus) => {
    if (requiresResolutionDetails(newStatus) && !hasValidResolutionDetails()) {
        commentForm.status = newStatus;
        showResolutionModal.value = true;
        showStatusDropdown.value = false;
        return;
    }

    if (!validateResolutionBeforeSubmit(newStatus)) {
        showStatusDropdown.value = false;
        return;
    }

    commentForm.status = newStatus;
    addComment();
    showStatusDropdown.value = false;
};

const submitResolution = () => {
    if (!validateResolutionBeforeSubmit(commentForm.status)) return;
    
    addComment();
    showResolutionModal.value = false;
};

const commentFileInput = ref(null);

// Inline Editing State
const isEditingTitle = ref(false);
const isEditingDescription = ref(false);
const titleInput = ref(null);
const descriptionInput = ref(null);

const priorities = ['low', 'medium', 'high', 'urgent'];
const statuses = ['open', 'for_schedule', 'in_progress', 'resolved', 'closed', 'waiting_service_provider', 'waiting_client_feedback'];
const slaWaitingStatuses = new Set(['waiting_service_provider', 'waiting_client_feedback']);
const slaStopStatuses = new Set(['resolved', 'closed']);

const normalizeTicketStatus = (status) => String(status || '').trim().toLowerCase();

const getValidTicketDate = (value) => {
    const date = parseDate(value);
    return date instanceof Date && !Number.isNaN(date.getTime()) && date.getTime() > 0 ? date : null;
};

const getWorkingMilliseconds = (start, end, config) => {
    if (!start || !end || start >= end) return 0;
    
    // Fallback if no config or invalid days
    if (!config || !config.days || !config.days.length) {
        return end.getTime() - start.getTime();
    }

    const [startH, startM] = config.start.split(':').map(Number);
    const [endH, endM] = config.end.split(':').map(Number);

    let totalMs = 0;
    let cursor = new Date(start.getTime());

    while (cursor < end) {
        const dayOfWeek = cursor.getDay() === 0 ? 7 : cursor.getDay(); // 1=Mon, 7=Sun
        
        if (config.days.includes(dayOfWeek)) {
            const dayStart = new Date(cursor.getTime());
            dayStart.setHours(startH, startM, 0, 0);

            const dayEnd = new Date(cursor.getTime());
            dayEnd.setHours(endH, endM, 0, 0);

            const actualStart = Math.max(cursor.getTime(), dayStart.getTime());
            const actualEnd = Math.min(end.getTime(), dayEnd.getTime());

            if (actualStart < actualEnd) {
                totalMs += actualEnd - actualStart;
            }
        }

        // Move to next day 00:00:00
        cursor.setDate(cursor.getDate() + 1);
        cursor.setHours(0, 0, 0, 0);
    }

    return totalMs;
};

const getStatusHistoryEvents = () => {
    return (props.ticket.histories || [])
        .filter(history => history.column_changed === 'status')
        .map(history => ({
            oldStatus: normalizeTicketStatus(history.old_value),
            newStatus: normalizeTicketStatus(history.new_value),
            changedAt: getValidTicketDate(history.changed_at),
        }))
        .filter(event => event.changedAt)
        .sort((left, right) => left.changedAt.getTime() - right.changedAt.getTime());
};

const formatDurationClock = (milliseconds) => {
    const totalSeconds = Math.max(0, Math.floor(milliseconds / 1000));
    const hours = Math.floor(totalSeconds / 3600);
    const minutes = Math.floor((totalSeconds % 3600) / 60);
    const seconds = totalSeconds % 60;

    return [hours, minutes, seconds]
        .map(value => String(value).padStart(2, '0'))
        .join(':');
};

const formatDurationCompact = (milliseconds) => {
    const totalMinutes = Math.max(0, Math.floor(milliseconds / 60000));
    const hours = Math.floor(totalMinutes / 60);
    const minutes = totalMinutes % 60;

    if (hours === 0) return `${totalMinutes} min`;
    if (minutes === 0) return `${hours} hr${hours === 1 ? '' : 's'}`;
    return `${hours} hr${hours === 1 ? '' : 's'} ${minutes} min`;
};

const slaRuntime = computed(() => {
    const startedAt = getValidTicketDate(props.ticket.created_at);

    if (!startedAt) {
        return null;
    }

    const now = slaNow.value;
    const events = getStatusHistoryEvents()
        .filter(event => event.changedAt.getTime() >= startedAt.getTime());

    let cursor = startedAt;
    let activeMilliseconds = 0;
    let pausedMilliseconds = 0;
    let currentStatus = events[0]?.oldStatus || normalizeTicketStatus(props.ticket.status);
    let stoppedAt = null;
    let stoppedStatus = null;

    for (const event of events) {
        const eventTime = event.changedAt.getTime();
        const cursorTime = cursor.getTime();

        if (eventTime < cursorTime) continue;

        const intervalMilliseconds = getWorkingMilliseconds(cursor, event.changedAt, props.businessHours);
        if (slaWaitingStatuses.has(currentStatus)) {
            pausedMilliseconds += intervalMilliseconds;
        } else {
            activeMilliseconds += intervalMilliseconds;
        }

        currentStatus = event.newStatus || currentStatus;
        cursor = event.changedAt;

        if (slaStopStatuses.has(currentStatus)) {
            stoppedAt = event.changedAt;
            stoppedStatus = currentStatus;
            break;
        }
    }

    const liveStatus = normalizeTicketStatus(editForm.status || props.ticket.status) || currentStatus;

    if (!stoppedAt) {
        let endAt = now;

        if (slaStopStatuses.has(liveStatus)) {
            endAt = getValidTicketDate(props.ticket.sla_metric?.resolved_at)
                || getValidTicketDate(props.ticket.updated_at)
                || now;
            stoppedAt = endAt;
            stoppedStatus = liveStatus;
        }

        if (endAt.getTime() < cursor.getTime()) {
            endAt = cursor;
        }

        const intervalMilliseconds = getWorkingMilliseconds(cursor, endAt, props.businessHours);
        const intervalStatus = currentStatus || liveStatus;

        if (slaWaitingStatuses.has(intervalStatus) || slaWaitingStatuses.has(liveStatus)) {
            pausedMilliseconds += intervalMilliseconds;
        } else {
            activeMilliseconds += intervalMilliseconds;
        }
    }

    const isPaused = !stoppedAt && (slaWaitingStatuses.has(currentStatus) || slaWaitingStatuses.has(liveStatus));
    const state = stoppedAt ? 'stopped' : (isPaused ? 'paused' : 'running');

    return {
        startedAt,
        stoppedAt,
        stoppedStatus,
        state,
        stateLabel: state === 'running' ? 'Running' : (state === 'paused' ? 'Paused' : 'Stopped'),
        stateClass: state === 'running'
            ? 'bg-emerald-50 text-emerald-700 border-emerald-200'
            : (state === 'paused'
                ? 'bg-amber-50 text-amber-700 border-amber-200'
                : 'bg-gray-100 text-gray-700 border-gray-200'),
        activeMilliseconds,
        pausedMilliseconds,
        clock: formatDurationClock(activeMilliseconds),
        pausedClock: formatDurationClock(pausedMilliseconds),
        totalMinutes: Math.floor(activeMilliseconds / 60000).toLocaleString(),
        totalHours: (activeMilliseconds / 3600000).toFixed(2),
        pausedLabel: formatDurationCompact(pausedMilliseconds),
    };
});

const calculateCountdown = (targetAt, pausedAt) => {
    const targetDate = getValidTicketDate(targetAt);
    if (!targetDate) return null;

    const now = slaNow.value;
    const isPaused = slaRuntime.value?.state === 'paused';
    
    let diffMs;
    let isBreached = false;

    if (now > targetDate) {
        isBreached = true;
        diffMs = getWorkingMilliseconds(targetDate, now, props.businessHours);
    } else {
        if (isPaused && pausedAt) {
            const pAt = getValidTicketDate(pausedAt);
            diffMs = getWorkingMilliseconds(pAt || now, targetDate, props.businessHours);
        } else {
            diffMs = getWorkingMilliseconds(now, targetDate, props.businessHours);
        }
    }

    const totalSeconds = Math.floor(diffMs / 1000);
    const hours = Math.floor(totalSeconds / 3600);
    const minutes = Math.floor((totalSeconds % 3600) / 60);
    const seconds = totalSeconds % 60;
    
    const clock = [hours, minutes, seconds]
        .map(value => String(value).padStart(2, '0'))
        .join(':');

    return {
        clock,
        isBreached,
        label: isBreached ? 'Breached' : 'Remaining',
        class: isBreached ? 'text-red-600' : 'text-emerald-600',
    };
};

const responseSLA = computed(() => {
    const metric = props.ticket.sla_metric;
    if (!metric || !metric.response_target_at || metric.first_response_at) return null;
    return calculateCountdown(metric.response_target_at, metric.paused_at);
});

const resolutionSLA = computed(() => {
    const metric = props.ticket.sla_metric;
    if (!metric || !metric.resolution_target_at || metric.resolved_at) return null;
    return calculateCountdown(metric.resolution_target_at, metric.paused_at);
});

// Filter available statuses based on permissions
const availableStatuses = computed(() => {
    return statuses.filter(status => {
        if (status === 'closed' || status === 'resolved') {
            // Allow 'closed' or 'resolved' if user has permission OR if ticket is already in that status
            return hasPermission('tickets.close') || props.ticket.status === status;
        }
        return true;
    });
});

// Media Navigation
const allMedia = computed(() => {
    const media = [];
    
    // Add description attachments
    const descAttachments = (props.ticket.attachments || []).filter(a => !a.comment_id && isMedia(a.file_name));
    media.push(...descAttachments);

    // Add comment attachments
    if (props.ticket.comments) {
        props.ticket.comments.forEach(comment => {
            if (comment.attachments) {
                media.push(...comment.attachments.filter(a => isMedia(a.file_name)));
            }
        });
    }

    return media;
});

const currentIndex = computed(() => {
    if (!currentImage.value) return -1;
    return allMedia.value.findIndex(m => m.id === currentImage.value.id);
});

const navigateMedia = (direction) => {
    if (currentIndex.value === -1 || allMedia.value.length <= 1) return;
    
    let newIndex = currentIndex.value + direction;
    
    // Loop
    if (newIndex < 0) newIndex = allMedia.value.length - 1;
    if (newIndex >= allMedia.value.length) newIndex = 0;
    
    currentImage.value = allMedia.value[newIndex];
    zoomLevel.value = 1;
    panOffset.value = { x: 0, y: 0 };
};

const handleKeydown = (e) => {
    if (!showImageViewer.value) return;
    
    if (e.key === 'ArrowLeft') navigateMedia(-1);
    if (e.key === 'ArrowRight') navigateMedia(1);
    if (e.key === 'Escape') closeImageViewer();
};

const showAuditTrails = ref(false);

const filteredActivities = computed(() => {
    if (showAuditTrails.value) return activities.value;
    return activities.value.filter(a => a.activity_type !== 'history');
});

const activities = computed(() => {
    const comments = (props.ticket.comments || [])
        .filter(c => !c.is_internal)
        .map(c => ({
            ...c,
            activity_type: 'comment',
            date: parseDate(c.created_at)
        }));

    const histories = (props.ticket.histories || []).map(h => ({
        ...h,
        activity_type: 'history',
        date: parseDate(h.changed_at)
    }));

    // ... (Description and children logic)
    const parentSStore = props.ticket.schedule_store || props.ticket.scheduleStore;
    const description = {
        id: 'description-' + props.ticket.id,
        activity_type: 'description',
        date: parseDate(props.ticket.created_at),
        user: props.ticket.reporter,
        sender_name: props.ticket.sender_name,
        sender_email: props.ticket.sender_email,
        text: props.ticket.description,
        // Include schedule for child context
        schedule: parentSStore?.schedule,
        store: parentSStore?.store,
        assignee: props.ticket.assignee,
        // Attachments that are not linked to any comment (created with ticket)
        attachments: (props.ticket.attachments || []).filter(a => !a.comment_id)
    };

    const children = (props.ticket.children || []).map(child => {
        const scheduleContext = buildScheduleContext(child);
        return {
            ...child,
            activity_type: 'child_ticket',
            date: parseDate(child.created_at),
            user: child.reporter,
            schedule: scheduleContext.schedule,
            store: scheduleContext.store,
            scheduleContext,
        };
    });

    // Sort ascending (oldest first)
    return [...comments, ...histories, ...children, description].sort((a, b) => {
        return a.date.getTime() - b.date.getTime();
    });
});

const formatColumnName = (column) => {
    if (column === 'company_id') return 'Company';
    if (column === 'store_id') return 'Store';
    if (column === 'assignee_id') return 'Assignee';
    if (column === 'reporter_id') return 'Reporter';
    return column.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
};

const debounce = (fn, delay) => {
    let timeoutId;
    return (...args) => {
        clearTimeout(timeoutId);
        timeoutId = setTimeout(() => fn(...args), delay);
    };
};

const departments = computed(() =>
    [...new Set(props.users.map(u => u.department).filter(Boolean))].sort()
);

const departmentNodes = computed(() =>
    departments.value.map(dept => ({ id: dept, name: dept }))
);

const isClassificationComplete = computed(() => {
    return !!editForm.company_id && !!editForm.store_id && !!editForm.item_id && !!editForm.department;
});

const storesWithLabel = computed(() =>
    props.stores.map(s => ({ ...s, display_name: `${s.code} - ${s.name}` }))
);

const STORE_LIST_DISPLAY_LIMIT = 5;
const expandedStoreLists = ref({});

const parseStoreListLine = (line) => {
    const match = String(line || '').match(/^(.*?\bStores:\s*)(.*)$/i);
    if (!match) return null;

    const prefix = match[1];
    const rawStores = match[2].trim();

    if (!rawStores) return null;

    if (/^all stores$/i.test(rawStores)) {
        return {
            prefix,
            isAllStores: true,
            stores: ['All Stores'],
        };
    }

    const stores = rawStores
        .split(',')
        .map(store => store.trim())
        .filter(Boolean);

    if (!stores.length) return null;

    return {
        prefix,
        isAllStores: false,
        stores,
    };
};

const getDescriptionLines = (text) => {
    return String(text || '').split(/\r?\n/).map((line, index) => ({
        index,
        raw: line,
        storeList: parseStoreListLine(line),
    }));
};

const getStoreListKey = (activity, lineIndex) => `${activity.activity_type}-${activity.id}-${lineIndex}`;

const isStoreListExpanded = (key) => !!expandedStoreLists.value[key];

const toggleStoreList = (key) => {
    expandedStoreLists.value = {
        ...expandedStoreLists.value,
        [key]: !expandedStoreLists.value[key],
    };
};

const visibleStoresForLine = (stores, key) => {
    return isStoreListExpanded(key) ? stores : stores.slice(0, STORE_LIST_DISPLAY_LIMIT);
};

const hiddenStoreCountForLine = (stores, key) => {
    if (isStoreListExpanded(key)) return 0;
    return Math.max(stores.length - STORE_LIST_DISPLAY_LIMIT, 0);
};

const buildTicketPayload = (source = editForm.data()) => {
    const payload = { ...source };

    if (!editForm.is_self_requester && hasDifferentInternalRequester(props.ticket)) {
        delete payload.is_self_requester;
        delete payload.sender_name;
        delete payload.sender_email;
    }

    return payload;
};

const updateTicket = (options = {}) => {
    if (!hasPermission('tickets.edit') && !hasPermission('tickets.assign') && !hasPermission('tickets.close')) {
        showError('You do not have permission to update tickets.');
        return;
    }
    
    // Check if form is dirty to avoid unnecessary requests
    if (!editForm.isDirty) {
        if (options.onSuccess) options.onSuccess();
        return;
    }

    if (editForm.processing) {
        // If already processing, try again after a short delay
        setTimeout(() => updateTicket(options), 500);
        return;
    }

    // Capture scroll position before request
    const savedScrollTop = (() => {
        const el = document.querySelector('[scroll-region]');
        return el ? el.scrollTop : window.scrollY;
    })();

    const restoreScroll = () => {
        const el = document.querySelector('[scroll-region]');
        if (el) el.scrollTop = savedScrollTop;
        else window.scrollTo(0, savedScrollTop);
    };

    const payload = buildTicketPayload();

    put(route('tickets.update', props.ticket.id), payload, {
        preserveScroll: true,
        preserveState: true,
        only: ['ticket', 'flash'], // Only refresh ticket data, ignore static lists
        onSuccess: () => {
            editForm.defaults(editForm.data()); // Update defaults to new state
            // Restore immediately after Vue re-renders, then again after any late Inertia scroll resets
            nextTick(() => {
                restoreScroll();
                setTimeout(restoreScroll, 50);
            });
            if (options.onSuccess) options.onSuccess();
        },
        onError: (errors) => {
            const errorMessage = Object.values(errors).flat().join(', ') || 'An error occurred';
            showError(errorMessage);
            if (options.onError) options.onError(errors);
        }
    });
};

const debouncedUpdate = debounce(() => {
    updateTicket();
}, 1000);

// Watch for ticket changes to sync form state (important after status changes from comments)
watch(() => props.ticket, (newTicket) => {
    const hadRequesterDraftChanges = requesterDetailsDirty.value;
    const requesterValues = getRequesterValues(newTicket);

    syncingTicketState.value = true;
    editForm.status = newTicket.status;
    editForm.priority = newTicket.priority ? String(newTicket.priority).toLowerCase() : '';
    editForm.severity = newTicket.severity;
    editForm.type = newTicket.type;
    editForm.assignee_id = newTicket.assignee_id || '';
    editForm.item_id = newTicket.item_id || '';
    editForm.company_id = newTicket.company_id || '';
    editForm.store_id = newTicket.store_id || '';
    editForm.is_self_requester = isCurrentUserRequester(newTicket);
    editForm.sender_name = newTicket.sender_name || '';
    editForm.sender_email = newTicket.sender_email || '';
    editForm.department = newTicket.department || '';
    setRequesterValues(requesterBaseline, requesterValues);
    if (!hadRequesterDraftChanges) {
        setRequesterValues(requesterDraft, requesterValues);
    }
    editForm.defaults(editForm.data()); // Reset dirty state
    nextTick(() => {
        syncingTicketState.value = false;
    });
}, { deep: true });

// Watchers for select/toggle fields (save immediately on change)
watch(() => [
    editForm.company_id,
    editForm.store_id,
    editForm.priority,
    editForm.severity,
    editForm.type,
    editForm.assignee_id,
], () => {
    updateTicket({ preserveScroll: true });
});

watch(() => editForm.status, (newStatus, oldStatus) => {
    if (syncingTicketState.value || oldStatus === undefined || newStatus === oldStatus) return;

    if (requiresResolutionDetails(newStatus)) {
        if (!hasValidResolutionDetails()) {
            syncingTicketState.value = true;
            editForm.status = oldStatus;
            nextTick(() => {
                syncingTicketState.value = false;
            });
            
            commentForm.status = newStatus;
            showResolutionModal.value = true;
            return;
        }

        if (!validateResolutionBeforeSubmit(newStatus)) {
            syncingTicketState.value = true;
            editForm.status = oldStatus;
            nextTick(() => {
                syncingTicketState.value = false;
            });
            return;
        }

        syncingTicketState.value = true;
        editForm.status = oldStatus;
        nextTick(() => {
            syncingTicketState.value = false;
        });

        commentForm.status = newStatus;
        addComment();
        return;
    }

    updateTicket({ preserveScroll: true });
});

const handleSelfRequesterToggle = async (event) => {
    const isChecked = event.target.checked;
    
    const confirmed = await confirm({
        title: 'Confirm Requester Change',
        message: isChecked 
            ? 'Are you sure you want to set yourself as the requester? This will overwrite existing sender details.'
            : 'Are you sure you want to remove yourself as the requester? You will need to manually enter the sender\'s details.'
    });

    if (confirmed) {
        syncingTicketState.value = true;
        editForm.is_self_requester = isChecked;
        editForm.department = isChecked ? (page.props.auth.user?.department || '') : '';
        if (isChecked) {
            requesterDraft.sender_name = '';
            requesterDraft.sender_email = '';
        }
        requesterDraft.department = editForm.department;
        nextTick(() => {
            syncingTicketState.value = false;
            updateTicket({ preserveScroll: true });
        });
    } else {
        event.target.checked = !isChecked;
    }
};

const updateRequesterDetails = () => {
    if (!hasPermission('tickets.edit')) {
        showError('You do not have permission to update tickets.');
        return;
    }

    if (!requesterDetailsDirty.value || requesterDetailsProcessing.value) return;

    requesterDetailsProcessing.value = true;

    const payload = buildTicketPayload({
        ...editForm.data(),
        sender_name: requesterDraft.sender_name,
        sender_email: requesterDraft.sender_email,
        department: requesterDraft.department,
    });

    put(route('tickets.update', props.ticket.id), payload, {
        preserveScroll: true,
        preserveState: true,
        only: ['ticket', 'flash'],
        onSuccess: () => {
            editForm.sender_name = requesterDraft.sender_name;
            editForm.sender_email = requesterDraft.sender_email;
            editForm.department = requesterDraft.department;
            setRequesterValues(requesterBaseline, {
                sender_name: requesterDraft.sender_name,
                sender_email: requesterDraft.sender_email,
                department: requesterDraft.department,
            });
            editForm.defaults(editForm.data());
        },
        onError: (errors) => {
            const errorMessage = Object.values(errors).flat().join(', ') || 'An error occurred';
            showError(errorMessage);
        },
        onFinish: () => {
            requesterDetailsProcessing.value = false;
        }
    });
};

// Classification Watcher
watch(() => editForm.item_id, (newVal, oldVal) => {
    if (newVal && newVal != oldVal && oldVal !== undefined) {
        const item = items.value.find(i => i.id == newVal);
        if (item) {
            editForm.priority = item.priority.toLowerCase();
        }
        updateTicket({ preserveScroll: true });
    }
});

watch(() => editForm.vendor_id, (newVal, oldVal) => {
    if (newVal !== oldVal && oldVal !== undefined) {
        updateTicket({ preserveScroll: true });
    }
});

const startEditingTitle = () => {
    if (!hasPermission('tickets.edit')) return;
    isEditingTitle.value = true;
    nextTick(() => {
        if (titleInput.value) titleInput.value.focus();
    });
};

const saveTitle = () => {
    updateTicket({
        onSuccess: () => {
            isEditingTitle.value = false;
        }
    });
};

const cancelTitleEdit = () => {
    editForm.title = props.ticket.title;
    isEditingTitle.value = false;
};

const startEditingDescription = () => {
    if (!hasPermission('tickets.edit')) return;
    isEditingDescription.value = true;
    nextTick(() => {
        if (descriptionInput.value) descriptionInput.value.focus();
    });
};

const saveDescription = () => {
    updateTicket({
        onSuccess: () => {
            isEditingDescription.value = false;
        }
    });
};

const cancelDescriptionEdit = () => {
    editForm.description = props.ticket.description;
    isEditingDescription.value = false;
};

const addComment = () => {
    if (!canSubmitCurrentComment()) return;
    
    const attachmentsToUpload = commentForm.attachments.map(a => a.file);
    
    post(route('tickets.comments.store', props.ticket.id), {
        comment_text: commentForm.comment_text,
        status: commentForm.status,
        is_internal: commentForm.is_internal,
        action_taken: commentForm.action_taken,
        root_cause_analysis: commentForm.root_cause_analysis,
        attachments: attachmentsToUpload
    }, {
        onSuccess: () => {
            commentForm.attachments.forEach(a => {
                if (a.preview) URL.revokeObjectURL(a.preview);
            });
            commentForm.reset();
            if (commentFileInput.value) commentFileInput.value.value = '';
        },
        onError: (errors) => {
            const errorMessage = Object.values(errors).flat().join(', ') || 'Failed to add comment';
            showError(errorMessage);
        }
    });
};

const handleCommentFileSelect = (event) => {
    const files = Array.from(event.target.files);
    const maxSize = 1000 * 1024 * 1024; // 1GB
    const validFiles = [];
    const oversizedFiles = [];

    files.forEach(file => {
        if (file.size <= maxSize) {
            validFiles.push(createFileObject(file));
        } else {
            oversizedFiles.push(file.name);
        }
    });

    if (oversizedFiles.length > 0) {
        showError(`The following files exceed the 1GB limit and were not added: ${oversizedFiles.join(', ')}`);
    }

    commentForm.attachments = [...commentForm.attachments, ...validFiles];
    event.target.value = ''; 
};

const handlePaste = (event) => {
    const items = (event.clipboardData || event.originalEvent.clipboardData).items;
    const maxSize = 1000 * 1024 * 1024; // 1GB

    for (const item of items) {
        if (item.type.indexOf('image') !== -1 || item.type.indexOf('video') !== -1) {
            const blob = item.getAsFile();
            if (blob) {
                if (blob.size > maxSize) {
                    showError(`Pasted media exceeds the 1GB limit.`);
                    continue;
                }
                const ext = item.type.split('/')[1] || 'png';
                const file = new File([blob], `screenshot-${Date.now()}.${ext}`, { type: blob.type });
                commentForm.attachments.push(createFileObject(file));
            }
        }
    }
};

const removeCommentAttachment = (index) => {
    const attachment = commentForm.attachments[index];
    if (attachment.preview) URL.revokeObjectURL(attachment.preview);
    commentForm.attachments.splice(index, 1);
};

const duplicateTicket = async () => {
    const confirmed = await confirm({
        title: 'Duplicate Ticket',
        message: `Duplicate ticket ${props.ticket.ticket_key}? All fields will be copied into a new open ticket.`
    });

    if (confirmed) {
        post(route('tickets.duplicate', props.ticket.id), {}, {
            onError: (errors) => {
                const errorMessage = Object.values(errors).flat().join(', ') || 'Failed to duplicate ticket';
                showError(errorMessage);
            }
        });
    }
};

const deleteTicket = async () => {
    if (!hasPermission('tickets.delete')) return;

    const confirmed = await confirm({
        title: 'Archive Ticket',
        message: `Archive Ticket ${props.ticket.ticket_key}? It will move to Ticket Archive and can be restored later.`,
        confirmLabel: 'Archive',
        cancelLabel: 'Cancel'
    });
    
    if (confirmed) {
        destroy(route('tickets.destroy', props.ticket.id), {
            onSuccess: () => {
                router.visit(route('tickets.index'));
            },
            onError: (errors) => {
                const errorMessage = Object.values(errors).flat().join(', ') || 'Cannot archive ticket';
                showError(errorMessage);
            }
        });
    }
};

const priorityMap = {
    'urgent': 'P1',
    'high': 'P2',
    'medium': 'P3',
    'low': 'P4'
};

const getPriorityLabel = (priority) => {
    const p = String(priority || '').toLowerCase();
    return priorityMap[p] ? `${priorityMap[p]} ${p}` : p;
};

const getPriorityColor = (priority) => {
    switch (String(priority || '').toLowerCase()) {
        case 'urgent': return 'text-red-900 bg-red-200';
        case 'high': return 'text-red-800 bg-red-100';
        case 'medium': return 'text-yellow-900 bg-yellow-200';
        case 'low': return 'text-green-900 bg-green-200';
        default: return 'text-gray-800 bg-gray-100';
    }
};

const getStatusColor = (status) => {
    switch (status) {
        case 'open': return 'text-blue-800 bg-blue-100';
        case 'for_schedule': return 'text-teal-800 bg-teal-100';
        case 'in_progress': return 'text-purple-800 bg-purple-100';
        case 'resolved': return 'text-green-800 bg-green-100';
        case 'closed': return 'text-gray-600 bg-gray-200';
        case 'waiting_service_provider': return 'text-orange-800 bg-orange-100';
        case 'waiting_client_feedback': return 'text-blue-800 bg-blue-100';
        default: return 'text-gray-800 bg-gray-100';
    }
};

const getStatusLabel = (status) => {
    switch (status) {
        case 'for_schedule': return 'For Schedule';
        case 'waiting_service_provider': return 'Waiting for service provider';
        case 'waiting_client_feedback': return 'Waiting for Client\'s Feedback';
        default: return status.replace('_', ' ');
    }
};

const formatFileSize = (bytes) => {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
};

const linkify = (text) => {
    if (!text) return '';
    let escaped = text
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    
    // Support markdown style links: [filename](url)
    const markdownRegex = /\[([^\]]+)\]\((https?:\/\/[^\s\)]+)\)/g;
    escaped = escaped.replace(markdownRegex, (match, label, url) => {
        return `<a href="${url}" target="_blank" class="text-blue-600 font-bold hover:underline break-all">${label}</a>`;
    });

    const urlRegex = /(?<!href=")(https?:\/\/[^\s<]+)/g;
    return escaped.replace(urlRegex, (url) => {
        return `<a href="${url}" target="_blank" class="text-blue-600 hover:underline break-all">${url}</a>`;
    });
};
</script>

<template>
    <Head :title="`Edit Ticket ${ticket.ticket_key}`" />

    <AppLayout content-class="w-full max-w-none px-2 sm:px-4 lg:px-6">
        <template #header>
            <div class="flex items-center space-x-4">
                <Link :href="route('tickets.index')" class="text-blue-600 hover:text-blue-800 flex-shrink-0 transition-colors">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                    </svg>
                </Link>
                <div class="flex flex-col">
                    <h1 class="text-lg font-bold tracking-tight">
                        <span class="text-blue-600">{{ ticket.ticket_key }}</span> <span class="text-gray-900 truncate max-w-[200px] sm:max-w-none">{{ ticket.title }}</span>
                    </h1>
                </div>
            </div>
        </template>

        <div>
            <div class="flex flex-col lg:grid lg:grid-cols-3 gap-6">
                <!-- Right Column (Metadata) moved to TOP on mobile -->
                <div class="lg:col-span-1 space-y-6 order-1 lg:order-2">
                    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4 sm:p-6 space-y-6 lg:sticky lg:top-6">
                        <div class="space-y-4 sm:space-y-6">
                            <div v-if="slaRuntime" class="rounded-lg border border-gray-200 bg-gray-50 p-4 space-y-4">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="flex items-center gap-3 min-w-0">
                                        <div class="h-9 w-9 rounded-lg bg-white border border-gray-200 flex items-center justify-center text-blue-600 shrink-0">
                                            <ClockIcon class="w-5 h-5" />
                                        </div>
                                        <div class="min-w-0">
                                            <h3 class="text-xs font-bold text-gray-400 uppercase tracking-widest">SLA Timer</h3>
                                            <p class="text-[11px] font-semibold text-gray-600 truncate">Requester created date</p>
                                        </div>
                                    </div>
                                    <span class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-[10px] font-black uppercase tracking-wider shrink-0" :class="slaRuntime.stateClass">
                                        {{ slaRuntime.stateLabel }}
                                    </span>
                                </div>

                                <div>
                                    <div class="font-mono text-3xl sm:text-4xl font-black text-gray-900 tracking-normal tabular-nums leading-none">
                                        {{ slaRuntime.clock }}
                                    </div>
                                    <div class="mt-2 grid grid-cols-2 gap-2">
                                        <div class="rounded-md border border-gray-200 bg-white px-3 py-2">
                                            <div class="text-[9px] font-black text-gray-400 uppercase tracking-widest">Total Minutes</div>
                                            <div class="text-sm font-black text-gray-900 tabular-nums">{{ slaRuntime.totalMinutes }}</div>
                                        </div>
                                        <div class="rounded-md border border-gray-200 bg-white px-3 py-2">
                                            <div class="text-[9px] font-black text-gray-400 uppercase tracking-widest">Total Hours</div>
                                            <div class="text-sm font-black text-gray-900 tabular-nums">{{ slaRuntime.totalHours }}</div>
                                        </div>
                                    </div>
                                </div>

                                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-1 gap-2 text-[11px]">
                                    <div class="flex items-center justify-between gap-3 rounded-md border border-gray-200 bg-white px-3 py-2">
                                        <span class="font-black text-gray-400 uppercase tracking-widest">Started</span>
                                        <span class="font-bold text-gray-800 text-right">{{ formatDate(slaRuntime.startedAt) }}</span>
                                    </div>
                                    <div v-if="slaRuntime.stoppedAt" class="flex items-center justify-between gap-3 rounded-md border border-gray-200 bg-white px-3 py-2">
                                        <span class="font-black text-gray-400 uppercase tracking-widest">{{ getStatusLabel(slaRuntime.stoppedStatus) }}</span>
                                        <span class="font-bold text-gray-800 text-right">{{ formatDate(slaRuntime.stoppedAt) }}</span>
                                    </div>
                                    <div v-else class="flex items-center justify-between gap-3 rounded-md border border-gray-200 bg-white px-3 py-2">
                                        <span class="font-black text-gray-400 uppercase tracking-widest">Status</span>
                                        <span class="font-bold text-gray-800 text-right">{{ getStatusLabel(editForm.status || ticket.status) }}</span>
                                    </div>
                                    <div class="flex items-center justify-between gap-3 rounded-md border border-gray-200 bg-white px-3 py-2">
                                        <span class="font-black text-gray-400 uppercase tracking-widest">Waiting Paused</span>
                                        <span class="font-mono font-black text-gray-900 text-right tabular-nums">{{ slaRuntime.pausedClock }}</span>
                                    </div>
                                </div>
                            </div>

                            <!-- SLA Widget -->
                            <div v-if="ticket.sla_metric" class="pt-6 border-t space-y-4">
                                <h3 class="text-xs font-bold text-gray-400 uppercase tracking-widest">Ticket SLA</h3>
                                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-1 gap-3">
                                    <!-- Response SLA -->
                                    <div class="p-3 rounded-lg border" :class="ticket.sla_metric.is_response_breached ? 'bg-red-50 border-red-100' : (ticket.sla_metric.first_response_at ? 'bg-green-50 border-green-100' : 'bg-gray-50 border-gray-100')">
                                        <div class="flex justify-between items-center mb-1">
                                            <span class="text-[9px] font-black text-gray-500 uppercase">Response</span>
                                            <span v-if="ticket.sla_metric.is_response_breached" class="text-[9px] font-black text-red-600 uppercase">BREACHED</span>
                                            <span v-else-if="ticket.sla_metric.first_response_at" class="text-[9px] font-black text-green-600 uppercase">MET</span>
                                            <span v-else class="text-[9px] font-black text-blue-600 uppercase">ACTIVE</span>
                                        </div>
                                        <div class="text-[11px] font-bold text-gray-900 truncate">
                                            {{ ticket.sla_metric.first_response_at ? formatDate(ticket.sla_metric.first_response_at) : (ticket.sla_metric.response_target_at ? formatDate(ticket.sla_metric.response_target_at) : 'No target') }}
                                        </div>
                                        <div v-if="responseSLA" class="mt-2 pt-2 border-t border-gray-200/50 flex justify-between items-baseline">
                                            <span class="text-[8px] font-black text-gray-400 uppercase tracking-tighter">{{ responseSLA.label }}</span>
                                            <span class="font-mono text-sm font-black tabular-nums" :class="responseSLA.class">
                                                {{ responseSLA.isBreached ? '-' : '' }}{{ responseSLA.clock }}
                                            </span>
                                        </div>
                                    </div>

                                    <!-- Resolution SLA -->
                                    <div class="p-3 rounded-lg border" :class="ticket.sla_metric.is_resolution_breached ? 'bg-red-50 border-red-100' : (ticket.sla_metric.resolved_at ? 'bg-green-50 border-green-100' : 'bg-gray-50 border-gray-100')">
                                        <div class="flex justify-between items-center mb-1">
                                            <span class="text-[9px] font-black text-gray-500 uppercase">Resolution</span>
                                            <span v-if="ticket.sla_metric.is_resolution_breached" class="text-[9px] font-black text-red-600 uppercase">BREACHED</span>
                                            <span v-else-if="ticket.sla_metric.resolved_at" class="text-[9px] font-black text-green-600 uppercase">MET</span>
                                            <span v-else class="text-[9px] font-black text-blue-600 uppercase">ACTIVE</span>
                                        </div>
                                        <div class="text-[11px] font-bold text-gray-900 truncate">
                                            {{ ticket.sla_metric.resolved_at ? formatDate(ticket.sla_metric.resolved_at) : (ticket.sla_metric.resolution_target_at ? formatDate(ticket.sla_metric.resolution_target_at) : 'No target') }}
                                        </div>
                                        <div v-if="resolutionSLA" class="mt-2 pt-2 border-t border-gray-200/50 flex justify-between items-baseline">
                                            <span class="text-[8px] font-black text-gray-400 uppercase tracking-tighter">{{ resolutionSLA.label }}</span>
                                            <span class="font-mono text-sm font-black tabular-nums" :class="resolutionSLA.class">
                                                {{ resolutionSLA.isBreached ? '-' : '' }}{{ resolutionSLA.clock }}
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                             
                            <!-- Requester Configuration -->
                            <div class="relative bg-gray-50 p-4 rounded-xl border border-gray-100 space-y-4">
                                <button
                                    v-if="hasPermission('tickets.edit') && (ticket.status === 'resolved' || ticket.status === 'closed')"
                                    type="button"
                                    @click="duplicateTicket"
                                    title="Duplicate Ticket"
                                    class="absolute top-2 right-2 p-1.5 text-white bg-indigo-500 hover:bg-indigo-600 rounded-md shadow-sm transition-colors"
                                >
                                    <DocumentDuplicateIcon class="w-4 h-4" />
                                </button>
                                <label class="flex items-center space-x-3 cursor-pointer">
                                    <div class="relative">
                                        <input type="checkbox" :checked="editForm.is_self_requester" @change="handleSelfRequesterToggle" class="sr-only peer" :disabled="!hasPermission('tickets.edit')">
                                        <div class="w-9 h-5 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-blue-600"></div>
                                    </div>
                                    <span class="text-xs font-bold text-gray-700">I am the requester</span>
                                </label>

                                <div class="pt-2 border-t border-gray-200">
                                    <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1">Current Requester</label>
                                    <div class="rounded-lg border border-gray-200 bg-white px-3 py-2">
                                        <div class="text-xs font-bold text-gray-900">{{ requesterDisplayName }}</div>
                                        <div v-if="requesterDisplayEmail" class="text-[11px] text-gray-500 truncate">{{ requesterDisplayEmail }}</div>
                                    </div>
                                </div>

                                <div v-if="showExternalRequesterFields" class="space-y-3 pt-2 border-t border-gray-200">
                                    <div>
                                        <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1">Requester Name</label>
                                        <input v-model="requesterDraft.sender_name" type="text" maxlength="255" required :disabled="!hasPermission('tickets.edit')"
                                               @blur="updateRequesterDetails"
                                               class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-xs">
                                    </div>
                                    <div>
                                        <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1">Requester Email</label>
                                        <input v-model="requesterDraft.sender_email" type="email" maxlength="255" required :disabled="!hasPermission('tickets.edit')"
                                               @blur="updateRequesterDetails"
                                               class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-xs">
                                    </div>
                                </div>

                                <div class="pt-2 border-t border-gray-200">
                                    <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1">Department</label>
                                    <HierarchySelector
                                        v-model="requesterDraft.department"
                                        :nodes="departmentNodes"
                                        placeholder="Select Department"
                                        :disabled="editForm.is_self_requester || !hasPermission('tickets.edit')"
                                        @update:modelValue="updateRequesterDetails"
                                    />
                                </div>

                            </div>

                            <div v-if="availableCompanies.length > 0">
                                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Company</label>
                                <CustomSelect
                                    v-model="editForm.company_id"
                                    :options="availableCompanies"
                                    label-key="name"
                                    value-key="id"
                                    placeholder="Select Company"
                                    :disabled="!hasPermission('tickets.edit')"
                                >
                                    <template #option="{ option }">
                                        <div class="flex items-center">
                                            <div class="h-6 w-6 rounded bg-gray-200 flex items-center justify-center text-xs font-bold text-gray-600 mr-2">
                                                {{ option.name.charAt(0) }}
                                            </div>
                                            <span>{{ option.name }}</span>
                                        </div>
                                    </template>
                                    <template #trigger="{ selected }">
                                        <div v-if="selected" class="flex items-center">
                                            <div class="h-5 w-5 rounded bg-gray-200 flex items-center justify-center text-[10px] font-bold text-gray-600 mr-2">
                                                {{ selected.name.charAt(0) }}
                                            </div>
                                            <span class="text-sm">{{ selected.name }}</span>
                                        </div>
                                        <span v-else class="text-gray-400 text-sm">Select Company</span>
                                    </template>
                                </CustomSelect>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-1 gap-4 sm:gap-6">
                                <div>
                                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Store</label>
                                    <Autocomplete
                                        v-model="editForm.store_id"
                                        :options="storesWithLabel"
                                        label-key="display_name"
                                        value-key="id"
                                        placeholder="Select store..."
                                        :disabled="!hasPermission('tickets.edit')"
                                    />
                                </div>

                                <div>
                                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Item</label>
                                    <Autocomplete
                                        v-model="editForm.item_id"
                                        :options="items"
                                        label-key="display_name"
                                        value-key="id"
                                        placeholder="Select item..."
                                        :disabled="!hasPermission('tickets.edit')"
                                        size="sm"
                                    />
                                </div>

                                <div>
                                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Vendor Escalation</label>
                                    <Autocomplete
                                        v-model="editForm.vendor_id"
                                        :options="vendors"
                                        label-key="name"
                                        value-key="id"
                                        placeholder="None"
                                        :disabled="!hasPermission('tickets.edit')"
                                        size="sm"
                                    />
                                </div>
                            </div>

                            <div class="grid grid-cols-2 gap-4 lg:grid-cols-1 lg:gap-6">
                                <div>
                                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Status</label>
                                    <CustomSelect
                                        v-model="editForm.status"
                                        :options="availableStatuses"
                                        placeholder="Select Status"
                                        :disabled="!hasPermission('tickets.edit') && !hasPermission('tickets.close')"
                                    >
                                        <template #option="{ option }">
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-black capitalize border" :class="getStatusColor(option)">
                                                {{ getStatusLabel(option) }}
                                            </span>
                                        </template>
                                        <template #trigger="{ selected }">
                                            <span v-if="selected" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-black capitalize border" :class="getStatusColor(selected)">
                                                {{ getStatusLabel(selected) }}
                                            </span>
                                            <span v-else class="text-gray-400 text-sm">Select Status</span>
                                        </template>
                                    </CustomSelect>
                                </div>

                                <div v-if="editForm.priority" class="p-3 bg-gray-50 rounded-lg border border-gray-100">
                                    <label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Priority</label>
                                    <span class="inline-flex px-2.5 py-0.5 rounded-full text-xs font-bold capitalize shadow-sm" :class="getPriorityColor(editForm.priority)">
                                        {{ getPriorityLabel(editForm.priority) }}
                                    </span>
                                </div>
                            </div>
                        </div>

                        <div v-if="hasPermission('tickets.assign')">
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Assignee</label>
                            <p v-if="!isClassificationComplete" class="text-[9px] text-amber-600 font-black uppercase mb-2 bg-amber-50 p-1.5 rounded border border-amber-100 flex items-center">
                                <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" /></svg>
                                Set Company, Store, Item & Department first
                            </p>
                            <CustomSelect
                                v-model="editForm.assignee_id"
                                :options="staff"
                                label-key="name"
                                value-key="id"
                                placeholder="Unassigned"
                                :disabled="!isClassificationComplete"
                            >
                                <template #option="{ option }">
                                    <div class="flex items-center">
                                        <div class="h-6 w-6 rounded-full bg-blue-100 flex items-center justify-center text-xs font-bold text-blue-700 mr-2">
                                            {{ option.name.charAt(0) }}
                                        </div>
                                        <span>{{ option.name }}</span>
                                    </div>
                                </template>
                                <template #trigger="{ selected }">
                                    <div v-if="selected" class="flex items-center">
                                        <div class="h-5 w-5 rounded-full bg-blue-100 flex items-center justify-center text-[10px] font-bold text-blue-700 mr-2">
                                            {{ selected.name.charAt(0) }}
                                        </div>
                                        <span class="text-sm">{{ selected.name }}</span>
                                    </div>
                                    <span v-else class="text-gray-500 italic text-sm">Unassigned</span>
                                </template>
                            </CustomSelect>
                        </div>

                        <!-- CC Recipients -->
                        <div class="pt-6 border-t">
                            <div class="flex items-center justify-between mb-2">
                                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider">CC on Notifications</label>
                                <span v-if="isChildTicket" class="text-[9px] font-black uppercase tracking-widest text-purple-600">Inherited</span>
                            </div>

                            <!-- Child ticket: read-only display of parent's CC list -->
                            <div v-if="isChildTicket">
                                <div v-if="inheritedCcs.length === 0" class="text-[11px] text-gray-400 italic bg-gray-50 rounded-lg p-3 border border-dashed border-gray-200">
                                    Parent ticket has no CC recipients.
                                </div>
                                <div v-else class="flex flex-wrap gap-1.5">
                                    <span v-for="cc in inheritedCcs" :key="cc.id" class="inline-flex items-center gap-1 px-2 py-1 bg-purple-50 text-purple-700 rounded-md text-[10px] font-bold border border-purple-100">
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l9 6 9-6M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                                        {{ cc.name || cc.email }}
                                    </span>
                                </div>
                                <p class="text-[10px] text-gray-400 mt-2">Managed on the parent ticket.</p>
                            </div>

                            <!-- Parent ticket: editable CC manager -->
                            <div v-else class="space-y-2">
                                <div v-if="ccForm.ccs.length === 0" class="text-[11px] text-gray-400 italic bg-gray-50 rounded-lg p-2 border border-dashed border-gray-200">
                                    No CC recipients. Add emails below to notify them on comments, status changes, and assignment changes.
                                </div>
                                <div v-else class="flex flex-wrap gap-1.5">
                                    <span v-for="(cc, idx) in ccForm.ccs" :key="cc.email" class="inline-flex items-center gap-1 px-2 py-1 bg-blue-50 text-blue-700 rounded-md text-[10px] font-bold border border-blue-100">
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l9 6 9-6M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                                        <span :title="cc.email">{{ cc.name || cc.email }}</span>
                                        <button v-if="hasPermission('tickets.edit')" type="button" @click="removeCc(idx)" class="ml-0.5 text-blue-400 hover:text-red-600">
                                            <XMarkIcon class="w-3 h-3" />
                                        </button>
                                    </span>
                                </div>

                                <div v-if="hasPermission('tickets.edit')" class="space-y-2 pt-2">
                                    <!-- Search internal users -->
                                    <div class="relative">
                                        <input
                                            v-model="ccUserSearch"
                                            @focus="showCcUserDropdown = true"
                                            @blur="hideCcDropdownSoon"
                                            type="text"
                                            placeholder="Search internal users..."
                                            class="w-full px-3 py-1.5 text-xs border border-gray-300 rounded-lg focus:ring-1 focus:ring-blue-500 focus:border-blue-500"
                                        />
                                        <div v-if="showCcUserDropdown && ccUserOptions.length > 0" class="absolute z-10 mt-1 w-full bg-white border border-gray-200 rounded-lg shadow-lg max-h-48 overflow-y-auto">
                                            <button
                                                v-for="user in ccUserOptions"
                                                :key="user.id"
                                                type="button"
                                                @mousedown.prevent="addCcUser(user)"
                                                class="w-full text-left px-3 py-2 hover:bg-blue-50 border-b border-gray-100 last:border-0"
                                            >
                                                <div class="text-xs font-bold text-gray-800">{{ user.name }}</div>
                                                <div class="text-[10px] text-gray-500">{{ user.email }}</div>
                                            </button>
                                        </div>
                                    </div>

                                    <!-- Free-form email entry -->
                                    <div class="flex gap-1.5">
                                        <input
                                            v-model="ccDraftEmail"
                                            @keydown.enter.prevent="addCcEmail"
                                            type="email"
                                            placeholder="Add external email..."
                                            class="flex-1 px-3 py-1.5 text-xs border border-gray-300 rounded-lg focus:ring-1 focus:ring-blue-500 focus:border-blue-500"
                                        />
                                        <button type="button" @click="addCcEmail" class="px-3 py-1.5 text-xs font-black text-white bg-blue-600 rounded-lg hover:bg-blue-700 uppercase tracking-widest">
                                            Add
                                        </button>
                                    </div>

                                    <button
                                        type="button"
                                        @click="saveCcList"
                                        :disabled="ccForm.processing"
                                        class="w-full px-3 py-1.5 text-xs font-black text-white bg-emerald-600 rounded-lg hover:bg-emerald-700 uppercase tracking-widest disabled:opacity-60"
                                    >
                                        {{ ccForm.processing ? 'Saving...' : 'Save CC List' }}
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="pt-6 border-t space-y-3">
                            <button
                                v-if="hasPermission('tickets.edit') && (ticket.status === 'open' || ticket.status === 'in_progress')"
                                type="button" 
                                @click="openChildModal" 
                                class="w-full flex justify-center py-2 px-4 border border-blue-600 rounded-md text-sm font-black text-blue-600 bg-white hover:bg-blue-50 transition-colors uppercase tracking-widest"
                            >
                                Create Child Ticket
                            </button>

                            <button
                                v-if="canAssignSchedule && hasPermission('tickets.edit')"
                                type="button"
                                @click="openAssignScheduleModal"
                                class="w-full flex justify-center py-2 px-4 border border-amber-600 rounded-md text-sm font-black text-amber-700 bg-amber-50 hover:bg-amber-100 transition-colors uppercase tracking-widest"
                            >
                                Assign Schedule
                            </button>

                            <button
                                v-if="canEditSchedule && hasPermission('tickets.edit')"
                                type="button"
                                @click="openEditScheduleModal"
                                class="w-full flex justify-center py-2 px-4 border border-blue-600 rounded-md text-sm font-black text-blue-700 bg-blue-50 hover:bg-blue-100 transition-colors uppercase tracking-widest"
                            >
                                Edit Schedule
                            </button>

                            <button v-if="hasPermission('tickets.delete')" type="button" @click="deleteTicket" class="w-full flex justify-center py-2 px-4 border border-transparent rounded-md text-sm font-black text-red-600 bg-red-50 hover:bg-red-100 transition-colors uppercase tracking-widest">
                                Archive Ticket
                            </button>
                        </div>
                    </div>

                    <!-- Children Tickets -->
                    <div v-if="ticket.children && ticket.children.length > 0" class="bg-white rounded-lg shadow-sm border border-gray-200 p-4 sm:p-6 space-y-4">
                        <h3 class="text-sm font-black text-gray-900 uppercase tracking-widest">Child Tickets</h3>
                        <div class="space-y-3">
                            <div v-for="child in ticket.children" :key="child.id" class="flex items-center justify-between p-3 bg-gray-50 rounded-lg border border-gray-100 gap-3">
                                <div class="flex flex-col min-w-0 flex-1">
                                    <Link :href="route('tickets.edit', child.id)" class="text-sm font-black text-blue-600 hover:underline truncate">
                                        {{ child.ticket_key }}
                                    </Link>
                                    <span class="text-[10px] text-gray-600 truncate">{{ child.title }}</span>
                                </div>
                                <div class="flex flex-col items-end gap-1 shrink-0">
                                    <span class="px-2 py-0.5 rounded-full text-[9px] font-black uppercase tracking-tighter" :class="getStatusColor(child.status)">
                                        {{ child.status }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Left Column: Content & History -->
                <div class="lg:col-span-2 space-y-6 order-2 lg:order-1">
                    
                    <!-- Title Section -->
                    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4 sm:p-6 transition-all duration-200 hover:shadow-md">
                        <div v-if="!isEditingTitle" 
                             class="group relative -m-2 p-2 rounded-lg hover:bg-gray-50 transition-colors duration-200">
                            <h1 class="text-xl sm:text-3xl font-bold text-gray-900 leading-tight tracking-tight">
                                {{ ticket.title }}
                            </h1>
                            <div v-if="hasPermission('tickets.edit')" 
                                 class="absolute top-2 right-2 opacity-0 group-hover:opacity-100 transition-opacity duration-200 cursor-pointer"
                                 @click="startEditingTitle"
                                 title="Edit Title">
                                <span class="inline-flex items-center px-2 py-1 rounded text-[10px] font-medium bg-blue-50 text-blue-700 hover:bg-blue-100">
                                    <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" /></svg>
                                    Edit
                                </span>
                            </div>
                        </div>
                        <div v-else>
                             <input 
                                ref="titleInput"
                                v-model="editForm.title" 
                                type="text" 
                                maxlength="255"
                                class="block w-full text-lg sm:text-3xl font-bold text-gray-900 leading-tight border-0 border-b-2 border-blue-500 focus:ring-0 focus:border-blue-600 px-0 py-1 bg-transparent placeholder-gray-300"
                                placeholder="Enter ticket title..."
                                @blur="saveTitle"
                                @keydown.enter="saveTitle"
                                @keydown.esc="cancelTitleEdit"
                            >
                            <div class="mt-2 text-[10px] text-gray-500 flex justify-end">Press Enter to save, Esc to cancel</div>
                        </div>
                    </div>

                    <!-- Activity / Timeline -->
                    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4 sm:p-6 pb-0 relative">
                        <div class="flex items-center justify-between mb-6">
                            <h3 class="text-lg font-black text-gray-900 uppercase tracking-widest">Activity Timeline</h3>
                            <button 
                                type="button"
                                @click="showAuditTrails = !showAuditTrails"
                                :class="[
                                    'inline-flex items-center gap-2 px-3 py-1.5 rounded-lg text-[10px] font-black uppercase tracking-widest transition-all',
                                    showAuditTrails ? 'bg-blue-600 text-white shadow-sm' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'
                                ]"
                            >
                                <AdjustmentsHorizontalIcon class="w-4 h-4" />
                                {{ showAuditTrails ? 'Hide Audit Trails' : 'Show Audit Trails' }}
                            </button>
                        </div>

                        <!-- Timeline List -->
                        <div class="relative pl-4 border-l-2 border-gray-200 space-y-8 mb-8 pb-6">
                            <!-- Loop Activities (Comments + History + Description) -->
                            <div v-for="activity in filteredActivities" :key="activity.activity_type + '-' + activity.id" class="relative">
                                
                                <!-- Description Item (Inline Editable) -->
                                <template v-if="activity.activity_type === 'description'">
                                    <!-- Parent Context Card (for Child Tickets) -->
                                    <div v-if="ticket.parent_id && ticket.parent" class="mb-6 bg-purple-50 border border-purple-100 rounded-xl overflow-hidden shadow-sm">
                                        <div class="px-4 py-3 bg-white border-b border-purple-100 flex items-center justify-between">
                                            <div class="flex flex-col">
                                                <span class="text-[10px] font-black text-purple-400 uppercase tracking-widest mb-0.5">Originating Parent Ticket</span>
                                                <h4 class="text-sm font-bold text-gray-900 leading-tight">{{ ticket.parent.title }}</h4>
                                            </div>
                                            <span class="px-2.5 py-1 rounded-lg text-xs font-black bg-purple-100 text-purple-700 border border-purple-200">
                                                {{ ticket.parent.ticket_key }}
                                            </span>
                                        </div>
                                        <div class="p-4">
                                            <div class="text-sm text-gray-600 leading-relaxed line-clamp-3 italic whitespace-pre-wrap" v-html="linkify(ticket.parent.description)"></div>
                                            <div class="mt-3 flex justify-end">
                                                <Link :href="route('tickets.edit', ticket.parent_id)" class="text-[10px] font-black text-purple-600 hover:text-purple-800 uppercase tracking-widest flex items-center group">
                                                    View Parent Details
                                                    <svg class="w-3 h-3 ml-1 transform group-hover:translate-x-0.5 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6" /></svg>
                                                </Link>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Empty Schedule State (Child Ticket without Schedule) -->
                                    <div v-if="canAssignSchedule" class="mb-6 bg-amber-50 border border-amber-200 rounded-xl p-4 flex items-center justify-between gap-3">
                                        <div class="flex items-center gap-3">
                                            <div class="w-9 h-9 rounded-full bg-amber-100 flex items-center justify-center">
                                                <svg class="w-5 h-5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
                                            </div>
                                            <div class="flex flex-col">
                                                <span class="text-xs font-black text-amber-700 uppercase tracking-widest">No schedule assigned</span>
                                                <span class="text-[11px] text-amber-600">This child ticket was created without a schedule. Assign one to set start/end times.</span>
                                            </div>
                                        </div>
                                        <button @click="openAssignScheduleModal" type="button" class="px-3 py-2 text-xs font-black text-white bg-amber-600 rounded-lg hover:bg-amber-700 shadow-sm uppercase tracking-widest whitespace-nowrap">
                                            + Assign Schedule
                                        </button>
                                    </div>

                                    <!-- Child Schedule Context (for Child Tickets) -->
                                    <div v-if="ticket.parent_id && (activity.schedule || activity.schedule_store || activity.scheduleStore)" class="mb-6 bg-blue-50/50 border border-blue-100 rounded-xl p-4 space-y-3">
                                        <div class="flex items-center justify-between border-b border-blue-100 pb-2 mb-2">
                                            <span class="text-[10px] font-black text-blue-400 uppercase tracking-widest">Schedule Assignment</span>
                                            <div class="flex items-center gap-2">
                                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[9px] font-black uppercase bg-blue-100 text-blue-700 border border-blue-200">
                                                    {{ (activity.schedule || activity.schedule_store?.schedule || activity.scheduleStore?.schedule)?.status }}
                                                </span>
                                                <button v-if="canEditSchedule" @click="openEditScheduleModal" type="button" class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[9px] font-black uppercase tracking-widest text-blue-700 bg-white border border-blue-200 hover:bg-blue-50">
                                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
                                                    Edit
                                                </button>
                                            </div>
                                        </div>
                                        
                                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                            <div class="flex flex-col">
                                                <span class="text-[9px] font-bold text-gray-400 uppercase tracking-wider">Scheduled Time</span>
                                                <span class="text-xs font-bold text-gray-700">
                                                    {{ formatDate((activity.schedule || activity.schedule_store?.schedule || activity.scheduleStore?.schedule)?.start_time) }} – 
                                                    {{ formatDate((activity.schedule || activity.schedule_store?.schedule || activity.scheduleStore?.schedule)?.end_time) }}
                                                </span>
                                            </div>
                                            <div v-if="activity.store || activity.schedule_store?.store || activity.scheduleStore?.store" class="flex flex-col">
                                                <span class="text-[9px] font-bold text-gray-400 uppercase tracking-wider">Store Branch</span>
                                                <span class="text-xs font-bold text-gray-700">
                                                    {{ (activity.store || activity.schedule_store?.store || activity.scheduleStore?.store)?.name }}
                                                </span>
                                            </div>
                                        </div>

                                        <div v-if="(activity.schedule || activity.schedule_store?.schedule || activity.scheduleStore?.schedule)?.pickup_start || (activity.schedule || activity.schedule_store?.schedule || activity.scheduleStore?.schedule)?.backlogs_start" class="flex flex-col gap-2 pt-2 border-t border-blue-50">
                                            <span class="text-[9px] font-bold text-gray-400 uppercase tracking-wider">Operational Windows</span>
                                            <div class="flex flex-wrap gap-2">
                                                <span v-if="(activity.schedule || activity.schedule_store?.schedule || activity.scheduleStore?.schedule)?.pickup_start" class="bg-white px-2 py-1 rounded text-[10px] font-medium border border-blue-100 text-blue-600">Pickup: {{ formatTime((activity.schedule || activity.schedule_store?.schedule || activity.scheduleStore?.schedule)?.pickup_start) }}–{{ formatTime((activity.schedule || activity.schedule_store?.schedule || activity.scheduleStore?.schedule)?.pickup_end) }}</span>
                                                <span v-if="(activity.schedule || activity.schedule_store?.schedule || activity.scheduleStore?.schedule)?.pickup_start && (activity.schedule || activity.schedule_store?.schedule || activity.scheduleStore?.schedule)?.backlogs_start"> &nbsp;|&nbsp; </span>
                                                <span v-if="(activity.schedule || activity.schedule_store?.schedule || activity.scheduleStore?.schedule)?.backlogs_start" class="bg-white px-2 py-1 rounded text-[10px] font-medium border border-blue-100 text-blue-600">Backlogs: {{ formatTime((activity.schedule || activity.schedule_store?.schedule || activity.scheduleStore?.schedule)?.backlogs_start) }}–{{ formatTime((activity.schedule || activity.schedule_store?.schedule || activity.scheduleStore?.schedule)?.backlogs_end) }}</span>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Dot (Avatar) -->
                                    <div class="absolute -left-[25px] top-0 w-6 h-6 rounded-full border-2 border-white shadow-sm overflow-hidden bg-white">
                                        <img v-if="activity.user && activity.user.profile_photo" :src="'/serve-storage/' + activity.user.profile_photo" class="w-full h-full object-cover" :alt="activity.user.name">
                                        <div v-else class="w-full h-full bg-blue-500 flex items-center justify-center text-[10px] font-bold text-white">
                                            {{ activity.user ? activity.user.name.charAt(0) : '?' }}
                                        </div>
                                    </div>
                                    
                                    <div class="flex flex-col sm:flex-row sm:justify-between sm:items-start mb-1 gap-1">
                                        <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                                            <span class="font-bold text-gray-900 text-sm">
                                                {{ activity.user ? activity.user.name : (activity.sender_name || 'External User') }}
                                            </span>
                                            <span class="text-[10px] sm:text-xs text-gray-500 font-medium">
                                                on {{ formatDate(activity.date) }}
                                            </span>
                                        </div>
                                    </div>
                                    
                                    <!-- Editable Description Area -->
                                    <div v-if="!isEditingDescription" 
                                         class="group relative border border-transparent rounded p-2 -ml-2 hover:bg-gray-50 hover:border-gray-200 transition-colors">
                                        <div class="text-gray-700 text-sm sm:text-base leading-relaxed">
                                            <template v-for="line in getDescriptionLines(activity.text)" :key="line.index">
                                                <div v-if="line.storeList" class="flex flex-wrap items-center gap-1.5 min-h-[1.5rem]">
                                                    <span class="font-semibold text-gray-800" v-html="linkify(line.storeList.prefix)"></span>
                                                    <span
                                                        v-for="store in visibleStoresForLine(line.storeList.stores, getStoreListKey(activity, line.index))"
                                                        :key="`${getStoreListKey(activity, line.index)}-${store}`"
                                                        class="inline-flex items-center rounded-md border border-blue-100 bg-blue-50 px-2 py-0.5 text-[11px] font-bold text-blue-700"
                                                    >
                                                        {{ store }}
                                                    </span>
                                                    <button
                                                        v-if="hiddenStoreCountForLine(line.storeList.stores, getStoreListKey(activity, line.index)) > 0"
                                                        type="button"
                                                        @click="toggleStoreList(getStoreListKey(activity, line.index))"
                                                        class="inline-flex items-center rounded-md border border-gray-200 bg-white px-2 py-0.5 text-[11px] font-black text-blue-600 hover:border-blue-200 hover:bg-blue-50"
                                                    >
                                                        +{{ hiddenStoreCountForLine(line.storeList.stores, getStoreListKey(activity, line.index)) }} more
                                                    </button>
                                                    <button
                                                        v-else-if="isStoreListExpanded(getStoreListKey(activity, line.index)) && line.storeList.stores.length > STORE_LIST_DISPLAY_LIMIT"
                                                        type="button"
                                                        @click="toggleStoreList(getStoreListKey(activity, line.index))"
                                                        class="inline-flex items-center rounded-md border border-gray-200 bg-white px-2 py-0.5 text-[11px] font-black text-gray-500 hover:border-gray-300 hover:bg-gray-50"
                                                    >
                                                        Show less
                                                    </button>
                                                </div>
                                                <div v-else class="whitespace-pre-wrap min-h-[1.5rem]" v-html="linkify(line.raw || ' ')"></div>
                                            </template>
                                        </div>
                                        <div v-if="hasPermission('tickets.edit')" 
                                             class="absolute top-2 right-2 opacity-0 group-hover:opacity-100 text-gray-400 cursor-pointer hover:text-blue-600 transition-colors"
                                             @click="startEditingDescription"
                                             title="Edit Description">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" /></svg>
                                        </div>
                                    </div>
                                    <div v-else>
                                        <textarea 
                                            ref="descriptionInput"
                                            v-model="editForm.description" 
                                            rows="6" 
                                            class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 mb-2 text-sm sm:text-base"
                                            @keydown.esc="cancelDescriptionEdit"
                                        ></textarea>
                                        <div class="flex justify-end space-x-2">
                                            <button @click="cancelDescriptionEdit" class="px-3 py-1 text-sm text-gray-600 hover:text-gray-800">Cancel</button>
                                            <button @click="saveDescription" class="px-3 py-1 text-sm bg-blue-600 text-white rounded hover:bg-blue-700">Save</button>
                                        </div>
                                    </div>

                                    <!-- Description Attachments -->
                                    <div v-if="activity.attachments && activity.attachments.length > 0" class="mt-3 grid grid-cols-2 sm:grid-cols-4 gap-3">
                                        <div v-for="attachment in activity.attachments" :key="attachment.id" class="relative group border border-gray-200 rounded-lg overflow-hidden hover:shadow-md transition bg-white">
                                            <a
                                                v-if="getAttachmentDownloadUrl(attachment)"
                                                :href="getAttachmentDownloadUrl(attachment)"
                                                class="absolute right-2 top-2 z-20 inline-flex h-8 w-8 items-center justify-center rounded-full bg-white/95 text-gray-600 shadow-sm ring-1 ring-gray-200 transition hover:bg-blue-600 hover:text-white"
                                                title="Download attachment"
                                                aria-label="Download attachment"
                                                @click.stop
                                            >
                                                <ArrowDownTrayIcon class="h-4 w-4" />
                                            </a>
                                            <div v-if="isMedia(attachment.file_name) && !failedImages.has(attachment.id)" 
                                                 class="aspect-w-16 aspect-h-9 bg-gray-100 cursor-pointer relative"
                                                 @click="openImageViewer(attachment)">
                                                <video v-if="isVideo(attachment.file_name)" 
                                                       :src="getThumbnailUrl(attachment)" 
                                                       class="object-cover w-full h-24 sm:h-32" 
                                                       muted></video>
                                                <img v-else :src="getThumbnailUrl(attachment)" 
                                                     class="object-cover w-full h-24 sm:h-32" 
                                                     :alt="attachment.file_name"
                                                     @error="handleImageError(attachment.id)">
                                                <!-- Video Play Icon Overlay -->
                                                <div v-if="isVideo(attachment.file_name)" class="absolute inset-0 flex items-center justify-center bg-black/10 group-hover:bg-black/20 transition-colors">
                                                    <svg class="w-8 h-8 text-white drop-shadow-md" fill="currentColor" viewBox="0 0 20 20"><path d="M10 18a8 8 0 100-16 8 8 0 000 16zM9.555 7.168A1 1 0 008 8v4a1 1 0 001.555.832l3-2a1 1 0 000-1.664l-3-2z" /></svg>
                                                </div>
                                            </div>
                                            <div v-else class="h-24 sm:h-32 flex flex-col items-center justify-center p-4 bg-gray-50">
                                                <svg class="w-8 h-8 text-gray-400 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" /></svg>
                                                <span class="text-[10px] text-gray-500 text-center truncate w-full px-2">{{ attachment.file_name }}</span>
                                            </div>
                                        </div>
                                    </div>
                                </template>

                                <!-- Comment Item -->
                                <template v-else-if="activity.activity_type === 'comment'">
                                    <!-- Dot (Avatar) -->
                                    <div class="absolute -left-[25px] top-0 w-6 h-6 rounded-full border-2 border-white shadow-sm overflow-hidden bg-white">
                                        <img v-if="activity.user && activity.user.profile_photo" :src="'/serve-storage/' + activity.user.profile_photo" class="w-full h-full object-cover" :alt="activity.user.name">
                                        <div v-else class="w-full h-full bg-blue-100 flex items-center justify-center text-[10px] font-bold text-blue-600">
                                            {{ activity.user ? activity.user.name.charAt(0) : (activity.sender_name ? activity.sender_name.charAt(0) : '?') }}
                                        </div>
                                    </div>
                                    
                                    <div class="flex items-center space-x-2 mb-1">
                                        <span class="font-bold text-gray-900 text-sm">
                                            {{ activity.user ? activity.user.name : (activity.sender_name || activity.sender_email || 'External User') }}
                                        </span>
                                        <span class="text-[10px] sm:text-xs text-gray-500 font-medium">{{ formatDate(activity.date) }}</span>
                                    </div>
                                    
                                    <div class="text-gray-700 whitespace-pre-wrap mb-2 text-sm sm:text-base leading-relaxed" v-html="linkify(activity.comment_text)"></div>

                                    <!-- Comment Attachments -->
                                    <div v-if="activity.attachments && activity.attachments.length > 0" class="mt-3 grid grid-cols-2 sm:grid-cols-4 gap-3">
                                        <div v-for="attachment in activity.attachments" :key="attachment.id" class="relative group border border-gray-200 rounded-lg overflow-hidden hover:shadow-md transition bg-white">
                                            <a
                                                v-if="getAttachmentDownloadUrl(attachment)"
                                                :href="getAttachmentDownloadUrl(attachment)"
                                                class="absolute right-2 top-2 z-20 inline-flex h-8 w-8 items-center justify-center rounded-full bg-white/95 text-gray-600 shadow-sm ring-1 ring-gray-200 transition hover:bg-blue-600 hover:text-white"
                                                title="Download attachment"
                                                aria-label="Download attachment"
                                                @click.stop
                                            >
                                                <ArrowDownTrayIcon class="h-4 w-4" />
                                            </a>
                                            <div v-if="isMedia(attachment.file_name) && !failedImages.has(attachment.id)" 
                                                 class="aspect-w-16 aspect-h-9 bg-gray-100 cursor-pointer relative"
                                                 @click="openImageViewer(attachment)">
                                                <video v-if="isVideo(attachment.file_name)" 
                                                       :src="getThumbnailUrl(attachment)" 
                                                       class="object-cover w-full h-24 sm:h-32" 
                                                       muted></video>
                                                <img v-else :src="getThumbnailUrl(attachment)" 
                                                     class="object-cover w-full h-24 sm:h-32" 
                                                     :alt="attachment.file_name"
                                                     @error="handleImageError(attachment.id)">
                                                <!-- Video Play Icon Overlay -->
                                                <div v-if="isVideo(attachment.file_name)" class="absolute inset-0 flex items-center justify-center bg-black/10 group-hover:bg-black/20 transition-colors">
                                                    <svg class="w-8 h-8 text-white drop-shadow-md" fill="currentColor" viewBox="0 0 20 20"><path d="M10 18a8 8 0 100-16 8 8 0 000 16zM9.555 7.168A1 1 0 008 8v4a1 1 0 001.555.832l3-2a1 1 0 000-1.664l-3-2z" /></svg>
                                                </div>
                                            </div>
                                            <div v-else class="h-24 sm:h-32 flex flex-col items-center justify-center p-4 bg-gray-50">
                                                <svg class="w-8 h-8 text-gray-400 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" /></svg>
                                                <span class="text-[10px] text-gray-500 text-center truncate w-full px-2">{{ attachment.file_name }}</span>
                                            </div>
                                        </div>
                                    </div>
                                </template>

                                <!-- History Item -->
                                <template v-else-if="activity.activity_type === 'history'">
                                    <!-- Dot (Avatar) -->
                                    <div class="absolute -left-[25px] top-0 w-6 h-6 rounded-full border-2 border-white shadow-sm overflow-hidden bg-white">
                                        <img v-if="activity.user && activity.user.profile_photo" :src="'/serve-storage/' + activity.user.profile_photo" class="w-full h-full object-cover" :alt="activity.user.name">
                                        <div v-else class="w-full h-full bg-gray-400 flex items-center justify-center text-[10px] font-bold text-white">
                                            {{ activity.user ? activity.user.name.charAt(0) : '?' }}
                                        </div>
                                    </div>
                                    
                                    <div class="flex items-center space-x-2 mb-1">
                                        <span class="font-bold text-gray-900 text-sm">
                                            {{ activity.user ? activity.user.name : (ticket.reporter ? ticket.reporter.name : (ticket.sender_name || 'Customer')) }}
                                        </span>
                                        <span class="text-[10px] text-gray-500">{{ formatDate(activity.date) }}</span>
                                    </div>
                                    
                                    <div class="text-xs sm:text-sm text-gray-600 bg-gray-50 p-2 rounded border border-gray-100 leading-relaxed">
                                        Changed <span class="font-black text-gray-800">{{ formatColumnName(activity.column_changed) }}</span> 
                                        from <span class="font-bold text-red-600 bg-red-50 px-1 rounded line-through decoration-red-400">
                                            {{ activity.column_changed === 'assignee_id' && !activity.old_value ? 'Unassigned' : (activity.old_value || '(empty)') }}
                                        </span> 
                                        to <span class="font-bold text-green-600 bg-green-50 px-1 rounded">
                                            {{ activity.column_changed === 'assignee_id' && !activity.new_value ? 'Unassigned' : (activity.new_value || '(empty)') }}
                                        </span>
                                    </div>
                                </template>

                                <!-- Child Ticket Item -->
                                <template v-else-if="activity.activity_type === 'child_ticket'">
                                    <!-- Dot (Avatar) -->
                                    <div class="absolute -left-[25px] top-0 w-6 h-6 rounded-full border-2 border-white shadow-sm overflow-hidden bg-white">
                                        <div class="w-full h-full bg-purple-500 flex items-center justify-center text-[10px] font-bold text-white">
                                            {{ activity.user ? activity.user.name.charAt(0) : '?' }}
                                        </div>
                                    </div>

                                    <div class="flex flex-col sm:flex-row sm:items-center sm:space-x-2 mb-2">
                                        <span class="font-bold text-gray-900 text-sm">{{ activity.user ? activity.user.name : 'Unknown User' }}</span>
                                        <span class="text-[10px] sm:text-xs text-gray-500 font-medium">created child ticket <Link :href="route('tickets.edit', activity.id)" class="font-black text-blue-600 hover:underline">{{ activity.ticket_key }}</Link> on {{ formatDate(activity.date) }}</span>
                                    </div>

                                    <div class="text-xs bg-purple-50 border border-purple-100 rounded-lg p-3 space-y-1.5">
                                        <div v-if="activity.assignee" class="flex items-center gap-2">
                                            <span class="text-[10px] font-bold text-purple-500 uppercase tracking-wider w-20 flex-shrink-0">Assigned To</span>
                                            <span class="font-semibold text-gray-800">{{ activity.assignee.name }}</span>
                                        </div>
                                        <div v-if="activity.scheduleContext?.schedule?.status" class="flex items-center gap-2">
                                            <span class="text-[10px] font-bold text-purple-500 uppercase tracking-wider w-20 flex-shrink-0">Schedule</span>
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-purple-100 text-purple-800">
                                                {{ activity.scheduleContext.schedule.status }}
                                            </span>
                                        </div>
                                        <div v-if="activity.scheduleContext?.start_time && activity.scheduleContext?.end_time" class="flex items-center gap-2">
                                            <span class="text-[10px] font-bold text-purple-500 uppercase tracking-wider w-20 flex-shrink-0">Time</span>
                                            <span class="text-gray-700">
                                                {{ formatDate(activity.scheduleContext.start_time) }} - {{ formatDate(activity.scheduleContext.end_time) }}
                                            </span>
                                        </div>
                                        <div v-if="activity.scheduleContext?.pickup_start || activity.scheduleContext?.backlogs_start" class="flex items-center gap-2">
                                            <span class="text-[10px] font-bold text-purple-500 uppercase tracking-wider w-20 flex-shrink-0">Add'l Times</span>
                                            <span class="text-gray-600 text-[11px]">
                                                <span v-if="activity.scheduleContext?.pickup_start">Pickup: {{ formatTime(activity.scheduleContext.pickup_start) }}-{{ formatTime(activity.scheduleContext.pickup_end) }}</span>
                                                <span v-if="activity.scheduleContext?.pickup_start && activity.scheduleContext?.backlogs_start"> | </span>
                                                <span v-if="activity.scheduleContext?.backlogs_start">Backlogs: {{ formatTime(activity.scheduleContext.backlogs_start) }}-{{ formatTime(activity.scheduleContext.backlogs_end) }}</span>
                                            </span>
                                        </div>
                                        <div v-if="activity.scheduleContext?.store" class="flex items-center gap-2">
                                            <span class="text-[10px] font-bold text-purple-500 uppercase tracking-wider w-20 flex-shrink-0">Store</span>
                                            <span class="text-gray-700">{{ activity.scheduleContext.store.name }}</span>
                                        </div>
                                        <div v-if="activity.scheduleContext?.remarks" class="flex items-start gap-2">
                                            <span class="text-[10px] font-bold text-purple-500 uppercase tracking-wider w-20 flex-shrink-0 mt-0.5">Remarks</span>
                                            <span class="text-gray-700 whitespace-pre-wrap">{{ activity.scheduleContext.remarks }}</span>
                                        </div>
                                    </div>
                                </template>
                            </div>

                        </div>

                        <!-- Sticky Comment Input -->
                        <div v-if="ticket.status !== 'closed'" class="sticky bottom-0 z-10 -mx-4 sm:-mx-6 -mb-0 p-4 sm:p-6 bg-blue-50/95 backdrop-blur-sm border-t-2 border-blue-200 shadow-[0_-8px_15px_-3px_rgba(0,0,0,0.1)] rounded-b-lg">
                            <div class="flex space-x-3 sm:space-x-4">
                                <div class="flex-shrink-0 hidden xs:block">
                                    <div v-if="$page.props.auth.user.profile_photo" class="w-8 h-8 sm:w-10 sm:h-10 rounded-full overflow-hidden border-2 border-white shadow-sm">
                                        <img :src="'/serve-storage/' + $page.props.auth.user.profile_photo" class="h-full w-full object-cover" :alt="$page.props.auth.user.name">
                                    </div>
                                    <div v-else class="w-8 h-8 sm:w-10 sm:h-10 rounded-full bg-blue-600 flex items-center justify-center text-white font-bold shadow-sm">
                                        {{ $page.props.auth.user.name.charAt(0) }}
                                    </div>
                                </div>
                                <div class="flex-grow">
                                    <div class="bg-white border-2 border-blue-100 rounded-xl shadow-sm focus-within:ring-2 focus-within:ring-blue-400 focus-within:border-blue-400 transition-all duration-200">
                                        <textarea 
                                            v-model="commentForm.comment_text" 
                                            rows="2" 
                                            class="block w-full border-0 focus:ring-0 resize-y bg-transparent p-3 text-sm sm:text-base text-gray-700 placeholder-gray-400 transition-all duration-300 ease-in-out focus:min-h-[50vh]" 
                                            placeholder="Write your response..."
                                            @paste="handlePaste"
                                        ></textarea>
                                        
                                        <!-- Attachment Preview -->
                                        <div v-if="commentForm.attachments.length > 0" class="px-3 pb-3 flex flex-nowrap overflow-x-auto gap-3 border-t border-blue-50 pt-3 custom-scrollbar scrollbar-hide">
                                            <div v-for="(attachment, index) in commentForm.attachments" :key="attachment.id" class="relative group border border-gray-200 rounded-lg overflow-hidden hover:shadow-md transition bg-white flex-shrink-0 w-24">
                                                <div v-if="isMedia(attachment.file_name) && attachment.preview" 
                                                     class="aspect-w-16 aspect-h-9 bg-gray-100 cursor-pointer relative"
                                                     @click="openImageViewer(attachment)">
                                                    <video v-if="isVideo(attachment.file_name)" 
                                                           :src="attachment.preview" 
                                                           class="object-cover w-full h-24" 
                                                           muted></video>
                                                    <img v-else :src="attachment.preview" class="object-cover w-full h-24" :alt="attachment.file_name">
                                                    <!-- Video Play Icon Overlay -->
                                                    <div v-if="isVideo(attachment.file_name)" class="absolute inset-0 flex items-center justify-center bg-black/10 group-hover:bg-black/20 transition-colors">
                                                        <svg class="w-6 h-6 text-white drop-shadow-md" fill="currentColor" viewBox="0 0 20 20"><path d="M10 18a8 8 0 100-16 8 8 0 000 16zM9.555 7.168A1 1 0 008 8v4a1 1 0 001.555.832l3-2a1 1 0 000-1.664l-3-2z" /></svg>
                                                    </div>
                                                </div>
                                                <div v-else class="h-24 flex flex-col items-center justify-center p-2 bg-gray-50">
                                                    <svg class="w-8 h-8 text-gray-400 mb-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" /></svg>
                                                    <span class="text-[9px] text-gray-500 text-center truncate w-full px-1">{{ attachment.file_name }}</span>
                                                </div>
                                                <button type="button" @click="removeCommentAttachment(index)" class="absolute top-1 right-1 p-1 bg-red-500 text-white rounded-full shadow-sm hover:bg-red-600">
                                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                                </button>
                                            </div>
                                        </div>

                                        <div class="flex flex-col sm:flex-row sm:items-center justify-between px-3 py-2 border-t border-blue-50 bg-blue-50/50 rounded-b-xl gap-3">
                                            <div class="flex items-center space-x-2">
                                                <input ref="commentFileInput" type="file" multiple accept="image/*,video/*" class="hidden" @change="handleCommentFileSelect">
                                                <button type="button" @click="commentFileInput.click()" class="p-1.5 text-blue-600 hover:text-blue-800 rounded-lg hover:bg-blue-100 transition-all" title="Attach Media">
                                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13" /></svg>
                                                </button>
                                                
                                                <!-- Canned Messages -->
                                                <div v-if="hasPermission('tickets.canned_messages') || hasPermission('tickets.edit')" class="relative">
                                                    <button 
                                                        type="button" 
                                                        @click="showCannedMessages = !showCannedMessages; showInternalNotesPopover = false" 
                                                        class="p-1.5 text-orange-600 hover:text-orange-800 rounded-lg hover:bg-orange-100 transition-all flex items-center justify-center" 
                                                        title="Canned Messages"
                                                    >
                                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" />
                                                        </svg>
                                                    </button>
                                                    
                                                    <div v-if="showCannedMessages" class="absolute bottom-full left-0 mb-2 w-72 bg-white rounded-lg shadow-xl border border-gray-200 z-50 overflow-hidden">
                                                        <div class="p-2 border-b bg-gray-50 flex justify-between items-center">
                                                            <span class="text-xs font-bold text-gray-700 uppercase tracking-wider">Canned Messages</span>
                                                            <button @click="showCannedMessages = false" class="text-gray-400 hover:text-gray-600">
                                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                                                            </button>
                                                        </div>
                                                        <div class="p-2 border-b bg-white">
                                                            <input 
                                                                v-model="cannedMessageSearch" 
                                                                type="text" 
                                                                placeholder="Search messages..." 
                                                                class="w-full px-3 py-1.5 text-xs border border-gray-200 rounded-md focus:ring-blue-500 focus:border-blue-500"
                                                                @click.stop
                                                            >
                                                        </div>
                                                        <div class="max-h-60 overflow-y-auto">
                                                            <template v-if="filteredCannedMessages && filteredCannedMessages.length > 0">
                                                                <button 
                                                                    v-for="message in filteredCannedMessages" 
                                                                    :key="message.id"
                                                                    @click="applyCannedMessage(message)"
                                                                    class="w-full text-left px-4 py-3 hover:bg-blue-50 border-b border-gray-50 last:border-0 transition-colors"
                                                                >
                                                                    <div class="font-bold text-xs text-blue-700 mb-1">{{ message.title }}</div>
                                                                    <div class="text-[10px] text-gray-600 line-clamp-2">{{ message.content }}</div>
                                                                </button>
                                                            </template>
                                                            <div v-else class="px-4 py-8 text-center text-gray-500 text-xs italic">
                                                                {{ cannedMessageSearch ? 'No matching messages found.' : 'No canned messages found.' }}
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>

                                                <!-- Internal Notes Popover -->
                                                <div v-if="hasPermission('tickets.internal_notes') || hasPermission('tickets.edit')" class="relative">
                                                    <button 
                                                        type="button" 
                                                        @click="showInternalNotesPopover = !showInternalNotesPopover; showCannedMessages = false" 
                                                        :class="[
                                                            'p-1.5 rounded-lg transition-all border flex items-center justify-center relative',
                                                            showInternalNotesPopover ? 'bg-amber-600 text-white border-amber-700' : 'text-amber-600 hover:text-amber-800 border-transparent hover:bg-amber-50'
                                                        ]"
                                                        title="Internal Notes"
                                                    >
                                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                                                        </svg>
                                                        <!-- Notification Badge -->
                                                        <span v-if="internalNotes.length > 0" class="absolute -top-1.5 -right-1.5 flex h-4 min-w-[16px] items-center justify-center rounded-full bg-red-600 px-1 text-[9px] font-black text-white shadow-sm ring-2 ring-white animate-bounce-short">
                                                            {{ internalNotes.length }}
                                                        </span>
                                                    </button>

                                                    <div v-if="showInternalNotesPopover" class="absolute bottom-full left-0 mb-2 w-80 bg-white rounded-lg shadow-2xl border border-gray-200 z-50 overflow-hidden flex flex-col">
                                                        <div class="p-3 border-b bg-amber-50 flex justify-between items-center shrink-0">
                                                            <span class="text-xs font-black text-amber-800 uppercase tracking-widest">Internal Notes</span>
                                                            <button @click="showInternalNotesPopover = false" class="text-amber-400 hover:text-amber-600">
                                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                                                            </button>
                                                        </div>
                                                        
                                                        <!-- Notes List (Resizable) -->
                                                        <div class="resize-y overflow-y-auto min-h-[200px] max-h-[60vh] p-2 space-y-2 bg-gray-50/30 custom-scrollbar">
                                                            <div v-for="note in internalNotes" :key="note.id" class="p-2.5 bg-white border border-gray-100 rounded-lg shadow-sm">
                                                                <div class="flex justify-between items-start mb-1">
                                                                    <span class="text-[10px] font-black text-gray-700 uppercase">{{ note.user?.name }}</span>
                                                                    <span class="text-[9px] text-gray-400">{{ formatDate(parseDate(note.created_at)) }}</span>
                                                                </div>
                                                                <p class="text-xs text-gray-600 whitespace-pre-wrap leading-relaxed">{{ note.comment_text }}</p>
                                                                
                                                                <!-- Note Attachments (Images Only) -->
                                                                <div v-if="note.attachments && note.attachments.length > 0" class="mt-2 flex flex-wrap gap-1">
                                                                    <a 
                                                                        v-for="attachment in note.attachments" 
                                                                        :key="attachment.id"
                                                                        :href="getThumbnailUrl(attachment)"
                                                                        target="_blank"
                                                                        class="block w-12 h-12 border border-gray-200 rounded overflow-hidden hover:opacity-80 transition-opacity"
                                                                    >
                                                                        <img :src="getThumbnailUrl(attachment)" class="w-full h-full object-cover" :alt="attachment.file_name">
                                                                    </a>
                                                                </div>
                                                            </div>
                                                            <div v-if="internalNotes.length === 0" class="py-10 text-center text-gray-400 text-xs italic">
                                                                No internal notes yet.
                                                            </div>
                                                        </div>

                                                        <!-- Add Note Form -->
                                                        <div class="p-3 border-t bg-white">
                                                            <!-- Selected Images Preview -->
                                                            <div v-if="noteForm.attachments.length > 0" class="flex flex-wrap gap-2 mb-2">
                                                                <div v-for="(attachment, index) in noteForm.attachments" :key="attachment.id" class="relative group w-12 h-12">
                                                                    <video v-if="isVideo(attachment.file_name)" 
                                                                           :src="attachment.preview" 
                                                                           class="w-full h-full object-cover rounded border border-amber-200" 
                                                                           muted></video>
                                                                    <img v-else :src="attachment.preview" class="w-full h-full object-cover rounded border border-amber-200" :alt="attachment.file_name">
                                                                    <!-- Video Play Icon Overlay (Mini) -->
                                                                    <div v-if="isVideo(attachment.file_name)" class="absolute inset-0 flex items-center justify-center bg-black/10 rounded">
                                                                        <svg class="w-4 h-4 text-white" fill="currentColor" viewBox="0 0 20 20"><path d="M10 18a8 8 0 100-16 8 8 0 000 16zM9.555 7.168A1 1 0 008 8v4a1 1 0 001.555.832l3-2a1 1 0 000-1.664l-3-2z" /></svg>
                                                                    </div>
                                                                    <button type="button" @click="removeNoteAttachment(index)" class="absolute -top-1 -right-1 bg-red-500 text-white rounded-full p-0.5 shadow-sm hover:bg-red-600">
                                                                        <svg class="w-2.5 h-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                                                    </button>
                                                                </div>
                                                            </div>

                                                            <textarea 
                                                                v-model="noteForm.comment_text"
                                                                rows="2"
                                                                class="block w-full border-gray-200 rounded-lg text-xs focus:ring-amber-500 focus:border-amber-500 resize-none mb-3"
                                                                placeholder="Type an internal note..."
                                                                @paste="handleNotePaste"
                                                            ></textarea>

                                                            <div class="flex flex-col gap-3">
                                                                <div class="flex items-center justify-between">
                                                                    <div class="flex items-center">
                                                                        <input ref="noteFileInput" type="file" multiple accept="image/*,video/*" class="hidden" @change="handleNoteFileSelect">
                                                                        <button type="button" @click="noteFileInput.click()" class="p-1.5 text-amber-600 hover:text-amber-800 rounded-lg hover:bg-amber-50 transition-colors border border-transparent" title="Attach Media">
                                                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                                                            </svg>
                                                                        </button>
                                                                    </div>
                                                                </div>

                                                                <button 
                                                                    @click="saveInternalNote"
                                                                    :disabled="noteForm.processing || (!noteForm.comment_text.trim() && noteForm.attachments.length === 0)"
                                                                    class="w-full py-2 bg-amber-600 text-white text-[11px] font-black rounded-lg hover:bg-amber-700 disabled:opacity-50 uppercase tracking-widest transition-all shadow-md active:scale-95"
                                                                >
                                                                    <template v-if="noteForm.processing">
                                                                        <svg class="animate-spin -ml-1 mr-2 h-3 w-3 text-white inline-block" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 6.477 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                                                                        Saving...
                                                                    </template>
                                                                    <template v-else>
                                                                        Add Note
                                                                    </template>
                                                                </button>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="flex items-center w-full sm:w-auto">
                                                <div class="inline-flex rounded-lg shadow-md divide-x divide-blue-500 w-full sm:w-auto">
                                                        <button 
                                                        type="button" 
                                                        @click="submitWithStatus('')" 
                                                        :disabled="commentForm.processing || (!commentForm.comment_text.trim() && commentForm.attachments.length === 0)"
                                                        class="inline-flex items-center justify-center flex-1 sm:flex-none px-4 py-2 text-xs sm:text-sm font-black rounded-l-lg text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 disabled:opacity-50 transition-all active:transform active:scale-95 whitespace-nowrap uppercase tracking-widest"
                                                    >
                                                        <span v-if="commentForm.processing">Saving...</span>
                                                        <span v-else>Send as Response Only</span>
                                                    </button>
                                                    <div class="relative">
                                                        <button 
                                                            type="button"
                                                            @click="showStatusDropdown = !showStatusDropdown"
                                                            :disabled="commentForm.processing"
                                                            class="inline-flex items-center p-2 text-sm font-bold rounded-r-lg text-white bg-blue-600 hover:bg-blue-700 focus:outline-none transition-all"
                                                        >
                                                            <ChevronDownIcon class="w-5 h-5" />
                                                        </button>
                                                        <div v-if="showStatusDropdown" class="absolute bottom-full right-0 mb-2 w-64 bg-white rounded-lg shadow-xl border border-gray-200 z-50 overflow-hidden">
                                                            <div class="py-1">
                                                                <button v-for="s in availableStatuses" :key="s" @click="submitWithStatus(s)" :class="['w-full text-left px-4 py-2 text-[10px] font-black uppercase tracking-widest transition-colors hover:opacity-80', getStatusColor(s)]">
                                                                    Send and set as {{ getStatusLabel(s) }}
                                                                </button>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Create Child Ticket Modal -->
        <Modal :show="showChildModal" max-width="2xl" @close="showChildModal = false">
            <div class="p-4 sm:p-6">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-lg font-black text-gray-900 leading-none uppercase tracking-widest">
                        Create Child Ticket
                    </h3>
                    <button @click="showChildModal = false" class="text-gray-400 hover:text-gray-600">
                        <XMarkIcon class="w-6 h-6" />
                    </button>
                </div>

                <form @submit.prevent="submitChildTicket" class="space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Assigned User</label>
                            <Autocomplete v-model="childForm.user_id" :options="staff" label-key="name" value-key="id" placeholder="Select user..." />
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Location{{ isChildLocationRequired ? '' : ' (Optional)' }}</label>
                            <Autocomplete
                                v-model="childForm.store_id"
                                :options="childStoreOptions"
                                label-key="display_name"
                                value-key="id"
                                :placeholder="isChildLocationRequired ? 'Select store...' : 'Select store if needed...'"
                            />
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Schedule Status</label>
                            <select v-model="childForm.status" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
                                <option v-for="status in scheduleStatuses" :key="status" :value="status">{{ status }}</option>
                            </select>
                        </div>
                        <div class="md:col-span-2">
                            <label class="inline-flex items-center gap-2 cursor-pointer">
                                <input v-model="childForm.set_schedule" type="checkbox" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                <span class="text-xs font-bold text-gray-700 uppercase tracking-wider">Set schedule times</span>
                            </label>
                            <p class="text-[10px] text-gray-500 mt-1">Uncheck to create the child ticket without a fixed schedule (can be scheduled later).</p>
                        </div>
                        <div v-if="childForm.set_schedule">
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Start Time</label>
                            <input v-model="childForm.start_time" type="datetime-local" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
                        </div>
                        <div v-if="childForm.set_schedule">
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">End Time</label>
                            <input v-model="childForm.end_time" type="datetime-local" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
                        </div>
                    </div>

                    <div v-if="childForm.set_schedule" class="p-4 bg-gray-50 rounded-xl space-y-4 border border-gray-100">
                        <h4 class="text-xs font-bold text-gray-500 uppercase tracking-wider">Additional Times</h4>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="space-y-2">
                                <label class="block text-xs font-medium text-gray-600">Pickup Time (From - To)</label>
                                <div class="flex items-center space-x-2">
                                    <input v-model="childForm.pickup_start" type="time" class="flex-1 px-3 py-1.5 border border-gray-300 rounded-lg text-sm">
                                    <span class="text-gray-400">-</span>
                                    <input v-model="childForm.pickup_end" type="time" class="flex-1 px-3 py-1.5 border border-gray-300 rounded-lg text-sm">
                                </div>
                            </div>
                            <div class="space-y-2">
                                <label class="block text-xs font-medium text-gray-600">Backlogs Time (From - To)</label>
                                <div class="flex items-center space-x-2">
                                    <input v-model="childForm.backlogs_start" type="time" class="flex-1 px-3 py-1.5 border border-gray-300 rounded-lg text-sm">
                                    <span class="text-gray-400">-</span>
                                    <input v-model="childForm.backlogs_end" type="time" class="flex-1 px-3 py-1.5 border border-gray-300 rounded-lg text-sm">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Remarks</label>
                        <textarea v-model="childForm.remarks" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" placeholder="Activity details..."></textarea>
                    </div>

                    <div class="flex flex-col-reverse sm:flex-row justify-end gap-3 pt-4 border-t">
                        <button type="button" @click="showChildModal = false" class="px-4 py-2 text-sm font-bold text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors uppercase tracking-widest">
                            Cancel
                        </button>
                        <button type="submit" class="px-6 py-2 text-sm font-black text-white bg-blue-600 rounded-lg hover:bg-blue-700 shadow-md transition-all active:scale-95 uppercase tracking-widest">
                            Create Ticket
                        </button>
                    </div>
                </form>
            </div>
        </Modal>

        <!-- Assign Schedule Modal (for existing child ticket without schedule) -->
        <Modal :show="showAssignScheduleModal" max-width="2xl" @close="showAssignScheduleModal = false">
            <div class="p-6">
                <div class="flex justify-between items-center mb-4 pb-3 border-b">
                    <h3 class="text-base font-black text-gray-900 uppercase tracking-widest">
                        {{ assignScheduleMode === 'edit' ? 'Edit Schedule' : 'Assign Schedule' }}
                    </h3>
                    <button @click="showAssignScheduleModal = false" class="text-gray-400 hover:text-gray-600">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                    </button>
                </div>

                <form @submit.prevent="submitAssignSchedule" class="space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Assigned User</label>
                            <Autocomplete v-model="assignScheduleForm.user_id" :options="staff" label-key="name" value-key="id" placeholder="Select user..." />
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Location{{ isAssignLocationRequired ? '' : ' (Optional)' }}</label>
                            <Autocomplete
                                v-model="assignScheduleForm.store_id"
                                :options="assignStoreOptions"
                                label-key="display_name"
                                value-key="id"
                                :placeholder="isAssignLocationRequired ? 'Select store...' : 'Select store if needed...'"
                            />
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Schedule Status</label>
                            <select v-model="assignScheduleForm.status" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
                                <option v-for="status in scheduleStatuses" :key="status" :value="status">{{ status }}</option>
                            </select>
                        </div>
                        <div></div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Start Time</label>
                            <input v-model="assignScheduleForm.start_time" type="datetime-local" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">End Time</label>
                            <input v-model="assignScheduleForm.end_time" type="datetime-local" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
                        </div>
                    </div>

                    <div class="p-4 bg-gray-50 rounded-xl space-y-4 border border-gray-100">
                        <h4 class="text-xs font-bold text-gray-500 uppercase tracking-wider">Additional Times</h4>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="space-y-2">
                                <label class="block text-xs font-medium text-gray-600">Pickup Time (From - To)</label>
                                <div class="flex items-center space-x-2">
                                    <input v-model="assignScheduleForm.pickup_start" type="time" class="flex-1 px-3 py-1.5 border border-gray-300 rounded-lg text-sm">
                                    <span class="text-gray-400">-</span>
                                    <input v-model="assignScheduleForm.pickup_end" type="time" class="flex-1 px-3 py-1.5 border border-gray-300 rounded-lg text-sm">
                                </div>
                            </div>
                            <div class="space-y-2">
                                <label class="block text-xs font-medium text-gray-600">Backlogs Time (From - To)</label>
                                <div class="flex items-center space-x-2">
                                    <input v-model="assignScheduleForm.backlogs_start" type="time" class="flex-1 px-3 py-1.5 border border-gray-300 rounded-lg text-sm">
                                    <span class="text-gray-400">-</span>
                                    <input v-model="assignScheduleForm.backlogs_end" type="time" class="flex-1 px-3 py-1.5 border border-gray-300 rounded-lg text-sm">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Remarks</label>
                        <textarea v-model="assignScheduleForm.remarks" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" placeholder="Schedule notes..."></textarea>
                    </div>

                    <div class="flex flex-col-reverse sm:flex-row justify-end gap-3 pt-4 border-t">
                        <button type="button" @click="showAssignScheduleModal = false" class="px-4 py-2 text-sm font-bold text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors uppercase tracking-widest">
                            Cancel
                        </button>
                        <button type="submit" :disabled="assignScheduleForm.processing" :class="['px-6 py-2 text-sm font-black text-white rounded-lg shadow-md transition-all active:scale-95 uppercase tracking-widest disabled:opacity-60', assignScheduleMode === 'edit' ? 'bg-blue-600 hover:bg-blue-700' : 'bg-amber-600 hover:bg-amber-700']">
                            {{ assignScheduleMode === 'edit' ? 'Save Changes' : 'Assign Schedule' }}
                        </button>
                    </div>
                </form>
            </div>
        </Modal>

        <!-- Image Viewer Modal -->
        <Modal :show="showImageViewer" max-width="4xl" @close="closeImageViewer">
            <div class="relative bg-black rounded-lg overflow-hidden h-[80vh] flex flex-col">
                <!-- Toolbar -->
                <div class="absolute top-0 left-0 right-0 z-10 flex justify-between items-center p-4 bg-gradient-to-b from-black/50 to-transparent">
                    <h3 class="text-white text-xs sm:text-sm font-medium truncate ml-2 text-shadow">{{ currentImage?.file_name }}</h3>
                    <div class="flex items-center space-x-2">
                        <button @click="handleZoom(-0.1)" class="p-1 sm:p-2 text-white hover:bg-white/20 rounded-full backdrop-blur-sm">
                            <svg class="w-5 h-5 sm:w-6 sm:h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4" /></svg>
                        </button>
                        <span class="text-white text-[10px] sm:text-sm font-mono w-8 sm:w-12 text-center">{{ Math.round(zoomLevel * 100) }}%</span>
                        <button @click="handleZoom(0.1)" class="p-1 sm:p-2 text-white hover:bg-white/20 rounded-full backdrop-blur-sm">
                            <svg class="w-5 h-5 sm:w-6 sm:h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" /></svg>
                        </button>
                        <div class="w-px h-6 bg-white/30 mx-1 sm:mx-2"></div>
                        <a
                            v-if="currentImage && getAttachmentDownloadUrl(currentImage)"
                            :href="getAttachmentDownloadUrl(currentImage)"
                            class="p-1 sm:p-2 text-white hover:bg-white/20 rounded-full backdrop-blur-sm"
                            title="Download attachment"
                            aria-label="Download attachment"
                            @click.stop
                        >
                            <ArrowDownTrayIcon class="w-5 h-5 sm:w-6 sm:h-6" />
                        </a>
                        <button @click="closeImageViewer" class="p-1 sm:p-2 text-white hover:bg-red-500/80 rounded-full backdrop-blur-sm transition-colors">
                            <svg class="w-5 h-5 sm:w-6 sm:h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>
                </div>

                <!-- Image Container -->
                <div class="flex-grow flex items-center justify-center overflow-hidden cursor-move p-4 relative" 
                     @mousedown.prevent="isDragging = true" 
                     @mouseup="isDragging = false" 
                     @mouseleave="isDragging = false"
                     @mousemove="isDragging && (panOffset.x += $event.movementX, panOffset.y += $event.movementY)"
                     @wheel.prevent="handleWheel">
                    
                    <!-- Navigation Arrows -->
                    <button v-if="allMedia.length > 1" @click.stop="navigateMedia(-1)" class="absolute left-2 sm:left-4 top-1/2 transform -translate-y-1/2 p-2 sm:p-3 text-white/70 hover:text-white bg-black/20 hover:bg-black/40 rounded-full backdrop-blur-sm transition-all z-20">
                        <svg class="w-6 h-6 sm:w-8 sm:h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" /></svg>
                    </button>
                    <button v-if="allMedia.length > 1" @click.stop="navigateMedia(1)" class="absolute right-2 sm:right-4 top-1/2 transform -translate-y-1/2 p-2 sm:p-3 text-white/70 hover:text-white bg-black/20 hover:bg-black/40 rounded-full backdrop-blur-sm transition-all z-20">
                        <svg class="w-6 h-6 sm:w-8 sm:h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
                    </button>

                    <template v-if="currentImage">
                        <video v-if="isVideo(currentImage.file_name)" 
                               :src="getThumbnailUrl(currentImage)" 
                               controls 
                               class="max-h-full max-w-full shadow-2xl"
                               @click.stop></video>
                        <img v-else 
                             :src="getThumbnailUrl(currentImage)" 
                             class="transition-transform duration-100 ease-linear transform origin-center max-w-none shadow-2xl" 
                             :style="{ transform: `scale(${zoomLevel}) translate(${panOffset.x / zoomLevel}px, ${panOffset.y / zoomLevel}px)` }" 
                             draggable="false">
                    </template>
                </div>
            </div>
        </Modal>

        <!-- Resolution Details Modal -->
        <Modal :show="showResolutionModal" max-width="2xl" @close="showResolutionModal = false">
            <div class="p-4 sm:p-6">
                <div class="flex justify-between items-center mb-6">
                    <div>
                        <h3 class="text-lg font-black text-gray-900 leading-none uppercase tracking-widest">
                            {{ commentForm.status === 'closed' ? 'Close Ticket' : 'Resolve Ticket' }}
                        </h3>
                        <p class="text-xs text-gray-500 mt-1">Please provide the details of the resolution.</p>
                    </div>
                    <button @click="showResolutionModal = false" class="text-gray-400 hover:text-gray-600">
                        <XMarkIcon class="w-6 h-6" />
                    </button>
                </div>

                <div class="space-y-6">
                    <div v-if="commentForm.status === 'closed'" class="rounded-xl border border-blue-100 bg-blue-50 px-4 py-3 text-xs text-blue-800">
                        Closing this ticket will create a draft Knowledge Base article under the selected Item bucket unless an existing draft or published article already covers the same concern and resolution.
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-gray-700 uppercase tracking-widest mb-2 flex items-center">
                            Action Taken
                            <span class="ml-1 text-red-500">*</span>
                        </label>
                        <textarea
                            v-model="commentForm.action_taken"
                            rows="4"
                            class="block w-full rounded-xl border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm"
                            placeholder="Describe what was done to resolve the issue..."
                        ></textarea>
                    </div>

                    <div v-if="requiresRcaOnResolve">
                        <label class="block text-xs font-bold text-gray-700 uppercase tracking-widest mb-2 flex items-center">
                            Root Cause Analysis (RCA)
                            <span class="ml-1 text-red-500">*</span>
                        </label>
                        <textarea
                            v-model="commentForm.root_cause_analysis"
                            rows="4"
                            class="block w-full rounded-xl border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm"
                            placeholder="Explain the root cause for this issue..."
                        ></textarea>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-gray-700 uppercase tracking-widest mb-2">
                            Resolution Note (Optional)
                        </label>
                        <textarea
                            v-model="commentForm.comment_text"
                            rows="3"
                            class="block w-full rounded-xl border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm"
                            placeholder="Any final comments to the requester..."
                        ></textarea>
                    </div>

                    <div class="flex flex-col-reverse sm:flex-row justify-end gap-3 pt-6 border-t">
                        <button type="button" @click="showResolutionModal = false" class="px-4 py-2 text-sm font-bold text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors uppercase tracking-widest">
                            Cancel
                        </button>
                        <button 
                            @click="submitResolution"
                            :disabled="commentForm.processing"
                            class="px-8 py-2 text-sm font-black text-white bg-green-600 rounded-lg hover:bg-green-700 shadow-md transition-all active:scale-95 uppercase tracking-widest disabled:opacity-50"
                        >
                            <template v-if="commentForm.processing">{{ commentForm.status === 'closed' ? 'Closing...' : 'Resolving...' }}</template>
                            <template v-else>{{ commentForm.status === 'closed' ? 'Close Ticket' : 'Submit Resolution' }}</template>
                        </button>
                    </div>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>

<style scoped>
.scrollbar-hide::-webkit-scrollbar { display: none; }
.scrollbar-hide { -ms-overflow-style: none; scrollbar-width: none; }

@keyframes bounce-short {
  0%, 100% { transform: translateY(0); }
  50% { transform: translateY(-3px); }
}
.animate-bounce-short {
  animation: bounce-short 1s ease-in-out infinite;
}
</style>
