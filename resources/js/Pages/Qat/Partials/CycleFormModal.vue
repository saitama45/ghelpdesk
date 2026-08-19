<template>
    <Modal :show="show" @close="$emit('close')" maxWidth="2xl">
        <form @submit.prevent="submit" class="p-6">
            <h3 class="text-xl font-bold text-gray-900 dark:text-gray-100">
                {{ cycle ? 'Edit QAT cycle' : 'New QAT cycle' }}
            </h3>
            <p v-if="loading" class="mt-1 text-sm text-gray-400">Loading the full record...</p>

            <div class="mt-5 space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">Title <span class="text-rose-600">*</span></label>
                    <input v-model="form.title" type="text" required maxlength="255"
                           class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-slate-900 dark:text-gray-100">
                    <InputError :message="form.errors.title" class="mt-1" />
                </div>

                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">System under test</label>
                        <input v-model="form.system_name" type="text" maxlength="255"
                               class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-slate-900 dark:text-gray-100">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">Round</label>
                        <input v-model.number="form.cycle_no" type="number" min="1" max="99"
                               class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-slate-900 dark:text-gray-100">
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">Description</label>
                    <textarea v-model="form.description" rows="2"
                              class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-slate-900 dark:text-gray-100"></textarea>
                </div>

                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">Environment</label>
                        <Autocomplete v-model="form.environment" :options="options.environments || []" placeholder="Select environment..." />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">Status</label>
                        <Autocomplete v-model="form.status" :options="options.statuses || []" placeholder="Select status..." />
                    </div>
                </div>

                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">Entity</label>
                        <Autocomplete v-model="form.company_id" :options="options.companies || []" placeholder="Select entity..." />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">Owning department</label>
                        <Autocomplete v-model="form.department_id" :options="options.departments || []" placeholder="Shared across departments" />
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            Controls who can reach this cycle. Left blank it is shared.
                        </p>
                    </div>
                </div>

                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">QA lead</label>
                        <Autocomplete v-model="form.qa_lead_id" :options="options.users || []" placeholder="Search user..." />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">Dev lead</label>
                        <Autocomplete v-model="form.dev_lead_id" :options="options.users || []" placeholder="Search user..." />
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">Related UAT cycle</label>
                    <Autocomplete v-model="form.uat_cycle_id" :options="options.uatCycles || []" placeholder="None" />
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        Optional. Promoting this cycle sets the link automatically.
                    </p>
                </div>

                <div class="grid gap-4 md:grid-cols-3">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">Start date</label>
                        <input v-model="form.start_date" type="date"
                               class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-slate-900 dark:text-gray-100">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">Target sign-off</label>
                        <input v-model="form.target_signoff_date" type="date"
                               class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-slate-900 dark:text-gray-100">
                        <InputError :message="form.errors.target_signoff_date" class="mt-1" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">Go live</label>
                        <input v-model="form.go_live_date" type="date"
                               class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-slate-900 dark:text-gray-100">
                    </div>
                </div>

                <label class="flex items-start gap-2 text-sm text-gray-700 dark:text-gray-200">
                    <input type="checkbox" v-model="form.gate_on_critical_only" class="mt-0.5 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                    <span>
                        Gate on critical cases only
                        <span class="block text-xs text-gray-500 dark:text-gray-400">
                            Non-critical cases are still reported, but do not hold up the sign-off.
                        </span>
                    </span>
                </label>
            </div>

            <div class="mt-5 flex justify-end gap-3 border-t border-gray-200 pt-4 dark:border-gray-700">
                <button type="button" @click="$emit('close')"
                        class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                    Cancel
                </button>
                <button type="submit" :disabled="form.processing || loading"
                        class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-blue-700 disabled:opacity-50">
                    {{ form.processing ? 'Saving...' : 'Save' }}
                </button>
            </div>
        </form>
    </Modal>
</template>

<script setup>
import { ref, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import Modal from '@/Components/Modal.vue'
import InputError from '@/Components/InputError.vue'
import Autocomplete from '@/Components/Autocomplete.vue'

const props = defineProps({
    show: { type: Boolean, default: false },
    cycle: { type: Object, default: null },
    options: { type: Object, default: () => ({}) },
})

const emit = defineEmits(['close'])

const route = window.route
const loading = ref(false)

const form = useForm({
    title: '',
    system_name: '',
    description: '',
    cycle_no: 1,
    environment: 'Web',
    company_id: null,
    department_id: null,
    qa_lead_id: null,
    dev_lead_id: null,
    uat_cycle_id: null,
    status: 'draft',
    start_date: '',
    target_signoff_date: '',
    go_live_date: '',
    gate_on_critical_only: true,
})

const blank = () => {
    form.reset()
    form.clearErrors()
}

/**
 * An edit always re-fetches the whole record.
 *
 * The index listing selects a narrow column set (no description, no links), and
 * handing that partial row to this form meant the missing fields posted back
 * blank and silently wiped what was stored.
 */
watch(() => props.show, async (open) => {
    if (!open) return

    blank()

    if (!props.cycle) return

    loading.value = true
    try {
        const response = await fetch(route('qat.edit-data', props.cycle.id), { headers: { Accept: 'application/json' } })
        if (!response.ok) return
        const { cycle } = await response.json()

        for (const key of Object.keys(form.data())) {
            if (cycle[key] !== undefined) form[key] = cycle[key] ?? (typeof form[key] === 'string' ? '' : null)
        }
        form.gate_on_critical_only = !!cycle.gate_on_critical_only
    } finally {
        loading.value = false
    }
})

const submit = () => {
    const options = { preserveScroll: true, preserveState: true, onSuccess: () => emit('close') }

    if (props.cycle) {
        form.put(route('qat.update', props.cycle.id), options)
    } else {
        form.post(route('qat.store'), options)
    }
}
</script>
