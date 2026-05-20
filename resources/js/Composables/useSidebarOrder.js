import { reactive } from 'vue'

export const SECTION_LABELS = {
    dashboard: 'Dashboard',
    projectTracker: 'Project Tracker',
    services: 'Services',
    inventory: 'Inventory',
    monitoring: 'Monitoring',
    adminTask: 'Administrative',
    references: 'References',
    reports: 'Reports',
    userManagement: 'User Management',
    settings: 'Settings',
}

export const DEFAULT_SECTION_ORDER = [
    'dashboard', 'projectTracker', 'services', 'inventory', 'monitoring', 'adminTask', 'references', 'reports', 'userManagement', 'settings',
]

export const DEFAULT_CHILD_ORDER = {
    dashboard: [],
    projectTracker: [],
    services: ['tickets', 'task-boards', 'pos-requests', 'sap-requests'],
    inventory: ['assets', 'stock-ins', 'stock-transfers', 'stock-receivings', 'inventory-report'],
    monitoring: ['npc-status', 'payments'],
    adminTask: ['dtr', 'attendance-logs', 'scheduling', 'service-vehicle-trips', 'presence', 'kb-articles'],
    references: ['companies', 'departments', 'clusters', 'stores', 'vendors', 'activity-templates', 'categories', 'sub-categories', 'items', 'request-types', 'form-builder'],
    reports: ['store-health', 'sla-performance', 'assignee-performance'],
    userManagement: ['users', 'roles'],
    settings: ['system-settings', 'ticket-archive', 'canned-messages', 'leadership-points', 'profile'],
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
        'stock-ins': 'Stock In',
        'stock-transfers': 'Stock Transfer',
        'stock-receivings': 'Receiving Stock',
        'inventory-report': 'Inventory Report',
    },
    monitoring: {
        'npc-status': 'NPC Status',
        'payments': 'Payments & SOA',
    },
    adminTask: {
        'dtr': 'DTR',
        'attendance-logs': 'Attendance Logs',
        'scheduling': 'Scheduling',
        'presence': 'Presence',
        'kb-articles': 'KB Articles',
        'service-vehicle-trips': 'Service Vehicle Trips',
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
        'leadership-points': 'Leadership Points',
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
            // Merge per-section: keep saved order, but append any default children
            // that aren't yet in the saved list (so new modules appear automatically).
            const merged = cloneChildren(DEFAULT_CHILD_ORDER)
            const savedChildren = cloneChildren(config.children)
            for (const sectionId of Object.keys(merged)) {
                const savedList = Array.isArray(savedChildren[sectionId]) ? savedChildren[sectionId] : null
                if (!savedList) continue
                const defaults = merged[sectionId]
                // Start with saved order, then append any default child not already present
                const finalList = [...savedList]
                for (const childId of defaults) {
                    if (!finalList.includes(childId)) {
                        finalList.push(childId)
                    }
                }
                merged[sectionId] = finalList
            }
            // Carry over any sections in saved config not in defaults
            for (const sectionId of Object.keys(savedChildren)) {
                if (!(sectionId in merged)) {
                    merged[sectionId] = savedChildren[sectionId]
                }
            }
            _state.children = merged
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

    const ensureChild = (sectionId, childId, label = null) => {
        if (!_state.children[sectionId]) {
            _state.children[sectionId] = []
        }

        if (!_state.children[sectionId].includes(childId)) {
            _state.children[sectionId].push(childId)
        }

        if (label) {
            if (!_state.customChildLabels[sectionId]) {
                _state.customChildLabels[sectionId] = {}
            }

            if (!Object.prototype.hasOwnProperty.call(_state.customChildLabels[sectionId], childId)) {
                _state.customChildLabels[sectionId][childId] = label
            }
        }
    }

    const ensureDynamicFormChildren = (forms = []) => {
        forms.forEach((form) => {
            if (!form?.slug) return

            ensureChild('services', 'form-' + form.slug, form.name)
        })
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
        ensureDynamicFormChildren,
        serialize,
        reset 
    }
}
