<template>
    <AppLayout title="Service Vehicle Trips">
        <div class="py-10">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

                <!-- Stat cards -->
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
                        <p class="text-xs font-bold text-gray-500 uppercase tracking-wider">Pending Approval</p>
                        <p class="text-2xl font-black text-amber-600 mt-1">{{ summary.pending_approval }}</p>
                    </div>
                    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
                        <p class="text-xs font-bold text-gray-500 uppercase tracking-wider">Scheduled (next 7d)</p>
                        <p class="text-2xl font-black text-blue-600 mt-1">{{ summary.scheduled_next_7d }}</p>
                    </div>
                    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
                        <p class="text-xs font-bold text-gray-500 uppercase tracking-wider">In Progress</p>
                        <p class="text-2xl font-black text-emerald-600 mt-1">{{ summary.in_progress }}</p>
                    </div>
                    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
                        <p class="text-xs font-bold text-gray-500 uppercase tracking-wider">Trips This Month</p>
                        <p class="text-2xl font-black text-gray-900 mt-1">{{ summary.trips_this_month }}</p>
                    </div>
                </div>

                <!-- Tabs + Book button -->
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-1 inline-flex">
                        <button type="button" @click="activeTab = 'calendar'" :class="tabClass('calendar')">Calendar</button>
                        <button type="button" @click="activeTab = 'table'" :class="tabClass('table')">Table</button>
                        <button v-if="hasPermission('service_vehicle_trips.edit')" type="button" @click="activeTab = 'vehicles'" :class="tabClass('vehicles')">Vehicles</button>
                    </div>
                    <button v-if="hasPermission('service_vehicle_trips.create')" @click="openBookModal" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-bold whitespace-nowrap inline-flex items-center gap-2">
                        <PlusIcon class="w-4 h-4" /> Book Trip
                    </button>
                </div>

                <!-- Filters -->
                <div v-if="activeTab !== 'vehicles'" class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
                    <div class="grid grid-cols-1 md:grid-cols-5 gap-3 items-end">
                        <div>
                            <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">Vehicle</label>
                            <Autocomplete v-model="filters.vehicle_id" :options="vehicleFilterOptions" placeholder="All vehicles" />
                        </div>
                        <div>
                            <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">Driver</label>
                            <Autocomplete v-model="filters.driver_id" :options="driverFilterOptions" placeholder="All drivers" />
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">Status</label>
                            <MultiAutocomplete v-model="filters.statuses" :options="statusOptions" label-key="label" value-key="value" placeholder="All statuses..." />
                        </div>
                        <div v-if="activeTab === 'table'">
                            <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">Search</label>
                            <input v-model="filters.search" type="text" placeholder="Purpose, driver, plate..." class="w-full border-gray-300 rounded-lg text-sm focus:ring-blue-500 focus:border-blue-500">
                        </div>
                    </div>
                    <div class="mt-3 flex justify-end">
                        <button @click="resetFilters" class="px-3 py-1.5 text-xs font-bold text-gray-600 bg-gray-100 hover:bg-gray-200 rounded-lg">Reset</button>
                    </div>
                </div>

                <!-- Calendar tab -->
                <div v-if="activeTab === 'calendar'" class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="text-lg font-black text-gray-900 uppercase tracking-tight">{{ monthLabel }}</h2>
                        <div class="flex items-center gap-1">
                            <button type="button" @click="navigateMonth(-1)" class="p-2 rounded-lg hover:bg-gray-100 text-gray-600" title="Previous month">
                                <ChevronLeftIcon class="w-4 h-4" />
                            </button>
                            <button type="button" @click="goToCurrentMonth" class="px-3 py-1.5 text-xs font-bold text-gray-600 bg-gray-50 hover:bg-gray-100 rounded-lg">Today</button>
                            <button type="button" @click="navigateMonth(1)" class="p-2 rounded-lg hover:bg-gray-100 text-gray-600" title="Next month">
                                <ChevronRightIcon class="w-4 h-4" />
                            </button>
                        </div>
                    </div>
                    <div class="grid grid-cols-7 gap-1 text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1">
                        <div v-for="dow in ['Sun','Mon','Tue','Wed','Thu','Fri','Sat']" :key="dow" class="text-center py-1">{{ dow }}</div>
                    </div>
                    <div class="grid grid-cols-7 gap-1">
                        <div
                            v-for="cell in calendarCells"
                            :key="cell.key"
                            @click="hasPermission('service_vehicle_trips.create') ? openBookModal(cell.key) : null"
                            class="min-h-[100px] rounded-lg border p-1.5 flex flex-col gap-1 transition-colors"
                            :class="[
                                cell.inCurrentMonth ? 'bg-white border-gray-100' : 'bg-gray-50/60 border-gray-50',
                                cell.isToday ? 'ring-2 ring-blue-300' : '',
                                hasPermission('service_vehicle_trips.create') ? 'cursor-pointer hover:bg-gray-50' : '',
                            ]"
                        >
                            <div class="flex items-center justify-between">
                                <span class="text-xs font-bold" :class="cell.inCurrentMonth ? 'text-gray-700' : 'text-gray-400'">{{ cell.day }}</span>
                                <span v-if="cell.trips.length > 0" class="text-[9px] font-bold text-gray-400">{{ cell.trips.length }}</span>
                            </div>
                            <button
                                v-for="trip in cell.trips"
                                :key="trip.id"
                                type="button"
                                @click.stop="openViewModal(trip)"
                                class="text-left px-1.5 py-1 rounded text-[10px] leading-tight truncate transition-opacity hover:opacity-80"
                                :class="chipClass(trip.status)"
                                :title="`${trip.vehicle?.plate_no || ''} · ${trip.driver?.name || ''} · ${formatTime(trip.planned_departure_time)}–${formatTime(trip.planned_arrival_time)}`"
                            >
                                <span class="font-bold">{{ formatTime(trip.planned_departure_time) }}</span>
                                <span class="opacity-80"> · {{ trip.vehicle?.plate_no || trip.vehicle?.name || '—' }}</span>
                            </button>
                        </div>
                    </div>
                    <!-- Legend -->
                    <div class="mt-4 flex flex-wrap gap-3 text-[10px] font-bold uppercase tracking-widest text-gray-500">
                        <span v-for="opt in statusOptions" :key="opt.value" class="inline-flex items-center gap-1.5">
                            <span class="w-3 h-3 rounded" :class="legendDotClass(opt.value)"></span>
                            {{ opt.label }}
                        </span>
                    </div>
                </div>

                <!-- Table tab -->
                <DataTable
                    v-if="activeTab === 'table'"
                    title="Trip Records"
                    subtitle="All bookings and completed trips"
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
                            <th class="px-4 py-3 text-left text-[10px] font-bold text-gray-500 uppercase">Date</th>
                            <th class="px-4 py-3 text-left text-[10px] font-bold text-gray-500 uppercase">Vehicle</th>
                            <th class="px-4 py-3 text-left text-[10px] font-bold text-gray-500 uppercase">Driver</th>
                            <th class="px-4 py-3 text-left text-[10px] font-bold text-gray-500 uppercase">Route</th>
                            <th class="px-4 py-3 text-left text-[10px] font-bold text-gray-500 uppercase">Time</th>
                            <th class="px-4 py-3 text-left text-[10px] font-bold text-gray-500 uppercase">Status</th>
                            <th class="px-4 py-3 text-right text-[10px] font-bold text-gray-500 uppercase">Actions</th>
                        </tr>
                    </template>
                    <template #body="{ data }">
                        <tr v-for="trip in data" :key="trip.id" class="hover:bg-gray-50">
                            <td class="px-4 py-3 text-sm text-gray-900 whitespace-nowrap">{{ formatDate(trip.date_used) }}</td>
                            <td class="px-4 py-3 text-sm text-gray-900 whitespace-nowrap">
                                <div class="font-bold">{{ trip.vehicle?.name }}</div>
                                <div class="text-xs text-gray-500 font-mono">{{ trip.vehicle?.plate_no }}</div>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-900 whitespace-nowrap">{{ trip.driver?.name }}</td>
                            <td class="px-4 py-3 text-sm text-gray-900">
                                <div class="flex items-center gap-1.5 text-xs">
                                    <span class="font-bold text-gray-700">{{ trip.start_point }}</span>
                                    <ArrowRightIcon class="w-3 h-3 text-gray-400" />
                                    <span class="font-bold text-blue-600">{{ trip.end_point }}</span>
                                </div>
                                <div class="text-[10px] text-gray-500 truncate max-w-xs">{{ trip.purpose_of_travel }}</div>
                            </td>
                            <td class="px-4 py-3 text-xs text-gray-700 whitespace-nowrap">
                                {{ formatTime(trip.planned_departure_time) }} – {{ formatTime(trip.planned_arrival_time) }}
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider" :class="chipClass(trip.status)">
                                    {{ trip.status }}
                                </span>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-right">
                                <div class="flex justify-end space-x-1">
                                    <button @click="openViewModal(trip)" title="View" class="p-2 rounded-full text-gray-600 hover:text-gray-900 hover:bg-gray-50">
                                        <EyeIcon class="w-4 h-4" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </template>
                </DataTable>

                <!-- Vehicles tab -->
                <div v-if="activeTab === 'vehicles'" class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="text-lg font-black text-gray-900 uppercase tracking-tight">Service Vehicles</h2>
                        <button @click="openVehicleModal()" class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded-lg text-xs font-bold whitespace-nowrap inline-flex items-center gap-1">
                            <PlusIcon class="w-3.5 h-3.5" /> Add Vehicle
                        </button>
                    </div>
                    <table class="min-w-full divide-y divide-gray-100">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-3 py-2 text-left text-[10px] font-bold uppercase text-gray-500">Name</th>
                                <th class="px-3 py-2 text-left text-[10px] font-bold uppercase text-gray-500">Plate No</th>
                                <th class="px-3 py-2 text-left text-[10px] font-bold uppercase text-gray-500">Capacity</th>
                                <th class="px-3 py-2 text-left text-[10px] font-bold uppercase text-gray-500">Status</th>
                                <th class="px-3 py-2 text-right text-[10px] font-bold uppercase text-gray-500">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="v in allVehicles" :key="v.id" class="hover:bg-gray-50">
                                <td class="px-3 py-2 text-sm font-bold text-gray-900">{{ v.name }}</td>
                                <td class="px-3 py-2 text-sm font-mono text-gray-700">{{ v.plate_no }}</td>
                                <td class="px-3 py-2 text-sm text-gray-700">{{ v.capacity || '—' }}</td>
                                <td class="px-3 py-2"><span class="inline-flex px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider" :class="vehicleStatusClass(v.status)">{{ v.status }}</span></td>
                                <td class="px-3 py-2 text-right">
                                    <div class="flex justify-end space-x-1">
                                        <button @click="openVehicleModal(v)" title="Edit" class="p-2 rounded-full text-blue-600 hover:text-blue-900 hover:bg-blue-50">
                                            <PencilSquareIcon class="w-4 h-4" />
                                        </button>
                                        <button v-if="hasPermission('service_vehicle_trips.delete')" @click="deleteVehicle(v)" title="Delete" class="p-2 rounded-full text-red-600 hover:text-red-900 hover:bg-red-50">
                                            <TrashIcon class="w-4 h-4" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="allVehicles.length === 0">
                                <td colspan="5" class="px-3 py-6 text-center text-sm text-gray-400 italic">No vehicles yet. Add the first one to start booking trips.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

            </div>
        </div>

        <!-- Trip modal -->
        <Modal :show="tripModal.show" @close="closeTripModal" max-width="3xl">
            <div class="p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-black text-gray-900 uppercase tracking-tight">{{ modalTitle }}</h3>
                    <span v-if="tripModal.mode !== 'book' && tripModal.trip" class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider" :class="chipClass(tripModal.trip.status)">
                        {{ tripModal.trip.status }}
                    </span>
                </div>

                <!-- BOOK / EDIT mode -->
                <form v-if="tripModal.mode === 'book' || tripModal.mode === 'edit'" @submit.prevent="saveBooking" class="space-y-4">
                    <div v-if="conflict" class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-800">
                        Conflict: this vehicle is already booked from {{ formatTime(conflict.planned_departure_time) }} to {{ formatTime(conflict.planned_arrival_time) }} (driver: {{ conflict.driver_name || '—' }}, status {{ conflict.status }}).
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-gray-600 mb-1">Vehicle <span class="text-red-500">*</span></label>
                            <Autocomplete v-model="bookForm.service_vehicle_id" :options="vehicleOptions" placeholder="Select vehicle..." />
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-600 mb-1">Driver <span class="text-red-500">*</span></label>
                            <Autocomplete v-model="bookForm.driver_id" :options="driverOptions" placeholder="Select driver..." />
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-600 mb-1">Date Used <span class="text-red-500">*</span></label>
                            <input type="date" v-model="bookForm.date_used" required class="w-full rounded-lg border-gray-300 text-sm focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label class="block text-xs font-bold text-gray-600 mb-1">Planned Departure <span class="text-red-500">*</span></label>
                                <input type="time" v-model="bookForm.planned_departure_time" required class="w-full rounded-lg border-gray-300 text-sm focus:ring-blue-500 focus:border-blue-500">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-600 mb-1">Planned Arrival <span class="text-red-500">*</span></label>
                                <input type="time" v-model="bookForm.planned_arrival_time" required class="w-full rounded-lg border-gray-300 text-sm focus:ring-blue-500 focus:border-blue-500">
                            </div>
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-xs font-bold text-gray-600 mb-1">Purpose of Travel <span class="text-red-500">*</span></label>
                            <input type="text" v-model="bookForm.purpose_of_travel" required class="w-full rounded-lg border-gray-300 text-sm focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-600 mb-1">Origin (Start) <span class="text-red-500">*</span></label>
                            <input type="text" v-model="bookForm.start_point" required class="w-full rounded-lg border-gray-300 text-sm focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-600 mb-1">Final Destination (End) <span class="text-red-500">*</span></label>
                            <input type="text" v-model="bookForm.end_point" required class="w-full rounded-lg border-gray-300 text-sm focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        <div class="md:col-span-2 space-y-2">
                            <label class="block text-xs font-bold text-gray-600 mb-1">Waypoints (Stopovers)</label>
                            <div v-for="(wp, index) in bookForm.waypoints" :key="index" class="flex items-center gap-2">
                                <input type="text" v-model="bookForm.waypoints[index]" class="w-full rounded-lg border-gray-300 text-sm focus:ring-blue-500 focus:border-blue-500" placeholder="Enter waypoint...">
                                <button type="button" @click="bookForm.waypoints.splice(index, 1)" class="p-2 text-red-600 hover:bg-red-50 rounded-lg" title="Remove Waypoint">
                                    <TrashIcon class="w-4 h-4" />
                                </button>
                            </div>
                            <button type="button" @click="bookForm.waypoints.push('')" class="text-xs font-bold text-blue-600 hover:text-blue-800 flex items-center gap-1">
                                <PlusIcon class="w-3 h-3" /> Add Waypoint
                            </button>
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-xs font-bold text-gray-600 mb-1">Passengers (please enumerate)</label>
                            <textarea v-model="bookForm.passengers" rows="2" class="w-full rounded-lg border-gray-300 text-sm focus:ring-blue-500 focus:border-blue-500 resize-none" placeholder="Juan dela Cruz, Maria Santos..."></textarea>
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-xs font-bold text-gray-600 mb-1">Remarks</label>
                            <textarea v-model="bookForm.remarks" rows="2" class="w-full rounded-lg border-gray-300 text-sm focus:ring-blue-500 focus:border-blue-500 resize-none"></textarea>
                        </div>
                    </div>
                    <div class="flex justify-end gap-2 pt-2">
                        <button type="button" @click="closeTripModal" class="px-4 py-2 text-sm font-bold text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50">Cancel</button>
                        <button type="submit" :disabled="conflict !== null || isSaving" class="px-4 py-2 text-sm font-bold text-white bg-blue-600 hover:bg-blue-700 disabled:opacity-50 rounded-lg">
                            {{ tripModal.mode === 'edit' ? 'Save Changes' : 'Submit for Approval' }}
                        </button>
                    </div>
                </form>

                <!-- VIEW mode -->
                <div v-else-if="tripModal.mode === 'view' && tripModal.trip" class="space-y-5">

                    <!-- Booking Details -->
                    <div>
                        <p class="text-[10px] font-black uppercase tracking-widest text-gray-400 mb-2">Booking Details</p>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <div><p class="text-[10px] font-bold uppercase tracking-widest text-gray-500">Date Used</p><p class="text-sm font-bold text-gray-900">{{ formatDate(tripModal.trip.date_used) || '—' }}</p></div>
                            <div><p class="text-[10px] font-bold uppercase tracking-widest text-gray-500">Vehicle</p><p class="text-sm font-bold text-gray-900">{{ tripModal.trip.vehicle ? `${tripModal.trip.vehicle.name} (${tripModal.trip.vehicle.plate_no})` : '—' }}</p></div>
                            <div><p class="text-[10px] font-bold uppercase tracking-widest text-gray-500">Driver</p><p class="text-sm font-bold text-gray-900">{{ tripModal.trip.driver?.name || '—' }}</p></div>
                            <div><p class="text-[10px] font-bold uppercase tracking-widest text-gray-500">Purpose of Travel</p><p class="text-sm font-bold text-gray-900">{{ tripModal.trip.purpose_of_travel || '—' }}</p></div>
                            <div><p class="text-[10px] font-bold uppercase tracking-widest text-gray-500">Origin (Start)</p><p class="text-sm font-bold text-gray-900">{{ tripModal.trip.start_point || '—' }}</p></div>
                            <div><p class="text-[10px] font-bold uppercase tracking-widest text-gray-500">Final Destination (End)</p><p class="text-sm font-bold text-gray-900">{{ tripModal.trip.end_point || '—' }}</p></div>
                            <div v-if="tripModal.trip.waypoints && tripModal.trip.waypoints.length > 0" class="md:col-span-2">
                                <p class="text-[10px] font-bold uppercase tracking-widest text-gray-500">Waypoints</p>
                                <ul class="list-disc list-inside text-sm font-bold text-gray-900 mt-1">
                                    <li v-for="(wp, idx) in tripModal.trip.waypoints" :key="idx">{{ wp }}</li>
                                </ul>
                            </div>
                            <div><p class="text-[10px] font-bold uppercase tracking-widest text-gray-500">Planned Departure</p><p class="text-sm font-bold text-gray-900">{{ formatTime(tripModal.trip.planned_departure_time) || '—' }}</p></div>
                            <div><p class="text-[10px] font-bold uppercase tracking-widest text-gray-500">Planned Arrival</p><p class="text-sm font-bold text-gray-900">{{ formatTime(tripModal.trip.planned_arrival_time) || '—' }}</p></div>
                        </div>
                        <div class="mt-3">
                            <p class="text-[10px] font-bold uppercase tracking-widest text-gray-500 mb-1">Passengers</p>
                            <p class="text-sm text-gray-700 whitespace-pre-line">{{ tripModal.trip.passengers || '—' }}</p>
                        </div>
                    </div>

                    <!-- Completion Details (always visible) -->
                    <div class="border-t border-gray-100 pt-4">
                        <p class="text-[10px] font-black uppercase tracking-widest text-gray-400 mb-2">Completion Details</p>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <div><p class="text-[10px] font-bold uppercase tracking-widest text-gray-500">Actual Departure</p><p class="text-sm font-bold text-gray-900">{{ tripModal.trip.actual_departure_time ? formatTime(tripModal.trip.actual_departure_time) : '—' }}</p></div>
                            <div><p class="text-[10px] font-bold uppercase tracking-widest text-gray-500">Actual Arrival</p><p class="text-sm font-bold text-gray-900">{{ tripModal.trip.actual_arrival_time ? formatTime(tripModal.trip.actual_arrival_time) : '—' }}</p></div>
                            <div><p class="text-[10px] font-bold uppercase tracking-widest text-gray-500">Odometer Before (km)</p><p class="text-sm font-bold text-gray-900">{{ tripModal.trip.odometer_before != null ? Number(tripModal.trip.odometer_before).toLocaleString() : '—' }}</p></div>
                            <div><p class="text-[10px] font-bold uppercase tracking-widest text-gray-500">Odometer After (km)</p><p class="text-sm font-bold text-gray-900">{{ tripModal.trip.odometer_after != null ? Number(tripModal.trip.odometer_after).toLocaleString() : '—' }}</p></div>
                            <div v-if="tripModal.trip.odometer_before != null && tripModal.trip.odometer_after != null"><p class="text-[10px] font-bold uppercase tracking-widest text-gray-500">Distance Traveled</p><p class="text-sm font-bold text-gray-900">{{ (Number(tripModal.trip.odometer_after) - Number(tripModal.trip.odometer_before)).toLocaleString() }} km</p></div>
                            <div v-if="tripModal.trip.acknowledged_at"><p class="text-[10px] font-bold uppercase tracking-widest text-gray-500">Acknowledgement Signed</p><p class="text-sm font-bold text-gray-900">{{ formatDateTime(tripModal.trip.acknowledged_at) }}</p></div>
                        </div>
                        <div v-if="tripModal.trip.remarks" class="mt-3">
                            <p class="text-[10px] font-bold uppercase tracking-widest text-gray-500 mb-1">Remarks</p>
                            <p class="text-sm text-gray-700 whitespace-pre-line">{{ tripModal.trip.remarks }}</p>
                        </div>
                        <div v-if="tripModal.trip.attachments && tripModal.trip.attachments.length > 0" class="mt-3">
                            <p class="text-[10px] font-bold uppercase tracking-widest text-gray-500 mb-1">Attachments ({{ tripModal.trip.attachments.length }})</p>
                            <ul class="space-y-1">
                                <li v-for="att in tripModal.trip.attachments" :key="att.id" class="flex items-center gap-2 text-sm">
                                    <PaperClipIcon class="w-3.5 h-3.5 flex-shrink-0 text-gray-400" />
                                    <a :href="`/storage/${att.file_storage_path}`" target="_blank" rel="noopener noreferrer" class="text-blue-600 hover:underline truncate">{{ att.file_name }}</a>
                                    <span class="text-[10px] text-gray-400 whitespace-nowrap">({{ Math.round(att.file_size_bytes / 1024) }} KB)</span>
                                </li>
                            </ul>
                        </div>
                    </div>

                    <!-- Approval History -->
                    <div v-if="tripModal.trip.approved_at || tripModal.trip.rejection_reason" class="border-t border-gray-100 pt-4 space-y-2">
                        <p class="text-[10px] font-black uppercase tracking-widest text-gray-400 mb-2">Approval History</p>
                        <div v-if="tripModal.trip.approved_at && tripModal.trip.status !== 'Rejected'" class="rounded-lg border border-blue-100 bg-blue-50/50 p-3 text-xs">
                            <p class="font-bold text-blue-800">Approved by {{ tripModal.trip.approver?.name || 'system' }} on {{ formatDateTime(tripModal.trip.approved_at) }}</p>
                        </div>
                        <div v-if="tripModal.trip.rejection_reason" class="rounded-lg border border-red-100 bg-red-50/50 p-3 text-xs">
                            <p class="font-bold text-red-800 mb-0.5">Rejected by {{ tripModal.trip.approver?.name || 'system' }} on {{ formatDateTime(tripModal.trip.approved_at) }}</p>
                            <p class="text-red-700">Reason: {{ tripModal.trip.rejection_reason }}</p>
                        </div>
                    </div>

                    <div class="flex flex-wrap justify-end gap-2 pt-3 border-t border-gray-100">
                        <button @click="closeTripModal" class="px-4 py-2 text-sm font-bold text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50">Close</button>

                        <button v-if="canApprove" @click="approveTrip" class="px-4 py-2 text-sm font-bold text-white bg-emerald-600 hover:bg-emerald-700 rounded-lg">Approve</button>
                        <button v-if="canApprove" @click="openRejectModal" class="px-4 py-2 text-sm font-bold text-white bg-red-600 hover:bg-red-700 rounded-lg">Reject</button>

                        <button v-if="canEdit" @click="switchToEdit" class="px-4 py-2 text-sm font-bold text-white bg-blue-600 hover:bg-blue-700 rounded-lg">Edit</button>
                        <button v-if="canStart" @click="startTrip" class="px-4 py-2 text-sm font-bold text-white bg-emerald-600 hover:bg-emerald-700 rounded-lg">Start Trip</button>
                        <button v-if="canComplete" @click="switchToComplete" class="px-4 py-2 text-sm font-bold text-white bg-indigo-600 hover:bg-indigo-700 rounded-lg">Log Completion</button>
                        <button v-if="canCancel" @click="cancelTrip" class="px-4 py-2 text-sm font-bold text-white bg-amber-600 hover:bg-amber-700 rounded-lg">Cancel Trip</button>
                    </div>
                </div>

                <!-- COMPLETE mode -->
                <form v-else-if="tripModal.mode === 'complete' && tripModal.trip" @submit.prevent="submitCompletion" class="space-y-4">
                    <p class="text-xs text-gray-500">Fill in the actuals after returning the vehicle. Photos for accidents or defects are optional.</p>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-gray-600 mb-1">Actual Departure <span class="text-red-500">*</span></label>
                            <input type="time" v-model="completeForm.actual_departure_time" required class="w-full rounded-lg border-gray-300 text-sm focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-600 mb-1">Actual Arrival <span class="text-red-500">*</span></label>
                            <input type="time" v-model="completeForm.actual_arrival_time" required class="w-full rounded-lg border-gray-300 text-sm focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-600 mb-1">Odometer Before <span class="text-red-500">*</span></label>
                            <input type="number" v-model.number="completeForm.odometer_before" min="0" required class="w-full rounded-lg border-gray-300 text-sm focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-600 mb-1">Odometer After <span class="text-red-500">*</span></label>
                            <input type="number" v-model.number="completeForm.odometer_after" :min="completeForm.odometer_before || 0" required class="w-full rounded-lg border-gray-300 text-sm focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-xs font-bold text-gray-600 mb-1">Photos for accident reporting / defects (skip if N/A)</label>
                            <input type="file" multiple accept="image/*,.pdf" @change="onFilesChosen" class="text-xs">
                            <p class="text-[10px] text-gray-400 mt-1">Up to 10 files, 10 MB each. Selected: {{ completeForm.attachments.length }}</p>
                            <ul v-if="completeForm.attachments.length > 0" class="text-xs mt-1 space-y-0.5">
                                <li v-for="(f, i) in completeForm.attachments" :key="i" class="flex items-center justify-between gap-2 px-2 py-1 bg-gray-50 rounded">
                                    <span class="truncate">{{ f.name }} ({{ Math.round(f.size/1024) }} KB)</span>
                                    <button type="button" @click="removeFile(i)" class="text-red-500 hover:text-red-700 text-[10px] font-bold">REMOVE</button>
                                </li>
                            </ul>
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-xs font-bold text-gray-600 mb-1">Remarks</label>
                            <textarea v-model="completeForm.remarks" rows="2" class="w-full rounded-lg border-gray-300 text-sm focus:ring-blue-500 focus:border-blue-500 resize-none"></textarea>
                        </div>
                    </div>
                    <div class="rounded-lg border border-amber-200 bg-amber-50/50 p-3 text-xs text-amber-900">
                        <p class="font-bold mb-1.5">Vehicle Use Responsibility Clause and Acknowledgement</p>
                        <p>I acknowledge and agree that I am responsible for any and all damage caused to the vehicle while it is under my operation, regardless of the cause, unless such damage is proven to be due to factors beyond my control and not related to negligence or misuse. I understand that I may be held financially liable for the cost of repairs or replacement as determined necessary by management.</p>
                        <label class="mt-2 inline-flex items-start gap-2 cursor-pointer">
                            <input type="checkbox" v-model="completeForm.acknowledgement_accepted" required class="mt-0.5 h-4 w-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500">
                            <span class="font-bold">By ticking this, I confirm that I have read and accepted the responsibility outlined above. <span class="text-red-500">*</span></span>
                        </label>
                    </div>
                    <div class="flex justify-end gap-2 pt-2">
                        <button type="button" @click="tripModal.mode = 'view'" class="px-4 py-2 text-sm font-bold text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50">Back</button>
                        <button type="submit" :disabled="!completeForm.acknowledgement_accepted || isSaving" class="px-4 py-2 text-sm font-bold text-white bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 rounded-lg">Mark as Completed</button>
                    </div>
                </form>
            </div>
        </Modal>

        <!-- Reject modal -->
        <Modal :show="rejectModal.show" @close="rejectModal.show = false" max-width="md">
            <div class="p-6">
                <h3 class="text-lg font-black text-gray-900 mb-3 uppercase tracking-tight">Reject Trip</h3>
                <p class="text-sm text-gray-500 mb-3">Please provide a reason. The driver will see this.</p>
                <textarea v-model="rejectModal.reason" rows="3" class="w-full rounded-lg border-gray-300 text-sm focus:ring-blue-500 focus:border-blue-500 resize-none" placeholder="Reason for rejection..."></textarea>
                <div class="flex justify-end gap-2 mt-4">
                    <button @click="rejectModal.show = false" class="px-4 py-2 text-sm font-bold text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50">Cancel</button>
                    <button @click="confirmReject" :disabled="!rejectModal.reason.trim()" class="px-4 py-2 text-sm font-bold text-white bg-red-600 hover:bg-red-700 disabled:opacity-50 rounded-lg">Reject</button>
                </div>
            </div>
        </Modal>

        <!-- Vehicle CRUD modal -->
        <Modal :show="vehicleModal.show" @close="vehicleModal.show = false" max-width="md">
            <div class="p-6">
                <h3 class="text-lg font-black text-gray-900 mb-4 uppercase tracking-tight">{{ vehicleModal.id ? 'Edit Vehicle' : 'Add Vehicle' }}</h3>
                <form @submit.prevent="saveVehicle" class="space-y-3">
                    <div>
                        <label class="block text-xs font-bold text-gray-600 mb-1">Name <span class="text-red-500">*</span></label>
                        <input type="text" v-model="vehicleModal.form.name" required class="w-full rounded-lg border-gray-300 text-sm focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-600 mb-1">Plate No <span class="text-red-500">*</span></label>
                        <input type="text" v-model="vehicleModal.form.plate_no" required class="w-full rounded-lg border-gray-300 text-sm font-mono focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-600 mb-1">Capacity</label>
                        <input type="number" v-model.number="vehicleModal.form.capacity" min="1" class="w-full rounded-lg border-gray-300 text-sm focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-600 mb-1">Status</label>
                        <Autocomplete v-model="vehicleModal.form.status" :options="vehicleStatusOptions" />
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-600 mb-1">Notes</label>
                        <textarea v-model="vehicleModal.form.notes" rows="2" class="w-full rounded-lg border-gray-300 text-sm focus:ring-blue-500 focus:border-blue-500 resize-none"></textarea>
                    </div>
                    <div class="flex justify-end gap-2 pt-2">
                        <button type="button" @click="vehicleModal.show = false" class="px-4 py-2 text-sm font-bold text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50">Cancel</button>
                        <button type="submit" class="px-4 py-2 text-sm font-bold text-white bg-blue-600 hover:bg-blue-700 rounded-lg">{{ vehicleModal.id ? 'Save' : 'Add' }}</button>
                    </div>
                </form>
            </div>
        </Modal>
    </AppLayout>
