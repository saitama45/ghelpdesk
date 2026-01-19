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
                            class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors flex items-center space-x-2"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                            </svg>
                            <span>Create Role</span>
                        </button>
                    </template>

                    <template #header>
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Role</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Permissions</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </template>

                    <template #body="{ data }">
                        <tr v-for="role in data" :key="role.id" class="hover:bg-gray-50 transition-colors">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="flex items-center">
                                    <div class="h-10 w-10 bg-gradient-to-br from-purple-500 to-purple-600 rounded-full flex items-center justify-center shadow-sm">
                                        <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197m13.5-9a2.5 2.5 0 11-5 0 2.5 2.5 0 015 0z" />
                                        </svg>
                                    </div>
                                    <div class="ml-4">
                                        <div class="text-sm font-medium text-gray-900">{{ role.name }}</div>
                                        <div class="text-sm text-gray-500">{{ role.permissions.length }} permissions assigned</div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <button 
                                    @click="viewPermissions(role)" 
                                    class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-800 hover:bg-gray-200 transition-colors"
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
        <div v-if="showPermissionsModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
            <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-1/2 shadow-lg rounded-md bg-white">
                <div class="mt-3">
                    <h3 class="text-lg font-medium text-gray-900 mb-4">
                        Permissions for "{{ selectedRole?.name }}"
                    </h3>
                    <div class="max-h-96 overflow-y-auto">
                        <div class="flex flex-wrap gap-2">
                            <span v-for="permission in selectedRole?.permissions" :key="permission.id" 
                                  class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                {{ permission.name }}
                            </span>
                        </div>
                    </div>
                    <div class="flex justify-end mt-4">
                        <button @click="closePermissionsModal" 
                                class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-200 rounded-md hover:bg-gray-300">
                            Close
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Create/Edit Modal -->
        <div v-if="showModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
            <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-3/4 lg:w-1/2 shadow-lg rounded-md bg-white">
                <div class="mt-3">
                    <h3 class="text-lg font-medium text-gray-900 mb-4">
                        {{ isEditing ? 'Edit Role' : 'Create Role' }}
                    </h3>
                    <form @submit.prevent="submitForm">
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Role Name</label>
                            <input v-model="form.name" type="text" required
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>

                        <div class="mb-4">
                            <label class="flex items-center space-x-3 cursor-pointer">
                                <span class="text-sm font-medium text-gray-700">Can be assigned to tickets?</span>
                                <div class="relative">
                                    <input type="checkbox" v-model="form.is_assignable" class="sr-only peer">
                                    <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                                </div>
                            </label>
                            <p class="text-xs text-gray-500 mt-1">Users with this role will appear in the "Assignee" dropdown on tickets.</p>
                        </div>

                        <div class="mb-4 p-4 bg-gray-50 rounded-lg border border-gray-100">
                            <h4 class="text-sm font-medium text-gray-900 mb-3">Notification Settings</h4>
                            <div class="space-y-4">
                                <label class="flex items-center justify-between cursor-pointer">
                                    <span class="text-sm font-medium text-gray-700">Notify on Ticket Creation?</span>
                                    <div class="relative">
                                        <input type="checkbox" v-model="form.notify_on_ticket_create" class="sr-only peer">
                                        <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                                    </div>
                                </label>
                                
                                <label class="flex items-center justify-between cursor-pointer">
                                    <span class="text-sm font-medium text-gray-700">Notify when Assigned?</span>
                                    <div class="relative">
                                        <input type="checkbox" v-model="form.notify_on_ticket_assign" class="sr-only peer">
                                        <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                                    </div>
                                </label>
                            </div>
                            <p class="text-xs text-gray-500 mt-2">Configure when users with this role should receive email notifications.</p>
                        </div>

                            <div class="mb-6">
                                <div class="flex items-center justify-between mb-2">
                                    <label class="block text-sm font-medium text-gray-700">Companies</label>
                                    <label class="flex items-center space-x-2 text-xs text-blue-600 hover:text-blue-800 cursor-pointer">
                                        <input type="checkbox" 
                                            :checked="form.companies.length === companies.length && companies.length > 0"
                                            @change="toggleAllCompanies"
                                            class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                        <span>Select All</span>
                                    </label>
                                </div>
                                <div class="space-y-2 max-h-32 overflow-y-auto border rounded-lg p-3">
                                    <label v-for="company in companies" :key="company.id" class="flex items-center">
                                        <input type="checkbox" :value="company.id" v-model="form.companies"
                                               class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                        <span class="ml-2 text-sm text-gray-700">{{ company.name }}</span>
                                    </label>
                                </div>
                            </div>

                            <div class="mb-6">
                                <div class="flex items-center justify-between mb-2">
                                    <label class="block text-sm font-medium text-gray-700">Permissions</label>
                                    <label class="flex items-center space-x-2 text-sm font-medium text-blue-600 hover:text-blue-800 cursor-pointer">
                                        <input type="checkbox"
                                            :checked="areAllPermissionsSelected"
                                            @change="toggleAllPermissions"
                                            class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                        <span>Select All Permissions</span>
                                    </label>
                                </div>
                                <div class="space-y-4">
                                    <div v-for="(perms, category) in permissions" :key="category" class="border rounded-lg p-4">
                                        <div class="flex items-center justify-between mb-2">
                                            <h4 class="font-medium text-gray-900 capitalize">{{ category }}</h4>
                                            <label class="flex items-center space-x-2 text-xs text-gray-600 hover:text-gray-900 cursor-pointer">
                                                <input type="checkbox" 
                                                    :checked="isCategorySelected(perms)" 
                                                    @change="toggleCategory(category, perms)"
                                                    class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                                <span>Select All</span>
                                            </label>
                                        </div>
                                        <div class="grid grid-cols-2 md:grid-cols-3 gap-2">
                                            <label v-for="permission in sortPermissions(perms)" :key="permission.id" class="flex items-center">
                                                <input type="checkbox" :value="permission.name" v-model="form.permissions"
                                                       class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                                <span class="ml-2 text-sm text-gray-700">{{ permission.name.split('.')[1] }}</span>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>

                        <div class="flex justify-end space-x-3">
                            <button type="button" @click="closeModal" 
                                    class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-200 rounded-md hover:bg-gray-300">
                                Cancel
                            </button>
                            <button type="submit" 
                                    class="px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-md hover:bg-blue-700">
                                {{ isEditing ? 'Update' : 'Create' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref, reactive, onMounted, watch, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import DataTable from '@/Components/DataTable.vue'
import { useToast } from '@/Composables/useToast'
import { useConfirm } from '@/Composables/useConfirm'
import { useErrorHandler } from '@/Composables/useErrorHandler'
import { usePagination } from '@/Composables/usePagination'
import { usePermission } from '@/Composables/usePermission'

const props = defineProps({
    roles: Object,
    permissions: Object,
    companies: Array
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

const form = reactive({
    name: '',
    permissions: [],
    companies: [],
    is_assignable: false,
    notify_on_ticket_create: false,
    notify_on_ticket_assign: false
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
    form.permissions = []
    form.companies = []
    form.is_assignable = false
    form.notify_on_ticket_create = false
    form.notify_on_ticket_assign = false
    showModal.value = true
}

const editRole = (role) => {
    isEditing.value = true
    currentRole.value = role
    form.name = role.name
    form.permissions = role.permissions.map(p => p.name)
    form.companies = role.companies ? role.companies.map(c => c.id) : []
    form.is_assignable = !!role.is_assignable
    form.notify_on_ticket_create = !!role.notify_on_ticket_create
    form.notify_on_ticket_assign = !!role.notify_on_ticket_assign
    showModal.value = true
}

const closeModal = () => {
    showModal.value = false
    form.name = ''
    form.permissions = []
    form.companies = []
    form.is_assignable = false
    form.notify_on_ticket_create = false
    form.notify_on_ticket_assign = false
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

const getAllPermissionNames = () => {
    return Object.values(props.permissions).flat().map(p => p.name);
};

const areAllPermissionsSelected = computed(() => {
    if (!props.permissions) return false;
    const allNames = getAllPermissionNames();
    return allNames.length > 0 && allNames.every(name => form.permissions.includes(name));
});

const toggleAllPermissions = () => {
    const allNames = getAllPermissionNames();
    
    if (areAllPermissionsSelected.value) {
        form.permissions = [];
    } else {
        form.permissions = [...allNames];
    }
}

const toggleCategory = (category, permissionsList) => {
    const allNames = permissionsList.map(p => p.name);
    const hasAll = allNames.every(name => form.permissions.includes(name));
    
    if (hasAll) {
        // Unselect all
        form.permissions = form.permissions.filter(name => !allNames.includes(name));
    } else {
        // Select all (add missing ones)
        const missing = allNames.filter(name => !form.permissions.includes(name));
        form.permissions = [...form.permissions, ...missing];
    }
}

const isCategorySelected = (permissionsList) => {
    if (!permissionsList || permissionsList.length === 0) return false;
    return permissionsList.every(p => form.permissions.includes(p.name));
}

const sortPermissions = (permissions) => {
    const order = ['view', 'create', 'edit', 'delete'];
    return permissions.sort((a, b) => {
        const aAction = a.name.split('.')[1];
        const bAction = b.name.split('.')[1];
        const aIndex = order.indexOf(aAction);
        const bIndex = order.indexOf(bAction);
        
        if (aIndex === -1 && bIndex === -1) return aAction.localeCompare(bAction);
        if (aIndex === -1) return 1;
        if (bIndex === -1) return -1;
        return aIndex - bIndex;
    });
}
</script>
