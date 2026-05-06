<template>
    <AppLayout title="Stock Transaction">
        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <DataTable
                    title="Stock Transaction Headers"
                    subtitle="Manage stock movements (In/Transfers) and their quantity"
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
                        <div class="flex items-center space-x-2">
                            <button
                                v-if="hasPermission('stock_ins.create')"
                                @click="openImportModal"
                                class="bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors flex items-center space-x-2 shadow-sm whitespace-nowrap"
                            >
                                <ArrowUpTrayIcon class="w-4 h-4" />
                                <span>Import</span>
                            </button>
                            <button
                                v-if="hasPermission('stock_ins.create')"
                                @click="openCreateModal"
                                class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors flex items-center space-x-2 shadow-sm whitespace-nowrap"
                            >
                                <PlusIcon class="w-4 h-4" />
                                <span>Add Stock</span>
                            </button>
                        </div>
                    </template>

                    <template #header>
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Receive Date</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">DR / Status</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Item Code</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Header Record</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Qty</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                        </tr>
                    </template>

                    <template #body="{ data }">
                        <tr v-for="item in data" :key="item.id" class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ formatDate(item.receive_date) }}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                <div class="flex flex-col">
                                    <span class="font-semibold text-gray-900">{{ item.dr_no || '-' }}</span>
                                    <span class="mt-1 inline-flex w-fit items-center rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider"
                                          :class="item.status === 'Posted' ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800'">
                                        {{ item.status || 'For Posting' }}
                                    </span>
                                    <span v-if="item.status === 'Posted'" class="mt-1 text-[11px] text-gray-500">
                                        Posted by {{ item.posted_by || '-' }}<span v-if="item.posted_date"> on {{ formatAuditDate(item.posted_date) }}</span>
                                    </span>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 font-mono">{{ item.asset?.item_code }}</td>
                            <td class="px-6 py-4 text-sm text-gray-900">
                                <div class="flex flex-col">
                                    <div class="font-semibold text-gray-900">
                                        {{ [item.asset?.brand, item.asset?.model].filter(Boolean).join(' ') || 'Unnamed Stock Header' }}
                                    </div>
                                    <div class="text-xs text-gray-500 max-w-md truncate" :title="item.asset?.description">
                                        {{ item.asset?.description || 'No description' }}
                                    </div>
                                    <div class="mt-1 text-[11px] text-gray-500">
                                        Vendor: {{ item.vendor || '-' }} | Origin: {{ item.origin_location || '-' }} | Destination: {{ item.destination_location || '-' }}
                                    </div>
                                    <div class="mt-2 text-[11px] text-gray-500">
                                        {{ item.record_count }} row<span v-if="item.record_count !== 1">s</span> grouped in this header
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                <span class="inline-flex items-center px-2.5 py-1 rounded-full bg-slate-100 text-slate-800 text-xs font-bold">
                                    {{ item.quantity }}
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                <div class="flex justify-end space-x-1">
                                    <button
                                        v-if="hasPermission('stock_ins.post') && item.status !== 'Posted'"
                                        @click="postHeaderItem(item)"
                                        class="p-2 text-emerald-600 hover:text-emerald-900 hover:bg-emerald-50 rounded-full transition-colors"
                                        title="Post"
                                    >
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                        </svg>
                                    </button>
                                    <button 
                                        v-if="hasPermission('stock_ins.edit')" 
                                        @click="editHeaderItem(item)" 
                                        class="p-2 text-blue-600 hover:text-blue-900 hover:bg-blue-50 rounded-full transition-colors"
                                        title="Edit"
                                    >
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                        </svg>
                                    </button>
                                    <button
                                        v-if="hasPermission('stock_ins.delete')"
                                        @click="deleteItem(item)"
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

        <!-- Import Modal -->
        <Modal :show="showImportModal" @close="closeImportModal" max-width="xl">
            <div class="p-6">
                <div class="flex items-start justify-between gap-4 mb-6">
                    <div>
                        <h3 class="text-lg font-bold text-gray-900">Import Stock In</h3>
                        <p class="text-sm text-gray-500 mt-1">Upload one spreadsheet row per stock-in unit.</p>
                    </div>
                    <button type="button" @click="closeImportModal" class="text-gray-400 hover:text-gray-600 transition-colors">
                        <span class="sr-only">Close</span>
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div class="space-y-6">
                    <div class="rounded-xl border border-blue-200 bg-blue-50 p-5">
                        <h4 class="text-xs font-bold text-blue-700 uppercase tracking-wider mb-2">Instructions</h4>
                        <ul class="text-xs text-blue-600 space-y-1 list-disc pl-4">
                            <li>Download the Excel template to keep the expected column order.</li>
                            <li>Each row creates one Stock In unit; matching rows are grouped in the table.</li>
                            <li>Use existing asset item codes. Store codes are recommended for origin and destination.</li>
                            <li>Imported rows are saved as For Posting.</li>
                        </ul>
                    </div>

                    <a
                        :href="route('stock-ins.template')"
                        class="group flex items-center justify-between gap-4 rounded-xl border border-blue-300 bg-blue-600 px-5 py-4 text-white shadow-sm transition-colors hover:bg-blue-700"
                    >
                        <div class="flex items-center gap-4">
                            <div class="flex h-11 w-11 items-center justify-center rounded-lg bg-white/15 ring-1 ring-white/20">
                                <ArrowDownTrayIcon class="w-6 h-6" />
                            </div>
                            <div>
                                <p class="text-[11px] font-black uppercase tracking-[0.2em] text-blue-100">Step 1</p>
                                <p class="text-base font-black leading-tight">Download Excel Template</p>
                                <p class="text-xs text-blue-100 mt-1">Includes sample rows and dropdown-ready reference fields.</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-2 text-sm font-black uppercase tracking-wider">
                            <span>Download</span>
                            <ArrowDownTrayIcon class="w-5 h-5 transition-transform group-hover:translate-y-0.5" />
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
                            <ArrowUpTrayIcon class="w-8 h-8 text-gray-400 mb-3" />
                            <span class="text-sm font-semibold text-gray-700">Choose stock-in import file</span>
                            <span class="text-xs text-gray-500 mt-1">Accepted formats: .xlsx or .csv</span>
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

                        <div
                            v-if="importResults"
                            class="p-4 rounded-lg"
                            :class="(importResults.errors?.length || 0) > 0 ? 'bg-amber-50' : 'bg-green-50'"
                        >
                            <p
                                class="text-sm font-bold"
                                :class="(importResults.errors?.length || 0) > 0 ? 'text-amber-800' : 'text-green-800'"
                            >
                                Successfully imported {{ importResults.imported }} stock-in row<span v-if="importResults.imported !== 1">s</span>.
                            </p>
                            <div v-if="(importResults.errors?.length || 0) > 0" class="mt-2">
                                <p class="text-xs font-black text-amber-700 uppercase mb-1">Issues encountered:</p>
                                <ul class="text-[10px] text-amber-600 max-h-32 overflow-y-auto list-disc pl-4">
                                    <li v-for="(err, index) in importResults.errors" :key="index">{{ err }}</li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end space-x-3 pt-6 border-t">
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
        </Modal>

        <!-- Modal -->
        <Modal :show="showModal" @close="closeModal" max-width="4xl">
            <div class="p-6">
                <h3 class="text-lg font-bold text-gray-900 mb-4">{{ isEditing ? 'Edit Stock In' : 'Add Stock In' }}</h3>
                <form @submit.prevent="submitForm()" class="space-y-4">
                    <div class="rounded-2xl border border-gray-200 bg-gray-50/70 p-4 space-y-4">
                        <div>
                            <h4 class="text-sm font-semibold text-gray-900">Header Details</h4>
                            <p class="text-xs text-gray-500">Capture delivery reference, source, and receiver information before adding unit-level details.</p>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Receive Date</label>
                                <input type="date" v-model="form.receive_date" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">DR No</label>
                                <input type="text" v-model="form.dr_no" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">DR Date</label>
                                <input type="date" v-model="form.dr_date" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Received By</label>
                                <input type="text" v-model="form.received_by" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Vendor</label>
                                <Autocomplete
                                    v-model="form.vendor"
                                    :options="vendorOptions"
                                    label-key="name"
                                    value-key="value"
                                    placeholder="Select Vendor"
                                />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Origin Location</label>
                                <Autocomplete
                                    v-model="form.origin_location"
                                    :options="storeOptions"
                                    label-key="name"
                                    value-key="value"
                                    placeholder="Select Origin Store"
                                />
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Brand</label>
                                <Autocomplete
                                    v-model="form.brand"
                                    :options="brandOptions"
                                    label-key="name"
                                    value-key="value"
                                    placeholder="Select Brand"
                                    @update:modelValue="onBrandChange"
                                />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Model</label>
                                <Autocomplete
                                    v-model="form.model"
                                    :options="modelOptions"
                                    label-key="name"
                                    value-key="value"
                                    placeholder="Select Model"
                                    :disabled="!form.brand"
                                    @update:modelValue="onModelChange"
                                />
                            </div>
                            <div class="xl:col-span-2">
                                <label class="block text-sm font-medium text-gray-700">Asset Item</label>
                                <Autocomplete
                                    v-model="form.asset_id"
                                    :options="assetOptions"
                                    label-key="name"
                                    value-key="id"
                                    placeholder="Select Asset Item"
                                    :disabled="!form.brand || !form.model"
                                    @update:modelValue="onAssetChange"
                                />
                            </div>
                        </div>

                        <div
                            v-if="isTransferMode && form.asset_id && !isEditing"
                            class="grid grid-cols-1 md:grid-cols-3 gap-3 rounded-xl border border-blue-200 bg-blue-50 p-4"
                        >
                            <div>
                                <p class="text-[10px] font-bold uppercase tracking-wider text-blue-500">Transfer Origin</p>
                                <p class="mt-1 text-sm font-semibold text-blue-950">{{ normalizedOriginLocation }}</p>
                            </div>
                            <div>
                                <p class="text-[10px] font-bold uppercase tracking-wider text-blue-500">Current SOH</p>
                                <p class="mt-1 text-2xl font-black text-blue-950">
                                    <span v-if="isLoadingAvailableStock">...</span>
                                    <span v-else>{{ availableSoh }}</span>
                                </p>
                            </div>
                            <div>
                                <p class="text-[10px] font-bold uppercase tracking-wider text-blue-500">Transfer Mode</p>
                                <p class="mt-1 text-sm font-semibold text-blue-950">
                                    {{ selectedAsset?.type === 'Fixed' ? 'Select fixed asset units below' : 'Qty is capped by origin SOH' }}
                                </p>
                            </div>
                            <p v-if="availableStockError" class="md:col-span-3 text-xs font-semibold text-red-600">
                                {{ availableStockError }}
                            </p>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div v-if="isFixedTransfer && !isEditing">
                                <label class="block text-sm font-medium text-gray-700">Selected Units</label>
                                <div class="mt-1 flex min-h-[38px] items-center rounded-md border border-gray-300 bg-white px-3 text-sm font-semibold text-gray-900 shadow-sm">
                                    {{ form.entries.length }} selected
                                </div>
                                <p class="mt-1 text-[11px] text-gray-500">
                                    Transfer quantity follows the fixed asset units selected below.
                                </p>
                            </div>
                            <div v-else>
                                <label class="block text-sm font-medium text-gray-700">Qty</label>
                                <input
                                    type="number"
                                    v-model.number="form.quantity"
                                    required
                                    min="1"
                                    :max="isConsumableTransfer && availableSoh > 0 ? availableSoh : null"
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm"
                                >
                                <p class="mt-1 text-[11px] text-gray-500">
                                    {{ isConsumableTransfer && !isEditing ? `Up to ${availableSoh} item(s) can be transferred from the origin.` : (isEditing ? 'Qty updates how many grouped stock-in detail rows are kept below.' : 'Qty controls how many detail rows are prepared below.') }}
                                </p>
                                <p
                                    v-if="pendingDetailRemovalCount > 0"
                                    class="mt-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-[11px] font-semibold text-amber-800"
                                >
                                    Saving this change will remove {{ pendingDetailRemovalCount }} existing Stock Detail row<span v-if="pendingDetailRemovalCount !== 1">s</span>. You will be asked to confirm before it is saved.
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-2xl border border-gray-200 bg-gray-50/70 overflow-hidden">
                        <div class="flex items-center justify-between px-4 py-3 border-b border-gray-200 bg-white">
                            <div>
                                <h4 class="text-sm font-semibold text-gray-900">Stock Details</h4>
                                <p class="text-xs text-gray-500">Add serial, codes, pricing, warranty, and location per unit.</p>
                            </div>
                            <div class="flex flex-col sm:flex-row sm:items-center gap-2">
                                <div v-if="isEditing && editingStockIn" class="flex items-center gap-2">
                                    <button
                                        type="button"
                                        @click="printStockInCodes('barcodes')"
                                        class="inline-flex items-center gap-1.5 rounded-md border border-gray-300 bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 shadow-sm hover:bg-gray-50"
                                        title="Print barcode labels as PDF"
                                    >
                                        <PrinterIcon class="h-4 w-4" />
                                        <span>Print Barcodes</span>
                                    </button>
                                    <button
                                        type="button"
                                        @click="printStockInCodes('qrcodes')"
                                        class="inline-flex items-center gap-1.5 rounded-md border border-gray-300 bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 shadow-sm hover:bg-gray-50"
                                        title="Print QR code labels as PDF"
                                    >
                                        <QrCodeIcon class="h-4 w-4" />
                                        <span>Print QR Codes</span>
                                    </button>
                                </div>
                                <span class="text-xs font-semibold uppercase tracking-[0.2em] text-blue-600">{{ form.entries.length }} row<span v-if="form.entries.length !== 1">s</span></span>
                            </div>
                        </div>

                        <div class="max-h-[55vh] overflow-y-auto p-4 space-y-4">
                            <div v-if="isFixedTransfer && !isEditing" class="space-y-4">
                                <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 rounded-xl border border-gray-200 bg-white p-4">
                                    <div class="lg:col-span-2">
                                        <p class="text-sm font-semibold text-gray-900">Available fixed asset units</p>
                                        <p class="mt-1 text-xs text-gray-500">
                                            Pick the posted units currently held by {{ normalizedOriginLocation }}. Selected units inherit their serial, barcode, QR code, cost, warranty, and EOL values.
                                        </p>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Destination for Selected</label>
                                        <Autocomplete
                                            v-model="bulkDestinationLocation"
                                            :options="storeOptions"
                                            label-key="name"
                                            value-key="value"
                                            placeholder="Select Destination"
                                            size="sm"
                                        />
                                    </div>
                                </div>

                                <div v-if="isLoadingAvailableStock" class="rounded-xl border border-gray-200 bg-white p-6 text-sm font-semibold text-gray-500">
                                    Loading available stock...
                                </div>

                                <div v-else-if="availableStockError" class="rounded-xl border border-red-200 bg-red-50 p-6 text-sm font-semibold text-red-700">
                                    {{ availableStockError }}
                                </div>

                                <div v-else-if="availableUnits.length === 0" class="rounded-xl border border-amber-200 bg-amber-50 p-6 text-sm font-semibold text-amber-800">
                                    No available fixed asset units were found at {{ normalizedOriginLocation }}.
                                </div>

                                <div v-else class="overflow-hidden rounded-xl border border-gray-200 bg-white">
                                    <div class="max-h-80 overflow-y-auto">
                                        <table class="min-w-full divide-y divide-gray-200">
                                            <thead class="bg-gray-50 sticky top-0 z-10">
                                                <tr>
                                                    <th class="w-12 px-4 py-3 text-left text-xs font-bold uppercase tracking-wider text-gray-500">Pick</th>
                                                    <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wider text-gray-500">Serial / Barcode</th>
                                                    <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wider text-gray-500">Received</th>
                                                    <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wider text-gray-500">Cost</th>
                                                    <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wider text-gray-500">Current Location</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-gray-100 bg-white">
                                                <tr
                                                    v-for="unit in availableUnits"
                                                    :key="unit.id"
                                                    class="hover:bg-blue-50/60"
                                                    :class="isSourceSelected(unit) ? 'bg-blue-50' : ''"
                                                >
                                                    <td class="px-4 py-3">
                                                        <input
                                                            type="checkbox"
                                                            :checked="isSourceSelected(unit)"
                                                            class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                                                            @change="toggleSourceUnit(unit)"
                                                        >
                                                    </td>
                                                    <td class="px-4 py-3">
                                                        <p class="text-sm font-semibold text-gray-900">{{ unit.serial_no || 'No serial' }}</p>
                                                        <p class="text-xs font-mono text-gray-500">{{ unit.barcode || 'No barcode' }}</p>
                                                    </td>
                                                    <td class="px-4 py-3">
                                                        <p class="text-sm text-gray-900">{{ formatDate(unit.receive_date) }}</p>
                                                        <p class="text-xs text-gray-500">{{ unit.dr_no || 'No DR' }}</p>
                                                    </td>
                                                    <td class="px-4 py-3 text-sm font-semibold text-gray-900">
                                                        {{ Number(unit.cost || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }}
                                                    </td>
                                                    <td class="px-4 py-3 text-sm text-gray-700">
                                                        {{ unit.destination_location || normalizedOriginLocation }}
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                                <div v-if="form.entries.length" class="rounded-xl border border-blue-200 bg-white p-4">
                                    <div class="mb-3 flex items-center justify-between gap-3">
                                        <div>
                                            <p class="text-sm font-semibold text-gray-900">Selected transfer rows</p>
                                            <p class="text-xs text-gray-500">Confirm the destination for each selected unit.</p>
                                        </div>
                                        <span class="rounded-full bg-blue-100 px-2.5 py-1 text-xs font-bold text-blue-800">{{ form.entries.length }} selected</span>
                                    </div>
                                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
                                        <div
                                            v-for="entry in form.entries"
                                            :key="entry.uid"
                                            class="rounded-lg border border-gray-200 bg-gray-50 p-3"
                                        >
                                            <div class="mb-3">
                                                <p class="text-sm font-semibold text-gray-900">{{ entry.serial_no || 'No serial' }}</p>
                                                <p class="text-xs font-mono text-gray-500">{{ entry.barcode || 'No barcode' }}</p>
                                            </div>
                                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Destination Location</label>
                                            <Autocomplete
                                                v-model="entry.destination_location"
                                                :options="storeOptions"
                                                label-key="name"
                                                value-key="value"
                                                placeholder="Select Destination Store"
                                                size="sm"
                                            />
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <template v-else>
                            <div
                                v-for="(entry, index) in form.entries"
                                :key="entry.uid"
                                class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm"
                            >
                                <div class="flex items-center justify-between mb-4">
                                    <div>
                                        <p class="text-sm font-semibold text-gray-900">Item {{ index + 1 }}</p>
                                        <p class="text-xs text-gray-500">{{ selectedAssetLabel || 'Select an asset item above first.' }}</p>
                                    </div>
                                    <button
                                        v-if="!isEditing && form.entries.length > 1"
                                        type="button"
                                        @click="removeEntry(index)"
                                        class="text-xs font-semibold text-red-600 hover:text-red-700"
                                    >
                                        Remove
                                    </button>
                                </div>

                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Serial No</label>
                                        <input type="text" v-model="entry.serial_no" class="block w-full rounded-md border-gray-300 shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Destination Location</label>
                                        <Autocomplete
                                            v-model="entry.destination_location"
                                            :options="storeOptions"
                                            label-key="name"
                                            value-key="value"
                                            placeholder="Select Destination Store"
                                            size="sm"
                                        />
                                    </div>
                                </div>

                                <div class="grid grid-cols-1 xl:grid-cols-2 gap-4 mt-4">
                                    <div class="p-3 bg-gray-50 rounded-xl border border-gray-100">
                                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">
                                            Barcode Generated <span class="text-red-500">*</span>
                                        </label>
                                        <div class="flex rounded-md shadow-sm">
                                            <input
                                                type="text"
                                                v-model="entry.barcode"
                                                @input="entry.qrcode = ''"
                                                class="block w-full rounded-none rounded-l-md focus:ring-blue-500 focus:border-blue-500 text-sm"
                                                :class="entryNeedsBarcode(entry) ? 'border-red-300 bg-red-50' : 'border-gray-300'"
                                            >
                                            <button type="button" @click="generateBarcode(index)" class="inline-flex items-center px-4 rounded-r-md border border-l-0 border-gray-300 bg-white text-blue-600 text-xs font-bold hover:bg-gray-50 uppercase tracking-widest transition-colors">Gen</button>
                                        </div>
                                        <p v-if="entryNeedsBarcode(entry)" class="mt-2 text-xs text-red-600">
                                            Generate a barcode before saving.
                                        </p>
                                        <div v-if="entry.barcode" class="mt-3 p-4 bg-white border border-gray-200 rounded-lg flex justify-center cursor-pointer hover:border-blue-300 transition-all shadow-sm"
                                             @click="openImageViewer(`https://barcode.tec-it.com/barcode.ashx?data=${encodeURIComponent(entry.barcode)}&code=Code128`, `Barcode: ${entry.barcode}`)">
                                            <img :src="`https://bwipjs-api.metafloor.com/?bcid=code128&text=${encodeURIComponent(entry.barcode)}&scale=1&height=10&includetext`" class="max-h-12" :alt="entry.barcode">
                                        </div>
                                    </div>

                                    <div class="p-3 bg-gray-50 rounded-xl border border-gray-100">
                                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">
                                            QR Code Generated <span class="text-red-500">*</span>
                                        </label>
                                        <div class="flex rounded-md shadow-sm">
                                            <input
                                                type="text"
                                                v-model="entry.qrcode"
                                                class="block w-full rounded-none rounded-l-md focus:ring-blue-500 focus:border-blue-500 text-sm"
                                                :class="entryNeedsQrcode(entry) ? 'border-red-300 bg-red-50' : 'border-gray-300'"
                                                placeholder="Generate summary for scanning..."
                                            >
                                            <button type="button" @click="generateQrcode(index)" class="inline-flex items-center px-4 rounded-r-md border border-l-0 border-gray-300 bg-white text-blue-600 text-xs font-bold hover:bg-gray-50 uppercase tracking-widest transition-colors">Gen</button>
                                        </div>
                                        <p v-if="entryNeedsQrcode(entry)" class="mt-2 text-xs text-red-600">
                                            Generate a QR code before saving.
                                        </p>
                                        <div v-if="entry.qrcode" class="mt-3 p-4 bg-white border border-gray-200 rounded-lg flex justify-center cursor-pointer hover:border-blue-300 transition-all shadow-sm"
                                             @click="openImageViewer(`https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=${encodeURIComponent(entry.qrcode)}`, `QR Code Summary`)">
                                            <img :src="`https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=${encodeURIComponent(entry.qrcode)}`" class="w-24 h-24" :alt="entry.qrcode">
                                        </div>
                                    </div>
                                </div>

                                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4 mt-4">
                                    <div class="p-3 bg-gray-50 rounded-lg border border-gray-200">
                                        <label class="block text-xs font-bold text-gray-500 uppercase mb-2">Warranty (Months)</label>
                                        <input type="number" v-model.number="entry.warranty_months" required min="0" class="block w-full rounded-md border-gray-300 shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                                        <p class="mt-1 text-[10px] text-blue-600 font-medium italic">Computed: {{ computedWarrantyDate(entry) }}</p>
                                    </div>
                                    <div class="p-3 bg-gray-50 rounded-lg border border-gray-200">
                                        <label class="block text-xs font-bold text-gray-500 uppercase mb-2">EOL (Months)</label>
                                        <input type="number" v-model.number="entry.eol_months" required min="0" class="block w-full rounded-md border-gray-300 shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                                        <p class="mt-1 text-[10px] text-blue-600 font-medium italic">Computed: {{ computedEolDate(entry) }}</p>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Cost</label>
                                        <input type="number" step="0.01" v-model.number="entry.cost" required min="0" class="block w-full rounded-md border-gray-300 shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Price</label>
                                        <input type="number" step="0.01" v-model.number="entry.price" required min="0" class="block w-full rounded-md border-gray-300 shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm">
                                    </div>
                                </div>
                            </div>
                            </template>
                        </div>
                    </div>

                    <div v-if="isEditing" class="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-6 border-t mt-6">
                        <div class="rounded-lg bg-gray-50 border border-gray-100 px-3 py-2">
                            <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wider">Created By</p>
                            <p class="text-sm font-semibold text-gray-800 truncate">{{ auditUserLabel(editingStockIn?.creator, editingStockIn?.created_by) }}</p>
                        </div>
                        <div class="rounded-lg bg-gray-50 border border-gray-100 px-3 py-2">
                            <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wider">Updated By</p>
                            <p class="text-sm font-semibold text-gray-800 truncate">{{ auditUserLabel(editingStockIn?.updater, editingStockIn?.updated_by) }}</p>
                        </div>
                        <div class="rounded-lg bg-gray-50 border border-gray-100 px-3 py-2">
                            <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wider">Created Date</p>
                            <p class="text-sm font-semibold text-gray-800">{{ formatAuditDate(editingStockIn?.created_at) }}</p>
                        </div>
                        <div class="rounded-lg bg-gray-50 border border-gray-100 px-3 py-2">
                            <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wider">Updated Date</p>
                            <p class="text-sm font-semibold text-gray-800">{{ formatAuditDate(editingStockIn?.updated_at) }}</p>
                        </div>
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

        <!-- Image Viewer Modal -->
        <Modal :show="showImageViewer" max-width="4xl" @close="closeImageViewer">
            <div class="relative bg-black rounded-lg overflow-hidden h-[80vh] flex flex-col">
                <!-- Toolbar -->
                <div class="absolute top-0 left-0 right-0 z-10 flex justify-between items-center p-4 bg-gradient-to-b from-black/50 to-transparent">
                    <h3 class="text-white text-xs sm:text-sm font-medium truncate ml-2 text-shadow">{{ viewerTitle }}</h3>
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
                    
                    <div v-if="viewerLoading" class="absolute inset-0 flex items-center justify-center">
                        <svg class="animate-spin h-10 w-10 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 6.477 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                    </div>

                    <template v-if="viewerImageUrl">
                        <img :src="viewerImageUrl" 
                             class="transition-transform duration-100 ease-linear transform origin-center max-w-none shadow-2xl bg-white p-6 rounded-lg" 
                             :style="{ transform: `scale(${zoomLevel}) translate(${panOffset.x / zoomLevel}px, ${panOffset.y / zoomLevel}px)` }" 
                             @load="viewerLoading = false"
                             @error="handleViewerError"
                             draggable="false">
                    </template>
                    
                    <div v-if="viewerError" class="bg-red-50 p-4 rounded-lg text-red-700 text-sm font-bold flex flex-col items-center">
                        <svg class="w-10 h-10 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" /></svg>
                        Failed to load image. Please check the code content or your internet connection.
                    </div>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>

<script setup>
import { ref, reactive, computed, onMounted, watch } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import DataTable from '@/Components/DataTable.vue'
import Modal from '@/Components/Modal.vue'
import Autocomplete from '@/Components/Autocomplete.vue'
import { ArrowDownTrayIcon, ArrowUpTrayIcon, PlusIcon, PrinterIcon, QrCodeIcon } from '@heroicons/vue/24/outline'
import { usePagination } from '@/Composables/usePagination'
import { useToast } from '@/Composables/useToast'
import { useConfirm } from '@/Composables/useConfirm'
import { usePermission } from '@/Composables/usePermission'
import axios from 'axios'

const props = defineProps({
    stockIns: Object,
    assets: Array,
    stores: Array,
    vendors: Array
})

const { showSuccess, showError } = useToast()
const { confirm } = useConfirm()
const pagination = usePagination(props.stockIns, 'stock-ins.index')
const { hasPermission } = usePermission()
const page = usePage()
const authUserName = computed(() => page.props.auth?.user?.name || '')

const normalizedAssets = computed(() => {
    return (props.assets || []).map(asset => ({
        ...asset,
        brand: asset.brand || 'Unbranded',
        model: asset.model || 'Unspecified Model',
    }))
})

const brandOptions = computed(() => {
    return [...new Set(normalizedAssets.value.map(asset => asset.brand))]
        .sort((a, b) => a.localeCompare(b))
        .map(value => ({ value, name: value }))
})

const modelOptions = computed(() => {
    return [...new Set(
        normalizedAssets.value
            .filter(asset => asset.brand === form.brand)
            .map(asset => asset.model)
    )]
        .sort((a, b) => a.localeCompare(b))
        .map(value => ({ value, name: value }))
})

const assetOptions = computed(() => {
    return normalizedAssets.value
        .filter(asset => asset.brand === form.brand && asset.model === form.model)
        .map(asset => ({
            id: asset.id,
            name: `${asset.item_code} - ${asset.description || asset.model}`
        }))
})

const storeOptions = computed(() => {
    return (props.stores || []).map(store => ({
        value: store.code,
        name: [store.code, store.name].filter(Boolean).join(' - ')
    }))
})
const vendorOptions = computed(() => {
    return (props.vendors || []).map(vendor => ({
        value: vendor.name,
        name: [vendor.code, vendor.name].filter(Boolean).join(' - ')
    }))
})

const normalizeLocationValue = (value) => {
    if (!value) return ''

    const matchedStore = (props.stores || []).find(store =>
        store.code === value || store.name === value
    )

    return matchedStore?.code || value
}

const showModal = ref(false)
const showImportModal = ref(false)
const isEditing = ref(false)
const currentId = ref(null)
const editingStockIn = ref(null)
const importFileInput = ref(null)
const selectedImportFile = ref(null)
const isImporting = ref(false)
const importResults = ref(null)

const auditUserLabel = (user, userId = null) => {
    if (user?.name || user?.email) {
        return user.name || user.email
    }

    if (userId) {
        return `User #${userId}`
    }

    return 'System'
}

const formatAuditDate = (value) => {
    if (!value) return '-'

    const date = new Date(value)

    if (Number.isNaN(date.getTime())) return '-'

    return date.toLocaleString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        hour12: true,
    })
}

