<template>
    <AppLayout title="Scheduling">
        <div class="py-12">
            <div class="max-w-[1600px] mx-auto sm:px-6 lg:px-8">
                
                <!-- View Toggle & Actions Header -->
                <div class="flex flex-col md:flex-row justify-between items-center mb-6 bg-white p-4 rounded-xl shadow-sm border border-gray-100">
                    <div class="flex bg-gray-100 p-1 rounded-lg">
                        <button 
                            @click="currentView = 'calendar'" 
                            :class="['px-4 py-2 text-sm font-bold rounded-md transition-all', currentView === 'calendar' ? 'bg-white text-blue-600 shadow-sm' : 'text-gray-500 hover:text-gray-700']"
                        >
                            Calendar View
                        </button>
                        <button 
                            @click="currentView = 'report'" 
                            :class="['px-4 py-2 text-sm font-bold rounded-md transition-all', currentView === 'report' ? 'bg-white text-blue-600 shadow-sm' : 'text-gray-500 hover:text-gray-700']"
                        >
                            Report View
                        </button>
                    </div>

                    <div class="flex items-center space-x-3 mt-4 md:mt-0">
                        <!-- Year Multi-select for Report View -->
                        <div v-if="currentView === 'report'" class="flex items-center space-x-2">
                            <span class="text-xs font-black text-gray-400 uppercase tracking-widest">Compare Years:</span>
                            <div class="flex flex-wrap gap-1 bg-gray-100 p-1 rounded-lg border border-gray-200">
                                <button 
                                    v-for="year in availableYears" 
                                    :key="year"
                                    @click="toggleYear(year)"
                                    :class="[
                                        'px-3 py-1 text-[10px] font-black rounded-md transition-all border',
                                        selectedReportYears.includes(year)
                                            ? 'bg-blue-600 text-white border-blue-700 shadow-sm'
                                            : 'bg-white text-gray-500 border-gray-200 hover:bg-gray-50'
                                    ]"
                                >
                                    {{ year }}
                                </button>
                            </div>
                        </div>

                        <div class="w-48 md:w-64" v-if="currentView === 'calendar'">
                            <Autocomplete 
                                v-model="filterUser"
                                :options="userFilterOptions"
                                label-key="name"
                                value-key="id"
                                placeholder="Filter by user..."
                                @update:modelValue="applyFilter"
                            />
                        </div>
                        <button 
                            v-if="currentView === 'calendar'"
                            @click="exportPdf"
                            class="bg-white border border-gray-300 text-gray-700 px-4 py-2 rounded-lg text-sm font-medium hover:bg-gray-50 transition-colors flex items-center space-x-2 shadow-sm"
                        >
                            <svg class="w-4 h-4 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                            </svg>
                            <span>Export PDF</span>
                        </button>
                        <button
                            v-if="hasPermission('schedules.create')"
                            @click="openImportModal"
                            class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors flex items-center space-x-2"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
                            </svg>
                            <span>Import</span>
                        </button>
                        <button
                            v-if="hasPermission('schedules.create')"
                            @click="openCreateModal"
                            class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors flex items-center space-x-2"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                            </svg>
                            <span>Create Schedule</span>
                        </button>
                    </div>
                </div>

                <!-- Calendar View -->
                <div v-if="currentView === 'calendar'">
                    <Calendar 
                        :events="schedules" 
                        @date-click="handleDateClick"
                        @event-click="handleEventClick"
                    />
                </div>

                <!-- Pivot Report View -->
                <div v-else-if="currentView === 'report'" class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-200 bg-gray-50 flex justify-between items-center">
                        <h3 class="text-lg font-bold text-gray-900">3-Year Schedule Comparison</h3>
                        <span class="text-xs font-black text-gray-500 uppercase tracking-widest">Live Report</span>
                    </div>
                    <div class="overflow-x-auto custom-scrollbar">
                        <table class="min-w-full divide-y divide-gray-200 border-b border-gray-200">
                            <thead class="bg-gray-100">
                                <!-- Year Headers -->
                                <tr>
                                    <th rowspan="2" class="px-4 py-3 text-left text-[10px] font-black text-gray-500 uppercase tracking-widest border-r border-gray-200 bg-gray-50 z-10 sticky left-0 min-w-[100px]">Unit</th>
                                    <th rowspan="2" class="px-4 py-3 text-left text-[10px] font-black text-gray-500 uppercase tracking-widest border-r border-gray-200 bg-gray-50 z-10 sticky left-[100px] min-w-[150px]">Name</th>
                                    <th v-for="year in pivotYears" :key="'header-' + year" :colspan="pivotStatuses.length" class="px-4 py-2 text-center text-xs font-black text-white bg-slate-700 uppercase tracking-widest border-r border-slate-600 last:border-r-0">
                                        {{ year }}
                                    </th>
                                </tr>
                                <!-- Status Headers -->
                                <tr>
                                    <template v-for="year in pivotYears" :key="'status-' + year">
                                        <th v-for="status in pivotStatuses" :key="year + '-' + status" class="px-2 py-2 text-center text-[9px] font-black text-gray-500 uppercase tracking-tighter border-r border-gray-200 border-t last:border-r-0" :class="status === 'Holiday' ? 'bg-red-50/50' : (status === 'Restday' ? 'bg-gray-50/50' : 'bg-white')">
                                            {{ status === 'On-site' ? 'On-site' : (status === 'Off-site' ? 'Off-site' : (status === 'Restday' ? 'RD' : status)) }}
                                        </th>
                                    </template>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <tr v-for="row in pivotData" :key="row.name + row.unit" class="hover:bg-blue-50/50 transition-colors">
                                    <td class="px-4 py-2 whitespace-nowrap text-xs font-bold text-gray-500 bg-white border-r border-gray-100 sticky left-0 z-10">{{ row.unit || '-' }}</td>
                                    <td class="px-4 py-2 whitespace-nowrap text-sm font-bold text-gray-900 bg-white border-r border-gray-200 sticky left-[100px] z-10">{{ row.name }}</td>
                                    
                                    <template v-for="year in pivotYears" :key="'data-' + year">
                                        <td v-for="status in pivotStatuses" :key="row.name + year + status" class="px-2 py-2 whitespace-nowrap text-center text-xs border-r border-gray-100 last:border-r-0" :class="[
                                            (row.years[year] && row.years[year][status] > 0) ? 'font-black text-blue-700' : 'font-medium text-gray-300',
                                            status === 'Holiday' ? 'bg-red-50/30' : (status === 'Restday' ? 'bg-gray-50/30' : '')
                                        ]">
                                            {{ (row.years[year] && row.years[year][status] > 0) ? row.years[year][status] : '-' }}
                                        </td>
                                    </template>
                                </tr>
                                <tr v-if="!pivotData || pivotData.length === 0">
                                    <td :colspan="2 + (pivotYears.length * pivotStatuses.length)" class="px-6 py-12 text-center text-sm text-gray-500 italic">
                                        No schedule data found for the reporting period.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Import Modal -->
        <div v-if="showImportModal" class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex items-center justify-center min-h-screen px-4 py-6">
                <div class="fixed inset-0 bg-black/20 backdrop-blur-md" @click="closeImportModal"></div>
                <div class="relative bg-white rounded-xl shadow-2xl w-full max-w-lg p-6 border border-gray-100 transform transition-all">
                    <div class="flex justify-between items-center mb-6">
                        <h3 class="text-xl font-bold text-gray-900">Import Schedules</h3>
                        <button @click="closeImportModal" class="text-gray-400 hover:text-gray-600 transition-colors">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                    <div class="space-y-4">
                        <p class="text-sm text-gray-600">
                            Import schedules in bulk using an Excel file. Columns:
                            <span class="font-semibold">user_email</span>,
                            <span class="font-semibold">store_code</span> (optional),
                            <span class="font-semibold">status</span> (dropdown),
                            <span class="font-semibold">start_time</span>,
                            <span class="font-semibold">end_time</span>,
                            <span class="font-semibold">pickup_start</span>,
                            <span class="font-semibold">pickup_end</span>,
                            <span class="font-semibold">backlogs_start</span>,
                            <span class="font-semibold">backlogs_end</span>,
                            <span class="font-semibold">remarks</span>.
                        </p>

                        <!-- Template Download -->
                        <div class="rounded-lg border border-dashed border-gray-300 bg-gray-50 p-4">
                            <a href="/schedules/template" class="flex items-center space-x-3 text-blue-600 hover:text-blue-800 transition-colors group">
                                <div class="h-10 w-10 bg-blue-100 group-hover:bg-blue-200 rounded-lg flex items-center justify-center flex-shrink-0 transition-colors">
                                    <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                    </svg>
                                </div>
                                <div>
                                    <div class="text-sm font-semibold">Download Excel Template</div>
                                    <div class="text-xs text-gray-500">schedules-import-template.xlsx</div>
                                </div>
                            </a>
                        </div>

                        <!-- Divider -->
                        <div class="flex items-center space-x-3">
                            <div class="flex-1 border-t border-gray-200"></div>
                            <span class="text-xs text-gray-400 font-medium uppercase tracking-wider">Then Upload</span>
                            <div class="flex-1 border-t border-gray-200"></div>
                        </div>

                        <!-- File Upload -->
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Upload Excel File</label>
                            <div v-if="!importFile"
                                 @click="importFileInput.click()"
                                 class="rounded-lg border-2 border-dashed border-gray-300 bg-gray-50 hover:bg-gray-100 hover:border-gray-400 p-6 text-center cursor-pointer transition-colors">
                                <svg class="w-8 h-8 mx-auto text-gray-400 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
                                </svg>
                                <p class="text-sm text-gray-600 font-medium">Click to choose an Excel file</p>
                                <p class="text-xs text-gray-400 mt-1">XLSX only, max 5MB</p>
                            </div>
                            <div v-else class="flex items-center justify-between p-3 bg-green-50 border border-green-200 rounded-lg">
                                <div class="flex items-center space-x-2 min-w-0">
                                    <svg class="w-5 h-5 text-green-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                    </svg>
                                    <span class="text-sm font-medium text-green-800 truncate">{{ importFile.name }}</span>
                                </div>
                                <button @click="removeImportFile" type="button" class="text-gray-400 hover:text-red-500 transition-colors ml-2 flex-shrink-0">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </div>
                            <input ref="importFileInput" type="file" accept=".xlsx" class="hidden" @change="handleImportFileSelect">
                        </div>

                        <!-- Import Result -->
                        <div v-if="importResult" class="rounded-lg p-4" :class="(importResult.errors?.length || 0) === 0 ? 'bg-green-50 border border-green-200' : 'bg-yellow-50 border border-yellow-200'">
                            <div class="flex items-center space-x-2 mb-1">
                                <svg v-if="(importResult.errors?.length || 0) === 0" class="w-4 h-4 text-green-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                </svg>
                                <svg v-else class="w-4 h-4 text-yellow-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                </svg>
                                <span class="text-sm font-semibold" :class="(importResult.errors?.length || 0) === 0 ? 'text-green-800' : 'text-yellow-800'">
                                    {{ importResult.imported }} schedule{{ importResult.imported !== 1 ? 's' : '' }} imported
                                </span>
                            </div>
                            <ul v-if="importResult.errors?.length > 0" class="space-y-0.5 max-h-28 overflow-y-auto mt-2">
                                <li v-for="(error, i) in importResult.errors" :key="i" class="text-xs text-red-700">{{ error }}</li>
                            </ul>
                        </div>
                    </div>

                    <div class="flex justify-between pt-6 border-t mt-6">
                        <button type="button" @click="closeImportModal"
                                class="px-4 py-2 text-sm font-semibold text-gray-600 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors">
                            Close
                        </button>
                        <button type="button" @click="submitImport" :disabled="!importFile || isImporting"
                                class="px-6 py-2 bg-green-600 text-white text-sm font-bold rounded-lg hover:bg-green-700 shadow-md transition-all disabled:opacity-50 disabled:cursor-not-allowed flex items-center space-x-2">
                            <svg v-if="isImporting" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 22 6.477 22 12h-4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            <span>{{ isImporting ? 'Importing...' : 'Import' }}</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Create/Edit Modal -->
        <div v-if="showModal" class="fixed inset-0 bg-black/20 backdrop-blur-md overflow-y-auto h-full w-full z-50">
            <div class="relative top-10 mx-auto p-5 border w-11/12 md:w-3/4 lg:w-1/2 shadow-lg rounded-md bg-white">
                <div class="mt-3">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-lg font-bold text-gray-900">
                            {{ isViewingOnly ? 'View Schedule' : (isEditing ? 'Edit Schedule' : 'New Schedule') }}
                        </h3>
                        <button @click="closeModal" class="text-gray-400 hover:text-gray-600 transition-colors">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <form @submit.prevent="submitForm" class="space-y-4">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">User</label>
                                <Autocomplete 
                                    v-model="form.user_id"
                                    :options="users"
                                    label-key="name"
                                    value-key="id"
                                    placeholder="Select user..."
                                    :disabled="isViewingOnly"
                                />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                                <select v-model="form.status" required :disabled="isViewingOnly"
                                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                                    <option v-for="status in statuses" :key="status" :value="status">{{ status }}</option>
                                </select>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Store (Optional)</label>
                                <Autocomplete 
                                    v-model="form.store_id"
                                    :options="stores"
                                    label-key="name"
                                    value-key="id"
                                    placeholder="Select store..."
                                    :disabled="isViewingOnly"
                                />
                            </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Off-site Remarks / Other Activities</label>
                            <textarea v-model="form.remarks" rows="3" :disabled="isViewingOnly"
                                      class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm"
                                      placeholder="Provide details about the off-site activity or other remarks..."></textarea>
                        </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Start Date & Time</label>
                                <input v-model="form.start_time" type="datetime-local" required :disabled="isViewingOnly"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">End Date & Time</label>
                                <input v-model="form.end_time" type="datetime-local" required :disabled="isViewingOnly"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                            </div>
                        </div>

                        <div v-if="form.status === 'On-site' || form.status === 'WFH'" class="p-4 bg-gray-50 rounded-xl space-y-4 border border-gray-100">
                            <h4 class="text-xs font-bold text-gray-500 uppercase tracking-wider">Additional Times</h4>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div class="space-y-2">
                                    <label class="block text-xs font-medium text-gray-600">Pickup Time (From - To)</label>
                                    <div class="flex items-center space-x-2">
                                        <input v-model="form.pickup_start" type="time" :disabled="isViewingOnly" class="flex-1 px-3 py-1.5 border border-gray-300 rounded-lg text-sm">
                                        <span class="text-gray-400">-</span>
                                        <input v-model="form.pickup_end" type="time" :disabled="isViewingOnly" class="flex-1 px-3 py-1.5 border border-gray-300 rounded-lg text-sm">
                                    </div>
                                </div>
                                <div class="space-y-2">
                                    <label class="block text-xs font-medium text-gray-600">Backlogs Time (From - To)</label>
                                    <div class="flex items-center space-x-2">
                                        <input v-model="form.backlogs_start" type="time" :disabled="isViewingOnly" class="flex-1 px-3 py-1.5 border border-gray-300 rounded-lg text-sm">
                                        <span class="text-gray-400">-</span>
                                        <input v-model="form.backlogs_end" type="time" :disabled="isViewingOnly" class="flex-1 px-3 py-1.5 border border-gray-300 rounded-lg text-sm">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="flex justify-end items-center pt-4 border-t">
                            <div class="flex space-x-3">
                                <button type="button" @click="closeModal" 
                                        class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200">
                                    {{ isViewingOnly ? 'Close' : 'Cancel' }}
                                </button>
                                <button v-if="!isViewingOnly" type="submit" 
                                        class="px-6 py-2 text-sm font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700 shadow-md">
                                    {{ isEditing ? 'Save Changes' : 'Create Schedule' }}
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref, reactive, onMounted, computed, watch } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Calendar from '@/Components/Calendar.vue'
import Autocomplete from '@/Components/Autocomplete.vue'
import { useToast } from '@/Composables/useToast'
import { useConfirm } from '@/Composables/useConfirm'
import { useErrorHandler } from '@/Composables/useErrorHandler'
import { usePermission } from '@/Composables/usePermission'

