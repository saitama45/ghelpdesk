<template>
    <AppLayout title="Project Templates">
        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <DataTable
                    title="Project Activity Blueprints"
                    subtitle="Manage predefined activity sets for different project types and store classes"
                    search-placeholder="Search templates by name..."
                    empty-message="No templates found. Create your first project blueprint to get started."
                    :search="pagination.search.value"
                    :data="pagination.data.value"
                    :current-page="pagination.currentPage.value"
                    :last-page="pagination.lastPage.value"
                    :per-page="pagination.perPage.value"
                    :showing-text="pagination.showingText.value"
                    :is-loading="pagination.isLoading.value"
                    @update:search="pagination.search.value = $event"
                    @go-to-page="pagination.goToPage"
                    @change-per-page="pagination.changePerPage"
                >
                    <template #actions>
                        <div class="flex items-center space-x-4">
                            <nav class="flex flex-wrap p-1 bg-gray-100 rounded-lg gap-0.5">
                                <button
                                    v-for="cls in localStoreClasses"
                                    :key="cls.value"
                                    @click="filterByClass(cls.value)"
                                    :class="[selectedClass === cls.value ? 'bg-white text-blue-600 shadow-sm' : 'text-gray-500 hover:text-gray-700', 'px-4 py-1.5 rounded-md text-xs font-bold transition-all whitespace-nowrap']"
                                >
                                    {{ cls.label }}
                                </button>
                            </nav>
                            <button 
                                v-if="hasPermission('activity_templates.create')"
                                @click="openCreateModal" 
                                class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors flex items-center space-x-2 shadow-sm whitespace-nowrap"
                            >
                                <PlusIcon class="w-4 h-4" />
                                <span>Create Template</span>
                            </button>
                        </div>
                    </template>

                    <template #header>
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Template Name</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Project Type</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Class</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Activities</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </template>

                    <template #body="{ data }">
                        <tr v-for="template in data" :key="template.id" class="hover:bg-gray-50 transition-colors">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm font-bold text-gray-900">{{ template.name }}</div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="px-2.5 py-1 bg-purple-50 text-purple-700 text-[10px] font-black uppercase tracking-widest rounded-full border border-purple-100">
                                    {{ template.project_type }}
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span v-if="template.store_class === 'Kitchen'" class="px-2.5 py-1 bg-amber-50 text-amber-700 border-amber-100 text-[10px] font-black uppercase tracking-widest rounded-lg border flex items-center w-fit">
                                    <BeakerIcon class="w-3 h-3 mr-1" />
                                    Kitchen
                                </span>
                                <span v-else-if="template.store_class === 'Both'" class="px-2.5 py-1 bg-blue-50 text-blue-700 border-blue-100 text-[10px] font-black uppercase tracking-widest rounded-lg border flex items-center w-fit">
                                    <ArrowsPointingOutIcon class="w-3 h-3 mr-1" />
                                    Both
                                </span>
                                <span v-else-if="template.store_class === 'Office'" class="px-2.5 py-1 bg-indigo-50 text-indigo-700 border-indigo-100 text-[10px] font-black uppercase tracking-widest rounded-lg border flex items-center w-fit">
                                    <BuildingOfficeIcon class="w-3 h-3 mr-1" />
                                    Office
                                </span>
                                <span v-else-if="template.store_class === 'Department Store (DS)'" class="px-2.5 py-1 bg-rose-50 text-rose-700 border-rose-100 text-[10px] font-black uppercase tracking-widest rounded-lg border flex items-center w-fit">
                                    <BuildingOfficeIcon class="w-3 h-3 mr-1" />
                                    DS
                                </span>
                                <span v-else class="px-2.5 py-1 bg-slate-50 text-slate-600 border-slate-100 text-[10px] font-black uppercase tracking-widest rounded-lg border flex items-center w-fit">
                                    <DocumentTextIcon class="w-3 h-3 mr-1" />
                                    Regular
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-xs font-bold text-gray-500">{{ template.activities?.length || 0 }} rows</div>
                                <div v-if="templateSubTaskCount(template)" class="text-[10px] font-black uppercase tracking-wider text-blue-500">{{ templateSubTaskCount(template) }} sub-tasks</div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                <div class="flex justify-end space-x-1">
                                    <button 
                                        v-if="hasPermission('activity_templates.edit')"
                                        @click="editTemplate(template)" 
                                        class="p-2 text-blue-600 hover:text-blue-900 hover:bg-blue-50 rounded-full transition-colors"
                                        title="Edit Template"
                                    >
                                        <PencilSquareIcon class="w-4 h-4" />
                                    </button>
                                    <button 
                                        v-if="hasPermission('activity_templates.delete')"
                                        @click="deleteTemplate(template)" 
                                        class="p-2 text-red-600 hover:text-red-900 hover:bg-red-50 rounded-full transition-colors"
                                        title="Delete Template"
                                    >
                                        <TrashIcon class="w-4 h-4" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </template>
                </DataTable>
            </div>
        </div>

        <!-- Create/Edit Modal -->
        <Modal :show="showModal" @close="closeModal" maxWidth="6xl" :closeable="false">
            <div class="p-6">
                <div class="flex justify-between items-center mb-6 border-b pb-4">
                    <div>
                        <h3 class="text-xl font-bold text-gray-900">
                            {{ isEditing ? 'Edit Project Template' : 'Create Project Template' }}
                        </h3>
                        <p class="text-xs text-gray-500 mt-1 uppercase font-black tracking-widest">Template Blueprint</p>
                    </div>
                    <button @click="closeModal" class="text-gray-400 hover:text-gray-600 transition-colors">
                        <XMarkIcon class="w-6 h-6" />
                    </button>
                </div>

                <form @submit.prevent="submitForm" class="space-y-8">
                    <!-- Header Info -->
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 bg-gray-50 p-4 rounded-xl border border-gray-100">
                        <div>
                            <InputLabel for="name" value="Template Name" />
                            <TextInput 
                                id="name" 
                                type="text" 
                                v-model="form.name" 
                                class="w-full mt-1" 
                                placeholder="e.g. Standard NSO Blueprint"
                                required
                            />
                            <InputError :message="form.errors.name" class="mt-1" />
                        </div>

                        <div>
                            <InputLabel for="project_type" value="Project Type" />
                            <ManageableAutocomplete
                                id="project_type"
                                v-model="form.project_type"
                                :options="localProjectTypes"
                                option-type="project_type"
                                placeholder="Select project type..."
                                class="mt-1"
                                :can-create="hasPermission('reference_options.create')"
                                :can-edit="hasPermission('reference_options.edit')"
                                :can-delete="hasPermission('reference_options.delete')"
                                @options-changed="localProjectTypes = $event"
                            />
                            <InputError :message="form.errors.project_type" class="mt-1" />
                        </div>

                        <div>
                            <InputLabel for="store_class" value="Store Class" />
                            <ManageableAutocomplete
                                id="store_class"
                                v-model="form.store_class"
                                :options="localStoreClasses"
                                option-type="store_class"
                                placeholder="Select store class..."
                                class="mt-1"
                                :can-create="hasPermission('reference_options.create')"
                                :can-edit="hasPermission('reference_options.edit')"
                                :can-delete="hasPermission('reference_options.delete')"
                                @options-changed="localStoreClasses = $event"
                            />
                            <InputError :message="form.errors.store_class" class="mt-1" />
                        </div>
                    </div>

                    <!-- Details Repeater -->
                    <div class="space-y-4">
                        <div class="flex items-center justify-between">
                            <h4 class="text-sm font-black text-gray-900 uppercase tracking-widest">Milestone Activities / Sub-tasks</h4>
                            <button 
                                type="button" 
                                @click="addMilestone"
                                class="inline-flex items-center px-3 py-1.5 bg-blue-50 text-blue-700 text-xs font-bold rounded-lg border border-blue-100 hover:bg-blue-100 transition-colors"
                            >
                                <PlusIcon class="w-3.5 h-3.5 mr-1.5" />
                                Add Milestone
                            </button>
                        </div>

                        <div class="space-y-4">
                            <div v-for="(activities, milestone, milestoneIndex) in milestoneGroups" :key="milestoneIndex" class="overflow-hidden border rounded-xl shadow-sm bg-white">
                                <div class="flex flex-wrap items-center justify-between gap-3 bg-gray-50 border-b px-4 py-3">
                                    <div class="flex items-center gap-3 min-w-0 flex-1">
                                        <input
                                            :value="milestone"
                                            type="text"
                                            @input="renameMilestone(milestone, $event.target.value)"
                                            class="w-full max-w-sm text-xs border-gray-200 rounded-lg p-1.5 font-black text-gray-700 uppercase tracking-widest focus:ring-blue-500 focus:border-blue-500"
                                            placeholder="Milestone name"
                                        >
                                        <span class="px-2 py-0.5 bg-gray-200 text-gray-500 rounded text-[9px] font-black uppercase whitespace-nowrap">
                                            {{ activities.reduce((count, activity) => count + 1 + subTasksFor(activity).length, 0) }} rows
                                        </span>
                                    </div>
                                    <button
                                        type="button"
                                        @click="addActivity(milestone)"
                                        class="inline-flex items-center px-2.5 py-1 bg-white text-blue-700 text-[10px] font-black uppercase tracking-wider rounded-lg border border-blue-100 hover:bg-blue-50 transition-colors"
                                    >
                                        <PlusIcon class="w-3.5 h-3.5 mr-1" />
                                        Add Activity
                                    </button>
                                </div>

                                <div class="overflow-x-auto">
                                    <table class="min-w-full divide-y divide-gray-200">
                                        <thead class="bg-white">
                                            <tr>
                                                <th class="px-3 py-2 text-left text-[10px] font-black text-gray-500 uppercase tracking-wider w-16">Ord</th>
                                                <th class="px-3 py-2 text-left text-[10px] font-black text-gray-500 uppercase tracking-wider min-w-[220px]">Activity / Sub-task</th>
                                                <th class="px-3 py-2 text-left text-[10px] font-black text-gray-500 uppercase tracking-wider min-w-[150px]">Department</th>
                                                <th class="px-3 py-2 text-left text-[10px] font-black text-gray-500 uppercase tracking-wider min-w-[150px]">Sub-Unit</th>
                                                <th class="px-3 py-2 text-left text-[10px] font-black text-gray-500 uppercase tracking-wider w-20">Qty</th>
                                                <th class="px-3 py-2 text-left text-[10px] font-black text-gray-500 uppercase tracking-wider w-28">Lead Time Days</th>
                                                <th class="px-3 py-2 text-center text-[10px] font-black text-gray-500 uppercase tracking-wider w-24"></th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-100">
                                            <template v-for="act in activities" :key="act.client_key">
                                                <tr class="group hover:bg-slate-50 transition-colors">
                                                    <td class="px-2 py-2">
                                                        <input v-model="act.order" type="number" class="w-full text-xs border-gray-200 rounded p-1 font-mono font-bold text-gray-400 focus:ring-blue-500 focus:border-blue-500">
                                                    </td>
                                                    <td class="px-2 py-2">
                                                        <input 
                                                            ref="activityInputs"
                                                            v-model="act.activity" 
                                                            type="text" 
                                                            class="w-full text-xs border-gray-200 rounded p-1 font-bold text-gray-800 placeholder-gray-300 focus:ring-blue-500 focus:border-blue-500" 
                                                            placeholder="Activity name..." 
                                                            required
                                                            @input="syncSubTaskMilestone(act)"
                                                        >
                                                    </td>
                                                    <td class="px-2 py-2">
                                                        <select v-model="act.department" class="w-full text-xs border-gray-200 rounded p-1 text-gray-600 focus:ring-blue-500 focus:border-blue-500" @change="handleActivityDepartmentChange(act)">
                                                            <option value="">None</option>
                                                            <option v-for="department in departmentOptions" :key="department.name" :value="department.name">{{ department.name }}</option>
                                                        </select>
                                                    </td>
                                                    <td class="px-2 py-2">
                                                        <select v-model="act.sub_unit" class="w-full text-xs border-gray-200 rounded p-1 text-gray-600 focus:ring-blue-500 focus:border-blue-500" :disabled="!act.department" @change="syncSubTaskOrganization(act)">
                                                            <option value="">None</option>
                                                            <option v-for="subUnit in subUnitsForDepartment(act.department)" :key="subUnit" :value="subUnit">{{ subUnit }}</option>
                                                        </select>
                                                    </td>
                                                    <td class="px-2 py-2">
                                                        <input v-model="act.qty" type="number" min="1" class="w-full text-xs border-gray-200 rounded p-1 text-gray-600 focus:ring-blue-500 focus:border-blue-500">
                                                    </td>
                                                    <td class="px-2 py-2">
                                                        <input v-model="act.default_duration_days" type="number" min="1" class="w-full text-xs border-gray-200 rounded p-1 text-gray-600 focus:ring-blue-500 focus:border-blue-500">
                                                        <span v-if="subTasksFor(act).length" class="block mt-0.5 text-[9px] font-black text-blue-400 uppercase tracking-wider">Σ {{ subTaskLeadTimeSum(act) }} days</span>
                                                    </td>
                                                    <td class="px-2 py-2">
                                                        <div class="flex justify-center gap-1">
                                                            <button
                                                                type="button"
                                                                @click="addSubActivity(act)"
                                                                class="text-blue-400 hover:text-blue-700 transition-colors p-1"
                                                                title="Add Sub-task"
                                                            >
                                                                <PlusIcon class="w-4 h-4" />
                                                            </button>
                                                            <button 
                                                                v-if="form.activities.length > 1"
                                                                type="button" 
                                                                @click="removeActivity(act)"
                                                                class="text-gray-300 hover:text-red-500 transition-colors p-1"
                                                                title="Delete Activity"
                                                            >
                                                                <TrashIcon class="w-4 h-4" />
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>

                                                <tr v-for="subTask in subTasksFor(act)" :key="subTask.client_key" class="group bg-slate-50/70 hover:bg-slate-100 transition-colors">
                                                    <td class="px-2 py-2">
                                                        <input v-model="subTask.order" type="number" class="w-full text-xs border-gray-200 rounded p-1 font-mono font-bold text-gray-400 focus:ring-blue-500 focus:border-blue-500">
                                                    </td>
                                                    <td class="px-2 py-2">
                                                        <div class="flex items-center gap-2 pl-6">
                                                            <span class="text-[9px] font-black text-gray-400 uppercase tracking-widest">Sub</span>
                                                            <input 
                                                                ref="activityInputs"
                                                                v-model="subTask.activity" 
                                                                type="text" 
                                                                class="w-full text-xs border-gray-200 rounded p-1 font-bold text-gray-700 placeholder-gray-300 focus:ring-blue-500 focus:border-blue-500" 
                                                                placeholder="Sub-task name..." 
                                                                required
                                                            >
                                                        </div>
                                                    </td>
                                                    <td class="px-2 py-2">
                                                        <select v-model="subTask.department"
                                                                class="w-full text-xs border-gray-200 rounded p-1 text-gray-600 focus:ring-blue-500 focus:border-blue-500"
                                                                @change="handleSubTaskDepartmentChange(subTask)">
                                                            <option value="">None</option>
                                                            <option v-for="department in departmentOptions" :key="department.name" :value="department.name">{{ department.name }}</option>
                                                        </select>
                                                    </td>
                                                    <td class="px-2 py-2">
                                                        <select v-model="subTask.sub_unit"
                                                                class="w-full text-xs border-gray-200 rounded p-1 text-gray-600 focus:ring-blue-500 focus:border-blue-500"
                                                                :disabled="!subTask.department">
                                                            <option value="">None</option>
                                                            <option v-for="subUnit in subUnitsForDepartment(subTask.department)" :key="subUnit" :value="subUnit">{{ subUnit }}</option>
                                                        </select>
                                                    </td>
                                                    <td class="px-2 py-2">
                                                        <input v-model="subTask.qty" type="number" min="1" class="w-full text-xs border-gray-200 rounded p-1 text-gray-600 focus:ring-blue-500 focus:border-blue-500">
                                                    </td>
                                                    <td class="px-2 py-2">
                                                        <input v-model="subTask.default_duration_days" type="number" min="1" class="w-full text-xs border-gray-200 rounded p-1 text-gray-600 focus:ring-blue-500 focus:border-blue-500">
                                                    </td>
                                                    <td class="px-2 py-2">
                                                        <div class="flex justify-center">
                                                            <button 
                                                                v-if="form.activities.length > 1"
                                                                type="button" 
                                                                @click="removeActivity(subTask)"
                                                                class="text-gray-300 hover:text-red-500 transition-colors p-1"
                                                                title="Delete Sub-task"
                                                            >
                                                                <TrashIcon class="w-4 h-4" />
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            </template>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <div v-if="form.errors.activities" class="text-sm text-red-600">{{ form.errors.activities }}</div>
                    </div>

                    <div class="flex justify-end pt-6 border-t mt-6">
                        <PrimaryButton type="submit" :disabled="form.processing" class="bg-blue-600 hover:bg-blue-700">
                            {{ isEditing ? 'Update Template' : 'Create Template' }}
                        </PrimaryButton>
                    </div>
                </form>
            </div>
        </Modal>
    </AppLayout>
