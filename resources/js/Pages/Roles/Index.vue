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
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Role</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Landing Page</th>
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
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 0 00-9-5.197m13.5-9a2.5 2.5 0 11-5 0 2.5 2.5 0 015 0z" />
                                        </svg>
                                    </div>
                                    <div class="ml-4">
                                        <div class="text-sm font-medium text-gray-900">{{ role.name }}</div>
                                        <div class="text-sm text-gray-500">{{ role.permissions.length }} permissions assigned</div>
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
                <div class="relative bg-white rounded-xl shadow-2xl w-full max-w-lg p-6 border border-gray-100 transform transition-all">
                    <div class="flex justify-between items-center mb-6">
                        <h3 class="text-xl font-bold text-gray-900">
                            Permissions: {{ selectedRole?.name }}
                        </h3>
                        <button @click="closePermissionsModal" class="text-gray-400 hover:text-gray-600 transition-colors">
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
                                class="px-6 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors text-sm font-bold shadow-sm">
                            Close
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Create/Edit Modal -->
        <div v-if="showModal" class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex items-center justify-center min-h-screen px-4 py-6">
                <div class="fixed inset-0 bg-black/20 backdrop-blur-md" @click="closeModal"></div>
                <div class="relative bg-white rounded-xl shadow-2xl w-full max-w-4xl p-6 border border-gray-100 transform transition-all">
                    <div class="flex justify-between items-center mb-6">
                        <h3 class="text-xl font-bold text-gray-900">
                            {{ isEditing ? 'Edit Role' : 'Create Role' }}
                        </h3>
                        <button @click="closeModal" class="text-gray-400 hover:text-gray-600 transition-colors">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <form @submit.prevent="submitForm" class="space-y-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Role Name</label>
                                <input v-model="form.name" type="text" required
                                       class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                            </div>

                            <div>
                                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Default Landing Page</label>
                                <select v-model="form.landing_page" 
                                        class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                                    <optgroup v-for="group in landingPageOptions" :key="group.group" :label="group.group">
                                        <option v-for="opt in group.options" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                                    </optgroup>
                                </select>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div class="flex flex-col justify-center p-4 bg-blue-50 rounded-xl border border-blue-100">
                                <label class="flex items-center space-x-3 cursor-pointer">
                                    <div class="relative">
                                        <input type="checkbox" v-model="form.is_assignable" class="sr-only peer">
                                        <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                                    </div>
                                    <span class="text-sm font-bold text-blue-900">Assignable to Tickets</span>
                                </label>
                                <p class="text-[10px] text-blue-600 mt-1 uppercase font-bold italic">Users with this role appear in "Assignee" list.</p>
                            </div>

                            <div class="p-4 bg-gray-50 rounded-xl border border-gray-100">
                                <h4 class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-3">Email Notifications</h4>
                                <div class="space-y-3">
                                    <label class="flex items-center justify-between cursor-pointer group">
                                        <span class="text-sm font-medium text-gray-700 group-hover:text-blue-600 transition-colors">On Ticket Creation</span>
                                        <div class="relative">
                                            <input type="checkbox" v-model="form.notify_on_ticket_create" class="sr-only peer">
                                            <div class="w-9 h-5 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-blue-600"></div>
                                        </div>
                                    </label>
                                    
                                    <label class="flex items-center justify-between cursor-pointer group">
                                        <span class="text-sm font-medium text-gray-700 group-hover:text-blue-600 transition-colors">When Assigned</span>
                                        <div class="relative">
                                            <input type="checkbox" v-model="form.notify_on_ticket_assign" class="sr-only peer">
                                            <div class="w-9 h-5 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-blue-600"></div>
                                        </div>
                                    </label>

                                    <label class="flex items-center justify-between cursor-pointer group">
                                        <span class="text-sm font-medium text-gray-700 group-hover:text-red-600 transition-colors flex items-center gap-1.5">
                                            On Urgent Ticket
                                            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-black bg-red-100 text-red-700 border border-red-200">P1</span>
                                        </span>
                                        <div class="relative">
                                            <input type="checkbox" v-model="form.notify_on_urgent_ticket" class="sr-only peer">
                                            <div class="w-9 h-5 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-red-500"></div>
                                        </div>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div class="md:col-span-1">
                                <div class="flex items-center justify-between mb-2">
                                    <label class="text-xs font-bold text-gray-500 uppercase tracking-wider">Companies</label>
                                    <button type="button" @click="toggleAllCompanies" class="text-[10px] font-black text-blue-600 uppercase hover:text-blue-800">
                                        {{ form.companies.length === companies.length ? 'Unselect All' : 'Select All' }}
                                    </button>
                                </div>
                                <div class="space-y-2 max-h-64 overflow-y-auto border border-gray-200 rounded-xl p-4 bg-white shadow-inner custom-scrollbar">
                                    <label v-for="company in companies" :key="company.id" class="flex items-center group cursor-pointer">
                                        <input type="checkbox" :value="company.id" v-model="form.companies"
                                               class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 group-hover:border-blue-400 transition-colors">
                                        <span class="ml-2 text-sm text-gray-700 group-hover:text-blue-600 transition-colors">{{ company.name }}</span>
                                    </label>
                                </div>
                            </div>

                            <div class="md:col-span-2">
                                <div class="flex flex-col sm:flex-row sm:items-center justify-between mb-4 gap-4">
                                    <label class="text-xs font-bold text-gray-500 uppercase tracking-wider">Permissions</label>
                                    <div class="flex items-center space-x-3">
                                        <div class="relative flex-1 sm:flex-none">
                                            <div class="absolute inset-y-0 left-0 pl-2.5 flex items-center pointer-events-none">
                                                <svg class="h-3.5 w-3.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                                </svg>
                                            </div>
                                            <input v-model="permissionSearch" type="text" placeholder="Search permissions..." 
                                                   class="pl-8 pr-3 py-1.5 text-xs border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500 placeholder-gray-400 w-full sm:w-64 shadow-sm">
                                        </div>
                                        <button type="button" @click="toggleAllPermissions" class="text-[10px] font-black text-blue-600 uppercase hover:text-blue-800 whitespace-nowrap px-2 py-1 bg-blue-50 rounded-md transition-colors">
                                            {{ areAllPermissionsSelected ? 'Unselect All' : 'Select All' }}
                                        </button>
                                    </div>
                                </div>

                                <!-- Tab Navigation -->
                                <div class="flex overflow-x-auto custom-scrollbar border-b border-gray-200 mb-4 pb-1">
                                    <button 
                                        v-for="group in groupedPermissions" 
                                        :key="group.name"
                                        type="button"
                                        @click="activeTab = group.name"
                                        :class="[
                                            'px-4 py-2 text-xs font-bold uppercase tracking-wider whitespace-nowrap transition-all border-b-2 -mb-[2px]',
                                            activeTab === group.name 
                                                ? 'border-blue-600 text-blue-600' 
                                                : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
                                        ]"
                                    >
                                        {{ group.name }}
                                        <span v-if="permissionSearch" class="ml-1 px-1.5 py-0.5 rounded-full bg-blue-100 text-blue-700 text-[10px]">
                                            {{ group.categories.reduce((acc, cat) => acc + cat.permissions.length, 0) }}
                                        </span>
                                    </button>
                                </div>

                                <!-- Tab Content -->
                                <div class="space-y-6 max-h-[500px] overflow-y-auto pr-2 custom-scrollbar">
                                    <div v-for="group in groupedPermissions" :key="group.name">
                                        <div v-if="activeTab === group.name" class="space-y-4">
                                            <div class="flex items-center justify-between">
                                                <h3 class="text-xs font-black text-gray-400 uppercase tracking-widest">{{ group.name }} Overview</h3>
                                                <button type="button" @click="toggleGroup(group)" class="text-[10px] font-black text-blue-600 uppercase hover:text-blue-800 bg-blue-50 px-2 py-1 rounded transition-colors">
                                                    {{ isGroupSelected(group) ? 'Clear All in Group' : 'Select All in Group' }}
                                                </button>
                                            </div>
                                            
                                            <div class="grid grid-cols-1 gap-4">
                                                <div v-for="categoryData in group.categories" :key="categoryData.name" class="bg-gray-50 rounded-xl p-4 border border-gray-100">
                                                    <div class="flex items-center justify-between mb-3 border-b border-gray-200 pb-2">
                                                        <h4 class="text-xs font-black text-gray-900 uppercase tracking-widest">{{ categoryData.name.replace(/_/g, ' ') }}</h4>
                                                        <button type="button" @click="toggleCategory(categoryData.name, categoryData.permissions)" class="text-[10px] font-bold text-blue-600 uppercase hover:text-blue-800">
                                                            {{ isCategorySelected(categoryData.permissions) ? 'Clear' : 'All' }}
                                                        </button>
                                                    </div>
                                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                                        <label v-for="permission in sortPermissions(categoryData.permissions)" :key="permission.id" class="flex items-center group cursor-pointer p-2 hover:bg-white rounded-lg transition-colors border border-transparent hover:border-gray-200 shadow-sm sm:shadow-none">
                                                            <input type="checkbox" :value="permission.name" v-model="form.permissions"
                                                                   class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 group-hover:border-blue-400 transition-colors">
                                                            <span class="ml-2 text-sm text-gray-700 group-hover:text-blue-600 transition-colors truncate" :title="permission.name">{{ permission.name.split('.')[1] }}</span>
                                                        </label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div v-if="groupedPermissions.length === 0" class="text-center py-12">
                                        <div class="bg-gray-50 rounded-full w-16 h-16 flex items-center justify-center mx-auto mb-4">
                                            <svg class="w-8 h-8 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                            </svg>
                                        </div>
                                        <p class="text-sm text-gray-500 font-medium">No permissions found matching "{{ permissionSearch }}"</p>
                                        <button type="button" @click="permissionSearch = ''" class="mt-2 text-xs font-bold text-blue-600 uppercase hover:text-blue-800">
                                            Clear search
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="flex justify-end space-x-3 pt-6 border-t mt-6">
                            <button type="button" @click="closeModal" 
                                    class="px-4 py-2 text-sm font-semibold text-gray-600 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors">
                                Cancel
                            </button>
                            <button type="submit" 
                                    class="px-6 py-2 bg-blue-600 text-white text-sm font-bold rounded-lg hover:bg-blue-700 shadow-md transition-all">
                                {{ isEditing ? 'Update Role' : 'Create Role' }}
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
import { router, usePage } from '@inertiajs/vue3'
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

