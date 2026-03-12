<template>
    <AppLayout title="Activity Templates">
        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <DataTable
                    title="Activity Task Templates"
                    subtitle="Manage predefined tasks automatically assigned to projects based on store class"
                    search-placeholder="Search tasks by name or category..."
                    empty-message="No templates found. Create your first task blueprint to get started."
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
                            </nav>

                            <button 
                                v-if="hasPermission('activity_templates.create')"
                                @click="openCreateModal" 
                                class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors flex items-center space-x-2 shadow-sm whitespace-nowrap"
                            >
                                <PlusIcon class="w-4 h-4" />
                                <span>Add Template Task</span>
                            </button>
                        </div>
                    </template>

                    <template #header>
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-16">Order</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Task Name</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Category</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Class</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Duration</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </template>

                    <template #body="{ data }">
                        <tr v-for="template in data" :key="template.id" class="hover:bg-gray-50 transition-colors">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="text-sm font-bold text-gray-400 font-mono">#{{ template.order }}</span>
                            </td>
                            <td class="px-6 py-4">
                                <div class="text-sm font-bold text-gray-900">{{ template.name }}</div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="px-2.5 py-1 bg-indigo-50 text-indigo-700 text-[10px] font-black uppercase tracking-widest rounded-full border border-indigo-100">
                                    {{ template.category || 'General' }}
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
                                <span v-else class="px-2.5 py-1 bg-slate-50 text-slate-600 border-slate-100 text-[10px] font-black uppercase tracking-widest rounded-lg border flex items-center w-fit">
                                    <DocumentTextIcon class="w-3 h-3 mr-1" />
                                    Regular
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="flex items-center text-sm text-gray-600">
                                    <ClockIcon class="w-4 h-4 mr-1.5 text-gray-400" />
                                    <span class="font-bold">{{ template.default_duration_days }}</span>
                                    <span class="ml-1 text-gray-400">days</span>
                                </div>
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
        <Modal :show="showModal" @close="closeModal" maxWidth="2xl">
            <div class="p-6">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-xl font-bold text-gray-900">
                        {{ isEditing ? 'Edit Activity Template' : 'Create Activity Template' }}
                    </h3>
                    <button @click="closeModal" class="text-gray-400 hover:text-gray-600 transition-colors">
                        <XMarkIcon class="w-6 h-6" />
                    </button>
                </div>

                <form @submit.prevent="submitForm" class="space-y-5">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="md:col-span-2">
                            <InputLabel for="name" value="Task Name" />
                            <TextInput 
                                id="name" 
                                type="text" 
                                v-model="form.name" 
                                class="w-full mt-1" 
                                placeholder="e.g. Server Installation"
                                required
                            />
                            <InputError :message="form.errors.name" class="mt-1" />
                        </div>

                        <div>
                            <InputLabel for="category" value="Category / Group" />
                            <TextInput 
                                id="category" 
                                type="text" 
                                v-model="form.category" 
                                class="w-full mt-1" 
                                placeholder="e.g. Infrastructure"
                            />
                            <InputError :message="form.errors.category" class="mt-1" />
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
                            </select>
                            <InputError :message="form.errors.store_class" class="mt-1" />
                        </div>

                        <div>
                            <InputLabel for="default_duration_days" value="Default Duration (Days)" />
                            <TextInput 
                                id="default_duration_days" 
                                type="number" 
                                min="1"
                                v-model="form.default_duration_days" 
                                class="w-full mt-1" 
                                required
                            />
                            <InputError :message="form.errors.default_duration_days" class="mt-1" />
                        </div>

                        <div>
                            <InputLabel for="order" value="Display Order" />
                            <TextInput 
                                id="order" 
                                type="number" 
                                min="0"
                                v-model="form.order" 
                                class="w-full mt-1" 
                                required
                            />
                            <InputError :message="form.errors.order" class="mt-1" />
                        </div>
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
import { ref, reactive, onMounted, watch } from 'vue'
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
    ArrowsPointingOutIcon
} from '@heroicons/vue/24/outline'

const props = defineProps({
    templates: Object,
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

const form = useForm({
    name: '',
    category: '',
    store_class: 'Regular',
    default_duration_days: 1,
    order: 0
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
    form.order = (props.templates.data.length > 0) 
        ? Math.max(...props.templates.data.map(t => t.order)) + 10 
        : 10
    showModal.value = true
}

const editTemplate = (template) => {
    isEditing.value = true
    currentTemplate.value = template
    form.name = template.name
    form.category = template.category
    form.store_class = template.store_class
    form.default_duration_days = template.default_duration_days
    form.order = template.order
    showModal.value = true
}

const closeModal = () => {
    showModal.value = false
    form.reset()
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
        title: 'Delete Template Task',
        message: `Are you sure you want to delete "${template.name}"? This will not affect existing projects.`,
        confirmLabel: 'Delete',
        cancelLabel: 'Cancel',
        variant: 'danger'
    })
    
    if (confirmed) {
        router.delete(route('activity-templates.destroy', template.id))
    }
}
</script>
