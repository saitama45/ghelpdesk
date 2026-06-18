<template>
    <AppLayout title="Stock Transfer" content-class="w-full max-w-none px-2 sm:px-4 lg:px-6">
        <div class="py-12">
            <div class="space-y-6">

                <!-- Summary Cards -->
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 dark:bg-gray-800 dark:border-gray-700">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-xs font-bold text-gray-500 uppercase tracking-wider dark:text-gray-300">Total Units</p>
                                <p class="text-2xl font-black text-gray-900 mt-1 dark:text-gray-100">{{ summary.total_qty }}</p>
                            </div>
                            <div class="p-3 bg-slate-50 rounded-lg">
                                <svg class="w-6 h-6 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" />
                                </svg>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 dark:bg-gray-800 dark:border-gray-700">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-xs font-bold text-gray-500 uppercase tracking-wider dark:text-gray-300">In Transit</p>
                                <p class="text-2xl font-black text-emerald-600 mt-1">{{ summary.posted_qty }}</p>
                            </div>
                            <div class="p-3 bg-emerald-50 rounded-lg">
                                <svg class="w-6 h-6 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                                </svg>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 dark:bg-gray-800 dark:border-gray-700">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-xs font-bold text-gray-500 uppercase tracking-wider dark:text-gray-300">Pending Posts</p>
                                <p class="text-2xl font-black text-amber-600 mt-1">{{ summary.for_posting_qty }}</p>
                            </div>
                            <div class="p-3 bg-amber-50 rounded-lg">
                                <svg class="w-6 h-6 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 dark:bg-gray-800 dark:border-gray-700">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-xs font-bold text-gray-500 uppercase tracking-wider dark:text-gray-300">Total Records</p>
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
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 dark:bg-gray-800 dark:border-gray-700">
                    <div class="grid grid-cols-1 md:grid-cols-6 gap-4">
                        <div class="md:col-span-1">
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1 dark:text-gray-300">Origin Location</label>
                            <Autocomplete
                                :model-value="filterForm.location"
                                :options="locationFilterOptions"
                                label-key="name"
                                value-key="id"
                                placeholder="All Locations"
                                size="sm"
                                @update:modelValue="updateFilter('location', $event)"
                            />
                        </div>
                        <div class="md:col-span-1">
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1 dark:text-gray-300">Destination Location</label>
                            <Autocomplete
                                :model-value="filterForm.destination_location"
                                :options="destinationLocationFilterOptions"
                                label-key="name"
                                value-key="id"
                                placeholder="All Destinations"
                                size="sm"
                                @update:modelValue="updateFilter('destination_location', $event)"
                            />
                        </div>
                        <div class="md:col-span-1">
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1 dark:text-gray-300">Category</label>
                            <Autocomplete
                                :model-value="filterForm.category_id"
                                :options="categoryFilterOptions"
                                label-key="name"
                                value-key="id"
                                placeholder="All Categories"
                                size="sm"
                                @update:modelValue="updateFilter('category_id', $event)"
                            />
                        </div>
                        <div class="md:col-span-1">
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1 dark:text-gray-300">Status</label>
                            <MultiAutocomplete
                                v-model="statusFilter"
                                :options="statusOptions"
                                label-key="label"
                                value-key="value"
                                placeholder="All statuses..."
                                @update:modelValue="updateStatusFilter"
                            />
                        </div>
                        <div class="md:col-span-1">
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1 dark:text-gray-300">Search</label>
                            <input v-model="pagination.search.value" type="text" placeholder="Search No. / Requestor" class="w-full border-gray-300 rounded-lg text-sm focus:ring-blue-500 focus:border-blue-500 dark:border-gray-600">
                        </div>
                        <div class="flex items-end">
                            <button @click="resetFilters" class="w-full px-4 py-2 bg-gray-100 text-gray-600 text-sm font-bold rounded-lg hover:bg-gray-200 transition-colors dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700">
                                Reset
                            </button>
                        </div>
                    </div>
                </div>

                <DataTable
                    title="Stock Transfer Records"
                    subtitle="Manage internal stock movements between locations"
                    search-placeholder="Search by transfer no..."
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
                            v-if="hasPermission('stock_transfers.create')"
                            @click="openCreateModal"
                            class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors flex items-center space-x-2 shadow-sm whitespace-nowrap"
                        >
                            <PlusIcon class="w-4 h-4" />
                            <span>Create Transfer</span>
                        </button>
                    </template>

                    <template #header>
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase dark:text-slate-300">Transfer Date</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase dark:text-slate-300">Transfer No.</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase dark:text-slate-300">Status</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase dark:text-slate-300">Items</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase dark:text-slate-300">Route</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase dark:text-slate-300">Qty</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase dark:text-slate-300">Actions</th>
                        </tr>
                    </template>

                    <template #body="{ data }">
                        <tr v-for="item in data" :key="item.id" class="hover:bg-gray-50 dark:hover:bg-gray-700">
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">{{ formatDate(item.transfer_date) }}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-gray-900 dark:text-gray-100">{{ item.transfer_no || '—' }}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider"
                                      :class="getStatusBadgeClass(item.status)">
                                    {{ item.status }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900 dark:text-gray-100">
                                <div class="flex flex-col">
                                    <div class="font-semibold text-gray-900 dark:text-gray-100">{{ item.asset_count }} asset(s)</div>
                                    <div class="text-xs text-gray-500 dark:text-gray-300">{{ item.record_count }} unit(s)</div>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900 dark:text-gray-100">
                                <div class="flex items-center space-x-2">
                                    <span class="font-bold text-gray-600 dark:text-gray-300">{{ item.origin_location }}</span>
                                    <svg class="w-3 h-3 text-gray-400 dark:text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                                    </svg>
                                    <span class="font-bold text-blue-600 dark:text-blue-400">{{ item.destination_location }}</span>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                                <span class="inline-flex items-center px-2.5 py-1 rounded-full bg-slate-100 text-slate-800 text-xs font-bold dark:bg-slate-800 dark:text-slate-100">
                                    {{ item.quantity }}
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                <div class="flex justify-end space-x-1">
                                    <button
                                        @click="viewTransfer(item)"
                                        class="p-2 text-gray-600 hover:text-gray-900 hover:bg-gray-50 rounded-full transition-colors dark:text-gray-300 dark:hover:bg-gray-700"
                                        title="View Details"
                                    >
                                        <EyeIcon class="w-4 h-4" />
                                    </button>
                                    <button
                                        v-if="hasPermission('stock_transfers.post') && canPostTransfer(item.status)"
                                        @click="postTransfer(item)"
                                        class="p-2 text-emerald-600 hover:text-emerald-900 hover:bg-emerald-50 rounded-full transition-colors dark:text-emerald-300 dark:hover:bg-emerald-500/15 dark:hover:text-emerald-200"
                                        title="Post Transfer"
                                    >
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                        </svg>
                                    </button>
                                    <button
                                        v-if="hasPermission('stock_transfers.edit') && canPostTransfer(item.status)"
                                        @click="editTransfer(item)"
                                        class="p-2 text-blue-600 hover:text-blue-900 hover:bg-blue-50 rounded-full transition-colors dark:text-blue-300 dark:hover:bg-blue-500/15 dark:hover:text-blue-200"
                                        title="Edit"
                                    >
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                        </svg>
                                    </button>
                                    <button
                                        v-if="hasPermission('stock_transfers.delete') && canPostTransfer(item.status)"
                                        @click="deleteTransfer(item)"
                                        class="p-2 text-red-600 hover:text-red-900 hover:bg-red-50 rounded-full transition-colors dark:text-red-300 dark:hover:bg-red-500/15 dark:hover:text-red-200"
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

        <!-- Modal -->
        <Modal :show="showModal" @close="closeModal" max-width="6xl" :closeable="false">
            <div class="p-6">
                <h3 class="text-lg font-bold text-gray-900 mb-4 dark:text-gray-100">{{ readOnlyMode ? 'View Stock Transfer' : (isEditing ? 'Edit Stock Transfer' : 'New Stock Transfer') }}</h3>
                <form @submit.prevent="submitForm" class="space-y-4">
                    <p v-if="readOnlyMode" class="rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm font-semibold text-blue-800 dark:border-blue-400/30 dark:bg-blue-500/15 dark:text-blue-200">
                        This transfer is read-only because it has already been posted or received.
                    </p>
                    <fieldset :disabled="readOnlyMode" class="space-y-4 disabled:opacity-90">
                    <div class="rounded-2xl border border-gray-200 bg-gray-50/70 p-4 space-y-4 dark:border-gray-700 dark:bg-gray-800/70">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Transfer Date</label>
                                <input type="date" v-model="form.transfer_date" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Transfer No (Optional)</label>
                                <input type="text" v-model="form.transfer_no" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100" placeholder="Auto-generated if empty">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Origin Location</label>
                                <Autocomplete
                                    v-model="form.origin_location"
                                    :options="storeOptions"
                                    label-key="name"
                                    value-key="value"
                                    placeholder="Select Origin Store"
                                    :disabled="readOnlyMode"
                                    @update:modelValue="onOriginChange"
                                />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Destination Location</label>
                                <Autocomplete
                                    v-model="form.destination_location"
                                    :options="storeOptions"
                                    label-key="name"
                                    value-key="value"
                                    placeholder="Select Destination Store"
                                    :disabled="readOnlyMode"
                                />
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Requested By</label>
                            <input type="text" v-model="form.requested_by" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                        </div>

                        <!-- Asset Item Table -->
                        <div>
                            <div class="flex items-center justify-between mb-1">
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                    Asset Item (With SOH &gt; 0)
                                    <span v-if="!form.origin_location" class="text-xs text-gray-400 font-normal ml-1 dark:text-gray-400">— select an origin location first</span>
                                </label>
                                <span v-if="assetSelections.length > 0" class="text-xs font-bold text-blue-600 cursor-pointer hover:underline" @click="assetSelections = []">Clear ({{ assetSelections.length }} selected)</span>
                            </div>
                            <div class="rounded-xl border border-gray-200 overflow-hidden bg-white shadow-sm dark:bg-gray-800 dark:border-gray-700">
                                <div class="px-3 py-2 bg-gray-50 border-b border-gray-200 dark:bg-gray-900/50 dark:border-gray-700">
                                    <div class="relative">
                                        <svg class="absolute left-2.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-gray-400 dark:text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                        </svg>
                                        <input
                                            v-model="assetSearch"
                                            type="text"
                                            placeholder="Search by code, brand or model..."
                                            :disabled="readOnlyMode || !form.origin_location"
                                            class="w-full pl-8 pr-3 py-1.5 text-xs border-0 bg-transparent focus:ring-0 focus:outline-none placeholder-gray-400 disabled:opacity-40 dark:text-gray-100"
                                        >
                                    </div>
                                </div>
                                <div class="max-h-48 overflow-y-auto custom-scrollbar">
                                    <div v-if="isLoadingAssets" class="flex items-center justify-center py-6">
                                        <svg class="animate-spin w-4 h-4 text-blue-500 mr-2" fill="none" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
                                        </svg>
                                        <span class="text-xs text-blue-600 font-medium">Loading assets with stock...</span>
                                    </div>
                                    <div v-else-if="!form.origin_location" class="py-6 text-center text-xs text-gray-400 italic dark:text-gray-400">
                                        Select an origin location to see available assets.
                                    </div>
                                    <div v-else-if="filteredAssets.length === 0" class="py-6 text-center text-xs text-gray-400 italic dark:text-gray-400">
                                        No assets with stock found{{ assetSearch ? ' matching "' + assetSearch + '"' : '' }}.
                                    </div>
                                    <table v-else class="min-w-full divide-y divide-gray-100 dark:divide-gray-700">
                                        <thead class="bg-gray-50 sticky top-0 dark:bg-gray-900/50">
                                            <tr>
                                                <th class="w-8 px-3 py-2"></th>
                                                <th class="px-3 py-2 text-left text-[10px] font-black uppercase text-gray-500 dark:text-slate-300">Item Code</th>
                                                <th class="px-3 py-2 text-left text-[10px] font-black uppercase text-gray-500 dark:text-slate-300">Brand / Model</th>
                                                <th class="px-3 py-2 text-left text-[10px] font-black uppercase text-gray-500 hidden md:table-cell dark:text-slate-300">Description</th>
                                                <th class="px-3 py-2 text-left text-[10px] font-black uppercase text-gray-500 dark:text-slate-300">Status</th>
                                                <th class="px-3 py-2 text-right text-[10px] font-black uppercase text-gray-500 dark:text-slate-300">SOH</th>
                                                <th class="px-3 py-2 text-right text-[10px] font-black uppercase text-gray-500 dark:text-slate-300">Transfer Qty</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                            <tr
                                                v-for="asset in filteredAssets"
                                                :key="asset.id"
                                                class="transition-colors"
                                                :class="[
                                                    isAssetSelected(asset) ? 'bg-blue-50 dark:bg-blue-500/15' : (asset.is_in_pending_transfer ? 'bg-amber-50/30 dark:bg-amber-500/10' : 'hover:bg-gray-50 dark:hover:bg-gray-700/50'),
                                                    (readOnlyMode || asset.is_in_pending_transfer) ? 'cursor-not-allowed opacity-80' : 'cursor-pointer'
                                                ]"
                                                @click="!(readOnlyMode || asset.is_in_pending_transfer) && toggleAsset(asset)"
                                            >
                                                <td class="px-3 py-2 text-center" @click.stop>
                                                    <input
                                                        type="checkbox"
                                                        :checked="isAssetSelected(asset)"
                                                        @change="toggleAsset(asset)"
                                                        :disabled="readOnlyMode || asset.is_in_pending_transfer"
                                                        class="h-4 w-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500 disabled:opacity-40 dark:border-gray-600 dark:bg-gray-900"
                                                    >
                                                </td>
                                                <td class="px-3 py-2 text-xs font-bold text-gray-900 whitespace-nowrap dark:text-gray-100">{{ asset.item_code }}</td>
                                                <td class="px-3 py-2 text-xs text-gray-700 whitespace-nowrap dark:text-gray-300">{{ asset.brand }} {{ asset.model }}</td>
                                                <td class="px-3 py-2 text-xs text-gray-500 hidden md:table-cell max-w-xs truncate dark:text-gray-300">{{ asset.description }}</td>
                                                <td class="px-3 py-2 whitespace-nowrap">
                                                    <span v-if="asset.is_in_pending_transfer" class="inline-flex items-center px-2 py-0.5 rounded text-[9px] font-black bg-amber-100 text-amber-700 border border-amber-200 uppercase tracking-tighter dark:bg-amber-500/15 dark:text-amber-200 dark:border-amber-500/30">
                                                        For Posting
                                                    </span>
                                                    <span v-else class="text-[10px] text-gray-400 font-medium dark:text-gray-400">Available</span>
                                                </td>
                                                <td class="px-3 py-2 text-right">
                                                    <span class="text-xs font-bold" :class="(asset.soh ?? 0) > 0 ? 'text-emerald-600' : 'text-red-500'">{{ asset.soh ?? '?' }}</span>
                                                </td>
                                                <td class="px-3 py-2 text-right" @click.stop>
                                                    <template v-if="isAssetSelected(asset)">
                                                        <template v-if="asset.type === 'Fixed'">
                                                            <span v-if="getSelection(asset)?.isLoadingUnits" class="text-xs text-gray-400 dark:text-gray-400">...</span>
                                                            <span v-else class="text-xs font-bold" :class="getSelection(asset)?.availableUnits.length > 0 ? 'text-blue-600 dark:text-blue-400' : 'text-red-400 dark:text-red-300'">
                                                                {{ getSelection(asset)?.entries.length }}/{{ getSelection(asset)?.availableUnits.length }}
                                                            </span>
                                                        </template>
                                                        <input v-else
                                                            type="number"
                                                            :value="getSelection(asset)?.qty ?? 1"
                                                            @input="updateSelectionQty(asset, $event.target.value)"
                                                            :max="asset.soh"
                                                            min="1"
                                                            :disabled="readOnlyMode || (asset.soh ?? 0) <= 1"
                                                            class="w-16 text-right rounded-md border-gray-300 shadow-sm text-xs font-bold focus:ring-blue-500 focus:border-blue-500 disabled:bg-gray-50 disabled:text-gray-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 dark:disabled:bg-gray-800"
                                                        >
                                                    </template>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <!-- Selected Assets Panel -->
                        <div v-if="assetSelections.length > 0" class="rounded-2xl border border-blue-100 bg-blue-50/40 overflow-hidden dark:border-blue-400/30 dark:bg-blue-500/10">
                            <div class="px-4 py-2.5 bg-blue-50 border-b border-blue-100 flex items-center justify-between dark:bg-blue-500/15 dark:border-blue-400/30">
                                <span class="text-xs font-black text-blue-800 uppercase tracking-widest dark:text-blue-300">Selected Assets</span>
                                <span class="text-xs font-bold text-blue-600 dark:text-blue-400">{{ assetSelections.length }} asset(s) · {{ totalSelectedQty }} unit(s)</span>
                            </div>
                            <div class="divide-y divide-blue-100 dark:divide-blue-400/30">
                                <div v-for="sel in assetSelections" :key="sel.asset.id" class="bg-white dark:bg-gray-800">
                                    <!-- Asset summary row -->
                                    <div class="flex items-center gap-3 px-4 py-3">
                                        <div class="flex-1 min-w-0">
                                            <p class="text-sm font-bold text-gray-900 truncate dark:text-gray-100">{{ sel.asset.item_code }}</p>
                                            <p class="text-xs text-gray-500 truncate dark:text-gray-300">{{ sel.asset.brand }} {{ sel.asset.model }}</p>
                                        </div>
                                        <!-- Non-Fixed: qty input -->
                                        <template v-if="sel.asset.type !== 'Fixed'">
                                            <div class="flex items-center gap-1.5">
                                                <label class="text-[10px] font-bold text-gray-500 uppercase dark:text-gray-300">Qty</label>
                                                <input
                                                    type="number"
                                                    :value="sel.qty"
                                                    @input="updateSelectionQty(sel.asset, $event.target.value)"
                                                    :max="sel.asset.soh"
                                                    min="1"
                                                    :disabled="readOnlyMode || (sel.asset.soh ?? 0) <= 1"
                                                    class="w-16 text-right rounded-md border-gray-300 shadow-sm text-xs font-bold focus:ring-blue-500 focus:border-blue-500 disabled:bg-gray-50 disabled:text-gray-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 dark:disabled:bg-gray-800"
                                                >
                                            </div>
                                        </template>
                                        <template v-else>
                                            <span v-if="sel.isLoadingUnits" class="text-xs text-gray-400 italic dark:text-gray-400">Loading...</span>
                                            <span v-else-if="readOnlyMode" class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-bold bg-blue-100 text-blue-700 dark:bg-blue-500/15 dark:text-blue-200">
                                                {{ sel.entries.length }} unit(s)
                                            </span>
                                            <span v-else class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-bold"
                                                :class="sel.availableUnits.length > 0 ? (sel.entries.length > 0 ? 'bg-blue-100 text-blue-700 dark:bg-blue-500/15 dark:text-blue-200' : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300') : 'bg-red-50 text-red-500 dark:bg-red-500/15 dark:text-red-300'">
                                                {{ sel.availableUnits.length > 0 ? `${sel.entries.length}/${sel.availableUnits.filter(u => !u.is_reserved).length} units selected` : `No available units at ${form.origin_location}` }}
                                            </span>
                                        </template>
                                        <!-- Remove button -->
                                        <button
                                            type="button"
                                            @click="toggleAsset(sel.asset)"
                                            class="p-1 text-gray-400 hover:text-red-500 rounded transition-colors flex-shrink-0 dark:text-gray-400"
                                            title="Remove"
                                        >
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                            </svg>
                                        </button>
                                    </div>
                                    <!-- Fixed asset unit picker — always expanded -->
                                    <div v-if="sel.asset.type === 'Fixed' && (sel.availableUnits.length > 0 || sel.entries.length > 0)" class="border-t border-blue-50 dark:border-blue-400/30">
                                        <table class="min-w-full divide-y divide-gray-100 dark:divide-gray-700">
                                            <thead class="bg-gray-50 dark:bg-gray-900/50">
                                                <tr>
                                                    <th v-if="!readOnlyMode" class="px-4 py-2 text-left">
                                                        <label class="inline-flex items-center gap-2 cursor-pointer select-none">
                                                            <input type="checkbox"
                                                                :checked="isAllUnitsSelected(sel)"
                                                                :indeterminate.prop="isSomeUnitsSelected(sel)"
                                                                @change="toggleAllUnits(sel, $event.target.checked)"
                                                                :disabled="selectableUnits(sel).length === 0"
                                                                class="h-4 w-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500 disabled:opacity-40 disabled:cursor-not-allowed dark:border-gray-600 dark:bg-gray-900">
                                                            <span class="text-[10px] font-black uppercase text-gray-500 dark:text-gray-300">Pick All</span>
                                                        </label>
                                                    </th>
                                                    <th class="px-4 py-2 text-left text-[10px] font-black uppercase text-gray-500 dark:text-slate-300">Serial No / Barcode</th>
                                                    <th class="px-4 py-2 text-right text-[10px] font-black uppercase text-gray-500 dark:text-slate-300">Cost</th>
                                                </tr>
                                            </thead>
                                            <tbody class="bg-white divide-y divide-gray-100 dark:bg-gray-800 dark:divide-gray-700">
                                                <!-- View mode: show entries directly (works even when SOH = 0) -->
                                                <template v-if="readOnlyMode">
                                                    <tr v-for="(entry, ei) in sel.entries" :key="ei" class="hover:bg-blue-50/50 dark:hover:bg-blue-500/10">
                                                        <td class="px-4 py-2">
                                                            <p class="text-sm font-bold text-gray-900 dark:text-gray-100">{{ entry.serial_no || 'NO SERIAL' }}</p>
                                                            <p class="text-[10px] font-mono text-gray-500 dark:text-gray-300">{{ entry.barcode }}</p>
                                                        </td>
                                                        <td class="px-4 py-2 text-right text-sm font-bold text-gray-700 dark:text-gray-300">{{ Number(entry.cost).toLocaleString() }}</td>
                                                    </tr>
                                                </template>
                                                <!-- Edit mode: show available units with checkboxes -->
                                                <template v-else>
                                                    <tr v-for="unit in sel.availableUnits" :key="unit.id"
                                                        class="transition-colors"
                                                        :class="[
                                                            unit.is_reserved ? 'bg-amber-50/50 opacity-70 cursor-not-allowed dark:bg-amber-900/20' : 'hover:bg-blue-50/50 cursor-pointer dark:hover:bg-blue-500/10',
                                                            isUnitSelected(sel, unit) ? 'bg-blue-50/70 dark:bg-blue-500/20' : ''
                                                        ]"
                                                        @click="!unit.is_reserved && toggleUnit(sel, unit)"
                                                    >
                                                        <td class="px-4 py-2" @click.stop>
                                                            <input type="checkbox"
                                                                :checked="isUnitSelected(sel, unit)"
                                                                @change="toggleUnit(sel, unit)"
                                                                :disabled="unit.is_reserved"
                                                                class="h-4 w-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500 disabled:opacity-40 disabled:cursor-not-allowed dark:border-gray-600 dark:bg-gray-900">
                                                        </td>
                                                        <td class="px-4 py-2">
                                                            <p class="text-sm font-bold text-gray-900 dark:text-gray-100">{{ unit.serial_no || 'NO SERIAL' }}</p>
                                                            <p class="text-[10px] font-mono text-gray-500 dark:text-gray-300">{{ unit.barcode }}</p>
                                                            <p v-if="unit.is_reserved" class="text-[10px] font-semibold text-amber-600 mt-0.5 dark:text-amber-400">
                                                                Reserved · {{ unit.reserved_in || 'Pending Transfer' }}
                                                            </p>
                                                        </td>
                                                        <td class="px-4 py-2 text-right text-sm font-bold text-gray-700 dark:text-gray-300">{{ Number(unit.cost).toLocaleString() }}</td>
                                                    </tr>
                                                </template>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Remarks</label>
                            <textarea v-model="form.memo_remarks" rows="2" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm resize-none dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"></textarea>
                        </div>
                    </div>

                    </fieldset>

                    <div class="flex justify-end space-x-3 mt-6">
                        <button type="button" @click="closeModal" class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md shadow-sm hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-300 dark:border-gray-600 dark:hover:bg-gray-700">{{ readOnlyMode ? 'Close' : 'Cancel' }}</button>
                        <button v-if="!readOnlyMode" type="submit" class="px-4 py-2 text-sm font-medium text-white bg-blue-600 border border-transparent rounded-md shadow-sm hover:bg-blue-700" :disabled="form.processing || assetSelections.length === 0">
                            {{ isEditing ? 'Update Transfer' : 'Save Transfer' }}
                        </button>
                    </div>
                </form>
            </div>
        </Modal>
    </AppLayout>
