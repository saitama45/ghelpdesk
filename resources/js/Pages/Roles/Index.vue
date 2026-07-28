<template>
    <AppLayout title="Roles" content-class="w-full max-w-none px-2 sm:px-4 lg:px-6">
        <div class="space-y-6">
            <div>
                <!-- Data Table -->
                <DataTable
                    title="Roles & Permissions"
                    subtitle="Manage system roles and their permissions"
                    search-placeholder="Search roles by name..."
                    empty-message="No roles found. Create your first role to get started."
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
                        <a
                            v-if="hasPermission('roles.export')"
                            :href="exportUrl"
                            class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2 rounded-lg text-sm font-medium transition-colors flex items-center space-x-2 shadow-sm whitespace-nowrap dark:bg-gray-700 dark:text-gray-100 dark:hover:bg-gray-600"
                            title="Export the current role list to Excel"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                            </svg>
                            <span>Export</span>
                        </a>
                        <button
                            v-if="hasPermission('roles.import')"
                            @click="openImportModal"
                            class="bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors flex items-center space-x-2 shadow-sm whitespace-nowrap"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                            </svg>
                            <span>Import</span>
                        </button>
                        <button
                            v-if="hasPermission('roles.create')"
                            @click="openCreateModal"
                            class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors flex items-center space-x-2 shadow-sm whitespace-nowrap"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                            </svg>
                            <span>Create Role</span>
                        </button>
                    </template>

                    <template #header>
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-slate-300">Role</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-slate-300">Landing Page</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-slate-300">Permissions</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-slate-300">Actions</th>
                        </tr>
                    </template>

                    <template #body="{ data }">
                        <tr v-for="role in data" :key="role.id" class="hover:bg-gray-50 transition-colors dark:hover:bg-gray-700">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="flex items-center">
                                    <div class="h-10 w-10 bg-gradient-to-br from-purple-500 to-purple-600 rounded-full flex items-center justify-center shadow-sm">
                                        <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 0 00-9-5.197m13.5-9a2.5 2.5 0 11-5 0 2.5 2.5 0 015 0z" />
                                        </svg>
                                    </div>
                                    <div class="ml-4">
                                        <div class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ role.name }}</div>
                                        <div class="text-sm text-gray-500 dark:text-gray-300">{{ role.permissions.length }} permissions assigned</div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="px-2.5 py-1 rounded-lg text-xs font-bold bg-blue-50 text-blue-700 border border-blue-100">
                                    {{ getLandingPageLabel(role.landing_page) }}
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <button 
                                    @click="viewPermissions(role)" 
                                    class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-800 hover:bg-gray-200 transition-colors dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
                                >
                                    <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                    </svg>
                                    View {{ role.permissions.length }} permissions
                                </button>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                <div class="flex justify-end space-x-1">
                                    <button 
                                        v-if="hasPermission('roles.create')"
                                        @click="copyRole(role)" 
                                        class="p-2 text-indigo-600 hover:text-indigo-900 hover:bg-indigo-50 rounded-full transition-colors"
                                        title="Copy Role"
                                    >
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7v8a2 2 0 002 2h6M8 7V5a2 2 0 012-2h4.586a1 1 0 01.707.293l4.414 4.414a1 1 0 01.293.707V15a2 2 0 01-2 2h-2M8 7H6a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2v-2" />
                                        </svg>
                                    </button>
                                    <button 
                                        v-if="hasPermission('roles.edit')"
                                        @click="editRole(role)" 
                                        class="p-2 text-blue-600 hover:text-blue-900 hover:bg-blue-50 rounded-full transition-colors"
                                        title="Edit Role"
                                    >
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                        </svg>
                                    </button>
                                    <button 
                                        v-if="hasPermission('roles.delete')"
                                        @click="deleteRole(role)" 
                                        class="p-2 text-red-600 hover:text-red-900 hover:bg-red-50 rounded-full transition-colors"
                                        title="Delete Role"
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

        <!-- Permissions Modal -->
        <div v-if="showPermissionsModal" class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex items-center justify-center min-h-screen px-4 py-6">
                <div class="fixed inset-0 bg-black/20 backdrop-blur-md" @click="closePermissionsModal"></div>
                <div class="relative bg-white rounded-xl shadow-2xl w-full max-w-lg p-6 border border-gray-100 transform transition-all dark:bg-gray-800 dark:border-gray-700">
                    <div class="flex justify-between items-center mb-6">
                        <h3 class="text-xl font-bold text-gray-900 dark:text-gray-100">
                            Permissions: {{ selectedRole?.name }}
                        </h3>
                        <button @click="closePermissionsModal" class="text-gray-400 hover:text-gray-600 transition-colors dark:text-gray-400">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <div class="max-h-96 overflow-y-auto pr-2 custom-scrollbar">
                        <div class="flex flex-wrap gap-2">
                            <span v-for="permission in selectedRole?.permissions" :key="permission.id" 
                                  class="inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-bold bg-blue-50 text-blue-700 border border-blue-100">
                                {{ permission.name }}
                            </span>
                        </div>
                    </div>
                    <div class="flex justify-end mt-8 pt-4 border-t">
                        <button @click="closePermissionsModal" 
                                class="px-6 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors text-sm font-bold shadow-sm dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700">
                            Close
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Import Roles Modal -->
        <div v-if="showImportModal" class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex items-center justify-center min-h-screen px-4 py-6">
                <div class="fixed inset-0 bg-black/20 backdrop-blur-md" @click="showImportModal = false"></div>
                <div class="relative bg-white rounded-xl shadow-2xl w-full max-w-xl p-6 border border-gray-100 transform transition-all dark:bg-gray-800 dark:border-gray-700">
                    <div class="flex justify-between items-center mb-6">
                        <h3 class="text-xl font-bold text-gray-900 dark:text-gray-100">Import Roles</h3>
                        <button @click="showImportModal = false" class="text-gray-400 hover:text-gray-600 transition-colors dark:text-gray-400">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <div class="space-y-6">
                        <div class="p-4 bg-blue-50 rounded-lg border border-blue-100 dark:bg-blue-900/20 dark:border-blue-900/40">
                            <h4 class="text-xs font-bold text-blue-700 uppercase tracking-wider mb-2 dark:text-blue-300">Instructions</h4>
                            <ul class="text-xs text-blue-600 space-y-1 list-disc pl-4 dark:text-blue-300">
                                <li>Use an <strong>Export</strong> file as the starting point — the columns it produces are exactly what this importer reads.</li>
                                <li><strong>companies</strong> (names) and <strong>permissions</strong> (keys) accept several values separated by a semicolon (<code>;</code>).</li>
                                <li>Every role needs at least one company that already exists; unknown permissions are reported and skipped, never created.</li>
                                <li>Existing role names are skipped unless you tick "Update existing roles" — that overwrites their permissions and companies.</li>
                            </ul>
                            <div class="mt-4">
                                <a :href="exportUrl" class="text-xs font-black text-blue-700 underline hover:text-blue-800 dark:text-blue-300">
                                    Download current roles as Excel
                                </a>
                            </div>
                        </div>

                        <div class="space-y-4">
                            <label class="block">
                                <span class="sr-only">Choose file</span>
                                <input type="file" @change="handleImportFileChange" accept=".xlsx,.csv"
                                       class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100 transition-all cursor-pointer dark:text-gray-300">
                            </label>

                            <label class="flex items-start space-x-2 cursor-pointer">
                                <input type="checkbox" v-model="updateExisting" class="mt-0.5 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                <span class="text-xs text-gray-600 dark:text-gray-300">
                                    Update existing roles with the same name
                                    <span class="block text-[10px] text-amber-600 dark:text-amber-400">Their permissions and companies are replaced by what the file says.</span>
                                </span>
                            </label>

                            <div v-if="importResults" class="p-4 rounded-lg" :class="importResults.errors.length > 0 ? 'bg-amber-50 dark:bg-amber-900/20' : 'bg-green-50 dark:bg-green-900/20'">
                                <p class="text-sm font-bold" :class="importResults.errors.length > 0 ? 'text-amber-800 dark:text-amber-300' : 'text-green-800 dark:text-green-300'">
                                    Created {{ importResults.created }} role(s), updated {{ importResults.updated }}.
                                </p>
                                <div v-if="importResults.errors.length > 0" class="mt-2">
                                    <p class="text-xs font-black text-amber-700 uppercase mb-1 dark:text-amber-400">Issues encountered:</p>
                                    <ul class="text-[10px] text-amber-600 max-h-32 overflow-y-auto custom-scrollbar list-disc pl-4 dark:text-amber-300">
                                        <li v-for="(err, i) in importResults.errors" :key="i">{{ err }}</li>
                                    </ul>
                                </div>
                            </div>
                        </div>

                        <div class="flex justify-end space-x-3 pt-6 border-t dark:border-gray-700">
                            <button type="button" @click="showImportModal = false"
                                    class="px-4 py-2 text-sm font-semibold text-gray-600 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700">
                                Close
                            </button>
                            <button @click="submitImport" :disabled="!selectedImportFile || importing"
                                    class="px-6 py-2 bg-emerald-600 text-white text-sm font-bold rounded-lg hover:bg-emerald-700 shadow-md transition-all disabled:opacity-50 flex items-center space-x-2">
                                <svg v-if="importing" class="animate-spin h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 6.477 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                <span>{{ importing ? 'Importing...' : 'Start Import' }}</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <RoleFormModal
            :show="showModal"
            :title="isEditing ? 'Edit Role' : 'Create Role'"
            :submit-label="isEditing ? 'Update Role' : 'Create Role'"
            :form="form"
            :permissions="permissions"
            :companies="companies"
            :dynamic-forms="dynamicForms"
            :landing-page-options="landingPageOptions"
            @close="closeModal"
            @submit="submitForm"
        />
    </AppLayout>