const padDatePart = (value) => String(value).padStart(2, '0')

const toLocalDateKey = (date) => {
    if (!(date instanceof Date) || Number.isNaN(date.getTime())) return ''

    return [
        date.getFullYear(),
        padDatePart(date.getMonth() + 1),
        padDatePart(date.getDate()),
    ].join('-')
}

const getToday = () => toLocalDateKey(new Date())

const form = reactive({
    receive_date: getToday(),
    dr_no: '',
    dr_date: getToday(),
    vendor: '',
    origin_location: '',
    received_by: authUserName.value,
    status: 'For Posting',
    brand: '',
    model: '',
    asset_id: '',
    quantity: 1,
    entries: []
})

let entryUid = 0
const codeValidationAttempted = ref(false)
const supplierLocationCode = 'SUPPLIER'
const availableStock = ref(null)
const availableStockError = ref('')
const isLoadingAvailableStock = ref(false)
const selectedSourceIds = ref([])
const bulkDestinationLocation = ref('')
let availableStockRequestId = 0

const createEntry = (overrides = {}) => ({
    uid: `entry-${entryUid++}`,
    source_stock_in_id: null,
    serial_no: '',
    barcode: '',
    qrcode: '',
    warranty_months: 12,
    eol_months: 60,
    cost: 0,
    price: 0,
    destination_location: '',
    ...overrides
})

