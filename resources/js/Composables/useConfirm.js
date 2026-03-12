import { ref, reactive } from 'vue'

const showConfirmModal = ref(false)
const confirmState = reactive({
    title: 'Confirm Action',
    message: 'Are you sure you want to proceed?',
    confirmLabel: 'Confirm',
    cancelLabel: 'Cancel',
    variant: 'danger'
})

let confirmCallback = null
let cancelCallback = null

export function useConfirm() {
    const confirm = (options = {}) => {
        return new Promise((resolve) => {
            confirmState.title = options.title || 'Confirm Action'
            confirmState.message = options.message || 'Are you sure you want to proceed?'
            confirmState.confirmLabel = options.confirmLabel || 'Confirm'
            confirmState.cancelLabel = options.cancelLabel || 'Cancel'
            confirmState.variant = options.variant || 'danger'
            
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
        confirmState,
        confirm,
        handleConfirm,
        handleCancel
    }
}