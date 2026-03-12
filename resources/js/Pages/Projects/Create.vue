<script setup>
import { Head, useForm, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { ChevronLeftIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    stores: Array
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
    remarks: ''
});

const submit = () => {
    form.post(route('projects.store'));
};
</script>

<template>
    <Head title="Create Project" />

    <AppLayout>
        <template #header>
            <div class="flex items-center">
                <Link :href="route('projects.index')" class="mr-4 text-gray-400 hover:text-gray-600">
                    <ChevronLeftIcon class="h-6 w-6" />
                </Link>
                Create New Project
            </div>
        </template>

        <div class="max-w-4xl mx-auto">
            <form @submit.prevent="submit" class="bg-white shadow-sm rounded-lg border border-gray-200 overflow-hidden">
                <div class="p-6 space-y-6">
                    <!-- Basic Info Section -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="col-span-1 md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Project Name (Store Code/Name)</label>
                            <input 
                                v-model="form.name"
                                type="text" 
                                required
                                placeholder="e.g. NN NAIA Terminal 1 (NT1)"
                                class="w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                            >
                            <div v-if="form.errors.name" class="text-red-500 text-xs mt-1">{{ form.errors.name }}</div>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Store Branch</label>
                            <select 
                                v-model="form.store_id"
                                required
                                class="w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                            >
                                <option value="" disabled>Select a store</option>
                                <option v-for="store in stores" :key="store.id" :value="store.id">
                                    {{ store.name }}
                                </option>
                            </select>
                            <div v-if="form.errors.store_id" class="text-red-500 text-xs mt-1">{{ form.errors.store_id }}</div>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Initial Status</label>
                            <select 
                                v-model="form.status"
                                class="w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                            >
                                <option value="Planning">Planning</option>
                                <option value="In Progress">In Progress</option>
                                <option value="Delayed">Delayed</option>
                            </select>
                            <div v-if="form.errors.status" class="text-red-500 text-xs mt-1">{{ form.errors.status }}</div>
                        </div>
                    </div>

                    <hr class="border-gray-100">

                    <!-- Important Dates Section -->
                    <h3 class="text-lg font-semibold text-gray-900">Important Dates</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Target Go-Live</label>
                            <input 
                                v-model="form.target_go_live"
                                type="date" 
                                class="w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                            >
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Store Turn-over (to TAS)</label>
                            <input 
                                v-model="form.turn_over_date"
                                type="date" 
                                class="w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                            >
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Training Dates</label>
                            <input 
                                v-model="form.training_date"
                                type="date" 
                                class="w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                            >
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Testing Date</label>
                            <input 
                                v-model="form.testing_date"
                                type="date" 
                                class="w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                            >
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Mock Service Date</label>
                            <input 
                                v-model="form.mock_service_date"
                                type="date" 
                                class="w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                            >
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Turn-over to Franchisee</label>
                            <input 
                                v-model="form.turn_over_to_franchisee_date"
                                type="date" 
                                class="w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                            >
                        </div>
                    </div>

                    <div class="col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Remarks</label>
                        <textarea 
                            v-model="form.remarks"
                            rows="3"
                            class="w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                        ></textarea>
                    </div>
                </div>

                <div class="px-6 py-4 bg-gray-50 text-right">
                    <Link 
                        :href="route('projects.index')"
                        class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 mr-3"
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
