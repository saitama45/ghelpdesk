<script setup>
import { computed } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'

const props = defineProps({
    title: { type: String, default: 'SAP Request' },
    isPublic: { type: Boolean, default: false },
    isEdit: { type: Boolean, default: false },
    sapRequestId: { type: [String, Number], default: null },
})

const page = usePage()
const flash = computed(() => page.props.flash ?? {})
</script>

<template>
    <div :class="isPublic ? 'bg-gradient-to-br from-teal-50 via-white to-emerald-50' : 'bg-gray-50'" class="min-h-screen py-10 px-4 sm:px-6 lg:px-8">
        <div class="max-w-4xl mx-auto">
            
            <!-- Private Header (with Back Link) -->
            <div v-if="!isPublic" class="flex items-center gap-4 mb-8">
                <Link :href="route('sap-requests.index')" class="p-2 rounded-xl text-gray-400 hover:bg-white hover:text-gray-600 hover:shadow-md transition-all">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7"/></svg>
                </Link>
                <div>
                    <h1 class="text-2xl font-black text-gray-900 tracking-tight">
                        {{ isEdit ? `Edit SAP Request #${sapRequestId}` : 'New SAP Request' }}
                    </h1>
                    <p class="text-sm text-gray-500 font-medium">Fill in the required fields for your SAP data creation request.</p>
                </div>
            </div>

            <!-- Public Header (Centered) -->
            <div v-else class="text-center mb-10">
                <div class="w-16 h-16 bg-teal-600 rounded-[1.5rem] flex items-center justify-center mx-auto mb-6 shadow-2xl shadow-teal-200">
                    <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                </div>
                <h1 class="text-4xl font-black text-gray-900 tracking-tight mb-3">SAP Request Form</h1>
                <p class="text-base text-gray-500 font-medium max-w-lg mx-auto">
                    Submit your SAP data creation request. Our team will process it within 1–3 business days.
                </p>
            </div>

            <!-- Centralized Flash Success -->
            <div v-if="flash.success" class="mb-8 px-6 py-4 bg-emerald-50 border border-emerald-200 rounded-2xl flex items-center gap-3 animate-in fade-in slide-in-from-top-2">
                <svg class="w-5 h-5 text-emerald-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                <p class="text-sm font-bold text-emerald-700">{{ flash.success }}</p>
            </div>

            <!-- Centralized SLA/Info Banner -->
            <div class="mb-8 px-6 py-4 bg-teal-50 border border-teal-100 rounded-2xl flex items-start gap-3">
                <svg class="w-5 h-5 text-teal-600 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <div>
                    <p class="text-sm font-black text-teal-800 mb-1">SLA: 1–3 Business Days</p>
                    <p class="text-xs font-medium text-teal-600">
                        Requests requiring approval will be forwarded to the relevant approver first, then to the SAP Data Officer for encoding.
                        Requests with no approval go directly to the Data Officer.
                    </p>
                </div>
            </div>

            <!-- Content Slot (The Form) -->
            <slot />

            <!-- Centralized Footer -->
            <p v-if="isPublic" class="text-center text-xs text-gray-400 font-medium mt-8">
                Having trouble with this form? Contact IT Support.
            </p>
        </div>
    </div>
</template>
