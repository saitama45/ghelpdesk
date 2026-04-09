<script setup>
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { FunnelIcon, ChartBarIcon, CheckCircleIcon, ClockIcon, DocumentArrowDownIcon, StarIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    reportData: Array,
    users: Array,
    subUnits: Array,
    filters: Object,
});

const filterForm = ref({
    user_id:    props.filters.user_id,
    sub_unit:   props.filters.sub_unit,
    start_date: props.filters.start_date,
    end_date:   props.filters.end_date,
});

const applyFilters = () => {
    router.get(route('reports.assignee-performance'), filterForm.value, {
        preserveState: true,
        preserveScroll: true,
    });
};

const exportPDF = () => {
    const params = new URLSearchParams(filterForm.value).toString();
    window.open(route('reports.assignee-performance.pdf') + '?' + params, '_blank');
};

const getPercentageColor = (pct) => {
    if (pct >= 95) return 'text-green-600';
    if (pct >= 85) return 'text-yellow-600';
    return 'text-red-600';
};

const getProgressColor = (pct) => {
    if (pct >= 95) return 'bg-green-500';
    if (pct >= 85) return 'bg-yellow-500';
    return 'bg-red-500';
};

const getRatingColor = (avg) => {
    if (avg >= 3.5) return 'text-green-600';
    if (avg >= 2.5) return 'text-blue-600';
    if (avg >= 1.5) return 'text-yellow-600';
    return 'text-red-600';
};

const getRatingLabel = (avg) => {
    if (avg >= 3.5) return 'Excellent';
    if (avg >= 2.5) return 'Good';
    if (avg >= 1.5) return 'Fair';
    if (avg > 0)    return 'Poor';
    return 'No Surveys';
};

const getRatingEmoji = (rating) => {
    if (rating >= 4) return '🤩';
    if (rating >= 3) return '😊';
    if (rating >= 2) return '😐';
    return '😞';
};
</script>