</template>

<script setup>
import { ref, reactive, computed, watch, onMounted } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import DataTable from '@/Components/DataTable.vue'
import Modal from '@/Components/Modal.vue'
import Autocomplete from '@/Components/Autocomplete.vue'
import MultiAutocomplete from '@/Components/MultiAutocomplete.vue'
import { PlusIcon, EyeIcon } from '@heroicons/vue/24/outline'
import { usePagination } from '@/Composables/usePagination'
import { useToast } from '@/Composables/useToast'
import { useConfirm } from '@/Composables/useConfirm'
import { usePermission } from '@/Composables/usePermission'
import axios from 'axios'

const props = defineProps({
    stockTransfers: Object,
    assets: Array,
    stores: Array,
    categories: Array,
    locations: Array,
    destinationLocations: Array,
    summary: Object,
    filters: Object,
})

const { showError } = useToast()
const { confirm } = useConfirm()
const { hasPermission } = usePermission()

const toArray = (value) => Array.isArray(value) ? value : (value ? [value] : [])

const statusFilter = ref(toArray(props.filters?.statuses))
const statusOptions = [
    { value: 'For Posting', label: 'For Posting' },
    { value: 'Posted', label: 'Posted' },
    { value: 'Received', label: 'Received' },
    { value: 'Declined', label: 'Declined' },
]

