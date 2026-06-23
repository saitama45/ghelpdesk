<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import {
    PlusIcon,
    BuildingStorefrontIcon,
    MagnifyingGlassIcon,
    DocumentDuplicateIcon,
    TrashIcon,
    ClipboardDocumentListIcon,
    XMarkIcon,
    ArrowRightIcon,
    CheckCircleIcon,
    ClockIcon,
    ExclamationTriangleIcon,
    CalendarDaysIcon,
    FolderOpenIcon,
} from '@heroicons/vue/24/outline';
import { CheckCircleIcon as CheckCircleSolid } from '@heroicons/vue/24/solid';
import { ref, computed, watch } from 'vue';
import { useConfirm } from '@/Composables/useConfirm';
import { usePermission } from '@/Composables/usePermission';
import Autocomplete from '@/Components/Autocomplete.vue';

const { confirm } = useConfirm()
const { hasPermission } = usePermission()
const duplicating = ref(null)
const deleting = ref(null)

const deleteProject = async (project) => {
    const ok = await confirm({
        title: 'Delete Project',
        message: `Are you sure you want to delete "${project.name}" and all its details? This action cannot be undone.`,
        confirmLabel: 'Delete',
        cancelLabel: 'Cancel',
        variant: 'danger',
    })
    if (!ok) return
    deleting.value = project.id
    router.delete(route('projects.destroy', project.id), {
        onFinish: () => { deleting.value = null }
    })
}

const duplicateProject = async (project) => {
    const ok = await confirm({
        title: 'Duplicate Project',
        message: `Create a copy of "${project.name}" with all its tasks and assets?`,
        confirmLabel: 'Duplicate',
        cancelLabel: 'Cancel',
        variant: 'info',
    })
    if (!ok) return
    duplicating.value = project.id
    router.post(route('projects.duplicate', project.id), {}, {
        onFinish: () => { duplicating.value = null }
    })
}

const props = defineProps({
    projects: Object,
    stats: {
        type: Object,
        default: () => ({}),
    },
    typeCounts: {
        type: Object,
        default: () => ({}),
    },
    projectTypes: {
        type: Array,
        default: () => [],
    },
    filters: {
        type: Object,
        default: () => ({}),
    },
    statusOptions: {
        type: Array,
        default: () => [],
    },
    storeOptions: {
        type: Array,
        default: () => [],
    },
});

const searchQuery = ref(props.filters?.search || '');
const statusFilter = ref(props.filters?.status || null);
const storeFilter = ref(props.filters?.store_id || null);
const activeType = ref(props.filters?.type || '');
let searchTimeout = null;

const visibleProjects = computed(() => props.projects?.data || []);
const hasPagination = computed(() => Number(props.projects?.last_page || 1) > 1);

const filterParams = () => ({
    search:   searchQuery.value || undefined,
    status:   statusFilter.value || undefined,
    store_id: storeFilter.value || undefined,
    type:     activeType.value || undefined,
});

const applyFilters = () => {
    router.get(route('projects.index'), filterParams(), {
        preserveState: true,
        replace: true,
        preserveScroll: true,
    });
};

const updateFilter = (key, value) => {
    if (key === 'status') statusFilter.value = value || null;
    if (key === 'store') storeFilter.value = value || null;
    applyFilters();
};

const setType = (type) => {
    activeType.value = type;
    applyFilters();
};

const resetFilters = () => {
    searchQuery.value = '';
    statusFilter.value = null;
    storeFilter.value = null;
    clearTimeout(searchTimeout);
    applyFilters();
};

watch(searchQuery, () => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => applyFilters(), 300);
});

const getStatusColor = (status) => {
    if (!status) return 'bg-gray-50 text-gray-600 border-gray-200';
    switch (status.toLowerCase()) {
        case 'completed':  return 'bg-emerald-50 text-emerald-700 border-emerald-200';
        case 'in progress': return 'bg-blue-50 text-blue-700 border-blue-200';
        case 'delayed':    return 'bg-red-50 text-red-700 border-red-200';
        case 'planning':   return 'bg-violet-50 text-violet-700 border-violet-200';
        case 'cancelled':  return 'bg-gray-100 text-gray-500 border-gray-200';
        default:           return 'bg-gray-50 text-gray-600 border-gray-200';
    }
};

const getStatusAccent = (status) => {
    if (!status) return 'bg-gray-300';
    switch (status.toLowerCase()) {
        case 'completed':   return 'bg-emerald-500';
        case 'in progress': return 'bg-blue-500';
        case 'delayed':     return 'bg-red-500';
        case 'planning':    return 'bg-violet-500';
        case 'cancelled':   return 'bg-gray-400';
        default:            return 'bg-gray-300';
    }
};

