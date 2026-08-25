<script setup>
import { computed, nextTick, reactive, ref, watch } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import axios from 'axios'
import AppLayout from '@/Layouts/AppLayout.vue'
import DataTable from '@/Components/DataTable.vue'
import Modal from '@/Components/Modal.vue'
import Autocomplete from '@/Components/Autocomplete.vue'
import { QrCodeIcon } from '@heroicons/vue/24/outline'
import { useConfirm } from '@/Composables/useConfirm.js'
import { useToast } from '@/Composables/useToast.js'
import { usePermission } from '@/Composables/usePermission.js'

const props = defineProps({
    tab: { type: String, default: 'dashboard' },
    customers: { type: Array, default: () => [] },
    programs: { type: Array, default: () => [] },
    cards: { type: Array, default: () => [] },
    redemptions: { type: Array, default: () => [] },
    stores: { type: Array, default: () => [] },
    companies: { type: Array, default: () => [] },
    summary: { type: Object, default: () => ({}) },
})

const { confirm } = useConfirm()
const { addToast } = useToast()
const { hasPermission } = usePermission()

const currentTab = ref(props.tab && props.tab !== 'dashboard' ? props.tab : 'cards')
const tabList = [
    { id: 'cards', label: 'Cards' },
    { id: 'redemptions', label: 'Redemptions' },
    { id: 'customers', label: 'Customers' },
    { id: 'programs', label: 'Programs' },
]

/* ------------------------------------------------------------------ *
 | Lightweight client-side table (search + pagination) for DataTable
 * ------------------------------------------------------------------ */
const getField = (row, path) => path.split('.').reduce((o, k) => (o == null ? o : o[k]), row)

function useClientTable(rowsGetter, searchFields) {
    const t = reactive({ search: '', perPage: 10, currentPage: 1 })
    const filtered = computed(() => {
        const q = t.search.trim().toLowerCase()
        let rows = rowsGetter() || []
        if (q) {
            rows = rows.filter(r => searchFields.some(f => String(getField(r, f) ?? '').toLowerCase().includes(q)))
        }
        return rows
    })
    t.total = computed(() => filtered.value.length)
    t.lastPage = computed(() => Math.max(1, Math.ceil(filtered.value.length / t.perPage)))
    t.data = computed(() => {
        const start = (t.currentPage - 1) * t.perPage
        return filtered.value.slice(start, start + t.perPage)
    })
    t.showingText = computed(() => {
        const total = filtered.value.length
        if (total === 0) return 'No records found'
        const from = (t.currentPage - 1) * t.perPage + 1
        const to = Math.min(t.currentPage * t.perPage, total)
        return `Showing ${from} to ${to} of ${total} records`
    })
    t.goToPage = (p) => { if (p >= 1 && p <= t.lastPage) t.currentPage = p }
    t.changePerPage = (n) => { t.perPage = n; t.currentPage = 1 }
    watch(() => t.search, () => { t.currentPage = 1 })
    return t
}

const custTable = useClientTable(() => props.customers, ['name', 'email', 'phone'])
const progTable = useClientTable(() => props.programs, ['name', 'description'])
const cardTable = useClientTable(() => props.cards, ['customer.name', 'program.name', 'status'])
const redeemTable = useClientTable(() => props.redemptions, ['customer.name', 'program.name', 'asset.item_code', 'location'])

/* ------------------------------------------------------------------ *
 | Dropdown option sources
 * ------------------------------------------------------------------ */
const customerOptions = computed(() =>
    props.customers.map(c => ({ label: c.email ? `${c.name} (${c.email})` : c.name, value: c.id })))
const programOptions = computed(() =>
    props.programs.filter(p => p.is_active).map(p => ({ label: `${p.name} — ${p.stamps_required} stamps`, value: p.id })))
const storeOptions = computed(() =>
    props.stores.map(s => ({ label: s.name ? `${s.code} — ${s.name}` : s.code, value: s.id })))
const storeLocationOptions = computed(() =>
    props.stores.map(s => ({ label: s.name ? `${s.code} — ${s.name}` : s.code, value: s.code })))
const companyOptions = computed(() =>
    props.companies.map(c => ({ label: c.name, value: c.id })))

/* ------------------------------------------------------------------ *
 | Formatting helpers
 * ------------------------------------------------------------------ */
const formatAmount = (v) => Number(v || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const formatDate = (v) => v ? new Date(v).toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: 'numeric' }) : '—'
const formatDateTime = (v) => v ? new Date(v).toLocaleString('en-PH', { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—'
const statusClass = (s) => ({
    active: 'bg-blue-100 text-blue-700',
    completed: 'bg-amber-100 text-amber-700',
    redeemed: 'bg-green-100 text-green-700',
}[s] || 'bg-gray-100 text-gray-700')
const assetLabel = (a) => a ? [a.item_code, a.brand, a.model].filter(Boolean).join(' ') : '—'

/* ------------------------------------------------------------------ *
 | Customer modal
 * ------------------------------------------------------------------ */
const customerModal = ref(false)
const customerForm = useForm({ id: null, name: '', email: '', phone: '', is_active: true })
const customerInlineCtx = ref(false) // true = opened from card modal to create
const customerPrevIds = ref(new Set())
const openCustomerModal = (c = null, fromCard = false) => {
    customerForm.clearErrors()
    customerInlineCtx.value = fromCard && !c
    if (fromCard && !c) customerPrevIds.value = new Set(props.customers.map(x => x.id))
    if (c) {
        customerForm.id = c.id; customerForm.name = c.name; customerForm.email = c.email
        customerForm.phone = c.phone; customerForm.is_active = !!c.is_active
    } else {
        customerForm.reset()
    }
    customerModal.value = true
}
const submitCustomer = () => {
    const isCreate = !customerForm.id
    const ctx = customerInlineCtx.value
    const prevIds = new Set(customerPrevIds.value)
    const opts = {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            customerModal.value = false
            customerForm.reset()
            customerInlineCtx.value = false
            if (ctx && isCreate) {
                const newC = props.customers.find(c => !prevIds.has(c.id))
                if (newC) cardForm.customer_id = newC.id
            }
        },
    }
    customerForm.id
        ? customerForm.put(route('stamps.customers.update', customerForm.id), opts)
        : customerForm.post(route('stamps.customers.store'), opts)
}
const deleteCustomer = async (c) => {
    if (!await confirm({ title: 'Delete customer', message: `Delete ${c.name}? This cannot be undone.`, confirmLabel: 'Delete' })) return
    router.delete(route('stamps.customers.destroy', c.id), { preserveScroll: true, preserveState: true })
}
const deleteCustomerInline = async () => {
    const c = props.customers.find(x => x.id === cardForm.customer_id)
    if (!c) return
    if (!await confirm({ title: 'Delete customer', message: `Delete ${c.name}? This cannot be undone.`, confirmLabel: 'Delete' })) return
    router.delete(route('stamps.customers.destroy', c.id), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => { cardForm.customer_id = null },
    })
}

/* ------------------------------------------------------------------ *
 | Program modal
 * ------------------------------------------------------------------ */
