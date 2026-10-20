<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';
import { useConfirm } from '@/Composables/useConfirm';
import { VISIBILITY } from './visibility';
import {
    DocumentDuplicateIcon,
    EyeIcon,
    MagnifyingGlassIcon,
    PencilSquareIcon,
    PlusIcon,
    TrashIcon,
} from '@heroicons/vue/24/outline';

/**
 * List page shared by Diagrams and Whiteboards: search + All/Mine (kept in the URL), cards
 * with a saved preview, New (title, visibility, template), Rename/share, Duplicate, Delete.
 * The page renders each template's thumbnail through the `template-preview` slot and can add
 * header buttons through `actions`.
 */
const props = defineProps({
    records: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    can: { type: Object, default: () => ({}) },
    routes: { type: String, required: true },
    noun: { type: String, required: true },
    title: { type: String, required: true },
    description: { type: String, default: '' },
    icon: { type: [Object, Function], required: true },
    templates: { type: Array, default: () => [] },
    buildScene: { type: Function, required: true },
    testPrefix: { type: String, required: true },
});

const { confirm } = useConfirm();

// ── Filters (kept in the URL) ────────────────────────────────────────────────

const search = ref(props.filters.search || '');
const scope = ref(props.filters.scope || 'all');

const reload = () => {
    router.get(route(`${props.routes}.index`), {
        search: search.value || undefined,
        scope: scope.value === 'mine' ? 'mine' : undefined,
    }, { preserveState: true, preserveScroll: true, replace: true });
};

let searchTimer = null;
watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(reload, 300);
});
watch(scope, reload);

const previewSrc = (svg) => (svg ? `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}` : null);

const relative = (value) => {
    if (!value) return '';
    const date = new Date(value);
    const minutes = Math.round((Date.now() - date.getTime()) / 60000);
    if (minutes < 1) return 'just now';
    if (minutes < 60) return `${minutes} min ago`;
    if (minutes < 60 * 24) return `${Math.round(minutes / 60)} h ago`;
    return date.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
};

// ── New ──────────────────────────────────────────────────────────────────────

const showCreate = ref(false);
const creating = ref(false);
const createForm = reactive({ title: '', description: '', visibility: 'department', template: 'blank' });
const createErrors = ref({});

const openCreate = () => {
    Object.assign(createForm, { title: '', description: '', visibility: 'department', template: props.templates[0]?.id || 'blank' });
    createErrors.value = {};
    showCreate.value = true;
};

const submitCreate = () => {
    if (creating.value) return;
    router.post(route(`${props.routes}.store`), {
        title: createForm.title,
        description: createForm.description || null,
        visibility: createForm.visibility,
        scene: props.buildScene(createForm.template),
    }, {
        onStart: () => { creating.value = true; },
        onFinish: () => { creating.value = false; },
        onError: (errors) => { createErrors.value = errors; },
    });
};

// ── Rename / share ───────────────────────────────────────────────────────────

const editing = ref(null);
const editForm = reactive({ title: '', description: '', visibility: 'department' });
const editErrors = ref({});

const openEdit = (record) => {
    editing.value = record;
    Object.assign(editForm, { title: record.title, description: record.description || '', visibility: record.visibility });
    editErrors.value = {};
};

const submitEdit = () => {
    const record = editing.value;
    const payload = { title: editForm.title, description: editForm.description || null };
    if (record.can_manage) payload.visibility = editForm.visibility;
    router.put(route(`${props.routes}.update`, record.id), payload, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => { editing.value = null; },
        onError: (errors) => { editErrors.value = errors; },
    });
};

// ── Duplicate / delete ───────────────────────────────────────────────────────

const duplicate = (record) => {
    router.post(route(`${props.routes}.duplicate`, record.id), {}, { preserveScroll: true, preserveState: true });
};

const remove = async (record) => {
    const ok = await confirm({
        title: `Delete ${props.noun}`,
        message: `Delete "${record.title}" with all its pages?`,
        confirmLabel: `Delete ${props.noun}`,
        variant: 'danger',
    });
    if (ok) router.delete(route(`${props.routes}.destroy`, record.id), { preserveScroll: true, preserveState: true });
};

