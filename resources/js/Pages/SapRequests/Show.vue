<script setup>
import { ref, computed } from 'vue'
import { Link, useForm, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { usePermission } from '@/Composables/usePermission'
import { useConfirm } from '@/Composables/useConfirm'
import { useToast } from '@/Composables/useToast'
import DynamicFormRenderer from '@/Components/DynamicFormRenderer.vue'

const props = defineProps({
    sapRequest: Object,
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
    approver_data: { ... (props.sapRequest.form_data ?? {}) }
})

const authUserId = computed(() => page.props.auth.user.id)

const approverFields = computed(() => props.sapRequest.request_type?.form_schema?.approver_fields ?? [])
const schemaFields = computed(() => props.sapRequest.request_type?.form_schema?.fields ?? [])
const requestFormData = computed(() => props.sapRequest.form_data ?? {})
const normalizeApproverMatrix = (matrix = [], levels = 0) => {
    const totalLevels = Math.max(0, Number(levels) || 0)

    return Array.from({ length: totalLevels }, (_, index) => {
        const level = index + 1
        const existing = Array.isArray(matrix)
            ? matrix.find(entry => Number(entry?.level) === level)
            : null

        return {
            level,
            user_ids: Array.isArray(existing?.user_ids)
                ? [...new Set(existing.user_ids.map(Number).filter(Boolean))]
                : [],
        }
    })
}

const resolveOptionApprovalMatrix = (option = {}) => {
    if (Array.isArray(option.approval_matrix) && option.approval_matrix.length > 0) {
        const levels = Math.max(Number(option.approval_levels ?? 0) || 0, option.approval_matrix.length)
        return normalizeApproverMatrix(option.approval_matrix, levels)
    }

    const legacyIds = Array.isArray(option.approver_user_ids)
        ? [...new Set(option.approver_user_ids.map(Number).filter(Boolean))]
        : []

    return legacyIds.length > 0
        ? [{ level: 1, user_ids: legacyIds }]
        : []
}

const effectiveApproverMatrix = computed(() => {
    const baseLevels = Number(props.sapRequest.request_type?.approval_levels ?? 0)
    const baseMatrix = normalizeApproverMatrix(props.sapRequest.request_type?.approver_matrix ?? [], baseLevels)
    const dynamicLevelMap = {}

    schemaFields.value
        .filter(field => field.type === 'checkbox_group' && field.has_option_approvers && field.key)
        .forEach(field => {
            const selectedValues = requestFormData.value[field.key]
            if (!Array.isArray(selectedValues) || selectedValues.length === 0) {
                return
            }

            ;(field.options || [])
                .filter(option => selectedValues.includes(option.value))
                .forEach(option => {
                    resolveOptionApprovalMatrix(option).forEach(entry => {
                        const level = Number(entry.level)
                        if (!level) return

                        if (!Array.isArray(dynamicLevelMap[level])) {
                            dynamicLevelMap[level] = []
                        }

                        dynamicLevelMap[level].push(...(entry.user_ids || []).map(Number).filter(Boolean))
                    })
                })
        })

    const dynamicLevels = Object.keys(dynamicLevelMap).map(Number).filter(Boolean)
    const totalLevels = Math.max(baseLevels, dynamicLevels.length ? Math.max(...dynamicLevels) : 0)

    return Array.from({ length: totalLevels }, (_, index) => {
        const level = index + 1
        const dynamicUserIds = [...new Set((dynamicLevelMap[level] || []).map(Number).filter(Boolean))]
        const baseEntry = baseMatrix.find(entry => Number(entry.level) === level)

        return {
            level,
            user_ids: dynamicUserIds.length > 0 ? dynamicUserIds : (baseEntry?.user_ids ?? []),
        }
    })
})

const assignedApproversByLevel = computed(() => {
    const users = props.users ?? []
    return effectiveApproverMatrix.value.reduce((carry, entry) => {
        const level = Number(entry.level)
        const ids = Array.isArray(entry.user_ids) ? entry.user_ids.map(Number) : []

        carry[level] = users.filter(user => ids.includes(Number(user.id)))

        return carry
    }, {})
})
const currentLevelAssignedApprovers = computed(() => {
    return assignedApproversByLevel.value[Number(props.sapRequest.current_approval_level)] ?? []
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
        message: `Are you sure you want to approve Stage ${props.sapRequest.current_approval_level} for this SAP request?`,
        confirmLabel: 'Approve Request',
        variant: 'success'
    })

    if (confirmed) {
        approvalForm.post(route('sap-requests.approve', props.sapRequest.id), {
            onSuccess: () => {
                approvalForm.reset()
            },
            onError: () => showError('Approval failed')
        })
    }
}

