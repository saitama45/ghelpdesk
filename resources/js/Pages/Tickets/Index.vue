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
    filters: Object,
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

const filterStatus = ref(props.filters?.status || 'open');
const pagination = usePagination(props.tickets, 'tickets.index', () => ({ status: filterStatus.value }));

const filterOptions = [
    { value: 'all', label: 'All' },
    { value: 'my_tickets', label: 'My Tickets' },
    { value: 'open', label: 'Open' },
    { value: 'in_progress', label: 'In Progress' },
    { value: 'resolved', label: 'Resolved' },
    { value: 'waiting_service_provider', label: 'Waiting for service provider' },
    { value: 'waiting_client_feedback', label: 'Waiting for clients feedback?' },
    { value: 'closed', label: 'Closed' },
    { value: 'unassigned', label: 'Unassigned' },
];

const applyFilter = () => {
    router.get(route('tickets.index'), {
        status: filterStatus.value,
        search: pagination.search.value
    }, {
        preserveState: true,
        preserveScroll: true
    });
};

onMounted(() => {
    pagination.updateData(props.tickets);
});

watch(() => props.tickets, (newTickets) => {
    pagination.updateData(newTickets);
}, { deep: true });

const acceptForm = useForm({
    store_id: '',
    item_id: '',
});

const createForm = useForm({
    company_id: '',
    store_id: '',
    item_id: '',
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

// Also watch defaultCompanyId in case it loads later
watch(defaultCompanyId, (newId) => {
    if (!createForm.company_id) {
        createForm.company_id = newId;
    }
}, { immediate: true });

// ── Bulk Selection ────────────────────────────────────────────────────────
const selectedIds = ref([])

const allSelected = computed(() =>
    pagination.data.value.length > 0 &&
    pagination.data.value.every(t => selectedIds.value.includes(t.id))
)

const toggleAll = () => {
    selectedIds.value = allSelected.value ? [] : pagination.data.value.map(t => t.id)
}

watch(() => [pagination.currentPage.value, pagination.search.value], () => {
    selectedIds.value = []
})

// ── Bulk Form ─────────────────────────────────────────────────────────────
const bulkForm = reactive({
    store_id: '', item_id: '', assignee_id: ''
})
const isBulkSubmitting = ref(false)

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
const statuses = ['open', 'in_progress', 'resolved', 'closed', 'waiting_service_provider', 'waiting_client_feedback'];

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
    acceptForm.store_id = ticket.store_id || '';
    acceptForm.item_id = ticket.item_id || '';
    showAcceptModal.value = true;
};

const submitAcceptTicket = () => {
    if (!acceptingTicket.value) return;
    const ticket = acceptingTicket.value;

    const item = items.value.find(i => i.id == acceptForm.item_id);
    const priority = item ? item.priority.toLowerCase() : (ticket.priority || 'medium');

    put(route('tickets.update', ticket.id), {
        company_id: ticket.company_id,
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
        case 'urgent': return 'text-red-900 bg-red-200';
        case 'high': return 'text-red-800 bg-red-100';
        case 'medium': return 'text-yellow-900 bg-yellow-200';
        case 'low': return 'text-green-900 bg-green-200';
        default: return 'text-gray-800 bg-gray-100';
    }
};

const getPriorityBorder = (priority) => {
    const p = String(priority || '').toLowerCase();
    switch (p) {
        case 'urgent': return 'border-l-red-600';
        case 'high': return 'border-l-red-400';
        case 'medium': return 'border-l-yellow-400';
        case 'low': return 'border-l-green-400';
        default: return 'border-l-gray-200';
    }
};

const getStatusColor = (status) => {
    switch (status) {
        case 'open': return 'text-blue-800 bg-blue-100';
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
        case 'waiting_service_provider': return 'Waiting for service provider';
        case 'waiting_client_feedback': return 'Waiting for clients feedback?';
        default: return status.replace('_', ' ');
    }
};

const getSlaRowClass = (ticket) => {
    if (!ticket.sla_metric) return 'border-l-gray-200 hover:bg-gray-50';
    
    const isBreached = ticket.sla_metric.is_response_breached || ticket.sla_metric.is_resolution_breached;
    const isAllMet = ticket.sla_metric.first_response_at && ticket.sla_metric.resolved_at;
    
    if (isBreached) return 'border-l-red-600 !bg-red-50 hover:!bg-red-100/50';
    if (isAllMet) return 'border-l-green-500 !bg-green-50 hover:!bg-green-100/50';
    
    // Default/Active based on item priority background
    const priority = ticket.item?.priority?.toLowerCase() || ticket.priority?.toLowerCase();
    let bgClass = 'hover:bg-gray-50';
    
    if (priority === 'urgent') bgClass = '!bg-red-50 hover:!bg-red-100/50';
    else if (priority === 'high') bgClass = '!bg-orange-50 hover:!bg-orange-100/50';
    else if (priority === 'medium') bgClass = '!bg-yellow-50 hover:!bg-yellow-100/50';
    else if (priority === 'low') bgClass = '!bg-green-50 hover:!bg-green-100/50';

    return getPriorityBorder(priority) + ' ' + bgClass;
};

const formatItemName = (item) => {
    if (!item) return '-';
    const cat = item.category?.name ?? 'N/A';
    const sub = item.sub_category?.name ?? 'N/A';
    return `${cat} | ${sub} | ${item.name}`;
};
</script>

<template>
    <Head title="Tickets - Help Desk" />

    <AppLayout>
        <template #header>
            Tickets
        </template>

        <div class="space-y-6">
            <!-- Bulk Action Toolbar -->
            <Transition
                enter-active-class="transition ease-out duration-200"
                enter-from-class="opacity-0 -translate-y-2"
                enter-to-class="opacity-100 translate-y-0"
                leave-active-class="transition ease-in duration-150"
                leave-from-class="opacity-100 translate-y-0"
                leave-to-class="opacity-0 -translate-y-2"
            >
                <div v-if="selectedIds.length > 0"
                     class="bg-blue-50 border border-blue-200 rounded-xl p-4 flex flex-wrap items-end gap-3 shadow-sm">

                    <div class="text-sm font-bold text-blue-700 self-center whitespace-nowrap mr-2">
                        {{ selectedIds.length }} ticket(s) selected
                    </div>

                    <!-- Store -->
                    <div class="flex flex-col gap-1">
                        <label class="text-[10px] font-bold text-blue-600 uppercase tracking-wider">Store</label>
                        <Autocomplete v-model="bulkForm.store_id" :options="stores"
                                      label-key="name" value-key="id" placeholder="Unchanged..." />
                    </div>

                    <!-- Item -->
                    <div class="flex flex-col gap-1">
                        <label class="text-[10px] font-bold text-blue-600 uppercase tracking-wider">Item</label>
                        <Autocomplete v-model="bulkForm.item_id" :options="items"
                                      label-key="display_name" value-key="id" placeholder="Unchanged..." size="sm" />
                    </div>

                    <!-- Assignee -->
                    <div class="flex flex-col gap-1">
                        <label class="text-[10px] font-bold text-blue-600 uppercase tracking-wider">Assignee</label>
                        <select v-model="bulkForm.assignee_id"
                                class="border-gray-300 rounded-lg text-sm focus:ring-blue-500 focus:border-blue-500 min-w-[140px] py-2 px-3">
                            <option value="">— Unchanged —</option>
                            <option v-for="p in staff" :key="p.id" :value="p.id">{{ p.name }}</option>
                        </select>
                    </div>

                    <!-- Buttons -->
                    <div class="flex gap-2 self-end ml-auto">
                        <button @click="selectedIds = []"
                                class="px-3 py-2 text-sm font-semibold bg-white border border-gray-300 text-gray-600 rounded-lg hover:bg-gray-50 transition-colors">
                            Clear
                        </button>
                        <button @click="submitBulk" :disabled="isBulkSubmitting"
                                class="px-4 py-2 text-sm font-bold bg-blue-600 text-white rounded-lg hover:bg-blue-700 shadow-sm disabled:opacity-50 transition-colors flex items-center gap-2">
                            <svg v-if="isBulkSubmitting" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 22 6.477 22 12h-4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            Apply to {{ selectedIds.length }}
                        </button>
                    </div>
                </div>
            </Transition>

            <!-- Data Table -->
            <DataTable
                title="Ticket Management"
                subtitle="Manage support tickets (Click a row to edit)"
                search-placeholder="Search by key, title, or description..."
                empty-message="No tickets found. Create your first ticket to get started."
                :search="pagination.search.value"
                :data="pagination.data.value"
                :current-page="pagination.currentPage.value"
                :last-page="pagination.lastPage.value"
                :per-page="pagination.perPage.value"
                :showing-text="pagination.showingText.value"
                :is-loading="pagination.isLoading.value"
                @update:search="pagination.search.value = $event"
                @go-to-page="pagination.goToPage"
                @change-per-page="pagination.changePerPage"
            >
                <template #actions>
                    <select 
                        v-model="filterStatus" 
                        @change="applyFilter"
                        class="border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm"
                    >
                        <option v-for="option in filterOptions" :key="option.value" :value="option.value">
                            {{ option.label }}
                        </option>
                    </select>
                    <button
                        v-if="hasPermission('tickets.create')"
                        @click="showCreateModal = true"
                        class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition-colors flex items-center space-x-2 text-sm font-medium shadow-sm whitespace-nowrap"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                        </svg>
                        <span>Create Ticket</span>
                    </button>
                </template>

                <template #header>
                    <tr>
                        <th class="px-4 py-3 w-10">
                            <input type="checkbox" :checked="allSelected" @change="toggleAll"
                                   class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 cursor-pointer">
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">#</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Title</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Store</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Items</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Priority</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">SLA</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Creator</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Assignee</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Created</th>
                    </tr>
                </template>

                <template #body="{ data }">
                    <tr
                        v-for="ticket in data"
                        :key="ticket.id"
                        @click="editTicket(ticket)"
                        class="group transition-all border-l-4"
                        :class="[
                            getSlaRowClass(ticket),
                            hasPermission('tickets.edit') ? 'cursor-pointer' : 'cursor-not-allowed',
                            selectedIds.includes(ticket.id) ? '!bg-blue-50' : ''
                        ]"
                    >
                        <td class="px-4 py-4 w-10" @click.stop>
                            <input type="checkbox" :value="ticket.id" v-model="selectedIds"
                                   class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 cursor-pointer">
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-gray-700">
                            <div>{{ ticket.ticket_key }}</div>
                            <div v-for="child in ticket.children" :key="child.id" class="text-[10px] text-blue-600 mt-1">
                                {{ child.ticket_key }}
                            </div>
                        </td>
                        <td class="px-6 py-4">
                            <div class="flex flex-col">
                                <div class="text-sm font-semibold text-gray-900 group-hover:text-blue-700 transition-colors">{{ ticket.title }}</div>
                                <div class="text-xs text-gray-500 truncate max-w-xs">{{ ticket.description }}</div>
                                <!-- Child Tickets -->
                                <div v-for="child in ticket.children" :key="child.id" class="text-[10px] text-blue-500 italic mt-1 font-medium">
                                    ↳ {{ child.title }}
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                            {{ ticket.store ? ticket.store.name : '-' }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-xs text-gray-600 font-medium">
                            {{ formatItemName(ticket.item) }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="inline-flex px-2.5 py-0.5 rounded-full text-xs font-bold capitalize shadow-sm" :class="getPriorityColor(ticket.item?.priority || ticket.priority)">
                                {{ getPriorityLabel(ticket.item?.priority || ticket.priority) }}
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="inline-flex px-2.5 py-0.5 rounded-full text-xs font-bold capitalize border" :class="getStatusColor(ticket.status)">
                                {{ getStatusLabel(ticket.status) }}
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div v-if="ticket.sla_metric" class="flex flex-col space-y-1">
                                <!-- Response SLA -->
                                <span v-if="ticket.sla_metric.response_target_at" 
                                      class="inline-flex px-1.5 py-0.5 rounded text-[9px] font-black uppercase border items-center shadow-sm"
                                      :class="[
                                          ticket.sla_metric.is_response_breached ? 'bg-red-100 text-red-700 border-red-200 animate-pulse-red' : 
                                          (ticket.sla_metric.first_response_at ? 'bg-green-100 text-green-700 border-green-200' : 
                                          (isNearlyDue(ticket.sla_metric.response_target_at) ? 'bg-yellow-100 text-yellow-700 border-yellow-300 animate-pulse-yellow' : 'bg-blue-50 text-blue-700 border-blue-100'))
                                      ]">
                                    <svg v-if="ticket.sla_metric.is_response_breached" class="w-2 h-2 mr-1" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd" /></svg>
                                    <svg v-else-if="isNearlyDue(ticket.sla_metric.response_target_at) && !ticket.sla_metric.first_response_at" class="w-2 h-2 mr-1 text-yellow-600" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd" /></svg>
                                    RES: {{ ticket.sla_metric.is_response_breached ? 'BREACH' : (ticket.sla_metric.first_response_at ? 'MET' : 'OK') }}
                                </span>
                                <!-- Resolution SLA -->
                                <span v-if="ticket.sla_metric.resolution_target_at" 
                                      class="inline-flex px-1.5 py-0.5 rounded text-[9px] font-black uppercase border items-center shadow-sm"
                                      :class="[
                                          ticket.sla_metric.is_resolution_breached ? 'bg-red-100 text-red-700 border-red-200 animate-pulse-red' : 
                                          (ticket.sla_metric.resolved_at ? 'bg-green-100 text-green-700 border-green-200' : 
                                          (isNearlyDue(ticket.sla_metric.resolution_target_at) ? 'bg-yellow-100 text-yellow-700 border-yellow-300 animate-pulse-yellow' : 'bg-blue-50 text-blue-700 border-blue-100'))
                                      ]">
                                    <svg v-if="ticket.sla_metric.is_resolution_breached" class="w-2 h-2 mr-1" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd" /></svg>
                                    <svg v-else-if="isNearlyDue(ticket.sla_metric.resolution_target_at) && !ticket.sla_metric.resolved_at" class="w-2 h-2 mr-1 text-yellow-600" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd" /></svg>
                                    SLV: {{ ticket.sla_metric.is_resolution_breached ? 'BREACH' : (ticket.sla_metric.resolved_at ? 'MET' : 'OK') }}
                                </span>
                            </div>
                            <span v-else class="text-[10px] text-gray-400 italic">No SLA</span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                            <div v-if="ticket.reporter" class="flex items-center space-x-2">
                                <div v-if="ticket.reporter.profile_photo" class="h-6 w-6 rounded-full overflow-hidden border border-gray-200">
                                    <img :src="'/storage/' + ticket.reporter.profile_photo" class="h-full w-full object-cover" :alt="ticket.reporter.name">
                                </div>
                                <div v-else class="h-6 w-6 rounded-full bg-blue-50 flex items-center justify-center text-[10px] font-bold text-blue-600 border border-blue-100">
                                    {{ ticket.reporter.name.charAt(0) }}
                                </div>
                                <span class="text-gray-700 font-medium">{{ ticket.reporter.name }}</span>
                            </div>
                            <div v-else-if="ticket.sender_email" class="flex flex-col">
                                <span class="text-gray-700 font-medium">{{ ticket.sender_name || 'External User' }}</span>
                                <span class="text-[10px] text-gray-400 truncate max-w-[150px]">{{ ticket.sender_email }}</span>
                            </div>
                            <span v-else class="text-gray-400 italic">Unknown</span>
                        </td>
                         <td class="px-6 py-4 whitespace-nowrap text-sm" @click.stop>
                            <div v-if="ticket.assignee" class="flex items-center space-x-2">
                                <div v-if="ticket.assignee.profile_photo" class="h-6 w-6 rounded-full overflow-hidden border border-gray-200">
                                    <img :src="'/storage/' + ticket.assignee.profile_photo" class="h-full w-full object-cover" :alt="ticket.assignee.name">
                                </div>
                                <div v-else class="h-6 w-6 rounded-full bg-gray-200 flex items-center justify-center text-[10px] font-bold text-gray-600">
                                    {{ ticket.assignee.name.charAt(0) }}
                                </div>
                                <span class="text-gray-700 font-medium">{{ ticket.assignee.name }}</span>
                            </div>
                            <button 
                                v-else-if="hasPermission('tickets.assign')"
                                @click="acceptTicket(ticket)"
                                class="inline-flex items-center px-3 py-1 border border-blue-600 text-xs font-bold rounded-md text-blue-600 bg-white hover:bg-blue-600 hover:text-white transition-all focus:outline-none shadow-sm"
                            >
                                Accept Ticket
                            </button>
                            <!-- Child Assignees -->
                            <div v-for="child in ticket.children" :key="child.id" class="mt-2 ml-4">
                                <div v-if="child.assignee" class="flex items-center space-x-2">
                                    <div v-if="child.assignee.profile_photo" class="h-4 w-4 rounded-full overflow-hidden border border-gray-200">
                                        <img :src="'/storage/' + child.assignee.profile_photo" class="h-full w-full object-cover" :alt="child.assignee.name">
                                    </div>
                                    <div v-else class="h-4 w-4 rounded-full bg-blue-50 flex items-center justify-center text-[8px] font-bold text-blue-600">
                                        {{ child.assignee.name.charAt(0) }}
                                    </div>
                                    <span class="text-[10px] text-blue-600 font-medium italic">↳ {{ child.assignee.name }}</span>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 relative">
                            <span>{{ formatDate(ticket.created_at) }}</span>
                            <!-- Hover instruction -->
                            <div v-if="hasPermission('tickets.edit')" class="absolute inset-y-0 right-4 flex items-center opacity-0 group-hover:opacity-100 transition-opacity">
                                <span class="text-[10px] font-bold text-blue-600 uppercase bg-blue-100 px-2 py-1 rounded">View Details</span>
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
                            <label class="flex items-center space-x-3 cursor-pointer">
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

                            <div class="pt-2">
                                <label class="flex items-center space-x-3 cursor-pointer">
                                    <div class="relative">
                                        <input type="checkbox" v-model="createForm.notify_requester" class="sr-only peer">
                                        <div class="w-9 h-5 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-blue-600"></div>
                                    </div>
                                    <span class="text-xs font-medium text-gray-600">Send email notification to requester</span>
                                </label>
                            </div>
                        </div>

                        <div>
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
                                :options="stores"
                                label-key="name"
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
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Store <span class="text-red-500">*</span></label>
                            <Autocomplete
                                v-model="acceptForm.store_id"
                                :options="stores"
                                label-key="name"
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
                    </div>

                    <div class="flex justify-end space-x-3 pt-5 border-t mt-5">
                        <button type="button" @click="showAcceptModal = false" class="px-4 py-2 text-sm font-semibold text-gray-600 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors">Cancel</button>
                        <button
                            type="button"
                            @click="submitAcceptTicket"
                            :disabled="!acceptForm.store_id || !acceptForm.item_id"
                            class="px-6 py-2 bg-blue-600 text-white text-sm font-bold rounded-lg hover:bg-blue-700 shadow-md disabled:opacity-50 transition-all"
                        >
                            Accept Ticket
                        </button>
                    </div>
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
