<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch, defineComponent, h } from 'vue'
import { useForm, Link } from '@inertiajs/vue3'

const reorderForm = useForm({
    users: [], // Array of { id, org_sort_order }
})

const dragState = reactive({ active: false, draggedId: null, targetId: null, x: 0, y: 0 })

const startCardDrag = (e, node) => {
    if (node.type !== 'user') return
    e.preventDefault()
    e.stopPropagation()
    dragState.active = true
    dragState.draggedId = node.id
    dragState.targetId = null
    dragState.x = e.clientX
    dragState.y = e.clientY
}

const onGlobalMouseMove = (e) => {
    if (!dragState.active) return
    dragState.x = e.clientX
    dragState.y = e.clientY
}

const onGlobalMouseUp = () => {
    if (!dragState.active) return
    const fromId = Number(dragState.draggedId)

    // Primary: use tracked target; fallback: find card under cursor via DOM
    let toId = dragState.targetId ? Number(dragState.targetId) : null
    if (!toId) {
        const el = document.elementFromPoint(dragState.x, dragState.y)
        const card = el?.closest('[data-user-id]')
        if (card) toId = Number(card.dataset.userId)
    }

    dragState.active = false
    dragState.draggedId = null
    dragState.targetId = null

    if (!toId || fromId === toId) return

    // Build ordered list of all users currently rendered in the chart
    const chartUsers = (props.users || [])
        .filter(u => userCardRefs.has(Number(u.id)))
        .slice()
        .sort((a, b) => (a.org_sort_order ?? 0) - (b.org_sort_order ?? 0) || a.name.localeCompare(b.name))

    const fromIdx = chartUsers.findIndex(u => Number(u.id) === fromId)
    const toIdx   = chartUsers.findIndex(u => Number(u.id) === toId)

    if (fromIdx === -1 || toIdx === -1) return

    // Swap the two users in the ordered array
    ;[chartUsers[fromIdx], chartUsers[toIdx]] = [chartUsers[toIdx], chartUsers[fromIdx]]

    // Assign sequential values (1-based) so every user gets a unique, non-zero order
    reorderForm.users = chartUsers.map((u, i) => ({ id: Number(u.id), org_sort_order: i + 1 }))
    reorderForm.put(route('departments.users.reorder'), {
        preserveScroll: true,
        onSuccess: () => refreshChartLinks(),
    })
}

const enterCardTarget = (nodeId) => {
    if (!dragState.active || Number(nodeId) === Number(dragState.draggedId)) return
    dragState.targetId = nodeId
}

const leaveCardTarget = () => {
    if (!dragState.active) return
    dragState.targetId = null
}

const UserNode = defineComponent({
    name: 'UserNode',
    props: ['node', 'getOrgPath', 'hasPermission', 'openEditPlacementModal', 'openQuickAddModal', 'setUserCardRef', 'startCardDrag', 'enterCardTarget', 'leaveCardTarget', 'draggedId', 'dragTargetId'],
    setup(props) {
        const isUser = props.node.type === 'user'
        const isStructure = props.node.type === 'structure'

        return () => {
            const isDragSource = isUser && Number(props.node.id) === Number(props.draggedId)
            const isDragTarget = isUser && Number(props.node.id) === Number(props.dragTargetId)

            return h('div', { class: 'flex flex-col items-center' }, [
                h('div', {
                    ref: el => props.setUserCardRef(props.node.id, el),
                    'data-user-id': isUser ? props.node.id : undefined,
                    onMousedown: isUser ? (e) => props.startCardDrag(e, props.node) : undefined,
                    onMouseenter: isUser ? () => props.enterCardTarget(props.node.id) : undefined,
                    onMouseleave: isUser ? () => props.leaveCardTarget() : undefined,
                    class: [
                        'relative flex-shrink-0 transition-all duration-200 z-10 select-none',
                        isUser ? 'w-64 rounded-xl border bg-white p-4 shadow-sm cursor-grab' : '',
                        isUser && !isDragSource && !isDragTarget ? 'border-gray-200 hover:border-blue-400 hover:shadow-md' : '',
                        isDragSource ? 'opacity-30 scale-95 border-blue-200' : '',
                        isDragTarget ? 'ring-2 ring-blue-500 ring-offset-2 border-blue-400 shadow-lg scale-[1.03]' : '',
                        isStructure ? 'px-6 py-2.5 rounded-lg border-2 border-dashed bg-slate-50 text-center min-w-[200px] shadow-sm' : ''
                    ],
                    style: isStructure ? {
                        backgroundColor: props.node.structureType === 'section' ? '#bae6fd' :
                                         props.node.structureType === 'unit' ? '#e2e8f0' : '#f1f5f9',
                        borderColor: props.node.structureType === 'section' ? '#0ea5e9' :
                                     props.node.structureType === 'unit' ? '#64748b' : '#cbd5e1',
                        color: props.node.structureType === 'section' ? '#0369a1' :
                               props.node.structureType === 'unit' ? '#334155' : '#475569'
                    } : {}
                }, isUser ? [
                    props.hasPermission('departments.edit') ? h('div', { class: 'absolute top-1 right-1 flex items-center' }, [
                        h('button', {
                            type: 'button',
                            onMousedown: (e) => e.stopPropagation(),
                            onClick: () => props.openQuickAddModal(props.node.data),
                            class: 'rounded p-1 text-gray-300 hover:bg-emerald-50 hover:text-emerald-600 transition-colors z-30',
                            title: 'Add Subordinate'
                        }, [
                            h('svg', { class: 'h-3.5 w-3.5', fill: 'none', stroke: 'currentColor', viewBox: '0 0 24 24' }, [
                                h('path', { 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'stroke-width': '2', d: 'M12 4v16m8-8H4' })
                            ])
                        ]),
                        h('button', {
                            type: 'button',
                            onMousedown: (e) => e.stopPropagation(),
                            onClick: () => props.openEditPlacementModal(props.node.data),
                            class: 'rounded p-1 text-gray-400 hover:bg-blue-50 hover:text-blue-600 transition-colors z-30',
                            title: 'Edit Placement'
                        }, [
                            h('svg', { class: 'h-3.5 w-3.5', fill: 'none', stroke: 'currentColor', viewBox: '0 0 24 24' }, [
                                h('path', { 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'stroke-width': '2', d: 'M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z' })
                            ])
                        ])
                    ]) : null,

                    h('div', { class: 'flex items-center gap-3' }, [
                        h('div', { class: 'shrink-0 h-12 w-12 rounded-full border-2 border-white shadow-sm overflow-hidden bg-gradient-to-br from-blue-500 to-blue-600 flex items-center justify-center text-white font-black' },
                            props.node.data.profile_photo
                                ? h('img', { src: `/serve-storage/${props.node.data.profile_photo}`, class: 'h-full w-full object-cover' })
                                : props.node.data.name.charAt(0).toUpperCase()
                        ),
                        h('div', { class: 'min-w-0 flex-1 text-left' }, [
                            h('div', { class: 'truncate text-sm font-black text-gray-900 leading-tight' }, props.node.data.name),
                            h('div', { class: 'truncate text-[10px] font-bold text-gray-400 uppercase tracking-tight' }, props.node.data.position || 'No position'),
                            !props.node.data.is_active ? h('div', { class: 'mt-1' }, [
                                h('span', { class: 'rounded-full bg-rose-50 px-2 py-0.5 text-[8px] font-black uppercase tracking-wider text-rose-700' }, 'Inactive')
                            ]) : null
                        ])
                    ])
                ] : [
                    h('div', { class: 'text-xs font-black' }, props.node.name)
                ]),

                props.node.children?.length ? h('div', { class: 'flex items-start gap-12 pt-10' },
                    props.node.children.map(child => h(UserNode, {
                        key: child.id,
                        node: child,
                        getOrgPath: props.getOrgPath,
                        hasPermission: props.hasPermission,
                        openEditPlacementModal: props.openEditPlacementModal,
                        openQuickAddModal: props.openQuickAddModal,
                        setUserCardRef: props.setUserCardRef,
                        startCardDrag: props.startCardDrag,
                        enterCardTarget: props.enterCardTarget,
                        leaveCardTarget: props.leaveCardTarget,
                        draggedId: props.draggedId,
                        dragTargetId: props.dragTargetId,
                    }))
                ) : null
            ])
        }
    }
})

