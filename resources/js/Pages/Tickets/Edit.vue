<script setup>
import { Head, Link, useForm, usePage, router } from '@inertiajs/vue3';
import { ref, computed, reactive, watch, nextTick, onMounted, onUnmounted } from 'vue';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import CustomSelect from '@/Components/CustomSelect.vue';
import Autocomplete from '@/Components/Autocomplete.vue';
import { useConfirm } from '@/Composables/useConfirm';
import { useErrorHandler } from '@/Composables/useErrorHandler';
import { useToast } from '@/Composables/useToast';
import { usePermission } from '@/Composables/usePermission';
import { useDateFormatter } from '@/Composables/useDateFormatter';
import { ChatBubbleBottomCenterTextIcon, ChevronDownIcon, XMarkIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    ticket: Object,
    staff: Array,
    companies: Array,
    users: Array,
    stores: Array,
    cannedMessages: Array,
});

const page = usePage();
const { confirm } = useConfirm();
const { put, destroy, post } = useErrorHandler();
const { showSuccess, showError } = useToast();
const { hasPermission } = usePermission();
const { formatDate, parseDate } = useDateFormatter();

// Canned Messages State
const showCannedMessages = ref(false);

const applyCannedMessage = (message) => {
    if (commentForm.comment_text) {
        commentForm.comment_text += '\n' + message.content;
    } else {
        commentForm.comment_text = message.content;
    }
    showCannedMessages.value = false;
};

// Child Ticket State
const showChildModal = ref(false);
const childForm = useForm({
    user_id: null,
    status: 'On-site',
    start_time: '',
    end_time: '',
    pickup_start: '',
    pickup_end: '',
    backlogs_start: '',
    backlogs_end: '',
    remarks: ''
});

const scheduleStatuses = [
    'On-site', 'Off-site', 'WFH', 'SL', 'VL', 'Restday', 'Offset', 'Holiday'
];

const formatDateForInput = (date) => {
    const d = new Date(date);
    d.setMinutes(d.getMinutes() - d.getTimezoneOffset());
    return d.toISOString().slice(0, 16);
};

const openChildModal = () => {
    childForm.reset();
    
    // Set default times
    const now = new Date();
    childForm.start_time = formatDateForInput(now);
    const end = new Date();
    end.setHours(end.getHours() + 8);
    childForm.end_time = formatDateForInput(end);
    
    showChildModal.value = true
};

const submitChildTicket = () => {
    childForm.post(route('tickets.store-child', props.ticket.id), {
        onSuccess: () => {
            showChildModal.value = false;
        },
        onError: (errors) => {
            const errorMessage = Object.values(errors).flat().join(', ') || 'An error occurred';
            showError(errorMessage);
        }
    });
};

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
    if (newZoom >= 0.1 && newZoom <= 5) {
        zoomLevel.value = Math.round(newZoom * 10) / 10;
    }
};

const handleWheel = (event) => {
    const delta = event.deltaY > 0 ? -0.1 : 0.1;
    handleZoom(delta);
};

const isImage = (filename) => {
    return /\.(jpg|jpeg|png|gif|webp|svg|bmp)$/i.test(filename);
};

const createFileObject = (file) => {
    return {
        id: 'local-' + Date.now() + '-' + Math.random(),
        file: file,
        file_name: file.name,
        file_size_bytes: file.size,
        preview: isImage(file.name) ? URL.createObjectURL(file) : null,
        is_local: true
    };
};

const getThumbnailUrl = (attachment) => {
    if (attachment.preview) return attachment.preview;
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
    store_id: props.ticket.store_id || '',
    category_id: props.ticket.category_id || '',
    sub_category_id: props.ticket.sub_category_id || '',
    item_id: props.ticket.item_id || '',
    title: props.ticket.title,
    description: props.ticket.description,
    type: props.ticket.type,
    priority: props.ticket.priority,
    status: props.ticket.status,
    severity: props.ticket.severity,
    assignee_id: props.ticket.assignee_id || '',
});

const categories = ref([]);
const subCategories = ref([]);
const items = ref([]);

const fetchCategories = async () => {
    try {
        const response = await axios.get(route('tickets.data.categories'));
        categories.value = response.data;
    } catch (error) {
        console.error('Error fetching categories:', error);
    }
};

const fetchSubCategories = async (categoryId) => {
    if (!categoryId) {
        subCategories.value = [];
        return;
    }
    try {
        const response = await axios.get(`/tickets/data/subcategories?category_id=${categoryId}`);
        subCategories.value = response.data;
    } catch (error) {
        console.error('Error fetching subcategories:', error);
    }
};

const fetchItems = async (categoryId, subCategoryId) => {
    if (!categoryId || !subCategoryId) {
        items.value = [];
        return;
    }
    try {
        const response = await axios.get(`/tickets/data/items?category_id=${categoryId}&sub_category_id=${subCategoryId}`);
        items.value = response.data;
    } catch (error) {
        console.error('Error fetching items:', error);
    }
};

onMounted(() => {
    fetchCategories();
    if (props.ticket.category_id) fetchSubCategories(props.ticket.category_id);
    if (props.ticket.category_id && props.ticket.sub_category_id) fetchItems(props.ticket.category_id, props.ticket.sub_category_id);
    window.addEventListener('keydown', handleKeydown);
});

onUnmounted(() => {
    window.removeEventListener('keydown', handleKeydown);
});

const commentForm = useForm({
    comment_text: '',
    status: '',
    attachments: [],
});

const showStatusDropdown = ref(false);

const submitWithStatus = (newStatus) => {
    commentForm.status = newStatus;
    addComment();
    showStatusDropdown.value = false;
};

const commentFileInput = ref(null);

// Inline Editing State
const isEditingTitle = ref(false);
const isEditingDescription = ref(false);
const titleInput = ref(null);
const descriptionInput = ref(null);

const priorities = ['low', 'medium', 'high', 'urgent'];
const statuses = ['open', 'in_progress', 'resolved', 'closed', 'waiting_service_provider', 'waiting_client_feedback'];

// Filter available statuses based on permissions
const availableStatuses = computed(() => {
    return statuses.filter(status => {
        if (status === 'closed' || status === 'resolved') {
            // Allow 'closed' or 'resolved' if user has permission OR if ticket is already in that status
            return hasPermission('tickets.close') || props.ticket.status === status;
        }
        return true;
    });
});