const programModal = ref(false)
const programForm = useForm({ id: null, name: '', year: new Date().getFullYear(), description: '', company_id: null, stamps_required: 12, auto_stamp_amount: null, is_active: true })
const programInlineCtx = ref(false)
const programPrevIds = ref(new Set())
const openProgramModal = (p = null, fromCard = false) => {
    programForm.clearErrors()
    programInlineCtx.value = fromCard && !p
    if (fromCard && !p) programPrevIds.value = new Set(props.programs.map(x => x.id))
    if (p) {
        programForm.id = p.id; programForm.name = p.name; programForm.year = p.year ?? new Date().getFullYear()
        programForm.description = p.description
        programForm.company_id = p.company_id ?? null
        programForm.stamps_required = p.stamps_required; programForm.auto_stamp_amount = p.auto_stamp_amount
        programForm.is_active = !!p.is_active
    } else {
        programForm.reset()
    }
    programModal.value = true
}
const submitProgram = () => {
    const isCreate = !programForm.id
    const ctx = programInlineCtx.value
    const prevIds = new Set(programPrevIds.value)
    const opts = {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            programModal.value = false
            programForm.reset()
            programInlineCtx.value = false
            if (ctx && isCreate) {
                const newP = props.programs.find(p => !prevIds.has(p.id))
                if (newP) cardForm.stamp_program_id = newP.id
            }
        },
    }
    programForm.id
        ? programForm.put(route('stamps.programs.update', programForm.id), opts)
        : programForm.post(route('stamps.programs.store'), opts)
}
const deleteProgram = async (p) => {
    if (!await confirm({ title: 'Delete program', message: `Delete ${p.name}?`, confirmLabel: 'Delete' })) return
    router.delete(route('stamps.programs.destroy', p.id), { preserveScroll: true, preserveState: true })
}
const deleteProgramInline = async () => {
    const p = props.programs.find(x => x.id === cardForm.stamp_program_id)
    if (!p) return
    if (!await confirm({ title: 'Delete program', message: `Delete ${p.name}?`, confirmLabel: 'Delete' })) return
    router.delete(route('stamps.programs.destroy', p.id), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => { cardForm.stamp_program_id = null },
    })
}

/* ------------------------------------------------------------------ *
 | Card create modal
 * ------------------------------------------------------------------ */
const cardModal = ref(false)
const cardForm = useForm({ customer_id: null, stamp_program_id: null, store_id: null })
const openCardModal = () => { cardForm.clearErrors(); cardForm.reset(); cardModal.value = true }
const submitCard = () => {
    cardForm.post(route('stamps.cards.store'), { preserveScroll: true, preserveState: true, onSuccess: () => { cardModal.value = false; cardForm.reset() } })
}
const deleteCard = async (card) => {
    if (!await confirm({ title: 'Delete card', message: `Delete this card for ${card.customer?.name}?`, confirmLabel: 'Delete' })) return
    router.delete(route('stamps.cards.destroy', card.id), { preserveScroll: true, preserveState: true })
}

/* ------------------------------------------------------------------ *
 | Scan Customer modal — member QR in, staff only pick Program (Store is
 | remembered per device, not re-asked every scan). Two steps against the
 | barcode-scanner-friendly token input:
 |   1) resolve the scanned code to a customer (stamps.scan.resolve)
 |   2) pick a Program and add the stamp (stamps.scan.add-stamp), which
 |      reuses or auto-creates the customer's card for that program.
 * ------------------------------------------------------------------ */
const scanModal = reactive({ open: false, step: 'scan', resolving: false, submitting: false, customer: null, cards: [], error: null })
const scanTokenInput = ref('')
const scanTokenInputRef = ref(null)
const scanPurchaseAmountInputRef = ref(null)
// Per-transaction, unlike scanStoreId/scanProgramId below — reset on every
// scan rather than remembered, since the sale amount is never the same twice.
const scanPurchaseAmount = ref(null)
const scanNote = ref('')

// Small helper: a ref backed by localStorage under `key`, read once on load
// and written back on every change. Used for the two fields staff pick once
// per shift/device rather than re-choosing on every single scan — "My Store"
// (which a QR can never carry, since it's generated once on the customer's
// phone before any store is involved) and, per feedback, the Program too
// (staff typically run one campaign at a time at the counter). Per-browser
// only, deliberately not synced server-side.
const rememberedRef = (key, parse = Number) => {
    const r = ref((() => {
        try {
            const saved = localStorage.getItem(key)
            return saved ? parse(saved) : null
        } catch { return null }
    })())
    watch(r, (val) => {
        try {
            if (val) localStorage.setItem(key, String(val))
            else localStorage.removeItem(key)
        } catch { /* private browsing etc. — remembering is a convenience, not required */ }
    })
    return r
}
const scanStoreId = rememberedRef('stamps:currentStoreId')
const scanProgramId = rememberedRef('stamps:currentProgramId')

const openScanModal = () => {
    scanModal.open = true
    scanModal.step = 'scan'
    scanModal.resolving = false
    scanModal.submitting = false
    scanModal.customer = null
    scanModal.cards = []
    scanModal.error = null
    scanTokenInput.value = ''
    scanPurchaseAmount.value = null
    scanNote.value = ''
    // scanStoreId / scanProgramId are deliberately NOT reset — that's the
    // "remembered per shift" behavior staff asked for.
    // A hardware barcode scanner types into whatever input has focus, so the
    // scan step needs the token field focused the instant the modal opens —
    // no click required before scanning.
    nextTick(() => scanTokenInputRef.value?.focus())
}
const closeScanModal = () => { scanModal.open = false }

const submitScanToken = async () => {
    const token = scanTokenInput.value.trim()
    if (!token || scanModal.resolving) return
    scanModal.resolving = true
    scanModal.error = null
    try {
        const res = await axios.post(route('stamps.scan.resolve'), { token })
        scanModal.customer = res.data.customer
        scanModal.cards = res.data.cards || []
        scanModal.step = 'assign'
        // Program and Store are usually already remembered from a prior
        // scan (see rememberedRef), so Purchase amount is the field staff
        // actually need to fill in next — send focus straight there instead
        // of leaving it on the now-hidden token input.
        nextTick(() => scanPurchaseAmountInputRef.value?.focus())
    } catch (e) {
        scanModal.error = e.response?.data?.errors?.token?.[0] || 'That code could not be read. Try scanning again.'
        scanTokenInput.value = ''
        nextTick(() => scanTokenInputRef.value?.focus())
    } finally {
        scanModal.resolving = false
    }
}

// Auto-submit the instant a scan looks complete, instead of requiring staff
// to press Enter or click "Look Up" — a barcode scanner types fast but not
// all of them send a terminating Enter/Tab keystroke, and this covers that
// case (plus paste) too. Debounced briefly so a human manually typing the
// code doesn't fire a request on every partial keystroke; a real scanner's
// burst-typed input clears the debounce almost immediately either way.
// Matches LoyaltyQrService::encode's exact shape: "LCARD1:{customer_id}:{24 hex chars}".
const SCAN_TOKEN_PATTERN = /^LCARD1:\d+:[0-9a-f]{24}$/
let scanAutoSubmitTimer = null
watch(scanTokenInput, (val) => {
    if (scanAutoSubmitTimer) clearTimeout(scanAutoSubmitTimer)
    const trimmed = val.trim()
    if (scanModal.step !== 'scan' || scanModal.resolving || !SCAN_TOKEN_PATTERN.test(trimmed)) return
    scanAutoSubmitTimer = setTimeout(() => {
        if (scanTokenInput.value.trim() === trimmed) submitScanToken()
    }, 150)
})

const scanCardForSelectedProgram = computed(() =>
    scanModal.cards.find(c => c.stamp_program_id === scanProgramId.value) || null)