const filterForm = reactive({
    category_id: props.filters?.category_id || null,
    location: props.filters?.location || null,
    destination_location: props.filters?.destination_location || null,
})

const locationFilterOptions = computed(() => [
    { id: null, name: 'All Locations' },
    ...(props.locations || []).map(location => ({ id: location, name: location })),
])

const destinationLocationFilterOptions = computed(() => [
    { id: null, name: 'All Destinations' },
    ...(props.destinationLocations || []).map(location => ({ id: location, name: location })),
])

const categoryFilterOptions = computed(() => [
    { id: null, name: 'All Categories' },
    ...(props.categories || []).map(category => ({ id: category.id, name: category.name })),
])

const pagination = usePagination(props.stockTransfers, 'stock-transfers.index', () => ({
    statuses: statusFilter.value,
    category_id: filterForm.category_id,
    location: filterForm.location,
    destination_location: filterForm.destination_location,
}))

const applyFilters = () => {
    pagination.currentPage.value = 1
    pagination.performSearch()
}

const updateFilter = (key, value) => {
    filterForm[key] = value
    applyFilters()
}

const updateStatusFilter = (value) => {
    statusFilter.value = toArray(value)
    applyFilters()
}

const resetFilters = () => {
    Object.assign(filterForm, { category_id: null, location: null, destination_location: null })
    statusFilter.value = []
    pagination.search.value = ''
    applyFilters()
}

