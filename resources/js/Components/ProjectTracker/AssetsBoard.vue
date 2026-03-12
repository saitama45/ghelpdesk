<script setup>
import { ref, computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { PlusIcon, TrashIcon, PencilSquareIcon } from '@heroicons/vue/24/outline';
import { useToast } from '@/Composables/useToast.js';
import { useConfirm } from '@/Composables/useConfirm.js';

const props = defineProps({
    project: Object,
});

const { success } = useToast();
const { confirm: confirmAction } = useConfirm();
const isAddingAsset = ref(false);
const isEditing = ref(false);
const editingAssetId = ref(null);

const form = useForm({
    project_id: props.project.id,
    category: '',
    item_name: '',
    model_specs: '',
    quantity: 1,
    delivery_status: 'Pending',
    responsible: '',
    store_delivery_date: '',
    store_setup_date: '',
    remarks: ''
});

const saveAsset = () => {
    if (isEditing.value) {
        form.put(route('projects-assets.update', { 'projects_asset': editingAssetId.value, tab: 'assets' }), {
            preserveScroll: true,
            onSuccess: () => {
                isAddingAsset.value = false;
                isEditing.value = false;
                editingAssetId.value = null;
                form.reset();
                form.project_id = props.project.id;
            }
        });
    } else {
        form.post(route('projects-assets.store', { tab: 'assets' }), {
            preserveScroll: true,
            onSuccess: () => {
                isAddingAsset.value = false;
                form.reset();
                form.project_id = props.project.id;
            }
        });
    }
};

const editAsset = (asset) => {
    isEditing.value = true;
    editingAssetId.value = asset.id;
    isAddingAsset.value = true;
    
    form.category = asset.category;
    form.item_name = asset.item_name;
    form.model_specs = asset.model_specs;
    form.quantity = asset.quantity;
    form.delivery_status = asset.delivery_status;
    form.responsible = asset.responsible;
    form.store_delivery_date = asset.store_delivery_date ? asset.store_delivery_date.split('T')[0] : '';
    form.store_setup_date = asset.store_setup_date ? asset.store_setup_date.split('T')[0] : '';
    form.remarks = asset.remarks;
};

const deleteAsset = async (assetId) => {
    const ok = await confirmAction({
        title: 'Delete Item',
        message: 'Are you sure you want to permanently delete this item? This action cannot be undone.'
    });

    if (ok) {
        useForm({}).delete(route('projects-assets.destroy', { 'projects_asset': assetId, tab: 'assets' }), {
            preserveScroll: true
        });
    }
};

const getStatusClass = (status) => {
    if (!status) return 'bg-gray-100 text-gray-800';
    if (status.includes('Purchased') || status.includes('Done')) return 'bg-green-100 text-green-800';
    if (status.includes('Pending')) return 'bg-yellow-100 text-yellow-800';
    if (status.includes('swap')) return 'bg-blue-100 text-blue-800';
    return 'bg-gray-100 text-gray-800';
};

const formatDate = (dateString) => {
    if (!dateString) return '-';
    return new Date(dateString).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric'
    });
};

const groupedAssets = computed(() => {
    if (!props.project.assets) return {};
    return props.project.assets.reduce((groups, asset) => {
        const category = asset.category || 'Uncategorized';
        if (!groups[category]) {
            groups[category] = [];
        }
        groups[category].push(asset);
        return groups;
    }, {});
});
</script>

