<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref, reactive, computed, onMounted, watch } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import DataTable from '@/Components/DataTable.vue';
import Autocomplete from '@/Components/Autocomplete.vue';
import HierarchySelector from '@/Components/HierarchySelector.vue';
import MultiAutocomplete from '@/Components/MultiAutocomplete.vue';
import RoleFormModal from '@/Components/Roles/RoleFormModal.vue';
import { roleLandingPageOptions } from '@/Components/Roles/roleLandingPageOptions';
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
    permissions: Object,
    companies: Array,
    dynamicForms: Array,
    departmentTree: Array,
    filters: Object,
});

const showCreateModal = ref(false);
const showEditModal = ref(false);
const showPasswordModal = ref(false);
const showStoresModal = ref(false);
const editingUser = ref(null);
const resetPasswordUser = ref(null);
const selectedUserStores = ref([]);
const filterStatus = ref(props.filters?.status || '');
const filterRole = ref(props.filters?.role || '');
const { confirm } = useConfirm();
const { post, put, destroy } = useErrorHandler();
const { showError } = useToast();
const { hasPermission } = usePermission();

const allStoreIds = computed(() => props.stores.map(s => s.id));
const departmentOptions = computed(() => props.departmentTree || []);
const landingPageOptions = roleLandingPageOptions;

const flattenNodes = (nodes, level = 0) => {
    let flat = [];
    nodes.forEach(n => {
        flat.push({ ...n, level });
        if (n.children?.length) flat = flat.concat(flattenNodes(n.children, level + 1));
    });
    return flat;
};

const handleDepartmentChange = (form) => {
    form.department_node_id = '';
};

const formatOrganisation = (user) => {
    const parts = [user.department, user.org_path]
        .filter(part => part && String(part).trim() !== '');

    return parts.length ? parts.join(' > ') : '-';
};

const auditUserLabel = (user, userId = null) => {
    if (user?.name || user?.email) {
        return user.name || user.email;
    }

    if (userId) {
        return `User #${userId}`;
    }

    return 'System';
};

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
const isPendingApprovalUser = computed(() => editingUser.value?.google_id && !editingUser.value?.is_active && !(editingUser.value?.roles?.length));

const statusFilterOptions = [
    { label: 'All Statuses', value: '' },
    { label: 'Active', value: 'active' },
    { label: 'Inactive', value: 'inactive' },
    { label: 'Pending Approval', value: 'pending_approval' },
];

const roleFilterOptions = computed(() => [
    { label: 'All Roles', value: '' },
    ...(props.roles || []).map(r => ({ label: r.name, value: r.name })),
    { label: 'No Role', value: 'none' },
]);

const toggleAllStores = (form) => {
    form.store_ids = isAllStoresSelected(form.store_ids) ? [] : [...allStoreIds.value];
};

const pagination = usePagination(props.users, 'users.index', () => ({
    status: filterStatus.value,
    role: filterRole.value,
}));

onMounted(() => {
    pagination.search.value = props.filters?.search || '';
    pagination.updateData(props.users);
});

watch(() => props.users, (newUsers) => {
    pagination.updateData(newUsers);
}, { deep: true });

watch([filterStatus, filterRole], () => {
    pagination.currentPage.value = 1;
    pagination.performSearch();
});

const createForm = useForm({
    name: '',
    email: '',
    password: '',
    role: '',
    department_id: '',
    department_node_id: '',
    position: '',
    date_hired: '',
    is_active: true,
    is_manager: false,
    store_ids: [],
    manager_ids: [],
});

