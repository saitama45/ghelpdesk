<script setup>
import { ref, computed } from 'vue'
import { Link, useForm, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { useToast } from '@/Composables/useToast'
import { usePermission } from '@/Composables/usePermission'
import { useConfirm } from '@/Composables/useConfirm'

const props = defineProps({
    posRequest: Object
})

const page = usePage()
const { showSuccess, showError } = useToast()
const { hasPermission } = usePermission()
const { confirm } = useConfirm()

const authUserId = computed(() => page.props.auth.user.id)

const approvalForm = useForm({
    remarks: ''
})

const submitApproval = async () => {
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

const canApprove = computed(() => {
    const status = props.posRequest.status || ''
    const alreadyApproved = props.posRequest.approvals?.some(a => Number(a.user_id) === Number(authUserId.value))

    return (status === 'Open' || status.startsWith('Approved Level')) && 
           hasPermission('pos_requests.approve') &&
           props.posRequest.current_approval_level > 0 &&
           !alreadyApproved;
})

const getStatusBadgeClass = (status) => {
    if (!status) return 'bg-gray-500 text-white'
    if (status.startsWith('Approved Level')) return 'bg-blue-500 text-white shadow-blue-200'
    switch (status) {
        case 'Approved': return 'bg-emerald-500 text-white shadow-emerald-200'
        case 'Open': return 'bg-blue-500 text-white shadow-blue-200'
        case 'Cancelled': return 'bg-rose-500 text-white shadow-rose-200'
        default: return 'bg-amber-500 text-white shadow-amber-200'
    }
}

const totalLevels = computed(() => Number(props.posRequest.request_type?.approval_levels || 0))
const stages = computed(() => Array.from({ length: totalLevels.value }, (_, i) => i + 1))

const getApprovalForLevel = (lvl) => {
    if (!props.posRequest.approvals) return null
    return props.posRequest.approvals.find(a => Number(a.level) === Number(lvl))
}

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
        <div class="py-12 bg-gray-50 min-h-screen">
            <div class="max-w-[1600px] mx-auto sm:px-6 lg:px-8">
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                    
                    <!-- Left: Request Details (2 cols) -->
                    <div class="lg:col-span-2 space-y-8">
                        <!-- Header Detail Card -->
                        <div class="bg-white rounded-[2.5rem] shadow-2xl shadow-gray-200/50 p-10 border border-gray-100 relative overflow-hidden">
                            <div class="absolute top-0 right-0 p-8">
                                <span :class="getStatusBadgeClass(posRequest.status)" class="px-8 py-3 rounded-2xl text-xs font-black uppercase tracking-[0.2em] shadow-lg text-center min-w-[150px]">
                                    {{ posRequest.status }}
                                </span>
                            </div>

                            <h1 class="text-4xl font-black text-gray-900 tracking-tighter mb-10 flex items-center">
                                <span class="text-indigo-600 mr-3">#{{ posRequest.id }}</span>
                                {{ posRequest.request_type.name }}
                            </h1>

                            <div class="grid grid-cols-2 md:grid-cols-5 gap-10">
                                <div>
                                    <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2">Company</label>
                                    <p class="text-lg font-bold text-gray-900">{{ posRequest.company.name }}</p>
                                </div>
                                <div>
                                    <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2">Requester</label>
                                    <p class="text-lg font-bold text-gray-900">{{ posRequest.user ? posRequest.user.name : (posRequest.requester_name || 'Public Submission') }}</p>
                                    <p v-if="!posRequest.user && posRequest.requester_email" class="text-[10px] text-gray-400 font-bold">{{ posRequest.requester_email }}</p>
                                </div>
                                <div>
                                    <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2">Requested Date</label>
                                    <p class="text-lg font-mono font-black text-gray-600">{{ formatDateTime(posRequest.created_at) }}</p>
                                </div>
                                <div>
                                    <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2">Launch Date</label>
                                    <p class="text-lg font-mono font-black text-indigo-600">{{ posRequest.launch_date }}</p>
                                </div>
                                <div>
                                    <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2">Effectivity</label>
                                    <p class="text-lg font-mono font-black text-emerald-600">{{ posRequest.effectivity_date }}</p>
                                </div>
                            </div>

                            <div class="mt-10 pt-10 border-t border-gray-100">
                                <label class="block text-[10px] font-black text-gray-500 uppercase tracking-widest mb-4">Stores Covered</label>
                                <div class="flex flex-wrap gap-2">
                                    <template v-if="posRequest.stores_covered.includes('all')">
                                        <span class="px-6 py-2 bg-indigo-600 text-white rounded-xl text-xs font-black uppercase italic tracking-widest shadow-lg shadow-indigo-100">All Stores</span>
                                    </template>
                                    <template v-else>
                                        <span v-for="code in posRequest.stores_covered" :key="code" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-xl text-xs font-bold border border-gray-200">
                                            {{ code }}
                                        </span>
                                    </template>
                                </div>
                            </div>
                        </div>

                        <!-- Line Items Table -->
                        <div class="bg-white rounded-[2.5rem] shadow-2xl shadow-gray-200/50 p-10 border border-gray-100">
                            <h3 class="text-2xl font-black text-gray-900 mb-8">Detailed Configuration</h3>
                            <div class="overflow-x-auto custom-scrollbar">
                                <table class="w-full border-separate border-spacing-y-3">
                                    <thead>
                                        <tr class="text-[10px] font-black text-gray-500 uppercase tracking-widest text-left">
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
                                        <tr v-for="item in posRequest.details" :key="item.id" class="bg-gray-50/50 hover:bg-white hover:shadow-xl transition-all duration-300 rounded-2xl group">
                                            <td class="px-6 py-5 rounded-l-2xl">
                                                <div class="font-black text-gray-900 group-hover:text-indigo-600 transition-colors">{{ item.product_name }}</div>
                                            </td>
                                            <td class="px-6 py-5 font-bold text-gray-700">{{ item.pos_name }}</td>
                                            <td class="px-6 py-5 text-center">
                                                <span class="px-3 py-1 bg-white border border-gray-200 rounded-lg text-[10px] font-black uppercase text-gray-600 shadow-sm">{{ item.price_type }}</span>
                                            </td>
                                            <td class="px-6 py-5 text-center font-black text-indigo-600">
                                                {{ item.price_amount ? Number(item.price_amount).toLocaleString(undefined, { minimumFractionDigits: 2 }) : '-' }}
                                            </td>
                                            <td class="px-6 py-5 font-bold text-gray-700">{{ item.category }}</td>
                                            <td class="px-6 py-5 font-mono text-xs font-black bg-white/50 rounded-lg">{{ item.item_code }}</td>
                                            <td class="px-6 py-5">
                                                <div class="text-[11px] text-gray-600 font-medium max-w-[200px] truncate" :title="item.remarks_mechanics">
                                                    {{ item.remarks_mechanics || '-' }}
                                                </div>
                                            </td>
                                            <td class="px-6 py-5 text-center font-bold text-gray-600">{{ item.sc || '-' }}</td>
                                            <td class="px-6 py-5 text-center font-bold text-gray-600">{{ item.local_tax || '-' }}</td>
                                            <td class="px-6 py-5 text-center font-bold text-gray-600">{{ item.mgr_meal || '-' }}</td>
                                            <td class="px-6 py-5 text-center rounded-r-2xl font-bold text-gray-500">{{ item.printer }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Right: Approval Sidebar (1 col) -->
                    <div class="space-y-8">
                        <!-- Approval Pulse Tracker -->
                        <div class="bg-white rounded-[2.5rem] shadow-2xl shadow-gray-200/50 p-8 border border-gray-100">
                            <div class="flex items-center justify-between mb-10">
                                <h3 class="text-xl font-black text-gray-900 tracking-tight">Approval Pulse</h3>
                                <span v-if="posRequest.status === 'Approved'" class="px-3 py-1 bg-emerald-100 text-emerald-700 rounded-lg text-[9px] font-black uppercase tracking-widest">
                                    Finalized
                                </span>
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
                                            <div v-if="getApprovalForLevel(lvl)" class="mt-3 p-4 bg-gray-50 rounded-2xl border border-gray-100 shadow-sm relative overflow-hidden group">
                                                <div class="absolute top-0 left-0 w-1 h-full bg-emerald-500"></div>
                                                <div class="flex items-center mb-2">
                                                    <div class="w-6 h-6 rounded-full bg-indigo-100 flex items-center justify-center text-[10px] font-black text-indigo-600 mr-2 capitalize">
                                                        {{ getApprovalForLevel(lvl).user?.name?.charAt(0) || '?' }}
                                                    </div>
                                                    <span class="text-xs font-black text-gray-900">{{ getApprovalForLevel(lvl).user?.name || 'Unknown User' }}</span>
                                                </div>
                                                <p v-if="getApprovalForLevel(lvl).remarks" class="text-[11px] text-gray-700 italic font-medium leading-relaxed">
                                                    "{{ getApprovalForLevel(lvl).remarks }}"
                                                </p>
                                                <p v-else class="text-[9px] text-gray-400 font-bold uppercase tracking-tighter">Approved without remarks</p>
                                            </div>

                                            <!-- Pending State -->
                                            <div v-else-if="Number(lvl) === Number(posRequest.current_approval_level)" class="mt-3 p-4 bg-indigo-50/50 rounded-2xl border-2 border-dashed border-indigo-200">
                                                <div class="flex items-center text-indigo-600">
                                                    <svg class="w-4 h-4 mr-2 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                                    </svg>
                                                    <span class="text-[10px] font-black uppercase tracking-widest">Awaiting Decision</span>
                                                </div>
                                            </div>

                                            <!-- Future State -->
                                            <div v-else class="mt-3 px-4 py-2 text-[10px] font-bold text-gray-400 uppercase italic">
                                                Locked
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Action Area -->
                            <div v-if="canApprove" class="mt-12 pt-10 border-t border-gray-100 relative">
                                <div class="absolute -top-3 left-1/2 -translate-x-1/2 px-4 bg-white text-[9px] font-black text-indigo-500 uppercase tracking-[0.3em]">Your Decision</div>
                                <div class="mb-6">
                                    <label class="block text-[9px] font-black text-slate-600 uppercase tracking-widest mb-3 ml-1">Approval Remarks (Optional)</label>
                                    <textarea v-model="approvalForm.remarks" rows="3" placeholder="Add comments for this level..." 
                                              class="w-full bg-gray-50 border-2 border-gray-100 rounded-3xl p-5 text-sm font-medium focus:bg-white focus:border-indigo-500 focus:ring-0 transition-all shadow-inner text-gray-900"></textarea>
                                </div>
                                <button @click="submitApproval" :disabled="approvalForm.processing"
                                        class="w-full py-5 bg-indigo-600 text-white rounded-[1.5rem] font-black text-sm uppercase tracking-[0.2em] shadow-2xl shadow-indigo-200 hover:bg-indigo-700 transform hover:-translate-y-1 active:scale-95 transition-all disabled:opacity-50 flex items-center justify-center">
                                    <span>Release Level {{ posRequest.current_approval_level }}</span>
                                    <svg class="w-5 h-5 ml-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                </button>
                            </div>
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
