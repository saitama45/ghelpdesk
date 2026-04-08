<script setup>
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import StoreHealthReport from '@/Components/StoreHealthReport.vue';

const props = defineProps({
    reportData: Array,
    summary: Object,
    users: Array,
    stores: Array,
    subUnits: Array,
    thresholds: Object,
    filters: Object
});

const handleFilter = (filterData) => {
    router.get(route('reports.store-health'), filterData, {
        preserveState: true,
        preserveScroll: true
    });
};
</script>

<template>
    <Head title="Store Health Report" />

    <AppLayout>
        <template #header>
            Store Health Report
        </template>

        <StoreHealthReport 
            :report-data="reportData"
            :summary="summary"
            :thresholds="thresholds"
            :users="users"
            :stores="stores"
            :sub-units="subUnits"
            :filters="filters"
            @filter="handleFilter"
        />
    </AppLayout>
</template>

<style>
@media print {
    body {
        background: white !important;
    }
    .print\:hidden {
        display: none !important;
    }
    .print\:space-y-0 {
        margin-top: 0 !important;
        margin-bottom: 0 !important;
    }
    .shadow-sm, .shadow-inner {
        box-shadow: none !important;
        border: 1px solid #e5e7eb !important;
    }
}
</style>