</template>

<script setup>
import { ref, computed, onMounted, watch, nextTick } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import DataTable from '@/Components/DataTable.vue'
import Modal from '@/Components/Modal.vue'
import TextInput from '@/Components/TextInput.vue'
import InputLabel from '@/Components/InputLabel.vue'
import InputError from '@/Components/InputError.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import ManageableAutocomplete from '@/Components/ManageableAutocomplete.vue'
import { useToast } from '@/Composables/useToast'
import { useConfirm } from '@/Composables/useConfirm'
import { usePagination } from '@/Composables/usePagination'
import { usePermission } from '@/Composables/usePermission'
import { 
    PlusIcon, 
    TrashIcon, 
    PencilSquareIcon, 
    XMarkIcon,
    ClockIcon,
    DocumentTextIcon,
    BeakerIcon,
    ArrowsPointingOutIcon,
    BuildingOfficeIcon
} from '@heroicons/vue/24/outline'

const props = defineProps({
    templates: Object,
    subUnits: Array,
    departmentOptions: Array,
    projectTypes: Array,
    storeClasses: Array,
    filters: Object
})

const localProjectTypes = ref([...(props.projectTypes || [])])
const localStoreClasses = ref([...(props.storeClasses || [])])

const { showSuccess, showError } = useToast()
const { confirm } = useConfirm()
const pagination = usePagination(props.templates, 'activity-templates.index')
const { hasPermission } = usePermission()

