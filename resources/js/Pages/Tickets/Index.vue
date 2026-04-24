<script setup>
import { Head, Link, useForm, usePage, router } from '@inertiajs/vue3';
import { ref, reactive, onMounted, watch, computed } from 'vue';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import DataTable from '@/Components/DataTable.vue';
import Autocomplete from '@/Components/Autocomplete.vue';
import { useConfirm } from '@/Composables/useConfirm';
import { useErrorHandler } from '@/Composables/useErrorHandler';
import { useToast } from '@/Composables/useToast';
import { usePagination } from '@/Composables/usePagination';
import { usePermission } from '@/Composables/usePermission';
import { useDateFormatter } from '@/Composables/useDateFormatter';

const props = defineProps({
    tickets: Object,
    staff: Array,
    companies: Array,
    stores: Array,
    vendors: Array,
    filters: Object,
    departments: Array,
    sub_units: Array,
});

const page = usePage();
const showCreateModal = ref(false);
const showAcceptModal = ref(false);
const acceptingTicket = ref(null);
const fileInput = ref(null);
const isSubmitting = ref(false);
const { confirm } = useConfirm();
const { post, put, destroy } = useErrorHandler();
const { showSuccess, showError } = useToast();
const { hasPermission } = usePermission();
const { formatDate } = useDateFormatter();

// Real-time clock for SLA calculations
const currentTime = ref(new Date());
let timer;

onMounted(() => {
    pagination.updateData(props.tickets);
    timer = setInterval(() => {
        currentTime.value = new Date();
    }, 60000); // Update every minute
});

import { onUnmounted } from 'vue';
onUnmounted(() => {
    if (timer) clearInterval(timer);
});

const isNearlyDue = (targetAt) => {
    if (!targetAt) return false;
    const target = new Date(targetAt);
    const diff = target - currentTime.value;
    // Nearly due = less than 1 hour (3600000 ms) and not yet past
    return diff > 0 && diff < 3600000;
};

const isUserRole = computed(() => {
    const user = page.props.auth.user;
    return user?.roles?.some(role => role.name === 'User') ?? false;
});

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

// Determine default company ID
const defaultCompanyId = computed(() => {
    const user = page.props.auth.user;
    if (!user) return '';

    // If user has a direct company_id and it's in the available list, use it
    if (user.company_id && availableCompanies.value.some(c => c.id === user.company_id)) {
        return user.company_id;
    }

    // Otherwise, use the first available company
    return availableCompanies.value.length > 0 ? availableCompanies.value[0].id : '';
});

const filterStatus = ref(props.filters?.status || (isUserRole.value ? 'all' : 'open'));
const filterSubUnit = ref(props.filters?.sub_unit || '');
const filterAssignee = ref(props.filters?.assignee_id || '');
const filterStartDate = ref(props.filters?.start_date || '');
const filterEndDate = ref(props.filters?.end_date || '');

const filterOptions = [
    { value: 'all', label: 'All' },
    { value: 'my_tickets', label: 'My Tickets' },
    { value: 'open', label: 'Open' },
    { value: 'for_schedule', label: 'For Schedule' },
    { value: 'in_progress', label: 'In Progress' },
    { value: 'resolved', label: 'Resolved' },
    { value: 'waiting_service_provider', label: 'Waiting for service provider' },
    { value: 'waiting_client_feedback', label: 'Waiting for clients feedback?' },
    { value: 'closed', label: 'Closed' },
    { value: 'unassigned', label: 'Unassigned' },
];

const statusOptions = computed(() => {
    return filterOptions.map(opt => ({ id: opt.value, name: opt.label }));
});

const subUnitOptions = computed(() => {
    return [
        { id: '', name: 'All Sub-Units' },
        ...(props.sub_units || []).map(u => ({ id: u, name: u }))
    ];
});

const assigneeOptions = computed(() => {
    return [
        { id: '', name: 'All Assignees' },
        ...(props.staff || []).map(s => ({ id: s.id, name: s.name }))
    ];
});

const pagination = usePagination(props.tickets, 'tickets.index', () => ({ 
    status: filterStatus.value,
    sub_unit: filterSubUnit.value,
    assignee_id: filterAssignee.value,
    start_date: filterStartDate.value,
    end_date: filterEndDate.value,
}));

const subUnits = computed(() => {
    return props.sub_units || [];
});

const applyFilter = () => {
    router.get(route('tickets.index'), {
        status: filterStatus.value,
        sub_unit: filterSubUnit.value,
        assignee_id: filterAssignee.value,
        start_date: filterStartDate.value,
        end_date: filterEndDate.value,
        search: pagination.search.value
    }, {
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => {
            pagination.updateData(page.props.tickets);
        }
    });
};

const clearFilters = () => {
    filterStatus.value = isUserRole.value ? 'all' : 'open';
    filterSubUnit.value = '';
    filterAssignee.value = '';
    filterStartDate.value = '';
    filterEndDate.value = '';
    pagination.search.value = '';
    applyFilter();
};

watch(() => props.tickets, (newTickets) => {
    pagination.updateData(newTickets);
}, { deep: true });

const acceptForm = useForm({
    company_id: '',
    store_id: '',
    item_id: '',
    department: '',
});

const createForm = useForm({
    company_id: '',
    store_id: '',
    item_id: '',
    vendor_id: null,
    title: '',
    description: '',
    type: 'task',
    priority: 'medium',
    status: 'open',
    severity: 'minor',
    assignee_id: '',
    attachments: [],
    is_self_requester: true,
    sender_name: '',
    sender_email: '',
    department: page.props.auth.user?.department || '',
    notify_requester: true,
});

const items = ref([]);

const fetchItems = async () => {
    try {
        const response = await axios.get(route('tickets.data.items', undefined, false));
        items.value = response.data;
    } catch (error) {
        console.error('Error fetching items:', error);
    }
};

watch(() => createForm.item_id, (newVal) => {
    if (newVal) {
        const item = items.value.find(i => i.id === newVal);
        if (item) {
            createForm.priority = item.priority.toLowerCase();
        }
    }
});

// Set default company when modal opens or companies load
watch(() => showCreateModal.value, (isOpen) => {
    if (isOpen && !createForm.company_id) {
        createForm.company_id = defaultCompanyId.value;
    }
    if (isOpen && items.value.length === 0) {
        fetchItems();
    }
});

watch(() => showAcceptModal.value, (isOpen) => {
    if (isOpen && items.value.length === 0) {
        fetchItems();
    }
});

// Auto-populate department from auth user when "I am the requester" is toggled
watch(() => createForm.is_self_requester, (isSelf) => {
    createForm.department = isSelf ? (page.props.auth.user?.department || '') : '';
});

// Also watch defaultCompanyId in case it loads later
watch(defaultCompanyId, (newId) => {
    if (!createForm.company_id) {
        createForm.company_id = newId;
    }
}, { immediate: true });

// ── Bulk Selection ────────────────────────────────────────────────────────
const selectedIds = ref([])
const activeDashboardFilter = ref('all')

const allSelected = computed(() =>
    displayedTickets.value.length > 0 &&
    displayedTickets.value.every(t => selectedIds.value.includes(t.id))
)

const toggleAll = () => {
    selectedIds.value = allSelected.value ? [] : displayedTickets.value.map(t => t.id)
}

watch(() => [pagination.currentPage.value, pagination.search.value], () => {
    selectedIds.value = []
})

const storesWithLabel = computed(() =>
    props.stores.map(s => ({ ...s, display_name: `${s.code} - ${s.name}` }))
)

// ── Bulk Form ─────────────────────────────────────────────────────────────
const bulkForm = reactive({
    store_id: '', item_id: '', assignee_id: ''
})
const isBulkSubmitting = ref(false)

const showSplitModal = ref(false);
const showMergeModal = ref(false);
const showBulkChildModal = ref(false);

const bulkChildForm = useForm({
    tickets: [], // Array of individual ticket schedule data
});

