<template>
    <div class="space-y-5">
        <!-- Where the cycle stands -->
        <div class="rounded-xl border p-5 shadow-sm" :class="stateClass">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0">
                    <h3 class="text-lg font-bold">{{ stateHeadline }}</h3>
                    <p class="mt-1 text-sm opacity-90">{{ stateDetail }}</p>

                    <div v-if="readiness.awaiting_approval && (readiness.assigned_approvers || []).length"
                         class="mt-3 flex flex-wrap items-center gap-2">
                        <span class="text-xs font-semibold uppercase tracking-wider opacity-75">Waiting on</span>
                        <span v-for="name in readiness.assigned_approvers" :key="name"
                              class="rounded-full bg-white/70 px-2.5 py-0.5 text-xs font-semibold text-gray-800 dark:bg-slate-900/60 dark:text-gray-100">
                            {{ name }}
                        </span>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <!-- Print the certificate. Opens a new tab; the endpoint streams
                         the PDF inline so the browser renders it rather than saving it. -->
                    <a v-if="signoff" :href="route('qat.signoff.pdf', cycle.id)" target="_blank" rel="noopener"
                       class="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm transition-colors hover:bg-gray-50 dark:border-gray-600 dark:bg-slate-900 dark:text-gray-200 dark:hover:bg-gray-700">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
                        </svg>
                        Print sign-off PDF
                    </a>

                    <button v-if="can('qat.submit') && readiness.can_submit" @click="submitting = true"
                            class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-blue-700">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                        </svg>
                        Submit for manager sign-off
                    </button>

                    <button v-if="can('qat.submit') && readiness.awaiting_approval" @click="withdraw"
                            class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-50 dark:border-gray-600 dark:bg-slate-900 dark:text-gray-200 dark:hover:bg-gray-700">
                        Withdraw
                    </button>
                </div>
            </div>

            <!-- Why the submit button is not there -->
            <ul v-if="!readiness.can_submit && !readiness.awaiting_approval && !readiness.signed_off"
                class="mt-3 space-y-1 text-sm opacity-90">
                <li v-if="!readiness.has_cases">• Add at least one test case.</li>
                <li v-if="!readiness.has_participants">• Add at least one active tester.</li>
                <li v-if="(readiness.unanswered_cases || []).length">
                    • {{ readiness.unanswered_cases.length }}
                    {{ readiness.gate_on_critical_only ? 'critical ' : '' }}case(s) still have no verdict.
                </li>
            </ul>
        </div>

        <!-- Promotion, the thing the sign-off actually gates -->
        <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h3 class="text-base font-bold text-gray-900 dark:text-gray-100">Promote to UAT</h3>
                    <p class="mt-1 max-w-2xl text-sm text-gray-500 dark:text-gray-300">
                        Creates a new UAT cycle carrying this cycle's sections and test cases. Verdicts,
                        findings and testers stay behind — the client-facing round starts clean.
                    </p>

                    <p v-if="cycle.promoted_uat_cycle" class="mt-2 text-sm font-semibold text-emerald-700 dark:text-emerald-300">
                        Already promoted to
                        <Link :href="route('uat.show', cycle.promoted_uat_cycle.id)" class="underline">
                            {{ cycle.promoted_uat_cycle.code }} — {{ cycle.promoted_uat_cycle.title }}
                        </Link>
                    </p>
                    <p v-else-if="!readiness.signed_off" class="mt-2 flex items-center gap-1.5 text-sm font-semibold text-amber-700 dark:text-amber-300">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                        </svg>
                        Locked until a manager has signed this cycle off.
                    </p>
                </div>

                <button v-if="can('qat.promote')" :disabled="!readiness.can_promote"
                        @click="openPromote"
                        class="inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold shadow-sm transition-colors"
                        :class="readiness.can_promote
                            ? 'bg-indigo-600 text-white hover:bg-indigo-700'
                            : 'cursor-not-allowed bg-gray-200 text-gray-400 dark:bg-gray-700 dark:text-gray-500'">
                    Promote to UAT
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13 7l5 5m0 0l-5 5m5-5H6" />
                    </svg>
                </button>
            </div>
        </div>

        <!-- The manager's decision panel -->
        <div v-if="readiness.awaiting_approval && readiness.is_approver && can('qat.approve')"
             class="rounded-xl border-2 border-blue-300 bg-white p-5 shadow-sm dark:border-blue-500/40 dark:bg-gray-800">
            <h3 class="text-lg font-bold text-gray-900 dark:text-gray-100">Your decision</h3>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-300">
                You were nominated to sign this cycle off. Your name and signature are recorded against it.
            </p>

            <form @submit.prevent="decide" class="mt-4 space-y-4">
                <!-- Failing cases are context, not a gate: the manager needs to see
                     what the run actually found before deciding. -->
                <div v-if="(readiness.failing_cases || []).length"
                     class="rounded-lg border border-gray-200 bg-gray-50 p-3 dark:border-gray-700 dark:bg-slate-900">
                    <h4 class="text-sm font-bold text-gray-700 dark:text-gray-200">
                        {{ readiness.failing_cases.length }} case(s) did not pass
                    </h4>
                    <ul class="mt-1.5 space-y-0.5 text-sm text-gray-600 dark:text-gray-300">
                        <li v-for="c in readiness.failing_cases" :key="c.case_key">
                            <span class="font-mono text-xs text-gray-400">{{ c.case_key }}</span>
                            {{ c.title }}
                            <span class="ml-1 text-xs font-semibold uppercase">{{ c.verdict }}</span>
                        </li>
                    </ul>
                </div>

                <!-- Blocking findings: the gate -->
                <div v-if="blocking.length" class="rounded-lg border border-rose-200 bg-rose-50 p-4 dark:border-rose-500/30 dark:bg-rose-500/10">
                    <h4 class="flex items-center gap-2 text-sm font-bold text-rose-800 dark:text-rose-200">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M5.07 19h13.86a2 2 0 001.71-3.01L13.71 4a2 2 0 00-3.42 0L3.36 15.99A2 2 0 005.07 19z" />
                        </svg>
                        {{ blocking.length }} finding(s) block this sign-off
                    </h4>
                    <p class="mt-1 text-xs text-rose-700 dark:text-rose-300">
                        These are unresolved and rated blocker or major. Approve only after they are fixed —
                        or tick the ones you are accepting anyway and say why.
                    </p>

                    <ul class="mt-3 space-y-2">
                        <li v-for="f in blocking" :key="f.id"
                            class="flex items-start gap-2 rounded-lg bg-white p-2.5 dark:bg-slate-900">
                            <input :id="`waive-${f.id}`" type="checkbox" :value="f.id" v-model="form.waived_finding_ids"
                                   class="mt-0.5 h-4 w-4 rounded border-gray-300 text-amber-600 focus:ring-amber-500">
                            <label :for="`waive-${f.id}`" class="min-w-0 flex-1 cursor-pointer">
                                <span class="rounded px-1.5 py-0.5 text-[10px] font-bold uppercase" :class="SEVERITY_CHIPS[f.severity]">
                                    {{ f.severity }}
                                </span>
                                <span class="ml-2 font-mono text-xs text-gray-400">{{ f.reference }}</span>
                                <span class="ml-1 text-sm font-medium text-gray-800 dark:text-gray-100">{{ f.title }}</span>
                            </label>
                        </li>
                    </ul>

                    <div v-if="form.waived_finding_ids.length" class="mt-3">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">
                            Why are you accepting these? <span class="text-rose-600">*</span>
                        </label>
                        <textarea v-model="form.waiver_reason" rows="2"
                                  placeholder="e.g. Cosmetic in practice; the affected report is not used by the client until Q4."
                                  class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-amber-500 focus:ring-amber-500 dark:border-gray-600 dark:bg-slate-900 dark:text-gray-100"></textarea>
                        <InputError :message="form.errors.waiver_reason" class="mt-1" />
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            Recorded permanently against each finding and on the sign-off certificate.
                        </p>
                    </div>
                </div>

                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">Decision <span class="text-rose-600">*</span></label>
                        <Autocomplete v-model="form.result" :options="options.signoffResults || []" placeholder="Select decision..." />
                        <InputError :message="form.errors.result" class="mt-1" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">
                            Remarks
                            <span v-if="remarksRequired" class="text-rose-600">*</span>
                        </label>
                        <textarea v-model="form.remarks" rows="2"
                                  class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-slate-900 dark:text-gray-100"></textarea>
                        <InputError :message="form.errors.remarks" class="mt-1" />
                    </div>
                </div>

                <SignaturePad
                    v-model="form.signature"
                    label="Sign here"
                    hint="Draw your signature with a mouse, finger or stylus. It appears on the printed certificate."
                    placeholder="Draw your signature"
                />
                <InputError :message="form.errors.signature" class="mt-1" />

                <div class="flex flex-wrap justify-end gap-3 border-t border-gray-200 pt-4 dark:border-gray-700">
                    <button type="button" @click="reject" :disabled="form.processing"
                            class="rounded-lg border border-rose-300 bg-white px-4 py-2 text-sm font-semibold text-rose-700 transition-colors hover:bg-rose-50 disabled:opacity-50 dark:border-rose-400/40 dark:bg-slate-900 dark:text-rose-200 dark:hover:bg-rose-500/15">
                        Return to the team
                    </button>
                    <button type="submit" :disabled="form.processing"
                            class="rounded-lg bg-emerald-600 px-5 py-2 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-emerald-700 disabled:opacity-50">
                        {{ form.processing ? 'Recording...' : 'Sign off' }}
                    </button>
                </div>
            </form>
        </div>

        <!-- Read-only note for everyone else. The tab stays visible on purpose:
             hiding it would make the gate itself invisible. -->
        <div v-else-if="readiness.awaiting_approval"
             class="rounded-xl border border-gray-200 bg-gray-50 p-4 text-sm text-gray-600 dark:border-gray-700 dark:bg-gray-900/40 dark:text-gray-300">
            This cycle is waiting on its approving manager. You are not one of the assigned approvers, so this is read-only for you.
        </div>

        <!-- The signed record -->
        <div v-if="signoff" class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <h3 class="text-base font-bold text-gray-900 dark:text-gray-100">Sign-off record</h3>

            <div class="mt-3 grid gap-5 md:grid-cols-2">
                <div>
                    <dl class="space-y-2 text-sm">
                        <div class="flex justify-between gap-4">
                            <dt class="text-gray-500 dark:text-gray-400">Decision</dt>
                            <dd>
                                <span class="rounded-full px-2 py-0.5 text-xs font-bold" :class="SIGNOFF_CHIPS[signoff.result]">
                                    {{ resultLabel(signoff.result) }}
                                </span>
                            </dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-gray-500 dark:text-gray-400">Signed by</dt>
                            <dd class="font-semibold text-gray-900 dark:text-gray-100">{{ signoff.confirmed_name || '—' }}</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-gray-500 dark:text-gray-400">Signed on</dt>
                            <dd class="text-gray-900 dark:text-gray-100">{{ formatDateTime(signoff.confirmed_at) }}</dd>
                        </div>
                    </dl>

                    <p v-if="signoff.remarks" class="mt-3 whitespace-pre-wrap rounded-lg bg-gray-50 p-3 text-sm text-gray-700 dark:bg-slate-900 dark:text-gray-200">
                        {{ signoff.remarks }}
                    </p>
                </div>

                <div>
                    <span class="text-xs font-semibold uppercase tracking-wider text-gray-400">Signature</span>
                    <div class="mt-1 rounded-lg border border-gray-200 bg-white p-2 dark:border-gray-600 dark:bg-white">
                        <img v-if="signoff.signature_url" :src="signoff.signature_url" alt="Signature" class="h-24 object-contain">
                        <p v-else class="py-8 text-center text-sm italic text-gray-400">
                            Confirmed electronically — no drawn signature.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Waived findings stay visible for good -->
            <div v-if="(readiness.waived_findings || []).length"
                 class="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-3 dark:border-amber-500/30 dark:bg-amber-500/10">
                <h4 class="text-sm font-bold text-amber-800 dark:text-amber-200">Accepted under waiver</h4>
                <ul class="mt-2 space-y-1 text-sm text-amber-900 dark:text-amber-100">
                    <li v-for="f in readiness.waived_findings" :key="f.id">
                        <span class="font-mono text-xs">{{ f.reference }}</span>
                        <span class="ml-1 font-medium">{{ f.title }}</span>
                        <span class="ml-1 text-xs opacity-75">({{ f.severity }})</span>
                    </li>
                </ul>
                <p v-if="signoff.waiver_reason" class="mt-2 whitespace-pre-wrap text-sm italic text-amber-900 dark:text-amber-100">
                    “{{ signoff.waiver_reason }}”
                </p>
            </div>
        </div>

        <!-- History. Superseded rows are kept, never overwritten. -->
        <div v-if="ledger.length > 1" class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <h3 class="text-base font-bold text-gray-900 dark:text-gray-100">Sign-off history</h3>
            <div class="mt-3 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-xs uppercase tracking-wider text-gray-400 dark:border-gray-700">
                            <th class="py-2 pr-4">Date</th>
                            <th class="py-2 pr-4">By</th>
                            <th class="py-2 pr-4">Decision</th>
                            <th class="py-2">Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in ledger" :key="row.id" class="border-b border-gray-100 dark:border-gray-700/60">
                            <td class="py-2 pr-4 whitespace-nowrap text-gray-600 dark:text-gray-300">{{ formatDateTime(row.confirmed_at) }}</td>
                            <td class="py-2 pr-4 text-gray-800 dark:text-gray-100">{{ row.confirmed_name || '—' }}</td>
                            <td class="py-2 pr-4">
                                <span class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="SIGNOFF_CHIPS[row.result]">
                                    {{ resultLabel(row.result) }}
                                </span>
                                <span v-if="!row.is_current" class="ml-1 text-[10px] uppercase text-gray-400">superseded</span>
                            </td>
                            <td class="py-2 text-gray-600 dark:text-gray-300">{{ row.remarks || '' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Submit confirmation -->
        <Modal :show="submitting" @close="submitting = false" maxWidth="lg">
            <div class="p-6">
                <h3 class="text-xl font-bold text-gray-900 dark:text-gray-100">Submit for manager sign-off</h3>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
                    Your immediate manager is worked out from the org chart and notified. While the cycle waits
                    for their decision it is frozen — no further verdicts can be recorded, so they are deciding
                    on exactly what you are showing them.
                </p>
                <div v-if="blocking.length" class="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
                    {{ blocking.length }} unresolved blocker/major finding(s) will be shown to them. They cannot
                    approve without either those being fixed, or writing down why they are accepting them.
                </div>
                <InputError :message="submitForm.errors.submit" class="mt-2" />

                <div class="mt-5 flex justify-end gap-3 border-t border-gray-200 pt-4 dark:border-gray-700">
                    <button type="button" @click="submitting = false"
                            class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                        Cancel
                    </button>
                    <button type="button" @click="doSubmit" :disabled="submitForm.processing"
                            class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-blue-700 disabled:opacity-50">
                        {{ submitForm.processing ? 'Submitting...' : 'Submit' }}
                    </button>
                </div>
            </div>
        </Modal>

        <!-- Promote -->
        <Modal :show="promoting" @close="promoting = false" maxWidth="lg">
            <div class="p-6">
                <h3 class="text-xl font-bold text-gray-900 dark:text-gray-100">Promote to UAT</h3>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
                    A new UAT cycle is created with this cycle's sections and test cases. Nothing else carries
                    over — you add the stakeholder roster there.
                </p>

                <form @submit.prevent="doPromote" class="mt-4 space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">UAT cycle title <span class="text-rose-600">*</span></label>
                        <input v-model="promoteForm.title" type="text" required
                               class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-slate-900 dark:text-gray-100">
                        <InputError :message="promoteForm.errors.title" class="mt-1" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">Owning department</label>
                        <Autocomplete v-model="promoteForm.department_id" :options="options.departments || []" placeholder="Same as this cycle..." />
                    </div>
                    <InputError :message="promoteForm.errors.promote" />

                    <div class="flex justify-end gap-3 border-t border-gray-200 pt-4 dark:border-gray-700">
                        <button type="button" @click="promoting = false"
                                class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                            Cancel
                        </button>
                        <button type="submit" :disabled="promoteForm.processing"
                                class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-indigo-700 disabled:opacity-50">
                            {{ promoteForm.processing ? 'Promoting...' : 'Create the UAT cycle' }}
                        </button>
                    </div>
                </form>
            </div>
        </Modal>
    </div>
</template>

<script setup>
import { ref, computed, inject } from 'vue'
import { Link, useForm, router } from '@inertiajs/vue3'
import Modal from '@/Components/Modal.vue'
import InputError from '@/Components/InputError.vue'
import Autocomplete from '@/Components/Autocomplete.vue'
import SignaturePad from '@/Components/SignaturePad.vue'
import { SIGNOFF_CHIPS, SEVERITY_CHIPS, formatDateTime } from '../qatVerdict'

const props = defineProps({
    cycle: { type: Object, required: true },
    findings: { type: Array, default: () => [] },
    signoffs: { type: Array, default: () => [] },
    readiness: { type: Object, default: () => ({}) },
    options: { type: Object, default: () => ({}) },
})

const route = window.route
const can = inject('qatCan', () => false)

const submitting = ref(false)
const promoting = ref(false)

// Declared before any watch that reads them — a watch getter runs immediately and
// would otherwise hit the temporal dead zone and blank the whole page.
const form = useForm({
    result: 'passed',
    remarks: '',
    waived_finding_ids: [],
    waiver_reason: '',
    signature: null,
})

const submitForm = useForm({})
const promoteForm = useForm({ title: '', department_id: null })

const blocking = computed(() => props.readiness?.blocking_findings || [])

const ledger = computed(() => props.signoffs.filter(s => s.stage === 'manager'))
const signoff = computed(() => ledger.value.find(s => s.is_current) || null)

const remarksRequired = computed(() =>
    form.result === 'not_accepted' || form.result === 'passed_with_reservation'
)

const resultLabel = (value) =>
    (props.options?.signoffResults || []).find(r => r.value === value)?.label || value

const stateHeadline = computed(() => {
    if (props.readiness.signed_off) return 'Signed off'
    if (props.readiness.awaiting_approval) return 'Waiting on the manager'
    if (props.cycle.status === 'returned') return 'Returned by the manager'
    if (props.readiness.can_submit) return 'Ready to submit'
    return 'Testing in progress'
})

const stateDetail = computed(() => {
    if (props.readiness.signed_off) return 'A manager has signed this cycle off. It can be promoted to UAT.'
    if (props.readiness.awaiting_approval) return 'The cycle is frozen while it waits for a decision.'
    if (props.cycle.status === 'returned') return 'Act on the remarks below, then submit again.'
    if (props.readiness.can_submit) return 'Every gated case has a verdict. Send it to your manager.'
    return 'Finish the test run before submitting for sign-off.'
})

const stateClass = computed(() => {
    if (props.readiness.signed_off) return 'border-emerald-200 bg-emerald-50 text-emerald-900 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-100'
    if (props.readiness.awaiting_approval) return 'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100'
    if (props.cycle.status === 'returned') return 'border-rose-200 bg-rose-50 text-rose-900 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-100'
    return 'border-gray-200 bg-white text-gray-800 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100'
})

const doSubmit = () => {
    submitForm.post(route('qat.signoff.submit', props.cycle.id), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => { submitting.value = false },
    })
}

const withdraw = () => {
    if (!window.confirm('Withdraw this sign-off request? The cycle reopens for testing.')) return
    router.post(route('qat.signoff.cancel', props.cycle.id), {}, { preserveScroll: true, preserveState: true })
}

const decide = () => {
    form.post(route('qat.signoff.decide', props.cycle.id), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => form.reset(),
    })
}

const reject = () => {
    form.result = 'not_accepted'
    decide()
}

const openPromote = () => {
    promoteForm.title = props.cycle.title
    promoteForm.department_id = props.cycle.department_id ?? null
    promoting.value = true
}

const doPromote = () => {
    promoteForm.post(route('qat.promote', props.cycle.id), { preserveScroll: true })
}
</script>