const submitScanAddStamp = async () => {
    if (!scanProgramId.value || !scanPurchaseAmount.value || scanModal.submitting) return
    scanModal.submitting = true
    scanModal.error = null
    try {
        await axios.post(route('stamps.scan.add-stamp'), {
            token: scanTokenInput.value.trim(),
            stamp_program_id: scanProgramId.value,
            store_id: scanStoreId.value,
            purchase_amount: scanPurchaseAmount.value,
            note: scanNote.value || null,
        })
        addToast(`Stamp added for ${scanModal.customer?.name}.`, 'success')
        closeScanModal()
        router.reload({ only: ['cards', 'summary'] })
    } catch (e) {
        scanModal.error = e.response?.data?.errors?.stamp_program_id?.[0]
            || e.response?.data?.errors?.token?.[0]
            || e.response?.data?.errors?.quantity?.[0]
            || e.response?.data?.errors?.store_id?.[0]
            || e.response?.data?.errors?.purchase_amount?.[0]
            || e.response?.data?.errors?.note?.[0]
            || 'Could not add a stamp. Try again.'
    } finally {
        scanModal.submitting = false
    }
}

const rescan = () => {
    scanModal.step = 'scan'
    scanModal.customer = null
    scanModal.cards = []
    // scanProgramId is deliberately kept — staff scanning several members in
    // a row for the same running campaign shouldn't have to re-pick it each time.
    scanTokenInput.value = ''
    scanPurchaseAmount.value = null
    scanNote.value = ''
    nextTick(() => scanTokenInputRef.value?.focus())
}

/* ------------------------------------------------------------------ *
 | Add-stamps modal
 * ------------------------------------------------------------------ */
const stampModal = reactive({ open: false, card: null })
const stampForm = useForm({ quantity: 1, purchase_amount: null, store_id: null, note: '' })

/* ------------------------------------------------------------------ *
 | Stamp entry history modal
 * ------------------------------------------------------------------ */
const entriesModal = reactive({ open: false, card: null, entries: [], loading: false })
const openEntriesModal = async (card) => {
    entriesModal.card = card
    entriesModal.entries = []
    entriesModal.loading = true
    entriesModal.open = true
    try {
        const res = await fetch(route('stamps.cards.entries', card.id), { headers: { Accept: 'application/json' } })
        const json = await res.json()
        entriesModal.entries = json.entries || []
    } catch {
        addToast('Failed to load stamp history.', 'error')
    } finally {
        entriesModal.loading = false
    }
}
const stampRemaining = computed(() => {
    const card = stampModal.card
    if (!card?.program) return 0
    return Math.max(0, (card.program.stamps_required ?? 0) - (card.stamps_count ?? 0))
})
const openStampModal = (card) => {
    stampForm.clearErrors()
    stampForm.reset()
    stampModal.card = card
    stampModal.open = true
    stampForm.quantity = 1
    stampForm.purchase_amount = null
    stampForm.store_id = card.store_id ?? null
}
const submitStamp = () => {
    stampForm.post(route('stamps.cards.add-stamps', stampModal.card.id), { preserveScroll: true, preserveState: true, onSuccess: () => { stampModal.open = false; stampForm.reset() } })
}

/* ------------------------------------------------------------------ *
 | Record-purchase modal
 * ------------------------------------------------------------------ */
const purchaseModal = reactive({ open: false, card: null })
const purchaseForm = useForm({ purchase_amount: null, note: '' })
const openPurchaseModal = (card) => { purchaseForm.clearErrors(); purchaseForm.reset(); purchaseModal.card = card; purchaseModal.open = true }
const purchaseProgram = computed(() => purchaseModal.card?.program || null)
const submitPurchase = () => {
    purchaseForm.post(route('stamps.cards.record-purchase', purchaseModal.card.id), { preserveScroll: true, preserveState: true, onSuccess: () => { purchaseModal.open = false; purchaseForm.reset() } })
}

/* ------------------------------------------------------------------ *
 | Redeem modal (loads consumable assets with stock at the chosen store)
 * ------------------------------------------------------------------ */
const redeemModal = reactive({ open: false, card: null })
const redeemForm = useForm({ location: null, asset_id: null, quantity: 1, remarks: '' })
const assetOptions = ref([])
const loadingAssets = ref(false)
const openRedeemModal = (card) => {
    redeemForm.clearErrors(); redeemForm.reset()
    assetOptions.value = []
    redeemModal.card = card; redeemModal.open = true
}
const loadAssets = async (location) => {
    redeemForm.asset_id = null
    assetOptions.value = []
    if (!location) return
    loadingAssets.value = true
    try {
        const res = await fetch(route('stamps.assets-at-location', { location }), { headers: { Accept: 'application/json' } })
        const json = await res.json()
        assetOptions.value = (json || []).map(a => ({ label: `${assetLabel(a)} — SOH ${a.soh}`, value: a.id }))
        if (!assetOptions.value.length) addToast('No consumable stock available at this location.', 'error')
    } catch (e) {
        addToast('Failed to load available items.', 'error')
    } finally {
        loadingAssets.value = false
    }
}
watch(() => redeemForm.location, (loc) => loadAssets(loc))
const submitRedeem = () => {
    redeemForm.post(route('stamps.cards.redeem', redeemModal.card.id), { preserveScroll: true, preserveState: true, onSuccess: () => { redeemModal.open = false; redeemForm.reset() } })
}
</script>