const openBulkChildModal = () => {
    if (selectedIds.value.length === 0) return;
    bulkChildForm.reset();
    
    // Set default times
    const start = new Date();
    start.setHours(7, 0, 0, 0);
    const startTimeStr = formatDateForInput(start);
    
    const end = new Date(start);
    end.setHours(17, 0, 0, 0);
    const endTimeStr = formatDateForInput(end);

    const selectedTickets = pagination.data.value.filter(t => selectedIds.value.includes(t.id));
    
    bulkChildForm.tickets = selectedTickets.map(t => ({
        parent_id: t.id,
        ticket_key: t.ticket_key,
        title: t.title,
        user_id: '',
        status: 'On-site',
        start_time: startTimeStr,
        end_time: endTimeStr,
        pickup_start: '',
        pickup_end: '',
        backlogs_start: '',
        backlogs_end: '',
        remarks: '',
    }));
    
    showBulkChildModal.value = true;
};

const submitBulkChild = () => {
    bulkChildForm.post(route('tickets.bulk-child'), {
        onSuccess: () => {
            showBulkChildModal.value = false;
            selectedIds.value = [];
            showSuccess('Bulk child tickets and schedules created successfully');
        },
        onError: (errors) => {
            const errorMessage = Object.values(errors).flat().join(', ') || 'Bulk child creation failed';
            showError(errorMessage);
        }
    });
};

const formatDateForInput = (date) => {
    const d = new Date(date);
    d.setMinutes(d.getMinutes() - d.getTimezoneOffset());
    return d.toISOString().slice(0, 16);
};

const splitForm = useForm({
    original_title: '',
    new_titles: [''],
});

const mergeForm = useForm({
    parent_id: '',
    ticket_ids: [],
});

const openSplitModal = () => {
    if (selectedIds.value.length !== 1) return;
    const ticket = pagination.data.value.find(t => t.id === selectedIds.value[0]);
    if (!ticket) return;
    
    splitForm.original_title = ticket.title;
    splitForm.new_titles = [''];
    showSplitModal.value = true;
};

const addSplitConcern = () => splitForm.new_titles.push('');
const removeSplitConcern = (index) => splitForm.new_titles.splice(index, 1);

const submitSplit = () => {
    const ticketId = selectedIds.value[0];
    splitForm.transform((data) => ({
        original_title: data.original_title,
        new_titles: data.new_titles.filter(t => t.trim() !== ''),
    })).post(route('tickets.split', ticketId), {
        onSuccess: () => {
            showSplitModal.value = false;
            selectedIds.value = [];
            showSuccess('Ticket split successfully');
        },
        onError: (errors) => showError(Object.values(errors).flat().join(', ') || 'Split failed')
    });
};

const openMergeModal = () => {
    if (selectedIds.value.length < 2) return;
    mergeForm.ticket_ids = [...selectedIds.value];
    mergeForm.parent_id = selectedIds.value[0];
    showMergeModal.value = true;
};

const submitMerge = () => {
    mergeForm.post(route('tickets.merge'), {
        onSuccess: () => {
            showMergeModal.value = false;
            selectedIds.value = [];
            showSuccess('Tickets merged successfully');
        },
        onError: (errors) => showError(Object.values(errors).flat().join(', ') || 'Merge failed')
    });
};

const getSelectedTickets = computed(() => {
    return pagination.data.value.filter(t => selectedIds.value.includes(t.id));
});

watch(() => selectedIds.value.length > 0, (visible) => {
    if (visible && items.value.length === 0) {
        fetchItems();
    }
})

const submitBulk = () => {
    if (!selectedIds.value.length || isBulkSubmitting.value) return
    isBulkSubmitting.value = true
    const payload = { ticket_ids: selectedIds.value }
    if (bulkForm.store_id)        payload.store_id        = bulkForm.store_id
    if (bulkForm.item_id)         payload.item_id         = bulkForm.item_id
    if (bulkForm.assignee_id)     payload.assignee_id     = bulkForm.assignee_id

    post(route('tickets.bulk-update'), payload, {
        onSuccess: () => {
            selectedIds.value = []
            Object.keys(bulkForm).forEach(k => bulkForm[k] = '')
        },
        onError: (errors) => showError(Object.values(errors).flat().join(', ') || 'Bulk update failed'),
        onFinish: () => { isBulkSubmitting.value = false }
    })
}

const priorities = ['low', 'medium', 'high', 'urgent'];
const statuses = ['open', 'for_schedule', 'in_progress', 'resolved', 'closed', 'waiting_service_provider', 'waiting_client_feedback'];

const handleFileSelect = (event) => {
    const files = Array.from(event.target.files);
    const maxSize = 50 * 1024 * 1024; // 50MB
    const oversizedFiles = files.filter(file => file.size > maxSize);

    if (oversizedFiles.length > 0) {
        showError(`The following files exceed the 50MB limit: ${oversizedFiles.map(f => f.name).join(', ')}`);
        event.target.value = '';
        createForm.attachments = [];
        return;
    }

    createForm.attachments = files;
};

const createTicket = () => {
    if (isSubmitting.value) return;
    isSubmitting.value = true;

    const formData = new FormData();
    Object.keys(createForm.data()).forEach(key => {
        if (key === 'attachments') {
            createForm.attachments.forEach((file, index) => {
                formData.append(`attachments[${index}]`, file);
            });
        } else {
            let value = createForm[key];
            if (typeof value === 'boolean') {
                value = value ? 1 : 0;
            }
            if (value === null || value === undefined || value === '') {
                return; // skip — backend treats missing nullable fields as null
            }
            formData.append(key, value);
        }
    });
    
    post(route('tickets.store'), formData, {
        onSuccess: () => {
            showCreateModal.value = false;
            createForm.reset();
            if (fileInput.value) fileInput.value.value = '';
        },
        onError: (errors) => {
            const errorMessage = Object.values(errors).flat().join(', ') || 'An error occurred'
            showError(errorMessage)
        },
        onFinish: () => {
            isSubmitting.value = false;
        }
    });
};

const formatFileSize = (bytes) => {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
};

const editTicket = (ticket) => {
    if (!hasPermission('tickets.edit')) {
        showError('You do not have permission to edit this ticket.');
        return;
    }
    router.visit(route('tickets.edit', ticket.id));
};

const acceptTicket = (ticket) => {
    if (!hasPermission('tickets.assign')) {
        showError('You do not have permission to accept tickets.');
        return;
    }
    acceptingTicket.value = ticket;
    acceptForm.company_id = ticket.company_id || '';
    acceptForm.store_id = ticket.store_id || '';
    acceptForm.item_id = ticket.item_id || '';
    acceptForm.department = ticket.department || '';
    showAcceptModal.value = true;
};

const submitAcceptTicket = () => {
    if (!acceptingTicket.value) return;
    const ticket = acceptingTicket.value;

    const item = items.value.find(i => i.id == acceptForm.item_id);
    const priority = item ? item.priority.toLowerCase() : (ticket.priority || 'medium');

    put(route('tickets.update', ticket.id), {
        company_id: acceptForm.company_id,
        store_id: acceptForm.store_id,
        category_id: ticket.category_id,
        sub_category_id: ticket.sub_category_id,
        item_id: acceptForm.item_id,
        title: ticket.title,
        description: ticket.description,
        type: ticket.type,
        priority: priority,
        status: ticket.status,
        severity: ticket.severity,
        assignee_id: page.props.auth.user.id,
        department: acceptForm.department,
    }, {
        onSuccess: () => {
            showAcceptModal.value = false;
            acceptingTicket.value = null;
            acceptForm.reset();
        },
        onError: (errors) => {
            const errorMessage = Object.values(errors).flat().join(', ') || 'Cannot accept ticket';
            showError(errorMessage);
        }
    });
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
    const p = String(priority || '').toLowerCase();
    switch (p) {
        case 'urgent': return 'border-red-500 text-black bg-white';
        case 'high': return 'border-orange-400 text-black bg-white';
        case 'medium': return 'border-yellow-400 text-black bg-white';
        case 'low': return 'border-green-500 text-black bg-white';
        default: return 'border-slate-300 text-black bg-white';
    }
};

const getPriorityBorder = (priority) => {
    return 'border-l-transparent';
};

