<template>
    <AppLayout title="Receiving Stock" content-class="w-full max-w-none px-2 sm:px-4 lg:px-6">
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
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                                </svg>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 dark:bg-gray-800 dark:border-gray-700">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-xs font-bold text-gray-500 uppercase tracking-wider dark:text-gray-300">Received</p>
                                <p class="text-2xl font-black text-emerald-600 mt-1">{{ summary.received_qty }}</p>
                            </div>
                            <div class="p-3 bg-emerald-50 rounded-lg">
                                <svg class="w-6 h-6 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                </svg>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 dark:bg-gray-800 dark:border-gray-700">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-xs font-bold text-gray-500 uppercase tracking-wider dark:text-gray-300">For Receiving</p>
                                <p class="text-2xl font-black text-amber-600 mt-1">{{ summary.for_receiving_qty }}</p>
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
                    <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
                        <div class="md:col-span-1">
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1 dark:text-gray-300">Destination</label>
                            <Autocomplete
                                :model-value="filterForm.destination_location"
                                :options="destinationOptions"
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
                                :options="categoryOptions"
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
                                @update:modelValue="applyFilters"
                            />
                        </div>
                        <div class="md:col-span-1">
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1 dark:text-gray-300">Search</label>
                            <input v-model="pagination.search.value" type="text" placeholder="Search receiving no / location" class="w-full border-gray-300 rounded-lg text-sm focus:ring-blue-500 focus:border-blue-500 dark:border-gray-600">
                        </div>
                        <div class="flex items-end">
                            <button @click="resetFilters" class="w-full px-4 py-2 bg-gray-100 text-gray-600 text-sm font-bold rounded-lg hover:bg-gray-200 transition-colors dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700">Reset</button>
                        </div>
                    </div>
                </div>

                <DataTable
                    title="Receiving Stock Records"
                    subtitle="Confirm receipt of transferred stock at destination locations"
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
                    <template #header>
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase dark:text-slate-300">Receiving Date</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase dark:text-slate-300">Receiving No.</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase dark:text-slate-300">Status</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase dark:text-slate-300">Items</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase dark:text-slate-300">Route</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase dark:text-slate-300">Qty</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase dark:text-slate-300">Actions</th>
                        </tr>
                    </template>

                    <template #body="{ data }">
                        <tr v-for="item in data" :key="item.id" class="hover:bg-gray-50 dark:hover:bg-gray-700">
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">{{ formatDate(item.receiving_date) }}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-gray-900 dark:text-gray-100">{{ item.receiving_no || '—' }}</td>
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
                                    <span class="font-bold text-blue-600">{{ item.destination_location }}</span>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                                <span class="inline-flex items-center px-2.5 py-1 rounded-full bg-slate-100 text-slate-800 text-xs font-bold dark:bg-slate-800 dark:text-slate-100">
                                    {{ item.quantity }}<span class="text-gray-400 mx-1 dark:text-gray-400">/</span>{{ item.transferred_quantity }}
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                <div class="flex justify-end space-x-1">
                                    <button
                                        v-if="hasPermission('stock_receivings.post') && item.status === 'For Receiving'"
                                        @click="postReceiving(item)"
                                        class="p-2 text-emerald-600 hover:text-emerald-900 hover:bg-emerald-50 rounded-full transition-colors dark:text-emerald-300 dark:hover:bg-emerald-500/15 dark:hover:text-emerald-200"
                                        title="Post Receiving"
                                    >
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                        </svg>
                                    </button>
                                    <button
                                        v-if="hasPermission('stock_receivings.post') && item.status === 'For Receiving'"
                                        @click="openDeclineModal(item)"
                                        class="p-2 text-orange-600 hover:text-orange-900 hover:bg-orange-50 rounded-full transition-colors dark:text-orange-300 dark:hover:bg-orange-500/15 dark:hover:text-orange-200"
                                        title="Decline Receiving"
                                    >
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h11m0 0l-4-4m4 4l-4 4m8-9v14" />
                                        </svg>
                                    </button>
                                    <button
                                        v-if="hasPermission('stock_receivings.edit') && item.status === 'For Receiving'"
                                        @click="editReceiving(item)"
                                        class="p-2 text-blue-600 hover:text-blue-900 hover:bg-blue-50 rounded-full transition-colors dark:text-blue-300 dark:hover:bg-blue-500/15 dark:hover:text-blue-200"
                                        title="Edit"
                                    >
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                        </svg>
                                    </button>
                                    <button
                                        v-else-if="['Received', 'Declined'].includes(item.status)"
                                        @click="viewReceiving(item)"
                                        class="p-2 text-gray-500 hover:text-gray-700 hover:bg-gray-50 rounded-full transition-colors dark:text-gray-300 dark:hover:bg-gray-700"
                                        title="View"
                                    >
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                        </svg>
                                    </button>
                                    <button
                                        v-if="hasPermission('stock_receivings.delete') && item.status === 'For Receiving'"
                                        @click="deleteReceiving(item)"
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
        <Modal :show="showModal" @close="closeModal" max-width="6xl">
            <div class="p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-bold text-gray-900 dark:text-gray-100">
                        {{ readOnly ? 'View Receiving' : 'Receive Stock' }} —
                        <span class="text-blue-600 dark:text-blue-400">{{ headerInfo.receiving_no }}</span>
                    </h3>
                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider"
                          :class="getStatusBadgeClass(headerInfo.status)">
                        {{ headerInfo.status }}
                    </span>
                </div>

                <form @submit.prevent="submitForm" class="space-y-4">
                    <div class="rounded-2xl border border-gray-200 bg-gray-50/70 p-4 grid grid-cols-1 md:grid-cols-3 gap-4 text-sm dark:border-gray-700 dark:bg-gray-800/70">
                        <div>
                            <p class="text-[10px] font-bold text-gray-500 uppercase tracking-widest dark:text-gray-300">Receiving Date</p>
                            <p class="font-bold text-gray-900 mt-0.5 dark:text-gray-100">{{ formatDate(headerInfo.receiving_date) }}</p>
                        </div>
                        <div>
                            <p class="text-[10px] font-bold text-gray-500 uppercase tracking-widest dark:text-gray-300">Origin</p>
                            <p class="font-bold text-gray-900 mt-0.5 dark:text-gray-100">{{ headerInfo.origin_location }}</p>
                        </div>
                        <div>
                            <p class="text-[10px] font-bold text-gray-500 uppercase tracking-widest dark:text-gray-300">Destination</p>
                            <p class="font-bold text-blue-600 mt-0.5 dark:text-blue-400">{{ headerInfo.destination_location }}</p>
                        </div>
                    </div>

                    <!-- Barcode scan strip (edit mode only) -->
                    <div v-if="!readOnly" class="flex items-center gap-3 rounded-xl border border-blue-100 bg-blue-50 p-3 dark:border-blue-400/30 dark:bg-blue-500/10">
                        <svg class="w-5 h-5 text-blue-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v1m0 14v1M4.22 4.22l.707.707M18.364 18.364l.707.707M1 12h1m20 0h1M4.22 19.778l.707-.707M18.364 5.636l.707-.707M12 7a5 5 0 100 10A5 5 0 0012 7z" />
                        </svg>
                        <svg class="w-5 h-5 text-blue-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5h2M3 10h2M3 15h2M3 20h2M8 5h1M8 10h1M8 15h1M8 20h1M14 5h2M14 20h2M20 5h1M20 10h1M20 15h1M20 20h1" />
                        </svg>
                        <input
                            ref="scanInputRef"
                            v-model="scanInput"
                            @keydown.enter.prevent="handleScan()"
                            @paste="handlePaste"
                            placeholder="Scan a box or piece barcode and press Enter"
                            class="flex-1 rounded-lg border-gray-200 text-sm font-mono focus:ring-blue-500 focus:border-blue-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"
                            autocomplete="off"
                            spellcheck="false"
                        />
                        <transition name="fade">
                            <span v-if="scanFeedback"
                                :class="scanFeedback.type === 'success' ? 'text-green-700 bg-green-100 dark:bg-green-500/15 dark:text-green-300' : 'text-red-700 bg-red-100 dark:bg-red-500/15 dark:text-red-300'"
                                class="px-2 py-1 rounded-lg text-xs font-black whitespace-nowrap">
                                {{ scanFeedback.message }}
                            </span>
                        </transition>
                    </div>

                    <!-- Scan progress -->
                    <p v-if="!readOnly" class="text-xs font-bold text-gray-500 dark:text-gray-300">
                        Verified: {{ verifiedIds.size }} / {{ itemRows.length }} items
                    </p>

                    <!-- Items grouped by asset -->
                    <template v-for="group in groupedItems" :key="group.asset.id">
                        <div class="rounded-2xl border border-gray-200 bg-white overflow-hidden shadow-sm dark:bg-gray-800 dark:border-gray-700">
                            <div class="px-4 py-3 bg-gray-50 border-b border-gray-200 flex items-center justify-between dark:bg-gray-900/50 dark:border-gray-700">
                                <div>
                                    <h4 class="text-xs font-black text-gray-900 uppercase tracking-widest dark:text-gray-100">{{ group.asset.item_code }}</h4>
                                    <p class="text-[10px] text-gray-500 dark:text-gray-300">{{ group.asset.brand }} {{ group.asset.model }} — {{ group.asset.description }}</p>
                                </div>
                                <span class="text-xs font-bold text-blue-600 dark:text-blue-400">{{ group.rows.length }} unit(s)</span>
                            </div>
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                    <thead class="bg-gray-50 dark:bg-gray-900/50">
                                        <tr>
                                            <th class="px-4 py-2 text-left text-[10px] font-black uppercase text-gray-500 dark:text-slate-300">Serial / Barcode</th>
                                            <th class="px-4 py-2 text-right text-[10px] font-black uppercase text-gray-500 dark:text-slate-300">Transferred</th>
                                            <th class="px-4 py-2 text-right text-[10px] font-black uppercase text-gray-500 dark:text-slate-300">Verified</th>
                                            <th class="px-4 py-2 text-left text-[10px] font-black uppercase text-gray-500 dark:text-slate-300">Condition</th>
                                            <th class="px-4 py-2 text-left text-[10px] font-black uppercase text-gray-500 dark:text-slate-300">Damage / Notes</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-100 dark:bg-gray-800 dark:divide-gray-700">
                                      <template v-for="box in boxGroups(group.rows)" :key="box.key">
                                        <!-- Box header: one scan of its label verifies every piece below -->
                                        <tr v-if="box.pack" class="bg-indigo-50/70 dark:bg-indigo-500/10">
                                            <td colspan="5" class="px-4 py-1.5">
                                                <span class="text-[11px] font-black text-indigo-700 uppercase dark:text-indigo-200">{{ box.pack.bulk_uom }} {{ box.pack.barcode }}</span>
                                                <span class="ml-2 text-[10px] font-semibold text-indigo-500 dark:text-indigo-300">
                                                    {{ box.rows.filter(r => verifiedIds.has(r.id)).length }}/{{ box.rows.length }} pcs verified
                                                </span>
                                                <span v-if="box.rows.length < box.pack.units_per_pack"
                                                      class="ml-2 inline-flex items-center rounded bg-amber-100 px-1.5 py-0.5 text-[9px] font-black uppercase text-amber-700 dark:bg-amber-500/15 dark:text-amber-200"
                                                      :title="`Only ${box.rows.length} of the ${box.pack.units_per_pack} pieces in this box were sent here`">
                                                    Partial box · {{ box.rows.length }} of {{ box.pack.units_per_pack }}
                                                </span>
                                            </td>
                                        </tr>
                                        <tr v-for="row in box.rows" :key="row.id">
                                            <td class="px-4 py-2" :class="box.pack ? 'pl-8' : ''">
                                                <p class="text-sm font-bold text-gray-900 dark:text-gray-100">{{ row.serial_no || 'NO SERIAL' }}</p>
                                                <p class="text-[10px] font-mono text-gray-500 dark:text-gray-300">{{ row.barcode }}</p>
                                            </td>
                                            <td class="px-4 py-2 text-right text-sm font-bold text-gray-700 dark:text-gray-300">{{ row.transferred_quantity }}</td>
                                            <td class="px-4 py-2 text-right">
                                                <template v-if="!readOnly">
                                                    <span v-if="verifiedIds.has(row.id)"
                                                        class="inline-flex items-center gap-1 rounded-full bg-green-100 px-2 py-0.5 text-xs font-black text-green-700 dark:bg-green-500/15 dark:text-green-300">
                                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7" />
                                                        </svg>
                                                        1
                                                    </span>
                                                    <span v-else class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-xs font-black text-gray-400 dark:bg-gray-800 dark:text-gray-400">
                                                        Pending
                                                    </span>
                                                </template>
                                                <span v-else class="text-sm font-bold text-gray-900 dark:text-gray-100">{{ row.received_quantity }}</span>
                                            </td>
                                            <td class="px-4 py-2">
                                                <select v-if="!readOnly"
                                                    v-model="row.condition"
                                                    class="rounded-md border-gray-300 shadow-sm text-sm focus:ring-blue-500 focus:border-blue-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"
                                                >
                                                    <option value="Good">Good</option>
                                                    <option value="Damaged">Damaged</option>
                                                    <option value="Missing">Missing</option>
                                                </select>
                                                <span v-else class="text-sm font-bold"
                                                      :class="{
                                                          'text-emerald-600': row.condition === 'Good',
                                                          'text-orange-600': row.condition === 'Damaged',
                                                          'text-red-600': row.condition === 'Missing',
                                                      }">{{ row.condition }}</span>
                                            </td>
                                            <td class="px-4 py-2">
                                                <textarea v-if="!readOnly && row.condition !== 'Good'"
                                                    v-model="row.damage_notes"
                                                    rows="1"
                                                    placeholder="Describe damage / missing"
                                                    class="w-full text-xs rounded-md border-gray-300 shadow-sm focus:ring-blue-500 focus:border-blue-500 resize-none dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"
                                                ></textarea>
                                                <span v-else-if="row.damage_notes" class="text-xs text-gray-600 dark:text-gray-300">{{ row.damage_notes }}</span>
                                                <span v-else class="text-xs text-gray-300 italic">—</span>
                                            </td>
                                        </tr>
                                      </template>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </template>

                    <!-- DR photo: a visual guide only, optional -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                            DR Image <span class="font-normal text-gray-400 dark:text-gray-500">(optional guide)</span>
                        </label>

                        <div v-if="drImagePreview" class="mt-1 flex flex-col sm:flex-row sm:items-start gap-3 rounded-md border border-gray-200 p-3 dark:border-gray-700">
                            <a :href="drImagePreview" target="_blank" rel="noopener" class="block shrink-0" title="Open full size">
                                <img :src="drImagePreview" alt="Delivery receipt" class="h-40 w-auto max-w-full rounded border border-gray-200 object-contain bg-gray-50 dark:border-gray-700 dark:bg-gray-900">
                            </a>
                            <div class="text-xs text-gray-500 dark:text-gray-400 space-y-2">
                                <p v-if="drImage.note">{{ drImage.note }}</p>
                                <p v-else-if="!drImage.file">Tap the image to view it full size.</p>
                                <div v-if="!readOnly" class="flex flex-wrap gap-2">
                                    <button type="button" @click="drImageInput?.click()" :disabled="drImage.compressing"
                                        class="px-3 py-1.5 text-xs font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50 disabled:opacity-50 dark:bg-gray-800 dark:text-gray-300 dark:border-gray-600 dark:hover:bg-gray-700">
                                        Replace
                                    </button>
                                    <button type="button" @click="removeDrImage"
                                        class="px-3 py-1.5 text-xs font-medium text-red-600 bg-white border border-red-200 rounded-md hover:bg-red-50 dark:bg-gray-800 dark:border-red-500/40 dark:hover:bg-red-500/10">
                                        Remove
                                    </button>
                                </div>
                            </div>
                        </div>

                        <button v-else-if="!readOnly" type="button" @click="drImageInput?.click()" :disabled="drImage.compressing"
                            class="mt-1 flex w-full flex-col items-center justify-center rounded-md border-2 border-dashed border-gray-300 bg-gray-50 px-4 py-5 text-center hover:border-blue-300 hover:bg-gray-100 disabled:opacity-60 dark:border-gray-600 dark:bg-gray-900/50 dark:hover:bg-gray-800">
                            <PhotoIcon class="h-7 w-7 text-gray-400" />
                            <span class="mt-1 text-sm font-medium text-gray-700 dark:text-gray-300">
                                {{ drImage.compressing ? 'Optimizing image…' : 'Upload or take a photo of the DR' }}
                            </span>
                            <span class="text-xs text-gray-500 dark:text-gray-400">Large phone photos are reduced automatically.</span>
                        </button>

                        <p v-else class="mt-1 text-sm text-gray-400 dark:text-gray-500">No DR image attached.</p>

                        <input ref="drImageInput" type="file" accept="image/*" class="hidden" @change="onDrImagePicked">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Remarks</label>
                        <textarea v-model="form.remarks" :disabled="readOnly" rows="2" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm resize-none disabled:bg-gray-100 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 dark:disabled:bg-gray-800"></textarea>
                    </div>

                    <div class="flex justify-end space-x-3 mt-6">
                        <button type="button" @click="closeModal" class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md shadow-sm hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-300 dark:border-gray-600 dark:hover:bg-gray-700">
                            {{ readOnly ? 'Close' : 'Cancel' }}
                        </button>
                        <button v-if="!readOnly" type="submit"
                            class="px-4 py-2 text-sm font-medium text-white bg-blue-600 border border-transparent rounded-md shadow-sm hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed"
                            :disabled="processing || verifiedIds.size === 0"
                            :title="verifiedIds.size === 0 ? 'Scan at least one item to save.' : ''"
                        >
                            Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </Modal>

        <Modal :show="showDeclineModal" @close="closeDeclineModal" max-width="lg">
            <div class="bg-white p-6 dark:bg-gray-800">
                <div class="mb-5">
                    <h3 class="text-lg font-black text-gray-900 uppercase tracking-tight dark:text-gray-100">Decline Receiving</h3>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-300">
                        This will return the transferred inventory to the origin location and close the receiving as declined.
                    </p>
                </div>

                <div v-if="declineTarget" class="mb-5 rounded-xl border border-orange-100 bg-orange-50 px-4 py-3 text-sm dark:bg-orange-500/10 dark:border-orange-500/30">
                    <div class="font-bold text-orange-900 dark:text-orange-400">{{ declineTarget.receiving_no || 'Pending Receiving' }}</div>
                    <div class="mt-1 text-orange-700 dark:text-orange-300">
                        {{ declineTarget.origin_location }} -> {{ declineTarget.destination_location }}
                        <span class="font-bold">({{ declineTarget.transferred_quantity }} qty)</span>
                    </div>
                </div>

                <label class="block text-[10px] font-black uppercase tracking-[0.22em] text-gray-500 dark:text-gray-300">Reason</label>
                <textarea
                    v-model="declineReason"
                    rows="4"
                    class="mt-2 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-orange-500 focus:ring-orange-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"
                    placeholder="Explain why this receiving is being declined..."
                ></textarea>

                <div class="mt-6 flex justify-end gap-3">
                    <button
                        type="button"
                        @click="closeDeclineModal"
                        class="rounded-lg border border-gray-200 px-4 py-2 text-sm font-bold text-gray-600 transition-colors hover:bg-gray-50 dark:text-gray-300 dark:border-gray-700 dark:hover:bg-gray-700"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        @click="submitDecline"
                        :disabled="declineProcessing || !declineReason.trim()"
                        class="rounded-lg bg-orange-600 px-4 py-2 text-sm font-bold text-white shadow-sm transition-colors hover:bg-orange-700 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        Decline Receiving
                    </button>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>

