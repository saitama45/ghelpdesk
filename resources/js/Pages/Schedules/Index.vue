<template>
    <AppLayout title="Scheduling">
        <div class="py-12">
            <div class="max-w-[1600px] mx-auto sm:px-6 lg:px-8">
                
                <!-- View Toggle & Actions Header -->
                <div class="mb-6 bg-white rounded-xl shadow-sm border border-gray-100">
                    <!-- Row 1: View toggle + Action buttons -->
                    <div class="flex items-center justify-between px-4 py-3 border-b border-gray-100">
                        <!-- View Toggle -->
                        <div class="flex bg-gray-100 p-1 rounded-lg">
                            <button
                                @click="switchView('calendar')"
                                :class="['px-4 py-2 text-sm font-bold rounded-md transition-all', currentView === 'calendar' ? 'bg-white text-blue-600 shadow-sm' : 'text-gray-500 hover:text-gray-700']"
                            >
                                Calendar View
                            </button>
                            <button
                                @click="switchView('report')"
                                :class="['px-4 py-2 text-sm font-bold rounded-md transition-all', currentView === 'report' ? 'bg-white text-blue-600 shadow-sm' : 'text-gray-500 hover:text-gray-700']"
                            >
                                Report View
                            </button>
                            <button
                                @click="switchView('missing-schedules')"
                                :class="['px-4 py-2 text-sm font-bold rounded-md transition-all', currentView === 'missing-schedules' ? 'bg-white text-blue-600 shadow-sm' : 'text-gray-500 hover:text-gray-700']"
                            >
                                Missing Schedules
                            </button>
                        </div>

                        <!-- Action Buttons -->
                        <div class="flex items-center space-x-2">
                            <button
                                @click="exportPdf"
                                class="bg-white border border-gray-300 text-gray-700 px-4 py-2 rounded-lg text-sm font-medium hover:bg-gray-50 transition-colors flex items-center space-x-2 shadow-sm"
                            >
                                <svg class="w-4 h-4 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                                </svg>
                                <span>{{ currentView === 'report' ? 'Export Report PDF' : (currentView === 'missing-schedules' ? 'Export Missing PDF' : 'Export PDF') }}</span>
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

                    <!-- Row 2: Filters -->
                    <div class="flex items-center gap-3 px-4 py-3">
                        <!-- Sub-Unit filter (both views) -->
                        <div class="w-56" v-if="subUnitOptions.length > 1">
                            <Autocomplete
                                v-model="filterSubUnit"
                                :options="subUnitOptions"
                                label-key="name"
                                value-key="id"
                                placeholder="Filter by sub-unit..."
                                @update:modelValue="applyFilter"
                            />
                        </div>

                        <!-- Store filter (both views) -->
                        <div class="w-56">
                            <Autocomplete
                                v-model="filterStore"
                                :options="storeOptions"
                                label-key="name"
                                value-key="id"
                                placeholder="Filter by store..."
                                @update:modelValue="applyFilter"
                            />
                        </div>

                        <!-- User filter (calendar and missing schedules) -->
                        <div class="w-56" v-if="currentView === 'calendar' || currentView === 'missing-schedules'">
                            <Autocomplete
                                v-model="filterUser"
                                :options="userFilterOptions"
                                label-key="name"
                                value-key="id"
                                placeholder="Filter by user..."
                                @update:modelValue="applyFilter"
                            />
                        </div>

                        <!-- Date Range filter (missing schedules only) -->
                        <div v-if="currentView === 'missing-schedules'" class="flex items-center gap-2">
                            <div class="flex items-center gap-1.5">
                                <span class="text-[10px] font-black text-gray-400 uppercase tracking-widest">From:</span>
                                <input 
                                    v-model="visibleRange.start" 
                                    type="date" 
                                    @change="applyFilter"
                                    class="px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-xs font-bold text-gray-700"
                                >
                            </div>
                            <div class="flex items-center gap-1.5">
                                <span class="text-[10px] font-black text-gray-400 uppercase tracking-widest">To:</span>
                                <input 
                                    v-model="visibleRange.end" 
                                    type="date" 
                                    @change="applyFilter"
                                    class="px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-xs font-bold text-gray-700"
                                >
                            </div>
                        </div>

                        <!-- Year compare (report only) -->
                        <div v-if="currentView === 'report'" class="flex items-center space-x-2">
                            <span class="text-xs font-black text-gray-400 uppercase tracking-widest whitespace-nowrap">Compare Years:</span>
                            <div class="flex gap-1 bg-gray-100 p-1 rounded-lg border border-gray-200">
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
                    </div>
                </div>

                <!-- Calendar View -->
                <div v-if="currentView === 'calendar'">
                    <Calendar 
                        :events="schedules" 
                        v-model:statusFilter="filterStatus"
                        v-model:priorityFilter="filterPriority"
                        @visible-range-change="handleVisibleRangeChange"
                        @date-click="handleDateClick"
                        @event-click="handleEventClick"
                    />
                </div>

                <!-- Pivot Report View -->
                <div v-else-if="currentView === 'report'" class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-200 bg-gray-50 flex justify-between items-center">
                        <h3 class="text-lg font-bold text-gray-900">{{ reportTitle }}</h3>
                        <span class="text-xs font-black text-gray-500 uppercase tracking-widest">Live Report</span>
                    </div>
                    <div class="overflow-x-auto custom-scrollbar">
                        <table class="min-w-full divide-y divide-gray-200 border-b border-gray-200">
                            <thead class="bg-gray-100">
                                <!-- Year Headers -->
                                <tr>
                                    <th rowspan="2" class="px-4 py-3 text-left text-[10px] font-black text-gray-500 uppercase tracking-widest border-r border-gray-200 bg-gray-50 z-10 sticky left-0 min-w-[100px]">Unit</th>
                                    <th rowspan="2" class="px-4 py-3 text-left text-[10px] font-black text-gray-500 uppercase tracking-widest border-r border-gray-200 bg-gray-50 z-10 sticky left-[100px] min-w-[150px]">Name</th>
                                    <th v-for="year in activePivotYears" :key="'header-' + year" :colspan="pivotStatuses.length" class="px-4 py-2 text-center text-xs font-black text-white bg-slate-700 uppercase tracking-widest border-r border-slate-600 last:border-r-0">
                                        {{ year }}
                                    </th>
                                </tr>
                                <!-- Status Headers -->
                                <tr>
                                    <template v-for="year in activePivotYears" :key="'status-' + year">
                                        <th v-for="status in pivotStatuses" :key="year + '-' + status" class="px-2 py-2 text-center text-[9px] font-black text-gray-500 uppercase tracking-tighter border-r border-gray-200 border-t last:border-r-0" :class="status === 'Holiday' ? 'bg-red-50/50' : (status === 'Restday' ? 'bg-gray-50/50' : 'bg-white')">
                                            {{ status === 'On-site' ? 'On-site' : (status === 'Off-site' ? 'Off-site' : (status === 'Restday' ? 'RD' : status)) }}
                                        </th>
                                    </template>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <!-- Loading state -->
                                <tr v-if="isPivotLoading">
                                    <td :colspan="2 + (activePivotYears.length * pivotStatuses.length)" class="px-6 py-12 text-center">
                                        <div class="flex items-center justify-center gap-2 text-sm text-gray-500">
                                            <svg class="w-4 h-4 animate-spin text-blue-500" fill="none" viewBox="0 0 24 24">
                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 6.477 0 12h4z"/>
                                            </svg>
                                            Loading report data...
                                        </div>
                                    </td>
                                </tr>
                                <template v-else>
                                    <tr v-for="row in pivotData" :key="row.name + row.unit" class="hover:bg-blue-50/50 transition-colors">
                                        <td class="px-4 py-2 whitespace-nowrap text-xs font-bold text-gray-500 bg-white border-r border-gray-100 sticky left-0 z-10">{{ row.unit || '-' }}</td>
                                        <td class="px-4 py-2 whitespace-nowrap text-sm font-bold text-gray-900 bg-white border-r border-gray-200 sticky left-[100px] z-10">{{ row.name }}</td>

                                        <template v-for="year in activePivotYears" :key="'data-' + year">
                                            <td v-for="status in pivotStatuses" :key="row.name + year + status" class="px-2 py-2 whitespace-nowrap text-center text-xs border-r border-gray-100 last:border-r-0" :class="[
                                                (row.years[year] && row.years[year][status] > 0) ? 'font-black text-blue-700' : 'font-medium text-gray-300',
                                                status === 'Holiday' ? 'bg-red-50/30' : (status === 'Restday' ? 'bg-gray-50/30' : '')
                                            ]">
                                                {{ (row.years[year] && row.years[year][status] > 0) ? row.years[year][status] : '-' }}
                                            </td>
                                        </template>
                                    </tr>
                                    <tr v-if="pivotData.length === 0">
                                        <td :colspan="2 + (activePivotYears.length * pivotStatuses.length)" class="px-6 py-12 text-center text-sm text-gray-500 italic">
                                            No schedule data found for the reporting period.
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Missing Schedules View -->
                <div v-else-if="currentView === 'missing-schedules'" class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-200 bg-gray-50 flex justify-between items-center">
                        <h3 class="text-lg font-bold text-gray-900">Missing Schedules ({{ visibleRange.start }} to {{ visibleRange.end }})</h3>
                        <span class="text-xs font-black text-gray-500 uppercase tracking-widest">Unscheduled Users</span>
                    </div>
                    <div class="overflow-x-auto custom-scrollbar">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-100">
                                <tr>
                                    <th class="px-6 py-3 text-left text-[10px] font-black text-gray-500 uppercase tracking-widest">Sub-Unit</th>
                                    <th class="px-6 py-3 text-left text-[10px] font-black text-gray-500 uppercase tracking-widest">Name</th>
                                    <th class="px-6 py-3 text-left text-[10px] font-black text-gray-500 uppercase tracking-widest">Missing Days</th>
                                    <th class="px-6 py-3 text-left text-[10px] font-black text-gray-500 uppercase tracking-widest">Count</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <tr v-if="isMissingSchedulesLoading">
                                    <td colspan="4" class="px-6 py-12 text-center">
                                        <div class="flex items-center justify-center gap-2 text-sm text-gray-500">
                                            <svg class="w-4 h-4 animate-spin text-blue-500" fill="none" viewBox="0 0 24 24">
                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 6.477 0 12h4z"/>
                                            </svg>
                                            Loading unscheduled users...
                                        </div>
                                    </td>
                                </tr>
                                <template v-else>
                                    <tr v-for="user in missingSchedulesData" :key="user.id" class="hover:bg-gray-50 transition-colors">
                                        <td class="px-6 py-4 whitespace-nowrap text-xs font-bold text-gray-500">{{ user.sub_unit || '-' }}</td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-gray-900">{{ user.name }}</td>
                                        <td class="px-6 py-4 text-sm text-red-600 font-medium max-w-md">
                                            <div class="flex flex-wrap gap-1">
                                                <span v-for="(day, i) in user.missing_days" :key="i" class="bg-red-50 px-1.5 py-0.5 rounded border border-red-100 text-[10px] whitespace-nowrap">
                                                    {{ day }}
                                                </span>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-black text-gray-700">{{ user.missing_days_count }}</td>
                                    </tr>
                                    <tr v-if="missingSchedulesData.length === 0">
                                        <td colspan="4" class="px-6 py-12 text-center text-sm text-gray-500 italic">
                                            All users have schedules for this period.
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination Footer -->
                    <div v-if="missingSchedulesPagination.total > 0" class="px-6 py-4 bg-gray-50 border-t border-gray-200 flex items-center justify-between">
                        <div class="text-xs text-gray-500 font-bold uppercase tracking-widest">
                            Showing {{ (missingSchedulesPagination.current_page - 1) * missingSchedulesPagination.per_page + 1 }} 
                            to {{ Math.min(missingSchedulesPagination.current_page * missingSchedulesPagination.per_page, missingSchedulesPagination.total) }} 
                            of {{ missingSchedulesPagination.total }} users
                        </div>
                        <div class="flex items-center space-x-2">
                            <button 
                                @click="fetchMissingSchedulesData(missingSchedulesPagination.current_page - 1)"
                                :disabled="missingSchedulesPagination.current_page === 1"
                                class="px-3 py-1 bg-white border border-gray-300 rounded-md text-xs font-bold text-gray-700 hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                            >
                                Previous
                            </button>
                            
                            <!-- Page Numbers (simple logic) -->
                            <div class="flex items-center space-x-1">
                                <button 
                                    v-for="p in missingSchedulesPagination.last_page" 
                                    :key="p"
                                    @click="fetchMissingSchedulesData(p)"
                                    :class="[
                                        'w-8 h-8 rounded-md text-xs font-bold transition-all',
                                        missingSchedulesPagination.current_page === p 
                                            ? 'bg-blue-600 text-white shadow-sm' 
                                            : 'bg-white text-gray-600 border border-gray-200 hover:bg-gray-50'
                                    ]"
                                >
                                    {{ p }}
                                </button>
                            </div>

                            <button 
                                @click="fetchMissingSchedulesData(missingSchedulesPagination.current_page + 1)"
                                :disabled="missingSchedulesPagination.current_page === missingSchedulesPagination.last_page"
                                class="px-3 py-1 bg-white border border-gray-300 rounded-md text-xs font-bold text-gray-700 hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                            >
                                Next
                            </button>
                        </div>
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
                        <!-- Format description -->
                        <div class="rounded-lg bg-blue-50 border border-blue-100 p-3 text-xs text-blue-800 space-y-1">
                            <p class="font-bold">Format: wide (one row per user, three columns per date)</p>
                            <ul class="list-disc list-inside space-y-0.5 text-blue-700">
                                <li><span class="font-semibold">user_id</span> — numeric user ID (column A)</li>
                                <li><span class="font-semibold">user_name</span> — reference only, not imported (column B)</li>
                                <li><span class="font-semibold">Per Date Triples</span> — set <span class="font-semibold">Status</span>, <span class="font-semibold">Store</span> (optional), and <span class="font-semibold">Remarks</span> (optional)</li>
                                <li>Leave status <span class="font-semibold">NA</span> or blank for no schedule</li>
                            </ul>
                            <p class="text-blue-600 mt-1">Default schedule time: <span class="font-semibold">07:00 – 17:00</span></p>
                        </div>

                        <!-- Template Download with month/year selector -->
                        <div class="rounded-lg border border-dashed border-gray-300 bg-gray-50 p-4 space-y-3">
                            <p class="text-xs font-bold text-gray-500 uppercase tracking-wider">Step 1 — Download template</p>
                            <div class="flex items-center gap-2">
                                <select v-model="importYear" class="border border-gray-300 rounded-lg pl-3 pr-8 py-1.5 text-sm text-gray-700 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                    <option v-for="y in yearOptions" :key="y" :value="y">{{ y }}</option>
                                </select>
                            </div>
                            <a :href="importTemplateUrl" class="flex items-center space-x-3 text-blue-600 hover:text-blue-800 transition-colors group">
                                <div class="h-10 w-10 bg-blue-100 group-hover:bg-blue-200 rounded-lg flex items-center justify-center flex-shrink-0 transition-colors">
                                    <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                    </svg>
                                </div>
                                <div>
                                    <div class="text-sm font-semibold">Download Excel Template</div>
                                    <div class="text-xs text-gray-500">schedules-import-{{ importYear }}.xlsx</div>
                                </div>
                            </a>
                        </div>

                        <!-- File Upload -->
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Step 2 — Upload filled template</label>
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
                        <div class="flex items-center space-x-2">
                            <!-- Edit Button (Pencil) -->
                            <button 
                                v-if="isViewingOnly && canEditSchedule"
                                @click="isViewingOnly = false"
                                class="p-2 text-blue-600 hover:bg-blue-50 rounded-full transition-colors"
                                title="Edit Schedule"
                            >
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                </svg>
                            </button>
                            
                            <button @click="closeModal" class="text-gray-400 hover:text-gray-600 transition-colors">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>
                    </div>

                    <form @submit.prevent="submitForm" class="space-y-4">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">User</label>
                                <template v-if="isManager && !isViewingOnly">
                                    <!-- Edit / create mode: pick from subordinates -->
                                    <Autocomplete
                                        v-model="form.user_id"
                                        :options="subordinateUsers"
                                        label-key="name"
                                        value-key="id"
                                        placeholder="Select user..."
                                    />
                                </template>
                                <template v-else>
                                    <!-- View mode (any user) or non-manager: show read-only label -->
                                    <p class="px-3 py-2 text-sm text-gray-800 bg-gray-100 rounded-lg border border-gray-200">
                                        {{ (props.users ?? []).find(u => Number(u.id) === Number(form.user_id))?.name || authUser.name }}
                                    </p>
                                </template>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                                <select v-model="form.status" required :disabled="isViewingOnly"
                                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                                    <option v-for="status in statuses" :key="status" :value="status">{{ status }}</option>
                                </select>
                            </div>
                        </div>

                        <!-- Store Entries Repeater -->
                        <div class="space-y-2">
                            <div class="flex items-center justify-between mb-1">
                                <label class="block text-sm font-medium text-gray-700">Location Visits</label>
                                <button v-if="!isViewingOnly" type="button" @click="addStore"
                                        class="inline-flex items-center gap-1 text-xs font-bold text-blue-600 hover:text-blue-800 transition-colors">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
                                    Add Location
                                </button>
                            </div>

                            <div v-for="(entry, index) in form.stores" :key="index"
                                 class="relative p-3 bg-gray-50 rounded-lg border border-gray-100 space-y-3">
                                <!-- Remove button -->
                                <button v-if="!isViewingOnly && form.stores.length > 1" type="button" @click="removeStore(index)"
                                        class="absolute top-2 right-2 p-1.5 text-red-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors" title="Remove">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                </button>

                                <!-- Row 1: Store | Start | End -->
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                                    <div>
                                        <label class="block text-xs font-medium text-gray-500 mb-1">Location</label>
                                        <Autocomplete
                                            v-model="entry.store_id"
                                            :options="storeSelectOptions"
                                            label-key="name"
                                            value-key="id"
                                            placeholder="Select store..."
                                            :disabled="isViewingOnly"
                                        />
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-500 mb-1">Start Date & Time</label>
                                        <input v-model="entry.start_time" type="datetime-local" required :disabled="isViewingOnly"
                                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-500 mb-1">End Date & Time</label>
                                        <input v-model="entry.end_time" type="datetime-local" required :disabled="isViewingOnly"
                                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                                    </div>
                                </div>

                                <!-- Actual Time In / Out (per segment) -->
                                <div v-if="entry.ticket || (isEditing && (entry.actual_time_in || entry.actual_time_out))"
                                     class="flex flex-col gap-2 bg-white/50 p-3 rounded-md border border-gray-100 shadow-sm">
                                    
                                    <!-- Ticket Link per Visit -->
                                    <div v-if="entry.ticket" class="flex items-center justify-between pb-2 border-b border-gray-100/50 mb-1">
                                        <div class="flex items-center space-x-2">
                                            <svg class="w-3.5 h-3.5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 5v2m0 4v2m0 4v2M5 5a2 2 0 012-2h10a2 2 0 012 2v14a2 2 0 01-2 2H7a2 2 0 01-2-2V5z" />
                                            </svg>
                                            <span class="text-[10px] font-black text-blue-700 uppercase tracking-widest">Visit Ticket:</span>
                                            <Link :href="route('tickets.edit', entry.ticket.id)" class="text-xs font-black text-blue-600 hover:text-blue-800 hover:underline">
                                                #{{ entry.ticket.ticket_key }}
                                            </Link>
                                        </div>
                                        <span class="text-[9px] font-bold text-blue-400 truncate max-w-[200px]" :title="entry.ticket.title">{{ entry.ticket.title }}</span>
                                    </div>

                                    <div v-if="entry.actual_time_in || entry.actual_time_out" class="flex flex-wrap gap-x-6 gap-y-1 text-xs font-bold">
                                        <span v-if="entry.actual_time_in" class="text-emerald-600 flex items-center gap-1">
                                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"/></svg>
                                            Actual In: {{ formatDateTime(entry.actual_time_in) }}
                                        </span>
                                        <span v-if="entry.actual_time_out" class="text-orange-500 flex items-center gap-1">
                                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"/></svg>
                                            Actual Out: {{ formatDateTime(entry.actual_time_out) }}
                                        </span>
                                    </div>
                                </div>

                                <!-- Row 2: Grace | Remarks -->
                                <div class="grid grid-cols-1 md:grid-cols-[120px_1fr] gap-3">
                                    <div>
                                        <label class="block text-xs font-medium text-gray-500 mb-1">Grace (min)</label>
                                        <input v-model.number="entry.grace_period_minutes" type="number" min="0" max="480" :disabled="isViewingOnly"
                                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm"
                                               placeholder="30">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-500 mb-1">Off-site Remarks / Other Activities</label>
                                        <textarea v-model="entry.remarks" rows="2" :disabled="isViewingOnly"
                                                  class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm"
                                                  placeholder="Remarks for this location visit..."></textarea>
                                    </div>
                                </div>
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

                        <div class="pt-4 border-t space-y-4">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-sm">
                                <div class="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2">
                                    <div class="text-[10px] font-black uppercase tracking-widest text-gray-400">Created By</div>
                                    <div class="mt-1 font-medium text-gray-700">{{ modalAudit.createdBy }}</div>
                                </div>
                                <div class="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2">
                                    <div class="text-[10px] font-black uppercase tracking-widest text-gray-400">Created At</div>
                                    <div class="mt-1 font-medium text-gray-700">{{ modalAudit.createdAt }}</div>
                                </div>
                                <div class="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2">
                                    <div class="text-[10px] font-black uppercase tracking-widest text-gray-400">Updated By</div>
                                    <div class="mt-1 font-medium text-gray-700">{{ modalAudit.updatedBy }}</div>
                                </div>
                                <div class="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2">
                                    <div class="text-[10px] font-black uppercase tracking-widest text-gray-400">Updated At</div>
                                    <div class="mt-1 font-medium text-gray-700">{{ modalAudit.updatedAt }}</div>
                                </div>
                            </div>

                            <div class="flex justify-end items-center">
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
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref, reactive, onMounted, computed, watch } from 'vue'
import { router, usePage, useRemember, Link } from '@inertiajs/vue3'
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
    pivotYears: Array,
    availableYears: Array,
    pivotStatuses: Array,
    filters: Object
})