import AppLayout from '@/Layouts/AppLayout.vue'
import Autocomplete from '@/Components/Autocomplete.vue'
import MultiAutocomplete from '@/Components/MultiAutocomplete.vue'
import { useConfirm } from '@/Composables/useConfirm'
import { useErrorHandler } from '@/Composables/useErrorHandler'
import { usePermission } from '@/Composables/usePermission'
import { useToast } from '@/Composables/useToast'

const props = defineProps({
    departments: Array,
    activeDepartments: Array,
    users: Array,
    authUserDepartmentId: [Number, String],
})

const { confirm } = useConfirm()
const { post, put, destroy } = useErrorHandler()
const { hasPermission } = usePermission()
const { showError } = useToast()

const zoomScale = ref(1)
const panX = ref(0)
const panY = ref(0)
const isDragging = ref(false)
const startDragX = ref(0)
const startDragY = ref(0)

const zoomIn = () => {
    zoomScale.value = Math.min(3, Math.round((zoomScale.value + 0.1) * 10) / 10)
}

const zoomOut = () => {
    zoomScale.value = Math.max(0.1, Math.round((zoomScale.value - 0.1) * 10) / 10)
}

const resetZoom = () => {
    zoomScale.value = 1
    panX.value = 0
    panY.value = 0
}

const handleWheel = (e) => {
    const delta = e.deltaY > 0 ? -0.1 : 0.1
    zoomScale.value = Math.max(0.1, Math.min(3, Math.round((zoomScale.value + delta) * 10) / 10))
}

const startDrag = (e) => {
    if (dragState.active) return
    isDragging.value = true
    startDragX.value = e.clientX - panX.value
    startDragY.value = e.clientY - panY.value
}

const onDrag = (e) => {
    if (!isDragging.value) return
    panX.value = e.clientX - startDragX.value
    panY.value = e.clientY - startDragY.value
}

const stopDrag = () => {
    isDragging.value = false
}

const selectedDepartmentId = ref(props.authUserDepartmentId || props.departments?.[0]?.id || '')
const chartContent = ref(null)
const userCardRefs = new Map()
const chartLinks = ref([])
const chartSize = ref({ width: 0, height: 0 })

const departmentSelectOptions = computed(() => [
    ...(props.departments || []).map(department => ({
        id: department.id,
        name: department.is_active ? department.name : `${department.name} (Inactive)`,
    })),
])

const selectedDepartment = computed(() => {
    return (props.departments || []).find(department => Number(department.id) === Number(selectedDepartmentId.value)) || null
})

const draggedUserData = computed(() =>
    props.users?.find(u => Number(u.id) === Number(dragState.draggedId)) ?? null
)

const usersById = computed(() => {
    const map = new Map()
    ;(props.users || []).forEach(user => map.set(Number(user.id), user))
    return map
})

const selectedDepartmentUsers = computed(() => {
    if (!selectedDepartment.value) return []
    return (props.users || []).filter(user => Number(user.department_id) === Number(selectedDepartment.value.id))
})

const photoPreview = ref(null)

const handlePhotoSelect = (e) => {
    const file = e.target.files[0]
    if (file) {
        placementForm.profile_photo = file
        photoPreview.value = URL.createObjectURL(file)
    }
}