// Image Navigation
const allImages = computed(() => {
    const images = [];
    
    // Add description attachments
    const descAttachments = (props.ticket.attachments || []).filter(a => !a.comment_id && isImage(a.file_name));
    images.push(...descAttachments);

    // Add comment attachments
    if (props.ticket.comments) {
        props.ticket.comments.forEach(comment => {
            if (comment.attachments) {
                images.push(...comment.attachments.filter(a => isImage(a.file_name)));
            }
        });
    }

    return images;
});

const currentIndex = computed(() => {
    if (!currentImage.value) return -1;
    return allImages.value.findIndex(img => img.id === currentImage.value.id);
});

const navigateImage = (direction) => {
    if (currentIndex.value === -1 || allImages.value.length <= 1) return;
    
    let newIndex = currentIndex.value + direction;
    
    // Loop
    if (newIndex < 0) newIndex = allImages.value.length - 1;
    if (newIndex >= allImages.value.length) newIndex = 0;
    
    currentImage.value = allImages.value[newIndex];
    zoomLevel.value = 1;
    panOffset.value = { x: 0, y: 0 };
};

const handleKeydown = (e) => {
    if (!showImageViewer.value) return;
    
    if (e.key === 'ArrowLeft') navigateImage(-1);
    if (e.key === 'ArrowRight') navigateImage(1);
    if (e.key === 'Escape') closeImageViewer();
};

const activities = computed(() => {
    const comments = (props.ticket.comments || []).map(c => ({
        ...c,
        activity_type: 'comment',
        date: parseDate(c.created_at)
    }));

    const histories = (props.ticket.histories || []).map(h => ({
        ...h,
        activity_type: 'history',
        date: parseDate(h.changed_at)
    }));

    // Add Description as an activity
    const description = {
        id: 'description-' + props.ticket.id,
        activity_type: 'description',
        date: parseDate(props.ticket.created_at),
        user: props.ticket.reporter,
        sender_name: props.ticket.sender_name,
        sender_email: props.ticket.sender_email,
        text: props.ticket.description,
        // Attachments that are not linked to any comment (created with ticket)
        attachments: (props.ticket.attachments || []).filter(a => !a.comment_id)
    };

    const children = (props.ticket.children || []).map(child => ({
        ...child,
        activity_type: 'child_ticket',
        date: parseDate(child.created_at),
        user: child.reporter
    }));

    // Sort ascending (oldest first)
    return [...comments, ...histories, ...children, description].sort((a, b) => {
        return a.date.getTime() - b.date.getTime();
    });
});

const formatColumnName = (column) => {
    if (column === 'company_id') return 'Company';
    if (column === 'store_id') return 'Store';
    if (column === 'assignee_id') return 'Assignee';
    if (column === 'reporter_id') return 'Reporter';
    return column.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
};

const debounce = (fn, delay) => {
    let timeoutId;
    return (...args) => {
        clearTimeout(timeoutId);
        timeoutId = setTimeout(() => fn(...args), delay);
    };
};

const updateTicket = (options = {}) => {
    if (!hasPermission('tickets.edit') && !hasPermission('tickets.assign') && !hasPermission('tickets.close')) {
        showError('You do not have permission to update tickets.');
        return;
    }
    
    // Check if form is dirty to avoid unnecessary requests
    if (!editForm.isDirty) {
        if (options.onSuccess) options.onSuccess();
        return;
    }

    if (editForm.processing) {
        // If already processing, try again after a short delay
        setTimeout(() => updateTicket(options), 500);
        return;
    }

    put(route('tickets.update', props.ticket.id), editForm.data(), {
        preserveScroll: true, // Keep scroll position during autosave
        onSuccess: () => {
            editForm.defaults(editForm.data()); // Update defaults to new state
            if (options.onSuccess) options.onSuccess();
        },
        onError: (errors) => {
            const errorMessage = Object.values(errors).flat().join(', ') || 'An error occurred';
            showError(errorMessage);
            if (options.onError) options.onError(errors);
        }
    });
};

const debouncedUpdate = debounce(() => {
    updateTicket();
}, 1000);

// Watch for ticket changes to sync form state (important after status changes from comments)
watch(() => props.ticket, (newTicket) => {
    editForm.status = newTicket.status;
    editForm.priority = newTicket.priority;
    editForm.severity = newTicket.severity;
    editForm.type = newTicket.type;
    editForm.assignee_id = newTicket.assignee_id || '';
    editForm.category_id = newTicket.category_id || '';
    editForm.sub_category_id = newTicket.sub_category_id || '';
    editForm.item_id = newTicket.item_id || '';
    editForm.company_id = newTicket.company_id || '';
    editForm.store_id = newTicket.store_id || '';
    editForm.defaults(editForm.data()); // Reset dirty state
}, { deep: true });

// Watchers for other fields
watch(() => [
    editForm.company_id,
    editForm.store_id,
    editForm.status,
    editForm.priority,
    editForm.severity,
    editForm.type,
    editForm.assignee_id
], () => {
    debouncedUpdate();
});

// Watchers for cascading dropdowns
watch(() => editForm.category_id, (newVal, oldVal) => {
    if (newVal != oldVal) {
        if (oldVal !== undefined) {
            editForm.sub_category_id = '';
            editForm.item_id = '';
        }
        if (newVal) fetchSubCategories(newVal);
    }
});

watch(() => editForm.sub_category_id, (newVal, oldVal) => {
    if (newVal != oldVal) {
        if (oldVal !== undefined) {
            editForm.item_id = '';
        }
        if (newVal) fetchItems(editForm.category_id, newVal);
    }
});

// Classification Watcher: Only save when the full hierarchy (Category -> Sub -> Item) is complete
watch(() => editForm.item_id, (newVal, oldVal) => {
    if (newVal && newVal != oldVal && oldVal !== undefined) {
        const item = items.value.find(i => i.id == newVal);
        if (item) {
            editForm.priority = item.priority.toLowerCase();
        }
        debouncedUpdate();
    }
});

const startEditingTitle = () => {
    if (!hasPermission('tickets.edit')) return;
    isEditingTitle.value = true;
    nextTick(() => {
        if (titleInput.value) titleInput.value.focus();
    });
};

const saveTitle = () => {
    updateTicket({
        onSuccess: () => {
            isEditingTitle.value = false;
        }
    });
};

const cancelTitleEdit = () => {
    editForm.title = props.ticket.title;
    isEditingTitle.value = false;
};

const startEditingDescription = () => {
    if (!hasPermission('tickets.edit')) return;
    isEditingDescription.value = true;
    nextTick(() => {
        if (descriptionInput.value) descriptionInput.value.focus();
    });
};

