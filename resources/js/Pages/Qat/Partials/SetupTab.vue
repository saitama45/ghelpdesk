<template>
    <div class="space-y-4">
        <!-- Sub-navigation -->
        <div class="flex flex-wrap gap-1 rounded-xl border border-gray-200 bg-white p-1.5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <button v-for="pane in PANES" :key="pane.id" @click="active = pane.id"
                    class="rounded-lg px-4 py-2 text-sm font-semibold transition-colors"
                    :class="active === pane.id
                        ? 'bg-blue-600 text-white'
                        : 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700'">
                {{ pane.label }}
                <span class="ml-1 opacity-70">{{ pane.count }}</span>
            </button>
        </div>

        <!-- Testers -->
        <div v-if="active === 'participants'" class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h3 class="text-base font-bold text-gray-900 dark:text-gray-100">Testers</h3>
                    <p class="mt-0.5 max-w-2xl text-sm text-gray-500 dark:text-gray-300">
                        The matrix shows one column per <span class="font-semibold">department</span>. A department can
                        have both a tester and a reviewer — both answers are kept, and the reviewer's is the one that
                        counts. This is the test roster only; the manager who signs the cycle off is worked out from the
                        org chart, not listed here.
                    </p>
                </div>
                <button @click="openParticipant()" class="rounded-lg bg-blue-600 px-3 py-2 text-sm font-semibold text-white hover:bg-blue-700">
                    Add tester
                </button>
            </div>

            <p v-if="!participants.length" class="mt-5 text-sm italic text-gray-400">Nobody added yet.</p>

            <div v-else class="mt-4 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-xs uppercase tracking-wider text-gray-400 dark:border-gray-700">
                            <th class="py-2 pr-4">Column</th>
                            <th class="py-2 pr-4">Person</th>
                            <th class="py-2 pr-4">Role</th>
                            <th class="py-2 pr-4">Active</th>
                            <th class="py-2 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="p in participants" :key="p.id" class="border-b border-gray-100 dark:border-gray-700/60">
                            <td class="py-2 pr-4 font-semibold text-gray-800 dark:text-gray-100">{{ p.label }}</td>
                            <td class="py-2 pr-4 text-gray-600 dark:text-gray-300">{{ p.display_name }}</td>
                            <td class="py-2 pr-4">
                                <span class="rounded-full px-2 py-0.5 text-[10px] font-bold uppercase"
                                      :class="p.role === 'reviewer'
                                          ? 'bg-blue-100 text-blue-700 dark:bg-blue-500/20 dark:text-blue-200'
                                          : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300'">
                                    {{ p.role }}
                                </span>
                            </td>
                            <td class="py-2 pr-4">{{ p.is_active ? 'Yes' : 'No' }}</td>
                            <td class="py-2 text-right">
                                <QatIconBtn kind="edit" title="Edit" @click="openParticipant(p)" />
                                <QatIconBtn kind="delete" title="Remove" @click="destroyParticipant(p)" />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Sections -->
        <div v-if="active === 'sections'" class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h3 class="text-base font-bold text-gray-900 dark:text-gray-100">Sections</h3>
                    <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-300">
                        Functional areas that group the test cases. A non-critical section's cases do not block the sign-off gate.
                    </p>
                </div>
                <button @click="openSection()" class="rounded-lg bg-blue-600 px-3 py-2 text-sm font-semibold text-white hover:bg-blue-700">
                    Add section
                </button>
            </div>

            <p v-if="!sections.length" class="mt-5 text-sm italic text-gray-400">No sections — every case sits in one ungrouped list.</p>

            <ul v-else class="mt-4 divide-y divide-gray-100 dark:divide-gray-700/60">
                <li v-for="s in sections" :key="s.id" class="flex items-center justify-between gap-3 py-2">
                    <div>
                        <span class="font-semibold text-gray-800 dark:text-gray-100">{{ s.name }}</span>
                        <span v-if="!s.is_critical" class="ml-2 rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-slate-600 dark:bg-slate-700 dark:text-slate-200">
                            non-critical
                        </span>
                        <p v-if="s.description" class="text-xs text-gray-500 dark:text-gray-400">{{ s.description }}</p>
                    </div>
                    <div class="shrink-0">
                        <QatIconBtn kind="edit" title="Edit" @click="openSection(s)" />
                        <QatIconBtn kind="delete" title="Remove" @click="destroySection(s)" />
                    </div>
                </li>
            </ul>
        </div>

        <!-- Cases -->
        <div v-if="active === 'cases'" class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h3 class="text-base font-bold text-gray-900 dark:text-gray-100">Test cases</h3>
                    <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-300">
                        Leave the key blank and the next one in the cycle's own sequence is allocated.
                    </p>
                </div>
                <button @click="openCase()" class="rounded-lg bg-blue-600 px-3 py-2 text-sm font-semibold text-white hover:bg-blue-700">
                    Add test case
                </button>
            </div>

            <p v-if="!cases.length" class="mt-5 text-sm italic text-gray-400">
                No test cases yet. Add them here, or import a workbook from the header.
            </p>

            <div v-else class="mt-4 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-xs uppercase tracking-wider text-gray-400 dark:border-gray-700">
                            <th class="py-2 pr-4">Key</th>
                            <th class="py-2 pr-4">Title</th>
                            <th class="py-2 pr-4">Section</th>
                            <th class="py-2 pr-4">Priority</th>
                            <th class="py-2 pr-4">Critical</th>
                            <th class="py-2 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="c in cases" :key="c.id" class="border-b border-gray-100 dark:border-gray-700/60">
                            <td class="py-2 pr-4 font-mono text-xs text-gray-500 dark:text-gray-400">{{ c.case_key }}</td>
                            <td class="py-2 pr-4 text-gray-800 dark:text-gray-100">{{ c.title }}</td>
                            <td class="py-2 pr-4 text-gray-600 dark:text-gray-300">{{ sectionName(c.qat_section_id) }}</td>
                            <td class="py-2 pr-4 text-gray-600 dark:text-gray-300">{{ c.priority }}</td>
                            <td class="py-2 pr-4">{{ c.is_critical ? 'Yes' : 'No' }}</td>
                            <td class="py-2 text-right">
                                <QatIconBtn kind="edit" title="Edit" @click="openCase(c)" />
                                <QatIconBtn kind="delete" title="Delete" @click="destroyCase(c)" />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Participant modal -->
        <Modal :show="participantModal" @close="participantModal = false" maxWidth="lg">
            <form @submit.prevent="saveParticipant" class="p-6">
                <h3 class="text-xl font-bold text-gray-900 dark:text-gray-100">
                    {{ editingParticipant ? 'Edit tester' : 'Add tester' }}
                </h3>

                <div class="mt-5 space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">Department</label>
                        <Autocomplete v-model="pForm.department_id" :options="options.departments || []"
                                      placeholder="Select department..." @update:modelValue="syncLabel" />
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Sets the matrix column heading.</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">Column heading <span class="text-rose-600">*</span></label>
                        <input v-model="pForm.label" type="text" required maxlength="80"
                               class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-slate-900 dark:text-gray-100">
                        <InputError :message="pForm.errors.label" class="mt-1" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">Person</label>
                        <Autocomplete v-model="pForm.user_id" :options="options.users || []" placeholder="Search user..." />
                    </div>
                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">Role</label>
                            <Autocomplete v-model="pForm.role" :options="options.participantRoles || []" placeholder="Select role..." />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">Kind</label>
                            <Autocomplete v-model="pForm.kind" :options="options.participantKinds || []" placeholder="Select kind..." />
                        </div>
                    </div>
                    <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                        <input type="checkbox" v-model="pForm.is_active" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                        Active
                    </label>
                </div>

                <div class="mt-5 flex justify-end gap-3 border-t border-gray-200 pt-4 dark:border-gray-700">
                    <button type="button" @click="participantModal = false" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">Cancel</button>
                    <button type="submit" :disabled="pForm.processing" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50">Save</button>
                </div>
            </form>
        </Modal>

        <!-- Section modal -->
        <Modal :show="sectionModal" @close="sectionModal = false" maxWidth="lg">
            <form @submit.prevent="saveSection" class="p-6">
                <h3 class="text-xl font-bold text-gray-900 dark:text-gray-100">{{ editingSection ? 'Edit section' : 'Add section' }}</h3>
                <div class="mt-5 space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">Name <span class="text-rose-600">*</span></label>
                        <input v-model="sForm.name" type="text" required maxlength="255"
                               class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-slate-900 dark:text-gray-100">
                        <InputError :message="sForm.errors.name" class="mt-1" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">Description</label>
                        <input v-model="sForm.description" type="text" maxlength="255"
                               class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-slate-900 dark:text-gray-100">
                    </div>
                    <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                        <input type="checkbox" v-model="sForm.is_critical" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                        Critical for sign-off
                    </label>
                </div>
                <div class="mt-5 flex justify-end gap-3 border-t border-gray-200 pt-4 dark:border-gray-700">
                    <button type="button" @click="sectionModal = false" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">Cancel</button>
                    <button type="submit" :disabled="sForm.processing" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50">Save</button>
                </div>
            </form>
        </Modal>

        <!-- Case modal -->
        <Modal :show="caseModal" @close="caseModal = false" maxWidth="2xl">
            <form @submit.prevent="saveCase" class="p-6">
                <h3 class="text-xl font-bold text-gray-900 dark:text-gray-100">{{ editingCase ? 'Edit test case' : 'Add test case' }}</h3>
                <div class="mt-5 space-y-4">
                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">Case key</label>
                            <input v-model="cForm.case_key" type="text" maxlength="40" placeholder="Auto"
                                   class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-slate-900 dark:text-gray-100">
                            <InputError :message="cForm.errors.case_key" class="mt-1" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">Section</label>
                            <Autocomplete v-model="cForm.qat_section_id" :options="sectionOptions" placeholder="Ungrouped" />
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">Title <span class="text-rose-600">*</span></label>
                        <input v-model="cForm.title" type="text" required maxlength="255"
                               class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-slate-900 dark:text-gray-100">
                        <InputError :message="cForm.errors.title" class="mt-1" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">Screen</label>
                        <input v-model="cForm.screen" type="text" maxlength="255"
                               class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-slate-900 dark:text-gray-100">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">Test steps</label>
                        <textarea v-model="cForm.steps" rows="4" placeholder="One step per line."
                                  class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-slate-900 dark:text-gray-100"></textarea>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">Expected results</label>
                        <textarea v-model="cForm.expected_results" rows="3" placeholder="One expectation per line."
                                  class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-slate-900 dark:text-gray-100"></textarea>
                    </div>
                    <div class="grid items-end gap-4 md:grid-cols-2">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">Priority</label>
                            <Autocomplete v-model="cForm.priority" :options="options.priorities || []" placeholder="Select priority..." />
                        </div>
                        <label class="flex items-center gap-2 pb-2 text-sm text-gray-700 dark:text-gray-200">
                            <input type="checkbox" v-model="cForm.is_critical" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                            Critical — blocks sign-off until it passes
                        </label>
                    </div>
                </div>
                <div class="mt-5 flex justify-end gap-3 border-t border-gray-200 pt-4 dark:border-gray-700">
                    <button type="button" @click="caseModal = false" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">Cancel</button>
                    <button type="submit" :disabled="cForm.processing" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50">Save</button>
                </div>
            </form>
        </Modal>
    </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import Modal from '@/Components/Modal.vue'