const props = defineProps({
    schedules: Array,
    users: Array,
    stores: Array,
    pivotData: Array,
    pivotYears: Array,
    availableYears: Array,
    pivotStatuses: Array,
    filters: Object
})

const page = usePage()
const filterUser = ref(props.filters?.user_id || '')
const selectedReportYears = ref(props.filters?.report_years ? (Array.isArray(props.filters.report_years) ? props.filters.report_years.map(Number) : [Number(props.filters.report_years)]) : [...props.pivotYears])
const currentView = ref('calendar') // 'calendar' or 'report'

const userFilterOptions = computed(() => {
    const currentUserId = page.props.auth.user.id
    const options = [
        { id: '', name: 'All Users' },
        { id: 'my', name: 'My Schedules' }
    ]
    
    props.users.forEach(user => {
        if (user.id !== currentUserId) {
            options.push({ id: user.id, name: user.name })
        }
    })
    
    return options
})

const applyFilter = () => {
    router.get(route('schedules.index'), {
        user_id: filterUser.value,
        report_years: selectedReportYears.value
    }, {
        preserveState: true,
        preserveScroll: true
    })
}

// Watch for year selection changes to auto-apply filter in report view
watch(selectedReportYears, () => {
    if (currentView.value === 'report') {
        applyFilter()
    }
}, { deep: true })

