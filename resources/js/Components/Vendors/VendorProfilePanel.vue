<template>
    <div>
        <!-- Header -->
        <div class="flex items-center justify-between gap-2 pb-3" :class="compact ? '' : 'border-b border-gray-100 dark:border-gray-700'">
            <div>
                <h4 class="text-sm font-bold uppercase tracking-wider text-gray-800 dark:text-gray-100">
                    Company Profile
                </h4>
                <p v-if="!compact" class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                    Legal, tax and payment details the vendor maintains in the portal
                </p>
            </div>
            <span v-if="profile" :class="statusClass" class="shrink-0 rounded-full px-2 py-0.5 text-[10px] font-bold">
                {{ profile.status }}
            </span>
        </div>

        <!-- Loading -->
        <div v-if="loading" class="grid grid-cols-1 gap-2 pt-3 sm:grid-cols-2">
            <div v-for="n in 6" :key="n" class="animate-pulse rounded-lg bg-gray-100 px-3 py-2 dark:bg-gray-700/50">
                <div class="h-2 w-1/3 rounded bg-gray-200 dark:bg-gray-600"></div>
                <div class="mt-1.5 h-2.5 w-2/3 rounded bg-gray-200 dark:bg-gray-600"></div>
            </div>
        </div>

        <p v-else-if="error" class="py-6 text-center text-xs font-semibold text-red-700 dark:text-red-300">
            {{ error }}
        </p>

        <!-- The vendor has never opened the portal's profile form -->
        <div
            v-else-if="!profile"
            class="mt-3 rounded-xl border border-dashed border-gray-200 py-8 text-center dark:border-gray-700"
        >
            <p class="text-xs font-semibold text-gray-600 dark:text-gray-300">No company profile yet</p>
            <p class="mt-0.5 text-[11px] text-gray-400">
                {{ vendor?.has_portal_access
                    ? 'This vendor has not filled in their profile in the portal.'
                    : 'Only vendors with a portal account keep a company profile.' }}
            </p>
        </div>

        <template v-else>
            <!-- Requested changes, awaiting a decision -->
            <div
                v-if="profile.has_pending_changes"
                class="mt-3 rounded-xl border border-amber-200 bg-amber-50/70 p-3 dark:border-amber-900/50 dark:bg-amber-900/20"
            >
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="text-[11px] font-bold uppercase tracking-wider text-amber-900 dark:text-amber-200">
                        Requested changes ({{ profile.pending_changes.length }})
                    </p>
                    <div v-if="canReview && !rejecting" class="flex items-center gap-1.5">
                        <button
                            type="button"
                            @click="$emit('review', { action: 'approved' })"
                            :disabled="reviewing"
                            class="inline-flex items-center gap-1 rounded-md bg-emerald-600 px-2.5 py-1 text-xs font-bold text-white transition-colors hover:bg-emerald-700 disabled:opacity-50"
                        >
                            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                            </svg>
                            <span>{{ reviewing ? 'Saving...' : 'Approve changes' }}</span>
                        </button>
                        <button
                            type="button"
                            @click="rejecting = true"
                            :disabled="reviewing"
                            class="inline-flex items-center gap-1 rounded-md border border-red-300 bg-white px-2.5 py-1 text-xs font-bold text-red-700 transition-colors hover:bg-red-50 disabled:opacity-50 dark:border-red-900/60 dark:bg-transparent dark:text-red-300"
                        >
                            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                            <span>Reject</span>
                        </button>
                    </div>
                </div>

                <!-- Only what actually differs: old value struck through, new beside it -->
                <ul class="mt-2 space-y-1.5">
                    <li
                        v-for="change in profile.pending_changes"
                        :key="change.field"
                        class="rounded-lg bg-white/70 px-2.5 py-1.5 dark:bg-gray-900/40"
                    >
                        <p class="text-[10px] font-bold uppercase tracking-wider text-gray-400">{{ change.label }}</p>
                        <p class="text-xs text-gray-800 dark:text-gray-200">
                            <span v-if="change.from" class="text-gray-400 line-through">{{ change.from }}</span>
                            <span v-else class="italic text-gray-400">empty</span>
                            <span class="mx-1.5 text-gray-400">→</span>
                            <span class="font-semibold">{{ change.to || '—' }}</span>
                        </p>
                    </li>
                </ul>

                <div v-if="rejecting" class="mt-2.5">
                    <label class="block text-[10px] font-bold uppercase tracking-wider text-amber-900 dark:text-amber-200">
                        Reason for rejection <span class="text-red-500">*</span>
                    </label>
                    <textarea
                        v-model="rejectRemarks"
                        rows="2"
                        maxlength="1000"
                        placeholder="Tell the vendor what to correct before resubmitting."
                        class="mt-1 block w-full rounded-lg border-gray-300 text-xs shadow-sm focus:border-red-500 focus:ring-red-500 dark:border-gray-600 dark:bg-gray-800"
                    ></textarea>
                    <div class="mt-2 flex items-center justify-end gap-1.5">
                        <button
                            type="button"
                            @click="cancelReject"
                            class="rounded-md bg-white px-2.5 py-1 text-xs font-semibold text-gray-600 transition-colors hover:bg-gray-100 dark:bg-gray-800 dark:text-gray-300"
                        >
                            Cancel
                        </button>
                        <button
                            type="button"
                            @click="confirmReject"
                            :disabled="reviewing"
                            class="rounded-md bg-red-600 px-2.5 py-1 text-xs font-bold text-white transition-colors hover:bg-red-700 disabled:opacity-50"
                        >
                            {{ reviewing ? 'Saving...' : 'Confirm rejection' }}
                        </button>
                    </div>
                </div>

                <p v-else-if="!canReview" class="mt-2 text-[10px] italic text-amber-800 dark:text-amber-300">
                    Waiting for someone who can approve vendor accounts.
                </p>
            </div>

            <!-- The live profile, exactly the fields the portal's form carries -->
            <dl v-if="!compact || !profile.has_pending_changes" class="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2">
                <div
                    v-for="field in visibleFields"
                    :key="field.field"
                    class="rounded-lg border border-gray-100 bg-gray-50/70 px-3 py-2 dark:border-gray-700 dark:bg-gray-900/40"
                >
                    <dt class="text-[10px] font-bold uppercase tracking-wider text-gray-400">{{ field.label }}</dt>
                    <dd class="mt-0.5 truncate text-xs font-semibold text-gray-800 dark:text-gray-200" :title="field.value || ''">
                        {{ field.value || '—' }}
                    </dd>
                </div>
            </dl>

            <!-- Cheque instructions: the portal keeps these as their own section
                 because a payee name the bank rejects cannot be encashed. -->
            <div v-if="!compact && chequeFields.length" class="mt-4">
                <h5 class="text-[11px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-300">Cheque Details</h5>
                <p class="mt-0.5 text-[10px] text-gray-400">
                    Used when payment is released by cheque. The payee name must match what the bank will accept.
                </p>
                <dl class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2">
                    <div
                        v-for="field in chequeFields"
                        :key="field.field"
                        class="rounded-lg border border-gray-100 bg-gray-50/70 px-3 py-2 dark:border-gray-700 dark:bg-gray-900/40"
                    >
                        <dt class="text-[10px] font-bold uppercase tracking-wider text-gray-400">{{ field.label }}</dt>
                        <dd class="mt-0.5 truncate text-xs font-semibold text-gray-800 dark:text-gray-200" :title="field.value || ''">
                            {{ field.value || '—' }}
                        </dd>
                    </div>
                </dl>
            </div>

            <p v-if="profile.reviewed_by" class="mt-2 text-[10px] text-gray-400">
                Last reviewed by <span class="font-medium text-gray-600 dark:text-gray-300">{{ profile.reviewed_by }}</span>
                <span v-if="profile.reviewed_at"> · {{ profile.reviewed_at }}</span>
            </p>
            <p v-if="profile.review_remarks" class="mt-0.5 text-[10px] italic text-gray-500 dark:text-gray-400">
                “{{ profile.review_remarks }}”
            </p>
        </template>

        <!-- Contacts: the vendor maintains these freely, nothing to approve. -->
        <div v-if="!loading && contacts.length" class="mt-5">
            <div class="flex items-center gap-2">
                <h5 class="text-[11px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-300">Contacts</h5>
                <span class="rounded-full bg-gray-100 px-1.5 py-0.5 text-[10px] font-bold text-gray-600 dark:bg-gray-700 dark:text-gray-300">
                    {{ contacts.length }}
                </span>
            </div>
            <ul class="mt-2 space-y-1.5">
                <li
                    v-for="contact in contacts"
                    :key="contact.id"
                    class="rounded-lg border border-gray-100 bg-gray-50/70 px-3 py-2 dark:border-gray-700 dark:bg-gray-900/40"
                >
                    <div class="flex items-center gap-1.5">
                        <p class="text-xs font-bold text-gray-800 dark:text-gray-200">{{ contact.name }}</p>
                        <span
                            v-if="contact.is_primary"
                            class="rounded bg-emerald-100 px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wider text-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-300"
                        >
                            Primary
                        </span>
                    </div>
                    <p class="mt-0.5 text-[11px] text-gray-500 dark:text-gray-400">
                        <span v-if="contact.position">{{ contact.position }}</span>
                        <span v-if="contact.position && contact.email"> &middot; </span>
                        <span v-if="contact.email">{{ contact.email }}</span>
                        <span v-if="contact.phone"> &middot; {{ contact.phone }}</span>
                    </p>
                </li>
            </ul>
        </div>

        <!-- Bank accounts: verified before payments are released against them. -->
        <div v-if="!loading && bankAccounts.length" class="mt-5">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex items-center gap-2">
                    <h5 class="text-[11px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-300">Bank Accounts</h5>
                    <span class="rounded-full bg-gray-100 px-1.5 py-0.5 text-[10px] font-bold text-gray-600 dark:bg-gray-700 dark:text-gray-300">
                        {{ bankAccounts.length }}
                    </span>
                </div>
                <span
                    v-if="pendingBankCount"
                    class="rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-bold text-amber-800 dark:bg-amber-950/50 dark:text-amber-300"
                >
                    {{ pendingBankCount }} awaiting verification
                </span>
            </div>
            <p class="mt-1 text-[10px] text-gray-400">
                Payments are only released against a verified account.
            </p>

            <ul class="mt-2 space-y-2">
                <li
                    v-for="account in bankAccounts"
                    :key="account.id"
                    class="rounded-lg border bg-white px-3 py-2 dark:bg-gray-800/60"
                    :class="account.is_pending
                        ? 'border-amber-200 dark:border-amber-900/50'
                        : 'border-gray-100 dark:border-gray-700'"
                >
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <div class="flex items-center gap-1.5">
                                <p class="truncate text-xs font-bold text-gray-800 dark:text-gray-200">{{ account.bank_name }}</p>
                                <span
                                    v-if="account.is_default"
                                    class="rounded bg-gray-100 px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wider text-gray-600 dark:bg-gray-700 dark:text-gray-300"
                                >
                                    Default
                                </span>
                            </div>
                            <p class="mt-0.5 truncate text-[11px] text-gray-500 dark:text-gray-400">
                                {{ account.account_name }}
                                <span v-if="account.branch"> &middot; {{ account.branch }}</span>
                                <span v-if="account.currency"> &middot; {{ account.currency }}</span>
                            </p>
                            <p class="mt-0.5 font-mono text-[11px] text-gray-700 dark:text-gray-300">
                                {{ account.account_number || account.masked_account_number }}
                                <span v-if="!account.account_number" class="ml-1 font-sans text-[10px] italic text-gray-400">
                                    (masked)
                                </span>
                            </p>
                        </div>
                        <span :class="bankStatusClass(account.status)" class="shrink-0 rounded px-1.5 py-0.5 text-[10px] font-semibold">
                            {{ account.status }}
                        </span>
                    </div>

                    <p v-if="account.reviewed_by" class="mt-1 text-[10px] text-gray-400">
                        Verified by <span class="font-medium text-gray-600 dark:text-gray-300">{{ account.reviewed_by }}</span>
                        <span v-if="account.reviewed_at"> &middot; {{ account.reviewed_at }}</span>
                    </p>
                    <p v-if="account.review_remarks" class="mt-0.5 text-[10px] italic text-gray-500 dark:text-gray-400">
                        &ldquo;{{ account.review_remarks }}&rdquo;
                    </p>

                    <!-- The verification decision -->
                    <div v-if="account.is_pending && canVerifyBank" class="mt-2 border-t border-gray-100 pt-2 dark:border-gray-700/60">
                        <template v-if="rejectingBankId !== account.id">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <p class="text-[11px] font-semibold text-amber-800 dark:text-amber-300">Awaiting verification</p>
                                <div class="flex items-center gap-1.5">
                                    <button
                                        type="button"
                                        @click="$emit('review-bank', { account, action: 'approved' })"
                                        :disabled="verifyingBankId === account.id"
                                        class="inline-flex items-center gap-1 rounded-md bg-emerald-600 px-2.5 py-1 text-xs font-bold text-white transition-colors hover:bg-emerald-700 disabled:opacity-50"
                                    >
                                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                        </svg>
                                        <span>{{ verifyingBankId === account.id ? 'Saving...' : 'Verify' }}</span>
                                    </button>
                                    <button
                                        type="button"
                                        @click="startBankReject(account)"
                                        :disabled="verifyingBankId === account.id"
                                        class="inline-flex items-center gap-1 rounded-md border border-red-300 bg-white px-2.5 py-1 text-xs font-bold text-red-700 transition-colors hover:bg-red-50 disabled:opacity-50 dark:border-red-900/60 dark:bg-transparent dark:text-red-300"
                                    >
                                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                        </svg>
                                        <span>Reject</span>
                                    </button>
                                </div>
                            </div>
                        </template>

                        <template v-else>
                            <label class="block text-[10px] font-bold uppercase tracking-wider text-amber-900 dark:text-amber-200">
                                Reason for rejection <span class="text-red-500">*</span>
                            </label>
                            <textarea
                                v-model="bankRejectRemarks"
                                rows="2"
                                maxlength="1000"
                                placeholder="Tell the vendor what does not match the bank certification."
                                class="mt-1 block w-full rounded-lg border-gray-300 text-xs shadow-sm focus:border-red-500 focus:ring-red-500 dark:border-gray-600 dark:bg-gray-800"
                            ></textarea>
                            <div class="mt-2 flex items-center justify-end gap-1.5">
                                <button
                                    type="button"
                                    @click="cancelBankReject"
                                    class="rounded-md bg-gray-100 px-2.5 py-1 text-xs font-semibold text-gray-600 transition-colors hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="button"
                                    @click="confirmBankReject(account)"
                                    :disabled="verifyingBankId === account.id"
                                    class="rounded-md bg-red-600 px-2.5 py-1 text-xs font-bold text-white transition-colors hover:bg-red-700 disabled:opacity-50"
                                >
                                    {{ verifyingBankId === account.id ? 'Saving...' : 'Confirm rejection' }}
                                </button>
                            </div>
                        </template>
                    </div>

                    <p
                        v-else-if="account.is_pending"
                        class="mt-1.5 text-[10px] italic text-amber-800 dark:text-amber-300"
                    >
                        Waiting for someone who can verify bank details.
                    </p>
                </li>
            </ul>
        </div>
    </div>
