<template>
    <Modal :show="show" @close="$emit('close')" maxWidth="2xl">
        <div class="p-6">
            <div class="flex items-start justify-between border-b border-gray-200 pb-4 dark:border-gray-700">
                <div>
                    <h3 class="text-xl font-bold text-gray-900 dark:text-gray-100">
                        {{ finding ? `Edit ${finding.reference}` : 'Log a Finding' }}
                    </h3>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-300">
                        A tracked defect with an owner and a status — not a paragraph in a remarks cell.
                    </p>
                </div>
                <button type="button" @click="$emit('close')" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
                    <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <form @submit.prevent="submit" class="mt-5 space-y-4">
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">Title</label>
                    <input v-model="form.title" type="text" required placeholder="e.g. Pie chart labels overlap and are unreadable"
                           class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                    <InputError :message="errors.title" class="mt-1" />
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">Details</label>
                    <textarea v-model="form.details" rows="4" placeholder="What you did, what happened, what you expected instead."
                              class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"></textarea>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">Related Test Case</label>
                        <Autocomplete v-model="form.uat_case_id" :options="caseOptions" placeholder="Search test case..." />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">Severity</label>
                        <Autocomplete v-model="form.severity" :options="options.severities || []" placeholder="Select severity..." />
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div v-if="finding">
                        <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">Status</label>
                        <Autocomplete v-model="form.status" :options="options.findingStatuses || []" placeholder="Status..." />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">Assign To</label>
                        <Autocomplete v-model="form.assigned_to_user_id" :options="userOptions" placeholder="Search user..." />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">Owning Department</label>
                        <Autocomplete v-model="form.department_id" :options="departmentOptions" placeholder="Department..." />
                    </div>
                </div>

                <div v-if="finding">
                    <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">
                        Resolution Notes
                        <span v-if="form.status === 'closed'" class="text-rose-600">*</span>
                    </label>
                    <textarea v-model="form.resolution_notes" rows="2" placeholder="How it was fixed, or why it is being deferred."
                              class="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"></textarea>
                    <InputError :message="errors.resolution_notes" class="mt-1" />
                </div>

                <div class="flex justify-end gap-3 border-t border-gray-200 pt-4 dark:border-gray-700">
                    <button type="button" @click="$emit('close')"
                            class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                        Cancel
                    </button>
                    <button type="submit" :disabled="saving"
                            class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-blue-700 disabled:opacity-50">
                        {{ saving ? 'Saving...' : (finding ? 'Save Changes' : 'Log Finding') }}
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

const props = defineProps({
    show: Boolean,
    cycle: Object,
    cases: { type: Array, default: () => [] },
    options: { type: Object, default: () => ({}) },
    finding: { type: Object, default: null },
    prefill: { type: Object, default: null },
})

const emit = defineEmits(['close'])

const saving = ref(false)
const errors = ref({})

const form = reactive({
    uat_case_id: null,
    uat_participant_id: null,
    title: '',
    details: '',
    severity: 'minor',
    status: 'open',
    assigned_to_user_id: null,
    department_id: null,
    resolution_notes: '',
})

const caseOptions = computed(() => [
    { label: '— Not tied to a specific case —', value: null },
    ...(props.cases || []).map(c => ({ label: `${c.case_key} — ${c.title}`, value: c.id })),
])

const userOptions = computed(() => [{ label: '—', value: null }, ...(props.options?.users || [])])
const departmentOptions = computed(() => [{ label: '—', value: null }, ...(props.options?.departments || [])])

watch(() => props.show, (open) => {
    if (!open) return

    errors.value = {}

    if (props.finding) {
        Object.assign(form, {
            uat_case_id: props.finding.uat_case_id,
            uat_participant_id: props.finding.uat_participant_id,
            title: props.finding.title,
            details: props.finding.details || '',
            severity: props.finding.severity,
            status: props.finding.status,
            assigned_to_user_id: props.finding.assigned_to_user_id,
            department_id: props.finding.department_id,
            resolution_notes: props.finding.resolution_notes || '',
        })
        return
    }

    Object.assign(form, {
        uat_case_id: props.prefill?.uat_case_id ?? null,
        uat_participant_id: props.prefill?.uat_participant_id ?? null,
        title: props.prefill?.title ?? '',
        details: props.prefill?.details ?? '',
        severity: 'minor',
        status: 'open',
        assigned_to_user_id: null,
        department_id: null,
        resolution_notes: '',
    })
}, { immediate: true })

const submit = () => {
    saving.value = true
    errors.value = {}

    const payload = { ...form }
    const done = {
        preserveScroll: true,
        onSuccess: () => emit('close'),
        onError: (e) => { errors.value = e },
        onFinish: () => { saving.value = false },
    }

    if (props.finding) {
        router.put(`/uat/${props.cycle.id}/findings/${props.finding.id}`, payload, done)
    } else {
        router.post(`/uat/${props.cycle.id}/findings`, payload, done)
    }
}
</script>