const userHierarchy = computed(() => {
    if (!selectedDepartment.value) return []
    
    const userMap = new Map()
    const relevantUserIds = new Set()
    const inDeptUsers = selectedDepartmentUsers.value
    inDeptUsers.forEach(u => relevantUserIds.add(Number(u.id)))
    
    inDeptUsers.forEach(u => {
        (u.managers || []).forEach(m => relevantUserIds.add(Number(m.id)))
    })

    props.users.forEach(u => {
        if (relevantUserIds.has(Number(u.id))) {
            userMap.set(Number(u.id), { 
                id: Number(u.id),
                type: 'user',
                data: u,
                children: [] 
            })
        }
    })

    userMap.forEach(node => {
        const user = node.data
        const inChartManagers = (user.managers || []).filter(m => userMap.has(Number(m.id)) && Number(m.id) !== Number(user.id))
        if (inChartManagers.length > 0) {
            const primaryManagerId = Number(inChartManagers[0].id)
            userMap.get(primaryManagerId).children.push(node)
        }
    })

    const getUserOrgPath = (user) => {
        return [
            user.department_section_id ? { type: 'section', id: Number(user.department_section_id), name: user.section } : null,
            user.department_unit_id ? { type: 'unit', id: Number(user.department_unit_id), name: user.unit } : null,
            user.department_sub_unit_id ? { type: 'sub_unit', id: Number(user.department_sub_unit_id), name: user.sub_unit } : null
        ].filter(Boolean)
    }

    const injectStructure = (subordinates, parentPath) => {
        if (!subordinates || subordinates.length === 0) return []
        
        const result = []
        const groups = new Map()
        
        subordinates.forEach(sub => {
            const subPath = getUserOrgPath(sub.data)
            let i = 0
            while(i < subPath.length && parentPath[i] && Number(subPath[i].id) === Number(parentPath[i].id)) {
                i++
            }
            
            if (i === subPath.length) {
                sub.children = injectStructure(sub.children, subPath)
                result.push(sub)
            } else {
                const level = subPath[i]
                const key = `${level.type}-${level.id}`
                if (!groups.has(key)) {
                    groups.set(key, { info: level, items: [], fullPath: subPath.slice(0, i + 1) })
                }
                groups.get(key).items.push(sub)
            }
        })
        
        groups.forEach(group => {
            result.push({
                id: `struct-${group.info.type}-${group.info.id}-${group.items[0].id}`,
                type: 'structure',
                structureType: group.info.type,
                name: group.info.name,
                children: injectStructure(group.items, group.fullPath)
            })
        })
        
        return result
    }

    const rootNodes = Array.from(userMap.values()).filter(node => {
        const managers = (node.data.managers || []).filter(m => userMap.has(Number(m.id)) && Number(m.id) !== Number(node.data.id))
        return managers.length === 0
    })

    return injectStructure(rootNodes, [])
})

const getOrgPath = (user) => {
    const parts = []
    if (user.section) parts.push(user.section)
    if (user.unit) parts.push(user.unit)
    if (user.sub_unit) parts.push(user.sub_unit)
    return parts.length ? parts.join(' > ') : 'General'
}

const showStructureModal = ref(false)

const unplacedUsers = computed(() => {
    return (props.users || []).filter(user => !user.department_sub_unit_id)
})

const managerOptions = computed(() => {
    return (props.users || [])
        .filter(user => user.is_active && user.is_manager && Number(user.id) !== Number(placementForm.user_id || 0))
        .map(user => ({ id: user.id, name: user.name }))
})

const activeDepartmentOptions = computed(() => props.activeDepartments || [])

const placementSections = computed(() => {
    const department = activeDepartmentOptions.value.find(item => Number(item.id) === Number(placementForm.department_id))
    return department?.sections || []
})

const placementUnits = computed(() => {
    const section = placementSections.value.find(item => Number(item.id) === Number(placementForm.department_section_id))
    return section?.units || []
})

const placementSubUnits = computed(() => {
    const unit = placementUnits.value.find(item => Number(item.id) === Number(placementForm.department_unit_id))
    return unit?.sub_units || []
})

const selectedPlacementDepartment = computed(() => {
    return activeDepartmentOptions.value.find(item => Number(item.id) === Number(placementForm.department_id)) || null
})

const selectedPlacementSection = computed(() => {
    return placementSections.value.find(item => Number(item.id) === Number(placementForm.department_section_id)) || null
})

const selectedPlacementUnit = computed(() => {
    return placementUnits.value.find(item => Number(item.id) === Number(placementForm.department_unit_id)) || null
})

const selectedPlacementSubUnit = computed(() => {
    return placementSubUnits.value.find(item => Number(item.id) === Number(placementForm.department_sub_unit_id)) || null
})

const userSelectOptions = computed(() => {
    return (props.users || [])
        .filter(user => user.is_active)
        .map(user => ({
            id: user.id,
            name: `${user.name} (${user.email})`,
        }))
})

watch(() => props.departments, (departments) => {
    if (!departments?.length) {
        selectedDepartmentId.value = ''
        return
    }

    if (!departments.some(department => Number(department.id) === Number(selectedDepartmentId.value))) {
        selectedDepartmentId.value = departments[0].id
    }
}, { deep: true })

const centerChart = async () => {
    await nextTick()
    const container = chartContent.value?.parentElement
    if (!container || !chartContent.value) return
    const containerW = container.offsetWidth
    const contentW = chartContent.value.scrollWidth
    panX.value = Math.round(containerW / 2 - (contentW / 2) * zoomScale.value)
    panY.value = 40
}

watch(selectedDepartmentUsers, () => refreshChartLinks(), { deep: true })
watch(selectedDepartmentId, async () => { await refreshChartLinks(); await centerChart() })
watch(zoomScale, () => refreshChartLinks())

onMounted(async () => {
    window.addEventListener('resize', refreshChartLinks)
    window.addEventListener('mousemove', onGlobalMouseMove)
    window.addEventListener('mouseup', onGlobalMouseUp)
    await refreshChartLinks()
    await centerChart()
})

onBeforeUnmount(() => {
    window.removeEventListener('resize', refreshChartLinks)
    window.removeEventListener('mousemove', onGlobalMouseMove)
    window.removeEventListener('mouseup', onGlobalMouseUp)
})

watch(() => dragState.active, (active) => {
    document.body.style.cursor = active ? 'grabbing' : ''
    document.body.style.userSelect = active ? 'none' : ''
})

const setUserCardRef = (nodeId, el) => {
    if (el) {
        userCardRefs.set(nodeId, el)
    } else {
        userCardRefs.delete(nodeId)
    }
}

const refreshChartLinks = async () => {
    await nextTick()

    const root = chartContent.value
    if (!root) {
        chartLinks.value = []
        return
    }

    chartSize.value = {
        width: Math.max(root.scrollWidth, root.offsetWidth, 2000),
        height: Math.max(root.scrollHeight, root.offsetHeight, 2000),
    }

    const links = []
    const rootRect = root.getBoundingClientRect()

    const traceHierarchy = (nodes) => {
        nodes.forEach(node => {
            const parentEl = userCardRefs.get(node.id)
            if (!parentEl) {
                if (node.children) traceHierarchy(node.children)
                return
            }

            const parentRect = parentEl.getBoundingClientRect()
            node.children.forEach(child => {
                const childEl = userCardRefs.get(child.id)
                if (!childEl) return

                const childRect = childEl.getBoundingClientRect()
                
                const x1 = (parentRect.left - rootRect.left + parentRect.width / 2) / zoomScale.value
                const y1 = (parentRect.bottom - rootRect.top) / zoomScale.value
                const x2 = (childRect.left - rootRect.left + childRect.width / 2) / zoomScale.value
                const y2 = (childRect.top - rootRect.top) / zoomScale.value
                
                const midY = (y1 + y2) / 2
                const path = `M ${x1} ${y1} L ${x1} ${midY} L ${x2} ${midY} L ${x2} ${y2}`

                links.push({
                    key: `${node.id}-${child.id}`,
                    path: path
                })

                traceHierarchy([child])
            })
        })
    }

    traceHierarchy(userHierarchy.value)
    chartLinks.value = links
}

