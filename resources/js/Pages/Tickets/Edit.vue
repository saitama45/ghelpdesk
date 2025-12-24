<script setup>
import { Head, Link, useForm, usePage, router } from '@inertiajs/vue3';
import { ref, computed, reactive } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import { useConfirm } from '@/Composables/useConfirm';
import { useErrorHandler } from '@/Composables/useErrorHandler';
import { useToast } from '@/Composables/useToast';
import { usePermission } from '@/Composables/usePermission';

const props = defineProps({
    ticket: Object,
    staff: Array,
    companies: Array,
});

const page = usePage();
const { confirm } = useConfirm();
const { put, destroy, post } = useErrorHandler();
const { showSuccess, showError } = useToast();
const { hasPermission } = usePermission();

// Image Viewer State
const showImageViewer = ref(false);
const currentImage = ref(null);
const zoomLevel = ref(1);
const isDragging = ref(false);
const startPan = ref({ x: 0, y: 0 });
const panOffset = ref({ x: 0, y: 0 });
const failedImages = reactive(new Set());

const openImageViewer = (attachment) => {
    currentImage.value = attachment;
    zoomLevel.value = 1;
    panOffset.value = { x: 0, y: 0 };
    showImageViewer.value = true;
};

const closeImageViewer = () => {
    showImageViewer.value = false;
    currentImage.value = null;
};

const handleImageError = (attachmentId) => {
    failedImages.add(attachmentId);
};

const handleZoom = (delta) => {
    const newZoom = zoomLevel.value + delta;
    if (newZoom >= 0.5 && newZoom <= 5) {
        zoomLevel.value = newZoom;
    }
};

const isImage = (filename) => {
    return /\.(jpg|jpeg|png|gif|webp|svg|bmp)$/i.test(filename);
};

const getThumbnailUrl = (attachment) => {
    if (!attachment.file_storage_path) return '';
    
    // Check if path already starts with /storage (not expected but safe)
    if (attachment.file_storage_path.startsWith('/storage')) {
        return attachment.file_storage_path;
    }

    // Legacy: if path starts with public/, remove it and prepend /storage/
    if (attachment.file_storage_path.startsWith('public/')) {
        return '/storage/' + attachment.file_storage_path.replace('public/', '');
    }

    // New standard: path is relative to public disk root (ticket-attachments/filename)
    // Map to /storage/ticket-attachments/filename
    return '/storage/' + attachment.file_storage_path;
};

const editForm = useForm({
    company_id: props.ticket.company_id || '',
    title: props.ticket.title,
    description: props.ticket.description,
    type: props.ticket.type,
    priority: props.ticket.priority,
    status: props.ticket.status,
    severity: props.ticket.severity,
    assignee_id: props.ticket.assignee_id || '',
});

const commentForm = useForm({
    comment_text: '',
    attachments: [],
});

const commentFileInput = ref(null);

const types = ['bug', 'feature', 'task', 'spike'];
const priorities = ['low', 'medium', 'high', 'urgent'];
const statuses = ['open', 'in_progress', 'closed', 'waiting'];
const severities = ['critical', 'major', 'minor', 'cosmetic'];

// Filter available statuses based on permissions
const availableStatuses = computed(() => {
    return statuses.filter(status => {
        if (status === 'closed') {
            // Allow 'closed' if user has permission OR if ticket is already closed (so they see the correct current status)
            return hasPermission('tickets.close') || props.ticket.status === 'closed';
        }
        return true;
    });
});

const updateTicket = () => {
    if (!hasPermission('tickets.edit') && !hasPermission('tickets.assign') && !hasPermission('tickets.close')) {
        showError('You do not have permission to update tickets.');
        return;
    }
    put(route('tickets.update', props.ticket.id), editForm.data(), {
        onSuccess: () => {
            showSuccess('Ticket updated successfully');
        },
        onError: (errors) => {
            const errorMessage = Object.values(errors).flat().join(', ') || 'An error occurred';
            showError(errorMessage);
        }
    });
};

