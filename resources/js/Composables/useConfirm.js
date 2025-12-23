import { ref } from 'vue'

const showConfirmModal = ref(false)
const confirmTitle = ref('')
const confirmMessage = ref('')
let confirmCallback = null
let cancelCallback = null

export function useConfirm() {
    const confirm = (options = {}) => {
        return new Promise((resolve, reject) => {
            confirmTitle.value = options.title || 'Confirm Delete'
            confirmMessage.value = options.message || 'Are you sure you want to delete this item? This action cannot be undone.'
            
            confirmCallback = () => {
                showConfirmModal.value = false
                resolve(true)
            }
            
            cancelCallback = () => {
                showConfirmModal.value = false
                resolve(false)
            }
            
            showConfirmModal.value = true
        })
    }

    const handleConfirm = () => {
        if (confirmCallback) confirmCallback()
    }

    const handleCancel = () => {
        if (cancelCallback) cancelCallback()
    }

    return {
        showConfirmModal,
        confirmTitle,
        confirmMessage,
        confirm,
        handleConfirm,
        handleCancel
    }
}