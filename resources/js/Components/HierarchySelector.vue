<script setup>
import { ref, watch, onMounted, computed } from 'vue';
import { ChevronRightIcon, FolderIcon, TagIcon, XMarkIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    nodes: {
        type: Array,
        default: () => []
    },
    modelValue: [String, Number],
    placeholder: {
        type: String,
        default: 'Select Team Level...'
    },
    label: String,
    inline: {
        type: Boolean,
        default: false
    },
    disabled: {
        type: Boolean,
        default: false
    }
});

const emit = defineEmits(['update:modelValue']);

const isOpen = ref(false);
const expandedStates = ref({});
const container = ref(null);

const toggleDropdown = () => {
    if (props.disabled) return;
    if (!props.inline) isOpen.value = !isOpen.value;
};

const closeDropdown = () => {
    isOpen.value = false;
};

const toggleNode = (id, event) => {
    event.stopPropagation();
    expandedStates.value[id] = !expandedStates.value[id];
};

const selectNode = (id) => {
    if (props.disabled) return;
    emit('update:modelValue', id);
    if (!props.inline) closeDropdown();
};

const clearSelection = (event) => {
    event.stopPropagation();
    if (props.disabled) return;
    emit('update:modelValue', '');
};

// Find node by ID in the tree
const findNode = (nodes, id) => {
    if (id === null || id === undefined || id === '') return null;
    for (const node of nodes) {
        if (String(node.id) === String(id)) return node;
        if (node.children?.length) {
            const found = findNode(node.children, id);
            if (found) return found;
        }
    }
    return null;
};

const selectedNode = computed(() => findNode(props.nodes, props.modelValue));

const expandToSelected = () => {
    if (!props.modelValue || !props.nodes.length) return;

    const findAndExpandAncestors = (nodes) => {
        for (const node of nodes) {
            if (String(node.id) === String(props.modelValue)) return true;
            if (node.children?.length) {
                if (findAndExpandAncestors(node.children)) {
                    expandedStates.value[node.id] = true;
                    return true;
                }
            }
        }
        return false;
    };

    findAndExpandAncestors(props.nodes);
};

onMounted(() => {
    expandToSelected();
    
    // Close dropdown on outside click
    const handleClickOutside = (event) => {
        if (container.value && !container.value.contains(event.target)) {
            closeDropdown();
        }
    };
    document.addEventListener('click', handleClickOutside);
    return () => document.removeEventListener('click', handleClickOutside);
});

watch(() => props.modelValue, () => expandToSelected());

// Recursive Tree Item Component
const TreeItem = {
    name: 'TreeItem',
    props: ['node', 'selectedId', 'expandedStates', 'level'],
    render() {
        const hasChildren = this.node.children && this.node.children.length > 0;
        const isExpanded = !!this.expandedStates[this.node.id];
        const isSelected = String(this.selectedId) === String(this.node.id);
        const level = this.level || 0;

        return h('div', { class: 'flex flex-col' }, [
            h('div', {
                class: [
                    'group flex items-center gap-2 px-3 py-2 rounded-lg cursor-pointer transition-all mx-1',
                    isSelected 
                        ? 'bg-blue-600 text-white shadow-sm' 
                        : 'hover:bg-gray-100 text-gray-700'
                ],
                style: { paddingLeft: `${(level * 16) + 12}px` },
                onClick: () => selectNode(this.node.id)
            }, [
                // Expand Icon
                h('div', {
                    class: [
                        'w-5 h-5 flex items-center justify-center rounded transition-colors',
                        isSelected ? 'text-blue-200' : 'text-gray-400 hover:bg-gray-200'
                    ],
                    onClick: (e) => hasChildren && toggleNode(this.node.id, e)
                }, hasChildren ? [
                    h(ChevronRightIcon, {
                        class: ['w-3.5 h-3.5 transform transition-transform duration-200', isExpanded ? 'rotate-90' : ''],
                    })
                ] : [
                    h('div', { class: 'w-1 h-1 rounded-full bg-current opacity-20' })
                ]),

                // Node Icon
                h(hasChildren ? FolderIcon : TagIcon, {
                    class: ['w-4 h-4 shrink-0', isSelected ? 'text-blue-100' : 'text-gray-400'],
                }),

                // Name
                h('span', { 
                    class: ['text-xs truncate flex-1', isSelected ? 'font-black' : 'font-bold']
                }, this.node.name),

                // Code
                this.node.code && !isSelected ? h('span', {
                    class: 'text-[9px] font-black px-1.5 py-0.5 rounded bg-gray-200 text-gray-500 font-mono'
                }, this.node.code) : null,
            ]),

            // Recursive Children
            (hasChildren && isExpanded) ? this.node.children.map(child => h(TreeItem, {
                node: child,
                selectedId: this.selectedId,
                expandedStates: this.expandedStates,
                level: level + 1
            })) : null
        ]);
    }
};

import { h } from 'vue';
</script>

<template>
    <div ref="container" class="relative w-full">
        <!-- Dropdown Trigger (only if not inline) -->
        <div v-if="!inline" @click="toggleDropdown" 
            class="flex items-center justify-between w-full rounded-xl border border-gray-200 bg-white px-4 py-2.5 text-xs font-bold text-gray-700 shadow-sm cursor-pointer hover:border-gray-300 transition-all dark:bg-gray-800 dark:text-gray-300 dark:border-gray-700"
            :class="{
                'ring-2 ring-blue-500 border-blue-500': isOpen,
                'opacity-60 cursor-not-allowed bg-gray-50 hover:border-gray-200': disabled
            }"
        >
            <div class="flex items-center gap-2 truncate">
                <FolderIcon v-if="selectedNode" class="w-4 h-4 text-blue-500 shrink-0" />
                <span :class="selectedNode ? 'text-gray-900' : 'text-gray-400'">
                    {{ selectedNode ? selectedNode.name : placeholder }}
                </span>
            </div>
            <div class="flex items-center gap-1">
                <XMarkIcon v-if="modelValue && !disabled" @click="clearSelection" class="w-4 h-4 text-gray-400 hover:text-gray-600 p-0.5 dark:text-gray-400" />
                <ChevronRightIcon class="w-3.5 h-3.5 text-gray-400 transition-transform dark:text-gray-400" :class="{'rotate-90': isOpen}" />
            </div>
        </div>

        <!-- The Tree Content -->
        <div v-if="isOpen || inline" 
            :class="[
                inline ? 'border border-gray-200 rounded-xl bg-white overflow-hidden shadow-sm' : 
                'absolute z-[100] mt-2 w-full bg-white rounded-xl border border-gray-200 shadow-xl overflow-hidden'
            ]"
        >
            <div v-if="label" class="px-4 py-2.5 bg-gray-50 border-b border-gray-200 flex justify-between items-center dark:bg-gray-900/50 dark:border-gray-700">
                <span class="text-[10px] font-black text-gray-500 uppercase tracking-widest dark:text-gray-300">{{ label }}</span>
                <button v-if="modelValue" type="button" @click="selectNode('')" class="text-[10px] font-black text-blue-600 hover:underline">
                    Reset
                </button>
            </div>
            
            <div class="max-h-80 overflow-y-auto p-1 custom-scrollbar">
                <TreeItem 
                    v-for="node in nodes" 
                    :key="node.id" 
                    :node="node" 
                    :selected-id="modelValue"
                    :expanded-states="expandedStates"
                />
                <div v-if="!nodes.length" class="p-8 text-center">
                    <p class="text-xs text-gray-400 font-bold italic dark:text-gray-400">No teams configured.</p>
                </div>
            </div>
        </div>
    </div>
</template>