const getStatusColor = (status) => {
    switch (status) {
        case 'open': return 'border-blue-500 text-black bg-white';
        case 'for_schedule': return 'border-teal-500 text-black bg-white';
        case 'in_progress': return 'border-violet-500 text-black bg-white';
        case 'resolved': return 'border-green-500 text-black bg-white';
        case 'closed': return 'border-slate-400 text-black bg-white';
        case 'waiting_service_provider': return 'border-orange-400 text-black bg-white';
        case 'waiting_client_feedback': return 'border-sky-500 text-black bg-white';
        default: return 'border-slate-300 text-black bg-white';
    }
};

const getStatusLabel = (status) => {
    switch (status) {
        case 'for_schedule': return 'For Schedule';
        case 'waiting_service_provider': return 'Waiting for service provider';
        case 'waiting_client_feedback': return 'Waiting for clients feedback?';
        default: return String(status || '').replace(/_/g, ' ');
    }
};

const getSlaRowClass = (ticket) => {
    if (!ticket.sla_metric) return 'border-l-transparent hover:bg-slate-50';
    
    const isBreached = ticket.sla_metric.is_response_breached || ticket.sla_metric.is_resolution_breached;
    const isAllMet = ticket.sla_metric.first_response_at && ticket.sla_metric.resolved_at;
    
    if (isBreached) return 'border-l-transparent hover:bg-slate-50';
    if (isAllMet) return 'border-l-transparent hover:bg-slate-50';

    const priority = ticket.item?.priority?.toLowerCase() || ticket.priority?.toLowerCase();

    return getPriorityBorder(priority) + ' hover:bg-slate-50';
};

const formatItemName = (item) => {
    if (!item) return '-';
    const cat = item.category?.name ?? 'N/A';
    const sub = item.sub_category?.name ?? 'N/A';
    return `${cat} | ${sub} | ${item.name}`;
};

const getReporterLabel = (ticket) => {
    if (ticket.reporter?.name) return ticket.reporter.name;
    if (ticket.sender_name) return ticket.sender_name;
    if (ticket.sender_email) return ticket.sender_email;
    return 'Unknown';
};

const hasBreachedSla = (ticket) => {
    return Boolean(ticket.sla_metric && (ticket.sla_metric.is_response_breached || ticket.sla_metric.is_resolution_breached));
};

const isTicketNearlyDue = (ticket) => {
    if (!ticket.sla_metric) return false;

    const responseNearlyDue = Boolean(
        ticket.sla_metric.response_target_at &&
        !ticket.sla_metric.first_response_at &&
        !ticket.sla_metric.is_response_breached &&
        isNearlyDue(ticket.sla_metric.response_target_at)
    );

    const resolutionNearlyDue = Boolean(
        ticket.sla_metric.resolution_target_at &&
        !ticket.sla_metric.resolved_at &&
        !ticket.sla_metric.is_resolution_breached &&
        isNearlyDue(ticket.sla_metric.resolution_target_at)
    );

    return responseNearlyDue || resolutionNearlyDue;
};

const isNewTicket = (ticket) => {
    return ticket?.status === 'open'
        && !ticket?.category_id
        && !ticket?.sub_category_id
        && !ticket?.item_id
        && !ticket?.assignee;
};

const summaryCards = computed(() => {
    const data = pagination.data.value || [];

    return [
        {
            key: 'new',
            filterKey: 'new',
            label: 'New',
            value: data.filter(isNewTicket).length,
            hint: 'Open, uncategorized, and unassigned',
            shellClass: 'border-purple-200 bg-purple-50/80',
            valueClass: 'text-purple-900',
            accentClass: 'bg-purple-600',
        },
        {
            key: 'unassigned',
            filterKey: 'unassigned',
            label: 'Unassigned',
            value: data.filter(ticket => !ticket.assignee).length,
            hint: 'Tickets waiting for ownership',
            shellClass: 'border-blue-200 bg-blue-50/80',
            valueClass: 'text-blue-900',
            accentClass: 'bg-blue-600',
        },
        {
            key: 'breached',
            filterKey: 'breached',
            label: 'SLA Breached',
            value: data.filter(hasBreachedSla).length,
            hint: 'Immediate follow-up required',
            shellClass: 'border-red-200 bg-red-50/80',
            valueClass: 'text-red-900',
            accentClass: 'bg-red-600',
        },
        {
            key: 'nearly_due',
            filterKey: 'due_soon',
            label: 'Due Soon',
            value: data.filter(isTicketNearlyDue).length,
            hint: 'Targets due within one hour',
            shellClass: 'border-amber-200 bg-amber-50/80',
            valueClass: 'text-amber-900',
            accentClass: 'bg-amber-500',
        },
        {
            key: 'in_progress',
            filterKey: 'in_progress',
            label: 'In Progress',
            value: data.filter(ticket => ticket.status === 'in_progress').length,
            hint: 'Actively being worked on',
            shellClass: 'border-violet-200 bg-violet-50/80',
            valueClass: 'text-violet-900',
            accentClass: 'bg-violet-600',
        },
    ];
});

const displayedTickets = computed(() => {
    const data = pagination.data.value || [];

    switch (activeDashboardFilter.value) {
        case 'new':
            return data.filter(isNewTicket);
        case 'unassigned':
            return data.filter(ticket => !ticket.assignee);
        case 'breached':
            return data.filter(hasBreachedSla);
        case 'due_soon':
            return data.filter(isTicketNearlyDue);
        case 'in_progress':
            return data.filter(ticket => ticket.status === 'in_progress');
        case 'all':
        default:
            return data;
    }
});

const getDashboardFilterLabel = (filterKey) => {
    switch (filterKey) {
        case 'new': return 'Quick Filter: New';
        case 'unassigned': return 'Quick Filter: Unassigned';
        case 'breached': return 'Quick Filter: SLA Breached';
        case 'due_soon': return 'Quick Filter: Due Soon';
        case 'in_progress': return 'Quick Filter: In Progress';
        default: return '';
    }
};

const toggleDashboardFilter = (filterKey) => {
    activeDashboardFilter.value = activeDashboardFilter.value === filterKey ? 'all' : filterKey;
};

const activeFilterBadges = computed(() => {
    const badges = [];

    if (filterStatus.value && filterStatus.value !== 'all') {
        badges.push(`Status: ${getStatusLabel(filterStatus.value)}`);
    }
    if (filterSubUnit.value) {
        badges.push(`Sub-Unit: ${filterSubUnit.value}`);
    }
    if (filterAssignee.value) {
        const assignee = props.staff?.find(staff => String(staff.id) === String(filterAssignee.value));
        badges.push(`Assignee: ${assignee?.name || filterAssignee.value}`);
    }
    if (filterStartDate.value) {
        badges.push(`From: ${filterStartDate.value}`);
    }
    if (filterEndDate.value) {
        badges.push(`To: ${filterEndDate.value}`);
    }
    if (pagination.search.value) {
        badges.push(`Search: ${pagination.search.value}`);
    }
    if (activeDashboardFilter.value !== 'all') {
        badges.push(getDashboardFilterLabel(activeDashboardFilter.value));
    }

    return badges;
});

const hasActiveFilters = computed(() => activeFilterBadges.value.length > 0);

const tableSubtitle = computed(() => {
    const visibleCount = displayedTickets.value?.length || 0;
    if (hasActiveFilters.value) {
        return `Focused monitoring for ${visibleCount} visible ticket${visibleCount === 1 ? '' : 's'}. Click a row to open details.`;
    }

    return 'Monitor queue health, SLA pressure, ownership, and ticket hierarchy. Click a row to open details.';
});

const emptyStateMessage = computed(() => {
    if (hasActiveFilters.value) {
        return 'No tickets match the current search or filters. Adjust the monitoring controls and try again.';
    }

    return 'No tickets are visible right now. Create a new ticket to start monitoring the queue.';
});