const hasGeneratedCode = (value) => String(value || '').trim().length > 0

const entryNeedsBarcode = (entry) => codeValidationAttempted.value && !hasGeneratedCode(entry?.barcode)
const entryNeedsQrcode = (entry) => codeValidationAttempted.value && !hasGeneratedCode(entry?.qrcode)

const missingGeneratedCodeRows = () => form.entries
    .map((entry, index) => {
        const missing = []

        if (!hasGeneratedCode(entry.barcode)) missing.push('barcode')
        if (!hasGeneratedCode(entry.qrcode)) missing.push('QR code')

        return missing.length ? { row: index + 1, missing } : null
    })
    .filter(Boolean)

const describeMissingGeneratedCodes = (missingRows) => {
    const preview = missingRows
        .slice(0, 5)
        .map(({ row, missing }) => `row ${row} (${missing.join(' and ')})`)
        .join(', ')
    const remaining = missingRows.length > 5 ? `, and ${missingRows.length - 5} more` : ''

    return `${preview}${remaining}`
}

const validateGeneratedCodes = () => {
    codeValidationAttempted.value = true

    const missingRows = missingGeneratedCodeRows()
    if (!missingRows.length) return true

    const action = isEditing.value ? 'updating' : 'saving'
    showError(`Generate required codes before ${action}: ${describeMissingGeneratedCodes(missingRows)}.`)
    return false
}