const selectedClass = ref(props.filters.store_class || 'Regular')

const filterByClass = (className) => {
    selectedClass.value = className
    router.get(route('activity-templates.index'), {
        store_class: className,
        search: pagination.search.value,
        per_page: pagination.perPage.value
    }, {
        preserveState: true,
        replace: true
    })
}

const showModal = ref(false)
const isEditing = ref(false)
const currentTemplate = ref(null)
const activityInputs = ref([])
let clientKeySequence = 1


const makeClientKey = () => `activity-${Date.now()}-${clientKeySequence++}`

const createActivityRow = (overrides = {}) => ({
    id: null,
    client_key: makeClientKey(),
    parent_client_key: null,
    activity: '',
    milestone: 'General',
    milestone_order: 1,
    asset_item: '',
    model_specs: '',
    qty: 1,
    responsible: null,
    department: '',
    sub_unit: '',
    default_duration_days: 1,
    order: 1,
    ...overrides
})

const form = useForm({
    name: '',
    project_type: 'NSO',
    store_class: 'Regular',
    activities: [
        createActivityRow()
    ]
})

onMounted(() => {
    pagination.updateData(props.templates)
})

watch(() => props.templates, (newTemplates) => {
    pagination.updateData(newTemplates)
}, { deep: true })

const openCreateModal = () => {
    isEditing.value = false
    currentTemplate.value = null
    form.reset()
    form.store_class = selectedClass.value
    form.activities = [createActivityRow()]

    showModal.value = true
}

