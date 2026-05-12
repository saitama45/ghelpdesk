<script setup>
import { ref, watch, h, defineComponent } from 'vue';

const props = defineProps({
    nodes: {
        type: Array,
        default: () => []
    },
    modelValue: [String, Number],
    label: String,
    emptyMessage: {
        type: String,
        default: 'No sub-teams available.'
    }
});

const emit = defineEmits(['update:modelValue']);

// Using an object for better reactivity tracking than a Set
const expandedStates = ref({});

const expandToSelected = () => {
    if (!props.modelValue || !props.nodes.length) return;

    const findAndExpandAncestors = (nodes) => {
        for (const node of nodes) {
            if (Number(node.id) === Number(props.modelValue)) {
                return true;
            }
            if (node.children && node.children.length > 0) {
                const foundInChild = findAndExpandAncestors(node.children);
                if (foundInChild) {
                    expandedStates.value[node.id] = true;
                    return true;
                }
            }
        }
        return false;
    };

    findAndExpandAncestors(props.nodes);
};

// Initial expansion
expandToSelected();

// Re-expand if nodes or modelValue change
watch(() => props.modelValue, () => {
    expandToSelected();
}, { immediate: true });

watch(() => props.nodes, () => {
    expandToSelected();
}, { deep: true });

const toggle = (id) => {
    expandedStates.value[id] = !expandedStates.value[id];
};

const select = (id) => {
    emit('update:modelValue', id);
};

// TreeItem component defined as a recursive helper
const TreeItem = defineComponent({
    name: 'TreeItem',
    props: ['node', 'selectedId', 'expandedStates', 'level'],
    emits: ['toggle', 'select'],
    setup(props, { emit }) {
        return () => {
            const hasChildren = props.node.children && props.node.children.length > 0;
            const isExpanded = !!props.expandedStates[props.node.id];
            const isSelected = Number(props.selectedId) === Number(props.node.id);
            const currentLevel = props.level || 0;

            return h('div', { class: 'flex flex-col' }, [
                h('div', {
                    class: [
                        'group flex items-center gap-2 px-2 py-1.5 rounded-lg cursor-pointer transition-all',
                        isSelected 
                            ? 'bg-blue-600 text-white shadow-md ring-1 ring-blue-700' 
                            : 'hover:bg-gray-100 text-gray-700'
                    ],
                    style: { marginLeft: `${currentLevel * 12}px` },
                    onClick: (e) => {
                        e.stopPropagation();
                        emit('select', props.node.id);
                    }
                }, [
                    // Expand/Collapse Toggle
                    h('div', {
                        class: [
                            'w-5 h-5 flex items-center justify-center rounded transition-colors',
                            isSelected ? 'text-blue-200 hover:bg-blue-500' : 'text-gray-400 hover:bg-gray-200'
                        ],
                        onClick: (e) => {
                            if (hasChildren) {
                                e.stopPropagation();
                                emit('toggle', props.node.id);
                            }
                        }
                    }, hasChildren ? [
                        h('svg', {
                            class: ['w-3 h-3 transform transition-transform duration-200', isExpanded ? 'rotate-90' : ''],
                            fill: 'none',
                            stroke: 'currentColor',
                            viewBox: '0 0 24 24'
                        }, [
                            h('path', { 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'stroke-width': '3', d: 'M9 5l7 7-7 7' })
                        ])
                    ] : [
                        h('div', { class: 'w-1 h-1 rounded-full bg-current opacity-30' })
                    ]),

                    // Icon
                    h('svg', {
                        class: ['w-4 h-4 shrink-0', isSelected ? 'text-blue-100' : 'text-gray-400'],
                        fill: 'none',
                        stroke: 'currentColor',
                        viewBox: '0 0 24 24'
                    }, [
                        h('path', { 
                            'stroke-linecap': 'round', 
                            'stroke-linejoin': 'round', 
                            'stroke-width': '2', 
                            d: hasChildren ? 'M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z' : 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z' 
                        })
                    ]),

                    // Name
                    h('span', { 
                        class: [
                            'text-xs truncate flex-1',
                            isSelected ? 'font-black' : 'font-bold'
                        ]
                    }, props.node.name),

                    // Code badge
                    props.node.code && !isSelected ? h('span', {
                        class: 'text-[9px] font-black px-1.5 py-0.5 rounded bg-gray-200 text-gray-500 font-mono'
                    }, props.node.code) : null,
                    
                    // Selected indicator
                    isSelected ? h('svg', {
                        class: 'w-4 h-4 text-blue-100',
                        fill: 'none',
                        stroke: 'currentColor',
                        viewBox: '0 0 24 24'
                    }, [
                        h('path', { 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'stroke-width': '3', d: 'M5 13l4 4L19 7' })
                    ]) : null
                ]),

                // Children
                (hasChildren && isExpanded) ? props.node.children.map(child => h(TreeItem, {
                    key: child.id,
                    node: child,
                    selectedId: props.selectedId,
                    expandedStates: props.expandedStates,
                    level: currentLevel + 1,
                    onToggle: (id) => emit('toggle', id),
                    onSelect: (id) => emit('select', id)
                })) : null
            ]);
        };
    }
});
</script>

<template>
    <div class="border border-gray-200 rounded-xl bg-white overflow-hidden shadow-sm">
        <div class="px-4 py-2.5 bg-gray-50 border-b border-gray-200 flex justify-between items-center">
            <span class="text-[10px] font-black text-gray-500 uppercase tracking-widest">{{ label || 'Select Placement' }}</span>
            <button 
                type="button" 
                @click="select('')"
                :class="[
                    'text-[10px] font-black uppercase tracking-wider transition-colors',
                    !modelValue ? 'text-gray-400 cursor-default' : 'text-blue-600 hover:text-blue-800 hover:underline'
                ]"
                :disabled="!modelValue"
            >
                Top Level
            </button>
        </div>
        <div class="max-h-72 overflow-y-auto p-2 custom-scrollbar space-y-1">
            <TreeItem 
                v-for="node in nodes" 
                :key="node.id" 
                :node="node" 
                :selected-id="modelValue"
                :expanded-states="expandedStates"
                @toggle="toggle"
                @select="select"
            />
            <div v-if="!nodes.length" class="p-8 text-center">
                <p class="text-xs text-gray-400 font-bold italic">{{ emptyMessage }}</p>
            </div>
        </div>
    </div>
</template>
