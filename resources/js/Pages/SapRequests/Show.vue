<script setup>
import { ref, computed } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { usePermission } from '@/Composables/usePermission'

const props = defineProps({ sapRequest: Object })

const { hasPermission } = usePermission()
const approvalForm = useForm({ remarks: '' })

function submitApproval() {
    approvalForm.post(route('sap-requests.approve', props.sapRequest.id), {
        onSuccess: () => approvalForm.reset(),
    })
}

const canApprove = computed(() => {
    const s = props.sapRequest.status ?? ''
    return (s === 'Open' || s.startsWith('Approved Level')) &&
        hasPermission('sap_requests.approve') &&
        props.sapRequest.current_approval_level > 0
})

const totalLevels = computed(() => Number(props.sapRequest.request_type?.approval_levels ?? 0))
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

const formData = computed(() => props.sapRequest.form_data ?? {})
const items = computed(() => props.sapRequest.items ?? [])
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
                                    <Link :href="route('tickets.show', sapRequest.ticket.id)" class="text-sm font-black text-teal-600 hover:text-teal-800 font-mono">
                                        {{ sapRequest.ticket.ticket_key }}
                                    </Link>
                                </div>
                            </div>
                        </div>

                        <!-- Form Data Card -->
                        <div class="bg-white rounded-[2.5rem] shadow-2xl shadow-gray-200/50 p-10 border border-gray-100">
                            <h3 class="text-xl font-black text-gray-900 mb-6">Request Details</h3>
                            <dl class="divide-y divide-gray-50">
                                <div v-for="(value, key) in formData" :key="key" class="flex items-start justify-between py-4">
                                    <dt class="text-xs font-black text-gray-400 uppercase tracking-widest w-1/3">
                                        {{ String(key).replace(/_/g, ' ') }}
                                    </dt>
                                    <dd class="text-sm font-semibold text-gray-900 text-right w-2/3">
                                        <template v-if="Array.isArray(value)">{{ value.join(', ') }}</template>
                                        <template v-else>{{ value ?? '—' }}</template>
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
                                                <template v-if="Array.isArray(val)">{{ val.join(', ') }}</template>
                                                <template v-else>{{ val ?? '—' }}</template>
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
                                            </div>
                                            <div v-else class="mt-3 px-4 py-2 text-[10px] font-bold text-gray-400 uppercase italic">Locked</div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Approve Action -->
                            <div v-if="canApprove" class="mt-10 pt-8 border-t border-gray-100 relative">
                                <div class="absolute -top-3 left-1/2 -translate-x-1/2 px-4 bg-white text-[9px] font-black text-teal-500 uppercase tracking-[0.3em]">Your Decision</div>
                                <textarea v-model="approvalForm.remarks" rows="3" placeholder="Add approval remarks (optional)..."
                                    class="w-full bg-gray-50 border-2 border-gray-100 rounded-3xl p-4 text-sm font-medium focus:bg-white focus:border-teal-500 focus:ring-0 transition-all mb-4"></textarea>
                                <button @click="submitApproval" :disabled="approvalForm.processing"
                                    class="w-full py-4 bg-teal-600 text-white rounded-[1.5rem] font-black text-sm uppercase tracking-[0.2em] shadow-2xl shadow-teal-200 hover:bg-teal-700 transform hover:-translate-y-1 active:scale-95 transition-all disabled:opacity-50 flex items-center justify-center gap-2">
                                    <span>Release Level {{ sapRequest.current_approval_level }}</span>
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
