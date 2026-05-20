<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch, defineComponent, h } from 'vue'
import { useForm, Link } from '@inertiajs/vue3'

const reorderForm = useForm({
    users: [], // Array of { id, org_sort_order }
})

const dragState = reactive({
    active: false,
    kind: null,         // 'user' | 'structure'
    draggedId: null,    // user DB id (number) OR structure composite id (string)
    draggedDbId: null,  // structure actual DB id (number), null for user drags
    targetId: null,
    targetDbId: null,
    x: 0,
    y: 0,
})

const startCardDrag = (e, node) => {
    if (node.type !== 'user' && node.type !== 'structure') return
    e.preventDefault()
    e.stopPropagation()
    dragState.active = true
    dragState.kind = node.type
    dragState.draggedId = node.id
    dragState.draggedDbId = node.type === 'structure' ? node.structureDbId : null
    dragState.targetId = null
    dragState.targetDbId = null
    dragState.x = e.clientX
    dragState.y = e.clientY
}

const onGlobalMouseMove = (e) => {
    if (!dragState.active) return
    dragState.x = e.clientX
    dragState.y = e.clientY
}

const findStructureSiblings = (nodeId, nodes) => {
    const directSiblings = nodes.filter(n => n.type === 'structure')
    if (directSiblings.some(n => n.id === nodeId)) return directSiblings
    for (const node of nodes) {
        if (node.children?.length) {
            const found = findStructureSiblings(nodeId, node.children)
            if (found) return found
        }
    }
    return null
}

const onGlobalMouseUp = () => {
    if (!dragState.active) return

    const kind = dragState.kind

    if (kind === 'user') {
        const fromId = Number(dragState.draggedId)
        let toId = dragState.targetId ? Number(dragState.targetId) : null
        if (!toId) {
            const el = document.elementFromPoint(dragState.x, dragState.y)
            const card = el?.closest('[data-user-id]')
            if (card) toId = Number(card.dataset.userId)
        }

        dragState.active = false
        dragState.draggedId = null
        dragState.targetId = null
        dragState.kind = null

        if (!toId || fromId === toId) return

        const chartUsers = (props.users || [])
            .filter(u => userCardRefs.has(Number(u.id)))
            .slice()
            .sort((a, b) => (a.org_sort_order ?? 0) - (b.org_sort_order ?? 0) || a.name.localeCompare(b.name))

        const fromIdx = chartUsers.findIndex(u => Number(u.id) === fromId)
        const toIdx   = chartUsers.findIndex(u => Number(u.id) === toId)
        if (fromIdx === -1 || toIdx === -1) return

        ;[chartUsers[fromIdx], chartUsers[toIdx]] = [chartUsers[toIdx], chartUsers[fromIdx]]
        reorderForm.users = chartUsers.map((u, i) => ({ id: Number(u.id), org_sort_order: i + 1 }))
        reorderForm.put(route('departments.users.reorder'), {
            preserveScroll: true,
            onSuccess: () => refreshChartLinks(),
        })
    } else if (kind === 'structure') {
        const fromNodeId = dragState.draggedId
        const fromDbId = dragState.draggedDbId
        let toDbId = dragState.targetDbId

        if (!toDbId) {
            const el = document.elementFromPoint(dragState.x, dragState.y)
            const structEl = el?.closest('[data-struct-node-id]')
            if (structEl) {
                toDbId = Number(structEl.dataset.structDbId)
            }
        }

        dragState.active = false
        dragState.draggedId = null
        dragState.draggedDbId = null
        dragState.targetId = null
        dragState.targetDbId = null
        dragState.kind = null

        if (!toDbId || fromDbId === toDbId) return

        const siblings = findStructureSiblings(fromNodeId, userHierarchy.value)
        if (!siblings || siblings.length < 2) return

        const fromIdx = siblings.findIndex(s => s.structureDbId === fromDbId)
        const toIdx   = siblings.findIndex(s => s.structureDbId === toDbId)
        if (fromIdx === -1 || toIdx === -1) return

        const ordered = [...siblings]
        ;[ordered[fromIdx], ordered[toIdx]] = [ordered[toIdx], ordered[fromIdx]]
        const items = ordered.map((s, i) => ({ id: s.structureDbId, sort_order: i + 1 }))

        reorderStructure(items)
    } else {
        dragState.active = false
        dragState.draggedId = null
        dragState.targetId = null
        dragState.kind = null
    }
}

const enterCardTarget = (node) => {
    if (!dragState.active) return
    if (dragState.kind === 'user') {
        if (node.type !== 'user' || Number(node.id) === Number(dragState.draggedId)) return
        dragState.targetId = node.id
        dragState.targetDbId = null
    } else if (dragState.kind === 'structure') {
        if (node.type !== 'structure') return
        if (node.id === dragState.draggedId) return
        dragState.targetId = node.id
        dragState.targetDbId = node.structureDbId
    }
}

const leaveCardTarget = () => {
    if (!dragState.active) return
    dragState.targetId = null
    dragState.targetDbId = null
}