const formatDateParam = (date) => {
    const year = date.getFullYear()
    const month = String(date.getMonth() + 1).padStart(2, '0')
    const day = String(date.getDate()).padStart(2, '0')

    return `${year}-${month}-${day}`
}

const getMonthRange = (date = new Date()) => {
    const year = date.getFullYear()
    const month = date.getMonth()

    return {
        start: formatDateParam(new Date(year, month, 1)),
        end: formatDateParam(new Date(year, month + 1, 0)),
    }
}

const page = usePage()
const initialRange = props.filters?.start && props.filters?.end
    ? { start: props.filters.start, end: props.filters.end }
    : getMonthRange()

const filterUser = useRemember(props.filters?.user_id || '', 'schedules.filterUser')
const filterSubUnit = useRemember(props.filters?.sub_unit || '', 'schedules.filterSubUnit')
const filterStore = useRemember(props.filters?.store_id || '', 'schedules.filterStore')

// These filters are synced with the Calendar component
const filterStatus = useRemember(
    props.filters?.status ? (Array.isArray(props.filters.status) ? props.filters.status : [props.filters.status]) : ['On-site', 'Off-site', 'WFH', 'SL', 'VL', 'Restday', 'Holiday', 'Offset'],
    'schedules.filterStatus'
)
const filterPriority = useRemember(
    props.filters?.priority ? (Array.isArray(props.filters.priority) ? props.filters.priority : [props.filters.priority]) : ['none', 'urgent', 'high', 'medium', 'low'],
    'schedules.filterPriority'
)