const saveDescription = () => {
    updateTicket({
        onSuccess: () => {
            isEditingDescription.value = false;
        }
    });
};

const cancelDescriptionEdit = () => {
    editForm.description = props.ticket.description;
    isEditingDescription.value = false;
};

const addComment = () => {
    if (!commentForm.comment_text.trim() && commentForm.attachments.length === 0) return;
    
    const attachmentsToUpload = commentForm.attachments.map(a => a.file);
    
    post(route('tickets.comments.store', props.ticket.id), {
        comment_text: commentForm.comment_text,
        status: commentForm.status,
        attachments: attachmentsToUpload
    }, {
        onSuccess: () => {
            commentForm.attachments.forEach(a => {
                if (a.preview) URL.revokeObjectURL(a.preview);
            });
            commentForm.reset();
            if (commentFileInput.value) commentFileInput.value.value = '';
        },
        onError: (errors) => {
            const errorMessage = Object.values(errors).flat().join(', ') || 'Failed to add comment';
            showError(errorMessage);
        }
    });
};

const handleCommentFileSelect = (event) => {
    const files = Array.from(event.target.files);
    const wrappedFiles = files.map(file => createFileObject(file));
    commentForm.attachments = [...commentForm.attachments, ...wrappedFiles];
    event.target.value = ''; 
};

const handlePaste = (event) => {
    const items = (event.clipboardData || event.originalEvent.clipboardData).items;
    for (const item of items) {
        if (item.type.indexOf('image') !== -1) {
            const blob = item.getAsFile();
            if (blob) {
                const file = new File([blob], `screenshot-${Date.now()}.png`, { type: blob.type });
                commentForm.attachments.push(createFileObject(file));
            }
        }
    }
};

const removeCommentAttachment = (index) => {
    const attachment = commentForm.attachments[index];
    if (attachment.preview) URL.revokeObjectURL(attachment.preview);
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
            },
            onError: (errors) => {
                const errorMessage = Object.values(errors).flat().join(', ') || 'Cannot delete ticket';
                showError(errorMessage);
            }
        });
    }
};

const priorityMap = {
    'urgent': 'P1',
    'high': 'P2',
    'medium': 'P3',
    'low': 'P4'
};

const getPriorityLabel = (priority) => {
    const p = String(priority || '').toLowerCase();
    return priorityMap[p] ? `${priorityMap[p]} ${p}` : p;
};

const getPriorityColor = (priority) => {
    switch (priority) {
        case 'urgent': return 'text-red-900 bg-red-200';
        case 'high': return 'text-red-800 bg-red-100';
        case 'medium': return 'text-yellow-900 bg-yellow-200';
        case 'low': return 'text-green-900 bg-green-200';
        default: return 'text-gray-800 bg-gray-100';
    }
};

const getStatusColor = (status) => {
    switch (status) {
        case 'open': return 'text-blue-800 bg-blue-100';
        case 'in_progress': return 'text-purple-800 bg-purple-100';
        case 'resolved': return 'text-green-800 bg-green-100';
        case 'closed': return 'text-gray-600 bg-gray-200';
        case 'waiting_service_provider': return 'text-orange-800 bg-orange-100';
        case 'waiting_client_feedback': return 'text-blue-800 bg-blue-100';
        default: return 'text-gray-800 bg-gray-100';
    }
};

const getStatusLabel = (status) => {
    switch (status) {
        case 'waiting_service_provider': return 'Waiting for service provider';
        case 'waiting_client_feedback': return 'Waiting for clients feedback?';
        default: return status.replace('_', ' ');
    }
};

const formatFileSize = (bytes) => {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
};

const linkify = (text) => {
    if (!text) return '';
    const escaped = text
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    
    const urlRegex = /(https?:\/\/[^\s]+)/g;
    return escaped.replace(urlRegex, (url) => {
        return `<a href="${url}" target="_blank" class="text-blue-600 hover:underline break-all">${url}</a>`;
    });
};
</script>