const toggleYear = (year) => {
    const index = selectedReportYears.value.indexOf(year)
    if (index === -1) {
        selectedReportYears.value.push(year)
    } else {
        if (selectedReportYears.value.length > 1) {
            selectedReportYears.value.splice(index, 1)
        } else {
            showError('At least one year must be selected.')
        }
    }
}

const { showSuccess, showError } = useToast()
const { confirm } = useConfirm()
const { post, put, destroy } = useErrorHandler()
const { hasPermission } = usePermission()

const exportPdf = () => {
    // Get first and last day of current visible month from the calendar state if possible, 
    // or just default to current month for now.
    const now = new Date();
    const start = new Date(now.getFullYear(), now.getMonth(), 1).toISOString().split('T')[0];
    const end = new Date(now.getFullYear(), now.getMonth() + 1, 0).toISOString().split('T')[0];
    
    window.open(route('schedules.export.pdf', { start, end }), '_blank');
};

// ── Import ──────────────────────────────────────────────────────────────
const showImportModal = ref(false)
const importFile = ref(null)
const importFileInput = ref(null)
const isImporting = ref(false)
const importResult = ref(null)

const openImportModal = () => { showImportModal.value = true }

const closeImportModal = () => {
    showImportModal.value = false
    importFile.value = null
    importResult.value = null
    if (importFileInput.value) importFileInput.value.value = ''
}