const formatDate = (dateString) => {
    if (!dateString) return '—';
    return new Date(dateString).toLocaleDateString('en-US', {
        month: 'short', day: 'numeric', year: 'numeric'
    });
};

const projectProgress = (project) => {
    const tasks = project.tasks || [];
    if (!tasks.length) return 0;
    const total = tasks.reduce((sum, t) => sum + (t.progress || 0), 0);
    return Math.round(total / tasks.length);
};

const isGoingLiveSoon = (project) => {
    if (!project.target_go_live) return false;
    const live = new Date(project.target_go_live);
    const now = new Date();
    const diffDays = Math.ceil((live - now) / (1000 * 60 * 60 * 24));
    return diffDays >= 0 && diffDays <= 30 && project.status !== 'Completed';
};

const statCards = computed(() => [
    {
        label: 'Total Projects',
        value: props.stats?.total ?? 0,
        icon: FolderOpenIcon,
        color: 'text-slate-600',
        bg: 'bg-slate-50 dark:bg-slate-800/50',
        border: 'border-slate-200 dark:border-slate-700',
        ring: 'ring-slate-100 dark:ring-slate-700',
        dot: 'bg-slate-400',
    },
    {
        label: 'In Progress',
        value: props.stats?.in_progress ?? 0,
        icon: ClockIcon,
        color: 'text-blue-600',
        bg: 'bg-blue-50 dark:bg-blue-900/20',
        border: 'border-blue-100 dark:border-blue-800',
        ring: 'ring-blue-100 dark:ring-blue-900',
        dot: 'bg-blue-500',
    },
    {
        label: 'Delayed',
        value: props.stats?.delayed ?? 0,
        icon: ExclamationTriangleIcon,
        color: 'text-red-600',
        bg: 'bg-red-50 dark:bg-red-900/20',
        border: 'border-red-100 dark:border-red-800',
        ring: 'ring-red-100 dark:ring-red-900',
        dot: 'bg-red-500',
    },
    {
        label: 'Completed',
        value: props.stats?.completed ?? 0,
        icon: CheckCircleIcon,
        color: 'text-emerald-600',
        bg: 'bg-emerald-50 dark:bg-emerald-900/20',
        border: 'border-emerald-100 dark:border-emerald-800',
        ring: 'ring-emerald-100 dark:ring-emerald-900',
        dot: 'bg-emerald-500',
    },
    {
        label: 'Planning',
        value: props.stats?.planning ?? 0,
        icon: ClipboardDocumentListIcon,
        color: 'text-violet-600',
        bg: 'bg-violet-50 dark:bg-violet-900/20',
        border: 'border-violet-100 dark:border-violet-800',
        ring: 'ring-violet-100 dark:ring-violet-900',
        dot: 'bg-violet-500',
    },
    {
        label: 'Go-Live This Month',
        value: props.stats?.going_live_soon ?? 0,
        icon: CalendarDaysIcon,
        color: 'text-amber-600',
        bg: 'bg-amber-50 dark:bg-amber-900/20',
        border: 'border-amber-100 dark:border-amber-800',
        ring: 'ring-amber-100 dark:ring-amber-900',
        dot: 'bg-amber-500',
    },
]);

const hasActiveFilter = computed(() =>
    !!searchQuery.value || !!statusFilter.value || !!storeFilter.value
);

const typeTabConfig = computed(() => {
    const typeIconMap = {
        'Store Opening':       { dot: 'bg-blue-500',   text: 'text-blue-600' },
        'IT Deployment':       { dot: 'bg-violet-500', text: 'text-violet-600' },
        'Internal Initiative': { dot: 'bg-teal-500',   text: 'text-teal-600' },
        'Vendor Project':      { dot: 'bg-amber-500',  text: 'text-amber-600' },
        'General':             { dot: 'bg-gray-400',   text: 'text-gray-600' },
    };
    return props.projectTypes.map(t => ({
        label: t,
        count: props.typeCounts[t] ?? 0,
        ...(typeIconMap[t] ?? { dot: 'bg-gray-400', text: 'text-gray-600' }),
    }));
});
</script>

