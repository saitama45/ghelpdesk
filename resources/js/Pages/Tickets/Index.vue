<script setup>
import { Head, Link, useForm, usePage, router } from '@inertiajs/vue3';
import { ref, onMounted, watch, computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import DataTable from '@/Components/DataTable.vue';
import { useConfirm } from '@/Composables/useConfirm';
import { useErrorHandler } from '@/Composables/useErrorHandler';
import { useToast } from '@/Composables/useToast';
import { usePagination } from '@/Composables/usePagination';
import { usePermission } from '@/Composables/usePermission';

const props = defineProps({
    tickets: Object,
    staff: Array,
    companies: Array,
    filters: Object,
});

const page = usePage();
const showCreateModal = ref(false);
const fileInput = ref(null);
const isSubmitting = ref(false);
const { confirm } = useConfirm();
const { post, put, destroy } = useErrorHandler();
const { showSuccess, showError } = useToast();
const { hasPermission } = usePermission();

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

const pagination = usePagination(props.tickets, 'tickets.index');
const filterStatus = ref(props.filters?.status || 'open');

const filterOptions = [
    { value: 'all', label: 'All' },
    { value: 'my_tickets', label: 'My Tickets' },
    { value: 'open', label: 'Open' },
    { value: 'in_progress', label: 'In Progress' },
    { value: 'waiting', label: 'Waiting' },
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

const createForm = useForm({
    company_id: '',
    title: '',
    description: '',
    type: 'task',
    priority: 'medium',
    status: 'open',
    severity: 'minor',
    assignee_id: '',
    attachments: [],
});

// Set default company when modal opens or companies load
watch(() => showCreateModal.value, (isOpen) => {
    if (isOpen && !createForm.company_id) {
        createForm.company_id = defaultCompanyId.value;
    }
});

// Also watch defaultCompanyId in case it loads later
watch(defaultCompanyId, (newId) => {
    if (!createForm.company_id) {
        createForm.company_id = newId;
    }
}, { immediate: true });

const types = ['bug', 'feature', 'task', 'spike'];
const priorities = ['low', 'medium', 'high', 'urgent'];
const statuses = ['open', 'in_progress', 'closed', 'waiting'];
const severities = ['critical', 'major', 'minor', 'cosmetic'];

const handleFileSelect = (event) => {
    const files = Array.from(event.target.files);
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
            formData.append(key, createForm[key]);
        }
    });
    
    post(route('tickets.store'), formData, {
        onSuccess: () => {
            showCreateModal.value = false;
            createForm.reset();
            if (fileInput.value) fileInput.value.value = '';
            showSuccess('Ticket created successfully')
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
    if (!hasPermission('tickets.assign')) { // Or tickets.edit? Assuming 'assign' permission is needed to take ownership
         showError('You do not have permission to accept tickets.');
         return;
    }

    const acceptForm = useForm({
        company_id: ticket.company_id,
        title: ticket.title,
        description: ticket.description,
        type: ticket.type,
        priority: ticket.priority,
        status: ticket.status,
        severity: ticket.severity,
        assignee_id: page.props.auth.user.id
    });
    
    put(route('tickets.update', ticket.id), acceptForm.data(), {
        onSuccess: () => showSuccess('Ticket assigned to you successfully'),
        onError: (errors) => {
            const errorMessage = Object.values(errors).flat().join(', ') || 'Cannot accept ticket'
            showError(errorMessage)
        }
    });
};



const getPriorityColor = (priority) => {
    switch (priority) {
        case 'urgent': return 'text-red-900 bg-red-200';
        case 'high': return 'text-red-800 bg-red-100';
        case 'medium': return 'text-yellow-800 bg-yellow-100';
        case 'low': return 'text-green-800 bg-green-100';
        default: return 'text-gray-800 bg-gray-100';
    }
};

const getPriorityBorder = (priority) => {
    switch (priority) {
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
        case 'closed': return 'text-gray-600 bg-gray-200';
        case 'waiting': return 'text-orange-800 bg-orange-100';
        default: return 'text-gray-800 bg-gray-100';
    }
};

const getTypeColor = (type) => {
    switch (type) {
        case 'bug': return 'text-red-600';
        case 'feature': return 'text-green-600';
        case 'task': return 'text-blue-600';
        case 'spike': return 'text-purple-600';
        default: return 'text-gray-600';
    }
}
</script>

<template>
    <Head title="Tickets - Help Desk" />

    <AppLayout>
        <template #header>
            Tickets
        </template>

        <div class="space-y-6">
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
                    <div class="flex items-center space-x-3">
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
                            class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition-colors flex items-center space-x-2 text-sm font-medium shadow-sm"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                            </svg>
                            <span>Create Ticket</span>
                        </button>
                    </div>
                </template>

                <template #header>
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">#</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Title</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Priority</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
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
                        class="group hover:bg-blue-50/50 transition-all border-l-4"
                        :class="[getPriorityBorder(ticket.priority), hasPermission('tickets.edit') ? 'cursor-pointer' : 'cursor-not-allowed']"
                    >
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-gray-700">
                            {{ ticket.ticket_key }}
                        </td>
                        <td class="px-6 py-4">
                            <div class="flex flex-col">
                                <div class="text-sm font-semibold text-gray-900 group-hover:text-blue-700 transition-colors">{{ ticket.title }}</div>
                                <div class="text-xs text-gray-500 truncate max-w-xs">{{ ticket.description }}</div>
                            </div>
                        </td>
                         <td class="px-6 py-4 whitespace-nowrap">
                            <span class="text-xs font-bold uppercase tracking-tight" :class="getTypeColor(ticket.type)">
                                {{ ticket.type }}
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="inline-flex px-2.5 py-0.5 rounded-full text-xs font-bold capitalize shadow-sm" :class="getPriorityColor(ticket.priority)">
                                {{ ticket.priority }}
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="inline-flex px-2.5 py-0.5 rounded-full text-xs font-bold capitalize border" :class="getStatusColor(ticket.status)">
                                {{ ticket.status.replace('_', ' ') }}
                            </span>
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
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 relative">
                            <span>{{ new Date(ticket.created_at).toLocaleString() }}</span>
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
                <div class="fixed inset-0 bg-gray-900 bg-opacity-50 backdrop-blur-sm" @click="showCreateModal = false"></div>
                <div class="bg-white rounded-xl shadow-2xl max-w-md w-full p-6 relative border border-gray-100">
                    <div class="flex justify-between items-center mb-6">
                        <h3 class="text-xl font-bold text-gray-900">Create New Ticket</h3>
                        
                        <div class="flex flex-col items-end">
                            <label class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Status</label>
                            <select v-model="createForm.status" required class="bg-gray-50 border-none rounded-lg text-xs font-bold capitalize focus:ring-0 cursor-pointer shadow-sm" :class="getStatusColor(createForm.status)">
                                <option v-for="s in statuses" :key="s" :value="s">{{ s.replace('_', ' ') }}</option>
                            </select>
                         </div>
                    </div>

                    <form @submit.prevent="createTicket" class="space-y-5">
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Company</label>
                            <select v-model="createForm.company_id" required class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                                <option value="">Select Company</option>
                                <option v-for="company in availableCompanies" :key="company.id" :value="company.id">{{ company.name }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Title</label>
                            <input v-model="createForm.title" type="text" required class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Description</label>
                            <textarea v-model="createForm.description" rows="4" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm"></textarea>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Type</label>
                                <select v-model="createForm.type" required class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm capitalize">
                                    <option v-for="t in types" :key="t" :value="t">{{ t }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Priority</label>
                                <select v-model="createForm.priority" required class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm capitalize">
                                    <option v-for="p in priorities" :key="p" :value="p">{{ p }}</option>
                                </select>
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                             <div>
                                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Severity</label>
                                <select v-model="createForm.severity" required class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm capitalize">
                                    <option v-for="s in severities" :key="s" :value="s">{{ s }}</option>
                                </select>
                            </div>
                            <div v-if="hasPermission('tickets.assign')">
                                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Assign To</label>
                                <select v-model="createForm.assignee_id" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                                    <option value="">Unassigned</option>
                                    <option v-for="person in staff" :key="person.id" :value="person.id">{{ person.name }}</option>
                                </select>
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Attachments</label>
                            <input ref="fileInput" type="file" multiple accept="image/*,.pdf,.doc,.docx,.txt" @change="handleFileSelect" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
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


    </AppLayout>
</template>
