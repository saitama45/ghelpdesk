<template>
    <AppLayout title="Scheduling">
        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <Calendar 
                    :events="schedules" 
                    @date-click="handleDateClick"
                    @event-click="handleEventClick"
                >
                    <template #actions>
                        <div class="w-48 md:w-64">
                            <Autocomplete 
                                v-model="filterUser"
                                :options="userFilterOptions"
                                label-key="name"
                                value-key="id"
                                placeholder="Filter by user..."
                                @update:modelValue="applyFilter"
                            />
                        </div>
                        <button 
                            @click="exportPdf"
                            class="bg-white border border-gray-300 text-gray-700 px-4 py-2 rounded-lg text-sm font-medium hover:bg-gray-50 transition-colors flex items-center space-x-2 shadow-sm"
                        >
                            <svg class="w-4 h-4 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                            </svg>
                            <span>Export PDF</span>
                        </button>
                        <button 
                            v-if="hasPermission('schedules.create')"
                            @click="openCreateModal" 
                            class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors flex items-center space-x-2"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                            </svg>
                            <span>Create Schedule</span>
                        </button>
                    </template>
                </Calendar>
            </div>
        </div>

        <!-- Create/Edit Modal -->
        <div v-if="showModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
            <div class="relative top-10 mx-auto p-5 border w-11/12 md:w-3/4 lg:w-1/2 shadow-lg rounded-md bg-white">
                <div class="mt-3">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-lg font-bold text-gray-900">
                            {{ isEditing ? 'Edit Schedule' : 'New Schedule' }}
                        </h3>
                        <button @click="closeModal" class="text-gray-400 hover:text-gray-600 transition-colors">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <form @submit.prevent="submitForm" class="space-y-4">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">User</label>
                                <Autocomplete 
                                    v-model="form.user_id"
                                    :options="users"
                                    label-key="name"
                                    value-key="id"
                                    placeholder="Select user..."
                                />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                                <select v-model="form.status" required
                                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                                    <option v-for="status in statuses" :key="status" :value="status">{{ status }}</option>
                                </select>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Store (Optional)</label>
                                <Autocomplete 
                                    v-model="form.store_id"
                                    :options="stores"
                                    label-key="name"
                                    value-key="id"
                                    placeholder="Select store..."
                                />
                            </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Off-site Remarks / Other Activities</label>
                            <textarea v-model="form.remarks" rows="3"
                                      class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm"
                                      placeholder="Provide details about the off-site activity or other remarks..."></textarea>
                        </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Start Date & Time</label>
                                <input v-model="form.start_time" type="datetime-local" required
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">End Date & Time</label>
                                <input v-model="form.end_time" type="datetime-local" required
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                            </div>
                        </div>

                        <div class="p-4 bg-gray-50 rounded-xl space-y-4 border border-gray-100">
                            <h4 class="text-xs font-bold text-gray-500 uppercase tracking-wider">Additional Times</h4>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div class="space-y-2">
                                    <label class="block text-xs font-medium text-gray-600">Pickup Time (From - To)</label>
                                    <div class="flex items-center space-x-2">
                                        <input v-model="form.pickup_start" type="time" class="flex-1 px-3 py-1.5 border border-gray-300 rounded-lg text-sm">
                                        <span class="text-gray-400">-</span>
                                        <input v-model="form.pickup_end" type="time" class="flex-1 px-3 py-1.5 border border-gray-300 rounded-lg text-sm">
                                    </div>
                                </div>
                                <div class="space-y-2">
                                    <label class="block text-xs font-medium text-gray-600">Backlogs Time (From - To)</label>
                                    <div class="flex items-center space-x-2">
                                        <input v-model="form.backlogs_start" type="time" class="flex-1 px-3 py-1.5 border border-gray-300 rounded-lg text-sm">
                                        <span class="text-gray-400">-</span>
                                        <input v-model="form.backlogs_end" type="time" class="flex-1 px-3 py-1.5 border border-gray-300 rounded-lg text-sm">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="flex justify-end items-center pt-4 border-t">
                            <div class="flex space-x-3">
                                <button type="button" @click="closeModal" 
                                        class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200">
                                    Cancel
                                </button>
                                <button type="submit" 
                                        class="px-6 py-2 text-sm font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700 shadow-md">
                                    {{ isEditing ? 'Save Changes' : 'Create Schedule' }}
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref, reactive, onMounted, computed } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Calendar from '@/Components/Calendar.vue'
import Autocomplete from '@/Components/Autocomplete.vue'
import { useToast } from '@/Composables/useToast'
import { useConfirm } from '@/Composables/useConfirm'
import { useErrorHandler } from '@/Composables/useErrorHandler'
import { usePermission } from '@/Composables/usePermission'