const selectedReportYears = useRemember(
    props.filters?.report_years ? (Array.isArray(props.filters.report_years) ? props.filters.report_years.map(Number) : [Number(props.filters.report_years)]) : [...props.pivotYears],
    'schedules.selectedReportYears'
)
const activePivotYears = computed(() => [...selectedReportYears.value].sort((a, b) => a - b))
const reportTitle = computed(() => {
    const count = activePivotYears.value.length
    return `${count}-Year Schedule Comparison`
})
const currentView = useRemember('calendar', 'schedules.currentView')
const visibleRange = useRemember(initialRange, 'schedules.visibleRange')

// Pivot report data — fetched on demand when the user opens the Report tab
const pivotData = ref([])
const isPivotLoading = ref(false)

const fetchPivotData = async () => {
    if (isPivotLoading.value) return
    isPivotLoading.value = true
    try {
        const params = new URLSearchParams()
        selectedReportYears.value.forEach(y => params.append('report_years[]', y))
        if (filterSubUnit.value) params.set('sub_unit', filterSubUnit.value)
        if (filterStore.value)   params.set('store_id', filterStore.value)

        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
        const res = await fetch(`/schedules/report-data?${params}`, {
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken }
        })
        if (res.ok) pivotData.value = await res.json()
    } catch (e) {
        console.error('Failed to load report data', e)
    } finally {
        isPivotLoading.value = false
    }
}