const UserNode = defineComponent({
    name: 'UserNode',
    props: ['node', 'getOrgPath', 'hasPermission', 'openEditPlacementModal', 'openQuickAddModal', 'openAddVacantModal', 'openEditVacantModal', 'destroyVacant', 'setUserCardRef', 'startCardDrag', 'enterCardTarget', 'leaveCardTarget', 'draggedId', 'dragTargetId', 'dragKind'],
    setup(props) {
        const isUser = props.node.type === 'user'
        const isStructure = props.node.type === 'structure'

        return () => {
            const isDragSource = isUser && Number(props.node.id) === Number(props.draggedId) && props.dragKind === 'user'
            const isDragTarget = isUser && Number(props.node.id) === Number(props.dragTargetId) && props.dragKind === 'user'
            const isVacant = isUser && props.node.data?.is_vacant
            const isStructureDragSource = isStructure && props.node.id === props.draggedId && props.dragKind === 'structure'
            const isStructureDragTarget = isStructure && props.node.id === props.dragTargetId && props.dragKind === 'structure'

            return h('div', { class: 'flex flex-col items-center' }, [
                h('div', {
                    ref: el => props.setUserCardRef(props.node.id, el),
                    'data-user-id': isUser ? props.node.id : undefined,
                    'data-struct-node-id': isStructure ? props.node.id : undefined,
                    'data-struct-db-id': isStructure ? props.node.structureDbId : undefined,
                    onMousedown: (isUser || isStructure) ? (e) => props.startCardDrag(e, props.node) : undefined,
                    onMouseenter: (isUser || isStructure) ? () => props.enterCardTarget(props.node) : undefined,
                    onMouseleave: (isUser || isStructure) ? () => props.leaveCardTarget() : undefined,
                    class: [
                        'relative flex-shrink-0 transition-all duration-200 z-10 select-none',
                        isUser ? 'w-64 rounded-xl border p-4 shadow-sm cursor-grab' : '',
                        isVacant ? 'border-dashed border-gray-400 bg-gray-50/80' : (isUser ? 'bg-white' : ''),
                        isUser && !isDragSource && !isDragTarget && !isVacant ? 'border-gray-200 hover:border-blue-400 hover:shadow-md' : '',
                        isUser && !isDragSource && !isDragTarget && isVacant ? 'hover:border-amber-400 hover:shadow-md' : '',
                        isDragSource ? 'opacity-30 scale-95 border-blue-200' : '',
                        isDragTarget ? 'ring-2 ring-blue-500 ring-offset-2 border-blue-400 shadow-lg scale-[1.03]' : '',
                        isStructure ? 'px-6 py-2.5 rounded-lg border-2 border-dashed text-center min-w-[200px] shadow-sm cursor-grab bg-slate-700 border-slate-800 text-white' : '',
                        isStructureDragSource ? 'opacity-30 scale-95' : '',
                        isStructureDragTarget ? 'ring-2 ring-offset-2 ring-blue-400 shadow-lg scale-105' : '',
                    ],
                }, isUser ? [
                    // Action buttons
                    props.hasPermission('departments.edit') ? h('div', { class: 'absolute top-1 right-1 flex items-center' }, [
                        // For non-vacant: quick-add real subordinate
                        !isVacant ? h('button', {
                            type: 'button',
                            onMousedown: (e) => e.stopPropagation(),
                            onClick: () => props.openQuickAddModal(props.node.data),
                            class: 'rounded p-1 text-gray-300 hover:bg-emerald-50 hover:text-emerald-600 transition-colors z-30',
                            title: 'Add Subordinate'
                        }, [
                            h('svg', { class: 'h-3.5 w-3.5', fill: 'none', stroke: 'currentColor', viewBox: '0 0 24 24' }, [
                                h('path', { 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'stroke-width': '2', d: 'M12 4v16m8-8H4' })
                            ])
                        ]) : null,
                        // For non-vacant: add vacant subordinate
                        !isVacant ? h('button', {
                            type: 'button',
                            onMousedown: (e) => e.stopPropagation(),
                            onClick: () => props.openAddVacantModal(props.node.data),
                            class: 'rounded p-1 text-gray-300 hover:bg-amber-50 hover:text-amber-500 transition-colors z-30',
                            title: 'Add Vacant Subordinate'
                        }, [
                            h('svg', { class: 'h-3.5 w-3.5', fill: 'none', stroke: 'currentColor', viewBox: '0 0 24 24' }, [
                                h('path', { 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'stroke-width': '2', d: 'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z' }),
                                h('path', { 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'stroke-width': '2', d: 'M22 12h-4m2-2v4' })
                            ])
                        ]) : null,
                        // Edit button
                        h('button', {
                            type: 'button',
                            onMousedown: (e) => e.stopPropagation(),
                            onClick: () => isVacant ? props.openEditVacantModal(props.node.data) : props.openEditPlacementModal(props.node.data),
                            class: 'rounded p-1 text-gray-400 hover:bg-blue-50 hover:text-blue-600 transition-colors z-30',
                            title: isVacant ? 'Edit Vacant Position' : 'Edit Placement'
                        }, [
                            h('svg', { class: 'h-3.5 w-3.5', fill: 'none', stroke: 'currentColor', viewBox: '0 0 24 24' }, [
                                h('path', { 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'stroke-width': '2', d: 'M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z' })
                            ])
                        ]),
                        // Delete button for vacant
                        isVacant && props.hasPermission('departments.delete') ? h('button', {
                            type: 'button',
                            onMousedown: (e) => e.stopPropagation(),
                            onClick: () => props.destroyVacant(props.node.data),
                            class: 'rounded p-1 text-gray-300 hover:bg-rose-50 hover:text-rose-500 transition-colors z-30',
                            title: 'Remove Vacant Position'
                        }, [
                            h('svg', { class: 'h-3.5 w-3.5', fill: 'none', stroke: 'currentColor', viewBox: '0 0 24 24' }, [
                                h('path', { 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'stroke-width': '2', d: 'M6 18L18 6M6 6l12 12' })
                            ])
                        ]) : null,
                    ]) : null,

                    h('div', { class: 'flex items-center gap-3' }, [
                        // Avatar / vacant placeholder
                        isVacant
                            ? h('div', { class: 'shrink-0 h-12 w-12 rounded-full border-2 border-dashed border-gray-300 bg-gray-100 flex items-center justify-center text-gray-400' }, [
                                h('svg', { class: 'h-6 w-6', fill: 'none', stroke: 'currentColor', viewBox: '0 0 24 24' }, [
                                    h('path', { 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'stroke-width': '1.5', d: 'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z' })
                                ])
                              ])
                            : h('div', { class: 'shrink-0 h-12 w-12 rounded-full border-2 border-white shadow-sm overflow-hidden bg-gradient-to-br from-blue-500 to-blue-600 flex items-center justify-center text-white font-black' },
                                props.node.data.profile_photo
                                    ? h('img', { src: `/serve-storage/${props.node.data.profile_photo}`, class: 'h-full w-full object-cover' })
                                    : props.node.data.name.charAt(0).toUpperCase()
                              ),
                        h('div', { class: 'min-w-0 flex-1 text-left' }, [
                            h('div', { class: ['truncate text-sm font-black leading-tight', isVacant ? 'text-gray-500 italic' : 'text-gray-900'] }, props.node.data.name),
                            isVacant
                                ? h('span', { class: 'mt-1 inline-block rounded-full bg-amber-50 border border-amber-200 px-2 py-0.5 text-[9px] font-black uppercase tracking-wider text-amber-600' }, 'Vacant')
                                : h('div', { class: 'truncate text-[10px] font-bold text-gray-400 uppercase tracking-tight' }, props.node.data.position || 'No position'),
                            !props.node.data.is_active && !isVacant ? h('div', { class: 'mt-1' }, [
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
                        openAddVacantModal: props.openAddVacantModal,
                        openEditVacantModal: props.openEditVacantModal,
                        destroyVacant: props.destroyVacant,
                        setUserCardRef: props.setUserCardRef,
                        startCardDrag: props.startCardDrag,
                        enterCardTarget: props.enterCardTarget,
                        leaveCardTarget: props.leaveCardTarget,
                        draggedId: props.draggedId,
                        dragTargetId: props.dragTargetId,
                        dragKind: props.dragKind,
                    }))
                ) : null
            ])
        }
    }
})

const StructureNode = defineComponent({
    name: 'StructureNode',
    props: ['node', 'hasPermission', 'openCreateNode', 'openEditNode', 'deleteNode', 'openTeamAssignUserModal'],
    setup(props) {
        return () => h('div', { class: 'ml-6 mt-4 space-y-4' }, [
            h('div', { class: 'flex items-center justify-between rounded-xl border border-gray-200 bg-white p-4 shadow-sm' }, [
                h('div', { class: 'flex items-center gap-3' }, [
                    h('div', { class: 'text-sm font-black text-gray-900' }, props.node.name),
                    props.node.code ? h('span', { class: 'rounded bg-gray-100 px-1.5 py-0.5 text-[10px] font-bold text-gray-500 font-mono' }, props.node.code) : null,
                ]),
                h('div', { class: 'flex items-center gap-2' }, [
                    h('button', {
                        type: 'button',
                        onClick: () => props.openTeamAssignUserModal(props.node.department_id, props.node),
                        class: 'text-[10px] font-black uppercase tracking-wider text-emerald-600 hover:underline'
                    }, 'Assign'),
                    props.hasPermission('departments.create') ? h('button', {
                        type: 'button',
                        onClick: () => props.openCreateNode('node', props.node),
                        class: 'text-[10px] font-black uppercase tracking-wider text-blue-600 hover:underline'
                    }, 'Add Child') : null,
                    props.hasPermission('departments.edit') ? h('button', {
                        type: 'button',
                        onClick: () => props.openEditNode('node', props.node),
                        class: 'text-[10px] font-black uppercase tracking-wider text-gray-400 hover:text-gray-600'
                    }, 'Edit') : null,
                    props.hasPermission('departments.delete') ? h('button', {
                        type: 'button',
                        onClick: () => props.deleteNode('node', props.node),
                        class: 'text-[10px] font-black uppercase tracking-wider text-rose-400 hover:text-rose-600'
                    }, 'Delete') : null,
                ])
            ]),
            props.node.children?.length ? h('div', { class: 'border-l-2 border-gray-100' },
                props.node.children.map(child => h(StructureNode, {
                    key: child.id,
                    node: child,
                    hasPermission: props.hasPermission,
                    openCreateNode: props.openCreateNode,
                    openEditNode: props.openEditNode,
                    deleteNode: props.deleteNode,
                    openTeamAssignUserModal: props.openTeamAssignUserModal,
                }))
            ) : null
        ])
    }
})

import AppLayout from '@/Layouts/AppLayout.vue'
import Autocomplete from '@/Components/Autocomplete.vue'
import HierarchySelector from '@/Components/HierarchySelector.vue'
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

const filterNodeId = ref('')

watch(selectedDepartmentId, () => {
    filterNodeId.value = ''
})

const nodesFlat = computed(() => {
    if (!selectedDepartment.value?.nodes) return []
    return flattenNodes(selectedDepartment.value.nodes)
})

const selectedDepartmentUsers = computed(() => {
    if (!selectedDepartment.value) return []
    return (props.users || []).filter(user => Number(user.department_id) === Number(selectedDepartment.value.id))
})

const filteredChartUsers = computed(() => {
    let users = selectedDepartmentUsers.value
    if (filterNodeId.value) {
        const targetId = Number(filterNodeId.value)
        const descendantIds = [targetId]
        
        // Recursively find all sub-team IDs
        const findDescendants = (nodes) => {
            nodes.forEach(n => {
                descendantIds.push(n.id)
                if (n.children?.length) findDescendants(n.children)
            })
        }
        
        const targetNode = findNodeInTree(selectedDepartment.value.nodes, targetId)
        if (targetNode?.children?.length) findDescendants(targetNode.children)
        
        users = users.filter(u => descendantIds.includes(Number(u.department_node_id)))
    }
    return users
})

const photoPreview = ref(null)

const handlePhotoSelect = (e) => {
    const file = e.target.files[0]
    if (file) {
        placementForm.profile_photo = file
        photoPreview.value = URL.createObjectURL(file)
    }
}

const flattenNodes = (nodes, level = 0, path = []) => {
    let flat = []
    nodes.forEach(n => {
        const fullPath = [...path, n.name]
        flat.push({ 
            ...n, 
            level, 
            fullPathName: fullPath.join(' > ') 
        })
        if (n.children?.length) {
            flat = flat.concat(flattenNodes(n.children, level + 1, fullPath))
        }
    })
    return flat
}

const findNodeInTree = (nodes, id) => {
    if (!nodes || !id) return null;
    for (const node of nodes) {
        if (Number(node.id) === Number(id)) return node;
        if (node.children?.length) {
            const found = findNodeInTree(node.children, id);
            if (found) return found;
        }
    }
    return null;
}

const userHierarchy = computed(() => {
    if (!selectedDepartment.value) return []
    
    const userMap = new Map()
    const relevantUserIds = new Set()
    const inDeptUsers = filteredChartUsers.value
    inDeptUsers.forEach(u => relevantUserIds.add(Number(u.id)))
    
    const isFiltering = !!filterNodeId.value

    if (!isFiltering) {
        inDeptUsers.forEach(u => {
            (u.managers || []).forEach(m => relevantUserIds.add(Number(m.id)))
        })
    }

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

    const findDirectChildNode = (leafNodeId, parentNodeId) => {
        let current = nodesFlat.value.find(n => Number(n.id) === Number(leafNodeId))
        if (!current) return null
        
        while (current) {
            if (Number(current.parent_id) === Number(parentNodeId)) return current
            const pId = current.parent_id
            if (!pId) break
            current = nodesFlat.value.find(n => Number(n.id) === Number(pId))
        }
        return null
    }

    const injectStructure = (subordinates, parentNodeId = null) => {
        if (!subordinates || subordinates.length === 0) return []
        
        const result = []
        const groups = new Map()
        
        subordinates.forEach(sub => {
            const userNodeId = sub.data.department_node_id
            
            if (Number(userNodeId) === Number(parentNodeId)) {
                sub.children = injectStructure(sub.children, userNodeId)
                result.push(sub)
            } else {
                const targetNode = findDirectChildNode(userNodeId, parentNodeId)
                if (targetNode) {
                    const key = `node-${targetNode.id}`
                    if (!groups.has(key)) {
                        groups.set(key, { info: targetNode, items: [] })
                    }
                    groups.get(key).items.push(sub)
                } else {
                    sub.children = injectStructure(sub.children, userNodeId)
                    result.push(sub)
                }
            }
        })
        
        groups.forEach(group => {
            result.push({
                id: `struct-node-${group.info.id}-${group.items[0].id}`,
                type: 'structure',
                structureDbId: group.info.id,
                name: group.info.name,
                sortOrder: group.info.sort_order ?? 0,
                children: injectStructure(group.items, group.info.id)
            })
        })

        const userNodes = result.filter(n => n.type !== 'structure')
        const structureNodes = result.filter(n => n.type === 'structure').sort((a, b) => (a.sortOrder ?? 0) - (b.sortOrder ?? 0))
        return [...userNodes, ...structureNodes]
    }

    const rootNodes = Array.from(userMap.values()).filter(node => {
        const managers = (node.data.managers || []).filter(m => userMap.has(Number(m.id)) && Number(m.id) !== Number(node.data.id))
        return managers.length === 0
    })

    return injectStructure(rootNodes, isFiltering ? Number(filterNodeId.value) : null)
})

const getOrgPath = (user) => {
    return user.org_path || 'General'
}

const showStructureModal = ref(false)

const unplacedUsers = computed(() => {
    return (props.users || []).filter(user => !user.department_node_id)
})

const managerOptions = computed(() => {
    return (props.users || [])
        .filter(user => user.is_active && user.is_manager && Number(user.id) !== Number(placementForm.user_id || 0))
        .map(user => ({ id: Number(user.id), name: user.name }))
})

const activeDepartmentOptions = computed(() => props.activeDepartments || [])

const structureSortOrderMap = computed(() => {
    const map = new Map()
    const traverse = (nodes) => {
        nodes.forEach(n => {
            map.set(`node-${n.id}`, n.sort_order ?? 0)
            if (n.children?.length) traverse(n.children)
        })
    }
    activeDepartmentOptions.value.forEach(d => traverse(d.nodes || []))
    return map
})

const draggedStructureData = computed(() => {
    if (dragState.kind !== 'structure' || !dragState.draggedDbId) return null
    
    const findNode = (nodes) => {
        for (const n of nodes) {
            if (Number(n.id) === Number(dragState.draggedDbId)) return n
            if (n.children?.length) {
                const found = findNode(n.children)
                if (found) return found
            }
        }
        return null
    }

    for (const dept of activeDepartmentOptions.value) {
        const found = findNode(dept.nodes || [])
        if (found) return { name: found.name, type: 'node' }
    }
    return null
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
watch(filterNodeId, async () => {
    await refreshChartLinks()
    await centerChart()
})

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
    parent_id: '',
    name: '',
    code: '',
    description: '',
    is_active: true,
})

const showNodeModal = ref(false)
const nodeMode = ref('create')
const nodeType = ref('department') // 'department' | 'node'
const nodeParent = ref(null) // department or node
const editingNode = ref(null)
const createNodeSource = ref('') // 'vacant' | 'placement' | ''

const nodeTitle = computed(() => {
    const action = nodeMode.value === 'create' ? 'Create' : 'Edit'
    const label = nodeType.value === 'department' ? 'Department' : 'Hierarchy Node'
    return `${action} ${label}`
})

const openCreateNode = (type, parent = null) => {
    createNodeSource.value = showVacantModal.value ? 'vacant' : (showPlacementModal.value ? 'placement' : '')
    nodeMode.value = 'create'
    nodeType.value = type
    nodeParent.value = parent
    editingNode.value = null
    nodeForm.reset()
    nodeForm.parent_id = type === 'node' && parent && parent.department_id ? (parent.id || '') : ''
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
    nodeForm.code = node.code || ''
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

const autoSelectNewNode = (name) => {
    const source = createNodeSource.value
    if (!source) return

    // Double nextTick to wait for Inertia reload and Vue reactivity
    nextTick(() => nextTick(() => {
        const trimmed = name.trim()
        const targetForm = source === 'vacant' ? vacantForm : placementForm
        
        // Find the dept ID we're currently working with in that form
        const deptId = Number(targetForm.department_id)
        if (!deptId) return

        // Find the department in active options to get its (refreshed) nodes
        const dept = activeDepartmentOptions.value.find(d => Number(d.id) === deptId)
        if (!dept?.nodes) return

        // Search recursively for the new node by name
        const findNode = (nodes) => {
            for (const n of nodes) {
                if (n.name.trim() === trimmed) return n
                if (n.children?.length) {
                    const found = findNode(n.children)
                    if (found) return found
                }
            }
            return null
        }

        const newNode = findNode(dept.nodes)
        if (newNode) {
            targetForm.department_node_id = newNode.id
        }
    }))
}

const submitNode = () => {
    const payload = nodeForm.data()
    const isCreate = nodeMode.value === 'create'
    const nodeName = nodeForm.name

    const options = {
        preserveScroll: true,
        onSuccess: () => {
            closeNodeModal()
            if (isCreate) autoSelectNewNode(nodeName)
        },
        onError: handleErrors,
    }

    if (isCreate) {
        if (nodeType.value === 'department') return post(route('departments.store'), payload, options)
        const deptId = nodeParent.value.department_id || nodeParent.value.id
        return post(route('departments.nodes.store', deptId), payload, options)
    }

    if (nodeType.value === 'department') return put(route('departments.update', editingNode.value.id), payload, options)
    return put(route('departments.nodes.update', editingNode.value.id), payload, options)
}

const deleteNode = async (type, node) => {
    const label = type === 'department' ? 'Department' : 'Hierarchy Node'
    const confirmed = await confirm({
        title: `Delete ${label}`,
        message: `Delete "${node.name}"? This is blocked if it still has children or assigned users.`,
    })
    if (!confirmed) return

    const options = { 
        preserveScroll: true, 
        onSuccess: () => {
            if (type === 'node') {
                if (Number(placementForm.department_node_id) === Number(node.id)) placementForm.department_node_id = '';
                if (Number(vacantForm.department_node_id) === Number(node.id)) vacantForm.department_node_id = '';
            }
        },
        onError: handleErrors 
    }
    if (type === 'department') return destroy(route('departments.destroy', node.id), options)
    return destroy(route('departments.nodes.destroy', node.id), options)
}

const placementForm = useForm({
    user_id: '',
    department_id: '',
    department_node_id: '',
    manager_ids: [],
    profile_photo: null,
    org_sort_order: 0,
})

const showPlacementModal = ref(false)
const placementMode = ref('assign')

const openTeamAssignUserModal = (department, node) => {
    placementMode.value = 'assign'
    placementForm.reset()
    placementForm.user_id = ''
    placementForm.department_id = department.id
    placementForm.department_node_id = node ? node.id : ''
    placementForm.manager_ids = []
    placementForm.profile_photo = null
    placementForm.org_sort_order = 0
    photoPreview.value = null
    showPlacementModal.value = true
}

const openAssignUserModal = () => {
    placementMode.value = 'assign'
    placementForm.reset()
    placementForm.user_id = ''
    placementForm.department_id = selectedDepartment.value ? Number(selectedDepartment.value.id) : ''
    placementForm.department_node_id = ''
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
    placementForm.department_node_id = user.department_node_id ? Number(user.department_node_id) : ''
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
    placementForm.department_node_id = user.department_node_id ? Number(user.department_node_id) : ''
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

const vacantForm = useForm({
    title: '',
    department_id: '',
    department_node_id: '',
    manager_ids: [],
    org_sort_order: 0,
})

const showVacantModal = ref(false)
const vacantMode = ref('create')
const editingVacantUser = ref(null)

const openAddVacantModal = (parentUser = null) => {
    vacantMode.value = 'create'
    editingVacantUser.value = null
    vacantForm.reset()
    if (parentUser && parentUser.id) {
        vacantForm.department_id = parentUser.department_id ? Number(parentUser.department_id) : (selectedDepartment.value?.id || '')
        vacantForm.department_node_id = parentUser.department_node_id ? Number(parentUser.department_node_id) : ''
        vacantForm.manager_ids = [Number(parentUser.id)]
    } else {
        vacantForm.department_id = selectedDepartment.value ? selectedDepartment.value.id : ''
    }
    showVacantModal.value = true
}

const openEditVacantModal = (user) => {
    vacantMode.value = 'edit'
    editingVacantUser.value = user
    vacantForm.title = user.name
    vacantForm.department_id = user.department_id ? Number(user.department_id) : ''
    vacantForm.department_node_id = user.department_node_id ? Number(user.department_node_id) : ''
    vacantForm.manager_ids = (user.managers || []).map(m => Number(m.id))
    vacantForm.org_sort_order = user.org_sort_order || 0
    showVacantModal.value = true
}

const closeVacantModal = () => {
    showVacantModal.value = false
    editingVacantUser.value = null
    vacantForm.clearErrors()
}

const submitVacant = () => {
    const opts = { preserveScroll: true, onSuccess: closeVacantModal, onError: handleErrors }
    if (vacantMode.value === 'create') {
        return post(route('departments.users.vacant.store'), vacantForm.data(), opts)
    }
    return put(route('departments.users.vacant.update', editingVacantUser.value.id), vacantForm.data(), opts)
}

const destroyVacant = async (user) => {
    const confirmed = await confirm({
        title: 'Remove Vacant Position',
        message: `Remove "${user.name}" from the org chart?`,
        confirmLabel: 'Remove',
        variant: 'danger',
    })
    if (!confirmed) return
    destroy(route('departments.users.vacant.destroy', user.id), { preserveScroll: true, onError: handleErrors })
}

const clearPlacementOrg = () => {
    placementForm.department_id = ''
    placementForm.department_node_id = ''
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

const reorderStructure = (items) => {
    put(route('departments.structure.reorder'), { items }, {
        preserveScroll: true,
        onSuccess: () => refreshChartLinks(),
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
                    <div class="flex flex-col gap-4 border-b border-gray-200 bg-gray-50 px-6 py-5">
                        <div class="flex flex-col gap-6 lg:flex-row lg:items-center lg:justify-between">
                            <!-- Left: Department Selector & Status -->
                            <div class="flex flex-1 items-center gap-2">
                                <div class="w-full lg:max-w-md">
                                    <Autocomplete
                                        v-model="selectedDepartmentId"
                                        :options="departmentSelectOptions"
                                        label-key="name"
                                        value-key="id"
                                        placeholder="Select department..."
                                    />
                                </div>
                                <button
                                    v-if="selectedDepartment && hasPermission('departments.edit')"
                                    type="button"
                                    @click="openEditNode('department', selectedDepartment)"
                                    class="rounded-lg bg-white border border-gray-200 px-3 py-2 text-[10px] font-black uppercase tracking-wider text-gray-600 transition-colors hover:bg-gray-100 shadow-sm whitespace-nowrap"
                                    title="Edit department name, code, etc."
                                >
                                    Edit Dept
                                </button>
                            </div>

                            <!-- Right: Action Control Panel -->
                            <div v-if="selectedDepartment" class="flex flex-wrap items-center gap-4">
                                <!-- Group 1: Configuration (Blue) -->
                                <div class="flex items-center gap-1.5 rounded-xl bg-blue-50/50 p-1.5 border border-blue-100">
                                    <button
                                        v-if="hasPermission('departments.edit')"
                                        type="button"
                                        @click="showStructureModal = true"
                                        class="rounded-lg bg-blue-600 px-3 py-2 text-[10px] font-black uppercase tracking-wider text-white transition-colors hover:bg-blue-700 shadow-md shadow-blue-100"
                                        title="Setup Sections, Units, and Sub-Units"
                                    >
                                        Setup Hierarchy
                                    </button>
                                </div>

                                <!-- Group 2: Personnel (Emerald/Amber) -->
                                <div class="flex items-center gap-1.5 rounded-xl bg-emerald-50/30 p-1.5 border border-emerald-100">
                                    <button
                                        v-if="hasPermission('departments.edit')"
                                        type="button"
                                        @click="openAssignUserModal"
                                        class="rounded-lg bg-white border border-emerald-200 px-3 py-2 text-[10px] font-black uppercase tracking-wider text-emerald-700 transition-colors hover:bg-emerald-50 shadow-sm"
                                    >
                                        + Assign User
                                    </button>
                                    <button
                                        v-if="hasPermission('departments.edit')"
                                        type="button"
                                        @click="openAddVacantModal"
                                        class="rounded-lg bg-white border border-amber-200 px-3 py-2 text-[10px] font-black uppercase tracking-wider text-amber-700 transition-colors hover:bg-amber-50 shadow-sm"
                                    >
                                        + Vacant Position
                                    </button>
                                </div>

                                <!-- Group 3: Danger (Rose) -->
                                <button
                                    v-if="hasPermission('departments.delete')"
                                    type="button"
                                    @click="deleteNode('department', selectedDepartment)"
                                    class="rounded-lg border border-rose-100 bg-rose-50 px-3 py-2 text-[10px] font-black uppercase tracking-wider text-rose-700 transition-colors hover:bg-rose-100"
                                >
                                    Delete
                                </button>
                            </div>
                        </div>

                        <!-- Org Chart View Filters -->
                        <div v-if="selectedDepartment" class="pt-4 border-t border-gray-200 mt-2">
                            <div class="flex flex-col gap-2">
                                <div class="flex items-center gap-2 text-gray-400">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z" />
                                    </svg>
                                    <span class="text-[10px] font-black uppercase tracking-widest text-gray-500">View Filters</span>
                                </div>
                                <div class="w-full sm:w-80">
                                    <HierarchySelector
                                        v-model="filterNodeId"
                                        :nodes="selectedDepartment.nodes || []"
                                        placeholder="Focus on a specific Team..."
                                    />
                                </div>
                            </div>
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
                                            :openAddVacantModal="openAddVacantModal"
                                            :openEditVacantModal="openEditVacantModal"
                                            :destroyVacant="destroyVacant"
                                            :setUserCardRef="setUserCardRef"
                                            :startCardDrag="startCardDrag"
                                            :enterCardTarget="enterCardTarget"
                                            :leaveCardTarget="leaveCardTarget"
                                            :draggedId="dragState.draggedId"
                                            :dragTargetId="dragState.targetId"
                                            :dragKind="dragState.kind"
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

        <div v-if="showNodeModal" class="fixed inset-0 z-[70] overflow-y-auto">
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
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-xs font-bold uppercase tracking-wider text-gray-500">Name <span class="text-rose-500">*</span></label>
                                <input v-model="nodeForm.name" type="text" required class="block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <p v-if="nodeForm.errors.name" class="mt-1 text-xs text-rose-600">{{ nodeForm.errors.name }}</p>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-bold uppercase tracking-wider text-gray-500">Code</label>
                                <input v-model="nodeForm.code" type="text" maxlength="50" placeholder="e.g. HR, IT-OPS" class="block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <p v-if="nodeForm.errors.code" class="mt-1 text-xs text-rose-600">{{ nodeForm.errors.code }}</p>
                            </div>
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

        <div v-if="showPlacementModal" class="fixed inset-0 z-[60] overflow-y-auto">
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
                                <select v-model="placementForm.department_id" @change="placementForm.department_node_id = ''" class="block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                    <option value="">No Department</option>
                                    <option v-for="department in activeDepartmentOptions" :key="department.id" :value="department.id">{{ department.name }}</option>
                                </select>
                            </div>
                            <div>
                                <div class="mb-1 flex min-h-5 items-center justify-between gap-2">
                                    <label class="text-xs font-bold uppercase tracking-wider text-gray-500">Team / Placement</label>
                                    <div class="flex items-center gap-2">
                                        <button
                                            v-if="hasPermission('departments.create') && placementForm.department_id"
                                            type="button"
                                            @click="openCreateNode('node', activeDepartmentOptions.find(d => Number(d.id) === Number(placementForm.department_id)))"
                                            class="text-[10px] font-black uppercase tracking-wider text-blue-600 hover:underline"
                                            title="Add root-level team"
                                        >
                                            + New
                                        </button>
                                        <template v-if="placementForm.department_node_id">
                                            <button
                                                v-if="hasPermission('departments.create')"
                                                type="button"
                                                @click="openCreateNode('node', findNodeInTree(activeDepartmentOptions.find(d => Number(d.id) === Number(placementForm.department_id))?.nodes, placementForm.department_node_id))"
                                                class="text-[10px] font-black uppercase tracking-wider text-blue-600 hover:underline"
                                                title="Add child team to selected"
                                            >
                                                + Child
                                            </button>
                                            <button
                                                v-if="hasPermission('departments.edit')"
                                                type="button"
                                                @click="openEditNode('node', findNodeInTree(activeDepartmentOptions.find(d => Number(d.id) === Number(placementForm.department_id))?.nodes, placementForm.department_node_id))"
                                                class="text-[10px] font-black uppercase tracking-wider text-gray-500 hover:text-gray-700"
                                            >
                                                Edit
                                            </button>
                                            <button
                                                v-if="hasPermission('departments.delete')"
                                                type="button"
                                                @click="deleteNode('node', findNodeInTree(activeDepartmentOptions.find(d => Number(d.id) === Number(placementForm.department_id))?.nodes, placementForm.department_node_id))"
                                                class="text-[10px] font-black uppercase tracking-wider text-rose-500 hover:text-rose-700"
                                            >
                                                Del
                                            </button>
                                        </template>
                                    </div>
                                </div>
                                <HierarchySelector
                                    v-model="placementForm.department_node_id"
                                    :nodes="activeDepartmentOptions.find(d => Number(d.id) === Number(placementForm.department_id))?.nodes || []"
                                    label="Select Team Level"
                                    :disabled="!placementForm.department_id"
                                    inline
                                />
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
        <!-- Vacant Position Modal -->
        <div v-if="showVacantModal" class="fixed inset-0 z-[60] overflow-y-auto">
            <div class="flex min-h-screen items-center justify-center px-4 py-6">
                <div class="fixed inset-0 bg-black/20 backdrop-blur-md" @click="closeVacantModal"></div>
                <div class="relative w-full max-w-2xl rounded-xl border border-gray-100 bg-white p-6 shadow-2xl">
                    <div class="mb-6 flex items-center justify-between">
                        <div>
                            <h3 class="text-xl font-black text-gray-900">{{ vacantMode === 'create' ? 'Add Vacant Position' : 'Edit Vacant Position' }}</h3>
                            <p class="mt-0.5 text-xs font-medium text-gray-500">Placeholder node for an unfilled role in the org chart.</p>
                        </div>
                        <button type="button" @click="closeVacantModal" class="text-gray-400 transition-colors hover:text-gray-600">
                            <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <form class="space-y-4" @submit.prevent="submitVacant">
                        <div>
                            <label class="mb-1 block text-xs font-bold uppercase tracking-wider text-gray-500">Position Title</label>
                            <input
                                v-model="vacantForm.title"
                                type="text"
                                placeholder="e.g. Head of Marketing"
                                class="block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-amber-500 focus:ring-amber-500"
                            >
                            <p v-if="vacantForm.errors.title" class="mt-1 text-xs text-rose-600">{{ vacantForm.errors.title }}</p>
                        </div>

                        <div class="grid gap-4 md:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-xs font-bold uppercase tracking-wider text-gray-500">Department</label>
                                <select
                                    v-model="vacantForm.department_id"
                                    @change="vacantForm.department_node_id = ''"
                                    class="block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-amber-500 focus:ring-amber-500"
                                >
                                    <option value="">No Department</option>
                                    <option v-for="dept in activeDepartmentOptions" :key="dept.id" :value="dept.id">{{ dept.name }}</option>
                                </select>
                            </div>
                            <div>
                                <div class="mb-1 flex min-h-5 items-center justify-between gap-2">
                                    <label class="text-xs font-bold uppercase tracking-wider text-gray-500">Team / Placement</label>
                                    <div class="flex items-center gap-2">
                                        <button
                                            v-if="hasPermission('departments.create') && vacantForm.department_id"
                                            type="button"
                                            @click="openCreateNode('node', activeDepartmentOptions.find(d => Number(d.id) === Number(vacantForm.department_id)))"
                                            class="text-[10px] font-black uppercase tracking-wider text-amber-600 hover:underline"
                                            title="Add root-level team"
                                        >
                                            + New
                                        </button>
                                        <template v-if="vacantForm.department_node_id">
                                            <button
                                                v-if="hasPermission('departments.create')"
                                                type="button"
                                                @click="openCreateNode('node', findNodeInTree(activeDepartmentOptions.find(d => Number(d.id) === Number(vacantForm.department_id))?.nodes, vacantForm.department_node_id))"
                                                class="text-[10px] font-black uppercase tracking-wider text-amber-600 hover:underline"
                                                title="Add child team to selected"
                                            >
                                                + Child
                                            </button>
                                            <button
                                                v-if="hasPermission('departments.edit')"
                                                type="button"
                                                @click="openEditNode('node', findNodeInTree(activeDepartmentOptions.find(d => Number(d.id) === Number(vacantForm.department_id))?.nodes, vacantForm.department_node_id))"
                                                class="text-[10px] font-black uppercase tracking-wider text-gray-500 hover:text-gray-700"
                                            >
                                                Edit
                                            </button>
                                            <button
                                                v-if="hasPermission('departments.delete')"
                                                type="button"
                                                @click="deleteNode('node', findNodeInTree(activeDepartmentOptions.find(d => Number(d.id) === Number(vacantForm.department_id))?.nodes, vacantForm.department_node_id))"
                                                class="text-[10px] font-black uppercase tracking-wider text-rose-500 hover:text-rose-700"
                                            >
                                                Del
                                            </button>
                                        </template>
                                    </div>
                                </div>
                                <HierarchySelector
                                    v-model="vacantForm.department_node_id"
                                    :nodes="activeDepartmentOptions.find(d => Number(d.id) === Number(vacantForm.department_id))?.nodes || []"
                                    label="Select Team Level"
                                    :disabled="!vacantForm.department_id"
                                    inline
                                />
                            </div>
                        </div>

                        <div>
                            <label class="mb-1 block text-xs font-bold uppercase tracking-wider text-gray-500">Reports To</label>
                            <MultiAutocomplete
                                v-model="vacantForm.manager_ids"
                                :options="managerOptions"
                                label-key="name"
                                value-key="id"
                                placeholder="Select managers..."
                                :limit="5"
                            />
                        </div>

                        <div class="flex justify-end gap-3 border-t pt-5">
                            <button type="button" @click="closeVacantModal" class="rounded-lg bg-gray-100 px-4 py-2 text-sm font-bold text-gray-600 transition-colors hover:bg-gray-200">Cancel</button>
                            <button type="submit" :disabled="vacantForm.processing" class="rounded-lg bg-amber-500 px-5 py-2 text-sm font-bold text-white transition-colors hover:bg-amber-600 disabled:opacity-50">
                                {{ vacantMode === 'create' ? 'Add to Chart' : 'Save Changes' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Drag ghost card -->
        <Teleport to="body">
            <!-- User drag ghost -->
            <div
                v-if="dragState.active && dragState.kind === 'user' && draggedUserData"
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
            <!-- Structure drag ghost -->
            <div
                v-else-if="dragState.active && dragState.kind === 'structure' && draggedStructureData"
                class="pointer-events-none fixed z-[9999] rounded-lg border-2 border-dashed px-5 py-2.5 shadow-2xl text-xs font-black text-center"
                :style="{
                    left: dragState.x + 'px',
                    top: dragState.y + 'px',
                    transform: 'translate(-50%, -60%) rotate(2deg)',
                    opacity: 0.92,
                    backgroundColor: draggedStructureData.type === 'section' ? '#bae6fd' : draggedStructureData.type === 'unit' ? '#e2e8f0' : '#f1f5f9',
                    borderColor: draggedStructureData.type === 'section' ? '#0ea5e9' : draggedStructureData.type === 'unit' ? '#64748b' : '#cbd5e1',
                    color: draggedStructureData.type === 'section' ? '#0369a1' : draggedStructureData.type === 'unit' ? '#334155' : '#475569',
                }"
            >
                {{ draggedStructureData.name }}
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
                                <h4 class="text-xs font-black uppercase tracking-widest text-blue-500">Root Level Teams</h4>
                                <button
                                    v-if="hasPermission('departments.create')"
                                    type="button"
                                    @click="openCreateNode('node', selectedDepartment)"
                                    class="rounded-lg bg-blue-50 px-3 py-1.5 text-xs font-black text-blue-700 hover:bg-blue-100"
                                >
                                    + Add Team
                                </button>
                            </div>

                            <div v-if="!selectedDepartment.nodes?.length" class="py-12 text-center bg-gray-50 rounded-xl border border-dashed border-gray-200">
                                <p class="text-sm font-bold text-gray-500">No teams created yet.</p>
                                <p class="text-xs text-gray-400 mt-1">Start by adding a team at the root level.</p>
                            </div>

                            <template v-else>
                                <div class="-ml-6">
                                    <StructureNode
                                        v-for="node in selectedDepartment.nodes"
                                        :key="node.id"
                                        :node="node"
                                        :hasPermission="hasPermission"
                                        :openCreateNode="openCreateNode"
                                        :openEditNode="openEditNode"
                                        :deleteNode="deleteNode"
                                        :openTeamAssignUserModal="openTeamAssignUserModal"
                                    />
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
--- End of content ---