<script setup>
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import PublicLayout from '@/Layouts/PublicLayout.vue'
import SapRequestForm from '@/Components/SapRequest/SapRequestForm.vue'
import SapRequestLayoutWrapper from '@/Components/SapRequest/SapRequestLayoutWrapper.vue'

const props = defineProps({
    companies: Array,
    requestTypes: Array,
})

const page = usePage()

const initialTypeId = computed(() => {
    const urlParams = new URLSearchParams(window.location.search)
    return urlParams.get('type_id') || ''
})
</script>

<template>
    <PublicLayout title="SAP Request Submission">
        <SapRequestLayoutWrapper :is-public="true">
            <SapRequestForm
                :companies="companies"
                :request-types="requestTypes"
                :is-public="true"
                :submit-route="route('public.sap-requests.store')"
                method="post"
                :initial-request-type-id="initialTypeId"
            />
        </SapRequestLayoutWrapper>
    </PublicLayout>
</template>
