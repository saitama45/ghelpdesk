<script setup>
import { Head } from '@inertiajs/vue3'
import PosRequestForm from '@/Components/PosRequestForm.vue'
import PublicLayout from '@/Layouts/PublicLayout.vue'

const props = defineProps({
    companies: Array,
    requestTypes: Array,
    stores: Array,
    priceTypes: Array,
    categories: Array,
    sub_categories: Array,
})
</script>

<template>
    <PublicLayout>
        <Head title="Submit POS Request" />
        <div class="py-12 px-4 sm:px-6 lg:px-8">
            <div class="max-w-[1600px] mx-auto">
                <div class="mb-12 text-center">
                    <div class="inline-flex items-center justify-center w-20 h-20 bg-indigo-100 rounded-3xl mb-6 shadow-xl shadow-indigo-100/50">
                        <svg class="w-10 h-10 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                    </div>
                    <h1 class="text-4xl sm:text-5xl font-black text-gray-900 tracking-tight">Submit POS Request</h1>
                    <p class="text-gray-500 mt-4 text-lg font-medium">Please fill out the form below to submit your request to the IT team.</p>
                    
                    <!-- SLA Notice Card -->
                    <div class="mt-10 max-w-3xl mx-auto bg-amber-50 border-2 border-amber-100 rounded-[2rem] p-8 text-left flex items-start shadow-sm">
                        <div class="mr-6 bg-amber-100 p-3 rounded-2xl flex-shrink-0">
                            <svg class="w-6 h-6 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                        <div>
                            <h3 class="text-amber-900 font-black text-xs uppercase tracking-widest mb-2">SLA Compliance Notice</h3>
                            <p class="text-amber-800 text-sm leading-relaxed font-medium">
                                Kindly note the <span class="font-black underline decoration-2 decoration-amber-200 underline-offset-4">Service Level Agreement (SLA)</span> regarding the minimum working days required depending on the POS Request. 
                                Approvers have the right to <span class="font-bold">decline the request</span> if the needed effectivity date indicated will not meet the minimum business days as defined by the SLA.
                            </p>
                        </div>
                    </div>
                </div>
                
                <PosRequestForm 
                    :companies="companies"
                    :requestTypes="requestTypes"
                    :stores="stores"
                    :priceTypes="priceTypes"
                    :categories="categories"
                    :sub_categories="sub_categories"
                    :isPublic="true"
                />
            </div>
        </div>
    </PublicLayout>
</template>
