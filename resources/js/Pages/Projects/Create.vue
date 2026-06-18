<script setup>
import { Head, useForm, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { ChevronLeftIcon } from '@heroicons/vue/24/outline';
import Autocomplete from '@/Components/Autocomplete.vue';

const props = defineProps({
    stores: Array,
    boardYears: Array,
});

const now = new Date();

const sortedStores = computed(() => {
    return [...(props.stores ?? [])].sort((a, b) => {
        return String(a.name ?? '').localeCompare(String(b.name ?? ''), undefined, { sensitivity: 'base' });
    });
});

const form = useForm({
    store_id: '',
    name: '',
    status: 'Planning',
    turn_over_date: '',
    training_date: '',
    testing_date: '',
    mock_service_date: '',
    turn_over_to_franchisee_date: '',
    target_go_live: '',
    board_month: now.getMonth() + 1,
    board_year: now.getFullYear(),
    remarks: ''
});

const monthOptions = [
    { value: 1, label: 'January' },
    { value: 2, label: 'February' },
    { value: 3, label: 'March' },
    { value: 4, label: 'April' },
    { value: 5, label: 'May' },
    { value: 6, label: 'June' },
    { value: 7, label: 'July' },
    { value: 8, label: 'August' },
    { value: 9, label: 'September' },
    { value: 10, label: 'October' },
    { value: 11, label: 'November' },
    { value: 12, label: 'December' },
];

const submit = () => {
    form.post(route('projects.store'));
};
</script>

<template>
    <Head title="Create Project" />

    <AppLayout>
        <template #header>
            <div class="flex items-center">
                <Link :href="route('projects.index')" class="mr-4 text-gray-400 hover:text-gray-600 dark:text-gray-400">
                    <ChevronLeftIcon class="h-6 w-6" />
                </Link>
                Create New Project
            </div>
        </template>

        <div class="max-w-4xl mx-auto">
            <form @submit.prevent="submit" class="bg-white shadow-sm rounded-lg border border-gray-200 overflow-hidden dark:bg-gray-800 dark:border-gray-700">
                <div class="p-6 space-y-6">
                    <!-- Basic Info Section -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="col-span-1 md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-1 dark:text-gray-300">Project Name (Store Code/Name)</label>
                            <input 
                                v-model="form.name"
                                type="text" 
                                required
                                placeholder="e.g. NN NAIA Terminal 1 (NT1)"
                                class="w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 dark:border-gray-600"
                            >
                            <div v-if="form.errors.name" class="text-red-500 text-xs mt-1">{{ form.errors.name }}</div>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1 dark:text-gray-300">Store Branch</label>
                            <Autocomplete
                                v-model="form.store_id"
                                :options="sortedStores"
                                label-key="name"
                                value-key="id"
                                placeholder="Select a store"
                            />
                            <div v-if="form.errors.store_id" class="text-red-500 text-xs mt-1">{{ form.errors.store_id }}</div>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1 dark:text-gray-300">Initial Status</label>
                            <select 
                                v-model="form.status"
                                class="w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 dark:border-gray-600"
                            >
                                <option value="Planning">Planning</option>
                                <option value="In Progress">In Progress</option>
                                <option value="Delayed">Delayed</option>
                            </select>
                            <div v-if="form.errors.status" class="text-red-500 text-xs mt-1">{{ form.errors.status }}</div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1 dark:text-gray-300">Task Board Month</label>
                            <select
                                v-model.number="form.board_month"
                                class="w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 dark:border-gray-600"
                            >
                                <option v-for="month in monthOptions" :key="month.value" :value="month.value">{{ month.label }}</option>
                            </select>
                            <div v-if="form.errors.board_month" class="text-red-500 text-xs mt-1">{{ form.errors.board_month }}</div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1 dark:text-gray-300">Task Board Year</label>
                            <select
                                v-model.number="form.board_year"
                                class="w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 dark:border-gray-600"
                            >
                                <option v-for="year in boardYears" :key="year" :value="year">{{ year }}</option>
                            </select>
                            <div v-if="form.errors.board_year" class="text-red-500 text-xs mt-1">{{ form.errors.board_year }}</div>
                        </div>
                    </div>

                    <hr class="border-gray-100 dark:border-gray-700">

                    <!-- Important Dates Section -->
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Important Dates</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1 dark:text-gray-300">Target Go-Live</label>
                            <input 
                                v-model="form.target_go_live"
                                type="date" 
                                class="w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 dark:border-gray-600"
                            >
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1 dark:text-gray-300">Store Turn-over (to TAS)</label>
                            <input 
                                v-model="form.turn_over_date"
                                type="date" 
                                class="w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 dark:border-gray-600"
                            >
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1 dark:text-gray-300">Training Dates</label>
                            <input 
                                v-model="form.training_date"
                                type="date" 
                                class="w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 dark:border-gray-600"
                            >
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1 dark:text-gray-300">Testing Date</label>
                            <input 
                                v-model="form.testing_date"
                                type="date" 
                                class="w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 dark:border-gray-600"
                            >
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1 dark:text-gray-300">Mock Service Date</label>
                            <input 
                                v-model="form.mock_service_date"
                                type="date" 
                                class="w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 dark:border-gray-600"
                            >
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1 dark:text-gray-300">Turn-over to Franchisee</label>
                            <input 
                                v-model="form.turn_over_to_franchisee_date"
                                type="date" 
                                class="w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 dark:border-gray-600"
                            >
                        </div>
                    </div>

                    <div class="col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1 dark:text-gray-300">Remarks</label>
                        <textarea 
                            v-model="form.remarks"
                            rows="3"
                            class="w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 dark:border-gray-600"
                        ></textarea>
                    </div>
                </div>

                <div class="px-6 py-4 bg-gray-50 text-right dark:bg-gray-900/50">
                    <Link 
                        :href="route('projects.index')"
                        class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 mr-3 dark:bg-gray-800 dark:text-gray-300 dark:border-gray-600 dark:hover:bg-gray-700"
                    >
                        Cancel
                    </Link>
                    <button 
                        type="submit"
                        :disabled="form.processing"
                        class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 disabled:opacity-50"
                    >
                        {{ form.processing ? 'Saving...' : 'Create Project' }}
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
