<script setup>
import { Head } from '@inertiajs/vue3';
</script>

<template>
    <Head title="Thank You" />

    <div class="min-h-screen bg-gray-50 flex flex-col items-center justify-center p-4">
        <div class="max-w-md w-full bg-white rounded-2xl shadow-xl p-12 text-center border border-gray-100">
            <div class="w-20 h-20 bg-green-100 text-green-600 rounded-full flex items-center justify-center mx-auto mb-8 text-4xl">
                ✨
            </div>
            <h1 class="text-2xl font-bold text-gray-900 mb-4">Feedback Received!</h1>
            <p class="text-gray-600 mb-8 leading-relaxed">
                Thank you for taking the time to share your experience. Your input helps us provide better support to all our customers.
            </p>
            <div class="pt-8 border-t border-gray-50">
                <p class="text-sm font-bold text-blue-600 uppercase tracking-widest">TAS Support Team</p>
            </div>
        </div>
    </div>
</template>
