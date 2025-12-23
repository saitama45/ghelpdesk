<script setup>
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <div class="min-h-screen flex">
        <!-- Left Panel - Branding -->
        <div class="hidden lg:flex lg:w-1/2 bg-gradient-to-br from-blue-600 to-blue-800 relative overflow-hidden">
            <!-- Background Pattern -->
            <div class="absolute inset-0 opacity-10">
                <div class="absolute top-10 left-10 w-40 h-40 bg-white rounded-full"></div>
                <div class="absolute bottom-10 right-10 w-60 h-60 bg-white rounded-full"></div>
                <div class="absolute top-1/2 left-1/3 w-32 h-32 bg-white rounded-full"></div>
            </div>

            <!-- Content -->
            <div class="relative z-10 flex flex-col justify-center items-center text-white w-full h-full px-12 py-16">
                <div class="text-center max-w-md mx-auto">
                    <Link href="/" class="inline-block mb-8">
                        <ApplicationLogo class="h-32 w-32 mx-auto drop-shadow-lg" />
                    </Link>

                    <h1 class="text-4xl font-bold mb-6">Help Desk Tickets</h1>
                    <p class="text-xl text-blue-100 leading-relaxed mb-12">
                        Streamlined ticketing system for efficient issue resolution and support management.
                    </p>
                </div>

                <!-- Feature Highlights -->
                <div class="mt-12 space-y-6">
                    <div class="flex items-center space-x-3">
                        <div class="w-8 h-8 bg-white bg-opacity-20 rounded-lg flex items-center justify-center">
                            <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                        </div>
                        <span class="text-blue-100">Secure Ticket Management</span>
                    </div>
                    <div class="flex items-center space-x-3">
                        <div class="w-8 h-8 bg-white bg-opacity-20 rounded-lg flex items-center justify-center">
                            <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                            </svg>
                        </div>
                        <span class="text-blue-100">Fast Issue Resolution</span>
                    </div>
                    <div class="flex items-center space-x-3">
                        <div class="w-8 h-8 bg-white bg-opacity-20 rounded-lg flex items-center justify-center">
                            <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                            </svg>
                        </div>
                        <span class="text-blue-100">Priority-Based Workflow</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Panel - Login Form -->
        <div class="flex-1 flex items-center justify-center bg-gradient-to-br from-gray-50 to-blue-50 p-8">
            <div class="w-full max-w-md">
                <!-- Mobile Logo -->
                <div class="lg:hidden text-center mb-10">
                    <Link href="/" class="inline-block">
                        <ApplicationLogo class="h-24 w-24 mx-auto" />
                    </Link>
                    <h1 class="text-2xl font-bold text-gray-800 mt-4">Help Desk</h1>
                </div>

                <!-- Form Card -->
                <div class="bg-white rounded-2xl shadow-xl border border-gray-100 overflow-hidden backdrop-blur-lg bg-opacity-95">
                    <div class="px-8 py-10">
                        <slot />
                    </div>
                </div>

                <!-- Footer -->
                <div class="mt-8 text-center">
                    <p class="text-sm text-gray-600">
                        &copy; 2026 Help Desk Tickets. All rights reserved.
                    </p>
                </div>
            </div>
        </div>
    </div>
</template>