const selectedAsset = computed(() => normalizedAssets.value.find(asset => asset.id == form.asset_id) || null)
const selectedAssetLabel = computed(() => selectedAsset.value ? `${selectedAsset.value.item_code} - ${selectedAsset.value.description || selectedAsset.value.model}` : '')
const normalizedOriginLocation = computed(() => normalizeLocationValue(form.origin_location))
const isTransferMode = computed(() => !!normalizedOriginLocation.value && normalizedOriginLocation.value !== supplierLocationCode)
const isFixedTransfer = computed(() => isTransferMode.value && selectedAsset.value?.type === 'Fixed')
const isConsumableTransfer = computed(() => isTransferMode.value && selectedAsset.value?.type === 'Consumables')
const availableSoh = computed(() => Number(availableStock.value?.soh || 0))
const availableUnits = computed(() => availableStock.value?.available_units || [])
const selectedSourceIdSet = computed(() => new Set(selectedSourceIds.value.map(id => Number(id))))
const sourceRowsById = computed(() => new Map(availableUnits.value.map(unit => [Number(unit.id), unit])))
const normalizedQuantity = computed(() => Math.max(1, parseInt(form.quantity || 1, 10)))
const pendingDetailRemovalCount = computed(() => {
    if (!isEditing.value) return 0

    return Math.max(0, form.entries.length - normalizedQuantity.value)
})
const toDateKey = (value) => {
    if (!value) return ''
    if (value instanceof Date) return toLocalDateKey(value)

    const normalized = String(value).trim()
    if (/^\d{4}-\d{2}-\d{2}$/.test(normalized)) return normalized

    const parsed = new Date(normalized)
    if (!Number.isNaN(parsed.getTime())) return toLocalDateKey(parsed)

    const dateMatch = normalized.match(/^(\d{4}-\d{2}-\d{2})/)
    return dateMatch ? dateMatch[1] : ''
}

