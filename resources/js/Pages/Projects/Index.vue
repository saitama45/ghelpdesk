<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import {
    PlusIcon,
    BuildingStorefrontIcon,
    ChevronRightIcon,
    MagnifyingGlassIcon,
    DocumentDuplicateIcon,
    TrashIcon,
    ClipboardDocumentListIcon,
} from '@heroicons/vue/24/outline';
import { ref, computed, watch } from 'vue';
import { useConfirm } from '@/Composables/useConfirm';
import { usePermission } from '@/Composables/usePermission';

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
    filters: {
        type: Object,
        default: () => ({}),
    },
});

const searchQuery = ref(props.filters?.search || '');
let searchTimeout = null;

const visibleProjects = computed(() => props.projects?.data || []);
const hasPagination = computed(() => Number(props.projects?.last_page || 1) > 1);

watch(searchQuery, (value) => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        router.get(route('projects.index'), { search: value || undefined }, {
            preserveState: true,
            replace: true,
            preserveScroll: true,
        });
    }, 300);
});

const getStatusColor = (status) => {
    if (!status) return 'bg-gray-50 text-gray-700 border-gray-100';
    switch (status.toLowerCase()) {
        case 'completed': return 'bg-emerald-50 text-emerald-700 border-emerald-100';
        case 'in progress': return 'bg-blue-50 text-blue-700 border-blue-100';
        case 'delayed': return 'bg-red-50 text-red-700 border-red-100';
        default: return 'bg-gray-50 text-gray-700 border-gray-100';
    }
};

const formatDate = (dateString) => {
    if (!dateString) return 'N/A';
    return new Date(dateString).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric'
    });
};
</script>

<template>
    <Head title="Project Tracker" />

    <AppLayout>
        <template #header>
            Project Tracker
        </template>

        <div class="space-y-6">
            <!-- Header Actions -->
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div class="relative max-w-md w-full">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <MagnifyingGlassIcon class="h-5 w-5 text-gray-400" />
                    </div>
                    <input 
                        v-model="searchQuery"
                        type="text" 
                        placeholder="Search projects or stores..." 
                        class="block w-full pl-10 pr-3 py-2 border border-gray-300 rounded-md leading-5 bg-white placeholder-gray-500 focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500 sm:text-sm"
                    >
                </div>
                <Link 
                    :href="route('projects.create')"
                    class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500"
                >
                    <PlusIcon class="-ml-1 mr-2 h-5 w-5" />
                    New Project
                </Link>
            </div>

            <!-- Projects Grid -->
            <div v-if="visibleProjects.length > 0" class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
                <div v-for="project in visibleProjects" :key="project.id" class="bg-white overflow-hidden shadow rounded-lg border border-gray-200 hover:shadow-md transition-shadow duration-200">
                    <div class="p-5">
                        <div class="flex items-center justify-between mb-4">
                            <span :class="['inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-black uppercase tracking-widest border', getStatusColor(project.status)]">
                                {{ project.status }}
                            </span>
                            <span class="text-sm text-gray-500">Go-Live: {{ formatDate(project.target_go_live) }}</span>
                        </div>
                        
                        <Link :href="route('projects.show', project.id)" class="block group">
                            <h3 class="text-lg font-bold text-gray-900 group-hover:text-blue-600 transition-colors">
                                {{ project.name }}
                            </h3>
                            <div class="mt-1 flex items-center text-sm text-gray-500">
                                <BuildingStorefrontIcon class="flex-shrink-0 mr-1.5 h-4 w-4 text-gray-400" />
                                {{ project.store?.name }}
                            </div>
                        </Link>

                        <div class="mt-6 border-t border-gray-100 pt-4 flex items-center justify-between">
                            <div class="flex flex-col">
                                <span class="text-xs text-gray-400 uppercase font-semibold">Turn-over</span>
                                <span class="text-sm font-medium text-gray-700">{{ formatDate(project.turn_over_date) }}</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <button
                                    v-if="hasPermission('projects.delete')"
                                    type="button"
                                    @click.prevent="deleteProject(project)"
                                    :disabled="deleting === project.id"
                                    class="inline-flex items-center gap-1 text-xs font-medium text-gray-400 hover:text-red-600 transition-colors disabled:opacity-50"
                                    title="Delete project"
                                >
                                    <TrashIcon class="h-4 w-4" />
                                </button>
                                <button
                                    type="button"
                                    @click.prevent="duplicateProject(project)"
                                    :disabled="duplicating === project.id"
                                    class="inline-flex items-center gap-1 text-xs font-medium text-gray-400 hover:text-indigo-600 transition-colors disabled:opacity-50"
                                    title="Duplicate project"
                                >
                                    <DocumentDuplicateIcon class="h-4 w-4" />
                                    <span>{{ duplicating === project.id ? 'Copying...' : 'Duplicate' }}</span>
                                </button>
                                <Link
                                    :href="route('projects.show', project.id)"
                                    class="inline-flex items-center text-sm font-medium text-blue-600 hover:text-blue-500"
                                >
                                    View Details
                                    <ChevronRightIcon class="ml-1 h-4 w-4" />
                                </Link>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Empty State -->
            <div v-else class="text-center py-12 bg-white rounded-lg border-2 border-dashed border-gray-300">
                <ClipboardDocumentListIcon class="mx-auto h-12 w-12 text-gray-400" />
                <h3 class="mt-2 text-sm font-medium text-gray-900">No projects found</h3>
                <p class="mt-1 text-sm text-gray-500">Get started by creating a new store opening project.</p>
                <div class="mt-6">
                    <Link
                        :href="route('projects.create')"
                        class="inline-flex items-center px-4 py-2 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700"
                    >
                        <PlusIcon class="-ml-1 mr-2 h-5 w-5" />
                        New Project
                    </Link>
                </div>
            </div>

            <div v-if="hasPagination" class="mt-6 flex flex-col gap-3 border-t border-gray-200 pt-4 sm:flex-row sm:items-center sm:justify-between">
                <div class="text-sm text-gray-600">
                    Showing {{ projects.from || 0 }} to {{ projects.to || 0 }} of {{ projects.total || 0 }} projects
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <Link
                        v-if="projects.prev_page_url"
                        :href="projects.prev_page_url"
                        preserve-scroll
                        class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700 transition-colors hover:bg-gray-50"
                    >
                        Previous
                    </Link>
                    <span
                        v-else
                        class="rounded-md border border-gray-200 bg-gray-100 px-3 py-2 text-sm font-semibold text-gray-400"
                    >
                        Previous
                    </span>

                    <span class="min-w-[110px] text-center text-sm font-semibold text-gray-700">
                        Page {{ projects.current_page || 1 }} of {{ projects.last_page || 1 }}
                    </span>

                    <Link
                        v-if="projects.next_page_url"
                        :href="projects.next_page_url"
                        preserve-scroll
                        class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700 transition-colors hover:bg-gray-50"
                    >
                        Next
                    </Link>
                    <span
                        v-else
                        class="rounded-md border border-gray-200 bg-gray-100 px-3 py-2 text-sm font-semibold text-gray-400"
                    >
                        Next
                    </span>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