const getSlaState = (ticket, type) => {
    const metric = ticket.sla_metric;
    if (!metric) return null;

    const isResponse = type === 'response';
    const targetAt = isResponse ? metric.response_target_at : metric.resolution_target_at;
    const completedAt = isResponse ? metric.first_response_at : metric.resolved_at;
    const isBreached = isResponse ? metric.is_response_breached : metric.is_resolution_breached;

    if (!targetAt) {
        return {
            label: isResponse ? 'Response' : 'Resolution',
            value: 'No Target',
            toneClass: 'border-gray-300 bg-white text-black',
            dotClass: 'bg-gray-300',
        };
    }

    if (isBreached) {
        return {
            label: isResponse ? 'Response' : 'Resolution',
            value: 'Breached',
            toneClass: 'border-red-500 bg-white text-black',
            dotClass: 'bg-red-500',
        };
    }

    if (completedAt) {
        return {
            label: isResponse ? 'Response' : 'Resolution',
            value: 'Met',
            toneClass: 'border-emerald-500 bg-white text-black',
            dotClass: 'bg-emerald-500',
        };
    }

    if (isNearlyDue(targetAt)) {
        return {
            label: isResponse ? 'Response' : 'Resolution',
            value: 'Due Soon',
            toneClass: 'border-amber-500 bg-white text-black',
            dotClass: 'bg-amber-500',
        };
    }

    return {
        label: isResponse ? 'Response' : 'Resolution',
        value: 'Pending',
        toneClass: 'border-blue-500 bg-white text-black',
        dotClass: 'bg-blue-500',
    };
};

watch(activeDashboardFilter, () => {
    selectedIds.value = [];
});
</script>

