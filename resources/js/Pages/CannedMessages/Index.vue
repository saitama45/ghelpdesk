<template>
    <AppLayout title="Canned Messages">
        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <DataTable
                    title="Canned Messages"
                    subtitle="Manage pre-defined response templates for tickets"
                    search-placeholder="Search by title or content..."
                    empty-message="No canned messages found."
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
                        <button 
                            v-if="hasPermission('canned_messages.create')"
                            @click="openCreateModal" 
                            class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors flex items-center space-x-2"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                            </svg>
                            <span>Create Canned Message</span>
                        </button>
                    </template>

                    <template #header>
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Title</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Content Preview</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </template>

                    <template #body="{ data }">
                        <tr v-for="message in data" :key="message.id" class="hover:bg-gray-50 transition-colors">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm font-medium text-gray-900">{{ message.title }}</div>
                            </td>
                            <td class="px-6 py-4">
                                <div class="text-sm text-gray-500 truncate max-w-md">{{ message.content }}</div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span :class="message.is_active ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'" 
                                      class="inline-flex px-2 py-1 text-xs font-semibold rounded-full">
                                    {{ message.is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                <div class="flex justify-end space-x-1">
                                    <button 
                                        v-if="hasPermission('canned_messages.edit')"
                                        @click="editMessage(message)" 
                                        class="p-2 text-blue-600 hover:text-blue-900 hover:bg-blue-50 rounded-full transition-colors"
                                        title="Edit"
                                    >
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                        </svg>
                                    </button>
                                    <button 
                                        v-if="hasPermission('canned_messages.delete')"
                                        @click="deleteMessage(message)" 
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

        <!-- Create/Edit Modal -->
        <div v-if="showModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
            <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-3/4 lg:w-1/2 shadow-lg rounded-md bg-white">
                <div class="mt-3">
                    <h3 class="text-lg font-bold text-gray-900 mb-4 border-b pb-2">
                        {{ isEditing ? 'Edit Canned Message' : 'Create Canned Message' }}
                    </h3>
                    <form @submit.prevent="submitForm">
                        <div class="space-y-4 mb-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Title</label>
                                <input v-model="form.title" type="text" required
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm"
                                       placeholder="e.g. Greeting">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Content</label>
                                <textarea v-model="form.content" required rows="6"
                                          class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm"
                                          placeholder="Enter the message template..."></textarea>
                            </div>
                            <div class="flex items-center">
                                <label class="flex items-center cursor-pointer">
                                    <input v-model="form.is_active" type="checkbox" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                    <span class="ml-2 text-sm text-gray-700">Active</span>
                                </label>
                            </div>
                        </div>

                        <div class="flex justify-end space-x-3 pt-4 border-t">
                            <button type="button" @click="closeModal" 
                                    class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-200 rounded-md hover:bg-gray-300">
                                Cancel
                            </button>
                            <button type="submit" 
                                    class="px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-md hover:bg-blue-700">
                                {{ isEditing ? 'Update' : 'Create' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref, reactive, onMounted, watch } from 'vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import DataTable from '@/Components/DataTable.vue'
import { useToast } from '@/Composables/useToast'
import { useConfirm } from '@/Composables/useConfirm'
import { useErrorHandler } from '@/Composables/useErrorHandler'
import { usePagination } from '@/Composables/usePagination'
import { usePermission } from '@/Composables/usePermission'

const props = defineProps({
    cannedMessages: Object
})

const { showSuccess, showError } = useToast()
const { confirm } = useConfirm()
const { post, put, destroy } = useErrorHandler()
const pagination = usePagination(props.cannedMessages, 'canned-messages.index')
const { hasPermission } = usePermission()

const showModal = ref(false)
const isEditing = ref(false)
const currentMessage = ref(null)

const form = reactive({
    title: '',
    content: '',
    is_active: true
})

onMounted(() => {
    pagination.updateData(props.cannedMessages)
})

watch(() => props.cannedMessages, (newMessages) => {
    pagination.updateData(newMessages)
}, { deep: true })

const openCreateModal = () => {
    isEditing.value = false
    currentMessage.value = null
    Object.assign(form, {
        title: '',
        content: '',
        is_active: true
    })
    showModal.value = true
}

const editMessage = (message) => {
    isEditing.value = true
    currentMessage.value = message
    Object.assign(form, {
        title: message.title,
        content: message.content,
        is_active: message.is_active
    })
    showModal.value = true
}

const closeModal = () => {
    showModal.value = false
}

const submitForm = () => {
    const url = isEditing.value ? `/canned-messages/${currentMessage.value.id}` : '/canned-messages'
    const requestMethod = isEditing.value ? put : post
    
    requestMethod(url, form, {
        onSuccess: () => {
            closeModal()
        },
        onError: (errors) => {
            const errorMessage = Object.values(errors).flat().join(', ') || 'An error occurred'
            showError(errorMessage)
        }
    })
}

const deleteMessage = async (message) => {
    const confirmed = await confirm({
        title: 'Delete Canned Message',
        message: `Are you sure you want to delete "${message.title}"?`
    })
    
    if (confirmed) {
        destroy(`/canned-messages/${message.id}`, {
            onSuccess: () => {},
            onError: (errors) => {
                const errorMessage = Object.values(errors).flat().join(', ') || 'Cannot delete message'
                showError(errorMessage)
            }
        })
    }
}
</script>