const handleImportFileSelect = (event) => {
    importFile.value = event.target.files[0] || null
    importResult.value = null
}

const removeImportFile = () => {
    importFile.value = null
    importResult.value = null
    if (importFileInput.value) importFileInput.value.value = ''
}

const submitImport = async () => {
    if (!importFile.value || isImporting.value) return
    isImporting.value = true
    importResult.value = null

    const formData = new FormData()
    formData.append('file', importFile.value)

    try {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
        const response = await fetch(route('schedules.import'), {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
            body: formData
        })
        
        const result = await response.json()
        
        if (response.ok) {
            importResult.value = result
            if (result.imported > 0) {
                showSuccess(`${result.imported} schedule${result.imported === 1 ? '' : 's'} imported successfully`)
                router.reload({ only: ['schedules'] })
            }
        } else {
            showError(result.message || 'Import failed. Please check your session and try again.')
        }
    } catch (e) {
        showError('Import failed. Please try again.')
    } finally {
        isImporting.value = false
    }
}

// ── Create / Edit ────────────────────────────────────────────────────────
const showModal = ref(false)
const isEditing = ref(false)
const isViewingOnly = ref(false)
const currentScheduleId = ref(null)

const statuses = [
    'On-site', 'Off-site', 'WFH', 'SL', 'VL', 'Restday', 'Offset', 'Holiday'
]

