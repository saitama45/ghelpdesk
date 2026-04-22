<template>
    <AppLayout title="Stock In">
        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <DataTable
                    title="Stock In"
                    subtitle="Manage beginning balance entries"
                    search-placeholder="Search by serial no..."
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
                            v-if="permissions.create"
                            @click="openCreateModal" 
                            class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors flex items-center space-x-2 shadow-sm whitespace-nowrap"
                        >
                            <PlusIcon class="w-4 h-4" />
                            <span>Add Stock</span>
                        </button>
                    </template>

                    <template #header>
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Receive Date</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Item Code</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Description</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Serial No</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Qty</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Warranty</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">EOL</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Location</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                        </tr>
                    </template>

                    <template #body="{ data }">
                        <tr v-for="item in data" :key="item.id" class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ formatDate(item.receive_date) }}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 font-mono">{{ item.asset?.item_code }}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ item.asset?.description }}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 font-mono">{{ item.serial_no || '-' }}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ item.quantity }}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                <div class="flex flex-col">
                                    <span>{{ item.warranty_months }} months</span>
                                    <span class="text-[10px] text-gray-500">{{ formatDate(item.warranty_date) }}</span>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                <div class="flex flex-col">
                                    <span>{{ item.eol_months }} months</span>
                                    <span class="text-[10px] text-gray-500">{{ formatDate(item.eol_date) }}</span>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ item.location || '-' }}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                <div class="flex justify-end space-x-2">
                                    <button v-if="permissions.edit" @click="editItem(item)" class="text-blue-600 hover:text-blue-900">Edit</button>
                                    <button v-if="permissions.delete" @click="deleteItem(item)" class="text-red-600 hover:text-red-900">Delete</button>
                                </div>
                            </td>
                        </tr>
                    </template>
                </DataTable>
            </div>
        </div>

        <!-- Modal -->
        <Modal :show="showModal" @close="closeModal" max-width="2xl">
            <div class="p-6">
                <h3 class="text-lg font-bold text-gray-900 mb-4">{{ isEditing ? 'Edit Stock In' : 'Add Stock In' }}</h3>
                <form @submit.prevent="submitForm" class="space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Receive Date</label>
                            <input type="date" v-model="form.receive_date" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Asset (Item Code)</label>
                            <select v-model="form.asset_id" required @change="onAssetChange" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                                <option value="">Select Asset</option>
                                <option v-for="asset in assets" :key="asset.id" :value="asset.id">{{ asset.item_code }} - {{ asset.description }}</option>
                            </select>
                        </div>
                    </div>

                    <div class="grid grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Quantity</label>
                            <input type="number" v-model="form.quantity" required min="1" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                        </div>
                        <div class="col-span-2">
                            <label class="block text-sm font-medium text-gray-700">Serial No</label>
                            <input type="text" v-model="form.serial_no" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div class="p-3 bg-gray-50 rounded-lg border border-gray-200">
                            <label class="block text-xs font-bold text-gray-500 uppercase mb-2">Warranty (Months)</label>
                            <input type="number" v-model="form.warranty_months" required min="0" class="block w-full rounded-md border-gray-300 shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                            <p class="mt-1 text-[10px] text-blue-600 font-medium italic">Computed: {{ computedWarrantyDate }}</p>
                        </div>
                        <div class="p-3 bg-gray-50 rounded-lg border border-gray-200">
                            <label class="block text-xs font-bold text-gray-500 uppercase mb-2">EOL (Months)</label>
                            <input type="number" v-model="form.eol_months" required min="0" class="block w-full rounded-md border-gray-300 shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                            <p class="mt-1 text-[10px] text-blue-600 font-medium italic">Computed: {{ computedEolDate }}</p>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Cost</label>
                            <input type="number" step="0.01" v-model="form.cost" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Price</label>
                            <input type="number" step="0.01" v-model="form.price" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700">Location</label>
                        <input type="text" v-model="form.location" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                    </div>

                    <div class="flex justify-end space-x-3 mt-6">
                        <button type="button" @click="closeModal" class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md shadow-sm hover:bg-gray-50">Cancel</button>
                        <button type="submit" class="px-4 py-2 text-sm font-medium text-white bg-blue-600 border border-transparent rounded-md shadow-sm hover:bg-blue-700">
                            {{ isEditing ? 'Update' : 'Save' }}
                        </button>
                    </div>
                </form>
            </div>
        </Modal>
    </AppLayout>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import DataTable from '@/Components/DataTable.vue'