<template>
    <div class="bg-white shadow rounded-lg border border-gray-200 overflow-hidden">
        <div class="p-4 border-b border-gray-200 flex justify-between items-center bg-gray-50">
            <h3 class="text-lg font-bold text-gray-900">IT Items Status</h3>
            <button 
                @click="isAddingAsset = !isAddingAsset"
                class="inline-flex items-center px-3 py-1.5 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-blue-600 hover:bg-blue-700"
            >
                <PlusIcon class="-ml-0.5 mr-2 h-4 w-4" />
                Add Item
            </button>
        </div>

        <!-- Add Asset Form -->
        <transition
            enter-active-class="transition duration-300 ease-out"
            enter-from-class="transform -translate-y-4 opacity-0"
            enter-to-class="transform translate-y-0 opacity-100"
            leave-active-class="transition duration-200 ease-in"
            leave-from-class="transform translate-y-0 opacity-100"
            leave-to-class="transform -translate-y-4 opacity-0"
        >
            <div v-if="isAddingAsset" class="p-6 border-b border-indigo-100 bg-indigo-50/30">
                <div class="grid grid-cols-1 md:grid-cols-12 gap-6 items-end">
                    <div class="md:col-span-2">
                        <label class="block text-[10px] font-bold text-indigo-900 uppercase tracking-widest mb-1.5 ml-1">Category</label>
                        <input v-model="form.category" type="text" placeholder="e.g. POS, CCTV" class="w-full text-sm border-slate-200 rounded-xl shadow-sm focus:ring-2 focus:ring-indigo-500 transition-all">
                    </div>
                    <div class="md:col-span-3">
                        <label class="block text-[10px] font-bold text-indigo-900 uppercase tracking-widest mb-1.5 ml-1">Item & Specs</label>
                        <div class="space-y-2">
                            <input v-model="form.item_name" type="text" placeholder="Item Name" class="w-full text-sm border-slate-200 rounded-xl shadow-sm focus:ring-2 focus:ring-indigo-500 transition-all">
                            <input v-model="form.model_specs" type="text" placeholder="Model / Specs" class="w-full text-xs border-slate-200 rounded-xl shadow-sm focus:ring-2 focus:ring-indigo-500 transition-all bg-white/50">
                        </div>
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-[10px] font-bold text-indigo-900 uppercase tracking-widest mb-1.5 ml-1">Qty & Status</label>
                        <div class="space-y-2">
                            <input v-model="form.quantity" type="number" min="1" class="w-full text-sm border-slate-200 rounded-xl shadow-sm focus:ring-2 focus:ring-indigo-500 transition-all">
                            <select v-model="form.delivery_status" class="w-full text-xs border-slate-200 rounded-xl shadow-sm focus:ring-2 focus:ring-indigo-500 transition-all">
                                <option value="Pending">Pending</option>
                                <option value="Yes - Purchased">Yes - Purchased</option>
                                <option value="Yes - to swap">Yes - to swap</option>
                                <option value="N/A">N/A</option>
                            </select>
                        </div>
                    </div>
                    <div class="md:col-span-3">
                        <label class="block text-[10px] font-bold text-indigo-900 uppercase tracking-widest mb-1.5 ml-1">Responsible & Dates</label>
                        <div class="space-y-2">
                            <input v-model="form.responsible" type="text" placeholder="Responsible Party" class="w-full text-sm border-slate-200 rounded-xl shadow-sm focus:ring-2 focus:ring-indigo-500 transition-all">
                            <div class="flex gap-2">
                                <input v-model="form.store_delivery_date" type="date" title="Delivery" class="w-full text-[10px] border-slate-200 rounded-xl shadow-sm focus:ring-2 focus:ring-indigo-500 transition-all">
                                <input v-model="form.store_setup_date" type="date" title="Setup" class="w-full text-[10px] border-slate-200 rounded-xl shadow-sm focus:ring-2 focus:ring-indigo-500 transition-all">
                            </div>
                        </div>
                    </div>
                    <div class="md:col-span-2 flex gap-2">
                        <button @click="saveAsset" :disabled="form.processing" class="flex-1 bg-indigo-600 text-white font-bold py-2 rounded-xl hover:bg-indigo-700 shadow-md transition-all active:scale-95 disabled:opacity-50 text-sm">
                            {{ isEditing ? 'Update' : 'Save' }}
                        </button>
                        <button @click="isAddingAsset = false" class="px-3 bg-white text-slate-600 font-bold py-2 border border-slate-200 rounded-xl hover:bg-slate-50 transition-all">
                            <TrashIcon class="w-4 h-4" />
                        </button>
                    </div>
                </div>
                <div class="mt-4">
                    <label class="block text-[10px] font-bold text-indigo-900 uppercase tracking-widest mb-1.5 ml-1">Remarks (Optional)</label>
                    <input v-model="form.remarks" type="text" placeholder="Additional notes..." class="w-full text-sm border-slate-200 rounded-xl shadow-sm focus:ring-2 focus:ring-indigo-500 transition-all bg-white/50">
                </div>
            </div>
        </transition>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Category / Item</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Specs</th>
                        <th scope="col" class="px-3 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Qty</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Responsible</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Dates</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Remarks</th>
                        <th scope="col" class="relative px-6 py-3"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <template v-for="(assets, category) in groupedAssets" :key="category">
                        <tr class="bg-gray-100/50">
                            <td colspan="8" class="px-6 py-2 text-sm font-bold text-gray-900 border-t border-gray-200">
                                {{ category }}
                            </td>
                        </tr>
                        <tr v-for="asset in assets" :key="asset.id" class="hover:bg-gray-50">
                            <td class="px-6 py-3 whitespace-nowrap text-sm font-medium text-gray-900 pl-8">
                                {{ asset.item_name }}
                            </td>
                            <td class="px-6 py-3 text-sm text-gray-500 max-w-[200px] truncate" :title="asset.model_specs">
                                {{ asset.model_specs || '-' }}
                            </td>
                            <td class="px-3 py-3 whitespace-nowrap text-sm text-gray-500 text-center">
                                {{ asset.quantity }}
                            </td>
                            <td class="px-6 py-3 whitespace-nowrap">
                                <span :class="['px-2 inline-flex text-xs leading-5 font-semibold rounded-full', getStatusClass(asset.delivery_status)]">
                                    {{ asset.delivery_status || 'Pending' }}
                                </span>
                            </td>
                            <td class="px-6 py-3 whitespace-nowrap text-sm text-gray-500">
                                {{ asset.responsible || '-' }}
                            </td>
                            <td class="px-6 py-3 whitespace-nowrap text-sm text-gray-500">
                                <div class="flex flex-col gap-1">
                                    <span class="text-xs" v-if="asset.store_delivery_date">
                                        <span class="text-gray-400 font-medium">Del:</span> {{ formatDate(asset.store_delivery_date) }}
                                    </span>
                                    <span class="text-xs" v-if="asset.store_setup_date">
                                        <span class="text-gray-400 font-medium">Set:</span> {{ formatDate(asset.store_setup_date) }}
                                    </span>
                                    <span class="text-xs text-gray-300" v-if="!asset.store_delivery_date && !asset.store_setup_date">-</span>
                                </div>
                            </td>
                            <td class="px-6 py-3 text-sm text-gray-500 max-w-[200px] truncate" :title="asset.remarks">
                                {{ asset.remarks || '-' }}
                            </td>
                            <td class="px-6 py-3 whitespace-nowrap text-right text-sm font-medium">
                                <div class="flex justify-end space-x-2">
                                    <button @click="editAsset(asset)" class="text-indigo-600 hover:text-indigo-900" title="Edit Item">
                                        <PencilSquareIcon class="w-4 h-4" />
                                    </button>
                                    <button @click="deleteAsset(asset.id)" class="text-red-600 hover:text-red-900" title="Delete Item">
                                        <TrashIcon class="w-4 h-4" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </template>
                    <tr v-if="Object.keys(groupedAssets).length === 0">
                        <td colspan="8" class="px-6 py-12 text-center text-sm text-gray-500">
                            No IT assets or items tracked yet. Click "Add Item" to begin tracking the Bill of Materials.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