const showModal = ref(false)
const isEditing = ref(false)
const readOnlyMode = ref(false)
const currentId = ref(null)

const canPostTransfer = (status) => String(status || '').trim().toLowerCase() === 'for posting'
const getStatusBadgeClass = (status) => {
    if (status === 'Posted') return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300'
    if (status === 'Received') return 'bg-blue-100 text-blue-800 dark:bg-blue-500/15 dark:text-blue-300'
    if (status === 'Declined') return 'bg-orange-100 text-orange-800 dark:bg-orange-500/15 dark:text-orange-300'
    return 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300'
}

const viewTransfer = async (item) => {
    await editTransfer(item)
    readOnlyMode.value = true
}

const formatDateForInput = (date) => {
    const year = date.getFullYear()
    const month = String(date.getMonth() + 1).padStart(2, '0')
    const day = String(date.getDate()).padStart(2, '0')
    return `${year}-${month}-${day}`
}

const toDateInputValue = (value) => {
    if (!value) return ''

    if (typeof value === 'string') {
        if (/^\d{4}-\d{2}-\d{2}$/.test(value)) return value

        const sqlDateMatch = value.match(/^(\d{4}-\d{2}-\d{2})\s/)
        if (sqlDateMatch) return sqlDateMatch[1]
    }

    const date = new Date(value)
    return Number.isNaN(date.getTime()) ? '' : formatDateForInput(date)
}

