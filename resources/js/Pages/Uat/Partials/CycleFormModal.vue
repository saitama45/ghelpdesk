<template>
    <Modal :show="show" @close="$emit('close')" maxWidth="3xl">
        <div class="p-6">
            <div class="flex items-start justify-between border-b border-gray-200 pb-4 dark:border-gray-700">
                <div>
                    <h3 class="text-xl font-bold text-gray-900 dark:text-gray-100">
                        {{ isEdit ? `Edit ${cycle.code}` : 'New UAT Cycle' }}
                    </h3>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-300">
                        {{ isEdit
                            ? 'Correct anything here at any time. Test cases, verdicts and sign-off records are untouched.'
                            : 'A cycle is one round of acceptance testing. Add the roster and test cases once it exists — or import them from an existing workbook.' }}
                    </p>
                </div>
                <button type="button" @click="$emit('close')" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
                    <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <form @submit.prevent="submit" class="mt-6 space-y-5">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">Cycle Title</label>
                        <input v-model="form.title" type="text" required placeholder="e.g. System Testing of Planning Website"
                               class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                        <InputError :message="errors.title" class="mt-1" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">Round #</label>
                        <input v-model.number="form.cycle_no" type="number" min="1" max="99" required
                               class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                        <InputError :message="errors.cycle_no" class="mt-1" />
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">System Under Test</label>
                        <input v-model="form.system_name" type="text" placeholder="e.g. Planning Service Website"
                               class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">Environment</label>
                        <Autocomplete v-model="form.environment" :options="options.environments || []" placeholder="Select environment..." />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">Status</label>
                        <Autocomplete v-model="form.status" :options="options.statuses || []" placeholder="Select status..." />
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">Entity</label>
                        <Autocomplete v-model="form.company_id" :options="companyOptions" placeholder="Select entity..." />
                        <p class="mt-1 text-xs text-gray-400">Controls which entity's list this cycle appears under.</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">Owning Department</label>
                        <Autocomplete v-model="form.department_id" :options="departmentOptions" placeholder="Select department..." />
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">Assigned QA</label>
                        <Autocomplete v-model="form.qa_lead_id" :options="userOptions" placeholder="Search user..." />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">Assigned Dev</label>
                        <Autocomplete v-model="form.dev_lead_id" :options="userOptions" placeholder="Search user..." />
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">Start Date</label>
                        <input v-model="form.start_date" type="date"
                               class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">Target Sign-off</label>
                        <input v-model="form.target_signoff_date" type="date"
                               class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                        <InputError :message="errors.target_signoff_date" class="mt-1" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">Go-Live</label>
                        <input v-model="form.go_live_date" type="date"
                               class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                    </div>
                </div>

                <div>
                    <div class="mb-1 flex items-center justify-between">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">Environment Links</label>
                        <button type="button" @click="form.links.push({ label: '', url: '' })"
                                class="text-xs font-semibold text-blue-600 hover:underline dark:text-blue-300">+ Add link</button>
                    </div>
                    <div v-for="(link, index) in form.links" :key="index" class="mb-2 flex items-center gap-2">
                        <input v-model="link.label" type="text" placeholder="Label (e.g. Front-end)"
                               class="w-1/3 rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                        <input v-model="link.url" type="url" placeholder="https://..."
                               class="flex-1 rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                        <button type="button" @click="form.links.splice(index, 1)" title="Remove link"
                                class="rounded-full p-2 text-red-600 transition-colors hover:bg-red-50 hover:text-red-900 dark:hover:bg-red-900/30">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                    <p v-if="!form.links.length" class="text-xs text-gray-400 dark:text-gray-500">
                        The URLs testers should open — they appear at the top of every tester's screen.
                    </p>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">Description</label>
                    <textarea v-model="form.description" rows="2" placeholder="Scope of this round, what changed since the last cycle..."
                              class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"></textarea>
                </div>

                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                        <input v-model="form.signoff_requires_all" type="checkbox" class="mt-0.5 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                        <span>
                            <span class="block text-sm font-medium text-gray-800 dark:text-gray-100">Require every approver</span>
                            <span class="block text-xs text-gray-500 dark:text-gray-400">Final sign-off stays locked until all nominated approvers accept.</span>
                        </span>
                    </label>
                    <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                        <input v-model="form.gate_on_critical_only" type="checkbox" class="mt-0.5 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                        <span>
                            <span class="block text-sm font-medium text-gray-800 dark:text-gray-100">Gate on critical items only</span>
                            <span class="block text-xs text-gray-500 dark:text-gray-400">Non-critical cases are reported but never block go-live.</span>
                        </span>
                    </label>
                </div>

                <div class="flex justify-end gap-3 border-t border-gray-200 pt-4 dark:border-gray-700">
                    <button type="button" @click="$emit('close')"
                            class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                        Cancel
                    </button>
                    <button type="submit" :disabled="processing || loading"
                            class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-blue-700 disabled:opacity-50">
                        {{ loading ? 'Loading...' : (processing ? 'Saving...' : (isEdit ? 'Save Changes' : 'Create Cycle')) }}
                    </button>
                </div>
            </form>
        </div>
    </Modal>