import InputError from '@/Components/InputError.vue'
import Autocomplete from '@/Components/Autocomplete.vue'
import QatIconBtn from './QatIconBtn.vue'

const props = defineProps({
    cycle: { type: Object, required: true },
    sections: { type: Array, default: () => [] },
    cases: { type: Array, default: () => [] },
    participants: { type: Array, default: () => [] },
    options: { type: Object, default: () => ({}) },
})

const route = window.route

const active = ref('participants')

const participantModal = ref(false)
const sectionModal = ref(false)
const caseModal = ref(false)
const editingParticipant = ref(null)
const editingSection = ref(null)
const editingCase = ref(null)

const pForm = useForm({ kind: 'department', label: '', department_id: null, company_id: null, user_id: null, contact_name: '', contact_email: '', role: 'tester', is_active: true })
const sForm = useForm({ name: '', description: '', is_critical: true })
const cForm = useForm({ qat_section_id: null, case_key: '', screen: '', title: '', description: '', steps: '', expected_results: '', is_critical: true, priority: 'medium' })

const PANES = computed(() => [
    { id: 'participants', label: 'Testers', count: props.participants.length },
    { id: 'sections', label: 'Sections', count: props.sections.length },
    { id: 'cases', label: 'Test cases', count: props.cases.length },
])

