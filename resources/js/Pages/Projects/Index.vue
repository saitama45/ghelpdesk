<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { 
    PlusIcon, 
    CalendarIcon, 
    BuildingStorefrontIcon,
    ChevronRightIcon,
    MagnifyingGlassIcon
} from '@heroicons/vue/24/outline';
import { ref, computed } from 'vue';

const props = defineProps({
    projects: Object
});

const searchQuery = ref('');

const filteredProjects = computed(() => {
    if (!searchQuery.value) return props.projects.data;
    return props.projects.data.filter(project => 
        project.name.toLowerCase().includes(searchQuery.value.toLowerCase()) ||
        project.store?.name.toLowerCase().includes(searchQuery.value.toLowerCase())
    );
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
            <div v-if="filteredProjects.length > 0" class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
                <div v-for="project in filteredProjects" :key="project.id" class="bg-white overflow-hidden shadow rounded-lg border border-gray-200 hover:shadow-md transition-shadow duration-200">
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

            <!-- Pagination (if needed) -->
            <div v-if="projects.links.length > 3" class="mt-6">
                <!-- Standard pagination links could go here -->
            </div>
        </div>
    </AppLayout>
</template>