const editTemplate = (template) => {
    isEditing.value = true
    currentTemplate.value = template
    form.name = template.name
    form.project_type = template.project_type || 'NSO'
    form.store_class = template.store_class
    form.activities = normalizeTemplateActivities(template.activities || [])
    if (form.activities.length === 0) {
        form.activities = [createActivityRow()]
    }
    showModal.value = true
}

const closeModal = () => {
    showModal.value = false
    form.reset()
    form.activities = [createActivityRow()]
}

const normalizeTemplateActivities = (activities) => {
    const keyById = new Map()
    const milestoneOrderByName = new Map()
    let nextMilestoneOrder = 1

    activities.forEach(activity => {
        keyById.set(activity.id, makeClientKey())
    })

    return [...activities]
        .sort((a, b) => {
            const aIsSubTask = a.parent_activity_template_id ? 1 : 0
            const bIsSubTask = b.parent_activity_template_id ? 1 : 0
            const aMilestoneOrder = Number.isFinite(Number(a.milestone_order)) ? Number(a.milestone_order) : Number.MAX_SAFE_INTEGER
            const bMilestoneOrder = Number.isFinite(Number(b.milestone_order)) ? Number(b.milestone_order) : Number.MAX_SAFE_INTEGER

            if (aMilestoneOrder !== bMilestoneOrder) return aMilestoneOrder - bMilestoneOrder
            if (aIsSubTask !== bIsSubTask) return aIsSubTask - bIsSubTask
            return (Number(a.order) || 0) - (Number(b.order) || 0)
        })
        .map(activity => {
            const milestone = activity.milestone || 'General'
            if (!milestoneOrderByName.has(milestone)) {
                const explicitOrder = Number(activity.milestone_order)
                milestoneOrderByName.set(milestone, Number.isFinite(explicitOrder) ? explicitOrder : nextMilestoneOrder)
                nextMilestoneOrder = Math.max(nextMilestoneOrder, milestoneOrderByName.get(milestone) + 1)
            }

            return createActivityRow({
                id: activity.id,
                client_key: keyById.get(activity.id),
                parent_client_key: activity.parent_activity_template_id ? keyById.get(activity.parent_activity_template_id) : null,
                activity: activity.activity,
                milestone,
                milestone_order: milestoneOrderByName.get(milestone),
                asset_item: activity.asset_item,
                model_specs: activity.model_specs,
                qty: activity.qty,
                responsible: activity.responsible,
                department: activity.department || '',
                sub_unit: activity.sub_unit || '',
                default_duration_days: activity.default_duration_days,
                order: activity.order
            })
        })
}

