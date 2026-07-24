<script setup>
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import { ref, reactive, computed, onMounted, watch, defineAsyncComponent } from 'vue';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import DataTable from '@/Components/DataTable.vue';
import Autocomplete from '@/Components/Autocomplete.vue';
const HierarchySelector = defineAsyncComponent(() => import('@/Components/HierarchySelector.vue'));
const MultiAutocomplete = defineAsyncComponent(() => import('@/Components/MultiAutocomplete.vue'));
const RoleFormModal = defineAsyncComponent(() => import('@/Components/Roles/RoleFormModal.vue'));
import { roleLandingPageOptions } from '@/Components/Roles/roleLandingPageOptions';
import { useConfirm } from '@/Composables/useConfirm';
import { useErrorHandler } from '@/Composables/useErrorHandler';
import { useToast } from '@/Composables/useToast';
import { usePagination } from '@/Composables/usePagination';
import { usePermission } from '@/Composables/usePermission';

const props = defineProps({
    users: Object,
    roles: { type: Array, default: () => [] },
    filters: Object,
});

const showCreateModal = ref(false);
const showEditModal = ref(false);
const showPasswordModal = ref(false);
const showStoresModal = ref(false);
const showImportModal = ref(false);
const editingUser = ref(null);
const resetPasswordUser = ref(null);
const selectedUserStores = ref([]);
const formOptions = reactive({ stores: [], managers: [], departmentTree: [] });
const formOptionsLoaded = ref(false);
const formOptionsLoading = ref(false);
const userFormLoading = ref(false);
const storesModalLoading = ref(false);
const userDetailsCache = new Map();
const filterStatus = ref(props.filters?.status || '');
const filterRole = ref(props.filters?.role || '');
const { confirm } = useConfirm();
const { post, put, destroy } = useErrorHandler();
const { showError, showSuccess } = useToast();
const { hasPermission } = usePermission();

// ── User import ──────────────────────────────────────────────────────────
const importing = ref(false);
const selectedImportFile = ref(null);
const importResults = ref(null);

const openImportModal = () => {
    selectedImportFile.value = null;
    importResults.value = null;
    showImportModal.value = true;
};

const handleImportFileChange = (e) => {
    selectedImportFile.value = e.target.files[0] || null;
};

const submitImport = async () => {
    if (!selectedImportFile.value) return;

    importing.value = true;
    importResults.value = null;

    const formData = new FormData();
    formData.append('file', selectedImportFile.value);

    try {
        const { data } = await axios.post(route('users.import'), formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
        importResults.value = data;
        if (data.imported > 0) {
            showSuccess(`Imported ${data.imported} user(s) successfully.`);
            router.reload({ only: ['users'] });
        }
    } catch (err) {
        showError(err.response?.data?.message || 'Import failed.');
    } finally {
        importing.value = false;
    }
};

const allStoreIds = computed(() => formOptions.stores.map(s => s.id));
const departmentOptions = computed(() => formOptions.departmentTree);
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
}), { only: ['users', 'filters'] });

onMounted(() => {
    pagination.search.value = props.filters?.search || '';
    pagination.updateData(props.users);
});

watch(() => props.users, (newUsers) => {
    pagination.updateData(newUsers);
});

watch([filterStatus, filterRole], () => {
    pagination.currentPage.value = 1;
    pagination.performSearch();
});