const form = reactive({
    transfer_date: formatDateForInput(new Date()),
    transfer_no: '',
    origin_location: '',
    destination_location: '',
    requested_by: usePage().props.auth.user.name,
    memo_remarks: '',
    status: 'For Posting',
    processing: false,
})

const storeOptions = computed(() => (props.stores || []).map(s => ({ value: s.code, name: `${s.code} - ${s.name}` })))

// Asset table state
const availableAssets = ref([])
const isLoadingAssets = ref(false)
const assetSearch = ref('')
const filteredAssets = computed(() => {
    const q = assetSearch.value.trim().toLowerCase()
    if (!q) return availableAssets.value
    return availableAssets.value.filter(a =>
        (a.item_code || '').toLowerCase().includes(q) ||
        (a.brand || '').toLowerCase().includes(q) ||
        (a.model || '').toLowerCase().includes(q) ||
        (a.description || '').toLowerCase().includes(q)
    )
})

// Multi-asset selection state — each entry: { asset, qty, availableUnits, entries, isLoadingUnits }
const assetSelections = ref([])

const isAssetSelected = (asset) => assetSelections.value.some(s => s.asset.id === asset.id)
const getSelection = (asset) => assetSelections.value.find(s => s.asset.id === asset.id)


const totalSelectedQty = computed(() =>
    assetSelections.value.reduce((sum, sel) => {
        if (sel.asset.type === 'Fixed' && sel.availableUnits.length > 0) {
            return sum + sel.entries.length
        }
        return sum + sel.qty
    }, 0)
)

