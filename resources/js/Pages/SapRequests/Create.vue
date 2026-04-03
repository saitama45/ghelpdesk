<script setup>
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import SapRequestForm from '@/Components/SapRequest/SapRequestForm.vue'

const props = defineProps({
    companies: Array,
    requestTypes: Array,
    sapRequest: { type: Object, default: null },
})

const isEdit = computed(() => !!props.sapRequest)
const submitRoute = computed(() =>
    isEdit.value ? route('sap-requests.update', props.sapRequest.id) : route('sap-requests.store')
)
</script>

<template>
    <AppLayout :title="isEdit ? `Edit SAP Request #${sapRequest.id}` : 'New SAP Request'">
        <div class="py-10 bg-gray-50 min-h-screen">
            <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">

                <!-- Header -->
                <div class="flex items-center gap-4 mb-8">
                    <Link :href="route('sap-requests.index')" class="p-2 rounded-xl text-gray-400 hover:bg-white hover:text-gray-600 hover:shadow-md transition-all">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7"/></svg>
                    </Link>
                    <div>
                        <h1 class="text-2xl font-black text-gray-900 tracking-tight">
                            {{ isEdit ? `Edit SAP Request #${sapRequest.id}` : 'New SAP Request' }}
                        </h1>
                        <p class="text-sm text-gray-500 font-medium">Fill in the required fields for your SAP data creation request.</p>
                    </div>
                </div>

                <SapRequestForm
                    :companies="companies"
                    :request-types="requestTypes"
                    :sap-request="sapRequest"
                    :is-public="false"
                    :submit-route="submitRoute"
                    :method="isEdit ? 'put' : 'post'"
                />
            </div>
        </div>
    </AppLayout>
</template>
