<template>
    <AppLayout title="CCTV Monitoring">
        <div class="py-12">
            <div class="max-w-[100rem] mx-auto sm:px-6 lg:px-8 space-y-6">

                <!-- Summary Cards -->
                <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
                    <div v-for="(count, status) in summary.status_counts" :key="status"
                         class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
                        <p class="text-[10px] font-black uppercase tracking-wider"
                           :class="statusColorClass(status, 'text')">{{ status }}</p>
                        <p class="text-2xl font-black mt-1"
                           :class="statusColorClass(status, 'text')">{{ count }}</p>
                    </div>
                    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
                        <p class="text-[10px] font-black uppercase tracking-wider text-gray-400">Tracked Stores</p>
                        <p class="text-2xl font-black mt-1 text-gray-700">{{ summary.total_stores }}</p>
                    </div>
                </div>

                <!-- Filters -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
                    <div class="grid grid-cols-1 md:grid-cols-6 gap-3">
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Year</label>
                            <input v-model.number="filters.year" type="number" min="2000" max="2100"
                                   class="w-full border-gray-300 rounded-lg text-sm focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Sector</label>
                            <select v-model="filters.sector" class="w-full border-gray-300 rounded-lg text-sm focus:ring-blue-500 focus:border-blue-500">
                                <option :value="null">All Sectors</option>
                                <option v-for="s in sectors" :key="s" :value="s">Sector {{ s }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Brand</label>
                            <select v-model="filters.brand" class="w-full border-gray-300 rounded-lg text-sm focus:ring-blue-500 focus:border-blue-500">
                                <option value="">All Brands</option>
                                <option v-for="b in brands" :key="b" :value="b">{{ b }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Status</label>
                            <select v-model="filters.status" class="w-full border-gray-300 rounded-lg text-sm focus:ring-blue-500 focus:border-blue-500">
                                <option value="">All Statuses</option>
                                <option v-for="s in statuses" :key="s" :value="s">{{ s }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Search</label>
                            <input v-model="filters.search" type="text" placeholder="Code, name, brand..."
                                   class="w-full border-gray-300 rounded-lg text-sm focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        <div class="flex items-end gap-2">
                            <button @click="applyFilters" class="flex-1 px-4 py-2 bg-blue-600 text-white text-sm font-bold rounded-lg hover:bg-blue-700 transition-colors">Apply</button>
                            <button @click="resetFilters" class="px-4 py-2 bg-gray-100 text-gray-600 text-sm font-bold rounded-lg hover:bg-gray-200 transition-colors">Reset</button>
                        </div>
                    </div>
                </div>

                <!-- Matrix -->
                <DataTable
                    title="CCTV Monitoring Matrix"
                    :subtitle="`Per-store monthly inspection status — ${filters.year}`"
                    search-placeholder="Search stores..."
                    :search="filters.search"
                    :data="rows.data"
                    :current-page="rows.current_page"
                    :last-page="rows.last_page"
                    :per-page="rows.per_page"
                    :showing-text="`Showing ${rows.from || 0} to ${rows.to || 0} of ${rows.total} stores`"
                    :is-loading="false"
                    @update:search="filters.search = $event"
                    @go-to-page="goToPage"
                    @change-per-page="changePerPage"
                >
                    <template #actions>
                        <div class="flex items-center space-x-2">
                            <button v-if="hasPermission('cctv_monitoring.create')" @click="openCreateModal"
                                class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors flex items-center space-x-2 shadow-sm whitespace-nowrap">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" /></svg>
                                <span>Add System</span>
                            </button>
                            <!-- Import button hidden temporarily -->
                            <!-- <button v-if="hasPermission('cctv_monitoring.create')" @click="openImportModal"
                                class="bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors flex items-center space-x-2 shadow-sm whitespace-nowrap">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
                                <span>Import</span>
                            </button> -->
                        </div>
                    </template>

                    <template #header>
                        <tr>
                            <th class="px-3 py-3 text-left text-[11px] font-black uppercase tracking-wider text-slate-500 sticky left-0 bg-white z-10">Store</th>
                            <th class="px-3 py-3 text-left text-[10px] font-black uppercase tracking-wider text-slate-400">Equip</th>
                            <th v-for="m in 12" :key="m" class="px-2 py-3 text-center text-[10px] font-black uppercase tracking-wider text-slate-500">{{ monthShort(m) }}</th>
                            <th class="px-3 py-3 text-left text-[10px] font-black uppercase tracking-wider text-slate-400">Latest</th>
                            <th class="px-3 py-3 text-right text-[11px] font-black uppercase tracking-wider text-slate-500">Actions</th>
                        </tr>
                    </template>

                    <template #body="{ data }">
                        <tr v-for="row in data" :key="row.id" class="hover:bg-gray-50">
                            <td class="px-3 py-2 sticky left-0 bg-white z-10 border-r border-gray-100">
                                <div class="text-sm font-bold text-gray-900">{{ row.store?.code }}</div>
                                <div class="text-[11px] text-gray-500 truncate max-w-[160px]">{{ row.store?.name }}</div>
                                <div class="text-[10px] text-gray-400">{{ row.store?.brand }} · {{ row.store?.area }}</div>
                            </td>
                            <td class="px-3 py-2">
                                <div class="flex flex-col gap-0.5 text-[10px]">
                                    <span class="font-bold text-slate-600" :title="`Cameras: ${row.inventory_context.camera_count}`">📷 {{ row.inventory_context.camera_count }}</span>
                                    <span class="font-bold text-slate-600" :title="`DVR/NVR: ${row.inventory_context.dvr_nvr_count}`">🎬 {{ row.inventory_context.dvr_nvr_count }}</span>
                                </div>
                            </td>
                            <td v-for="m in 12" :key="m" class="px-2 py-2 text-center">
                                <template v-if="row.months[m]">
                                    <button @click="openInspectionModal(row, m, row.months[m])"
                                        class="w-full py-1.5 rounded-md text-[10px] font-black uppercase tracking-wide transition-transform hover:scale-105"
                                        :class="statusColorClass(row.months[m].status, 'bg')"
                                        :title="row.months[m].date + ' · ' + row.months[m].status">
                                        {{ statusChipLabel(row.months[m].status) }}
                                    </button>
                                    <a v-if="row.months[m].ticket_key && row.months[m].ticket_id"
                                        :href="route('tickets.edit', row.months[m].ticket_id)"
                                        target="_blank"
                                        @click.stop
                                        class="block mt-0.5 text-[9px] font-bold text-blue-500 hover:text-blue-800 hover:underline leading-tight truncate">
                                        {{ row.months[m].ticket_key }}
                                    </a>
                                </template>
                                <button v-else-if="hasPermission('cctv_monitoring.create')" @click="openInspectionModal(row, m)"
                                    class="w-full py-1.5 rounded-md text-[10px] font-bold text-gray-300 hover:text-blue-600 hover:bg-blue-50 transition-colors">+</button>
                                <span v-else class="text-gray-200">·</span>
                            </td>
                            <td class="px-3 py-2">
                                <span v-if="row.latest_status" class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold"
                                      :class="statusColorClass(row.latest_status, 'bg')">{{ row.latest_status }}</span>
                                <span v-else class="text-[11px] text-gray-400 italic">No record</span>
                            </td>
                            <td class="px-3 py-2 text-right">
                                <div class="flex justify-end space-x-1">
                                    <button v-if="hasPermission('cctv_monitoring.edit')" @click="openSystemModal(row)" title="Configure CCTV"
                                        class="p-2 text-slate-500 hover:text-slate-800 hover:bg-slate-100 rounded-full transition-colors">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </template>
                </DataTable>
            </div>
        </div>

        <!-- Inspection Modal -->
        <div v-if="showInspectionModal" class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex items-center justify-center min-h-screen px-4 py-6">
                <div class="fixed inset-0 bg-black/30 backdrop-blur-md" @click="closeInspectionModal"></div>
                <div class="relative bg-white rounded-xl shadow-2xl w-full max-w-4xl p-6 border border-gray-100 max-h-[92vh] overflow-y-auto">
                    <div class="flex justify-between items-center mb-4">
                        <div>
                            <h3 class="text-xl font-bold text-gray-900">
                                {{ editingInspection ? 'Edit' : 'Log' }} CCTV Inspection
                            </h3>
                            <p class="text-xs text-gray-500 mt-0.5">
                                {{ activeRow?.store?.code }} · {{ activeRow?.store?.name }} · {{ activeMonthLabel }}
                            </p>
                        </div>
                        <button @click="closeInspectionModal" class="text-gray-400 hover:text-gray-600">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                        </button>
                    </div>

                    <form @submit.prevent="submitInspection" class="space-y-5">
                        <!-- Deployed Equipment (read-only) -->
                        <div class="p-4 bg-slate-50 rounded-lg border border-slate-100">
                            <div class="flex items-center justify-between mb-2">
                                <h4 class="text-xs font-black text-slate-500 uppercase tracking-widest">Deployed CCTV Equipment</h4>
                                <span class="text-[11px] text-slate-400">{{ activeRow?.inventory_context.units.length || 0 }} units found at store</span>
                            </div>
                            <div v-if="activeRow?.inventory_context.units.length" class="grid grid-cols-2 md:grid-cols-3 gap-2">
                                <div v-for="u in activeRow.inventory_context.units" :key="u.stock_in_id"
                                     class="bg-white rounded-md border border-slate-200 px-2 py-1.5">
                                    <div class="text-[11px] font-bold text-gray-800 truncate">{{ u.item_code }} {{ u.brand }} {{ u.model }}</div>
                                    <div class="flex items-center justify-between mt-0.5">
                                        <span class="text-[9px] uppercase font-black px-1.5 py-0.5 rounded"
                                              :class="u.role === 'camera' ? 'bg-blue-50 text-blue-600' : 'bg-purple-50 text-purple-600'">{{ u.role }}</span>
                                        <span class="text-[10px] font-mono text-gray-500 truncate">{{ u.serial_no || u.barcode || '—' }}</span>
                                    </div>
                                </div>
                            </div>
                            <p v-else class="text-[11px] text-slate-400 italic">No CCTV equipment matched by keyword (DVR/NVR/Camera) in inventory at this store.</p>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Inspection Date <span class="text-red-500">*</span></label>
                                <input v-model="inspectionForm.inspection_date" type="date" required class="block w-full border-gray-300 rounded-lg text-sm focus:ring-blue-500 focus:border-blue-500">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Overall Status <span class="text-red-500">*</span></label>
                                <select v-model="inspectionForm.overall_status" required class="block w-full border-gray-300 rounded-lg text-sm focus:ring-blue-500 focus:border-blue-500">
                                    <option v-for="s in statuses" :key="s" :value="s">{{ s }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Tech</label>
                                <Autocomplete
                                    v-model="inspectionForm.technician"
                                    :options="assignableStaff"
                                    placeholder="Select technician..."
                                />
                            </div>
                        </div>

                        <div class="grid grid-cols-3 gap-4">
                            <div>
                                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Working Cameras</label>
                                <input v-model.number="inspectionForm.working_cameras" type="number" min="0" @input="autoTotal" class="block w-full border-gray-300 rounded-lg text-sm focus:ring-blue-500 focus:border-blue-500">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Not Working</label>
                                <input v-model.number="inspectionForm.not_working_cameras" type="number" min="0" @input="autoTotal" class="block w-full border-gray-300 rounded-lg text-sm focus:ring-blue-500 focus:border-blue-500">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Total Cameras</label>
                                <input v-model.number="inspectionForm.total_cameras" type="number" min="0"
                                       :placeholder="`inv: ${activeRow?.inventory_context.camera_count}`" class="block w-full border-gray-300 rounded-lg text-sm focus:ring-blue-500 focus:border-blue-500">
                            </div>
                        </div>

                        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                            <div>
                                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Data Retention</label>
                                <input v-model="inspectionForm.data_retention" type="text" placeholder="e.g. 40 days" class="block w-full border-gray-300 rounded-lg text-sm focus:ring-blue-500 focus:border-blue-500">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Storage</label>
                                <input v-model="inspectionForm.storage" type="text" placeholder="e.g. 5TB" class="block w-full border-gray-300 rounded-lg text-sm focus:ring-blue-500 focus:border-blue-500">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">UPS Status</label>
                                <input v-model="inspectionForm.ups_status" type="text" class="block w-full border-gray-300 rounded-lg text-sm focus:ring-blue-500 focus:border-blue-500">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">LGU Status</label>
                                <select v-model="inspectionForm.lgu_status" class="block w-full border-gray-300 rounded-lg text-sm focus:ring-blue-500 focus:border-blue-500">
                                    <option v-for="s in lguStatuses" :key="s" :value="s">{{ s }}</option>
                                </select>
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">LGU Memo</label>
                            <input v-model="inspectionForm.lgu_memo" type="text" class="block w-full border-gray-300 rounded-lg text-sm focus:ring-blue-500 focus:border-blue-500">
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Next Step</label>
                                <textarea v-model="inspectionForm.next_step" rows="2" class="block w-full border-gray-300 rounded-lg text-sm focus:ring-blue-500 focus:border-blue-500"></textarea>
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Remarks</label>
                                <textarea v-model="inspectionForm.remarks" rows="2" class="block w-full border-gray-300 rounded-lg text-sm focus:ring-blue-500 focus:border-blue-500"></textarea>
                            </div>
                        </div>

                        <!-- Units Inspected / Defective -->
                        <div class="p-4 bg-red-50/40 rounded-lg border border-red-100">
                            <div class="flex items-center justify-between mb-2">
                                <div>
                                    <h4 class="text-xs font-black text-red-500 uppercase tracking-widest">Units Inspected / Defective</h4>
                                    <p class="text-[10px] text-gray-400">Auto-loaded from deployed inventory — mark any defective ones.</p>
                                </div>
                                <button type="button" @click="openUnitPicker" class="text-[11px] font-bold text-blue-600 hover:text-blue-700">+ Add unit</button>
                            </div>
                            <div v-if="inspectionForm.linked_units.length" class="space-y-1.5">
                                <div v-for="(lu, idx) in inspectionForm.linked_units" :key="lu.stock_in_id"
                                     class="flex items-center gap-2 bg-white rounded-md border border-gray-200 px-3 py-2">
                                    <div class="flex-1 min-w-0">
                                        <div class="text-xs font-bold text-gray-800 truncate">{{ lu.item_code }} {{ lu.brand }} {{ lu.model }}</div>
                                        <div class="text-[10px] font-mono text-gray-400">{{ lu.serial_no || lu.barcode || '—' }}</div>
                                    </div>
                                    <select v-model="lu.condition" class="border-gray-300 rounded-md text-[11px] font-bold">
                                        <option value="Working">Working</option>
                                        <option value="Defective">Defective</option>
                                        <option value="N/A">N/A</option>
                                    </select>
                                    <button type="button" @click="removeLinkedUnit(idx)" class="text-red-400 hover:text-red-600 p-1">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                                    </button>
                                </div>
                            </div>
                            <p v-else class="text-[11px] text-gray-400 italic">No CCTV units found in this store's deployed inventory.</p>
                        </div>

                        <!-- Mandatory Ticket -->
                        <div class="p-4 bg-blue-50/50 rounded-lg border border-blue-100">
                            <div class="flex items-center justify-between mb-2">
                                <h4 class="text-xs font-black text-blue-600 uppercase tracking-widest">Linked Ticket <span class="text-red-500">(required)</span></h4>
                                <a v-if="existingTicket" :href="route('tickets.edit', existingTicket.id)" target="_blank"
                                   class="text-[11px] font-bold text-blue-700 hover:text-blue-900 hover:underline">🎫 {{ existingTicket.ticket_key }} ↗</a>
                            </div>
                            <div v-if="existingTicket" class="text-xs text-gray-600">
                                Ticket already created: <span class="font-bold">{{ existingTicket.title }}</span> ({{ existingTicket.status }})
                            </div>
                            <div v-else class="grid grid-cols-1 gap-2">
                                <input v-model="inspectionForm.ticket_title" type="text" placeholder="Ticket title (auto-generated if blank)"
                                       class="block w-full border-gray-300 rounded-lg text-sm focus:ring-blue-500 focus:border-blue-500">
                                <textarea v-model="inspectionForm.ticket_description" rows="2" placeholder="Ticket description (optional)"
                                          class="block w-full border-gray-300 rounded-lg text-sm focus:ring-blue-500 focus:border-blue-500"></textarea>
                                <p class="text-[10px] text-blue-500">A ticket is auto-created with this store + CCTV category on save.</p>
                            </div>
                        </div>

                        <div class="flex justify-between items-center pt-4 border-t">
                            <button v-if="editingInspection && hasPermission('cctv_monitoring.delete')" type="button" @click="deleteInspection"
                                    class="px-4 py-2 text-sm font-bold text-red-600 bg-red-50 rounded-lg hover:bg-red-100 transition-colors">Delete Inspection</button>
                            <span v-else></span>
                            <div class="flex space-x-3">
                                <button type="button" @click="closeInspectionModal" class="px-4 py-2 text-sm font-semibold text-gray-600 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors">Cancel</button>
                                <button type="submit" :disabled="saving"
                                        class="px-6 py-2 bg-blue-600 text-white text-sm font-bold rounded-lg hover:bg-blue-700 shadow-md transition-all disabled:opacity-50">
                                    {{ saving ? 'Saving...' : (editingInspection ? 'Update Inspection' : 'Save Inspection') }}
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Unit Picker Modal -->
        <div v-if="showUnitPicker" class="fixed inset-0 z-[60] overflow-y-auto">
            <div class="flex items-center justify-center min-h-screen px-4 py-6">
                <div class="fixed inset-0 bg-black/40" @click="showUnitPicker = false"></div>
                <div class="relative bg-white rounded-xl shadow-2xl w-full max-w-2xl p-6 border border-gray-100 max-h-[80vh] overflow-y-auto">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-lg font-bold text-gray-900">Link CCTV Unit</h3>
                        <button @click="showUnitPicker = false" class="text-gray-400 hover:text-gray-600">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                        </button>
                    </div>
                    <div v-if="unitOptions.length" class="space-y-2">
                        <div v-for="u in availableUnits" :key="u.stock_in_id"
                             class="flex items-center gap-3 border border-gray-200 rounded-lg px-3 py-2 hover:bg-blue-50">
                            <div class="flex-1">
                                <div class="text-sm font-bold text-gray-800">{{ u.item_code }} {{ u.brand }} {{ u.model }}</div>
                                <div class="text-[11px] text-gray-400 font-mono">{{ u.serial_no || u.barcode || '—' }} · {{ u.role }}</div>
                            </div>
                            <button type="button" @click="linkUnit(u)" class="px-3 py-1.5 bg-blue-600 text-white text-xs font-bold rounded-md hover:bg-blue-700">Add</button>
                        </div>
                        <p v-if="!availableUnits.length" class="text-[11px] text-gray-400 italic text-center py-3">All available units are already linked.</p>
                    </div>
                    <div v-else class="text-center py-8 text-sm text-gray-400">
                        No CCTV units found in inventory at this store.
                    </div>
                </div>
            </div>
        </div>

        <!-- System Config Modal -->
        <div v-if="showSystemModal" class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex items-center justify-center min-h-screen px-4 py-6">
                <div class="fixed inset-0 bg-black/30 backdrop-blur-md" @click="showSystemModal = false"></div>
                <div class="relative bg-white rounded-xl shadow-2xl w-full max-w-xl p-6 border border-gray-100">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-lg font-bold text-gray-900">CCTV System — {{ activeRow?.store?.code }}</h3>
                        <button @click="showSystemModal = false" class="text-gray-400 hover:text-gray-600">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                        </button>
                    </div>
                    <form @submit.prevent="submitSystem" class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">CCTV Type</label>
                            <select v-model="systemForm.cctv_type" class="block w-full border-gray-300 rounded-lg text-sm focus:ring-blue-500 focus:border-blue-500">
                                <option value="">—</option>
                                <option value="DVR">DVR</option>
                                <option value="NVR">NVR</option>
                                <option value="Hybrid">Hybrid</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Total DVR/NVR No.</label>
                            <input v-model.number="systemForm.dvr_nvr_count" type="number" min="0" class="block w-full border-gray-300 rounded-lg text-sm focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Expected Cameras</label>
                            <input v-model.number="systemForm.expected_cameras" type="number" min="0" class="block w-full border-gray-300 rounded-lg text-sm focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">DPO Seal Checking</label>
                            <select v-model="systemForm.dpo_seal_checking" class="block w-full border-gray-300 rounded-lg text-sm focus:ring-blue-500 focus:border-blue-500">
                                <option value="Pending">Pending</option>
                                <option value="Done">Done</option>
                                <option value="N/A">N/A</option>
                            </select>
                        </div>
                        <div class="col-span-2 flex items-center gap-6">
                            <label class="flex items-center gap-2 text-sm"><input v-model="systemForm.has_qr_code" type="checkbox" class="rounded border-gray-300 text-blue-600"> Has QR Code</label>
                            <label class="flex items-center gap-2 text-sm"><input v-model="systemForm.setup_completed" type="checkbox" class="rounded border-gray-300 text-blue-600"> Setup Completed</label>
                        </div>
                        <div class="col-span-2 flex justify-end space-x-3 pt-4 border-t">
                            <button type="button" @click="showSystemModal = false" class="px-4 py-2 text-sm font-semibold text-gray-600 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors">Cancel</button>
                            <button type="submit" :disabled="saving" class="px-6 py-2 bg-blue-600 text-white text-sm font-bold rounded-lg hover:bg-blue-700 disabled:opacity-50">Save</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Create System Modal -->
        <div v-if="showCreateModal" class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex items-center justify-center min-h-screen px-4 py-6">
                <div class="fixed inset-0 bg-black/30 backdrop-blur-md" @click="showCreateModal = false"></div>
                <div class="relative bg-white rounded-xl shadow-2xl w-full max-w-xl p-6 border border-gray-100">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-lg font-bold text-gray-900">Add CCTV System</h3>
                        <button @click="showCreateModal = false" class="text-gray-400 hover:text-gray-600">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                        </button>
                    </div>
                    <form @submit.prevent="submitCreate" class="space-y-4">
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Store <span class="text-red-500">*</span></label>
                            <Autocomplete
                                v-model="createForm.store_id"
                                :options="availableStores"
                                placeholder="Search store by code or name..."
                            />
                            <p v-if="!availableStores.length" class="text-[11px] text-gray-400 italic mt-1">All stores already have a CCTV system configured.</p>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">CCTV Type</label>
                                <select v-model="createForm.cctv_type" class="block w-full border-gray-300 rounded-lg text-sm focus:ring-blue-500 focus:border-blue-500">
                                    <option value="">—</option>
                                    <option value="DVR">DVR</option>
                                    <option value="NVR">NVR</option>
                                    <option value="Hybrid">Hybrid</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Total DVR/NVR No.</label>
                                <input v-model.number="createForm.dvr_nvr_count" type="number" min="0" class="block w-full border-gray-300 rounded-lg text-sm focus:ring-blue-500 focus:border-blue-500">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Expected Cameras</label>
                                <input v-model.number="createForm.expected_cameras" type="number" min="0" class="block w-full border-gray-300 rounded-lg text-sm focus:ring-blue-500 focus:border-blue-500">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">DPO Seal Checking</label>
                                <select v-model="createForm.dpo_seal_checking" class="block w-full border-gray-300 rounded-lg text-sm focus:ring-blue-500 focus:border-blue-500">
                                    <option value="Pending">Pending</option>
                                    <option value="Done">Done</option>
                                    <option value="N/A">N/A</option>
                                </select>
                            </div>
                        </div>
                        <div class="flex items-center gap-6">
                            <label class="flex items-center gap-2 text-sm"><input v-model="createForm.has_qr_code" type="checkbox" class="rounded border-gray-300 text-blue-600"> Has QR Code</label>
                            <label class="flex items-center gap-2 text-sm"><input v-model="createForm.setup_completed" type="checkbox" class="rounded border-gray-300 text-blue-600"> Setup Completed</label>
                        </div>
                        <div class="flex justify-end space-x-3 pt-4 border-t">
                            <button type="button" @click="showCreateModal = false" class="px-4 py-2 text-sm font-semibold text-gray-600 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors">Cancel</button>
                            <button type="submit" :disabled="saving || !createForm.store_id"
                                    class="px-6 py-2 bg-blue-600 text-white text-sm font-bold rounded-lg hover:bg-blue-700 shadow-md transition-all disabled:opacity-50">{{ saving ? 'Saving...' : 'Create System' }}</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Import Modal -->
        <div v-if="showImportModal" class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex items-center justify-center min-h-screen px-4 py-6">
                <div class="fixed inset-0 bg-black/30 backdrop-blur-md" @click="showImportModal = false"></div>
                <div class="relative bg-white rounded-xl shadow-2xl w-full max-w-xl p-6 border border-gray-100">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-lg font-bold text-gray-900">Import CCTV Inspections</h3>
                        <button @click="showImportModal = false" class="text-gray-400 hover:text-gray-600">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                        </button>
                    </div>
                    <div class="space-y-4">
                        <div class="p-4 bg-blue-50 rounded-lg border border-blue-100">
                            <p class="text-xs text-blue-700">Each row = one store-month inspection. Match by <b>Store Code</b>. A ticket is auto-created per row.</p>
                            <a :href="route('cctv-monitoring.import-template')" class="text-xs font-black text-blue-700 underline mt-2 inline-block">Download CSV Template</a>
                        </div>
                        <input type="file" ref="importFileInput" accept=".csv" @change="handleImportFile" class="block w-full text-sm text-gray-500">
                        <div v-if="importErrors.length" class="p-3 bg-amber-50 rounded-lg border border-amber-100 max-h-40 overflow-y-auto">
                            <p class="text-xs font-bold text-amber-800 mb-1">Issues:</p>
                            <ul class="text-[11px] text-amber-700 list-disc pl-4">
                                <li v-for="(e, i) in importErrors" :key="i">{{ e }}</li>
                            </ul>
                        </div>
                        <div class="flex justify-end space-x-3 pt-4 border-t">
                            <button type="button" @click="showImportModal = false" class="px-4 py-2 text-sm font-semibold text-gray-600 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors">Close</button>
                            <button @click="submitImport" :disabled="!importFile || importing"
                                    class="px-6 py-2 bg-emerald-600 text-white text-sm font-bold rounded-lg hover:bg-emerald-700 disabled:opacity-50">{{ importing ? 'Importing...' : 'Start Import' }}</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref, reactive, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import DataTable from '@/Components/DataTable.vue'
import Autocomplete from '@/Components/Autocomplete.vue'
import { useToast } from '@/Composables/useToast'
import { useConfirm } from '@/Composables/useConfirm'
import { usePermission } from '@/Composables/usePermission'
import axios from 'axios'

const props = defineProps({
    rows: Object,
    filters: Object,
    statuses: Array,
    lguStatuses: Array,
    brands: Array,
    sectors: Array,
    summary: Object,
    availableStores: { type: Array, default: () => [] },
    assignableStaff: { type: Array, default: () => [] },
})

const { showSuccess, showError } = useToast()
const { confirm } = useConfirm()
const { hasPermission } = usePermission()

const route = window.route

const filters = reactive({ ...props.filters })

const STATUS_STYLES = {
    'Working': { bg: 'bg-emerald-100 text-emerald-700', text: 'text-emerald-600' },
    'Not Working': { bg: 'bg-red-100 text-red-700', text: 'text-red-600' },
    'For Schedule': { bg: 'bg-amber-100 text-amber-700', text: 'text-amber-600' },
    'On-going': { bg: 'bg-blue-100 text-blue-700', text: 'text-blue-600' },
    'Pending': { bg: 'bg-gray-100 text-gray-600', text: 'text-gray-500' },
}

const statusColorClass = (status, kind) => (STATUS_STYLES[status]?.[kind] || 'bg-gray-100 text-gray-500')
const statusChipLabel = (status) => ({ 'Working': 'OK', 'Not Working': 'X', 'For Schedule': 'SCH', 'On-going': 'ON', 'Pending': 'PD' }[status] || '·')
const monthShort = (m) => ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'][m - 1]

const applyFilters = () => router.get(route('cctv-monitoring.index'), { ...filters }, { preserveState: true, preserveScroll: true })
const resetFilters = () => {
    filters.year = new Date().getFullYear()
    filters.sector = null
    filters.brand = ''
    filters.status = ''
    filters.search = ''
    applyFilters()
}
const goToPage = (page) => router.get(route('cctv-monitoring.index'), { ...filters, page }, { preserveState: true, preserveScroll: true })
const changePerPage = (perPage) => router.get(route('cctv-monitoring.index'), { ...filters, per_page: perPage }, { preserveState: true, preserveScroll: true })

// ---- Inspection Modal ----
const showInspectionModal = ref(false)
const showUnitPicker = ref(false)
const showSystemModal = ref(false)
const showImportModal = ref(false)
const showCreateModal = ref(false)
const saving = ref(false)
const editingInspection = ref(false)
const editingInspectionId = ref(null)
const activeRow = ref(null)
const activeMonth = ref(null)
const existingTicket = ref(null)
const unitOptions = ref([])

const inspectionForm = reactive({
    inspection_date: '',
    overall_status: 'Working',
    working_cameras: null,
    not_working_cameras: null,
    total_cameras: null,
    technician: '',
    data_retention: '',
    storage: '',
    ups_status: '',
    lgu_memo: '',
    lgu_status: 'Pending',
    next_step: '',
    remarks: '',
    ticket_title: '',
    ticket_description: '',
    linked_units: [],
})

const systemForm = reactive({
    cctv_type: '',
    has_qr_code: false,
    setup_completed: false,
    dpo_seal_checking: 'Pending',
    dvr_nvr_count: null,
    expected_cameras: null,
})

const createForm = reactive({
    store_id: '',
    cctv_type: '',
    has_qr_code: false,
    setup_completed: false,
    dpo_seal_checking: 'Pending',
    dvr_nvr_count: null,
    expected_cameras: null,
})

const importFile = ref(null)
const importing = ref(false)
const importErrors = ref([])

const activeMonthLabel = computed(() => activeMonth.value ? monthShort(activeMonth.value) + ' ' + filters.year : '')
const availableUnits = computed(() => {
    const linked = new Set(inspectionForm.linked_units.map(u => u.stock_in_id))
    return unitOptions.value.filter(u => !linked.has(u.stock_in_id))
})

const openInspectionModal = async (row, month, existing) => {
    activeRow.value = row
    activeMonth.value = month
    existingTicket.value = null
    Object.assign(inspectionForm, {
        inspection_date: `${filters.year}-${String(month).padStart(2, '0')}-01`,
        overall_status: 'Working',
        working_cameras: null,
        not_working_cameras: null,
        total_cameras: row.inventory_context.camera_count || null,
        technician: '',
        data_retention: '',
        storage: '',
        ups_status: '',
        lgu_memo: '',
        lgu_status: 'Pending',
        next_step: '',
        remarks: '',
        ticket_title: '',
        ticket_description: '',
        linked_units: [],
    })

    if (existing) {
        try {
            const { data } = await axios.get(route('cctv-monitoring.inspections.show', existing.inspection_id))
            editingInspection.value = true
            editingInspectionId.value = data.id
            Object.assign(inspectionForm, {
                inspection_date: data.inspection_date,
                overall_status: data.overall_status,
                working_cameras: data.working_cameras,
                not_working_cameras: data.not_working_cameras,
                total_cameras: data.total_cameras,
                technician: data.technician || '',
                data_retention: data.data_retention || '',
                storage: data.storage || '',
                ups_status: data.ups_status || '',
                lgu_memo: data.lgu_memo || '',
                lgu_status: data.lgu_status || 'Pending',
                next_step: data.next_step || '',
                remarks: data.remarks || '',
                linked_units: data.linked_units.map(u => ({
                    stock_in_id: u.stock_in_id,
                    condition: u.condition,
                    notes: u.notes,
                    item_code: u.item_code,
                    brand: u.brand,
                    model: u.model,
                    serial_no: u.serial_no,
                    barcode: u.barcode,
                })),
            })
            existingTicket.value = data.ticket
        } catch (e) {
            editingInspection.value = false
        }
    } else {
        editingInspection.value = false
        editingInspectionId.value = null
    }

    const units = await loadUnitOptions(row.store.id)

    // Auto-populate inspected units from the store's deployed inventory for new
    // inspections — no need to link them one by one. Each defaults to "Working";
    // the user just flips the defective ones.
    if (!editingInspection.value) {
        inspectionForm.linked_units = units.map(u => ({
            stock_in_id: u.stock_in_id,
            condition: 'Working',
            notes: '',
            item_code: u.item_code,
            brand: u.brand,
            model: u.model,
            serial_no: u.serial_no,
            barcode: u.barcode,
        }))
    }

    showInspectionModal.value = true
}

const loadUnitOptions = async (storeId) => {
    try {
        const { data } = await axios.get(route('cctv-monitoring.units.search', storeId))
        unitOptions.value = data.units
        return data.units
    } catch (e) {
        unitOptions.value = []
        return []
    }
}

const closeInspectionModal = () => { showInspectionModal.value = false }

const autoTotal = () => {
    const w = inspectionForm.working_cameras || 0
    const nw = inspectionForm.not_working_cameras || 0
    inspectionForm.total_cameras = (w + nw) || null
}

const openUnitPicker = () => { showUnitPicker.value = true }
const linkUnit = (u) => {
    inspectionForm.linked_units.push({
        stock_in_id: u.stock_in_id,
        condition: 'Working',
        notes: '',
        item_code: u.item_code,
        brand: u.brand,
        model: u.model,
        serial_no: u.serial_no,
        barcode: u.barcode,
    })
}
const removeLinkedUnit = (idx) => inspectionForm.linked_units.splice(idx, 1)

const submitInspection = async () => {
    saving.value = true
    const payload = { ...inspectionForm }
    try {
        if (editingInspection.value) {
            await axios.put(route('cctv-monitoring.inspections.update', editingInspectionId.value), payload)
            showSuccess('Inspection updated')
        } else {
            await axios.post(route('cctv-monitoring.inspections.store', activeRow.value.id), payload)
            showSuccess('Inspection saved — ticket created')
        }
        showInspectionModal.value = false
        router.reload({ preserveScroll: true })
    } catch (e) {
        const msg = e.response?.data?.message || Object.values(e.response?.data?.errors || {}).flat().join(', ') || 'Save failed'
        showError(msg)
    } finally {
        saving.value = false
    }
}

const deleteInspection = async () => {
    const ok = await confirm({ title: 'Delete Inspection', message: 'Delete this CCTV inspection? The linked ticket is kept.' })
    if (!ok) return
    try {
        await axios.delete(route('cctv-monitoring.inspections.destroy', editingInspectionId.value))
        showSuccess('Inspection deleted')
        showInspectionModal.value = false
        router.reload({ preserveScroll: true })
    } catch (e) {
        showError('Delete failed')
    }
}

// ---- System Modal ----
const openSystemModal = (row) => {
    activeRow.value = row
    Object.assign(systemForm, {
        cctv_type: row.cctv_type || '',
        has_qr_code: row.has_qr_code,
        setup_completed: row.setup_completed,
        dpo_seal_checking: row.dpo_seal_checking || 'Pending',
        dvr_nvr_count: row.dvr_nvr_count ?? (row.inventory_context.dvr_nvr_count || null),
        expected_cameras: row.expected_cameras ?? (row.inventory_context.camera_count || null),
    })
    showSystemModal.value = true
}

const submitSystem = async () => {
    saving.value = true
    try {
        await axios.put(route('cctv-monitoring.update', activeRow.value.id), systemForm)
        showSuccess('CCTV system updated')
        showSystemModal.value = false
        router.reload({ preserveScroll: true })
    } catch (e) {
        showError(e.response?.data?.message || 'Update failed')
    } finally {
        saving.value = false
    }
}

// ---- Create System ----
const openCreateModal = () => {
    Object.assign(createForm, {
        store_id: '',
        cctv_type: '',
        has_qr_code: false,
        setup_completed: false,
        dpo_seal_checking: 'Pending',
        dvr_nvr_count: null,
        expected_cameras: null,
    })
    showCreateModal.value = true
}

const submitCreate = async () => {
    saving.value = true
    try {
        await axios.post(route('cctv-monitoring.store'), createForm)
        showSuccess('CCTV system created')
        showCreateModal.value = false
        router.reload({ preserveScroll: true })
    } catch (e) {
        const msg = e.response?.data?.message || Object.values(e.response?.data?.errors || {}).flat().join(', ') || 'Create failed'
        showError(msg)
    } finally {
        saving.value = false
    }
}

// ---- Import ----
const handleImportFile = (e) => { importFile.value = e.target.files[0]; importErrors.value = [] }
const submitImport = async () => {
    if (!importFile.value) return
    importing.value = true
    const formData = new FormData()
    formData.append('file', importFile.value)
    try {
        const { data } = await axios.post(route('cctv-monitoring.import'), formData, { headers: { 'Content-Type': 'multipart/form-data' } })
        showSuccess(`Imported ${data.imported} inspection(s)`)
        if (data.errors?.length) importErrors.value = data.errors
        else { showImportModal.value = false; router.reload({ preserveScroll: true }) }
    } catch (e) {
        importErrors.value = e.response?.data?.errors?.import || ['Import failed']
        showError('Import failed')
    } finally {
        importing.value = false
    }
}
</script>
