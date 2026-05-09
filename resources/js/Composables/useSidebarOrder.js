import { reactive } from 'vue'

export const SECTION_LABELS = {
    dashboard: 'Dashboard',
    projectTracker: 'Project Tracker',
    services: 'Services',
    inventory: 'Inventory',
    adminTask: 'Administrative',
    references: 'References',
    reports: 'Reports',
    userManagement: 'User Management',
    settings: 'Settings',
}

export const DEFAULT_SECTION_ORDER = [
    'dashboard', 'projectTracker', 'services', 'inventory', 'adminTask', 'references', 'reports', 'userManagement', 'settings',
]

export const DEFAULT_CHILD_ORDER = {
    dashboard: [],
    projectTracker: [],
    services: ['tickets', 'task-boards', 'pos-requests', 'sap-requests'],
    inventory: ['assets', 'stock-ins', 'inventory-report'],
    adminTask: ['dtr', 'attendance-logs', 'scheduling', 'presence', 'kb-articles'],
    references: ['companies', 'departments', 'clusters', 'stores', 'vendors', 'activity-templates', 'categories', 'sub-categories', 'items', 'request-types', 'form-builder'],
    reports: ['store-health', 'sla-performance', 'assignee-performance'],
    userManagement: ['users', 'roles'],
    settings: ['system-settings', 'ticket-archive', 'canned-messages', 'profile'],
}

export const CHILD_LABELS = {
    services: {
        'tickets': 'Tickets',
        'task-boards': 'Task Board',
        'pos-requests': 'POS Requests',
        'sap-requests': 'SAP Requests',
    },
    inventory: {
        'assets': 'Assets',
        'stock-ins': 'Stock Transaction',
        'inventory-report': 'Inventory Report',
    },
    adminTask: {
        'dtr': 'DTR',
        'attendance-logs': 'Attendance Logs',
        'scheduling': 'Scheduling',
        'presence': 'Presence',
        'kb-articles': 'KB Articles',
    },
    references: {
        'companies': 'Companies',
        'departments': 'Departments',
        'clusters': 'Clusters',
        'stores': 'Stores',
        'vendors': 'Vendors',
        'activity-templates': 'Activity Templates',
        'categories': 'Categories',
        'sub-categories': 'Sub-Categories',
        'items': 'Items',
        'request-types': 'Request Types',
        'form-builder': 'Form Builder',
    },
    reports: {
        'store-health': 'Store Health Report',
        'sla-performance': 'SLA Performance Report',
        'assignee-performance': 'Assignee Performance',
    },
    userManagement: {
        'users': 'Users',
        'roles': 'Roles & Permissions',
    },
    settings: {
        'system-settings': 'System Settings',
        'ticket-archive': 'Ticket Archive',
        'canned-messages': 'Canned Messages',
        'profile': 'My Profile',
    },
}

function cloneChildren(src) {
    if (!src || typeof src !== 'object') return {}
    return Object.fromEntries(
        Object.entries(src).map(([k, v]) => [k, Array.isArray(v) ? [...v] : { ...v }])
    )
}

// Global shared state for Sidebar.vue and Settings UI
const _state = reactive({
    sections: [...DEFAULT_SECTION_ORDER],
    children: cloneChildren(DEFAULT_CHILD_ORDER),
    customSectionLabels: {},
    customChildLabels: {},
})

export function useSidebarOrder() {
    
    const init = (config) => {
        if (!config) {
            _state.sections = [...DEFAULT_SECTION_ORDER]
            _state.children = cloneChildren(DEFAULT_CHILD_ORDER)
            _state.customSectionLabels = {}
            _state.customChildLabels = {}
            return
        }

        // Merge orders
        if (config.sections) {
            _state.sections = [...config.sections]
            // Ensure any new default sections are included
            DEFAULT_SECTION_ORDER.forEach((id, idx) => {
                if (!_state.sections.includes(id)) {
                    _state.sections.splice(idx, 0, id)
                }
            })
        } else {
            _state.sections = [...DEFAULT_SECTION_ORDER]
        }

        if (config.children) {
            _state.children = { ...cloneChildren(DEFAULT_CHILD_ORDER), ...cloneChildren(config.children) }
        } else {
            _state.children = cloneChildren(DEFAULT_CHILD_ORDER)
        }

        // Merge labels
        _state.customSectionLabels = config.customSectionLabels || {}
        // Ensure customChildLabels is an object even if DB returns empty JSON array []
        const rawChildLabels = config.customChildLabels
        _state.customChildLabels = (rawChildLabels && !Array.isArray(rawChildLabels)) 
            ? rawChildLabels 
            : {}
    }

    const getSectionOrder = (sectionId) => {
        const idx = _state.sections.indexOf(sectionId)
        return idx === -1 ? 999 : idx + 1
    }

    const getChildOrder = (sectionId, childId) => {
        const children = _state.children[sectionId] || []
        const idx = children.indexOf(childId)
        return idx === -1 ? 999 : idx + 1
    }

    const getSectionLabel = (sectionId) => {
        return _state.customSectionLabels[sectionId] || SECTION_LABELS[sectionId] || sectionId
    }

    const getChildLabel = (sectionId, childId) => {
        return _state.customChildLabels[sectionId]?.[childId] || CHILD_LABELS[sectionId]?.[childId] || childId
    }

    const updateSectionLabel = (sectionId, label) => {
        _state.customSectionLabels[sectionId] = label
    }

    const updateChildLabel = (sectionId, childId, label) => {
        if (!_state.customChildLabels[sectionId]) {
            _state.customChildLabels[sectionId] = {}
        }
        _state.customChildLabels[sectionId][childId] = label
    }

    const serialize = () => {
        return {
            sections: [..._state.sections],
            children: cloneChildren(_state.children),
            customSectionLabels: { ..._state.customSectionLabels },
            customChildLabels: cloneChildren(_state.customChildLabels),
        }
    }

    const reset = () => {
        init(null)
    }

    return { 
        state: _state, 
        init, 
        getSectionOrder, 
        getChildOrder, 
        getSectionLabel, 
        getChildLabel,
        updateSectionLabel,
        updateChildLabel,
        serialize,
        reset 
    }
}