// Missing schedules data
const missingSchedulesData = ref([])
const missingSchedulesPagination = ref({
    current_page: 1,
    last_page: 1,
    total: 0,
    per_page: 10
})
const isMissingSchedulesLoading = ref(false)

const fetchMissingSchedulesData = async (page = 1) => {
    if (isMissingSchedulesLoading.value) return
    isMissingSchedulesLoading.value = true
    try {
        const params = new URLSearchParams()
        params.set('start', visibleRange.value.start)
        params.set('end', visibleRange.value.end)
        params.set('page', page)
        if (filterSubUnit.value) params.set('sub_unit', filterSubUnit.value)
        if (filterUser.value)    params.set('user_id', filterUser.value)

        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
        const res = await fetch(`/schedules/missing-schedules?${params}`, {
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken }
        })
        if (res.ok) {
            const result = await res.json()
            missingSchedulesData.value = result.data
            missingSchedulesPagination.value = {
                current_page: result.current_page,
                last_page: result.last_page,
                total: result.total,
                per_page: result.per_page
            }
        }
    } catch (e) {
        console.error('Failed to load missing schedules data', e)
    } finally {
        isMissingSchedulesLoading.value = false
    }
}

const switchView = (view) => {
    currentView.value = view
    if (view === 'report') fetchPivotData()
    if (view === 'missing-schedules') fetchMissingSchedulesData()
}

