<template>
    <AppLayout title="Scheduling">
        <div class="py-12">
            <div class="max-w-[1600px] mx-auto sm:px-6 lg:px-8">
                
                <!-- View Toggle & Actions Header -->
                <div class="mb-8 space-y-4">
                    <!-- Top Bar: View Tabs & Primary Actions -->
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                        <!-- Modern Segmented Control / Tabs -->
                        <div class="inline-flex p-1 bg-gray-100 rounded-xl shadow-inner-sm">
                            <button
                                @click="switchView('calendar')"
                                :class="['inline-flex items-center gap-2 px-4 py-2 text-sm font-semibold transition-all duration-200 rounded-lg', currentView === 'calendar' ? 'bg-white text-blue-600 shadow-md transform scale-100' : 'text-gray-500 hover:text-gray-700 hover:bg-gray-200/50']"
                            >
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                </svg>
                                <span>Calendar</span>
                            </button>
                            <button
                                @click="switchView('report')"
                                :class="['inline-flex items-center gap-2 px-4 py-2 text-sm font-semibold transition-all duration-200 rounded-lg', currentView === 'report' ? 'bg-white text-blue-600 shadow-md transform scale-100' : 'text-gray-500 hover:text-gray-700 hover:bg-gray-200/50']"
                            >
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                                </svg>
                                <span>Reports</span>
                            </button>
                            <button
                                @click="switchView('missing-schedules')"
                                :class="['inline-flex items-center gap-2 px-4 py-2 text-sm font-semibold transition-all duration-200 rounded-lg', currentView === 'missing-schedules' ? 'bg-white text-blue-600 shadow-md transform scale-100' : 'text-gray-500 hover:text-gray-700 hover:bg-gray-200/50']"
                            >
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                <span>Missing</span>
                            </button>
                            <button
                                @click="switchView('complete-schedules')"
                                :class="['inline-flex items-center gap-2 px-4 py-2 text-sm font-semibold transition-all duration-200 rounded-lg', currentView === 'complete-schedules' ? 'bg-white text-blue-600 shadow-md transform scale-100' : 'text-gray-500 hover:text-gray-700 hover:bg-gray-200/50']"
                            >
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                <span>Complete</span>
                            </button>
                        </div>

                        <!-- Action Buttons Group -->
                        <div class="flex flex-wrap items-center gap-3">
                            <button
                                v-if="currentView !== 'complete-schedules'"
                                @click="exportPdf"
                                class="inline-flex items-center gap-2 px-4 py-2.5 bg-white border border-gray-200 rounded-xl text-sm font-bold text-gray-700 shadow-sm hover:bg-gray-50 hover:border-gray-300 transition-all duration-200 group"
                            >
                                <svg class="w-4 h-4 text-red-500 group-hover:scale-110 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                                </svg>
                                <span>{{ currentView === 'report' ? 'Export Report' : (currentView === 'missing-schedules' ? 'Export Missing' : 'Export PDF') }}</span>
                            </button>

                            <div class="h-8 w-[1px] bg-gray-200 mx-1 hidden lg:block"></div>

                            <button
                                v-if="hasPermission('schedules.create')"
                                @click="openImportModal"
                                class="inline-flex items-center gap-2 px-4 py-2.5 bg-emerald-50 text-emerald-700 border border-emerald-100 rounded-xl text-sm font-bold hover:bg-emerald-100 hover:border-emerald-200 transition-all duration-200"
                            >
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
                                </svg>
                                <span>Import</span>
                            </button>

                            <button
                                v-if="hasPermission('schedules.create')"
                                @click="openCreateModal"
                                class="inline-flex items-center gap-2 px-5 py-2.5 bg-blue-600 text-white rounded-xl text-sm font-bold shadow-lg shadow-blue-200 hover:bg-blue-700 hover:shadow-blue-300 transform active:scale-95 transition-all duration-200"
                            >
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                                </svg>
                                <span>New Schedule</span>
                            </button>

                            <button
                                v-if="hasPermission('schedules.delete')"
                                @click="openDuplicateModal"
                                class="p-2.5 text-red-500 hover:bg-red-50 rounded-xl transition-colors"
                                title="Find Duplicates"
                            >
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h8m-8 4h8m-8 4h5M5 5a2 2 0 012-2h7l5 5v11a2 2 0 01-2 2H7a2 2 0 01-2-2V5z" />
                                </svg>
                            </button>
                        </div>
                    </div>

                    <!-- Filter Bar -->
                    <div class="flex flex-wrap items-center gap-4 bg-white p-4 rounded-2xl border border-gray-100 shadow-sm">
                        <div class="flex items-center gap-2 text-gray-400 mr-2">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z" />
                            </svg>
                            <span class="text-xs font-bold uppercase tracking-widest">Filters</span>
                        </div>

                        <!-- Sub-Unit filter -->
                        <div class="w-full sm:w-56" v-if="subUnitOptions.length > 1">
                            <Autocomplete
                                v-model="filterSubUnit"
                                :options="subUnitOptions"
                                label-key="name"
                                value-key="id"
                                placeholder="All Sub-Units"
                                @update:modelValue="applyFilter"
                                class="modern-autocomplete"
                            />
                        </div>

                        <!-- Store filter -->
                        <div class="w-full sm:w-64">
                            <Autocomplete
                                v-model="filterStore"
                                :options="storeOptions"
                                label-key="name"
                                value-key="id"
                                placeholder="All Stores"
                                @update:modelValue="applyFilter"
                                class="modern-autocomplete"
                            />
                        </div>

                        <!-- User filter -->
                        <div class="w-full sm:w-64" v-if="currentView === 'calendar' || currentView === 'missing-schedules' || currentView === 'complete-schedules'">
                            <Autocomplete
                                v-model="filterUser"
                                :options="userFilterOptions"
                                label-key="name"
                                value-key="id"
                                placeholder="Search user..."
                                @update:modelValue="applyFilter"
                                class="modern-autocomplete"
                            />
                        </div>

                        <!-- Date Range filter -->
                        <div v-if="currentView === 'missing-schedules' || currentView === 'complete-schedules'" class="flex items-center gap-2">
                            <div class="relative">
                                <input 
                                    v-model="visibleRange.start" 
                                    type="date" 
                                    @change="applyFilter"
                                    class="h-10 pl-3 pr-2 rounded-xl border-gray-200 bg-gray-50 text-sm font-bold text-gray-700 focus:ring-blue-500 focus:border-blue-500 border transition-all"
                                >
                            </div>
                            <span class="text-gray-400 font-bold">→</span>
                            <div class="relative">
                                <input 
                                    v-model="visibleRange.end" 
                                    type="date" 
                                    @change="applyFilter"
                                    class="h-10 pl-3 pr-2 rounded-xl border-gray-200 bg-gray-50 text-sm font-bold text-gray-700 focus:ring-blue-500 focus:border-blue-500 border transition-all"
                                >
                            </div>
                        </div>

                        <!-- Year compare (report only) -->
                        <div v-if="currentView === 'report'" class="flex items-center gap-3 ml-auto">
                            <span class="text-[10px] font-black text-gray-400 uppercase tracking-widest whitespace-nowrap">Compare:</span>
                            <div class="flex gap-1 bg-gray-100 p-1 rounded-lg">
                                <button
                                    v-for="year in availableYears"
                                    :key="year"
                                    @click="toggleYear(year)"
                                    :class="[
                                        'px-3 py-1 text-[10px] font-bold rounded-md transition-all duration-200',
                                        selectedReportYears.includes(year)
                                            ? 'bg-blue-600 text-white shadow-sm'
                                            : 'text-gray-500 hover:text-gray-700 hover:bg-gray-200'
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
                        :events="calendarSchedules"
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
                <div v-else-if="currentView === 'missing-schedules'" class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-visible">
                    <div class="sticky top-0 z-30 px-6 py-4 border-b border-gray-200 bg-gray-50 flex justify-between items-center rounded-t-xl">
                        <h3 class="text-lg font-bold text-gray-900">Missing Schedules ({{ visibleRange.start }} to {{ visibleRange.end }})</h3>
                        <span class="text-xs font-black text-gray-500 uppercase tracking-widest">Missing Days / Location / Actual Times</span>
                    </div>
                    <div class="overflow-x-auto custom-scrollbar">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-100">
                                <tr>
                                    <th class="sticky top-[61px] z-20 bg-gray-100 px-6 py-3 text-left text-[10px] font-black text-gray-500 uppercase tracking-widest">Sub-Unit</th>
                                    <th class="sticky top-[61px] z-20 bg-gray-100 px-6 py-3 text-left text-[10px] font-black text-gray-500 uppercase tracking-widest">Name</th>
                                    <th class="sticky top-[61px] z-20 bg-gray-100 px-6 py-3 text-left text-[10px] font-black text-gray-500 uppercase tracking-widest">Missing Days</th>
                                    <th class="sticky top-[61px] z-20 bg-gray-100 px-6 py-3 text-left text-[10px] font-black text-gray-500 uppercase tracking-widest">Missing Location</th>
                                    <th class="sticky top-[61px] z-20 bg-gray-100 px-6 py-3 text-left text-[10px] font-black text-gray-500 uppercase tracking-widest">Missing Actual Time In</th>
                                    <th class="sticky top-[61px] z-20 bg-gray-100 px-6 py-3 text-left text-[10px] font-black text-gray-500 uppercase tracking-widest">Missing Actual Time Out</th>
                                    <th class="sticky top-[61px] z-20 bg-gray-100 px-6 py-3 text-left text-[10px] font-black text-gray-500 uppercase tracking-widest">Count</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <tr v-if="isMissingSchedulesLoading">
                                    <td colspan="7" class="px-6 py-12 text-center">
                                        <div class="flex items-center justify-center gap-2 text-sm text-gray-500">
                                            <svg class="w-4 h-4 animate-spin text-blue-500" fill="none" viewBox="0 0 24 24">
                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 6.477 0 12h4z"/>
                                            </svg>
                                            Loading missing schedule records...
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
                                                <span v-if="!user.missing_days?.length" class="text-gray-300 text-xs">-</span>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 text-sm text-amber-700 font-medium max-w-md">
                                            <div class="flex flex-wrap gap-1">
                                                <span v-for="(day, i) in user.missing_locations" :key="i" class="bg-amber-50 px-1.5 py-0.5 rounded border border-amber-100 text-[10px] whitespace-nowrap">
                                                    {{ day }}
                                                </span>
                                                <span v-if="!user.missing_locations?.length" class="text-gray-300 text-xs">-</span>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 text-sm text-emerald-700 font-medium max-w-md">
                                            <div class="flex flex-wrap gap-1">
                                                <span v-for="(day, i) in user.missing_actual_time_ins" :key="i" class="bg-emerald-50 px-1.5 py-0.5 rounded border border-emerald-100 text-[10px] whitespace-nowrap">
                                                    {{ day }}
                                                </span>
                                                <span v-if="!user.missing_actual_time_ins?.length" class="text-gray-300 text-xs">-</span>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 text-sm text-orange-700 font-medium max-w-md">
                                            <div class="flex flex-wrap gap-1">
                                                <span v-for="(day, i) in user.missing_actual_time_outs" :key="i" class="bg-orange-50 px-1.5 py-0.5 rounded border border-orange-100 text-[10px] whitespace-nowrap">
                                                    {{ day }}
                                                </span>
                                                <span v-if="!user.missing_actual_time_outs?.length" class="text-gray-300 text-xs">-</span>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-black text-gray-700">{{ user.missing_total_count ?? user.missing_days_count }}</td>
                                    </tr>
                                    <tr v-if="missingSchedulesData.length === 0">
                                        <td colspan="7" class="px-6 py-12 text-center text-sm text-gray-500 italic">
                                            All users have schedules, locations, and actual times for this period.
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination Footer -->
                    <div v-if="missingSchedulesPagination.total > 0" class="px-6 py-4 bg-gray-50 border-t border-gray-200 flex items-center justify-between">
                        <div class="text-[10px] text-gray-400 font-black uppercase tracking-widest">
                            Showing {{ (missingSchedulesPagination.current_page - 1) * missingSchedulesPagination.per_page + 1 }} 
                            to {{ Math.min(missingSchedulesPagination.current_page * missingSchedulesPagination.per_page, missingSchedulesPagination.total) }} 
                            of {{ missingSchedulesPagination.total }}
                        </div>
                        <div class="flex items-center space-x-1">
                            <button 
                                @click="fetchMissingSchedulesData(missingSchedulesPagination.current_page - 1)"
                                :disabled="missingSchedulesPagination.current_page === 1"
                                class="p-2 bg-white border border-gray-200 rounded-lg text-gray-500 hover:bg-gray-50 disabled:opacity-30 disabled:cursor-not-allowed transition-all"
                            >
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                            </button>
                            
                            <div class="flex items-center space-x-1 mx-2">
                                <button 
                                    v-for="p in missingSchedulesPagination.last_page" 
                                    :key="p"
                                    @click="fetchMissingSchedulesData(p)"
                                    :class="[
                                        'w-8 h-8 rounded-lg text-xs font-bold transition-all',
                                        missingSchedulesPagination.current_page === p 
                                            ? 'bg-blue-600 text-white shadow-md' 
                                            : 'bg-white text-gray-600 border border-gray-200 hover:border-gray-300 hover:bg-gray-50'
                                    ]"
                                >
                                    {{ p }}
                                </button>
                            </div>

                            <button 
                                @click="fetchMissingSchedulesData(missingSchedulesPagination.current_page + 1)"
                                :disabled="missingSchedulesPagination.current_page === missingSchedulesPagination.last_page"
                                class="p-2 bg-white border border-gray-200 rounded-lg text-gray-500 hover:bg-gray-50 disabled:opacity-30 disabled:cursor-not-allowed transition-all"
                            >
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Complete Schedules View -->
                <div v-else-if="currentView === 'complete-schedules'" class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-200 bg-gray-50 flex justify-between items-center">
                        <h3 class="text-lg font-bold text-gray-900">Complete Schedules</h3>
                        <span class="text-xs font-black text-gray-500 uppercase tracking-widest">Full Coverage</span>
                    </div>
                    <div class="overflow-x-auto custom-scrollbar">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-100">
                                <tr>
                                    <th class="px-6 py-3 text-left text-[10px] font-black text-gray-500 uppercase tracking-widest">Sub-Unit</th>
                                    <th class="px-6 py-3 text-left text-[10px] font-black text-gray-500 uppercase tracking-widest">Name</th>
                                    <th class="px-6 py-3 text-left text-[10px] font-black text-gray-500 uppercase tracking-widest">Covered Days</th>
                                    <th class="px-6 py-3 text-left text-[10px] font-black text-gray-500 uppercase tracking-widest">Date Range</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <tr v-if="isCompleteSchedulesLoading">
                                    <td colspan="4" class="px-6 py-12 text-center">
                                        <div class="flex items-center justify-center gap-2 text-sm text-gray-500">
                                            <svg class="w-4 h-4 animate-spin text-blue-500" fill="none" viewBox="0 0 24 24">
                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 6.477 0 12h4z"/>
                                            </svg>
                                            Loading completed schedules...
                                        </div>
                                    </td>
                                </tr>
                                <template v-else>
                                    <tr v-for="user in completeSchedulesData" :key="user.id" class="hover:bg-gray-50 transition-colors">
                                        <td class="px-6 py-4 whitespace-nowrap text-xs font-bold text-gray-500">{{ user.sub_unit || '-' }}</td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-gray-900">{{ user.name }}</td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-black text-emerald-700">{{ user.covered_days_count }}</td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-700">{{ user.range_start }} to {{ user.range_end }}</td>
                                    </tr>
                                    <tr v-if="completeSchedulesData.length === 0">
                                        <td colspan="4" class="px-6 py-12 text-center text-sm text-gray-500 italic">
                                            No users have complete schedule coverage for this period.
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination Footer -->
                    <div v-if="completeSchedulesPagination.total > 0" class="px-6 py-4 bg-gray-50 border-t border-gray-200 flex items-center justify-between">
                        <div class="text-[10px] text-gray-400 font-black uppercase tracking-widest">
                            Showing {{ (completeSchedulesPagination.current_page - 1) * completeSchedulesPagination.per_page + 1 }} 
                            to {{ Math.min(completeSchedulesPagination.current_page * completeSchedulesPagination.per_page, completeSchedulesPagination.total) }} 
                            of {{ completeSchedulesPagination.total }}
                        </div>
                        <div class="flex items-center space-x-1">
                            <button 
                                @click="fetchCompleteSchedulesData(completeSchedulesPagination.current_page - 1)"
                                :disabled="completeSchedulesPagination.current_page === 1"
                                class="p-2 bg-white border border-gray-200 rounded-lg text-gray-500 hover:bg-gray-50 disabled:opacity-30 disabled:cursor-not-allowed transition-all"
                            >
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                            </button>
                            
                            <div class="flex items-center space-x-1 mx-2">
                                <button 
                                    v-for="p in completeSchedulesPagination.last_page" 
                                    :key="p"
                                    @click="fetchCompleteSchedulesData(p)"
                                    :class="[
                                        'w-8 h-8 rounded-lg text-xs font-bold transition-all',
                                        completeSchedulesPagination.current_page === p 
                                            ? 'bg-blue-600 text-white shadow-md' 
                                            : 'bg-white text-gray-600 border border-gray-200 hover:border-gray-300 hover:bg-gray-50'
                                    ]"
                                >
                                    {{ p }}
                                </button>
                            </div>

                            <button 
                                @click="fetchCompleteSchedulesData(completeSchedulesPagination.current_page + 1)"
                                :disabled="completeSchedulesPagination.current_page === completeSchedulesPagination.last_page"
                                class="p-2 bg-white border border-gray-200 rounded-lg text-gray-500 hover:bg-gray-50 disabled:opacity-30 disabled:cursor-not-allowed transition-all"
                            >
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Import Modal -->
        <div v-if="showImportModal" class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex items-center justify-center min-h-screen px-4 py-6">
                <div class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm" @click="closeImportModal"></div>
                <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-lg p-8 border border-gray-100 transform transition-all">
                    <div class="flex justify-between items-center mb-8">
                        <div>
                            <h3 class="text-xl font-bold text-gray-900">Import Schedules</h3>
                            <p class="text-xs font-medium text-gray-400 mt-1">Upload your excel template below</p>
                        </div>
                        <button @click="closeImportModal" class="p-2 text-gray-400 hover:text-gray-600 hover:bg-gray-100 rounded-xl transition-colors">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                    <div class="space-y-6">
                        <!-- Format description -->
                        <div class="rounded-xl bg-blue-50 border border-blue-100 p-4 text-xs text-blue-800 space-y-2">
                            <div class="flex items-center gap-2 mb-1">
                                <svg class="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                <p class="font-bold">Import Format Instructions</p>
                            </div>
                            <ul class="list-disc list-inside space-y-1 text-blue-700/80 ml-1">
                                <li><span class="font-bold text-blue-800">user_id</span> — Column A (numeric)</li>
                                <li><span class="font-bold text-blue-800">user_name</span> — Column B (reference)</li>
                                <li><span class="font-bold text-blue-800">Triples</span> — Status, Location, Remarks</li>
                                <li>Use <span class="font-bold text-blue-800">NA</span> for empty days</li>
                            </ul>
                        </div>

                        <!-- Template Download -->
                        <div class="rounded-xl border-2 border-dashed border-gray-200 bg-gray-50/50 p-5 space-y-4">
                            <div>
                                <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">1. Download Template</p>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">Start Date</label>
                                    <input
                                        v-model="importTemplateRange.start"
                                        type="date"
                                        class="block w-full rounded-lg border-gray-200 bg-white text-xs font-bold text-gray-700 focus:border-blue-500 focus:ring-blue-500"
                                    >
                                </div>
                                <div>
                                    <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">End Date</label>
                                    <input
                                        v-model="importTemplateRange.end"
                                        type="date"
                                        class="block w-full rounded-lg border-gray-200 bg-white text-xs font-bold text-gray-700 focus:border-blue-500 focus:ring-blue-500"
                                    >
                                </div>
                            </div>
                            <p v-if="importTemplateRangeError" class="text-[10px] font-bold text-red-600">{{ importTemplateRangeError }}</p>
                            <a
                                :href="canDownloadImportTemplate ? importTemplateUrl : '#'"
                                @click="handleTemplateDownloadClick"
                                :class="[
                                    'flex items-center p-3 bg-white border border-gray-200 rounded-xl transition-all group',
                                    canDownloadImportTemplate ? 'hover:border-blue-300 hover:shadow-sm' : 'opacity-60 cursor-not-allowed'
                                ]"
                            >
                                <div class="h-10 w-10 bg-blue-50 group-hover:bg-blue-100 rounded-lg flex items-center justify-center flex-shrink-0 transition-colors">
                                    <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                    </svg>
                                </div>
                                <div class="ml-4 overflow-hidden">
                                    <div class="text-sm font-bold text-gray-900 truncate">Schedules Template</div>
                                    <div class="text-[10px] text-gray-400 font-bold uppercase tracking-wider">Range: {{ importTemplateRange.start }} to {{ importTemplateRange.end }}</div>
                                </div>
                            </a>
                        </div>

                        <!-- File Upload -->
                        <div class="space-y-3">
                            <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest ml-1">2. Upload File</p>
                            <div v-if="!importFile"
                                 @click="importFileInput.click()"
                                 class="rounded-xl border-2 border-dashed border-gray-200 bg-gray-50/50 hover:bg-gray-50 hover:border-blue-300 p-8 text-center cursor-pointer transition-all group">
                                <div class="w-12 h-12 bg-white rounded-2xl shadow-sm border border-gray-100 flex items-center justify-center mx-auto mb-3 group-hover:scale-110 transition-transform">
                                    <svg class="w-6 h-6 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
                                    </svg>
                                </div>
                                <p class="text-sm text-gray-900 font-bold">Choose Excel file</p>
                                <p class="text-xs text-gray-400 mt-1">XLSX format up to 5MB</p>
                            </div>
                            <div v-else class="flex items-center justify-between p-4 bg-emerald-50 border border-emerald-100 rounded-xl">
                                <div class="flex items-center space-x-3 min-w-0">
                                    <div class="h-8 w-8 bg-emerald-100 rounded-lg flex items-center justify-center flex-shrink-0">
                                        <svg class="w-4 h-4 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
                                    </div>
                                    <span class="text-sm font-bold text-emerald-900 truncate">{{ importFile.name }}</span>
                                </div>
                                <button @click="removeImportFile" type="button" class="p-1.5 text-emerald-400 hover:text-red-500 hover:bg-white rounded-lg transition-colors">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                                </button>
                            </div>
                            <input ref="importFileInput" type="file" accept=".xlsx" class="hidden" @change="handleImportFileSelect">
                        </div>

                        <!-- Import Result -->
                        <div v-if="importResult" class="rounded-xl p-4 animate-in fade-in slide-in-from-top-2 duration-300" :class="(importResult.errors?.length || 0) === 0 ? 'bg-emerald-50 border border-emerald-100' : 'bg-amber-50 border border-amber-100'">
                            <div class="flex items-center space-x-2 mb-2">
                                <div :class="['p-1 rounded-full', (importResult.errors?.length || 0) === 0 ? 'bg-emerald-200' : 'bg-amber-200']">
                                    <svg v-if="(importResult.errors?.length || 0) === 0" class="w-3 h-3 text-emerald-700" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>
                                    <svg v-else class="w-3 h-3 text-amber-700" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" /></svg>
                                </div>
                                <span class="text-sm font-bold" :class="(importResult.errors?.length || 0) === 0 ? 'text-emerald-900' : 'text-amber-900'">
                                    {{ importResult.imported }} records imported
                                </span>
                            </div>
                            <ul v-if="importResult.errors?.length > 0" class="space-y-1 max-h-32 overflow-y-auto mt-2 pl-6 list-disc text-[10px] font-medium text-red-600/80">
                                <li v-for="(error, i) in importResult.errors" :key="i">{{ error }}</li>
                            </ul>
                        </div>
                    </div>

                    <div class="flex items-center justify-end gap-3 pt-8 mt-8 border-t border-gray-100">
                        <button type="button" @click="closeImportModal"
                                class="px-5 py-2.5 text-sm font-bold text-gray-500 hover:text-gray-700 hover:bg-gray-100 rounded-xl transition-all">
                            Cancel
                        </button>
                        <button type="button" @click="submitImport" :disabled="!importFile || isImporting"
                                class="inline-flex items-center gap-2 px-6 py-2.5 bg-blue-600 text-white text-sm font-bold rounded-xl shadow-lg shadow-blue-200 hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed transition-all">
                            <svg v-if="isImporting" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 22 6.477 22 12h-4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                            <span>{{ isImporting ? 'Processing...' : 'Start Import' }}</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Duplicate Cleanup Modal -->
        <div v-if="showDuplicateModal" class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex min-h-screen items-center justify-center px-4 py-6">
                <div class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm" @click="closeDuplicateModal"></div>
                <div class="relative w-full max-w-5xl rounded-2xl border border-gray-100 bg-white p-8 shadow-2xl overflow-hidden">
                    <div class="mb-8 flex items-start justify-between">
                        <div>
                            <h3 class="text-2xl font-bold text-gray-900">Duplicate Detection</h3>
                            <div class="flex items-center gap-2 mt-1">
                                <span class="text-xs font-black uppercase tracking-widest text-gray-400">Scanning Period:</span>
                                <span class="px-2 py-0.5 bg-blue-50 text-blue-700 rounded-md text-[10px] font-black">{{ visibleRange.start }} – {{ visibleRange.end }}</span>
                            </div>
                        </div>
                        <button @click="closeDuplicateModal" class="p-2 text-gray-400 hover:text-gray-600 hover:bg-gray-100 rounded-xl transition-colors">
                            <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <div v-if="isFindingDuplicates" class="flex flex-col items-center justify-center py-20 bg-gray-50/50 rounded-2xl border-2 border-dashed border-gray-100">
                        <div class="relative w-12 h-12 mb-4">
                            <div class="absolute inset-0 rounded-full border-4 border-blue-100"></div>
                            <div class="absolute inset-0 rounded-full border-4 border-blue-500 border-t-transparent animate-spin"></div>
                        </div>
                        <p class="text-sm font-bold text-gray-500">Analyzing schedule database...</p>
                    </div>

                    <div v-else-if="!hasScannedDuplicates" class="flex flex-col items-center justify-center py-16 bg-gray-50/50 rounded-2xl border border-gray-100">
                        <div class="w-16 h-16 bg-white rounded-2xl shadow-sm border border-gray-100 flex items-center justify-center mb-4">
                            <svg class="w-8 h-8 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h8m-8 4h8m-8 4h5M5 5a2 2 0 012-2h7l5 5v11a2 2 0 01-2 2H7a2 2 0 01-2-2V5z" />
                            </svg>
                        </div>
                        <p class="text-lg font-bold text-gray-900">Ready to scan</p>
                        <p class="text-sm font-medium text-gray-500 mt-1">No duplicate check has been run for this period yet.</p>
                    </div>

                    <div v-else-if="duplicateGroups.length === 0" class="flex flex-col items-center justify-center py-16 bg-emerald-50/50 rounded-2xl border border-emerald-100">
                        <div class="w-16 h-16 bg-white rounded-2xl shadow-sm border border-emerald-100 flex items-center justify-center mb-4">
                            <svg class="w-8 h-8 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        </div>
                        <p class="text-lg font-bold text-emerald-900">All Clear!</p>
                        <p class="text-sm font-medium text-emerald-600/80 mt-1">No duplicate schedules found in the selected period.</p>
                        <p v-if="duplicateCleanupResult" class="mt-4 px-4 py-2 bg-emerald-100 text-emerald-800 rounded-xl text-xs font-bold">
                            Cleanup Successful: Removed {{ duplicateCleanupResult.deleted_schedule_stores }} records.
                        </p>
                    </div>

                    <div v-else class="space-y-6">
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                            <div class="bg-white rounded-2xl border border-gray-100 p-5 shadow-sm hover:shadow-md transition-shadow">
                                <div class="text-[10px] font-black uppercase tracking-widest text-gray-400 mb-2">Impacted Users</div>
                                <div class="text-3xl font-black text-gray-900">{{ duplicateSummary.groupCount }}</div>
                            </div>
                            <div class="bg-white rounded-2xl border border-gray-100 p-5 shadow-sm hover:shadow-md transition-shadow">
                                <div class="text-[10px] font-black uppercase tracking-widest text-gray-400 mb-2">Total Duplicates</div>
                                <div class="text-3xl font-black text-red-500">{{ duplicateSummary.duplicateCount }}</div>
                            </div>
                            <div class="bg-white rounded-2xl border border-gray-100 p-5 shadow-sm hover:shadow-md transition-shadow">
                                <div class="text-[10px] font-black uppercase tracking-widest text-gray-400 mb-2">Linked Logs</div>
                                <div class="text-3xl font-black text-blue-600">{{ duplicateSummary.attendanceLogCount }}</div>
                            </div>
                        </div>

                        <div class="max-h-[50vh] space-y-4 overflow-y-auto pr-3 custom-scrollbar">
                            <div v-for="group in duplicateGroups" :key="group.key" class="bg-white rounded-2xl border border-gray-100 p-5 shadow-sm">
                                <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between mb-4">
                                    <div class="flex items-center gap-3">
                                        <div class="h-10 w-10 bg-gray-100 rounded-xl flex items-center justify-center text-gray-500 font-bold">
                                            {{ group.user_name.charAt(0) }}
                                        </div>
                                        <div>
                                            <div class="flex items-center gap-2">
                                                <span class="text-base font-bold text-gray-900">{{ group.user_name }}</span>
                                                <span class="px-2 py-0.5 bg-red-50 text-red-700 rounded-md text-[10px] font-bold uppercase">{{ group.duplicate_count }} Duplicates</span>
                                            </div>
                                            <p class="text-xs font-medium text-gray-400 mt-0.5">
                                                {{ group.store_name || 'Generic Location' }} • {{ formatDateTime(group.start_time) }}
                                            </p>
                                        </div>
                                    </div>
                                    <div class="text-[10px] font-black text-gray-300 uppercase tracking-widest">
                                        {{ group.total_count }} Total Records
                                    </div>
                                </div>

                                <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                    <div
                                        v-for="row in group.rows"
                                        :key="row.row_key"
                                        class="group flex items-center justify-between gap-3 rounded-xl border p-3 transition-all"
                                        :class="row.action === 'keep' ? 'border-emerald-100 bg-emerald-50/50 text-emerald-900' : 'border-red-100 bg-red-50/30 text-red-900 hover:bg-red-50'"
                                    >
                                        <div class="min-w-0">
                                            <div class="text-[9px] font-black uppercase tracking-tighter opacity-50 mb-0.5">ID: #{{ row.schedule_id }}</div>
                                            <div class="text-[11px] font-bold truncate">
                                                {{ row.store_name || 'No location' }}{{ row.ticket_key ? ' • Ticket ' + row.ticket_key : '' }}
                                            </div>
                                        </div>
                                        <span class="shrink-0 px-2 py-1 rounded-lg text-[9px] font-black uppercase tracking-widest bg-white shadow-sm" :class="row.action === 'keep' ? 'text-emerald-600' : 'text-red-500'">
                                            {{ row.action }}
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="mt-10 flex flex-col gap-4 border-t border-gray-100 pt-6 sm:flex-row sm:items-center sm:justify-between">
                        <div class="flex items-center gap-2 text-gray-400">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            <p class="text-xs font-medium">Rows with verified attendance logs are prioritized for keeping.</p>
                        </div>
                        <div class="flex justify-end gap-3">
                            <button type="button" @click="closeDuplicateModal" class="px-5 py-2.5 text-sm font-bold text-gray-500 hover:text-gray-700 hover:bg-gray-100 rounded-xl transition-all">
                                Cancel
                            </button>
                            <button type="button" @click="fetchDuplicateSchedules" :disabled="isFindingDuplicates || isDeletingDuplicates" class="px-5 py-2.5 bg-white border border-gray-200 text-sm font-bold text-gray-700 rounded-xl hover:bg-gray-50 hover:border-gray-300 transition-all">
                                {{ hasScannedDuplicates ? 'Refresh Scan' : 'Find Duplicates' }}
                            </button>
                            <button v-if="duplicateGroups.length" type="button" @click="deleteDuplicateSchedules" :disabled="isDeletingDuplicates" class="px-6 py-2.5 bg-red-600 text-white text-sm font-bold rounded-xl shadow-lg shadow-red-100 hover:bg-red-700 hover:shadow-red-200 transform active:scale-95 transition-all">
                                {{ isDeletingDuplicates ? 'Deleting...' : 'Cleanup Duplicates' }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Create/Edit Modal -->
        <div v-if="showModal" class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex min-h-screen items-center justify-center px-4 py-8">
                <div class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm" @click="closeModal"></div>
                <div class="relative w-full max-w-2xl rounded-2xl border border-gray-100 bg-white p-8 shadow-2xl overflow-hidden animate-in fade-in zoom-in-95 duration-200">
                    <div class="flex justify-between items-center mb-8">
                        <div>
                            <h3 class="text-2xl font-bold text-gray-900">
                                {{ isViewingOnly ? 'Schedule Details' : (isEditing ? 'Edit Schedule' : 'New Schedule Entry') }}
                            </h3>
                            <p class="text-xs font-medium text-gray-400 mt-1">Management and planning workspace</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <button 
                                v-if="isViewingOnly && canEditSchedule"
                                @click="isViewingOnly = false"
                                class="p-2.5 text-blue-600 hover:bg-blue-50 rounded-xl transition-all group"
                                title="Edit Schedule"
                            >
                                <svg class="w-5 h-5 group-hover:scale-110 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                </svg>
                            </button>
                            
                            <button @click="closeModal" class="p-2.5 text-gray-400 hover:text-gray-600 hover:bg-gray-100 rounded-xl transition-all">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>
                    </div>

                    <form @submit.prevent="submitForm" class="space-y-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2 ml-1">Team Member</label>
                                <template v-if="isManager && !isViewingOnly">
                                    <Autocomplete
                                        v-model="form.user_id"
                                        :options="subordinateUsers"
                                        label-key="name"
                                        value-key="id"
                                        placeholder="Select user..."
                                        class="modern-autocomplete"
                                    />
                                </template>
                                <template v-else>
                                    <div class="px-4 py-2.5 text-sm font-bold text-gray-700 bg-gray-50 rounded-xl border border-gray-100">
                                        {{ (props.users ?? []).find(u => Number(u.id) === Number(form.user_id))?.name || authUser.name }}
                                    </div>
                                </template>
                            </div>
                            <div>
                                <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2 ml-1">Duty Status</label>
                                <select v-model="form.status" required :disabled="isViewingOnly"
                                        class="w-full px-4 py-2.5 bg-white border border-gray-200 rounded-xl text-sm font-bold text-gray-700 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all disabled:bg-gray-50 disabled:text-gray-400">
                                    <option v-for="status in statuses" :key="status" :value="status">{{ status }}</option>
                                </select>
                            </div>
                        </div>

                        <!-- Store Entries Repeater -->
                        <div class="space-y-4">
                            <div class="flex items-center justify-between px-1">
                                <label class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Deployment Plan</label>
                                <button v-if="!isViewingOnly" type="button" @click="addStore"
                                        class="inline-flex items-center gap-1.5 text-xs font-bold text-blue-600 hover:text-blue-800 transition-colors">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
                                    Add Location
                                </button>
                            </div>

                            <div class="space-y-4 max-h-[35vh] overflow-y-auto pr-2 custom-scrollbar">
                                <div v-for="(entry, index) in form.stores" :key="index"
                                     class="relative p-5 bg-slate-50/50 rounded-2xl border border-slate-100/50 group/item transition-all hover:bg-slate-50 hover:border-slate-200">
                                    
                                    <!-- Remove button -->
                                    <button v-if="!isViewingOnly && form.stores.length > 1" type="button" @click="removeStore(index)"
                                            class="absolute -top-2 -right-2 p-1.5 bg-white text-red-400 hover:text-red-600 rounded-lg shadow-sm border border-gray-100 transition-all transform scale-0 group-hover/item:scale-100" title="Remove">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                    </button>

                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                                        <div class="md:col-span-2">
                                            <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1.5 ml-1">Location{{ isLocationRequired ? '' : ' (Optional)' }}</label>
                                            <Autocomplete
                                                v-model="entry.store_id"
                                                :options="storeSelectOptions"
                                                label-key="name"
                                                value-key="id"
                                                :placeholder="isLocationRequired ? 'Search Location...' : 'Select if applicable...'"
                                                :disabled="isViewingOnly"
                                                class="modern-autocomplete"
                                            />
                                        </div>
                                        <div>
                                            <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1.5 ml-1">Start Time</label>
                                            <input v-model="entry.start_time" type="datetime-local" required :disabled="isViewingOnly"
                                                   class="w-full px-4 py-2.5 bg-white border border-gray-200 rounded-xl text-xs font-bold text-gray-700 focus:ring-blue-500 transition-all disabled:bg-gray-50/50">
                                        </div>
                                        <div>
                                            <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1.5 ml-1">End Time</label>
                                            <input v-model="entry.end_time" type="datetime-local" required :disabled="isViewingOnly"
                                                   class="w-full px-4 py-2.5 bg-white border border-gray-200 rounded-xl text-xs font-bold text-gray-700 focus:ring-blue-500 transition-all disabled:bg-gray-50/50">
                                        </div>
                                    </div>

                                    <!-- Segment Footer: Actual Times & Remarks -->
                                    <div class="space-y-3 pt-3 border-t border-slate-200/50">
                                        <div v-if="entry.ticket || (isEditing && (entry.actual_time_in || entry.actual_time_out))"
                                             class="flex flex-wrap items-center gap-4 bg-white/80 p-3 rounded-xl border border-white shadow-sm">
                                            
                                            <div v-if="entry.ticket" class="flex items-center gap-2 pr-4 border-r border-gray-100">
                                                <div class="h-6 w-6 bg-blue-50 text-blue-600 rounded-lg flex items-center justify-center">
                                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 5v2m0 4v2m0 4v2M5 5a2 2 0 012-2h10a2 2 0 012 2v14a2 2 0 01-2 2H7a2 2 0 01-2-2V5z" /></svg>
                                                </div>
                                                <Link :href="route('tickets.edit', entry.ticket.id)" class="text-xs font-black text-blue-600 hover:underline">#{{ entry.ticket.ticket_key }}</Link>
                                            </div>

                                            <div class="flex gap-4 text-[10px] font-black">
                                                <span v-if="entry.actual_time_in" class="text-emerald-600 uppercase tracking-tighter">In: {{ formatTime(entry.actual_time_in) }}</span>
                                                <span v-if="entry.actual_time_out" class="text-orange-500 uppercase tracking-tighter">Out: {{ formatTime(entry.actual_time_out) }}</span>
                                            </div>
                                        </div>

                                        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                                            <div>
                                                <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1 ml-1">Grace (m)</label>
                                                <input v-model.number="entry.grace_period_minutes" type="number" min="0" :disabled="isViewingOnly"
                                                       class="w-full px-3 py-2 bg-white border border-gray-200 rounded-xl text-xs font-bold text-gray-700 focus:ring-blue-500">
                                            </div>
                                            <div class="md:col-span-3">
                                                <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1 ml-1">Activities / Remarks</label>
                                                <input v-model="entry.remarks" :disabled="isViewingOnly"
                                                       class="w-full px-3 py-2 bg-white border border-gray-200 rounded-xl text-xs font-bold text-gray-700 focus:ring-blue-500"
                                                       placeholder="What needs to be done here?">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="bg-gray-50 rounded-2xl p-5 border border-gray-100">
                            <h4 class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-4">Operations Buffer</h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div class="space-y-2">
                                    <label class="text-[10px] font-black text-gray-500 uppercase tracking-tighter ml-1">Inventory / Pickup Window</label>
                                    <div class="flex items-center gap-2">
                                        <input v-model="form.pickup_start" type="time" :disabled="isViewingOnly" class="flex-1 px-3 py-2 bg-white border border-gray-200 rounded-xl text-xs font-bold text-gray-700">
                                        <span class="text-gray-300">→</span>
                                        <input v-model="form.pickup_end" type="time" :disabled="isViewingOnly" class="flex-1 px-3 py-2 bg-white border border-gray-200 rounded-xl text-xs font-bold text-gray-700">
                                    </div>
                                </div>
                                <div class="space-y-2">
                                    <label class="text-[10px] font-black text-gray-500 uppercase tracking-tighter ml-1">Documentation / Backlogs Window</label>
                                    <div class="flex items-center gap-2">
                                        <input v-model="form.backlogs_start" type="time" :disabled="isViewingOnly" class="flex-1 px-3 py-2 bg-white border border-gray-200 rounded-xl text-xs font-bold text-gray-700">
                                        <span class="text-gray-300">→</span>
                                        <input v-model="form.backlogs_end" type="time" :disabled="isViewingOnly" class="flex-1 px-3 py-2 bg-white border border-gray-200 rounded-xl text-xs font-bold text-gray-700">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Audit Footer -->
                        <div v-if="isEditing" class="grid grid-cols-2 md:grid-cols-4 gap-3">
                            <div class="p-3 bg-white border border-gray-100 rounded-xl shadow-inner-sm">
                                <div class="text-[8px] font-black text-gray-400 uppercase tracking-widest">Creator</div>
                                <div class="text-[10px] font-bold text-gray-600 truncate">{{ modalAudit.createdBy }}</div>
                            </div>
                            <div class="p-3 bg-white border border-gray-100 rounded-xl shadow-inner-sm">
                                <div class="text-[8px] font-black text-gray-400 uppercase tracking-widest">Date</div>
                                <div class="text-[10px] font-bold text-gray-600 truncate">{{ modalAudit.createdAt }}</div>
                            </div>
                            <div class="p-3 bg-white border border-gray-100 rounded-xl shadow-inner-sm">
                                <div class="text-[8px] font-black text-gray-400 uppercase tracking-widest">Modifier</div>
                                <div class="text-[10px] font-bold text-gray-600 truncate">{{ modalAudit.updatedBy }}</div>
                            </div>
                            <div class="p-3 bg-white border border-gray-100 rounded-xl shadow-inner-sm">
                                <div class="text-[8px] font-black text-gray-400 uppercase tracking-widest">Modified</div>
                                <div class="text-[10px] font-bold text-gray-600 truncate">{{ modalAudit.updatedAt }}</div>
                            </div>
                        </div>

                        <div class="flex items-center justify-between pt-8 border-t border-gray-100 mt-8">
                            <button
                                v-if="canDeleteSchedule"
                                type="button"
                                @click="deleteSchedule"
                                :disabled="isDeletingSchedule"
                                class="inline-flex items-center gap-2 px-5 py-2.5 text-red-600 hover:bg-red-50 rounded-xl text-sm font-bold transition-all"
                            >
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                                <span>Delete</span>
                            </button>
                            <div v-else></div>

                            <div class="flex gap-3">
                                <button type="button" @click="closeModal"
                                        class="px-5 py-2.5 text-sm font-bold text-gray-500 hover:text-gray-700 hover:bg-gray-100 rounded-xl transition-all">
                                    {{ isViewingOnly ? 'Close' : 'Cancel' }}
                                </button>
                                <button v-if="!isViewingOnly" type="submit"
                                        class="px-8 py-2.5 bg-blue-600 text-white text-sm font-bold rounded-xl shadow-lg shadow-blue-200 hover:bg-blue-700 transition-all transform active:scale-95">
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

const getScheduleDateKey = (value) => {
    if (!value) return null

    return new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Manila' }).format(new Date(value))
}

const parseScheduleDateKey = (key) => {
    const [year, month, day] = key.split('-').map(Number)
    return new Date(year, month - 1, day)
}

const getScheduleDateKeysBetween = (startValue, endValue) => {
    const startKey = getScheduleDateKey(startValue)
    const endKey = getScheduleDateKey(endValue)

    if (!startKey || !endKey) return []

    const keys = []
    const cursor = parseScheduleDateKey(startKey)
    const end = parseScheduleDateKey(endKey)

    while (cursor <= end) {
        keys.push(formatDateParam(cursor))
        cursor.setDate(cursor.getDate() + 1)
    }

    return keys
}

const getTopPriorityTicket = (segments, fallbackTicket = null) => {
    const tickets = segments
        .map(segment => segment.ticket)
        .filter(Boolean)

    if (!tickets.length) return fallbackTicket

    const priorityRank = { urgent: 1, high: 2, medium: 3, low: 4 }

    return [...tickets].sort((a, b) => {
        const rankA = priorityRank[String(a.priority || '').toLowerCase()] ?? 5
        const rankB = priorityRank[String(b.priority || '').toLowerCase()] ?? 5
        return rankA - rankB
    })[0]
}

const getActualTimesForScheduleDate = (source, dateKey) => {
    if (!source || !dateKey) {
        return { actual_time_in: null, actual_time_out: null }
    }

    const dateActualTimes = source.actual_times_by_date?.[dateKey]

    if (dateActualTimes) {
        return {
            actual_time_in: dateActualTimes.actual_time_in ?? null,
            actual_time_out: dateActualTimes.actual_time_out ?? null,
        }
    }

    return {
        actual_time_in: source.actual_time_in && getScheduleDateKey(source.actual_time_in) === dateKey ? source.actual_time_in : null,
        actual_time_out: source.actual_time_out && getScheduleDateKey(source.actual_time_out) === dateKey ? source.actual_time_out : null,
    }
}

const getActualTimesForSegmentDate = (segments, dateKey, fallback = null) => {
    const dailyTimes = { actual_time_in: null, actual_time_out: null }

    for (const segment of segments) {
        const segmentTimes = getActualTimesForScheduleDate(segment, dateKey)
        dailyTimes.actual_time_in = dailyTimes.actual_time_in || segmentTimes.actual_time_in
        dailyTimes.actual_time_out = segmentTimes.actual_time_out || dailyTimes.actual_time_out
    }

    const fallbackTimes = getActualTimesForScheduleDate(fallback, dateKey)
    const exactTimes = {
        actual_time_in: dailyTimes.actual_time_in || fallbackTimes.actual_time_in,
        actual_time_out: dailyTimes.actual_time_out || fallbackTimes.actual_time_out,
    }

    return exactTimes
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
    props.filters?.status ? (Array.isArray(props.filters.status) ? props.filters.status : [props.filters.status]) : ['On-site', 'Off-site', 'WFH', 'SL', 'VL', 'Restday', 'Holiday', 'Offset', 'N/A'],
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

const calendarSchedules = computed(() => {
    return (props.schedules ?? []).flatMap(schedule => {
        const stores = Array.isArray(schedule.schedule_stores) ? schedule.schedule_stores : []

        if (!stores.length) {
            return [schedule]
        }

        const displaySegments = filterStore.value
            ? stores.filter(segment => String(segment.store_id ?? '') === String(filterStore.value))
            : stores

        if (!displaySegments.length) {
            return []
        }

        const segmentsByDate = new Map()

        for (const segment of displaySegments) {
            for (const dateKey of getScheduleDateKeysBetween(segment.start_time, segment.end_time)) {
                if (!segmentsByDate.has(dateKey)) {
                    segmentsByDate.set(dateKey, [])
                }

                segmentsByDate.get(dateKey).push(segment)
            }
        }

        if (!segmentsByDate.size) {
            return [schedule]
        }

        return [...segmentsByDate.entries()].map(([dateKey, segments]) => {
            const sortedSegments = [...segments].sort((a, b) => new Date(a.start_time) - new Date(b.start_time))
            const firstSegment = sortedSegments[0]
            const lastSegment = sortedSegments.reduce((latest, segment) => (
                new Date(segment.end_time) > new Date(latest.end_time) ? segment : latest
            ), sortedSegments[0])
            const actualTimes = getActualTimesForSegmentDate(sortedSegments, dateKey, schedule)

            return {
                ...schedule,
                start_time: firstSegment.start_time,
                end_time: lastSegment.end_time,
                store: firstSegment.store ?? schedule.store ?? null,
                ticket: getTopPriorityTicket(sortedSegments, schedule.ticket ?? null),
                actual_time_in: actualTimes.actual_time_in,
                actual_time_out: actualTimes.actual_time_out,
                actual_times_by_date: {
                    ...(schedule.actual_times_by_date ?? {}),
                    [dateKey]: actualTimes,
                },
                calendar_date_key: dateKey,
            }
        })
    })
})

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

// Complete schedules data
const completeSchedulesData = ref([])
const completeSchedulesPagination = ref({
    current_page: 1,
    last_page: 1,
    total: 0,
    per_page: 10
})
const isCompleteSchedulesLoading = ref(false)

const fetchCompleteSchedulesData = async (page = 1) => {
    if (isCompleteSchedulesLoading.value) return
    isCompleteSchedulesLoading.value = true
    try {
        const params = new URLSearchParams()
        params.set('start', visibleRange.value.start)
        params.set('end', visibleRange.value.end)
        params.set('page', page)
        if (filterSubUnit.value) params.set('sub_unit', filterSubUnit.value)
        if (filterUser.value)    params.set('user_id', filterUser.value)
        if (filterStore.value)   params.set('store_id', filterStore.value)

        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
        const res = await fetch(`/schedules/complete-schedules?${params}`, {
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken }
        })
        if (res.ok) {
            const result = await res.json()
            completeSchedulesData.value = result.data
            completeSchedulesPagination.value = {
                current_page: result.current_page,
                last_page: result.last_page,
                total: result.total,
                per_page: result.per_page
            }
        }
    } catch (e) {
        console.error('Failed to load complete schedules data', e)
    } finally {
        isCompleteSchedulesLoading.value = false
    }
}

const switchView = (view) => {
    currentView.value = view
    if (view === 'report') fetchPivotData()
    if (view === 'missing-schedules') fetchMissingSchedulesData()
    if (view === 'complete-schedules') fetchCompleteSchedulesData()
}

onMounted(() => {
    if (currentView.value === 'report') fetchPivotData()
    if (currentView.value === 'missing-schedules') fetchMissingSchedulesData()
    if (currentView.value === 'complete-schedules') fetchCompleteSchedulesData()
})

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
        ...(props.stores ?? []).map(s => ({ id: s.id, name: `${s.code} - ${s.name}` }))
    ]
})