const editForm = useForm({
    name: '',
    email: '',
    role: '',
    department_id: '',
    department_node_id: '',
    position: '',
    date_hired: '',
    is_active: true,
    is_manager: false,
    store_ids: [],
    manager_ids: [],
    notify_user_approval: true,
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
    editForm.department_id = user.department_id || '';
    editForm.department_node_id = user.department_node_id || '';
    editForm.position = user.position || '';
    editForm.date_hired = user.date_hired ? String(user.date_hired).substring(0, 10) : '';
    editForm.is_active = user.google_id && !user.is_active && !(user.roles?.length) ? true : !!user.is_active;
    editForm.is_manager = !!user.is_manager;
    editForm.store_ids = user.stores?.map(s => s.id) || [];
    editForm.manager_ids = user.managers?.map(m => m.id) || [];
    editForm.notify_user_approval = true;
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

// Role Edit Modal
const showRoleModal = ref(false);
const editingRole = ref(null);
const rolePermissionSearch = ref('');
const activeRoleTab = ref('');

const roleForm = reactive({
    name: '',
    landing_page: 'dashboard',
    permissions: [],
    companies: [],
    is_assignable: false,
    notify_on_ticket_create: false,
    notify_on_ticket_assign: false,
    notify_on_urgent_ticket: false,
    notify_on_user_registration: false,
});

const openRoleEditModal = (userRole) => {
    if (!userRole) return;
    const fullRole = props.roles.find(r => r.id === userRole.id);
    if (!fullRole) return;
    editingRole.value = fullRole;
    roleForm.name = fullRole.name;
    roleForm.landing_page = fullRole.landing_page || 'dashboard';
    roleForm.permissions = fullRole.permissions.map(p => p.name);
    roleForm.companies = fullRole.companies ? fullRole.companies.map(c => c.id) : [];
    roleForm.is_assignable = !!fullRole.is_assignable;
    roleForm.notify_on_ticket_create = !!fullRole.notify_on_ticket_create;
    roleForm.notify_on_ticket_assign = !!fullRole.notify_on_ticket_assign;
    roleForm.notify_on_urgent_ticket = !!fullRole.notify_on_urgent_ticket;
    roleForm.notify_on_user_registration = !!fullRole.notify_on_user_registration;
    rolePermissionSearch.value = '';
    showRoleModal.value = true;
};

const closeRoleModal = () => {
    showRoleModal.value = false;
    editingRole.value = null;
    rolePermissionSearch.value = '';
};

const submitRoleForm = () => {
    if (roleForm.companies.length === 0) {
        showError('Please select at least one company');
        return;
    }
    put(`/roles/${editingRole.value.id}`, roleForm, {
        onSuccess: () => closeRoleModal(),
        onError: (errors) => {
            const errorMessage = Object.values(errors).flat().join(', ') || 'An error occurred';
            showError(errorMessage);
        },
    });
};

const rolePermissionGroups = computed(() => {
    const servicesCategories = ['Tickets', 'Queue', 'Task Board', 'Pos_requests', 'Sap_requests'];
    (props.dynamicForms || []).forEach(f => servicesCategories.push(f.name));
    return [
        { name: 'Dashboard', categories: ['Dashboard'] },
        { name: 'Project Tracker', categories: ['Projects'] },
        { name: 'Services', categories: servicesCategories },
        { name: 'Inventory', categories: ['Assets', 'Stock_in', 'Stock_transfer', 'Reports'] },
        { name: 'Monitoring', categories: ['NPC Status', 'CCTV Monitoring', 'WIGS', 'Payments & SOA', 'Mall Hookup'] },
        { name: 'Administrative', categories: ['Attendance', 'Schedules', 'Presence', 'KB Articles'] },
        { name: 'References', categories: ['Companies', 'Departments', 'Clusters', 'Stores', 'Vendors', 'Activity_templates', 'Project Type & Store Class', 'Categories', 'Subcategories', 'Items', 'Request_types', 'Form_builder'] },
        { name: 'Reports', categories: ['Reports'] },
        { name: 'User Management', categories: ['Users', 'Roles'] },
        { name: 'Settings', categories: ['Settings', 'Canned_messages', 'Leadership Points'] },
    ];
});

const groupedRolePermissions = computed(() => {
    const search = rolePermissionSearch.value.toLowerCase();
    const result = [];
    const availableCategories = Object.keys(props.permissions || {});
    const mappedKeys = new Set();

    rolePermissionGroups.value.forEach(group => {
        const groupCategories = [];
        group.categories.forEach(catName => {
            const normalizedCatName = catName.toLowerCase().replace(/[^a-z0-9]/g, '');
            const actualKey = availableCategories.find(k => k.toLowerCase().replace(/[^a-z0-9]/g, '') === normalizedCatName);
            if (actualKey && !mappedKeys.has(actualKey)) {
                const perms = props.permissions[actualKey];
                if (perms) {
                    let filteredPerms = perms.filter(p => p.name.toLowerCase().includes(search));
                    if (group.name === 'Inventory' && actualKey === 'Reports') {
                        filteredPerms = filteredPerms.filter(p => p.name === 'reports.inventory');
                    } else if (group.name === 'Reports' && actualKey === 'Reports') {
                        filteredPerms = filteredPerms.filter(p => p.name !== 'reports.inventory');
                    }
                    if (filteredPerms.length > 0) {
                        groupCategories.push({ name: actualKey, permissions: filteredPerms });
                        if (group.name !== 'Inventory' || actualKey !== 'Reports') {
                            mappedKeys.add(actualKey);
                        }
                    }
                }
            }
        });
        if (groupCategories.length > 0) {
            result.push({ name: group.name, categories: groupCategories });
        }
    });

    const otherCategories = [];
    availableCategories.forEach(catName => {
        if (!mappedKeys.has(catName)) {
            const perms = props.permissions[catName];
            if (perms) {
                const filteredPerms = perms.filter(p => p.name.toLowerCase().includes(search));
                if (filteredPerms.length > 0) {
                    otherCategories.push({ name: catName, permissions: filteredPerms });
                }
            }
        }
    });
    if (otherCategories.length > 0) {
        result.push({ name: 'Other', categories: otherCategories });
    }
    return result;
});

watch(groupedRolePermissions, (newGroups) => {
    if (newGroups.length > 0 && (!activeRoleTab.value || !newGroups.find(g => g.name === activeRoleTab.value))) {
        activeRoleTab.value = newGroups[0].name;
    }
}, { immediate: true });

const isRoleGroupSelected = (group) => {
    if (!group?.categories) return false;
    const allNames = group.categories.flatMap(c => c.permissions.map(p => p.name));
    return allNames.length > 0 && allNames.every(name => roleForm.permissions.includes(name));
};

const toggleRoleGroup = (group) => {
    if (!group?.categories) return;
    const allNames = group.categories.flatMap(c => c.permissions.map(p => p.name));
    if (!allNames.length) return;
    const hasAll = allNames.every(name => roleForm.permissions.includes(name));
    if (hasAll) {
        roleForm.permissions = roleForm.permissions.filter(name => !allNames.includes(name));
    } else {
        roleForm.permissions = [...roleForm.permissions, ...allNames.filter(name => !roleForm.permissions.includes(name))];
    }
};

const getAllRolePermissionNames = () => Object.values(props.permissions || {}).flat().map(p => p.name);

const areAllRolePermissionsSelected = computed(() => {
    const allNames = getAllRolePermissionNames();
    return allNames.length > 0 && allNames.every(name => roleForm.permissions.includes(name));
});

const toggleAllRolePermissions = () => {
    const allNames = getAllRolePermissionNames();
    roleForm.permissions = areAllRolePermissionsSelected.value ? [] : [...allNames];
};

const toggleRoleCategory = (category, permissionsList) => {
    const allNames = permissionsList.map(p => p.name);
    const hasAll = allNames.every(name => roleForm.permissions.includes(name));
    if (hasAll) {
        roleForm.permissions = roleForm.permissions.filter(name => !allNames.includes(name));
    } else {
        roleForm.permissions = [...roleForm.permissions, ...allNames.filter(name => !roleForm.permissions.includes(name))];
    }
};

const isRoleCategorySelected = (permissionsList) => {
    if (!permissionsList?.length) return false;
    return permissionsList.every(p => roleForm.permissions.includes(p.name));
};

const toggleAllRoleCompanies = () => {
    roleForm.companies = roleForm.companies.length === props.companies.length
        ? []
        : props.companies.map(c => c.id);
};

const sortRolePermissions = (permissions) => {
    const order = ['view', 'show', 'create', 'edit', 'post', 'delete', 'approve', 'canned_messages', 'internal_notes'];
    return [...permissions].sort((a, b) => {
        const aAction = a.name.split('.')[1];
        const bAction = b.name.split('.')[1];
        const aIndex = order.indexOf(aAction);
        const bIndex = order.indexOf(bAction);
        if (aIndex === -1 && bIndex === -1) return aAction.localeCompare(bAction);
        if (aIndex === -1) return 1;
        if (bIndex === -1) return -1;
        return aIndex - bIndex;
    });
};
</script>

<template>
    <Head title="Users - Help Desk" />

    <AppLayout content-class="w-full max-w-none px-2 sm:px-4 lg:px-6">
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
                    <div class="w-full sm:w-48">
                        <Autocomplete
                            v-model="filterRole"
                            :options="roleFilterOptions"
                            label-key="label"
                            value-key="value"
                            placeholder="Filter role..."
                            size="sm"
                        />
                    </div>
                    <div class="w-full sm:w-48">
                        <Autocomplete
                            v-model="filterStatus"
                            :options="statusFilterOptions"
                            label-key="label"
                            value-key="value"
                            placeholder="Filter status..."
                            size="sm"
                        />
                    </div>
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
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-slate-300">User</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-slate-300">Role</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-slate-300">Organisation</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-slate-300">Reports To</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-slate-300">Assigned Stores</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-slate-300">Status</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-slate-300">Actions</th>
                    </tr>
                </template>

                <template #body="{ data }">
                    <tr v-for="user in data" :key="user.id" class="hover:bg-gray-50 transition-colors dark:hover:bg-gray-700">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="flex items-center">
                                <div class="h-10 w-10 bg-gradient-to-br from-blue-500 to-blue-600 rounded-full flex items-center justify-center shadow-sm">
                                    <span class="text-sm font-medium text-white">{{ user.name.charAt(0).toUpperCase() }}</span>
                                </div>
                                <div class="ml-4">
                                    <div class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ user.name }}</div>
                                    <div class="text-sm text-gray-500 dark:text-gray-300">{{ user.email }}</div>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span
                                class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-blue-100 text-blue-800 transition-colors"
                                :class="hasPermission('roles.edit') && user.roles[0] ? 'cursor-pointer hover:bg-blue-200' : ''"
                                @click="hasPermission('roles.edit') && user.roles[0] && openRoleEditModal(user.roles[0])"
                                :title="hasPermission('roles.edit') && user.roles[0] ? 'Click to edit role permissions' : ''"
                            >
                                {{ user.roles[0]?.name || 'No Role' }}
                            </span>
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-900 dark:text-gray-100">
                            <div class="max-w-[260px] font-medium leading-5">
                                {{ formatOrganisation(user) }}
                            </div>
                        </td>
                        <td class="px-6 py-4">
                            <div v-if="user.managers?.length > 0" class="flex flex-wrap gap-1 max-w-[200px]">
                                <span v-for="manager in user.managers" :key="manager.id" 
                                      class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-purple-50 text-purple-700 border border-purple-100"
                                >
                                    {{ manager.name }}
                                </span>
                            </div>
                            <span v-else class="text-xs text-gray-400 italic dark:text-gray-400">No Manager</span>
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
                            <span v-else class="text-xs text-gray-400 italic dark:text-gray-400">No Stores</span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span :class="[
                                'inline-flex px-2 py-1 text-xs font-semibold rounded-full',
                                user.google_id && !user.is_active && !(user.roles?.length) ? 'bg-amber-100 text-amber-800' : (user.is_active ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800')
                            ]">
                                {{ user.google_id && !user.is_active && !(user.roles?.length) ? 'Pending Approval' : (user.is_active ? 'Active' : 'Inactive') }}
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
                <div class="relative bg-white rounded-xl shadow-2xl w-full max-w-lg p-6 border border-gray-100 transform transition-all dark:bg-gray-800 dark:border-gray-700">
                    <div class="flex justify-between items-center mb-6">
                        <h3 class="text-xl font-bold text-gray-900 dark:text-gray-100">Create New User</h3>
                        <button @click="showCreateModal = false" class="text-gray-400 hover:text-gray-600 transition-colors dark:text-gray-400">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <form @submit.prevent="createUser" class="space-y-4">
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1 dark:text-gray-300">Name</label>
                            <input v-model="createForm.name" type="text" required class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1 dark:text-gray-300">Email</label>
                            <input v-model="createForm.email" type="email" required class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1 dark:text-gray-300">Password</label>
                            <input v-model="createForm.password" type="password" required class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1 dark:text-gray-300">Role</label>
                            <select v-model="createForm.role" required class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600">
                                <option value="">Select Role</option>
                                <option v-for="role in roles" :key="role.id" :value="role.name">{{ role.name }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1 dark:text-gray-300">Department</label>
                            <select v-model="createForm.department_id" @change="handleDepartmentChange(createForm)" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600">
                                <option value="">No Organisation</option>
                                <option v-for="department in departmentOptions" :key="department.id" :value="department.id">{{ department.name }}</option>
                            </select>
                        </div>
                        <div v-if="createForm.department_id">
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1 dark:text-gray-300">Team / Placement</label>
                            <HierarchySelector
                                v-model="createForm.department_node_id"
                                :nodes="departmentOptions.find(d => Number(d.id) === Number(createForm.department_id))?.nodes || []"
                                label="Select Team Level"
                                inline
                            />
                        </div>
                        <div v-if="createForm.department_id && !createForm.department_node_id" class="rounded-lg border border-amber-100 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-700">
                            You have selected a Department. Team placement is optional.
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1 dark:text-gray-300">Position</label>
                            <input v-model="createForm.position" type="text" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1 dark:text-gray-300">Date Hired</label>
                            <input v-model="createForm.date_hired" type="date" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600">
                        </div>
                        <div>
                            <div class="flex items-center justify-between mb-1">
                                <label class="text-xs font-bold text-gray-500 uppercase tracking-wider dark:text-gray-300">Assigned Stores</label>
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
                                <input v-model="createForm.is_active" type="checkbox" id="is_active_create" class="rounded border-gray-300 text-blue-600 shadow-sm focus:ring-blue-500 dark:border-gray-600">
                                <label for="is_active_create" class="ml-2 text-sm font-bold text-gray-700 dark:text-gray-300">Active Account</label>
                            </div>
                            <div class="flex items-center">
                                <input v-model="createForm.is_manager" type="checkbox" id="is_manager_create" class="rounded border-gray-300 text-purple-600 shadow-sm focus:ring-purple-500 dark:border-gray-600">
                                <label for="is_manager_create" class="ml-2 text-sm font-bold text-gray-700 dark:text-gray-300">Is Manager</label>
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1 dark:text-gray-300">Reports To</label>
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
                            <button type="button" @click="showCreateModal = false" class="px-4 py-2 text-sm font-semibold text-gray-600 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700">Cancel</button>
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
                <div class="relative bg-white rounded-xl shadow-2xl w-full max-w-lg p-6 border border-gray-100 transform transition-all dark:bg-gray-800 dark:border-gray-700">
                    <div class="flex justify-between items-center mb-6">
                        <h3 class="text-xl font-bold text-gray-900 dark:text-gray-100">Edit User</h3>
                        <button @click="showEditModal = false" class="text-gray-400 hover:text-gray-600 transition-colors dark:text-gray-400">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <form @submit.prevent="updateUser" class="space-y-4">
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1 dark:text-gray-300">Name</label>
                            <input v-model="editForm.name" type="text" required class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1 dark:text-gray-300">Email</label>
                            <input v-model="editForm.email" type="email" required class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1 dark:text-gray-300">Role</label>
                            <select v-model="editForm.role" required class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600">
                                <option value="">Select Role</option>
                                <option v-for="role in roles" :key="role.id" :value="role.name">{{ role.name }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1 dark:text-gray-300">Department</label>
                            <select v-model="editForm.department_id" @change="handleDepartmentChange(editForm)" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600">
                                <option value="">No Organisation</option>
                                <option v-for="department in departmentOptions" :key="department.id" :value="department.id">{{ department.name }}</option>
                            </select>
                        </div>
                        <div v-if="editForm.department_id">
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1 dark:text-gray-300">Team / Placement</label>
                            <HierarchySelector
                                v-model="editForm.department_node_id"
                                :nodes="departmentOptions.find(d => Number(d.id) === Number(editForm.department_id))?.nodes || []"
                                label="Select Team Level"
                                inline
                            />
                        </div>
                        <div v-if="editForm.department_id && !editForm.department_node_id" class="rounded-lg border border-amber-100 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-700">
                            You have selected a Department. Team placement is optional.
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1 dark:text-gray-300">Position</label>
                            <input v-model="editForm.position" type="text" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1 dark:text-gray-300">Date Hired</label>
                            <input v-model="editForm.date_hired" type="date" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600">
                        </div>
                        <div>
                            <div class="flex items-center justify-between mb-1">
                                <label class="text-xs font-bold text-gray-500 uppercase tracking-wider dark:text-gray-300">Assigned Stores</label>
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
                                <input v-model="editForm.is_active" type="checkbox" id="is_active_edit" class="rounded border-gray-300 text-blue-600 shadow-sm focus:ring-blue-500 dark:border-gray-600">
                                <label for="is_active_edit" class="ml-2 text-sm font-bold text-gray-700 dark:text-gray-300">Active Account</label>
                            </div>
                            <div class="flex items-center">
                                <input v-model="editForm.is_manager" type="checkbox" id="is_manager_edit" class="rounded border-gray-300 text-purple-600 shadow-sm focus:ring-purple-500 dark:border-gray-600">
                                <label for="is_manager_edit" class="ml-2 text-sm font-bold text-gray-700 dark:text-gray-300">Is Manager</label>
                            </div>
                        </div>
                        <div v-if="isPendingApprovalUser" class="flex flex-col justify-center p-4 bg-emerald-50 rounded-xl border border-emerald-100">
                            <label class="flex items-center justify-between cursor-pointer gap-4">
                                <span class="text-sm font-bold text-emerald-900">Notify user by email that they can now sign in</span>
                                <div class="relative shrink-0">
                                    <input type="checkbox" v-model="editForm.notify_user_approval" class="sr-only peer">
                                    <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-emerald-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-emerald-600 dark:bg-gray-700"></div>
                                </div>
                            </label>
                            <p class="text-[10px] text-emerald-700 mt-1 uppercase font-bold italic">Only shown for pending Google registrations.</p>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1 dark:text-gray-300">Reports To</label>
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
                            <div class="rounded-lg bg-gray-50 border border-gray-100 px-3 py-2 dark:bg-gray-900/50 dark:border-gray-700">
                                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wider dark:text-gray-400">Created By</p>
                                <p class="text-sm font-semibold text-gray-800 truncate dark:text-gray-200">{{ auditUserLabel(editingUser?.creator, editingUser?.created_by) }}</p>
                            </div>
                            <div class="rounded-lg bg-gray-50 border border-gray-100 px-3 py-2 dark:bg-gray-900/50 dark:border-gray-700">
                                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wider dark:text-gray-400">Updated By</p>
                                <p class="text-sm font-semibold text-gray-800 truncate dark:text-gray-200">{{ auditUserLabel(editingUser?.updater, editingUser?.updated_by) }}</p>
                            </div>
                            <div class="rounded-lg bg-gray-50 border border-gray-100 px-3 py-2 dark:bg-gray-900/50 dark:border-gray-700">
                                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wider dark:text-gray-400">Created At</p>
                                <p class="text-sm font-semibold text-gray-800 dark:text-gray-200">{{ formatAuditDate(editingUser?.created_at) }}</p>
                            </div>
                            <div class="rounded-lg bg-gray-50 border border-gray-100 px-3 py-2 dark:bg-gray-900/50 dark:border-gray-700">
                                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wider dark:text-gray-400">Updated At</p>
                                <p class="text-sm font-semibold text-gray-800 dark:text-gray-200">{{ formatAuditDate(editingUser?.updated_at) }}</p>
                            </div>
                        </div>
                        <div class="flex justify-end space-x-3 pt-2">
                            <button type="button" @click="showEditModal = false" class="px-4 py-2 text-sm font-semibold text-gray-600 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700">Cancel</button>
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
                <div class="relative bg-white rounded-xl shadow-2xl w-full max-w-md p-6 border border-gray-100 transform transition-all dark:bg-gray-800 dark:border-gray-700">
                    <div class="flex justify-between items-center mb-6">
                        <h3 class="text-xl font-bold text-gray-900 dark:text-gray-100">Reset Password</h3>
                        <button @click="showPasswordModal = false" class="text-gray-400 hover:text-gray-600 transition-colors dark:text-gray-400">
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
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1 dark:text-gray-300">New Password</label>
                            <input v-model="passwordForm.password" type="text" required class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600">
                            <p class="text-[10px] text-gray-400 mt-1 uppercase font-bold italic dark:text-gray-400">Suggested: password123</p>
                        </div>
                        <div class="flex justify-end space-x-3 pt-6 border-t mt-6">
                            <button type="button" @click="showPasswordModal = false" class="px-4 py-2 text-sm font-semibold text-gray-600 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700">Cancel</button>
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
                <div class="relative bg-white rounded-xl shadow-2xl w-full max-w-lg p-6 border border-gray-100 transform transition-all dark:bg-gray-800 dark:border-gray-700">
                    <div class="flex items-center justify-between mb-6 border-b pb-4">
                        <h3 class="text-xl font-bold text-gray-900 flex items-center dark:text-gray-100">
                            <svg class="w-6 h-6 mr-2 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                            </svg>
                            Assigned Stores
                        </h3>
                        <button @click="showStoresModal = false" class="text-gray-400 hover:text-gray-600 transition-colors dark:text-gray-400">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                    
                    <div class="max-h-[60vh] overflow-y-auto pr-2 custom-scrollbar">
                        <div v-if="selectedUserStores.length > 0" class="grid grid-cols-1 gap-3">
                            <div v-for="store in selectedUserStores" :key="store.id" 
                                 class="flex items-center p-4 bg-gray-50 rounded-xl border border-gray-100 hover:bg-blue-50 hover:border-blue-200 transition-all group dark:bg-gray-900/50 dark:border-gray-700"
                            >
                                <div class="h-10 w-10 bg-white rounded-lg shadow-sm flex items-center justify-center mr-4 group-hover:scale-110 transition-transform dark:bg-gray-800">
                                    <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                                    </svg>
                                </div>
                                <div>
                                    <div class="text-sm font-bold text-gray-900 uppercase tracking-tight dark:text-gray-100">{{ store.name }}</div>
                                    <div class="text-[10px] text-blue-600 font-black font-mono">CODE: {{ store.code || 'N/A' }}</div>
                                </div>
                            </div>
                        </div>
                        <div v-else class="text-center py-12">
                            <svg class="w-12 h-12 text-gray-300 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                            </svg>
                            <p class="text-sm text-gray-500 font-medium italic dark:text-gray-300">No stores assigned to this user.</p>
                        </div>
                    </div>

                    <div class="mt-8 flex justify-end">
                        <button @click="showStoresModal = false" 
                                class="px-6 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors text-sm font-bold shadow-sm dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700">
                            Close
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <RoleFormModal
            :show="showRoleModal"
            :title="`Edit Role: ${editingRole?.name || ''}`"
            submit-label="Update Role"
            :form="roleForm"
            :permissions="permissions"
            :companies="companies"
            :dynamic-forms="dynamicForms"
            :landing-page-options="landingPageOptions"
            @close="closeRoleModal"
            @submit="submitRoleForm"
        />

        <!-- Legacy inline role modal kept inactive; RoleFormModal is the shared source. -->
        <div v-if="false && showRoleModal" class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex items-center justify-center min-h-screen px-4 py-6">
                <div class="fixed inset-0 bg-black/20 backdrop-blur-md" @click="closeRoleModal"></div>
                <div class="relative bg-white rounded-xl shadow-2xl w-full max-w-4xl p-6 border border-gray-100 transform transition-all dark:bg-gray-800 dark:border-gray-700">
                    <div class="flex justify-between items-center mb-6">
                        <h3 class="text-xl font-bold text-gray-900 dark:text-gray-100">Edit Role: {{ editingRole?.name }}</h3>
                        <button @click="closeRoleModal" class="text-gray-400 hover:text-gray-600 transition-colors dark:text-gray-400">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <form @submit.prevent="submitRoleForm" class="space-y-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1 dark:text-gray-300">Role Name</label>
                                <input v-model="roleForm.name" type="text" required
                                       class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1 dark:text-gray-300">Default Landing Page</label>
                                <select v-model="roleForm.landing_page"
                                        class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600">
                                    <option value="dashboard">Dashboard</option>
                                    <option value="tickets.index">Tickets</option>
                                    <option value="pos-requests.index">POS Requests</option>
                                    <option value="sap-requests.index">SAP Requests</option>
                                    <option value="assets.index">Assets</option>
                                    <option value="stock-ins.index">Stock In</option>
                                    <option value="stock-transfers.index">Stock Transfer</option>
                                    <option value="reports.inventory">Inventory Report</option>
                                    <option value="attendance.index">DTR (Attendance)</option>
                                    <option value="attendance.logs">Attendance Logs</option>
                                    <option value="schedules.index">Scheduling</option>
                                    <option value="presence.index">Presence</option>
                                    <option value="kb-articles.index">KB Articles</option>
                                    <option value="npc-statuses.index">NPC Status</option>
                                    <option value="payments.index">Payments & SOA</option>
                                    <option value="mall-hookups.index">Mall Hookup</option>
                                    <option value="reports.store-health">Store Health Report</option>
                                    <option value="companies.index">Companies</option>
                                    <option value="departments.index">Departments</option>
                                    <option value="clusters.index">Clusters</option>
                                    <option value="stores.index">Stores</option>
                                    <option value="vendors.index">Vendors</option>
                                    <option value="categories.index">Categories</option>
                                    <option value="sub-categories.index">Sub-Categories</option>
                                    <option value="items.index">Items</option>
                                    <option value="request-types.index">Request Types</option>
                                    <option value="form-builder.index">Form Builder</option>
                                    <option value="users.index">Users</option>
                                    <option value="roles.index">Roles & Permissions</option>
                                    <option value="settings.index">System Settings</option>
                                    <option value="canned-messages.index">Canned Messages</option>
                                    <option value="profile.edit">My Profile</option>
                                </select>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div class="flex flex-col justify-center p-4 bg-blue-50 rounded-xl border border-blue-100">
                                <label class="flex items-center space-x-3 cursor-pointer">
                                    <div class="relative">
                                        <input type="checkbox" v-model="roleForm.is_assignable" class="sr-only peer">
                                        <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600 dark:bg-gray-700"></div>
                                    </div>
                                    <span class="text-sm font-bold text-blue-900">Assignable to Tickets</span>
                                </label>
                                <p class="text-[10px] text-blue-600 mt-1 uppercase font-bold italic">Users with this role appear in "Assignee" list.</p>
                            </div>

                            <div class="p-4 bg-gray-50 rounded-xl border border-gray-100 dark:bg-gray-900/50 dark:border-gray-700">
                                <h4 class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-3 dark:text-gray-400">Email Notifications</h4>
                                <div class="space-y-3">
                                    <label class="flex items-center justify-between cursor-pointer group">
                                        <span class="text-sm font-medium text-gray-700 group-hover:text-blue-600 transition-colors dark:text-gray-300">On Ticket Creation</span>
                                        <div class="relative">
                                            <input type="checkbox" v-model="roleForm.notify_on_ticket_create" class="sr-only peer">
                                            <div class="w-9 h-5 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-blue-600 dark:bg-gray-700"></div>
                                        </div>
                                    </label>
                                    <label class="flex items-center justify-between cursor-pointer group">
                                        <span class="text-sm font-medium text-gray-700 group-hover:text-blue-600 transition-colors dark:text-gray-300">When Assigned</span>
                                        <div class="relative">
                                            <input type="checkbox" v-model="roleForm.notify_on_ticket_assign" class="sr-only peer">
                                            <div class="w-9 h-5 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-blue-600 dark:bg-gray-700"></div>
                                        </div>
                                    </label>
                                    <label class="flex items-center justify-between cursor-pointer group">
                                        <span class="text-sm font-medium text-gray-700 group-hover:text-red-600 transition-colors flex items-center gap-1.5 dark:text-gray-300">
                                            On Urgent Ticket
                                            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-black bg-red-100 text-red-700 border border-red-200">P1</span>
                                        </span>
                                        <div class="relative">
                                            <input type="checkbox" v-model="roleForm.notify_on_urgent_ticket" class="sr-only peer">
                                            <div class="w-9 h-5 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-red-500 dark:bg-gray-700"></div>
                                        </div>
                                    </label>
                                    <label class="flex items-center justify-between cursor-pointer group">
                                        <span class="text-sm font-medium text-gray-700 group-hover:text-emerald-600 transition-colors dark:text-gray-300">On User Registration</span>
                                        <div class="relative">
                                            <input type="checkbox" v-model="roleForm.notify_on_user_registration" class="sr-only peer">
                                            <div class="w-9 h-5 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-emerald-600 dark:bg-gray-700"></div>
                                        </div>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div class="md:col-span-1">
                                <div class="flex items-center justify-between mb-2">
                                    <label class="text-xs font-bold text-gray-500 uppercase tracking-wider dark:text-gray-300">Companies</label>
                                    <button type="button" @click="toggleAllRoleCompanies" class="text-[10px] font-black text-blue-600 uppercase hover:text-blue-800">
                                        {{ roleForm.companies.length === companies.length ? 'Unselect All' : 'Select All' }}
                                    </button>
                                </div>
                                <div class="space-y-2 max-h-64 overflow-y-auto border border-gray-200 rounded-xl p-4 bg-white shadow-inner custom-scrollbar dark:bg-gray-800 dark:border-gray-700">
                                    <label v-for="company in companies" :key="company.id" class="flex items-center group cursor-pointer">
                                        <input type="checkbox" :value="company.id" v-model="roleForm.companies"
                                               class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 group-hover:border-blue-400 transition-colors dark:border-gray-600">
                                        <span class="ml-2 text-sm text-gray-700 group-hover:text-blue-600 transition-colors dark:text-gray-300">{{ company.name }}</span>
                                    </label>
                                </div>
                            </div>

                            <div class="md:col-span-2">
                                <div class="flex flex-col sm:flex-row sm:items-center justify-between mb-4 gap-4">
                                    <label class="text-xs font-bold text-gray-500 uppercase tracking-wider dark:text-gray-300">Permissions</label>
                                    <div class="flex items-center space-x-3">
                                        <div class="relative flex-1 sm:flex-none">
                                            <div class="absolute inset-y-0 left-0 pl-2.5 flex items-center pointer-events-none">
                                                <svg class="h-3.5 w-3.5 text-gray-400 dark:text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                                </svg>
                                            </div>
                                            <input v-model="rolePermissionSearch" type="text" placeholder="Search permissions..."
                                                   class="pl-8 pr-3 py-1.5 text-xs border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500 placeholder-gray-400 w-full sm:w-64 shadow-sm dark:border-gray-600">
                                        </div>
                                        <button type="button" @click="toggleAllRolePermissions" class="text-[10px] font-black text-blue-600 uppercase hover:text-blue-800 whitespace-nowrap px-2 py-1 bg-blue-50 rounded-md transition-colors">
                                            {{ areAllRolePermissionsSelected ? 'Unselect All' : 'Select All' }}
                                        </button>
                                    </div>
                                </div>

                                <!-- Tab Navigation -->
                                <div class="flex overflow-x-auto custom-scrollbar border-b border-gray-200 mb-4 pb-1 dark:border-gray-700">
                                    <button
                                        v-for="group in groupedRolePermissions"
                                        :key="group.name"
                                        type="button"
                                        @click="activeRoleTab = group.name"
                                        :class="[
                                            'px-4 py-2 text-xs font-bold uppercase tracking-wider whitespace-nowrap transition-all border-b-2 -mb-[2px]',
                                            activeRoleTab === group.name
                                                ? 'border-blue-600 text-blue-600'
                                                : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
                                        ]"
                                    >
                                        {{ group.name }}
                                        <span v-if="rolePermissionSearch" class="ml-1 px-1.5 py-0.5 rounded-full bg-blue-100 text-blue-700 text-[10px]">
                                            {{ group.categories.reduce((acc, cat) => acc + cat.permissions.length, 0) }}
                                        </span>
                                    </button>
                                </div>

                                <!-- Tab Content -->
                                <div class="space-y-6 max-h-[500px] overflow-y-auto pr-2 custom-scrollbar">
                                    <div v-for="group in groupedRolePermissions" :key="group.name">
                                        <div v-if="activeRoleTab === group.name" class="space-y-4">
                                            <div class="flex items-center justify-between">
                                                <h3 class="text-xs font-black text-gray-400 uppercase tracking-widest dark:text-gray-400">{{ group.name }} Overview</h3>
                                                <button type="button" @click="toggleRoleGroup(group)" class="text-[10px] font-black text-blue-600 uppercase hover:text-blue-800 bg-blue-50 px-2 py-1 rounded transition-colors">
                                                    {{ isRoleGroupSelected(group) ? 'Clear All in Group' : 'Select All in Group' }}
                                                </button>
                                            </div>
                                            <div class="grid grid-cols-1 gap-4">
                                                <div v-for="categoryData in group.categories" :key="categoryData.name" class="bg-gray-50 rounded-xl p-4 border border-gray-100 dark:bg-gray-900/50 dark:border-gray-700">
                                                    <div class="flex items-center justify-between mb-3 border-b border-gray-200 pb-2 dark:border-gray-700">
                                                        <h4 class="text-xs font-black text-gray-900 uppercase tracking-widest dark:text-gray-100">{{ categoryData.name.replace(/_/g, ' ') }}</h4>
                                                        <button type="button" @click="toggleRoleCategory(categoryData.name, categoryData.permissions)" class="text-[10px] font-bold text-blue-600 uppercase hover:text-blue-800">
                                                            {{ isRoleCategorySelected(categoryData.permissions) ? 'Clear' : 'All' }}
                                                        </button>
                                                    </div>
                                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                                        <label v-for="permission in sortRolePermissions(categoryData.permissions)" :key="permission.id" class="flex items-center group cursor-pointer p-2 hover:bg-white rounded-lg transition-colors border border-transparent hover:border-gray-200 shadow-sm sm:shadow-none dark:hover:bg-gray-700">
                                                            <input type="checkbox" :value="permission.name" v-model="roleForm.permissions"
                                                                   class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 group-hover:border-blue-400 transition-colors dark:border-gray-600">
                                                            <span class="ml-2 text-sm text-gray-700 group-hover:text-blue-600 transition-colors truncate dark:text-gray-300" :title="permission.name">{{ permission.name.split('.')[1] }}</span>
                                                        </label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div v-if="groupedRolePermissions.length === 0" class="text-center py-12">
                                        <div class="bg-gray-50 rounded-full w-16 h-16 flex items-center justify-center mx-auto mb-4 dark:bg-gray-900/50">
                                            <svg class="w-8 h-8 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                            </svg>
                                        </div>
                                        <p class="text-sm text-gray-500 font-medium dark:text-gray-300">No permissions found matching "{{ rolePermissionSearch }}"</p>
                                        <button type="button" @click="rolePermissionSearch = ''" class="mt-2 text-xs font-bold text-blue-600 uppercase hover:text-blue-800">Clear search</button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="flex justify-end space-x-3 pt-6 border-t mt-6">
                            <button type="button" @click="closeRoleModal"
                                    class="px-4 py-2 text-sm font-semibold text-gray-600 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700">
                                Cancel
                            </button>
                            <button type="submit"
                                    class="px-6 py-2 bg-blue-600 text-white text-sm font-bold rounded-lg hover:bg-blue-700 shadow-md transition-all">
                                Update Role
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