</template>

<script setup>
import { ref, reactive, computed, onMounted, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import axios from 'axios'
import AppLayout from '@/Layouts/AppLayout.vue'
import DataTable from '@/Components/DataTable.vue'
import RoleFormModal from '@/Components/Roles/RoleFormModal.vue'
import { roleLandingPageOptions } from '@/Components/Roles/roleLandingPageOptions'
import { useToast } from '@/Composables/useToast'
import { useConfirm } from '@/Composables/useConfirm'
import { useErrorHandler } from '@/Composables/useErrorHandler'
import { usePagination } from '@/Composables/usePagination'
import { usePermission } from '@/Composables/usePermission'

const props = defineProps({
    roles: Object,
    permissions: Object,
    companies: Array,
    dynamicForms: Array
})

const { showSuccess, showError } = useToast()
const { confirm } = useConfirm()
const { post, put, destroy } = useErrorHandler()
const pagination = usePagination(props.roles, 'roles.index')
const { hasPermission } = usePermission();

const showModal = ref(false)
const showPermissionsModal = ref(false)
const isEditing = ref(false)
const currentRole = ref(null)
const selectedRole = ref(null)

const landingPageOptions = roleLandingPageOptions

// ── Export / import ──────────────────────────────────────────────────────
const exportUrl = computed(() => {
    const search = pagination.search.value
    return search ? `/roles/export?search=${encodeURIComponent(search)}` : '/roles/export'
})

const showImportModal = ref(false)
const importing = ref(false)
const selectedImportFile = ref(null)
const importResults = ref(null)
const updateExisting = ref(false)

const openImportModal = () => {
    selectedImportFile.value = null
    importResults.value = null
    updateExisting.value = false
    showImportModal.value = true
}

const handleImportFileChange = (event) => {
    selectedImportFile.value = event.target.files[0] || null
    importResults.value = null
}

const submitImport = async () => {
    if (!selectedImportFile.value) return

    importing.value = true
    importResults.value = null

    const formData = new FormData()
    formData.append('file', selectedImportFile.value)
    formData.append('update_existing', updateExisting.value ? '1' : '0')

    try {
        const { data } = await axios.post('/roles/import', formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        })
        importResults.value = data
        if (data.created > 0 || data.updated > 0) {
            showSuccess(`Imported ${data.created} new role(s), updated ${data.updated}.`)
            router.reload({ only: ['roles', 'permissions'] })
        } else if (data.errors.length === 0) {
            showError('Nothing to import — the file had no role rows.')
        }
    } catch (error) {
        const message = error.response?.data?.message
            || Object.values(error.response?.data?.errors || {}).flat().join(', ')
            || 'Import failed. Please check the file and try again.'
        showError(message)
    } finally {
        importing.value = false
    }
}

