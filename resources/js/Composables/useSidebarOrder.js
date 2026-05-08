import { reactive } from 'vue'

const STORAGE_KEY = 'sidebar_order'

export const SECTION_LABELS = {
    services: 'Services',
    inventory: 'Inventory',
    adminTask: 'Administrative',
    references: 'References',
    reports: 'Reports',
    userManagement: 'User Management',
    settings: 'Settings',
}

export const DEFAULT_SECTION_ORDER = [
    'services', 'inventory', 'adminTask', 'references', 'reports', 'userManagement', 'settings',
]

export const DEFAULT_CHILD_ORDER = {
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
    return Object.fromEntries(Object.entries(src).map(([k, v]) => [k, [...v]]))
}

function loadFromStorage() {
    try {
        const raw = localStorage.getItem(STORAGE_KEY)
        if (raw) return JSON.parse(raw)
    } catch {}
    return null
}

const _stored = loadFromStorage()

const _state = reactive({
    sections: _stored?.sections ?? [...DEFAULT_SECTION_ORDER],
    children: _stored?.children
        ? { ...cloneChildren(DEFAULT_CHILD_ORDER), ...cloneChildren(_stored.children) }
        : cloneChildren(DEFAULT_CHILD_ORDER),
})

export function useSidebarOrder() {
    const getSectionOrder = (sectionId) => {
        const idx = _state.sections.indexOf(sectionId)
        return idx === -1 ? 999 : idx + 1
    }

    const getChildOrder = (sectionId, childId) => {
        const children = _state.children[sectionId] || []
        const idx = children.indexOf(childId)
        return idx === -1 ? 999 : idx + 1
    }

    const save = () => {
        localStorage.setItem(STORAGE_KEY, JSON.stringify({
            sections: [..._state.sections],
            children: cloneChildren(_state.children),
        }))
    }

    const reset = () => {
        _state.sections = [...DEFAULT_SECTION_ORDER]
        _state.children = cloneChildren(DEFAULT_CHILD_ORDER)
        localStorage.removeItem(STORAGE_KEY)
    }

    return { state: _state, getSectionOrder, getChildOrder, save, reset }
}