import Modal from '@/Components/Modal.vue'
import { PlusIcon } from '@heroicons/vue/24/outline'
import { usePagination } from '@/Composables/usePagination'
import { useToast } from '@/Composables/useToast'
import { useConfirm } from '@/Composables/useConfirm'

const props = defineProps({
    stockIns: Object,
    assets: Array,
    permissions: Object
})

const { showSuccess, showError } = useToast()
const { confirm } = useConfirm()
const pagination = usePagination(props.stockIns, 'stock-ins.index')

const showModal = ref(false)
const isEditing = ref(false)
const currentId = ref(null)

const getToday = () => new Date().toISOString().split('T')[0]

const form = reactive({
    receive_date: getToday(),
    asset_id: '',
    quantity: 1,
    serial_no: '',
    warranty_months: 12,
    eol_months: 60,
    cost: 0,
    price: 0,
    location: ''
})

const onAssetChange = () => {
    const asset = props.assets.find(a => a.id === form.asset_id);
    if (asset) {
        form.cost = asset.cost || 0;
    }
}

const addMonths = (dateStr, months) => {
    if (!dateStr) return null;
    const date = new Date(dateStr);
    date.setMonth(date.getMonth() + parseInt(months));
    return date;
}

const formatDate = (date) => {
    if (!date) return '-';
    return new Intl.DateTimeFormat('en-US', { month: 'short', day: '2-digit', year: 'numeric' }).format(new Date(date));
}

const computedWarrantyDate = computed(() => {
    const date = addMonths(form.receive_date, form.warranty_months);
    return date ? formatDate(date) : '-';
})

const computedEolDate = computed(() => {
    const date = addMonths(form.receive_date, form.eol_months);
    return date ? formatDate(date) : '-';
})

const openCreateModal = () => {
    isEditing.value = false
    currentId.value = null
    Object.assign(form, {
        receive_date: getToday(),
        asset_id: '',
        quantity: 1,
        serial_no: '',
        warranty_months: 12,
        eol_months: 60,
        cost: 0,
        price: 0,
        location: ''
    })
    showModal.value = true
}

const editItem = (item) => {
    isEditing.value = true
    currentId.value = item.id
    Object.assign(form, {
        receive_date: item.receive_date.split('T')[0],
        asset_id: item.asset_id,
        quantity: item.quantity,
        serial_no: item.serial_no,
        warranty_months: item.warranty_months,
        eol_months: item.eol_months,
        cost: item.cost,
        price: item.price,
        location: item.location
    })
    showModal.value = true
}

const closeModal = () => {
    showModal.value = false
}

const submitForm = () => {
    const url = isEditing.value ? route('stock-ins.update', currentId.value) : route('stock-ins.store')
    const method = isEditing.value ? 'put' : 'post'

    router[method](url, form, {
        onSuccess: () => {
            closeModal()
            showSuccess(isEditing.value ? 'Stock In updated' : 'Stock In recorded')
        },
        onError: (errors) => {
            showError(Object.values(errors)[0])
        }
    })
}

const deleteItem = (item) => {
    confirm({
        title: 'Delete Stock In',
        message: 'Are you sure you want to delete this record?',
        onConfirm: () => {
            router.delete(route('stock-ins.destroy', item.id), {
                onSuccess: () => showSuccess('Record deleted successfully')
            })
        }
    })
}

onMounted(() => {
    pagination.updateData(props.stockIns)
})
</script>
