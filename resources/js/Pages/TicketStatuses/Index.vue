<template>
    <AppLayout title="Ticket Statuses" content-class="w-full max-w-none px-2 sm:px-4 lg:px-6">
        <div class="py-8">
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 dark:bg-gray-800 dark:border-gray-700">
                <div class="px-6 py-5 border-b border-gray-200 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between dark:border-gray-700">
                    <div>
                        <h2 class="text-lg font-bold text-gray-900 dark:text-gray-100">Ticket Statuses</h2>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            Add or rename statuses, then choose which ones each department of this entity can pick on its tickets.
                            A hidden status disappears from that department's status pickers and filters; tickets already in it keep it.
                            Open, Resolved and Closed start and end every ticket, so they are always shown.
                        </p>
                    </div>
                    <button
                        v-if="can.create"
                        type="button"
                        @click="openCreate"
                        class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors shadow-sm whitespace-nowrap inline-flex items-center gap-2 shrink-0"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" /></svg>
                        <span>New Status</span>
                    </button>
                </div>

                <div v-if="!departments.length" class="px-6 py-12 text-center text-sm text-gray-500 dark:text-gray-400">
                    This entity has no service departments. Switch to an entity that has departments to configure its statuses.
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-900/50">
                            <tr>
                                <th class="sticky left-0 z-10 bg-gray-50 px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider dark:bg-gray-900 dark:text-gray-300">Status</th>
                                <th
                                    v-for="department in departments"
                                    :key="department.id"
                                    class="px-4 py-3 text-center text-xs font-bold text-gray-500 uppercase tracking-wider whitespace-nowrap dark:text-gray-300"
                                    :title="department.name"
                                >
                                    <div>{{ department.code || department.name }}</div>
                                    <div v-if="department.code" class="mt-0.5 text-[10px] font-medium normal-case tracking-normal text-gray-400">{{ department.name }}</div>
                                    <div v-if="!department.is_active" class="mt-0.5 text-[10px] font-medium normal-case tracking-normal text-amber-600">Inactive</div>
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            <tr v-for="status in statuses" :key="status.key" class="hover:bg-gray-50/60 dark:hover:bg-gray-900/30">
                                <td class="sticky left-0 z-10 bg-white px-6 py-3 whitespace-nowrap dark:bg-gray-800">
                                    <div class="flex items-center gap-2">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[11px] font-bold border" :class="statusClasses(status.color, 'pill')">
                                            {{ status.label }}
                                        </span>
                                        <span v-if="status.required" class="text-[10px] font-semibold uppercase tracking-wider text-gray-400">Always shown</span>
                                        <span v-else-if="!status.is_system" class="text-[10px] font-semibold uppercase tracking-wider text-gray-400" :title="`Follows the SLA, queue and report rules of ${behaviorLabel(status.behaves_like)}`">
                                            Custom · like {{ behaviorLabel(status.behaves_like) }}
                                        </span>
                                        <button
                                            v-if="can.edit"
                                            type="button"
                                            @click="openEdit(status)"
                                            :title="`Edit ${status.label}`"
                                            class="ml-auto p-2 rounded-full transition-colors text-blue-600 hover:text-blue-900 hover:bg-blue-50 dark:text-blue-300 dark:hover:bg-blue-500/15"
                                        >
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
                                        </button>
                                    </div>
                                </td>
                                <td v-for="department in departments" :key="department.id" class="px-4 py-3 text-center">
                                    <button
                                        type="button"
                                        role="switch"
                                        :aria-checked="isVisible(department, status.key)"
                                        :aria-label="`${status.label} for ${department.name}`"
                                        :disabled="status.required || !department.can_edit || saving === cellKey(department, status.key)"
                                        :title="cellTitle(department, status)"
                                        class="relative inline-flex h-5 w-9 items-center rounded-full transition-colors disabled:cursor-not-allowed disabled:opacity-50"
                                        :class="isVisible(department, status.key) ? 'bg-blue-600' : 'bg-gray-300 dark:bg-gray-600'"
                                        @click="toggle(department, status)"
                                    >
                                        <span
                                            class="inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform"
                                            :class="isVisible(department, status.key) ? 'translate-x-4' : 'translate-x-0.5'"
                                        />
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <Modal :show="modal.open" max-width="lg" @close="closeModal">
            <form @submit.prevent="submit" class="p-6 space-y-5">
                <div class="flex items-center justify-between">
                    <h3 class="text-lg font-bold text-gray-900 dark:text-gray-100">{{ modal.status ? 'Edit Status' : 'New Status' }}</h3>
                    <button type="button" @click="closeModal" class="text-gray-400 hover:text-gray-600" title="Close">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                    </button>
                </div>

                <div>
                    <label for="status-label" class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1 dark:text-gray-300">Name</label>
                    <input
                        id="status-label"
                        v-model="form.label"
                        type="text"
                        maxlength="100"
                        required
                        placeholder="e.g. Waiting for Parts"
                        class="w-full rounded-lg border-gray-300 text-sm focus:border-blue-500 focus:ring-blue-500 dark:bg-gray-900 dark:border-gray-600 dark:text-gray-100"
                    />
                    <p v-if="form.errors.label" class="mt-1 text-xs text-red-600">{{ form.errors.label }}</p>
                    <p v-if="modal.status" class="mt-1 text-xs text-gray-500 dark:text-gray-400">Renaming applies to every entity and department; tickets keep their status.</p>
                </div>

                <div>
                    <span class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2 dark:text-gray-300">Colour</span>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="color in colors"
                            :key="color"
                            type="button"
                            @click="form.color = color"
                            :title="color"
                            :aria-label="`Colour ${color}`"
                            :aria-pressed="form.color === color"
                            class="h-7 w-7 rounded-full ring-offset-2 transition dark:ring-offset-gray-800"
                            :class="[statusClasses(color, 'swatch'), form.color === color ? 'ring-2 ring-gray-900 dark:ring-gray-100' : '']"
                        />
                    </div>
                    <div class="mt-3">
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[11px] font-bold border" :class="statusClasses(form.color, 'pill')">
                            {{ form.label || 'Preview' }}
                        </span>
                    </div>
                </div>

                <div v-if="!modal.status || !modal.status.is_system">
                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1 dark:text-gray-300">Behaves like</label>
                    <Autocomplete v-model="form.behaves_like" :options="behaviors" placeholder="Choose a system status..." />
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        The SLA clock, queue, dashboard lanes and reports treat this status exactly like the one chosen here.
                    </p>
                    <p v-if="form.errors.behaves_like" class="mt-1 text-xs text-red-600">{{ form.errors.behaves_like }}</p>
                </div>
                <p v-else class="text-xs text-gray-500 dark:text-gray-400">
                    System status: its SLA, queue and report behaviour is fixed. You can change its name and colour.
                </p>

                <div v-if="!modal.status">
                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1 dark:text-gray-300">Show for departments</label>
                    <MultiAutocomplete
                        v-model="form.department_ids"
                        :options="departmentOptions"
                        label-key="name"
                        value-key="id"
                        placeholder="Select departments..."
                    />
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        A new status stays hidden for every other department until it is switched on in the table.
                    </p>
                    <p v-if="form.errors.department_ids" class="mt-1 text-xs text-red-600">{{ form.errors.department_ids }}</p>
                </div>

                <div class="flex justify-end gap-3 pt-4 border-t dark:border-gray-700">
                    <button type="button" @click="closeModal" class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600">Cancel</button>
                    <button type="submit" :disabled="form.processing" class="px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700 disabled:opacity-50 whitespace-nowrap">
                        {{ form.processing ? 'Saving...' : (modal.status ? 'Save Changes' : 'Create Status') }}
                    </button>
                </div>
            </form>
        </Modal>
    </AppLayout>