const toTimestamp = (value) => {
    const timestamp = new Date(value || 0).getTime()
    return Number.isNaN(timestamp) ? 0 : timestamp
}

const syncEntriesToQuantity = (quantity) => {
    const target = Math.max(1, parseInt(quantity || 1, 10))
    form.quantity = target

    if (!form.entries.length) {
        form.entries = [createEntry(isEditing.value ? {} : getEntryDefaults())]
    }

    while (form.entries.length < target) {
        form.entries.push(createEntry(getEntryDefaults()))
    }

    while (form.entries.length > target) {
        form.entries.pop()
    }
}

const getEntryDefaults = () => ({
    cost: selectedAsset.value?.cost || 0,
    price: 0,
    warranty_months: 12,
    eol_months: 60,
})

const clearAvailableStock = () => {
    availableStock.value = null
    availableStockError.value = ''
    selectedSourceIds.value = []
    bulkDestinationLocation.value = ''
}

const resetFixedTransferEntries = () => {
    selectedSourceIds.value = []
    form.entries = []
    form.quantity = 0
}

const unitToTransferEntry = (unit, existing = null) => createEntry({
    source_stock_in_id: unit.id,
    serial_no: unit.serial_no || '',
    barcode: unit.barcode || '',
    qrcode: unit.qrcode || '',
    warranty_months: Number(unit.warranty_months || 0),
    eol_months: Number(unit.eol_months || 0),
    cost: Number(unit.cost || 0),
    price: Number(unit.price || 0),
    destination_location: normalizeLocationValue(existing?.destination_location || bulkDestinationLocation.value || ''),
    ...(existing?.uid ? { uid: existing.uid } : {}),
})

