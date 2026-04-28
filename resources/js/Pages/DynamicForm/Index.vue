<script setup>
import { ref, reactive, onMounted, watch, computed } from 'vue'
import { router, Head, useForm, usePage, Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import DataTable from '@/Components/DataTable.vue'
import DynamicFormRenderer from '@/Components/DynamicFormRenderer.vue'
import { useToast } from '@/Composables/useToast'
import { useConfirm } from '@/Composables/useConfirm'
import { useErrorHandler } from '@/Composables/useErrorHandler'
import { usePagination } from '@/Composables/usePagination'
import { usePermission } from '@/Composables/usePermission'

const props = defineProps({
    form: Object,
    records: Object,
})

const { showSuccess, showError } = useToast()
const { confirm } = useConfirm()
const { post, put, destroy: deleteRequest } = useErrorHandler()

// Destructure pagination for cleaner template usage
const { 
    data: paginatedData, 
    search: paginationSearch, 
    currentPage, 
    lastPage, 
    perPage, 
    showingText, 
    isLoading, 
    goToPage, 
    changePerPage, 
    updateData 
} = usePagination(props.records, 'dynamic-form.index', { slug: props.form.slug })

const { hasPermission } = usePermission()

const showModal = ref(false)
const isEditing = ref(false)
const currentRecord = ref(null)

// Use Inertia useForm for better error handling and file uploads
const dynamicForm = useForm({
    form_data: {},
    items: [],
})

const schemaItemsTemplateSource = computed(() => props.form.form_schema?.items_template_source || null)
const schemaItemsTemplates = computed(() => props.form.form_schema?.items_templates || {})

const getActiveItemColumns = (formData = dynamicForm.form_data) => {
    const source = schemaItemsTemplateSource.value
    if (!source) return props.form.form_schema?.items_columns || []

    const selected = String(formData?.[source] ?? '')
    return selected ? (schemaItemsTemplates.value[selected]?.columns || []) : []
}

const buildBlankItemRow = (formData = dynamicForm.form_data) => {
    const row = {}
    getActiveItemColumns(formData).forEach(column => {
        row[column.key] = column.type === 'checkbox_group' ? [] : ''
    })
    return row
}

const initForm = (record = null) => {
    const schema = props.form.form_schema || {}
    const fields = schema.fields || []
    
    const initialFormData = {}
    fields.forEach(field => {
        initialFormData[field.key] = record?.data ? record.data[field.key] : (field.type === 'checkbox_group' ? [] : '')
    })
    
    dynamicForm.form_data = initialFormData
    dynamicForm.items = record?.data?.items
        ? JSON.parse(JSON.stringify(record.data.items))
        : (schema.has_items && getActiveItemColumns(initialFormData).length ? [buildBlankItemRow(initialFormData)] : [])
}

onMounted(() => {
    updateData(props.records)
})

watch(() => props.records, (newRecords) => {
    updateData(newRecords)
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
    dynamicForm.clearErrors()
}

const submitForm = () => {
    const url = isEditing.value 
        ? route('dynamic-form.update', { slug: props.form.slug, id: currentRecord.value.id }) 
        : route('dynamic-form.store', props.form.slug)
    
    // Use POST with _method spoofing for updates to support multipart/form-data
    if (isEditing.value) {
        dynamicForm.transform((data) => ({
            ...data,
            _method: 'put',
        })).post(url, {
            forceFormData: true,
            onSuccess: () => {
                closeModal()
                showSuccess('Record updated successfully')
            },
        })
    } else {
        dynamicForm.post(url, {
            onSuccess: () => {
                closeModal()
                showSuccess('Record created successfully')
            },
        })
    }
}

const deleteRecord = async (record) => {
    const confirmed = await confirm({
        title: 'Delete Record',
        message: `Are you sure you want to delete this record?`
    })
    
    if (confirmed) {
        deleteRequest(route('dynamic-form.destroy', { slug: props.form.slug, id: record.id }), {
            onSuccess: () => showSuccess('Record deleted successfully'),
            onError: (errors) => {
                const errorMessage = Object.values(errors).flat().join(', ') || 'Cannot delete record'
                showError(errorMessage)
            }
        })
    }
}

const formColumns = computed(() => {
    const fields = props.form.form_schema?.fields || []
    return fields.slice(0, 4) // Show first 4 fields in the form table
})

const getDisplayValue = (record, col) => {
    const value = record.data ? record.data[col.key] : null
    if (value === null || value === undefined || value === '') return '—'

    // Handle File type
    if (col.type === 'file') {
        if (Array.isArray(value)) {
            return value.map(f => typeof f === 'object' ? f.name : f).join(', ')
        }
        return typeof value === 'object' ? value.name || value.path.split('/').pop() : value
    }

    // Handle Toggle
    if (col.type === 'toggle') {
        return value ? 'Yes' : 'No'
    }

    // Handle Options (Select, Radio, Checkbox Group)
    if (col.options && col.options.length > 0) {
        if (Array.isArray(value)) {
            return value.map(v => {
                const opt = col.options.find(o => String(o.value) === String(v))
                return opt ? opt.label : v
            }).join(', ')
        }
        const opt = col.options.find(o => String(o.value) === String(value))
        return opt ? opt.label : value
    }

    // Default array handling
    if (Array.isArray(value)) return value.join(', ')

    return value
}

const STATUS_COLORS = {
    'Open': 'bg-blue-100 text-blue-700',
    'Approved': 'bg-emerald-100 text-emerald-700',
    'Cancelled': 'bg-rose-100 text-rose-700',
    'In Progress': 'bg-amber-100 text-amber-700',
}

function statusClass(s) {
    if (!s) return 'bg-gray-100 text-gray-500'
    if (s.startsWith('Approved Level')) return 'bg-indigo-100 text-indigo-700'
    return STATUS_COLORS[s] ?? 'bg-gray-100 text-gray-500'
}
</script>

<template>
    <Head :title="form.name" />

    <AppLayout :title="form.name">
        <div class="py-12 bg-gray-50/50 min-h-screen">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <DataTable
                    :title="form.name"
                    :subtitle="form.description"
                    search-placeholder="Search records..."
                    empty-message="Get started by adding your first record."
                    :search="paginationSearch"
                    :data="paginatedData"
                    :current-page="currentPage"
                    :last-page="lastPage"
                    :per-page="perPage"
                    :showing-text="showingText"
                    :is-loading="isLoading"
                    @update:search="paginationSearch = $event"
                    @go-to-page="goToPage"
                    @change-per-page="changePerPage"
                >
                    <template #actions>
                        <button
                            v-if="hasPermission(form.slug + '.create')"
                            @click="openCreateModal"
                            class="group relative inline-flex items-center px-6 py-2.5 bg-indigo-600 text-white text-sm font-semibold rounded-xl hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition-all duration-300 shadow-md hover:shadow-indigo-200 whitespace-nowrap"
                        >
                            <svg class="w-4 h-4 mr-2 group-hover:rotate-90 transition-transform duration-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                            </svg>
                            <span>Add {{ form.name }}</span>
                        </button>
                    </template>

                    <template #header>
                        <tr class="bg-gray-50/80 backdrop-blur-sm">
                            <th v-for="col in formColumns" :key="col.key" class="px-6 py-4 text-left text-xs font-bold text-gray-500 uppercase tracking-widest text-left">
                                {{ col.label }}
                            </th>
                            <th class="px-6 py-4 text-left text-xs font-bold text-gray-500 uppercase tracking-widest text-left">Status</th>
                            <th class="px-6 py-4 text-left text-xs font-bold text-gray-500 uppercase tracking-widest text-left">Created By</th>
                            <th class="px-6 py-4 text-right text-xs font-bold text-gray-500 uppercase tracking-widest">Actions</th>
                        </tr>
                    </template>

                    <template #body="{ data }">
                        <tr v-for="record in data" :key="record.id" 
                            class="group hover:bg-white hover:shadow-xl hover:shadow-gray-200/50 transition-all duration-300 border-b border-gray-100 last:border-0"
                        >
                            <td v-for="col in formColumns" :key="col.key" class="px-6 py-5 whitespace-nowrap text-left">
                                <div class="text-sm font-medium text-gray-900">
                                    {{ getDisplayValue(record, col) }}
                                </div>
                            </td>
                            <td class="px-6 py-5 whitespace-nowrap text-left">
                                <span :class="statusClass(record.status)" class="px-3 py-1 rounded-lg text-[10px] font-black uppercase tracking-wide whitespace-nowrap">
                                    {{ record.status }}
                                </span>
                            </td>
                            <td class="px-6 py-5 whitespace-nowrap text-left">
                                <div class="text-sm text-gray-500">{{ record.creator?.name || 'System' }}</div>
                                <div class="text-[10px] text-gray-400">{{ new Date(record.created_at).toLocaleDateString() }}</div>
                            </td>
                            <td class="px-6 py-5 whitespace-nowrap text-right text-sm font-medium">
                                <div class="flex justify-end items-center space-x-2">
                                    <Link
                                        v-if="hasPermission(form.slug + '.show')"
                                        :href="route('dynamic-form.show', { slug: form.slug, id: record.id })"
                                        class="p-2 text-emerald-600 hover:text-white hover:bg-emerald-600 rounded-xl transition-all duration-300 shadow-sm"
                                        title="View"
                                    >
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                        </svg>
                                    </Link>
                                    <button
                                        v-if="hasPermission(form.slug + '.edit')"
                                        @click="editRecord(record)"
                                        class="p-2 text-indigo-600 hover:text-white hover:bg-indigo-600 rounded-xl transition-all duration-300 shadow-sm"
                                        title="Edit"
                                    >
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                        </svg>
                                    </button>
                                    <button
                                        v-if="hasPermission(form.slug + '.delete')"
                                        @click="deleteRecord(record)"
                                        class="p-2 text-rose-600 hover:text-white hover:bg-rose-600 rounded-xl transition-all duration-300 shadow-sm"
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
                                {{ isEditing ? 'Update ' + form.name : 'New ' + form.name }}
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
                        <DynamicFormRenderer
                            :fields="form.form_schema?.fields"
                            v-model="dynamicForm.form_data"
                            :items-columns="form.form_schema?.items_columns"
                            :items-template-source="form.form_schema?.items_template_source"
                            :items-templates="form.form_schema?.items_templates || {}"
                            :item-label="form.form_schema?.item_label || 'Row'"
                            v-model:items="dynamicForm.items"
                            :has-items="form.form_schema?.has_items"
                            :errors="dynamicForm.errors"
                            grid-columns="2"
                        />

                        <div class="flex space-x-4 pt-6">
                            <button type="button" @click="closeModal" 
                                    class="flex-1 px-6 py-3 text-sm font-bold text-gray-600 bg-gray-100 rounded-2xl hover:bg-gray-200 transition-all duration-300">
                                Cancel
                            </button>
                            <button type="submit" 
                                    class="flex-[2] px-6 py-3 bg-indigo-600 text-white text-sm font-black rounded-2xl hover:bg-indigo-700 shadow-lg hover:shadow-indigo-200 transform hover:-translate-y-0.5 transition-all duration-300">
                                {{ isEditing ? 'Update ' + form.name : 'Create ' + form.name }}
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