</template>

<script setup>
import { computed, reactive, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Modal from '@/Components/Modal.vue'
import Autocomplete from '@/Components/Autocomplete.vue'
import MultiAutocomplete from '@/Components/MultiAutocomplete.vue'
import { useToast } from '@/Composables/useToast'
import { statusClasses } from '@/Composables/useTicketStatuses'

const props = defineProps({
    statuses: { type: Array, default: () => [] },
    departments: { type: Array, default: () => [] },
    colors: { type: Array, default: () => [] },
    behaviors: { type: Array, default: () => [] },
    homeDepartmentId: { type: Number, default: null },
    can: { type: Object, default: () => ({ create: false, edit: false }) },
})

const { showError } = useToast()
const saving = ref(null)

const cellKey = (department, key) => `${department.id}:${key}`
const isVisible = (department, key) => !(department.hidden || []).includes(key)
const behaviorLabel = (key) => props.behaviors.find(b => b.value === key)?.label || key

const cellTitle = (department, status) => {
    if (status.required) return `${status.label} is always shown`
    if (!department.can_edit) return `Only ${department.name} (or a department administrator) can change this`
    return `${isVisible(department, status.key) ? 'Hide' : 'Show'} ${status.label} for ${department.name}`
}

const toggle = (department, status) => {
    if (status.required || !department.can_edit) return
    saving.value = cellKey(department, status.key)
    // AppLayout toasts the flash message; no manual success toast here.
    router.put(route('ticket-statuses.visibility'), {
        department_id: department.id,
        status: status.key,
        is_visible: !isVisible(department, status.key),
    }, {
        preserveScroll: true,
        preserveState: true,
        onError: (errors) => showError(Object.values(errors).flat().join(', ') || 'Could not update the status'),
        onFinish: () => { saving.value = null },
    })
}

// Only departments this user may switch statuses on for.
const departmentOptions = computed(() => props.departments.filter(d => d.can_edit).map(d => ({ id: d.id, name: d.name })))

const modal = reactive({ open: false, status: null })
const form = useForm({ label: '', color: 'indigo', behaves_like: null, department_ids: [] })

const openCreate = () => {
    form.reset()
    form.clearErrors()
    form.color = 'indigo'
    form.behaves_like = 'in_progress'
    form.department_ids = departmentOptions.value.some(d => d.id === props.homeDepartmentId) ? [props.homeDepartmentId] : []
    modal.status = null
    modal.open = true
}

const openEdit = (status) => {
    form.clearErrors()
    form.label = status.label
    form.color = status.color
    form.behaves_like = status.behaves_like
    form.department_ids = []
    modal.status = status
    modal.open = true
}

const closeModal = () => { modal.open = false }

const submit = () => {
    const options = { preserveScroll: true, preserveState: true, onSuccess: closeModal }
    if (modal.status) {
        form.put(route('ticket-statuses.update', modal.status.id), options)
    } else {
        form.post(route('ticket-statuses.store'), options)
    }
}
</script>
