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
    form: Object, // Includes requestTypes relation
    records: Object,
    copyTransferPayload: { type: Object, default: null },
})

const { showSuccess, showError } = useToast()
const { confirm } = useConfirm()
const { post, put, destroy: deleteRequest } = useErrorHandler()

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
const showCreateSection = ref(false)
const selectedRequestType = ref(null)

// Use Inertia useForm for better error handling and file uploads
const dynamicForm = useForm({
    request_type_id: null,
    form_data: {},
    items: [],
})

// Determine which schema to use
const effectiveSchema = computed(() => {
    if (isEditing.value && currentRecord.value?.request_type) {
        return currentRecord.value.request_type.form_schema
    }
    return selectedRequestType.value ? selectedRequestType.value.form_schema : props.form.form_schema
})

const schemaItemsTemplateSource = computed(() => effectiveSchema.value?.items_template_source || null)
const schemaItemsTemplates = computed(() => effectiveSchema.value?.items_templates || {})

const getActiveItemColumns = (formData = dynamicForm.form_data) => {
    const source = schemaItemsTemplateSource.value
    if (!source) return effectiveSchema.value?.items_columns || []

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

const initForm = (record = null, preFill = null) => {
    const schema = effectiveSchema.value || {}
    const fields = schema.fields || []
    
    const initialFormData = {}
    fields.forEach(field => {
        if (record?.data) {
            initialFormData[field.key] = record.data[field.key]
        } else if (preFill?.form_data) {
            initialFormData[field.key] = preFill.form_data[field.key] !== undefined ? preFill.form_data[field.key] : (field.type === 'checkbox_group' ? [] : '')
        } else {
            initialFormData[field.key] = field.type === 'checkbox_group' ? [] : ''
        }
    })
    
    dynamicForm.request_type_id = record ? record.request_type_id : (selectedRequestType.value?.id || null)
    dynamicForm.form_data = initialFormData
    
    const sourceItems = record?.data?.items || preFill?.items
    dynamicForm.items = sourceItems
        ? JSON.parse(JSON.stringify(sourceItems))
        : (schema.has_items && getActiveItemColumns(initialFormData).length ? [buildBlankItemRow(initialFormData)] : [])
}

onMounted(async () => {
    updateData(props.records)

    // Handle pre-fill if payload exists
    if (props.copyTransferPayload && hasPermission(props.form.slug + '.create')) {
        await nextTick()
        // If the payload has a request_type_id that belongs to this form, use it
        if (props.copyTransferPayload.request_type_id) {
            const rt = props.form.request_types?.find(t => t.id === props.copyTransferPayload.request_type_id)
            if (rt) selectedRequestType.value = rt
        }
        
        initForm(null, JSON.parse(JSON.stringify(props.copyTransferPayload)))
        showModal.value = true
    }
    
    // Auto-open create modal if query param is present
    const urlParams = new URLSearchParams(window.location.search)
    if (urlParams.get('create') === '1' && !showModal.value && hasPermission(props.form.slug + '.create')) {
        openCreateModal()
    }
})

watch(() => props.records, (newRecords) => {
    updateData(newRecords)
}, { deep: true })

const openCreateModal = (requestType = null) => {
    isEditing.value = false
    currentRecord.value = null
    selectedRequestType.value = requestType
    initForm()
    showModal.value = true
}

const editRecord = (record) => {
    isEditing.value = true
    currentRecord.value = record
    selectedRequestType.value = record.request_type || null
    initForm(record)
    showModal.value = true
}

const closeModal = () => {
    showModal.value = false
    selectedRequestType.value = null
    dynamicForm.clearErrors()
}

const validateForm = () => {
    let isValid = true
    const errors = {}
    
    if (effectiveSchema.value?.fields) {
        effectiveSchema.value.fields.forEach(field => {
            if (field.required) {
                const val = dynamicForm.form_data[field.key]
                if (val === null || val === undefined || val === '' || (Array.isArray(val) && val.length === 0)) {
                    errors[`form_data.${field.key}`] = `${field.label || field.key} is required`
                    isValid = false
                }
            }
        })
    }
    
    if (effectiveSchema.value?.has_items && dynamicForm.items?.length > 0) {
        const columns = getActiveItemColumns(dynamicForm.form_data)
        if (columns?.length > 0) {
            dynamicForm.items.forEach((row, rowIdx) => {
                columns.forEach(col => {
                    if (col.required) {
                        const val = row[col.key]
                        if (val === null || val === undefined || val === '' || (Array.isArray(val) && val.length === 0)) {
                            errors[`items.${rowIdx}.${col.key}`] = `${col.label || col.key} is required in row ${rowIdx + 1}`
                            isValid = false
                        }
                    }
                })
            })
        }
    }
    
    if (!isValid) {
        dynamicForm.clearErrors()
        dynamicForm.setError(errors)
        showError('Please fill in all required fields.')
    }
    
    return isValid
}

const submitForm = () => {
    if (!validateForm()) return

    const url = isEditing.value 
        ? route('dynamic-form.update', { slug: props.form.slug, id: currentRecord.value.id }) 
        : route('dynamic-form.store', props.form.slug)
    
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
            onError: (errors) => {
                const errorMessage = Object.values(errors).flat().join('\n') || 'Please fix the validation errors'
                showError(errorMessage)
            }
        })
    } else {
        dynamicForm.post(url, {
            onSuccess: () => {
                closeModal()
                showSuccess('Record created successfully')
            },
            onError: (errors) => {
                const errorMessage = Object.values(errors).flat().join('\n') || 'Please fix the validation errors'
                showError(errorMessage)
            }
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

// Columns to show in the data table
const tableColumns = computed(() => {
    // If most records have a request type, show the Request Type column
    // Otherwise show first few fields from the base form schema
    const fields = props.form.form_schema?.fields || []
    return fields.slice(0, 3)
})

const getDisplayValue = (record, col) => {
    const value = record.data ? record.data[col.key] : null
    if (value === null || value === undefined || value === '') return '—'

    if (col.type === 'file') {
        if (Array.isArray(value)) return value.map(f => typeof f === 'object' ? f.name : f).join(', ')
        return typeof value === 'object' ? value.name || value.path.split('/').pop() : value
    }

    if (col.type === 'toggle') return value ? 'Yes' : 'No'

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

    if (Array.isArray(value)) return value.join(', ')
    return value
}

const STATUS_COLORS = {
    'Open': 'bg-blue-100 text-blue-700',
    'Approved': 'bg-emerald-100 text-emerald-700',
    'Cancelled': 'bg-rose-100 text-rose-700',
    'Rejected': 'bg-red-100 text-red-700',
    'In Progress': 'bg-amber-100 text-amber-700',
}

function statusClass(s) {
    if (!s) return 'bg-gray-100 text-gray-500'
    if (s.startsWith('Approved Level')) return 'bg-indigo-100 text-indigo-700'
    return STATUS_COLORS[s] ?? 'bg-gray-100 text-gray-500'
}

const getStageDisplay = (record) => {
    const totalLevels = Number(record.request_type ? record.request_type.approval_levels : props.form.approval_levels)
    if (totalLevels === 0) return { label: 'N/A', class: 'text-[10px] font-black text-gray-300 uppercase' }

    const isChecklist = (record.request_type?.workflow_type || props.form.workflow_type) === 'checklist'
    
    if (isChecklist) {
        const completed = new Set((record.approvals || []).map(a => Number(a.level))).size
        return {
            label: `${completed} / ${totalLevels}`,
            class: 'text-xs font-black text-teal-600 bg-teal-50 px-3 py-0.5 rounded-full border border-teal-100',
            isBadge: true,
        }
    } else {
        if (record.status === 'Approved') return { label: `${totalLevels} / ${totalLevels}`, class: 'text-xs font-black text-emerald-600 bg-emerald-50 px-3 py-0.5 rounded-full border border-emerald-100', isBadge: true }
        if (record.status === 'Rejected') return { label: 'Rejected', class: 'text-[10px] font-black text-red-600 uppercase tracking-widest' }
        if (record.status === 'Cancelled') return { label: 'Cancelled', class: 'text-[10px] font-black text-rose-600 uppercase tracking-widest' }

        const current = Number(record.current_approval_level || 1)
        return {
            label: `${current - 1} / ${totalLevels}`,
            class: 'text-xs font-black text-indigo-600 bg-indigo-50 px-3 py-0.5 rounded-full border border-indigo-100',
            isBadge: true,
        }
    }
}
</script>

<template>
    <Head :title="form.name" />

    <AppLayout :title="form.name">
        <div class="py-12 bg-gray-50/50 min-h-screen">
            <div class="max-w-[1600px] mx-auto px-4 sm:px-6 lg:px-8">
                
                <!-- Header with Tile Toggle -->
                <div class="flex items-center justify-between mb-8">
                    <div>
                        <h1 class="text-3xl font-black text-gray-900 tracking-tight">{{ form.name }}</h1>
                        <p class="text-sm text-gray-500 font-medium mt-1">{{ form.description || 'Submit and track your requests.' }}</p>
                    </div>
                    <button v-if="hasPermission(form.slug + '.create')" 
                        @click="showCreateSection = !showCreateSection"
                        :class="showCreateSection ? 'bg-gray-200 text-gray-700' : 'bg-indigo-600 text-white shadow-lg shadow-indigo-100 hover:bg-indigo-700'"
                        class="flex items-center gap-2 px-6 py-3 rounded-2xl font-black text-sm transition-all">
                        <svg class="w-4 h-4 transition-transform" :class="showCreateSection ? 'rotate-45' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/>
                        </svg>
                        {{ showCreateSection ? 'Close' : 'New Submission' }}
                    </button>
                </div>

                <!-- Tile Selection Section -->
                <div v-if="showCreateSection" class="mb-10 animate-in fade-in slide-in-from-top-4 duration-300">
                    <div class="flex items-center gap-3 mb-6">
                        <div class="h-px flex-1 bg-gray-200"></div>
                        <h2 class="text-[10px] font-black text-gray-400 uppercase tracking-[0.2em]">Select Request Type</h2>
                        <div class="h-px flex-1 bg-gray-200"></div>
                    </div>
                    
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                        <!-- Default Form Tile (if it has fields) -->
                        <button v-if="form.form_schema?.fields?.length"
                            @click="openCreateModal(null)"
                            class="bg-white p-6 rounded-[2rem] shadow-xl shadow-gray-100/50 border border-gray-100 text-left hover:border-indigo-500 hover:shadow-indigo-100/50 transition-all group">
                            <div class="w-12 h-12 bg-indigo-50 rounded-2xl flex items-center justify-center mb-4 group-hover:bg-indigo-600 group-hover:text-white transition-all">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                </svg>
                            </div>
                            <h4 class="text-sm font-black text-gray-900 mb-1">Standard {{ form.name }}</h4>
                            <p class="text-[10px] text-gray-400 font-bold uppercase tracking-wider">Default Schema</p>
                        </button>

                        <!-- Request Type Tiles -->
                        <button v-for="rt in form.request_types" :key="rt.id"
                            @click="openCreateModal(rt)"
                            class="bg-white p-6 rounded-[2rem] shadow-xl shadow-gray-100/50 border border-gray-100 text-left hover:border-indigo-500 hover:shadow-indigo-100/50 transition-all group">
                            <div class="w-12 h-12 bg-teal-50 rounded-2xl flex items-center justify-center mb-4 group-hover:bg-teal-600 group-hover:text-white transition-all">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                                </svg>
                            </div>
                            <h4 class="text-sm font-black text-gray-900 mb-1">{{ rt.name }}</h4>
                            <p class="text-[10px] text-gray-400 font-bold uppercase tracking-wider">
                                {{ rt.approval_levels > 0 ? `${rt.approval_levels} Approval Steps` : 'No Approval Required' }}
                            </p>
                        </button>
                    </div>
                </div>

                <DataTable
                    :title="form.name + ' Submissions'"
                    search-placeholder="Search records..."
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
                    <template #header>
                        <tr class="bg-gray-50/80 backdrop-blur-sm">
                            <th class="px-6 py-4 text-left text-xs font-bold text-gray-500 uppercase tracking-widest">Request Type</th>
                            <th v-for="col in tableColumns" :key="col.key" class="px-6 py-4 text-left text-xs font-bold text-gray-500 uppercase tracking-widest">
                                {{ col.label }}
                            </th>
                            <th class="px-6 py-4 text-left text-xs font-bold text-gray-500 uppercase tracking-widest text-center">Stage</th>
                            <th class="px-6 py-4 text-left text-xs font-bold text-gray-500 uppercase tracking-widest text-center">Status</th>
                            <th class="px-6 py-4 text-left text-xs font-bold text-gray-500 uppercase tracking-widest">Created By</th>
                            <th class="px-6 py-4 text-right text-xs font-bold text-gray-500 uppercase tracking-widest">Actions</th>
                        </tr>
                    </template>

                    <template #body="{ data }">
                        <tr v-for="record in data" :key="record.id" 
                            class="group hover:bg-white hover:shadow-xl hover:shadow-gray-200/50 transition-all duration-300 border-b border-gray-100 last:border-0"
                        >
                            <td class="px-6 py-5 whitespace-nowrap">
                                <span v-if="record.request_type" class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-black bg-teal-50 text-teal-700 border border-teal-100 uppercase tracking-tight">
                                    {{ record.request_type.name }}
                                </span>
                                <span v-else class="text-xs text-gray-400 font-bold uppercase tracking-widest">Standard</span>
                            </td>
                            <td v-for="col in tableColumns" :key="col.key" class="px-6 py-5 whitespace-nowrap text-left">
                                <div class="text-sm font-medium text-gray-900">
                                    {{ getDisplayValue(record, col) }}
                                </div>
                            </td>
                            <td class="px-6 py-5 whitespace-nowrap text-center">
                                <span v-if="!getStageDisplay(record).isBadge" :class="getStageDisplay(record).class">
                                    {{ getStageDisplay(record).label }}
                                </span>
                                <div v-else class="inline-flex flex-col">
                                    <span :class="getStageDisplay(record).class">
                                        {{ getStageDisplay(record).label }}
                                    </span>
                                </div>
                            </td>
                            <td class="px-6 py-5 whitespace-nowrap text-center">
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
                                        v-if="hasPermission(form.slug + '.edit') && record.status === 'Open'"
                                        @click="editRecord(record)"
                                        class="p-2 text-indigo-600 hover:text-white hover:bg-indigo-600 rounded-xl transition-all duration-300 shadow-sm"
                                        title="Edit"
                                    >
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                        </svg>
                                    </button>
                                    <button
                                        v-if="hasPermission(form.slug + '.delete') && record.status === 'Open'"
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
                                {{ isEditing ? 'Update ' + form.name : 'New ' + (selectedRequestType ? selectedRequestType.name : form.name) }}
                            </h3>
                            <p class="text-sm text-gray-500 mt-1">Please fill in the required information.</p>
                        </div>
                        <button @click="closeModal" class="p-2 text-gray-400 hover:text-gray-900 hover:bg-gray-100 rounded-full transition-all duration-300">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <form @submit.prevent="submitForm" class="space-y-6" novalidate>
                        <!-- Notice about request type -->
                        <div v-if="selectedRequestType" class="flex items-center gap-3 p-3 bg-teal-50 border border-teal-100 rounded-2xl mb-4">
                            <div class="h-8 w-8 bg-teal-100 rounded-lg flex items-center justify-center text-teal-600">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            </div>
                            <div>
                                <p class="text-xs font-black text-teal-800 uppercase tracking-tight">Using {{ selectedRequestType.name }} schema</p>
                                <p class="text-[10px] text-teal-600 font-medium">Fields and approvals are defined by the selected request type.</p>
                            </div>
                        </div>

                        <DynamicFormRenderer
                            v-if="effectiveSchema"
                            :fields="effectiveSchema.fields"
                            v-model="dynamicForm.form_data"
                            :items-columns="effectiveSchema.items_columns"
                            :items-template-source="effectiveSchema.items_template_source"
                            :items-templates="effectiveSchema.items_templates || {}"
                            :item-label="effectiveSchema.item_label || 'Row'"
                            v-model:items="dynamicForm.items"
                            :has-items="effectiveSchema.has_items"
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
                                {{ isEditing ? 'Update Submission' : 'Submit Request' }}
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