const syncFixedTransferEntries = () => {
    if (!isFixedTransfer.value || isEditing.value) return

    const existingBySource = new Map(
        form.entries
            .filter(entry => entry.source_stock_in_id)
            .map(entry => [Number(entry.source_stock_in_id), entry])
    )

    form.entries = selectedSourceIds.value
        .map(id => sourceRowsById.value.get(Number(id)))
        .filter(Boolean)
        .map(unit => unitToTransferEntry(unit, existingBySource.get(Number(unit.id))))

    form.quantity = form.entries.length
}

const isSourceSelected = (unit) => selectedSourceIdSet.value.has(Number(unit.id))

const toggleSourceUnit = (unit) => {
    const sourceId = Number(unit.id)
    const current = new Set(selectedSourceIds.value.map(id => Number(id)))

    if (current.has(sourceId)) {
        current.delete(sourceId)
    } else {
        current.add(sourceId)
    }

    selectedSourceIds.value = Array.from(current)
    syncFixedTransferEntries()
}

const applyBulkDestination = () => {
    if (!isFixedTransfer.value || isEditing.value) return

    const destination = normalizeLocationValue(bulkDestinationLocation.value)
    form.entries = form.entries.map(entry => ({
        ...entry,
        destination_location: destination,
    }))
}

const fetchAvailableStock = async () => {
    const requestId = ++availableStockRequestId

    if (isEditing.value || !isTransferMode.value || !form.asset_id) {
        isLoadingAvailableStock.value = false
        clearAvailableStock()
        return
    }

    isLoadingAvailableStock.value = true
    availableStockError.value = ''

    try {
        const response = await axios.get(route('stock-ins.available-stock'), {
            params: {
                asset_id: form.asset_id,
                origin_location: normalizedOriginLocation.value,
            },
        })

        if (requestId !== availableStockRequestId) return

        availableStock.value = response.data
        selectedSourceIds.value = []

        if (isFixedTransfer.value) {
            resetFixedTransferEntries()
        } else if (isConsumableTransfer.value && availableSoh.value > 0 && form.quantity > availableSoh.value) {
            syncEntriesToQuantity(availableSoh.value)
        }
    } catch (error) {
        if (requestId !== availableStockRequestId) return

        availableStock.value = null
        availableStockError.value = error.response?.data?.message || 'Unable to load stock on hand for the selected origin.'

        if (isFixedTransfer.value) {
            resetFixedTransferEntries()
        }
    } finally {
        if (requestId === availableStockRequestId) {
            isLoadingAvailableStock.value = false
        }
    }
}

const validateTransferStock = () => {
    if (!isTransferMode.value || isEditing.value) return true

    if (!form.asset_id) {
        showError('Select an asset item before preparing a transfer.')
        return false
    }

    if (isLoadingAvailableStock.value) {
        showError('Please wait for the current SOH lookup to finish.')
        return false
    }

    if (availableStockError.value) {
        showError(availableStockError.value)
        return false
    }

    if (availableSoh.value <= 0) {
        showError(`No stock on hand is available at ${normalizedOriginLocation.value}.`)
        return false
    }

    if (isFixedTransfer.value && form.entries.length === 0) {
        showError('Select at least one available fixed asset unit to transfer.')
        return false
    }

    if (form.entries.length > availableSoh.value) {
        showError(`Only ${availableSoh.value} item(s) are available at ${normalizedOriginLocation.value}.`)
        return false
    }

    const invalidDestination = form.entries.find(entry => {
        const destination = normalizeLocationValue(entry.destination_location)
        return !destination || destination === normalizedOriginLocation.value
    })

    if (invalidDestination) {
        showError('Select a destination location different from the origin for every transfer row.')
        return false
    }

    return true
}