<script setup>
import { ref, reactive, computed, watch, onMounted, nextTick } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import DataTable from '@/Components/DataTable.vue'
import Modal from '@/Components/Modal.vue'
import Autocomplete from '@/Components/Autocomplete.vue'
import MultiAutocomplete from '@/Components/MultiAutocomplete.vue'
import { usePagination } from '@/Composables/usePagination'
import { useToast } from '@/Composables/useToast'
import { useConfirm } from '@/Composables/useConfirm'
import { usePermission } from '@/Composables/usePermission'
import { compressImage, formatBytes } from '@/Composables/useImageCompressor'
import { PhotoIcon } from '@heroicons/vue/24/outline'
import axios from 'axios'

// Keep in step with DR_IMAGE_MAX_KB in StockReceivingController. Photos are shrunk to
// well under the cap; 2400px on the long edge keeps DR print legible.
const DR_IMAGE_MAX_MB = 5
const DR_IMAGE_TARGET = { maxMb: 2, maxDimension: 2400 }

const props = defineProps({
    stockReceivings: Object,
    stores: Array,
    categories: Array,
    destinations: Array,
    summary: Object,
    filters: Object,
})

const { showError } = useToast()
const { confirm } = useConfirm()
const { hasPermission } = usePermission()

const statusFilter = ref(props.filters?.statuses || [])
const statusOptions = [
    { value: 'For Receiving', label: 'For Receiving' },
    { value: 'Received', label: 'Received' },
    { value: 'Declined', label: 'Declined' },
]

