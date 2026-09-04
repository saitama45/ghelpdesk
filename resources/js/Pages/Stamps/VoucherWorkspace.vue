<script setup>
import { computed, nextTick, onMounted, onUnmounted, reactive, ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import axios from 'axios'
import Modal from '@/Components/Modal.vue'
import Autocomplete from '@/Components/Autocomplete.vue'
import { usePermission } from '@/Composables/usePermission.js'
import { useToast } from '@/Composables/useToast.js'

const props = defineProps({
    batches: { type: Array, default: () => [] },
    redemptions: { type: Array, default: () => [] },
    customers: { type: Array, default: () => [] },
    stores: { type: Array, default: () => [] },
    summary: { type: Object, default: () => ({}) },
})
const { hasPermission } = usePermission()
const { addToast } = useToast()

const money = value => Number(value || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const date = value => value ? new Date(`${String(value).slice(0, 10)}T00:00:00`).toLocaleDateString('en-PH') : '—'
const dateTime = value => value ? new Date(value).toLocaleString('en-PH') : '—'
const statusClass = status => ({ active: 'bg-green-100 text-green-700', not_yet_valid: 'bg-blue-100 text-blue-700', expired: 'bg-gray-100 text-gray-700', draft: 'bg-amber-100 text-amber-700', suspended: 'bg-orange-100 text-orange-700', cancelled: 'bg-red-100 text-red-700' }[status] || 'bg-gray-100 text-gray-700')
const statusLabel = status => String(status || '').replaceAll('_', ' ').replace(/\b\w/g, c => c.toUpperCase())
const batchStatusHelp = batch => {
    if (batch.effective_status === 'not_yet_valid') return `Starts ${date(batch.claim_starts_on)}`
    if (batch.effective_status === 'expired') return `Ended ${date(batch.claim_ends_on)}`
    if (batch.effective_status === 'active') return `Valid until ${date(batch.claim_ends_on)}`
    return ''
}
const pdfRequestingId = ref(null)
const pdfBusy = batch => pdfRequestingId.value === batch.id || (['queued', 'processing'].includes(batch.pdf_status) && !batch.pdf_is_stale)

let pdfPoll = null
let voucherAutoVerifyTimer = null
onMounted(() => {
    pdfPoll = window.setInterval(() => {
        if (props.batches.some(pdfBusy)) {
            router.reload({ only: ['voucherBatches'], preserveScroll: true, preserveState: true })
        }
    }, 4000)
})
onUnmounted(() => {
    if (pdfPoll) window.clearInterval(pdfPoll)
    if (voucherAutoVerifyTimer) window.clearTimeout(voucherAutoVerifyTimer)
})

const batchModal = ref(false)
const editingBatch = ref(null)
const logoInput = ref(null)
const batchForm = useForm({ partner_name: 'Globe', title: 'Globe ₱150 Voucher', description: '', quantity: 917, face_value: 150, turnover_date: '2026-09-09', claim_starts_on: '', claim_ends_on: '', claim_instructions: 'Present to the cashier as payment.', short_terms: 'Single use only. No cash change. One voucher per sale.', partner_logo: null })
const openBatch = batch => {
    editingBatch.value = batch || null
    batchForm.clearErrors()
    if (batch) Object.assign(batchForm, {
        partner_name: batch.partner_name, title: batch.title, description: batch.description || '', quantity: batch.quantity,
        face_value: batch.face_value, turnover_date: batch.turnover_date || '', claim_starts_on: batch.claim_starts_on || '',
        claim_ends_on: batch.claim_ends_on || '', claim_instructions: batch.claim_instructions || '', short_terms: batch.short_terms || '', partner_logo: null,
    })
    else batchForm.reset()
    if (logoInput.value) logoInput.value.value = ''
    batchModal.value = true
}
const onLogo = event => { batchForm.partner_logo = event.target.files?.[0] || null }
const submitBatch = () => {
    const editing = !!editingBatch.value
    batchForm.transform(data => {
        if (!editing) return data
        const { quantity, face_value, ...editable } = data
        return editable
    }).post(editing ? route('stamps.voucher-batches.update', editingBatch.value.id) : route('stamps.voucher-batches.store'), {
        forceFormData: true, preserveScroll: true,
        onSuccess: () => { batchModal.value = false; editingBatch.value = null; batchForm.reset() },
        onFinish: () => batchForm.transform(data => data),
    })
}

const postBatchAction = (batch, action, payload = {}) => router.post(route(`stamps.voucher-batches.${action}`, batch.id), payload, {
    preserveScroll: true,
    onError: errors => addToast(Object.values(errors || {})[0] || 'The voucher action could not be completed.', 'error'),
})
const activate = batch => {
    if (!batch.claim_starts_on || !batch.claim_ends_on) {
        addToast('Edit the batch and enter both claim dates before activation.', 'error')
        return
    }
    postBatchAction(batch, batch.status === 'suspended' ? 'resume' : 'activate')
}
const requestPdf = batch => {
    pdfRequestingId.value = batch.id
    router.post(route('stamps.voucher-batches.pdf', batch.id), {}, {
        preserveScroll: true,
        onError: errors => addToast(Object.values(errors || {})[0] || 'The voucher PDF could not be generated.', 'error'),
        onFinish: () => { pdfRequestingId.value = null },
    })
}

const claimModal = ref(false)
const claimBatch = ref(null)
const claimForm = useForm({ claim_starts_on: '', claim_ends_on: '' })
const openClaimPeriod = batch => {
    claimBatch.value = batch
    claimForm.claim_starts_on = batch.claim_starts_on || ''
    claimForm.claim_ends_on = batch.claim_ends_on || ''
    claimForm.clearErrors()
    claimModal.value = true
}
const submitClaimPeriod = () => claimForm.post(route('stamps.voucher-batches.claim-period', claimBatch.value.id), {
    preserveScroll: true,
    onSuccess: () => { claimModal.value = false; claimBatch.value = null },
})

const reasonModal = reactive({ open: false, kind: '', target: null, title: '', reason: '', processing: false })
const askReason = (kind, target, title) => Object.assign(reasonModal, { open: true, kind, target, title, reason: '', processing: false })
const submitReason = async () => {
    if (!reasonModal.reason.trim()) return
    reasonModal.processing = true
    try {
        if (reasonModal.kind === 'cancel') {
            postBatchAction(reasonModal.target, 'cancel', { reason: reasonModal.reason })
        } else {
            const routeName = reasonModal.kind === 'voucher' ? 'stamps.vouchers.void' : 'stamps.voucher-redemptions.void'
            await axios.post(route(routeName, reasonModal.target.id), { reason: reasonModal.reason })
            addToast(reasonModal.kind === 'voucher' ? 'Unused voucher voided.' : 'Redemption voided and voucher restored.', 'success')
            router.reload({ only: ['voucherBatches', 'voucherRedemptions', 'voucherSummary'] })
            scan.open = false
        }
        reasonModal.open = false
    } catch (error) {
        addToast(error.response?.data?.errors?.reason?.[0] || 'The action could not be completed.', 'error')
    } finally { reasonModal.processing = false }
}

const scan = reactive({ open: false, code: '', store_id: null, loading: false, result: null, error: '' })
const scanInput = ref(null)
const redeeming = ref(false)
const newCustomer = ref(false)
const rememberedStore = ref((() => { try { return Number(localStorage.getItem('vouchers:currentStoreId')) || null } catch { return null } })())
const todayLocal = () => { const d = new Date(); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}` }
const redemption = reactive({ customer_id: null, new_customer_name: '', new_customer_phone: '', new_customer_email: '', receipt_number: '', sale_date: todayLocal(), gross_sale_total: null })
watch(() => scan.store_id, value => { rememberedStore.value = value; try { value ? localStorage.setItem('vouchers:currentStoreId', value) : localStorage.removeItem('vouchers:currentStoreId') } catch {} })
const openScan = () => {
    Object.assign(scan, { open: true, code: '', store_id: rememberedStore.value, loading: false, result: null, error: '' })
    Object.assign(redemption, { customer_id: null, new_customer_name: '', new_customer_phone: '', new_customer_email: '', receipt_number: '', sale_date: todayLocal(), gross_sale_total: null })
    newCustomer.value = false
    nextTick(() => scanInput.value?.focus())
}
const verify = async () => {
    if (!scan.code.trim() || scan.loading) return
    scan.loading = true; scan.error = ''
    try { scan.result = (await axios.post(route('stamps.vouchers.verify'), { code: scan.code, store_id: scan.store_id })).data }
    catch (error) { scan.error = error.response?.data?.message || 'Unable to verify the voucher.' }
    finally { scan.loading = false }
}
const submitVoucherScan = () => {
    if (voucherAutoVerifyTimer) window.clearTimeout(voucherAutoVerifyTimer)
    verify()
}
watch(() => scan.code, value => {
    if (voucherAutoVerifyTimer) window.clearTimeout(voucherAutoVerifyTimer)
    const code = String(value || '').trim()
    if (!scan.open || scan.loading || scan.result || !/^VCH-[2-9A-HJ-NP-Z]{4}(?:-[2-9A-HJ-NP-Z]{4}){3}$/i.test(code)) return
    voucherAutoVerifyTimer = window.setTimeout(() => {
        if (scan.code.trim() === code && !scan.result) verify()
    }, 150)
})
const resetScan = () => {
    if (voucherAutoVerifyTimer) window.clearTimeout(voucherAutoVerifyTimer)
    scan.code = ''; scan.result = null; scan.error = ''; nextTick(() => scanInput.value?.focus())
}
const applyPayment = async () => {
    redeeming.value = true
    try {
        await axios.post(route('stamps.vouchers.redeem'), { code: scan.result.voucher.code, store_id: scan.store_id, ...redemption,
            customer_id: newCustomer.value ? null : redemption.customer_id,
            new_customer_name: newCustomer.value ? redemption.new_customer_name : null,
            new_customer_phone: newCustomer.value ? redemption.new_customer_phone : null,
            new_customer_email: newCustomer.value ? redemption.new_customer_email : null,
        })
        addToast('Voucher applied as payment and marked Used.', 'success')
        scan.open = false
        router.reload({ only: ['customers', 'voucherBatches', 'voucherRedemptions', 'voucherSummary'] })
    } catch (error) {
        const errors = error.response?.data?.errors
        scan.error = errors ? Object.values(errors).flat()[0] : 'Unable to apply the voucher.'
    } finally { redeeming.value = false }
}
const customerOptions = computed(() => props.customers.filter(c => c.is_active).map(c => ({ value: c.id, label: `${c.name}${c.phone ? ` (${c.phone})` : ''}` })))
const storeOptions = computed(() => props.stores.map(s => ({ value: s.id, label: `${s.code}${s.name ? ` — ${s.name}` : ''}` })))
</script>

<template>
    <div class="space-y-6">
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-5">
            <div v-for="item in [{l:'Batches',v:summary.batches},{l:'Available',v:summary.issued},{l:'Used',v:summary.used},{l:'Void',v:summary.void},{l:'Recognized',v:`₱${money(summary.recognized)}`}]" :key="item.l" class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
                <p class="text-xs font-bold uppercase text-gray-400">{{ item.l }}</p><p class="mt-1 text-xl font-black text-gray-900 dark:text-white">{{ item.v || 0 }}</p>
            </div>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 p-4 dark:border-gray-700">
                <div><h3 class="font-bold text-gray-900 dark:text-white">Campaign Voucher Batches</h3><p class="text-xs text-gray-500">Generate, activate, print, and monitor in-house payment vouchers.</p></div>
                <div class="flex gap-2">
                    <button v-if="hasPermission('stamps.redeem')" @click="openScan" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-bold text-white hover:bg-emerald-700">Verify / Use Voucher</button>
                    <button v-if="hasPermission('stamps.create')" @click="openBatch(null)" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-bold text-white hover:bg-blue-700">+ New Batch</button>
                </div>
            </div>
            <div class="overflow-x-auto"><table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500 dark:bg-gray-900/40"><tr><th class="px-4 py-3">Batch</th><th class="px-4 py-3">Value / Count</th><th class="px-4 py-3">Claim Period</th><th class="px-4 py-3">Status</th><th class="px-4 py-3">PDF</th><th class="px-4 py-3 text-right">Actions</th></tr></thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    <tr v-for="batch in batches" :key="batch.id">
                        <td class="px-4 py-3"><p class="font-bold text-gray-900 dark:text-white">{{ batch.title }}</p><p class="text-xs text-gray-500">{{ batch.partner_name }} · Turnover {{ date(batch.turnover_date) }}</p></td>
                        <td class="px-4 py-3">₱{{ money(batch.face_value) }}<p class="text-xs text-gray-500">{{ batch.used_count }} used / {{ batch.vouchers_count }} generated</p></td>
                        <td class="px-4 py-3">{{ batch.claim_starts_on && batch.claim_ends_on ? `${date(batch.claim_starts_on)} – ${date(batch.claim_ends_on)}` : 'To follow' }}</td>
                        <td class="px-4 py-3"><span :class="['rounded-full px-2 py-1 text-xs font-bold', statusClass(batch.effective_status)]">{{ statusLabel(batch.effective_status) }}</span><p v-if="batchStatusHelp(batch)" class="mt-1 text-[10px] text-gray-500">{{ batchStatusHelp(batch) }}</p></td>
                        <td class="px-4 py-3"><span class="text-xs">{{ statusLabel(batch.pdf_status) }}</span><p v-if="batch.pdf_generated_at" class="text-[10px] text-gray-400">{{ dateTime(batch.pdf_generated_at) }}</p></td>
                        <td class="px-4 py-3"><div class="flex justify-end gap-1 whitespace-nowrap">
                            <button v-if="hasPermission('stamps.edit') && batch.status === 'draft'" @click="openBatch(batch)" class="rounded px-2 py-1 text-blue-600 hover:bg-blue-50">Edit</button>
                            <button v-if="hasPermission('stamps.edit') && batch.status !== 'draft' && batch.status !== 'cancelled'" @click="openClaimPeriod(batch)" :disabled="pdfBusy(batch)" class="rounded px-2 py-1 text-blue-600 hover:bg-blue-50 disabled:cursor-wait disabled:opacity-50">Edit Claim Period</button>
                            <button v-if="hasPermission('stamps.approve') && ['draft','suspended'].includes(batch.status)" @click="activate(batch)" class="rounded px-2 py-1 text-green-700 hover:bg-green-50">{{ batch.status === 'suspended' ? 'Resume' : 'Activate' }}</button>
                            <button v-if="hasPermission('stamps.approve') && batch.status === 'active'" @click="postBatchAction(batch, 'suspend')" class="rounded px-2 py-1 text-orange-700 hover:bg-orange-50">Suspend</button>
                            <button v-if="hasPermission('stamps.export') && batch.pdf_status !== 'ready'" @click="requestPdf(batch)" :disabled="pdfBusy(batch)" class="rounded px-2 py-1 text-indigo-700 hover:bg-indigo-50 disabled:cursor-wait disabled:opacity-60">{{ pdfBusy(batch) ? 'Preparing Print PDF…' : (batch.pdf_status === 'failed' || batch.pdf_is_stale ? 'Retry Print PDF' : 'Prepare Print PDF') }}</button>
                            <a v-if="hasPermission('stamps.export') && batch.pdf_status === 'ready'" :href="route('stamps.voucher-batches.pdf.download', batch.id)" target="_blank" rel="noopener" class="rounded bg-indigo-600 px-2 py-1 font-bold text-white hover:bg-indigo-700">Open / Print Vouchers</a>
                            <button v-if="hasPermission('stamps.export') && batch.pdf_status === 'ready'" @click="requestPdf(batch)" :disabled="pdfBusy(batch)" class="rounded px-2 py-1 text-indigo-700 hover:bg-indigo-50 disabled:cursor-wait disabled:opacity-60">{{ pdfBusy(batch) ? 'Rebuilding…' : 'Rebuild Smaller PDF' }}</button>
                            <button v-if="hasPermission('stamps.cancel') && batch.status !== 'cancelled'" @click="askReason('cancel', batch, 'Cancel voucher batch')" class="rounded px-2 py-1 text-red-700 hover:bg-red-50">Cancel</button>
                        </div></td>
                    </tr>
                    <tr v-if="!batches.length"><td colspan="6" class="px-4 py-10 text-center text-gray-400">No voucher batches yet.</td></tr>
                </tbody>
            </table></div>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
            <div class="flex items-center justify-between border-b border-gray-200 p-4 dark:border-gray-700"><div><h3 class="font-bold text-gray-900 dark:text-white">Recent Voucher Payments</h3><p class="text-xs text-gray-500">Latest 100 redemption and reversal records.</p></div><a v-if="hasPermission('stamps.export')" :href="route('stamps.voucher-redemptions.export')" class="rounded-lg border border-gray-300 px-3 py-2 text-xs font-bold dark:border-gray-600">Export CSV</a></div>
            <div class="overflow-x-auto"><table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700"><thead class="bg-gray-50 text-left text-xs uppercase text-gray-500 dark:bg-gray-900/40"><tr><th class="px-4 py-3">Voucher</th><th class="px-4 py-3">Customer</th><th class="px-4 py-3">Sale</th><th class="px-4 py-3">Applied</th><th class="px-4 py-3">Cashier</th><th class="px-4 py-3">Status</th></tr></thead><tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                <tr v-for="row in redemptions" :key="row.id"><td class="px-4 py-3 font-mono text-xs">{{ row.voucher?.code }}<p class="font-sans text-gray-400">{{ row.voucher?.batch?.title }}</p></td><td class="px-4 py-3">{{ row.customer?.name }}<p class="text-xs text-gray-400">{{ row.customer?.phone }}</p></td><td class="px-4 py-3">{{ row.store?.code }} / {{ row.receipt_number }}<p class="text-xs text-gray-400">{{ date(row.sale_date) }} · Total ₱{{ money(row.gross_sale_total) }}</p></td><td class="px-4 py-3">₱{{ money(row.applied_amount) }}<p v-if="Number(row.forfeited_amount)" class="text-xs text-amber-600">₱{{ money(row.forfeited_amount) }} forfeited</p></td><td class="px-4 py-3">{{ row.cashier?.name }}<p class="text-xs text-gray-400">{{ dateTime(row.redeemed_at) }}</p></td><td class="px-4 py-3"><span :class="row.voided_at ? 'text-red-600' : 'text-green-600'" class="font-bold">{{ row.voided_at ? 'Voided' : 'Used' }}</span></td></tr>
                <tr v-if="!redemptions.length"><td colspan="6" class="px-4 py-8 text-center text-gray-400">No voucher payments recorded.</td></tr>
            </tbody></table></div>
        </div>

        <Modal :show="batchModal" @close="batchModal = false" max-width="2xl"><div class="p-6"><h3 class="text-lg font-bold dark:text-white">{{ editingBatch ? 'Edit Voucher Batch' : 'New Voucher Batch' }}</h3><p v-if="editingBatch" class="mt-1 text-xs text-amber-600">Quantity, face value, and generated codes are locked.</p>
            <div class="mt-5 grid gap-4 sm:grid-cols-2">
                <label class="text-sm">Partner<input v-model="batchForm.partner_name" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-gray-900"/><span class="text-xs text-red-600">{{ batchForm.errors.partner_name }}</span></label>
                <label class="text-sm">Voucher title<input v-model="batchForm.title" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-gray-900"/><span class="text-xs text-red-600">{{ batchForm.errors.title }}</span></label>
                <label class="text-sm">Quantity<input v-model.number="batchForm.quantity" :disabled="!!editingBatch" type="number" min="1" max="10000" class="mt-1 w-full rounded-lg border-gray-300 disabled:bg-gray-100 dark:bg-gray-900"/></label>
                <label class="text-sm">Face value<input v-model.number="batchForm.face_value" :disabled="!!editingBatch" type="number" min="0.01" step="0.01" class="mt-1 w-full rounded-lg border-gray-300 disabled:bg-gray-100 dark:bg-gray-900"/></label>
                <label class="text-sm">Turnover date<input v-model="batchForm.turnover_date" type="date" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-gray-900"/></label>
                <label class="text-sm">Partner logo<input ref="logoInput" type="file" accept="image/*" @change="onLogo" class="mt-1 block w-full text-xs"/><span class="text-xs text-red-600">{{ batchForm.errors.partner_logo }}</span></label>
                <label class="text-sm">Claim starts<input v-model="batchForm.claim_starts_on" type="date" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-gray-900"/><span class="text-xs text-red-600">{{ batchForm.errors.claim_starts_on }}</span></label>
                <label class="text-sm">Claim ends<input v-model="batchForm.claim_ends_on" type="date" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-gray-900"/><span class="text-xs text-red-600">{{ batchForm.errors.claim_ends_on }}</span></label>
                <label class="text-sm sm:col-span-2">Claim instructions<input v-model="batchForm.claim_instructions" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-gray-900"/></label>
                <label class="text-sm sm:col-span-2">Short printed terms<textarea v-model="batchForm.short_terms" rows="2" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-gray-900"></textarea></label>
                <label class="text-sm sm:col-span-2">Internal description<textarea v-model="batchForm.description" rows="2" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-gray-900"></textarea></label>
            </div><div class="mt-6 flex justify-end gap-2"><button @click="batchModal=false" class="rounded-lg px-4 py-2 text-sm">Cancel</button><button @click="submitBatch" :disabled="batchForm.processing" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-bold text-white disabled:opacity-50">{{ batchForm.processing ? 'Saving…' : 'Save Batch' }}</button></div>
        </div></Modal>

        <Modal :show="claimModal" @close="claimModal = false" max-width="md"><div class="p-6">
            <h3 class="text-lg font-bold dark:text-white">Edit Claim Period</h3>
            <p class="mt-1 text-sm text-gray-500">{{ claimBatch?.title }}</p>
            <p class="mt-2 text-xs text-amber-600">Changing these dates immediately changes whether unused vouchers can be accepted. The existing print PDF will be invalidated.</p>
            <div class="mt-5 grid gap-4 sm:grid-cols-2">
                <label class="text-sm">Claim starts<input v-model="claimForm.claim_starts_on" type="date" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-gray-900"/><span class="text-xs text-red-600">{{ claimForm.errors.claim_starts_on }}</span></label>
                <label class="text-sm">Claim ends<input v-model="claimForm.claim_ends_on" type="date" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-gray-900"/><span class="text-xs text-red-600">{{ claimForm.errors.claim_ends_on }}</span></label>
            </div>
            <div class="mt-6 flex justify-end gap-2"><button @click="claimModal=false" class="rounded-lg px-4 py-2 text-sm">Cancel</button><button @click="submitClaimPeriod" :disabled="claimForm.processing" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-bold text-white disabled:opacity-50">{{ claimForm.processing ? 'Saving…' : 'Save Claim Period' }}</button></div>
        </div></Modal>

        <Modal :show="scan.open" @close="scan.open=false" max-width="2xl"><div class="p-6"><div class="flex items-center justify-between"><div><h3 class="text-lg font-bold dark:text-white">Verify / Use Voucher</h3><p class="text-xs text-gray-500">Scanning verifies first. The voucher is used only after payment confirmation.</p></div><button v-if="scan.result" @click="resetScan" class="text-sm font-bold text-blue-600">Scan another</button></div>
            <div class="mt-5 grid gap-3 sm:grid-cols-2"><label class="text-sm">Current store<Autocomplete v-model="scan.store_id" :options="storeOptions" placeholder="Select store"/></label><label class="text-sm">Voucher code<input ref="scanInput" v-model="scan.code" @keydown.enter.prevent="submitVoucherScan" :disabled="!!scan.result" autocomplete="off" class="mt-1 w-full rounded-lg border-gray-300 font-mono uppercase dark:bg-gray-900" placeholder="Scan barcode or enter code"/><span class="text-xs text-gray-500">A complete barcode scan verifies automatically.</span></label></div>
            <button v-if="!scan.result" @click="verify" :disabled="scan.loading || !scan.code" class="mt-3 w-full rounded-lg bg-emerald-600 py-2.5 font-bold text-white disabled:opacity-50">{{ scan.loading ? 'Verifying…' : 'Verify Voucher' }}</button>
            <p v-if="scan.error" class="mt-3 rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ scan.error }}</p>
            <div v-if="scan.result" class="mt-4 rounded-xl border p-4" :class="scan.result.result === 'active' ? 'border-green-300 bg-green-50' : 'border-red-200 bg-red-50'">
                <p class="font-black" :class="scan.result.result === 'active' ? 'text-green-800' : 'text-red-800'">{{ scan.result.message }}</p>
                <template v-if="scan.result.voucher"><p class="mt-1 font-mono text-sm">{{ scan.result.voucher.code }}</p><p class="text-sm">{{ scan.result.voucher.batch.title }} · ₱{{ money(scan.result.voucher.value) }}</p></template>
                <div v-if="scan.result.voucher?.redemption" class="mt-3 grid gap-1 text-sm text-gray-700"><p><strong>Customer:</strong> {{ scan.result.voucher.redemption.customer?.name }}</p><p><strong>Store / receipt:</strong> {{ scan.result.voucher.redemption.store?.code }} / {{ scan.result.voucher.redemption.receipt_number }}</p><p><strong>Used:</strong> {{ dateTime(scan.result.voucher.redemption.redeemed_at) }}</p><p><strong>Processed by cashier:</strong> {{ scan.result.voucher.redemption.cashier?.name }}</p><button v-if="hasPermission('stamps.cancel')" @click="askReason('redemption', scan.result.voucher.redemption, 'Void voucher payment')" class="mt-2 self-start rounded bg-red-600 px-3 py-2 text-xs font-bold text-white">Void mistaken payment</button></div>
                <button v-if="scan.result.result === 'active' && hasPermission('stamps.cancel')" @click="askReason('voucher', scan.result.voucher, 'Void unused voucher')" class="mt-2 text-xs font-bold text-red-700 underline">Void this unused voucher</button>
            </div>
            <div v-if="scan.result?.result === 'active'" class="mt-5 space-y-4 border-t pt-5"><div class="flex gap-4 text-sm"><label><input v-model="newCustomer" :value="false" type="radio"/> Existing customer</label><label><input v-model="newCustomer" :value="true" type="radio"/> New customer</label></div>
                <Autocomplete v-if="!newCustomer" v-model="redemption.customer_id" :options="customerOptions" placeholder="Select customer"/>
                <div v-else class="grid gap-3 sm:grid-cols-3"><input v-model="redemption.new_customer_name" class="rounded-lg border-gray-300 dark:bg-gray-900" placeholder="Customer name *"/><input v-model="redemption.new_customer_phone" class="rounded-lg border-gray-300 dark:bg-gray-900" placeholder="Mobile number *"/><input v-model="redemption.new_customer_email" type="email" class="rounded-lg border-gray-300 dark:bg-gray-900" placeholder="Email (optional)"/></div>
                <div class="grid gap-3 sm:grid-cols-3"><label class="text-sm">POS receipt<input v-model="redemption.receipt_number" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-gray-900"/></label><label class="text-sm">Sale date<input v-model="redemption.sale_date" type="date" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-gray-900"/></label><label class="text-sm">Gross sale total<input v-model.number="redemption.gross_sale_total" type="number" min="0.01" step="0.01" class="mt-1 w-full rounded-lg border-gray-300 dark:bg-gray-900"/></label></div>
                <button @click="applyPayment" :disabled="redeeming" class="w-full rounded-lg bg-emerald-700 py-3 font-black text-white disabled:opacity-50">{{ redeeming ? 'Applying…' : `Apply as Payment and Mark Used` }}</button>
            </div>
        </div></Modal>

        <Modal :show="reasonModal.open" @close="reasonModal.open=false" max-width="md"><div class="p-6"><h3 class="text-lg font-bold dark:text-white">{{ reasonModal.title }}</h3><p class="mt-1 text-sm text-gray-500">This action is audited. Enter the reason to continue.</p><textarea v-model="reasonModal.reason" rows="4" class="mt-4 w-full rounded-lg border-gray-300 dark:bg-gray-900"></textarea><div class="mt-4 flex justify-end gap-2"><button @click="reasonModal.open=false" class="px-4 py-2 text-sm">Cancel</button><button @click="submitReason" :disabled="reasonModal.processing || !reasonModal.reason.trim()" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-bold text-white disabled:opacity-50">Confirm</button></div></div></Modal>
    </div>
</template>
