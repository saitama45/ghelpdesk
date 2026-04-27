<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref, computed, onMounted, watch } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import DataTable from '@/Components/DataTable.vue';
import MultiAutocomplete from '@/Components/MultiAutocomplete.vue';
import { useConfirm } from '@/Composables/useConfirm';
import { useErrorHandler } from '@/Composables/useErrorHandler';
import { useToast } from '@/Composables/useToast';
import { usePagination } from '@/Composables/usePagination';
import { usePermission } from '@/Composables/usePermission';

const props = defineProps({
    users: Object,
    roles: Array,
    stores: Array,
    managers: Array,
    departments: Array,
    units: Array,
    subUnits: Array,
});

const showCreateModal = ref(false);
const showEditModal = ref(false);
const showPasswordModal = ref(false);
const showStoresModal = ref(false);
const editingUser = ref(null);
const resetPasswordUser = ref(null);
const selectedUserStores = ref([]);
const { confirm } = useConfirm();
const { post, put, destroy } = useErrorHandler();
const { showError } = useToast();
const { hasPermission } = usePermission();

const allStoreIds = computed(() => props.stores.map(s => s.id));

const auditUserLabel = (user) => user?.name || user?.email || 'System';

const formatAuditDate = (value) => {
    if (!value) {
        return '-';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return '-';
    }

    return date.toLocaleString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        hour12: true,
    });
};

const isAllStoresSelected = (storeIds) => allStoreIds.value.length > 0 && allStoreIds.value.every(id => storeIds.includes(id));

const toggleAllStores = (form) => {
    form.store_ids = isAllStoresSelected(form.store_ids) ? [] : [...allStoreIds.value];
};

const pagination = usePagination(props.users, 'users.index');

onMounted(() => {
    pagination.updateData(props.users);
});

watch(() => props.users, (newUsers) => {
    pagination.updateData(newUsers);
}, { deep: true });

const createForm = useForm({
    name: '',
    email: '',
    password: '',
    role: '',
    department: '',
    unit: '',
    sub_unit: '',
    position: '',
    is_active: true,
    is_manager: false,
    store_ids: [],
    manager_ids: [],
});

const editForm = useForm({
    name: '',
    email: '',
    role: '',
    department: '',
    unit: '',
    sub_unit: '',
    position: '',
    is_active: true,
    is_manager: false,
    store_ids: [],
    manager_ids: [],
});

const passwordForm = useForm({
    password: '',
});

const createUser = () => {
    post(route('users.store'), createForm.data(), {
        onSuccess: () => {
            showCreateModal.value = false;
            createForm.reset();
        },
        onError: (errors) => {
            const errorMessage = Object.values(errors).flat().join(', ') || 'An error occurred'
            showError(errorMessage)
        }
    });
};

const editUser = (user) => {
    editingUser.value = user;
    editForm.name = user.name;
    editForm.email = user.email;
    editForm.role = user.roles[0]?.name || '';
    editForm.department = user.department || '';
    editForm.unit = user.unit || '';
    editForm.sub_unit = user.sub_unit || '';
    editForm.position = user.position || '';
    editForm.is_active = !!user.is_active;
    editForm.is_manager = !!user.is_manager;
    editForm.store_ids = user.stores?.map(s => s.id) || [];
    editForm.manager_ids = user.managers?.map(m => m.id) || [];
    showEditModal.value = true;
};

const updateUser = () => {
    put(route('users.update', editingUser.value.id), editForm.data(), {
        onSuccess: () => {
            showEditModal.value = false;
            editForm.reset();
            editingUser.value = null;
        },
        onError: (errors) => {
            const errorMessage = Object.values(errors).flat().join(', ') || 'An error occurred'
            showError(errorMessage)
        }
    });
};

const deleteUser = async (user) => {
    const confirmed = await confirm({
        title: 'Delete User',
        message: `Are you sure you want to delete "${user.name}"? This will permanently remove their account and all associated data.`
    })
    
    if (confirmed) {
        destroy(route('users.destroy', user.id), {
            onSuccess: () => {},
            onError: (errors) => {
                const errorMessage = Object.values(errors).flat().join(', ') || 'Cannot delete user'
                showError(errorMessage)
            }
        });
    }
};

const resetPassword = (user) => {
    resetPasswordUser.value = user;
    passwordForm.password = 'password123';
    showPasswordModal.value = true;
};