const authUser = computed(() => page.props.auth.user)
const isManager = computed(() => !!authUser.value?.is_manager)

// Users available to a manager in the schedule form:
// the manager themselves + their direct subordinates
const subordinateUsers = computed(() => {
    const all = props.users ?? []
    const subs = all.filter(u => u.managers?.some(m => Number(m.id) === Number(authUser.value?.id)))
    const self = all.find(u => Number(u.id) === Number(authUser.value?.id))
    // Prepend self if not already in the subordinates list
    if (self && !subs.some(u => Number(u.id) === Number(authUser.value?.id))) {
        return [self, ...subs]
    }
    return subs
})

const storeOptions = computed(() => {
    return [
        { id: '', name: 'All Stores' },
        ...(props.stores ?? []).map(s => ({ id: s.id, name: s.name }))
    ]
})

// For the store repeater inside the form (no "All Stores" entry)
const storeSelectOptions = computed(() => {
    return (props.stores ?? []).map(s => ({ id: s.id, name: s.name }))
})

const subUnitOptions = computed(() => {
    const units = props.users
        .map(u => u.sub_unit)
        .filter(u => u && u.trim() !== '')
    const unique = [...new Set(units)].sort()
    return [
        { id: '', name: 'All Sub-Units' },
        ...unique.map(u => ({ id: u, name: u }))
    ]
})

