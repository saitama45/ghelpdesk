<script setup>
import { computed } from 'vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import PosRequestForm from '@/Components/PosRequestForm.vue'

const props = defineProps({
    companies: Array,
    requestTypes: Array,
    stores: Array,
    priceTypes: Array,
    categories: Array,
    sub_categories: Array,
    posRequest: { type: Object, default: null },
    copyTransferPayload: { type: Object, default: null },
})

const initialTypeId = computed(() => {
    const urlParams = new URLSearchParams(window.location.search)
    return urlParams.get('type_id') || ''
})

const isEdit = computed(() => !!props.posRequest)
const submitRoute = computed(() =>
    isEdit.value ? route('pos-requests.update', props.posRequest.id) : route('pos-requests.store')
)
</script>

<template>
    <AppLayout :title="isEdit ? `Edit POS Request #${posRequest.id}` : 'New POS Request'">
        <div class="py-12 bg-gray-50/50 min-h-screen">
            <div class="max-w-[1600px] mx-auto px-4 sm:px-6 lg:px-8">
                <PosRequestForm
                    :companies="companies"
                    :request-types="requestTypes"
                    :stores="stores"
                    :price-types="priceTypes"
                    :categories="categories"
                    :sub_categories="sub_categories"
                    :pos-request="posRequest"
                    :is-public="false"
                    :submit-route="submitRoute"
                    :method="isEdit ? 'put' : 'post'"
                    :initial-request-type-id="initialTypeId"
                    :pre-fill-payload="copyTransferPayload"
                />
            </div>
        </div>
    </AppLayout>
</template>