const landingPageOptions = [
    {
        group: 'General',
        options: [
            { label: 'Dashboard', value: 'dashboard' },
        ]
    },
    {
        group: 'Administrative',
        options: [
            { label: 'DTR (Attendance)', value: 'attendance.index' },
            { label: 'Attendance Logs', value: 'attendance.logs' },
            { label: 'Scheduling', value: 'schedules.index' },
            { label: 'Presence', value: 'presence.index' },
            { label: 'KB Articles', value: 'kb-articles.index' },
        ]
    },
    {
        group: 'Services',
        options: [
            { label: 'Tickets', value: 'tickets.index' },
            { label: 'POS Requests', value: 'pos-requests.index' },
            { label: 'SAP Requests', value: 'sap-requests.index' },
            { label: 'Stock In', value: 'stock-ins.index' },
        ]
    },
    {
        group: 'Reports',
        options: [
            { label: 'Store Health Report', value: 'reports.store-health' },
        ]
    },
    {
        group: 'References',
        options: [
            { label: 'Companies', value: 'companies.index' },
            { label: 'Clusters', value: 'clusters.index' },
            { label: 'Stores', value: 'stores.index' },
            { label: 'Vendors', value: 'vendors.index' },
            { label: 'Categories', value: 'categories.index' },
            { label: 'Sub-Categories', value: 'sub-categories.index' },
            { label: 'Items', value: 'items.index' },
            { label: 'Assets', value: 'assets.index' },
            { label: 'Request Types', value: 'request-types.index' },
            { label: 'Form Builder', value: 'form-builder.index' },
        ]
    },
    {
        group: 'User Management',
        options: [
            { label: 'Users', value: 'users.index' },
            { label: 'Roles & Permissions', value: 'roles.index' },
        ]
    },
    {
        group: 'Settings',
        options: [
            { label: 'System Settings', value: 'settings.index' },
            { label: 'Canned Messages', value: 'canned-messages.index' },
            { label: 'My Profile', value: 'profile.edit' },
        ]
    }
]

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
    notify_on_urgent_ticket: false
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
    permissionSearch.value = ''
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
    permissionSearch.value = ''
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
    permissionSearch.value = ''
    showModal.value = true;
};

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
    permissionSearch.value = ''
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