const viewAssignedStores = (user) => {
    selectedUserStores.value = user.stores || [];
    showStoresModal.value = true;
};

const updatePassword = () => {
    put(route('users.reset-password', resetPasswordUser.value.id), passwordForm.data(), {
        onSuccess: () => {
            showPasswordModal.value = false;
            passwordForm.reset();
            resetPasswordUser.value = null;
        },
        onError: (errors) => {
            const errorMessage = Object.values(errors).flat().join(', ') || 'An error occurred'
            showError(errorMessage)
        }
    });
};
</script>

<template>
    <Head title="Users - Help Desk" />

    <AppLayout>
        <template #header>
            Users
        </template>

        <div class="space-y-6">
            <!-- Data Table -->
            <DataTable
                title="User Management"
                subtitle="Manage system users and their roles"
                search-placeholder="Search users by name, email, department..."
                empty-message="No users found. Create your first user to get started."
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
                        v-if="hasPermission('users.create')"
                        @click="showCreateModal = true"
                        class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition-colors flex items-center space-x-2 text-sm font-medium shadow-sm whitespace-nowrap"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                        </svg>
                        <span>Add User</span>
                    </button>
                </template>

                <template #header>
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">User</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Role</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Department</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Reports To</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Sub-Unit</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Assigned Stores</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </template>

                <template #body="{ data }">
                    <tr v-for="user in data" :key="user.id" class="hover:bg-gray-50 transition-colors">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="flex items-center">
                                <div class="h-10 w-10 bg-gradient-to-br from-blue-500 to-blue-600 rounded-full flex items-center justify-center shadow-sm">
                                    <span class="text-sm font-medium text-white">{{ user.name.charAt(0).toUpperCase() }}</span>
                                </div>
                                <div class="ml-4">
                                    <div class="text-sm font-medium text-gray-900">{{ user.name }}</div>
                                    <div class="text-sm text-gray-500">{{ user.email }}</div>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-blue-100 text-blue-800">
                                {{ user.roles[0]?.name || 'No Role' }}
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                            {{ user.department || '-' }}
                        </td>
                        <td class="px-6 py-4">
                            <div v-if="user.managers?.length > 0" class="flex flex-wrap gap-1 max-w-[200px]">
                                <span v-for="manager in user.managers" :key="manager.id" 
                                      class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-purple-50 text-purple-700 border border-purple-100"
                                >
                                    {{ manager.name }}
                                </span>
                            </div>
                            <span v-else class="text-xs text-gray-400 italic">No Manager</span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 font-medium">
                            {{ user.sub_unit || '-' }}
                        </td>
                        <td class="px-6 py-4">
                            <button 
                                v-if="user.stores?.length > 0"
                                @click="viewAssignedStores(user)"
                                class="text-blue-600 hover:text-blue-800 text-xs font-medium flex items-center"
                            >
                                <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                                </svg>
                                View {{ user.stores.length }} assigned Store{{ user.stores.length > 1 ? 's' : '' }}
                            </button>
                            <span v-else class="text-xs text-gray-400 italic">No Stores</span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span :class="[
                                'inline-flex px-2 py-1 text-xs font-semibold rounded-full',
                                user.is_active ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'
                            ]">
                                {{ user.is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                            <div class="flex justify-end space-x-1">
                                <button
                                    v-if="hasPermission('users.edit')"
                                    @click="editUser(user)"
                                    class="p-2 text-blue-600 hover:text-blue-900 hover:bg-blue-50 rounded-full transition-colors"
                                    title="Edit User"
                                >
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                    </svg>
                                </button>
                                <button
                                    v-if="hasPermission('users.edit')"
                                    @click="resetPassword(user)"
                                    class="p-2 text-yellow-600 hover:text-yellow-900 hover:bg-yellow-50 rounded-full transition-colors"
                                    title="Reset Password"
                                >
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v-2L4.257 10.257a6 6 0 0111.486-3.486L16 6.5a2.5 2.5 0 11-1 4.5m-5 3v6a2 2 0 002 2h6a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2z" />
                                    </svg>
                                </button>
                                <button
                                    v-if="hasPermission('users.delete')"
                                    @click="deleteUser(user)"
                                    class="p-2 text-red-600 hover:text-red-900 hover:bg-red-50 rounded-full transition-colors"
                                    title="Delete User"
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

        <!-- Create User Modal -->
        <div v-if="showCreateModal" class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex items-center justify-center min-h-screen px-4 py-6">
                <div class="fixed inset-0 bg-black/20 backdrop-blur-md" @click="showCreateModal = false"></div>
                <div class="relative bg-white rounded-xl shadow-2xl w-full max-w-lg p-6 border border-gray-100 transform transition-all">
                    <div class="flex justify-between items-center mb-6">
                        <h3 class="text-xl font-bold text-gray-900">Create New User</h3>
                        <button @click="showCreateModal = false" class="text-gray-400 hover:text-gray-600 transition-colors">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <form @submit.prevent="createUser" class="space-y-4">
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Name</label>
                            <input v-model="createForm.name" type="text" required class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Email</label>
                            <input v-model="createForm.email" type="email" required class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Password</label>
                            <input v-model="createForm.password" type="password" required class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Role</label>
                            <select v-model="createForm.role" required class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                                <option value="">Select Role</option>
                                <option v-for="role in roles" :key="role.id" :value="role.name">{{ role.name }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Department</label>
                            <input v-model="createForm.department" type="text" list="departments-list" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                            <datalist id="departments-list">
                                <option v-for="dept in departments" :key="dept" :value="dept" />
                            </datalist>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Unit</label>
                                <input v-model="createForm.unit" type="text" list="units-list" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                                <datalist id="units-list">
                                    <option v-for="u in units" :key="u" :value="u" />
                                </datalist>
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Sub-Unit</label>
                                <input v-model="createForm.sub_unit" type="text" list="subunits-list" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                                <datalist id="subunits-list">
                                    <option v-for="su in subUnits" :key="su" :value="su" />
                                </datalist>
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Position</label>
                            <input v-model="createForm.position" type="text" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                        </div>
                        <div>
                            <div class="flex items-center justify-between mb-1">
                                <label class="text-xs font-bold text-gray-500 uppercase tracking-wider">Assigned Stores</label>
                                <button
                                    type="button"
                                    @click="toggleAllStores(createForm)"
                                    class="flex items-center gap-1.5 text-xs font-bold transition-colors"
                                    :class="isAllStoresSelected(createForm.store_ids) ? 'text-red-500 hover:text-red-700' : 'text-blue-600 hover:text-blue-800'"
                                >
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path v-if="isAllStoresSelected(createForm.store_ids)" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                        <path v-else stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                    {{ isAllStoresSelected(createForm.store_ids) ? 'Clear All' : 'Select All' }}
                                </button>
                            </div>
                            <MultiAutocomplete
                                v-model="createForm.store_ids"
                                :options="stores"
                                label-key="name"
                                value-key="id"
                                placeholder="Assign stores..."
                                :limit="5"
                            />
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div class="flex items-center">
                                <input v-model="createForm.is_active" type="checkbox" id="is_active_create" class="rounded border-gray-300 text-blue-600 shadow-sm focus:ring-blue-500">
                                <label for="is_active_create" class="ml-2 text-sm font-bold text-gray-700">Active Account</label>
                            </div>
                            <div class="flex items-center">
                                <input v-model="createForm.is_manager" type="checkbox" id="is_manager_create" class="rounded border-gray-300 text-purple-600 shadow-sm focus:ring-purple-500">
                                <label for="is_manager_create" class="ml-2 text-sm font-bold text-gray-700">Is Manager</label>
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Reports To</label>
                            <MultiAutocomplete
                                v-model="createForm.manager_ids"
                                :options="managers"
                                label-key="name"
                                value-key="id"
                                placeholder="Select managers..."
                                :limit="5"
                            />
                        </div>
                        <div class="flex justify-end space-x-3 pt-6 border-t mt-6">
                            <button type="button" @click="showCreateModal = false" class="px-4 py-2 text-sm font-semibold text-gray-600 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors">Cancel</button>
                            <button type="submit" :disabled="createForm.processing" class="px-6 py-2 bg-blue-600 text-white text-sm font-bold rounded-lg hover:bg-blue-700 shadow-md transition-all disabled:opacity-50">Create</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Edit User Modal -->
        <div v-if="showEditModal" class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex items-center justify-center min-h-screen px-4 py-6">
                <div class="fixed inset-0 bg-black/20 backdrop-blur-md" @click="showEditModal = false"></div>
                <div class="relative bg-white rounded-xl shadow-2xl w-full max-w-lg p-6 border border-gray-100 transform transition-all">
                    <div class="flex justify-between items-center mb-6">
                        <h3 class="text-xl font-bold text-gray-900">Edit User</h3>
                        <button @click="showEditModal = false" class="text-gray-400 hover:text-gray-600 transition-colors">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <form @submit.prevent="updateUser" class="space-y-4">
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Name</label>
                            <input v-model="editForm.name" type="text" required class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Email</label>
                            <input v-model="editForm.email" type="email" required class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Role</label>
                            <select v-model="editForm.role" required class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                                <option value="">Select Role</option>
                                <option v-for="role in roles" :key="role.id" :value="role.name">{{ role.name }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Department</label>
                            <input v-model="editForm.department" type="text" list="edit-departments-list" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                            <datalist id="edit-departments-list">
                                <option v-for="dept in departments" :key="dept" :value="dept" />
                            </datalist>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Unit</label>
                                <input v-model="editForm.unit" type="text" list="edit-units-list" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                                <datalist id="edit-units-list">
                                    <option v-for="u in units" :key="u" :value="u" />
                                </datalist>
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Sub-Unit</label>
                                <input v-model="editForm.sub_unit" type="text" list="edit-subunits-list" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                                <datalist id="edit-subunits-list">
                                    <option v-for="su in subUnits" :key="su" :value="su" />
                                </datalist>
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Position</label>
                            <input v-model="editForm.position" type="text" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                        </div>
                        <div>
                            <div class="flex items-center justify-between mb-1">
                                <label class="text-xs font-bold text-gray-500 uppercase tracking-wider">Assigned Stores</label>
                                <button
                                    type="button"
                                    @click="toggleAllStores(editForm)"
                                    class="flex items-center gap-1.5 text-xs font-bold transition-colors"
                                    :class="isAllStoresSelected(editForm.store_ids) ? 'text-red-500 hover:text-red-700' : 'text-blue-600 hover:text-blue-800'"
                                >
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path v-if="isAllStoresSelected(editForm.store_ids)" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                        <path v-else stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                    {{ isAllStoresSelected(editForm.store_ids) ? 'Clear All' : 'Select All' }}
                                </button>
                            </div>
                            <MultiAutocomplete
                                v-model="editForm.store_ids"
                                :options="stores"
                                label-key="name"
                                value-key="id"
                                placeholder="Assign stores..."
                                :limit="5"
                            />
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div class="flex items-center">
                                <input v-model="editForm.is_active" type="checkbox" id="is_active_edit" class="rounded border-gray-300 text-blue-600 shadow-sm focus:ring-blue-500">
                                <label for="is_active_edit" class="ml-2 text-sm font-bold text-gray-700">Active Account</label>
                            </div>
                            <div class="flex items-center">
                                <input v-model="editForm.is_manager" type="checkbox" id="is_manager_edit" class="rounded border-gray-300 text-purple-600 shadow-sm focus:ring-purple-500">
                                <label for="is_manager_edit" class="ml-2 text-sm font-bold text-gray-700">Is Manager</label>
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Reports To</label>
                            <MultiAutocomplete
                                v-model="editForm.manager_ids"
                                :options="managers"
                                label-key="name"
                                value-key="id"
                                placeholder="Select managers..."
                                :limit="5"
                            />
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-6 border-t mt-6">
                            <div class="rounded-lg bg-gray-50 border border-gray-100 px-3 py-2">
                                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wider">Created By</p>
                                <p class="text-sm font-semibold text-gray-800 truncate">{{ auditUserLabel(editingUser?.creator) }}</p>
                            </div>
                            <div class="rounded-lg bg-gray-50 border border-gray-100 px-3 py-2">
                                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wider">Updated By</p>
                                <p class="text-sm font-semibold text-gray-800 truncate">{{ auditUserLabel(editingUser?.updater) }}</p>
                            </div>
                            <div class="rounded-lg bg-gray-50 border border-gray-100 px-3 py-2">
                                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wider">Created At</p>
                                <p class="text-sm font-semibold text-gray-800">{{ formatAuditDate(editingUser?.created_at) }}</p>
                            </div>
                            <div class="rounded-lg bg-gray-50 border border-gray-100 px-3 py-2">
                                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wider">Updated At</p>
                                <p class="text-sm font-semibold text-gray-800">{{ formatAuditDate(editingUser?.updated_at) }}</p>
                            </div>
                        </div>
                        <div class="flex justify-end space-x-3 pt-2">
                            <button type="button" @click="showEditModal = false" class="px-4 py-2 text-sm font-semibold text-gray-600 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors">Cancel</button>
                            <button type="submit" :disabled="editForm.processing" class="px-6 py-2 bg-blue-600 text-white text-sm font-bold rounded-lg hover:bg-blue-700 shadow-md transition-all disabled:opacity-50">Update</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Password Reset Modal -->
        <div v-if="showPasswordModal" class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex items-center justify-center min-h-screen px-4 py-6">
                <div class="fixed inset-0 bg-black/20 backdrop-blur-md" @click="showPasswordModal = false"></div>
                <div class="relative bg-white rounded-xl shadow-2xl w-full max-w-md p-6 border border-gray-100 transform transition-all">
                    <div class="flex justify-between items-center mb-6">
                        <h3 class="text-xl font-bold text-gray-900">Reset Password</h3>
                        <button @click="showPasswordModal = false" class="text-gray-400 hover:text-gray-600 transition-colors">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                    
                    <div class="mb-6 p-4 bg-yellow-50 rounded-lg border border-yellow-100">
                        <p class="text-sm text-yellow-800">Resetting password for <strong class="font-bold text-yellow-900">{{ resetPasswordUser?.name }}</strong></p>
                    </div>

                    <form @submit.prevent="updatePassword" class="space-y-4">
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">New Password</label>
                            <input v-model="passwordForm.password" type="text" required class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                            <p class="text-[10px] text-gray-400 mt-1 uppercase font-bold italic">Suggested: password123</p>
                        </div>
                        <div class="flex justify-end space-x-3 pt-6 border-t mt-6">
                            <button type="button" @click="showPasswordModal = false" class="px-4 py-2 text-sm font-semibold text-gray-600 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors">Cancel</button>
                            <button type="submit" :disabled="passwordForm.processing" class="px-6 py-2 bg-yellow-600 text-white text-sm font-bold rounded-lg hover:bg-yellow-700 shadow-md transition-all disabled:opacity-50">Reset Password</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Assigned Stores Modal -->
        <div v-if="showStoresModal" class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex items-center justify-center min-h-screen px-4 py-6">
                <div class="fixed inset-0 bg-black/20 backdrop-blur-md" @click="showStoresModal = false"></div>
                <div class="relative bg-white rounded-xl shadow-2xl w-full max-w-lg p-6 border border-gray-100 transform transition-all">
                    <div class="flex items-center justify-between mb-6 border-b pb-4">
                        <h3 class="text-xl font-bold text-gray-900 flex items-center">
                            <svg class="w-6 h-6 mr-2 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                            </svg>
                            Assigned Stores
                        </h3>
                        <button @click="showStoresModal = false" class="text-gray-400 hover:text-gray-600 transition-colors">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                    
                    <div class="max-h-[60vh] overflow-y-auto pr-2 custom-scrollbar">
                        <div v-if="selectedUserStores.length > 0" class="grid grid-cols-1 gap-3">
                            <div v-for="store in selectedUserStores" :key="store.id" 
                                 class="flex items-center p-4 bg-gray-50 rounded-xl border border-gray-100 hover:bg-blue-50 hover:border-blue-200 transition-all group"
                            >
                                <div class="h-10 w-10 bg-white rounded-lg shadow-sm flex items-center justify-center mr-4 group-hover:scale-110 transition-transform">
                                    <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                                    </svg>
                                </div>
                                <div>
                                    <div class="text-sm font-bold text-gray-900 uppercase tracking-tight">{{ store.name }}</div>
                                    <div class="text-[10px] text-blue-600 font-black font-mono">CODE: {{ store.code || 'N/A' }}</div>
                                </div>
                            </div>
                        </div>
                        <div v-else class="text-center py-12">
                            <svg class="w-12 h-12 text-gray-300 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                            </svg>
                            <p class="text-sm text-gray-500 font-medium italic">No stores assigned to this user.</p>
                        </div>
                    </div>

                    <div class="mt-8 flex justify-end">
                        <button @click="showStoresModal = false" 
                                class="px-6 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors text-sm font-bold shadow-sm">
                            Close
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
