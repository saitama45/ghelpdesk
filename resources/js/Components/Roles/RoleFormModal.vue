<template>
    <div v-if="show" class="fixed inset-0 z-50 overflow-y-auto">
        <div class="flex items-center justify-center min-h-screen px-4 py-6">
            <div class="fixed inset-0 bg-black/20 backdrop-blur-md" @click="$emit('close')"></div>
            <div class="relative bg-white rounded-xl shadow-2xl w-full max-w-4xl p-6 border border-gray-100 transform transition-all">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-xl font-bold text-gray-900">{{ title }}</h3>
                    <button @click="$emit('close')" class="text-gray-400 hover:text-gray-600 transition-colors">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <form @submit.prevent="$emit('submit')" class="space-y-6">
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

                                <label class="flex items-center justify-between cursor-pointer group">
                                    <span class="text-sm font-medium text-gray-700 group-hover:text-emerald-600 transition-colors">On User Registration</span>
                                    <div class="relative">
                                        <input type="checkbox" v-model="form.notify_on_user_registration" class="sr-only peer">
                                        <div class="w-9 h-5 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-emerald-600"></div>
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
                                                    <button type="button" @click="toggleCategory(categoryData.permissions)" class="text-[10px] font-bold text-blue-600 uppercase hover:text-blue-800">
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
                        <button type="button" @click="$emit('close')"
                                class="px-4 py-2 text-sm font-semibold text-gray-600 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors">
                            Cancel
                        </button>
                        <button type="submit"
                                class="px-6 py-2 bg-blue-600 text-white text-sm font-bold rounded-lg hover:bg-blue-700 shadow-md transition-all">
                            {{ submitLabel }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'

const props = defineProps({
    show: Boolean,
    title: {
        type: String,
        default: 'Create Role'
    },
    submitLabel: {
        type: String,
        default: 'Create Role'
    },
    form: {
        type: Object,
        required: true
    },
    permissions: {
        type: Object,
        default: () => ({})
    },
    companies: {
        type: Array,
        default: () => []
    },
    dynamicForms: {
        type: Array,
        default: () => []
    },
    landingPageOptions: {
        type: Array,
        required: true
    }
})

defineEmits(['close', 'submit'])

const permissionSearch = ref('')
const activeTab = ref('')

watch(() => props.show, (show) => {
    if (show) {
        permissionSearch.value = ''
    }
})

const permissionGroups = computed(() => {
    const servicesCategories = ['Tickets', 'Task Board', 'Pos_requests', 'Sap_requests', 'Loyalty Stamps']

    ;(props.dynamicForms || []).forEach(form => {
        servicesCategories.push(form.name)
    })

    return [
        { name: 'Dashboard', categories: ['Dashboard'] },
        { name: 'Project Tracker', categories: ['Projects'] },
        { name: 'Services', categories: servicesCategories },
        { name: 'Inventory', categories: ['Assets', 'Stock_in', 'Stock_transfer', 'Receiving_stock', 'Reports'] },
        { name: 'Monitoring', categories: ['NPC Status', 'CCTV Monitoring', 'Payments & SOA'] },
        { name: 'Administrative', categories: ['Attendance', 'Schedules', 'Service Vehicle Trips', 'Presence', 'KB Articles'] },
        { name: 'References', categories: ['Companies', 'Departments', 'Clusters', 'Stores', 'Vendors', 'Activity_templates', 'Project Type & Store Class', 'Categories', 'Subcategories', 'Items', 'Request_types', 'Form_builder'] },
        { name: 'Reports', categories: ['Reports'] },
        { name: 'User Management', categories: ['Users', 'Roles'] },
        { name: 'Settings', categories: ['Settings', 'Canned_messages', 'Leadership Points'] }
    ]
})

const groupedPermissions = computed(() => {
    const search = permissionSearch.value.toLowerCase()
    const result = []
    const normalizeCategory = (value) => value.toLowerCase().replace(/[^a-z0-9]/g, '')
    const categoryAliases = {
        [normalizeCategory('Payments & SOA')]: ['Payments', 'Payments & SOA Monitoring']
    }
    const categoryPermissionPrefixes = {
        [normalizeCategory('Payments & SOA')]: 'payments.'
    }
    const getCategoryDisplayName = (actualKey, catName, permissionsList) => {
        const permissionPrefix = categoryPermissionPrefixes[normalizeCategory(catName)]

        if (permissionPrefix && permissionsList.some(p => p.name.startsWith(permissionPrefix))) {
            return catName
        }

        return actualKey
    }

    const availableCategories = Object.keys(props.permissions || {})
    const mappedKeys = new Set()

    permissionGroups.value.forEach(group => {
        const groupCategories = []

        group.categories.forEach(catName => {
            const normalizedCatName = normalizeCategory(catName)
            const categoryMatches = [
                normalizedCatName,
                ...(categoryAliases[normalizedCatName] || []).map(normalizeCategory)
            ]
            const actualKey = availableCategories.find(k => {
                const normalizedK = normalizeCategory(k)

                if (categoryMatches.includes(normalizedK)) {
                    return true
                }

                const permissionPrefix = categoryPermissionPrefixes[normalizedCatName]
                return permissionPrefix && props.permissions[k]?.some(p => p.name.startsWith(permissionPrefix))
            })

            if (actualKey && !mappedKeys.has(actualKey)) {
                const perms = props.permissions[actualKey]

                if (perms) {
                    let filteredPerms = perms.filter(p => p.name.toLowerCase().includes(search))

                    if (group.name === 'Inventory' && actualKey === 'Reports') {
                        filteredPerms = filteredPerms.filter(p => p.name === 'reports.inventory')
                    } else if (group.name === 'Reports' && actualKey === 'Reports') {
                        filteredPerms = filteredPerms.filter(p => p.name !== 'reports.inventory')
                    }

                    if (filteredPerms.length > 0) {
                        groupCategories.push({
                            name: getCategoryDisplayName(actualKey, catName, filteredPerms),
                            permissions: filteredPerms
                        })

                        if (group.name !== 'Inventory' || actualKey !== 'Reports') {
                            mappedKeys.add(actualKey)
                        }
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

watch(groupedPermissions, (newGroups) => {
    if (newGroups.length > 0 && (!activeTab.value || !newGroups.find(g => g.name === activeTab.value))) {
        activeTab.value = newGroups[0].name
    }
}, { immediate: true })

const isGroupSelected = (group) => {
    if (!group || !group.categories) return false
    const allNames = group.categories.flatMap(c => c.permissions.map(p => p.name))
    if (allNames.length === 0) return false
    return allNames.every(name => props.form.permissions.includes(name))
}

const toggleGroup = (group) => {
    if (!group || !group.categories) return
    const allNames = group.categories.flatMap(c => c.permissions.map(p => p.name))
    if (allNames.length === 0) return

    const hasAll = allNames.every(name => props.form.permissions.includes(name))

    if (hasAll) {
        props.form.permissions = props.form.permissions.filter(name => !allNames.includes(name))
    } else {
        const missing = allNames.filter(name => !props.form.permissions.includes(name))
        props.form.permissions = [...props.form.permissions, ...missing]
    }
}

const getAllPermissionNames = () => {
    return Object.values(props.permissions || {}).flat().map(p => p.name)
}

const areAllPermissionsSelected = computed(() => {
    const allNames = getAllPermissionNames()
    return allNames.length > 0 && allNames.every(name => props.form.permissions.includes(name))
})

const toggleAllPermissions = () => {
    const allNames = getAllPermissionNames()

    if (areAllPermissionsSelected.value) {
        props.form.permissions = []
    } else {
        props.form.permissions = [...allNames]
    }
}

const toggleCategory = (permissionsList) => {
    const allNames = permissionsList.map(p => p.name)
    const hasAll = allNames.every(name => props.form.permissions.includes(name))

    if (hasAll) {
        props.form.permissions = props.form.permissions.filter(name => !allNames.includes(name))
    } else {
        const missing = allNames.filter(name => !props.form.permissions.includes(name))
        props.form.permissions = [...props.form.permissions, ...missing]
    }
}

const isCategorySelected = (permissionsList) => {
    if (!permissionsList || permissionsList.length === 0) return false
    return permissionsList.every(p => props.form.permissions.includes(p.name))
}

const toggleAllCompanies = () => {
    if (props.form.companies.length === props.companies.length) {
        props.form.companies = []
    } else {
        props.form.companies = props.companies.map(c => c.id)
    }
}

const sortPermissions = (permissions) => {
    const order = ['view', 'show', 'create', 'edit', 'assign', 'resolve', 'close', 'post', 'delete', 'approve', 'canned_messages', 'internal_notes']

    return [...permissions].sort((a, b) => {
        const aAction = a.name.split('.')[1]
        const bAction = b.name.split('.')[1]
        const aIndex = order.indexOf(aAction)
        const bIndex = order.indexOf(bAction)

        if (aIndex === -1 && bIndex === -1) return aAction.localeCompare(bAction)
        if (aIndex === -1) return 1
        if (bIndex === -1) return -1
        return aIndex - bIndex
    })
}
</script>