const createForm = useForm({
    name: '',
    employee_id_no: '',
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
    employee_id_no: '',
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

const loadFormOptions = async () => {
    if (formOptionsLoaded.value) return;
    if (formOptionsLoading.value) {
        while (formOptionsLoading.value) {
            await new Promise(resolve => setTimeout(resolve, 20));
        }
        return;
    }

    formOptionsLoading.value = true;
    try {
        const { data } = await axios.get(route('users.form-options', undefined, false));
        formOptions.stores = data.stores || [];
        formOptions.managers = data.managers || [];
        formOptions.departmentTree = data.department_tree || [];
        formOptionsLoaded.value = true;
    } catch (error) {
        showError(error.response?.data?.message || 'Unable to load user form options.');
        throw error;
    } finally {
        formOptionsLoading.value = false;
    }
};

const loadUserDetails = async (userId) => {
    if (userDetailsCache.has(userId)) return userDetailsCache.get(userId);

    const request = axios
        .get(route('users.details', userId, false))
        .then(({ data }) => data.user);
    userDetailsCache.set(userId, request);

    try {
        const details = await request;
        userDetailsCache.set(userId, details);
        return details;
    } catch (error) {
        userDetailsCache.delete(userId);
        throw error;
    }
};

const openCreateUserModal = async () => {
    showCreateModal.value = true;
    userFormLoading.value = true;
    try {
        await loadFormOptions();
    } catch (error) {
        showCreateModal.value = false;
    } finally {
        userFormLoading.value = false;
    }
};

const createUser = () => {
    post(route('users.store'), createForm.data(), {
        onSuccess: () => {
            showCreateModal.value = false;
            createForm.reset();
            formOptionsLoaded.value = false;
        },
        onError: (errors) => {
            const errorMessage = Object.values(errors).flat().join(', ') || 'An error occurred'
            showError(errorMessage)
        }
    });
};

const editUser = async (user) => {
    editingUser.value = user;
    editForm.name = user.name;
    editForm.employee_id_no = user.employee_id_no || '';
    editForm.email = user.email;
    editForm.role = user.roles[0]?.name || '';
    editForm.department_id = user.department_id || '';
    editForm.department_node_id = user.department_node_id || '';
    editForm.position = user.position || '';
    editForm.date_hired = user.date_hired ? String(user.date_hired).substring(0, 10) : '';
    editForm.is_active = user.google_id && !user.is_active && !(user.roles?.length) ? true : !!user.is_active;
    editForm.is_manager = !!user.is_manager;
    editForm.store_ids = [];
    editForm.manager_ids = [];
    editForm.notify_user_approval = true;
    showEditModal.value = true;
    userFormLoading.value = true;

    try {
        const [, details] = await Promise.all([loadFormOptions(), loadUserDetails(user.id)]);
        editingUser.value = { ...user, ...details };
        editForm.store_ids = details.store_ids || [];
        editForm.manager_ids = details.manager_ids || [];
    } catch (error) {
        showError(error.response?.data?.message || 'Unable to load user details.');
        showEditModal.value = false;
    } finally {
        userFormLoading.value = false;
    }
};

const updateUser = () => {
    put(route('users.update', editingUser.value.id), editForm.data(), {
        onSuccess: () => {
            showEditModal.value = false;
            editForm.reset();
            userDetailsCache.delete(editingUser.value.id);
            formOptionsLoaded.value = false;
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

const viewAssignedStores = async (user) => {
    selectedUserStores.value = [];
    showStoresModal.value = true;
    storesModalLoading.value = true;
    try {
        const details = await loadUserDetails(user.id);
        selectedUserStores.value = details.stores || [];
    } catch (error) {
        showError(error.response?.data?.message || 'Unable to load assigned stores.');
        showStoresModal.value = false;
    } finally {
        storesModalLoading.value = false;
    }
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
const roleEditorLoading = ref(false);
const roleEditorPermissions = ref({});
const roleEditorCompanies = ref([]);
const roleEditorDynamicForms = ref([]);
const roleEditorCache = new Map();
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

const openRoleEditModal = async (userRole) => {
    if (!userRole) return;
    editingRole.value = userRole;
    showRoleModal.value = true;
    roleEditorLoading.value = true;

    try {
        let payload = roleEditorCache.get(userRole.id);
        if (!payload) {
            const response = await axios.get(route('roles.editor-data', userRole.id, false));
            payload = response.data;
            roleEditorCache.set(userRole.id, payload);
        }
        const fullRole = payload.role;
        roleEditorPermissions.value = payload.permissions || {};
        roleEditorCompanies.value = payload.companies || [];
        roleEditorDynamicForms.value = payload.dynamic_forms || [];
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
    } catch (error) {
        showError(error.response?.data?.message || 'Unable to load role permissions.');
        showRoleModal.value = false;
        editingRole.value = null;
    } finally {
        roleEditorLoading.value = false;
    }
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
        onSuccess: () => {
            roleEditorCache.delete(editingRole.value.id);
            closeRoleModal();
        },
        onError: (errors) => {
            const errorMessage = Object.values(errors).flat().join(', ') || 'An error occurred';
            showError(errorMessage);
        },
    });
};

const rolePermissionGroups = computed(() => {
    const servicesCategories = ['Tickets', 'Queue', 'Task Board', 'Pos_requests', 'Sap_requests'];
    roleEditorDynamicForms.value.forEach(f => servicesCategories.push(f.name));
    return [
        { name: 'Dashboard', categories: ['Dashboard'] },
        { name: 'Project Tracker', categories: ['Projects'] },
        { name: 'Services', categories: servicesCategories },
        { name: 'Inventory', categories: ['Assets', 'Stock_in', 'Stock_transfer', 'Reports'] },
        { name: 'Monitoring', categories: ['NPC Status', 'CCTV Monitoring', 'ALAGA', 'WIGS', 'Payments & SOA', 'Accounting Documents', 'Mall Hookup'] },
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
    const availableCategories = Object.keys(roleEditorPermissions.value);
    const mappedKeys = new Set();

    rolePermissionGroups.value.forEach(group => {
        const groupCategories = [];
        group.categories.forEach(catName => {
            const normalizedCatName = catName.toLowerCase().replace(/[^a-z0-9]/g, '');
            const actualKey = availableCategories.find(k => k.toLowerCase().replace(/[^a-z0-9]/g, '') === normalizedCatName);
            if (actualKey && !mappedKeys.has(actualKey)) {
                const perms = roleEditorPermissions.value[actualKey];
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
            const perms = roleEditorPermissions.value[catName];
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

const getAllRolePermissionNames = () => Object.values(roleEditorPermissions.value).flat().map(p => p.name);

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
    roleForm.companies = roleForm.companies.length === roleEditorCompanies.value.length
        ? []
        : roleEditorCompanies.value.map(c => c.id);
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
                search-placeholder="Search users by name, employee ID, email, department..."
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
                        @click="openImportModal"
                        class="bg-emerald-600 text-white px-4 py-2 rounded-lg hover:bg-emerald-700 transition-colors flex items-center space-x-2 text-sm font-medium shadow-sm whitespace-nowrap"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                        <span>Import</span>
                    </button>
                    <button
                        v-if="hasPermission('users.create')"
                        @click="openCreateUserModal"
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
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-slate-300">Employee ID No</th>
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
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-gray-100">
                            {{ user.employee_id_no || '-' }}
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
                                v-if="user.stores_count > 0"
                                @click="viewAssignedStores(user)"
                                class="text-blue-600 hover:text-blue-800 text-xs font-medium flex items-center"
                            >
                                <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                                </svg>
                                View {{ user.stores_count }} assigned Store{{ user.stores_count > 1 ? 's' : '' }}
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
                    <div v-if="userFormLoading" class="absolute inset-0 z-20 flex flex-col items-center justify-center rounded-xl bg-white/90 text-sm font-semibold text-gray-600 dark:bg-gray-800/90 dark:text-gray-300">
                        <span class="mb-3 h-7 w-7 animate-spin rounded-full border-2 border-blue-500 border-t-transparent"></span>
                        Loading form options...
                    </div>
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
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1 dark:text-gray-300">Employee ID No</label>
                            <input v-model="createForm.employee_id_no" type="text" maxlength="255" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600">
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
                                :options="formOptions.stores"
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
                                :options="formOptions.managers"
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
                    <div v-if="userFormLoading" class="absolute inset-0 z-20 flex flex-col items-center justify-center rounded-xl bg-white/90 text-sm font-semibold text-gray-600 dark:bg-gray-800/90 dark:text-gray-300">
                        <span class="mb-3 h-7 w-7 animate-spin rounded-full border-2 border-blue-500 border-t-transparent"></span>
                        Loading user details...
                    </div>
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
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1 dark:text-gray-300">Employee ID No</label>
                            <input v-model="editForm.employee_id_no" type="text" maxlength="255" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600">
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
                                :options="formOptions.stores"
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
                                :options="formOptions.managers"
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
                        <div v-if="storesModalLoading" class="flex justify-center py-10 text-sm font-semibold text-gray-500">
                            <span class="mr-3 h-5 w-5 animate-spin rounded-full border-2 border-blue-500 border-t-transparent"></span>
                            Loading assigned stores...
                        </div>
                        <div v-else-if="selectedUserStores.length > 0" class="grid grid-cols-1 gap-3">
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

        <!-- Import Users Modal -->
        <div v-if="showImportModal" class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex items-center justify-center min-h-screen px-4 py-6">
                <div class="fixed inset-0 bg-black/20 backdrop-blur-md" @click="showImportModal = false"></div>
                <div class="relative bg-white rounded-xl shadow-2xl w-full max-w-xl p-6 border border-gray-100 transform transition-all dark:bg-gray-800 dark:border-gray-700">
                    <div class="flex justify-between items-center mb-6">
                        <h3 class="text-xl font-bold text-gray-900 dark:text-gray-100">Import Users</h3>
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
                                <li>Download the template — include <strong>employee_id_no</strong> when available; the <strong>role</strong>, <strong>department</strong>, <strong>assigned_stores</strong> and <strong>reports_to</strong> columns have dropdowns.</li>
                                <li>For <strong>assigned_stores</strong> and <strong>reports_to</strong>, pick from the dropdown and separate multiple values with a semicolon (<code>;</code>). See the header cell comments.</li>
                                <li>New users are created with the default password <code class="font-mono font-bold">Password@123</code> — ask them to change it on first login.</li>
                                <li>Rows whose email or Employee ID No already exists are skipped and listed below.</li>
                            </ul>
                            <div class="mt-4">
                                <a :href="route('users.template')"
                                   class="text-xs font-black text-blue-700 underline hover:text-blue-800 dark:text-blue-300">
                                    Download Excel Template
                                </a>
                            </div>
                        </div>

                        <div class="space-y-4">
                            <label class="block">
                                <span class="sr-only">Choose file</span>
                                <input type="file" @change="handleImportFileChange" accept=".xlsx,.csv"
                                       class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100 transition-all cursor-pointer dark:text-gray-300">
                            </label>

                            <div v-if="importResults" class="p-4 rounded-lg" :class="importResults.errors.length > 0 ? 'bg-amber-50 dark:bg-amber-900/20' : 'bg-green-50 dark:bg-green-900/20'">
                                <p class="text-sm font-bold" :class="importResults.errors.length > 0 ? 'text-amber-800 dark:text-amber-300' : 'text-green-800 dark:text-green-300'">
                                    Imported {{ importResults.imported }} user(s).
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
            :show="showRoleModal"
            :loading="roleEditorLoading"
            :title="`Edit Role: ${editingRole?.name || ''}`"
            submit-label="Update Role"
            :form="roleForm"
            :permissions="roleEditorPermissions"
            :companies="roleEditorCompanies"
            :dynamic-forms="roleEditorDynamicForms"
            :landing-page-options="landingPageOptions"
            @close="closeRoleModal"
            @submit="submitRoleForm"
        />
    </AppLayout>
</template>