const userFilterOptions = computed(() => {
    const currentUserId = page.props.auth.user.id
    const options = [
        { id: '', name: 'All Users' },
        { id: 'my', name: 'My Schedules' }
    ]
    
    props.users.forEach(user => {
        if (Number(user.id) !== Number(currentUserId)) {
            options.push({ id: user.id, name: user.name })
        }
    })
    
    return options
})

const applyFilter = () => {
    router.get(route('schedules.index'), {
        start: visibleRange.value.start,
        end: visibleRange.value.end,
        user_id: filterUser.value,
        sub_unit: filterSubUnit.value,
        store_id: filterStore.value,
        report_years: selectedReportYears.value
    }, {
        only: ['schedules', 'filters'],
        preserveState: true,
        preserveScroll: true,
        replace: true,
    })

    if (currentView.value === 'report') {
        fetchPivotData()
    }
    if (currentView.value === 'missing-schedules') {
        fetchMissingSchedulesData()
    }
}

const handleVisibleRangeChange = (range) => {
    if (!range?.start || !range?.end) return
    if (visibleRange.value.start === range.start && visibleRange.value.end === range.end) return

    visibleRange.value = range

    router.get(route('schedules.index'), {
        start: range.start,
        end: range.end,
        user_id: filterUser.value,
        sub_unit: filterSubUnit.value,
        store_id: filterStore.value,
        report_years: selectedReportYears.value
    }, {
        only: ['schedules', 'filters'],
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onSuccess: () => {
            if (currentView.value === 'missing-schedules') {
                fetchMissingSchedulesData()
            }
        }
    })
}

// Year changes in report view: re-fetch pivot data only (no full page reload needed)
watch(selectedReportYears, () => {
    if (currentView.value === 'report') {
        fetchPivotData()
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
    let params = {};

    if (currentView.value === 'report') {
        params = {
            view: 'report',
            report_years: selectedReportYears.value,
        };
    } else if (currentView.value === 'missing-schedules') {
        params = {
            view: 'missing-schedules',
            start: visibleRange.value.start,
            end: visibleRange.value.end,
        };
    } else {
        params = {
            start: visibleRange.value.start,
            end: visibleRange.value.end,
            status: filterStatus.value.join(','),
            priority: filterPriority.value.join(','),
        };
    }

    if (currentView.value !== 'report' && filterUser.value) {
        params.user_id = filterUser.value;
    }
    if (filterSubUnit.value) params.sub_unit = filterSubUnit.value;
    if (filterStore.value && currentView.value === 'calendar') params.store_id = filterStore.value;

    window.open(route('schedules.export.pdf', params), '_blank');
};

// Import
const showImportModal = ref(false)
const importFile = ref(null)
const importFileInput = ref(null)
const isImporting = ref(false)
const importResult = ref(null)
const importYear = ref(new Date().getFullYear())

const importTemplateUrl = computed(() =>
    `/schedules/template?year=${importYear.value}`
)

const yearOptions = computed(() => {
    const current = new Date().getFullYear()
    return Array.from({ length: 5 }, (_, index) => current - 2 + index)
})

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

        const isJson = response.headers.get('content-type')?.includes('application/json')
        const result = isJson ? await response.json() : null

        if (response.ok) {
            importResult.value = result
            if (result.imported > 0) {
                showSuccess(`${result.imported} schedule${result.imported === 1 ? '' : 's'} imported successfully`)
                router.reload({ only: ['schedules'] })
            }
        } else {
            if (response.status === 504) {
                showError('Import timed out on the server. The import flow has been optimized; please try the file again.')
                return
            }

            showError(result?.message || 'Import failed. Please check your file and try again.')
        }
    } catch (e) {
        showError('Import failed. Please try again.')
    } finally {
        isImporting.value = false
    }
}