const nodeForm = useForm({
    name: '',
    description: '',
    is_active: true,
})

const showNodeModal = ref(false)
const nodeMode = ref('create')
const nodeType = ref('department')
const nodeParent = ref(null)
const editingNode = ref(null)

const nodeTitle = computed(() => {
    const action = nodeMode.value === 'create' ? 'Create' : 'Edit'
    return `${action} ${nodeLabel(nodeType.value)}`
})

const nodeLabel = (type) => ({
    department: 'Department',
    section: 'Section',
    unit: 'Unit',
    subUnit: 'Sub-Unit',
})[type] || 'Node'

const openCreateNode = (type, parent = null) => {
    nodeMode.value = 'create'
    nodeType.value = type
    nodeParent.value = parent
    editingNode.value = null
    nodeForm.reset()
    nodeForm.name = ''
    nodeForm.description = ''
    nodeForm.is_active = true
    showNodeModal.value = true
}

const openEditNode = (type, node) => {
    nodeMode.value = 'edit'
    nodeType.value = type
    editingNode.value = node
    nodeParent.value = null
    nodeForm.name = node.name || ''
    nodeForm.description = node.description || ''
    nodeForm.is_active = !!node.is_active
    showNodeModal.value = true
}

const closeNodeModal = () => {
    showNodeModal.value = false
    editingNode.value = null
    nodeParent.value = null
    nodeForm.clearErrors()
}

const autoSelectNewNode = (type, name) => {
    nextTick(() => {
        const trimmed = name.trim()
        if (type === 'section') {
            const created = placementSections.value.find(s => s.name.trim() === trimmed)
            if (created) {
                placementForm.department_section_id = created.id
                placementForm.department_unit_id = ''
                placementForm.department_sub_unit_id = ''
            }
        } else if (type === 'unit') {
            const created = placementUnits.value.find(u => u.name.trim() === trimmed)
            if (created) {
                placementForm.department_unit_id = created.id
                placementForm.department_sub_unit_id = ''
            }
        } else if (type === 'subUnit') {
            const created = placementSubUnits.value.find(su => su.name.trim() === trimmed)
            if (created) placementForm.department_sub_unit_id = created.id
        }
    })
}

const submitNode = () => {
    const payload = nodeForm.data()
    const isPlacementCreate = nodeMode.value === 'create' && showPlacementModal.value
    const createdType = nodeType.value
    const createdName = nodeForm.name

    const options = {
        preserveScroll: true,
        onSuccess: () => {
            closeNodeModal()
            if (isPlacementCreate) autoSelectNewNode(createdType, createdName)
        },
        onError: handleErrors,
    }

    if (nodeMode.value === 'create') {
        if (nodeType.value === 'department') return post(route('departments.store'), payload, options)
        if (nodeType.value === 'section') return post(route('departments.sections.store', nodeParent.value.id), payload, options)
        if (nodeType.value === 'unit') return post(route('departments.units.store', nodeParent.value.id), payload, options)
        if (nodeType.value === 'subUnit') return post(route('departments.sub-units.store', nodeParent.value.id), payload, options)
    }

    if (nodeType.value === 'department') return put(route('departments.update', editingNode.value.id), payload, options)
    if (nodeType.value === 'section') return put(route('departments.sections.update', editingNode.value.id), payload, options)
    if (nodeType.value === 'unit') return put(route('departments.units.update', editingNode.value.id), payload, options)
    if (nodeType.value === 'subUnit') return put(route('departments.sub-units.update', editingNode.value.id), payload, options)
}

const deleteNode = async (type, node) => {
    const confirmed = await confirm({
        title: `Delete ${nodeLabel(type)}`,
        message: `Delete "${node.name}"? This is blocked if it still has child records or assigned users.`,
    })
    if (!confirmed) return

    const options = { preserveScroll: true, onError: handleErrors }
    if (type === 'department') return destroy(route('departments.destroy', node.id), options)
    if (type === 'section') return destroy(route('departments.sections.destroy', node.id), options)
    if (type === 'unit') return destroy(route('departments.units.destroy', node.id), options)
    if (type === 'subUnit') return destroy(route('departments.sub-units.destroy', node.id), options)
}

const placementForm = useForm({
    user_id: '',
    department_id: '',
    department_section_id: '',
    department_unit_id: '',
    department_sub_unit_id: '',
    manager_ids: [],
    profile_photo: null,
    org_sort_order: 0,
})

const showPlacementModal = ref(false)
const placementMode = ref('assign')

const openAssignUserModal = (department, section, unit, subUnit) => {
    placementMode.value = 'assign'
    placementForm.reset()
    placementForm.user_id = ''
    placementForm.department_id = department.id
    placementForm.department_section_id = section.id
    placementForm.department_unit_id = unit.id
    placementForm.department_sub_unit_id = subUnit.id
    placementForm.manager_ids = []
    placementForm.profile_photo = null
    placementForm.org_sort_order = 0
    photoPreview.value = null
    showPlacementModal.value = true
}

const openEditPlacementModal = (user) => {
    placementMode.value = 'edit'
    placementForm.user_id = user.id
    placementForm.department_id = user.department_id ? Number(user.department_id) : ''
    placementForm.department_section_id = user.department_section_id ? Number(user.department_section_id) : ''
    placementForm.department_unit_id = user.department_unit_id ? Number(user.department_unit_id) : ''
    placementForm.department_sub_unit_id = user.department_sub_unit_id ? Number(user.department_sub_unit_id) : ''
    placementForm.manager_ids = (user.managers || []).map(manager => Number(manager.id))
    placementForm.profile_photo = null
    placementForm.org_sort_order = user.org_sort_order || 0
    photoPreview.value = user.profile_photo ? `/serve-storage/${user.profile_photo}` : null
    showPlacementModal.value = true
}

