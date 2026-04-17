<script setup>
import { ref, reactive, onMounted, watch, computed } from 'vue'
import { router, Head } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import DataTable from '@/Components/DataTable.vue'
import { useToast } from '@/Composables/useToast'
import { useConfirm } from '@/Composables/useConfirm'
import { useErrorHandler } from '@/Composables/useErrorHandler'
import { usePagination } from '@/Composables/usePagination'
import { usePermission } from '@/Composables/usePermission'

const props = defineProps({
    table: Object,
    records: Object,
})

const { showSuccess, showError } = useToast()
const { confirm } = useConfirm()
const { post, put, destroy: deleteRequest } = useErrorHandler()
const pagination = usePagination(props.records, 'dynamic-table.index', { slug: props.table.slug })
const { hasPermission } = usePermission()

const showModal = ref(false)
const isEditing = ref(false)
const currentRecord = ref(null)

// Initialize form based on table schema
const form = reactive({})

const initForm = (record = null) => {
    // Clear existing form keys
    Object.keys(form).forEach(key => delete form[key])
    
    const fields = props.table.form_schema?.fields || []
    fields.forEach(field => {
        form[field.key] = record ? record.data[field.key] : (field.type === 'checkbox_group' ? [] : '')
    })
}

onMounted(() => {
    pagination.updateData(props.records)
})

watch(() => props.records, (newRecords) => {
    pagination.updateData(newRecords)
}, { deep: true })

const openCreateModal = () => {
    isEditing.value = false
    currentRecord.value = null
    initForm()
    showModal.value = true
}

const editRecord = (record) => {
    isEditing.value = true
    currentRecord.value = record
    initForm(record)
    showModal.value = true
}

const closeModal = () => {
    showModal.value = false
}

const submitForm = () => {
    const url = isEditing.value 
        ? route('dynamic-table.update', { slug: props.table.slug, id: currentRecord.value.id }) 
        : route('dynamic-table.store', props.table.slug)
    
    const method = isEditing.value ? 'put' : 'post'
    const requestMethod = method === 'put' ? put : post
    
    requestMethod(url, form, {
        onSuccess: () => {
            closeModal()
            showSuccess(isEditing.value ? 'Record updated successfully' : 'Record created successfully')
        },
        onError: (errors) => {
            const errorMessage = Object.values(errors).flat().join(', ') || 'An error occurred'
            showError(errorMessage)
        }
    })
}

const deleteRecord = async (record) => {
    const confirmed = await confirm({
        title: 'Delete Record',
        message: `Are you sure you want to delete this record?`
    })
    
    if (confirmed) {
        deleteRequest(route('dynamic-table.destroy', { slug: props.table.slug, id: record.id }), {
            onSuccess: () => showSuccess('Record deleted successfully'),
            onError: (errors) => {
                const errorMessage = Object.values(errors).flat().join(', ') || 'Cannot delete record'
                showError(errorMessage)
            }
        })
    }
}

const tableColumns = computed(() => {
    const fields = props.table.form_schema?.fields || []
    return fields.slice(0, 4) // Show first 4 fields in the table
})
</script>