<template>
    <Head title="Project Tracker" />

    <AppLayout content-class="w-full max-w-none px-2 sm:px-4 lg:px-6">
        <template #header>
            Project Tracker
        </template>

        <div class="space-y-6">

            <!-- Analytics Summary Row -->
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                <div
                    v-for="card in statCards"
                    :key="card.label"
                    :class="['relative flex flex-col gap-2 rounded-xl border p-4 shadow-sm transition-shadow hover:shadow-md', card.bg, card.border]"
                >
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                            {{ card.label }}
                        </span>
                        <div :class="['flex h-7 w-7 items-center justify-center rounded-full', card.ring, 'ring-4']">
                            <component :is="card.icon" :class="['h-4 w-4', card.color]" />
                        </div>
                    </div>
                    <div class="flex items-end gap-2">
                        <span :class="['text-3xl font-black tabular-nums leading-none', card.color]">
                            {{ card.value }}
                        </span>
                    </div>
                    <!-- bottom accent bar -->
                    <div :class="['absolute bottom-0 left-0 h-1 w-full rounded-b-xl', card.dot]" />
                </div>
            </div>

            <!-- Project Type Tabs -->
            <div class="flex items-center gap-1 overflow-x-auto rounded-xl border border-gray-200 bg-white p-1 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <!-- All tab -->
                <button
                    type="button"
                    @click="setType('')"
                    :class="[
                        'flex shrink-0 items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-semibold transition-all',
                        activeType === ''
                            ? 'bg-gray-900 text-white shadow dark:bg-gray-100 dark:text-gray-900'
                            : 'text-gray-500 hover:bg-gray-100 hover:text-gray-800 dark:text-gray-400 dark:hover:bg-gray-700'
                    ]"
                >
                    All
                    <span :class="['rounded-full px-1.5 py-0.5 text-[10px] font-black tabular-nums', activeType === '' ? 'bg-white/20 text-white dark:bg-gray-900/20 dark:text-gray-900' : 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400']">
                        {{ stats.total ?? 0 }}
                    </span>
                </button>

                <div class="mx-1 h-5 w-px bg-gray-200 dark:bg-gray-700" />

                <!-- Per-type tabs -->
                <button
                    v-for="tab in typeTabConfig"
                    :key="tab.label"
                    type="button"
                    @click="setType(tab.label)"
                    :class="[
                        'flex shrink-0 items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-semibold transition-all',
                        activeType === tab.label
                            ? 'bg-gray-900 text-white shadow dark:bg-gray-100 dark:text-gray-900'
                            : 'text-gray-500 hover:bg-gray-100 hover:text-gray-800 dark:text-gray-400 dark:hover:bg-gray-700'
                    ]"
                >
                    <span :class="['inline-block h-2 w-2 rounded-full', tab.dot]" />
                    {{ tab.label }}
                    <span v-if="tab.count > 0" :class="['rounded-full px-1.5 py-0.5 text-[10px] font-black tabular-nums', activeType === tab.label ? 'bg-white/20 text-white dark:bg-gray-900/20 dark:text-gray-900' : 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400']">
                        {{ tab.count }}
                    </span>
                </button>
            </div>

            <!-- Filters + New Project Button -->
            <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                <div class="grid w-full grid-cols-1 gap-3 md:grid-cols-4">
                    <!-- Search -->
                    <div class="relative">
                        <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                            <MagnifyingGlassIcon class="h-4 w-4 text-gray-400" />
                        </div>
                        <input
                            v-model="searchQuery"
                            type="text"
                            placeholder="Search projects or stores…"
                            class="block w-full rounded-lg border border-gray-300 bg-white py-2 pl-9 pr-3 text-sm placeholder-gray-400 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-800 dark:placeholder-gray-400 dark:text-gray-100"
                        />
                    </div>

                    <!-- Status -->
                    <div class="relative">
                        <Autocomplete
                            v-model="statusFilter"
                            :options="statusOptions"
                            placeholder="All Statuses"
                            @update:modelValue="updateFilter('status', $event)"
                        />
                        <button
                            v-if="statusFilter"
                            type="button"
                            @click.stop="updateFilter('status', null)"
                            class="absolute right-8 top-1/2 z-10 -translate-y-1/2 rounded-full p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-gray-700"
                        >
                            <XMarkIcon class="h-3.5 w-3.5" />
                        </button>
                    </div>

                    <!-- Store -->
                    <div class="relative">
                        <Autocomplete
                            v-model="storeFilter"
                            :options="storeOptions"
                            placeholder="All Stores"
                            @update:modelValue="updateFilter('store', $event)"
                        />
                        <button
                            v-if="storeFilter"
                            type="button"
                            @click.stop="updateFilter('store', null)"
                            class="absolute right-8 top-1/2 z-10 -translate-y-1/2 rounded-full p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-gray-700"
                        >
                            <XMarkIcon class="h-3.5 w-3.5" />
                        </button>
                    </div>

                    <!-- Reset -->
                    <button
                        type="button"
                        @click="resetFilters"
                        :class="[
                            'inline-flex items-center justify-center rounded-lg border px-4 py-2 text-sm font-semibold shadow-sm transition-colors',
                            hasActiveFilter
                                ? 'border-blue-300 bg-blue-50 text-blue-700 hover:bg-blue-100 dark:border-blue-700 dark:bg-blue-900/30 dark:text-blue-300'
                                : 'border-gray-300 bg-white text-gray-600 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300'
                        ]"
                    >
                        <XMarkIcon v-if="hasActiveFilter" class="mr-1.5 h-4 w-4" />
                        Reset Filters
                    </button>
                </div>

                <Link
                    :href="route('projects.create')"
                    class="inline-flex shrink-0 items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2"
                >
                    <PlusIcon class="h-4 w-4" />
                    New Project
                </Link>
            </div>

            <!-- Results count when filtered -->
            <div v-if="hasActiveFilter" class="text-sm text-gray-500 dark:text-gray-400">
                Showing <span class="font-semibold text-gray-800 dark:text-gray-200">{{ projects.total ?? 0 }}</span> result{{ (projects.total ?? 0) !== 1 ? 's' : '' }} matching your filters
            </div>

            <!-- Projects Grid -->
            <div v-if="visibleProjects.length > 0" class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                <div
                    v-for="project in visibleProjects"
                    :key="project.id"
                    class="group relative flex flex-col overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm transition-all duration-200 hover:shadow-md hover:-translate-y-0.5 dark:border-gray-700 dark:bg-gray-800"
                >
                    <!-- Status accent strip (left side) -->
                    <div :class="['absolute left-0 top-0 h-full w-1 rounded-l-xl', getStatusAccent(project.status)]" />

                    <div class="flex flex-1 flex-col gap-3 p-4 pl-5">
                        <!-- Top row: status badge + go-live date -->
                        <div class="flex items-center justify-between gap-2">
                            <span :class="['inline-flex items-center rounded-md border px-2 py-0.5 text-[10px] font-black uppercase tracking-widest', getStatusColor(project.status)]">
                                {{ project.status }}
                            </span>
                            <span v-if="isGoingLiveSoon(project)" class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-bold text-amber-700 dark:bg-amber-900/30 dark:text-amber-400">
                                <CalendarDaysIcon class="h-3 w-3" />
                                Soon
                            </span>
                        </div>

                        <!-- Project name + store -->
                        <Link :href="route('projects.show', project.id)" class="block flex-1">
                            <h3 class="line-clamp-2 text-sm font-bold text-gray-900 transition-colors group-hover:text-blue-600 dark:text-gray-100 dark:group-hover:text-blue-400">
                                {{ project.name }}
                            </h3>
                            <div class="mt-1.5 flex items-center gap-1 text-xs text-gray-500 dark:text-gray-400">
                                <BuildingStorefrontIcon class="h-3.5 w-3.5 shrink-0 text-gray-400" />
                                <span class="truncate">{{ project.store?.name ?? '—' }}</span>
                            </div>
                        </Link>

                        <!-- Progress bar -->
                        <div>
                            <div class="mb-1 flex items-center justify-between text-xs">
                                <span class="text-gray-400 dark:text-gray-500">Progress</span>
                                <span class="font-semibold text-gray-700 dark:text-gray-300">{{ projectProgress(project) }}%</span>
                            </div>
                            <div class="h-1.5 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700">
                                <div
                                    :class="['h-full rounded-full transition-all duration-500', getStatusAccent(project.status)]"
                                    :style="{ width: projectProgress(project) + '%' }"
                                />
                            </div>
                        </div>

                        <!-- Date info -->
                        <div class="grid grid-cols-2 gap-x-3 gap-y-0.5 text-xs">
                            <div>
                                <span class="text-gray-400 dark:text-gray-500">Turn-over</span>
                                <p class="font-medium text-gray-700 dark:text-gray-300">{{ formatDate(project.turn_over_date) }}</p>
                            </div>
                            <div>
                                <span class="text-gray-400 dark:text-gray-500">Go-Live</span>
                                <p class="font-medium text-gray-700 dark:text-gray-300">{{ formatDate(project.target_go_live) }}</p>
                            </div>
                        </div>

                        <!-- Actions footer -->
                        <div class="flex items-center justify-between border-t border-gray-100 pt-3 dark:border-gray-700">
                            <div class="flex items-center gap-1">
                                <button
                                    v-if="hasPermission('projects.delete')"
                                    type="button"
                                    @click.prevent="deleteProject(project)"
                                    :disabled="deleting === project.id"
                                    class="flex h-7 w-7 items-center justify-center rounded-full text-gray-400 transition-colors hover:bg-red-50 hover:text-red-600 disabled:opacity-40 dark:hover:bg-red-900/30 dark:hover:text-red-400"
                                    title="Delete project"
                                >
                                    <TrashIcon class="h-4 w-4" />
                                </button>
                                <button
                                    type="button"
                                    @click.prevent="duplicateProject(project)"
                                    :disabled="duplicating === project.id"
                                    class="flex h-7 w-7 items-center justify-center rounded-full text-gray-400 transition-colors hover:bg-indigo-50 hover:text-indigo-600 disabled:opacity-40 dark:hover:bg-indigo-900/30 dark:hover:text-indigo-400"
                                    :title="duplicating === project.id ? 'Copying…' : 'Duplicate project'"
                                >
                                    <DocumentDuplicateIcon class="h-4 w-4" />
                                </button>
                            </div>

                            <Link
                                :href="route('projects.show', project.id)"
                                class="inline-flex items-center gap-1 rounded-md px-2.5 py-1 text-xs font-semibold text-blue-600 transition-colors hover:bg-blue-50 hover:text-blue-700 dark:text-blue-400 dark:hover:bg-blue-900/30"
                            >
                                View Details
                                <ArrowRightIcon class="h-3.5 w-3.5" />
                            </Link>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Empty State -->
            <div v-else class="flex flex-col items-center justify-center rounded-xl border-2 border-dashed border-gray-200 bg-white py-16 text-center dark:border-gray-700 dark:bg-gray-800">
                <div class="flex h-16 w-16 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-700">
                    <ClipboardDocumentListIcon class="h-8 w-8 text-gray-400" />
                </div>
                <h3 class="mt-4 text-sm font-semibold text-gray-900 dark:text-gray-100">No projects found</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    {{ hasActiveFilter ? 'Try adjusting your filters.' : 'Get started by creating a new project.' }}
                </p>
                <div class="mt-6 flex gap-3">
                    <button
                        v-if="hasActiveFilter"
                        type="button"
                        @click="resetFilters"
                        class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-300"
                    >
                        Clear Filters
                    </button>
                    <Link
                        :href="route('projects.create')"
                        class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-700"
                    >
                        <PlusIcon class="h-4 w-4" />
                        New Project
                    </Link>
                </div>
            </div>

            <!-- Pagination -->
            <div v-if="hasPagination" class="flex flex-col gap-3 border-t border-gray-200 pt-4 sm:flex-row sm:items-center sm:justify-between dark:border-gray-700">
                <div class="text-sm text-gray-500 dark:text-gray-400">
                    Showing <span class="font-semibold text-gray-800 dark:text-gray-200">{{ projects.from ?? 0 }}</span>–<span class="font-semibold text-gray-800 dark:text-gray-200">{{ projects.to ?? 0 }}</span> of <span class="font-semibold text-gray-800 dark:text-gray-200">{{ projects.total ?? 0 }}</span> projects
                </div>
                <div class="flex items-center gap-2">
                    <Link
                        v-if="projects.prev_page_url"
                        :href="projects.prev_page_url"
                        preserve-scroll
                        class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-semibold text-gray-700 transition-colors hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700"
                    >
                        Previous
                    </Link>
                    <span
                        v-else
                        class="rounded-lg border border-gray-200 bg-gray-100 px-3 py-1.5 text-sm font-semibold text-gray-400 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-500"
                    >
                        Previous
                    </span>

                    <span class="min-w-[120px] text-center text-sm font-semibold text-gray-700 dark:text-gray-300">
                        Page {{ projects.current_page ?? 1 }} of {{ projects.last_page ?? 1 }}
                    </span>

                    <Link
                        v-if="projects.next_page_url"
                        :href="projects.next_page_url"
                        preserve-scroll
                        class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-semibold text-gray-700 transition-colors hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700"
                    >
                        Next
                    </Link>
                    <span
                        v-else
                        class="rounded-lg border border-gray-200 bg-gray-100 px-3 py-1.5 text-sm font-semibold text-gray-400 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-500"
                    >
                        Next
                    </span>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