</template>

<script setup>
import { ref, reactive, computed, watch, onMounted } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import DataTable from '@/Components/DataTable.vue'
import Modal from '@/Components/Modal.vue'
import Autocomplete from '@/Components/Autocomplete.vue'
import MultiAutocomplete from '@/Components/MultiAutocomplete.vue'
import {
    PlusIcon, EyeIcon, PencilSquareIcon, TrashIcon, ArrowRightIcon,
    ChevronLeftIcon, ChevronRightIcon, PaperClipIcon,
} from '@heroicons/vue/24/outline'
import { usePagination } from '@/Composables/usePagination'
import { useConfirm } from '@/Composables/useConfirm'
import { usePermission } from '@/Composables/usePermission'
import { useToast } from '@/Composables/useToast'
import axios from 'axios'

const props = defineProps({
    trips: Object,
    calendarTrips: Array,
    month: String,
    vehicles: Array,
    allVehicles: Array,
    drivers: Array,
    summary: Object,
    filters: Object,
})

const { hasPermission } = usePermission()
const { confirm } = useConfirm()
const { showError } = useToast()

const activeTab = ref('calendar')

const filters = reactive({
    vehicle_id: props.filters?.vehicle_id || null,
    driver_id: props.filters?.driver_id || null,
    statuses: props.filters?.statuses || [],
    search: props.filters?.search || '',
    month: props.filters?.month || props.month,
})