<template>
    <Head :title="table.name" />

    <AppLayout :title="table.name">
        <div class="py-12 bg-gray-50/50 min-h-screen">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <DataTable
                    :title="table.name"
                    :subtitle="table.description"
                    search-placeholder="Search records..."
                    empty-message="No records found."
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
                        <button
                            @click="openCreateModal"
                            class="group relative inline-flex items-center px-6 py-2.5 bg-indigo-600 text-white text-sm font-semibold rounded-xl hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition-all duration-300 shadow-md hover:shadow-indigo-200 whitespace-nowrap"
                        >
                            <svg class="w-4 h-4 mr-2 group-hover:rotate-90 transition-transform duration-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                            </svg>
                            <span>Add {{ table.name }}</span>
                        </button>
                    </template>

                    <template #header>
                        <tr class="bg-gray-50/80 backdrop-blur-sm">
                            <th v-for="col in tableColumns" :key="col.key" class="px-6 py-4 text-left text-xs font-bold text-gray-500 uppercase tracking-widest">
                                {{ col.label }}
                            </th>
                            <th class="px-6 py-4 text-left text-xs font-bold text-gray-500 uppercase tracking-widest">Created By</th>
                            <th class="px-6 py-4 text-right text-xs font-bold text-gray-500 uppercase tracking-widest">Actions</th>
                        </tr>
                    </template>

                    <template #body="{ data }">
                        <tr v-for="record in data" :key="record.id" 
                            class="group hover:bg-white hover:shadow-xl hover:shadow-gray-200/50 transition-all duration-300 border-b border-gray-100 last:border-0"
                        >
                            <td v-for="col in tableColumns" :key="col.key" class="px-6 py-5 whitespace-nowrap">
                                <div class="text-sm font-medium text-gray-900">
                                    {{ Array.isArray(record.data[col.key]) ? record.data[col.key].join(', ') : record.data[col.key] }}
                                </div>
                            </td>
                            <td class="px-6 py-5 whitespace-nowrap">
                                <div class="text-sm text-gray-500">{{ record.creator?.name || 'System' }}</div>
                                <div class="text-[10px] text-gray-400">{{ new Date(record.created_at).toLocaleDateString() }}</div>
                            </td>
                            <td class="px-6 py-5 whitespace-nowrap text-right text-sm font-medium">
                                <div class="flex justify-end items-center space-x-2">
                                    <button
                                        @click="editRecord(record)"
                                        class="p-2 text-indigo-600 hover:text-white hover:bg-indigo-600 rounded-xl transition-all duration-300"
                                        title="Edit"
                                    >
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                        </svg>
                                    </button>
                                    <button
                                        @click="deleteRecord(record)"
                                        class="p-2 text-rose-600 hover:text-white hover:bg-rose-600 rounded-xl transition-all duration-300"
                                        title="Delete"
                                    >
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                        </svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </template>
                </DataTable>
            </div>
        </div>

        <!-- Create/Edit Modal -->
        <transition
            enter-active-class="duration-300 ease-out"
            enter-from-class="opacity-0 scale-95"
            enter-to-class="opacity-100 scale-100"
            leave-active-class="duration-200 ease-in"
            leave-from-class="opacity-100 scale-100"
            leave-to-class="opacity-0 scale-95"
        >
            <div v-if="showModal" class="fixed inset-0 z-50 overflow-y-auto overflow-x-hidden flex items-center justify-center p-4">
                <div class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm transition-opacity" @click="closeModal"></div>
                
                <div class="relative bg-white rounded-3xl shadow-2xl w-full max-w-2xl p-8 border border-gray-100 transform transition-all">
                    <div class="flex justify-between items-center mb-8">
                        <div>
                            <h3 class="text-2xl font-black text-gray-900 tracking-tight">
                                {{ isEditing ? 'Update ' + table.name : 'New ' + table.name }}
                            </h3>
                            <p class="text-sm text-gray-500 mt-1">Fill in the details below.</p>
                        </div>
                        <button @click="closeModal" class="p-2 text-gray-400 hover:text-gray-900 hover:bg-gray-100 rounded-full transition-all duration-300">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <form @submit.prevent="submitForm" class="space-y-6">
                        <div v-for="field in table.form_schema?.fields" :key="field.key">
                            <label class="block text-[10px] font-black text-gray-400 uppercase tracking-[0.2em] mb-2 ml-1">
                                {{ field.label }} {{ field.required ? '*' : '' }}
                            </label>
                            
                            <!-- Dynamic Input Rendering -->
                            <input v-if="['text', 'number', 'email', 'tel', 'date'].includes(field.type)" 
                                   v-model="form[field.key]" 
                                   :type="field.type" 
                                   :required="field.required"
                                   class="block w-full px-4 py-3 bg-gray-50 border-transparent rounded-2xl focus:ring-2 focus:ring-indigo-500 focus:bg-white focus:border-transparent text-sm font-bold transition-all duration-300">
                            
                            <textarea v-else-if="field.type === 'textarea'"
                                      v-model="form[field.key]"
                                      :required="field.required"
                                      rows="3"
                                      class="block w-full px-4 py-3 bg-gray-50 border-transparent rounded-2xl focus:ring-2 focus:ring-indigo-500 focus:bg-white focus:border-transparent text-sm font-medium transition-all duration-300"></textarea>
                            
                            <select v-else-if="field.type === 'select'"
                                    v-model="form[field.key]"
                                    :required="field.required"
                                    class="block w-full px-4 py-3 bg-gray-50 border-transparent rounded-2xl focus:ring-2 focus:ring-indigo-500 focus:bg-white focus:border-transparent text-sm font-bold transition-all duration-300">
                                <option value="">Select option</option>
                                <option v-for="opt in field.options" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                            </select>

                            <!-- Add more field types as needed -->
                        </div>

                        <div class="flex space-x-4 pt-6">
                            <button type="button" @click="closeModal" 
                                    class="flex-1 px-6 py-3 text-sm font-bold text-gray-600 bg-gray-100 rounded-2xl hover:bg-gray-200 transition-all duration-300">
                                Cancel
                            </button>
                            <button type="submit" 
                                    class="flex-[2] px-6 py-3 bg-indigo-600 text-white text-sm font-black rounded-2xl hover:bg-indigo-700 shadow-lg hover:shadow-indigo-200 transform hover:-translate-y-0.5 transition-all duration-300">
                                {{ isEditing ? 'Update ' + table.name : 'Create ' + table.name }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </transition>
    </AppLayout>
</template>

<style scoped>
.font-black { font-weight: 900; }
.tracking-widest { letter-spacing: 0.15em; }
</style>