const form = reactive({
    user_id: null,
    store_id: null,
    status: 'On-site',
    start_time: '',
    end_time: '',
    pickup_start: '',
    pickup_end: '',
    backlogs_start: '',
    backlogs_end: '',
    remarks: ''
})

const formatDateForInput = (date) => {
    const d = new Date(date);
    d.setMinutes(d.getMinutes() - d.getTimezoneOffset());
    return d.toISOString().slice(0, 16);
}

const openCreateModal = () => {
    isEditing.value = false
    isViewingOnly.value = false
    currentScheduleId.value = null
    Object.keys(form).forEach(key => {
        if (key === 'status') form[key] = 'On-site'
        else if (key === 'user_id' || key === 'store_id') form[key] = null
        else form[key] = ''
    })
    
    // Set default times
    const now = new Date();
    form.start_time = formatDateForInput(now);
    const end = new Date();
    end.setHours(end.getHours() + 8);
    form.end_time = formatDateForInput(end);
    
    showModal.value = true
}

const handleDateClick = (date) => {
    if (!hasPermission('schedules.create')) return
    
    openCreateModal()
    const start = new Date(date)
    start.setHours(8, 0, 0)
    form.start_time = formatDateForInput(start)
    
    const end = new Date(date)
    end.setHours(17, 0, 0)
    form.end_time = formatDateForInput(end)
}