const pagination = usePagination(props.trips, 'service-vehicle-trips.index', () => ({
    vehicle_id: filters.vehicle_id,
    driver_id: filters.driver_id,
    statuses: filters.statuses,
    month: filters.month,
}))

watch(() => props.trips, (val) => pagination.updateData(val))
watch(() => [filters.vehicle_id, filters.driver_id, filters.statuses, filters.month], () => {
    pagination.currentPage.value = 1
    pagination.performSearch()
}, { deep: true })

const resetFilters = () => {
    filters.vehicle_id = null
    filters.driver_id = null
    filters.statuses = []
    filters.search = ''
    pagination.search.value = ''
}

// --- Options ---
const statusOptions = [
    { value: 'Pending Approval', label: 'Pending Approval' },
    { value: 'Scheduled', label: 'Scheduled' },
    { value: 'In Progress', label: 'In Progress' },
    { value: 'Completed', label: 'Completed' },
    { value: 'Rejected', label: 'Rejected' },
    { value: 'Cancelled', label: 'Cancelled' },
]

const vehicleStatusOptions = computed(() => [
    { label: 'Active', value: 'active' },
    { label: 'Maintenance', value: 'maintenance' },
    { label: 'Retired', value: 'retired' },
])

const vehicleOptions = computed(() => (props.vehicles || []).map(v => ({
    label: `${v.name} (${v.plate_no})`,
    value: v.id,
})))