const getLandingPageLabel = (value) => {
    for (const group of landingPageOptions) {
        const found = group.options.find(opt => opt.value === value);
        if (found) return found.label;
    }
    return 'Dashboard';
}

const form = reactive({
    name: '',
    landing_page: 'dashboard',
    permissions: [],
    companies: [],
    is_assignable: false,
    notify_on_ticket_create: false,
    notify_on_ticket_assign: false,
    notify_on_urgent_ticket: false,
    notify_on_user_registration: false
})

onMounted(() => {
    pagination.updateData(props.roles)
})

watch(() => props.roles, (newRoles) => {
    pagination.updateData(newRoles);
}, { deep: true });

const viewPermissions = (role) => {
    selectedRole.value = role
    showPermissionsModal.value = true
}

const closePermissionsModal = () => {
    showPermissionsModal.value = false
    selectedRole.value = null
}

const openCreateModal = () => {
    isEditing.value = false
    currentRole.value = null
    form.name = ''
    form.landing_page = 'dashboard'
    form.permissions = []
    form.companies = []
    form.is_assignable = false
    form.notify_on_ticket_create = false
    form.notify_on_ticket_assign = false
    form.notify_on_urgent_ticket = false
    form.notify_on_user_registration = false
    showModal.value = true
}

const editRole = (role) => {
    isEditing.value = true;
    currentRole.value = role
    form.name = role.name
    form.landing_page = role.landing_page || 'dashboard'
    form.permissions = role.permissions.map(p => p.name)
    form.companies = role.companies ? role.companies.map(c => c.id) : []
    form.is_assignable = !!role.is_assignable
    form.notify_on_ticket_create = !!role.notify_on_ticket_create
    form.notify_on_ticket_assign = !!role.notify_on_ticket_assign
    form.notify_on_urgent_ticket = !!role.notify_on_urgent_ticket
    form.notify_on_user_registration = !!role.notify_on_user_registration
    showModal.value = true
}

