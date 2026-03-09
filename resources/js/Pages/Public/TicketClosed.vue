<script setup>
import { Head } from '@inertiajs/vue3';

const props = defineProps({
    ticket: Object,
});
</script>

<template>
    <Head title="Ticket Closed" />

    <div class="min-h-screen bg-gray-50 flex flex-col items-center justify-center p-4">
        <div class="max-w-md w-full bg-white rounded-2xl shadow-xl p-12 text-center border border-gray-100">
            <div class="w-20 h-20 bg-blue-100 text-blue-600 rounded-full flex items-center justify-center mx-auto mb-8 text-4xl">
                🔒
            </div>
            <h1 class="text-2xl font-bold text-gray-900 mb-4">Ticket Closed</h1>
            <p class="text-gray-600 mb-8 leading-relaxed">
                Ticket <span class="font-bold text-blue-600">{{ ticket.ticket_key }}</span> has been successfully closed.
            </p>
            <p class="text-sm text-gray-500 mb-8">
                A separate email has been sent to you with a link to our customer satisfaction survey. We'd love to hear your feedback!
            </p>
            <div class="pt-8 border-t border-gray-50">
                <p class="text-sm font-bold text-blue-600 uppercase tracking-widest">TAS Support Team</p>
            </div>
        </div>
    </div>
</template>