const openQuickAddModal = (user) => {
    placementMode.value = 'assign'
    placementForm.reset()
    placementForm.user_id = ''
    placementForm.department_id = user.department_id ? Number(user.department_id) : ''
    placementForm.department_section_id = user.department_section_id ? Number(user.department_section_id) : ''
    placementForm.department_unit_id = user.department_unit_id ? Number(user.department_unit_id) : ''
    placementForm.department_sub_unit_id = user.department_sub_unit_id ? Number(user.department_sub_unit_id) : ''
    placementForm.manager_ids = [Number(user.id)]
    placementForm.profile_photo = null
    placementForm.org_sort_order = 0
    photoPreview.value = null
    showPlacementModal.value = true
}

const closePlacementModal = () => {
    showPlacementModal.value = false
    placementForm.clearErrors()
    photoPreview.value = null
}

const clearPlacementOrg = () => {
    placementForm.department_id = ''
    placementForm.department_section_id = ''
    placementForm.department_unit_id = ''
    placementForm.department_sub_unit_id = ''
}

const handlePlacementDepartmentChange = () => {
    placementForm.department_section_id = ''
    placementForm.department_unit_id = ''
    placementForm.department_sub_unit_id = ''
}

const handlePlacementSectionChange = () => {
    placementForm.department_unit_id = ''
    placementForm.department_sub_unit_id = ''
}

const handlePlacementUnitChange = () => {
    placementForm.department_sub_unit_id = ''
}

const submitPlacement = () => {
    if (!placementForm.user_id) {
        showError('Select a user first.')
        return
    }

    const data = {
        ...placementForm.data(),
        _method: 'PUT',
    }

    post(route('departments.users.placement', placementForm.user_id), data, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: closePlacementModal,
        onError: handleErrors,
    })
}

const handleErrors = (errors) => {
    const message = Object.values(errors || {}).flat().join(', ') || 'Unable to save changes.'
    showError(message)
}

const isDownloading = ref(false)

const downloadChart = async () => {
    if (!chartContent.value || !selectedDepartment.value) return
    isDownloading.value = true

    const savedPanX = panX.value
    const savedPanY = panY.value
    const savedZoom = zoomScale.value

    try {
        const { toJpeg } = await import('html-to-image')

        // Reset view so the full chart is captured at natural size
        panX.value = 0
        panY.value = 0
        zoomScale.value = 1
        await refreshChartLinks()
        // Let the DOM fully settle after the transform reset
        await new Promise(r => setTimeout(r, 200))

        const name = (selectedDepartment.value?.name || 'Org-Chart').replace(/\s+/g, '-')

        const dataUrl = await toJpeg(chartContent.value, {
            quality: 0.92,
            backgroundColor: '#f8fafc',
            pixelRatio: 2,
            skipFonts: false,
        })

        const link = document.createElement('a')
        link.href = dataUrl
        link.download = `${name}-Org-Chart.jpg`
        document.body.appendChild(link)
        link.click()
        document.body.removeChild(link)
    } catch (err) {
        console.error('Chart download failed:', err)
        showError('Could not generate chart image. Please try again.')
    } finally {
        panX.value = savedPanX
        panY.value = savedPanY
        zoomScale.value = savedZoom
        await refreshChartLinks()
        isDownloading.value = false
    }
}
</script>

