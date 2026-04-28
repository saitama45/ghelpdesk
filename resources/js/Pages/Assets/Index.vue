<template>
    <AppLayout title="Assets">
        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <DataTable
                    title="Asset Management"
                    subtitle="Manage asset references and their properties"
                    search-placeholder="Search assets by code, brand, model..."
                    empty-message="No assets found. Create your first asset to get started."
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
                        <div class="flex items-center space-x-2">
                            <button
                                v-if="hasPermission('assets.create')"
                                @click="openImportModal"
                                class="bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors flex items-center space-x-2 shadow-sm whitespace-nowrap"
                            >
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                </svg>
                                <span>Import</span>
                            </button>
                            <button
                                v-if="hasPermission('assets.create')"
                                @click="openCreateModal"
                                class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors flex items-center space-x-2 shadow-sm whitespace-nowrap"
                            >
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                                </svg>
                                <span>Create Asset</span>
                            </button>
                        </div>
                    </template>

                    <template #header>
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Item Code</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Category / Sub</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Asset Info</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Cost</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type / EOL</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </template>

                    <template #body="{ data }">
                        <tr v-for="asset in data" :key="asset.id" class="hover:bg-gray-50 transition-colors">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="text-sm font-bold text-blue-600 font-mono tracking-tight">{{ asset.item_code }}</span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="flex flex-col space-y-1">
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-blue-100 text-blue-800 border border-blue-200 uppercase tracking-wider w-fit">
                                        {{ asset.category?.name || 'N/A' }}
                                    </span>
                                    <span v-if="asset.sub_category" class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-purple-100 text-purple-800 border border-purple-200 uppercase tracking-wider w-fit">
                                        {{ asset.sub_category.name }}
                                    </span>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="flex flex-col">
                                    <div class="text-sm font-medium text-gray-900">{{ asset.brand }} {{ asset.model }}</div>
                                    <div class="text-xs text-gray-500 max-w-xs truncate" :title="asset.description">{{ asset.description || 'No description' }}</div>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="text-sm font-medium text-gray-900">
                                    {{ asset.cost ? Number(asset.cost).toLocaleString('en-US', { style: 'currency', currency: 'PHP' }) : '-' }}
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="flex flex-col space-y-1">
                                    <span class="px-2 py-0.5 rounded-md text-[10px] font-bold uppercase tracking-wider w-fit"
                                          :class="asset.type === 'Fixed' ? 'bg-indigo-100 text-indigo-800 border border-indigo-200' : 'bg-orange-100 text-orange-800 border border-orange-200'">
                                        {{ asset.type }}
                                    </span>
                                    <span class="text-[10px] font-bold text-gray-500 uppercase tracking-widest">
                                        EOL: {{ asset.eol_years ? asset.eol_years + ' Years' : 'N/A' }}
                                    </span>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span :class="asset.is_active ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'" 
                                      class="inline-flex px-2 py-1 text-xs font-semibold rounded-full">
                                    {{ asset.is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                <div class="flex justify-end space-x-1">
                                    <button 
                                        v-if="hasPermission('assets.edit')"
                                        @click="editAsset(asset)" 
                                        class="p-2 text-blue-600 hover:text-blue-900 hover:bg-blue-50 rounded-full transition-colors"
                                        title="Edit Asset"
                                    >
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                        </svg>
                                    </button>
                                    <button 
                                        v-if="hasPermission('assets.delete')"
                                        @click="deleteAsset(asset)" 
                                        class="p-2 text-red-600 hover:text-red-900 hover:bg-red-50 rounded-full transition-colors"
                                        title="Delete Asset"
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

        <!-- Import Modal -->
        <div v-if="showImportModal" class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex items-center justify-center min-h-screen px-4 py-6">
                <div class="fixed inset-0 bg-black/20 backdrop-blur-md" @click="closeImportModal"></div>
                <div class="relative bg-white rounded-xl shadow-2xl w-full max-w-xl p-6 border border-gray-100 transform transition-all">
                    <div class="flex justify-between items-center mb-6">
                        <h3 class="text-xl font-bold text-gray-900">Import Assets</h3>
                        <button @click="closeImportModal" class="text-gray-400 hover:text-gray-600 transition-colors">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <div class="space-y-6">
                        <div class="p-5 bg-gradient-to-br from-blue-50 via-cyan-50 to-indigo-50 rounded-xl border border-blue-200 shadow-sm">
                            <h4 class="text-xs font-bold text-blue-700 uppercase tracking-wider mb-2">Instructions</h4>
                            <ul class="text-xs text-blue-600 space-y-1 list-disc pl-4">
                                <li>Download the Excel template to keep the expected column order.</li>
                                <li>The template includes two sample rows you can replace with real asset data.</li>
                                <li>Use existing category and sub-category names exactly as they appear in the system.</li>
                                <li>Duplicate item codes are skipped during import and returned as issues.</li>
                            </ul>
                        </div>

                        <a
                            :href="route('assets.template')"
                            class="group relative overflow-hidden flex items-center justify-between gap-4 rounded-2xl border border-blue-300 bg-gradient-to-r from-blue-600 via-cyan-600 to-sky-600 px-5 py-4 text-white shadow-lg shadow-blue-200 transition-all hover:-translate-y-0.5 hover:shadow-xl hover:shadow-blue-300"
                        >
                            <div class="absolute inset-y-0 right-0 w-24 bg-white/10 blur-2xl transition-transform group-hover:-translate-x-4"></div>
                            <div class="relative flex items-center gap-4">
                                <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-white/15 ring-1 ring-white/20">
                                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m5 2a2 2 0 01-2 2H6a2 2 0 01-2-2" />
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7V5a2 2 0 012-2h6a2 2 0 012 2v2" />
                                    </svg>
                                </div>
                                <div class="relative">
                                    <p class="text-[11px] font-black uppercase tracking-[0.2em] text-blue-100">Step 1</p>
                                    <p class="text-base font-black leading-tight">Download Excel Template</p>
                                    <p class="text-xs text-blue-100 mt-1">Includes sample rows and dropdown-ready category fields.</p>
                                </div>
                            </div>
                            <div class="relative flex items-center gap-2 text-sm font-black uppercase tracking-wider">
                                <span>Download</span>
                                <svg class="w-5 h-5 transition-transform group-hover:translate-y-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 5v10m0 0l-4-4m4 4l4-4M5 19h14" />
                                </svg>
                            </div>
                        </a>

                        <div class="space-y-3">
                            <label
                                class="flex flex-col items-center justify-center w-full px-6 py-8 text-center border-2 border-dashed border-gray-300 rounded-xl bg-gray-50 hover:bg-gray-100 hover:border-blue-300 transition-colors cursor-pointer"
                            >
                                <input
                                    ref="importFileInput"
                                    type="file"
                                    accept=".xlsx,.csv"
                                    class="hidden"
                                    @change="handleImportFileChange"
                                >
                                <svg class="w-8 h-8 text-gray-400 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M9 19l3 3m0 0l3-3m-3 3V10" />
                                </svg>
                                <span class="text-sm font-semibold text-gray-700">Choose asset import file</span>
                                <span class="text-xs text-gray-500 mt-1">Accepted formats: `.xlsx` or `.csv`</span>
                            </label>

                            <div v-if="selectedImportFile" class="flex items-center justify-between rounded-lg border border-gray-200 bg-gray-50 px-4 py-3">
                                <div>
                                    <p class="text-sm font-medium text-gray-900">{{ selectedImportFile.name }}</p>
                                    <p class="text-xs text-gray-500">{{ formatFileSize(selectedImportFile.size) }}</p>
                                </div>
                                <button type="button" @click="removeImportFile" class="text-sm font-semibold text-red-600 hover:text-red-700">
                                    Remove
                                </button>
                            </div>

                            <div v-if="importResults" class="p-4 rounded-lg" :class="importResults.errors.length > 0 ? 'bg-amber-50' : 'bg-green-50'">
                                <p class="text-sm font-bold" :class="importResults.errors.length > 0 ? 'text-amber-800' : 'text-green-800'">
                                    Successfully imported {{ importResults.imported }} assets.
                                </p>
                                <div v-if="importResults.errors.length > 0" class="mt-2">
                                    <p class="text-xs font-black text-amber-700 uppercase mb-1">Issues encountered:</p>
                                    <ul class="text-[10px] text-amber-600 max-h-32 overflow-y-auto list-disc pl-4">
                                        <li v-for="(err, index) in importResults.errors" :key="index">{{ err }}</li>
                                    </ul>
                                </div>
                            </div>
                        </div>

                        <div class="flex justify-end space-x-3 pt-6 border-t mt-6">
                            <button type="button" @click="closeImportModal"
                                    class="px-4 py-2 text-sm font-semibold text-gray-600 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors">
                                Close
                            </button>
                            <button @click="submitImport" :disabled="!selectedImportFile || isImporting"
                                    class="px-6 py-2 bg-emerald-600 text-white text-sm font-bold rounded-lg hover:bg-emerald-700 shadow-md transition-all disabled:opacity-50 flex items-center space-x-2">
                                <svg v-if="isImporting" class="animate-spin h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 6.477 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                <span>{{ isImporting ? 'Importing...' : 'Start Import' }}</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Create/Edit Modal -->
        <div v-if="showModal" class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex items-center justify-center min-h-screen px-4 py-6">
                <div class="fixed inset-0 bg-black/20 backdrop-blur-md" @click="closeModal"></div>
                <div class="relative bg-white rounded-xl shadow-2xl w-full max-w-2xl p-6 border border-gray-100 transform transition-all">
                    <div class="flex justify-between items-center mb-6">
                        <h3 class="text-xl font-bold text-gray-900">
                            {{ isEditing ? 'Edit Asset' : 'Create Asset' }}
                        </h3>
                        <button @click="closeModal" class="text-gray-400 hover:text-gray-600 transition-colors">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <form @submit.prevent="submitForm" class="space-y-5">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Item Code</label>
                                <div class="relative">
                                    <input v-model="form.item_code" type="text" :required="isEditing"
                                           class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm font-mono bg-gray-50"
                                           :readonly="!isEditing">
                                    <div v-if="!isEditing" class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none">
                                        <svg class="h-4 w-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                                        </svg>
                                    </div>
                                </div>
                                <p v-if="!isEditing" class="mt-1 text-[10px] text-blue-600 font-medium">System generated code</p>
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Type</label>
                                <Autocomplete
                                    v-model="form.type"
                                    :options="['Fixed', 'Consumables']"
                                    placeholder="Select Type"
                                    required
                                />
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Category</label>
                                <Autocomplete
                                    v-model="form.category_id"
                                    :options="categories"
                                    label-key="name"
                                    value-key="id"
                                    placeholder="Select Category"
                                    required
                                />
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Sub-Category</label>
                                <Autocomplete
                                    v-model="form.sub_category_id"
                                    :options="filteredSubCategories"
                                    label-key="name"
                                    value-key="id"
                                    placeholder="Select Sub-Category (Optional)"
                                />
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Brand</label>
                                <Autocomplete
                                    v-model="form.brand"
                                    :options="brandOptions"
                                    placeholder="Type or select Brand"
                                    allow-custom
                                />
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Model</label>
                                <Autocomplete
                                    v-model="form.model"
                                    :options="modelOptions"
                                    placeholder="Type or select Model"
                                    allow-custom
                                />
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Cost (PHP)</label>
                                <input v-model="form.cost" type="number" step="0.01" min="0"
                                       class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">EOL (Years)</label>
                                <input v-model="form.eol_years" type="number" step="1" min="0"
                                       class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Description</label>
                            <textarea v-model="form.description" rows="3"
                                      class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm"
                                      placeholder="Provide a detailed description of the asset..."></textarea>
                        </div>

                        <div class="flex items-center space-x-6">
                            <label class="flex items-center group cursor-pointer">
                                <input type="checkbox" v-model="form.is_active" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 transition-colors">
                                <span class="ml-2 text-sm font-medium text-gray-700 group-hover:text-blue-600 transition-colors">Active Asset</span>
                            </label>
                        </div>

                        <div class="flex justify-end space-x-3 pt-6 border-t mt-6">
                            <button type="button" @click="closeModal" 
                                    class="px-4 py-2 text-sm font-semibold text-gray-600 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors">
                                Cancel
                            </button>
                            <button type="submit" 
                                    class="px-6 py-2 bg-blue-600 text-white text-sm font-bold rounded-lg hover:bg-blue-700 shadow-md transition-all">
                                {{ isEditing ? 'Update Asset' : 'Create Asset' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref, reactive, onMounted, watch, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import DataTable from '@/Components/DataTable.vue'
import Autocomplete from '@/Components/Autocomplete.vue'
import { useToast } from '@/Composables/useToast'
import { useConfirm } from '@/Composables/useConfirm'
import { useErrorHandler } from '@/Composables/useErrorHandler'
import { usePagination } from '@/Composables/usePagination'
import { usePermission } from '@/Composables/usePermission'
import axios from 'axios'

const props = defineProps({
    assets: Object,
    categories: Array,
    subCategories: Array,
    brandOptions: Array,
    modelOptions: Array,
    filters: Object
})

const { showSuccess, showError } = useToast()
const { confirm } = useConfirm()
const { post, put, destroy } = useErrorHandler()
const pagination = usePagination(props.assets, 'assets.index')
const { hasPermission } = usePermission()

const showModal = ref(false)
const showImportModal = ref(false)
const isEditing = ref(false)
const currentAsset = ref(null)
const importFileInput = ref(null)
const selectedImportFile = ref(null)
const isImporting = ref(false)
const importResults = ref(null)
const autoDescription = ref('')

const fetchNextCode = async () => {
    try {
        const response = await axios.get(route('assets.generate-code'));
        form.item_code = response.data.code;
    } catch (error) {
        console.error('Error fetching next item code:', error);
    }
};

const form = reactive({
    item_code: '',
    category_id: null,
    sub_category_id: null,
    brand: '',
    model: '',
    description: '',
    cost: null,
    type: 'Fixed',
    eol_years: null,
    is_active: true
})

onMounted(() => {
    pagination.updateData(props.assets)
})

watch(() => props.assets, (newAssets) => {
    pagination.updateData(newAssets)
}, { deep: true })

const filteredSubCategories = computed(() => {
    if (!form.category_id) return props.subCategories;
    // Assuming sub_categories have a category_id field. Let's check.
    // If not, we might need to filter differently. 
    // Based on the DB structure usually seen, subcategories belong to categories.
    return props.subCategories.filter(s => s.category_id === form.category_id || !s.category_id);
})

const buildAutoDescription = () => {
    return [form.brand, form.model]
        .map(value => String(value || '').trim())
        .filter(Boolean)
        .join(' ')
}

const syncDescriptionFromBrandModel = () => {
    const nextDescription = buildAutoDescription()

    if (!form.description || form.description === autoDescription.value) {
        form.description = nextDescription
    }

    autoDescription.value = nextDescription
}

watch(() => [form.brand, form.model], syncDescriptionFromBrandModel)

const openCreateModal = () => {
    isEditing.value = false
    currentAsset.value = null
    autoDescription.value = ''
    Object.assign(form, {
        item_code: '',
        category_id: props.categories.length > 0 ? props.categories[0].id : null,
        sub_category_id: null,
        brand: '',
        model: '',
        description: '',
        cost: null,
        type: 'Fixed',
        eol_years: null,
        is_active: true
    })
    fetchNextCode()
    showModal.value = true
}

const openImportModal = () => {
    selectedImportFile.value = null
    importResults.value = null
    if (importFileInput.value) {
        importFileInput.value.value = ''
    }
    showImportModal.value = true
}

const editAsset = (asset) => {
    isEditing.value = true
    currentAsset.value = asset
    autoDescription.value = [asset.brand, asset.model]
        .map(value => String(value || '').trim())
        .filter(Boolean)
        .join(' ')
    Object.assign(form, {
        item_code: asset.item_code,
        category_id: asset.category_id,
        sub_category_id: asset.sub_category_id,
        brand: asset.brand || '',
        model: asset.model || '',
        description: asset.description || '',
        cost: asset.cost,
        type: asset.type || 'Fixed',
        eol_years: asset.eol_years,
        is_active: asset.is_active
    })
    showModal.value = true
}

const closeModal = () => {
    showModal.value = false
}

const closeImportModal = () => {
    showImportModal.value = false
    selectedImportFile.value = null
    if (importFileInput.value) {
        importFileInput.value.value = ''
    }
}

const handleImportFileChange = (event) => {
    selectedImportFile.value = event.target.files?.[0] || null
}

const removeImportFile = () => {
    selectedImportFile.value = null
    if (importFileInput.value) {
        importFileInput.value.value = ''
    }
}

const formatFileSize = (size) => {
    if (!size) return '0 B'
    if (size < 1024) return `${size} B`
    if (size < 1024 * 1024) return `${(size / 1024).toFixed(1)} KB`
    return `${(size / (1024 * 1024)).toFixed(1)} MB`
}

const submitForm = () => {
    const url = isEditing.value ? `/assets/${currentAsset.value.id}` : '/assets'
    const requestMethod = isEditing.value ? put : post
    
    requestMethod(url, form, {
        onSuccess: () => {
            closeModal()
            showSuccess(isEditing.value ? 'Asset updated successfully' : 'Asset created successfully')
        },
        onError: (errors) => {
            const errorMessage = Object.values(errors).flat().join(', ') || 'An error occurred'
            showError(errorMessage)
        }
    })
}

const submitImport = async () => {
    if (!selectedImportFile.value || isImporting.value) return

    isImporting.value = true
    importResults.value = null

    const formData = new FormData()
    formData.append('file', selectedImportFile.value)

    try {
        const response = await axios.post(route('assets.import'), formData, {
            headers: { 'Content-Type': 'multipart/form-data' }
        })

        importResults.value = response.data

        if (response.data.imported > 0) {
            showSuccess(`Imported ${response.data.imported} asset${response.data.imported > 1 ? 's' : ''} successfully`)
            router.reload({ only: ['assets'] })
        }

        if (response.data.errors?.length > 0) {
            showError(`Import completed with ${response.data.errors.length} issue${response.data.errors.length > 1 ? 's' : ''}`)
        }
    } catch (error) {
        showError(error.response?.data?.message || 'Import failed')
    } finally {
        isImporting.value = false
    }
}

const deleteAsset = async (asset) => {
    const confirmed = await confirm({
        title: 'Delete Asset',
        message: `Are you sure you want to delete asset "${asset.item_code}"? This action cannot be undone.`
    })
    
    if (confirmed) {
        destroy(`/assets/${asset.id}`, {
            onSuccess: () => showSuccess('Asset deleted successfully'),
            onError: (errors) => {
                const errorMessage = Object.values(errors).flat().join(', ') || 'Cannot delete asset'
                showError(errorMessage)
            }
        })
    }
}
</script>