</template>

<script setup>
import { ref, reactive, computed, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import Modal from '@/Components/Modal.vue'
import Autocomplete from '@/Components/Autocomplete.vue'
import InputError from '@/Components/InputError.vue'

/**
 * The cycle header form, shared by create (index) and edit (index row + detail
 * page). Kept in one place so the two never drift apart.
 */
const props = defineProps({
    show: Boolean,
    // null = create mode
    cycle: { type: Object, default: null },
    options: { type: Object, default: () => ({}) },
    // Pre-selected entity when creating, so a new cycle lands where you can see it.
    defaultCompanyId: { type: Number, default: null },
})

const emit = defineEmits(['close'])

const processing = ref(false)
const errors = ref({})

const isEdit = computed(() => Boolean(props.cycle?.id))

const blank = () => ({
    title: '', system_name: '', description: '', cycle_no: 1, environment: 'Web', links: [],
    company_id: props.defaultCompanyId ?? null, department_id: null, qa_lead_id: null, dev_lead_id: null,
    status: 'draft', start_date: '', target_signoff_date: '', go_live_date: '',
    signoff_requires_all: true, gate_on_critical_only: true,
})

const form = reactive(blank())

const companyOptions = computed(() => [{ label: '—', value: null }, ...(props.options?.companies || [])])
const departmentOptions = computed(() => [{ label: '—', value: null }, ...(props.options?.departments || [])])
const userOptions = computed(() => [{ label: '—', value: null }, ...(props.options?.users || [])])

/** Dates arrive as ISO strings; <input type="date"> needs bare yyyy-mm-dd. */
const asDate = (value) => (value ? String(value).split('T')[0] : '')

const loading = ref(false)

const fill = (source) => {
    Object.assign(form, {
        title: source.title ?? '',
        system_name: source.system_name ?? '',
        description: source.description ?? '',
        cycle_no: source.cycle_no ?? 1,
        environment: source.environment ?? 'Web',
        links: Array.isArray(source.links) ? source.links.map(l => ({ ...l })) : [],
        company_id: source.company_id ?? null,
        department_id: source.department_id ?? null,
        qa_lead_id: source.qa_lead_id ?? null,
        dev_lead_id: source.dev_lead_id ?? null,
        status: source.status ?? 'draft',
        start_date: asDate(source.start_date),
        target_signoff_date: asDate(source.target_signoff_date),
        go_live_date: asDate(source.go_live_date),
        signoff_requires_all: source.signoff_requires_all ?? true,
        gate_on_critical_only: source.gate_on_critical_only ?? true,
    })
}

watch(() => [props.show, props.cycle?.id], async ([open]) => {
    if (!open) return
    errors.value = {}

    if (!props.cycle) {
        Object.assign(form, blank())
        return
    }

    // Seed from whatever the caller has so the dialog is never blank, then
    // replace it with the authoritative record. The index listing ships a
    // partial row (no description, links, dev lead or gate flags), and saving
    // that back used to blank those columns.
    fill(props.cycle)

    loading.value = true
    try {
        const response = await fetch(`/uat/${props.cycle.id}/edit-data`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        })
        if (response.ok) {
            const payload = await response.json()
            if (payload?.cycle) fill(payload.cycle)
        }
    } catch {
        // Keep the seeded values; the user can still correct them by hand.
    } finally {
        loading.value = false
    }
}, { immediate: true })

const submit = () => {
    processing.value = true
    errors.value = {}

    const payload = {
        ...form,
        // Empty rows would fail the url rule on an empty string.
        links: form.links.filter(l => l.url),
        start_date: form.start_date || null,
        target_signoff_date: form.target_signoff_date || null,
        go_live_date: form.go_live_date || null,
    }

    const done = {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => emit('close'),
        onError: (e) => { errors.value = e },
        onFinish: () => { processing.value = false },
    }

    if (isEdit.value) {
        router.put(`/uat/${props.cycle.id}`, payload, done)
    } else {
        router.post('/uat', payload, done)
    }
}
</script>