// Create / Edit
const showModal = ref(false)
const isEditing = ref(false)
const isViewingOnly = ref(false)
const canEditSchedule = ref(false)
const currentScheduleId    = ref(null)
const currentActualTimeIn  = ref(null)
const currentActualTimeOut = ref(null)
const currentCreatedBy     = ref(null)
const currentCreatedAt     = ref(null)
const currentUpdatedBy     = ref(null)
const currentUpdatedAt     = ref(null)

const statuses = [
    'On-site', 'Off-site', 'WFH', 'SL', 'VL', 'Restday', 'Offset', 'Holiday'
]

const priorityLegend = [
    { key: 'urgent', label: 'P1 - Urgent', color: 'bg-red-600' },
    { key: 'high',   label: 'P2 - High',   color: 'bg-orange-500' },
    { key: 'medium', label: 'P3 - Medium', color: 'bg-yellow-500' },
    { key: 'low',    label: 'P4 - Low',    color: 'bg-green-600' },
]

const scheduleLegend = [
    { status: 'On-site',  label: 'On-site',       color: 'bg-blue-600' },
    { status: 'Off-site', label: 'Off-site',       color: 'bg-purple-600' },
    { status: 'WFH',      label: 'WFH',            color: 'bg-emerald-600' },
    { status: 'SL',       label: 'Sick Leave',     color: 'bg-rose-600' },
    { status: 'VL',       label: 'Vacation Leave', color: 'bg-amber-500' },
    { status: 'Restday',  label: 'Rest Day',       color: 'bg-slate-400' },
    { status: 'Holiday',  label: 'Holiday',        color: 'bg-yellow-500' },
    { status: 'Offset',   label: 'Offset',         color: 'bg-cyan-600' },
]

const form = reactive({
    user_id: authUser.value?.id ?? null,
    status: 'On-site',
    stores: [{ store_id: null, ticket_id: null, start_time: '', end_time: '', grace_period_minutes: 30, remarks: '' }],
    pickup_start: '',
    pickup_end: '',
    backlogs_start: '',
    backlogs_end: '',
})

const formatAuditDateTime = (value) => {
    if (!value) return '-'

    const normalizedValue = typeof value === 'string' && value.includes(' ') ? value.replace(' ', 'T') : value
    const date = new Date(normalizedValue)

    if (Number.isNaN(date.getTime())) {
        return '-'
    }

    return date.toLocaleString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        hour12: true,
    })
}

const modalAudit = computed(() => {
    if (!isEditing.value) {
        return {
            createdBy: authUser.value?.name || '-',
            createdAt: 'Not saved yet',
            updatedBy: authUser.value?.name || '-',
            updatedAt: '-',
        }
    }

    return {
        createdBy: currentCreatedBy.value || '-',
        createdAt: formatAuditDateTime(currentCreatedAt.value),
        updatedBy: currentUpdatedBy.value || '-',
        updatedAt: formatAuditDateTime(currentUpdatedAt.value),
    }
})

const formatTime = (isoString) => {
    if (!isoString) return '-'
    return new Date(isoString).toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: true })
}

const formatDateTime = (isoString) => {
    if (!isoString) return '-'
    return new Date(isoString).toLocaleString('en-US', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', hour12: true })
}

const formatDateForInput = (date) => {
    const d = new Date(date);
    d.setMinutes(d.getMinutes() - d.getTimezoneOffset());
    return d.toISOString().slice(0, 16);
}

const getManilaDateKey = (value) => {
    if (!value) return null
    return new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Manila' }).format(new Date(value))
}

const getCalendarDateKey = (value) => {
    if (!value) return null

    if (value instanceof Date) {
        const year = value.getFullYear()
        const month = String(value.getMonth() + 1).padStart(2, '0')
        const day = String(value.getDate()).padStart(2, '0')
        return `${year}-${month}-${day}`
    }

    if (typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value)) {
        return value
    }

    return getManilaDateKey(value)
}

const getActualTimesForDate = (source, dateKey) => {
    if (!source || !dateKey) {
        return { actual_time_in: null, actual_time_out: null }
    }

    if (source.actual_times_by_date?.[dateKey]) {
        return source.actual_times_by_date[dateKey]
    }

    return {
        actual_time_in: source.actual_time_in && getManilaDateKey(source.actual_time_in) === dateKey ? source.actual_time_in : null,
        actual_time_out: source.actual_time_out && getManilaDateKey(source.actual_time_out) === dateKey ? source.actual_time_out : null,
    }
}

const isEntryOnDate = (entry, dateKey) => {
    if (!entry || !dateKey) return false

    const startKey = getManilaDateKey(entry.start_time)
    const endKey = getManilaDateKey(entry.end_time)

    return startKey === dateKey || endKey === dateKey
}