const vehicleFilterOptions = computed(() => [{ label: 'All vehicles', value: null }, ...vehicleOptions.value])
const driverOptions = computed(() => (props.drivers || []).map(d => ({ label: d.name, value: d.id })))
const driverFilterOptions = computed(() => [{ label: 'All drivers', value: null }, ...driverOptions.value])

// --- Calendar ---
const currentMonth = ref(props.month) // YYYY-MM

const monthLabel = computed(() => {
    const [y, m] = currentMonth.value.split('-').map(Number)
    return new Date(y, m - 1, 1).toLocaleString('en-US', { month: 'long', year: 'numeric' })
})

const calendarCells = computed(() => {
    const [y, m] = currentMonth.value.split('-').map(Number)
    const firstOfMonth = new Date(y, m - 1, 1)
    const gridStart = new Date(firstOfMonth)
    gridStart.setDate(gridStart.getDate() - firstOfMonth.getDay())

    const today = new Date()
    today.setHours(0, 0, 0, 0)

    const tripsByDate = {}
    for (const t of (props.calendarTrips || [])) {
        const key = String(t.date_used).slice(0, 10)
        if (!tripsByDate[key]) tripsByDate[key] = []
        tripsByDate[key].push(t)
    }

    const cells = []
    for (let i = 0; i < 42; i++) {
        const d = new Date(gridStart)
        d.setDate(gridStart.getDate() + i)
        const yy = d.getFullYear()
        const mm = String(d.getMonth() + 1).padStart(2, '0')
        const dd = String(d.getDate()).padStart(2, '0')
        const key = `${yy}-${mm}-${dd}`
        cells.push({
            key,
            day: d.getDate(),
            inCurrentMonth: d.getMonth() === m - 1,
            isToday: d.getTime() === today.getTime(),
            trips: (tripsByDate[key] || []).sort((a, b) => (a.planned_departure_time || '').localeCompare(b.planned_departure_time || '')),
        })
    }
    return cells
})