const onBrandChange = () => {
    form.model = ''
    form.asset_id = ''
    clearAvailableStock()
    form.entries = form.entries.map(entry => ({
        ...entry,
        barcode: '',
        qrcode: '',
    }))
}

const onModelChange = () => {
    form.asset_id = ''
    clearAvailableStock()
    form.entries = form.entries.map(entry => ({
        ...entry,
        barcode: '',
        qrcode: '',
    }))
}

const onAssetChange = () => {
    clearAvailableStock()
    const defaults = getEntryDefaults()
    form.entries = form.entries.map(entry => ({
        ...entry,
        barcode: '',
        qrcode: '',
        cost: entry.cost || defaults.cost,
        price: entry.price || defaults.price,
    }))
}

const generateBarcode = (index) => {
    const asset = selectedAsset.value
    const prefix = asset ? asset.item_code : 'ST'
    const entry = form.entries[index]
    if (!entry) return
    entry.barcode = `${prefix}-${Date.now()}-${index + 1}`
    entry.qrcode = ''
}

const generateQrcode = (index) => {
    const asset = selectedAsset.value
    const entry = form.entries[index]
    if (!entry) return

    if (!asset) {
        showError('Please select an asset first');
        return
    }

    if (!hasGeneratedCode(entry.barcode)) {
        showError('Generate a barcode before generating the QR code.')
        return
    }

    const details = [
        `Item Code: ${asset.item_code}`,
        `Description: ${asset.description || 'N/A'}`,
        `Brand: ${asset.brand || 'N/A'}`,
        `Model: ${asset.model || 'N/A'}`,
        `Vendor: ${form.vendor || 'N/A'}`,
        `Received Date: ${formatDateNumeric(form.receive_date)}`,
        `DR No: ${form.dr_no || 'N/A'}`,
        `DR Date: ${formatDateNumeric(form.dr_date)}`,
        `Received By: ${form.received_by || 'N/A'}`,
        `Serial No: ${entry?.serial_no || 'N/A'}`,
        `Barcode: ${entry?.barcode || 'N/A'}`,
        `Destination Location: ${entry?.destination_location || 'N/A'}`,
        `Warranty Until: ${formatDateNumeric(addMonths(form.receive_date, entry?.warranty_months))}`,
        `EOL: ${formatDateNumeric(addMonths(form.receive_date, entry?.eol_months))}`
    ]
    
    entry.qrcode = details.join('\n')
}

const parseDateOnly = (value) => {
    if (!value) return null
    if (value instanceof Date) return new Date(value.getTime())

    const normalized = String(value).trim()
    const match = normalized.match(/^(\d{4})-(\d{2})-(\d{2})$/)

    if (match) {
        const [, year, month, day] = match
        return new Date(Number(year), Number(month) - 1, Number(day))
    }

    const parsed = new Date(normalized)
    if (Number.isNaN(parsed.getTime())) return null

    return new Date(parsed.getFullYear(), parsed.getMonth(), parsed.getDate())
}

const addMonths = (dateStr, months) => {
    const date = parseDateOnly(dateStr)
    if (!date) return null

    const result = new Date(date.getFullYear(), date.getMonth(), date.getDate())
    result.setMonth(result.getMonth() + parseInt(months || 0, 10))
    return result
}

const formatDate = (date) => {
    const parsed = parseDateOnly(date)
    if (!parsed) return '-';
    return new Intl.DateTimeFormat('en-US', { month: 'short', day: '2-digit', year: 'numeric' }).format(parsed);
}

const formatDateNumeric = (date) => {
    const parsed = parseDateOnly(date)
    if (!parsed) return 'N/A';
    return new Intl.DateTimeFormat('en-US', {
        month: '2-digit',
        day: '2-digit',
        year: 'numeric',
    }).format(parsed);
}

const computedWarrantyDate = (entry) => {
    const date = addMonths(form.receive_date, entry?.warranty_months)
    return date ? formatDate(date) : '-'
}

const computedEolDate = (entry) => {
    const date = addMonths(form.receive_date, entry?.eol_months)
    return date ? formatDate(date) : '-'
}

const resetForm = () => {
    codeValidationAttempted.value = false
    clearAvailableStock()
    Object.assign(form, {
        receive_date: getToday(),
        dr_no: '',
        dr_date: getToday(),
        vendor: '',
        origin_location: '',
        received_by: authUserName.value,
        status: 'For Posting',
        brand: '',
        model: '',
        asset_id: '',
        quantity: 1,
        entries: [createEntry()],
    })
}

const openCreateModal = () => {
    isEditing.value = false
    currentId.value = null
    editingStockIn.value = null
    resetForm()
    showModal.value = true
}

const editHeaderItem = async (header) => {
    try {
        const response = await axios.get(route('stock-ins.show', header.id))
        const items = response.data
        if (items.length > 0) {
            const auditSource = {
                ...header,
                creator: items[0].creator,
                created_by: items[0].created_by,
                updater: items[0].updater,
                updated_by: items[0].updated_by
            }
            editItem(items[0], items.reduce((sum, i) => sum + Number(i.quantity), 0), items, auditSource)
        }
    } catch (error) {
        showError('Could not fetch stock details. Please try again.')
    }
}

const editItem = (item, aggregatedQuantity = item.quantity, relatedRows = [item], auditSource = item) => {
    const asset = normalizedAssets.value.find(a => a.id == item.asset_id)
    codeValidationAttempted.value = false
    isEditing.value = true
    currentId.value = item.id
    editingStockIn.value = auditSource
    Object.assign(form, {
        receive_date: toDateKey(item.receive_date),
        dr_no: item.dr_no || '',
        dr_date: toDateKey(item.dr_date),
        vendor: item.vendor || '',
        origin_location: normalizeLocationValue(item.origin_location),
        received_by: item.received_by || authUserName.value,
        status: item.status || 'For Posting',
        brand: asset?.brand || '',
        model: asset?.model || '',
        asset_id: item.asset_id,
        quantity: aggregatedQuantity,
        entries: relatedRows.map(row => createEntry({
            source_stock_in_id: row.source_stock_in_id || null,
            serial_no: row.serial_no,
            barcode: row.barcode || '',
            qrcode: row.qrcode || '',
            warranty_months: row.warranty_months,
            eol_months: row.eol_months,
            cost: Number(row.cost || 0),
            price: Number(row.price || 0),
            destination_location: normalizeLocationValue(row.destination_location || row.location),
        }))
    })
    showModal.value = true
}

const closeModal = () => {
    showModal.value = false
    codeValidationAttempted.value = false
}