const canApprove = computed(() => {
    const s = props.sapRequest.status ?? ''
    const currentLevel = Number(props.sapRequest.current_approval_level)
    const alreadyApprovedCurrentLevel = (props.sapRequest.approvals ?? []).some(a =>
        Number(a.user_id) === Number(authUserId.value) &&
        Number(a.level) === currentLevel
    )
    const assignedApprovers = currentLevelAssignedApprovers.value
    const isAssignedApprover = assignedApprovers.length === 0 ||
        assignedApprovers.some(user => Number(user.id) === Number(authUserId.value))

    return (s === 'Open' || s.startsWith('Approved Level')) &&
        hasPermission('sap_requests.approve') &&
        currentLevel > 0 &&
        isAssignedApprover &&
        !alreadyApprovedCurrentLevel
})

const totalLevels = computed(() => {
    return effectiveApproverMatrix.value.length
})
const stages = computed(() => Array.from({ length: totalLevels.value }, (_, i) => i + 1))

function getApprovalForLevel(lvl) {
    return (props.sapRequest.approvals ?? []).find(a => Number(a.level) === Number(lvl))
}

function statusClass(s) {
    if (!s) return 'bg-gray-500 text-white'
    if (s.startsWith('Approved Level')) return 'bg-indigo-500 text-white shadow-indigo-200'
    const map = { 'Approved': 'bg-emerald-500 text-white shadow-emerald-200', 'Open': 'bg-blue-500 text-white shadow-blue-200', 'Cancelled': 'bg-rose-500 text-white shadow-rose-200' }
    return map[s] ?? 'bg-amber-500 text-white shadow-amber-200'
}