const navigateMonth = (delta) => {
    const [y, m] = currentMonth.value.split('-').map(Number)
    const d = new Date(y, m - 1 + delta, 1)
    const newMonth = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`
    currentMonth.value = newMonth
    filters.month = newMonth
    router.get(route('service-vehicle-trips.index'), { ...flatFilters(), month: newMonth }, { preserveState: true, preserveScroll: true, only: ['calendarTrips', 'month', 'summary'] })
}

const goToCurrentMonth = () => {
    const now = new Date()
    const newMonth = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`
    if (newMonth !== currentMonth.value) navigateMonth((now.getFullYear() - Number(currentMonth.value.split('-')[0])) * 12 + (now.getMonth() + 1 - Number(currentMonth.value.split('-')[1])))
}

const flatFilters = () => ({
    vehicle_id: filters.vehicle_id,
    driver_id: filters.driver_id,
    statuses: filters.statuses,
    search: filters.search,
})

// --- Color helpers ---
const chipClass = (status) => ({
    'bg-amber-100 text-amber-800 border border-amber-200':       status === 'Pending Approval',
    'bg-blue-100 text-blue-800 border border-blue-200':          status === 'Scheduled',
    'bg-emerald-100 text-emerald-800 border border-emerald-200': status === 'In Progress',
    'bg-slate-100 text-slate-700 border border-slate-200':       status === 'Completed',
    'bg-red-50 text-red-700 border border-red-200':              status === 'Rejected',
    'bg-gray-50 text-gray-500 border border-gray-200':           status === 'Cancelled',
})

