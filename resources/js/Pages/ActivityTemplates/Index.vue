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
                            <nav class="flex p-1 bg-gray-100 rounded-lg">
                                <button 
                                    @click="filterByClass('Regular')"
                                    :class="[selectedClass === 'Regular' ? 'bg-white text-blue-600 shadow-sm' : 'text-gray-500 hover:text-gray-700', 'px-4 py-1.5 rounded-md text-xs font-bold transition-all']"
                                >
                                    Regular
                                </button>
                                <button 
                                    @click="filterByClass('Kitchen')"
                                    :class="[selectedClass === 'Kitchen' ? 'bg-white text-blue-600 shadow-sm' : 'text-gray-500 hover:text-gray-700', 'px-4 py-1.5 rounded-md text-xs font-bold transition-all']"
                                >
                                    Kitchen
                                </button>
                                <button
                                    @click="filterByClass('Both')"
                                    :class="[selectedClass === 'Both' ? 'bg-white text-blue-600 shadow-sm' : 'text-gray-500 hover:text-gray-700', 'px-4 py-1.5 rounded-md text-xs font-bold transition-all']"
                                >
                                    Both
                                </button>
                                <button
                                    @click="filterByClass('Office')"
                                    :class="[selectedClass === 'Office' ? 'bg-white text-blue-600 shadow-sm' : 'text-gray-500 hover:text-gray-700', 'px-4 py-1.5 rounded-md text-xs font-bold transition-all']"
                                >
                                    Office
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
                                <span v-else class="px-2.5 py-1 bg-slate-50 text-slate-600 border-slate-100 text-[10px] font-black uppercase tracking-widest rounded-lg border flex items-center w-fit">
                                    <DocumentTextIcon class="w-3 h-3 mr-1" />
                                    Regular
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-xs font-bold text-gray-500">{{ template.activities?.length || 0 }} activities</div>
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
        <Modal :show="showModal" @close="closeModal" maxWidth="6xl">
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
                            <select 
                                v-model="form.project_type" 
                                id="project_type"
                                class="w-full mt-1 border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm"
                                required
                            >
                                <option value="NSO">NSO (New Store Opening)</option>
                                <option value="Store Closure">Store Closure</option>
                                <option value="Store Renovation">Store Renovation</option>
                            </select>
                            <InputError :message="form.errors.project_type" class="mt-1" />
                        </div>

                        <div>
                            <InputLabel for="store_class" value="Store Class" />
                            <select 
                                v-model="form.store_class" 
                                id="store_class"
                                class="w-full mt-1 border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm"
                                required
                            >
                                <option value="Regular">Regular Store</option>
                                <option value="Kitchen">Kitchen Only</option>
                                <option value="Both">Both (Regular & Kitchen)</option>
                                <option value="Office">Office Store</option>
                            </select>
                            <InputError :message="form.errors.store_class" class="mt-1" />
                        </div>
                    </div>

                    <!-- Details Repeater -->
                    <div class="space-y-4">
                        <div class="flex items-center justify-between">
                            <h4 class="text-sm font-black text-gray-900 uppercase tracking-widest">Activities / Task Details</h4>
                            <button 
                                type="button" 
                                @click="addActivity"
                                class="inline-flex items-center px-3 py-1.5 bg-blue-50 text-blue-700 text-xs font-bold rounded-lg border border-blue-100 hover:bg-blue-100 transition-colors"
                            >
                                <PlusIcon class="w-3.5 h-3.5 mr-1.5" />
                                Add Activity
                            </button>
                        </div>

                        <div class="overflow-x-auto border rounded-xl shadow-sm bg-white">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-3 py-2 text-left text-[10px] font-black text-gray-500 uppercase tracking-wider w-16">Ord</th>
                                        <th class="px-3 py-2 text-left text-[10px] font-black text-gray-500 uppercase tracking-wider">Milestone</th>
                                        <th class="px-3 py-2 text-left text-[10px] font-black text-gray-500 uppercase tracking-wider min-w-[200px]">Activity</th>
                                        <th class="px-3 py-2 text-left text-[10px] font-black text-gray-500 uppercase tracking-wider">Asset Item</th>
                                        <th class="px-3 py-2 text-left text-[10px] font-black text-gray-500 uppercase tracking-wider">Model/Specs</th>
                                        <th class="px-3 py-2 text-left text-[10px] font-black text-gray-500 uppercase tracking-wider w-20">Qty</th>
                                        <th class="px-3 py-2 text-left text-[10px] font-black text-gray-500 uppercase tracking-wider">Resp.</th>
                                        <th class="px-3 py-2 text-left text-[10px] font-black text-gray-500 uppercase tracking-wider w-20">Days</th>
                                        <th class="px-3 py-2 text-center text-[10px] font-black text-gray-500 uppercase tracking-wider w-10"></th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <tr v-for="(act, index) in form.activities" :key="index" class="group hover:bg-slate-50 transition-colors">
                                        <td class="px-2 py-2">
                                            <input v-model="act.order" type="number" class="w-full text-xs border-gray-200 rounded p-1 font-mono font-bold text-gray-400 focus:ring-blue-500 focus:border-blue-500">
                                        </td>
                                        <td class="px-2 py-2">
                                            <input v-model="act.milestone" type="text" class="w-full text-xs border-gray-200 rounded p-1 text-gray-600 placeholder-gray-300 focus:ring-blue-500 focus:border-blue-500" placeholder="Milestone...">
                                        </td>
                                        <td class="px-2 py-2">
                                            <input 
                                                ref="activityInputs"
                                                v-model="act.activity" 
                                                type="text" 
                                                class="w-full text-xs border-gray-200 rounded p-1 font-bold text-gray-800 placeholder-gray-300 focus:ring-blue-500 focus:border-blue-500" 
                                                placeholder="Activity name..." 
                                                required
                                            >
                                        </td>
                                        <td class="px-2 py-2">
                                            <input v-model="act.asset_item" type="text" class="w-full text-xs border-gray-200 rounded p-1 text-gray-600 placeholder-gray-300 focus:ring-blue-500 focus:border-blue-500" placeholder="Asset...">
                                        </td>
                                        <td class="px-2 py-2">
                                            <input v-model="act.model_specs" type="text" class="w-full text-xs border-gray-200 rounded p-1 text-gray-600 placeholder-gray-300 focus:ring-blue-500 focus:border-blue-500" placeholder="Specs...">
                                        </td>
                                        <td class="px-2 py-2">
                                            <input v-model="act.qty" type="number" min="1" class="w-full text-xs border-gray-200 rounded p-1 text-gray-600 focus:ring-blue-500 focus:border-blue-500">
                                        </td>
                                        <td class="px-2 py-2">
                                            <select v-model="act.responsible" class="w-full text-xs border-gray-200 rounded p-1 text-gray-600 focus:ring-blue-500 focus:border-blue-500">
                                                <option :value="null">None</option>
                                                <option v-for="unit in subUnits" :key="unit" :value="unit">{{ unit }}</option>
                                            </select>
                                        </td>
                                        <td class="px-2 py-2">
                                            <input v-model="act.default_duration_days" type="number" min="1" class="w-full text-xs border-gray-200 rounded p-1 text-gray-600 focus:ring-blue-500 focus:border-blue-500">
                                        </td>
                                        <td class="px-2 py-2 text-center">
                                            <button 
                                                v-if="form.activities.length > 1"
                                                type="button" 
                                                @click="removeActivity(index)"
                                                class="text-gray-300 hover:text-red-500 transition-colors p-1"
                                            >
                                                <TrashIcon class="w-4 h-4" />
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div v-if="form.errors.activities" class="text-sm text-red-600">{{ form.errors.activities }}</div>
                    </div>

                    <div class="flex justify-end space-x-3 pt-6 border-t mt-6">
                        <SecondaryButton @click="closeModal">
                            Cancel
                        </SecondaryButton>
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
import { ref, reactive, onMounted, watch, nextTick } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import DataTable from '@/Components/DataTable.vue'
import Modal from '@/Components/Modal.vue'
import TextInput from '@/Components/TextInput.vue'
import InputLabel from '@/Components/InputLabel.vue'
import InputError from '@/Components/InputError.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
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
    filters: Object
})

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