const departmentOptions = computed(() => props.departmentOptions || [])

const subUnitsForDepartment = (departmentName) => {
    return departmentOptions.value.find(department => department.name === departmentName)?.sub_units || []
}

const syncSubTaskOrganization = (parentActivity) => {
    form.activities.forEach(activity => {
        if (activity.parent_client_key === parentActivity.client_key) {
            activity.department = parentActivity.department || ''
            activity.sub_unit = parentActivity.sub_unit || ''
        }
    })
}

const handleActivityDepartmentChange = (activity) => {
    if (!subUnitsForDepartment(activity.department).includes(activity.sub_unit)) {
        activity.sub_unit = ''
    }

    syncSubTaskOrganization(activity)
}

const milestoneGroups = computed(() => {
    const groups = {}

    form.activities.forEach(activity => {
        if (activity.parent_client_key) return

        const milestone = activity.milestone || 'General'
        if (!groups[milestone]) groups[milestone] = []
        groups[milestone].push(activity)
    })

    const sorted = Object.entries(groups).sort(([, a], [, b]) => {
        const aMilestoneOrder = Math.min(...a.map(act => Number.isFinite(Number(act.milestone_order)) ? Number(act.milestone_order) : Number.MAX_SAFE_INTEGER))
        const bMilestoneOrder = Math.min(...b.map(act => Number.isFinite(Number(act.milestone_order)) ? Number(act.milestone_order) : Number.MAX_SAFE_INTEGER))
        if (aMilestoneOrder !== bMilestoneOrder) return aMilestoneOrder - bMilestoneOrder

        const aMin = Math.min(...a.map(act => Number(act.order) || 0))
        const bMin = Math.min(...b.map(act => Number(act.order) || 0))
        return aMin - bMin
    })

    return Object.fromEntries(sorted)
})