const legendDotClass = (status) => ({
    'bg-amber-300':   status === 'Pending Approval',
    'bg-blue-300':    status === 'Scheduled',
    'bg-emerald-300': status === 'In Progress',
    'bg-slate-300':   status === 'Completed',
    'bg-red-300':     status === 'Rejected',
    'bg-gray-300':    status === 'Cancelled',
})

const vehicleStatusClass = (status) => ({
    'bg-emerald-100 text-emerald-800': status === 'active',
    'bg-amber-100 text-amber-800':     status === 'maintenance',
    'bg-gray-100 text-gray-600':       status === 'retired',
})

const tabClass = (tab) => [
    'px-4 py-2 text-sm font-bold rounded-lg transition-colors',
    activeTab.value === tab ? 'bg-blue-600 text-white shadow-sm' : 'text-gray-600 hover:bg-gray-50',
]

// --- Trip modal state ---
const tripModal = reactive({
    show: false,
    mode: 'book', // book | view | edit | complete
    trip: null,
})

const bookForm = reactive({
    service_vehicle_id: null,
    driver_id: null,
    date_used: new Date().toISOString().slice(0, 10),
    purpose_of_travel: '',
    passengers: '',
    start_point: '',
    end_point: '',
    waypoints: [],
    planned_departure_time: '',
    planned_arrival_time: '',
    remarks: '',
})