const props = defineProps({
    schedules: Array,
    users: Array,
    stores: Array,
    filters: Object
})

const page = usePage()
const filterUser = ref(props.filters?.user_id || '')

const userFilterOptions = computed(() => {
    const currentUserId = page.props.auth.user.id
    const options = [
        { id: '', name: 'All Users' },
        { id: 'my', name: 'My Schedules' }
    ]
    
    props.users.forEach(user => {
        if (user.id !== currentUserId) {
            options.push({ id: user.id, name: user.name })
        }
    })
    
    return options
})

const applyFilter = () => {
    router.get(route('schedules.index'), {
        user_id: filterUser.value
    }, {
        preserveState: true,
        preserveScroll: true
    })
}

const { showSuccess, showError } = useToast()
const { confirm } = useConfirm()
const { post, put, destroy } = useErrorHandler()
const { hasPermission } = usePermission()

const exportPdf = () => {
    // Get first and last day of current visible month from the calendar state if possible, 
    // or just default to current month for now.
    const now = new Date();
    const start = new Date(now.getFullYear(), now.getMonth(), 1).toISOString().split('T')[0];
    const end = new Date(now.getFullYear(), now.getMonth() + 1, 0).toISOString().split('T')[0];
    
    window.open(route('schedules.export.pdf', { start, end }), '_blank');
};

const showModal = ref(false)
const isEditing = ref(false)
const currentScheduleId = ref(null)

const statuses = [
    'On-site', 'Off-site', 'WFH', 'SL', 'VL', 'Restday', 'Offset', 'Holiday'
]

const form = reactive({
    user_id: null,
    store_id: null,
    status: 'On-site',
    start_time: '',
    end_time: '',
    pickup_start: '',
    pickup_end: '',
    backlogs_start: '',
    backlogs_end: '',
    remarks: ''
})

const formatDateForInput = (date) => {
    const d = new Date(date);
    d.setMinutes(d.getMinutes() - d.getTimezoneOffset());
    return d.toISOString().slice(0, 16);
}

const openCreateModal = () => {
    isEditing.value = false
    currentScheduleId.value = null
    Object.keys(form).forEach(key => {
        if (key === 'status') form[key] = 'On-site'
        else if (key === 'user_id' || key === 'store_id') form[key] = null
        else form[key] = ''
    })
    
    // Set default times
    const now = new Date();
    form.start_time = formatDateForInput(now);
    const end = new Date();
    end.setHours(end.getHours() + 8);
    form.end_time = formatDateForInput(end);
    
    showModal.value = true
}

const handleDateClick = (date) => {
    if (!hasPermission('schedules.create')) return
    
    openCreateModal()
    const start = new Date(date)
    start.setHours(8, 0, 0)
    form.start_time = formatDateForInput(start)
    
    const end = new Date(date)
    end.setHours(17, 0, 0)
    form.end_time = formatDateForInput(end)
}

const handleEventClick = (event) => {
    if (!hasPermission('schedules.edit')) return
    
    isEditing.value = true
    currentScheduleId.value = event.id
    
    form.user_id = event.user_id
    form.store_id = event.store_id
    form.status = event.status
    form.start_time = formatDateForInput(new Date(event.start_time))
    form.end_time = formatDateForInput(new Date(event.end_time))
    form.pickup_start = event.pickup_start || ''
    form.pickup_end = event.pickup_end || ''
    form.backlogs_start = event.backlogs_start || ''
    form.backlogs_end = event.backlogs_end || ''
    form.remarks = event.remarks || ''
    
    showModal.value = true
}

const closeModal = () => {
    showModal.value = false
}

const submitForm = () => {
    const url = isEditing.value ? `/schedules/${currentScheduleId.value}` : '/schedules'
    const requestMethod = isEditing.value ? put : post
    
    requestMethod(url, form, {
        onSuccess: () => {
            closeModal()
            showSuccess(isEditing.value ? 'Schedule updated successfully' : 'Schedule created successfully')
        },
        onError: (errors) => {
            const errorMessage = Object.values(errors).flat().join(', ') || 'An error occurred'
            showError(errorMessage)
        }
    })
}
</script>