const optionalScheduleLocationStatuses = new Set(['SL', 'VL', 'Restday', 'Holiday', 'N/A'])

// For the store repeater inside the form (no "All Stores" entry)
const storeSelectOptions = computed(() => {
    const storeChoices = (props.stores ?? []).map(s => ({ id: s.id, name: `${s.code} - ${s.name}` }))
    return isLocationRequired.value ? storeChoices : [{ id: null, name: 'No location' }, ...storeChoices]
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
    if (currentView.value === 'complete-schedules') {
        fetchCompleteSchedulesData()
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
            if (currentView.value === 'complete-schedules') {
                fetchCompleteSchedulesData()
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
const importTemplateRange = reactive({
    start: visibleRange.value.start,
    end: visibleRange.value.end,
})

const parseImportTemplateDate = (value) => {
    if (!value) return null

    const date = new Date(`${value}T00:00:00`)
    return Number.isNaN(date.getTime()) ? null : date
}

const importTemplateRangeDays = computed(() => {
    const start = parseImportTemplateDate(importTemplateRange.start)
    const end = parseImportTemplateDate(importTemplateRange.end)

    if (!start || !end || start > end) return 0

    return Math.floor((end - start) / 86400000) + 1
})

const importTemplateRangeError = computed(() => {
    const start = parseImportTemplateDate(importTemplateRange.start)
    const end = parseImportTemplateDate(importTemplateRange.end)

    if (!start || !end) return 'Select a start and end date.'
    if (start > end) return 'Start date must be on or before end date.'
    if (importTemplateRangeDays.value > 366) return 'Date range cannot exceed 366 days.'

    return ''
})

const canDownloadImportTemplate = computed(() => !importTemplateRangeError.value)

const importTemplateUrl = computed(() =>
    `/schedules/template?start=${encodeURIComponent(importTemplateRange.start)}&end=${encodeURIComponent(importTemplateRange.end)}`
)

const syncImportTemplateRange = () => {
    importTemplateRange.start = visibleRange.value.start
    importTemplateRange.end = visibleRange.value.end
}

const openImportModal = () => {
    syncImportTemplateRange()
    showImportModal.value = true
}

const handleTemplateDownloadClick = (event) => {
    if (canDownloadImportTemplate.value) return

    event.preventDefault()
    showError(importTemplateRangeError.value)
}

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

// Duplicate cleanup
const showDuplicateModal = ref(false)
const duplicateGroups = ref([])
const duplicateCleanupResult = ref(null)
const hasScannedDuplicates = ref(false)
const isFindingDuplicates = ref(false)
const isDeletingDuplicates = ref(false)

const duplicateSummary = computed(() => {
    return {
        groupCount: duplicateGroups.value.length,
        duplicateCount: duplicateGroups.value.reduce((sum, group) => sum + Number(group.duplicate_count || 0), 0),
        attendanceLogCount: duplicateGroups.value.reduce((sum, group) => sum + Number(group.attendance_log_count || 0), 0),
    }
})

const duplicateCleanupPayload = () => ({
    start: visibleRange.value.start,
    end: visibleRange.value.end,
    user_id: filterUser.value || null,
    sub_unit: filterSubUnit.value || null,
    store_id: filterStore.value || null,
})

const duplicateCleanupQuery = () => {
    const params = new URLSearchParams()
    const payload = duplicateCleanupPayload()

    Object.entries(payload).forEach(([key, value]) => {
        if (value !== null && value !== undefined && value !== '') {
            params.set(key, value)
        }
    })

    return params
}

const openDuplicateModal = () => {
    showDuplicateModal.value = true
    duplicateGroups.value = []
    duplicateCleanupResult.value = null
    hasScannedDuplicates.value = false
}

const closeDuplicateModal = () => {
    showDuplicateModal.value = false
    duplicateGroups.value = []
    duplicateCleanupResult.value = null
    hasScannedDuplicates.value = false
}

const fetchDuplicateSchedules = async () => {
    if (isFindingDuplicates.value) return

    isFindingDuplicates.value = true

    try {
        const response = await fetch(`${route('schedules.duplicates')}?${duplicateCleanupQuery()}`, {
            headers: { 'Accept': 'application/json' },
        })
        const result = await response.json()

        if (!response.ok) {
            showError(result?.message || 'Unable to scan duplicate schedules')
            return
        }

        duplicateGroups.value = result.groups || []
        hasScannedDuplicates.value = true
    } catch (error) {
        showError('Unable to scan duplicate schedules')
    } finally {
        isFindingDuplicates.value = false
    }
}

const deleteDuplicateSchedules = async () => {
    if (!duplicateGroups.value.length || isDeletingDuplicates.value) return

    const ok = await confirm({
        title: 'Delete Duplicate Schedules',
        message: `Delete ${duplicateSummary.value.duplicateCount} duplicate schedule location visit${duplicateSummary.value.duplicateCount === 1 ? '' : 's'}? This cannot be undone.`,
        confirmLabel: 'Delete Duplicates',
        variant: 'danger',
    })

    if (!ok) return

    isDeletingDuplicates.value = true

    try {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
        const response = await fetch(route('schedules.duplicates.destroy'), {
            method: 'DELETE',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
            },
            body: JSON.stringify(duplicateCleanupPayload()),
        })
        const result = await response.json()

        if (!response.ok) {
            showError(result?.message || 'Unable to delete duplicate schedules')
            return
        }

        duplicateCleanupResult.value = result
        showSuccess(`Deleted ${result.deleted_schedule_stores} duplicate location visit${result.deleted_schedule_stores === 1 ? '' : 's'}`)
        await fetchDuplicateSchedules()
        router.reload({ only: ['schedules', 'filters'], preserveState: true, preserveScroll: true })
    } catch (error) {
        showError('Unable to delete duplicate schedules')
    } finally {
        isDeletingDuplicates.value = false
    }
}

// Create / Edit
const showModal = ref(false)
const isEditing = ref(false)
const isViewingOnly = ref(false)
const isDeletingSchedule = ref(false)
const canEditSchedule = ref(false)
const currentScheduleId    = ref(null)
const currentActualTimeIn  = ref(null)
const currentActualTimeOut = ref(null)
const currentCreatedBy     = ref(null)
const currentCreatedAt     = ref(null)
const currentUpdatedBy     = ref(null)
const currentUpdatedAt     = ref(null)

const statuses = [
    'On-site', 'Off-site', 'WFH', 'SL', 'VL', 'Restday', 'Offset', 'Holiday', 'N/A'
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
    { status: 'N/A',      label: 'N/A',            color: 'bg-gray-400' },
]

const form = reactive({
    user_id: authUser.value?.id ?? null,
    status: 'On-site',
    stores: [{ store_id: null, ticket_id: null, start_time: '', end_time: '', grace_period_minutes: 30, remarks: '' }],
    scope_date: null,
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

const isLocationRequired = computed(() => !optionalScheduleLocationStatuses.has(form.status))

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
    return getActualTimesForScheduleDate(source, dateKey)
}

const isEntryOnDate = (entry, dateKey) => {
    if (!entry || !dateKey) return false

    const startKey = getManilaDateKey(entry.start_time)
    const endKey = getManilaDateKey(entry.end_time)

    return startKey === dateKey || endKey === dateKey
}

const resetScheduleModalState = () => {
    isEditing.value = false
    isViewingOnly.value = false
    isDeletingSchedule.value = false
    canEditSchedule.value = false
    currentScheduleId.value = null
    currentActualTimeIn.value = null
    currentActualTimeOut.value = null
    currentCreatedBy.value = null
    currentCreatedAt.value = null
    currentUpdatedBy.value = null
    currentUpdatedAt.value = null
    form.scope_date = null
}

const openCreateModal = () => {
    resetScheduleModalState()
    currentCreatedBy.value = authUser.value?.name || null
    currentCreatedAt.value = null
    currentUpdatedBy.value = authUser.value?.name || null
    currentUpdatedAt.value = null

    form.user_id = authUser.value?.id ?? null
    form.status = 'On-site'
    form.scope_date = null
    form.pickup_start = ''
    form.pickup_end = ''
    form.backlogs_start = ''
    form.backlogs_end = ''

    const now = new Date()
    const start = new Date(now)
    start.setHours(7, 0, 0, 0)
    const end = new Date(now)
    end.setHours(17, 0, 0, 0)
    form.stores = [{ store_id: null, ticket_id: null, start_time: formatDateForInput(start), end_time: formatDateForInput(end), grace_period_minutes: 30, remarks: '' }]

    showModal.value = true
}

const handleDateClick = (date) => {
    if (!hasPermission('schedules.create')) return

    openCreateModal()
    const start = new Date(date)
    start.setHours(7, 0, 0, 0)
    const end = new Date(date)
    end.setHours(17, 0, 0, 0)
    form.stores = [{ store_id: null, ticket_id: null, start_time: formatDateForInput(start), end_time: formatDateForInput(end), grace_period_minutes: 30, remarks: '' }]
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
    form.scope_date = null
    form.pickup_start = event.pickup_start || ''
    form.pickup_end = event.pickup_end || ''
    form.backlogs_start = event.backlogs_start || ''
    form.backlogs_end = event.backlogs_end || ''

    // Populate store entries from schedule_stores; fall back to legacy single store+time
    if (event.schedule_stores && event.schedule_stores.length > 0) {
        const dayStores = event.schedule_stores.filter(ss => isEntryOnDate(ss, clickedDateKey))
        const storesToDisplay = dayStores.length > 0 ? dayStores : event.schedule_stores
        form.scope_date = dayStores.length > 0 ? clickedDateKey : null
        const eventActualTimesFallback = storesToDisplay.length === 1
            ? eventActualTimes
            : { actual_time_in: null, actual_time_out: null }

        form.stores = storesToDisplay.map(ss => {
            const segmentActualTimes = getActualTimesForDate(ss, clickedDateKey)
            return {
                id: ss.id || null,
                store_id: ss.store_id,
                ticket_id: ss.ticket_id || ss.ticket?.id || null,
                start_time: formatDateForInput(new Date(ss.start_time)),
                end_time: formatDateForInput(new Date(ss.end_time)),
                grace_period_minutes: ss.grace_period_minutes ?? 30,
                remarks: ss.remarks || '',
                actual_time_in: segmentActualTimes.actual_time_in || eventActualTimesFallback.actual_time_in,
                actual_time_out: segmentActualTimes.actual_time_out || eventActualTimesFallback.actual_time_out,
                ticket: ss.ticket || null,
            }
        })
    } else {
        form.scope_date = null
        const scheduleActualTimes = getActualTimesForDate(event, clickedDateKey)
        form.stores = [{
            id: null,
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
    showModal.value = false
    resetScheduleModalState()
}

const canDeleteSchedule = computed(() => {
    return Boolean(currentScheduleId.value) && hasPermission('schedules.delete')
})

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
    if (!isLocationRequired.value) {
        return true
    }

    const missingStore = form.stores.some(entry => !entry.store_id)

    if (missingStore) {
        showError('Location is required for every schedule entry.')
        return false
    }

    return true
}

const submitForm = () => {
    if (!validateScheduleStores()) return

    const editingScheduleId = isEditing.value ? currentScheduleId.value : null
    const isUpdatingSchedule = Boolean(editingScheduleId)
    const url = isUpdatingSchedule ? `/schedules/${editingScheduleId}` : '/schedules'
    const requestMethod = isUpdatingSchedule ? put : post
    
    requestMethod(url, form, {
        onSuccess: () => {
            closeModal()
            showSuccess(isUpdatingSchedule ? 'Schedule updated successfully' : 'Schedule created successfully')
        },
        onError: (errors) => {
            const errorMessage = Object.values(errors).flat().join(', ') || 'An error occurred'
            showError(errorMessage)
        }
    })
}

const deleteSchedule = async () => {
    if (!canDeleteSchedule.value || isDeletingSchedule.value) return

    const ok = await confirm({
        title: 'Delete Schedule',
        message: 'Permanently delete this full schedule and all of its location visits? This cannot be undone.',
        confirmLabel: 'Delete',
        variant: 'danger',
    })

    if (!ok) return

    isDeletingSchedule.value = true

    destroy(`/schedules/${currentScheduleId.value}`, {
        preserveScroll: true,
        onSuccess: () => {
            closeModal()
            showSuccess('Schedule deleted successfully')
        },
        onError: (errors) => {
            const errorMessage = Object.values(errors).flat().join(', ') || 'Unable to delete schedule'
            showError(errorMessage)
        },
        onFinish: () => {
            isDeletingSchedule.value = false
        },
    })
}
</script>