const loadAssetUnits = async (sel) => {
    if (!form.origin_location) return
    sel.isLoadingUnits = true
    try {
        const response = await axios.get(route('stock-transfers.available-stock'), {
            params: { asset_id: sel.asset.id, origin_location: form.origin_location },
        })
        sel.availableUnits = response.data.available_units || []
        sel.asset = { ...sel.asset, soh: response.data.soh }
    } catch (e) {
        showError('Failed to load stock details for ' + sel.asset.item_code)
    } finally {
        sel.isLoadingUnits = false
    }
}

const toggleAsset = async (asset) => {
    const idx = assetSelections.value.findIndex(s => s.asset.id === asset.id)
    if (idx > -1) {
        assetSelections.value.splice(idx, 1)
    } else {
        const sel = reactive({
            asset: { ...asset },
            qty: 1,
            availableUnits: [],
            entries: [],
            isLoadingUnits: false,
        })
        assetSelections.value.push(sel)
        if (asset.type === 'Fixed') {
            await loadAssetUnits(sel)
        }
    }
}

const updateSelectionQty = (asset, value) => {
    const sel = getSelection(asset)
    if (sel) sel.qty = Math.max(1, Math.min(parseInt(value) || 1, asset.soh || 9999))
}

const isUnitSelected = (sel, unit) => sel.entries.some(e => e.source_stock_in_id === unit.id)
const toggleUnit = (sel, unit) => {
    const idx = sel.entries.findIndex(e => e.source_stock_in_id === unit.id)
    if (idx > -1) {
        sel.entries.splice(idx, 1)
    } else {
        sel.entries.push({
            source_stock_in_id: unit.id,
            serial_no: unit.serial_no,
            barcode: unit.barcode,
            qrcode: unit.qrcode,
            asset_type: unit.asset_type || 'New',
            is_allocation: !!unit.is_allocation,
            warranty_months: unit.warranty_months ?? 0,
            eol_months: unit.eol_months ?? 0,
            cost: unit.cost ?? 0,
            price: unit.price ?? 0,
        })
    }
}

