<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref, onMounted, watch } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import DataTable from '@/Components/DataTable.vue';
import { useConfirm } from '@/Composables/useConfirm';
import { useErrorHandler } from '@/Composables/useErrorHandler';
import { useToast } from '@/Composables/useToast';
import { usePagination } from '@/Composables/usePagination';
import { usePermission } from '@/Composables/usePermission';

const props = defineProps({
    users: Object,
    roles: Array,
});

const showCreateModal = ref(false);
const showEditModal = ref(false);
const showPasswordModal = ref(false);
const editingUser = ref(null);
const resetPasswordUser = ref(null);
const { confirm } = useConfirm();
const { post, put, destroy } = useErrorHandler();
const { showSuccess, showError } = useToast();
const { hasPermission } = usePermission();

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
    position: '',
});

const editForm = useForm({
    name: '',
    email: '',
    role: '',
    department: '',
    position: '',
});

const passwordForm = useForm({
    password: '',
});

const createUser = () => {
    post(route('users.store'), createForm.data(), {
        onSuccess: () => {
            showCreateModal.value = false;
            createForm.reset();
            showSuccess('User created successfully')
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
    editForm.position = user.position || '';
    showEditModal.value = true;
};

const updateUser = () => {
    put(route('users.update', editingUser.value.id), editForm.data(), {
        onSuccess: () => {
            showEditModal.value = false;
            editForm.reset();
            editingUser.value = null;
            showSuccess('User updated successfully')
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
            onSuccess: () => showSuccess('User deleted successfully'),
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

const updatePassword = () => {
    put(route('users.reset-password', resetPasswordUser.value.id), passwordForm.data(), {
        onSuccess: () => {
            showPasswordModal.value = false;
            passwordForm.reset();
            resetPasswordUser.value = null;
            showSuccess('Password reset successfully')
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
                        class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition-colors flex items-center space-x-2 text-sm font-medium"
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
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">
                                Active
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
            <div class="flex items-center justify-center min-h-screen px-4">
                <div class="fixed inset-0 bg-gray-500 bg-opacity-75" @click="showCreateModal = false"></div>
                <div class="bg-white rounded-lg shadow-xl max-w-md w-full p-6 relative">
                    <h3 class="text-lg font-medium text-gray-900 mb-4">Create New User</h3>
                    <form @submit.prevent="createUser" class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Name</label>
                            <input v-model="createForm.name" type="text" required class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Email</label>
                            <input v-model="createForm.email" type="email" required class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Password</label>
                            <input v-model="createForm.password" type="password" required class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Role</label>
                            <select v-model="createForm.role" required class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                                <option value="">Select Role</option>
                                <option v-for="role in roles" :key="role.id" :value="role.name">{{ role.name }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Department</label>
                            <input v-model="createForm.department" type="text" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        <div class="flex justify-end space-x-3 pt-4">
                            <button type="button" @click="showCreateModal = false" class="px-4 py-2 text-gray-700 bg-gray-200 rounded-md hover:bg-gray-300">Cancel</button>
                            <button type="submit" :disabled="createForm.processing" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 disabled:opacity-50">Create</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Edit User Modal -->
        <div v-if="showEditModal" class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex items-center justify-center min-h-screen px-4">
                <div class="fixed inset-0 bg-gray-500 bg-opacity-75" @click="showEditModal = false"></div>
                <div class="bg-white rounded-lg shadow-xl max-w-md w-full p-6 relative">
                    <h3 class="text-lg font-medium text-gray-900 mb-4">Edit User</h3>
                    <form @submit.prevent="updateUser" class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Name</label>
                            <input v-model="editForm.name" type="text" required class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Email</label>
                            <input v-model="editForm.email" type="email" required class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Role</label>
                            <select v-model="editForm.role" required class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                                <option value="">Select Role</option>
                                <option v-for="role in roles" :key="role.id" :value="role.name">{{ role.name }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Department</label>
                            <input v-model="editForm.department" type="text" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        <div class="flex justify-end space-x-3 pt-4">
                            <button type="button" @click="showEditModal = false" class="px-4 py-2 text-gray-700 bg-gray-200 rounded-md hover:bg-gray-300">Cancel</button>
                            <button type="submit" :disabled="editForm.processing" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 disabled:opacity-50">Update</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Password Reset Modal -->
        <div v-if="showPasswordModal" class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex items-center justify-center min-h-screen px-4">
                <div class="fixed inset-0 bg-gray-500 bg-opacity-75" @click="showPasswordModal = false"></div>
                <div class="bg-white rounded-lg shadow-xl max-w-md w-full p-6 relative">
                    <h3 class="text-lg font-medium text-gray-900 mb-4">Reset Password</h3>
                    <p class="text-sm text-gray-600 mb-4">Reset password for <strong>{{ resetPasswordUser?.name }}</strong></p>
                    <form @submit.prevent="updatePassword" class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">New Password</label>
                            <input v-model="passwordForm.password" type="text" required class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500">
                            <p class="text-xs text-gray-500 mt-1">Default: password123</p>
                        </div>
                        <div class="flex justify-end space-x-3 pt-4">
                            <button type="button" @click="showPasswordModal = false" class="px-4 py-2 text-gray-700 bg-gray-200 rounded-md hover:bg-gray-300">Cancel</button>
                            <button type="submit" :disabled="passwordForm.processing" class="px-4 py-2 bg-yellow-600 text-white rounded-md hover:bg-yellow-700 disabled:opacity-50">Reset Password</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
