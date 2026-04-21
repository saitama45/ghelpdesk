<template>
    <AppLayout :title="article.title">
        <template #header>
            <div class="flex items-center space-x-2 text-sm text-gray-500">
                <Link :href="route('knowledge-base.portal')" class="hover:text-blue-600 transition-colors">Knowledge Base</Link>
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                </svg>
                <span class="truncate max-w-[200px]">{{ article.title }}</span>
            </div>
        </template>

        <div class="py-6">
            <div class="flex flex-col lg:flex-row gap-8">
                <!-- Main Content -->
                <div class="flex-1">
                    <article class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                        <div class="p-6 md:p-10">
                            <div class="mb-8">
                                <div class="flex items-center space-x-3 mb-4">
                                    <span class="px-2.5 py-1 rounded text-xs font-black uppercase tracking-widest bg-blue-50 text-blue-600 border border-blue-100">
                                        {{ article.category?.name }}
                                    </span>
                                    <span class="text-xs text-gray-400 font-medium flex items-center">
                                        <svg class="w-3.5 h-3.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                        </svg>
                                        Last updated {{ formatDate(article.updated_at) }}
                                    </span>
                                </div>
                                <h1 class="text-3xl md:text-4xl font-black text-gray-900 leading-tight">{{ article.title }}</h1>
                            </div>

                            <div class="prose prose-blue max-w-none text-gray-700 leading-relaxed quill-content" v-html="article.content"></div>

                            <div class="mt-12 pt-8 border-t border-gray-100 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                                <div class="flex items-center">
                                    <div class="w-10 h-10 rounded-full bg-blue-600 flex items-center justify-center text-white font-bold mr-3 shadow-md shadow-blue-200">
                                        {{ article.author?.name?.charAt(0) }}
                                    </div>
                                    <div>
                                        <p class="text-sm font-bold text-gray-900">{{ article.author?.name }}</p>
                                        <p class="text-xs text-gray-500">Author</p>
                                    </div>
                                </div>
                                <div class="flex items-center space-x-2">
                                    <span class="text-xs text-gray-400 font-medium">{{ feedbackSubmitted ? 'Feedback sent!' : 'Was this helpful?' }}</span>
                                    <div v-if="!feedbackSubmitted" class="flex space-x-1">
                                        <button 
                                            @click="submitFeedback(true)"
                                            class="p-1.5 rounded-lg border border-gray-200 hover:bg-green-50 hover:text-green-600 hover:border-green-200 transition-all text-gray-400"
                                            title="Yes, it was helpful"
                                        >
                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 10h4.708a2 2 0 011.983 2.284l-1.347 8.977a2 2 0 01-1.983 1.739H7a2 2 0 01-2-2v-8a2 2 0 01.553-1.382l5.947-5.947a1.5 1.5 0 013.111 1.132L14 10z" />
                                            </svg>
                                        </button>
                                        <button 
                                            @click="submitFeedback(false)"
                                            class="p-1.5 rounded-lg border border-gray-200 hover:bg-red-50 hover:text-red-600 hover:border-red-200 transition-all text-gray-400"
                                            title="No, it was not helpful"
                                        >
                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14H5.292a2 2 0 01-1.983-2.284l1.347-8.977a2 2 0 011.983-1.739H17a2 2 0 012 2v8a2 2 0 01-.553 1.382l-5.947 5.947a1.5 1.5 0 01-3.111-1.132L10 14z" />
                                            </svg>
                                        </button>
                                    </div>
                                    <div v-else class="flex items-center text-green-600">
                                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                                        </svg>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </article>
                </div>

                <!-- Sidebar (Related Articles) -->
                <aside class="w-full lg:w-80 shrink-0">
                    <div class="space-y-6 sticky top-20">
                        <!-- Related Articles -->
                        <div v-if="relatedArticles.length > 0" class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                            <div class="p-4 border-b border-gray-100 bg-gray-50/50">
                                <h4 class="text-xs font-black text-gray-400 uppercase tracking-widest">Related Articles</h4>
                            </div>
                            <div class="p-2">
                                <Link 
                                    v-for="rel in relatedArticles" 
                                    :key="rel.id"
                                    :href="route('knowledge-base.show', rel.slug)"
                                    class="block p-3 rounded-xl hover:bg-blue-50 transition-colors group"
                                >
                                    <h5 class="text-sm font-bold text-gray-800 group-hover:text-blue-600 transition-colors line-clamp-2 mb-1">{{ rel.title }}</h5>
                                    <div class="flex items-center text-[10px] text-gray-400 font-medium">
                                        <span>{{ rel.views }} views</span>
                                        <span class="mx-1.5 text-gray-300">•</span>
                                        <span>{{ formatDate(rel.updated_at) }}</span>
                                    </div>
                                </Link>
                            </div>
                        </div>

                        <!-- Back to Portal -->
                        <Link 
                            :href="route('knowledge-base.portal')"
                            class="flex items-center justify-center space-x-2 w-full py-4 bg-gray-900 text-white rounded-2xl font-bold text-sm hover:bg-gray-800 transition-all shadow-lg shadow-gray-200"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                            </svg>
                            <span>Back to Knowledge Base</span>
                        </Link>
                    </div>
                </aside>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
    article: Object,
    relatedArticles: Array
})

const feedbackSubmitted = ref(false)

const submitFeedback = (wasHelpful) => {
    router.post(route('knowledge-base.feedback', props.article.id), {
        was_helpful: wasHelpful
    }, {
        preserveScroll: true,
        onSuccess: () => {
            feedbackSubmitted.value = true
        }
    })
}

const formatDate = (dateString) => {
    return new Date(dateString).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric'
    })
}
</script>

<style>
/* Style the Quill content in the show page */
.quill-content img {
    max-width: 100%;
    height: auto;
    border-radius: 0.5rem;
    margin: 1.5rem 0;
}
.quill-content h1, .quill-content h2, .quill-content h3 {
    font-weight: 800;
    color: #111827;
    margin-top: 2rem;
    margin-bottom: 1rem;
}
.quill-content h1 { font-size: 1.875rem; }
.quill-content h2 { font-size: 1.5rem; }
.quill-content h3 { font-size: 1.25rem; }
.quill-content p {
    margin-bottom: 1.25rem;
}
.quill-content ul, .quill-content ol {
    margin-bottom: 1.25rem;
    padding-left: 1.5rem;
}
.quill-content ul { list-style-type: disc; }
.quill-content ol { list-style-type: decimal; }
.quill-content blockquote {
    border-left: 4px solid #3b82f6;
    background-color: #eff6ff;
    padding: 1rem 1.5rem;
    font-style: italic;
    margin: 1.5rem 0;
    border-radius: 0.5rem;
}
.quill-content pre {
    background-color: #1f2937;
    color: #f9fafb;
    padding: 1rem;
    border-radius: 0.5rem;
    overflow-x: auto;
    margin: 1.5rem 0;
    font-family: monospace;
}
</style>