function fmt(d) {
    if (!d) return ''
    try { return new Date(d).toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit' }) }
    catch { return d }
}

const formData = computed(() => {
    const data = { ... (props.sapRequest.form_data ?? {}) }
    // Remove "Common Sense" fields
    delete data.sku_mode
    return data
})
const items = computed(() => props.sapRequest.items ?? [])

const getLabel = (key, value, isItem = false) => {
    const schema = props.sapRequest.request_type?.form_schema;
    if (!schema) return value;

    const fields = isItem ? (schema.items_columns || []) : (schema.fields || []);
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

    if (Array.isArray(value)) return value.join(', ');
    return value ?? '—';
};

const isFileField = (key, isItem = false) => {
    const schema = props.sapRequest.request_type?.form_schema;
    if (!schema) return false;
    const fields = isItem ? (schema.items_columns || []) : (schema.fields || []);
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
    
    // Fallback for legacy string paths
    const cleanPath = value.replace(/^public\//, '').replace(/^storage\//, '');
    const parts = cleanPath.split(/[/\\]/);
    return parts.pop() || 'Attachment';
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

    // Fallback for legacy string paths
    let cleanPath = path;
    if (cleanPath.startsWith('/')) cleanPath = cleanPath.substring(1);
    if (cleanPath.startsWith('public/')) cleanPath = cleanPath.substring(7);
    if (cleanPath.startsWith('storage/')) cleanPath = cleanPath.substring(8);
    
    if (!cleanPath) return '#';
    return `/storage/${cleanPath}`;
};
</script>

<template>
    <AppLayout :title="`SAP Request #${sapRequest.id}`">
        <div class="py-12 bg-gray-50 min-h-screen">
            <div class="max-w-[1600px] mx-auto sm:px-6 lg:px-8">

                <!-- Back -->
                <div class="flex items-center gap-3 mb-6">
                    <Link :href="route('sap-requests.index')" class="p-2 rounded-xl text-gray-400 hover:bg-white hover:text-gray-600 hover:shadow-md transition-all">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7"/></svg>
                    </Link>
                    <span class="text-sm font-bold text-gray-400">Back to SAP Requests</span>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">

                    <!-- Left: Details -->
                    <div class="lg:col-span-2 space-y-8">

                        <!-- Header Card -->
                        <div class="bg-white rounded-[2.5rem] shadow-2xl shadow-gray-200/50 p-10 border border-gray-100 relative overflow-hidden">
                            <div class="absolute top-0 right-0 p-8">
                                <span :class="statusClass(sapRequest.status)" class="px-6 py-2.5 rounded-2xl text-[10px] font-black uppercase tracking-[0.2em] shadow-lg">
                                    {{ sapRequest.status }}
                                </span>
                            </div>
                            <h1 class="text-3xl font-black text-gray-900 tracking-tight mb-8 flex items-center gap-3">
                                <span class="text-teal-600">#{{ sapRequest.id }}</span>
                                {{ sapRequest.request_type?.name }}
                            </h1>
                            <div class="grid grid-cols-2 md:grid-cols-3 gap-8">
                                <div>
                                    <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">Entity</label>
                                    <p class="text-base font-bold text-gray-900">{{ sapRequest.company?.name }}</p>
                                </div>
                                <div>
                                    <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">Requester</label>
                                    <p class="text-base font-bold text-gray-900">{{ sapRequest.user?.name ?? sapRequest.requester_name ?? 'Public Submission' }}</p>
                                    <p class="text-xs text-gray-400 font-medium">{{ sapRequest.user?.email ?? sapRequest.requester_email }}</p>
                                </div>
                                <div>
                                    <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">Submitted</label>
                                    <p class="text-sm font-mono font-black text-gray-600">{{ fmt(sapRequest.created_at) }}</p>
                                </div>
                                <div v-if="sapRequest.ticket">
                                    <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">Linked Ticket</label>
                                    <Link :href="route('tickets.edit', sapRequest.ticket.id)" class="text-sm font-black text-teal-600 hover:text-teal-800 font-mono">
                                        {{ sapRequest.ticket.ticket_key }}
                                    </Link>
                                </div>
                                <div v-if="hasPermission('sap_requests.edit') && sapRequest.status === 'Open'">
                                    <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">Actions</label>
                                    <Link :href="route('sap-requests.edit', sapRequest.id)" class="inline-flex items-center text-xs font-black text-amber-600 hover:text-amber-800">
                                        <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2.5 0 113.536 3.536L12 14.036H3v-3.572L16.732 3.732z"/></svg>
                                        Edit Request
                                    </Link>
                                </div>
                            </div>
                        </div>

                        <!-- Form Data Card -->
                        <div v-if="Object.keys(formData).length" class="bg-white rounded-[2.5rem] shadow-2xl shadow-gray-200/50 p-10 border border-gray-100">
                            <h3 class="text-xl font-black text-gray-900 mb-6">Request Details</h3>
                            <dl class="divide-y divide-gray-50">
                                <div v-for="(value, key) in formData" :key="key" class="flex items-start justify-between py-4">
                                    <dt class="text-xs font-black text-gray-400 uppercase tracking-widest w-1/3">
                                        {{ String(key).replace(/_/g, ' ') }}
                                    </dt>
                                    <dd class="text-sm font-semibold text-gray-900 text-right w-2/3">
                                        <template v-if="isFileField(key)">
                                            <div v-if="isFileArray(value)" class="flex flex-col items-end gap-1">
                                                <a v-for="(file, fi) in value" :key="fi" :href="getFileUrl(file)" target="_blank" rel="noopener noreferrer" :download="getFileName(file)" class="inline-flex items-center text-teal-600 hover:text-teal-800 hover:underline">
                                                    <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/></svg>
                                                    {{ getFileName(file) }}
                                                </a>
                                            </div>
                                            <a v-else-if="value" :href="getFileUrl(value)" target="_blank" rel="noopener noreferrer" :download="getFileName(value)" class="inline-flex items-center text-teal-600 hover:text-teal-800 hover:underline">
                                                <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/></svg>
                                                {{ getFileName(value) }}
                                            </a>
                                            <span v-else>—</span>
                                        </template>
                                        <template v-else>
                                            {{ getLabel(key, value) }}
                                        </template>
                                    </dd>
                                </div>
                            </dl>
                        </div>

                        <!-- Items Card (for New Item Request / New BOM) -->
                        <div v-if="items.length" class="bg-white rounded-[2.5rem] shadow-2xl shadow-gray-200/50 p-10 border border-gray-100">
                            <h3 class="text-xl font-black text-gray-900 mb-6">Items ({{ items.length }})</h3>
                            <div class="space-y-4">
                                <div v-for="(item, i) in items" :key="item.id" class="bg-gray-50 rounded-2xl p-6 border border-gray-100">
                                    <p class="text-[10px] font-black text-teal-600 uppercase tracking-widest mb-4">Item #{{ i + 1 }}</p>
                                    <dl class="grid grid-cols-2 md:grid-cols-3 gap-4">
                                        <div v-for="(val, k) in item.item_data" :key="k">
                                            <dt class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-0.5">{{ String(k).replace(/_/g, ' ') }}</dt>
                                            <dd class="text-sm font-semibold text-gray-900">
                                                <template v-if="isFileField(k, true)">
                                                    <div v-if="isFileArray(val)" class="flex flex-col gap-1">
                                                        <a v-for="(file, fi) in val" :key="fi" :href="getFileUrl(file)" target="_blank" rel="noopener noreferrer" :download="getFileName(file)" class="inline-flex items-center text-teal-600 hover:text-teal-800 hover:underline">
                                                            <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/></svg>
                                                            {{ getFileName(file) }}
                                                        </a>
                                                    </div>
                                                    <a v-else-if="val" :href="getFileUrl(val)" target="_blank" rel="noopener noreferrer" :download="getFileName(val)" class="inline-flex items-center text-teal-600 hover:text-teal-800 hover:underline">
                                                        <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/></svg>
                                                        {{ getFileName(val) }}
                                                    </a>
                                                    <span v-else>—</span>
                                                </template>
                                                <template v-else>
                                                    {{ getLabel(k, val, true) }}
                                                </template>
                                            </dd>
                                        </div>
                                    </dl>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Right: Approval Sidebar -->
                    <div class="space-y-8">
                        <div class="bg-white rounded-[2.5rem] shadow-2xl shadow-gray-200/50 p-8 border border-gray-100">
                            <div class="flex items-center justify-between mb-8">
                                <h3 class="text-lg font-black text-gray-900">Approval Pulse</h3>
                                <span v-if="sapRequest.status === 'Approved'" class="px-3 py-1 bg-emerald-100 text-emerald-700 rounded-lg text-[9px] font-black uppercase tracking-widest">Finalized</span>
                                <span v-if="totalLevels === 0" class="px-3 py-1 bg-gray-100 text-gray-500 rounded-lg text-[9px] font-black uppercase tracking-widest">No Approval Needed</span>
                            </div>

                            <!-- No approval types -->
                            <div v-if="totalLevels === 0" class="text-center py-6">
                                <div class="w-14 h-14 bg-emerald-100 rounded-2xl flex items-center justify-center mx-auto mb-3">
                                    <svg class="w-7 h-7 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                </div>
                                <p class="text-sm font-bold text-gray-700">Direct to SAP Data Officer</p>
                                <p class="text-xs text-gray-400 mt-1">This request type requires no approval and goes directly to the encoder.</p>
                            </div>

                            <!-- Approval stages -->
                            <div v-else class="relative px-2">
                                <div class="absolute left-[27px] top-2 bottom-2 w-1 bg-gradient-to-b from-teal-500 via-gray-100 to-gray-50 rounded-full"></div>
                                <div class="space-y-10">
                                    <div v-for="lvl in stages" :key="lvl" class="relative pl-16">
                                        <div :class="[
                                            'absolute left-0 w-10 h-10 rounded-2xl flex items-center justify-center border-4 border-white shadow-xl z-10 transition-all duration-700',
                                            getApprovalForLevel(lvl) ? 'bg-emerald-500 scale-110' :
                                            Number(lvl) === Number(sapRequest.current_approval_level) ? 'bg-teal-600 scale-125 ring-8 ring-teal-50' : 'bg-white border-gray-100'
                                        ]">
                                            <svg v-if="getApprovalForLevel(lvl)" class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                                            <span v-else :class="Number(lvl) === Number(sapRequest.current_approval_level) ? 'text-white' : 'text-gray-300'" class="text-xs font-black">{{ lvl }}</span>
                                        </div>
                                        <div :class="!getApprovalForLevel(lvl) && Number(lvl) !== Number(sapRequest.current_approval_level) ? 'opacity-40' : 'opacity-100'" class="transition-all duration-500">
                                            <div class="flex items-center justify-between gap-4">
                                                <span class="text-xs font-black uppercase tracking-[0.2em]" :class="getApprovalForLevel(lvl) || Number(lvl) === Number(sapRequest.current_approval_level) ? 'text-teal-600' : 'text-gray-500'">
                                                    Stage {{ lvl }}
                                                </span>
                                                <span v-if="getApprovalForLevel(lvl)" class="text-[11px] font-bold text-emerald-600 font-mono whitespace-nowrap">
                                                    {{ fmt(getApprovalForLevel(lvl).created_at) }}
                                                </span>
                                            </div>
                                            <div v-if="getApprovalForLevel(lvl)" class="mt-3 p-4 bg-gray-50 rounded-2xl border border-gray-100 relative overflow-hidden">
                                                <div class="absolute top-0 left-0 w-1 h-full bg-emerald-500"></div>
                                                <div class="flex items-center mb-1">
                                                    <div class="w-5 h-5 rounded-full bg-teal-100 flex items-center justify-center text-[9px] font-black text-teal-600 mr-2 capitalize">
                                                        {{ getApprovalForLevel(lvl).user?.name?.charAt(0) ?? '?' }}
                                                    </div>
                                                    <span class="text-xs font-black text-gray-900">{{ getApprovalForLevel(lvl).user?.name ?? 'Unknown' }}</span>
                                                </div>
                                                <p v-if="getApprovalForLevel(lvl).remarks" class="text-[11px] text-gray-600 italic">"{{ getApprovalForLevel(lvl).remarks }}"</p>
                                                <p v-else class="text-[9px] text-gray-400 font-bold uppercase">Approved without remarks</p>
                                            </div>
                                            <div v-else-if="Number(lvl) === Number(sapRequest.current_approval_level)" class="mt-3 p-4 bg-teal-50/50 rounded-2xl border-2 border-dashed border-teal-200">
                                                <div class="flex items-center text-teal-600">
                                                    <svg class="w-4 h-4 mr-2 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                                    <span class="text-[10px] font-black uppercase tracking-widest">Awaiting Decision</span>
                                                </div>
                                                <div v-if="(assignedApproversByLevel[Number(lvl)] ?? []).length > 0" class="mt-3 pt-3 border-t border-teal-100">
                                                    <p class="text-[9px] font-black uppercase tracking-widest text-teal-500 mb-2">Assigned Approvers</p>
                                                    <div class="flex flex-wrap gap-2">
                                                        <span
                                                            v-for="approver in assignedApproversByLevel[Number(lvl)]"
                                                            :key="approver.id"
                                                            class="px-2.5 py-1 rounded-full bg-white text-teal-700 border border-teal-200 text-[10px] font-bold"
                                                        >
                                                            {{ approver.name }}
                                                        </span>
                                                    </div>
                                                </div>
                                            </div>
                                            <div v-else class="mt-3 px-4 py-2 text-[10px] font-bold text-gray-400 uppercase italic">Locked</div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Approve Action -->
                            <div v-if="canApprove" class="mt-10 pt-8 border-t border-gray-100 relative">
                                <div class="absolute -top-3 left-1/2 -translate-x-1/2 px-4 bg-white text-[9px] font-black text-teal-500 uppercase tracking-[0.3em]">Your Decision</div>

                                <div v-if="currentLevelAssignedApprovers.length > 0" class="mb-6 p-4 bg-teal-50 border border-teal-100 rounded-2xl">
                                    <p class="text-[10px] font-black text-teal-600 uppercase tracking-widest mb-2">
                                        Level {{ sapRequest.current_approval_level }} Assigned Approvers
                                    </p>
                                    <div class="flex flex-wrap gap-2">
                                        <span
                                            v-for="approver in currentLevelAssignedApprovers"
                                            :key="approver.id"
                                            class="px-3 py-1 rounded-full bg-white text-teal-700 border border-teal-200 text-[10px] font-bold"
                                        >
                                            {{ approver.name }}
                                        </span>
                                    </div>
                                </div>
                                
                                <!-- Approver Fields -->
                                <div v-if="approverFields.length > 0" class="mb-6 bg-white border-2 border-indigo-100 rounded-[2rem] p-6 shadow-sm">
                                    <h4 class="text-[10px] font-black text-teal-600 uppercase tracking-widest mb-4">Required Approver Details</h4>
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
                                    class="w-full bg-gray-50 border-2 border-gray-100 rounded-3xl p-4 text-sm font-medium focus:bg-white focus:border-teal-500 focus:ring-0 transition-all mb-4"></textarea>
                                <button @click="submitApproval" :disabled="approvalForm.processing"
                                    class="w-full py-4 bg-teal-600 text-white rounded-[1.5rem] font-black text-sm uppercase tracking-[0.2em] shadow-2xl shadow-teal-200 hover:bg-teal-700 transform hover:-translate-y-1 active:scale-95 transition-all disabled:opacity-50 flex items-center justify-center gap-2">
                                    <span>Release Level {{ sapRequest.current_approval_level }}</span>
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
