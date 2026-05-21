<template>
    <AppLayout title="Stock Transaction">
        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

                <!-- Summary Cards -->
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-xs font-bold text-gray-500 uppercase tracking-wider">Total Stock Units</p>
                                <p class="text-2xl font-black text-gray-900 mt-1">{{ summary.total_qty }}</p>
                            </div>
                            <div class="p-3 bg-slate-50 rounded-lg">
                                <svg class="w-6 h-6 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                                </svg>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-xs font-bold text-gray-500 uppercase tracking-wider">Posted Units</p>
                                <p class="text-2xl font-black text-emerald-600 mt-1">{{ summary.posted_qty }}</p>
                            </div>
                            <div class="p-3 bg-emerald-50 rounded-lg">
                                <svg class="w-6 h-6 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-xs font-bold text-gray-500 uppercase tracking-wider">For Posting Units</p>
                                <p class="text-2xl font-black text-amber-600 mt-1">{{ summary.for_posting_qty }}</p>
                            </div>
                            <div class="p-3 bg-amber-50 rounded-lg">
                                <svg class="w-6 h-6 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-xs font-bold text-gray-500 uppercase tracking-wider">Total Records</p>
                                <p class="text-2xl font-black text-blue-600 mt-1">{{ summary.total_records }}</p>
                            </div>
                            <div class="p-3 bg-blue-50 rounded-lg">
                                <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                                </svg>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filters Panel -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <div class="grid grid-cols-1 md:grid-cols-6 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Category</label>
                            <select v-model="filterForm.category_id" @change="applyFilters" class="w-full border-gray-300 rounded-lg text-sm focus:ring-blue-500 focus:border-blue-500">
                                <option :value="null">All Categories</option>
                                <option v-for="cat in categories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Brand</label>
                            <select v-model="filterForm.brand" @change="applyFilters" class="w-full border-gray-300 rounded-lg text-sm focus:ring-blue-500 focus:border-blue-500">
                                <option :value="null">All Brands</option>
                                <option v-for="b in brands" :key="b" :value="b">{{ b }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Type</label>
                            <select v-model="filterForm.type" @change="applyFilters" class="w-full border-gray-300 rounded-lg text-sm focus:ring-blue-500 focus:border-blue-500">
                                <option :value="null">All Types</option>
                                <option value="Fixed">Fixed</option>
                                <option value="Consumables">Consumables</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Destination</label>
                            <select v-model="filterForm.location" @change="applyFilters" class="w-full border-gray-300 rounded-lg text-sm focus:ring-blue-500 focus:border-blue-500">
                                <option :value="null">All Locations</option>
                                <option v-for="loc in locations" :key="loc" :value="loc">{{ loc }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Status</label>
                            <MultiAutocomplete
                                v-model="statusFilter"
                                :options="statusOptions"
                                label-key="label"
                                value-key="value"
                                placeholder="All statuses..."
                            />
                        </div>
                        <div class="flex items-end">
                            <button @click="resetFilters" class="w-full px-4 py-2 bg-gray-100 text-gray-600 text-sm font-bold rounded-lg hover:bg-gray-200 transition-colors">
                                Reset Filters
                            </button>
                        </div>
                    </div>
                </div>

                <DataTable
                    title="Stock Transaction Headers"
                    subtitle="Manage stock movements (In/Transfers) and their quantity"
                    search-placeholder="Search by DR, Serial, Item Code or Desc..."
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
                        <template v-for="group in groupRowsByLocation(data)" :key="group.location">
                            <!-- Location Group Header -->
                            <tr class="bg-slate-50">
                                <td colspan="6" class="px-6 py-3 border-y border-slate-200">
                                    <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                                        <button
                                            type="button"
                                            @click="toggleLocation(group.location)"
                                            class="flex w-fit items-center gap-2 text-left"
                                            :aria-expanded="!isLocationCollapsed(group.location)"
                                        >
                                            <svg class="h-4 w-4 text-slate-500 transition-transform"
                                                 :class="isLocationCollapsed(group.location) ? '-rotate-90' : 'rotate-0'"
                                                 fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                            </svg>
                                            <span class="text-sm font-black uppercase tracking-wide text-slate-900">{{ group.location }}</span>
                                        </button>

                                        <div class="grid grid-cols-1 gap-2 text-xs sm:grid-cols-3 lg:min-w-[520px]">
                                            <div class="rounded-md border border-slate-200 bg-white px-3 py-2">
                                                <span class="block font-bold uppercase tracking-wider text-slate-400">Items</span>
                                                <span class="font-black text-slate-900">{{ group.summary.item_count }}</span>
                                            </div>
                                            <div class="rounded-md border border-emerald-100 bg-emerald-50 px-3 py-2">
                                                <span class="block font-bold uppercase tracking-wider text-emerald-600">Stock on Hand</span>
                                                <span class="font-black text-emerald-800">{{ group.summary.total_soh }}</span>
                                            </div>
                                            <div class="rounded-md border border-indigo-100 bg-indigo-50 px-3 py-2">
                                                <span class="block font-bold uppercase tracking-wider text-indigo-600">Inventory Value</span>
                                                <span class="font-black text-indigo-800">{{ formatCurrency(group.summary.total_value) }}</span>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>

                            <!-- Group Rows -->
                            <template v-if="!isLocationCollapsed(group.location)">
                                <tr v-for="item in group.rows" :key="item.id" class="hover:bg-gray-50">
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
                                                v-if="hasPermission('stock_ins.edit') && item.status !== 'Posted'"
                                                @click="editHeaderItem(item)"
                                                class="p-2 text-blue-600 hover:text-blue-900 hover:bg-blue-50 rounded-full transition-colors"
                                                title="Edit"
                                            >
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                                </svg>
                                            </button>
                                            <button
                                                v-if="hasPermission('stock_ins.delete') && item.status !== 'Posted'"
                                                @click="deleteItem(item)"
                                                class="p-2 text-red-600 hover:text-red-900 hover:bg-red-50 rounded-full transition-colors"
                                                title="Delete"
                                            >
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                </svg>
                                            </button>
                                            <button
                                                v-if="item.status === 'Posted'"
                                                @click="viewHeaderItem(item)"
                                                class="p-2 text-gray-600 hover:text-gray-900 hover:bg-gray-50 rounded-full transition-colors"
                                                title="View"
                                            >
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                                </svg>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </template>
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
        <Modal :show="showModal" @close="closeModal" max-width="4xl" :closeable="false">
            <div class="p-6">
                <h3 class="text-lg font-bold text-gray-900 mb-4">{{ readOnlyMode ? 'View Stock In' : (isEditing ? 'Edit Stock In' : 'Add Stock In') }}</h3>
                <form @submit.prevent="submitForm()" class="space-y-4">
                    <p v-if="readOnlyMode" class="rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm font-semibold text-blue-800">
                        This stock-in record is read-only because it has already been posted.
                    </p>
                    <div class="space-y-4">
                    <fieldset :disabled="readOnlyMode" class="space-y-4 disabled:opacity-90">
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

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Vendor</label>
                                <Autocomplete
                                    v-model="form.vendor"
                                    :options="vendorOptions"
                                    label-key="name"
                                    value-key="value"
                                    placeholder="Select Vendor"
                                    :disabled="readOnlyMode"
                                />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Origin Location</label>
                                <div class="mt-1 flex items-center h-[38px] px-3 rounded-md border border-gray-200 bg-gray-50 gap-2">
                                    <span class="w-2 h-2 rounded-full bg-emerald-500 flex-shrink-0"></span>
                                    <span class="text-sm font-semibold text-gray-700">Supplier</span>
                                    <span v-if="form.vendor" class="text-xs text-gray-400 truncate">({{ form.vendor }})</span>
                                </div>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">
                                    Destination Location <span class="text-red-500">*</span>
                                </label>
                                <Autocomplete
                                    v-model="headerDestinationLocation"
                                    :options="storeOptions"
                                    label-key="name"
                                    value-key="value"
                                    placeholder="Select Destination Store"
                                    :disabled="readOnlyMode"
                                />
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700">Memo / Remarks</label>
                            <textarea
                                v-model="form.memo_remarks"
                                rows="3"
                                maxlength="2000"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm resize-none"
                                placeholder="Optional notes or remarks for this stock transaction..."
                            ></textarea>
                        </div>

                        <!-- Asset Selection Table -->
                        <!-- SOH Panel at Destination (informational, create mode only) -->
                        <div v-if="!isEditing && headerDestinationLocation" class="rounded-xl border border-indigo-100 bg-indigo-50 p-4">
                            <div class="flex items-center justify-between mb-3">
                                <div>
                                    <h4 class="text-xs font-black uppercase tracking-widest text-indigo-700">Current Stock at {{ headerDestinationLocation }}</h4>
                                    <p class="text-[10px] text-indigo-500 mt-0.5">Informational — existing posted stock at this destination.</p>
                                </div>
                                <span v-if="isLoadingDestinationStock" class="text-[10px] text-indigo-400 animate-pulse">Loading...</span>
                                <span v-else class="text-[10px] font-bold text-indigo-600">{{ destinationStockItems.length }} item type(s)</span>
                            </div>
                            <div v-if="!isLoadingDestinationStock && destinationStockItems.length > 0" class="overflow-hidden rounded-lg border border-indigo-200 bg-white max-h-44 overflow-y-auto">
                                <table class="min-w-full divide-y divide-indigo-50">
                                    <thead class="bg-indigo-50/60 sticky top-0">
                                        <tr>
                                            <th class="px-3 py-1.5 text-left text-[9px] font-bold uppercase tracking-wider text-indigo-400">Item Code</th>
                                            <th class="px-3 py-1.5 text-left text-[9px] font-bold uppercase tracking-wider text-indigo-400">Asset</th>
                                            <th class="px-3 py-1.5 text-right text-[9px] font-bold uppercase tracking-wider text-indigo-400">SOH</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-indigo-50">
                                        <tr v-for="item in destinationStockItems" :key="item.id" class="hover:bg-indigo-50/30">
                                            <td class="px-3 py-1.5 text-xs font-mono font-semibold text-gray-800">{{ item.item_code }}</td>
                                            <td class="px-3 py-1.5">
                                                <p class="text-xs font-semibold text-gray-800">{{ item.brand }} {{ item.model }}</p>
                                                <p class="text-[10px] text-gray-400 truncate max-w-xs">{{ item.description }}</p>
                                            </td>
                                            <td class="px-3 py-1.5 text-right text-xs font-black text-indigo-700">{{ item.soh }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <div v-else-if="!isLoadingDestinationStock" class="text-center py-3 text-[10px] text-indigo-400 font-semibold">
                                No posted stock at this destination yet.
                            </div>
                        </div>

                        <!-- Asset Items Section (Create Mode Only) -->
                        <div v-if="!isEditing" class="space-y-4">
                            <div class="flex items-center justify-between">
                                <div>
                                    <h4 class="text-sm font-semibold text-gray-900">Asset Items</h4>
                                    <p class="text-xs text-gray-500">{{ createModalAssets.length }} asset type(s), {{ form.entries.length }} unit(s) added to this stock-in transaction.</p>
                                </div>
                                <button
                                    type="button"
                                    @click="showAssetPicker = !showAssetPicker"
                                    class="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-3 py-2 text-xs font-bold text-white shadow-sm hover:bg-blue-700 transition-colors"
                                >
                                    <PlusIcon class="h-3.5 w-3.5" />
                                    Add Asset Item
                                </button>
                            </div>

                            <!-- Asset Picker Panel -->
                            <div v-if="showAssetPicker" class="rounded-xl border border-blue-200 bg-blue-50/40 p-4 space-y-3">
                                <div class="relative">
                                    <input
                                        type="text"
                                        v-model="assetSearch"
                                        placeholder="Search by item code, brand, model or description..."
                                        class="w-full pl-9 pr-10 py-2 border-blue-300 rounded-lg text-sm focus:ring-blue-500 focus:border-blue-500 bg-white shadow-sm"
                                    >
                                    <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none">
                                        <svg class="w-4 h-4 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                        </svg>
                                    </div>
                                    <button type="button" @click="showAssetPicker = false; assetSearch = ''" class="absolute inset-y-0 right-0 flex items-center pr-3 text-gray-400 hover:text-gray-600 transition-colors">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                                    </button>
                                </div>
                                <div v-if="assetSearch && filteredAssets.length > 0" class="overflow-hidden rounded-lg border border-blue-200 bg-white shadow-sm">
                                    <table class="min-w-full divide-y divide-gray-100">
                                        <thead class="bg-gray-50">
                                            <tr>
                                                <th class="px-4 py-2 text-left text-[10px] font-bold uppercase tracking-wider text-gray-500">Item Code</th>
                                                <th class="px-4 py-2 text-left text-[10px] font-bold uppercase tracking-wider text-gray-500">Asset</th>
                                                <th class="px-4 py-2 text-center text-[10px] font-bold uppercase tracking-wider text-gray-500">Type</th>
                                                <th class="px-4 py-2 text-right text-[10px] font-bold uppercase tracking-wider text-gray-500">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-50">
                                            <tr v-for="asset in filteredAssets" :key="asset.id" class="hover:bg-blue-50/50">
                                                <td class="px-4 py-2.5 text-xs font-mono font-semibold text-gray-900">{{ asset.item_code }}</td>
                                                <td class="px-4 py-2.5">
                                                    <p class="text-xs font-bold text-gray-900">{{ asset.brand }} {{ asset.model }}</p>
                                                    <p class="text-[10px] text-gray-400 truncate max-w-xs">{{ asset.description }}</p>
                                                </td>
                                                <td class="px-4 py-2.5 text-center">
                                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[9px] font-bold uppercase"
                                                          :class="asset.type === 'Consumables' ? 'bg-amber-100 text-amber-700' : 'bg-blue-100 text-blue-700'">
                                                        {{ asset.type }}
                                                    </span>
                                                </td>
                                                <td class="px-4 py-2.5 text-right">
                                                    <button
                                                        type="button"
                                                        @click="pickAsset(asset)"
                                                        class="inline-flex items-center gap-1 rounded-md bg-blue-600 px-2.5 py-1 text-[10px] font-black text-white uppercase tracking-wider hover:bg-blue-700 transition-colors"
                                                    >
                                                        <PlusIcon class="w-3 h-3" />
                                                        Add
                                                    </button>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                                <div v-else-if="assetSearch && filteredAssets.length === 0" class="text-center py-3 text-xs text-gray-400 font-semibold">
                                    No assets found matching "{{ assetSearch }}".
                                </div>
                                <div v-else class="text-center py-2 text-[10px] text-blue-400">
                                    Start typing to search assets...
                                </div>
                            </div>

                            <!-- Entry Cards — Grouped by Asset -->
                            <div v-if="createModalAssets.length > 0" class="space-y-3">
                                <div
                                    v-for="asset in createModalAssets"
                                    :key="asset.id"
                                    class="relative rounded-xl border border-gray-200 bg-white shadow-sm overflow-hidden"
                                >
                                    <!-- Asset Header Bar -->
                                    <div class="flex items-center justify-between bg-gradient-to-r from-gray-50 to-white px-4 py-2.5 border-b border-gray-100">
                                        <div class="flex items-center gap-2.5 min-w-0">
                                            <span class="text-xs font-black font-mono text-gray-900 flex-shrink-0">{{ asset.item_code }}</span>
                                            <span class="text-xs text-gray-600 truncate">{{ asset.brand }} {{ asset.model }}</span>
                                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[9px] font-bold uppercase flex-shrink-0"
                                                  :class="asset.type === 'Consumables' ? 'bg-amber-100 text-amber-700' : 'bg-blue-100 text-blue-700'">
                                                {{ asset.type }}
                                            </span>
                                        </div>
                                        <button
                                            type="button"
                                            @click="removeAssetGroup(asset.id)"
                                            class="p-1 text-gray-300 hover:text-red-500 transition-colors rounded-full hover:bg-red-50"
                                            title="Remove this asset group"
                                        >
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                            </svg>
                                        </button>
                                    </div>

                                    <div v-if="groupSharedFields[asset.id]" class="p-4 space-y-4">
                                        <!-- Shared Fields: Type, Allocation, Cost, Price -->
                                        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                                            <div>
                                                <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">Type</label>
                                                <select
                                                    v-model="groupSharedFields[asset.id].asset_type"
                                                    @change="syncSharedField(asset.id, 'asset_type')"
                                                    class="block w-full rounded-md border-gray-300 shadow-sm focus:ring-blue-500 focus:border-blue-500 text-xs bg-white"
                                                >
                                                    <option value="New">New</option>
                                                    <option value="Used">Used</option>
                                                    <option value="For Disposal">For Disposal</option>
                                                    <option value="For Repair">For Repair</option>
                                                </select>
                                            </div>
                                            <div>
                                                <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">Allocation</label>
                                                <div class="mt-1 flex items-center h-[34px]">
                                                    <label class="relative inline-flex items-center cursor-pointer">
                                                        <input type="checkbox" v-model="groupSharedFields[asset.id].is_allocation" @change="syncSharedField(asset.id, 'is_allocation')" class="sr-only peer">
                                                        <div class="w-9 h-5 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-3 after:w-3 after:transition-all peer-checked:bg-blue-600"></div>
                                                        <span class="ml-2 text-xs font-bold text-gray-900">{{ groupSharedFields[asset.id].is_allocation ? 'Yes' : 'No' }}</span>
                                                    </label>
                                                </div>
                                            </div>
                                            <div>
                                                <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">Cost</label>
                                                <input type="number" step="0.01" v-model.number="groupSharedFields[asset.id].cost" @change="syncSharedField(asset.id, 'cost')" required min="0"
                                                       class="block w-full rounded-md border-gray-300 shadow-sm focus:ring-blue-500 focus:border-blue-500 text-xs">
                                            </div>
                                            <div>
                                                <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">Price</label>
                                                <input type="number" step="0.01" v-model.number="groupSharedFields[asset.id].price" @change="syncSharedField(asset.id, 'price')" required min="0"
                                                       class="block w-full rounded-md border-gray-300 shadow-sm focus:ring-blue-500 focus:border-blue-500 text-xs">
                                            </div>
                                        </div>

                                        <!-- Qty + Warranty + EOL -->
                                        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 items-start">
                                            <div>
                                                <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">
                                                    Qty <span class="text-red-500">*</span>
                                                </label>
                                                <div class="flex items-center gap-1">
                                                    <button
                                                        type="button"
                                                        @click="setAssetQty(asset.id, getEntriesForAsset(asset.id).length - 1)"
                                                        class="flex-shrink-0 w-7 h-7 rounded-md border border-gray-300 bg-white hover:bg-gray-50 text-gray-700 text-sm font-bold flex items-center justify-center"
                                                    >−</button>
                                                    <input
                                                        type="number"
                                                        :value="getEntriesForAsset(asset.id).length"
                                                        @change="setAssetQty(asset.id, $event.target.value)"
                                                        min="1"
                                                        class="w-14 text-center rounded-md border-gray-300 shadow-sm focus:ring-blue-500 focus:border-blue-500 text-xs font-bold"
                                                    >
                                                    <button
                                                        type="button"
                                                        @click="setAssetQty(asset.id, getEntriesForAsset(asset.id).length + 1)"
                                                        class="flex-shrink-0 w-7 h-7 rounded-md border border-gray-300 bg-white hover:bg-gray-50 text-gray-700 text-sm font-bold flex items-center justify-center"
                                                    >+</button>
                                                </div>
                                            </div>
                                            <div class="p-2 bg-gray-50 rounded-lg border border-gray-100">
                                                <label class="block text-[9px] font-black text-gray-400 uppercase mb-1">Warranty (Mos)</label>
                                                <input type="number" v-model.number="groupSharedFields[asset.id].warranty_months" @change="syncSharedField(asset.id, 'warranty_months')" required min="0"
                                                       class="block w-full rounded-md border-gray-300 shadow-sm focus:ring-blue-500 focus:border-blue-500 text-xs">
                                                <p class="mt-1 text-[8px] text-blue-600 font-bold">Expires: {{ computedWarrantyDate(groupSharedFields[asset.id]) }}</p>
                                            </div>
                                            <div class="p-2 bg-gray-50 rounded-lg border border-gray-100">
                                                <label class="block text-[9px] font-black text-gray-400 uppercase mb-1">EOL (Mos)</label>
                                                <input type="number" v-model.number="groupSharedFields[asset.id].eol_months" @change="syncSharedField(asset.id, 'eol_months')" required min="0"
                                                       class="block w-full rounded-md border-gray-300 shadow-sm focus:ring-blue-500 focus:border-blue-500 text-xs">
                                                <p class="mt-1 text-[8px] text-blue-600 font-bold">End: {{ computedEolDate(groupSharedFields[asset.id]) }}</p>
                                            </div>
                                        </div>

                                        <!-- Units List -->
                                        <div class="space-y-1.5">
                                            <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Units ({{ getEntriesForAsset(asset.id).length }})</p>
                                            <div class="divide-y divide-gray-100 rounded-xl border border-gray-100 overflow-hidden">
                                                <div
                                                    v-for="(entry, unitIdx) in getEntriesForAsset(asset.id)"
                                                    :key="entry.uid"
                                                    class="flex items-center gap-2.5 px-3 py-2 bg-white hover:bg-gray-50/50"
                                                >
                                                    <!-- Unit # badge -->
                                                    <span class="flex-shrink-0 w-5 h-5 rounded-full bg-blue-100 text-blue-700 text-[9px] font-black flex items-center justify-center">{{ unitIdx + 1 }}</span>

                                                    <!-- Serial No (Fixed only) -->
                                                    <div class="flex-1 min-w-0">
                                                        <input
                                                            v-if="asset.type !== 'Consumables'"
                                                            type="text"
                                                            v-model="entry.serial_no"
                                                            @change="regenUnitCodes(form.entries.indexOf(entry))"
                                                            placeholder="Serial No (optional)"
                                                            class="block w-full rounded-md border-gray-300 shadow-sm focus:ring-blue-500 focus:border-blue-500 text-xs"
                                                        >
                                                        <span v-else class="text-[10px] text-gray-300 italic">No serial required</span>
                                                    </div>

                                                    <!-- Barcode thumbnail -->
                                                    <div
                                                        class="flex-shrink-0 cursor-pointer"
                                                        :title="entry.barcode"
                                                        @click="entry.barcodeDataUrl && (barcodePreview = { show: true, src: entry.barcodeDataUrl, text: entry.barcode })"
                                                    >
                                                        <img v-if="entry.barcodeDataUrl" :src="entry.barcodeDataUrl" class="h-6 max-w-[80px]" :alt="entry.barcode">
                                                        <div v-else class="h-6 w-20 flex items-center justify-center bg-gray-100 rounded text-[9px] text-gray-300 animate-pulse">barcode…</div>
                                                    </div>

                                                    <!-- QR thumbnail -->
                                                    <div
                                                        class="flex-shrink-0 cursor-pointer"
                                                        title="QR Code"
                                                        @click="entry.qrcodeDataUrl && (barcodePreview = { show: true, src: entry.qrcodeDataUrl, text: entry.barcode, isQr: true })"
                                                    >
                                                        <img v-if="entry.qrcodeDataUrl" :src="entry.qrcodeDataUrl" class="h-6 w-6" alt="QR">
                                                        <div v-else class="h-6 w-6 flex items-center justify-center bg-gray-100 rounded text-[9px] text-gray-300 animate-pulse">QR</div>
                                                    </div>

                                                    <!-- Regen button -->
                                                    <button
                                                        type="button"
                                                        @click="regenUnitCodes(form.entries.indexOf(entry))"
                                                        class="flex-shrink-0 text-[9px] font-black text-blue-400 hover:text-blue-700 uppercase tracking-widest transition-colors"
                                                        title="Regenerate barcode & QR"
                                                    >↻</button>

                                                    <!-- Validation flags -->
                                                    <span v-if="entryNeedsBarcode(entry)" class="flex-shrink-0 text-[9px] text-red-500 font-bold">!BC</span>
                                                    <span v-if="entryNeedsQrcode(entry)" class="flex-shrink-0 text-[9px] text-red-500 font-bold">!QR</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Empty State -->
                            <div v-if="createModalAssets.length === 0" class="rounded-xl border-2 border-dashed border-gray-200 py-10 text-center">
                                <svg class="mx-auto h-10 w-10 text-gray-200 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                                </svg>
                                <p class="text-sm font-semibold text-gray-300">No items added yet</p>
                                <p class="text-xs text-gray-200 mt-1">Click "Add Asset Item" to get started.</p>
                            </div>
                        </div>
                    </div>
                    </fieldset>

                    <div v-if="isEditing" class="rounded-2xl border border-gray-200 bg-gray-50/70 overflow-hidden">
                        <div class="flex items-center justify-between px-4 py-3 border-b border-gray-200 bg-white">
                            <div>
                                <h4 class="text-sm font-semibold text-gray-900">Stock Details</h4>
                                <p class="text-xs text-gray-500">Selected assets and their unit-level details.</p>
                            </div>
                            <div class="flex items-center gap-2">
                                <div v-if="isEditing && editingStockIn" class="flex items-center gap-2">
                                    <button
                                        type="button"
                                        @click="printStockInCodes('barcodes')"
                                        class="inline-flex items-center gap-1.5 rounded-md border border-gray-300 bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 shadow-sm hover:bg-gray-50"
                                    >
                                        <PrinterIcon class="h-4 w-4" />
                                        <span>Print Barcodes</span>
                                    </button>
                                    <button
                                        type="button"
                                        @click="printStockInCodes('qrcodes')"
                                        class="inline-flex items-center gap-1.5 rounded-md border border-gray-300 bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 shadow-sm hover:bg-gray-50"
                                    >
                                        <QrCodeIcon class="h-4 w-4" />
                                        <span>Print QR Codes</span>
                                    </button>
                                </div>
                                <span class="text-xs font-semibold uppercase tracking-[0.2em] text-blue-600">{{ form.entries.length }} unit(s)</span>
                                <button
                                    v-if="!readOnlyMode"
                                    type="button"
                                    @click="showAssetPicker = !showAssetPicker"
                                    class="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-3 py-1.5 text-xs font-bold text-white shadow-sm hover:bg-blue-700 transition-colors"
                                >
                                    <PlusIcon class="h-3.5 w-3.5" />
                                    Add Asset Item
                                </button>
                            </div>
                        </div>

                        <fieldset :disabled="readOnlyMode" class="disabled:opacity-90">
                        <div class="max-h-[55vh] overflow-y-auto p-4 space-y-6">

                            <!-- Asset Picker Panel (edit mode) -->
                            <div v-if="showAssetPicker && !readOnlyMode" class="rounded-xl border border-blue-200 bg-blue-50/40 p-4 space-y-3">
                                <div class="relative">
                                    <input
                                        type="text"
                                        v-model="assetSearch"
                                        placeholder="Search by item code, brand, model or description..."
                                        class="w-full pl-9 pr-10 py-2 border-blue-300 rounded-lg text-sm focus:ring-blue-500 focus:border-blue-500 bg-white shadow-sm"
                                    >
                                    <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none">
                                        <svg class="w-4 h-4 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                        </svg>
                                    </div>
                                    <button type="button" @click="showAssetPicker = false; assetSearch = ''" class="absolute inset-y-0 right-0 flex items-center pr-3 text-gray-400 hover:text-gray-600 transition-colors">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                                    </button>
                                </div>
                                <div v-if="assetSearch && filteredAssets.length > 0" class="overflow-hidden rounded-lg border border-blue-200 bg-white shadow-sm">
                                    <table class="min-w-full divide-y divide-gray-100">
                                        <thead class="bg-gray-50">
                                            <tr>
                                                <th class="px-4 py-2 text-left text-[10px] font-bold uppercase tracking-wider text-gray-500">Item Code</th>
                                                <th class="px-4 py-2 text-left text-[10px] font-bold uppercase tracking-wider text-gray-500">Asset</th>
                                                <th class="px-4 py-2 text-center text-[10px] font-bold uppercase tracking-wider text-gray-500">Type</th>
                                                <th class="px-4 py-2 text-right text-[10px] font-bold uppercase tracking-wider text-gray-500">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-50">
                                            <tr v-for="asset in filteredAssets" :key="asset.id" class="hover:bg-blue-50/50">
                                                <td class="px-4 py-2.5 text-xs font-mono font-semibold text-gray-900">{{ asset.item_code }}</td>
                                                <td class="px-4 py-2.5">
                                                    <p class="text-xs font-bold text-gray-900">{{ asset.brand }} {{ asset.model }}</p>
                                                    <p class="text-[10px] text-gray-400 truncate max-w-xs">{{ asset.description }}</p>
                                                </td>
                                                <td class="px-4 py-2.5 text-center">
                                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[9px] font-bold uppercase"
                                                          :class="asset.type === 'Consumables' ? 'bg-amber-100 text-amber-700' : 'bg-blue-100 text-blue-700'">
                                                        {{ asset.type }}
                                                    </span>
                                                </td>
                                                <td class="px-4 py-2.5 text-right">
                                                    <button
                                                        type="button"
                                                        @click="pickAsset(asset)"
                                                        class="inline-flex items-center gap-1 rounded-md bg-blue-600 px-2.5 py-1 text-[10px] font-black text-white uppercase tracking-wider hover:bg-blue-700 transition-colors"
                                                    >
                                                        <PlusIcon class="w-3 h-3" />
                                                        Add
                                                    </button>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                                <div v-else-if="assetSearch && filteredAssets.length === 0" class="text-center py-3 text-xs text-gray-400 font-semibold">
                                    No assets found matching "{{ assetSearch }}".
                                </div>
                                <div v-else class="text-center py-2 text-[10px] text-blue-400">
                                    Start typing to search assets...
                                </div>
                            </div>

                            <div v-if="form.entries.length === 0" class="text-center py-12">
                                <p class="text-sm text-gray-500 italic">No assets added yet. Use "Add Asset Item" to get started.</p>
                            </div>

                            <div v-for="asset in selectedAssets" :key="asset.id" class="space-y-3">
                                <div class="flex items-center justify-between bg-gray-200/50 px-3 py-1.5 rounded-lg border border-gray-300">
                                    <div class="flex items-center gap-3">
                                        <span class="text-xs font-black text-gray-900">{{ asset.item_code }}</span>
                                        <span class="text-xs text-gray-600 font-medium">{{ asset.brand }} {{ asset.model }}</span>
                                        <span class="inline-flex items-center rounded-full bg-blue-100 px-2 py-0.5 text-[10px] font-bold text-blue-700 uppercase">
                                            {{ asset.type }}
                                        </span>
                                    </div>
                                    <div class="flex items-center gap-3">
                                        <div v-if="isTransferMode" class="flex items-center gap-2">
                                            <span v-if="availableStockMap[asset.id]?.loading" class="text-[10px] text-gray-400">Loading SOH...</span>
                                            <span v-else-if="availableStockMap[asset.id]?.error" class="text-[10px] text-red-500">{{ availableStockMap[asset.id].error }}</span>
                                            <span v-else class="text-[10px] font-bold text-blue-600 uppercase tracking-widest">SOH: {{ availableStockMap[asset.id]?.soh }}</span>
                                        </div>
                                        <button
                                            v-if="!isEditing"
                                            type="button"
                                            @click="addEntryForAsset(asset)"
                                            class="text-[10px] font-black uppercase text-blue-600 hover:text-blue-800"
                                        >
                                            + Add Unit
                                        </button>
                                    </div>
                                </div>

                                <!-- Grouped: Shared Fields + Units List -->
                                <div v-if="groupSharedFields[asset.id]" class="pl-4 border-l-2 border-gray-200 space-y-4">
                                    <!-- Shared Fields Row -->
                                    <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm space-y-4">
                                        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                                            <div>
                                                <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">Type</label>
                                                <select
                                                    v-model="groupSharedFields[asset.id].asset_type"
                                                    :disabled="isTransferMode && asset.type === 'Fixed'"
                                                    @change="syncSharedField(asset.id, 'asset_type')"
                                                    class="block w-full rounded-md border-gray-300 shadow-sm focus:ring-blue-500 focus:border-blue-500 text-xs bg-white"
                                                >
                                                    <option value="New">New</option>
                                                    <option value="Used">Used</option>
                                                    <option value="For Disposal">For Disposal</option>
                                                    <option value="For Repair">For Repair</option>
                                                </select>
                                            </div>
                                            <div>
                                                <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">Allocation</label>
                                                <div class="mt-1 flex items-center h-[34px]">
                                                    <label class="relative inline-flex items-center cursor-pointer">
                                                        <input type="checkbox" v-model="groupSharedFields[asset.id].is_allocation" @change="syncSharedField(asset.id, 'is_allocation')" class="sr-only peer">
                                                        <div class="w-9 h-5 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-3 after:w-3 after:transition-all peer-checked:bg-blue-600"></div>
                                                        <span class="ml-2 text-xs font-bold text-gray-900">{{ groupSharedFields[asset.id].is_allocation ? 'Yes' : 'No' }}</span>
                                                    </label>
                                                </div>
                                            </div>
                                            <div>
                                                <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">Cost</label>
                                                <input type="number" step="0.01" v-model.number="groupSharedFields[asset.id].cost" :disabled="isTransferMode && asset.type === 'Fixed'" @change="syncSharedField(asset.id, 'cost')" required min="0" class="block w-full rounded-md border-gray-300 shadow-sm focus:ring-blue-500 focus:border-blue-500 text-xs">
                                            </div>
                                            <div>
                                                <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">Price</label>
                                                <input type="number" step="0.01" v-model.number="groupSharedFields[asset.id].price" :disabled="isTransferMode && asset.type === 'Fixed'" @change="syncSharedField(asset.id, 'price')" required min="0" class="block w-full rounded-md border-gray-300 shadow-sm focus:ring-blue-500 focus:border-blue-500 text-xs">
                                            </div>
                                        </div>
                                        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                                            <div class="p-2 bg-gray-50 rounded-lg border border-gray-100">
                                                <label class="block text-[9px] font-black text-gray-400 uppercase mb-1">Warranty (Mos)</label>
                                                <input type="number" v-model.number="groupSharedFields[asset.id].warranty_months" :disabled="isTransferMode && asset.type === 'Fixed'" @change="syncSharedField(asset.id, 'warranty_months')" required min="0" class="block w-full rounded-md border-gray-300 shadow-sm focus:ring-blue-500 focus:border-blue-500 text-xs">
                                                <p class="mt-1 text-[8px] text-blue-600 font-bold">Expires: {{ computedWarrantyDate(groupSharedFields[asset.id]) }}</p>
                                            </div>
                                            <div class="p-2 bg-gray-50 rounded-lg border border-gray-100">
                                                <label class="block text-[9px] font-black text-gray-400 uppercase mb-1">EOL (Mos)</label>
                                                <input type="number" v-model.number="groupSharedFields[asset.id].eol_months" :disabled="isTransferMode && asset.type === 'Fixed'" @change="syncSharedField(asset.id, 'eol_months')" required min="0" class="block w-full rounded-md border-gray-300 shadow-sm focus:ring-blue-500 focus:border-blue-500 text-xs">
                                                <p class="mt-1 text-[8px] text-blue-600 font-bold">End: {{ computedEolDate(groupSharedFields[asset.id]) }}</p>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Units List -->
                                    <div class="space-y-1.5">
                                        <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Units ({{ getEntriesForAsset(asset.id).length }})</p>
                                        <div class="divide-y divide-gray-100 rounded-xl border border-gray-200 overflow-hidden bg-white shadow-sm">
                                            <div
                                                v-for="(entry, unitIdx) in getEntriesForAsset(asset.id)"
                                                :key="entry.uid"
                                                class="p-3 space-y-2"
                                            >
                                                <!-- Transfer Mode: Source Unit Pick (per unit) -->
                                                <div v-if="isTransferMode && asset.type === 'Fixed'" class="">
                                                    <label class="block text-[9px] font-black text-gray-500 uppercase tracking-widest mb-1.5">Unit {{ unitIdx + 1 }} — Pick Source</label>
                                                    <div class="max-h-32 overflow-y-auto rounded-lg border border-gray-100">
                                                        <table class="min-w-full divide-y divide-gray-100">
                                                            <thead class="bg-gray-50">
                                                                <tr>
                                                                    <th class="w-8 px-2 py-1"></th>
                                                                    <th class="px-2 py-1 text-left text-[9px] font-bold text-gray-400 uppercase">Serial / Barcode</th>
                                                                    <th class="px-2 py-1 text-right text-[9px] font-bold text-gray-400 uppercase">Cost</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody class="divide-y divide-gray-50">
                                                                <tr
                                                                    v-for="unit in availableStockMap[asset.id]?.units"
                                                                    :key="unit.id"
                                                                    @click="toggleSourceUnit(unit, entry)"
                                                                    class="hover:bg-blue-50"
                                                                    :class="[readOnlyMode ? 'cursor-not-allowed opacity-70' : 'cursor-pointer', entry.source_stock_in_id === unit.id ? 'bg-blue-50' : '']"
                                                                >
                                                                    <td class="px-2 py-1">
                                                                        <input type="radio" :checked="entry.source_stock_in_id === unit.id" :disabled="readOnlyMode" class="h-3 w-3 text-blue-600 border-gray-300">
                                                                    </td>
                                                                    <td class="px-2 py-1">
                                                                        <p class="text-[11px] font-semibold text-gray-900">{{ unit.serial_no || 'No Serial' }}</p>
                                                                        <p class="text-[9px] font-mono text-gray-500">{{ unit.barcode }}</p>
                                                                    </td>
                                                                    <td class="px-2 py-1 text-right text-[11px] font-mono text-gray-900">{{ unit.cost }}</td>
                                                                </tr>
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                </div>

                                                <!-- Compact unit row: #, serial, barcode, QR, regen -->
                                                <div class="flex items-center gap-2.5">
                                                    <span class="flex-shrink-0 w-5 h-5 rounded-full bg-blue-100 text-blue-700 text-[9px] font-black flex items-center justify-center">{{ unitIdx + 1 }}</span>

                                                    <div class="flex-1 min-w-0">
                                                        <input
                                                            v-if="asset.type !== 'Consumables'"
                                                            type="text"
                                                            v-model="entry.serial_no"
                                                            :disabled="isTransferMode && asset.type === 'Fixed'"
                                                            @change="regenUnitCodes(form.entries.indexOf(entry))"
                                                            placeholder="Serial No"
                                                            class="block w-full rounded-md border-gray-300 shadow-sm focus:ring-blue-500 focus:border-blue-500 text-xs"
                                                        >
                                                        <span v-else class="text-[10px] text-gray-300 italic">No serial required</span>
                                                    </div>

                                                    <div
                                                        class="flex-shrink-0 cursor-pointer"
                                                        :title="entry.barcode"
                                                        @click="entry.barcode && (barcodePreview = { show: true, src: entry.barcodeDataUrl || makeBarcodeDataUrl(entry.barcode), text: entry.barcode })"
                                                    >
                                                        <img v-if="entry.barcode" :src="entry.barcodeDataUrl || makeBarcodeDataUrl(entry.barcode)" class="h-6 max-w-[80px]" :alt="entry.barcode">
                                                        <div v-else class="h-6 w-20 flex items-center justify-center bg-gray-100 rounded text-[9px] text-gray-300">no BC</div>
                                                    </div>

                                                    <div
                                                        class="flex-shrink-0 cursor-pointer"
                                                        title="QR Code"
                                                        @click="entry.qrcodeDataUrl && (barcodePreview = { show: true, src: entry.qrcodeDataUrl, text: entry.barcode, isQr: true })"
                                                    >
                                                        <img v-if="entry.qrcodeDataUrl" :src="entry.qrcodeDataUrl" class="h-6 w-6" alt="QR">
                                                        <div v-else class="h-6 w-6 flex items-center justify-center bg-gray-100 rounded text-[9px] text-gray-300">QR</div>
                                                    </div>

                                                    <button
                                                        v-if="!(isTransferMode && asset.type === 'Fixed') && !readOnlyMode"
                                                        type="button"
                                                        @click="regenUnitCodes(form.entries.indexOf(entry))"
                                                        class="flex-shrink-0 text-[9px] font-black text-blue-400 hover:text-blue-700 uppercase tracking-widest transition-colors"
                                                        title="Regenerate barcode & QR"
                                                    >↻</button>

                                                    <span v-if="entryNeedsBarcode(entry)" class="flex-shrink-0 text-[9px] text-red-500 font-bold">!BC</span>
                                                    <span v-if="entryNeedsQrcode(entry)" class="flex-shrink-0 text-[9px] text-red-500 font-bold">!QR</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        </fieldset>
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
                        <button type="button" @click="closeModal" class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md shadow-sm hover:bg-gray-50">{{ readOnlyMode ? 'Close' : 'Cancel' }}</button>
                        <button v-if="!readOnlyMode" type="submit" class="px-4 py-2 text-sm font-medium text-white bg-blue-600 border border-transparent rounded-md shadow-sm hover:bg-blue-700">
                            {{ isEditing ? 'Update' : 'Save' }}
                        </button>
                    </div>
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

        <!-- Barcode / QR Code Preview Modal -->
        <Modal :show="barcodePreview.show" @close="barcodePreview.show = false" max-width="sm">
            <div class="p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-sm font-black uppercase tracking-widest text-gray-900">{{ barcodePreview.isQr ? 'QR Code Preview' : 'Barcode Preview' }}</h3>
                    <button type="button" @click="barcodePreview.show = false" class="text-gray-400 hover:text-gray-600 transition-colors">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
                <div class="flex flex-col items-center gap-3 p-4 bg-white border border-gray-100 rounded-xl">
                    <img v-if="barcodePreview.src" :src="barcodePreview.src" :alt="barcodePreview.text" :class="barcodePreview.isQr ? 'w-48 h-48' : 'w-full max-w-xs'">
                    <p class="text-xs font-mono text-gray-600 text-center break-all">{{ barcodePreview.text }}</p>
                </div>
                <div class="mt-4 flex justify-end gap-2">
                    <a
                        v-if="barcodePreview.src"
                        :href="barcodePreview.src"
                        :download="`${barcodePreview.isQr ? 'qrcode' : 'barcode'}-${barcodePreview.text}.png`"
                        class="px-3 py-1.5 text-xs font-semibold text-white bg-blue-600 rounded-md hover:bg-blue-700 transition-colors"
                    >
                        Download
                    </a>
                    <button type="button" @click="barcodePreview.show = false" class="px-3 py-1.5 text-xs font-semibold text-gray-600 bg-gray-100 rounded-md hover:bg-gray-200 transition-colors">
                        Close
                    </button>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>

<script setup>
import { ref, reactive, computed, nextTick, onMounted, watch } from 'vue'
import JsBarcode from 'jsbarcode'
import QRCode from 'qrcode'
import { router, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import DataTable from '@/Components/DataTable.vue'
import Modal from '@/Components/Modal.vue'
import Autocomplete from '@/Components/Autocomplete.vue'
import MultiAutocomplete from '@/Components/MultiAutocomplete.vue'
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
    vendors: Array,
    categories: Array,
    brands: Array,
    locations: Array,
    summary: Object,
    filters: Object,
})

const { showSuccess, showError } = useToast()
const { confirm } = useConfirm()
const { hasPermission } = usePermission()

const statusFilter = ref([])

const statusOptions = [
    { value: 'For Posting', label: 'For Posting' },
    { value: 'Posted', label: 'Posted' },
]

const filterForm = reactive({
    category_id: props.filters?.category_id || null,
    brand: props.filters?.brand || null,
    type: props.filters?.type || null,
    location: props.filters?.location || null,
})

const summary = computed(() => props.summary || { total_qty: 0, posted_qty: 0, for_posting_qty: 0, total_records: 0 })

const pagination = usePagination(props.stockIns, 'stock-ins.index', () => ({
    statuses: statusFilter.value,
    category_id: filterForm.category_id,
    brand: filterForm.brand,
    type: filterForm.type,
    location: filterForm.location,
}))

watch(() => props.stockIns, (newData) => {
    pagination.updateData(newData)
})

watch(statusFilter, () => {
    pagination.currentPage.value = 1
    pagination.performSearch()
}, { deep: true })

const applyFilters = () => {
    pagination.currentPage.value = 1
    pagination.performSearch()
}

const resetFilters = () => {
    Object.assign(filterForm, { category_id: null, brand: null, type: null, location: null })
    statusFilter.value = []
    pagination.search.value = ''
    pagination.currentPage.value = 1
    pagination.performSearch()
}

const collapsedLocations = ref(new Set())

const normalizeLocation = (value) => value || 'N/A'

const groupRowsByLocation = (rows = []) => {
    const groups = new Map()

    rows.forEach(row => {
        const location = normalizeLocation(row.destination_location)
        if (!groups.has(location)) {
            groups.set(location, { location, rows: [] })
        }
        groups.get(location).rows.push(row)
    })

    return Array.from(groups.values()).map(group => ({
        ...group,
        summary: {
            item_count: group.rows.length,
            total_soh: group.rows.reduce((sum, row) => sum + Number(row.quantity || 0), 0),
            total_value: group.rows.reduce((sum, row) => sum + Number(row.quantity || 0) * Number(row.asset?.cost || 0), 0),
        },
    }))
}

const isLocationCollapsed = (location) => collapsedLocations.value.has(normalizeLocation(location))

const toggleLocation = (location) => {
    const key = normalizeLocation(location)
    const next = new Set(collapsedLocations.value)
    if (next.has(key)) {
        next.delete(key)
    } else {
        next.add(key)
    }
    collapsedLocations.value = next
}

const formatCurrency = (value) => {
    if (!value) return 'PHP 0.00'
    return new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(value)
}

const page = usePage()
const authUserName = computed(() => page.props.auth?.user?.name || '')

const normalizedAssets = computed(() => {
    return (props.assets || []).map(asset => ({
        ...asset,
        id: Number(asset.id),
        brand: asset.brand || 'Unbranded',
        model: asset.model || 'Unspecified Model',
    }))
})

const assetSearch = ref('')
const availableOriginAssets = ref([])
const isLoadingOriginAssets = ref(false)
const filteredAssets = computed(() => {
    const search = assetSearch.value.toLowerCase().trim()
    const sourceAssets = isTransferMode.value ? availableOriginAssets.value : normalizedAssets.value

    if (!search) {
        return isTransferMode.value ? sourceAssets : []
    }

    return sourceAssets.filter(asset => 
        asset.item_code.toLowerCase().includes(search) ||
        asset.brand.toLowerCase().includes(search) ||
        asset.model.toLowerCase().includes(search) ||
        (asset.description || '').toLowerCase().includes(search)
    ).slice(0, 10)
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
const barcodePreview = ref({ show: false, src: '', text: '' })
const isEditing = ref(false)
const readOnlyMode = ref(false)
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
    memo_remarks: '',
    status: 'For Posting',
    quantity: 0,
    entries: []
})

let entryUid = 0
const codeValidationAttempted = ref(false)
const supplierLocationCode = 'SUPPLIER'
const availableStockMap = ref({}) // asset_id -> { soh, units, loading, error }
const assetDetailTabs = ref({})
const expandedAssetDetails = ref({})
const bulkDestinationLocation = ref('')
const headerDestinationLocation = ref('')
const destinationStockItems = ref([])
const isLoadingDestinationStock = ref(false)
const showAssetPicker = ref(false)
const groupSharedFields = reactive({}) // { [asset_id]: { asset_type, is_allocation, cost, price, warranty_months, eol_months } }
let availableStockRequestId = 0
let availableOriginAssetsRequestId = 0
let suppressOriginLocationWatch = false

const createEntry = (overrides = {}) => ({
    uid: `entry-${entryUid++}`,
    asset_id: null,
    source_stock_in_id: null,
    serial_no: '',
    barcode: '',
    qrcode: '',
    asset_type: 'New',
    is_allocation: false,
    warranty_months: 12,
    eol_months: 60,
    cost: 0,
    price: 0,
    destination_location: headerDestinationLocation.value || '',
    stock_in_id: null,
    ...overrides
})

const getAssetDetailTab = (assetId) => assetDetailTabs.value[Number(assetId)] || 'new'

const setAssetDetailTab = (assetId, tab) => {
    assetDetailTabs.value = {
        ...assetDetailTabs.value,
        [Number(assetId)]: tab,
    }
}

const isAssetDetailExpanded = (assetId) => !!expandedAssetDetails.value[Number(assetId)]

const expandAssetDetail = (assetId) => {
    expandedAssetDetails.value = {
        ...expandedAssetDetails.value,
        [Number(assetId)]: true,
    }
}

const isSourceUnitSelected = (sourceId) => form.entries.some(entry =>
    Number(entry.source_stock_in_id) === Number(sourceId)
)

const sourceUnitEntryDefaults = (unit) => ({
    source_stock_in_id: unit.id,
    serial_no: unit.serial_no || '',
    barcode: unit.barcode || '',
    qrcode: unit.qrcode || '',
    asset_type: 'Used',
    warranty_months: Number(unit.warranty_months || 0),
    eol_months: Number(unit.eol_months || 0),
    cost: Number(unit.cost || 0),
    price: Number(unit.price || 0),
})

const addEntryForAsset = async (asset, unit = null) => {
    if (!asset) return
    const aid = Number(asset.id)
    const shared = groupSharedFields[aid]

    const defaults = {
        asset_id: aid,
        cost: shared ? shared.cost : (asset.cost || 0),
        price: shared ? shared.price : 0,
        warranty_months: shared ? shared.warranty_months : 12,
        eol_months: shared ? shared.eol_months : 60,
        asset_type: shared ? shared.asset_type : 'New',
        is_allocation: shared ? shared.is_allocation : false,
        ...(unit ? sourceUnitEntryDefaults(unit) : {}),
    }
    const entry = createEntry(defaults)
    form.entries.push(entry)
    form.quantity = form.entries.length
    setAssetDetailTab(aid, 'new')
    expandAssetDetail(aid)

    // Auto-generate barcode and QR code if not coming from an existing source unit
    if (!unit || !unit.barcode) {
        const newEntryIndex = form.entries.length - 1
        generateBarcode(newEntryIndex)
        generateQrcode(newEntryIndex)
    }

    if (isTransferMode.value) {
        await fetchAvailableStockForAsset(aid, normalizedOriginLocation.value, availableStockRequestId)

        // For fixed assets, auto-pick the first available unselected source unit
        if (asset.type === 'Fixed' && !entry.source_stock_in_id) {
            const units = availableStockMap.value[aid]?.units || []
            const firstFree = units.find(u => !isSourceUnitSelected(Number(u.id)))
            if (firstFree) Object.assign(entry, sourceUnitEntryDefaults(firstFree))
        }
    }
}

/**
 * Pick an asset from the inline search picker. Creates a new group if first time,
 * or adds another unit to an existing group.
 */
const pickAsset = async (asset) => {
    initGroupSharedFields(asset)
    await addEntryForAsset(asset)
    assetSearch.value = ''
    showAssetPicker.value = false
}

/**
 * Re-generate the QR code for a specific entry index (called when serial_no changes).
 */
const regenerateQrForEntry = (entryIndex) => {
    if (entryIndex < 0 || entryIndex >= form.entries.length) return
    generateQrcode(entryIndex)
}

/**
 * Load the current stock at the selected destination location for the SOH info panel.
 */
const loadDestinationStockSummary = async (location) => {
    if (!location) {
        destinationStockItems.value = []
        return
    }
    isLoadingDestinationStock.value = true
    try {
        const response = await axios.get(route('stock-ins.assets-with-stock'), {
            params: { location }
        })
        destinationStockItems.value = (response.data || []).map(asset => ({
            ...asset,
            id: Number(asset.id),
            brand: asset.brand || 'Unbranded',
            model: asset.model || 'Unspecified Model',
            soh: Number(asset.soh || 0),
        }))
    } catch {
        destinationStockItems.value = []
    } finally {
        isLoadingDestinationStock.value = false
    }
}

const addEntryFromSourceUnit = (asset, unit) => {
    if (isSourceUnitSelected(unit.id)) return
    addEntryForAsset(asset, unit)
}

const removeEntry = (index) => {
    form.entries.splice(index, 1)
    form.quantity = form.entries.length
}

const getEntriesForAsset = (assetId) => {
    const aid = Number(assetId)
    return form.entries.filter(e => Number(e.asset_id) === aid)
}

const getAssetForEntry = (entry) => {
    const aid = Number(entry.asset_id)
    return normalizedAssets.value.find(a => a.id === aid)
}

const selectedAssets = computed(() => {
    const ids = [...new Set(form.entries.map(e => Number(e.asset_id)))].filter(Boolean)
    return normalizedAssets.value.filter(a => ids.includes(a.id))
})

// Assets ordered by first-added position (for create modal grouped view)
const createModalAssets = computed(() => {
    const seen = new Set()
    const result = []
    for (const entry of form.entries) {
        const aid = Number(entry.asset_id)
        if (aid && !seen.has(aid)) {
            seen.add(aid)
            const asset = normalizedAssets.value.find(a => a.id === aid)
            if (asset) result.push(asset)
        }
    }
    return result
})

const hasGeneratedCode = (value) => String(value || '').trim().length > 0

const entryNeedsBarcode = (entry) => codeValidationAttempted.value && !hasGeneratedCode(entry?.barcode)
const entryNeedsQrcode = (entry) => codeValidationAttempted.value && !hasGeneratedCode(entry?.qrcode)

const initGroupSharedFields = (asset) => {
    const aid = Number(asset.id)
    if (!groupSharedFields[aid]) {
        groupSharedFields[aid] = {
            asset_type: 'New',
            is_allocation: false,
            cost: Number(asset.cost || 0),
            price: 0,
            warranty_months: 12,
            eol_months: 60,
        }
    }
}

const syncSharedField = (assetId, field) => {
    const aid = Number(assetId)
    const shared = groupSharedFields[aid]
    if (!shared) return
    form.entries.forEach(entry => {
        if (Number(entry.asset_id) === aid) {
            entry[field] = shared[field]
        }
    })
}

const setAssetQty = async (assetId, rawQty) => {
    const aid = Number(assetId)
    const asset = normalizedAssets.value.find(a => a.id === aid)
    if (!asset) return
    const newQty = Math.max(1, parseInt(rawQty) || 1)
    const currentEntries = getEntriesForAsset(aid)

    if (newQty > currentEntries.length) {
        for (let i = currentEntries.length; i < newQty; i++) {
            await addEntryForAsset(asset)
        }
    } else if (newQty < currentEntries.length) {
        const toRemove = getEntriesForAsset(aid).slice(newQty)
        for (let i = form.entries.length - 1; i >= 0; i--) {
            if (toRemove.includes(form.entries[i])) {
                form.entries.splice(i, 1)
            }
        }
        form.quantity = form.entries.length
    }
}

const removeAssetGroup = (assetId) => {
    const aid = Number(assetId)
    for (let i = form.entries.length - 1; i >= 0; i--) {
        if (Number(form.entries[i].asset_id) === aid) {
            form.entries.splice(i, 1)
        }
    }
    form.quantity = form.entries.length
    delete groupSharedFields[aid]
}

const regenUnitCodes = (entryIndex) => {
    generateBarcode(entryIndex)
    generateQrcode(entryIndex)
}

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

const normalizedOriginLocation = computed(() => normalizeLocationValue(form.origin_location))
const isTransferMode = computed(() => !!normalizedOriginLocation.value && normalizedOriginLocation.value !== supplierLocationCode)

const currentEntryIds = () => form.entries
    .map(entry => Number(entry.stock_in_id || entry.id))
    .filter(Boolean)

const clearTransferSourceSelections = () => {
    form.entries.forEach(entry => {
        const asset = getAssetForEntry(entry)
        if (asset?.type !== 'Fixed') return

        entry.source_stock_in_id = null
        entry.serial_no = ''
        entry.barcode = ''
        entry.qrcode = ''
    })
}

const fetchAvailableStockForAsset = async (assetId, originLocation, requestId = availableStockRequestId) => {
    if (!assetId || !originLocation || originLocation === supplierLocationCode) return

    const aid = Number(assetId)
    // Update ref value to ensure reactivity
    availableStockMap.value = {
        ...availableStockMap.value,
        [aid]: { loading: true, error: '', soh: 0, units: [] }
    }

    try {
        const response = await axios.get(route('stock-ins.available-stock'), {
            params: {
                asset_id: aid,
                origin_location: originLocation,
                exclude_child_ids: currentEntryIds(),
            },
        })

        if (requestId !== availableStockRequestId) return

        availableStockMap.value = {
            ...availableStockMap.value,
            [aid]: {
                loading: false,
                error: '',
                soh: Number(response.data.soh || 0),
                units: response.data.available_units || []
            }
        }
    } catch (error) {
        if (requestId !== availableStockRequestId) return

        availableStockMap.value = {
            ...availableStockMap.value,
            [aid]: {
                loading: false,
                error: error.response?.data?.message || 'Unable to load stock.',
                soh: 0,
                units: []
            }
        }
    }
}

const refreshAvailableStockForSelectedAssets = (originLocation = normalizedOriginLocation.value) => {
    availableStockRequestId++
    availableStockMap.value = {}

    if (!originLocation || originLocation === supplierLocationCode) return

    const requestId = availableStockRequestId
    selectedAssets.value.forEach(asset => fetchAvailableStockForAsset(asset.id, originLocation, requestId))
}

const loadAssetsForOrigin = async (originLocation = normalizedOriginLocation.value) => {
    availableOriginAssetsRequestId++
    const requestId = availableOriginAssetsRequestId
    availableOriginAssets.value = []

    if (!originLocation || originLocation === supplierLocationCode) return

    isLoadingOriginAssets.value = true

    try {
        const response = await axios.get(route('stock-ins.assets-with-stock'), {
            params: { location: originLocation },
        })

        if (requestId !== availableOriginAssetsRequestId) return

        availableOriginAssets.value = (response.data || []).map(asset => ({
            ...asset,
            id: Number(asset.id),
            brand: asset.brand || 'Unbranded',
            model: asset.model || 'Unspecified Model',
            soh: Number(asset.soh || 0),
        }))
    } catch (error) {
        if (requestId !== availableOriginAssetsRequestId) return

        showError('Failed to load available stock for this origin location.')
    } finally {
        if (requestId === availableOriginAssetsRequestId) {
            isLoadingOriginAssets.value = false
        }
    }
}

const validateTransferStock = () => {
    if (!isTransferMode.value || isEditing.value) return true

    if (form.entries.length === 0) {
        showError('Add at least one asset item before preparing a transfer.')
        return false
    }

    for (const entry of form.entries) {
        const asset = getAssetForEntry(entry)
        const aid = Number(entry.asset_id)
        const assetStock = availableStockMap.value[aid]
        
        if (!assetStock || assetStock.loading) {
            showError(`Please wait for SOH lookup for ${asset?.item_code || 'Asset'}.`)
            return false
        }

        if (assetStock.error) {
            showError(`${asset?.item_code || 'Asset'}: ${assetStock.error}`)
            return false
        }

        if (assetStock.soh <= 0) {
            showError(`No stock on hand for ${asset?.item_code || 'Asset'} at ${normalizedOriginLocation.value}.`)
            return false
        }

        const destination = normalizeLocationValue(entry.destination_location)
        if (!destination || destination === normalizedOriginLocation.value) {
            showError(`Select a valid destination for ${asset?.item_code || 'Asset'}.`)
            return false
        }
    }

    // Check quantity caps per asset
    const countsByAsset = form.entries.reduce((acc, e) => {
        const aid = Number(e.asset_id)
        acc[aid] = (acc[aid] || 0) + 1
        return acc
    }, {})

    for (const [aid, count] of Object.entries(countsByAsset)) {
        const assetStock = availableStockMap.value[Number(aid)]
        if (assetStock && count > assetStock.soh) {
            const asset = normalizedAssets.value.find(a => a.id === Number(aid))
            showError(`Only ${assetStock.soh} unit(s) of ${asset?.item_code || 'Asset'} are available.`);
            return false
        }
    }

    return true
}

const makeBarcodeDataUrl = (text) => {
    try {
        const canvas = document.createElement('canvas')
        JsBarcode(canvas, text, { format: 'CODE128', width: 2, height: 60, displayValue: true, fontSize: 12, margin: 8 })
        return canvas.toDataURL('image/png')
    } catch {
        return ''
    }
}

const makeQrcodeDataUrl = async (text) => {
    try {
        return await QRCode.toDataURL(text, { width: 300, margin: 2, errorCorrectionLevel: 'M' })
    } catch {
        return ''
    }
}

const generateBarcode = (entryIndex) => {
    const entry = form.entries[entryIndex]
    if (!entry) return
    const asset = getAssetForEntry(entry)
    const prefix = asset ? asset.item_code : 'ST'
    entry.barcode = `${prefix}-${Date.now()}-${entryIndex + 1}`
    entry.barcodeDataUrl = makeBarcodeDataUrl(entry.barcode)
    entry.qrcode = ''
}

const generateQrcode = (entryIndex) => {
    const entry = form.entries[entryIndex]
    if (!entry) return
    const asset = getAssetForEntry(entry)

    if (!asset) {
        showError('Asset data missing for this entry');
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
    makeQrcodeDataUrl(entry.qrcode).then(url => { entry.qrcodeDataUrl = url })
}

const toggleSourceUnit = (unit, entry) => {
    if (readOnlyMode.value) return

    const sourceId = Number(unit.id)
    if (entry.source_stock_in_id === sourceId) {
        entry.source_stock_in_id = null
    } else {
        // Check if this source unit is already used by another entry
        const isUsed = form.entries.some(e => e !== entry && e.source_stock_in_id === sourceId)
        if (isUsed) {
            showError('This unit is already selected for another entry.')
            return
        }

        entry.source_stock_in_id = sourceId
        entry.serial_no = unit.serial_no || ''
        entry.barcode = unit.barcode || ''
        entry.qrcode = unit.qrcode || ''
        entry.warranty_months = Number(unit.warranty_months || 0)
        entry.eol_months = Number(unit.eol_months || 0)
        entry.cost = Number(unit.cost || 0)
        entry.price = Number(unit.price || 0)
    }
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
    assetSearch.value = ''
    availableStockMap.value = {}
    assetDetailTabs.value = {}
    expandedAssetDetails.value = {}
    availableOriginAssets.value = []
    isLoadingOriginAssets.value = false
    headerDestinationLocation.value = ''
    destinationStockItems.value = []
    isLoadingDestinationStock.value = false
    showAssetPicker.value = false
    Object.keys(groupSharedFields).forEach(k => delete groupSharedFields[k])
    Object.assign(form, {
        receive_date: getToday(),
        dr_no: '',
        dr_date: getToday(),
        vendor: '',
        origin_location: '',
        received_by: authUserName.value,
        memo_remarks: '',
        status: 'For Posting',
        quantity: 0,
        entries: [],
    })
}

const openCreateModal = () => {
    isEditing.value = false
    readOnlyMode.value = false
    currentId.value = null
    editingStockIn.value = null
    resetForm()
    // Origin is always SUPPLIER for Stock-In
    form.origin_location = supplierLocationCode
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
            await editItem(items[0], items.reduce((sum, i) => sum + Number(i.quantity), 0), items, auditSource)
        }
    } catch (error) {
        showError('Could not fetch stock details. Please try again.')
    }
}

const viewHeaderItem = async (header) => {
    await editHeaderItem(header)
    readOnlyMode.value = true
}

const openLinkedStockIn = async () => {
    const params = new URLSearchParams(window.location.search)
    const referenceId = params.get('open_stock_in')

    if (!referenceId) return

    await editHeaderItem({ id: referenceId })
    readOnlyMode.value = true
}

const editItem = async (item, aggregatedQuantity = item.quantity, relatedRows = [item], auditSource = item) => {
    codeValidationAttempted.value = false
    isEditing.value = true
    readOnlyMode.value = false
    currentId.value = item.id
    editingStockIn.value = auditSource
    suppressOriginLocationWatch = true
    Object.assign(form, {
        receive_date: toDateKey(item.receive_date),
        dr_no: item.dr_no || '',
        dr_date: toDateKey(item.dr_date),
        vendor: item.vendor || '',
        origin_location: supplierLocationCode,
        received_by: item.received_by || authUserName.value,
        memo_remarks: item.memo_remarks || '',
        status: item.status || 'For Posting',
        quantity: aggregatedQuantity,
        entries: relatedRows.map(row => createEntry({
            stock_in_id: row.id,
            asset_id: Number(row.asset_id),
            source_stock_in_id: row.source_stock_in_id || null,
            serial_no: row.serial_no,
            barcode: row.barcode || '',
            qrcode: row.qrcode || '',
            asset_type: row.asset_type || 'New',
            is_allocation: !!row.is_allocation,
            warranty_months: row.warranty_months,
            eol_months: row.eol_months,
            cost: Number(row.cost || 0),
            price: Number(row.price || 0),
            destination_location: normalizeLocationValue(row.destination_location || row.location),
        }))
    })
    // Populate header-level destination from the first entry (all entries share same destination)
    const firstDest = relatedRows.length > 0
        ? normalizeLocationValue(relatedRows[0].destination_location || relatedRows[0].location || '')
        : ''
    headerDestinationLocation.value = firstDest
    // Init shared fields per asset group (first row per asset wins)
    Object.keys(groupSharedFields).forEach(k => delete groupSharedFields[k])
    relatedRows.forEach(row => {
        const aid = Number(row.asset_id)
        if (!groupSharedFields[aid]) {
            groupSharedFields[aid] = {
                asset_type: row.asset_type || 'New',
                is_allocation: !!row.is_allocation,
                cost: Number(row.cost || 0),
                price: Number(row.price || 0),
                warranty_months: Number(row.warranty_months ?? 12),
                eol_months: Number(row.eol_months ?? 60),
            }
        }
    })
    showModal.value = true
    await nextTick()
    suppressOriginLocationWatch = false
    // Render barcode images and QR data URLs for loaded entries
    form.entries.forEach(entry => {
        if (entry.barcode) entry.barcodeDataUrl = makeBarcodeDataUrl(entry.barcode)
        if (entry.qrcode) makeQrcodeDataUrl(entry.qrcode).then(url => { entry.qrcodeDataUrl = url })
    })
    void loadAssetsForOrigin()
    refreshAvailableStockForSelectedAssets()
    // Load SOH for the destination (informational)
    if (firstDest) void loadDestinationStockSummary(firstDest)
}

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

const closeModal = () => {
    showModal.value = false
    codeValidationAttempted.value = false
    readOnlyMode.value = false
    showAssetPicker.value = false
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

const submitForm = async (statusOverride = form.status || 'For Posting') => {
    if (!validateTransferStock()) return
    if (!validateGeneratedCodes()) return

    const unitCount = form.entries.length
    const assetCount = [...new Set(form.entries.map(e => e.asset_id).filter(Boolean))].length
    const confirmed = await confirm({
        title: isEditing.value ? 'Confirm Update' : 'Confirm Save',
        message: `${isEditing.value ? 'Update' : 'Save'} this stock-in with ${assetCount} asset type(s) and ${unitCount} unit(s)?`,
    })
    if (!confirmed) return

    const url = isEditing.value ? route('stock-ins.update', currentId.value) : route('stock-ins.store')
    const method = isEditing.value ? 'put' : 'post'
    const payload = {
        receive_date: form.receive_date,
        dr_no: form.dr_no,
        dr_date: form.dr_date || null,
        vendor: form.vendor,
        origin_location: form.origin_location || null,
        received_by: form.received_by,
        memo_remarks: form.memo_remarks || null,
        status: statusOverride,
        quantity: form.quantity,
        entries: form.entries.map(({ uid, ...entry }) => ({
            ...entry,
        })),
    }

    if (isEditing.value) {
        Object.assign(payload, {
            header_mode: true,
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
    openLinkedStockIn()
})

watch(() => props.stockIns, (newVal) => {
    pagination.updateData(newVal)
}, { deep: true })

watch(() => form.origin_location, (newVal) => {
    if (suppressOriginLocationWatch || !showModal.value || readOnlyMode.value) return

    const loc = normalizeLocationValue(newVal)
    void loadAssetsForOrigin(loc)

    if (isEditing.value) {
        clearTransferSourceSelections()
    }

    refreshAvailableStockForSelectedAssets(loc)
})

watch(headerDestinationLocation, (newVal) => {
    if (!showModal.value || readOnlyMode.value) return
    // Load SOH panel at new destination (informational)
    void loadDestinationStockSummary(newVal)
    // Sync all entries to the new destination
    form.entries.forEach(entry => {
        entry.destination_location = newVal
    })
})
</script>