const permissionSearch = ref('')
const activeTab = ref('')

const dynamicForms = computed(() => usePage().props.dynamicForms || []);

const permissionGroups = computed(() => {
    const servicesCategories = ['Tickets', 'Pos_requests', 'Sap_requests', 'Stock_in'];

    // Add dynamic form names exactly as the backend RoleService does
    (dynamicForms.value || []).forEach(form => {
        servicesCategories.push(form.name);
    });

    return [
        { name: 'Dashboard', categories: ['Dashboard'] },
        { name: 'Project Tracker', categories: ['Projects'] },
        { name: 'Administrative', categories: ['Attendance', 'Schedules', 'Presence', 'KB Articles'] },
        { name: 'Services', categories: servicesCategories },
        { name: 'References', categories: ['Companies', 'Clusters', 'Stores', 'Vendors', 'Activity_templates', 'Categories', 'Subcategories', 'Items', 'Assets', 'Request_types', 'Form_builder'] },
        { name: 'Reports', categories: ['Reports'] },
        { name: 'User Management', categories: ['Users', 'Roles'] },
        { name: 'Settings', categories: ['Settings', 'Canned_messages'] }
    ];
});

const groupedPermissions = computed(() => {
    const search = permissionSearch.value.toLowerCase()
    const result = []
    
    // Create a copy of permissions keys to track what's mapped
    const availableCategories = Object.keys(props.permissions || {})
    const mappedKeys = new Set()

    permissionGroups.value.forEach(group => {
        const groupCategories = []

        group.categories.forEach(catName => {
            // Find match regardless of case and underscores/spaces
            const normalizedCatName = catName.toLowerCase().replace(/[\s_]/g, '')
            const actualKey = availableCategories.find(k => {
                const normalizedK = k.toLowerCase().replace(/[\s_]/g, '')
                return normalizedK === normalizedCatName
            })
            if (actualKey && !mappedKeys.has(actualKey)) {
                const perms = props.permissions[actualKey]
                if (perms) {
                    const filteredPerms = perms.filter(p => p.name.toLowerCase().includes(search))
                    if (filteredPerms.length > 0) {
                        groupCategories.push({
                            name: actualKey,
                            permissions: filteredPerms
                        })
                        mappedKeys.add(actualKey)
                    }
                }
            }
        })

        if (groupCategories.length > 0) {
            result.push({
                name: group.name,
                categories: groupCategories
            })
        }
    })

    // Handle any categories that were not mapped in the predefined groups
    const otherCategories = []
    availableCategories.forEach(catName => {
        if (!mappedKeys.has(catName)) {
            const perms = props.permissions[catName]
            if (perms) {
                const filteredPerms = perms.filter(p => p.name.toLowerCase().includes(search))
                if (filteredPerms.length > 0) {
                    otherCategories.push({
                        name: catName,
                        permissions: filteredPerms
                    })
                }
            }
        }
    })
    
    if (otherCategories.length > 0) {
        result.push({
            name: 'Other',
            categories: otherCategories
        })
    }

    return result
})

// Set default active tab to the first group that has content
watch(groupedPermissions, (newGroups) => {
    if (newGroups.length > 0 && (!activeTab.value || !newGroups.find(g => g.name === activeTab.value))) {
        activeTab.value = newGroups[0].name
    }
}, { immediate: true })

const isGroupSelected = (group) => {
    if (!group || !group.categories) return false
    const allNames = group.categories.flatMap(c => c.permissions.map(p => p.name))
    if (allNames.length === 0) return false
    return allNames.every(name => form.permissions.includes(name))
}

const toggleGroup = (group) => {
    if (!group || !group.categories) return
    const allNames = group.categories.flatMap(c => c.permissions.map(p => p.name))
    if (allNames.length === 0) return
    
    const hasAll = allNames.every(name => form.permissions.includes(name))
    
    if (hasAll) {
        form.permissions = form.permissions.filter(name => !allNames.includes(name))
    } else {
        const missing = allNames.filter(name => !form.permissions.includes(name))
        form.permissions = [...form.permissions, ...missing]
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
    const order = ['view', 'show', 'create', 'edit', 'post', 'delete', 'approve', 'canned_messages', 'internal_notes'];
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