<template>
    <AppLayout title="Departments" content-class="w-full">
        <template #header>
            Departments
        </template>

        <div class="px-4 sm:px-6 lg:px-8 space-y-6">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <h2 class="text-2xl font-black text-gray-900">Department Organisation Chart</h2>
                        <p class="mt-1 text-sm font-medium text-gray-500">Build Department, Section, Unit, and Sub-Unit references, then place existing users into the chart.</p>
                    </div>
                    <button
                        v-if="hasPermission('departments.create')"
                        type="button"
                        @click="openCreateNode('department')"
                        class="inline-flex items-center justify-center gap-2 rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-bold text-white shadow-sm transition-colors hover:bg-blue-700"
                    >
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v12m6-6H6" />
                        </svg>
                        Department
                    </button>
                </div>

                <div class="rounded-xl border border-gray-200 bg-white shadow-sm">
                    <div class="flex flex-col gap-4 border-b border-gray-200 bg-gray-50 px-6 py-4 lg:flex-row lg:items-center lg:justify-between">
                        <div class="w-full lg:max-w-md">
                            <Autocomplete
                                v-model="selectedDepartmentId"
                                :options="departmentSelectOptions"
                                label-key="name"
                                value-key="id"
                                placeholder="Select department..."
                            />
                        </div>
                        <div v-if="selectedDepartment" class="flex flex-wrap items-center gap-2">
                            <span
                                :class="selectedDepartment.is_active ? 'bg-emerald-50 text-emerald-700 border-emerald-100' : 'bg-rose-50 text-rose-700 border-rose-100'"
                                class="rounded-full border px-3 py-1 text-xs font-black uppercase tracking-wider"
                            >
                                {{ selectedDepartment.is_active ? 'Active' : 'Inactive' }}
                            </span>
                            <button
                                v-if="hasPermission('departments.edit')"
                                type="button"
                                @click="showStructureModal = true"
                                class="rounded-lg border border-blue-600 bg-blue-600 px-3 py-2 text-xs font-black uppercase tracking-wider text-white transition-colors hover:bg-blue-700"
                            >
                                Manage Structure
                            </button>
                            <button
                                v-if="hasPermission('departments.edit')"
                                type="button"
                                @click="openEditNode('department', selectedDepartment)"
                                class="rounded-lg border border-gray-200 px-3 py-2 text-xs font-black uppercase tracking-wider text-gray-600 transition-colors hover:bg-gray-100"
                            >
                                Edit Dept
                            </button>
                            <button
                                v-if="hasPermission('departments.delete')"
                                type="button"
                                @click="deleteNode('department', selectedDepartment)"
                                class="rounded-lg border border-rose-100 bg-rose-50 px-3 py-2 text-xs font-black uppercase tracking-wider text-rose-700 transition-colors hover:bg-rose-100"
                            >
                                Delete
                            </button>
                        </div>
                    </div>

                    <div v-if="!props.departments?.length" class="px-6 py-16 text-center">
                        <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-blue-50 text-blue-600">
                            <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 21h18M6 21V7l6-4 6 4v14M9 10h1m4 0h1M9 14h1m4 0h1" />
                            </svg>
                        </div>
                        <p class="mt-4 text-sm font-bold text-gray-700">No departments configured.</p>
                        <p class="mt-1 text-xs text-gray-500">Create a department to start building the organisation chart.</p>
                    </div>

                    <div v-else-if="selectedDepartment" class="relative overflow-hidden rounded-b-xl bg-slate-50/50" style="height: 70vh; min-height: 600px;">
                        <!-- Zoom Controls -->
                        <div class="absolute bottom-4 right-4 z-20 flex flex-col gap-2 rounded-lg bg-white p-2 shadow-md border border-gray-200">
                            <button type="button" @click="zoomIn" class="flex h-8 w-8 items-center justify-center rounded bg-gray-50 text-xl font-bold text-gray-600 hover:bg-gray-100 hover:text-gray-900" title="Zoom In">+</button>
                            <button type="button" @click="resetZoom" class="flex h-8 w-8 items-center justify-center rounded bg-gray-50 text-[10px] font-black text-gray-600 hover:bg-gray-100 hover:text-gray-900" title="Reset Zoom">100%</button>
                            <button type="button" @click="zoomOut" class="flex h-8 w-8 items-center justify-center rounded bg-gray-50 text-xl font-bold text-gray-600 hover:bg-gray-100 hover:text-gray-900" title="Zoom Out">-</button>
                            <div class="my-0.5 border-t border-gray-100"></div>
                            <button type="button" @click="downloadChart" :disabled="isDownloading" class="flex h-8 w-8 items-center justify-center rounded bg-gray-50 text-gray-600 hover:bg-blue-50 hover:text-blue-600 disabled:opacity-40" title="Download as JPG">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                                </svg>
                            </button>
                        </div>

                        <!-- Download overlay -->
                        <div v-if="isDownloading" class="absolute inset-0 z-30 flex flex-col items-center justify-center gap-2 rounded-b-xl bg-white/80 backdrop-blur-sm">
                            <svg class="h-5 w-5 animate-spin text-blue-600" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
                            </svg>
                            <span class="text-xs font-bold text-gray-600">Generating image…</span>
                        </div>

                        <!-- Canvas Wrapper -->
                        <div
                            class="h-full w-full cursor-grab active:cursor-grabbing select-none"
                            @wheel.prevent="handleWheel"
                            @mousedown="startDrag"
                            @mousemove="onDrag"
                            @mouseup="stopDrag"
                            @mouseleave="stopDrag"
                        >
                            <div
                                ref="chartContent"
                                class="relative min-w-max p-12 origin-top-left"
                                :style="{ transform: `translate(${panX}px, ${panY}px) scale(${zoomScale})` }"
                            >
                                <svg
                                    class="pointer-events-none absolute left-0 top-0 z-20"
                                    :width="chartSize.width"
                                    :height="chartSize.height"
                                >
                                    <defs>
                                        <marker id="org-arrow" markerWidth="6" markerHeight="6" refX="5" refY="3" orient="auto">
                                            <path d="M0,0 L6,3 L0,6 Z" fill="#60a5fa" />
                                        </marker>
                                    </defs>
                                    <path
                                        v-for="link in chartLinks"
                                        :key="link.key"
                                        :d="link.path"
                                        fill="none"
                                        stroke="#60a5fa"
                                        stroke-width="2"
                                        marker-end="url(#org-arrow)"
                                    />
                                </svg>

                                <div class="relative z-10 flex flex-col items-center">
                                    <div class="flex items-start gap-12">
                                        <UserNode
                                            v-for="root in userHierarchy"
                                            :key="root.id"
                                            :node="root"
                                            :getOrgPath="getOrgPath"
                                            :hasPermission="hasPermission"
                                            :openEditPlacementModal="openEditPlacementModal"
                                            :openQuickAddModal="openQuickAddModal"
                                            :setUserCardRef="setUserCardRef"
                                            :startCardDrag="startCardDrag"
                                            :enterCardTarget="enterCardTarget"
                                            :leaveCardTarget="leaveCardTarget"
                                            :draggedId="dragState.draggedId"
                                            :dragTargetId="dragState.targetId"
                                        />
                                    </div>
                                    
                                    <div v-if="userHierarchy.length === 0" class="rounded-lg border border-dashed border-gray-300 bg-white px-12 py-16 text-center">
                                        <p class="text-sm font-bold text-gray-600">No users assigned to this department hierarchy yet.</p>
                                        <p class="mt-1 text-xs text-gray-500">Use "Unplaced Users" or "Manage Structure" to assign team members.</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Unplaced Users section hidden as requested -->
            </div>

        <div v-if="showNodeModal" class="fixed inset-0 z-[60] overflow-y-auto">
            <div class="flex min-h-screen items-center justify-center px-4 py-6">
                <div class="fixed inset-0 bg-black/20 backdrop-blur-md" @click="closeNodeModal"></div>
                <div class="relative w-full max-w-lg rounded-xl border border-gray-100 bg-white p-6 shadow-2xl">
                    <div class="mb-6 flex items-center justify-between">
                        <h3 class="text-xl font-black text-gray-900">{{ nodeTitle }}</h3>
                        <button type="button" @click="closeNodeModal" class="text-gray-400 transition-colors hover:text-gray-600">
                            <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <form class="space-y-4" @submit.prevent="submitNode">
                        <div>
                            <label class="mb-1 block text-xs font-bold uppercase tracking-wider text-gray-500">Name</label>
                            <input v-model="nodeForm.name" type="text" required class="block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-bold uppercase tracking-wider text-gray-500">Description</label>
                            <textarea v-model="nodeForm.description" rows="3" class="block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"></textarea>
                        </div>
                        <label class="flex items-center gap-2 text-sm font-bold text-gray-700">
                            <input v-model="nodeForm.is_active" type="checkbox" class="rounded border-gray-300 text-blue-600 shadow-sm focus:ring-blue-500">
                            Active
                        </label>
                        <div class="flex justify-end gap-3 border-t pt-5">
                            <button type="button" @click="closeNodeModal" class="rounded-lg bg-gray-100 px-4 py-2 text-sm font-bold text-gray-600 transition-colors hover:bg-gray-200">Cancel</button>
                            <button type="submit" :disabled="nodeForm.processing" class="rounded-lg bg-blue-600 px-5 py-2 text-sm font-bold text-white transition-colors hover:bg-blue-700 disabled:opacity-50">Save</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div v-if="showPlacementModal" class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex min-h-screen items-center justify-center px-4 py-6">
                <div class="fixed inset-0 bg-black/20 backdrop-blur-md" @click="closePlacementModal"></div>
                <div class="relative w-full max-w-2xl rounded-xl border border-gray-100 bg-white p-6 shadow-2xl">
                    <div class="mb-6 flex items-center justify-between">
                        <h3 class="text-xl font-black text-gray-900">{{ placementMode === 'assign' ? 'Assign User' : 'Edit Organisation Placement' }}</h3>
                        <button type="button" @click="closePlacementModal" class="text-gray-400 transition-colors hover:text-gray-600">
                            <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <form class="space-y-4" @submit.prevent="submitPlacement">
                        <div>
                            <label class="mb-1 block text-xs font-bold uppercase tracking-wider text-gray-500">User</label>
                            <Autocomplete
                                v-model="placementForm.user_id"
                                :options="userSelectOptions"
                                label-key="name"
                                value-key="id"
                                placeholder="Select user..."
                                :disabled="placementMode === 'edit'"
                            />
                        </div>

                        <div class="grid gap-4 md:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-xs font-bold uppercase tracking-wider text-gray-500">Department</label>
                                <select v-model="placementForm.department_id" @change="handlePlacementDepartmentChange" class="block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                    <option value="">No Department</option>
                                    <option v-for="department in activeDepartmentOptions" :key="department.id" :value="department.id">{{ department.name }}</option>
                                </select>
                            </div>
                            <div>
                                <div class="mb-1 flex min-h-5 items-center justify-between gap-2">
                                    <label class="text-xs font-bold uppercase tracking-wider text-gray-500">Section</label>
                                    <div class="flex items-center gap-2">
                                        <button
                                            v-if="hasPermission('departments.create')"
                                            type="button"
                                            :disabled="!selectedPlacementDepartment"
                                            @click="openCreateNode('section', selectedPlacementDepartment)"
                                            class="text-[10px] font-black uppercase tracking-wider text-blue-600 hover:underline disabled:text-gray-300 disabled:no-underline"
                                        >
                                            Add
                                        </button>
                                        <button
                                            v-if="hasPermission('departments.edit') && selectedPlacementSection"
                                            type="button"
                                            @click="openEditNode('section', selectedPlacementSection)"
                                            class="text-[10px] font-black uppercase tracking-wider text-gray-500 hover:text-gray-700"
                                        >
                                            Edit
                                        </button>
                                    </div>
                                </div>
                                <select v-model="placementForm.department_section_id" :disabled="!placementForm.department_id" @change="handlePlacementSectionChange" class="block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 disabled:bg-gray-100">
                                    <option value="">Select Section</option>
                                    <option v-for="section in placementSections" :key="section.id" :value="section.id">{{ section.name }}</option>
                                </select>
                            </div>
                            <div>
                                <div class="mb-1 flex min-h-5 items-center justify-between gap-2">
                                    <label class="text-xs font-bold uppercase tracking-wider text-gray-500">Unit</label>
                                    <div class="flex items-center gap-2">
                                        <button
                                            v-if="hasPermission('departments.create')"
                                            type="button"
                                            :disabled="!selectedPlacementSection"
                                            @click="openCreateNode('unit', selectedPlacementSection)"
                                            class="text-[10px] font-black uppercase tracking-wider text-blue-600 hover:underline disabled:text-gray-300 disabled:no-underline"
                                        >
                                            Add
                                        </button>
                                        <button
                                            v-if="hasPermission('departments.edit') && selectedPlacementUnit"
                                            type="button"
                                            @click="openEditNode('unit', selectedPlacementUnit)"
                                            class="text-[10px] font-black uppercase tracking-wider text-gray-500 hover:text-gray-700"
                                        >
                                            Edit
                                        </button>
                                    </div>
                                </div>
                                <select v-model="placementForm.department_unit_id" :disabled="!placementForm.department_section_id" @change="handlePlacementUnitChange" class="block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 disabled:bg-gray-100">
                                    <option value="">Select Unit</option>
                                    <option v-for="unit in placementUnits" :key="unit.id" :value="unit.id">{{ unit.name }}</option>
                                </select>
                            </div>
                            <div>
                                <div class="mb-1 flex min-h-5 items-center justify-between gap-2">
                                    <label class="text-xs font-bold uppercase tracking-wider text-gray-500">Sub-Unit (Optional)</label>
                                    <div class="flex items-center gap-2">
                                        <button
                                            v-if="hasPermission('departments.create')"
                                            type="button"
                                            :disabled="!selectedPlacementUnit"
                                            @click="openCreateNode('subUnit', selectedPlacementUnit)"
                                            class="text-[10px] font-black uppercase tracking-wider text-blue-600 hover:underline disabled:text-gray-300 disabled:no-underline"
                                        >
                                            Add
                                        </button>
                                        <button
                                            v-if="hasPermission('departments.edit') && selectedPlacementSubUnit"
                                            type="button"
                                            @click="openEditNode('subUnit', selectedPlacementSubUnit)"
                                            class="text-[10px] font-black uppercase tracking-wider text-gray-500 hover:text-gray-700"
                                        >
                                            Edit
                                        </button>
                                    </div>
                                </div>
                                <select v-model="placementForm.department_sub_unit_id" :disabled="!placementForm.department_unit_id" class="block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 disabled:bg-gray-100">
                                    <option value="">No Sub-Unit</option>
                                    <option v-for="subUnit in placementSubUnits" :key="subUnit.id" :value="subUnit.id">{{ subUnit.name }}</option>
                                </select>
                            </div>
                        </div>

                        <div>
                            <div class="mb-1 flex items-center justify-between">
                                <label class="text-xs font-bold uppercase tracking-wider text-gray-500">Reports To</label>
                                <button type="button" @click="clearPlacementOrg" class="text-xs font-bold text-gray-500 hover:text-gray-700">Clear Org Placement</button>
                            </div>
                            <MultiAutocomplete
                                v-model="placementForm.manager_ids"
                                :options="managerOptions"
                                label-key="name"
                                value-key="id"
                                placeholder="Select managers..."
                                :limit="5"
                            />
                        </div>

                        <div class="grid gap-4 md:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-xs font-bold uppercase tracking-wider text-gray-500">Profile Photo</label>
                                <div class="flex items-center gap-4">
                                    <div v-if="photoPreview" class="h-16 w-16 shrink-0 overflow-hidden rounded-full border-2 border-gray-100 shadow-sm">
                                        <img :src="photoPreview" class="h-full w-full object-cover" />
                                    </div>
                                    <input
                                        type="file"
                                        @input="handlePhotoSelect"
                                        accept="image/*"
                                        class="block w-full text-sm text-gray-500 file:mr-4 file:rounded-lg file:border-0 file:bg-blue-50 file:px-4 file:py-2 file:text-xs file:font-black file:text-blue-700 hover:file:bg-blue-100"
                                    />
                                </div>
                                <progress v-if="placementForm.progress" :value="placementForm.progress.percentage" max="100" class="mt-2 h-1 w-full overflow-hidden rounded-full bg-gray-100 [&::-webkit-progress-bar]:bg-gray-100 [&::-webkit-progress-value]:bg-blue-600">
                                    {{ placementForm.progress.percentage }}%
                                </progress>
                            </div>
                        </div>

                        <div class="flex justify-end gap-3 border-t pt-5">
                            <button type="button" @click="closePlacementModal" class="rounded-lg bg-gray-100 px-4 py-2 text-sm font-bold text-gray-600 transition-colors hover:bg-gray-200">Cancel</button>
                            <button type="submit" :disabled="placementForm.processing" class="rounded-lg bg-blue-600 px-5 py-2 text-sm font-bold text-white transition-colors hover:bg-blue-700 disabled:opacity-50">Save</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <!-- Drag ghost card -->
        <Teleport to="body">
            <div
                v-if="dragState.active && draggedUserData"
                class="pointer-events-none fixed z-[9999] w-56 rounded-xl border border-blue-300 bg-white p-3 shadow-2xl"
                :style="{ left: dragState.x + 'px', top: dragState.y + 'px', transform: 'translate(-50%, -60%) rotate(2deg)', opacity: 0.92 }"
            >
                <div class="flex items-center gap-2">
                    <div class="shrink-0 h-9 w-9 rounded-full overflow-hidden bg-gradient-to-br from-blue-500 to-blue-600 flex items-center justify-center text-white text-sm font-black">
                        <img v-if="draggedUserData.profile_photo" :src="`/serve-storage/${draggedUserData.profile_photo}`" class="h-full w-full object-cover" />
                        <span v-else>{{ draggedUserData.name.charAt(0).toUpperCase() }}</span>
                    </div>
                    <div class="min-w-0">
                        <div class="truncate text-xs font-black text-gray-900">{{ draggedUserData.name }}</div>
                        <div class="truncate text-[10px] font-bold text-gray-400 uppercase tracking-tight">{{ draggedUserData.position || 'No position' }}</div>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Structure Management Modal -->
        <div v-if="showStructureModal && selectedDepartment" class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex min-h-screen items-center justify-center px-4 py-6">
                <div class="fixed inset-0 bg-black/40 backdrop-blur-sm" @click="showStructureModal = false"></div>
                <div class="relative w-full max-w-5xl rounded-2xl border border-gray-100 bg-white p-8 shadow-2xl">
                    <div class="mb-8 flex items-center justify-between border-b border-gray-100 pb-4">
                        <div>
                            <h3 class="text-2xl font-black text-gray-900">Manage Org Structure</h3>
                            <p class="text-sm font-medium text-gray-500">{{ selectedDepartment.name }}</p>
                        </div>
                        <button type="button" @click="showStructureModal = false" class="rounded-full bg-gray-50 p-2 text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-600">
                            <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <div class="max-h-[70vh] overflow-y-auto pr-4 custom-scrollbar">
                        <div class="space-y-6">
                            <div class="flex items-center justify-between">
                                <h4 class="text-xs font-black uppercase tracking-widest text-blue-500">Sections</h4>
                                <button
                                    v-if="hasPermission('departments.create')"
                                    type="button"
                                    @click="openCreateNode('section', selectedDepartment)"
                                    class="rounded-lg bg-blue-50 px-3 py-1.5 text-xs font-black text-blue-700 hover:bg-blue-100"
                                >
                                    + Add Section
                                </button>
                            </div>

                            <div v-for="section in selectedDepartment.sections" :key="section.id" class="rounded-xl border border-gray-200 bg-white p-4">
                                <div class="mb-4 flex items-center justify-between border-b border-gray-50 pb-2">
                                    <div class="text-sm font-black text-gray-900">{{ section.name }}</div>
                                    <div class="flex items-center gap-2">
                                        <button v-if="hasPermission('departments.create')" type="button" @click="openCreateNode('unit', section)" class="text-[10px] font-black uppercase tracking-wider text-blue-600 hover:underline">Add Unit</button>
                                        <button v-if="hasPermission('departments.edit')" type="button" @click="openEditNode('section', section)" class="text-[10px] font-black uppercase tracking-wider text-gray-400 hover:text-gray-600">Edit</button>
                                        <button v-if="hasPermission('departments.delete')" type="button" @click="deleteNode('section', section)" class="text-[10px] font-black uppercase tracking-wider text-rose-400 hover:text-rose-600">Delete</button>
                                    </div>
                                </div>

                                <div class="grid gap-4 sm:grid-cols-2">
                                    <div v-for="unit in section.units" :key="unit.id" class="rounded-lg border border-slate-100 bg-slate-50 p-3">
                                        <div class="mb-2 flex items-center justify-between">
                                            <div class="text-xs font-black text-slate-700">{{ unit.name }}</div>
                                            <div class="flex items-center gap-2">
                                                <button v-if="hasPermission('departments.create')" type="button" @click="openCreateNode('subUnit', unit)" class="text-[9px] font-black text-blue-600">Sub</button>
                                                <button v-if="hasPermission('departments.edit')" type="button" @click="openEditNode('unit', unit)" class="text-[9px] font-black text-gray-500">Edit</button>
                                                <button v-if="hasPermission('departments.delete')" type="button" @click="deleteNode('unit', unit)" class="text-[9px] font-black text-rose-400">Del</button>
                                            </div>
                                        </div>

                                        <div class="space-y-2">
                                            <div v-for="subUnit in unit.sub_units" :key="subUnit.id" class="flex items-center justify-between rounded border border-white bg-white px-2 py-1.5 shadow-sm">
                                                <span class="text-[11px] font-bold text-gray-700">{{ subUnit.name }}</span>
                                                <div class="flex items-center gap-1">
                                                    <button type="button" @click="openAssignUserModal(selectedDepartment, section, unit, subUnit)" class="text-[9px] font-black text-emerald-600 hover:underline">Assign</button>
                                                    <button v-if="hasPermission('departments.edit')" type="button" @click="openEditNode('subUnit', subUnit)" class="text-[9px] font-black text-gray-500">Edit</button>
                                                    <button v-if="hasPermission('departments.delete')" type="button" @click="deleteNode('subUnit', subUnit)" class="text-[9px] font-black text-rose-400">Del</button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
--- End of content ---