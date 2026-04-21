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
                                <label class="block text-sm font-medium text-gray-700">Category</label>
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
    </AppLayout>
</template>

<script setup>
import { useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { QuillEditor } from '@vueup/vue-quill'
import '@vueup/vue-quill/dist/vue-quill.snow.css'
import { useToast } from '@/Composables/useToast'

const props = defineProps({
    article: Object,
    categories: Array,
    isEditing: Boolean
})

const { showSuccess, showError } = useToast()

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