const sectionOptions = computed(() => props.sections.map(s => ({ label: s.name, value: s.id })))
const sectionName = (id) => props.sections.find(s => s.id === id)?.name || '—'

/** Picking a department fills the column heading with its code, as on the matrix. */
const syncLabel = (departmentId) => {
    if (pForm.label) return
    const match = (props.options.departments || []).find(d => d.value === departmentId)
    if (match) pForm.label = match.code || match.name
}

const openParticipant = (participant = null) => {
    editingParticipant.value = participant
    pForm.clearErrors()
    pForm.kind = participant?.kind ?? 'department'
    pForm.label = participant?.label ?? ''
    pForm.department_id = participant?.department_id ?? null
    pForm.company_id = participant?.company_id ?? props.cycle.company_id ?? null
    pForm.user_id = participant?.user_id ?? null
    pForm.contact_name = participant?.contact_name ?? ''
    pForm.contact_email = participant?.contact_email ?? ''
    pForm.role = participant?.role ?? 'tester'
    pForm.is_active = participant?.is_active ?? true
    participantModal.value = true
}

const saveParticipant = () => {
    const options = { preserveScroll: true, preserveState: true, onSuccess: () => { participantModal.value = false } }
    if (editingParticipant.value) {
        pForm.put(route('qat.participants.update', [props.cycle.id, editingParticipant.value.id]), options)
    } else {
        pForm.post(route('qat.participants.store', props.cycle.id), options)
    }
}