<template>
    <Head title="Tickets - Help Desk" />

    <AppLayout>
        <template #header>
            Tickets
        </template>

        <div class="space-y-6">
            <section class="relative overflow-hidden rounded-3xl border border-slate-200 bg-gradient-to-br from-slate-950 via-slate-900 to-blue-950 px-5 py-6 text-white shadow-xl sm:px-6">
                <div class="absolute inset-0 bg-[radial-gradient(circle_at_top_right,rgba(96,165,250,0.25),transparent_28%),radial-gradient(circle_at_bottom_left,rgba(45,212,191,0.18),transparent_30%)]"></div>
                <div class="relative flex flex-col gap-6">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                        <div class="max-w-3xl space-y-3">
                            <div class="inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/10 px-3 py-1 text-[11px] font-bold uppercase tracking-[0.25em] text-blue-100">
                                Ticket Monitoring Console
                            </div>
                            <div class="space-y-2">
                                <h2 class="text-2xl font-black tracking-tight sm:text-3xl">Operate the queue around urgency, ownership, and SLA pressure.</h2>
                                <p class="max-w-2xl text-sm leading-6 text-slate-200 sm:text-[15px]">
                                    Use the controls below to isolate risk quickly, then act from the table without losing visibility of assignment gaps or approaching deadlines.
                                </p>
                            </div>
                        </div>
                        <div class="grid gap-3 text-sm text-slate-200 sm:grid-cols-2">
                            <div class="rounded-2xl border border-white/10 bg-white/10 p-4 backdrop-blur">
                                <div class="text-[10px] font-black uppercase tracking-[0.22em] text-slate-300">Monitoring Focus</div>
                                <div class="mt-2 text-lg font-bold text-white">Live queue triage</div>
                                <div class="mt-1 text-xs text-slate-300">Prioritize breached, due soon, and unassigned tickets first.</div>
                            </div>
                            <div class="rounded-2xl border border-white/10 bg-white/10 p-4 backdrop-blur">
                                <div class="text-[10px] font-black uppercase tracking-[0.22em] text-slate-300">Current Scope</div>
                                <div class="mt-2 text-lg font-bold text-white">{{ pagination.showingText.value }}</div>
                                <div class="mt-1 text-xs text-slate-300">Metrics below reflect the currently visible page results.</div>
                            </div>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-5">
                        <button
                            v-for="card in summaryCards"
                            :key="card.key"
                            type="button"
                            class="rounded-2xl border px-4 py-4 text-left shadow-sm backdrop-blur transition-all hover:-translate-y-0.5 hover:shadow-md"
                            :class="[
                                card.shellClass,
                                activeDashboardFilter === card.filterKey ? 'ring-2 ring-offset-2 ring-blue-500 ring-offset-slate-950' : ''
                            ]"
                            @click="toggleDashboardFilter(card.filterKey)"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <div class="text-[11px] font-black uppercase tracking-[0.22em] text-slate-500">{{ card.label }}</div>
                                    <div class="mt-3 text-3xl font-black tracking-tight" :class="card.valueClass">{{ card.value }}</div>
                                    <div class="mt-2 text-xs text-slate-500">
                                        {{ activeDashboardFilter === card.filterKey ? 'Showing matching tickets below' : card.hint }}
                                    </div>
                                </div>
                                <span class="mt-1 h-3 w-3 rounded-full shadow-sm" :class="card.accentClass"></span>
                            </div>
                        </button>
                    </div>
                </div>
            </section>

            <div class="sticky top-4 z-20 space-y-4">
                <div class="rounded-2xl border border-slate-200 bg-white/95 p-4 shadow-lg shadow-slate-200/60 backdrop-blur supports-[backdrop-filter]:bg-white/85">
                    <div class="flex flex-col gap-4 xl:flex-row xl:items-end">
                        <div class="grid flex-1 grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-5">
                            <div class="flex flex-col gap-1.5">
                                <label class="text-[10px] font-black uppercase tracking-[0.22em] text-slate-400">Status</label>
                                <Autocomplete
                                    v-model="filterStatus"
                                    :options="statusOptions"
                                    label-key="name"
                                    value-key="id"
                                    placeholder="Filter by status..."
                                    @update:modelValue="applyFilter"
                                />
                            </div>

                            <div v-if="subUnitOptions.length > 1" class="flex flex-col gap-1.5">
                                <label class="text-[10px] font-black uppercase tracking-[0.22em] text-slate-400">Sub-Unit</label>
                                <Autocomplete
                                    v-model="filterSubUnit"
                                    :options="subUnitOptions"
                                    label-key="name"
                                    value-key="id"
                                    placeholder="Filter by sub-unit..."
                                    @update:modelValue="applyFilter"
                                />
                            </div>

                            <div class="flex flex-col gap-1.5">
                                <label class="text-[10px] font-black uppercase tracking-[0.22em] text-slate-400">Assignee</label>
                                <Autocomplete
                                    v-model="filterAssignee"
                                    :options="assigneeOptions"
                                    label-key="name"
                                    value-key="id"
                                    placeholder="Filter by assignee..."
                                    @update:modelValue="applyFilter"
                                />
                            </div>

                            <div class="flex flex-col gap-1.5">
                                <label class="text-[10px] font-black uppercase tracking-[0.22em] text-slate-400">From</label>
                                <input
                                    v-model="filterStartDate"
                                    type="date"
                                    @change="applyFilter"
                                    class="h-[38px] rounded-lg border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                >
                            </div>

                            <div class="flex flex-col gap-1.5">
                                <label class="text-[10px] font-black uppercase tracking-[0.22em] text-slate-400">To</label>
                                <input
                                    v-model="filterEndDate"
                                    type="date"
                                    @change="applyFilter"
                                    class="h-[38px] rounded-lg border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                >
                            </div>
                        </div>

                        <div class="flex flex-wrap items-center gap-2 xl:justify-end">
                            <button
                                @click="clearFilters"
                                class="inline-flex h-[38px] items-center rounded-lg border border-slate-200 px-4 text-sm font-bold text-slate-600 transition-colors hover:bg-slate-50 hover:text-slate-800"
                            >
                                Reset
                            </button>

                            <button
                                v-if="hasPermission('tickets.create')"
                                @click="showCreateModal = true"
                                class="inline-flex h-[38px] items-center gap-2 rounded-lg bg-blue-600 px-4 text-sm font-bold text-white shadow-md transition-colors hover:bg-blue-700"
                            >
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                                </svg>
                                <span>Create Ticket</span>
                            </button>
                        </div>
                    </div>

                    <div class="mt-4 flex flex-col gap-3 border-t border-slate-100 pt-4 lg:flex-row lg:items-center lg:justify-between">
                        <div class="flex flex-wrap gap-2">
                            <span
                                v-if="!hasActiveFilters"
                                class="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-[11px] font-semibold text-slate-600"
                            >
                                No active monitoring filters
                            </span>
                            <span
                                v-for="badge in activeFilterBadges"
                                :key="badge"
                                class="inline-flex items-center rounded-full border border-blue-200 bg-blue-50 px-3 py-1 text-[11px] font-semibold text-blue-700"
                            >
                                {{ badge }}
                            </span>
                        </div>
                        <div class="text-xs font-medium text-slate-500">
                            Filters apply without changing existing ticket logic or workflow behavior.
                        </div>
                    </div>
                </div>

                <Transition
                    enter-active-class="transition ease-out duration-200"
                    enter-from-class="opacity-0 -translate-y-2"
                    enter-to-class="opacity-100 translate-y-0"
                    leave-active-class="transition ease-in duration-150"
                    leave-from-class="opacity-100 translate-y-0"
                    leave-to-class="opacity-0 -translate-y-2"
                >
                    <div
                        v-if="selectedIds.length > 0"
                        class="rounded-2xl border border-blue-200 bg-blue-50/95 p-4 shadow-lg shadow-blue-100/60 backdrop-blur supports-[backdrop-filter]:bg-blue-50/90"
                    >
                        <div class="grid grid-cols-1 gap-4 xl:grid-cols-[240px_minmax(0,1fr)_auto] xl:items-end">
                            <div class="rounded-2xl border border-blue-200 bg-white/80 px-4 py-3 h-full">
                                <div class="text-[10px] font-black uppercase tracking-[0.22em] text-blue-500">Bulk Selection</div>
                                <div class="mt-2 text-2xl font-black text-blue-900">{{ selectedIds.length }}</div>
                                <div class="mt-1 text-xs text-blue-700">Selected ticket(s) ready for update, split, merge, or child creation.</div>
                            </div>

                            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                                <div class="flex flex-col gap-1.5">
                                    <label class="text-[10px] font-black uppercase tracking-[0.22em] text-blue-500">Store</label>
                                    <Autocomplete
                                        v-model="bulkForm.store_id"
                                        :options="storesWithLabel"
                                        label-key="display_name"
                                        value-key="id"
                                        placeholder="Unchanged..."
                                    />
                                </div>

                                <div class="flex flex-col gap-1.5">
                                    <label class="text-[10px] font-black uppercase tracking-[0.22em] text-blue-500">Item</label>
                                    <Autocomplete
                                        v-model="bulkForm.item_id"
                                        :options="items"
                                        label-key="display_name"
                                        value-key="id"
                                        placeholder="Unchanged..."
                                        size="sm"
                                    />
                                </div>

                                <div class="flex flex-col gap-1.5">
                                    <label class="text-[10px] font-black uppercase tracking-[0.22em] text-blue-500">Assignee</label>
                                    <select
                                        v-model="bulkForm.assignee_id"
                                        class="min-w-[140px] rounded-lg border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500"
                                    >
                                        <option value="">-- Unchanged --</option>
                                        <option v-for="p in staff" :key="p.id" :value="p.id">{{ p.name }}</option>
                                    </select>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 xl:w-[340px]">
                                <button
                                    v-if="selectedIds.length > 0 && hasPermission('tickets.edit')"
                                    @click="openBulkChildModal"
                                    class="inline-flex min-h-[42px] items-center justify-center gap-2 rounded-lg border border-teal-300 bg-white px-3 py-2 text-sm font-semibold text-teal-700 transition-colors hover:bg-teal-50"
                                >
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                                    </svg>
                                    Create Child Tickets
                                </button>
                                <button
                                    v-if="selectedIds.length === 1 && hasPermission('tickets.edit')"
                                    @click="openSplitModal"
                                    class="inline-flex min-h-[42px] items-center justify-center gap-2 rounded-lg border border-amber-300 bg-white px-3 py-2 text-sm font-semibold text-amber-700 transition-colors hover:bg-amber-50"
                                >
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7v8a2 2 0 002 2h6M8 7V5a2 2 0 012-2h4.586a1 1 0 01.707.293l4.414 4.414a1 1 0 01.293.707V15a2 2 0 01-2 2h-2M8 7H6a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2v-2" />
                                    </svg>
                                    Split
                                </button>
                                <button
                                    v-if="selectedIds.length > 1 && hasPermission('tickets.edit')"
                                    @click="openMergeModal"
                                    class="inline-flex min-h-[42px] items-center justify-center gap-2 rounded-lg border border-violet-300 bg-white px-3 py-2 text-sm font-semibold text-violet-700 transition-colors hover:bg-violet-50"
                                >
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" />
                                    </svg>
                                    Merge
                                </button>
                                <button
                                    @click="selectedIds = []"
                                    class="inline-flex min-h-[42px] items-center justify-center rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-600 transition-colors hover:bg-slate-50"
                                >
                                    Clear
                                </button>
                                <button
                                    @click="submitBulk"
                                    :disabled="isBulkSubmitting"
                                    class="inline-flex min-h-[42px] items-center justify-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-bold text-white shadow-sm transition-colors hover:bg-blue-700 disabled:opacity-50 sm:col-span-2"
                                >
                                    <svg v-if="isBulkSubmitting" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 22 6.477 22 12h-4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                    Apply to {{ selectedIds.length }}
                                </button>
                            </div>
                        </div>
                    </div>
                </Transition>
            </div>

            <DataTable
                title="Ticket Monitoring Board"
                :subtitle="tableSubtitle"
                search-placeholder="Search by key, title, description, reporter, or assignee..."
                :empty-message="emptyStateMessage"
                :search="pagination.search.value"
                :data="displayedTickets"
                :current-page="pagination.currentPage.value"
                :last-page="pagination.lastPage.value"
                :per-page="pagination.perPage.value"
                :showing-text="pagination.showingText.value"
                :is-loading="pagination.isLoading.value"
                @update:search="pagination.search.value = $event"
                @go-to-page="pagination.goToPage"
                @change-per-page="pagination.changePerPage"
            >
                <template #header>
                    <tr>
                        <th class="px-4 py-3 w-10">
                            <input
                                type="checkbox"
                                :checked="allSelected"
                                @change="toggleAll"
                                class="cursor-pointer rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                            >
                        </th>
                        <th class="px-4 py-3 text-left text-[11px] font-black uppercase tracking-[0.18em] text-slate-500">Ticket</th>
                        <th class="px-4 py-3 text-left text-[11px] font-black uppercase tracking-[0.18em] text-slate-500">Assignee</th>
                        <th class="px-4 py-3 text-left text-[11px] font-black uppercase tracking-[0.18em] text-slate-500">Queue Detail</th>
                        <th class="px-4 py-3 text-left text-[11px] font-black uppercase tracking-[0.18em] text-slate-500">SLA Health</th>
                        <th class="px-4 py-3 text-left text-[11px] font-black uppercase tracking-[0.18em] text-slate-500">Created</th>
                    </tr>
                </template>

                <template #body="{ data }">
                    <tr
                        v-for="ticket in data"
                        :key="ticket.id"
                        @click="editTicket(ticket)"
                        class="group border-l-4 align-top transition-all"
                        :class="[
                            getSlaRowClass(ticket),
                            hasPermission('tickets.edit') ? 'cursor-pointer' : 'cursor-not-allowed',
                            selectedIds.includes(ticket.id) ? 'ring-1 ring-inset ring-blue-300' : ''
                        ]"
                    >
                        <td class="px-4 py-5 w-10 align-top" @click.stop>
                            <input
                                type="checkbox"
                                :value="ticket.id"
                                v-model="selectedIds"
                                class="mt-1 cursor-pointer rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                            >
                        </td>
                        <td class="px-4 py-5 align-top">
                            <div class="min-w-[240px] max-w-[360px] space-y-3">
                                <div class="flex flex-wrap items-start gap-2">
                                    <span class="inline-flex rounded-md border border-slate-300 bg-white px-2.5 py-1 text-[11px] font-black tracking-wide text-black shadow-sm">
                                        {{ ticket.ticket_key }}
                                    </span>
                                    <span class="inline-flex rounded-full border px-2.5 py-1 text-[11px] font-bold capitalize shadow-sm" :class="getPriorityColor(ticket.item?.priority || ticket.priority)">
                                        {{ getPriorityLabel(ticket.item?.priority || ticket.priority) }}
                                    </span>
                                    <span class="inline-flex rounded-full border px-2.5 py-1 text-[11px] font-bold capitalize" :class="getStatusColor(ticket.status)">
                                        {{ getStatusLabel(ticket.status) }}
                                    </span>
                                </div>

                                <div class="space-y-1.5">
                                    <div class="break-words text-sm font-bold leading-5 text-black">
                                        {{ ticket.title }}
                                    </div>
                                    <p class="max-h-10 overflow-hidden break-words text-xs leading-5 text-black">
                                        {{ ticket.description || 'No description provided.' }}
                                    </p>
                                </div>

                                <div class="rounded-xl border border-slate-300 bg-white p-3">
                                    <div class="mb-2 text-[10px] font-black uppercase tracking-[0.22em] text-black">Requester</div>
                                    <div v-if="ticket.reporter" class="flex items-center gap-2 text-sm">
                                        <div v-if="ticket.reporter.profile_photo" class="h-7 w-7 overflow-hidden rounded-full border border-slate-200">
                                            <img :src="'/serve-storage/' + ticket.reporter.profile_photo" class="h-full w-full object-cover" :alt="ticket.reporter.name">
                                        </div>
                                        <div v-else class="flex h-7 w-7 items-center justify-center rounded-full border border-slate-300 bg-white text-[10px] font-bold text-black">
                                            {{ ticket.reporter.name.charAt(0) }}
                                        </div>
                                        <span class="break-words font-semibold text-black">{{ ticket.reporter.name }}</span>
                                    </div>
                                    <div v-else class="break-words text-sm font-semibold text-black">{{ getReporterLabel(ticket) }}</div>
                                    <div v-if="ticket.sender_email" class="mt-1 break-all text-[11px] text-black">{{ ticket.sender_email }}</div>
                                </div>

                                <div v-if="ticket.children?.length" class="rounded-xl border border-blue-300 bg-white p-3">
                                    <div class="mb-2 text-[10px] font-black uppercase tracking-[0.22em] text-black">Child Tickets</div>
                                    <div class="space-y-2">
                                        <div v-for="child in ticket.children" :key="child.id" class="flex items-start justify-between gap-3 text-xs">
                                            <div class="min-w-0">
                                                <div class="font-bold text-black">{{ child.ticket_key }}</div>
                                                <div class="break-words text-black">{{ child.title }}</div>
                                            </div>
                                            <div class="shrink-0 text-right text-[11px] text-black">
                                                {{ child.assignee?.name || 'Unassigned' }}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-5 align-top">
                            <div class="min-w-[160px] max-w-[220px] space-y-2">
                                <div v-if="ticket.assignee" class="flex items-center gap-2">
                                    <div v-if="ticket.assignee.profile_photo" class="h-7 w-7 overflow-hidden rounded-full border border-slate-200">
                                        <img :src="'/serve-storage/' + ticket.assignee.profile_photo" class="h-full w-full object-cover" :alt="ticket.assignee.name">
                                    </div>
                                    <div v-else class="flex h-7 w-7 items-center justify-center rounded-full bg-slate-200 text-[10px] font-bold text-slate-600">
                                        {{ ticket.assignee.name.charAt(0) }}
                                    </div>
                                    <div>
                                        <div class="font-semibold text-black">{{ ticket.assignee.name }}</div>
                                        <div class="text-[10px] font-black uppercase tracking-[0.2em] text-slate-400">Assigned</div>
                                    </div>
                                </div>
                                <button
                                    v-else-if="hasPermission('tickets.assign')"
                                    type="button"
                                    @click.stop="acceptTicket(ticket)"
                                    class="inline-flex items-center rounded-lg border border-blue-600 bg-white px-3 py-1.5 text-xs font-bold text-blue-600 shadow-sm transition-all hover:bg-blue-600 hover:text-white focus:outline-none"
                                >
                                    Accept Ticket
                                </button>
                                <div v-else class="inline-flex rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-black">
                                    Unassigned
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-5 align-top">
                            <div class="min-w-[180px] max-w-[240px] space-y-3 text-sm">
                                <div>
                                    <div class="text-[10px] font-black uppercase tracking-[0.2em] text-slate-400">Store</div>
                                    <div class="mt-1 break-words font-semibold text-black">{{ ticket.store ? ticket.store.name : '-' }}</div>
                                </div>
                                <div>
                                    <div class="text-[10px] font-black uppercase tracking-[0.2em] text-slate-400">Item</div>
                                    <div class="mt-1 break-words text-xs leading-5 text-black">{{ formatItemName(ticket.item) }}</div>
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-5 align-top">
                            <div v-if="ticket.sla_metric" class="min-w-[160px] max-w-[220px] space-y-2">
                                <div
                                    v-for="sla in [getSlaState(ticket, 'response'), getSlaState(ticket, 'resolution')]"
                                    :key="sla.label"
                                    class="rounded-xl border px-3 py-2"
                                    :class="sla.toneClass"
                                >
                                    <div class="flex items-center justify-between gap-3">
                                        <div class="flex items-center gap-2">
                                            <span class="h-2.5 w-2.5 rounded-full" :class="sla.dotClass"></span>
                                            <span class="text-[10px] font-black uppercase tracking-[0.2em]">{{ sla.label }}</span>
                                        </div>
                                        <span class="text-xs font-bold">{{ sla.value }}</span>
                                    </div>
                                </div>
                            </div>
                            <div v-else class="inline-flex rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs font-medium text-black">
                                No SLA target
                            </div>
                        </td>
                        <td class="px-4 py-5 align-top text-sm text-black">
                            <div class="min-w-[132px]">
                                <div class="font-medium text-black">{{ formatDate(ticket.created_at) }}</div>
                            </div>
                        </td>
                    </tr>
                </template>
            </DataTable>
        </div>
        <!-- Create Ticket Modal -->
        <div v-if="showCreateModal" class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex items-center justify-center min-h-screen px-4">
                <div class="fixed inset-0 bg-black/20 backdrop-blur-md" @click="showCreateModal = false"></div>
                <div class="bg-white rounded-xl shadow-2xl max-w-md w-full p-6 relative border border-gray-100">
                    <div class="flex justify-between items-center mb-6">
                        <h3 class="text-xl font-bold text-gray-900">Create New Ticket</h3>
                        
                        <div class="flex flex-col items-end">
                            <label class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Status</label>
                            <select v-model="createForm.status" required class="bg-gray-50 border-none rounded-lg text-xs font-bold capitalize focus:ring-0 cursor-pointer shadow-sm" :class="getStatusColor(createForm.status)">
                                <option v-for="s in statuses" :key="s" :value="s">{{ getStatusLabel(s) }}</option>
                            </select>
                         </div>
                    </div>

                    <form @submit.prevent="createTicket" class="space-y-5">
                        
                        <!-- Requester Configuration -->
                        <div class="bg-gray-50 p-4 rounded-xl border border-gray-100 space-y-4">
                            <label v-if="!isUserRole" class="flex items-center space-x-3 cursor-pointer">
                                <div class="relative">
                                    <input type="checkbox" v-model="createForm.is_self_requester" class="sr-only peer">
                                    <div class="w-9 h-5 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-blue-600"></div>
                                </div>
                                <span class="text-sm font-bold text-gray-700">I am the requester</span>
                            </label>

                            <div v-if="!createForm.is_self_requester" class="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-2 border-t border-gray-200">
                                <div>
                                    <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1">Requester Name</label>
                                    <input v-model="createForm.sender_name" type="text" maxlength="255" required class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1">Requester Email</label>
                                    <input v-model="createForm.sender_email" type="email" maxlength="255" required class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                                </div>
                            </div>

                            <div class="pt-2 border-t border-gray-200">
                                <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1">Department</label>
                                <input
                                    v-model="createForm.department"
                                    type="text"
                                    list="ticket-departments-list"
                                    maxlength="255"
                                    :readonly="createForm.is_self_requester"
                                    :class="createForm.is_self_requester ? 'bg-gray-100 cursor-not-allowed' : ''"
                                    class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm"
                                    placeholder="Department"
                                >
                                <datalist id="ticket-departments-list">
                                    <option v-for="dept in departments" :key="dept" :value="dept" />
                                </datalist>
                            </div>

                            <div v-if="!isUserRole" class="pt-2">
                                <label class="flex items-center space-x-3 cursor-pointer">
                                    <div class="relative">
                                        <input type="checkbox" v-model="createForm.notify_requester" class="sr-only peer">
                                        <div class="w-9 h-5 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-blue-600"></div>
                                    </div>
                                    <span class="text-xs font-medium text-gray-600">Send email notification to requester</span>
                                </label>
                            </div>
                        </div>

                        <div v-if="availableCompanies.length > 0">
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Company</label>
                            <select v-model="createForm.company_id" required class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                                <option value="">Select Company</option>
                                <option v-for="company in availableCompanies" :key="company.id" :value="company.id">{{ company.name }}</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Store</label>
                            <Autocomplete
                                v-model="createForm.store_id"
                                :options="storesWithLabel"
                                label-key="display_name"
                                value-key="id"
                                placeholder="Select store..."
                            />
                        </div>
                        
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Item</label>
                            <Autocomplete
                                v-model="createForm.item_id"
                                :options="items"
                                label-key="display_name"
                                value-key="id"
                                placeholder="Select item..."
                                size="sm"
                            />
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Vendor Escalation</label>
                            <Autocomplete
                                v-model="createForm.vendor_id"
                                :options="vendors"
                                label-key="name"
                                value-key="id"
                                placeholder="None"
                                size="sm"
                            />
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Title</label>
                            <input v-model="createForm.title" type="text" maxlength="255" required class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Description</label>
                            <textarea v-model="createForm.description" maxlength="65535" rows="4" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm"></textarea>
                        </div>
                        <div v-if="createForm.item_id" class="p-3 bg-gray-50 rounded-lg border border-gray-100">
                            <label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Priority</label>
                            <span class="inline-flex px-2.5 py-0.5 rounded-full text-xs font-bold capitalize shadow-sm" :class="getPriorityColor(createForm.priority)">
                                {{ getPriorityLabel(createForm.priority) }}
                            </span>
                        </div>

                        <div v-if="hasPermission('tickets.assign')">
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Assign To</label>
                            <select v-model="createForm.assignee_id" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                                <option value="">Unassigned</option>
                                <option v-for="person in staff" :key="person.id" :value="person.id">{{ person.name }}</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Attachments</label>
                            <input ref="fileInput" type="file" multiple @change="handleFileSelect" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                            <div v-if="createForm.attachments.length > 0" class="mt-2 text-xs text-gray-600">
                                <p class="font-medium mb-1">Selected files:</p>
                                <div class="space-y-1">
                                    <div v-for="(file, index) in createForm.attachments" :key="index">
                                        {{ file.name }} ({{ formatFileSize(file.size) }})
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="flex justify-end space-x-3 pt-6 border-t mt-6">
                            <button type="button" @click="showCreateModal = false" class="px-4 py-2 text-sm font-semibold text-gray-600 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors">Cancel</button>
                            <button type="submit" :disabled="isSubmitting" class="px-6 py-2 bg-blue-600 text-white text-sm font-bold rounded-lg hover:bg-blue-700 shadow-md disabled:opacity-50 transition-all">Create Ticket</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Accept Ticket Modal -->
        <div v-if="showAcceptModal" class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex items-center justify-center min-h-screen px-4">
                <div class="fixed inset-0 bg-black/20 backdrop-blur-md" @click="showAcceptModal = false"></div>
                <div class="bg-white rounded-xl shadow-2xl max-w-sm w-full p-6 relative border border-gray-100">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-lg font-bold text-gray-900">Accept Ticket</h3>
                        <button @click="showAcceptModal = false" class="text-gray-400 hover:text-gray-600">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>

                    <p v-if="acceptingTicket" class="text-xs text-gray-500 mb-5 bg-gray-50 rounded-lg p-3 border border-gray-100 truncate">
                        <span class="font-black text-gray-700">{{ acceptingTicket.ticket_key }}</span>
                        — {{ acceptingTicket.title }}
                    </p>

                    <div class="space-y-4">
                        <div v-if="availableCompanies.length > 0">
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Company <span class="text-red-500">*</span></label>
                            <select v-model="acceptForm.company_id" required class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                                <option value="">Select Company</option>
                                <option v-for="company in availableCompanies" :key="company.id" :value="company.id">{{ company.name }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Store <span class="text-red-500">*</span></label>
                            <Autocomplete
                                v-model="acceptForm.store_id"
                                :options="storesWithLabel"
                                label-key="display_name"
                                value-key="id"
                                placeholder="Select store..."
                            />
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Item <span class="text-red-500">*</span></label>
                            <Autocomplete
                                v-model="acceptForm.item_id"
                                :options="items"
                                label-key="display_name"
                                value-key="id"
                                placeholder="Select item..."
                                size="sm"
                            />
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Department</label>
                            <input
                                v-model="acceptForm.department"
                                type="text"
                                list="accept-ticket-departments-list"
                                maxlength="255"
                                class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm"
                                placeholder="Department"
                            >
                            <datalist id="accept-ticket-departments-list">
                                <option v-for="dept in departments" :key="dept" :value="dept" />
                            </datalist>
                        </div>
                    </div>

                    <div class="flex justify-end space-x-3 pt-5 border-t mt-5">
                        <button type="button" @click="showAcceptModal = false" class="px-4 py-2 text-sm font-semibold text-gray-600 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors">Cancel</button>
                        <button
                            type="button"
                            @click="submitAcceptTicket"
                            :disabled="!acceptForm.company_id || !acceptForm.store_id || !acceptForm.item_id"
                            class="px-6 py-2 bg-blue-600 text-white text-sm font-bold rounded-lg hover:bg-blue-700 shadow-md disabled:opacity-50 transition-all"
                        >
                            Accept Ticket
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Bulk Create Child Tickets Modal -->
        <div v-if="showBulkChildModal" class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex items-center justify-center min-h-screen px-4 py-8">
                <div class="fixed inset-0 bg-black/20 backdrop-blur-md" @click="showBulkChildModal = false"></div>
                <div class="bg-white rounded-xl shadow-2xl max-w-4xl w-full p-6 relative border border-gray-100 max-h-[90vh] flex flex-col">
                    <div class="flex justify-between items-center mb-6">
                        <h3 class="text-xl font-bold text-gray-900 uppercase tracking-widest">Bulk Create Child Tickets</h3>
                        <button @click="showBulkChildModal = false" class="text-gray-400 hover:text-gray-600">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>

                    <p class="text-sm text-gray-600 bg-teal-50 p-3 rounded-lg border border-teal-100 mb-6 shrink-0">
                        Set individual schedules for **{{ selectedIds.length }}** parent tickets. Child tickets will be created and both parent and child will be set to "For Schedule".
                    </p>

                    <form @submit.prevent="submitBulkChild" class="space-y-8 overflow-y-auto px-1 custom-scrollbar">
                        <div v-for="(ticketForm, index) in bulkChildForm.tickets" :key="ticketForm.parent_id" 
                             class="p-5 border border-gray-200 rounded-xl space-y-5 bg-gray-50/50 hover:border-teal-200 transition-colors">
                            
                            <!-- Header with Ticket Info -->
                            <div class="flex justify-between items-center border-b border-gray-200 pb-3">
                                <div class="flex items-center gap-3">
                                    <span class="px-2 py-1 bg-teal-600 text-white text-[10px] font-black rounded uppercase shadow-sm">{{ ticketForm.ticket_key }}</span>
                                    <span class="text-sm font-bold text-gray-900 truncate max-w-[500px]">{{ ticketForm.title }}</span>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5">Assigned User</label>
                                    <Autocomplete v-model="bulkChildForm.tickets[index].user_id" :options="staff" label-key="name" value-key="id" placeholder="Select user..." size="sm" />
                                </div>
                                <div>
                                    <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5">Schedule Status</label>
                                    <select v-model="bulkChildForm.tickets[index].status" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm bg-white">
                                        <option v-for="status in ['On-site', 'Off-site', 'WFH', 'SL', 'VL', 'Restday', 'Offset', 'Holiday']" :key="status" :value="status">{{ status }}</option>
                                    </select>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5">Start Time</label>
                                    <input v-model="bulkChildForm.tickets[index].start_time" type="datetime-local" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm bg-white">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5">End Time</label>
                                    <input v-model="bulkChildForm.tickets[index].end_time" type="datetime-local" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm bg-white">
                                </div>
                            </div>

                            <!-- Additional Times (Collapsible or always visible) -->
                            <div class="p-4 bg-white rounded-lg border border-gray-100 space-y-4 shadow-sm">
                                <h4 class="text-[10px] font-black text-teal-600 uppercase tracking-widest">Additional Activity Windows</h4>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    <div class="space-y-2">
                                        <label class="block text-[10px] font-bold text-gray-600 uppercase">Pickup Time</label>
                                        <div class="flex items-center space-x-2">
                                            <input v-model="bulkChildForm.tickets[index].pickup_start" type="time" class="flex-1 px-3 py-1.5 border border-gray-200 rounded-lg text-xs">
                                            <span class="text-gray-400">-</span>
                                            <input v-model="bulkChildForm.tickets[index].pickup_end" type="time" class="flex-1 px-3 py-1.5 border border-gray-200 rounded-lg text-xs">
                                        </div>
                                    </div>
                                    <div class="space-y-2">
                                        <label class="block text-[10px] font-bold text-gray-600 uppercase">Backlogs Time</label>
                                        <div class="flex items-center space-x-2">
                                            <input v-model="bulkChildForm.tickets[index].backlogs_start" type="time" class="flex-1 px-3 py-1.5 border border-gray-200 rounded-lg text-xs">
                                            <span class="text-gray-400">-</span>
                                            <input v-model="bulkChildForm.tickets[index].backlogs_end" type="time" class="flex-1 px-3 py-1.5 border border-gray-200 rounded-lg text-xs">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div>
                                <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-1.5">Remarks</label>
                                <textarea v-model="bulkChildForm.tickets[index].remarks" rows="2" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm bg-white" placeholder="Specific activity details for this child ticket..."></textarea>
                            </div>
                        </div>

                        <div class="flex justify-end space-x-3 pt-6 border-t mt-8 sticky bottom-0 bg-white pb-2">
                            <button type="button" @click="showBulkChildModal = false" class="px-6 py-2.5 text-sm font-bold text-gray-600 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors">Cancel</button>
                            <button type="submit" :disabled="bulkChildForm.processing" class="px-8 py-2.5 bg-teal-600 text-white text-sm font-black rounded-lg hover:bg-teal-700 shadow-lg disabled:opacity-50 transition-all uppercase tracking-widest">
                                Create {{ selectedIds.length }} Child Tickets
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Split Ticket Modal -->
        <div v-if="showSplitModal" class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex items-center justify-center min-h-screen px-4">
                <div class="fixed inset-0 bg-black/20 backdrop-blur-md" @click="showSplitModal = false"></div>
                <div class="bg-white rounded-xl shadow-2xl max-w-lg w-full p-6 relative border border-gray-100">
                    <div class="flex justify-between items-center mb-6">
                        <h3 class="text-xl font-bold text-gray-900">Split Ticket</h3>
                        <button @click="showSplitModal = false" class="text-gray-400 hover:text-gray-600">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>

                    <form @submit.prevent="submitSplit" class="space-y-6">
                        <p class="text-sm text-gray-600 bg-yellow-50 p-3 rounded-lg border border-yellow-100">
                            Splitting will update the original ticket's title and create new tickets for each additional concern listed below.
                        </p>

                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Original Ticket Concern (Current)</label>
                            <input v-model="splitForm.original_title" type="text" required class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                        </div>

                        <div class="space-y-4">
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider">Additional Concerns (New Tickets)</label>
                            <div v-for="(title, index) in splitForm.new_titles" :key="index" class="flex gap-2">
                                <input v-model="splitForm.new_titles[index]" type="text" placeholder="Enter new ticket subject..." class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                                <button type="button" @click="removeSplitConcern(index)" class="p-2 text-red-600 hover:bg-red-50 rounded-lg transition-colors">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                    </svg>
                                </button>
                            </div>
                            <button type="button" @click="addSplitConcern" class="text-sm font-bold text-blue-600 hover:text-blue-700 flex items-center gap-2">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                                </svg>
                                Add Another Concern
                            </button>
                        </div>

                        <div class="flex justify-end space-x-3 pt-6 border-t mt-6">
                            <button type="button" @click="showSplitModal = false" class="px-4 py-2 text-sm font-semibold text-gray-600 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors">Cancel</button>
                            <button type="submit" :disabled="splitForm.processing" class="px-6 py-2 bg-blue-600 text-white text-sm font-bold rounded-lg hover:bg-blue-700 shadow-md disabled:opacity-50 transition-all">
                                Split into {{ splitForm.new_titles.length + 1 }} Tickets
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Merge Tickets Modal -->
        <div v-if="showMergeModal" class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex items-center justify-center min-h-screen px-4">
                <div class="fixed inset-0 bg-black/20 backdrop-blur-md" @click="showMergeModal = false"></div>
                <div class="bg-white rounded-xl shadow-2xl max-w-lg w-full p-6 relative border border-gray-100">
                    <div class="flex justify-between items-center mb-6">
                        <h3 class="text-xl font-bold text-gray-900">Merge Tickets</h3>
                        <button @click="showMergeModal = false" class="text-gray-400 hover:text-gray-600">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>

                    <form @submit.prevent="submitMerge" class="space-y-6">
                        <p class="text-sm text-gray-600 bg-purple-50 p-3 rounded-lg border border-purple-100">
                            Select the **Parent Ticket** to retain. All other tickets will be closed and linked to the parent. Requesters will be notified.
                        </p>

                        <div class="space-y-3">
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider">Select Parent Ticket</label>
                            <div v-for="ticket in getSelectedTickets" :key="ticket.id" 
                                 class="flex items-center p-3 rounded-lg border cursor-pointer transition-all"
                                 :class="mergeForm.parent_id === ticket.id ? 'bg-blue-50 border-blue-200 ring-1 ring-blue-200' : 'bg-white border-gray-200 hover:border-gray-300'"
                                 @click="mergeForm.parent_id = ticket.id">
                                <input type="radio" :value="ticket.id" v-model="mergeForm.parent_id" class="w-4 h-4 text-blue-600 focus:ring-blue-500 border-gray-300 cursor-pointer">
                                <div class="ml-3 flex-1">
                                    <div class="text-xs font-bold text-blue-600">{{ ticket.ticket_key }}</div>
                                    <div class="text-sm font-semibold text-gray-900">{{ ticket.title }}</div>
                                </div>
                            </div>
                        </div>

                        <div class="flex justify-end space-x-3 pt-6 border-t mt-6">
                            <button type="button" @click="showMergeModal = false" class="px-4 py-2 text-sm font-semibold text-gray-600 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors">Cancel</button>
                            <button type="submit" :disabled="mergeForm.processing" class="px-6 py-2 bg-purple-600 text-white text-sm font-bold rounded-lg hover:bg-purple-700 shadow-md disabled:opacity-50 transition-all">
                                Merge {{ selectedIds.length }} Tickets
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

    </AppLayout>
</template>

<style scoped>
@keyframes pulse-red {
  0%, 100% { opacity: 1; transform: scale(1); }
  50% { opacity: 0.7; transform: scale(0.95); background-color: #fee2e2; }
}
@keyframes pulse-yellow {
  0%, 100% { background-color: #fef9c3; border-color: #fde047; }
  50% { background-color: #fef08a; border-color: #facc15; }
}
.animate-pulse-red {
  animation: pulse-red 1.5s cubic-bezier(0.4, 0, 0.6, 1) infinite;
}
.animate-pulse-yellow {
  animation: pulse-yellow 2s ease-in-out infinite;
}
</style>