const filterForm = reactive({
    category_id: props.filters?.category_id || null,
    destination_location: props.filters?.destination_location || null,
})

const destinationOptions = computed(() => [
    { id: null, name: 'All Destinations' },
    ...(props.destinations || []).map(destination => ({ id: destination, name: destination })),
])

const categoryOptions = computed(() => [
    { id: null, name: 'All Categories' },
    ...(props.categories || []).map(category => ({ id: category.id, name: category.name })),
])

const pagination = usePagination(props.stockReceivings, 'stock-receivings.index', () => ({
    statuses: statusFilter.value,
    category_id: filterForm.category_id,
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

const resetFilters = () => {
    Object.assign(filterForm, { category_id: null, destination_location: null })
    statusFilter.value = []
    pagination.search.value = ''
    applyFilters()
}

// Modal state
const showModal = ref(false)
const showDeclineModal = ref(false)
const readOnly = ref(false)
const processing = ref(false)
const declineProcessing = ref(false)
const currentId = ref(null)
const declineTarget = ref(null)
const declineReason = ref('')
const headerInfo = reactive({
    receiving_no: '',
    receiving_date: '',
    origin_location: '',
    destination_location: '',
    status: '',
})
const form = reactive({
    remarks: '',
})
const itemRows = ref([]) // flat list of receiving rows

// DR image: `existingUrl` is the saved photo, `file`/`previewUrl` a newly picked one.
const drImageInput = ref(null)
const drImage = reactive({
    existingUrl: null,
    file: null,
    previewUrl: null,
    remove: false,
    compressing: false,
    note: null,
})
const drImagePreview = computed(() => drImage.previewUrl || (drImage.remove ? null : drImage.existingUrl))

const clearPickedDrImage = () => {
    if (drImage.previewUrl) URL.revokeObjectURL(drImage.previewUrl)
    Object.assign(drImage, { file: null, previewUrl: null, note: null })
}

const resetDrImage = (existingUrl = null) => {
    clearPickedDrImage()
    Object.assign(drImage, { existingUrl, remove: false, compressing: false })
    if (drImageInput.value) drImageInput.value.value = ''
}

const onDrImagePicked = async (event) => {
    const picked = event.target.files?.[0]
    event.target.value = ''
    if (!picked) return
    if (!picked.type?.startsWith('image/')) {
        showError('Please choose an image file.')
        return
    }

    drImage.compressing = true
    try {
        const result = await compressImage(picked, DR_IMAGE_TARGET)
        if (result.file.size > DR_IMAGE_MAX_MB * 1024 * 1024) {
            showError(result.note || `The image could not be reduced below ${DR_IMAGE_MAX_MB} MB.`)
            return
        }
        clearPickedDrImage()
        Object.assign(drImage, {
            file: result.file,
            previewUrl: URL.createObjectURL(result.file),
            remove: false,
            note: result.compressed
                ? `Optimized ${formatBytes(result.originalSize)} → ${formatBytes(result.finalSize)}. Saved when you click Save Changes.`
                : `${formatBytes(result.finalSize)}. Saved when you click Save Changes.`,
        })
    } finally {
        drImage.compressing = false
    }
}

const removeDrImage = () => {
    clearPickedDrImage()
    drImage.remove = true
}

const getStatusBadgeClass = (status) => {
    if (status === 'Received') return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300'
    if (status === 'Declined') return 'bg-orange-100 text-orange-800 dark:bg-orange-500/15 dark:text-orange-300'
    return 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300'
}

// Barcode scanner state
const verifiedIds   = ref(new Set())
const scanInput     = ref('')
const scanFeedback  = ref(null)
const scanInputRef  = ref(null)

// code -> { ids, pack }. A piece barcode/serial covers that piece; a box barcode covers every
// piece of that box in THIS receiving (after a split, only the pieces that were sent here).
const scanLookup = computed(() => {
    const map = new Map()
    itemRows.value.forEach(row => {
        if (row.barcode)   map.set(row.barcode.trim().toLowerCase(),   { ids: [row.id], pack: null })
        if (row.serial_no) map.set(row.serial_no.trim().toLowerCase(), { ids: [row.id], pack: null })
    })
    itemRows.value.forEach(row => {
        const code = row.pack?.barcode?.trim().toLowerCase()
        if (!code) return
        if (!map.has(code) || !map.get(code).pack) map.set(code, { ids: [], pack: row.pack })
        map.get(code).ids.push(row.id)
    })
    return map
})

/** Rows of one asset grouped by box (boxes first), then loose pieces. */
const boxGroups = (rows) => {
    const boxes = new Map()
    const loose = []
    for (const row of rows) {
        if (row.pack) {
            if (!boxes.has(row.pack.id)) boxes.set(row.pack.id, { key: `pack-${row.pack.id}`, pack: row.pack, rows: [] })
            boxes.get(row.pack.id).rows.push(row)
        } else {
            loose.push(row)
        }
    }
    return [...boxes.values(), ...(loose.length ? [{ key: 'loose', pack: null, rows: loose }] : [])]
}

const handleScan = (overrideValue) => {
    const value = (overrideValue ?? scanInput.value).trim()
    if (!value) return

    const match = scanLookup.value.get(value.toLowerCase())
    if (match) {
        verifiedIds.value = new Set([...verifiedIds.value, ...match.ids])
        scanFeedback.value = {
            type: 'success',
            message: match.pack ? `✓ ${match.pack.bulk_uom} verified · ${match.ids.length} pcs` : '✓ Verified',
        }
    } else {
        scanFeedback.value = { type: 'error', message: 'Barcode not found in this receiving.' }
    }

    scanInput.value = ''
    nextTick(() => scanInputRef.value?.focus())
    setTimeout(() => { scanFeedback.value = null }, 2500)
}

const handlePaste = (e) => {
    e.preventDefault()
    const pasted = (e.clipboardData || window.clipboardData).getData('text')
    if (!pasted.trim()) return
    scanInput.value = pasted
    setTimeout(() => handleScan(), 400)
}

const groupedItems = computed(() => {
    const map = new Map()
    for (const row of itemRows.value) {
        const key = row.asset_id
        if (!map.has(key)) {
            map.set(key, { asset: row.asset, rows: [] })
        }
        map.get(key).rows.push(row)
    }
    return Array.from(map.values())
})

const loadIntoModal = async (item, options = {}) => {
    try {
        const res = await axios.get(route('stock-receivings.show', item.id))
        const rows = res.data
        if (rows.length === 0) return

        const first = rows[0]
        Object.assign(headerInfo, {
            receiving_no: first.receiving_no || '—',
            receiving_date: first.receiving_date,
            origin_location: first.origin_location,
            destination_location: first.destination_location,
            status: first.status,
        })
        form.remarks = first.remarks || ''
        const withImage = rows.find(r => r.dr_image_path)
        // The path in the query busts the browser cache after a replacement.
        resetDrImage(withImage
            ? `${route('stock-receivings.dr-image', withImage.id)}?v=${encodeURIComponent(withImage.dr_image_path)}`
            : null)
        itemRows.value = rows.map(r => ({
            id: r.id,
            asset_id: r.asset_id,
            asset: r.asset,
            serial_no: r.serial_no,
            barcode: r.barcode,
            transferred_quantity: r.transferred_quantity,
            received_quantity: r.received_quantity,
            condition: r.condition || 'Good',
            damage_notes: r.damage_notes || '',
            stock_pack_id: r.stock_pack_id || null,
            pack: r.pack || null,
        }))
        currentId.value = item.id
        readOnly.value = !!options.readOnly
        showModal.value = true
    } catch (e) {
        showError('Failed to load receiving details')
    }
}

const editReceiving = (item) => loadIntoModal(item, { readOnly: false })
const viewReceiving = (item) => loadIntoModal(item, { readOnly: true })

const closeModal = () => {
    showModal.value = false
    readOnly.value = false
    itemRows.value = []
    resetDrImage()
    verifiedIds.value = new Set()
    scanInput.value = ''
    scanFeedback.value = null
}

watch(showModal, async (open) => {
    if (open && !readOnly.value) {
        await nextTick()
        scanInputRef.value?.focus()
    }
})

const submitForm = () => {
    // Group by asset_id for payload
    const byAsset = new Map()
    for (const row of itemRows.value) {
        if (!byAsset.has(row.asset_id)) byAsset.set(row.asset_id, [])
        byAsset.get(row.asset_id).push({
            id: row.id,
            received_quantity: verifiedIds.value.has(row.id) ? row.transferred_quantity : 0,
            condition: row.condition,
            damage_notes: row.condition === 'Good' ? null : (row.damage_notes || null),
        })
    }
    const asset_transfers = Array.from(byAsset.entries()).map(([asset_id, entries]) => ({ asset_id, entries }))

    processing.value = true
    // Multipart needs POST + _method spoofing; "indices" keeps asset_transfers[i][entries][j]
    // nested (Inertia's default "brackets" would flatten it).
    router.post(route('stock-receivings.update', currentId.value), {
        _method: 'put',
        remarks: form.remarks,
        asset_transfers,
        dr_image: drImage.file,
        remove_dr_image: drImage.remove,
    }, {
        forceFormData: true,
        queryStringArrayFormat: 'indices',
        onSuccess: () => closeModal(),
        onError: (errors) => showError(Object.values(errors)[0]),
        onFinish: () => { processing.value = false },
    })
}

const postReceiving = async (item) => {
    const confirmed = await confirm({
        title: 'Post Receiving',
        message: 'This will credit the destination inventory and mark the source transfer as Received. Continue?',
    })
    if (confirmed) {
        router.post(route('stock-receivings.post', item.id), {})
    }
}

const openDeclineModal = (item) => {
    declineTarget.value = item
    declineReason.value = ''
    showDeclineModal.value = true
}

const closeDeclineModal = () => {
    showDeclineModal.value = false
    declineTarget.value = null
    declineReason.value = ''
    declineProcessing.value = false
}

const submitDecline = () => {
    if (!declineTarget.value || !declineReason.value.trim() || declineProcessing.value) return

    declineProcessing.value = true
    router.post(route('stock-receivings.decline', declineTarget.value.id), {
        reason: declineReason.value.trim(),
    }, {
        onSuccess: () => closeDeclineModal(),
        onError: (errors) => showError(Object.values(errors)[0] || 'Failed to decline receiving.'),
        onFinish: () => { declineProcessing.value = false },
    })
}

const deleteReceiving = async (item) => {
    const confirmed = await confirm({
        title: 'Delete Receiving',
        message: 'Are you sure you want to delete this pending receiving record?',
    })
    if (confirmed) {
        router.delete(route('stock-receivings.destroy', item.id), {
            data: { delete_group: true },
        })
    }
}

const formatDate = (date) => {
    if (!date) return '-'
    return new Date(date).toLocaleDateString('en-US', { month: 'short', day: '2-digit', year: 'numeric' })
}

watch(() => props.stockReceivings, (newVal) => {
    pagination.updateData(newVal)
})

onMounted(() => {
    pagination.updateData(props.stockReceivings)
})
</script>