const destroyParticipant = (participant) => {
    if (!window.confirm(`Remove ${participant.label}? Their verdicts go with them.`)) return
    router.delete(route('qat.participants.destroy', [props.cycle.id, participant.id]), { preserveScroll: true, preserveState: true })
}

const openSection = (section = null) => {
    editingSection.value = section
    sForm.clearErrors()
    sForm.name = section?.name ?? ''
    sForm.description = section?.description ?? ''
    sForm.is_critical = section?.is_critical ?? true
    sectionModal.value = true
}

const saveSection = () => {
    const options = { preserveScroll: true, preserveState: true, onSuccess: () => { sectionModal.value = false } }
    if (editingSection.value) {
        sForm.put(route('qat.sections.update', [props.cycle.id, editingSection.value.id]), options)
    } else {
        sForm.post(route('qat.sections.store', props.cycle.id), options)
    }
}

const destroySection = (section) => {
    if (!window.confirm(`Remove ${section.name}? Its test cases are kept and ungrouped.`)) return
    router.delete(route('qat.sections.destroy', [props.cycle.id, section.id]), { preserveScroll: true, preserveState: true })
}

const openCase = (testCase = null) => {
    editingCase.value = testCase
    cForm.clearErrors()
    cForm.qat_section_id = testCase?.qat_section_id ?? null
    cForm.case_key = testCase?.case_key ?? ''
    cForm.screen = testCase?.screen ?? ''
    cForm.title = testCase?.title ?? ''
    cForm.is_critical = testCase?.is_critical ?? true
    cForm.priority = testCase?.priority ?? 'medium'

    // The listing omits the nvarchar(MAX) columns, so an edit must fetch the full
    // record — posting the partial row back would silently blank steps and
    // expected results.
    cForm.description = ''
    cForm.steps = ''
    cForm.expected_results = ''

    caseModal.value = true

    if (testCase) {
        fetch(route('qat.cases.show', [props.cycle.id, testCase.id]), { headers: { Accept: 'application/json' } })
            .then(r => r.ok ? r.json() : null)
            .then(data => {
                if (!data?.case) return
                cForm.description = data.case.description ?? ''
                cForm.steps = data.case.steps ?? ''
                cForm.expected_results = data.case.expected_results ?? ''
            })
    }
}

const saveCase = () => {
    const options = { preserveScroll: true, preserveState: true, onSuccess: () => { caseModal.value = false } }
    if (editingCase.value) {
        cForm.put(route('qat.cases.update', [props.cycle.id, editingCase.value.id]), options)
    } else {
        cForm.post(route('qat.cases.store', props.cycle.id), options)
    }
}

const destroyCase = (testCase) => {
    if (!window.confirm(`Delete ${testCase.case_key}? Its verdicts go with it.`)) return
    router.delete(route('qat.cases.destroy', [props.cycle.id, testCase.id]), { preserveScroll: true, preserveState: true })
}
</script>
