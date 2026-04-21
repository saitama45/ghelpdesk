<template>
    <AppLayout title="KB Articles">
        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <DataTable
                    title="Knowledge Base Articles"
                    subtitle="Manage articles, guides, and documentation"
                    search-placeholder="Search by title or category..."
                    empty-message="No articles found."
                    :search="pagination.search.value"
                    :data="pagination.data.value"
                    :current-page="pagination.currentPage.value"
                    :last-page="pagination.lastPage.value"
                    :per-page="pagination.perPage.value"
                    :showing-text="pagination.showingText.value"
                    :is-loading="pagination.isLoading.value"
                    @update:search="pagination.search.value = $event"
                    @go-to-page="pagination.goToPage"
                    @change-per-page="pagination.changePerPage"
                >
                    <template #actions>
                        <Link 
                            v-if="hasPermission('kb_articles.create')"
                            :href="route('kb-articles.create')" 
                            class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors flex items-center space-x-2 shadow-sm whitespace-nowrap"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                            </svg>
                            <span>Create Article</span>
                        </Link>
                    </template>

                    <template #header>
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Title</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Category</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Author</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Views</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </template>

                    <template #body="{ data }">
                        <tr v-for="article in data" :key="article.id" class="hover:bg-gray-50 transition-colors">
                            <td class="px-6 py-4">
                                <div class="text-sm font-medium text-gray-900">{{ article.title }}</div>
                                <div class="text-xs text-gray-500">Last updated: {{ formatDate(article.updated_at) }}</div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="px-2 py-1 text-xs font-medium bg-gray-100 text-gray-800 rounded-full">
                                    {{ article.category?.name }}
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm text-gray-900">{{ article.author?.name }}</div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span :class="article.is_published ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800'" 
                                      class="inline-flex px-2 py-1 text-xs font-semibold rounded-full border border-current opacity-80">
                                    {{ article.is_published ? 'Published' : 'Draft' }}
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-center">
                                {{ article.views }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                <div class="flex justify-end space-x-1">
                                    <Link 
                                        v-if="hasPermission('kb_articles.edit')"
                                        :href="route('kb-articles.edit', article.id)" 
                                        class="p-2 text-blue-600 hover:text-blue-900 hover:bg-blue-50 rounded-full transition-colors"
                                        title="Edit"
                                    >
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                        </svg>
                                    </Link>
                                    <button 
                                        v-if="hasPermission('kb_articles.delete')"
                                        @click="deleteArticle(article)" 
                                        class="p-2 text-red-600 hover:text-red-900 hover:bg-red-50 rounded-full transition-colors"
                                        title="Delete"
                                    >
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                        </svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </template>
                </DataTable>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { onMounted, watch } from 'vue'
import { Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import DataTable from '@/Components/DataTable.vue'
import { useToast } from '@/Composables/useToast'
import { useConfirm } from '@/Composables/useConfirm'
import { useErrorHandler } from '@/Composables/useErrorHandler'
import { usePagination } from '@/Composables/usePagination'
import { usePermission } from '@/Composables/usePermission'

const props = defineProps({
    articles: Object,
    filters: Object
})

const { showSuccess, showError } = useToast()
const { confirm } = useConfirm()
const { destroy } = useErrorHandler()
const pagination = usePagination(props.articles, 'kb-articles.index')
const { hasPermission } = usePermission()

onMounted(() => {
    pagination.updateData(props.articles)
})

watch(() => props.articles, (newArticles) => {
    pagination.updateData(newArticles)
}, { deep: true })

const formatDate = (dateString) => {
    return new Date(dateString).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric'
    })
}

const deleteArticle = async (article) => {
    const confirmed = await confirm({
        title: 'Delete Article',
        message: `Are you sure you want to delete "${article.title}"?`
    })
    
    if (confirmed) {
        destroy(route('kb-articles.destroy', article.id), {
            onError: (errors) => {
                showError('Cannot delete article')
            }
        })
    }
}
</script>