const templateSubTaskCount = (template) => {
    return (template.activities || []).filter(activity => activity.parent_activity_template_id).length
}

const subTasksFor = (activity) => {
    return form.activities
        .filter(candidate => candidate.parent_client_key === activity.client_key)
        .sort((a, b) => (Number(a.order) || 0) - (Number(b.order) || 0))
}

const nextOrderFor = (milestone, parentClientKey = null) => {
    const siblings = form.activities.filter(activity => {
        if ((activity.parent_client_key || null) !== (parentClientKey || null)) return false
        if (parentClientKey) return true

        return (activity.milestone || 'General') === (milestone || 'General')
    })

    if (!siblings.length) return 1

    return Math.max(...siblings.map(activity => Number(activity.order) || 0)) + 1
}

const milestoneOrderFor = (milestone) => {
    const normalizedMilestone = milestone || 'General'
    const existing = form.activities
        .filter(activity => !activity.parent_client_key && (activity.milestone || 'General') === normalizedMilestone)
        .map(activity => Number(activity.milestone_order))
        .filter(Number.isFinite)

    return existing.length ? Math.min(...existing) : nextMilestoneOrder()
}

const nextMilestoneOrder = () => {
    const orders = form.activities
        .filter(activity => !activity.parent_client_key)
        .map(activity => Number(activity.milestone_order))
        .filter(Number.isFinite)

    return orders.length ? Math.max(...orders) + 1 : 1
}