<template>
    <Head title="Assignee Performance Report" />

    <AppLayout>
        <template #header>
            Assignee Performance Report
        </template>

        <div class="space-y-6">
            <!-- Filters Card -->
            <div class="bg-white p-4 rounded-lg shadow-sm border border-gray-200">
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
                    <div>
                        <label class="block text-xs font-bold text-gray-700 uppercase mb-1">Sub-Unit</label>
                        <select v-model="filterForm.sub_unit" class="w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                            <option value="all">All Sub-Units</option>
                            <option v-for="unit in subUnits" :key="unit" :value="unit">{{ unit }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-700 uppercase mb-1">Assignee</label>
                        <select v-model="filterForm.user_id" class="w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                            <option value="all">All Assignees</option>
                            <option v-for="user in users" :key="user.id" :value="user.id">{{ user.name }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-700 uppercase mb-1">Start Date</label>
                        <input type="date" v-model="filterForm.start_date" class="w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-700 uppercase mb-1">End Date</label>
                        <input type="date" v-model="filterForm.end_date" class="w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                    </div>
                    <div class="md:col-span-4 flex justify-end space-x-2">
                        <button @click="applyFilters" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-md text-sm font-medium flex items-center shadow-sm transition-colors">
                            <FunnelIcon class="w-4 h-4 mr-2" />
                            Generate Report
                        </button>
                        <button @click="exportPDF" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2 rounded-md text-sm font-medium flex items-center shadow-sm transition-colors border border-gray-200">
                            <DocumentArrowDownIcon class="w-4 h-4 mr-2" />
                            Export PDF
                        </button>
                    </div>
                </div>
            </div>

            <!-- Performance Cards -->
            <div v-if="reportData.length > 0" class="grid grid-cols-1 gap-6">
                <div v-for="user in reportData" :key="user.user_id" class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                    <!-- Card Header -->
                    <div class="bg-gray-50 px-6 py-4 border-b border-gray-200 flex flex-wrap justify-between items-center gap-4">
                        <div>
                            <h3 class="text-lg font-black text-gray-900 uppercase tracking-tight">{{ user.user_name }}</h3>
                            <p class="text-xs text-gray-500 font-bold uppercase">{{ user.sub_unit || 'No Sub-Unit' }}</p>
                        </div>
                        <div class="flex gap-6">
                            <div class="text-right">
                                <span class="text-2xl font-black text-blue-600">{{ user.total_tickets }}</span>
                                <p class="text-[10px] text-gray-400 font-black uppercase">Total Tickets</p>
                            </div>
                            <div class="text-right">
                                <span class="text-2xl font-black text-emerald-600">{{ user.closed_tickets }}</span>
                                <p class="text-[10px] text-gray-400 font-black uppercase">Closed/Resolved</p>
                            </div>
                        </div>
                    </div>

                    <div class="p-6 grid grid-cols-1 md:grid-cols-3 gap-8">
                        <!-- SLA Response -->
                        <div class="space-y-4">
                            <div class="flex justify-between items-end">
                                <h4 class="text-sm font-black text-gray-700 uppercase tracking-widest flex items-center">
                                    <ClockIcon class="w-4 h-4 mr-2 text-blue-500" />
                                    Target Response
                                </h4>
                                <span class="text-2xl font-black" :class="getPercentageColor(user.sla.response.percentage)">
                                    {{ user.sla.response.percentage }}%
                                </span>
                            </div>
                            <div class="w-full bg-gray-100 rounded-full h-4 overflow-hidden shadow-inner">
                                <div class="h-full transition-all duration-1000 shadow-sm"
                                    :class="getProgressColor(user.sla.response.percentage)"
                                    :style="{ width: user.sla.response.percentage + '%' }">
                                </div>
                            </div>
                            <div class="grid grid-cols-3 gap-2">
                                <div class="bg-green-50 p-2 rounded-lg border border-green-100 text-center">
                                    <p class="text-[10px] font-black text-green-600 uppercase">Met</p>
                                    <p class="text-sm font-bold text-green-700">{{ user.sla.response.met }}</p>
                                </div>
                                <div class="bg-red-50 p-2 rounded-lg border border-red-100 text-center">
                                    <p class="text-[10px] font-black text-red-600 uppercase">Breached</p>
                                    <p class="text-sm font-bold text-red-700">{{ user.sla.response.breached }}</p>
                                </div>
                                <div class="bg-blue-50 p-2 rounded-lg border border-blue-100 text-center">
                                    <p class="text-[10px] font-black text-blue-600 uppercase">Pending</p>
                                    <p class="text-sm font-bold text-blue-700">{{ user.sla.response.pending }}</p>
                                </div>
                            </div>
                        </div>

                        <!-- SLA Resolution -->
                        <div class="space-y-4">
                            <div class="flex justify-between items-end">
                                <h4 class="text-sm font-black text-gray-700 uppercase tracking-widest flex items-center">
                                    <CheckCircleIcon class="w-4 h-4 mr-2 text-purple-500" />
                                    Target Resolution
                                </h4>
                                <span class="text-2xl font-black" :class="getPercentageColor(user.sla.resolution.percentage)">
                                    {{ user.sla.resolution.percentage }}%
                                </span>
                            </div>
                            <div class="w-full bg-gray-100 rounded-full h-4 overflow-hidden shadow-inner">
                                <div class="h-full transition-all duration-1000 shadow-sm"
                                    :class="getProgressColor(user.sla.resolution.percentage)"
                                    :style="{ width: user.sla.resolution.percentage + '%' }">
                                </div>
                            </div>
                            <div class="grid grid-cols-3 gap-2">
                                <div class="bg-green-50 p-2 rounded-lg border border-green-100 text-center">
                                    <p class="text-[10px] font-black text-green-600 uppercase">Met</p>
                                    <p class="text-sm font-bold text-green-700">{{ user.sla.resolution.met }}</p>
                                </div>
                                <div class="bg-red-50 p-2 rounded-lg border border-red-100 text-center">
                                    <p class="text-[10px] font-black text-red-600 uppercase">Breached</p>
                                    <p class="text-sm font-bold text-red-700">{{ user.sla.resolution.breached }}</p>
                                </div>
                                <div class="bg-blue-50 p-2 rounded-lg border border-blue-100 text-center">
                                    <p class="text-[10px] font-black text-blue-600 uppercase">Pending</p>
                                    <p class="text-sm font-bold text-blue-700">{{ user.sla.resolution.pending }}</p>
                                </div>
                            </div>
                        </div>

                        <!-- Survey Rating -->
                        <div class="space-y-4">
                            <div class="flex justify-between items-end">
                                <h4 class="text-sm font-black text-gray-700 uppercase tracking-widest flex items-center">
                                    <StarIcon class="w-4 h-4 mr-2 text-yellow-500" />
                                    Survey Rating
                                </h4>
                                <div class="text-right" v-if="user.survey.total > 0">
                                    <span class="text-2xl font-black" :class="getRatingColor(user.survey.avg_rating)">
                                        {{ user.survey.avg_rating }}
                                    </span>
                                    <span class="text-xs text-gray-400 ml-1">/ 4</span>
                                </div>
                            </div>

                            <div v-if="user.survey.total > 0">
                                <!-- Avg rating label bar -->
                                <div class="flex items-center justify-between mb-3 p-2 rounded-lg bg-gray-50 border border-gray-100">
                                    <span class="text-xs font-black uppercase" :class="getRatingColor(user.survey.avg_rating)">
                                        {{ getRatingLabel(user.survey.avg_rating) }}
                                    </span>
                                    <span class="text-[10px] text-gray-400 font-bold">{{ user.survey.total }} survey{{ user.survey.total !== 1 ? 's' : '' }} / {{ user.total_tickets }} tickets</span>
                                </div>
                                <div class="grid grid-cols-4 gap-1.5">
                                    <div class="bg-green-50 p-2 rounded-lg border border-green-100 text-center">
                                        <p class="text-lg">🤩</p>
                                        <p class="text-[9px] font-black text-green-600 uppercase">Excellent</p>
                                        <p class="text-sm font-bold text-green-700">{{ user.survey.excellent }}</p>
                                    </div>
                                    <div class="bg-blue-50 p-2 rounded-lg border border-blue-100 text-center">
                                        <p class="text-lg">😊</p>
                                        <p class="text-[9px] font-black text-blue-600 uppercase">Good</p>
                                        <p class="text-sm font-bold text-blue-700">{{ user.survey.good }}</p>
                                    </div>
                                    <div class="bg-yellow-50 p-2 rounded-lg border border-yellow-100 text-center">
                                        <p class="text-lg">😐</p>
                                        <p class="text-[9px] font-black text-yellow-600 uppercase">Fair</p>
                                        <p class="text-sm font-bold text-yellow-700">{{ user.survey.fair }}</p>
                                    </div>
                                    <div class="bg-red-50 p-2 rounded-lg border border-red-100 text-center">
                                        <p class="text-lg">😞</p>
                                        <p class="text-[9px] font-black text-red-600 uppercase">Poor</p>
                                        <p class="text-sm font-bold text-red-700">{{ user.survey.poor }}</p>
                                    </div>
                                </div>

                                <!-- Feedbacks list -->
                                <div v-if="user.survey.feedbacks && user.survey.feedbacks.length > 0" class="mt-4 space-y-2">
                                    <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest border-b border-gray-100 pb-1">Recent Feedback</p>
                                    <div v-for="(feedback, fIdx) in user.survey.feedbacks.slice(0, 3)" :key="fIdx" class="bg-gray-50 p-2 rounded border border-gray-100 text-xs">
                                        <div class="flex justify-between items-start mb-1">
                                            <span class="font-bold">{{ getRatingEmoji(feedback.rating) }}</span>
                                            <span class="text-[9px] text-gray-400">{{ feedback.date }}</span>
                                        </div>
                                        <p class="text-gray-600 italic">"{{ feedback.text }}"</p>
                                    </div>
                                </div>
                            </div>

                            <div v-else class="flex flex-col items-center justify-center h-24 bg-gray-50 rounded-lg border border-dashed border-gray-200">
                                <p class="text-2xl mb-1">📋</p>
                                <p class="text-xs text-gray-400 font-bold uppercase">No surveys yet</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Empty State -->
            <div v-else class="bg-white p-12 rounded-lg shadow-sm border border-gray-200 text-center">
                <ChartBarIcon class="w-12 h-12 text-gray-300 mx-auto mb-4" />
                <h3 class="text-lg font-medium text-gray-900">No performance data found</h3>
                <p class="text-gray-500">Try adjusting your filters or date range.</p>
            </div>
        </div>
    </AppLayout>
</template>
