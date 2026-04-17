<script setup>
import { ref, reactive, onMounted, watch, computed } from 'vue'
import { router, Head } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import DataTable from '@/Components/DataTable.vue'
import TableFieldBuilderModal from '@/Components/TableBuilder/TableFieldBuilderModal.vue'
import MultiAutocomplete from '@/Components/MultiAutocomplete.vue'
import { useToast } from '@/Composables/useToast'
import { useConfirm } from '@/Composables/useConfirm'
import { useErrorHandler } from '@/Composables/useErrorHandler'
import { usePagination } from '@/Composables/usePagination'
import { usePermission } from '@/Composables/usePermission'

const props = defineProps({
    tables: Object,
    users: {
        type: Array,
        default: () => [],
    },
})

const { showSuccess, showError } = useToast()
const { confirm } = useConfirm()
const { post, put, destroy: deleteRequest } = useErrorHandler()
const pagination = usePagination(props.tables, 'table-builder.index')
const { hasPermission } = usePermission()

const showModal = ref(false)
const isEditing = ref(false)
const currentTable = ref(null)

const form = reactive({
    name: '',
    description: '',
    icon: 'TableCellsIcon',
    approval_levels: 0,
    approver_matrix: [],
    cc_emails: '',
    is_active: true,
})

const userOptions = computed(() => {
    return (props.users ?? []).map(user => ({
        id: user.id,
        name: user.email ? `${user.name} (${user.email})` : user.name,
    }))
})

const syncApproverMatrix = (levels) => {
    const totalLevels = Math.max(0, Number(levels) || 0)
    const currentMatrix = Array.isArray(form.approver_matrix) ? form.approver_matrix : []

    form.approver_matrix = Array.from({ length: totalLevels }, (_, index) => {
        const level = index + 1
        const existing = currentMatrix.find(entry => Number(entry.level) === level)

        return {
            level,
            user_ids: Array.isArray(existing?.user_ids) ? [...existing.user_ids] : [],
        }
    })
}

onMounted(() => {
    pagination.updateData(props.tables)
})

watch(() => props.tables, (newTables) => {
    pagination.updateData(newTables)
}, { deep: true })

watch(() => form.approval_levels, (newValue) => {
    syncApproverMatrix(newValue)
})

const openCreateModal = () => {
    isEditing.value = false
    currentTable.value = null
    form.name = ''
    form.description = ''
    form.icon = 'TableCellsIcon'
    form.approval_levels = 0
    form.approver_matrix = []
    form.cc_emails = ''
    form.is_active = true
    showModal.value = true
}

const editTable = (table) => {
    isEditing.value = true
    currentTable.value = table
    form.name = table.name
    form.description = table.description || ''
    form.icon = table.icon || 'TableCellsIcon'
    form.approval_levels = table.approval_levels ?? 0
    form.approver_matrix = Array.isArray(table.approver_matrix)
        ? table.approver_matrix.map(entry => ({
            level: Number(entry.level),
            user_ids: Array.isArray(entry.user_ids) ? [...entry.user_ids] : [],
        }))
        : []
    form.cc_emails = table.cc_emails || ''
    form.is_active = table.is_active
    syncApproverMatrix(form.approval_levels)
    showModal.value = true
}

const closeModal = () => {
    showModal.value = false
}

const submitForm = () => {
    const url = isEditing.value ? `/table-builder/${currentTable.value.id}` : '/table-builder'
    const method = isEditing.value ? 'put' : 'post'
    
    const requestMethod = method === 'put' ? put : post
    
    requestMethod(url, form, {
        onSuccess: () => {
            closeModal()
            showSuccess(isEditing.value ? 'Table Definition updated successfully' : 'Table Definition created successfully')
        },
        onError: (errors) => {
            const errorMessage = Object.values(errors).flat().join(', ') || 'An error occurred'
            showError(errorMessage)
        }
    })
}

const deleteTable = async (table) => {
    const confirmed = await confirm({
        title: 'Delete Table Definition',
        message: `Are you sure you want to delete "${table.name}"? This will also delete all associated data.`
    })
    
    if (confirmed) {
        deleteRequest(`/table-builder/${table.id}`, {
            onSuccess: () => showSuccess('Table Definition deleted successfully'),
            onError: (errors) => {
                const errorMessage = Object.values(errors).flat().join(', ') || 'Cannot delete table definition'
                showError(errorMessage)
            }
        })
    }
}

// ── Field Builder ─────────────────────────────────────────────────────────────
const showFieldBuilder = ref(false)
const fieldBuilderTarget = ref(null)

