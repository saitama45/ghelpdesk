<template>
    <Teleport to="body">
        <div v-if="show" class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex min-h-screen items-center justify-center p-4">
                <!-- Backdrop -->
                <div 
                    class="fixed inset-0 bg-black bg-opacity-50 transition-opacity duration-300"
                    :class="show ? 'opacity-100' : 'opacity-0'"
                    @click="cancel"
                ></div>
                
                <!-- Modal -->
                <div 
                    class="relative bg-white rounded-2xl shadow-2xl max-w-md w-full transform transition-all duration-300"
                    :class="show ? 'scale-100 opacity-100' : 'scale-95 opacity-0'"
                >
                    <!-- Icon -->
                    <div class="flex items-center justify-center w-16 h-16 mx-auto mt-8 bg-red-100 rounded-full">
                        <svg class="w-8 h-8 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z" />
                        </svg>
                    </div>
                    
                    <!-- Content -->
                    <div class="px-6 py-4 text-center">
                        <h3 class="text-xl font-semibold text-gray-900 mb-2">{{ title }}</h3>
                        <p class="text-gray-600 mb-6">{{ message }}</p>
                        
                        <!-- Actions -->
                        <div class="flex space-x-3">
                            <button 
                                @click="cancel"
                                class="flex-1 px-4 py-2.5 text-sm font-medium text-gray-700 bg-gray-100 hover:bg-gray-200 rounded-lg transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-gray-300"
                            >
                                Cancel
                            </button>
                            <button 
                                @click="confirm"
                                class="flex-1 px-4 py-2.5 text-sm font-medium text-white bg-red-600 hover:bg-red-700 rounded-lg transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-red-500"
                            >
                                Delete
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>

<script setup>
import { watch, nextTick } from 'vue'

const props = defineProps({
    show: Boolean,
    title: {
        type: String,
        default: 'Confirm Delete'
    },
    message: {
        type: String,
        default: 'Are you sure you want to delete this item? This action cannot be undone.'
    }
})

const emit = defineEmits(['confirm', 'cancel'])

const confirm = () => {
    emit('confirm')
}

const cancel = () => {
    emit('cancel')
}

// Handle escape key
watch(() => props.show, (newVal) => {
    if (newVal) {
        nextTick(() => {
            const handleEscape = (e) => {
                if (e.key === 'Escape') {
                    cancel()
                    document.removeEventListener('keydown', handleEscape)
                }
            }
            document.addEventListener('keydown', handleEscape)
        })
    }
})
</script>