const completeForm = reactive({
    actual_departure_time: '',
    actual_arrival_time: '',
    odometer_before: null,
    odometer_after: null,
    remarks: '',
    acknowledgement_accepted: false,
    attachments: [],
})

const conflict = ref(null)
const isSaving = ref(false)

const resetBookForm = () => {
    Object.assign(bookForm, {
        service_vehicle_id: null,
        driver_id: null,
        date_used: new Date().toISOString().slice(0, 10),
        purpose_of_travel: '',
        passengers: '',
        start_point: '',
        end_point: '',
        waypoints: [],
        planned_departure_time: '',
        planned_arrival_time: '',
        remarks: '',
    })
    conflict.value = null
}

const openBookModal = (date = null) => {
    resetBookForm()
    if (typeof date === 'string') {
        bookForm.date_used = date
    }
    tripModal.trip = null
    tripModal.mode = 'book'
    tripModal.show = true
}

const openViewModal = async (trip) => {
    tripModal.mode = 'view'
    tripModal.trip = trip  // show immediately with data already in props
    tripModal.show = true
    try {
        const res = await axios.get(route('service-vehicle-trips.show', trip.id))
        tripModal.trip = res.data  // upgrade with full relations (attachments, uploader names)
    } catch {
        // keep the prop data we already showed
    }
}

const switchToEdit = () => {
    const t = tripModal.trip
    Object.assign(bookForm, {
        service_vehicle_id: t.service_vehicle_id,
        driver_id: t.driver_id,
        date_used: String(t.date_used).slice(0, 10),
        purpose_of_travel: t.purpose_of_travel,
        passengers: t.passengers || '',
        start_point: t.start_point,
        end_point: t.end_point,
        waypoints: t.waypoints || [],
        planned_departure_time: (t.planned_departure_time || '').slice(0, 5),
        planned_arrival_time: (t.planned_arrival_time || '').slice(0, 5),
        remarks: t.remarks || '',
    })
    tripModal.mode = 'edit'
}

const switchToComplete = () => {
    const t = tripModal.trip
    Object.assign(completeForm, {
        actual_departure_time: (t.actual_departure_time || t.planned_departure_time || '').slice(0, 5),
        actual_arrival_time: (t.actual_arrival_time || t.planned_arrival_time || '').slice(0, 5),
        odometer_before: t.odometer_before || null,
        odometer_after: t.odometer_after || null,
        remarks: t.remarks || '',
        acknowledgement_accepted: false,
        attachments: [],
    })
    tripModal.mode = 'complete'
}

const closeTripModal = () => {
    tripModal.show = false
    tripModal.trip = null
    conflict.value = null
}

// --- Conflict preflight ---
const preflightConflict = async () => {
    if (!bookForm.service_vehicle_id || !bookForm.date_used || !bookForm.planned_departure_time || !bookForm.planned_arrival_time) {
        conflict.value = null
        return
    }
    if (bookForm.planned_arrival_time <= bookForm.planned_departure_time) {
        conflict.value = null
        return
    }
    try {
        const res = await axios.get(route('service-vehicle-trips.conflict-check'), {
            params: {
                service_vehicle_id: bookForm.service_vehicle_id,
                date_used: bookForm.date_used,
                planned_departure_time: bookForm.planned_departure_time,
                planned_arrival_time: bookForm.planned_arrival_time,
                exclude_trip_id: tripModal.mode === 'edit' ? tripModal.trip?.id : null,
            },
        })
        conflict.value = res.data.conflict
    } catch {
        conflict.value = null
    }
}

watch(() => [bookForm.service_vehicle_id, bookForm.date_used, bookForm.planned_departure_time, bookForm.planned_arrival_time], () => {
    preflightConflict()
})

// --- Save / actions ---
const saveBooking = () => {
    if (conflict.value) return
    if (bookForm.planned_arrival_time <= bookForm.planned_departure_time) {
        showError('Planned arrival must be after planned departure.')
        return
    }
    isSaving.value = true
    if (tripModal.mode === 'edit' && tripModal.trip) {
        router.put(route('service-vehicle-trips.update', tripModal.trip.id), { ...bookForm }, {
            onSuccess: () => closeTripModal(),
            onError: (errors) => showError(Object.values(errors)[0]),
            onFinish: () => { isSaving.value = false },
        })
    } else {
        router.post(route('service-vehicle-trips.store'), { ...bookForm }, {
            onSuccess: () => closeTripModal(),
            onError: (errors) => showError(Object.values(errors)[0]),
            onFinish: () => { isSaving.value = false },
        })
    }
}

const approveTrip = async () => {
    const ok = await confirm({ title: 'Approve trip?', message: 'This will move it to Scheduled.' })
    if (!ok) return
    router.patch(route('service-vehicle-trips.approve', tripModal.trip.id), {}, {
        onSuccess: () => closeTripModal(),
        onError: (errors) => showError(Object.values(errors)[0]),
    })
}

