<script setup>
import { ref, computed } from 'vue'
import { Link, useForm, usePage, Head, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { usePermission } from '@/Composables/usePermission'
import { useConfirm } from '@/Composables/useConfirm'
import { useToast } from '@/Composables/useToast'
import DynamicFormRenderer from '@/Components/DynamicFormRenderer.vue'

const props = defineProps({
    form: Object,
    record: Object,
    users: {
        type: Array,
        default: () => [],
    },
})

const page = usePage()
const { hasPermission } = usePermission()
const { confirm } = useConfirm()
const { showSuccess, showError } = useToast()

const approvalForm = useForm({
    remarks: '',
    approver_data: { ... (props.record.data ?? {}) }
})

const reminderLoading = ref(false)

async function sendReminder(level = null) {
    const reminderLevel = level || props.record.current_approval_level
    const confirmed = await confirm({
        title: 'Send Approval Reminder',
        message: `Send an email reminder to the Stage ${reminderLevel} approver(s) for this request?`,
        confirmLabel: 'Send Reminder',
        variant: 'warning'
    })
    if (!confirmed) return
    reminderLoading.value = true
    router.post(route('dynamic-form.remind', { slug: props.form.slug, id: props.record.id }), {
        level: reminderLevel,
    }, {
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

// Use RequestType schema if available, fallback to FormDefinition
const effectiveSchemaSource = computed(() => props.record.request_type || props.form)
const effectiveFormSchema = computed(() => effectiveSchemaSource.value.form_schema || {})

const approverFields = computed(() => effectiveFormSchema.value?.approver_fields ?? [])
const schemaFields = computed(() => effectiveFormSchema.value?.fields ?? [])
const schemaItemsColumns = computed(() => effectiveFormSchema.value?.items_columns ?? [])
const itemsTemplateSource = computed(() => effectiveFormSchema.value?.items_template_source || null)
const itemsTemplates = computed(() => effectiveFormSchema.value?.items_templates || {})
const selectedItemsTemplateKey = computed(() =>
    itemsTemplateSource.value ? String(props.record.data?.[itemsTemplateSource.value] ?? '') : ''
)
const activeItemsTemplate = computed(() =>
    selectedItemsTemplateKey.value
        ? itemsTemplates.value[selectedItemsTemplateKey.value] || null
        : null
)
const activeSchemaItemsColumns = computed(() =>
    itemsTemplateSource.value
        ? (activeItemsTemplate.value?.columns || [])
        : schemaItemsColumns.value
)
const activeItemLabel = computed(() => activeItemsTemplate.value?.label || 'Line Items')
const hasSchemaItems = computed(() => !!effectiveFormSchema.value?.has_items && activeSchemaItemsColumns.value.length > 0)

const approverMatrix = computed(() => effectiveSchemaSource.value.approver_matrix ?? [])

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
    return assignedApproversByLevel.value[Number(props.record.current_approval_level)] ?? []
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

async function submitApproval() {
    if (!validateApproverFields()) return;

    const confirmed = await confirm({
        title: 'Confirm Approval',
        message: `Are you sure you want to approve Stage ${props.record.current_approval_level} for this record?`,
        confirmLabel: 'Approve Record',
        variant: 'success'
    })

    if (confirmed) {
        approvalForm.post(route('dynamic-form.approve', { slug: props.form.slug, id: props.record.id }), {
            onSuccess: () => {
                approvalForm.reset('remarks')
            },
            onError: () => showError('Approval failed')
        })
    }
}

const isChecklistWorkflow = computed(() => props.form?.workflow_type === 'checklist')

const canApprove = computed(() => {
    const s = props.record.status ?? ''
    const currentLevel = Number(props.record.current_approval_level)
    
    // For Checklist workflow, anyone assigned to ANY unapproved level can approve it at any time
    if (isChecklistWorkflow.value) {
        if (s === 'Approved' || s === 'Cancelled' || s === 'Rejected') return false
        
        // Find all levels this user is assigned to that aren't yet approved
        const unapprovedLevels = stages.value.filter(lvl => !getApprovalForLevel(lvl))
        return unapprovedLevels.some(lvl => {
            const assigned = assignedApproversByLevel.value[lvl] ?? []
            return assigned.length === 0 || assigned.some(u => Number(u.id) === Number(authUserId.value))
        })
    }

    const alreadyApprovedCurrentLevel = (props.record.approvals ?? []).some(a =>
        Number(a.user_id) === Number(authUserId.value) &&
        Number(a.level) === currentLevel
    )
    const assignedApprovers = currentLevelAssignedApprovers.value
    const isAssignedApprover = assignedApprovers.length === 0 ||
        assignedApprovers.some(user => Number(user.id) === Number(authUserId.value))

    return (s === 'Open' || s.startsWith('Approved Level')) &&
        hasPermission('form_builder.edit') &&
        currentLevel > 0 &&
        isAssignedApprover &&
        !alreadyApprovedCurrentLevel
})

const selectedChecklistLevel = ref(null)

const toggleChecklistTask = (lvl) => {
    if (getApprovalForLevel(lvl)) return
    
    // Check assignment
    const assigned = assignedApproversByLevel.value[lvl] ?? []
    const isAssigned = assigned.length === 0 || assigned.some(u => Number(u.id) === Number(authUserId.value))
    
    if (!isAssigned) {
        showError('You are not assigned to this task.')
        return
    }

    selectedChecklistLevel.value = lvl
}

const totalLevels = computed(() => Number(effectiveSchemaSource.value.approval_levels ?? 0))
const stages = computed(() => Array.from({ length: totalLevels.value }, (_, i) => i + 1))

function getTaskName(lvl) {
    const match = approverMatrix.value.find(m => Number(m.level) === Number(lvl))
    return match?.name || 'Task ' + lvl
}

async function submitChecklistApproval(lvl) {
    if (!validateApproverFields()) return;

    const taskName = getTaskName(lvl);
    const confirmed = await confirm({
        title: 'Complete Task',
        message: `Are you sure you want to mark "${taskName}" as completed?`,
        confirmLabel: 'Mark Completed',
        variant: 'success'
    })

    if (confirmed) {
        approvalForm.post(route('dynamic-form.approve', { slug: props.form.slug, id: props.record.id, force_level: lvl }), {
            onSuccess: () => {
                approvalForm.reset('remarks')
                selectedChecklistLevel.value = null
                showSuccess('Task completed successfully')
            },
            onError: () => showError('Failed to update task')
        })
    }
}

function getApprovalForLevel(lvl) {
    return (props.record.approvals ?? []).find(a => Number(a.level) === Number(lvl))
}

function statusClass(s) {
    if (!s) return 'bg-gray-500 text-white'
    if (s.startsWith('Approved Level')) return 'bg-indigo-500 text-white shadow-indigo-200'
    const map = { 'Approved': 'bg-emerald-500 text-white shadow-emerald-200', 'Open': 'bg-blue-500 text-white shadow-blue-200', 'Cancelled': 'bg-rose-500 text-white shadow-rose-200', 'Rejected': 'bg-red-500 text-white shadow-red-200' }
    return map[s] ?? 'bg-amber-500 text-white shadow-amber-200'
}

function fmt(d) {
    if (!d) return ''
    try { return new Date(d).toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit' }) }
    catch { return d }
}

const getLabel = (key, value, isItem = false) => {
    const fields = isItem ? activeSchemaItemsColumns.value : schemaFields.value;
    const field = fields.find(f => f.key === key);

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

    if (typeof value === 'boolean') return value ? 'Yes' : 'No'
    if (Array.isArray(value)) return value.join(', ');
    return value ?? '—';
};

const isFileField = (key, isItem = false) => {
    const fields = isItem ? activeSchemaItemsColumns.value : schemaFields.value;
    const field = fields.find(f => f.key === key);
    return field && field.type === 'file';
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
    if (name) return route('attachments.download', { path, name });
    return `/storage/${path.replace(/^public\//, '').replace(/^storage\//, '')}`;
};

const formData = computed(() => {
    const data = { ... (props.record.data ?? {}) }
    delete data.items
    return data
})

const lineItems = computed(() => props.record.data?.items ?? [])
</script>

<template>
    <Head :title="`${form.name} #${record.id}`" />

    <AppLayout :title="`${form.name} #${record.id}`">
        <div class="py-12 bg-gray-50 min-h-screen">
            <div class="max-w-[1600px] mx-auto sm:px-6 lg:px-8">

                <!-- Back -->
                <div class="flex items-center gap-3 mb-6">
                    <Link :href="route('dynamic-form.index', form.slug)" class="p-2 rounded-xl text-gray-400 hover:bg-white hover:text-gray-600 hover:shadow-md transition-all">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7"/></svg>
                    </Link>
                    <span class="text-sm font-bold text-gray-400">Back to {{ form.name }}</span>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">

                    <!-- Left: Details -->
                    <div class="lg:col-span-2 space-y-8">

                        <!-- Header Card -->
                        <div class="bg-white rounded-[2.5rem] shadow-2xl shadow-gray-200/50 p-10 border border-gray-100 relative overflow-hidden">
                            <div class="absolute top-0 right-0 p-8">
                                <span :class="statusClass(record.status)" class="px-6 py-2.5 rounded-2xl text-[10px] font-black uppercase tracking-[0.2em] shadow-lg">
                                    {{ record.status }}
                                </span>
                            </div>
                            <h1 class="text-3xl font-black text-gray-900 tracking-tight mb-4 flex items-center gap-3">
                                <span class="text-indigo-600">#{{ record.id }}</span>
                                {{ form.name }}
                            </h1>
                            <div v-if="record.request_type" class="mb-8">
                                <span class="inline-flex items-center px-4 py-1.5 rounded-xl text-xs font-black bg-teal-50 text-teal-700 border border-teal-100 uppercase tracking-tight">
                                    <svg class="w-3.5 h-3.5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                                    {{ record.request_type.name }}
                                </span>
                            </div>
                            <div class="grid grid-cols-2 md:grid-cols-3 gap-8">
                                <div>
                                    <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">Created By</label>
                                    <p class="text-base font-bold text-gray-900">{{ record.creator?.name ?? 'System' }}</p>
                                    <p class="text-xs text-gray-400 font-medium">{{ record.creator?.email }}</p>
                                </div>
                                <div>
                                    <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">Created At</label>
                                    <p class="text-sm font-mono font-black text-gray-600">{{ fmt(record.created_at) }}</p>
                                </div>
                                <div v-if="record.updated_by">
                                    <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">Last Updated By</label>
                                    <p class="text-base font-bold text-gray-900">{{ record.updator?.name }}</p>
                                    <p class="text-xs text-gray-400 font-medium">{{ fmt(record.updated_at) }}</p>
                                </div>
                            </div>
                        </div>

                        <!-- Form Data Card -->
                        <div v-if="Object.keys(formData).length" class="bg-white rounded-[2.5rem] shadow-2xl shadow-gray-200/50 p-10 border border-gray-100">
                            <h3 class="text-xl font-black text-gray-900 mb-6">Details</h3>
                            <dl class="divide-y divide-gray-50">
                                <div v-for="field in schemaFields" :key="field.key" class="flex items-start justify-between py-4">
                                    <dt class="text-xs font-black text-gray-400 uppercase tracking-widest w-1/3">
                                        {{ field.label }}
                                    </dt>
                                    <dd class="text-sm font-semibold text-gray-900 text-right w-2/3">
                                        <template v-if="field.type === 'file'">
                                            <div v-if="Array.isArray(formData[field.key])" class="flex flex-col items-end gap-1">
                                                <a v-for="(file, fi) in formData[field.key]" :key="fi" :href="getFileUrl(file)" target="_blank" rel="noopener noreferrer" class="inline-flex items-center text-indigo-600 hover:text-indigo-800 hover:underline">
                                                    <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/></svg>
                                                    {{ getFileName(file) }}
                                                </a>
                                            </div>
                                            <a v-else-if="formData[field.key]" :href="getFileUrl(formData[field.key])" target="_blank" rel="noopener noreferrer" class="inline-flex items-center text-indigo-600 hover:text-indigo-800 hover:underline">
                                                <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/></svg>
                                                {{ getFileName(formData[field.key]) }}
                                            </a>
                                            <span v-else>—</span>
                                        </template>
                                        <template v-else>
                                            {{ getLabel(field.key, formData[field.key]) }}
                                        </template>
                                    </dd>
                                </div>
                            </dl>
                        </div>

                        <!-- Items Card -->
                        <div v-if="hasSchemaItems && lineItems.length" class="bg-white rounded-[2.5rem] shadow-2xl shadow-gray-200/50 p-10 border border-gray-100">
                            <h3 class="text-xl font-black text-gray-900 mb-6">{{ activeItemLabel }} ({{ lineItems.length }})</h3>
                            <div class="overflow-x-auto">
                                <table class="w-full border-separate border-spacing-y-3">
                                    <thead>
                                        <tr class="text-[10px] font-black text-gray-500 uppercase tracking-widest text-left">
                                            <th class="px-4 pb-4">#</th>
                                            <th v-for="col in activeSchemaItemsColumns" :key="col.key" class="px-4 pb-4">
                                                {{ col.label }}
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="(item, i) in lineItems" :key="i" class="bg-gray-50/50 hover:bg-white hover:shadow-xl transition-all duration-300 rounded-2xl group">
                                            <td class="px-4 py-5 rounded-l-2xl text-[10px] font-black text-gray-400">{{ i + 1 }}</td>
                                            <td v-for="col in activeSchemaItemsColumns" :key="col.key" class="px-4 py-5 text-sm font-semibold text-gray-700">
                                                <template v-if="col.type === 'file'">
                                                    <div v-if="Array.isArray(item[col.key])" class="flex flex-col gap-1">
                                                        <a v-for="(file, fi) in item[col.key]" :key="fi" :href="getFileUrl(file)" target="_blank" class="inline-flex items-center text-indigo-600 hover:text-indigo-800 hover:underline">
                                                            <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/></svg>
                                                            {{ getFileName(file) }}
                                                        </a>
                                                    </div>
                                                    <a v-else-if="item[col.key]" :href="getFileUrl(item[col.key])" target="_blank" class="inline-flex items-center text-indigo-600 hover:text-indigo-800 hover:underline">
                                                        <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/></svg>
                                                        {{ getFileName(item[col.key]) }}
                                                    </a>
                                                    <span v-else>—</span>
                                                </template>
                                                <template v-else>
                                                    {{ getLabel(col.key, item[col.key], true) }}
                                                </template>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Right: Workflow Sidebar -->
                    <div v-if="totalLevels > 0" class="space-y-8">
                        <!-- Linked Ticket & SLA Display -->
                        <div v-if="record.ticket" class="bg-white rounded-[2.5rem] shadow-2xl shadow-gray-200/50 p-8 border border-gray-100">
                            <div class="flex items-center justify-between mb-4">
                                <h3 class="text-lg font-black text-gray-900 flex items-center gap-2">
                                    <svg class="w-5 h-5 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 5v2m0 4v2m0 4v2M5 5a2 2 0 00-2 2v3a2 2 0 110 4v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 110-4V7a2 2 0 00-2-2H5z"/></svg>
                                    Linked Ticket
                                </h3>
                                <span :class="ticketStatusClass(record.ticket.status)" class="px-3 py-1 rounded-full text-[10px] font-black uppercase tracking-widest shadow-sm">
                                    {{ record.ticket.status }}
                                </span>
                            </div>
                            <div class="space-y-4">
                                <a :href="route('tickets.show', record.ticket.ticket_key)" target="_blank" class="block w-full py-3 px-4 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 rounded-2xl font-bold text-center transition-colors">
                                    {{ record.ticket.ticket_key }}
                                </a>

                                <div v-if="record.ticket.slaMetric" class="grid grid-cols-2 gap-3 mt-4">
                                    <div class="bg-gray-50 p-3 rounded-2xl border border-gray-100">
                                        <p class="text-[9px] font-black text-gray-400 uppercase tracking-widest mb-1">Response Target</p>
                                        <p class="text-xs font-bold text-gray-800" :class="{ 'text-red-600': record.ticket.slaMetric.first_response_breached }">
                                            {{ record.ticket.slaMetric.first_response_target_at ? fmt(record.ticket.slaMetric.first_response_target_at) : 'N/A' }}
                                        </p>
                                    </div>
                                    <div class="bg-gray-50 p-3 rounded-2xl border border-gray-100">
                                        <p class="text-[9px] font-black text-gray-400 uppercase tracking-widest mb-1">Resolution Target</p>
                                        <p class="text-xs font-bold text-gray-800" :class="{ 'text-red-600': record.ticket.slaMetric.resolution_breached }">
                                            {{ record.ticket.slaMetric.resolution_target_at ? fmt(record.ticket.slaMetric.resolution_target_at) : 'N/A' }}
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="bg-white rounded-[2.5rem] shadow-2xl shadow-gray-200/50 p-8 border border-gray-100">
                            
                            <!-- Header -->
                            <div class="flex items-center justify-between mb-8">
                                <h3 class="text-lg font-black text-gray-900">{{ isChecklistWorkflow ? 'Task Checklist' : 'Approval Pulse' }}</h3>
                                <span v-if="record.status === 'Approved'" class="px-3 py-1 bg-emerald-100 text-emerald-700 rounded-lg text-[9px] font-black uppercase tracking-widest">Finalized</span>
                                <span v-if="totalLevels === 0" class="px-3 py-1 bg-gray-100 text-gray-500 rounded-lg text-[9px] font-black uppercase tracking-widest">No workflow</span>
                            </div>

                            <!-- Checklist Workflow UI -->
                            <div v-if="isChecklistWorkflow" class="space-y-4">
                                <div v-for="lvl in stages" :key="lvl" 
                                    class="group p-5 rounded-3xl border-2 transition-all duration-300 relative overflow-hidden"
                                    :class="getApprovalForLevel(lvl) 
                                        ? 'bg-emerald-50/50 border-emerald-100 shadow-sm' 
                                        : (selectedChecklistLevel === lvl ? 'bg-indigo-50/50 border-indigo-200 ring-2 ring-indigo-100' : 'bg-gray-50/50 border-gray-100 hover:border-indigo-100 hover:bg-white hover:shadow-lg')">
                                    
                                    <div class="flex items-start justify-between gap-4">
                                        <div class="flex items-start gap-4">
                                            <button 
                                                @click="toggleChecklistTask(lvl)"
                                                :disabled="getApprovalForLevel(lvl)"
                                                class="mt-1 w-6 h-6 rounded-lg border-2 flex items-center justify-center transition-all"
                                                :class="getApprovalForLevel(lvl) ? 'bg-emerald-500 border-emerald-500 text-white' : 'border-gray-200 bg-white group-hover:border-indigo-400 group-hover:shadow-md'">
                                                <svg v-if="getApprovalForLevel(lvl)" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                                            </button>
                                            
                                            <div>
                                                <p class="text-sm font-black transition-colors" :class="getApprovalForLevel(lvl) ? 'text-emerald-900' : 'text-gray-900 group-hover:text-indigo-600'">{{ getTaskName(lvl) }}</p>
                                                <div v-if="getApprovalForLevel(lvl)" class="mt-1 space-y-1">
                                                    <p class="text-[10px] font-bold text-emerald-600">Completed by {{ getApprovalForLevel(lvl).user?.name }}</p>
                                                    <p class="text-[9px] font-medium text-emerald-500/70">{{ fmt(getApprovalForLevel(lvl).created_at) }}</p>
                                                    <p v-if="getApprovalForLevel(lvl).remarks" class="text-[10px] text-emerald-700 italic bg-white/50 rounded-lg p-2 mt-2 border border-emerald-100/50">"{{ getApprovalForLevel(lvl).remarks }}"</p>
                                                </div>
                                                <div v-else class="mt-1">
                                                    <p class="text-[10px] font-black uppercase tracking-widest text-gray-400 group-hover:text-indigo-400">Assigned To</p>
                                                    <div class="flex flex-wrap gap-1.5 mt-2">
                                                        <template v-if="(assignedApproversByLevel[lvl] ?? []).length > 0">
                                                            <span v-for="user in assignedApproversByLevel[lvl]" :key="user.id" class="px-2 py-0.5 rounded-md bg-white border border-gray-100 text-[9px] font-black text-gray-600 shadow-sm">
                                                                {{ user.name }}
                                                            </span>
                                                        </template>
                                                        <span v-else class="text-[10px] text-gray-400 italic">Anyone can complete</span>
                                                    </div>
                                                    <div v-if="hasPermission('form_builder.edit') && record.status !== 'Rejected' && record.status !== 'Cancelled'" class="mt-3">
                                                        <button 
                                                            @click.stop="sendReminder(lvl)"
                                                            :disabled="reminderLoading"
                                                            class="px-3 py-1.5 bg-white hover:bg-indigo-50 text-indigo-600 rounded-lg text-[9px] font-black uppercase tracking-widest shadow-sm border border-indigo-100 transition-all flex items-center gap-1.5">
                                                            <svg v-if="reminderLoading" class="w-3 h-3 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                                            <svg v-else class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                                                            {{ reminderLoading ? 'Sending...' : 'Remind' }}
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Selected Task Action Area -->
                                    <div v-if="selectedChecklistLevel === lvl" class="mt-6 pt-6 border-t border-indigo-100 space-y-4 animate-in fade-in slide-in-from-top-2 duration-300">
                                        <div v-if="approverFields.length > 0" class="bg-white border border-indigo-50 rounded-2xl p-4 shadow-sm">
                                            <h4 class="text-[9px] font-black text-indigo-600 uppercase tracking-widest mb-3">Task Details</h4>
                                            <DynamicFormRenderer
                                                :fields="approverFields"
                                                v-model="approvalForm.approver_data"
                                                :errors="approvalForm.errors"
                                                grid-columns="1"
                                                gap="3"
                                                dense
                                            />
                                        </div>

                                        <textarea v-model="approvalForm.remarks" rows="2" placeholder="Task remarks (optional)..."
                                            class="w-full bg-white border-2 border-indigo-50 rounded-2xl p-3 text-xs font-medium focus:border-indigo-500 focus:ring-0 transition-all"></textarea>
                                        
                                        <div class="flex gap-2">
                                            <button @click="selectedChecklistLevel = null" class="flex-1 py-2 text-[10px] font-black uppercase tracking-widest text-gray-500 bg-gray-100 rounded-xl hover:bg-gray-200 transition-all">Cancel</button>
                                            <button @click="submitChecklistApproval(lvl)" :disabled="approvalForm.processing"
                                                class="flex-[2] py-2 bg-indigo-600 text-white rounded-xl text-[10px] font-black uppercase tracking-widest shadow-lg shadow-indigo-100 hover:bg-indigo-700 transition-all flex items-center justify-center gap-2">
                                                <span>Mark as Done</span>
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Sequential Approval Workflow UI -->
                            <div v-else class="relative px-2">
                                <div class="absolute left-[27px] top-2 bottom-2 w-1 bg-gradient-to-b from-indigo-500 via-gray-100 to-gray-50 rounded-full"></div>
                                <div class="space-y-10">
                                    <div v-for="lvl in stages" :key="lvl" class="relative pl-16">
                                        <div :class="[
                                            'absolute left-0 w-10 h-10 rounded-2xl flex items-center justify-center border-4 border-white shadow-xl z-10 transition-all duration-700',
                                            getApprovalForLevel(lvl) ? 'bg-emerald-500 scale-110' :
                                            Number(lvl) === Number(record.current_approval_level) ? 'bg-indigo-600 scale-125 ring-8 ring-indigo-50' : 'bg-white border-gray-100'
                                        ]">
                                            <svg v-if="getApprovalForLevel(lvl)" class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                                            <span v-else :class="Number(lvl) === Number(record.current_approval_level) ? 'text-white' : 'text-gray-300'" class="text-xs font-black">{{ lvl }}</span>
                                        </div>
                                        <div :class="!getApprovalForLevel(lvl) && Number(lvl) !== Number(record.current_approval_level) ? 'opacity-40' : 'opacity-100'" class="transition-all duration-500">
                                            <div class="flex items-center justify-between gap-4">
                                                <span class="text-xs font-black uppercase tracking-[0.2em]" :class="getApprovalForLevel(lvl) || Number(lvl) === Number(record.current_approval_level) ? 'text-indigo-600' : 'text-gray-500'">
                                                    Stage {{ lvl }}
                                                </span>
                                                <span v-if="getApprovalForLevel(lvl)" class="text-[11px] font-bold text-emerald-600 font-mono whitespace-nowrap">
                                                    {{ fmt(getApprovalForLevel(lvl).created_at) }}
                                                </span>
                                            </div>
                                            <div v-if="getApprovalForLevel(lvl)" class="mt-3 p-4 bg-gray-50 rounded-2xl border border-gray-100 relative overflow-hidden">
                                                <div class="absolute top-0 left-0 w-1 h-full bg-emerald-500"></div>
                                                <div class="flex items-center mb-1">
                                                    <div class="w-5 h-5 rounded-full bg-indigo-100 flex items-center justify-center text-[9px] font-black text-indigo-600 mr-2 capitalize">
                                                        {{ getApprovalForLevel(lvl).user?.name?.charAt(0) ?? '?' }}
                                                    </div>
                                                    <span class="text-xs font-black text-gray-900">{{ getApprovalForLevel(lvl).user?.name ?? 'Unknown' }}</span>
                                                </div>
                                                <p v-if="getApprovalForLevel(lvl).remarks" class="text-[11px] text-gray-600 italic">"{{ getApprovalForLevel(lvl).remarks }}"</p>
                                            </div>
                                            <div v-else-if="Number(lvl) === Number(record.current_approval_level)" class="mt-3 p-4 bg-indigo-50/50 rounded-2xl border-2 border-dashed border-indigo-200">
                                                <div class="flex items-center text-indigo-600">
                                                    <svg class="w-4 h-4 mr-2 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                                    <span class="text-[10px] font-black uppercase tracking-widest">Awaiting Decision</span>
                                                </div>
                                                <div v-if="(assignedApproversByLevel[Number(lvl)] ?? []).length > 0" class="mt-3 pt-3 border-t border-indigo-100">
                                                    <p class="text-[9px] font-black uppercase tracking-widest text-indigo-500 mb-2">Assigned Approvers</p>
                                                    <div class="flex flex-wrap gap-2">
                                                        <span v-for="approver in assignedApproversByLevel[Number(lvl)]" :key="approver.id" class="px-2.5 py-1 rounded-full bg-white text-indigo-700 border border-indigo-200 text-[10px] font-bold">
                                                            {{ approver.name }}
                                                        </span>
                                                    </div>
                                                </div>
                                                <div v-if="hasPermission('form_builder.edit') && record.status !== 'Rejected' && record.status !== 'Cancelled'" class="mt-4 pt-3 border-t border-indigo-100 flex justify-end">
                                                    <button 
                                                        @click="sendReminder(lvl)"
                                                        :disabled="reminderLoading"
                                                        class="px-4 py-2 bg-white hover:bg-indigo-50 text-indigo-600 rounded-xl text-[10px] font-black uppercase tracking-widest shadow-sm border border-indigo-100 transition-all flex items-center gap-2">
                                                        <svg v-if="reminderLoading" class="w-3.5 h-3.5 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                                        <svg v-else class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                                                        {{ reminderLoading ? 'Sending...' : 'Remind' }}
                                                    </button>
                                                </div>
                                            </div>
                                            <div v-else class="mt-3 px-4 py-2 text-[10px] font-bold text-gray-400 uppercase italic">Locked</div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Action Box (Approval Workflow Only) -->
                            <div v-if="canApprove && !isChecklistWorkflow" class="mt-10 pt-8 border-t border-gray-100 relative">
                                <div class="absolute -top-3 left-1/2 -translate-x-1/2 px-4 bg-white text-[9px] font-black text-indigo-500 uppercase tracking-[0.3em]">Your Decision</div>
                                
                                <div v-if="approverFields.length > 0" class="mb-6 bg-white border-2 border-indigo-100 rounded-[2rem] p-6 shadow-sm">
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

                                <textarea v-model="approvalForm.remarks" rows="3" placeholder="Add approval remarks (optional)..."
                                    class="w-full bg-gray-50 border-2 border-gray-100 rounded-3xl p-4 text-sm font-medium focus:bg-white focus:border-indigo-500 focus:ring-0 transition-all mb-4"></textarea>
                                <button @click="submitApproval" :disabled="approvalForm.processing"
                                    class="w-full py-4 bg-indigo-600 text-white rounded-[1.5rem] font-black text-sm uppercase tracking-[0.2em] shadow-2xl shadow-indigo-200 hover:bg-indigo-700 transform hover:-translate-y-1 active:scale-95 transition-all disabled:opacity-50 flex items-center justify-center gap-2">
                                    <span>Release Stage {{ record.current_approval_level }}</span>
                                    <svg class="w-5 h-5 ml-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