const openCreateModal = () => {
    isEditing.value = false
    isViewingOnly.value = false
    currentScheduleId.value = null
    currentActualTimeIn.value  = null
    currentActualTimeOut.value = null
    currentCreatedBy.value = authUser.value?.name || null
    currentCreatedAt.value = null
    currentUpdatedBy.value = authUser.value?.name || null
    currentUpdatedAt.value = null

    form.user_id = authUser.value?.id ?? null
    form.status = 'On-site'
    form.pickup_start = ''
    form.pickup_end = ''
    form.backlogs_start = ''
    form.backlogs_end = ''

    const now = new Date()
    const start = new Date(now)
    start.setHours(7, 0, 0, 0)
    const end = new Date(now)
    end.setHours(17, 0, 0, 0)
    form.stores = [{ store_id: null, start_time: formatDateForInput(start), end_time: formatDateForInput(end), grace_period_minutes: 30, remarks: '' }]

    showModal.value = true
}

const handleDateClick = (date) => {
    if (!hasPermission('schedules.create')) return

    openCreateModal()
    const start = new Date(date)
    start.setHours(7, 0, 0, 0)
    const end = new Date(date)
    end.setHours(17, 0, 0, 0)
    form.stores = [{ store_id: null, start_time: formatDateForInput(start), end_time: formatDateForInput(end), grace_period_minutes: 30 }]
}

const handleEventClick = (payload) => {
    const event = payload?.event ?? payload
    const clickedDateKey = getCalendarDateKey(payload?.date) ?? getManilaDateKey(event?.start_time)

    if (!hasPermission('schedules.view') && !hasPermission('schedules.edit')) return;

    const user = page.props.auth.user;
    const isAdmin = user.roles?.some(r => r.name === 'Admin');
    const isOwner = Number(event.user_id) === Number(user.id);
    
    // Check if the current user is a manager of the user who owns this schedule
    const scheduleUser = props.users.find(u => Number(u.id) === Number(event.user_id));
    const isDirectManager = scheduleUser?.managers?.some(m => Number(m.id) === Number(user.id));
    
    const canEdit = isOwner || isAdmin || isDirectManager;

    isEditing.value = true; // Signifies we are interacting with an existing record
    isViewingOnly.value = true; // Always start in View mode
    canEditSchedule.value = canEdit; // Store permission for the Pencil icon
    currentScheduleId.value    = event.id
    const eventActualTimes = getActualTimesForDate(event, clickedDateKey)
    currentActualTimeIn.value  = eventActualTimes.actual_time_in
    currentActualTimeOut.value = eventActualTimes.actual_time_out
    currentCreatedBy.value = event.created_by_name || null
    currentCreatedAt.value = event.created_at || null
    currentUpdatedBy.value = event.updated_by_name || null
    currentUpdatedAt.value = event.updated_at || null

    form.user_id = event.user_id
    form.status = event.status
    form.pickup_start = event.pickup_start || ''
    form.pickup_end = event.pickup_end || ''
    form.backlogs_start = event.backlogs_start || ''
    form.backlogs_end = event.backlogs_end || ''

    // Populate store entries from schedule_stores; fall back to legacy single store+time
    if (event.schedule_stores && event.schedule_stores.length > 0) {
        const dayStores = event.schedule_stores.filter(ss => isEntryOnDate(ss, clickedDateKey))
        const storesToDisplay = dayStores.length > 0 ? dayStores : event.schedule_stores

        form.stores = storesToDisplay.map(ss => {
            const segmentActualTimes = getActualTimesForDate(ss, clickedDateKey)
            return {
                store_id: ss.store_id,
                ticket_id: ss.ticket_id || ss.ticket?.id || null,
                start_time: formatDateForInput(new Date(ss.start_time)),
                end_time: formatDateForInput(new Date(ss.end_time)),
                grace_period_minutes: ss.grace_period_minutes ?? 30,
                remarks: ss.remarks || '',
                actual_time_in: segmentActualTimes.actual_time_in,
                actual_time_out: segmentActualTimes.actual_time_out,
                ticket: ss.ticket || null,
            }
        })
    } else {
        const scheduleActualTimes = getActualTimesForDate(event, clickedDateKey)
        form.stores = [{
            store_id: event.store_id || null,
            ticket_id: event.ticket_id || event.ticket?.id || null,
            start_time: formatDateForInput(new Date(event.start_time)),
            end_time: formatDateForInput(new Date(event.end_time)),
            grace_period_minutes: 30,
            remarks: event.remarks || '',
            actual_time_in: scheduleActualTimes.actual_time_in,
            actual_time_out: scheduleActualTimes.actual_time_out,
            ticket: event.ticket || null,
        }]
    }

    showModal.value = true
}

const closeModal = () => {
    showModal.value = false;
    isViewingOnly.value = false;
    currentCreatedBy.value = null;
    currentCreatedAt.value = null;
    currentUpdatedBy.value = null;
    currentUpdatedAt.value = null;
}

const addStore = () => {
    const last = form.stores[form.stores.length - 1]
    const first = form.stores[0]
    form.stores.push({
        store_id: null,
        ticket_id: null,
        start_time: last?.end_time || '',
        end_time: first?.end_time || '',
        grace_period_minutes: 30,
        remarks: '',
    })
}

const removeStore = (index) => {
    form.stores.splice(index, 1)
}

const validateScheduleStores = () => {
    const missingStore = form.stores.some(entry => !entry.store_id)

    if (missingStore) {
        showError('Store is required for every schedule entry.')
        return false
    }

    return true
}

const submitForm = () => {
    if (!validateScheduleStores()) return

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