const focusLastActivityInput = () => {
    nextTick(() => {
        const lastInput = activityInputs.value[activityInputs.value.length - 1]
        if (lastInput) lastInput.focus()
    })
}

const addMilestone = () => {
    const milestoneName = `Milestone ${Object.keys(milestoneGroups.value).length + 1}`
    form.activities.push(createActivityRow({
        milestone: milestoneName,
        milestone_order: nextMilestoneOrder(),
        order: nextOrderFor(milestoneName)
    }))
    focusLastActivityInput()
}

const addActivity = (milestone = 'General') => {
    const lastRow = [...form.activities].reverse().find(activity => !activity.parent_client_key && (activity.milestone || 'General') === (milestone || 'General'))

    form.activities.push(createActivityRow({
        milestone: milestone || 'General',
        milestone_order: milestoneOrderFor(milestone),
        responsible: lastRow ? lastRow.responsible : null,
        department: lastRow ? lastRow.department : '',
        sub_unit: lastRow ? lastRow.sub_unit : '',
        default_duration_days: lastRow ? lastRow.default_duration_days : 1,
        order: nextOrderFor(milestone)
    }))

    focusLastActivityInput()
}

const addSubActivity = (parentActivity) => {
    form.activities.push(createActivityRow({
        parent_client_key: parentActivity.client_key,
        milestone: parentActivity.milestone || 'General',
        milestone_order: parentActivity.milestone_order ?? milestoneOrderFor(parentActivity.milestone),
        responsible: parentActivity.responsible,
        department: parentActivity.department || '',
        sub_unit: parentActivity.sub_unit || '',
        default_duration_days: parentActivity.default_duration_days || 1,
        order: nextOrderFor(parentActivity.milestone, parentActivity.client_key)
    }))

    focusLastActivityInput()
}