</template>

<script setup>
import { computed, ref } from 'vue'

const props = defineProps({
    vendor: {
        type: Object,
        default: null,
    },
    profile: {
        type: Object,
        default: null,
    },
    contacts: {
        type: Array,
        default: () => [],
    },
    bankAccounts: {
        type: Array,
        default: () => [],
    },
    loading: {
        type: Boolean,
        default: false,
    },
    error: {
        type: String,
        default: '',
    },
    // Holds vendors.approve — the portal notifies that same permission when a
    // vendor submits profile changes.
    canReview: {
        type: Boolean,
        default: false,
    },
    reviewing: {
        type: Boolean,
        default: false,
    },
    // Holds vendors.verify_bank — deliberately not the same right as approving
    // the account or its profile, because payments follow this decision.
    canVerifyBank: {
        type: Boolean,
        default: false,
    },
    verifyingBankId: {
        type: [Number, String],
        default: null,
    },
    // Inside the account approval modal only the decision matters, so the full
    // field grid is dropped while changes are pending.
    compact: {
        type: Boolean,
        default: false,
    },
})

const emit = defineEmits(['review', 'review-bank'])

const rejecting = ref(false)
const rejectRemarks = ref('')
const rejectingBankId = ref(null)
const bankRejectRemarks = ref('')