const openFieldBuilder = (table) => {
    fieldBuilderTarget.value = table
    showFieldBuilder.value = true
}

const countCheckboxApprovalOverrides = (table) => {
    const fields = table?.form_schema?.fields ?? []

    return fields.filter(field => {
        if (field?.type !== 'checkbox_group' || !field?.has_option_approvers) {
            return false
        }

        return (field.options ?? []).some(option =>
            (Array.isArray(option.approval_matrix) && option.approval_matrix.length > 0) ||
            (Array.isArray(option.approver_user_ids) && option.approver_user_ids.length > 0)
        )
    }).length
}
</script>

<template>
    <Head title="Table Builder" />

    <AppLayout title="Table Builder">
        <div class="py-12 bg-gray-50/50 min-h-screen">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <DataTable
                    title="Table Builder Management"
                    subtitle="Build and configure custom tables with dynamic fields and approvals"
                    search-placeholder="Search by name or description..."
                    empty-message="No custom tables found. Create your first one to get started."
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
                            v-if="hasPermission('table_builder.create')"
                            @click="openCreateModal"
                            class="group relative inline-flex items-center px-6 py-2.5 bg-indigo-600 text-white text-sm font-semibold rounded-xl hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition-all duration-300 shadow-md hover:shadow-indigo-200 whitespace-nowrap"
                        >
                            <svg class="w-4 h-4 mr-2 group-hover:rotate-90 transition-transform duration-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                            </svg>
                            <span>Add Table</span>
                        </button>
                    </template>

                    <template #header>
                        <tr class="bg-gray-50/80 backdrop-blur-sm">
                            <th class="px-6 py-4 text-left text-xs font-bold text-gray-500 uppercase tracking-widest">Name</th>
                            <th class="px-6 py-4 text-left text-xs font-bold text-gray-500 uppercase tracking-widest">Slug</th>
                            <th class="px-6 py-4 text-left text-xs font-bold text-gray-500 uppercase tracking-widest">Description</th>
                            <th class="px-6 py-4 text-left text-xs font-bold text-gray-500 uppercase tracking-widest">Status</th>
                            <th class="px-6 py-4 text-right text-xs font-bold text-gray-500 uppercase tracking-widest">Actions</th>
                        </tr>
                    </template>

                    <template #body="{ data }">
                        <transition-group
                            enter-active-class="transition duration-500 ease-out"
                            enter-from-class="transform translate-y-4 opacity-0"
                            enter-to-class="transform translate-y-0 opacity-100"
                            leave-active-class="transition duration-300 ease-in"
                            leave-from-class="transform translate-y-0 opacity-100"
                            leave-to-class="transform translate-y-4 opacity-0"
                        >
                            <tr v-for="(table, index) in data" :key="table.id" 
                                :style="{ transitionDelay: `${index * 50}ms` }"
                                class="group hover:bg-white hover:shadow-xl hover:shadow-gray-200/50 transition-all duration-300 border-b border-gray-100 last:border-0"
                            >
                                <td class="px-6 py-5 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <div class="h-10 w-10 bg-gradient-to-br from-indigo-50 to-indigo-100 rounded-xl flex items-center justify-center shadow-sm group-hover:from-indigo-500 group-hover:to-indigo-600 transition-all duration-500">
                                            <svg class="w-5 h-5 text-indigo-600 group-hover:text-white transition-colors duration-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M3 14h18m-9-4v8m-7 0h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
                                            </svg>
                                        </div>
                                        <div class="ml-4">
                                            <div class="text-sm font-bold text-gray-900 group-hover:text-indigo-600 transition-colors duration-300">{{ table.name }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-5 whitespace-nowrap">
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-mono font-bold bg-gray-100 text-gray-700 border border-gray-200 group-hover:bg-indigo-50 group-hover:text-indigo-700 group-hover:border-indigo-100 transition-colors duration-300">
                                        {{ table.slug }}
                                    </span>
                                </td>
                                <td class="px-6 py-5 whitespace-nowrap">
                                    <div class="text-sm text-gray-500 truncate max-w-xs">{{ table.description || 'No description' }}</div>
                                </td>
                                <td class="px-6 py-5 whitespace-nowrap">
                                    <span :class="table.is_active ? 'bg-emerald-100 text-emerald-800 border border-emerald-200' : 'bg-rose-100 text-rose-800 border border-rose-200'" 
                                          class="inline-flex items-center px-3 py-1 text-[10px] font-bold uppercase tracking-widest rounded-full shadow-sm">
                                        <span class="w-1 h-1 rounded-full mr-1.5" :class="table.is_active ? 'bg-emerald-500' : 'bg-rose-500'"></span>
                                        {{ table.is_active ? 'Active' : 'Inactive' }}
                                    </span>
                                </td>
                                <td class="px-6 py-5 whitespace-nowrap text-right text-sm font-medium">
                                    <div class="flex justify-end items-center space-x-2 opacity-0 group-hover:opacity-100 transition-all duration-300 transform translate-x-2 group-hover:translate-x-0">
                                        <!-- Schema badge -->
                                        <div class="flex flex-col items-end space-y-1">
                                            <span v-if="table.form_schema?.fields?.length" class="text-[9px] font-black text-teal-700 bg-teal-50 border border-teal-200 rounded-full px-2 py-0.5 whitespace-nowrap">
                                                {{ table.form_schema.fields.length }} fields
                                            </span>
                                            <span v-if="table.form_schema?.approver_fields?.length" class="text-[9px] font-black text-orange-700 bg-orange-50 border border-orange-200 rounded-full px-2 py-0.5 whitespace-nowrap">
                                                {{ table.form_schema.approver_fields.length }} approver fields
                                            </span>
                                            <span v-if="table.form_schema?.has_items && table.form_schema?.items_columns?.length" class="text-[9px] font-black text-purple-700 bg-purple-50 border border-purple-200 rounded-full px-2 py-0.5 whitespace-nowrap">
                                                {{ table.form_schema.items_columns.length }} line item cols
                                            </span>
                                        </div>
                                        <button
                                            v-if="hasPermission('table_builder.edit')"
                                            @click="openFieldBuilder(table)"
                                            class="p-2 text-teal-600 hover:text-white hover:bg-teal-600 rounded-xl transition-all duration-300 shadow-sm hover:shadow-teal-200"
                                            title="Configure Fields"
                                        >
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01" />
                                            </svg>
                                        </button>
                                        <button
                                            v-if="hasPermission('table_builder.edit')"
                                            @click="editTable(table)"
                                            class="p-2 text-indigo-600 hover:text-white hover:bg-indigo-600 rounded-xl transition-all duration-300 shadow-sm hover:shadow-indigo-200"
                                            title="Edit"
                                        >
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                            </svg>
                                        </button>
                                        <button
                                            v-if="hasPermission('table_builder.delete')"
                                            @click="deleteTable(table)"
                                            class="p-2 text-rose-600 hover:text-white hover:bg-rose-600 rounded-xl transition-all duration-300 shadow-sm hover:shadow-rose-200"
                                            title="Delete"
                                        >
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                            </svg>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </transition-group>
                    </template>
                </DataTable>
            </div>
        </div>

        <!-- Table Field Builder Modal -->
        <TableFieldBuilderModal
            v-if="fieldBuilderTarget"
            :show="showFieldBuilder"
            :table="fieldBuilderTarget"
            :users="props.users"
            @close="showFieldBuilder = false; fieldBuilderTarget = null"
        />

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
                                {{ isEditing ? 'Update Table Definition' : 'New Table Definition' }}
                            </h3>
                            <p class="text-sm text-gray-500 mt-1">Configure table name, description and status.</p>
                        </div>
                        <button @click="closeModal" class="p-2 text-gray-400 hover:text-gray-900 hover:bg-gray-100 rounded-full transition-all duration-300">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <form @submit.prevent="submitForm" class="space-y-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-[10px] font-black text-gray-400 uppercase tracking-[0.2em] mb-2 ml-1">Table Name</label>
                                <input v-model="form.name" type="text" required placeholder="e.g. Assets, Employees, Equipment"
                                       class="block w-full px-4 py-3 bg-gray-50 border-transparent rounded-2xl focus:ring-2 focus:ring-indigo-500 focus:bg-white focus:border-transparent text-sm font-bold transition-all duration-300">
                            </div>
                            <div>
                                <label class="block text-[10px] font-black text-gray-400 uppercase tracking-[0.2em] mb-2 ml-1">Approval Levels</label>
                                <div class="flex items-center space-x-4 bg-gray-50 rounded-2xl p-1 border border-transparent focus-within:border-indigo-500 focus-within:bg-white transition-all duration-300">
                                    <button type="button" @click="form.approval_levels > 0 && form.approval_levels--" class="p-2 text-indigo-600 hover:bg-indigo-50 rounded-xl transition-colors">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4" />
                                        </svg>
                                    </button>
                                    <div class="flex-1 text-center">
                                        <input v-model.number="form.approval_levels" type="number" required min="0"
                                               class="w-full text-center bg-transparent border-none focus:ring-0 text-sm font-black text-gray-900">
                                        <div class="text-[9px] font-black uppercase text-orange-500 mt-[-4px]" v-if="form.approval_levels === 0">No Approval</div>
                                    </div>
                                    <button type="button" @click="form.approval_levels++" class="p-2 text-indigo-600 hover:bg-indigo-50 rounded-xl transition-colors">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                                        </svg>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div>
                            <label class="block text-[10px] font-black text-gray-400 uppercase tracking-[0.2em] mb-2 ml-1">Description</label>
                            <textarea v-model="form.description" rows="2" placeholder="Describe the purpose of this table..."
                                      class="block w-full px-4 py-3 bg-gray-50 border-transparent rounded-2xl focus:ring-2 focus:ring-indigo-500 focus:bg-white focus:border-transparent text-sm font-medium transition-all duration-300"></textarea>
                        </div>

                        <div>
                            <label class="block text-[10px] font-black text-gray-400 uppercase tracking-[0.2em] mb-2 ml-1">CC Email Notifications (one per line)</label>
                            <textarea v-model="form.cc_emails" rows="2" placeholder="email1@example.com&#10;email2@example.com"
                                      class="block w-full px-4 py-3 bg-gray-50 border-transparent rounded-2xl focus:ring-2 focus:ring-indigo-500 focus:bg-white focus:border-transparent text-sm font-medium transition-all duration-300 custom-scrollbar"></textarea>
                            <p class="text-[10px] text-gray-400 mt-2 ml-1 italic">These addresses will be notified via CC on record updates.</p>
                        </div>

                        <div v-if="form.approval_levels > 0" class="space-y-4">
                            <div class="flex items-center justify-between">
                                <div>
                                    <label class="block text-[10px] font-black text-gray-400 uppercase tracking-[0.2em] mb-1 ml-1">Approval Matrix</label>
                                    <p class="text-xs text-gray-500 ml-1">Assign one or more approvers for each approval level.</p>
                                </div>
                                <span class="inline-flex items-center px-3 py-1 rounded-full text-[10px] font-black bg-orange-50 text-orange-700 border border-orange-100">
                                    {{ form.approval_levels }} Level{{ form.approval_levels > 1 ? 's' : '' }}
                                </span>
                            </div>

                            <div class="space-y-3 max-h-60 overflow-y-auto pr-2 custom-scrollbar">
                                <div
                                    v-for="level in form.approver_matrix"
                                    :key="level.level"
                                    class="rounded-2xl border border-gray-200 bg-gray-50/80 p-4"
                                >
                                    <div class="flex items-center justify-between gap-3 mb-3">
                                        <div>
                                            <p class="text-sm font-black text-gray-900">Level {{ level.level }}</p>
                                            <p class="text-[10px] uppercase tracking-widest text-gray-400 font-black">
                                                {{ level.user_ids.length }} approver{{ level.user_ids.length !== 1 ? 's' : '' }} assigned
                                            </p>
                                        </div>
                                    </div>

                                    <MultiAutocomplete
                                        v-model="level.user_ids"
                                        :options="userOptions"
                                        label-key="name"
                                        value-key="id"
                                        placeholder="Search and assign approvers..."
                                        :limit="4"
                                    />
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center p-4 bg-gray-50 rounded-2xl border border-gray-100">
                            <label class="relative inline-flex items-center cursor-pointer">
                                <input v-model="form.is_active" type="checkbox" class="sr-only peer">
                                <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-indigo-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-indigo-600"></div>
                                <span class="ml-3 text-sm font-bold text-gray-700">Active Status</span>
                            </label>
                        </div>

                        <div class="flex space-x-4 pt-6">
                            <button type="button" @click="closeModal" 
                                    class="flex-1 px-6 py-3 text-sm font-bold text-gray-600 bg-gray-100 rounded-2xl hover:bg-gray-200 transition-all duration-300">
                                Cancel
                            </button>
                            <button type="submit" 
                                    class="flex-[2] px-6 py-3 bg-indigo-600 text-white text-sm font-black rounded-2xl hover:bg-indigo-700 shadow-lg hover:shadow-indigo-200 transform hover:-translate-y-0.5 transition-all duration-300">
                                {{ isEditing ? 'Update Table Definition' : 'Create Table Definition' }}
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
.custom-scrollbar::-webkit-scrollbar {
    width: 6px;
}
.custom-scrollbar::-webkit-scrollbar-track {
    background: transparent;
}
.custom-scrollbar::-webkit-scrollbar-thumb {
    background: #e2e8f0;
    border-radius: 10px;
}
.custom-scrollbar::-webkit-scrollbar-thumb:hover {
    background: #cbd5e1;
}
</style>