<template>
    <AppLayout title="Loyalty Stamps" content-class="w-full max-w-none px-2 sm:px-4 lg:px-6 min-w-fit" main-class="overflow-auto">
        <div class="py-8">
            <div class="space-y-6 min-w-fit">
                <!-- Header -->
                <div>
                    <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">Loyalty Stamps</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-300">Manage customer stamp cards, programs, and reward redemptions.</p>
                </div>

                <!-- Summary stat cards (always visible) -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5 gap-4">
                    <div class="bg-white rounded-xl border border-gray-100 p-5 shadow-sm dark:bg-gray-800 dark:border-gray-700">
                        <p class="text-xs font-bold text-gray-500 uppercase tracking-wider dark:text-gray-300">Customers</p>
                        <p class="text-2xl font-bold text-gray-900 mt-2 dark:text-gray-100">{{ summary.customers ?? 0 }}</p>
                    </div>
                    <div class="bg-white rounded-xl border border-gray-100 p-5 shadow-sm dark:bg-gray-800 dark:border-gray-700">
                        <p class="text-xs font-bold text-gray-500 uppercase tracking-wider dark:text-gray-300">Active Cards</p>
                        <p class="text-2xl font-bold text-blue-600 mt-2">{{ summary.active_cards ?? 0 }}</p>
                    </div>
                    <div class="bg-white rounded-xl border border-gray-100 p-5 shadow-sm dark:bg-gray-800 dark:border-gray-700">
                        <p class="text-xs font-bold text-gray-500 uppercase tracking-wider dark:text-gray-300">Completed (to redeem)</p>
                        <p class="text-2xl font-bold text-amber-600 mt-2">{{ summary.completed_cards ?? 0 }}</p>
                    </div>
                    <div class="bg-white rounded-xl border border-gray-100 p-5 shadow-sm dark:bg-gray-800 dark:border-gray-700">
                        <p class="text-xs font-bold text-gray-500 uppercase tracking-wider dark:text-gray-300">Redeemed</p>
                        <p class="text-2xl font-bold text-green-600 mt-2">{{ summary.redeemed_cards ?? 0 }}</p>
                    </div>
                    <div class="bg-white rounded-xl border border-gray-100 p-5 shadow-sm dark:bg-gray-800 dark:border-gray-700">
                        <p class="text-xs font-bold text-gray-500 uppercase tracking-wider dark:text-gray-300">Total Amount</p>
                        <p class="text-2xl font-bold text-indigo-600 mt-2">₱{{ formatAmount(summary.total_amount) }}</p>
                    </div>
                </div>

                <!-- Tabs -->
                <div class="border-b border-gray-200 dark:border-gray-700">
                    <nav class="-mb-px flex space-x-6 overflow-x-auto">
                        <button
                            v-for="t in tabList"
                            :key="t.id"
                            @click="currentTab = t.id"
                            :class="[
                                'whitespace-nowrap py-3 px-2 border-b-2 text-sm font-medium transition-colors',
                                currentTab === t.id
                                    ? 'border-blue-600 text-blue-600'
                                    : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
                            ]"
                        >
                            {{ t.label }}
                        </button>
                    </nav>
                </div>

                <!-- CUSTOMERS -->
                <DataTable
                    v-if="currentTab === 'customers'"
                    title="Customers"
                    search-placeholder="Search customers..."
                    :data="custTable.data"
                    :search="custTable.search"
                    :current-page="custTable.currentPage"
                    :last-page="custTable.lastPage"
                    :per-page="custTable.perPage"
                    :showing-text="custTable.showingText"
                    @update:search="custTable.search = $event"
                    @goToPage="custTable.goToPage"
                    @changePerPage="custTable.changePerPage"
                >
                    <template #actions>
                        <button @click="openCustomerModal()" class="bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium px-4 py-2 rounded-lg whitespace-nowrap inline-flex items-center">+ New Customer</button>
                    </template>
                    <template #header>
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase dark:text-slate-300">Name</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase dark:text-slate-300">Email</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase dark:text-slate-300">Phone</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase dark:text-slate-300">Status</th>
                            <th class="px-6 py-3 text-right text-xs font-semibold text-gray-500 uppercase dark:text-slate-300">Actions</th>
                        </tr>
                    </template>
                    <template #body="{ data }">
                        <tr v-for="c in data" :key="c.id" class="hover:bg-gray-50 dark:hover:bg-gray-700">
                            <td class="px-6 py-3 text-sm font-medium text-gray-900 dark:text-gray-100">{{ c.name }}</td>
                            <td class="px-6 py-3 text-sm text-gray-600 dark:text-gray-300">{{ c.email || '—' }}</td>
                            <td class="px-6 py-3 text-sm text-gray-600 dark:text-gray-300">{{ c.phone || '—' }}</td>
                            <td class="px-6 py-3 text-sm">
                                <span :class="['px-2 py-0.5 rounded-full text-xs font-medium', c.is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600']">{{ c.is_active ? 'Active' : 'Inactive' }}</span>
                            </td>
                            <td class="px-6 py-3 text-sm">
                                <div class="flex justify-end space-x-1">
                                    <button @click="openCustomerModal(c)" title="Edit Customer" class="p-2 text-blue-600 hover:text-blue-900 hover:bg-blue-50 rounded-full transition-colors">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
                                    </button>
                                    <button @click="deleteCustomer(c)" title="Delete Customer" class="p-2 text-red-600 hover:text-red-900 hover:bg-red-50 rounded-full transition-colors">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </template>
                </DataTable>

                <!-- PROGRAMS -->
                <DataTable
                    v-if="currentTab === 'programs'"
                    title="Stamp Programs"
                    search-placeholder="Search programs..."
                    :data="progTable.data"
                    :search="progTable.search"
                    :current-page="progTable.currentPage"
                    :last-page="progTable.lastPage"
                    :per-page="progTable.perPage"
                    :showing-text="progTable.showingText"
                    @update:search="progTable.search = $event"
                    @goToPage="progTable.goToPage"
                    @changePerPage="progTable.changePerPage"
                >
                    <template #actions>
                        <button @click="openProgramModal()" class="bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium px-4 py-2 rounded-lg whitespace-nowrap inline-flex items-center">+ New Program</button>
                    </template>
                    <template #header>
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase dark:text-slate-300">Name</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase dark:text-slate-300">Company</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase dark:text-slate-300">Year</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase dark:text-slate-300">Stamps Required</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase dark:text-slate-300">Auto Rule</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase dark:text-slate-300">Status</th>
                            <th class="px-6 py-3 text-right text-xs font-semibold text-gray-500 uppercase dark:text-slate-300">Actions</th>
                        </tr>
                    </template>
                    <template #body="{ data }">
                        <tr v-for="p in data" :key="p.id" class="hover:bg-gray-50 dark:hover:bg-gray-700">
                            <td class="px-6 py-3 text-sm font-medium text-gray-900 dark:text-gray-100">
                                {{ p.name }}
                                <p v-if="p.description" class="text-xs text-gray-500 font-normal dark:text-gray-300">{{ p.description }}</p>
                            </td>
                            <td class="px-6 py-3 text-sm text-gray-700 dark:text-gray-300">
                                <span v-if="p.company" class="px-2 py-0.5 rounded-full text-xs font-medium bg-blue-50 text-blue-700 dark:bg-blue-500/10 dark:text-blue-300">{{ p.company.code }}</span>
                                <span v-else class="text-gray-400 dark:text-gray-500 text-xs italic">Shared</span>
                            </td>
                            <td class="px-6 py-3 text-sm text-gray-700 dark:text-gray-300">{{ p.year }}</td>
                            <td class="px-6 py-3 text-sm text-gray-700 dark:text-gray-300">{{ p.stamps_required }}</td>
                            <td class="px-6 py-3 text-sm text-gray-600 dark:text-gray-300">
                                <span v-if="p.auto_stamp_amount">1 stamp / ₱{{ formatAmount(p.auto_stamp_amount) }}</span>
                                <span v-else class="text-gray-400 dark:text-gray-400">Manual only</span>
                            </td>
                            <td class="px-6 py-3 text-sm">
                                <span :class="['px-2 py-0.5 rounded-full text-xs font-medium', p.is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600']">{{ p.is_active ? 'Active' : 'Inactive' }}</span>
                            </td>
                            <td class="px-6 py-3 text-sm">
                                <div class="flex justify-end space-x-1">
                                    <button @click="openProgramModal(p)" title="Edit Program" class="p-2 text-blue-600 hover:text-blue-900 hover:bg-blue-50 rounded-full transition-colors">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
                                    </button>
                                    <button @click="deleteProgram(p)" title="Delete Program" class="p-2 text-red-600 hover:text-red-900 hover:bg-red-50 rounded-full transition-colors">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </template>
                </DataTable>

                <!-- CARDS -->
                <DataTable
                    v-if="currentTab === 'cards'"
                    title="Stamp Cards"
                    search-placeholder="Search cards..."
                    :data="cardTable.data"
                    :search="cardTable.search"
                    :current-page="cardTable.currentPage"
                    :last-page="cardTable.lastPage"
                    :per-page="cardTable.perPage"
                    :showing-text="cardTable.showingText"
                    @update:search="cardTable.search = $event"
                    @goToPage="cardTable.goToPage"
                    @changePerPage="cardTable.changePerPage"
                >
                    <template #actions>
                        <div class="flex items-center gap-2">
                            <button @click="openScanModal()" title="Scan a member's QR code to add a stamp — no manual customer/store lookup" class="bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-medium px-4 py-2 rounded-lg whitespace-nowrap inline-flex items-center gap-1.5">
                                <QrCodeIcon class="w-4 h-4" />
                                Scan Customer
                            </button>
                            <button @click="openCardModal()" class="bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium px-4 py-2 rounded-lg whitespace-nowrap inline-flex items-center">+ New Card</button>
                        </div>
                    </template>
                    <template #header>
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase dark:text-slate-300">Customer</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase dark:text-slate-300">Program</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase dark:text-slate-300">Store</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase dark:text-slate-300">Issued</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase dark:text-slate-300">Progress</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase dark:text-slate-300">Status</th>
                            <th class="px-6 py-3 text-right text-xs font-semibold text-gray-500 uppercase dark:text-slate-300">Actions</th>
                        </tr>
                    </template>
                    <template #body="{ data }">
                        <tr v-for="card in data" :key="card.id" class="hover:bg-gray-50 dark:hover:bg-gray-700">
                            <td class="px-6 py-3 text-sm font-medium text-gray-900 dark:text-gray-100">{{ card.customer?.name || '—' }}</td>
                            <td class="px-6 py-3 text-sm text-gray-700 dark:text-gray-300">{{ card.program?.name || '—' }}</td>
                            <td class="px-6 py-3 text-sm text-gray-600 dark:text-gray-300">{{ card.store ? `${card.store.code}${card.store.name ? ' — ' + card.store.name : ''}` : '—' }}</td>
                            <td class="px-6 py-3 text-sm text-gray-600 whitespace-nowrap dark:text-gray-300">{{ formatDateTime(card.created_at) }}</td>
                            <td class="px-6 py-3 text-sm text-gray-700 dark:text-gray-300">
                                <button @click="openEntriesModal(card)" class="flex items-center gap-2 group" title="View stamp history">
                                    <div class="w-28 bg-gray-100 rounded-full h-2 overflow-hidden dark:bg-gray-800">
                                        <div class="bg-blue-500 h-2 group-hover:bg-blue-600 transition-colors" :style="{ width: ((card.stamps_count / (card.program?.stamps_required || 1)) * 100) + '%' }"></div>
                                    </div>
                                    <span class="text-xs text-gray-600 whitespace-nowrap group-hover:text-blue-600 transition-colors dark:text-gray-300">{{ card.stamps_count }} / {{ card.program?.stamps_required }}</span>
                                </button>
                            </td>
                            <td class="px-6 py-3 text-sm">
                                <span :class="['px-2 py-0.5 rounded-full text-xs font-medium capitalize', statusClass(card.status)]">{{ card.status }}</span>
                            </td>
                            <td class="px-6 py-3 text-sm">
                                <div class="flex justify-end space-x-1">
                                    <button v-if="card.status === 'active'" @click="openStampModal(card)" title="Add Stamp" class="p-2 text-green-600 hover:text-green-900 hover:bg-green-50 rounded-full transition-colors">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" /></svg>
                                    </button>
                                    <button v-if="card.status === 'active' && card.program?.auto_stamp_amount" @click="openPurchaseModal(card)" title="Record Purchase" class="p-2 text-indigo-600 hover:text-indigo-900 hover:bg-indigo-50 rounded-full transition-colors">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z" /></svg>
                                    </button>
                                    <button v-if="card.status === 'completed'" @click="openRedeemModal(card)" title="Redeem Reward" class="p-2 text-amber-600 hover:text-amber-900 hover:bg-amber-50 rounded-full transition-colors">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 11.25v8.25a1.5 1.5 0 01-1.5 1.5H5.25a1.5 1.5 0 01-1.5-1.5v-8.25M12 4.875A2.625 2.625 0 109.375 7.5H12m0-2.625V7.5m0-2.625A2.625 2.625 0 1114.625 7.5H12m0 0V21m-9-9.75h18" /></svg>
                                    </button>
                                    <button v-if="card.status !== 'redeemed'" @click="deleteCard(card)" title="Delete Card" class="p-2 text-red-600 hover:text-red-900 hover:bg-red-50 rounded-full transition-colors">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </template>
                </DataTable>

                <!-- REDEMPTIONS -->
                <DataTable
                    v-if="currentTab === 'redemptions'"
                    title="Redemptions"
                    search-placeholder="Search redemptions..."
                    :data="redeemTable.data"
                    :search="redeemTable.search"
                    :current-page="redeemTable.currentPage"
                    :last-page="redeemTable.lastPage"
                    :per-page="redeemTable.perPage"
                    :showing-text="redeemTable.showingText"
                    @update:search="redeemTable.search = $event"
                    @goToPage="redeemTable.goToPage"
                    @changePerPage="redeemTable.changePerPage"
                >
                    <template #header>
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase dark:text-slate-300">Date</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase dark:text-slate-300">Customer</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase dark:text-slate-300">Program</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase dark:text-slate-300">Reward Item</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase dark:text-slate-300">Location</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase dark:text-slate-300">Qty</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase dark:text-slate-300">Total Purchase</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase dark:text-slate-300">By</th>
                        </tr>
                    </template>
                    <template #body="{ data }">
                        <tr v-for="r in data" :key="r.id" class="hover:bg-gray-50 dark:hover:bg-gray-700">
                            <td class="px-6 py-3 text-sm text-gray-600 dark:text-gray-300">{{ formatDateTime(r.created_at) }}</td>
                            <td class="px-6 py-3 text-sm font-medium text-gray-900 dark:text-gray-100">{{ r.customer?.name || '—' }}</td>
                            <td class="px-6 py-3 text-sm text-gray-700 dark:text-gray-300">{{ r.program?.name || '—' }}</td>
                            <td class="px-6 py-3 text-sm text-gray-700 dark:text-gray-300">{{ assetLabel(r.asset) }}</td>
                            <td class="px-6 py-3 text-sm text-gray-600 dark:text-gray-300">{{ r.location }}</td>
                            <td class="px-6 py-3 text-sm text-gray-700 dark:text-gray-300">{{ r.quantity }}</td>
                            <td class="px-6 py-3 text-sm font-medium text-gray-700 whitespace-nowrap dark:text-gray-300">₱{{ formatAmount(r.total_purchase_amount) }}</td>
                            <td class="px-6 py-3 text-sm text-gray-600 dark:text-gray-300">{{ r.creator?.name || '—' }}</td>
                        </tr>
                    </template>
                </DataTable>
            </div>
        </div>

        <!-- Customer Modal -->
        <Modal :show="customerModal" @close="customerModal = false" max-width="lg">
            <div class="p-6 space-y-4">
                <h3 class="text-lg font-bold text-gray-900 dark:text-gray-100">{{ customerForm.id ? 'Edit Customer' : 'New Customer' }}</h3>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1 dark:text-gray-300">Name <span class="text-red-500">*</span></label>
                    <input v-model="customerForm.name" type="text" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 dark:border-gray-600" />
                    <p v-if="customerForm.errors.name" class="text-xs text-red-600 mt-1">{{ customerForm.errors.name }}</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1 dark:text-gray-300">Email</label>
                    <input v-model="customerForm.email" type="email" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 dark:border-gray-600" />
                    <p v-if="customerForm.errors.email" class="text-xs text-red-600 mt-1">{{ customerForm.errors.email }}</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1 dark:text-gray-300">Phone</label>
                    <input v-model="customerForm.phone" type="text" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 dark:border-gray-600" />
                </div>
                <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                    <input v-model="customerForm.is_active" type="checkbox" class="rounded border-gray-300 dark:border-gray-600" /> Active
                </label>
                <div class="flex justify-end gap-2 pt-2">
                    <button @click="customerModal = false" class="px-4 py-2 text-sm rounded-lg border border-gray-300 hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-700">Cancel</button>
                    <button @click="submitCustomer" :disabled="customerForm.processing" class="px-4 py-2 text-sm rounded-lg bg-blue-600 text-white hover:bg-blue-700 disabled:opacity-50">Save</button>
                </div>
            </div>
        </Modal>

        <!-- Program Modal -->
        <Modal :show="programModal" @close="programModal = false" max-width="lg">
            <div class="p-6 space-y-4">
                <h3 class="text-lg font-bold text-gray-900 dark:text-gray-100">{{ programForm.id ? 'Edit Program' : 'New Program' }}</h3>
                <div class="grid grid-cols-3 gap-3">
                    <div class="col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1 dark:text-gray-300">Name <span class="text-red-500">*</span></label>
                        <input v-model="programForm.name" type="text" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 dark:border-gray-600" />
                        <p v-if="programForm.errors.name" class="text-xs text-red-600 mt-1">{{ programForm.errors.name }}</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1 dark:text-gray-300">Year <span class="text-red-500">*</span></label>
                        <input v-model.number="programForm.year" type="number" min="2000" max="2100" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 dark:border-gray-600" />
                        <p v-if="programForm.errors.year" class="text-xs text-red-600 mt-1">{{ programForm.errors.year }}</p>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1 dark:text-gray-300">Description</label>
                    <textarea v-model="programForm.description" rows="2" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 dark:border-gray-600"></textarea>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1 dark:text-gray-300">Company</label>
                    <Autocomplete v-model="programForm.company_id" :options="companyOptions" placeholder="Leave blank = shared across every entity" />
                    <p v-if="programForm.errors.company_id" class="text-xs text-red-600 mt-1">{{ programForm.errors.company_id }}</p>
                    <p class="text-xs text-gray-400 mt-1">Which entity's app/portal this program shows on. The mobile loyalty app only ever shows CBTL's programs.</p>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1 dark:text-gray-300">Stamps Required <span class="text-red-500">*</span></label>
                        <input v-model.number="programForm.stamps_required" type="number" min="1" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 dark:border-gray-600" />
                        <p v-if="programForm.errors.stamps_required" class="text-xs text-red-600 mt-1">{{ programForm.errors.stamps_required }}</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1 dark:text-gray-300">₱ per stamp (auto)</label>
                        <input v-model.number="programForm.auto_stamp_amount" type="number" min="0" step="0.01" placeholder="Leave blank = manual only" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 dark:border-gray-600" />
                        <p v-if="programForm.errors.auto_stamp_amount" class="text-xs text-red-600 mt-1">{{ programForm.errors.auto_stamp_amount }}</p>
                    </div>
                </div>
                <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                    <input v-model="programForm.is_active" type="checkbox" class="rounded border-gray-300 dark:border-gray-600" /> Active
                </label>
                <div class="flex justify-end gap-2 pt-2">
                    <button @click="programModal = false" class="px-4 py-2 text-sm rounded-lg border border-gray-300 hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-700">Cancel</button>
                    <button @click="submitProgram" :disabled="programForm.processing" class="px-4 py-2 text-sm rounded-lg bg-blue-600 text-white hover:bg-blue-700 disabled:opacity-50">Save</button>
                </div>
            </div>
        </Modal>

        <!-- Card Modal -->
        <Modal :show="cardModal" @close="cardModal = false" max-width="lg">
            <div class="p-6 space-y-4">
                <h3 class="text-lg font-bold text-gray-900 dark:text-gray-100">New Stamp Card</h3>

                <!-- Customer field with inline management -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1 dark:text-gray-300">Customer <span class="text-red-500">*</span></label>
                    <div class="flex items-center gap-2">
                        <div class="flex-1 min-w-0">
                            <Autocomplete v-model="cardForm.customer_id" :options="customerOptions" placeholder="Search customer..." />
                        </div>
                        <div class="flex gap-1 flex-shrink-0">
                            <button v-if="hasPermission('stamps.create')"
                                    @click="openCustomerModal(null, true)"
                                    title="New Customer"
                                    class="p-1.5 text-green-600 hover:text-green-900 hover:bg-green-50 rounded-full transition-colors">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" /></svg>
                            </button>
                            <button v-if="hasPermission('stamps.edit') && cardForm.customer_id"
                                    @click="openCustomerModal(props.customers.find(c => c.id === cardForm.customer_id), true)"
                                    title="Edit Customer"
                                    class="p-1.5 text-blue-600 hover:text-blue-900 hover:bg-blue-50 rounded-full transition-colors">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
                            </button>
                            <button v-if="hasPermission('stamps.delete') && cardForm.customer_id"
                                    @click="deleteCustomerInline"
                                    title="Delete Customer"
                                    class="p-1.5 text-red-600 hover:text-red-900 hover:bg-red-50 rounded-full transition-colors">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                            </button>
                        </div>
                    </div>
                    <p v-if="cardForm.errors.customer_id" class="text-xs text-red-600 mt-1">{{ cardForm.errors.customer_id }}</p>
                </div>

                <!-- Program field with inline management -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1 dark:text-gray-300">Program <span class="text-red-500">*</span></label>
                    <div class="flex items-center gap-2">
                        <div class="flex-1 min-w-0">
                            <Autocomplete v-model="cardForm.stamp_program_id" :options="programOptions" placeholder="Search program..." />
                        </div>
                        <div class="flex gap-1 flex-shrink-0">
                            <button v-if="hasPermission('stamps.create')"
                                    @click="openProgramModal(null, true)"
                                    title="New Program"
                                    class="p-1.5 text-green-600 hover:text-green-900 hover:bg-green-50 rounded-full transition-colors">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" /></svg>
                            </button>
                            <button v-if="hasPermission('stamps.edit') && cardForm.stamp_program_id"
                                    @click="openProgramModal(props.programs.find(p => p.id === cardForm.stamp_program_id), true)"
                                    title="Edit Program"
                                    class="p-1.5 text-blue-600 hover:text-blue-900 hover:bg-blue-50 rounded-full transition-colors">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
                            </button>
                            <button v-if="hasPermission('stamps.delete') && cardForm.stamp_program_id"
                                    @click="deleteProgramInline"
                                    title="Delete Program"
                                    class="p-1.5 text-red-600 hover:text-red-900 hover:bg-red-50 rounded-full transition-colors">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                            </button>
                        </div>
                    </div>
                    <p v-if="cardForm.errors.stamp_program_id" class="text-xs text-red-600 mt-1">{{ cardForm.errors.stamp_program_id }}</p>
                </div>

                <!-- Store field -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1 dark:text-gray-300">Store</label>
                    <Autocomplete v-model="cardForm.store_id" :options="storeOptions" placeholder="Select store..." />
                    <p v-if="cardForm.errors.store_id" class="text-xs text-red-600 mt-1">{{ cardForm.errors.store_id }}</p>
                </div>

                <div class="flex justify-end gap-2 pt-2">
                    <button @click="cardModal = false" class="px-4 py-2 text-sm rounded-lg border border-gray-300 hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-700">Cancel</button>
                    <button @click="submitCard" :disabled="cardForm.processing" class="px-4 py-2 text-sm rounded-lg bg-blue-600 text-white hover:bg-blue-700 disabled:opacity-50">Create</button>
                </div>
            </div>
        </Modal>

        <!-- Scan Customer Modal — QR in, only Program picked manually -->
        <Modal :show="scanModal.open" @close="closeScanModal" max-width="md">
            <div class="p-6 space-y-4">
                <h3 class="text-lg font-bold text-gray-900 dark:text-gray-100 flex items-center gap-2">
                    <QrCodeIcon class="w-5 h-5 text-emerald-600" /> Scan Customer
                </h3>

                <!-- Step 1: scan -->
                <template v-if="scanModal.step === 'scan'">
                    <p class="text-sm text-gray-500 dark:text-gray-300">Point the barcode scanner at the customer's member QR code — no need to click first.</p>
                    <div>
                        <input
                            ref="scanTokenInputRef"
                            v-model="scanTokenInput"
                            type="text"
                            autocomplete="off"
                            placeholder="Waiting for scan…"
                            :disabled="scanModal.resolving"
                            @keydown.enter.prevent="submitScanToken"
                            class="w-full border border-gray-300 rounded-lg px-3 py-2.5 text-sm text-center tracking-wide focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 dark:border-gray-600"
                        />
                        <p v-if="scanModal.error" class="text-xs text-red-600 mt-1">{{ scanModal.error }}</p>
                    </div>
                    <p v-if="scanModal.resolving" class="text-xs text-gray-500 dark:text-gray-300">Looking up member…</p>
                    <div class="flex justify-end gap-2 pt-2">
                        <button @click="closeScanModal" class="px-4 py-2 text-sm rounded-lg border border-gray-300 hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-700">Cancel</button>
                        <button @click="submitScanToken" :disabled="!scanTokenInput.trim() || scanModal.resolving" class="px-4 py-2 text-sm rounded-lg bg-emerald-600 text-white hover:bg-emerald-700 disabled:opacity-50">Look Up</button>
                    </div>
                </template>

                <!-- Step 2: pick Program only (Store is remembered per shift below) -->
                <template v-else>
                    <div class="rounded-lg bg-emerald-50 border border-emerald-200 px-3 py-2 dark:bg-emerald-500/10 dark:border-emerald-500/30">
                        <p class="text-sm font-semibold text-emerald-800 dark:text-emerald-300">{{ scanModal.customer?.name }}</p>
                        <p class="text-xs text-emerald-700 dark:text-emerald-400">{{ scanModal.customer?.email || scanModal.customer?.phone || 'Member scanned' }}</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1 dark:text-gray-300">Program <span class="text-red-500">*</span></label>
                        <Autocomplete v-model="scanProgramId" :options="programOptions" placeholder="Search program..." />
                        <p v-if="scanCardForSelectedProgram" class="text-xs text-gray-500 mt-1 dark:text-gray-400">
                            Existing card: {{ scanCardForSelectedProgram.stamps_count }} / {{ scanCardForSelectedProgram.program?.stamps_required }} stamps
                        </p>
                        <p v-else-if="scanProgramId" class="text-xs text-gray-500 mt-1 dark:text-gray-400">No open card yet — one will be created automatically.</p>
                        <p v-if="scanModal.error" class="text-xs text-red-600 mt-1">{{ scanModal.error }}</p>
                    </div>
                    <div>
                        <div class="flex items-baseline justify-between mb-1">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">My Store</label>
                            <span class="text-[10px] font-semibold uppercase tracking-wide text-gray-400">Remembered for this device</span>
                        </div>
                        <Autocomplete v-model="scanStoreId" :options="storeOptions" placeholder="Select store..." />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1 dark:text-gray-300">Purchase amount (₱) <span class="text-red-500">*</span></label>
                        <input ref="scanPurchaseAmountInputRef" v-model.number="scanPurchaseAmount" type="number" min="0.01" step="0.01" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 dark:border-gray-600" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1 dark:text-gray-300">Note</label>
                        <input v-model="scanNote" type="text" placeholder="Optional" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 dark:border-gray-600" />
                    </div>
                    <div class="flex justify-between gap-2 pt-2">
                        <button @click="rescan" class="px-4 py-2 text-sm rounded-lg border border-gray-300 hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-700">Scan Different Member</button>
                        <div class="flex gap-2">
                            <button @click="closeScanModal" class="px-4 py-2 text-sm rounded-lg border border-gray-300 hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-700">Cancel</button>
                            <button @click="submitScanAddStamp" :disabled="!scanProgramId || !scanPurchaseAmount || scanModal.submitting" class="px-4 py-2 text-sm rounded-lg bg-emerald-600 text-white hover:bg-emerald-700 disabled:opacity-50">Add Stamp</button>
                        </div>
                    </div>
                </template>
            </div>
        </Modal>

        <!-- Add Stamps Modal -->
        <Modal :show="stampModal.open" @close="stampModal.open = false" max-width="md">
            <div class="p-6 space-y-4">
                <h3 class="text-lg font-bold text-gray-900 dark:text-gray-100">Add Stamps</h3>
                <p class="text-sm text-gray-500 dark:text-gray-300">{{ stampModal.card?.customer?.name }} — {{ stampModal.card?.program?.name ?? 'No program assigned' }}</p>
                <div v-if="!stampModal.card?.program" class="rounded-lg bg-red-50 border border-red-200 px-3 py-2 text-xs text-red-700 dark:bg-red-500/10 dark:border-red-500/30 dark:text-red-300">
                    This card has no stamp program assigned, so stamps can't be added. Edit the card (or contact an admin) to assign it a valid program first.
                </div>
                <div>
                    <div class="flex items-baseline justify-between mb-1">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Number of stamps <span class="text-red-500">*</span></label>
                        <span class="text-xs text-gray-500 dark:text-gray-300">
                            {{ stampModal.card?.stamps_count ?? 0 }} / {{ stampModal.card?.program?.stamps_required ?? '?' }} &mdash; <span class="font-medium text-blue-600">{{ stampRemaining }} remaining</span>
                        </span>
                    </div>
                    <input v-model.number="stampForm.quantity" type="number" :disabled="!stampModal.card?.program" :min="1" :max="stampRemaining" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 dark:border-gray-600 disabled:bg-gray-50 disabled:text-gray-400 dark:disabled:bg-gray-800" />
                    <p v-if="stampForm.errors.quantity" class="text-xs text-red-600 mt-1">{{ stampForm.errors.quantity }}</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1 dark:text-gray-300">Purchase amount (₱) <span class="text-red-500">*</span></label>
                    <input v-model.number="stampForm.purchase_amount" type="number" :disabled="!stampModal.card?.program" min="0.01" step="0.01" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 dark:border-gray-600 disabled:bg-gray-50 disabled:text-gray-400 dark:disabled:bg-gray-800" />
                    <p v-if="stampForm.errors.purchase_amount" class="text-xs text-red-600 mt-1">{{ stampForm.errors.purchase_amount }}</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1 dark:text-gray-300">Store</label>
                    <Autocomplete v-model="stampForm.store_id" :options="storeOptions" :disabled="!stampModal.card?.program" placeholder="Select store..." />
                    <p v-if="stampForm.errors.store_id" class="text-xs text-red-600 mt-1">{{ stampForm.errors.store_id }}</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1 dark:text-gray-300">Note</label>
                    <input v-model="stampForm.note" type="text" :disabled="!stampModal.card?.program" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 dark:border-gray-600 disabled:bg-gray-50 disabled:text-gray-400 dark:disabled:bg-gray-800" />
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <button @click="stampModal.open = false" class="px-4 py-2 text-sm rounded-lg border border-gray-300 hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-700">Cancel</button>
                    <button @click="submitStamp" :disabled="stampForm.processing || !stampModal.card?.program" class="px-4 py-2 text-sm rounded-lg bg-green-600 text-white hover:bg-green-700 disabled:opacity-50">Add</button>
                </div>
            </div>
        </Modal>

        <!-- Record Purchase Modal -->
        <Modal :show="purchaseModal.open" @close="purchaseModal.open = false" max-width="md">
            <div class="p-6 space-y-4">
                <h3 class="text-lg font-bold text-gray-900 dark:text-gray-100">Record Purchase</h3>
                <p class="text-sm text-gray-500 dark:text-gray-300">
                    {{ purchaseModal.card?.customer?.name }} — {{ purchaseModal.card?.program?.name }}
                    <span v-if="purchaseProgram?.auto_stamp_amount" class="block text-xs">Earns 1 stamp per ₱{{ formatAmount(purchaseProgram.auto_stamp_amount) }}</span>
                </p>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1 dark:text-gray-300">Purchase amount (₱) <span class="text-red-500">*</span></label>
                    <input v-model.number="purchaseForm.purchase_amount" type="number" min="0" step="0.01" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 dark:border-gray-600" />
                    <p v-if="purchaseForm.errors.purchase_amount" class="text-xs text-red-600 mt-1">{{ purchaseForm.errors.purchase_amount }}</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1 dark:text-gray-300">Note</label>
                    <input v-model="purchaseForm.note" type="text" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 dark:border-gray-600" />
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <button @click="purchaseModal.open = false" class="px-4 py-2 text-sm rounded-lg border border-gray-300 hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-700">Cancel</button>
                    <button @click="submitPurchase" :disabled="purchaseForm.processing" class="px-4 py-2 text-sm rounded-lg bg-indigo-600 text-white hover:bg-indigo-700 disabled:opacity-50">Record</button>
                </div>
            </div>
        </Modal>

        <!-- Redeem Modal -->
        <Modal :show="redeemModal.open" @close="redeemModal.open = false" max-width="lg">
            <div class="p-6 space-y-4">
                <h3 class="text-lg font-bold text-gray-900 dark:text-gray-100">Redeem Reward</h3>
                <p class="text-sm text-gray-500 dark:text-gray-300">{{ redeemModal.card?.customer?.name }} — {{ redeemModal.card?.program?.name }}</p>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1 dark:text-gray-300">Location / Store <span class="text-red-500">*</span></label>
                    <Autocomplete v-model="redeemForm.location" :options="storeLocationOptions" placeholder="Search store..." />
                    <p v-if="redeemForm.errors.location" class="text-xs text-red-600 mt-1">{{ redeemForm.errors.location }}</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1 dark:text-gray-300">Reward item (consumable) <span class="text-red-500">*</span></label>
                    <Autocomplete v-model="redeemForm.asset_id" :options="assetOptions" :placeholder="loadingAssets ? 'Loading…' : (redeemForm.location ? 'Select item…' : 'Pick a location first')" :disabled="!redeemForm.location || loadingAssets" />
                    <p v-if="redeemForm.errors.asset_id" class="text-xs text-red-600 mt-1">{{ redeemForm.errors.asset_id }}</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1 dark:text-gray-300">Quantity <span class="text-red-500">*</span></label>
                    <input v-model.number="redeemForm.quantity" type="number" min="1" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 dark:border-gray-600" />
                    <p v-if="redeemForm.errors.quantity" class="text-xs text-red-600 mt-1">{{ redeemForm.errors.quantity }}</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1 dark:text-gray-300">Remarks</label>
                    <input v-model="redeemForm.remarks" type="text" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 dark:border-gray-600" />
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <button @click="redeemModal.open = false" class="px-4 py-2 text-sm rounded-lg border border-gray-300 hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-700">Cancel</button>
                    <button @click="submitRedeem" :disabled="redeemForm.processing || !redeemForm.asset_id" class="px-4 py-2 text-sm rounded-lg bg-amber-600 text-white hover:bg-amber-700 disabled:opacity-50">Redeem</button>
                </div>
            </div>
        </Modal>

        <!-- Stamp Entry History Modal -->
        <Modal :show="entriesModal.open" @close="entriesModal.open = false" max-width="2xl">
            <div class="p-6 space-y-4">
                <div>
                    <h3 class="text-lg font-bold text-gray-900 dark:text-gray-100">Stamp History</h3>
                    <p class="text-sm text-gray-500 mt-0.5 dark:text-gray-300">
                        {{ entriesModal.card?.customer?.name }} — {{ entriesModal.card?.program?.name }}
                        <span class="ml-2 text-blue-600 font-medium">{{ entriesModal.card?.stamps_count }} / {{ entriesModal.card?.program?.stamps_required }} stamps</span>
                    </p>
                </div>

                <!-- Loading -->
                <div v-if="entriesModal.loading" class="py-10 flex items-center justify-center">
                    <svg class="animate-spin h-6 w-6 text-blue-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 6.477 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"/>
                    </svg>
                </div>

                <!-- Empty -->
                <div v-else-if="!entriesModal.entries.length" class="py-10 flex flex-col items-center justify-center border-2 border-dashed border-gray-100 rounded-xl dark:border-gray-700">
                    <p class="text-sm font-bold text-gray-400 uppercase tracking-widest dark:text-gray-400">No stamp entries yet</p>
                </div>

                <!-- History table -->
                <div v-else class="overflow-x-auto rounded-xl border border-gray-100 dark:border-gray-700">
                    <table class="min-w-full divide-y divide-gray-100 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-900/50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase dark:text-slate-300">Date & Time</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase dark:text-slate-300">Stamps</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase dark:text-slate-300">Source</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase dark:text-slate-300">Store</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase dark:text-slate-300">Purchase Amt</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase dark:text-slate-300">Note</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase dark:text-slate-300">By</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50 bg-white dark:bg-gray-800">
                            <tr v-for="e in entriesModal.entries" :key="e.id" class="hover:bg-gray-50 dark:hover:bg-gray-700">
                                <td class="px-4 py-3 text-sm text-gray-600 whitespace-nowrap dark:text-gray-300">{{ formatDateTime(e.created_at) }}</td>
                                <td class="px-4 py-3 text-sm font-bold text-green-600">+{{ e.quantity }}</td>
                                <td class="px-4 py-3 text-sm">
                                    <span :class="['px-2 py-0.5 rounded-full text-xs font-medium capitalize', e.source === 'purchase' ? 'bg-indigo-100 text-indigo-700' : 'bg-gray-100 text-gray-600']">
                                        {{ e.source }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">{{ e.store ? `${e.store.code}${e.store.name ? ' — ' + e.store.name : ''}` : '—' }}</td>
                                <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">{{ e.purchase_amount ? '₱' + formatAmount(e.purchase_amount) : '—' }}</td>
                                <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">{{ e.note || '—' }}</td>
                                <td class="px-4 py-3 text-sm text-gray-600 whitespace-nowrap dark:text-gray-300">{{ e.creator?.name || '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="flex justify-end pt-2">
                    <button @click="entriesModal.open = false" class="px-4 py-2 text-sm rounded-lg border border-gray-300 hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-700">Close</button>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>
