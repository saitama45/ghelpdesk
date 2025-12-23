<script setup>
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';

const page = usePage();
const user = computed(() => page.props.auth?.user || {});
</script>

<template>
    <Head title="Dashboard - Help Desk" />

    <AppLayout>
        <template #header>
            Dashboard
        </template>

        <!-- Welcome Section -->
        <div class="bg-white rounded-lg shadow-sm p-6 mb-6">
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-2xl font-bold text-gray-900">Welcome back, {{ user.name }}!</h2>
                    <p class="text-gray-600 mt-1">Here's what's happening with your help desk today.</p>
                </div>
                <div class="text-right">
                    <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-blue-100 text-blue-800">
                        {{ user.roles?.[0]?.name || 'User' }}
                    </span>
                </div>
            </div>
        </div>

        <!-- Stats Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
            <div class="bg-white rounded-lg shadow-sm p-6">
                <div class="flex items-center">
                    <div class="p-3 bg-blue-100 rounded-lg">
                        <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                        </svg>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-600">Open Tickets</p>
                        <p class="text-2xl font-semibold text-gray-900">24</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-sm p-6">
                <div class="flex items-center">
                    <div class="p-3 bg-green-100 rounded-lg">
                        <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-600">Resolved Today</p>
                        <p class="text-2xl font-semibold text-gray-900">12</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-sm p-6">
                <div class="flex items-center">
                    <div class="p-3 bg-yellow-100 rounded-lg">
                        <svg class="w-6 h-6 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-600">Avg Response</p>
                        <p class="text-2xl font-semibold text-gray-900">2.4h</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-sm p-6">
                <div class="flex items-center">
                    <div class="p-3 bg-purple-100 rounded-lg">
                        <svg class="w-6 h-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197m13.5-9a2.5 2.5 0 11-5 0 2.5 2.5 0 015 0z"></path>
                        </svg>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-600">Active Users</p>
                        <p class="text-2xl font-semibold text-gray-900">156</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Activity -->
        <div class="bg-white rounded-lg shadow-sm">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">Recent Activity</h3>
            </div>
            <div class="p-6">
                <div class="space-y-4">
                    <div class="flex items-start space-x-3">
                        <div class="flex-shrink-0">
                            <div class="w-2 h-2 bg-blue-600 rounded-full mt-2"></div>
                        </div>
                        <div class="flex-1">
                            <p class="text-sm text-gray-900">New ticket #1234 created by John Doe</p>
                            <p class="text-xs text-gray-500">2 minutes ago</p>
                        </div>
                    </div>
                    <div class="flex items-start space-x-3">
                        <div class="flex-shrink-0">
                            <div class="w-2 h-2 bg-green-600 rounded-full mt-2"></div>
                        </div>
                        <div class="flex-1">
                            <p class="text-sm text-gray-900">Ticket #1230 resolved by Support Agent</p>
                            <p class="text-xs text-gray-500">15 minutes ago</p>
                        </div>
                    </div>
                    <div class="flex items-start space-x-3">
                        <div class="flex-shrink-0">
                            <div class="w-2 h-2 bg-yellow-600 rounded-full mt-2"></div>
                        </div>
                        <div class="flex-1">
                            <p class="text-sm text-gray-900">Ticket #1228 assigned to Jane Smith</p>
                            <p class="text-xs text-gray-500">1 hour ago</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>