const openImportModal = () => {
    selectedImportFile.value = null
    importResults.value = null
    if (importFileInput.value) {
        importFileInput.value.value = ''
    }
    showImportModal.value = true
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
    importResults.value = null
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

const submitImport = async () => {
    if (!selectedImportFile.value || isImporting.value) return

    isImporting.value = true
    importResults.value = null

    const formData = new FormData()
    formData.append('file', selectedImportFile.value)

    try {
        const response = await axios.post(route('stock-ins.import'), formData, {
            headers: { 'Content-Type': 'multipart/form-data' }
        })

        importResults.value = response.data

        if (response.data.imported > 0) {
            showSuccess(`Imported ${response.data.imported} stock-in row${response.data.imported > 1 ? 's' : ''} successfully`)
            router.reload({ only: ['stockIns'] })
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

const printStockInCodes = (type) => {
    const stockInId = editingStockIn.value?.id || currentId.value

    if (!stockInId) {
        showError('Open an existing stock-in record before printing.')
        return
    }

    const endpoint = type === 'qrcodes' ? 'print-qrcodes' : 'print-barcodes'
    const popup = window.open(`/stock-ins/${stockInId}/${endpoint}`, '_blank', 'noopener,noreferrer')

    if (!popup) {
        showError('Unable to open the PDF. Please allow pop-ups for this site.')
    }
}

const postHeaderItem = async (item) => {
    const confirmed = await confirm({
        title: 'Update Stock In Status',
        message: `Mark stock-in header ${item.asset?.item_code || ''}${item.dr_no ? ` with DR No. ${item.dr_no}` : ''} as Posted?`,
    })

    if (!confirmed) return

    router.post(route('stock-ins.post', item.id), {}, {
        preserveScroll: true,
        onError: (errors) => {
            const errorMessage = Object.values(errors).flat().join(', ') || 'Unable to update stock in status'
            showError(errorMessage)
        }
    })
}

const confirmPendingDetailRemoval = async () => {
    if (pendingDetailRemovalCount.value <= 0) return true

    const target = normalizedQuantity.value
    const currentRows = form.entries.length
    const confirmed = await confirm({
        title: 'Reduce Stock Detail Rows',
        message: `Qty is set to ${target}, but this header currently has ${currentRows} detail row(s). Saving will remove the last ${pendingDetailRemovalCount.value} row(s) from this stock-in header. Continue?`,
        confirmLabel: 'Remove Rows and Save',
        cancelLabel: 'Keep Existing Rows',
        variant: 'danger',
    })

    if (!confirmed) {
        form.quantity = currentRows
        return false
    }

    syncEntriesToQuantity(target)
    return true
}

const submitForm = async (statusOverride = form.status || 'For Posting') => {
    if (!validateTransferStock()) return
    if (!(await confirmPendingDetailRemoval())) return
    if (!validateGeneratedCodes()) return

    const url = isEditing.value ? route('stock-ins.update', currentId.value) : route('stock-ins.store')
    const method = isEditing.value ? 'put' : 'post'
    const payload = {
        receive_date: form.receive_date,
        dr_no: form.dr_no,
        dr_date: form.dr_date || null,
        vendor: form.vendor,
        origin_location: form.origin_location || null,
        received_by: form.received_by,
        status: statusOverride,
        brand: form.brand,
        model: form.model,
        asset_id: form.asset_id,
        quantity: form.quantity,
        entries: form.entries.map(({ uid, ...entry }) => ({
            ...entry,
        })),
    }

    if (isEditing.value) {
        Object.assign(payload, {
            header_mode: true,
            quantity: form.quantity,
        })
    }

    router[method](url, payload, {
        onSuccess: () => {
            closeModal()
        },
        onError: (errors) => {
            showError(Object.values(errors)[0])
        }
    })
}

const removeEntry = (index) => {
    if (isEditing.value || form.entries.length === 1) return
    form.entries.splice(index, 1)
    form.quantity = form.entries.length
}

const deleteItem = async (item) => {
    const isGroup = item.record_count > 1;
    const message = isGroup 
        ? `This header contains ${item.record_count} items. Deleting this will remove ALL associated stock items. Please double check the entries before proceeding.`
        : 'Are you sure you want to delete this stock-in record?';

    const confirmed = await confirm({
        title: isGroup ? 'Delete Entire Stock Group' : 'Delete Stock In',
        message: message,
    })
    
    if (confirmed) {
        router.delete(route('stock-ins.destroy', item.id), {
            data: { delete_group: isGroup },
            onSuccess: () => showSuccess('Stock In deleted successfully'),
            onError: (errors) => showError(Object.values(errors)[0] || 'Unable to delete record')
        })
    }
}

// Image Viewer State
const showImageViewer = ref(false);
const viewerImageUrl = ref(null);
const viewerTitle = ref('');
const zoomLevel = ref(1);
const isDragging = ref(false);
const panOffset = ref({ x: 0, y: 0 });
const viewerLoading = ref(false);
const viewerError = ref(false);

const openImageViewer = (url, title) => {
    viewerError.value = false;
    viewerLoading.value = true;
    viewerImageUrl.value = url;
    viewerTitle.value = title;
    zoomLevel.value = 1;
    panOffset.value = { x: 0, y: 0 };
    showImageViewer.value = true;
};

const closeImageViewer = () => {
    showImageViewer.value = false;
    viewerImageUrl.value = null;
    viewerLoading.value = false;
    viewerError.value = false;
};

const handleViewerError = () => {
    viewerLoading.value = false;
    viewerError.value = true;
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

onMounted(() => {
    pagination.updateData(props.stockIns)
})

watch(() => props.stockIns, (newVal) => {
    pagination.updateData(newVal)
}, { deep: true })

watch(() => form.quantity, (newVal) => {
    if (isFixedTransfer.value && !isEditing.value) return

    if (isConsumableTransfer.value && !isEditing.value && availableSoh.value > 0 && Number(newVal || 0) > availableSoh.value) {
        syncEntriesToQuantity(availableSoh.value)
        return
    }

    if (isEditing.value) {
        const target = Math.max(1, parseInt(newVal || 1, 10))

        if (Number(newVal) !== target) {
            form.quantity = target
        }

        if (target < form.entries.length) {
            return
        }
    }

    syncEntriesToQuantity(newVal)
})

watch([() => form.origin_location, () => form.asset_id], () => {
    if (isEditing.value) return

    if (isFixedTransfer.value) {
        resetFixedTransferEntries()
    } else {
        selectedSourceIds.value = []

        if (!form.entries.length || form.entries.some(entry => entry.source_stock_in_id)) {
            form.entries = [createEntry(getEntryDefaults())]
            form.quantity = 1
        }
    }

    fetchAvailableStock()
})

watch(bulkDestinationLocation, () => {
    applyBulkDestination()
})
</script>