const addComment = () => {
    if (!commentForm.comment_text.trim() && commentForm.attachments.length === 0) return;
    
    // Inertia automatically handles FormData when files are present in the data object
    post(route('tickets.comments.store', props.ticket.id), {
        comment_text: commentForm.comment_text,
        attachments: commentForm.attachments
    }, {
        onSuccess: () => {
            commentForm.reset();
            if (commentFileInput.value) commentFileInput.value.value = '';
            showSuccess('Comment added successfully');
        },
        onError: (errors) => {
            const errorMessage = Object.values(errors).flat().join(', ') || 'Failed to add comment';
            showError(errorMessage);
        }
    });
};

const handleCommentFileSelect = (event) => {
    const files = Array.from(event.target.files);
    commentForm.attachments = [...commentForm.attachments, ...files];
    // Reset input so same file can be selected again if needed (though we just appended)
    event.target.value = ''; 
};

const removeCommentAttachment = (index) => {
    commentForm.attachments.splice(index, 1);
};

const deleteTicket = async () => {
    if (!hasPermission('tickets.delete')) return;

    const confirmed = await confirm({
        title: 'Delete Ticket',
        message: `Are you sure you want to delete Ticket ${props.ticket.ticket_key}? This action cannot be undone.`
    });
    
    if (confirmed) {
        destroy(route('tickets.destroy', props.ticket.id), {
            onSuccess: () => {
                router.visit(route('tickets.index'));
                showSuccess('Ticket deleted successfully');
            },
            onError: (errors) => {
                const errorMessage = Object.values(errors).flat().join(', ') || 'Cannot delete ticket';
                showError(errorMessage);
            }
        });
    }
};

const getPriorityColor = (priority) => {
    switch (priority) {
        case 'urgent': return 'text-red-900 bg-red-200';
        case 'high': return 'text-red-800 bg-red-100';
        case 'medium': return 'text-yellow-800 bg-yellow-100';
        case 'low': return 'text-green-800 bg-green-100';
        default: return 'text-gray-800 bg-gray-100';
    }
};

const getStatusColor = (status) => {
    switch (status) {
        case 'open': return 'text-blue-800 bg-blue-100';
        case 'in_progress': return 'text-purple-800 bg-purple-100';
        case 'closed': return 'text-gray-600 bg-gray-200';
        case 'waiting': return 'text-orange-800 bg-orange-100';
        default: return 'text-gray-800 bg-gray-100';
    }
};

const formatFileSize = (bytes) => {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
};

// Combine comments and unlinked attachments for timeline if needed, 
// but since we link attachments to comments now, we can iterate comments.
// Unlinked attachments (legacy or direct upload) can be shown at the bottom or top or separately.
// For this view, we'll focus on the comment stream + legacy attachment list if any.
</script>