<template>
    <Head :title="`Edit Ticket ${ticket.ticket_key}`" />

    <AppLayout>
        <template #header>
            <div class="flex items-center space-x-4">
                <Link :href="route('tickets.index')" class="text-blue-600 hover:text-blue-800 flex-shrink-0 transition-colors">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                    </svg>
                </Link>
                <div class="flex flex-col">
                    <h1 class="text-lg font-bold tracking-tight">
                        <span class="text-blue-600">{{ ticket.ticket_key }}</span> <span class="text-gray-900 truncate max-w-[200px] sm:max-w-none">{{ ticket.title }}</span>
                    </h1>
                </div>
            </div>
        </template>

        <div>
            <div class="flex flex-col lg:grid lg:grid-cols-3 gap-6">
                <!-- Right Column (Metadata) moved to TOP on mobile -->
                <div class="lg:col-span-1 space-y-6 order-1 lg:order-2">
                    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4 sm:p-6 space-y-6 lg:sticky lg:top-6">
                        <div class="space-y-4 sm:space-y-6">
                            <div>
                                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Company</label>
                                <CustomSelect
                                    v-model="editForm.company_id"
                                    :options="companies"
                                    label-key="name"
                                    value-key="id"
                                    placeholder="Select Company"
                                    :disabled="!hasPermission('tickets.edit')"
                                >
                                    <template #option="{ option }">
                                        <div class="flex items-center">
                                            <div class="h-6 w-6 rounded bg-gray-200 flex items-center justify-center text-xs font-bold text-gray-600 mr-2">
                                                {{ option.name.charAt(0) }}
                                            </div>
                                            <span>{{ option.name }}</span>
                                        </div>
                                    </template>
                                    <template #trigger="{ selected }">
                                        <div v-if="selected" class="flex items-center">
                                            <div class="h-5 w-5 rounded bg-gray-200 flex items-center justify-center text-[10px] font-bold text-gray-600 mr-2">
                                                {{ selected.name.charAt(0) }}
                                            </div>
                                            <span class="text-sm">{{ selected.name }}</span>
                                        </div>
                                        <span v-else class="text-gray-400 text-sm">Select Company</span>
                                    </template>
                                </CustomSelect>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-1 gap-4 sm:gap-6">
                                <div>
                                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Store</label>
                                    <Autocomplete 
                                        v-model="editForm.store_id"
                                        :options="stores"
                                        label-key="name"
                                        value-key="id"
                                        placeholder="Select store..."
                                        :disabled="!hasPermission('tickets.edit')"
                                    />
                                </div>

                                <div>
                                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Category</label>
                                    <Autocomplete 
                                        v-model="editForm.category_id"
                                        :options="categories"
                                        label-key="name"
                                        value-key="id"
                                        placeholder="Select category..."
                                        :disabled="!hasPermission('tickets.edit')"
                                    />
                                </div>

                                <div>
                                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Sub-Category</label>
                                    <Autocomplete 
                                        v-model="editForm.sub_category_id"
                                        :options="subCategories"
                                        label-key="name"
                                        value-key="id"
                                        placeholder="Select sub-category..."
                                        :disabled="!hasPermission('tickets.edit') || !editForm.category_id"
                                    />
                                </div>

                                <div>
                                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Item</label>
                                    <Autocomplete 
                                        v-model="editForm.item_id"
                                        :options="items"
                                        label-key="name"
                                        value-key="id"
                                        placeholder="Select item..."
                                        :disabled="!hasPermission('tickets.edit') || !editForm.sub_category_id"
                                    />
                                </div>
                            </div>

                            <div class="grid grid-cols-2 gap-4 lg:grid-cols-1 lg:gap-6">
                                <div>
                                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Status</label>
                                    <CustomSelect
                                        v-model="editForm.status"
                                        :options="availableStatuses"
                                        placeholder="Select Status"
                                        :disabled="!hasPermission('tickets.edit') && !hasPermission('tickets.close')"
                                    >
                                        <template #option="{ option }">
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-black capitalize border" :class="getStatusColor(option)">
                                                {{ getStatusLabel(option) }}
                                            </span>
                                        </template>
                                        <template #trigger="{ selected }">
                                            <span v-if="selected" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-black capitalize border" :class="getStatusColor(selected)">
                                                {{ getStatusLabel(selected) }}
                                            </span>
                                            <span v-else class="text-gray-400 text-sm">Select Status</span>
                                        </template>
                                    </CustomSelect>
                                </div>

                                <div v-if="editForm.priority" class="p-3 bg-gray-50 rounded-lg border border-gray-100">
                                    <label class="block text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-1">Priority</label>
                                    <span class="inline-flex px-2.5 py-0.5 rounded-full text-xs font-bold capitalize shadow-sm" :class="getPriorityColor(editForm.priority)">
                                        {{ getPriorityLabel(editForm.priority) }}
                                    </span>
                                </div>
                            </div>
                        </div>

                        <!-- SLA Widget -->
                        <div v-if="ticket.sla_metric" class="pt-6 border-t space-y-4">
                            <h3 class="text-xs font-bold text-gray-400 uppercase tracking-widest">Ticket SLA</h3>
                            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-1 gap-3">
                                <!-- Response SLA -->
                                <div class="p-3 rounded-lg border" :class="ticket.sla_metric.is_response_breached ? 'bg-red-50 border-red-100' : (ticket.sla_metric.first_response_at ? 'bg-green-50 border-green-100' : 'bg-gray-50 border-gray-100')">
                                    <div class="flex justify-between items-center mb-1">
                                        <span class="text-[9px] font-black text-gray-500 uppercase">Response</span>
                                        <span v-if="ticket.sla_metric.is_response_breached" class="text-[9px] font-black text-red-600 uppercase">BREACHED</span>
                                        <span v-else-if="ticket.sla_metric.first_response_at" class="text-[9px] font-black text-green-600 uppercase">MET</span>
                                        <span v-else class="text-[9px] font-black text-blue-600 uppercase">ACTIVE</span>
                                    </div>
                                    <div class="text-[11px] font-bold text-gray-900 truncate">
                                        {{ ticket.sla_metric.first_response_at ? formatDate(ticket.sla_metric.first_response_at) : (ticket.sla_metric.response_target_at ? formatDate(ticket.sla_metric.response_target_at) : 'No target') }}
                                    </div>
                                </div>

                                <!-- Resolution SLA -->
                                <div class="p-3 rounded-lg border" :class="ticket.sla_metric.is_resolution_breached ? 'bg-red-50 border-red-100' : (ticket.sla_metric.resolved_at ? 'bg-green-50 border-green-100' : 'bg-gray-50 border-gray-100')">
                                    <div class="flex justify-between items-center mb-1">
                                        <span class="text-[9px] font-black text-gray-500 uppercase">Resolution</span>
                                        <span v-if="ticket.sla_metric.is_resolution_breached" class="text-[9px] font-black text-red-600 uppercase">BREACHED</span>
                                        <span v-else-if="ticket.sla_metric.resolved_at" class="text-[9px] font-black text-green-600 uppercase">MET</span>
                                        <span v-else class="text-[9px] font-black text-blue-600 uppercase">ACTIVE</span>
                                    </div>
                                    <div class="text-[11px] font-bold text-gray-900 truncate">
                                        {{ ticket.sla_metric.resolved_at ? formatDate(ticket.sla_metric.resolved_at) : (ticket.sla_metric.resolution_target_at ? formatDate(ticket.sla_metric.resolution_target_at) : 'No target') }}
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div v-if="hasPermission('tickets.assign')">
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Assignee</label>
                            <CustomSelect
                                v-model="editForm.assignee_id"
                                :options="staff"
                                label-key="name"
                                value-key="id"
                                placeholder="Unassigned"
                            >
                                <template #option="{ option }">
                                    <div class="flex items-center">
                                        <div class="h-6 w-6 rounded-full bg-blue-100 flex items-center justify-center text-xs font-bold text-blue-700 mr-2">
                                            {{ option.name.charAt(0) }}
                                        </div>
                                        <span>{{ option.name }}</span>
                                    </div>
                                </template>
                                <template #trigger="{ selected }">
                                    <div v-if="selected" class="flex items-center">
                                        <div class="h-5 w-5 rounded-full bg-blue-100 flex items-center justify-center text-[10px] font-bold text-blue-700 mr-2">
                                            {{ selected.name.charAt(0) }}
                                        </div>
                                        <span class="text-sm">{{ selected.name }}</span>
                                    </div>
                                    <span v-else class="text-gray-500 italic text-sm">Unassigned</span>
                                </template>
                            </CustomSelect>
                        </div>

                        <div class="pt-6 border-t space-y-3">
                            <button 
                                v-if="hasPermission('tickets.edit') && (ticket.status === 'open' || ticket.status === 'in_progress')"
                                type="button" 
                                @click="openChildModal" 
                                class="w-full flex justify-center py-2 px-4 border border-blue-600 rounded-md text-sm font-black text-blue-600 bg-white hover:bg-blue-50 transition-colors uppercase tracking-widest"
                            >
                                Create Child Ticket
                            </button>
                            
                            <button v-if="hasPermission('tickets.delete')" type="button" @click="deleteTicket" class="w-full flex justify-center py-2 px-4 border border-transparent rounded-md text-sm font-black text-red-600 bg-red-50 hover:bg-red-100 transition-colors uppercase tracking-widest">
                                Delete Ticket
                            </button>
                        </div>
                    </div>

                    <!-- Children Tickets -->
                    <div v-if="ticket.children && ticket.children.length > 0" class="bg-white rounded-lg shadow-sm border border-gray-200 p-4 sm:p-6 space-y-4">
                        <h3 class="text-sm font-black text-gray-900 uppercase tracking-widest">Child Tickets</h3>
                        <div class="space-y-3">
                            <div v-for="child in ticket.children" :key="child.id" class="flex items-center justify-between p-3 bg-gray-50 rounded-lg border border-gray-100 gap-3">
                                <div class="flex flex-col min-w-0 flex-1">
                                    <Link :href="route('tickets.edit', child.id)" class="text-sm font-black text-blue-600 hover:underline truncate">
                                        {{ child.ticket_key }}
                                    </Link>
                                    <span class="text-[10px] text-gray-600 truncate">{{ child.title }}</span>
                                </div>
                                <div class="flex flex-col items-end gap-1 shrink-0">
                                    <span class="px-2 py-0.5 rounded-full text-[9px] font-black uppercase tracking-tighter" :class="getStatusColor(child.status)">
                                        {{ child.status }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Left Column: Content & History -->
                <div class="lg:col-span-2 space-y-6 order-2 lg:order-1">
                    
                    <!-- Title Section -->
                    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4 sm:p-6 transition-all duration-200 hover:shadow-md">
                        <div v-if="!isEditingTitle" 
                             class="group relative -m-2 p-2 rounded-lg hover:bg-gray-50 transition-colors duration-200">
                            <h1 class="text-xl sm:text-3xl font-bold text-gray-900 leading-tight tracking-tight">
                                {{ ticket.title }}
                            </h1>
                            <div v-if="hasPermission('tickets.edit')" 
                                 class="absolute top-2 right-2 opacity-0 group-hover:opacity-100 transition-opacity duration-200 cursor-pointer"
                                 @click="startEditingTitle"
                                 title="Edit Title">
                                <span class="inline-flex items-center px-2 py-1 rounded text-[10px] font-medium bg-blue-50 text-blue-700 hover:bg-blue-100">
                                    <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" /></svg>
                                    Edit
                                </span>
                            </div>
                        </div>
                        <div v-else>
                             <input 
                                ref="titleInput"
                                v-model="editForm.title" 
                                type="text" 
                                maxlength="255"
                                class="block w-full text-lg sm:text-3xl font-bold text-gray-900 leading-tight border-0 border-b-2 border-blue-500 focus:ring-0 focus:border-blue-600 px-0 py-1 bg-transparent placeholder-gray-300"
                                placeholder="Enter ticket title..."
                                @blur="saveTitle"
                                @keydown.enter="saveTitle"
                                @keydown.esc="cancelTitleEdit"
                            >
                            <div class="mt-2 text-[10px] text-gray-500 flex justify-end">Press Enter to save, Esc to cancel</div>
                        </div>
                    </div>

                    <!-- Activity / Timeline -->
                    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4 sm:p-6 pb-0 relative">
                        <h3 class="text-lg font-black text-gray-900 mb-6 uppercase tracking-widest">Activity Timeline</h3>

                        <!-- Timeline List -->
                        <div class="relative pl-4 border-l-2 border-gray-200 space-y-8 mb-8 pb-6">
                            <!-- Loop Activities (Comments + History + Description) -->
                            <div v-for="activity in activities" :key="activity.activity_type + '-' + activity.id" class="relative">
                                
                                <!-- Description Item (Inline Editable) -->
                                <template v-if="activity.activity_type === 'description'">
                                    <!-- Dot (Avatar) -->
                                    <div class="absolute -left-[25px] top-0 w-6 h-6 rounded-full border-2 border-white shadow-sm overflow-hidden bg-white">
                                        <img v-if="activity.user && activity.user.profile_photo" :src="'/storage/' + activity.user.profile_photo" class="w-full h-full object-cover" :alt="activity.user.name">
                                        <div v-else class="w-full h-full bg-blue-500 flex items-center justify-center text-[10px] font-bold text-white">
                                            {{ activity.user ? activity.user.name.charAt(0) : '?' }}
                                        </div>
                                    </div>
                                    
                                    <div class="flex flex-col sm:flex-row sm:justify-between sm:items-start mb-1 gap-1">
                                        <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                                            <span class="font-bold text-gray-900 text-sm">
                                                {{ activity.user ? activity.user.name : (activity.sender_name || 'External User') }}
                                            </span>
                                            <span class="text-[10px] sm:text-xs text-gray-500 font-medium">
                                                on {{ formatDate(activity.date) }}
                                            </span>
                                        </div>
                                    </div>
                                    
                                    <!-- Editable Description Area -->
                                    <div v-if="!isEditingDescription" 
                                         class="group relative border border-transparent rounded p-2 -ml-2 hover:bg-gray-50 hover:border-gray-200 transition-colors">
                                        <div class="text-gray-700 whitespace-pre-wrap text-sm sm:text-base leading-relaxed" v-html="linkify(activity.text)"></div>
                                        <div v-if="hasPermission('tickets.edit')" 
                                             class="absolute top-2 right-2 opacity-0 group-hover:opacity-100 text-gray-400 cursor-pointer hover:text-blue-600 transition-colors"
                                             @click="startEditingDescription"
                                             title="Edit Description">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" /></svg>
                                        </div>
                                    </div>
                                    <div v-else>
                                        <textarea 
                                            ref="descriptionInput"
                                            v-model="editForm.description" 
                                            rows="6" 
                                            maxlength="65535"
                                            class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 mb-2 text-sm sm:text-base"
                                            @keydown.esc="cancelDescriptionEdit"
                                        ></textarea>
                                        <div class="flex justify-end space-x-2">
                                            <button @click="cancelDescriptionEdit" class="px-3 py-1 text-sm text-gray-600 hover:text-gray-800">Cancel</button>
                                            <button @click="saveDescription" class="px-3 py-1 text-sm bg-blue-600 text-white rounded hover:bg-blue-700">Save</button>
                                        </div>
                                    </div>

                                    <!-- Description Attachments -->
                                    <div v-if="activity.attachments && activity.attachments.length > 0" class="mt-3 grid grid-cols-2 sm:grid-cols-4 gap-3">
                                        <div v-for="attachment in activity.attachments" :key="attachment.id" class="relative group border border-gray-200 rounded-lg overflow-hidden hover:shadow-md transition bg-white">
                                            <div v-if="isImage(attachment.file_name) && !failedImages.has(attachment.id)" 
                                                 class="aspect-w-16 aspect-h-9 bg-gray-100 cursor-pointer relative"
                                                 @click="openImageViewer(attachment)">
                                                <img :src="getThumbnailUrl(attachment)" 
                                                     class="object-cover w-full h-24 sm:h-32" 
                                                     :alt="attachment.file_name"
                                                     @error="handleImageError(attachment.id)">
                                            </div>
                                            <div v-else class="h-24 sm:h-32 flex flex-col items-center justify-center p-4 bg-gray-50">
                                                <svg class="w-8 h-8 text-gray-400 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" /></svg>
                                                <span class="text-[10px] text-gray-500 text-center truncate w-full px-2">{{ attachment.file_name }}</span>
                                            </div>
                                        </div>
                                    </div>
                                </template>

                                <!-- Comment Item -->
                                <template v-else-if="activity.activity_type === 'comment'">
                                    <!-- Dot (Avatar) -->
                                    <div class="absolute -left-[25px] top-0 w-6 h-6 rounded-full border-2 border-white shadow-sm overflow-hidden bg-white">
                                        <img v-if="activity.user && activity.user.profile_photo" :src="'/storage/' + activity.user.profile_photo" class="w-full h-full object-cover" :alt="activity.user.name">
                                        <div v-else class="w-full h-full bg-blue-100 flex items-center justify-center text-[10px] font-bold text-blue-600">
                                            {{ activity.user ? activity.user.name.charAt(0) : (activity.sender_name ? activity.sender_name.charAt(0) : '?') }}
                                        </div>
                                    </div>
                                    
                                    <div class="flex items-center space-x-2 mb-1">
                                        <span class="font-bold text-gray-900 text-sm">
                                            {{ activity.user ? activity.user.name : (activity.sender_name || activity.sender_email || 'External User') }}
                                        </span>
                                        <span class="text-[10px] sm:text-xs text-gray-500 font-medium">{{ formatDate(activity.date) }}</span>
                                    </div>
                                    
                                    <div class="text-gray-700 whitespace-pre-wrap mb-2 text-sm sm:text-base leading-relaxed" v-html="linkify(activity.comment_text)"></div>

                                    <!-- Comment Attachments -->
                                    <div v-if="activity.attachments && activity.attachments.length > 0" class="mt-3 grid grid-cols-2 sm:grid-cols-4 gap-3">
                                        <div v-for="attachment in activity.attachments" :key="attachment.id" class="relative group border border-gray-200 rounded-lg overflow-hidden hover:shadow-md transition bg-white">
                                            <div v-if="isImage(attachment.file_name) && !failedImages.has(attachment.id)" 
                                                 class="aspect-w-16 aspect-h-9 bg-gray-100 cursor-pointer relative"
                                                 @click="openImageViewer(attachment)">
                                                <img :src="getThumbnailUrl(attachment)" 
                                                     class="object-cover w-full h-24 sm:h-32" 
                                                     :alt="attachment.file_name"
                                                     @error="handleImageError(attachment.id)">
                                            </div>
                                            <div v-else class="h-24 sm:h-32 flex flex-col items-center justify-center p-4 bg-gray-50">
                                                <svg class="w-8 h-8 text-gray-400 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" /></svg>
                                                <span class="text-[10px] text-gray-500 text-center truncate w-full px-2">{{ attachment.file_name }}</span>
                                            </div>
                                        </div>
                                    </div>
                                </template>

                                <!-- History Item -->
                                <template v-else-if="activity.activity_type === 'history'">
                                    <!-- Dot (Avatar) -->
                                    <div class="absolute -left-[25px] top-0 w-6 h-6 rounded-full border-2 border-white shadow-sm overflow-hidden bg-white">
                                        <img v-if="activity.user && activity.user.profile_photo" :src="'/storage/' + activity.user.profile_photo" class="w-full h-full object-cover" :alt="activity.user.name">
                                        <div v-else class="w-full h-full bg-gray-400 flex items-center justify-center text-[10px] font-bold text-white">
                                            {{ activity.user ? activity.user.name.charAt(0) : '?' }}
                                        </div>
                                    </div>
                                    
                                    <div class="flex items-center space-x-2 mb-1">
                                        <span class="font-bold text-gray-900 text-sm">
                                            {{ activity.user ? activity.user.name : (ticket.reporter ? ticket.reporter.name : (ticket.sender_name || 'Customer')) }}
                                        </span>
                                        <span class="text-[10px] text-gray-500">{{ formatDate(activity.date) }}</span>
                                    </div>
                                    
                                    <div class="text-xs sm:text-sm text-gray-600 bg-gray-50 p-2 rounded border border-gray-100 leading-relaxed">
                                        Changed <span class="font-black text-gray-800">{{ formatColumnName(activity.column_changed) }}</span> 
                                        from <span class="font-bold text-red-600 bg-red-50 px-1 rounded line-through decoration-red-400">
                                            {{ activity.column_changed === 'assignee_id' && !activity.old_value ? 'Unassigned' : (activity.old_value || '(empty)') }}
                                        </span> 
                                        to <span class="font-bold text-green-600 bg-green-50 px-1 rounded">
                                            {{ activity.column_changed === 'assignee_id' && !activity.new_value ? 'Unassigned' : (activity.new_value || '(empty)') }}
                                        </span>
                                    </div>
                                </template>

                                <!-- Child Ticket Item -->
                                <template v-else-if="activity.activity_type === 'child_ticket'">
                                    <!-- Dot (Avatar) -->
                                    <div class="absolute -left-[25px] top-0 w-6 h-6 rounded-full border-2 border-white shadow-sm overflow-hidden bg-white">
                                        <div class="w-full h-full bg-purple-500 flex items-center justify-center text-[10px] font-bold text-white">
                                            {{ activity.user ? activity.user.name.charAt(0) : '?' }}
                                        </div>
                                    </div>
                                    
                                    <div class="flex flex-col sm:flex-row sm:items-center sm:space-x-2 mb-1">
                                        <span class="font-bold text-gray-900 text-sm">{{ activity.user ? activity.user.name : 'Unknown User' }}</span>
                                        <span class="text-[10px] sm:text-xs text-gray-500 font-medium">created child <Link :href="route('tickets.edit', activity.id)" class="font-black text-blue-600 hover:underline">{{ activity.ticket_key }}</Link> on {{ formatDate(activity.date) }}</span>
                                    </div>
                                </template>
                            </div>

                        </div>

                        <!-- Sticky Comment Input -->
                        <div v-if="ticket.status !== 'closed'" class="sticky bottom-0 z-10 -mx-4 sm:-mx-6 -mb-0 p-4 sm:p-6 bg-blue-50/95 backdrop-blur-sm border-t-2 border-blue-200 shadow-[0_-8px_15px_-3px_rgba(0,0,0,0.1)] rounded-b-lg">
                            <div class="flex space-x-3 sm:space-x-4">
                                <div class="flex-shrink-0 hidden xs:block">
                                    <div v-if="$page.props.auth.user.profile_photo" class="w-8 h-8 sm:w-10 sm:h-10 rounded-full overflow-hidden border-2 border-white shadow-sm">
                                        <img :src="'/storage/' + $page.props.auth.user.profile_photo" class="h-full w-full object-cover" :alt="$page.props.auth.user.name">
                                    </div>
                                    <div v-else class="w-8 h-8 sm:w-10 sm:h-10 rounded-full bg-blue-600 flex items-center justify-center text-white font-bold shadow-sm">
                                        {{ $page.props.auth.user.name.charAt(0) }}
                                    </div>
                                </div>
                                <div class="flex-grow">
                                    <div class="bg-white border-2 border-blue-100 rounded-xl shadow-sm focus-within:ring-2 focus-within:ring-blue-400 focus-within:border-blue-400 transition-all duration-200">
                                        <textarea 
                                            v-model="commentForm.comment_text" 
                                            rows="2" 
                                            maxlength="65535"
                                            class="block w-full border-0 focus:ring-0 resize-y bg-transparent p-3 text-sm sm:text-base text-gray-700 placeholder-gray-400" 
                                            placeholder="Write your response..."
                                            @paste="handlePaste"
                                        ></textarea>
                                        
                                        <!-- Attachment Preview -->
                                        <div v-if="commentForm.attachments.length > 0" class="px-3 pb-3 flex flex-nowrap overflow-x-auto gap-3 border-t border-blue-50 pt-3 custom-scrollbar scrollbar-hide">
                                            <div v-for="(attachment, index) in commentForm.attachments" :key="attachment.id" class="relative group border border-gray-200 rounded-lg overflow-hidden hover:shadow-md transition bg-white flex-shrink-0 w-24">
                                                <div v-if="isImage(attachment.file_name) && attachment.preview" 
                                                     class="aspect-w-16 aspect-h-9 bg-gray-100 cursor-pointer relative"
                                                     @click="openImageViewer(attachment)">
                                                    <img :src="attachment.preview" class="object-cover w-full h-24" :alt="attachment.file_name">
                                                </div>
                                                <div v-else class="h-24 flex flex-col items-center justify-center p-2 bg-gray-50">
                                                    <svg class="w-8 h-8 text-gray-400 mb-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" /></svg>
                                                    <span class="text-[9px] text-gray-500 text-center truncate w-full px-1">{{ attachment.file_name }}</span>
                                                </div>
                                                <button type="button" @click="removeCommentAttachment(index)" class="absolute top-1 right-1 p-1 bg-red-500 text-white rounded-full shadow-sm hover:bg-red-600">
                                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                                </button>
                                            </div>
                                        </div>

                                        <div class="flex flex-col sm:flex-row sm:items-center justify-between px-3 py-2 border-t border-blue-50 bg-blue-50/50 rounded-b-xl gap-3">
                                            <div class="flex items-center space-x-2">
                                                <input ref="commentFileInput" type="file" multiple class="hidden" @change="handleCommentFileSelect">
                                                <button type="button" @click="commentFileInput.click()" class="p-1.5 text-blue-600 hover:text-blue-800 rounded-lg hover:bg-blue-100 transition-all">
                                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13" /></svg>
                                                </button>
                                                <div class="relative">
                                                    <button v-if="cannedMessages?.length > 0" type="button" @click="showCannedMessages = !showCannedMessages" class="p-1.5 text-orange-600 hover:text-orange-800 rounded-lg hover:bg-orange-100 transition-all">
                                                        <ChatBubbleBottomCenterTextIcon class="w-5 h-5" />
                                                    </button>
                                                    <div v-if="showCannedMessages" class="absolute bottom-full left-0 mb-2 w-72 bg-white rounded-lg shadow-xl border border-gray-200 z-50 overflow-hidden">
                                                        <div class="p-2 border-b bg-gray-50 flex justify-between items-center">
                                                            <span class="text-xs font-bold text-gray-700 uppercase tracking-wider">Canned Messages</span>
                                                            <button @click="showCannedMessages = false" class="text-gray-400 hover:text-gray-600">
                                                                <XMarkIcon class="w-4 h-4" />
                                                            </button>
                                                        </div>
                                                        <div class="max-h-60 overflow-y-auto">
                                                            <button 
                                                                v-for="message in cannedMessages" 
                                                                :key="message.id"
                                                                @click="applyCannedMessage(message)"
                                                                class="w-full text-left px-4 py-3 hover:bg-blue-50 border-b border-gray-50 last:border-0 transition-colors"
                                                            >
                                                                <div class="font-bold text-xs text-blue-700 mb-1">{{ message.title }}</div>
                                                                <div class="text-[10px] text-gray-600 line-clamp-2">{{ message.content }}</div>
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="flex items-center w-full sm:w-auto">
                                                <div class="inline-flex rounded-lg shadow-md divide-x divide-blue-500 w-full sm:w-auto">
                                                    <button 
                                                        type="button" 
                                                        @click="submitWithStatus('')" 
                                                        :disabled="commentForm.processing || (!commentForm.comment_text.trim() && commentForm.attachments.length === 0)"
                                                        class="inline-flex items-center justify-center flex-1 sm:flex-none px-4 py-2 text-xs sm:text-sm font-black rounded-l-lg text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 disabled:opacity-50 transition-all active:transform active:scale-95 whitespace-nowrap uppercase tracking-widest"
                                                    >
                                                        <span v-if="commentForm.processing">Saving...</span>
                                                        <span v-else>Send as Response Only</span>
                                                    </button>
                                                    <div class="relative">
                                                        <button 
                                                            type="button"
                                                            @click="showStatusDropdown = !showStatusDropdown"
                                                            :disabled="commentForm.processing"
                                                            class="inline-flex items-center p-2 text-sm font-bold rounded-r-lg text-white bg-blue-600 hover:bg-blue-700 focus:outline-none transition-all"
                                                        >
                                                            <ChevronDownIcon class="w-5 h-5" />
                                                        </button>
                                                        <div v-if="showStatusDropdown" class="absolute bottom-full right-0 mb-2 w-64 bg-white rounded-lg shadow-xl border border-gray-200 z-50 overflow-hidden">
                                                            <div class="py-1">
                                                                <button v-for="s in availableStatuses" :key="s" @click="submitWithStatus(s)" :class="['w-full text-left px-4 py-2 text-[10px] font-black uppercase tracking-widest transition-colors hover:opacity-80', getStatusColor(s)]">
                                                                    Send and set as {{ getStatusLabel(s) }}
                                                                </button>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Create Child Ticket Modal -->
        <Modal :show="showChildModal" max-width="2xl" @close="showChildModal = false">
            <div class="p-4 sm:p-6">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-lg font-black text-gray-900 leading-none uppercase tracking-widest">
                        Create Child Ticket
                    </h3>
                    <button @click="showChildModal = false" class="text-gray-400 hover:text-gray-600">
                        <XMarkIcon class="w-6 h-6" />
                    </button>
                </div>

                <form @submit.prevent="submitChildTicket" class="space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Assigned User</label>
                            <Autocomplete v-model="childForm.user_id" :options="users" label-key="name" value-key="id" placeholder="Select user..." />
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Schedule Status</label>
                            <select v-model="childForm.status" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
                                <option v-for="status in scheduleStatuses" :key="status" :value="status">{{ status }}</option>
                            </select>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Start Time</label>
                            <input v-model="childForm.start_time" type="datetime-local" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">End Time</label>
                            <input v-model="childForm.end_time" type="datetime-local" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Remarks</label>
                        <textarea v-model="childForm.remarks" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" placeholder="Activity details..."></textarea>
                    </div>

                    <div class="flex flex-col-reverse sm:flex-row justify-end gap-3 pt-4 border-t">
                        <button type="button" @click="showChildModal = false" class="px-4 py-2 text-sm font-bold text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors uppercase tracking-widest">
                            Cancel
                        </button>
                        <button type="submit" class="px-6 py-2 text-sm font-black text-white bg-blue-600 rounded-lg hover:bg-blue-700 shadow-md transition-all active:scale-95 uppercase tracking-widest">
                            Create Ticket
                        </button>
                    </div>
                </form>
            </div>
        </Modal>

        <!-- Image Viewer Modal -->
        <Modal :show="showImageViewer" max-width="4xl" @close="closeImageViewer">
            <div class="relative bg-black rounded-lg overflow-hidden h-[80vh] flex flex-col">
                <!-- Toolbar -->
                <div class="absolute top-0 left-0 right-0 z-10 flex justify-between items-center p-4 bg-gradient-to-b from-black/50 to-transparent">
                    <h3 class="text-white text-xs sm:text-sm font-medium truncate ml-2 text-shadow">{{ currentImage?.file_name }}</h3>
                    <div class="flex items-center space-x-2">
                        <button @click="handleZoom(-0.1)" class="p-1 sm:p-2 text-white hover:bg-white/20 rounded-full backdrop-blur-sm">
                            <svg class="w-5 h-5 sm:w-6 sm:h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4" /></svg>
                        </button>
                        <span class="text-white text-[10px] sm:text-sm font-mono w-8 sm:w-12 text-center">{{ Math.round(zoomLevel * 100) }}%</span>
                        <button @click="handleZoom(0.1)" class="p-1 sm:p-2 text-white hover:bg-white/20 rounded-full backdrop-blur-sm">
                            <svg class="w-5 h-5 sm:w-6 sm:h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" /></svg>
                        </button>
                        <div class="w-px h-6 bg-white/30 mx-1 sm:mx-2"></div>
                        <button @click="closeImageViewer" class="p-1 sm:p-2 text-white hover:bg-red-500/80 rounded-full backdrop-blur-sm transition-colors">
                            <svg class="w-5 h-5 sm:w-6 sm:h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>
                </div>

                <!-- Image Container -->
                <div class="flex-grow flex items-center justify-center overflow-hidden cursor-move p-4 relative" 
                     @mousedown.prevent="isDragging = true" 
                     @mouseup="isDragging = false" 
                     @mouseleave="isDragging = false"
                     @mousemove="isDragging && (panOffset.x += $event.movementX, panOffset.y += $event.movementY)"
                     @wheel.prevent="handleWheel">
                    
                    <!-- Navigation Arrows -->
                    <button v-if="allImages.length > 1" @click.stop="navigateImage(-1)" class="absolute left-2 sm:left-4 top-1/2 transform -translate-y-1/2 p-2 sm:p-3 text-white/70 hover:text-white bg-black/20 hover:bg-black/40 rounded-full backdrop-blur-sm transition-all z-20">
                        <svg class="w-6 h-6 sm:w-8 sm:h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" /></svg>
                    </button>
                    <button v-if="allImages.length > 1" @click.stop="navigateImage(1)" class="absolute right-2 sm:right-4 top-1/2 transform -translate-y-1/2 p-2 sm:p-3 text-white/70 hover:text-white bg-black/20 hover:bg-black/40 rounded-full backdrop-blur-sm transition-all z-20">
                        <svg class="w-6 h-6 sm:w-8 sm:h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
                    </button>

                    <img v-if="currentImage" :src="getThumbnailUrl(currentImage)" class="transition-transform duration-100 ease-linear transform origin-center max-w-none shadow-2xl" :style="{ transform: `scale(${zoomLevel}) translate(${panOffset.x / zoomLevel}px, ${panOffset.y / zoomLevel}px)` }" draggable="false">
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>

<style scoped>
.scrollbar-hide::-webkit-scrollbar { display: none; }
.scrollbar-hide { -ms-overflow-style: none; scrollbar-width: none; }
</style>
