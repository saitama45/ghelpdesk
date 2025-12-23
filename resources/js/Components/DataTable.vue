<template>
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 relative">
        <!-- Loading Overlay -->
        <div v-if="isLoading" class="absolute inset-0 bg-white bg-opacity-75 flex items-center justify-center z-10 rounded-lg">
            <div class="flex flex-col items-center space-y-3">
                <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div>
                <p class="text-sm text-gray-600">Loading data...</p>
            </div>
        </div>
        <!-- Header with Search and Actions -->
        <div class="px-6 py-4 border-b border-gray-200 bg-gray-50">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div class="flex items-center space-x-4">
                    <div>
                        <h3 class="text-lg font-semibold text-gray-900">{{ title }}</h3>
                        <p v-if="subtitle" class="text-sm text-gray-600">{{ subtitle }}</p>
                    </div>
                </div>
                <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-3">
                    <!-- Search Box -->
                    <div class="relative min-w-64">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <svg class="h-4 w-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                            </svg>
                        </div>
                        <input
                            :value="search"
                            @input="$emit('update:search', $event.target.value)"
                            type="text"
                            :placeholder="searchPlaceholder"
                            class="block w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors"
                        />
                        <div v-if="isLoading" class="absolute inset-y-0 right-0 pr-3 flex items-center">
                            <svg class="animate-spin h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                        </div>
                    </div>
                    <!-- Action Button -->
                    <slot name="actions"></slot>
                </div>
            </div>
        </div>

        <!-- Table Content -->
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <slot name="header"></slot>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <slot name="body" :data="data" :isLoading="isLoading"></slot>
                </tbody>
            </table>
            
            <!-- Empty State -->
            <div v-if="!isLoading && data.length === 0" class="text-center py-12">
                <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                </svg>
                <h3 class="mt-2 text-sm font-medium text-gray-900">No records found</h3>
                <p class="mt-1 text-sm text-gray-500">{{ emptyMessage }}</p>
            </div>
        </div>

        <!-- Pagination Footer -->
        <div class="px-6 py-4 border-t border-gray-200 bg-gray-50">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <!-- Records Info -->
                <div class="flex items-center text-sm text-gray-700">
                    <span>{{ showingText }}</span>
                </div>

                <!-- Pagination Controls -->
                <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-3">
                    <!-- Per Page Selector -->
                    <div class="flex items-center space-x-2 text-sm">
                        <span class="text-gray-700">Show</span>
                        <select
                            :value="perPage"
                            @change="changePerPage(parseInt($event.target.value))"
                            class="border border-gray-300 rounded-md px-2 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                        >
                            <option value="10">10</option>
                            <option value="25">25</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                        </select>
                        <span class="text-gray-700">per page</span>
                    </div>

                    <!-- Page Navigation -->
                    <div class="flex items-center space-x-1">
                        <!-- Previous button -->
                        <button
                            @click="goToPage(currentPage - 1)"
                            :disabled="currentPage <= 1"
                            class="px-3 py-1 text-sm border border-gray-300 rounded-md hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                        >
                            Previous
                        </button>

                        <!-- Page numbers -->
                        <template v-for="page in visiblePages" :key="page">
                            <button
                                v-if="page !== '...'"
                                @click="goToPage(page)"
                                :class="[
                                    'px-3 py-1 text-sm border rounded-md transition-colors',
                                    page === currentPage
                                        ? 'bg-blue-600 text-white border-blue-600'
                                        : 'border-gray-300 hover:bg-gray-50'
                                ]"
                            >
                                {{ page }}
                            </button>
                            <span v-else class="px-2 py-1 text-sm text-gray-500">...</span>
                        </template>

                        <!-- Next button -->
                        <button
                            @click="goToPage(currentPage + 1)"
                            :disabled="currentPage >= lastPage"
                            class="px-3 py-1 text-sm border border-gray-300 rounded-md hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                        >
                            Next
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, toRefs } from 'vue'

const props = defineProps({
    title: {
        type: String,
        required: true
    },
    subtitle: String,
    searchPlaceholder: {
        type: String,
        default: 'Search...'
    },
    emptyMessage: {
        type: String,
        default: 'Get started by creating a new record.'
    },
    // Pagination props
    search: String,
    data: Array,
    currentPage: Number,
    lastPage: Number,
    perPage: Number,
    showingText: String,
    isLoading: Boolean
})

const emit = defineEmits(['update:search', 'goToPage', 'changePerPage'])

const { search, currentPage, lastPage } = toRefs(props)

const visiblePages = computed(() => {
    const pages = []
    const current = currentPage.value
    const last = lastPage.value
    
    if (last <= 7) {
        for (let i = 1; i <= last; i++) {
            pages.push(i)
        }
    } else {
        if (current <= 4) {
            for (let i = 1; i <= 5; i++) pages.push(i)
            pages.push('...')
            pages.push(last)
        } else if (current >= last - 3) {
            pages.push(1)
            pages.push('...')
            for (let i = last - 4; i <= last; i++) pages.push(i)
        } else {
            pages.push(1)
            pages.push('...')
            for (let i = current - 1; i <= current + 1; i++) pages.push(i)
            pages.push('...')
            pages.push(last)
        }
    }
    
    return pages
})

const goToPage = (page) => {
    emit('goToPage', page)
}

const changePerPage = (newPerPage) => {
    emit('changePerPage', newPerPage)
}
</script>