<template>
    <Head :title="`Edit Ticket ${ticket.ticket_key}`" />

    <AppLayout>
        <template #header>
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div class="flex items-center space-x-4">
                    <Link :href="route('tickets.index')" class="text-blue-600 hover:text-blue-800 flex-shrink-0">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                        </svg>
                    </Link>
                    <div class="flex flex-col">
                        <span class="text-sm font-bold text-gray-500 uppercase tracking-wider">{{ ticket.ticket_key }}</span>
                        <h1 class="text-2xl font-bold text-gray-900 leading-tight">
                            {{ ticket.title }}
                        </h1>
                    </div>
                </div>
                <div class="flex items-center text-sm text-gray-500 whitespace-nowrap bg-gray-50 px-3 py-1 rounded-full border border-gray-200">
                     <svg class="w-4 h-4 mr-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
                     Created {{ new Date(ticket.created_at).toLocaleDateString() }}
                </div>
            </div>
        </template>

        <form @submit.prevent="updateTicket">
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Left Column: Content & History -->
                <div class="lg:col-span-2 space-y-6">
                    <!-- Ticket Description -->
                    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Title</label>
                            <input v-model="editForm.title" type="text" :disabled="!hasPermission('tickets.edit')" required class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-lg font-medium disabled:bg-gray-100 disabled:text-gray-500">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Description</label>
                            <textarea v-model="editForm.description" rows="6" :disabled="!hasPermission('tickets.edit')" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 disabled:bg-gray-100 disabled:text-gray-500"></textarea>
                        </div>
                    </div>

                    <!-- Activity / Timeline -->
                    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                        <h3 class="text-lg font-semibold text-gray-900 mb-6">Activity</h3>

                        <!-- Comment Input -->
                        <div class="flex space-x-4 mb-8">
                            <div class="flex-shrink-0">
                                <div class="w-10 h-10 rounded-full bg-gray-200 flex items-center justify-center text-gray-500 font-bold">
                                    {{ $page.props.auth.user.name.charAt(0) }}
                                </div>
                            </div>
                            <div class="flex-grow">
                                <div class="bg-white border border-gray-300 rounded-lg shadow-sm focus-within:ring-1 focus-within:ring-blue-500 focus-within:border-blue-500">
                                    <textarea 
                                        v-model="commentForm.comment_text" 
                                        rows="3" 
                                        class="block w-full border-0 focus:ring-0 resize-y bg-transparent" 
                                        placeholder="Leave a comment..."
                                    ></textarea>
                                    
                                    <!-- Attachment Preview in Comment Form -->
                                    <div v-if="commentForm.attachments.length > 0" class="px-3 pb-2 flex flex-wrap gap-2">
                                        <div v-for="(file, index) in commentForm.attachments" :key="index" class="relative group inline-flex items-center px-2 py-1 bg-blue-50 text-blue-700 rounded text-xs">
                                            <span class="max-w-xs truncate">{{ file.name }}</span>
                                            <button type="button" @click="removeCommentAttachment(index)" class="ml-1 text-blue-400 hover:text-blue-600">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                            </button>
                                        </div>
                                    </div>

                                    <div class="flex items-center justify-between px-3 py-2 border-t border-gray-100 bg-gray-50 rounded-b-lg">
                                        <div class="flex items-center space-x-2">
                                            <input ref="commentFileInput" type="file" multiple class="hidden" @change="handleCommentFileSelect">
                                            <button type="button" @click="commentFileInput.click()" class="p-1 text-gray-500 hover:text-gray-700 rounded hover:bg-gray-200" title="Attach files">
                                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13" /></svg>
                                            </button>
                                        </div>
                                        <button 
                                            type="button" 
                                            @click="addComment" 
                                            :disabled="commentForm.processing || (!commentForm.comment_text.trim() && commentForm.attachments.length === 0)"
                                            class="inline-flex items-center px-4 py-1.5 border border-transparent text-sm font-medium rounded shadow-sm text-white bg-blue-600 hover:bg-blue-700 disabled:opacity-50"
                                        >
                                            Comment
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Timeline List -->
                        <div class="relative pl-4 border-l-2 border-gray-200 space-y-8">
                            <!-- Loop Comments -->
                            <div v-for="comment in ticket.comments" :key="comment.id" class="relative">
                                <!-- Dot -->
                                <div class="absolute -left-[25px] top-0 w-4 h-4 rounded-full bg-blue-100 border-2 border-blue-500"></div>
                                
                                <div class="flex justify-between items-start mb-1">
                                    <div class="flex items-center space-x-2">
                                        <span class="font-semibold text-gray-900">{{ comment.user ? comment.user.name : 'Unknown User' }}</span>
                                        <span class="text-xs text-gray-500">{{ new Date(comment.created_at).toLocaleString() }}</span>
                                    </div>
                                </div>
                                
                                <div class="text-gray-700 whitespace-pre-wrap mb-2">{{ comment.comment_text }}</div>

                                <!-- Comment Attachments -->
                                <div v-if="comment.attachments && comment.attachments.length > 0" class="mt-3 grid grid-cols-2 sm:grid-cols-4 gap-3">
                                    <div v-for="attachment in comment.attachments" :key="attachment.id" class="relative group border border-gray-200 rounded-lg overflow-hidden hover:shadow-md transition bg-white">
                                        <!-- Image Preview -->
                                        <div v-if="isImage(attachment.file_name) && !failedImages.has(attachment.id)" 
                                             class="aspect-w-16 aspect-h-9 bg-gray-100 cursor-pointer relative"
                                             @click="openImageViewer(attachment)">
                                            <img :src="getThumbnailUrl(attachment)" 
                                                 class="object-cover w-full h-32" 
                                                 :alt="attachment.file_name"
                                                 @error="handleImageError(attachment.id)">
                                            <div class="absolute inset-0 bg-black bg-opacity-0 group-hover:bg-opacity-10 transition-all flex items-center justify-center">
                                                <svg class="w-8 h-8 text-white opacity-0 group-hover:opacity-100 drop-shadow-lg" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0zM10 7v3m0 0v3m0-3h3m-3 0H7" /></svg>
                                            </div>
                                        </div>
                                        
                                        <!-- Generic File Icon -->
                                        <div v-else class="h-32 flex flex-col items-center justify-center p-4 bg-gray-50">
                                            <svg class="w-10 h-10 text-gray-400 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                                            </svg>
                                            <span class="text-xs text-gray-500 text-center truncate w-full px-2">{{ attachment.file_name }}</span>
                                        </div>

                                        <!-- File info -->
                                        <div class="bg-gray-50 px-2 py-1 text-xs border-t border-gray-100 flex justify-between items-center">
                                            <span class="text-gray-500 truncate w-full">{{ formatFileSize(attachment.file_size_bytes) }}</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Legacy/Unlinked Attachments (if any, grouping them as an event) -->
                            <div v-if="ticket.attachments.filter(a => !a.comment_id).length > 0" class="relative">
                                <div class="absolute -left-[25px] top-0 w-4 h-4 rounded-full bg-gray-100 border-2 border-gray-400"></div>
                                <div class="mb-1 text-sm text-gray-500">
                                    Attachments uploaded separately
                                </div>
                                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                                    <div v-for="attachment in ticket.attachments.filter(a => !a.comment_id)" :key="attachment.id" class="relative group border border-gray-200 rounded-lg overflow-hidden hover:shadow-md transition bg-white">
                                        <!-- Image Preview -->
                                        <div v-if="isImage(attachment.file_name) && !failedImages.has(attachment.id)" 
                                             class="aspect-w-16 aspect-h-9 bg-gray-100 cursor-pointer relative"
                                             @click="openImageViewer(attachment)">
                                            <img :src="getThumbnailUrl(attachment)" 
                                                 class="object-cover w-full h-32" 
                                                 :alt="attachment.file_name"
                                                 @error="handleImageError(attachment.id)">
                                            <div class="absolute inset-0 bg-black bg-opacity-0 group-hover:bg-opacity-10 transition-all flex items-center justify-center">
                                                <svg class="w-8 h-8 text-white opacity-0 group-hover:opacity-100 drop-shadow-lg" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0zM10 7v3m0 0v3m0-3h3m-3 0H7" /></svg>
                                            </div>
                                        </div>
                                        
                                        <!-- Generic File Icon -->
                                        <div v-else class="h-32 flex flex-col items-center justify-center p-4 bg-gray-50">
                                            <svg class="w-10 h-10 text-gray-400 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                                            </svg>
                                            <span class="text-xs text-gray-500 text-center truncate w-full px-2">{{ attachment.file_name }}</span>
                                        </div>

                                        <!-- File info -->
                                        <div class="bg-gray-50 px-2 py-1 text-xs border-t border-gray-100 flex justify-between items-center">
                                            <span class="text-gray-500 truncate w-full">{{ formatFileSize(attachment.file_size_bytes) }}</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                        </div>
                    </div>
                </div>

                <!-- Right Column: Metadata -->
                <div class="lg:col-span-1 space-y-6">
                    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6 space-y-6 sticky top-6">
                        <div class="space-y-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Company</label>
                                <select v-model="editForm.company_id" :disabled="!hasPermission('tickets.edit')" required class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 disabled:bg-gray-100 disabled:text-gray-500">
                                    <option value="">Select Company</option>
                                    <option v-for="company in companies" :key="company.id" :value="company.id">{{ company.name }}</option>
                                </select>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Status</label>
                                <select v-model="editForm.status" :disabled="!hasPermission('tickets.edit') && !hasPermission('tickets.close')" required class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 capitalize disabled:bg-gray-100 disabled:text-gray-500">
                                    <option v-for="s in availableStatuses" :key="s" :value="s">{{ s.replace('_', ' ') }}</option>
                                </select>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Priority</label>
                                <select v-model="editForm.priority" :disabled="!hasPermission('tickets.edit')" required class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 capitalize disabled:bg-gray-100 disabled:text-gray-500">
                                    <option v-for="p in priorities" :key="p" :value="p">{{ p }}</option>
                                </select>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Severity</label>
                                <select v-model="editForm.severity" :disabled="!hasPermission('tickets.edit')" required class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 capitalize disabled:bg-gray-100 disabled:text-gray-500">
                                    <option v-for="s in severities" :key="s" :value="s">{{ s }}</option>
                                </select>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Type</label>
                                <select v-model="editForm.type" :disabled="!hasPermission('tickets.edit')" required class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 capitalize disabled:bg-gray-100 disabled:text-gray-500">
                                    <option v-for="t in types" :key="t" :value="t">{{ t }}</option>
                                </select>
                            </div>
                        </div>

                        <div v-if="hasPermission('tickets.assign')">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Assignee</label>
                            <select v-model="editForm.assignee_id" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500">
                                <option value="">Unassigned</option>
                                <option v-for="person in staff" :key="person.id" :value="person.id">{{ person.name }}</option>
                            </select>
                        </div>

                        <div class="pt-6 border-t space-y-3">
                             <button v-if="hasPermission('tickets.edit') || hasPermission('tickets.assign') || hasPermission('tickets.close')" type="submit" :disabled="editForm.processing" class="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 disabled:opacity-50 transition-all">
                                Save Changes
                            </button>

                            <button v-if="hasPermission('tickets.delete')" type="button" @click="deleteTicket" class="w-full flex justify-center py-2 px-4 border border-transparent rounded-md text-sm font-medium text-red-600 bg-red-50 hover:bg-red-100 transition-colors">
                                Delete Ticket
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </form>

        <!-- Image Viewer Modal -->
        <Modal :show="showImageViewer" max-width="4xl" @close="closeImageViewer">
            <div class="relative bg-black rounded-lg overflow-hidden h-[80vh] flex flex-col">
                <!-- Toolbar -->
                <div class="absolute top-0 left-0 right-0 z-10 flex justify-between items-center p-4 bg-gradient-to-b from-black/50 to-transparent">
                    <h3 class="text-white font-medium truncate ml-2 text-shadow">{{ currentImage?.file_name }}</h3>
                    <div class="flex items-center space-x-2">
                        <button @click="handleZoom(-0.5)" class="p-2 text-white hover:bg-white/20 rounded-full backdrop-blur-sm">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4" /></svg>
                        </button>
                        <span class="text-white text-sm font-mono w-12 text-center">{{ Math.round(zoomLevel * 100) }}%</span>
                        <button @click="handleZoom(0.5)" class="p-2 text-white hover:bg-white/20 rounded-full backdrop-blur-sm">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" /></svg>
                        </button>
                        <div class="w-px h-6 bg-white/30 mx-2"></div>
                        <button @click="closeImageViewer" class="p-2 text-white hover:bg-red-500/80 rounded-full backdrop-blur-sm transition-colors">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                        </button>
                    </div>
                </div>

                <!-- Image Container -->
                <div class="flex-grow flex items-center justify-center overflow-hidden cursor-move p-4" 
                     @mousedown.prevent="isDragging = true" 
                     @mouseup="isDragging = false" 
                     @mouseleave="isDragging = false"
                     @mousemove="isDragging && (panOffset.x += $event.movementX, panOffset.y += $event.movementY)">
                    <img 
                        v-if="currentImage"
                        :src="getThumbnailUrl(currentImage)" 
                        class="transition-transform duration-100 ease-linear transform origin-center max-w-none"
                        :style="{ 
                            transform: `scale(${zoomLevel}) translate(${panOffset.x / zoomLevel}px, ${panOffset.y / zoomLevel}px)` 
                        }"
                        draggable="false"
                    >
                </div>
                
                <!-- Footer (Optional: could add more controls here later) -->
                 <div class="absolute bottom-0 left-0 right-0 p-4 bg-gradient-to-t from-black/50 to-transparent flex justify-center">
                    <!-- Download Original removed -->
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>
