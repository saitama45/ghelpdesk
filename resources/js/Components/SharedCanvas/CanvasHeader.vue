<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import { useConfirm } from '@/Composables/useConfirm';
import { useToast } from '@/Composables/useToast';
import { VISIBILITY } from './visibility';
import { ArrowLeftIcon, DocumentDuplicateIcon, TrashIcon } from '@heroicons/vue/24/outline';

/**
 * Editor header shared by diagrams and whiteboards: back link, click-to-rename title,
 * visibility chip (owner can change it), save state, Duplicate, page actions (slot), Delete.
 */
const props = defineProps({
    recordId: { type: Number, required: true },
    routes: { type: String, required: true },
    noun: { type: String, required: true },
    title: { type: String, required: true },
    visibility: { type: String, required: true },
    creator: { type: String, default: null },
    can: { type: Object, default: () => ({}) },
    editable: { type: Boolean, default: false },
    saveState: { type: String, default: 'saved' },
    saveLabel: { type: String, default: '' },
    testPrefix: { type: String, required: true },
});

const emit = defineEmits(['update:title', 'update:visibility']);

const { confirm } = useConfirm();
const { showError } = useToast();

const renaming = ref(false);
const draft = ref('');
const input = ref(null);

const startRename = () => {
    if (!props.editable) return;
    draft.value = props.title;
    renaming.value = true;
    nextTick(() => input.value?.select());
};

const saveMeta = async (patch) => {
    try {
        const { data } = await axios.put(route(`${props.routes}.update`, props.recordId), patch);
        emit('update:title', data.title);
        emit('update:visibility', data.visibility);
    } catch (error) {
        showError(error.response?.data?.message || `Could not update the ${props.noun}.`);
    }
};

const finishRename = () => {
    if (!renaming.value) return;
    renaming.value = false;
    const next = draft.value.trim().slice(0, 255);
    if (next && next !== props.title) saveMeta({ title: next });
};

const showMenu = ref(false);
const setVisibility = (value) => {
    showMenu.value = false;
    if (value !== props.visibility) saveMeta({ visibility: value });
};

const duplicate = () => router.post(route(`${props.routes}.duplicate`, props.recordId), { open: 1 });

const remove = async () => {
    const ok = await confirm({
        title: `Delete ${props.noun}`,
        message: `Delete "${props.title}" with all its pages?`,
        confirmLabel: `Delete ${props.noun}`,
        variant: 'danger',
    });
    if (ok) router.delete(route(`${props.routes}.destroy`, props.recordId));
};

const closeMenu = (event) => {
    if (!event.target.closest?.('[data-visibility-menu]')) showMenu.value = false;
};
onMounted(() => document.addEventListener('click', closeMenu));
onBeforeUnmount(() => document.removeEventListener('click', closeMenu));
</script>

<template>
    <header class="flex shrink-0 flex-wrap items-center justify-between gap-2 border-b border-gray-200 bg-white px-3 py-2 dark:border-gray-800 dark:bg-gray-900">
        <div class="flex min-w-0 items-center gap-2">
            <Link :href="route(`${routes}.index`)" class="rounded-lg p-2 text-gray-500 hover:bg-gray-100 hover:text-gray-800 dark:hover:bg-gray-800" :title="`All ${noun}s`">
                <ArrowLeftIcon class="h-5 w-5" />
            </Link>
            <div class="min-w-0">
                <div class="flex items-center gap-2">
                    <input
                        v-if="renaming"
                        ref="input"
                        v-model="draft"
                        maxlength="255"
                        class="h-8 w-72 max-w-full rounded-md border-gray-300 text-base font-bold dark:border-gray-600 dark:bg-gray-800"
                        :data-testid="`${testPrefix}-title-input`"
                        @keydown.enter.prevent="finishRename"
                        @keydown.esc.prevent="renaming = false"
                        @blur="finishRename"
                    >
                    <h1
                        v-else
                        class="truncate text-lg font-black"
                        :class="editable ? 'cursor-text rounded px-1 hover:bg-gray-100 dark:hover:bg-gray-800' : ''"
                        :title="editable ? 'Click to rename' : title"
                        :data-testid="`${testPrefix}-title`"
                        @click="startRename"
                    >{{ title }}</h1>

                    <div class="relative" data-visibility-menu>
                        <button
                            type="button"
                            class="inline-flex items-center gap-1 rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-bold text-gray-600 dark:bg-gray-800 dark:text-gray-300"
                            :class="can.manage ? 'hover:bg-gray-200 dark:hover:bg-gray-700' : 'cursor-default'"
                            :title="VISIBILITY[visibility]?.hint"
                            :data-testid="`${testPrefix}-visibility`"
                            @click="can.manage && (showMenu = !showMenu)"
                        >
                            <component :is="VISIBILITY[visibility]?.icon" class="h-3.5 w-3.5" />
                            {{ VISIBILITY[visibility]?.label }}
                        </button>
                        <div v-if="showMenu" class="absolute left-0 top-full z-30 mt-1 w-64 rounded-lg border border-gray-200 bg-white p-1 shadow-lg dark:border-gray-700 dark:bg-gray-800">
                            <button
                                v-for="(meta, value) in VISIBILITY"
                                :key="value"
                                type="button"
                                class="flex w-full items-start gap-2 rounded-md px-2 py-1.5 text-left hover:bg-gray-50 dark:hover:bg-gray-700"
                                :class="value === visibility ? 'bg-blue-50 dark:bg-blue-900/30' : ''"
                                @click="setVisibility(value)"
                            >
                                <component :is="meta.icon" class="mt-0.5 h-4 w-4 shrink-0 text-gray-500" />
                                <span>
                                    <span class="block text-sm font-semibold">{{ meta.label }}</span>
                                    <span class="block text-xs text-gray-500 dark:text-gray-400">{{ meta.hint }}</span>
                                </span>
                            </button>
                        </div>
                    </div>
                    <span v-if="!editable" class="rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-bold uppercase tracking-wider text-gray-500 dark:bg-gray-800">View only</span>
                </div>
                <p class="flex items-center gap-1.5 truncate pl-1 text-xs text-gray-500 dark:text-gray-400" :data-testid="`${testPrefix}-save-state`">
                    <span class="inline-block h-2 w-2 shrink-0 rounded-full" :class="{
                        'bg-emerald-500': saveState === 'saved',
                        'bg-amber-400': saveState === 'pending' || saveState === 'saving',
                        'bg-red-500': saveState === 'error',
                    }"></span>
                    {{ saveLabel }}
                    <span v-if="creator" class="text-gray-400">· Owner {{ creator }}</span>
                </p>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-1">
            <button v-if="can.create" type="button" class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-semibold text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-800" @click="duplicate">
                <DocumentDuplicateIcon class="h-4 w-4" />
                Duplicate
            </button>
            <slot name="actions" />
            <button v-if="can.delete" type="button" :title="`Delete ${noun}`" :aria-label="`Delete ${noun}`" class="rounded-full p-2 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/30" @click="remove">
                <TrashIcon class="h-4 w-4" />
            </button>
        </div>
    </header>
</template>
