<script setup>
import { ref, computed } from 'vue'
import { Link, useForm, router, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { useToast } from '@/Composables/useToast'
import { usePermission } from '@/Composables/usePermission'
import { useConfirm } from '@/Composables/useConfirm'
import DynamicFormRenderer from '@/Components/DynamicFormRenderer.vue'

const props = defineProps({
    posRequest: Object,
    // 'live' | 'archived' | 'none' — why the request does or doesn't have a ticket.
    ticketState: {
        type: String,
        default: 'none',
    },
    // Present only when ticketState === 'archived'.
    archivedTicket: {
        type: Object,
        default: null,
    },
    users: {
        type: Array,
        default: () => [],
    },
})

const page = usePage()
const { showError } = useToast()
const { hasPermission } = usePermission()
const { confirm } = useConfirm()

const reminderLoading = ref(false)

// Approved request whose ticket never got generated — the recovery affordance.
const needsTicket = computed(() => props.posRequest.status === 'Approved' && props.ticketState === 'none')
// Approved request whose ticket was archived — explain, don't offer to regenerate.
const ticketArchived = computed(() => props.posRequest.status === 'Approved' && props.ticketState === 'archived')
const isGenerating = ref(false)

async function generateTicket() {
    const confirmed = await confirm({
        title: 'Generate Ticket',
        message: `This request is approved but has no ticket. Generate its ticket now?`,
        confirmLabel: 'Generate',
        variant: 'warning'
    })
    if (!confirmed) return
    isGenerating.value = true
    // No toasts here: the controller flashes success/error and AppLayout renders it.
    // Inertia treats a redirect carrying an error flash as onSuccess, so toasting
    // here would stack a bogus "generated" message on top of the real failure.
    router.post(route('pos-requests.generate-ticket', props.posRequest.id), {}, {
        preserveScroll: true,
        onFinish: () => { isGenerating.value = false },
    })
}

async function sendReminder() {
    const confirmed = await confirm({
        title: 'Send Approval Reminder',
        message: `Send an email reminder to the Stage ${props.posRequest.current_approval_level} approver(s) for this POS request?`,
        confirmLabel: 'Send Reminder',
        variant: 'warning'
    })
    if (!confirmed) return
    reminderLoading.value = true
    router.post(route('pos-requests.remind', props.posRequest.id), {}, {
        preserveScroll: true,
        onFinish: () => { reminderLoading.value = false },
    })
}

function ticketStatusClass(status) {
    const s = status ? status.toLowerCase() : '';
    const map = {
        'open': 'bg-blue-100 text-blue-700',
        'for_schedule': 'bg-teal-100 text-teal-700',
        'in_progress': 'bg-purple-100 text-purple-700',
        'resolved': 'bg-green-100 text-green-700',
        'closed': 'bg-gray-100 text-gray-600',
        'waiting_service_provider': 'bg-orange-100 text-orange-700',
        'waiting_client_feedback': 'bg-blue-100 text-blue-700',
    };
    return map[s] ?? 'bg-gray-100 text-gray-500';
}

const authUserId = computed(() => page.props.auth.user.id)

const approverFields = computed(() => props.posRequest.request_type?.form_schema?.approver_fields ?? [])
const approverMatrix = computed(() => props.posRequest.request_type?.approver_matrix ?? [])
const assignedApproversByLevel = computed(() => {
    const users = props.users ?? []

    return approverMatrix.value.reduce((carry, entry) => {
        const level = Number(entry.level)
        const ids = Array.isArray(entry.user_ids) ? entry.user_ids.map(Number) : []

        carry[level] = users.filter(user => ids.includes(Number(user.id)))

        return carry
    }, {})
})
const currentLevelAssignedApprovers = computed(() => {
    return assignedApproversByLevel.value[Number(props.posRequest.current_approval_level)] ?? []
})

const approvalForm = useForm({
    remarks: '',
    approver_data: { ... (props.posRequest.approver_data ?? {}) }
})

const validateApproverFields = () => {
    if (!approverFields.value.length) return true;
    
    for (const field of approverFields.value) {
        if (field.required && field.type !== 'toggle') {
            const val = approvalForm.approver_data[field.key];
            if (val === undefined || val === null || val === '' || (Array.isArray(val) && val.length === 0)) {
                showError(`${field.label} is required.`);
                return false;
            }
        }
    }
    return true;
};

const submitApproval = async () => {
    if (!validateApproverFields()) return;

    const confirmed = await confirm({
        title: 'Confirm Approval',
        message: `Are you sure you want to approve Stage ${props.posRequest.current_approval_level} for this request?`,
        confirmLabel: 'Approve Request',
        variant: 'success'
    })

    if (confirmed) {
        approvalForm.post(route('pos-requests.approve', props.posRequest.id), {
            onSuccess: () => {
                approvalForm.reset()
            },
            onError: () => showError('Approval failed')
        })
    }
}

const submitReject = async () => {
    if (!approvalForm.remarks.trim()) {
        showError('Remarks are required when rejecting a request.');
        return;
    }

    const confirmed = await confirm({
        title: 'Confirm Rejection',
        message: `Are you sure you want to reject Stage ${props.posRequest.current_approval_level}? This will cancel the entire request.`,
        confirmLabel: 'Reject Request',
        variant: 'danger'
    })

    if (confirmed) {
        approvalForm.post(route('pos-requests.reject', props.posRequest.id), {
            onSuccess: () => {
                approvalForm.reset()
            },
            onError: () => showError('Rejection failed')
        })
    }
}

const canApprove = computed(() => {
    const status = props.posRequest.status || ''
    const currentLevel = Number(props.posRequest.current_approval_level)
    const alreadyApprovedCurrentLevel = props.posRequest.approvals?.some(a =>
        Number(a.user_id) === Number(authUserId.value) &&
        Number(a.level) === currentLevel
    )
    const assignedApprovers = currentLevelAssignedApprovers.value
    const isAssignedApprover = assignedApprovers.length === 0 ||
        assignedApprovers.some(user => Number(user.id) === Number(authUserId.value))

    return (status === 'Open' || status.startsWith('Approved Level')) && 
           hasPermission('pos_requests.approve') &&
           currentLevel > 0 &&
           isAssignedApprover &&
           !alreadyApprovedCurrentLevel;
})

const getStatusBadgeClass = (status) => {
    if (!status) return 'bg-gray-500 text-white'
    if (status.startsWith('Approved Level')) return 'bg-blue-500 text-white shadow-blue-200'
    switch (status) {
        case 'Approved': return 'bg-emerald-500 text-white shadow-emerald-200'
        case 'Open': return 'bg-blue-500 text-white shadow-blue-200'
        case 'Rejected': return 'bg-rose-500 text-white shadow-rose-200'
        case 'Cancelled': return 'bg-rose-500 text-white shadow-rose-200'
        default: return 'bg-amber-500 text-white shadow-amber-200'
    }
}

const storesCoveredDisplayLimit = 12
const showStoresCoveredModal = ref(false)

const storesCovered = computed(() => {
    return Array.isArray(props.posRequest.stores_covered)
        ? props.posRequest.stores_covered.filter(Boolean)
        : []
})

const coversAllStores = computed(() => storesCovered.value.includes('all'))
const visibleStoresCovered = computed(() => coversAllStores.value ? [] : storesCovered.value.slice(0, storesCoveredDisplayLimit))
const hiddenStoresCovered = computed(() => coversAllStores.value ? [] : storesCovered.value.slice(storesCoveredDisplayLimit))
const hiddenStoresCoveredCount = computed(() => hiddenStoresCovered.value.length)

const openStoresCoveredModal = () => {
    if (!hiddenStoresCoveredCount.value) return
    showStoresCoveredModal.value = true
}

const closeStoresCoveredModal = () => {
    showStoresCoveredModal.value = false
}

const totalLevels = computed(() => Number(props.posRequest.request_type?.approval_levels || 0))
const stages = computed(() => Array.from({ length: totalLevels.value }, (_, i) => i + 1))

const getApprovalForLevel = (lvl) => {
    if (!props.posRequest.approvals) return null
    return props.posRequest.approvals.find(a => Number(a.level) === Number(lvl))
}

// ── Schema helpers ────────────────────────────────────────────────────────────
const schemaItemsColumns = computed(() => props.posRequest.request_type?.form_schema?.items_columns ?? [])
const schemaFields       = computed(() => props.posRequest.request_type?.form_schema?.fields ?? [])
const hasSchemaItems     = computed(() => !!props.posRequest.request_type?.form_schema?.has_items && schemaItemsColumns.value.length > 0)
const hasSchema          = computed(() => schemaFields.value.length > 0 || hasSchemaItems.value)

// Items from form_data.items (schema) or from the legacy details relation (hard-coded)
const lineItems = computed(() =>
    hasSchemaItems.value
        ? (props.posRequest.form_data?.items ?? [])
        : (props.posRequest.details ?? [])
)

// Regular (non-tabular) form fields stored in form_data
const regularFormData = computed(() => {
    const fd = { ...(props.posRequest.form_data ?? {}) }
    delete fd.items
    return fd
})

const getLabel = (key, value) => {
    const schema = props.posRequest.request_type?.form_schema;
    if (!schema) return value;

    // For POS requests, details are in items_columns
    const field = (schema.items_columns || []).find(f => f.key === key);

    if (field && field.options && field.options.length > 0) {
        if (Array.isArray(value)) {
            return value.map(v => {
                const opt = field.options.find(o => String(o.value) === String(v));
                return opt ? opt.label : v;
            }).join(', ');
        }
        const opt = field.options.find(o => String(o.value) === String(value));
        return opt ? opt.label : value;
    }

    if (key === 'mgr_meal') {
        return (value === 'Yes' || value === true || value == 1) ? 'Yes' : 'No';
    }

    if (Array.isArray(value)) return value.join(', ');
    return value ?? '—';
};

const getCellValue = (item, col) => {
    const v = item[col.key]
    if (v === null || v === undefined || v === '') return '—'
    if (typeof v === 'boolean') return v ? 'Yes' : 'No'
    if (col.options?.length > 0) {
        if (Array.isArray(v)) {
            return col.options.filter(o => v.includes(o.value)).map(o => o.label).join(', ') || '—'
        }
        const opt = col.options.find(o => String(o.value) === String(v))
        return opt ? opt.label : v
    }
    if (Array.isArray(v)) return v.join(', ')
    return v
}

const getFieldLabel = (key, value) => {
    if (value === null || value === undefined || value === '') return '—'
    if (typeof value === 'boolean') return value ? 'Yes' : 'No'
    const field = schemaFields.value.find(f => f.key === key)
    if (field?.options?.length > 0) {
        if (Array.isArray(value)) {
            return field.options.filter(o => value.includes(o.value)).map(o => o.label).join(', ') || '—'
        }
        const opt = field.options.find(o => String(o.value) === String(value))
        return opt ? opt.label : value
    }
    if (Array.isArray(value)) return value.join(', ')
    return value
}

const isFileField = (key, isItem = false) => {
    const fields = isItem ? schemaItemsColumns.value : schemaFields.value;
    const field = fields.find(f => f.key === key);
    return field && field.type === 'file';
};

const isFileArray = (value) => {
    return Array.isArray(value);
};

const getFileName = (value) => {
    if (!value) return 'Attachment';
    if (typeof value === 'object' && value.name) return value.name;
    if (typeof value !== 'string') return 'Attachment';

    const cleanPath = value.replace(/^public\//, '').replace(/^storage\//, '');
    return cleanPath.split(/[/\\]/).pop() || 'Attachment';
};

const getFileUrl = (value) => {
    if (!value) return '#';

    let path = typeof value === 'object' ? value.path : value;
    let name = typeof value === 'object' ? value.name : '';

    if (typeof path !== 'string') return '#';

    // If we have a name, use our download route to ensure the filename is correct
    if (name) {
        return route('attachments.download', { path, name });
    }

    let cleanPath = path;
    if (cleanPath.startsWith('/')) cleanPath = cleanPath.substring(1);
    if (cleanPath.startsWith('public/')) cleanPath = cleanPath.substring(7);
    if (cleanPath.startsWith('storage/')) cleanPath = cleanPath.substring(8);
    if (!cleanPath) return '#';
    return `/storage/${cleanPath}`;
};

const formatDateTime = (dateStr) => {
    if (!dateStr) return ''
    try {
        const date = new Date(dateStr)
        return date.toLocaleString('en-US', {
            month: 'short',
            day: 'numeric',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        })
    } catch (e) {
        return dateStr
    }
}
</script>

<template>
    <AppLayout :title="`POS Request #${posRequest.id}`">
        <div class="py-12 bg-gray-50 min-h-screen dark:bg-gray-900/50">
            <div class="max-w-[1600px] mx-auto sm:px-6 lg:px-8">
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                    
                    <!-- Left: Request Details (2 cols) -->
                    <div class="lg:col-span-2 space-y-8">
                        <!-- Header Detail Card -->
                        <div class="bg-white rounded-[2.5rem] shadow-2xl shadow-gray-200/50 p-10 border border-gray-100 relative overflow-hidden dark:bg-gray-800 dark:border-gray-700">
                            <div class="absolute top-0 right-0 p-8">
                                <span :class="getStatusBadgeClass(posRequest.status)" class="px-8 py-3 rounded-2xl text-xs font-black uppercase tracking-[0.2em] shadow-lg text-center min-w-[150px]">
                                    {{ posRequest.status }}
                                </span>
                            </div>

                            <h1 class="text-4xl font-black text-gray-900 tracking-tighter mb-10 flex items-center dark:text-gray-100">
                                <span class="text-indigo-600 mr-3">#{{ posRequest.id }}</span>
                                {{ posRequest.request_type.name }}
                            </h1>

                            <div class="grid grid-cols-2 md:grid-cols-5 gap-10">
                                <div>
                                    <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2 dark:text-gray-400">Company</label>
                                    <p class="text-lg font-bold text-gray-900 dark:text-gray-100">{{ posRequest.company.name }}</p>
                                </div>
                                <div>
                                    <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2 dark:text-gray-400">Requester</label>
                                    <p class="text-lg font-bold text-gray-900 dark:text-gray-100">{{ posRequest.user ? posRequest.user.name : (posRequest.requester_name || 'Public Submission') }}</p>
                                    <p v-if="!posRequest.user && posRequest.requester_email" class="text-[10px] text-gray-400 font-bold dark:text-gray-400">{{ posRequest.requester_email }}</p>
                                </div>
                                <div>
                                    <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2 dark:text-gray-400">Requested Date</label>
                                    <p class="text-lg font-mono font-black text-gray-600 dark:text-gray-300">{{ formatDateTime(posRequest.created_at) }}</p>
                                </div>
                                <div>
                                    <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2 dark:text-gray-400">Launch Date</label>
                                    <p class="text-lg font-mono font-black text-indigo-600">{{ posRequest.launch_date }}</p>
                                </div>
                                <div>
                                    <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2 dark:text-gray-400">Effectivity</label>
                                    <p class="text-lg font-mono font-black text-emerald-600">{{ posRequest.effectivity_date }}</p>
                                </div>
                            </div>

                            <div class="mt-10 pt-10 border-t border-gray-100 dark:border-gray-700">
                                <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-4 dark:text-gray-300">Stores Covered</label>
                                <div class="flex flex-wrap gap-2">
                                    <template v-if="coversAllStores">
                                        <span class="px-6 py-2 bg-indigo-600 text-white rounded-xl text-xs font-black uppercase italic tracking-widest shadow-lg shadow-indigo-100">All Stores</span>
                                    </template>
                                    <template v-else>
                                        <span v-for="code in visibleStoresCovered" :key="code" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-xl text-xs font-bold border border-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:border-gray-700">
                                            {{ code }}
                                        </span>
                                        <button
                                            v-if="hiddenStoresCoveredCount > 0"
                                            type="button"
                                            @click="openStoresCoveredModal"
                                            class="px-4 py-2 bg-indigo-50 text-indigo-700 rounded-xl text-xs font-black border border-indigo-200 hover:bg-indigo-100 transition-colors"
                                        >
                                            {{ hiddenStoresCoveredCount }}+ more
                                        </button>
                                    </template>
                                </div>
                            </div>
                        </div>

                        <!-- Regular Schema Fields (non-tabular) -->
                        <div v-if="schemaFields.length > 0 && Object.keys(regularFormData).length > 0"
                             class="bg-white rounded-[2.5rem] shadow-2xl shadow-gray-200/50 p-10 border border-gray-100 dark:bg-gray-800 dark:border-gray-700">
                            <h3 class="text-2xl font-black text-gray-900 mb-8 dark:text-gray-100">Form Details</h3>
                            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                                <div v-for="field in schemaFields" :key="field.key" class="bg-gray-50 rounded-2xl p-5 dark:bg-gray-900/50">
                                    <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1.5 dark:text-gray-400">{{ field.label }}</label>
                                    <div class="text-sm font-bold text-gray-900 dark:text-gray-100">
                                        <template v-if="isFileField(field.key)">
                                            <div v-if="isFileArray(regularFormData[field.key])" class="flex flex-col gap-1">
                                                <a v-for="(file, fi) in regularFormData[field.key]" :key="fi" :href="getFileUrl(file)" target="_blank" rel="noopener noreferrer" :download="getFileName(file)" class="inline-flex items-center text-indigo-600 hover:text-indigo-800 hover:underline">
                                                    <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/></svg>
                                                    {{ getFileName(file) }}
                                                </a>
                                            </div>
                                            <a v-else-if="regularFormData[field.key]" :href="getFileUrl(regularFormData[field.key])" target="_blank" rel="noopener noreferrer" :download="getFileName(regularFormData[field.key])" class="inline-flex items-center text-indigo-600 hover:text-indigo-800 hover:underline">
                                                <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/></svg>
                                                {{ getFileName(regularFormData[field.key]) }}
                                            </a>
                                            <span v-else>—</span>
                                        </template>
                                        <template v-else>
                                            {{ getFieldLabel(field.key, regularFormData[field.key]) }}
                                        </template>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Line Items — Schema-driven (dynamic columns) -->
                        <div v-if="hasSchemaItems"
                             class="bg-white rounded-[2.5rem] shadow-2xl shadow-gray-200/50 p-10 border border-gray-100 dark:bg-gray-800 dark:border-gray-700">
                            <div class="flex items-center justify-between mb-8">
                                <h3 class="text-2xl font-black text-gray-900 dark:text-gray-100">Line Items</h3>
                                <span class="px-4 py-1.5 bg-indigo-50 text-indigo-700 rounded-xl text-[10px] font-black uppercase tracking-widest">
                                    {{ lineItems.length }} {{ lineItems.length === 1 ? 'Item' : 'Items' }}
                                </span>
                            </div>

                            <div v-if="lineItems.length === 0" class="text-center py-12 text-gray-400 font-bold text-sm italic dark:text-gray-400">
                                No line items recorded.
                            </div>
                            <div v-else class="overflow-x-auto custom-scrollbar">
                                <table class="w-full border-separate border-spacing-y-3 min-w-max">
                                    <thead>
                                        <tr class="text-[10px] font-black text-gray-500 uppercase tracking-widest text-left dark:text-slate-300">
                                            <th class="px-4 pb-4 text-center">#</th>
                                            <th v-for="col in schemaItemsColumns" :key="col.key" class="px-4 pb-4 whitespace-nowrap">
                                                {{ col.label }}
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="(item, idx) in lineItems" :key="idx"
                                            class="bg-gray-50/50 hover:bg-white hover:shadow-xl transition-all duration-300 rounded-2xl group dark:bg-slate-900/70 dark:hover:bg-slate-800 dark:hover:shadow-black/20">
                                            <td class="px-4 py-5 rounded-l-2xl text-center text-[10px] font-black text-gray-400 dark:text-slate-400">
                                                {{ idx + 1 }}
                                            </td>
                                            <td v-for="col in schemaItemsColumns" :key="col.key"
                                                class="px-4 py-5 last:rounded-r-2xl text-sm font-bold text-gray-700 whitespace-nowrap dark:text-slate-100">
                                                <template v-if="isFileField(col.key, true)">
                                                    <div v-if="isFileArray(item[col.key])" class="flex flex-col gap-1">
                                                        <a v-for="(file, fi) in item[col.key]" :key="fi" :href="getFileUrl(file)" target="_blank" :download="getFileName(file)" class="inline-flex items-center text-indigo-600 hover:text-indigo-800 hover:underline">
                                                            <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/></svg>
                                                            {{ getFileName(file) }}
                                                        </a>
                                                    </div>
                                                    <a v-else-if="item[col.key]" :href="getFileUrl(item[col.key])" target="_blank" :download="getFileName(item[col.key])" class="inline-flex items-center text-indigo-600 hover:text-indigo-800 hover:underline">
                                                        <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/></svg>
                                                        {{ getFileName(item[col.key]) }}
                                                    </a>
                                                    <span v-else>—</span>
                                                </template>
                                                <template v-else>
                                                    {{ getCellValue(item, col) }}
                                                </template>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Line Items — Hard-coded fallback (legacy types) -->
                        <div v-else-if="!hasSchema && posRequest.details?.length > 0"
                             class="bg-white rounded-[2.5rem] shadow-2xl shadow-gray-200/50 p-10 border border-gray-100 dark:bg-gray-800 dark:border-gray-700">
                            <h3 class="text-2xl font-black text-gray-900 mb-8 dark:text-gray-100">Detailed Configuration</h3>
                            <div class="overflow-x-auto custom-scrollbar">
                                <table class="w-full border-separate border-spacing-y-3">
                                    <thead>
                                        <tr class="text-[10px] font-black text-gray-500 uppercase tracking-widest text-left dark:text-slate-300">
                                            <th class="px-6 pb-4">Product</th>
                                            <th class="px-6 pb-4">POS Alias</th>
                                            <th class="px-6 pb-4 text-center">Price Type</th>
                                            <th class="px-6 pb-4 text-center">Amount</th>
                                            <th class="px-6 pb-4">Category</th>
                                            <th class="px-6 pb-4">Item Code</th>
                                            <th class="px-6 pb-4">Remarks/Mechanics</th>
                                            <th class="px-6 pb-4 text-center">SC</th>
                                            <th class="px-6 pb-4 text-center">Tax</th>
                                            <th class="px-6 pb-4 text-center">Meal</th>
                                            <th class="px-6 pb-4 text-center">Printer</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="item in posRequest.details" :key="item.id"
                                            class="bg-gray-50/50 hover:bg-white hover:shadow-xl transition-all duration-300 rounded-2xl group dark:bg-slate-900/70 dark:hover:bg-slate-800 dark:hover:shadow-black/20">
                                            <td class="px-6 py-5 rounded-l-2xl">
                                                <div class="font-black text-gray-900 group-hover:text-indigo-600 transition-colors dark:text-slate-100 dark:group-hover:text-indigo-200">{{ item.product_name }}</div>
                                            </td>
                                            <td class="px-6 py-5 font-bold text-gray-700 dark:text-slate-200">{{ item.pos_name }}</td>
                                            <td class="px-6 py-5 text-center">
                                                <span class="px-3 py-1 bg-white border border-gray-200 rounded-lg text-[10px] font-black uppercase text-gray-600 shadow-sm dark:border-slate-700 dark:bg-slate-950 dark:text-slate-200">
                                                    {{ getLabel('price_type', item.price_type) }}
                                                </span>
                                            </td>
                                            <td class="px-6 py-5 text-center font-black text-indigo-600 dark:text-indigo-300">
                                                {{ item.price_amount ? Number(item.price_amount).toLocaleString(undefined, { minimumFractionDigits: 2 }) : '-' }}
                                            </td>
                                            <td class="px-6 py-5 font-bold text-gray-700 dark:text-slate-200">{{ getLabel('category', item.category) }}</td>
                                            <td class="px-6 py-5 font-mono text-xs font-black text-gray-700 dark:text-slate-100">{{ item.item_code }}</td>
                                            <td class="px-6 py-5">
                                                <div class="text-[11px] text-gray-600 font-medium max-w-[200px] truncate dark:text-slate-200" :title="item.remarks_mechanics">
                                                    {{ item.remarks_mechanics || '-' }}
                                                </div>
                                            </td>
                                            <td class="px-6 py-5 text-center font-bold text-gray-600 dark:text-slate-200">{{ item.sc || '-' }}</td>
                                            <td class="px-6 py-5 text-center font-bold text-gray-600 dark:text-slate-200">{{ item.local_tax || '-' }}</td>
                                            <td class="px-6 py-5 text-center font-bold text-gray-600 dark:text-slate-200">{{ getLabel('mgr_meal', item.mgr_meal) }}</td>
                                            <td class="px-6 py-5 text-center rounded-r-2xl font-bold text-gray-500 dark:text-slate-300">{{ item.printer }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Right: Approval Sidebar (1 col) -->
                    <div class="space-y-8">
                        <!-- Approval Pulse Tracker -->
                        <div class="bg-white rounded-[2.5rem] shadow-2xl shadow-gray-200/50 p-8 border border-gray-100 dark:bg-gray-800 dark:border-gray-700">
                            <div class="flex items-center justify-between mb-10">
                                <h3 class="text-xl font-black text-gray-900 tracking-tight dark:text-gray-100">Approval Pulse</h3>
                                <span v-if="posRequest.status === 'Approved'" class="px-3 py-1 bg-emerald-100 text-emerald-700 rounded-lg text-[9px] font-black uppercase tracking-widest">
                                    Finalized
                                </span>
                            </div>

                            <!-- Linked Ticket Status & SLA -->
                            <div v-if="posRequest.ticket" class="mb-8 bg-gray-50 rounded-2xl border border-gray-100 p-5 dark:bg-gray-900/50 dark:border-gray-700">
                                <div class="flex items-center justify-between mb-4">
                                    <h4 class="text-[10px] font-black text-gray-400 uppercase tracking-widest dark:text-gray-400">Linked Ticket Status</h4>
                                    <span class="px-3 py-1 rounded-lg text-[9px] font-black uppercase tracking-widest" :class="ticketStatusClass(posRequest.ticket.status)">
                                        {{ posRequest.ticket.status.replace(/_/g, ' ') }}
                                    </span>
                                </div>

                                <div v-if="posRequest.ticket.sla_metric" class="space-y-3">
                                    <h4 class="text-[10px] font-black text-gray-400 uppercase tracking-widest mt-4 dark:text-gray-400">Ticket SLA</h4>
                                    <!-- Response Target -->
                                    <div class="p-3 rounded-xl border" :class="posRequest.ticket.sla_metric.is_response_breached ? 'bg-red-50 border-red-100' : (posRequest.ticket.sla_metric.first_response_at ? 'bg-green-50 border-green-100' : 'bg-white border-gray-100')">
                                        <div class="flex justify-between items-center mb-1">
                                            <span class="text-[9px] font-black text-gray-500 uppercase dark:text-gray-300">Response Target</span>
                                            <span v-if="posRequest.ticket.sla_metric.is_response_breached" class="text-[9px] font-black text-red-600 uppercase">BREACHED</span>
                                            <span v-else-if="posRequest.ticket.sla_metric.first_response_at" class="text-[9px] font-black text-green-600 uppercase">MET</span>
                                            <span v-else class="text-[9px] font-black text-blue-600 uppercase">ACTIVE</span>
                                        </div>
                                        <div class="text-[11px] font-bold text-gray-900 truncate dark:text-gray-100">
                                            {{ posRequest.ticket.sla_metric.first_response_at ? formatDateTime(posRequest.ticket.sla_metric.first_response_at) : (posRequest.ticket.sla_metric.response_target_at ? formatDateTime(posRequest.ticket.sla_metric.response_target_at) : 'No target') }}
                                        </div>
                                    </div>
                                    <!-- Resolution Target -->
                                    <div class="p-3 rounded-xl border" :class="posRequest.ticket.sla_metric.is_resolution_breached ? 'bg-red-50 border-red-100' : (posRequest.ticket.sla_metric.resolved_at ? 'bg-green-50 border-green-100' : 'bg-white border-gray-100')">
                                        <div class="flex justify-between items-center mb-1">
                                            <span class="text-[9px] font-black text-gray-500 uppercase dark:text-gray-300">Resolution Target</span>
                                            <span v-if="posRequest.ticket.sla_metric.is_resolution_breached" class="text-[9px] font-black text-red-600 uppercase">BREACHED</span>
                                            <span v-else-if="posRequest.ticket.sla_metric.resolved_at" class="text-[9px] font-black text-green-600 uppercase">MET</span>
                                            <span v-else class="text-[9px] font-black text-blue-600 uppercase">ACTIVE</span>
                                        </div>
                                        <div class="text-[11px] font-bold text-gray-900 truncate dark:text-gray-100">
                                            {{ posRequest.ticket.sla_metric.resolved_at ? formatDateTime(posRequest.ticket.sla_metric.resolved_at) : (posRequest.ticket.sla_metric.resolution_target_at ? formatDateTime(posRequest.ticket.sla_metric.resolution_target_at) : 'No target') }}
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Archived ticket: explain why there's no ticket. Restoring the
                                 archived ticket is the fix, so no Generate button here. -->
                            <div v-if="ticketArchived" class="mb-8 bg-rose-50 rounded-2xl border border-rose-200 p-5 dark:bg-rose-500/10 dark:border-rose-500/30">
                                <div class="flex items-start gap-3">
                                    <svg class="w-5 h-5 text-rose-500 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4" />
                                    </svg>
                                    <div>
                                        <h4 class="text-[11px] font-black text-rose-700 uppercase tracking-widest dark:text-rose-300">Ticket Archived</h4>
                                        <p class="text-xs text-rose-700/80 mt-1 dark:text-rose-200/80">
                                            This request's ticket
                                            <span v-if="archivedTicket?.ticket_key" class="font-bold">{{ archivedTicket.ticket_key }}</span>
                                            was archived, so it no longer appears here. Restore the ticket from the archive to relink it — generating a new one would create a duplicate.
                                        </p>
                                        <dl class="mt-3 space-y-1 text-xs text-rose-700/90 dark:text-rose-200/90">
                                            <div class="flex gap-2">
                                                <dt class="font-bold min-w-[76px]">Archived by</dt>
                                                <dd>{{ archivedTicket?.deleted_by || 'Unknown (archived before this was recorded)' }}</dd>
                                            </div>
                                            <div class="flex gap-2">
                                                <dt class="font-bold min-w-[76px]">Archived on</dt>
                                                <dd>{{ archivedTicket?.deleted_at ? formatDateTime(archivedTicket.deleted_at) : 'Unknown' }}</dd>
                                            </div>
                                        </dl>
                                    </div>
                                </div>
                            </div>

                            <!-- Missing-ticket recovery: approved but no ticket was generated -->
                            <div v-if="needsTicket" class="mb-8 bg-amber-50 rounded-2xl border border-amber-200 p-5 dark:bg-amber-500/10 dark:border-amber-500/30">
                                <div class="flex items-start gap-3">
                                    <svg class="w-5 h-5 text-amber-500 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M12 3a9 9 0 100 18 9 9 0 000-18z" />
                                    </svg>
                                    <div>
                                        <h4 class="text-[11px] font-black text-amber-700 uppercase tracking-widest dark:text-amber-300">No Ticket Generated</h4>
                                        <p class="text-xs text-amber-700/80 mt-1 dark:text-amber-200/80">This request is approved but its ticket was not created. You can generate it now.</p>
                                    </div>
                                </div>
                                <button
                                    v-if="hasPermission('pos_requests.approve')"
                                    @click="generateTicket"
                                    :disabled="isGenerating"
                                    class="mt-4 w-full inline-flex items-center justify-center gap-2 px-4 py-2.5 bg-emerald-600 text-white rounded-xl text-sm font-black hover:bg-emerald-700 transition-colors shadow-sm disabled:opacity-50"
                                >
                                    <svg v-if="isGenerating" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 6.477 0 12h4z"/>
                                    </svg>
                                    <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                                    </svg>
                                    {{ isGenerating ? 'Generating…' : 'Generate Ticket' }}
                                </button>
                            </div>

                            <div class="relative px-2">
                                <!-- Vertical Line -->
                                <div class="absolute left-[27px] top-2 bottom-2 w-1 bg-gradient-to-b from-indigo-500 via-gray-100 to-gray-50 rounded-full"></div>

                                <div class="space-y-12">
                                    <div v-for="lvl in stages" :key="lvl" class="relative pl-16">
                                        <!-- Level Indicator Node -->
                                        <div :class="[
                                            'absolute left-0 w-10 h-10 rounded-2xl flex items-center justify-center border-4 border-white shadow-xl z-10 transition-all duration-700',
                                            getApprovalForLevel(lvl) || posRequest.status === 'Approved' ? 'bg-emerald-500 scale-110' : 
                                            Number(lvl) === Number(posRequest.current_approval_level) ? 'bg-indigo-600 scale-125 ring-8 ring-indigo-50' : 'bg-white border-gray-100'
                                        ]">
                                            <svg v-if="getApprovalForLevel(lvl) || posRequest.status === 'Approved'" class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7" />
                                            </svg>
                                            <span v-else :class="Number(lvl) === Number(posRequest.current_approval_level) ? 'text-white' : 'text-gray-300'" class="text-xs font-black">{{ lvl }}</span>
                                        </div>

                                        <div class="transition-all duration-500" :class="!getApprovalForLevel(lvl) && Number(lvl) !== Number(posRequest.current_approval_level) && posRequest.status !== 'Approved' ? 'opacity-40' : 'opacity-100'">
                                            <div class="flex items-center justify-between gap-4">
                                                <span class="text-xs font-black uppercase tracking-[0.2em] whitespace-nowrap" :class="getApprovalForLevel(lvl) || Number(lvl) === Number(posRequest.current_approval_level) ? 'text-indigo-600' : 'text-gray-500'">
                                                    Stage {{ lvl }}
                                                </span>
                                                <span v-if="getApprovalForLevel(lvl)" class="text-[11px] font-bold text-emerald-600 font-mono whitespace-nowrap">
                                                    {{ formatDateTime(getApprovalForLevel(lvl).created_at) }}
                                                </span>
                                            </div>

                                            <!-- Approval Detail Card -->
                                            <div v-if="getApprovalForLevel(lvl)" class="mt-3 p-4 bg-gray-50 rounded-2xl border border-gray-100 shadow-sm relative overflow-hidden group dark:bg-gray-900/50 dark:border-gray-700">
                                                <div class="absolute top-0 left-0 w-1 h-full bg-emerald-500"></div>
                                                <div class="flex items-center mb-2">
                                                    <div class="w-6 h-6 rounded-full bg-indigo-100 flex items-center justify-center text-[10px] font-black text-indigo-600 mr-2 capitalize">
                                                        {{ getApprovalForLevel(lvl).user?.name?.charAt(0) || '?' }}
                                                    </div>
                                                    <span class="text-xs font-black text-gray-900 dark:text-gray-100">{{ getApprovalForLevel(lvl).user?.name || 'Unknown User' }}</span>
                                                </div>
                                                <p v-if="getApprovalForLevel(lvl).remarks" class="text-[11px] text-gray-700 italic font-medium leading-relaxed dark:text-gray-300">
                                                    "{{ getApprovalForLevel(lvl).remarks }}"
                                                </p>
                                                <p v-else class="text-[9px] text-gray-400 font-bold uppercase tracking-tighter dark:text-gray-400">Approved without remarks</p>
                                            </div>

                                            <!-- Pending State -->
                                            <div v-else-if="Number(lvl) === Number(posRequest.current_approval_level)" class="mt-3 p-4 bg-indigo-50/50 rounded-2xl border-2 border-dashed border-indigo-200">
                                                <div class="flex items-center justify-between">
                                                    <div class="flex items-center text-indigo-600">
                                                        <svg class="w-4 h-4 mr-2 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                                        </svg>
                                                        <span class="text-[10px] font-black uppercase tracking-widest">Awaiting Decision</span>
                                                    </div>
                                                    <!-- Reminder Button -->
                                                    <button
                                                        @click="sendReminder"
                                                        :disabled="reminderLoading"
                                                        title="Send email reminder to approvers"
                                                        class="flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-[10px] font-black uppercase tracking-widest transition-all"
                                                        :class="reminderLoading ? 'bg-gray-100 text-gray-400 cursor-wait' : 'bg-amber-50 text-amber-600 border border-amber-200 hover:bg-amber-100 hover:border-amber-300'"
                                                    >
                                                        <svg class="w-3 h-3" :class="{ 'animate-pulse': reminderLoading }" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                                                        {{ reminderLoading ? 'Sending...' : 'Remind' }}
                                                    </button>
                                                </div>
                                                <div v-if="(assignedApproversByLevel[Number(lvl)] ?? []).length > 0" class="mt-3 pt-3 border-t border-indigo-100">
                                                    <p class="text-[9px] font-black uppercase tracking-widest text-indigo-500 mb-2">Assigned Approvers</p>
                                                    <div class="flex flex-wrap gap-2">
                                                        <span
                                                            v-for="approver in assignedApproversByLevel[Number(lvl)]"
                                                            :key="approver.id"
                                                            class="px-2.5 py-1 rounded-full bg-white text-indigo-700 border border-indigo-200 text-[10px] font-bold dark:bg-gray-800"
                                                        >
                                                            {{ approver.name }}
                                                        </span>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Future State -->
                                            <div v-else class="mt-3 px-4 py-2 text-[10px] font-bold text-gray-400 uppercase italic dark:text-gray-400">
                                                Locked
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Action Area -->
                            <div v-if="canApprove" class="mt-12 pt-10 border-t border-gray-100 relative dark:border-gray-700">
                                <div class="absolute -top-3 left-1/2 -translate-x-1/2 px-4 bg-white text-[9px] font-black text-indigo-500 uppercase tracking-[0.3em] dark:bg-gray-800">Your Decision</div>

                                <!-- Approver Fields -->
                                <div v-if="approverFields.length > 0" class="mb-6 bg-white border-2 border-indigo-100 rounded-[2rem] p-6 shadow-sm dark:bg-gray-800">
                                    <h4 class="text-[10px] font-black text-indigo-600 uppercase tracking-widest mb-4">Required Approver Details</h4>
                                    <DynamicFormRenderer
                                        :fields="approverFields"
                                        v-model="approvalForm.approver_data"
                                        :errors="approvalForm.errors"
                                        grid-columns="1"
                                        gap="4"
                                        dense
                                    />
                                </div>

                                <div class="mb-6">
                                    <label class="block text-[9px] font-black text-slate-600 uppercase tracking-widest mb-3 ml-1">Approval/Rejection Remarks</label>
                                    <textarea v-model="approvalForm.remarks" rows="3" :placeholder="`Add comments for Stage ${posRequest.current_approval_level}...`" 
                                              class="w-full bg-gray-50 border-2 border-gray-100 rounded-3xl p-5 text-sm font-medium focus:bg-white focus:border-indigo-500 focus:ring-0 transition-all shadow-inner text-gray-900 dark:bg-gray-900/50 dark:text-gray-100 dark:border-gray-700"></textarea>
                                </div>
                                <div class="flex flex-col sm:flex-row gap-4">
                                    <button @click="submitReject" :disabled="approvalForm.processing"
                                            class="flex-1 py-4 bg-white text-red-600 border-2 border-red-100 rounded-2xl font-black text-xs uppercase tracking-widest shadow-lg hover:bg-red-50 transform hover:-translate-y-0.5 active:scale-95 transition-all disabled:opacity-50 text-center dark:bg-gray-800">
                                        Reject Request
                                    </button>
                                    <button @click="submitApproval" :disabled="approvalForm.processing"
                                            class="flex-1 py-4 bg-indigo-600 text-white rounded-2xl font-black text-xs uppercase tracking-widest shadow-2xl shadow-indigo-100 hover:bg-indigo-700 transform hover:-translate-y-0.5 active:scale-95 transition-all disabled:opacity-50 text-center">
                                        Release Level {{ posRequest.current_approval_level }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div v-if="showStoresCoveredModal" class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex items-center justify-center min-h-screen px-4 py-6">
                <div class="fixed inset-0 bg-black/20 backdrop-blur-md" @click="closeStoresCoveredModal"></div>
                <div class="relative bg-white rounded-[2rem] shadow-2xl w-full max-w-3xl p-8 border border-gray-100 dark:bg-gray-800 dark:border-gray-700">
                    <div class="flex items-start justify-between gap-4 mb-6">
                        <div>
                            <h3 class="text-2xl font-black text-gray-900 dark:text-gray-100">Stores Covered</h3>
                            <p class="mt-1 text-xs font-bold uppercase tracking-widest text-gray-400 dark:text-gray-400">
                                {{ storesCovered.length }} {{ storesCovered.length === 1 ? 'Store' : 'Stores' }}
                            </p>
                        </div>
                        <button @click="closeStoresCoveredModal" class="text-gray-400 hover:text-gray-600 transition-colors dark:text-gray-400">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <div class="max-h-[60vh] overflow-y-auto pr-1">
                        <div class="flex flex-wrap gap-2">
                            <span v-for="code in storesCovered" :key="`modal-${code}`" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-xl text-xs font-bold border border-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:border-gray-700">
                                {{ code }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.custom-scrollbar::-webkit-scrollbar {
    height: 6px;
}
.custom-scrollbar::-webkit-scrollbar-thumb {
    background: #e2e8f0;
    border-radius: 10px;
}
</style>