const handleEventClick = (event) => {
    if (!hasPermission('schedules.view') && !hasPermission('schedules.edit')) return;

    const user = page.props.auth.user;
    const isAdmin = user.roles?.some(r => r.name === 'Admin');
    const isOwner = Number(event.user_id) === Number(user.id);
    const canEdit = isOwner || isAdmin;

    isEditing.value = canEdit;
    isViewingOnly.value = !canEdit;
    currentScheduleId.value = event.id
    
    form.user_id = event.user_id
    form.store_id = event.store_id
    form.status = event.status
    form.start_time = formatDateForInput(new Date(event.start_time))
    form.end_time = formatDateForInput(new Date(event.end_time))
    form.pickup_start = event.pickup_start || ''
    form.pickup_end = event.pickup_end || ''
    form.backlogs_start = event.backlogs_start || ''
    form.backlogs_end = event.backlogs_end || ''
    form.remarks = event.remarks || ''
    
    showModal.value = true
}

const closeModal = () => {
    showModal.value = false;
    isViewingOnly.value = false;
}

const submitForm = () => {
    const url = isEditing.value ? `/schedules/${currentScheduleId.value}` : '/schedules'
    const requestMethod = isEditing.value ? put : post
    
    requestMethod(url, form, {
        onSuccess: () => {
            closeModal()
            showSuccess(isEditing.value ? 'Schedule updated successfully' : 'Schedule created successfully')
        },
        onError: (errors) => {
            const errorMessage = Object.values(errors).flat().join(', ') || 'An error occurred'
            showError(errorMessage)
        }
    })
}
</script>