const rejectModal = reactive({ show: false, reason: '' })
const openRejectModal = () => { rejectModal.reason = ''; rejectModal.show = true }
const confirmReject = () => {
    if (!rejectModal.reason.trim()) return
    router.patch(route('service-vehicle-trips.reject', tripModal.trip.id), { rejection_reason: rejectModal.reason }, {
        onSuccess: () => { rejectModal.show = false; closeTripModal() },
        onError: (errors) => showError(Object.values(errors)[0]),
    })
}

const startTrip = async () => {
    const ok = await confirm({ title: 'Start trip?', message: 'This will mark the trip as In Progress and record the current time as actual departure.' })
    if (!ok) return
    router.patch(route('service-vehicle-trips.start', tripModal.trip.id), {}, {
        onSuccess: () => closeTripModal(),
        onError: (errors) => showError(Object.values(errors)[0]),
    })
}

const cancelTrip = async () => {
    const ok = await confirm({ title: 'Cancel this trip?', message: 'The trip will be marked as Cancelled.', confirmText: 'Yes, cancel' })
    if (!ok) return
    router.patch(route('service-vehicle-trips.cancel', tripModal.trip.id), {}, {
        onSuccess: () => closeTripModal(),
        onError: (errors) => showError(Object.values(errors)[0]),
    })
}

const onFilesChosen = (e) => {
    const incoming = Array.from(e.target.files || [])
    const remaining = 10 - completeForm.attachments.length
    if (remaining <= 0) { showError('Maximum 10 files allowed.'); return }
    for (const f of incoming.slice(0, remaining)) {
        if (f.size > 10 * 1024 * 1024) { showError(`${f.name} exceeds 10 MB.`); continue }
        completeForm.attachments.push(f)
    }
    e.target.value = ''
}

const removeFile = (i) => completeForm.attachments.splice(i, 1)

const submitCompletion = () => {
    if (!completeForm.acknowledgement_accepted) { showError('Please accept the responsibility clause.'); return }
    if (completeForm.actual_arrival_time <= completeForm.actual_departure_time) { showError('Actual arrival must be after actual departure.'); return }
    if (completeForm.odometer_after < completeForm.odometer_before) { showError('Odometer After must be >= Odometer Before.'); return }

    const fd = new FormData()
    fd.append('_method', 'PATCH')
    fd.append('actual_departure_time', completeForm.actual_departure_time)
    fd.append('actual_arrival_time', completeForm.actual_arrival_time)
    fd.append('odometer_before', completeForm.odometer_before)
    fd.append('odometer_after', completeForm.odometer_after)
    fd.append('remarks', completeForm.remarks || '')
    fd.append('acknowledgement_accepted', '1')
    for (const f of completeForm.attachments) fd.append('attachments[]', f)

    isSaving.value = true
    router.post(route('service-vehicle-trips.complete', tripModal.trip.id), fd, {
        forceFormData: true,
        onSuccess: () => closeTripModal(),
        onError: (errors) => showError(Object.values(errors)[0]),
        onFinish: () => { isSaving.value = false },
    })
}

// --- Vehicle CRUD ---
const vehicleModal = reactive({
    show: false,
    id: null,
    form: { name: '', plate_no: '', capacity: null, status: 'active', notes: '' },
})

const openVehicleModal = (v = null) => {
    if (v) {
        vehicleModal.id = v.id
        Object.assign(vehicleModal.form, { name: v.name, plate_no: v.plate_no, capacity: v.capacity, status: v.status, notes: v.notes || '' })
    } else {
        vehicleModal.id = null
        Object.assign(vehicleModal.form, { name: '', plate_no: '', capacity: null, status: 'active', notes: '' })
    }
    vehicleModal.show = true
}

const saveVehicle = () => {
    const url = vehicleModal.id
        ? route('service-vehicles.update', vehicleModal.id)
        : route('service-vehicles.store')
    const method = vehicleModal.id ? 'put' : 'post'
    router[method](url, { ...vehicleModal.form }, {
        onSuccess: () => { vehicleModal.show = false },
        onError: (errors) => showError(Object.values(errors)[0]),
    })
}

const deleteVehicle = async (v) => {
    const ok = await confirm({ title: 'Delete vehicle?', message: `Remove ${v.name} (${v.plate_no})?`, confirmText: 'Delete' })
    if (!ok) return
    router.delete(route('service-vehicles.destroy', v.id), {
        onError: (errors) => showError(Object.values(errors)[0]),
    })
}

// --- Permissions for action visibility ---
const canApprove = computed(() => hasPermission('service_vehicle_trips.approve') && tripModal.trip?.status === 'Pending Approval')
const canEdit    = computed(() => hasPermission('service_vehicle_trips.edit') && ['Pending Approval', 'Scheduled'].includes(tripModal.trip?.status))
const canStart   = computed(() => hasPermission('service_vehicle_trips.edit') && tripModal.trip?.status === 'Scheduled')
const canComplete= computed(() => hasPermission('service_vehicle_trips.edit') && ['Scheduled', 'In Progress'].includes(tripModal.trip?.status))
const canCancel  = computed(() => hasPermission('service_vehicle_trips.edit') && ['Pending Approval', 'Scheduled'].includes(tripModal.trip?.status))

const modalTitle = computed(() => {
    if (tripModal.mode === 'book') return 'Book Vehicle Trip'
    if (tripModal.mode === 'edit') return 'Edit Trip'
    if (tripModal.mode === 'complete') return 'Log Trip Completion'
    return 'Trip Details'
})

// --- Formatting helpers ---
const formatDate = (val) => {
    if (!val) return '—'
    return new Date(String(val).slice(0, 10) + 'T00:00:00').toLocaleDateString('en-US', { month: 'short', day: '2-digit', year: 'numeric' })
}
const formatTime = (val) => {
    if (!val) return '—'
    const [h, m] = String(val).split(':')
    const hh = Number(h)
    const period = hh >= 12 ? 'PM' : 'AM'
    const h12 = hh % 12 || 12
    return `${h12}:${m} ${period}`
}
const formatDateTime = (val) => {
    if (!val) return '—'
    return new Date(val).toLocaleString('en-US', { month: 'short', day: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', hour12: true })
}

onMounted(() => {
    pagination.updateData(props.trips)
})

</script>
