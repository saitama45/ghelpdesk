<template>
    <AppLayout :title="`Review ${review.source_reference_no}`">
        <div class="py-8">
            <div class="max-w-[110rem] mx-auto px-4 sm:px-6 space-y-4">
                <!-- Header bar -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 flex flex-col xl:flex-row xl:items-center xl:justify-between gap-3 dark:bg-gray-800 dark:border-gray-700">
                    <div class="flex items-center gap-4">
                        <Link :href="route('accounting-documents.index')" class="p-2 text-gray-400 hover:text-gray-600 hover:bg-gray-100 rounded-lg transition-colors dark:hover:bg-gray-700">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                            </svg>
                        </Link>
                        <div>
                            <h1 class="text-xl font-bold text-gray-900 dark:text-gray-100">{{ review.source_reference_no }}</h1>
                            <p class="text-sm text-gray-500 dark:text-gray-300">
                                <span class="capitalize">{{ (review.document_type || '').replaceAll('_', ' ') }}</span>
                                · {{ review.vendor_name }} <span v-if="review.vendor_code">({{ review.vendor_code }})</span>
                                <span v-if="review.company_name"> · {{ review.company_name }}</span>
                            </p>
                        </div>
                        <span :class="statusClass(review.status)" class="inline-flex px-2.5 py-1 text-xs font-semibold rounded-full capitalize">
                            {{ review.status.replaceAll('_', ' ') }}
                        </span>
                    </div>

                    <div v-if="!isDecided" class="flex flex-wrap items-center gap-2">
                        <button v-if="hasPermission('accounting-documents.approve')" @click="openDecision('approve')"
                            class="px-5 py-2 bg-green-600 text-white text-sm font-bold rounded-lg hover:bg-green-700 shadow-md transition-all">
                            Approve
                        </button>
                        <button v-if="hasPermission('accounting-documents.return')" @click="openDecision('return')"
                            class="px-5 py-2 bg-amber-500 text-white text-sm font-bold rounded-lg hover:bg-amber-600 shadow-md transition-all">
                            Return
                        </button>
                        <button v-if="hasPermission('accounting-documents.reject')" @click="openDecision('reject')"
                            class="px-5 py-2 bg-red-600 text-white text-sm font-bold rounded-lg hover:bg-red-700 shadow-md transition-all">
                            Reject
                        </button>
                    </div>

                    <div v-else class="text-sm text-gray-600 dark:text-gray-300">
                        <span class="font-semibold capitalize">{{ review.status }}</span>
                        by {{ review.decided_by?.name || '—' }} · {{ formatDateTime(review.decided_at) }}
                        <div v-if="review.callback_status === 'failed'" class="mt-1 flex items-center gap-2">
                            <span class="text-red-600 text-xs font-semibold">Callback to linkportal failed</span>
                            <button v-if="hasPermission('accounting-documents.review')" @click="retryCallback"
                                class="px-2.5 py-1 bg-red-50 text-red-700 text-xs font-bold rounded-lg hover:bg-red-100 transition-colors">
                                Retry callback
                            </button>
                        </div>
                    </div>
                </div>

                <div class="grid xl:grid-cols-2 gap-4">
                    <!-- Left: source document -->
                    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden dark:bg-gray-800 dark:border-gray-700 xl:sticky xl:top-4 xl:self-start">
                        <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between">
                            <h2 class="text-sm font-bold text-gray-700 uppercase tracking-wider dark:text-gray-200">Source Document</h2>
                            <a v-if="review.file_url && !fileExpired" :href="review.file_url" target="_blank" rel="noopener"
                                class="text-xs font-semibold text-blue-600 hover:text-blue-800">Open in new tab ↗</a>
                        </div>
                        <div v-if="fileExpired" class="p-10 text-center text-sm text-gray-500 dark:text-gray-300">
                            The secure file link has expired.<br />Ask the portal team to resubmit the document to refresh it.
                        </div>
                        <div v-else-if="review.file_url" class="h-[75vh]">
                            <embed :src="review.file_url" type="application/pdf" class="w-full h-full" />
                        </div>
                        <div v-else class="p-10 text-center text-sm text-gray-500 dark:text-gray-300">No file attached.</div>
                    </div>

                    <!-- Right: extracted data -->
                    <div class="space-y-4">
                        <!-- Exceptions summary -->
                        <div v-if="exceptions.length" class="bg-amber-50 border border-amber-200 rounded-xl p-4">
                            <h3 class="text-sm font-bold text-amber-800">⚠ Warnings from the portal validation</h3>
                            <ul class="mt-2 space-y-1">
                                <li v-for="(exception, i) in exceptions" :key="i" class="text-sm text-amber-800">
                                    <span class="font-semibold">{{ exception.rule_key }}</span>: {{ exception.message }}
                                </li>
                            </ul>
                        </div>

                        <!-- Header fields -->
                        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 dark:bg-gray-800 dark:border-gray-700">
                            <div class="flex items-center justify-between mb-4">
                                <h2 class="text-sm font-bold text-gray-700 uppercase tracking-wider dark:text-gray-200">Extracted Fields</h2>
                                <span :class="confidenceClass(review.confidence?.overall)" class="inline-flex px-2 py-1 text-xs font-semibold rounded-full">
                                    overall {{ pct(review.confidence?.overall) }}
                                </span>
                            </div>
                            <dl class="grid grid-cols-2 sm:grid-cols-3 gap-x-6 gap-y-4">
                                <div v-for="(value, key) in displayFields" :key="key" :class="key === 'vendor_address' ? 'col-span-2 sm:col-span-3' : ''">
                                    <dt class="text-xs font-bold text-gray-400 uppercase flex items-center gap-1.5 dark:text-gray-400">
                                        {{ key.replaceAll('_', ' ') }}
                                        <span v-if="review.confidence?.fields?.[key] != null"
                                            :class="confidenceClass(review.confidence.fields[key])"
                                            class="px-1.5 py-0.5 text-[10px] font-bold rounded-full normal-case">
                                            {{ pct(review.confidence.fields[key]) }}
                                        </span>
                                    </dt>
                                    <dd class="mt-0.5 text-sm font-semibold text-gray-800 dark:text-gray-100">
                                        {{ isAmountField(key) ? money(value) : (value ?? '—') }}
                                    </dd>
                                </div>
                            </dl>
                        </div>

                        <!-- Line items -->
                        <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden dark:bg-gray-800 dark:border-gray-700">
                            <div class="px-5 py-3 border-b border-gray-100 dark:border-gray-700">
                                <h2 class="text-sm font-bold text-gray-700 uppercase tracking-wider dark:text-gray-200">
                                    Line Items ({{ lineItems.length }})
                                </h2>
                            </div>
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-100 dark:divide-gray-700">
                                    <thead class="bg-gray-50 dark:bg-gray-900">
                                        <tr>
                                            <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase dark:text-slate-300">Description</th>
                                            <th class="px-4 py-2.5 text-right text-xs font-medium text-gray-500 uppercase dark:text-slate-300">Qty</th>
                                            <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase dark:text-slate-300">UOM</th>
                                            <th class="px-4 py-2.5 text-right text-xs font-medium text-gray-500 uppercase dark:text-slate-300">Unit Price</th>
                                            <th class="px-4 py-2.5 text-right text-xs font-medium text-gray-500 uppercase dark:text-slate-300">Line Total</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-50 dark:divide-gray-700">
                                        <tr v-for="(item, i) in lineItems" :key="i">
                                            <td class="px-4 py-2.5 text-sm text-gray-800 dark:text-gray-100">{{ item.description }}</td>
                                            <td class="px-4 py-2.5 text-right text-sm text-gray-600 dark:text-gray-300">{{ item.quantity ?? '—' }}</td>
                                            <td class="px-4 py-2.5 text-sm text-gray-600 dark:text-gray-300">{{ item.uom || '—' }}</td>
                                            <td class="px-4 py-2.5 text-right text-sm text-gray-600 dark:text-gray-300">{{ money(item.unit_price) }}</td>
                                            <td class="px-4 py-2.5 text-right text-sm font-semibold text-gray-800 dark:text-gray-100">{{ money(item.line_total) }}</td>
                                        </tr>
                                        <tr v-if="lineItems.length === 0">
                                            <td colspan="5" class="px-4 py-8 text-center text-sm text-gray-400">No line items.</td>
                                        </tr>
                                    </tbody>
                                    <tfoot v-if="lineItems.length" class="bg-gray-50 dark:bg-gray-900">
                                        <tr>
                                            <td colspan="4" class="px-4 py-2.5 text-right text-xs font-bold text-gray-500 uppercase dark:text-gray-300">Line sum</td>
                                            <td class="px-4 py-2.5 text-right text-sm font-bold text-gray-900 dark:text-gray-100">{{ money(lineSum) }}</td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>

                        <!-- Event log -->
                        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 dark:bg-gray-800 dark:border-gray-700">
                            <h2 class="text-sm font-bold text-gray-700 uppercase tracking-wider mb-3 dark:text-gray-200">Activity</h2>
                            <ul class="space-y-2">
                                <li v-for="event in review.events" :key="event.id" class="text-sm text-gray-600 dark:text-gray-300">
                                    <span class="font-semibold capitalize">{{ event.event.replaceAll('_', ' ') }}</span>
                                    <span v-if="event.user"> by {{ event.user.name }}</span>
                                    <span class="text-xs text-gray-400"> · {{ formatDateTime(event.created_at) }}</span>
                                    <p v-if="event.remarks" class="text-xs text-gray-500 mt-0.5 dark:text-gray-400">{{ event.remarks }}</p>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Decision modal -->
        <div v-if="decisionModal" class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex items-center justify-center min-h-screen px-4 py-6">
                <div class="fixed inset-0 bg-black/20 backdrop-blur-md" @click="decisionModal = null"></div>
                <div class="relative bg-white rounded-xl shadow-2xl w-full max-w-md p-6 border border-gray-100 dark:bg-gray-800 dark:border-gray-700">
                    <h3 class="text-lg font-bold text-gray-900 capitalize dark:text-gray-100">{{ decisionModal }} document</h3>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-300">
                        {{ review.source_reference_no }} — {{ review.vendor_name }}
                    </p>
                    <div class="mt-4">
                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1 dark:text-gray-300">
                            Remarks <span v-if="decisionModal !== 'approve'" class="text-red-500">*</span>
                        </label>
                        <textarea v-model="remarks" rows="4"
                            class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600"
                            :placeholder="decisionModal === 'approve' ? 'Optional notes' : 'Explain what needs to change'" />
                    </div>
                    <div class="flex justify-end space-x-3 pt-5">
                        <button @click="decisionModal = null"
                            class="px-4 py-2 text-sm font-semibold text-gray-600 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors dark:bg-gray-700 dark:text-gray-300">
                            Cancel
                        </button>
                        <button :disabled="submitting || (decisionModal !== 'approve' && !remarks.trim())" @click="submitDecision"
                            :class="decisionButtonClass" class="px-6 py-2 text-white text-sm font-bold rounded-lg shadow-md transition-all disabled:opacity-50">
                            Confirm {{ decisionModal }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { computed, ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { usePermission } from '@/Composables/usePermission'
import { useToast } from '@/Composables/useToast'

const props = defineProps({
    review: { type: Object, required: true },
})

const { hasPermission } = usePermission()
const { showSuccess, showError } = useToast()

const isDecided = computed(() => ['approved', 'returned', 'rejected'].includes(props.review.status))
const fileExpired = computed(() => props.review.file_url_expires_at && new Date(props.review.file_url_expires_at) < new Date())
const exceptions = computed(() => props.review.exceptions_summary || [])
const lineItems = computed(() => props.review.line_items || [])
const lineSum = computed(() => lineItems.value.reduce((sum, item) => sum + (Number(item.line_total) || 0), 0))

const displayFields = computed(() => {
    const preferred = ['invoice_no', 'po_number', 'document_date', 'due_date', 'currency', 'subtotal', 'tax_amount', 'total_amount', 'vendor_address']
    const fields = props.review.fields || {}
    const ordered = {}
    for (const key of preferred) {
        if (key in fields) ordered[key] = fields[key]
    }
    for (const [key, value] of Object.entries(fields)) {
        if (!(key in ordered)) ordered[key] = value
    }
    return ordered
})

const isAmountField = (key) => ['subtotal', 'tax_amount', 'total_amount'].includes(key)

// ---- decision flow ----
const decisionModal = ref(null)
const remarks = ref('')
const submitting = ref(false)

const openDecision = (decision) => {
    decisionModal.value = decision
    remarks.value = ''
}

const routeMap = { approve: 'approve', return: 'return', reject: 'reject' }

const submitDecision = () => {
    submitting.value = true
    router.post(route(`accounting-documents.${routeMap[decisionModal.value]}`, props.review.id),
        { remarks: remarks.value || null },
        {
            preserveScroll: true,
            onSuccess: () => { decisionModal.value = null },
            onError: (errors) => showError(Object.values(errors).flat().join(', ') || 'Failed to record decision'),
            onFinish: () => { submitting.value = false },
        })
}

const retryCallback = () => {
    router.post(route('accounting-documents.retry-callback', props.review.id), {}, { preserveScroll: true })
}

const decisionButtonClass = computed(() => ({
    approve: 'bg-green-600 hover:bg-green-700',
    return: 'bg-amber-500 hover:bg-amber-600',
    reject: 'bg-red-600 hover:bg-red-700',
}[decisionModal.value]))

const money = (value) => (value == null || value === '' ? '—' : Number(value).toLocaleString(undefined, { minimumFractionDigits: 2 }))
const pct = (value) => (value == null ? '—' : `${Math.round(value * 100)}%`)
const formatDateTime = (value) => (value ? new Date(value).toLocaleString() : '—')

const confidenceClass = (value) => {
    if (value == null) return 'bg-gray-100 text-gray-600'
    if (value >= 0.9) return 'bg-green-100 text-green-800'
    if (value >= 0.75) return 'bg-yellow-100 text-yellow-800'
    return 'bg-red-100 text-red-800'
}

const statusClass = (status) => ({
    pending: 'bg-blue-100 text-blue-800',
    in_review: 'bg-indigo-100 text-indigo-800',
    approved: 'bg-green-100 text-green-800',
    returned: 'bg-yellow-100 text-yellow-800',
    rejected: 'bg-red-100 text-red-800',
}[status] || 'bg-gray-100 text-gray-600')
</script>