const copyRole = (role) => {
    isEditing.value = false;
    currentRole.value = null;
    form.name = `${role.name} - Copy`;
    form.landing_page = role.landing_page || 'dashboard';
    form.permissions = role.permissions.map(p => p.name);
    form.companies = role.companies ? role.companies.map(c => c.id) : [];
    form.is_assignable = !!role.is_assignable;
    form.notify_on_ticket_create = !!role.notify_on_ticket_create;
    form.notify_on_ticket_assign = !!role.notify_on_ticket_assign;
    form.notify_on_urgent_ticket = !!role.notify_on_urgent_ticket;
    form.notify_on_user_registration = !!role.notify_on_user_registration;
    showModal.value = true;
};

const deleteRole = async (role) => {
    const confirmed = await confirm({
        title: 'Delete Role',
        message: `Are you sure you want to delete "${role.name}"? This action cannot be undone.`
    })

    if (!confirmed) return

    destroy(`/roles/${role.id}`, {
        onSuccess: () => showSuccess('Role deleted successfully'),
        onError: (errors) => {
            const errorMessage = Object.values(errors).flat().join(', ') || 'Cannot delete role'
            showError(errorMessage)
        }
    })
}

const closeModal = () => {
    showModal.value = false
    form.name = ''
    form.landing_page = 'dashboard'
    form.permissions = []
    form.companies = []
    form.is_assignable = false
    form.notify_on_ticket_create = false
    form.notify_on_ticket_assign = false
    form.notify_on_urgent_ticket = false
    form.notify_on_user_registration = false
}

const submitForm = () => {
    if (form.companies.length === 0) {
        showError('Please select at least one company')
        return
    }

    const url = isEditing.value ? `/roles/${currentRole.value.id}` : '/roles'
    const method = isEditing.value ? 'put' : 'post'
    
    const requestMethod = method === 'put' ? put : post
    
    requestMethod(url, form, {
        onSuccess: () => {
            closeModal()
            showSuccess(isEditing.value ? 'Role updated successfully' : 'Role created successfully')
        },
        onError: (errors) => {
            const errorMessage = Object.values(errors).flat().join(', ') || 'An error occurred'
            showError(errorMessage)
        }
    })
}

const toggleAllCompanies = () => {
    if (form.companies.length === props.companies.length) {
        form.companies = []
    } else {
        form.companies = props.companies.map(c => c.id)
    }
}

</script>
