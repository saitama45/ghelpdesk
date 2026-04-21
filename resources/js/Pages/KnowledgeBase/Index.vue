<template>
    <AppLayout title="Knowledge Base">
        <template #header>
            <div class="flex items-center space-x-2">
                <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
                </svg>
                <span>Knowledge Base</span>
            </div>
        </template>

        <div class="py-6">
            <!-- Search Hero -->
            <div class="bg-gradient-to-r from-blue-600 to-indigo-700 rounded-2xl p-8 mb-8 shadow-lg">
                <h3 class="text-2xl font-bold text-white mb-2 text-center">How can we help you?</h3>
                <p class="text-blue-100 mb-6 text-center max-w-lg mx-auto">Search our knowledge base for guides, tutorials, and common issues.</p>
                <div class="max-w-2xl mx-auto relative">
                    <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                        <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                    </div>
                    <input 
                        v-model="pagination.search.value"
                        type="text" 
                        class="block w-full pl-11 pr-4 py-4 rounded-xl border-none focus:ring-4 focus:ring-white/20 shadow-xl text-gray-900 placeholder-gray-400 font-medium" 
                        placeholder="Search for topics, categories, or keywords..."
                        @keyup.enter="handleSearch"
                    >
                </div>
            </div>

            <div class="flex flex-col lg:flex-row gap-8">
                <!-- Categories Sidebar -->
                <aside class="w-full lg:w-64 shrink-0">
                    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden sticky top-20">
                        <div class="p-4 border-b border-gray-100 bg-gray-50/50 flex items-center space-x-2">
                            <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 11h.01M7 15h.01M13 7h.01M13 11h.01M13 15h.01M17 7h.01M17 11h.01M17 15h.01" />
                            </svg>
                            <h4 class="text-xs font-black text-gray-400 uppercase tracking-widest">Categories</h4>
                        </div>
                        <nav class="p-2 space-y-1">
                            <Link 
                                :href="route('knowledge-base.portal')"
                                :class="[!filters.category ? 'bg-blue-50 text-blue-700 font-bold' : 'text-gray-600 hover:bg-gray-50', 'flex items-center justify-between px-3 py-2 rounded-lg text-sm transition-colors']"
                            >
                                <span>All Topics</span>
                                <span class="text-[10px] bg-gray-100 text-gray-500 px-1.5 py-0.5 rounded-full">{{ totalArticles }}</span>
                            </Link>
                            <Link 
                                v-for="cat in categories" 
                                :key="cat.id"
                                :href="route('knowledge-base.portal', { category: cat.slug, search: filters.search })"
                                :class="[filters.category === cat.slug ? 'bg-blue-50 text-blue-700 font-bold' : 'text-gray-600 hover:bg-gray-50', 'flex items-center justify-between px-3 py-2 rounded-lg text-sm transition-colors']"
                            >
                                <span class="truncate">{{ cat.name }}</span>
                                <span class="text-[10px] bg-gray-100 text-gray-500 px-1.5 py-0.5 rounded-full">{{ cat.articles_count }}</span>
                            </Link>
                        </nav>
                    </div>
                </aside>

                <!-- Articles Grid -->
                <div class="flex-1">
                    <div v-if="articles.data.length > 0">
                        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">
                            <Link 
                                v-for="article in articles.data" 
                                :key="article.id"
                                :href="route('knowledge-base.show', article.slug)"
                                class="group bg-white rounded-xl border border-gray-100 shadow-sm hover:shadow-md hover:border-blue-200 transition-all duration-300 flex flex-col h-full"
                            >
                                <div class="p-5 flex-1">
                                    <div class="flex items-center space-x-2 mb-3">
                                        <span class="px-2 py-0.5 rounded text-[10px] font-black uppercase tracking-wider bg-blue-50 text-blue-600 border border-blue-100">
                                            {{ article.category?.name }}
                                        </span>
                                        <span class="text-[10px] text-gray-400 font-medium">
                                            {{ formatDate(article.updated_at) }}
                                        </span>
                                    </div>
                                    <h5 class="text-lg font-bold text-gray-900 mb-2 group-hover:text-blue-600 transition-colors line-clamp-2">{{ article.title }}</h5>
                                    <p class="text-sm text-gray-500 line-clamp-3 mb-4 leading-relaxed" v-html="stripHtml(article.content)"></p>
                                </div>
                                <div class="p-5 pt-0 mt-auto border-t border-gray-50 flex items-center justify-between">
                                    <div class="flex items-center">
                                        <div class="w-6 h-6 rounded-full bg-gray-100 flex items-center justify-center text-[10px] font-bold text-gray-500 mr-2">
                                            {{ article.author?.name?.charAt(0) }}
                                        </div>
                                        <span class="text-xs text-gray-600 font-medium">{{ article.author?.name }}</span>
                                    </div>
                                    <div class="flex items-center text-gray-400 text-xs font-medium">
                                        <svg class="w-3.5 h-3.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                        </svg>
                                        {{ article.views }}
                                    </div>
                                </div>
                            </Link>
                        </div>

                        <!-- Pagination -->
                        <div class="mt-10">
                            <Pagination 
                                :current-page="pagination.currentPage.value"
                                :last-page="pagination.lastPage.value"
                                :per-page="pagination.perPage.value"
                                :showing-text="pagination.showingText.value"
                                @go-to-page="pagination.goToPage($event, route('knowledge-base.portal'), { category: filters.category })"
                                @change-per-page="pagination.changePerPage($event, route('knowledge-base.portal'), { category: filters.category })"
                            />
                        </div>
                    </div>

                    <div v-else class="text-center py-20 bg-white rounded-2xl border border-dashed border-gray-300">
                        <div class="bg-gray-50 w-20 h-20 rounded-full flex items-center justify-center mx-auto mb-4">
                            <svg class="w-10 h-10 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.172 9.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                        <h4 class="text-xl font-bold text-gray-900 mb-1">No articles found</h4>
                        <p class="text-gray-500">Try adjusting your search or filter to find what you're looking for.</p>
                        <button 
                            @click="clearFilters"
                            class="mt-6 text-sm font-bold text-blue-600 uppercase hover:text-blue-800 transition-colors"
                        >
                            Clear all filters
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Pagination from '@/Components/Pagination.vue'
import { usePagination } from '@/Composables/usePagination'

const props = defineProps({
    articles: Object,
    categories: Array,
    filters: Object
})

const pagination = usePagination(props.articles, 'knowledge-base.portal', () => ({
    category: props.filters.category
}))

const totalArticles = computed(() => props.categories.reduce((acc, cat) => acc + cat.articles_count, 0))

onMounted(() => {
    pagination.updateData(props.articles)
    pagination.search.value = props.filters.search || ''
})

watch(() => props.articles, (newArticles) => {
    pagination.updateData(newArticles)
}, { deep: true })

const handleSearch = () => {
    pagination.performSearch()
}

const clearFilters = () => {
    pagination.search.value = ''
    router.get(route('knowledge-base.portal'))
}

const formatDate = (dateString) => {
    return new Date(dateString).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric'
    })
}

const stripHtml = (html) => {
    const tmp = document.createElement("DIV");
    tmp.innerHTML = html;
    return tmp.textContent || tmp.innerText || "";
}
</script>