const form = useForm({
    name: '',
    project_type: 'NSO',
    store_class: 'Regular',
    activities: [
        { 
            id: null,
            activity: '', 
            milestone: '', 
            asset_item: '', 
            model_specs: '', 
            qty: 1, 
            responsible: null, 
            default_duration_days: 1, 
            order: 1 
        }
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
    showModal.value = true
}

const editTemplate = (template) => {
    isEditing.value = true
    currentTemplate.value = template
    form.name = template.name
    form.project_type = template.project_type || 'NSO'
    form.store_class = template.store_class
    form.activities = template.activities.map(a => ({
        id: a.id,
        activity: a.activity,
        milestone: a.milestone,
        asset_item: a.asset_item,
        model_specs: a.model_specs,
        qty: a.qty,
        responsible: a.responsible,
        default_duration_days: a.default_duration_days,
        order: a.order
    }))
    showModal.value = true
}

const closeModal = () => {
    showModal.value = false
    form.reset()
}

const addActivity = () => {
    const lastRow = form.activities.length > 0 ? form.activities[form.activities.length - 1] : null;
    
    const lastOrder = form.activities.length > 0 
        ? Math.max(...form.activities.map(a => Number(a.order) || 0)) 
        : 0
    
    form.activities.push({
        id: null,
        activity: '',
        milestone: lastRow ? lastRow.milestone : '',
        asset_item: '',
        model_specs: '',
        qty: 1,
        responsible: lastRow ? lastRow.responsible : null,
        default_duration_days: 1,
        order: lastOrder + 1
    })

    nextTick(() => {
        const index = form.activities.length - 1;
        if (activityInputs.value[index]) {
            activityInputs.value[index].focus();
        }
    });
}

const removeActivity = (index) => {
    form.activities.splice(index, 1)
}

const submitForm = () => {
    if (isEditing.value) {
        form.put(route('activity-templates.update', currentTemplate.value.id), {
            onSuccess: () => {
                closeModal()
            }
        })
    } else {
        form.post(route('activity-templates.store'), {
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
