import { reactive } from 'vue'
import {
    REGISTRY_SECTION_ORDER,
    REGISTRY_SECTION_LABELS,
    REGISTRY_CHILD_ORDER,
    REGISTRY_CHILD_LABELS,
} from '@/Composables/useModuleRegistry.js'

/**
 * Section/child structure and default labels come from the module registry —
 * see useModuleRegistry.js. This file owns only the *user customisation* layer
 * on top of it: saved ordering and renamed labels.
 */
export const SECTION_LABELS = REGISTRY_SECTION_LABELS

export const DEFAULT_SECTION_ORDER = REGISTRY_SECTION_ORDER

export const DEFAULT_CHILD_ORDER = REGISTRY_CHILD_ORDER

export const CHILD_LABELS = REGISTRY_CHILD_LABELS

function cloneChildren(src) {
    if (!src || typeof src !== 'object') return {}
    return Object.fromEntries(
        Object.entries(src).map(([k, v]) => [k, Array.isArray(v) ? [...v] : { ...v }])
    )
}

function normalizeSidebarChildren(children) {
    const normalized = cloneChildren(children)

    Object.keys(normalized).forEach((sectionId) => {
        if (sectionId !== 'services' && Array.isArray(normalized[sectionId])) {
            normalized[sectionId] = normalized[sectionId].filter(childId => childId !== 'stamps')
        }
    })

    const serviceChildren = Array.isArray(normalized.services)
        ? normalized.services.filter(childId => childId !== 'stamps')
        : []
    const sapIndex = serviceChildren.indexOf('sap-requests')
    const insertAt = sapIndex === -1 ? serviceChildren.length : sapIndex + 1
    serviceChildren.splice(insertAt, 0, 'stamps')
    normalized.services = serviceChildren

    return normalized
}

function normalizeChildLabels(labels) {
    const normalized = cloneChildren(labels)

    if (normalized.monitoring?.stamps) {
        if (!normalized.services || typeof normalized.services !== 'object' || Array.isArray(normalized.services)) {
            normalized.services = {}
        }

        if (!Object.prototype.hasOwnProperty.call(normalized.services, 'stamps')) {
            normalized.services.stamps = normalized.monitoring.stamps
        }

        delete normalized.monitoring.stamps
        if (Object.keys(normalized.monitoring).length === 0) {
            delete normalized.monitoring
        }
    }

    return normalized
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
            const savedChildren = normalizeSidebarChildren(config.children)
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
        const childLabels = (rawChildLabels && !Array.isArray(rawChildLabels))
            ? rawChildLabels
            : {}
        _state.customChildLabels = normalizeChildLabels(childLabels)
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
