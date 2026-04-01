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
    posRequest: Object
})

const isEditing = computed(() => !!props.posRequest)
</script>

<template>
    <AppLayout :title="isEditing ? 'Edit POS Request' : 'Create POS Request'">
        <div class="py-12 bg-gray-50 min-h-screen font-sans">
            <div class="max-w-[1600px] mx-auto sm:px-6 lg:px-8">
                <PosRequestForm 
                    :companies="companies"
                    :requestTypes="requestTypes"
                    :stores="stores"
                    :priceTypes="priceTypes"
                    :categories="categories"
                    :sub_categories="sub_categories"
                    :posRequest="posRequest"
                    :isPublic="false"
                />
            </div>
        </div>
    </AppLayout>
</template>