const pageLinks = computed(() => (props.records.links || []).filter((link) => link.url !== null || link.active));

defineExpose({ openCreate });
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
            <div>
                <h1 class="flex items-center gap-2 text-2xl font-black text-gray-900 dark:text-gray-100">
                    <component :is="icon" class="h-6 w-6 text-blue-600" />
                    {{ title }}
                </h1>
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ description }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <div class="relative">
                    <MagnifyingGlassIcon class="pointer-events-none absolute left-2.5 top-2.5 h-4 w-4 text-gray-400" />
                    <input v-model="search" type="search" :placeholder="`Search ${title.toLowerCase()}…`" class="h-9 w-60 rounded-lg border-gray-300 pl-8 text-sm dark:border-gray-600 dark:bg-gray-800" :data-testid="`${testPrefix}-search`">
                </div>
                <div class="flex rounded-lg border border-gray-200 bg-white p-0.5 dark:border-gray-700 dark:bg-gray-800">
                    <button
                        v-for="option in [{ value: 'all', label: 'All I can see' }, { value: 'mine', label: 'Mine' }]"
                        :key="option.value"
                        type="button"
                        class="rounded-md px-3 py-1.5 text-xs font-bold"
                        :class="scope === option.value ? 'bg-blue-600 text-white' : 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700'"
                        @click="scope = option.value"
                    >{{ option.label }}</button>
                </div>
                <slot name="actions" />
                <button v-if="can.create" type="button" class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg bg-blue-600 px-4 py-2 text-sm font-bold text-white shadow-sm hover:bg-blue-700" :data-testid="`${testPrefix}-new`" @click="openCreate">
                    <PlusIcon class="h-4 w-4" />
                    New {{ noun }}
                </button>
            </div>
        </div>

        <div v-if="!records.data.length" class="rounded-xl border border-dashed border-gray-300 bg-white p-10 text-center dark:border-gray-700 dark:bg-gray-900">
            <component :is="icon" class="mx-auto h-10 w-10 text-gray-300" />
            <p class="mt-3 font-bold text-gray-700 dark:text-gray-200">{{ filters.search ? `No ${title.toLowerCase()} match your search.` : `No ${title.toLowerCase()} yet.` }}</p>
            <button v-if="can.create && !filters.search" type="button" class="mt-4 inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-4 py-2 text-sm font-bold text-white hover:bg-blue-700" @click="openCreate">
                <PlusIcon class="h-4 w-4" />
                New {{ noun }}
            </button>
        </div>

        <div v-else class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4">
            <article
                v-for="record in records.data"
                :key="record.id"
                class="group flex flex-col overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm transition-shadow hover:shadow-md dark:border-gray-700 dark:bg-gray-900"
                :data-testid="`${testPrefix}-card`"
            >
                <Link :href="route(`${routes}.show`, record.id)" class="block h-40 border-b border-gray-100 bg-gray-50 dark:border-gray-800 dark:bg-gray-800">
                    <img v-if="record.preview" :src="previewSrc(record.preview)" alt="" class="h-full w-full object-contain p-2" loading="lazy">
                    <div v-else class="flex h-full items-center justify-center text-gray-300"><component :is="icon" class="h-10 w-10" /></div>
                </Link>
                <div class="flex flex-1 flex-col p-3">
                    <div class="flex items-start justify-between gap-2">
                        <Link :href="route(`${routes}.show`, record.id)" class="min-w-0">
                            <h2 class="truncate font-bold text-gray-900 hover:text-blue-600 dark:text-gray-100" :title="record.title">{{ record.title }}</h2>
                        </Link>
                        <span class="inline-flex shrink-0 items-center gap-1 rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-bold text-gray-600 dark:bg-gray-800 dark:text-gray-300" :title="VISIBILITY[record.visibility]?.hint">
                            <component :is="VISIBILITY[record.visibility]?.icon" class="h-3 w-3" />
                            {{ record.visibility === 'department' && record.department ? record.department : VISIBILITY[record.visibility]?.label }}
                        </span>
                    </div>
                    <p v-if="record.description" class="mt-1 line-clamp-2 text-xs text-gray-500 dark:text-gray-400">{{ record.description }}</p>
                    <p class="mt-auto pt-2 text-[11px] text-gray-400">
                        {{ record.page_count }} {{ record.page_count === 1 ? 'page' : 'pages' }}
                        · Updated {{ relative(record.updated_at) }}<span v-if="record.editor"> by {{ record.editor }}</span>
                        <span v-if="!record.is_mine && record.creator"> · Owner {{ record.creator }}</span>
                    </p>
                    <div class="mt-2 flex justify-end space-x-1">
                        <Link :href="route(`${routes}.show`, record.id)" title="Open" class="rounded-full p-2 text-gray-600 transition-colors hover:bg-gray-100 hover:text-gray-900 dark:text-gray-300 dark:hover:bg-gray-800">
                            <EyeIcon class="h-4 w-4" />
                        </Link>
                        <button v-if="can.edit" type="button" title="Rename / share" class="rounded-full p-2 text-blue-600 transition-colors hover:bg-blue-50 hover:text-blue-900 dark:hover:bg-blue-900/30" @click="openEdit(record)">
                            <PencilSquareIcon class="h-4 w-4" />
                        </button>
                        <button v-if="can.create" type="button" title="Duplicate" class="rounded-full p-2 text-indigo-600 transition-colors hover:bg-indigo-50 hover:text-indigo-900 dark:hover:bg-indigo-900/30" @click="duplicate(record)">
                            <DocumentDuplicateIcon class="h-4 w-4" />
                        </button>
                        <button v-if="can.delete && record.can_manage" type="button" title="Delete" class="rounded-full p-2 text-red-600 transition-colors hover:bg-red-50 hover:text-red-900 dark:hover:bg-red-900/30" @click="remove(record)">
                            <TrashIcon class="h-4 w-4" />
                        </button>
                    </div>
                </div>
            </article>
        </div>

        <nav v-if="records.last_page > 1" class="flex flex-wrap items-center justify-center gap-1">
            <Link
                v-for="link in pageLinks"
                :key="link.label"
                :href="link.url || '#'"
                preserve-scroll
                class="min-w-9 rounded-lg px-3 py-1.5 text-center text-sm"
                :class="link.active ? 'bg-blue-600 font-bold text-white' : 'bg-white text-gray-600 hover:bg-gray-100 dark:bg-gray-800 dark:text-gray-300'"
            ><span v-html="link.label"></span></Link>
        </nav>

        <!-- New -->
        <Modal :show="showCreate" max-width="3xl" @close="showCreate = false">
            <form class="space-y-4 p-6" :data-testid="`${testPrefix}-create-form`" @submit.prevent="submitCreate">
                <h2 class="text-lg font-black text-gray-900 dark:text-gray-100">New {{ noun }}</h2>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block">
                        <span class="mb-1 block text-sm font-semibold text-gray-700 dark:text-gray-300">Title</span>
                        <input v-model="createForm.title" required maxlength="255" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800" :data-testid="`${testPrefix}-create-title`">
                        <span v-if="createErrors.title" class="text-xs text-red-600">{{ createErrors.title }}</span>
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-sm font-semibold text-gray-700 dark:text-gray-300">Description <span class="font-normal text-gray-400">(optional)</span></span>
                        <input v-model="createForm.description" maxlength="500" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800">
                    </label>
                </div>
                <div>
                    <span class="mb-1 block text-sm font-semibold text-gray-700 dark:text-gray-300">Who can see it</span>
                    <div class="grid gap-2 sm:grid-cols-3">
                        <button
                            v-for="(meta, value) in VISIBILITY"
                            :key="value"
                            type="button"
                            class="flex items-start gap-2 rounded-lg border p-2 text-left"
                            :class="createForm.visibility === value ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/30' : 'border-gray-200 hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800'"
                            @click="createForm.visibility = value"
                        >
                            <component :is="meta.icon" class="mt-0.5 h-4 w-4 shrink-0 text-gray-500" />
                            <span>
                                <span class="block text-sm font-semibold text-gray-800 dark:text-gray-100">{{ meta.label }}</span>
                                <span class="block text-xs text-gray-500">{{ meta.hint }}</span>
                            </span>
                        </button>
                    </div>
                </div>
                <div v-if="templates.length">
                    <span class="mb-1 block text-sm font-semibold text-gray-700 dark:text-gray-300">Start from</span>
                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                        <button
                            v-for="template in templates"
                            :key="template.id"
                            type="button"
                            class="overflow-hidden rounded-lg border text-left"
                            :class="createForm.template === template.id ? 'border-blue-500 ring-2 ring-blue-200 dark:ring-blue-900' : 'border-gray-200 hover:border-gray-300 dark:border-gray-700'"
                            :data-template="template.id"
                            @click="createForm.template = template.id"
                        >
                            <slot name="template-preview" :template="template" />
                            <span class="block border-t border-gray-100 px-2 py-1.5 dark:border-gray-700">
                                <span class="block text-xs font-bold text-gray-800 dark:text-gray-100">{{ template.label }}</span>
                                <span class="block text-[11px] text-gray-500">{{ template.description }}</span>
                            </span>
                        </button>
                    </div>
                </div>
                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-lg border border-gray-200 px-4 py-2 text-sm font-bold text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800" @click="showCreate = false">Cancel</button>
                    <button type="submit" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-bold text-white hover:bg-blue-700 disabled:opacity-50" :disabled="creating || !createForm.title.trim()" :data-testid="`${testPrefix}-create-submit`">{{ creating ? 'Creating…' : 'Create & open' }}</button>
                </div>
            </form>
        </Modal>

        <!-- Rename / share -->
        <Modal :show="!!editing" max-width="lg" @close="editing = null">
            <form v-if="editing" class="space-y-4 p-6" @submit.prevent="submitEdit">
                <h2 class="text-lg font-black text-gray-900 dark:text-gray-100">Rename / share</h2>
                <label class="block">
                    <span class="mb-1 block text-sm font-semibold text-gray-700 dark:text-gray-300">Title</span>
                    <input v-model="editForm.title" required maxlength="255" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800">
                    <span v-if="editErrors.title" class="text-xs text-red-600">{{ editErrors.title }}</span>
                </label>
                <label class="block">
                    <span class="mb-1 block text-sm font-semibold text-gray-700 dark:text-gray-300">Description</span>
                    <input v-model="editForm.description" maxlength="500" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800">
                </label>
                <div v-if="editing.can_manage">
                    <span class="mb-1 block text-sm font-semibold text-gray-700 dark:text-gray-300">Who can see it</span>
                    <div class="space-y-1">
                        <button
                            v-for="(meta, value) in VISIBILITY"
                            :key="value"
                            type="button"
                            class="flex w-full items-start gap-2 rounded-lg border p-2 text-left"
                            :class="editForm.visibility === value ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/30' : 'border-gray-200 hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800'"
                            @click="editForm.visibility = value"
                        >
                            <component :is="meta.icon" class="mt-0.5 h-4 w-4 shrink-0 text-gray-500" />
                            <span>
                                <span class="block text-sm font-semibold text-gray-800 dark:text-gray-100">{{ meta.label }}</span>
                                <span class="block text-xs text-gray-500">{{ meta.hint }}</span>
                            </span>
                        </button>
                    </div>
                </div>
                <p v-else class="text-xs text-gray-500">Only the owner can change who sees this {{ noun }}.</p>
                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-lg border border-gray-200 px-4 py-2 text-sm font-bold text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800" @click="editing = null">Cancel</button>
                    <button type="submit" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-bold text-white hover:bg-blue-700">Save</button>
                </div>
            </form>
        </Modal>
    </div>
</template>
