<script setup>
import { ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps({
    ticket_id: String,
    ticket_key: String,
    token: String,
});

const form = useForm({
    rating: null,
    feedback: '',
});

const ratings = [
    {
        value: 4,
        label: 'Excellent',
        emoji: '🤩',
        color: 'green',
        question: 'Did the Tech Engineer exceed expectations?',
        indicators: [
            'Concern resolved accurately and promptly',
            'Clear and proactive communication',
            'Showed ownership and initiative',
            'Provided updates without follow-up',
            'Professional and helpful attitude',
            'Minimized store/business impact',
        ],
    },
    {
        value: 3,
        label: 'Good',
        emoji: '😊',
        color: 'blue',
        question: 'Did the Tech Engineer meet expectations?',
        indicators: [
            'Concern resolved properly',
            'Communication was clear',
            'Response time acceptable',
            'Followed standard process',
            'Professional handling of concern',
        ],
    },
    {
        value: 2,
        label: 'Fair',
        emoji: '😐',
        color: 'yellow',
        question: 'Were there noticeable gaps in handling the concern?',
        indicators: [
            'Delayed response or updates',
            'Concern required repeated follow-ups',
            'Partial resolution or temporary fix only',
            'Communication lacked clarity',
            'Some impact to operations experienced',
        ],
    },
    {
        value: 1,
        label: 'Poor',
        emoji: '😞',
        color: 'red',
        question: 'Was the support experience unsatisfactory?',
        indicators: [
            'Concern unresolved or incorrectly handled',
            'Very delayed response',
            'Lack of coordination or updates',
            'Unprofessional handling',
            'Significant operational disruption experienced',
        ],
    },
];

const dotClass = {
    green: 'bg-green-500',
    blue: 'bg-blue-500',
    yellow: 'bg-yellow-400',
    red: 'bg-red-500',
};

const badgeClass = {
    green: 'bg-green-50 text-green-700',
    blue: 'bg-blue-50 text-blue-700',
    yellow: 'bg-yellow-50 text-yellow-700',
    red: 'bg-red-50 text-red-700',
};

const submit = () => {
    form.post(route('public.survey.submit', props.token));
};
</script>

<template>
    <Head title="Support Survey" />

    <div class="min-h-screen bg-gray-50 flex flex-col items-center justify-center p-4">
        <div class="max-w-3xl w-full bg-white rounded-2xl shadow-xl overflow-hidden border border-gray-100">
            <!-- Header -->
            <div class="p-8 text-center border-b border-gray-50 bg-blue-50/30">
                <img src="/images/company_logo.png" alt="Company Logo" class="h-16 mx-auto mb-6 object-contain">
                <h1 class="text-xl font-bold text-gray-900 mb-2">Thank you for your time.</h1>
                <p class="text-sm text-gray-500">This survey should take under a minute to complete.</p>
            </div>

            <!-- Form -->
            <form @submit.prevent="submit" class="p-6 sm:p-8 space-y-8">
                <!-- Feedback Guide -->
                <div class="space-y-3">
                    <h2 class="text-lg font-bold text-gray-900">Feedback Guide</h2>
                    <p class="text-xs text-gray-500">Use this guide to help you choose a rating.</p>
                    <div class="overflow-x-auto rounded-lg border border-gray-200">
                        <table class="w-full text-left border-collapse">
                            <thead>
                                <tr class="bg-blue-700 text-white text-xs uppercase tracking-wider">
                                    <th class="px-4 py-3 font-semibold">Rating</th>
                                    <th class="px-4 py-3 font-semibold">Guide Questions</th>
                                    <th class="px-4 py-3 font-semibold">Expected Indicators</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="r in ratings"
                                    :key="r.value"
                                    class="border-t border-gray-200 align-top"
                                >
                                    <td class="px-4 py-4 whitespace-nowrap">
                                        <div class="flex items-center gap-2">
                                            <span :class="['w-2.5 h-2.5 rounded-full', dotClass[r.color]]"></span>
                                            <span class="font-bold text-gray-800 text-sm">
                                                {{ r.label.toUpperCase() }} ({{ r.value }})
                                            </span>
                                        </div>
                                        <div
                                            :class="[
                                                'mt-2 inline-flex flex-col items-center px-3 py-2 rounded-md',
                                                badgeClass[r.color],
                                            ]"
                                        >
                                            <span class="text-2xl leading-none">{{ r.emoji }}</span>
                                            <span class="mt-1 text-[10px] font-bold uppercase tracking-widest">
                                                {{ r.label }}
                                            </span>
                                        </div>
                                    </td>
                                    <td class="px-4 py-4 text-sm text-gray-700">{{ r.question }}</td>
                                    <td class="px-4 py-4 text-sm text-gray-700">
                                        <ul class="list-disc list-outside pl-5 space-y-1">
                                            <li v-for="(ind, idx) in r.indicators" :key="idx">{{ ind }}</li>
                                        </ul>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Question 1 -->
                <div class="space-y-4">
                    <label class="block text-base font-semibold text-gray-800 text-center">
                        1. Please Rate the Quality of Support Experienced
                    </label>
                    <div class="flex justify-between items-center px-2 max-w-md mx-auto">
                        <button
                            v-for="r in ratings"
                            :key="r.value"
                            type="button"
                            @click="form.rating = r.value"
                            :class="[
                                'flex flex-col items-center group transition-all transform active:scale-95',
                                form.rating === r.value ? 'scale-110' : 'opacity-60 grayscale hover:opacity-100 hover:grayscale-0'
                            ]"
                        >
                            <span class="text-4xl mb-2">{{ r.emoji }}</span>
                            <span :class="[
                                'text-[10px] font-bold uppercase tracking-widest',
                                form.rating === r.value ? 'text-blue-600' : 'text-gray-400'
                            ]">{{ r.label }}</span>
                        </button>
                    </div>
                    <div v-if="form.errors.rating" class="text-red-500 text-xs text-center font-medium">{{ form.errors.rating }}</div>
                </div>

                <!-- Question 2 -->
                <div class="space-y-3">
                    <label class="block text-base font-semibold text-gray-800">
                        2. Share your experience working with us
                    </label>
                    <textarea
                        v-model="form.feedback"
                        rows="4"
                        class="w-full px-4 py-3 border-2 border-gray-100 rounded-xl focus:ring-4 focus:ring-blue-500/10 focus:border-blue-500 transition-all resize-none text-gray-700 placeholder-gray-400"
                        placeholder="Your feedback helps us improve..."
                    ></textarea>
                    <div v-if="form.errors.feedback" class="text-red-500 text-xs font-medium">{{ form.errors.feedback }}</div>
                </div>

                <button
                    type="submit"
                    :disabled="form.processing || !form.rating"
                    class="w-full py-4 bg-blue-600 hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed text-white font-bold rounded-xl shadow-lg shadow-blue-500/30 transition-all active:transform active:scale-[0.98]"
                >
                    <span v-if="form.processing">Submitting...</span>
                    <span v-else>Submit Feedback</span>
                </button>
            </form>
        </div>

        <div class="mt-8 text-center">
            <p class="text-gray-400 text-xs">Reference Ticket: <span class="font-bold">{{ ticket_key }}</span></p>
        </div>
    </div>
</template>
