<script setup>
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import SapRequestForm from '@/Components/SapRequest/SapRequestForm.vue'
import SapRequestLayoutWrapper from '@/Components/SapRequest/SapRequestLayoutWrapper.vue'

const props = defineProps({
    companies: Array,
    requestTypes: Array,
    sapRequest: { type: Object, default: null },
})

const page = usePage()
const initialTypeId = computed(() => {
    const urlParams = new URLSearchParams(window.location.search)
    return urlParams.get('type_id') || ''
})

const isEdit = computed(() => !!props.sapRequest)
const submitRoute = computed(() =>
    isEdit.value ? route('sap-requests.update', props.sapRequest.id) : route('sap-requests.store')
)
</script>

<template>
    <AppLayout :title="isEdit ? `Edit SAP Request #${sapRequest.id}` : 'New SAP Request'">
        <SapRequestLayoutWrapper :is-edit="isEdit" :sap-request-id="sapRequest?.id">
            <SapRequestForm
                :companies="companies"
                :request-types="requestTypes"
                :sap-request="sapRequest"
                :is-public="false"
                :submit-route="submitRoute"
                :method="isEdit ? 'put' : 'post'"
                :initial-request-type-id="initialTypeId"
            />
        </SapRequestLayoutWrapper>
    </AppLayout>
</template>