const selectableUnits = (sel) => sel.availableUnits.filter(u => !u.is_reserved)
const isAllUnitsSelected = (sel) => {
    const pickable = selectableUnits(sel)
    return pickable.length > 0 && pickable.every(u => isUnitSelected(sel, u))
}
const isSomeUnitsSelected = (sel) => {
    const pickable = selectableUnits(sel)
    const picked = pickable.filter(u => isUnitSelected(sel, u)).length
    return picked > 0 && picked < pickable.length
}
const toggleAllUnits = (sel, checked) => {
    if (checked) {
        const reservedEntries = sel.entries.filter(e =>
            sel.availableUnits.find(u => u.id === e.source_stock_in_id)?.is_reserved
        )
        sel.entries = [
            ...reservedEntries,
            ...selectableUnits(sel).map(unit => ({
                source_stock_in_id: unit.id,
                serial_no: unit.serial_no,
                barcode: unit.barcode,
                qrcode: unit.qrcode,
                asset_type: unit.asset_type || 'New',
                is_allocation: !!unit.is_allocation,
                warranty_months: unit.warranty_months ?? 0,
                eol_months: unit.eol_months ?? 0,
                cost: unit.cost ?? 0,
                price: unit.price ?? 0,
            })),
        ]
    } else {
        const reservedIds = sel.availableUnits.filter(u => u.is_reserved).map(u => u.id)
        sel.entries = sel.entries.filter(e => reservedIds.includes(e.source_stock_in_id))
    }
}

const onOriginChange = async (val) => {
    assetSelections.value = []
    assetSearch.value = ''

    if (!val) {
        availableAssets.value = []
        return
    }

    isLoadingAssets.value = true
    try {
        const response = await axios.get(route('stock-transfers.assets-with-stock'), { params: { location: val } })
        availableAssets.value = response.data
    } catch (e) {
        showError('Failed to load assets for this location')
    } finally {
        isLoadingAssets.value = false
    }
}

const openCreateModal = () => {
    isEditing.value = false
    readOnlyMode.value = false
    currentId.value = null
    Object.assign(form, {
        transfer_date: formatDateForInput(new Date()),
        transfer_no: '',
        origin_location: '',
        destination_location: '',
        requested_by: usePage().props.auth.user.name,
        memo_remarks: '',
        status: 'For Posting',
    })
    availableAssets.value = []
    assetSelections.value = []
    assetSearch.value = ''
    showModal.value = true
}

