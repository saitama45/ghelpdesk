<template>
    <AppLayout title="Roles">
        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
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
import { ref, reactive, onMounted, watch } from 'vue'
import { router } from '@inertiajs/vue3'
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