const pendingBankCount = computed(() => props.bankAccounts.filter((a) => a.is_pending).length)

const bankStatusClass = (status) => ({
    approved: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-300',
    pending: 'bg-amber-100 text-amber-800 dark:bg-amber-950/50 dark:text-amber-300',
    rejected: 'bg-red-100 text-red-800 dark:bg-red-950/50 dark:text-red-300',
}[(status || '').toLowerCase()] || 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300')

const startBankReject = (account) => {
    rejectingBankId.value = account.id
    bankRejectRemarks.value = ''
}

const cancelBankReject = () => {
    rejectingBankId.value = null
    bankRejectRemarks.value = ''
}

const confirmBankReject = (account) => {
    if (!bankRejectRemarks.value.trim()) {
        return
    }

    emit('review-bank', { account, action: 'rejected', remarks: bankRejectRemarks.value.trim() })
    cancelBankReject()
}

// Compact mode shows the identity fields only; the full panel shows all of the
// profile fields, with the cheque instructions in their own section below.
const visibleFields = computed(() => {
    const fields = (props.profile?.fields || []).filter((f) => f.group !== 'cheque')

    return props.compact ? fields.slice(0, 6) : fields
})

const chequeFields = computed(() => (props.profile?.fields || []).filter((f) => f.group === 'cheque'))

const statusClass = computed(() => ({
    approved: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-300',
    pending: 'bg-amber-100 text-amber-800 dark:bg-amber-950/50 dark:text-amber-300',
    rejected: 'bg-red-100 text-red-800 dark:bg-red-950/50 dark:text-red-300',
}[(props.profile?.status || '').toLowerCase()] || 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300'))

const cancelReject = () => {
    rejecting.value = false
    rejectRemarks.value = ''
}

const confirmReject = () => {
    if (!rejectRemarks.value.trim()) {
        return
    }

    emit('review', { action: 'rejected', remarks: rejectRemarks.value.trim() })
    cancelReject()
}
</script>