const editTransfer = async (item) => {
    isEditing.value = true
    readOnlyMode.value = false
    currentId.value = item.id

    try {
        // 1. Fetch all rows for this transfer (all assets)
        const transferRes = await axios.get(route('stock-transfers.show', item.id))
        const rows = transferRes.data
        if (rows.length === 0) return

        const first = rows[0]
        Object.assign(form, {
            transfer_date: toDateInputValue(first.transfer_date),
            transfer_no: first.transfer_no || '',
            origin_location: first.origin_location,
            destination_location: first.destination_location,
            requested_by: first.requested_by || '',
            memo_remarks: first.memo_remarks || '',
            status: first.status,
        })

        // 2. Group rows by asset_id
        const rowsByAsset = {}
        for (const row of rows) {
            if (!rowsByAsset[row.asset_id]) rowsByAsset[row.asset_id] = []
            rowsByAsset[row.asset_id].push(row)
        }
        const assetIds = Object.keys(rowsByAsset)

        // 3. Load available stock for each asset in parallel
        // Pass all row IDs of this transfer so the backend includes those units as available
        const allTransferIds = rows.map(r => r.id)
        const stockResponses = await Promise.all(
            assetIds.map(id =>
                axios.get(route('stock-transfers.available-stock'), {
                    params: {
                        asset_id: id,
                        origin_location: first.origin_location,
                        exclude_transfer_ids: allTransferIds,
                    },
                })
            )
        )

        // 4. Build assetSelections and the asset table list
        const selList = []
        const assetList = []
        assetIds.forEach((assetId, idx) => {
            const assetRows = rowsByAsset[assetId]
            const stockData = stockResponses[idx].data
            const assetData = { ...assetRows[0].asset, soh: stockData.soh }
            assetList.push(assetData)

            const entries = assetRows.map(r => ({
                source_stock_in_id: r.source_stock_in_id,
                serial_no: r.serial_no,
                barcode: r.barcode,
                qrcode: r.qrcode,
                asset_type: r.asset_type,
                is_allocation: !!r.is_allocation,
                warranty_months: r.warranty_months ?? 0,
                eol_months: r.eol_months ?? 0,
                cost: r.cost ?? 0,
                price: r.price ?? 0,
            }))

            selList.push(reactive({
                asset: assetData,
                qty: assetRows.reduce((sum, r) => sum + r.quantity, 0),
                availableUnits: stockData.available_units || [],
                entries,
                isLoadingUnits: false,
            }))
        })

        availableAssets.value = assetList
        assetSelections.value = selList
        assetSearch.value = ''
        showModal.value = true
    } catch (e) {
        showError('Failed to load transfer details')
    }
}

const openLinkedTransfer = async () => {
    const params = new URLSearchParams(window.location.search)
    const referenceId = params.get('open_transfer')

    if (!referenceId) return

    await editTransfer({ id: referenceId })
    readOnlyMode.value = true
}

const buildEntries = (sel) => {
    if (sel.asset.type === 'Fixed' && sel.availableUnits.length > 0) {
        return sel.entries
    }
    const entries = []
    for (let i = 0; i < sel.qty; i++) {
        entries.push({
            source_stock_in_id: null,
            serial_no: null,
            barcode: `${sel.asset.item_code}-TR-${Date.now()}-${i}`,
            qrcode: `Transfer of ${sel.asset.item_code}`,
            asset_type: 'New',
            is_allocation: false,
            warranty_months: 0,
            eol_months: 0,
            cost: sel.asset.cost || 0,
            price: 0,
        })
    }
    return entries
}

const submitForm = () => {
    if (form.origin_location === form.destination_location) {
        showError('Destination cannot be same as origin')
        return
    }

    if (assetSelections.value.length === 0) {
        showError('Please select at least one asset to transfer')
        return
    }

    for (const sel of assetSelections.value) {
        if (sel.asset.type === 'Fixed' && sel.availableUnits.length > 0 && sel.entries.length === 0) {
            showError(`Please select units to transfer for ${sel.asset.item_code}`)
            return
        }
    }

    const headerFields = {
        transfer_date: form.transfer_date,
        transfer_no: form.transfer_no,
        origin_location: form.origin_location,
        destination_location: form.destination_location,
        requested_by: form.requested_by,
        memo_remarks: form.memo_remarks,
        status: form.status,
    }

    form.processing = true

    const asset_transfers = assetSelections.value.map(sel => {
        const entries = buildEntries(sel)
        return { asset_id: sel.asset.id, quantity: entries.length, entries }
    })

    const payload = { ...headerFields, asset_transfers }

    if (isEditing.value) {
        router.put(route('stock-transfers.update', currentId.value), payload, {
            onSuccess: () => closeModal(),
            onError: (errors) => showError(Object.values(errors)[0]),
            onFinish: () => { form.processing = false },
        })
    } else {
        router.post(route('stock-transfers.store'), payload, {
            onSuccess: () => closeModal(),
            onError: (errors) => showError(Object.values(errors)[0]),
            onFinish: () => { form.processing = false },
        })
    }
}

const postTransfer = async (item) => {
    const confirmed = await confirm({
        title: 'Post Stock Transfer',
        message: 'This will deduct the stock from the origin location. It will be available for receiving later.',
    })
    if (confirmed) {
        router.post(route('stock-transfers.post', item.id), {})
    }
}

const deleteTransfer = async (item) => {
    const confirmed = await confirm({
        title: 'Delete Transfer',
        message: 'Are you sure you want to delete this transfer record?',
    })
    if (confirmed) {
        router.delete(route('stock-transfers.destroy', item.id), {
            data: { delete_group: true },
        })
    }
}

const closeModal = () => {
    showModal.value = false
    readOnlyMode.value = false
}

const formatDate = (date) => {
    if (!date) return '-'
    return new Date(date).toLocaleDateString('en-US', { month: 'short', day: '2-digit', year: 'numeric' })
}

watch(() => props.stockTransfers, (newVal) => {
    pagination.updateData(newVal)
})

onMounted(() => {
    pagination.updateData(props.stockTransfers)
    openLinkedTransfer()
})
</script>
