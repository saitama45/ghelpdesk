<template>
    <AppLayout :title="isEditing ? 'Edit Article' : 'Create Article'">
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ isEditing ? 'Edit Article' : 'Create Article' }}
            </h2>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg p-6">
                    <form @submit.prevent="submit" class="space-y-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <!-- Title -->
                            <div class="col-span-2 md:col-span-1">
                                <label class="block text-sm font-medium text-gray-700">Title</label>
                                <input v-model="form.title" type="text" required
                                    class="mt-1 block w-full border-gray-300 focus:border-blue-500 focus:ring-blue-500 rounded-md shadow-sm"
                                    placeholder="Enter article title">
                                <div v-if="form.errors.title" class="text-red-500 text-xs mt-1">{{ form.errors.title }}</div>
                            </div>

                            <!-- Category Autocomplete -->
                            <div class="col-span-2 md:col-span-1">
                                <div class="flex items-center justify-between">
                                    <label class="block text-sm font-medium text-gray-700">Category</label>
                                    <button 
                                        type="button" 
                                        @click="showManageCategories = true"
                                        class="text-[10px] font-black text-blue-600 uppercase hover:text-blue-800 flex items-center space-x-1"
                                    >
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                        </svg>
                                        <span>Manage</span>
                                    </button>
                                </div>
                                <div class="relative mt-1">
                                    <input v-model="form.category_name" type="text" required
                                        list="kb-categories-list"
                                        class="block w-full border-gray-300 focus:border-blue-500 focus:ring-blue-500 rounded-md shadow-sm"
                                        placeholder="Type or select a category">
                                    <datalist id="kb-categories-list">
                                        <option v-for="cat in categories" :key="cat.id" :value="cat.name"></option>
                                    </datalist>
                                </div>
                                <p class="text-[10px] text-gray-500 mt-1 italic">Type a new name to create a new category automatically.</p>
                                <div v-if="form.errors.category_name" class="text-red-500 text-xs mt-1">{{ form.errors.category_name }}</div>
                            </div>
                        </div>

                        <!-- Content (Quill Editor) -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Content</label>
                            <div class="quill-container border rounded-md min-h-[400px]">
                                <QuillEditor 
                                    v-model:content="form.content" 
                                    content-type="html"
                                    theme="snow"
                                    toolbar="full"
                                    class="min-h-[350px]"
                                />
                            </div>
                            <div v-if="form.errors.content" class="text-red-500 text-xs mt-1">{{ form.errors.content }}</div>
                        </div>

                        <!-- Publishing -->
                        <div class="flex items-center space-x-4">
                            <label class="inline-flex items-center cursor-pointer">
                                <input type="checkbox" v-model="form.is_published" class="rounded border-gray-300 text-blue-600 shadow-sm focus:ring-blue-500">
                                <span class="ml-2 text-sm font-medium text-gray-700">Publish this article</span>
                            </label>
                        </div>

                        <!-- Actions -->
                        <div class="flex items-center justify-end space-x-3 pt-6 border-t">
                            <Link :href="route('kb-articles.index')" class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md shadow-sm hover:bg-gray-50">
                                Cancel
                            </Link>
                            <button type="submit" 
                                :disabled="form.processing"
                                class="inline-flex justify-center py-2 px-6 border border-transparent shadow-sm text-sm font-bold rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 disabled:opacity-50">
                                {{ isEditing ? 'Update Article' : 'Create Article' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Manage Categories Modal -->
        <div v-if="showManageCategories" class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex items-center justify-center min-h-screen px-4 py-6">
                <div class="fixed inset-0 bg-black/20 backdrop-blur-md" @click="showManageCategories = false"></div>
                <div class="relative bg-white rounded-xl shadow-2xl w-full max-w-md p-6 border border-gray-100">
                    <div class="flex justify-between items-center mb-6 border-b pb-4">
                        <div>
                            <h3 class="text-xl font-bold text-gray-900">Manage Categories</h3>
                            <p class="text-xs text-gray-500 mt-1 uppercase font-black">Clean up typos or duplicates</p>
                        </div>
                        <button @click="showManageCategories = false" class="text-gray-400 hover:text-gray-600 transition-colors">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <div class="max-h-96 overflow-y-auto pr-2 custom-scrollbar">
                        <div class="space-y-2">
                            <div v-for="cat in categories" :key="cat.id" class="flex items-center justify-between p-3 bg-gray-50 rounded-lg hover:bg-gray-100 transition-colors group">
                                <span class="text-sm font-medium text-gray-700">{{ cat.name }}</span>
                                <button 
                                    @click="deleteCategory(cat)"
                                    class="p-1.5 text-gray-400 hover:text-red-600 hover:bg-red-50 rounded-md transition-all opacity-0 group-hover:opacity-100"
                                    title="Delete Category"
                                >
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                    </svg>
                                </button>
                            </div>
                        </div>
                        
                        <div v-if="categories.length === 0" class="text-center py-8">
                            <p class="text-sm text-gray-400">No categories found</p>
                        </div>
                    </div>

                    <div class="flex justify-end mt-8 pt-4 border-t">
                        <button @click="showManageCategories = false" 
                                class="px-6 py-2 bg-gray-900 text-white rounded-lg hover:bg-gray-800 transition-colors text-sm font-bold shadow-md">
                            Close
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref } from 'vue'
import { useForm, Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { QuillEditor } from '@vueup/vue-quill'
import '@vueup/vue-quill/dist/vue-quill.snow.css'
import { useToast } from '@/Composables/useToast'
import { useConfirm } from '@/Composables/useConfirm'

const props = defineProps({
    article: Object,
    categories: Array,
    isEditing: Boolean
})

const { showSuccess, showError } = useToast()
const { confirm } = useConfirm()

const showManageCategories = ref(false)

const form = useForm({
    title: props.article?.title || '',
    category_name: props.article?.category?.name || '',
    content: props.article?.content || '',
    is_published: props.article?.is_published || false
})

const submit = () => {
    if (props.isEditing) {
        form.put(route('kb-articles.update', props.article.id), {
            onError: () => showError('Failed to update article')
        })
    } else {
        form.post(route('kb-articles.store'), {
            onError: () => showError('Failed to create article')
        })
    }
}

const deleteCategory = async (category) => {
    const confirmed = await confirm({
        title: 'Delete Category',
        message: `Are you sure you want to delete "${category.name}"? This will only work if no articles are assigned to it.`
    })

    if (confirmed) {
        router.delete(route('kb-categories.destroy', category.id), {
            preserveScroll: true,
            onError: (errors) => {
                showError('Cannot delete category')
            }
        })
    }
}
</script>

<style>
.quill-container .ql-container {
    font-size: 1rem;
    min-height: 350px;
}
.quill-container .ql-editor {
    min-height: 350px;
}
</style>
