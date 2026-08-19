<template>
    <Modal :show="show" @close="close" maxWidth="2xl">
        <div class="p-6">
            <h3 class="text-xl font-bold text-gray-900 dark:text-gray-100">
                {{ finding ? `Edit ${finding.reference}` : 'Log a finding' }}
            </h3>
            <p v-if="testCase" class="mt-0.5 text-sm text-gray-500 dark:text-gray-300">
                Against <span class="font-mono text-xs">{{ testCase.case_key }}</span> {{ testCase.title }}
            </p>

            <form @submit.prevent="submit" class="mt-5 space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">What is wrong? <span class="text-rose-600">*</span></label>
                    <input v-model="form.title" type="text" required maxlength="255"
                           placeholder="e.g. Saving a department order clears the effective date"
                           class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-slate-900 dark:text-gray-100">
                    <InputError :message="form.errors.title" class="mt-1" />
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">Details</label>
                    <textarea v-model="form.details" rows="3"
                              placeholder="What you did, what happened, what you expected instead."
                              class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-slate-900 dark:text-gray-100"></textarea>
                </div>

                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">Severity <span class="text-rose-600">*</span></label>
                        <Autocomplete v-model="form.severity" :options="options.severities || []" placeholder="Select severity..." />
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            Blocker and major both block the manager's sign-off until fixed or waived.
                        </p>
                    </div>
                    <div v-if="finding">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">Status</label>
                        <Autocomplete v-model="form.status" :options="options.findingStatuses || []" placeholder="Select status..." />
                    </div>
                </div>

                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">Assign to</label>
                        <Autocomplete v-model="form.assigned_to_user_id" :options="options.users || []" placeholder="Search user..." />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">Owning department</label>
                        <Autocomplete v-model="form.department_id" :options="options.departments || []" placeholder="Select department..." />
                    </div>
                </div>

                <div v-if="finding && ['closed', 'deferred'].includes(form.status)">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">
                        Resolution notes <span class="text-rose-600">*</span>
                    </label>
                    <textarea v-model="form.resolution_notes" rows="2"
                              class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-slate-900 dark:text-gray-100"></textarea>
                    <InputError :message="form.errors.resolution_notes" class="mt-1" />
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">
                        Screenshots <span v-if="!finding" class="text-rose-600">*</span>
                    </label>
                    <input ref="fileInput" type="file" multiple accept="image/*" @change="pick"
                           class="mt-1 block w-full text-sm text-gray-600 file:mr-3 file:rounded-lg file:border-0 file:bg-blue-600 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-white hover:file:bg-blue-700 dark:text-gray-300">
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        A finding needs at least one picture. If you already attached one to this case's verdict it is
                        carried over automatically, so you may not need to add anything here.
                        Anything over {{ MAX_UPLOAD_MB }} MB is shrunk in your browser, not rejected.
                    </p>
                    <p v-if="compressing" class="mt-1 text-xs font-semibold text-blue-600 dark:text-blue-300">Compressing images...</p>
                    <p v-for="(note, i) in compressionNotes" :key="i" class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ note }}</p>
                    <InputError :message="form.errors.screenshots" class="mt-1" />
                </div>

                <div class="flex justify-end gap-3 border-t border-gray-200 pt-4 dark:border-gray-700">
                    <button type="button" @click="close"
                            class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                        Cancel
                    </button>
                    <button type="submit" :disabled="form.processing || compressing"
                            class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-blue-700 disabled:opacity-50">
                        {{ form.processing ? 'Saving...' : (finding ? 'Save' : 'Log finding') }}
                    </button>
                </div>
            </form>
        </div>
    </Modal>
</template>

<script setup>
import { ref, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import Modal from '@/Components/Modal.vue'
import InputError from '@/Components/InputError.vue'
import Autocomplete from '@/Components/Autocomplete.vue'
import { compressImages, MAX_UPLOAD_MB } from '@/Composables/useImageCompressor.js'

const props = defineProps({
    show: { type: Boolean, default: false },
    cycle: { type: Object, required: true },
    testCase: { type: Object, default: null },
    finding: { type: Object, default: null },
    participantId: { type: [Number, String], default: null },
    options: { type: Object, default: () => ({}) },
})

const emit = defineEmits(['close'])

const route = window.route

const fileInput = ref(null)
const compressing = ref(false)
const compressionNotes = ref([])

const form = useForm({
    qat_case_id: null,
    qat_participant_id: null,
    title: '',
    details: '',
    severity: 'minor',
    status: 'open',
    assigned_to_user_id: null,
    department_id: null,
    resolution_notes: '',
    screenshots: [],
})

watch(() => props.show, (open) => {
    if (!open) return

    form.clearErrors()
    form.qat_case_id = props.finding?.qat_case_id ?? props.testCase?.id ?? null
    form.qat_participant_id = props.finding?.qat_participant_id ?? props.participantId ?? null
    form.title = props.finding?.title ?? ''
    form.details = props.finding?.details ?? ''
    form.severity = props.finding?.severity ?? 'minor'
    form.status = props.finding?.status ?? 'open'
    form.assigned_to_user_id = props.finding?.assigned_to_user_id ?? null
    form.department_id = props.finding?.department_id ?? props.cycle.department_id ?? null
    form.resolution_notes = props.finding?.resolution_notes ?? ''
    form.screenshots = []
    compressionNotes.value = []
    if (fileInput.value) fileInput.value.value = ''
})

/**
 * Oversized images are shrunk in the browser, never rejected: an over-limit POST
 * dies at PHP's upload cap before Laravel validation ever runs, so the user would
 * just see a broken page instead of a message.
 */
const pick = async (event) => {
    if (!event.target.files?.length) return

    compressing.value = true
    try {
        const { files, notes } = await compressImages(event.target.files)
        form.screenshots = files
        compressionNotes.value = notes || []
    } finally {
        compressing.value = false
    }
}

const close = () => emit('close')

const submit = () => {
    const options = {
        forceFormData: true,
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => close(),
    }

    if (props.finding) {
        // Inertia cannot send files on a PUT, so the method is spoofed.
        form.transform(data => ({ ...data, _method: 'put' }))
            .post(route('qat.findings.update', [props.cycle.id, props.finding.id]), options)
    } else {
        form.post(route('qat.findings.store', props.cycle.id), options)
    }
}
</script>