const renameMilestone = (currentMilestone, nextMilestone) => {
    const order = milestoneOrderFor(currentMilestone)

    form.activities.forEach(activity => {
        if ((activity.milestone || 'General') === currentMilestone) {
            activity.milestone = nextMilestone || 'General'
            activity.milestone_order = order
        }
    })
}

const syncSubTaskMilestone = (parentActivity) => {
    form.activities.forEach(activity => {
        if (activity.parent_client_key === parentActivity.client_key) {
            activity.milestone = parentActivity.milestone || 'General'
            activity.milestone_order = parentActivity.milestone_order ?? milestoneOrderFor(parentActivity.milestone)
        }
    })
}

const removeActivity = (activity) => {
    const keysToRemove = new Set([activity.client_key])

    if (!activity.parent_client_key) {
        form.activities
            .filter(candidate => candidate.parent_client_key === activity.client_key)
            .forEach(candidate => keysToRemove.add(candidate.client_key))
    }

    form.activities = form.activities.filter(candidate => !keysToRemove.has(candidate.client_key))

    if (form.activities.length === 0) {
        form.activities = [createActivityRow()]
    }
}

const subTaskLeadTimeSum = (activity) => {
    return form.activities
        .filter(a => a.parent_client_key === activity.client_key)
        .reduce((sum, a) => sum + (Number(a.default_duration_days) || 0), 0)
}

watch(
    () => form.activities.map(a => ({ key: a.parent_client_key, days: a.default_duration_days })),
    () => {
        form.activities.forEach(activity => {
            if (!activity.parent_client_key) {
                const subs = form.activities.filter(a => a.parent_client_key === activity.client_key)
                if (subs.length) {
                    activity.default_duration_days = subs.reduce((sum, a) => sum + (Number(a.default_duration_days) || 0), 0)
                }
            }
        })
    },
    { deep: true }
)

const handleSubTaskDepartmentChange = (subTask) => {
    if (!subUnitsForDepartment(subTask.department).includes(subTask.sub_unit)) {
        subTask.sub_unit = ''
    }
}


const submitForm = () => {
    const transformPayload = (data) => ({
        ...data,
        activities: data.activities.map(({ subTasks, ...activity }) => activity)
    })

    if (isEditing.value) {
        form.transform(transformPayload).put(route('activity-templates.update', currentTemplate.value.id), {
            onSuccess: () => {
                closeModal()
            }
        })
    } else {
        form.transform(transformPayload).post(route('activity-templates.store'), {
            onSuccess: () => {
                closeModal()
            }
        })
    }
}

const deleteTemplate = async (template) => {
    const confirmed = await confirm({
        title: 'Delete Project Template',
        message: `Are you sure you want to delete "${template.name}"? All associated activities will also be removed.`,
        confirmLabel: 'Delete',
        cancelLabel: 'Cancel',
        variant: 'danger'
    })
    
    if (confirmed) {
        router.delete(route('activity-templates.destroy', template.id))
    }
}
</script>

<style scoped>
.custom-scrollbar::-webkit-scrollbar {
    height: 6px;
    width: 6px;
}
.custom-scrollbar::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 10px;
}
.custom-scrollbar::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 10px;
}
.custom-scrollbar::-webkit-scrollbar-thumb:hover {
    background: #94a3b8;
}
</style>
