<template>
    <AppLayout title="Payments & Monitoring" content-class="w-full max-w-none px-2 sm:px-4 lg:px-6">
        <div class="py-6 space-y-6">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">Payments & Monitoring</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-300">Telco / connectivity monitoring by office & store, with cash schedule and approval workflow</p>
                </div>
            </div>

            <!-- Tabs -->
            <div class="border-b border-gray-200 dark:border-gray-700">
                <nav class="-mb-px flex space-x-6 overflow-x-auto">
                    <button
                        v-for="t in tabList"
                        :key="t.id"
                        @click="switchTab(t.id)"
                        :class="[
                            'whitespace-nowrap py-3 px-2 border-b-2 text-sm font-medium transition-colors',
                            currentTab === t.id
                                ? 'border-blue-600 text-blue-600'
                                : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
                        ]"
                    >
                        {{ t.label }}
                    </button>
                </nav>
            </div>

            <!-- ============ MONITORING TAB ============ -->
            <div v-if="currentTab === 'monitoring'" class="space-y-5">
                <!-- KPI strip -->
                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
                    <div class="bg-white rounded-xl border border-gray-100 p-4 shadow-sm dark:bg-gray-800 dark:border-gray-700">
                        <p class="text-[11px] font-bold text-gray-500 uppercase tracking-wider dark:text-gray-300">Monthly MRC</p>
                        <p class="text-xl font-bold text-gray-900 mt-1 dark:text-gray-100">₱{{ formatAmount(summary.monthly_mrc) }}</p>
                    </div>
                    <div class="bg-white rounded-xl border border-gray-100 p-4 shadow-sm dark:bg-gray-800 dark:border-gray-700">
                        <p class="text-[11px] font-bold text-gray-500 uppercase tracking-wider dark:text-gray-300">Annual MRC</p>
                        <p class="text-xl font-bold text-purple-600 mt-1">₱{{ formatAmount(summary.annual_mrc) }}</p>
                    </div>
                    <div class="bg-white rounded-xl border border-gray-100 p-4 shadow-sm dark:bg-gray-800 dark:border-gray-700">
                        <p class="text-[11px] font-bold text-gray-500 uppercase tracking-wider dark:text-gray-300">Active Services</p>
                        <p class="text-xl font-bold text-emerald-600 mt-1">{{ summary.active_services || 0 }}</p>
                    </div>
                    <div class="bg-white rounded-xl border border-gray-100 p-4 shadow-sm dark:bg-gray-800 dark:border-gray-700">
                        <p class="text-[11px] font-bold text-gray-500 uppercase tracking-wider dark:text-gray-300">Pending Installs</p>
                        <p class="text-xl font-bold text-orange-600 mt-1">{{ summary.pending_installs || 0 }}</p>
                    </div>
                    <div class="bg-white rounded-xl border border-gray-100 p-4 shadow-sm dark:bg-gray-800 dark:border-gray-700">
                        <p class="text-[11px] font-bold text-gray-500 uppercase tracking-wider dark:text-gray-300">Monitored Sites</p>
                        <p class="text-xl font-bold text-blue-600 mt-1">{{ summary.monitored_locations || 0 }}</p>
                    </div>
                    <button @click="switchTab('approvals')" class="bg-white rounded-xl border border-gray-100 p-4 shadow-sm text-left hover:border-yellow-200 hover:bg-yellow-50 transition-colors group dark:bg-gray-800 dark:border-gray-700">
                        <p class="text-[11px] font-bold text-gray-500 uppercase tracking-wider group-hover:text-yellow-600 dark:text-gray-300">Pending Approvals</p>
                        <p class="text-xl font-bold text-yellow-600 mt-1">{{ summary.pending_approvals || 0 }}</p>
                    </button>
                </div>

                <!-- Controls -->
                <div class="bg-white rounded-xl border border-gray-100 p-4 shadow-sm dark:bg-gray-800 dark:border-gray-700">
                    <div class="flex flex-col xl:flex-row xl:items-center xl:justify-between gap-3">
                        <div class="flex flex-wrap items-center gap-3">
                            <div class="inline-flex rounded-lg border border-gray-200 bg-gray-50 p-1 w-fit dark:bg-gray-900/50 dark:border-gray-700">
                                <button v-for="seg in locationTypes" :key="seg.id" @click="monitoringType = seg.id"
                                        :class="[
                                            'px-3 py-1.5 rounded-md text-sm font-medium transition-colors',
                                            monitoringType === seg.id ? 'bg-white text-blue-700 shadow-sm' : 'text-gray-600 hover:text-gray-900'
                                        ]">
                                    {{ seg.label }}
                                    <span class="ml-1 text-xs text-gray-400 dark:text-gray-400">{{ seg.id === 'office' ? officeCount : storeCount }}</span>
                                </button>
                            </div>
                            <select v-model="monitoringBrand" class="border-gray-300 rounded-lg text-sm pl-2 pr-7 dark:border-gray-600">
                                <option value="">All Brands</option>
                                <option v-for="b in brands" :key="b" :value="b">{{ b }}</option>
                            </select>
                            <input v-model="monitoringSearch" type="text" placeholder="Search code, name, address..."
                                   class="border-gray-300 rounded-lg text-sm w-56 dark:border-gray-600" />
                        </div>
                        <div class="flex items-center gap-2 flex-nowrap">
                            <a v-if="hasPermission('payments.view')" href="/payments/services/import-template"
                               class="bg-white hover:bg-gray-50 text-gray-700 border border-gray-300 px-4 py-2 rounded-lg text-sm font-medium shadow-sm whitespace-nowrap inline-flex items-center dark:bg-gray-800 dark:text-gray-300 dark:border-gray-600 dark:hover:bg-gray-700">
                                Download Template
                            </a>
                            <button v-if="hasPermission('payments.create')" @click="openConnectivityImportModal()"
                                    class="bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded-lg text-sm font-medium shadow-sm whitespace-nowrap inline-flex items-center">
                                Import
                            </button>
                            <button v-if="hasPermission('payments.create')" @click="openServiceModal(null)"
                                    class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium shadow-sm whitespace-nowrap inline-flex items-center">
                                + Add Service
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Brand groups -->
                <div v-if="groupedByBrand.length" class="space-y-4">
                    <div v-for="group in groupedByBrand" :key="group.brand" class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden dark:bg-gray-800 dark:border-gray-700">
                        <button @click="toggleBrand(group.brand)"
                                class="w-full flex items-center justify-between px-4 py-3 bg-gray-50 hover:bg-gray-100 transition-colors text-left dark:bg-gray-900/50 dark:hover:bg-gray-700">
                            <div class="flex items-center gap-3">
                                <svg class="w-4 h-4 text-gray-400 transition-transform dark:text-gray-400" :class="collapsedBrands[group.brand] ? '-rotate-90' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" /></svg>
                                <span class="font-bold text-gray-900 dark:text-gray-100">{{ group.brand }}</span>
                                <span class="text-xs text-gray-500 dark:text-gray-300">{{ group.locations.length }} {{ monitoringType === 'office' ? 'office(s)' : 'store(s)' }} · {{ group.serviceCount }} service(s)</span>
                            </div>
                            <span class="text-sm font-mono font-semibold text-gray-700 dark:text-gray-300">₱{{ formatAmount(group.mrc) }}/mo</span>
                        </button>

                        <div v-show="!collapsedBrands[group.brand]" class="divide-y divide-gray-100 dark:divide-gray-700">
                            <div v-for="loc in group.locations" :key="loc.id" class="p-4">
                                <!-- Location header -->
                                <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-2">
                                    <div class="min-w-0">
                                        <div class="flex items-center gap-2 flex-wrap">
                                            <span class="text-sm font-bold text-gray-900 dark:text-gray-100">{{ loc.code }}</span>
                                            <span class="text-sm text-gray-700 dark:text-gray-300">{{ loc.name }}</span>
                                            <span :class="loc.type === 'office' ? 'bg-indigo-100 text-indigo-800' : 'bg-sky-100 text-sky-800'" class="px-2 py-0.5 text-[10px] rounded-full font-semibold uppercase">{{ loc.type }}</span>
                                            <span v-if="loc.monitoring_status" :class="monitoringStatusPill(loc.monitoring_status)" class="px-2 py-0.5 text-[10px] rounded-full font-semibold uppercase">{{ loc.monitoring_status }}</span>
                                        </div>
                                        <div v-if="loc.address" class="text-xs text-gray-500 mt-0.5 dark:text-gray-300">{{ loc.address }}</div>
                                        <div v-if="loc.legal_company || loc.company_applied_with" class="text-xs text-gray-400 dark:text-gray-400">
                                            <span v-if="loc.legal_company">{{ loc.legal_company }}</span>
                                            <span v-if="loc.company_applied_with"> · applied with {{ loc.company_applied_with }}</span>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-1 shrink-0">
                                        <span class="text-xs font-mono text-gray-500 mr-1 dark:text-gray-300">₱{{ formatAmount(locationMrc(loc)) }}/mo</span>
                                        <IconBtn v-if="hasPermission('payments.edit')" kind="edit" title="Edit location details" @click="openLocationModal(loc)" />
                                        <button v-if="hasPermission('payments.create')" @click="openServiceModal(loc)" title="Add connectivity service"
                                                class="p-2 rounded-full text-blue-600 hover:text-blue-900 hover:bg-blue-50 transition-colors">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6m0 0v6m0-6h6m-6 0H6" /></svg>
                                        </button>
                                    </div>
                                </div>

                                <!-- Services table -->
                                <div v-if="loc.services.length" class="mt-3 overflow-x-auto border border-gray-100 rounded-lg dark:border-gray-700">
                                    <table class="min-w-full text-sm">
                                        <thead class="bg-gray-50 text-[11px] uppercase text-gray-500 dark:bg-gray-900/50 dark:text-slate-300">
                                            <tr>
                                                <th class="px-3 py-2 text-left">Role</th>
                                                <th class="px-3 py-2 text-left">Telco / Vendor</th>
                                                <th class="px-3 py-2 text-left">Account / Service ID</th>
                                                <th class="px-3 py-2 text-left">Bandwidth</th>
                                                <th class="px-3 py-2 text-right">MRC</th>
                                                <th class="px-3 py-2 text-left">Installed</th>
                                                <th class="px-3 py-2 text-left">Status</th>
                                                <th class="px-3 py-2 text-right">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                            <tr v-for="sv in loc.services" :key="sv.id" class="hover:bg-gray-50 dark:hover:bg-gray-700">
                                                <td class="px-3 py-2">
                                                    <span :class="sv.role === 'primary' ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-700'" class="px-2 py-0.5 text-[10px] rounded-full font-semibold uppercase">{{ sv.role }}</span>
                                                </td>
                                                <td class="px-3 py-2">
                                                    <div class="font-medium text-gray-900 dark:text-gray-100">{{ sv.telco || '—' }}</div>
                                                    <div class="text-xs text-gray-500 dark:text-gray-300">{{ sv.vendor?.name || 'No vendor' }}</div>
                                                </td>
                                                <td class="px-3 py-2">
                                                    <div>{{ sv.account_no || '—' }}</div>
                                                    <div v-if="sv.service_id" class="text-xs text-gray-500 dark:text-gray-300">SID {{ sv.service_id }}</div>
                                                </td>
                                                <td class="px-3 py-2">
                                                    <div>{{ sv.bandwidth || '—' }}</div>
                                                    <div v-if="sv.install_type" class="text-xs text-gray-500 capitalize dark:text-gray-300">{{ sv.install_type }}</div>
                                                </td>
                                                <td class="px-3 py-2 text-right font-mono">{{ formatAmount(sv.mrc) }}</td>
                                                <td class="px-3 py-2 text-xs">{{ sv.installation_date || '—' }}</td>
                                                <td class="px-3 py-2">
                                                    <span :class="statusPill(sv.status)" class="px-2 py-0.5 text-xs rounded-full font-semibold capitalize">{{ sv.status }}</span>
                                                    <div v-if="sv.latest_record_status" class="mt-1">
                                                        <span :class="approvalPill(sv.latest_record_status)" class="px-2 py-0.5 text-[10px] rounded-full font-bold uppercase tracking-wide">
                                                            {{ approvalLabel(sv.latest_record_status) }}
                                                        </span>
                                                    </div>
                                                    <div v-if="sv.last_reminder_sent_at" class="mt-1 flex items-center text-[10px] text-gray-400 dark:text-gray-400" :title="'Last reminder sent on ' + sv.last_reminder_sent_at">
                                                        <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" /></svg>
                                                        {{ formatDateShort(sv.last_reminder_sent_at) }}
                                                    </div>
                                                </td>
                                                <td class="px-3 py-2 text-right">
                                                    <div class="flex justify-end space-x-1">
                                                        <IconBtn kind="remind" title="Send Manual Reminder" @click="sendManualReminder('service', sv.id)" />
                                                        <IconBtn v-if="hasPermission('payments.submit') && !sv.latest_record_status" kind="submit" title="Submit for Approval" @click="openSubmitModal('service', sv)" />
                                                        <IconBtn v-if="sv.latest_record_status === 'approved' && hasPermission('payments.mark_paid')" kind="paid" title="Mark as Paid" @click="openMarkPaidForPayable('service', sv.id)" />
                                                        <IconBtn v-if="hasPermission('payments.edit') && sv.latest_record_status !== 'approved'" kind="edit" title="Edit Service" @click="openServiceModal(loc, sv)" />
                                                        <IconBtn v-if="hasPermission('payments.delete') && !sv.latest_record_status" kind="delete" title="Delete Service" @click="confirmDelete('services', sv, 'connectivity service')" />
                                                    </div>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                                <div v-else class="mt-3 text-xs text-gray-400 italic border border-dashed border-gray-200 rounded-lg py-3 text-center dark:text-gray-400 dark:border-gray-700">
                                    No connectivity service yet.
                                    <button v-if="hasPermission('payments.create')" @click="openServiceModal(loc)" class="text-blue-600 hover:text-blue-800 font-medium not-italic">Add one</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div v-else class="bg-white rounded-xl border border-dashed border-gray-300 py-12 text-center text-gray-400 dark:bg-gray-800 dark:text-gray-400 dark:border-gray-600">
                    No {{ monitoringType === 'office' ? 'offices' : 'stores' }} match the current filters.
                </div>
            </div>

            <!-- ============ SCHEDULE TAB ============ -->
            <div v-if="currentTab === 'schedule'" class="space-y-4">
                <div class="bg-white rounded-lg border border-gray-200 shadow-sm dark:bg-gray-800 dark:border-gray-700">
                    <div class="px-4 sm:px-6 py-4 border-b border-gray-200 bg-gray-50 dark:bg-gray-900/50 dark:border-gray-700">
                        <div class="flex flex-col xl:flex-row xl:items-center xl:justify-between gap-4">
                            <div>
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Cash Schedule</h3>
                                <p class="text-sm text-gray-600 dark:text-gray-300">Monthly, weekly, and calendar view of payable due dates</p>
                            </div>
                            <div class="flex flex-col sm:flex-row gap-2">
                                <input v-model="cashFilters.month" @change="refreshCashSchedule" type="month"
                                       class="border-gray-300 rounded-lg text-sm dark:border-gray-600">
                                <select v-model="cashFilters.vendor_id" @change="refreshCashSchedule"
                                        class="border-gray-300 rounded-lg text-sm pl-2 pr-7 dark:border-gray-600">
                                    <option value="">All vendors</option>
                                    <option v-for="vendor in vendors" :key="vendor.id" :value="vendor.id">{{ vendor.name }}</option>
                                </select>
                                <select v-model="cashFilters.source" @change="refreshCashSchedule"
                                        class="border-gray-300 rounded-lg text-sm pl-2 pr-7 dark:border-gray-600">
                                    <option value="all">All sources</option>
                                    <option value="service">Connectivity (Telco)</option>
                                    <option value="invoice">SOA Invoices</option>
                                    <option value="renewal">Renewals</option>
                                    <option value="weekly">Weekly Plans</option>
                                </select>
                                <label class="inline-flex items-center gap-2 px-3 py-2 border border-gray-300 rounded-lg text-sm bg-white dark:bg-gray-800 dark:border-gray-600">
                                    <input v-model="cashFilters.include_paid" @change="refreshCashSchedule" type="checkbox"
                                           class="rounded border-gray-300 text-blue-600 dark:border-gray-600">
                                    Include Paid
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="px-4 sm:px-6 py-4 space-y-4">
                        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                            <div class="inline-flex rounded-lg border border-gray-200 bg-gray-50 p-1 w-fit dark:bg-gray-900/50 dark:border-gray-700">
                                <button v-for="view in cashViews" :key="view.id" @click="cashView = view.id"
                                        :class="[
                                            'px-3 py-1.5 rounded-md text-sm font-medium transition-colors',
                                            cashView === view.id ? 'bg-white text-blue-700 shadow-sm' : 'text-gray-600 hover:text-gray-900'
                                        ]">
                                    {{ view.label }}
                                </button>
                            </div>
                            <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 text-sm">
                                <div class="rounded-lg border border-gray-200 px-4 py-3 dark:border-gray-700">
                                    <div class="text-xs uppercase text-gray-500 font-semibold dark:text-gray-300">Items</div>
                                    <div class="text-xl font-bold text-gray-900 dark:text-gray-100">{{ cashSchedule.items?.length || 0 }}</div>
                                </div>
                                <div class="rounded-lg border border-gray-200 px-4 py-3 dark:border-gray-700">
                                    <div class="text-xs uppercase text-gray-500 font-semibold dark:text-gray-300">Year Total</div>
                                    <div class="text-xl font-bold text-gray-900 dark:text-gray-100">₱{{ formatAmount(cashSchedule.total) }}</div>
                                </div>
                                <div class="rounded-lg border border-gray-200 px-4 py-3 col-span-2 sm:col-span-1 dark:border-gray-700">
                                    <div class="text-xs uppercase text-gray-500 font-semibold dark:text-gray-300">Selected Month</div>
                                    <div class="text-xl font-bold text-gray-900 dark:text-gray-100">₱{{ formatAmount(selectedMonthTotal) }}</div>
                                </div>
                            </div>
                        </div>

                        <div v-if="cashView === 'monthly'" class="overflow-x-auto border border-gray-200 rounded-lg dark:border-gray-700">
                            <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                                <thead class="bg-gray-50 text-xs uppercase text-gray-500 dark:bg-gray-900/50 dark:text-slate-300">
                                    <tr>
                                        <th class="px-4 py-3 text-left">Month</th>
                                        <th class="px-4 py-3 text-right">Connectivity</th>
                                        <th class="px-4 py-3 text-right">SOA Invoices</th>
                                        <th class="px-4 py-3 text-right">Renewals</th>
                                        <th class="px-4 py-3 text-right">Weekly Plans</th>
                                        <th class="px-4 py-3 text-right">Total</th>
                                        <th class="px-4 py-3 text-right">Items</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                    <tr v-for="row in cashSchedule.monthly || []" :key="row.month" class="hover:bg-gray-50 dark:hover:bg-gray-700">
                                        <td class="px-4 py-3 font-medium text-gray-900 dark:text-gray-100">{{ formatMonthLabel(row.month) }}</td>
                                        <td class="px-4 py-3 text-right font-mono">{{ formatAmount(row.service_total) }}</td>
                                        <td class="px-4 py-3 text-right font-mono">{{ formatAmount(row.invoice_total) }}</td>
                                        <td class="px-4 py-3 text-right font-mono">{{ formatAmount(row.renewal_total) }}</td>
                                        <td class="px-4 py-3 text-right font-mono">{{ formatAmount(row.weekly_total) }}</td>
                                        <td class="px-4 py-3 text-right font-mono font-semibold">{{ formatAmount(row.total) }}</td>
                                        <td class="px-4 py-3 text-right">{{ row.count }}</td>
                                    </tr>
                                    <tr v-if="!(cashSchedule.monthly || []).length">
                                        <td colspan="7" class="px-4 py-8 text-center text-gray-400 dark:text-gray-400">No scheduled payable dates found.</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div v-if="cashView === 'weekly'" class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                            <div v-for="week in cashSchedule.weekly || []" :key="week.week" class="border border-gray-200 rounded-lg p-4 dark:border-gray-700">
                                <div class="flex items-center justify-between border-b border-gray-100 pb-3 mb-3 dark:border-gray-700">
                                    <div>
                                        <div class="font-semibold text-gray-900 dark:text-gray-100">{{ week.week }}</div>
                                        <div class="text-xs text-gray-500 dark:text-gray-300">{{ week.range }}</div>
                                    </div>
                                    <div class="text-right">
                                        <div class="font-mono font-bold text-gray-900 dark:text-gray-100">₱{{ formatAmount(week.total) }}</div>
                                        <div class="text-xs text-gray-500 dark:text-gray-300">{{ week.count }} items</div>
                                    </div>
                                </div>
                                <div class="space-y-2">
                                    <div v-for="item in week.items" :key="`${item.source_type}-${item.source_id}-${item.due_date}`" class="flex items-start justify-between gap-3 text-sm">
                                        <div>
                                            <div class="font-medium text-gray-800 dark:text-gray-200">{{ item.vendor_name || '—' }}</div>
                                            <div class="text-xs text-gray-500 dark:text-gray-300">{{ sourceLabel(item.source_type) }} · {{ item.label }}</div>
                                        </div>
                                        <div class="text-right shrink-0">
                                            <div class="font-mono">{{ formatAmount(item.amount) }}</div>
                                            <div class="text-xs" :class="dueDateClass(item.due_date)">{{ item.due_date }}</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div v-if="!(cashSchedule.weekly || []).length" class="lg:col-span-2 border border-dashed border-gray-300 rounded-lg py-10 text-center text-gray-400 dark:text-gray-400 dark:border-gray-600">
                                No weekly schedule for the selected month.
                            </div>
                        </div>

                        <div v-if="cashView === 'calendar'" class="grid grid-cols-7 gap-px bg-gray-200 border border-gray-200 rounded-lg overflow-hidden dark:bg-gray-700 dark:border-gray-700">
                            <div v-for="day in ['Sun','Mon','Tue','Wed','Thu','Fri','Sat']" :key="day" class="bg-gray-50 px-2 py-2 text-xs font-semibold text-gray-500 text-center dark:bg-gray-900/50 dark:text-gray-300">
                                {{ day }}
                            </div>
                            <div v-for="day in calendarDays" :key="day.key"
                                 :class="['min-h-28 bg-white p-2 text-xs', !day.inMonth ? 'bg-gray-50 text-gray-400' : '']">
                                <div class="font-semibold text-gray-700 mb-1 dark:text-gray-300">{{ day.day }}</div>
                                <div v-if="day.total > 0" class="font-mono font-bold text-blue-700 mb-1">₱{{ formatCompactAmount(day.total) }}</div>
                                <div class="space-y-1">
                                    <div v-for="item in day.items.slice(0, 3)" :key="`${day.key}-${item.source_type}-${item.source_id}`"
                                         class="rounded border border-gray-200 px-1.5 py-1 bg-gray-50 truncate dark:bg-gray-900/50 dark:border-gray-700" :title="`${item.vendor_name} - ${item.label}`">
                                        {{ sourceLabel(item.source_type) }} · {{ item.vendor_name || '—' }}
                                    </div>
                                    <div v-if="day.items.length > 3" class="text-gray-500 dark:text-gray-300">+{{ day.items.length - 3 }} more</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ============ APPROVALS TAB ============ -->
            <div v-if="currentTab === 'approvals'">
                <DataTable
                    title="Payment Records (Approval Chain)"
                    subtitle="Submitted payments awaiting approval / posting"
                    empty-message="No payment records yet."
                    :search="recordsPagination.search.value"
                    :data="recordsPagination.data.value"
                    :current-page="recordsPagination.currentPage.value"
                    :last-page="recordsPagination.lastPage.value"
                    :per-page="recordsPagination.perPage.value"
                    :showing-text="recordsPagination.showingText.value"
                    :is-loading="recordsPagination.isLoading.value"
                    @update:search="recordsPagination.search.value = $event"
                    @go-to-page="recordsPagination.goToPage"
                    @change-per-page="recordsPagination.changePerPage"
                >
                    <template #header>
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase dark:text-slate-300">#</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase dark:text-slate-300">Payable</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase dark:text-slate-300">Vendor</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase dark:text-slate-300">Amount</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase dark:text-slate-300">Status / Level</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase dark:text-slate-300">Paid On / Ref</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase dark:text-slate-300">Actions</th>
                        </tr>
                    </template>
                    <template #body="{ data }">
                        <tr v-for="rec in data" :key="rec.id" class="hover:bg-gray-50 dark:hover:bg-gray-700">
                            <td class="px-4 py-3 text-sm">{{ rec.id }}</td>
                            <td class="px-4 py-3 text-sm capitalize">{{ rec.payable_type }} #{{ rec.payable_id }}</td>
                            <td class="px-4 py-3 text-sm">{{ rec.vendor?.name || '—' }}</td>
                            <td class="px-4 py-3 text-right text-sm font-mono">{{ formatAmount(rec.amount) }}</td>
                            <td class="px-4 py-3 text-sm">
                                <span :class="statusPill(rec.status)" class="px-2 py-0.5 text-xs rounded-full font-semibold">{{ rec.status }}</span>
                                <div class="text-xs text-gray-500 mt-0.5 dark:text-gray-300">Lvl {{ rec.current_approval_level }} / {{ rec.approver_data?.levels || '?' }}</div>
                            </td>
                            <td class="px-4 py-3 text-xs">{{ rec.paid_on || '—' }}<div v-if="rec.reference_no" class="text-gray-500 dark:text-gray-300">{{ rec.reference_no }}</div></td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex justify-end space-x-1">
                                    <IconBtn v-if="rec.status === 'pending' && hasPermission('payments.approve')" kind="approve" title="Approve" @click="openApproveModal(rec)" />
                                    <IconBtn v-if="rec.status === 'pending' && hasPermission('payments.approve')" kind="reject" title="Reject" @click="openRejectModal(rec)" />
                                    <IconBtn v-if="rec.status === 'approved' && hasPermission('payments.mark_paid')" kind="paid" title="Mark as Paid" @click="openMarkPaidModal(rec)" />
                                </div>
                            </td>
                        </tr>
                    </template>
                </DataTable>
            </div>

            <!-- ============ OTHER PAYABLES TAB ============ -->
            <div v-if="currentTab === 'payables'" class="space-y-4">
                <div class="inline-flex rounded-lg border border-gray-200 bg-gray-50 p-1 w-fit dark:bg-gray-900/50 dark:border-gray-700">
                    <button v-for="seg in payablesTabs" :key="seg.id" @click="payablesTab = seg.id"
                            :class="[
                                'px-3 py-1.5 rounded-md text-sm font-medium transition-colors',
                                payablesTab === seg.id ? 'bg-white text-blue-700 shadow-sm' : 'text-gray-600 hover:text-gray-900'
                            ]">
                        {{ seg.label }}
                    </button>
                </div>

                <!-- Renewals -->
                <div v-if="payablesTab === 'renewals'" class="space-y-4">
                    <div class="flex flex-wrap gap-2">
                        <button v-for="s in ['active', 'paused', 'cancelled']" :key="s"
                                @click="renewalsPagination.updateSearchParam('status', s)"
                                :class="[
                                    'px-3 py-1 rounded-full text-xs font-medium border transition-colors',
                                    renewalsPagination.filters?.status === s ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-gray-600 border-gray-200 hover:bg-gray-50'
                                ]">
                            {{ s.charAt(0).toUpperCase() + s.slice(1) }}
                        </button>
                        <button @click="renewalsPagination.updateSearchParam('status', null)"
                                class="px-3 py-1 rounded-full text-xs font-medium border bg-white text-gray-400 border-gray-100 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-400 dark:border-gray-700 dark:hover:bg-gray-700">
                            Clear
                        </button>
                    </div>
                    <DataTable
                        title="Recurring Renewals"
                        subtitle="Subscriptions and recurring vendor charges"
                        search-placeholder="Search by service, sub-type, or purpose..."
                        empty-message="No renewals yet."
                        :search="renewalsPagination.search.value"
                        :data="renewalsPagination.data.value"
                        :current-page="renewalsPagination.currentPage.value"
                        :last-page="renewalsPagination.lastPage.value"
                        :per-page="renewalsPagination.perPage.value"
                        :showing-text="renewalsPagination.showingText.value"
                        :is-loading="renewalsPagination.isLoading.value"
                        @update:search="renewalsPagination.search.value = $event"
                        @go-to-page="renewalsPagination.goToPage"
                        @change-per-page="renewalsPagination.changePerPage"
                    >
                        <template #actions>
                            <div class="flex items-center gap-2 flex-nowrap">
                                <a v-if="hasPermission('payments.view')" href="/payments/renewals/import-template"
                                   class="bg-white hover:bg-gray-50 text-gray-700 border border-gray-300 px-4 py-2 rounded-lg text-sm font-medium shadow-sm whitespace-nowrap inline-flex items-center dark:bg-gray-800 dark:text-gray-300 dark:border-gray-600 dark:hover:bg-gray-700">
                                    Download Template
                                </a>
                                <button v-if="hasPermission('payments.create')" @click="openRenewalImportModal()"
                                        class="bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded-lg text-sm font-medium shadow-sm whitespace-nowrap inline-flex items-center">
                                    Import
                                </button>
                                <button v-if="hasPermission('payments.create')" @click="openRenewalModal()"
                                        class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium shadow-sm whitespace-nowrap inline-flex items-center">
                                    + New Renewal
                                </button>
                            </div>
                        </template>
                        <template #header>
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase dark:text-slate-300">Vendor / Service</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase dark:text-slate-300">Cycle</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase dark:text-slate-300">Amount</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase dark:text-slate-300">Next Due</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase dark:text-slate-300">Assignee</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase dark:text-slate-300">Status</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase dark:text-slate-300">Actions</th>
                            </tr>
                        </template>
                        <template #body="{ data }">
                            <tr v-for="r in data" :key="r.id" class="hover:bg-gray-50 dark:hover:bg-gray-700">
                                <td class="px-4 py-3">
                                    <div class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ r.vendor?.name || '—' }}</div>
                                    <div class="text-xs text-gray-500 dark:text-gray-300">{{ r.service_type }}<span v-if="r.sub_type"> · {{ r.sub_type }}</span></div>
                                    <div v-if="r.purpose" class="text-xs text-gray-400 dark:text-gray-400">{{ r.purpose }}</div>
                                </td>
                                <td class="px-4 py-3 text-sm capitalize">{{ r.cycle?.replace('_', ' ') }}</td>
                                <td class="px-4 py-3 text-right text-sm font-mono">{{ r.currency || 'PHP' }} {{ formatAmount(r.total_amount) }}</td>
                                <td class="px-4 py-3 text-sm">
                                    <span :class="dueDateClass(r.next_due_date)">{{ r.next_due_date || '—' }}</span>
                                </td>
                                <td class="px-4 py-3 text-sm">{{ r.assignee?.name || '—' }}</td>
                                <td class="px-4 py-3">
                                    <span :class="statusPill(r.status)" class="px-2 py-0.5 text-xs rounded-full font-semibold">{{ r.status }}</span>
                                    <div v-if="r.latest_record_status" class="mt-1">
                                        <span :class="approvalPill(r.latest_record_status)" class="px-2 py-0.5 text-[10px] rounded-full font-bold uppercase tracking-wide">
                                            {{ approvalLabel(r.latest_record_status) }}
                                        </span>
                                    </div>
                                    <div v-else-if="r.last_paid_on" class="mt-1">
                                        <span class="px-2 py-0.5 text-[10px] rounded-full font-bold uppercase tracking-wide bg-emerald-100 text-emerald-800 border border-emerald-200">
                                            Last Paid: {{ formatDateShort(r.last_paid_on) }}
                                        </span>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <div v-if="!isRowFinal('renewal', r)" class="flex justify-end space-x-1">
                                        <IconBtn kind="remind" title="Send Manual Reminder" @click="sendManualReminder('renewal', r.id)" />
                                        <IconBtn v-if="hasPermission('payments.submit') && !r.latest_record_status" kind="submit" title="Submit for Approval" @click="openSubmitModal('renewal', r)" />
                                        <IconBtn v-if="r.latest_record_status === 'approved' && hasPermission('payments.mark_paid')" kind="paid" title="Mark as Paid" @click="openMarkPaidForPayable('renewal', r.id)" />
                                        <IconBtn v-if="hasPermission('payments.edit') && r.latest_record_status !== 'approved'" kind="edit" title="Edit Renewal" @click="openRenewalModal(r)" />
                                        <IconBtn v-if="hasPermission('payments.delete') && !r.latest_record_status" kind="delete" title="Delete Renewal" @click="confirmDelete('renewals', r, 'renewal')" />
                                    </div>
                                    <span v-else class="text-xs text-gray-400 italic dark:text-gray-400">—</span>
                                </td>
                            </tr>
                        </template>
                    </DataTable>
                </div>

                <!-- SOA Invoices -->
                <div v-if="payablesTab === 'invoices'" class="space-y-6">
                    <div class="flex flex-wrap gap-2">
                        <button v-for="s in ['Pending', 'Due', 'Overdue']" :key="s"
                                @click="invoicesPagination.updateSearchParam('inv_status', s)"
                                :class="[
                                    'px-3 py-1 rounded-full text-xs font-medium border transition-colors',
                                    invoicesPagination.filters?.inv_status === s ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-gray-600 border-gray-200 hover:bg-gray-50'
                                ]">
                            {{ s }}
                        </button>
                        <button @click="invoicesPagination.updateSearchParam('inv_status', null)"
                                class="px-3 py-1 rounded-full text-xs font-medium border bg-white text-gray-400 border-gray-100 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-400 dark:border-gray-700 dark:hover:bg-gray-700">
                            Clear
                        </button>
                    </div>
                    <DataTable
                        title="SOA / Vendor Invoices"
                        subtitle="Aged invoices with outstanding balances"
                        search-placeholder="Search APV / SI / PO / store..."
                        empty-message="No invoices yet."
                        :search="invoicesPagination.search.value"
                        :data="invoicesPagination.data.value"
                        :current-page="invoicesPagination.currentPage.value"
                        :last-page="invoicesPagination.lastPage.value"
                        :per-page="invoicesPagination.perPage.value"
                        :showing-text="invoicesPagination.showingText.value"
                        :is-loading="invoicesPagination.isLoading.value"
                        @update:search="invoicesPagination.search.value = $event"
                        @go-to-page="invoicesPagination.goToPage"
                        @change-per-page="invoicesPagination.changePerPage"
                    >
                        <template #actions>
                            <div class="flex items-center gap-2 flex-nowrap">
                                <a v-if="hasPermission('payments.view')" href="/payments/invoices/import-template"
                                   class="bg-white hover:bg-gray-50 text-gray-700 border border-gray-300 px-4 py-2 rounded-lg text-sm font-medium shadow-sm whitespace-nowrap inline-flex items-center dark:bg-gray-800 dark:text-gray-300 dark:border-gray-600 dark:hover:bg-gray-700">
                                    Download Template
                                </a>
                                <button v-if="hasPermission('payments.create')" @click="openImportModal()"
                                        class="bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded-lg text-sm font-medium shadow-sm whitespace-nowrap inline-flex items-center">
                                    Import
                                </button>
                                <button v-if="hasPermission('payments.create')" @click="openOverpaymentModal()"
                                        class="bg-amber-600 hover:bg-amber-700 text-white px-4 py-2 rounded-lg text-sm font-medium shadow-sm whitespace-nowrap inline-flex items-center">
                                    + Overpayment
                                </button>
                                <button v-if="hasPermission('payments.create')" @click="openInvoiceModal()"
                                        class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium shadow-sm whitespace-nowrap inline-flex items-center">
                                    + New Invoice
                                </button>
                            </div>
                        </template>
                        <template #header>
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase dark:text-slate-300">APV / SI</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase dark:text-slate-300">Vendor / Store</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase dark:text-slate-300">Invoice</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase dark:text-slate-300">Outstanding</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase dark:text-slate-300">Due / Aging</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase dark:text-slate-300">Status</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase dark:text-slate-300">Actions</th>
                            </tr>
                        </template>
                        <template #body="{ data }">
                            <tr v-for="i in data" :key="i.id" class="hover:bg-gray-50 dark:hover:bg-gray-700">
                                <td class="px-4 py-3 text-sm">
                                    <div class="font-medium text-gray-900 dark:text-gray-100">{{ i.apv_no || '—' }}</div>
                                    <div class="text-xs text-gray-500 dark:text-gray-300">SI {{ i.si_number || '—' }}</div>
                                </td>
                                <td class="px-4 py-3 text-sm">
                                    <div>{{ i.vendor?.name || '—' }}</div>
                                    <div class="text-xs text-gray-500 dark:text-gray-300">{{ i.store_code || '' }} {{ i.po_number ? '· PO ' + i.po_number : '' }}</div>
                                </td>
                                <td class="px-4 py-3 text-right text-sm font-mono">{{ formatAmount(i.invoice_amount) }}</td>
                                <td class="px-4 py-3 text-right text-sm font-mono font-semibold" :class="i.outstanding_amount > 0 ? 'text-red-700' : 'text-green-700'">
                                    {{ formatAmount(i.outstanding_amount) }}
                                </td>
                                <td class="px-4 py-3 text-sm">
                                    <div :class="dueDateClass(i.due_date)">{{ i.due_date || '—' }}</div>
                                    <div v-if="i.aging_days > 0" class="text-xs text-red-600">{{ i.aging_days }} days overdue</div>
                                </td>
                                <td class="px-4 py-3">
                                    <span :class="statusPill(i.status)" class="px-2 py-0.5 text-xs rounded-full font-semibold">{{ i.status }}</span>
                                    <div v-if="i.latest_record_status" class="mt-1">
                                        <span :class="approvalPill(i.latest_record_status)" class="px-2 py-0.5 text-[10px] rounded-full font-bold uppercase tracking-wide">
                                            {{ approvalLabel(i.latest_record_status) }}
                                        </span>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <div v-if="!isRowFinal('invoice', i)" class="flex justify-end space-x-1">
                                        <IconBtn kind="remind" title="Send Manual Reminder" @click="sendManualReminder('invoice', i.id)" />
                                        <IconBtn v-if="hasPermission('payments.submit') && !i.latest_record_status" kind="submit" title="Submit for Approval" @click="openSubmitModal('invoice', i)" />
                                        <IconBtn v-if="i.latest_record_status === 'approved' && hasPermission('payments.mark_paid')" kind="paid" title="Mark as Paid" @click="openMarkPaidForPayable('invoice', i.id)" />
                                        <IconBtn v-if="hasPermission('payments.edit') && i.latest_record_status !== 'approved'" kind="edit" title="Edit Invoice" @click="openInvoiceModal(i)" />
                                        <IconBtn v-if="hasPermission('payments.delete') && !i.latest_record_status" kind="delete" title="Delete Invoice" @click="confirmDelete('invoices', i, 'invoice')" />
                                    </div>
                                    <span v-else class="text-xs text-gray-400 italic dark:text-gray-400">—</span>
                                </td>
                            </tr>
                        </template>
                    </DataTable>

                    <!-- Overpayments mini-table -->
                    <div class="bg-white rounded-xl border border-gray-100 p-4 shadow-sm dark:bg-gray-800 dark:border-gray-700">
                        <div class="flex justify-between items-center mb-3">
                            <h3 class="font-semibold text-gray-900 dark:text-gray-100">Recent Overpayments</h3>
                        </div>
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-xs text-gray-500 uppercase border-b dark:text-gray-300">
                                    <th class="py-2">Date</th>
                                    <th class="py-2">Vendor</th>
                                    <th class="py-2">Check Details</th>
                                    <th class="py-2 text-right">Amount</th>
                                    <th class="py-2">Applied To</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="op in (overpayments?.data || [])" :key="op.id" class="border-b last:border-0">
                                    <td class="py-2">{{ op.collection_date || '—' }}</td>
                                    <td class="py-2">{{ op.vendor?.name || '—' }}</td>
                                    <td class="py-2 text-gray-600 dark:text-gray-300">{{ op.check_details || '—' }}</td>
                                    <td class="py-2 text-right font-mono">{{ formatAmount(op.amount) }}</td>
                                    <td class="py-2 text-xs">{{ op.invoice ? ('APV ' + (op.invoice.apv_no || op.invoice.si_number || op.invoice.id)) : '— unapplied' }}</td>
                                    <td class="py-2 text-right">
                                        <IconBtn v-if="hasPermission('payments.delete')" kind="delete" title="Delete Overpayment" @click="confirmDelete('overpayments', op, 'overpayment')" />
                                    </td>
                                </tr>
                                <tr v-if="!(overpayments?.data?.length)"><td colspan="6" class="py-4 text-center text-gray-400 text-xs dark:text-gray-400">No overpayments yet</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Weekly Plans -->
                <div v-if="payablesTab === 'weekly'">
                    <DataTable
                        title="Weekly Payment Plans"
                        subtitle="Per-project weekly disbursement schedule (Datche, Vantage, etc.)"
                        empty-message="No weekly plans yet."
                        :search="weeklyPagination.search.value"
                        :data="weeklyPagination.data.value"
                        :current-page="weeklyPagination.currentPage.value"
                        :last-page="weeklyPagination.lastPage.value"
                        :per-page="weeklyPagination.perPage.value"
                        :showing-text="weeklyPagination.showingText.value"
                        :is-loading="weeklyPagination.isLoading.value"
                        @update:search="weeklyPagination.search.value = $event"
                        @go-to-page="weeklyPagination.goToPage"
                        @change-per-page="weeklyPagination.changePerPage"
                    >
                        <template #actions>
                            <div class="flex items-center gap-2 flex-nowrap">
                                <a v-if="hasPermission('payments.view')" href="/payments/weekly-plans/import-template"
                                   class="bg-white hover:bg-gray-50 text-gray-700 border border-gray-300 px-4 py-2 rounded-lg text-sm font-medium shadow-sm whitespace-nowrap inline-flex items-center dark:bg-gray-800 dark:text-gray-300 dark:border-gray-600 dark:hover:bg-gray-700">
                                    Download Template
                                </a>
                                <button v-if="hasPermission('payments.create')" @click="openWeeklyImportModal()"
                                        class="bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded-lg text-sm font-medium shadow-sm whitespace-nowrap inline-flex items-center">
                                    Import
                                </button>
                                <button v-if="hasPermission('payments.create')" @click="openWeeklyModal()"
                                        class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium shadow-sm whitespace-nowrap inline-flex items-center">
                                    + New Plan Row
                                </button>
                            </div>
                        </template>
                        <template #header>
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase dark:text-slate-300">Week</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase dark:text-slate-300">Vendor / Project</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase dark:text-slate-300">Category</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase dark:text-slate-300">Amount</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase dark:text-slate-300">Status</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase dark:text-slate-300">Actions</th>
                            </tr>
                        </template>
                        <template #body="{ data }">
                            <tr v-for="w in data" :key="w.id" class="hover:bg-gray-50 dark:hover:bg-gray-700">
                                <td class="px-4 py-3 text-sm">
                                    <div>{{ w.month || '—' }} <span v-if="w.week_no" class="text-xs text-gray-500 dark:text-gray-300">/ Wk {{ w.week_no }}</span></div>
                                    <div class="text-xs text-gray-500 dark:text-gray-300">{{ w.week_date || '' }}</div>
                                </td>
                                <td class="px-4 py-3 text-sm">
                                    <div>{{ w.vendor?.name || '—' }}</div>
                                    <div class="text-xs text-gray-500 dark:text-gray-300">{{ w.project_label || '' }}</div>
                                </td>
                                <td class="px-4 py-3 text-sm">{{ w.category || '—' }}</td>
                                <td class="px-4 py-3 text-right text-sm font-mono">{{ formatAmount(w.amount) }}</td>
                                <td class="px-4 py-3">
                                    <span :class="statusPill(w.status)" class="px-2 py-0.5 text-xs rounded-full font-semibold">{{ w.status }}</span>
                                    <div v-if="w.latest_record_status" class="mt-1">
                                        <span :class="approvalPill(w.latest_record_status)" class="px-2 py-0.5 text-[10px] rounded-full font-bold uppercase tracking-wide">
                                            {{ approvalLabel(w.latest_record_status) }}
                                        </span>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <div v-if="!isRowFinal('weekly', w)" class="flex justify-end space-x-1">
                                        <IconBtn v-if="hasPermission('payments.submit') && !w.latest_record_status" kind="submit" title="Submit for Approval" @click="openSubmitModal('weekly', w)" />
                                        <IconBtn v-if="w.latest_record_status === 'approved' && hasPermission('payments.mark_paid')" kind="paid" title="Mark as Paid" @click="openMarkPaidForPayable('weekly', w.id)" />
                                        <IconBtn v-if="hasPermission('payments.edit') && w.latest_record_status !== 'approved'" kind="edit" title="Edit Plan Row" @click="openWeeklyModal(w)" />
                                        <IconBtn v-if="hasPermission('payments.delete') && !w.latest_record_status" kind="delete" title="Delete Plan Row" @click="confirmDelete('weekly-plans', w, 'weekly plan row')" />
                                    </div>
                                    <span v-else class="text-xs text-gray-400 italic dark:text-gray-400">—</span>
                                </td>
                            </tr>
                        </template>
                    </DataTable>
                </div>
            </div>

            <!-- ============ SETTINGS TAB ============ -->
            <div v-if="currentTab === 'settings'" class="bg-white rounded-xl border border-gray-100 p-6 shadow-sm space-y-4 max-w-2xl dark:bg-gray-800 dark:border-gray-700">
                <h3 class="font-semibold text-gray-900 dark:text-gray-100">Reminder & Approval Settings</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-gray-500 uppercase mb-1 dark:text-gray-300">Approval Levels</label>
                        <input v-model.number="settingsForm.approval_levels" type="number" min="1" max="5"
                               class="block w-full border-gray-300 rounded-lg text-sm dark:border-gray-600">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 uppercase mb-1 dark:text-gray-300">Default Currency</label>
                        <input v-model="settingsForm.default_currency" type="text" maxlength="8"
                               class="block w-full border-gray-300 rounded-lg text-sm dark:border-gray-600">
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase mb-1 dark:text-gray-300">Global BCC (email)</label>
                    <input v-model="settingsForm.global_bcc" type="text"
                           class="block w-full border-gray-300 rounded-lg text-sm dark:border-gray-600" placeholder="finance-bcc@company.com">
                </div>
                <div class="flex items-center">
                    <input v-model="settingsForm.reminders_enabled" type="checkbox" id="rem_enabled"
                           class="rounded border-gray-300 text-blue-600 dark:border-gray-600">
                    <label for="rem_enabled" class="ml-2 text-sm">Enable automated due-date reminders</label>
                </div>
                <div class="pt-3 border-t flex justify-end">
                    <button v-if="hasPermission('payments.manage_settings')" @click="saveSettings"
                            class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2 rounded-lg text-sm font-bold shadow-sm">
                        Save Settings
                    </button>
                </div>
            </div>
        </div>

        <!-- ============ CONNECTIVITY SERVICES MODAL (per-provider blocks) ============ -->
        <Modal v-if="serviceModal.open" @close="serviceModal.open = false" title="Connectivity Services">
            <form @submit.prevent="submitServices" class="space-y-4">
                <FormField label="Location (Office / Store)" required>
                    <Autocomplete v-model="serviceModal.store_id" :options="storeIdOptions" placeholder="Search location..." />
                </FormField>

                <div class="max-h-[56vh] overflow-y-auto pr-1 space-y-3 custom-scrollbar">
                    <div v-for="(provider, idx) in serviceProviders" :key="idx" class="rounded-xl border border-gray-200 bg-gray-50/60 p-3 dark:border-gray-700">
                        <div class="flex items-center justify-between mb-2">
                            <div class="flex items-center gap-2">
                                <span class="text-[11px] font-black uppercase tracking-widest text-gray-500 dark:text-gray-300">Provider {{ idx + 1 }}</span>
                                <span :class="provider.role === 'primary' ? 'bg-blue-100 text-blue-800' : 'bg-gray-200 text-gray-700'" class="px-2 py-0.5 text-[10px] rounded-full font-semibold uppercase">{{ provider.role }}</span>
                            </div>
                            <button type="button" @click="removeProvider(idx)" class="p-1 text-red-500 hover:bg-red-50 rounded-lg" title="Remove provider">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                            </button>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <FormField label="Telco / Provider">
                                <ManageableAutocomplete
                                    v-model="provider.telco"
                                    :options="telcoOptionsLocal"
                                    option-type="store_telco"
                                    placeholder="Select telco..."
                                    :can-create="canCreateOption"
                                    :can-edit="canEditOption"
                                    :can-delete="canDeleteOption"
                                    @options-changed="telcoOptionsLocal = $event"
                                />
                            </FormField>
                            <FormField label="Role" required>
                                <Autocomplete v-model="provider.role" :options="roleOptions" placeholder="Select role..." />
                            </FormField>
                            <FormField label="Vendor (for approval)">
                                <Autocomplete v-model="provider.vendor_id" :options="userVendorOptions" placeholder="Search vendor..." />
                            </FormField>
                            <FormField label="Account No.">
                                <input v-model="provider.account_no" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600" />
                            </FormField>
                            <FormField label="Service ID">
                                <input v-model="provider.service_id" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600" />
                            </FormField>
                            <FormField label="Bandwidth">
                                <input v-model="provider.bandwidth" placeholder="50 Mbps" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600" />
                            </FormField>
                            <FormField label="Install Type">
                                <Autocomplete v-model="provider.install_type" :options="installTypeOptions" placeholder="Select type..." />
                            </FormField>
                            <FormField label="MRC (VAT inc)" required>
                                <input v-model.number="provider.mrc" type="number" step="0.01" required class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600" />
                            </FormField>
                            <FormField label="Currency">
                                <Autocomplete v-model="provider.currency" :options="currencyOptions" placeholder="Select currency..." />
                            </FormField>
                            <FormField label="Installation Date">
                                <input v-model="provider.installation_date" type="date" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600" />
                            </FormField>
                            <FormField label="Billing Day (1-31)">
                                <input v-model.number="provider.billing_day" type="number" min="1" max="31" placeholder="Defaults to install day" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600" />
                            </FormField>
                            <FormField label="Status">
                                <Autocomplete v-model="provider.status" :options="serviceStatusOptions" placeholder="Select status..." />
                            </FormField>
                            <FormField label="Assignee">
                                <Autocomplete v-model="provider.assignee_id" :options="userOptions" placeholder="Search user..." />
                            </FormField>
                            <FormField label="CC Emails (comma-separated)">
                                <input v-model="provider.cc_emails" placeholder="email1@example.com, email2@example.com" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600" />
                            </FormField>
                        </div>
                        <div class="mt-3">
                            <FormField label="Notes">
                                <textarea v-model="provider.notes" rows="2" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600"></textarea>
                            </FormField>
                        </div>
                    </div>

                    <div v-if="!serviceProviders.length" class="text-center text-xs text-gray-400 italic border border-dashed border-gray-200 rounded-lg py-6 dark:text-gray-400 dark:border-gray-700">
                        No providers. Add one below.
                    </div>
                </div>

                <button type="button" @click="addProvider"
                        class="w-full border border-dashed border-blue-300 rounded-lg py-2 text-sm font-semibold text-blue-600 hover:bg-blue-50 transition-colors">
                    + Add Provider
                </button>

                <ModalFooter @cancel="serviceModal.open = false" submit-label="Save Services" />
            </form>
        </Modal>

        <!-- ============ LOCATION DETAILS MODAL ============ -->
        <Modal v-if="locationModal.open" @close="locationModal.open = false" :title="'Edit Location — ' + (locationModal.current?.code || '')">
            <form @submit.prevent="submitLocation" class="space-y-3">
                <FormField label="Address">
                    <textarea v-model="locationForm.address" rows="2" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600"></textarea>
                </FormField>
                <div class="grid grid-cols-2 gap-3">
                    <FormField label="Company (operating entity)">
                        <Autocomplete v-model="locationForm.legal_company" :options="companyOptions" allow-custom placeholder="Select or type company..." />
                    </FormField>
                    <FormField label="Company Applied With">
                        <input v-model="locationForm.company_applied_with" placeholder="Telco account holder" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600" />
                    </FormField>
                    <FormField label="Monitoring Status">
                        <Autocomplete v-model="locationForm.monitoring_status" :options="monitoringStatusOptions" allow-custom placeholder="Select status..." />
                        <p class="mt-1 text-[11px] text-gray-400 dark:text-gray-400">Site lifecycle: OPEN once live, PENDING/FOR APPLICATION while connectivity is being set up, FOR TERMINATION when winding down.</p>
                    </FormField>
                </div>
                <ModalFooter @cancel="locationModal.open = false" submit-label="Save" />
            </form>
        </Modal>

        <!-- ============ CONNECTIVITY IMPORT MODAL ============ -->
        <Modal v-if="connectivityImportModal.open" @close="connectivityImportModal.open = false" title="Import Connectivity Services">
            <form @submit.prevent="submitConnectivityImport" class="space-y-4">
                <div class="rounded-lg border border-blue-100 bg-blue-50 p-4 text-sm text-blue-900">
                    <div class="font-semibold mb-1">Template columns</div>
                    <p>One row per location, matched by <strong>Branch Code</strong>. Each row sets the location's identity plus its <strong>primary and secondary telco</strong> (each with its own account / bandwidth / MRC). Existing locations are updated in place; unknown branch codes are skipped. Extra per-provider fields are optional columns appended after the core set.</p>
                </div>
                <FormField label="Excel File" required>
                    <input type="file" accept=".xlsx" required @change="onConnectivityImportFileChange"
                           class="block w-full text-sm text-gray-700 file:mr-4 file:rounded-lg file:border-0 file:bg-blue-50 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-blue-700 hover:file:bg-blue-100 dark:text-gray-300">
                </FormField>
                <div v-if="connectivityImportResult" class="rounded-lg border border-gray-200 p-4 text-sm space-y-2 dark:border-gray-700">
                    <div class="grid grid-cols-3 gap-2">
                        <div><span class="text-gray-500 dark:text-gray-300">Created:</span> <strong>{{ connectivityImportResult.created || 0 }}</strong></div>
                        <div><span class="text-gray-500 dark:text-gray-300">Updated:</span> <strong>{{ connectivityImportResult.updated || 0 }}</strong></div>
                        <div><span class="text-gray-500 dark:text-gray-300">Skipped:</span> <strong>{{ connectivityImportResult.skipped || 0 }}</strong></div>
                    </div>
                    <div v-if="connectivityImportResult.errors?.length" class="max-h-36 overflow-y-auto rounded bg-red-50 border border-red-100 p-2 text-red-700">
                        <div v-for="error in connectivityImportResult.errors" :key="error">{{ error }}</div>
                    </div>
                </div>
                <div class="flex justify-between items-center pt-4 border-t">
                    <a href="/payments/services/import-template" class="text-sm font-semibold text-blue-700 hover:text-blue-800">
                        Download template
                    </a>
                    <div class="flex gap-3">
                        <button type="button" @click="connectivityImportModal.open = false"
                                class="px-4 py-2 text-sm font-semibold text-gray-600 bg-gray-100 rounded-lg hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700">
                            Close
                        </button>
                        <button type="submit" :disabled="connectivityImportModal.loading"
                                class="px-6 py-2 bg-emerald-600 text-white text-sm font-bold rounded-lg hover:bg-emerald-700 shadow-sm disabled:opacity-60">
                            {{ connectivityImportModal.loading ? 'Importing...' : 'Import' }}
                        </button>
                    </div>
                </div>
            </form>
        </Modal>

        <!-- INVOICE IMPORT MODAL -->
        <Modal v-if="importModal.open" @close="importModal.open = false" title="Import SOA Invoices">
            <form @submit.prevent="submitInvoiceImport" class="space-y-4">
                <div class="rounded-lg border border-blue-100 bg-blue-50 p-4 text-sm text-blue-900">
                    <div class="font-semibold mb-1">Template columns</div>
                    <p>Use the Excel template for invoice fields and optional payment columns. Fill paid_on and paid_amount for fully or partially paid invoices.</p>
                </div>
                <FormField label="Excel File" required>
                    <input type="file" accept=".xlsx" required @change="onImportFileChange"
                           class="block w-full text-sm text-gray-700 file:mr-4 file:rounded-lg file:border-0 file:bg-blue-50 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-blue-700 hover:file:bg-blue-100 dark:text-gray-300">
                </FormField>
                <div v-if="importResult" class="rounded-lg border border-gray-200 p-4 text-sm space-y-2 dark:border-gray-700">
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                        <div><span class="text-gray-500 dark:text-gray-300">Created:</span> <strong>{{ importResult.created || 0 }}</strong></div>
                        <div><span class="text-gray-500 dark:text-gray-300">Updated:</span> <strong>{{ importResult.updated || 0 }}</strong></div>
                        <div><span class="text-gray-500 dark:text-gray-300">Payments:</span> <strong>{{ importResult.payments_created || 0 }}</strong></div>
                        <div><span class="text-gray-500 dark:text-gray-300">Skipped:</span> <strong>{{ importResult.skipped || 0 }}</strong></div>
                    </div>
                    <div v-if="importResult.errors?.length" class="max-h-36 overflow-y-auto rounded bg-red-50 border border-red-100 p-2 text-red-700">
                        <div v-for="error in importResult.errors" :key="error">{{ error }}</div>
                    </div>
                </div>
                <div class="flex justify-between items-center pt-4 border-t">
                    <a href="/payments/invoices/import-template" class="text-sm font-semibold text-blue-700 hover:text-blue-800">
                        Download template
                    </a>
                    <div class="flex gap-3">
                        <button type="button" @click="importModal.open = false"
                                class="px-4 py-2 text-sm font-semibold text-gray-600 bg-gray-100 rounded-lg hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700">
                            Close
                        </button>
                        <button type="submit" :disabled="importModal.loading"
                                class="px-6 py-2 bg-emerald-600 text-white text-sm font-bold rounded-lg hover:bg-emerald-700 shadow-sm disabled:opacity-60">
                            {{ importModal.loading ? 'Importing...' : 'Import' }}
                        </button>
                    </div>
                </div>
            </form>
        </Modal>

        <!-- RENEWAL IMPORT MODAL -->
        <Modal v-if="renewalImportModal.open" @close="renewalImportModal.open = false" title="Import Renewals">
            <form @submit.prevent="submitRenewalImport" class="space-y-4">
                <div class="rounded-lg border border-blue-100 bg-blue-50 p-4 text-sm text-blue-900">
                    <div class="font-semibold mb-1">Template columns</div>
                    <p>Use the Excel template for renewal fields. Rows matching an existing vendor + service type + sub type are skipped (never overwritten). Fill paid_on / paid_amount to also record a payment.</p>
                </div>
                <FormField label="Excel File" required>
                    <input type="file" accept=".xlsx" required @change="onRenewalImportFileChange"
                           class="block w-full text-sm text-gray-700 file:mr-4 file:rounded-lg file:border-0 file:bg-blue-50 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-blue-700 hover:file:bg-blue-100 dark:text-gray-300">
                </FormField>
                <div v-if="renewalImportResult" class="rounded-lg border border-gray-200 p-4 text-sm space-y-2 dark:border-gray-700">
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                        <div><span class="text-gray-500 dark:text-gray-300">Created:</span> <strong>{{ renewalImportResult.created || 0 }}</strong></div>
                        <div><span class="text-gray-500 dark:text-gray-300">Duplicates:</span> <strong>{{ renewalImportResult.duplicates || 0 }}</strong></div>
                        <div><span class="text-gray-500 dark:text-gray-300">Payments:</span> <strong>{{ renewalImportResult.payments_created || 0 }}</strong></div>
                        <div><span class="text-gray-500 dark:text-gray-300">Skipped:</span> <strong>{{ renewalImportResult.skipped || 0 }}</strong></div>
                    </div>
                    <div v-if="renewalImportResult.errors?.length" class="max-h-36 overflow-y-auto rounded bg-red-50 border border-red-100 p-2 text-red-700">
                        <div v-for="error in renewalImportResult.errors" :key="error">{{ error }}</div>
                    </div>
                </div>
                <div class="flex justify-between items-center pt-4 border-t">
                    <a href="/payments/renewals/import-template" class="text-sm font-semibold text-blue-700 hover:text-blue-800">
                        Download template
                    </a>
                    <div class="flex gap-3">
                        <button type="button" @click="renewalImportModal.open = false"
                                class="px-4 py-2 text-sm font-semibold text-gray-600 bg-gray-100 rounded-lg hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700">
                            Close
                        </button>
                        <button type="submit" :disabled="renewalImportModal.loading"
                                class="px-6 py-2 bg-emerald-600 text-white text-sm font-bold rounded-lg hover:bg-emerald-700 shadow-sm disabled:opacity-60">
                            {{ renewalImportModal.loading ? 'Importing...' : 'Import' }}
                        </button>
                    </div>
                </div>
            </form>
        </Modal>

        <!-- WEEKLY PLAN IMPORT MODAL -->
        <Modal v-if="weeklyImportModal.open" @close="weeklyImportModal.open = false" title="Import Weekly Plans">
            <form @submit.prevent="submitWeeklyImport" class="space-y-4">
                <div class="rounded-lg border border-blue-100 bg-blue-50 p-4 text-sm text-blue-900">
                    <div class="font-semibold mb-1">Template columns</div>
                    <p>Use the Excel template for weekly plan fields. Rows matching an existing vendor + month + week no + category are skipped (never overwritten). Set status to Paid or fill paid_on / paid_amount to also record a payment.</p>
                </div>
                <FormField label="Excel File" required>
                    <input type="file" accept=".xlsx" required @change="onWeeklyImportFileChange"
                           class="block w-full text-sm text-gray-700 file:mr-4 file:rounded-lg file:border-0 file:bg-blue-50 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-blue-700 hover:file:bg-blue-100 dark:text-gray-300">
                </FormField>
                <div v-if="weeklyImportResult" class="rounded-lg border border-gray-200 p-4 text-sm space-y-2 dark:border-gray-700">
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                        <div><span class="text-gray-500 dark:text-gray-300">Created:</span> <strong>{{ weeklyImportResult.created || 0 }}</strong></div>
                        <div><span class="text-gray-500 dark:text-gray-300">Duplicates:</span> <strong>{{ weeklyImportResult.duplicates || 0 }}</strong></div>
                        <div><span class="text-gray-500 dark:text-gray-300">Payments:</span> <strong>{{ weeklyImportResult.payments_created || 0 }}</strong></div>
                        <div><span class="text-gray-500 dark:text-gray-300">Skipped:</span> <strong>{{ weeklyImportResult.skipped || 0 }}</strong></div>
                    </div>
                    <div v-if="weeklyImportResult.errors?.length" class="max-h-36 overflow-y-auto rounded bg-red-50 border border-red-100 p-2 text-red-700">
                        <div v-for="error in weeklyImportResult.errors" :key="error">{{ error }}</div>
                    </div>
                </div>
                <div class="flex justify-between items-center pt-4 border-t">
                    <a href="/payments/weekly-plans/import-template" class="text-sm font-semibold text-blue-700 hover:text-blue-800">
                        Download template
                    </a>
                    <div class="flex gap-3">
                        <button type="button" @click="weeklyImportModal.open = false"
                                class="px-4 py-2 text-sm font-semibold text-gray-600 bg-gray-100 rounded-lg hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700">
                            Close
                        </button>
                        <button type="submit" :disabled="weeklyImportModal.loading"
                                class="px-6 py-2 bg-emerald-600 text-white text-sm font-bold rounded-lg hover:bg-emerald-700 shadow-sm disabled:opacity-60">
                            {{ weeklyImportModal.loading ? 'Importing...' : 'Import' }}
                        </button>
                    </div>
                </div>
            </form>
        </Modal>

        <!-- RENEWAL MODAL -->
        <Modal v-if="renewalModal.open" @close="renewalModal.open = false" :title="renewalModal.editing ? 'Edit Renewal' : 'New Renewal'">
            <form @submit.prevent="submitRenewal" class="space-y-3">
                <div class="grid grid-cols-2 gap-3">
                    <FormField label="Vendor" required>
                        <Autocomplete v-model="renewalForm.vendor_id" :options="userVendorOptions" placeholder="Search vendor..." />
                    </FormField>
                    <FormField label="Service Type" required>
                        <input v-model="renewalForm.service_type" required class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600" />
                    </FormField>
                    <FormField label="Sub-Type">
                        <input v-model="renewalForm.sub_type" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600" />
                    </FormField>
                    <FormField label="Purpose">
                        <input v-model="renewalForm.purpose" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600" />
                    </FormField>
                    <FormField label="Unit Cost" required>
                        <input v-model.number="renewalForm.unit_cost" type="number" step="0.01" required class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600" />
                    </FormField>
                    <FormField label="Qty" required>
                        <input v-model.number="renewalForm.qty" type="number" min="1" required class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600" />
                    </FormField>
                    <FormField label="Total Amount (auto-calc)">
                        <input :value="(Number(renewalForm.unit_cost || 0) * Number(renewalForm.qty || 0)).toFixed(2)"
                               type="text" readonly
                               class="block w-full border-gray-300 bg-gray-50 rounded-lg shadow-sm text-sm font-mono text-gray-700 cursor-not-allowed dark:bg-gray-900/50 dark:text-gray-300 dark:border-gray-600" />
                    </FormField>
                    <FormField label="Currency">
                        <Autocomplete v-model="renewalForm.currency" :options="currencyOptions" placeholder="Select currency..." />
                    </FormField>
                    <FormField label="Cycle" required>
                        <Autocomplete v-model="renewalForm.cycle" :options="cycleOptions" placeholder="Select cycle..." />
                    </FormField>
                    <FormField label="Cycle Anchor Date">
                        <input v-model="renewalForm.cycle_anchor_date" type="date" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600" />
                    </FormField>
                    <FormField label="Next Due Date">
                        <input v-model="renewalForm.next_due_date" type="date" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600" />
                    </FormField>
                    <FormField label="Expiration Date">
                        <input v-model="renewalForm.expiration_date" type="date" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600" />
                    </FormField>
                    <FormField label="Payment Terms">
                        <input v-model="renewalForm.payment_terms" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600" />
                    </FormField>
                    <FormField label="Assignee">
                        <Autocomplete v-model="renewalForm.assignee_user_id" :options="userOptions" placeholder="Search user..." />
                    </FormField>
                    <FormField label="CC Emails (comma-separated)">
                        <input v-model="renewalForm.cc_emails" placeholder="email1@example.com, email2@example.com" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600" />
                    </FormField>
                    <FormField label="Status">
                        <Autocomplete v-model="renewalForm.status" :options="renewalStatusOptions" placeholder="Select status..." />
                    </FormField>
                </div>
                <FormField label="Notes">
                    <textarea v-model="renewalForm.notes" rows="2" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600"></textarea>
                </FormField>
                <ModalFooter @cancel="renewalModal.open = false" :submit-label="renewalModal.editing ? 'Update' : 'Create'" />
            </form>
        </Modal>

        <!-- INVOICE MODAL -->
        <Modal v-if="invoiceModal.open" @close="invoiceModal.open = false" :title="invoiceModal.editing ? 'Edit Invoice' : 'New Invoice'">
            <form @submit.prevent="submitInvoice" class="space-y-3">
                <div class="grid grid-cols-2 gap-3">
                    <FormField label="Vendor" required>
                        <Autocomplete v-model="invoiceForm.vendor_id" :options="userVendorOptions" placeholder="Search vendor..." />
                    </FormField>
                    <FormField label="APV No.">
                        <input v-model="invoiceForm.apv_no" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600" />
                    </FormField>
                    <FormField label="Store Code">
                        <Autocomplete v-model="invoiceForm.store_code" :options="storeOptions" placeholder="Search store code..." />
                    </FormField>
                    <FormField label="PO Number">
                        <input v-model="invoiceForm.po_number" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600" />
                    </FormField>
                    <FormField label="SI Number">
                        <input v-model="invoiceForm.si_number" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600" />
                    </FormField>
                    <FormField label="SI Date">
                        <input v-model="invoiceForm.si_date" type="date" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600" />
                    </FormField>
                    <FormField label="Due Date">
                        <input v-model="invoiceForm.due_date" type="date" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600" />
                    </FormField>
                    <FormField label="Invoice Amount" required>
                        <input v-model.number="invoiceForm.invoice_amount" type="number" step="0.01" required class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600" />
                    </FormField>
                    <FormField label="Outstanding Amount">
                        <input v-model.number="invoiceForm.outstanding_amount" type="number" step="0.01" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600" />
                    </FormField>
                    <FormField label="Status">
                        <Autocomplete v-model="invoiceForm.status" :options="invoiceStatusOptions" placeholder="Select status..." />
                    </FormField>
                    <FormField label="Assignee">
                        <Autocomplete v-model="invoiceForm.assignee_user_id" :options="userOptions" placeholder="Search user..." />
                    </FormField>
                    <FormField label="CC Emails (comma-separated)">
                        <input v-model="invoiceForm.cc_emails" placeholder="email1@example.com, email2@example.com" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600" />
                    </FormField>
                </div>
                <FormField label="Remarks">
                    <textarea v-model="invoiceForm.remarks" rows="2" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600"></textarea>
                </FormField>
                <ModalFooter @cancel="invoiceModal.open = false" :submit-label="invoiceModal.editing ? 'Update' : 'Create'" />
            </form>
        </Modal>

        <!-- OVERPAYMENT MODAL -->
        <Modal v-if="overpaymentModal.open" @close="overpaymentModal.open = false" title="Apply Overpayment">
            <form @submit.prevent="submitOverpayment" class="space-y-3">
                <FormField label="Vendor" required>
                    <Autocomplete v-model="overpaymentForm.vendor_id" :options="userVendorOptions" placeholder="Search vendor..." />
                </FormField>
                <div class="grid grid-cols-2 gap-3">
                    <FormField label="Collection Date">
                        <input v-model="overpaymentForm.collection_date" type="date" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600" />
                    </FormField>
                    <FormField label="Check Details">
                        <input v-model="overpaymentForm.check_details" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600" />
                    </FormField>
                    <FormField label="Amount" required>
                        <input v-model.number="overpaymentForm.amount" type="number" step="0.01" required class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600" />
                    </FormField>
                    <FormField label="Apply to Invoice (optional)">
                        <Autocomplete v-model="overpaymentForm.applied_to_invoice_id" :options="invoiceForOverpaymentOptions" placeholder="Search invoice..." />
                    </FormField>
                </div>
                <FormField label="Remarks">
                    <textarea v-model="overpaymentForm.remarks" rows="2" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600"></textarea>
                </FormField>
                <ModalFooter @cancel="overpaymentModal.open = false" submit-label="Record" />
            </form>
        </Modal>

        <!-- WEEKLY PLAN MODAL -->
        <Modal v-if="weeklyModal.open" @close="weeklyModal.open = false" :title="weeklyModal.editing ? 'Edit Plan Row' : 'New Plan Row'">
            <form @submit.prevent="submitWeekly" class="space-y-3">
                <div class="grid grid-cols-2 gap-3">
                    <FormField label="Vendor" required>
                        <Autocomplete v-model="weeklyForm.vendor_id" :options="userVendorOptions" placeholder="Search vendor..." />
                    </FormField>
                    <FormField label="Project Label">
                        <input v-model="weeklyForm.project_label" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600" />
                    </FormField>
                    <FormField label="Month">
                        <input v-model="weeklyForm.month" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600" placeholder="Jan" />
                    </FormField>
                    <FormField label="Week #">
                        <input v-model.number="weeklyForm.week_no" type="number" min="1" max="53" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600" />
                    </FormField>
                    <FormField label="Week Date">
                        <input v-model="weeklyForm.week_date" type="date" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600" />
                    </FormField>
                    <FormField label="Amount" required>
                        <input v-model.number="weeklyForm.amount" type="number" step="0.01" required class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600" />
                    </FormField>
                    <FormField label="Category">
                        <Autocomplete v-model="weeklyForm.category" :options="weeklyCategoryOptions" placeholder="Select category..." />
                    </FormField>
                    <FormField label="Status">
                        <Autocomplete v-model="weeklyForm.status" :options="weeklyStatusOptions" placeholder="Select status..." />
                    </FormField>
                </div>
                <FormField label="Notes">
                    <textarea v-model="weeklyForm.notes" rows="2" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600"></textarea>
                </FormField>
                <ModalFooter @cancel="weeklyModal.open = false" :submit-label="weeklyModal.editing ? 'Update' : 'Create'" />
            </form>
        </Modal>

        <!-- SUBMIT FOR APPROVAL MODAL -->
        <Modal v-if="submitModal.open" @close="submitModal.open = false" title="Submit for Approval">
            <form @submit.prevent="confirmSubmit" class="space-y-3">
                <p class="text-sm text-gray-600 dark:text-gray-300">Submitting <strong>{{ submitModal.payableType }}</strong> for vendor approval chain.</p>
                <FormField label="Amount" required>
                    <input v-model.number="submitForm.amount" type="number" step="0.01" required class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600" />
                </FormField>
                <FormField label="Remarks">
                    <textarea v-model="submitForm.remarks" rows="2" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600"></textarea>
                </FormField>
                <ModalFooter @cancel="submitModal.open = false" submit-label="Submit" />
            </form>
        </Modal>

        <!-- MARK PAID MODAL -->
        <Modal v-if="markPaidModal.open" @close="markPaidModal.open = false" title="Mark as Paid">
            <form @submit.prevent="confirmMarkPaid" class="space-y-3">
                <FormField label="Paid On" required>
                    <input v-model="markPaidForm.paid_on" type="date" required class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600" />
                </FormField>
                <FormField label="Reference No.">
                    <input v-model="markPaidForm.reference_no" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600" />
                </FormField>
                <ModalFooter @cancel="markPaidModal.open = false" submit-label="Post Payment" />
            </form>
        </Modal>

        <!-- DELETE CONFIRM MODAL -->
        <Modal v-if="deleteModal.open" @close="deleteModal.open = false" title="Confirm Delete">
            <div class="space-y-4">
                <p class="text-sm text-gray-700 dark:text-gray-300">
                    Are you sure you want to delete this <strong>{{ deleteModal.label }}</strong>?
                    This action cannot be undone.
                </p>
                <div class="flex justify-end space-x-3 pt-4 border-t">
                    <button type="button" @click="deleteModal.open = false"
                            class="px-4 py-2 text-sm font-semibold text-gray-600 bg-gray-100 rounded-lg hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700">
                        Cancel
                    </button>
                    <button type="button" @click="performDelete"
                            class="px-6 py-2 bg-red-600 text-white text-sm font-bold rounded-lg hover:bg-red-700 shadow-sm">
                        Delete
                    </button>
                </div>
            </div>
        </Modal>

        <!-- APPROVE CONFIRM MODAL -->
        <Modal v-if="approveModal.open" @close="approveModal.open = false" title="Approve Payment">
            <form @submit.prevent="confirmApprove" class="space-y-3">
                <p class="text-sm text-gray-700 dark:text-gray-300">
                    Approve payment record <strong>#{{ approveModal.record?.id }}</strong>
                    for vendor <strong>{{ approveModal.record?.vendor?.name || '—' }}</strong>
                    (₱{{ formatAmount(approveModal.record?.amount) }})?
                </p>
                <FormField label="Remarks (optional)">
                    <textarea v-model="approveForm.remarks" rows="2"
                              class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600"></textarea>
                </FormField>
                <div class="flex justify-end space-x-3 pt-4 border-t">
                    <button type="button" @click="approveModal.open = false"
                            class="px-4 py-2 text-sm font-semibold text-gray-600 bg-gray-100 rounded-lg hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700">
                        Cancel
                    </button>
                    <button type="submit"
                            class="px-6 py-2 bg-green-600 text-white text-sm font-bold rounded-lg hover:bg-green-700 shadow-sm">
                        Approve
                    </button>
                </div>
            </form>
        </Modal>

        <!-- REJECT MODAL -->
        <Modal v-if="rejectModal.open" @close="rejectModal.open = false" title="Reject Payment">
            <form @submit.prevent="confirmReject" class="space-y-3">
                <p class="text-sm text-gray-700 dark:text-gray-300">
                    Reject payment record <strong>#{{ rejectModal.record?.id }}</strong>?
                    Please provide a reason — this will be sent to the requester.
                </p>
                <FormField label="Reason" required>
                    <textarea v-model="rejectForm.remarks" rows="3" required
                              class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600"
                              placeholder="Explain why this payment is being rejected..."></textarea>
                </FormField>
                <div class="flex justify-end space-x-3 pt-4 border-t">
                    <button type="button" @click="rejectModal.open = false"
                            class="px-4 py-2 text-sm font-semibold text-gray-600 bg-gray-100 rounded-lg hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700">
                        Cancel
                    </button>
                    <button type="submit"
                            class="px-6 py-2 bg-red-600 text-white text-sm font-bold rounded-lg hover:bg-red-700 shadow-sm">
                        Reject
                    </button>
                </div>
            </form>
        </Modal>
    </AppLayout>
</template>

<script setup>
import { ref, reactive, computed, watch, onMounted } from 'vue'
import { router } from '@inertiajs/vue3'
import axios from 'axios'
import AppLayout from '@/Layouts/AppLayout.vue'
import DataTable from '@/Components/DataTable.vue'
import Autocomplete from '@/Components/Autocomplete.vue'
import ManageableAutocomplete from '@/Components/ManageableAutocomplete.vue'
import Modal from '@/Pages/Payments/_PaymentsModal.vue'
import FormField from '@/Pages/Payments/_PaymentsField.vue'
import ModalFooter from '@/Pages/Payments/_PaymentsFooter.vue'
import IconBtn from '@/Pages/Payments/_PaymentsIconBtn.vue'
import { useToast } from '@/Composables/useToast'
import { useConfirm } from '@/Composables/useConfirm'
import { useErrorHandler } from '@/Composables/useErrorHandler'
import { usePagination } from '@/Composables/usePagination'
import { usePermission } from '@/Composables/usePermission'

const props = defineProps({
    tab: { type: String, default: 'monitoring' },
    summary: { type: Object, default: () => ({}) },
    vendors: { type: Array, default: () => [] },
    stores: { type: Array, default: () => [] },
    brands: { type: Array, default: () => [] },
    companies: { type: Array, default: () => [] },
    users: { type: Array, default: () => [] },
    currencies: { type: Array, default: () => ['PHP'] },
    cycles: { type: Array, default: () => [] },
    installTypes: { type: Array, default: () => [] },
    serviceStatuses: { type: Array, default: () => [] },
    telcoOptions: { type: Array, default: () => [] },
    monitoringStatuses: { type: Array, default: () => [] },
    invoiceStatuses: { type: Array, default: () => [] },
    renewalStatuses: { type: Array, default: () => [] },
    weeklyStatuses: { type: Array, default: () => [] },
    settings: { type: Object, default: () => ({}) },
    locations: { type: Array, default: () => [] },
    renewals: { type: Object, default: () => ({ data: [] }) },
    invoices: { type: Object, default: () => ({ data: [] }) },
    overpayments: { type: Object, default: () => ({ data: [] }) },
    weeklyPlans: { type: Object, default: () => ({ data: [] }) },
    records: { type: Object, default: () => ({ data: [] }) },
    cashSchedule: { type: Object, default: () => ({ filters: {}, items: [], monthly: [], weekly: [], calendar: [], total: 0 }) },
})

const { showSuccess, showError } = useToast()
const { confirm } = useConfirm()
const { post, put, destroy } = useErrorHandler()
const { hasPermission } = usePermission()

const tabList = [
    { id: 'monitoring', label: 'Monitoring' },
    { id: 'schedule', label: 'Schedule' },
    { id: 'approvals', label: 'Approvals' },
    { id: 'payables', label: 'Other Payables' },
    { id: 'settings', label: 'Settings' },
]

/* Map any legacy tab value coming from the backend / pagination links to a current tab. */
const normalizeTab = (id) => {
    const map = {
        dashboard: 'monitoring',
        renewals: 'payables',
        invoices: 'payables',
        weekly: 'payables',
        'cash-schedule': 'schedule',
        records: 'approvals',
    }
    return map[id] || (tabList.some(t => t.id === id) ? id : 'monitoring')
}

const currentTab = ref(normalizeTab(props.tab))
const switchTab = (id) => {
    currentTab.value = id
    router.get('/payments', { tab: id }, { preserveScroll: true, preserveState: true, replace: true })
}

/* Other Payables secondary tabs */
const payablesTabs = [
    { id: 'renewals', label: 'Renewals' },
    { id: 'invoices', label: 'SOA Invoices' },
    { id: 'weekly', label: 'Weekly Plans' },
]
const payablesTab = ref(['renewals', 'invoices', 'weekly'].includes(props.tab) ? props.tab : 'renewals')

const summary = computed(() => props.summary || {})
const weeklyCategories = ['POS', 'CCTV', 'Internet', 'Speaker', 'Anti-virus', 'Router', 'Google']
const cashViews = [
    { id: 'monthly', label: 'Monthly' },
    { id: 'weekly', label: 'Weekly' },
    { id: 'calendar', label: 'Calendar' },
]

/* ---- Monitoring (locations) ---- */
const locationTypes = [
    { id: 'store', label: 'Location / Stores' },
    { id: 'office', label: 'Corporate Offices' },
]
const monitoringType = ref('store')
const monitoringBrand = ref('')
const monitoringSearch = ref('')
const collapsedBrands = reactive({})
const toggleBrand = (b) => { collapsedBrands[b] = !collapsedBrands[b] }

const officeCount = computed(() => (props.locations || []).filter(l => l.type === 'office').length)
const storeCount = computed(() => (props.locations || []).filter(l => l.type === 'store').length)

const locationMrc = (loc) => (loc.services || []).reduce((s, sv) => s + (sv.status === 'active' ? Number(sv.mrc || 0) : 0), 0)

const filteredLocations = computed(() => {
    const term = monitoringSearch.value.trim().toLowerCase()
    return (props.locations || []).filter((l) => {
        if (l.type !== monitoringType.value) return false
        if (monitoringBrand.value && l.brand !== monitoringBrand.value) return false
        if (term) {
            const hay = `${l.code || ''} ${l.name || ''} ${l.address || ''} ${l.legal_company || ''}`.toLowerCase()
            if (!hay.includes(term)) return false
        }
        return true
    })
})

const groupedByBrand = computed(() => {
    const groups = new Map()
    for (const l of filteredLocations.value) {
        const key = l.brand || 'Unbranded'
        if (!groups.has(key)) groups.set(key, [])
        groups.get(key).push(l)
    }
    return Array.from(groups.entries())
        .sort((a, b) => String(a[0]).localeCompare(String(b[0])))
        .map(([brand, locations]) => ({
            brand,
            locations,
            mrc: locations.reduce((s, l) => s + locationMrc(l), 0),
            serviceCount: locations.reduce((s, l) => s + (l.services?.length || 0), 0),
        }))
})

/* ---- Autocomplete option lists ---- */
const userVendorOptions = computed(() => (props.vendors || []).map(v => ({ label: v.name, value: v.id })))
const storeOptions = computed(() => (props.stores || []).map(s => ({
    label: s.code + (s.name ? ` — ${s.name}` : ''),
    value: s.code,
})))
const storeIdOptions = computed(() => (props.stores || []).map(s => ({
    label: s.code + (s.name ? ` — ${s.name}` : ''),
    value: s.id,
})))
const userOptions = computed(() => [{ label: '—', value: null }, ...(props.users || []).map(u => ({ label: u.name, value: u.id }))])
const currencyOptions = computed(() => (props.currencies || []).map(c => ({ label: c, value: c })))
const cycleOptions = computed(() => (props.cycles || []).map(c => ({ label: c.replace('_', ' '), value: c })))
const installTypeOptions = computed(() => (props.installTypes || []).map(t => ({ label: t.charAt(0).toUpperCase() + t.slice(1), value: t })))
const serviceStatusOptions = computed(() => (props.serviceStatuses || []).map(s => ({ label: s.charAt(0).toUpperCase() + s.slice(1), value: s })))
const roleOptions = [{ label: 'Primary', value: 'primary' }, { label: 'Secondary', value: 'secondary' }]
/* Company (operating entity) options come from the Companies module; legal_company stays a string. */
const companyOptions = computed(() => (props.companies || []).map(c => ({ label: c.name, value: c.name })))
const monitoringStatusOptions = computed(() => (props.monitoringStatuses || []).map(s => ({ label: s, value: s })))
/* Telco/Provider shares the Stores `store_telco` reference list — add/edit/delete here writes
   to the same options the Stores page uses. Kept in a local ref synced via @options-changed. */
const telcoOptionsLocal = ref([...(props.telcoOptions || [])])
watch(() => props.telcoOptions, (v) => { telcoOptionsLocal.value = [...(v || [])] })
const canCreateOption = computed(() => hasPermission('reference_options.create'))
const canEditOption = computed(() => hasPermission('reference_options.edit'))
const canDeleteOption = computed(() => hasPermission('reference_options.delete'))
const renewalStatusOptions = computed(() => (props.renewalStatuses || []).map(s => ({ label: s, value: s })))
const invoiceStatusOptions = computed(() => (props.invoiceStatuses || []).map(s => ({ label: s, value: s })))
const weeklyStatusOptions = computed(() => (props.weeklyStatuses || []).map(s => ({ label: s, value: s })))
const weeklyCategoryOptions = computed(() => [{ label: '—', value: '' }, ...weeklyCategories.map(c => ({ label: c, value: c }))])
const invoiceForOverpaymentOptions = computed(() => [
    { label: '— unapplied', value: null },
    ...((props.invoices?.data) || []).map(i => ({
        label: `APV ${i.apv_no || '—'} · SI ${i.si_number || '—'} · ₱${formatAmount(i.outstanding_amount)}`,
        value: i.id,
    })),
])

const renewalsPagination = usePagination(props.renewals, 'payments.index', () => ({
    tab: 'renewals',
    ...renewalsPagination.filters
}), { dataKey: 'renewals' })
renewalsPagination.filters = reactive({ status: null })
renewalsPagination.updateSearchParam = (key, val) => {
    renewalsPagination.filters[key] = val
    renewalsPagination.currentPage.value = 1
    renewalsPagination.performSearch()
}

const invoicesPagination = usePagination(props.invoices, 'payments.index', () => ({
    tab: 'invoices',
    ...invoicesPagination.filters
}), { dataKey: 'invoices', searchKey: 'inv_search' })
invoicesPagination.filters = reactive({ inv_status: null })
invoicesPagination.updateSearchParam = (key, val) => {
    invoicesPagination.filters[key] = val
    invoicesPagination.currentPage.value = 1
    invoicesPagination.performSearch()
}

const weeklyPagination = usePagination(props.weeklyPlans, 'payments.index', () => ({
    tab: 'weekly',
    ...weeklyPagination.filters
}), { dataKey: 'weeklyPlans' })
weeklyPagination.filters = reactive({ wp_status: null, wp_vendor_id: null, wp_month: null, wp_category: null })
weeklyPagination.updateSearchParam = (key, val) => {
    weeklyPagination.filters[key] = val
    weeklyPagination.currentPage.value = 1
    weeklyPagination.performSearch()
}

const recordsPagination = usePagination(props.records, 'payments.index', () => ({
    tab: 'records',
    ...recordsPagination.filters
}), { dataKey: 'records' })
recordsPagination.filters = reactive({ rec_status: null, rec_vendor_id: null })
recordsPagination.updateSearchParam = (key, val) => {
    recordsPagination.filters[key] = val
    recordsPagination.currentPage.value = 1
    recordsPagination.performSearch()
}

onMounted(() => {
    renewalsPagination.updateData(props.renewals)
    invoicesPagination.updateData(props.invoices)
    weeklyPagination.updateData(props.weeklyPlans)
    recordsPagination.updateData(props.records)
})

watch(() => props.renewals, v => renewalsPagination.updateData(v), { deep: true })
watch(() => props.invoices, v => invoicesPagination.updateData(v), { deep: true })
watch(() => props.weeklyPlans, v => weeklyPagination.updateData(v), { deep: true })
watch(() => props.records, v => recordsPagination.updateData(v), { deep: true })

/* ---- formatters ---- */
const formatAmount = (n) => Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const formatDateShort = (d) => {
    if (!d) return '—'
    const dt = new Date(d)
    return isNaN(dt) ? d : dt.toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: 'numeric' })
}
const dueDateClass = (d) => {
    if (!d) return 'text-gray-400'
    const today = new Date(); today.setHours(0, 0, 0, 0)
    const due = new Date(d); due.setHours(0, 0, 0, 0)
    if (due < today) return 'text-red-700 font-semibold'
    if ((due - today) / (1000 * 60 * 60 * 24) <= 7) return 'text-orange-600 font-semibold'
    return 'text-gray-700'
}
const statusPill = (s) => {
    const k = String(s || '').toLowerCase()
    if (['paid', 'posted', 'approved', 'released', 'active'].includes(k)) return 'bg-green-100 text-green-800'
    if (['overdue', 'rejected', 'terminated'].includes(k)) return 'bg-red-100 text-red-800'
    if (['due', 'pending', 'planned'].includes(k)) return 'bg-blue-100 text-blue-800'
    if (['paused', 'cancelled'].includes(k)) return 'bg-gray-200 text-gray-700'
    return 'bg-gray-100 text-gray-700'
}
const monitoringStatusPill = (s) => {
    const k = String(s || '').toLowerCase()
    if (k === 'open') return 'bg-green-100 text-green-800'
    if (k === 'pending') return 'bg-amber-100 text-amber-800'
    if (['closed', 'terminated'].includes(k)) return 'bg-red-100 text-red-800'
    return 'bg-gray-100 text-gray-700'
}
const approvalPill = (s) => {
    const k = String(s || '').toLowerCase()
    if (k === 'pending') return 'bg-yellow-100 text-yellow-800 border border-yellow-200'
    if (k === 'approved') return 'bg-emerald-100 text-emerald-800 border border-emerald-200'
    return 'bg-gray-100 text-gray-700 border border-gray-200'
}
const approvalLabel = (s) => {
    const k = String(s || '').toLowerCase()
    if (k === 'pending') return 'Pending Approval'
    if (k === 'approved') return 'Approved — Awaiting Payment'
    return s
}
const todayDateString = () => {
    const today = new Date()
    const month = String(today.getMonth() + 1).padStart(2, '0')
    const day = String(today.getDate()).padStart(2, '0')

    return `${today.getFullYear()}-${month}-${day}`
}
const dateOnly = (value) => String(value || '').slice(0, 10)
const isRenewalPaidAhead = (row) => {
    if (!row?.last_paid_on || row.latest_record_status) return false

    const nextDue = dateOnly(row.next_due_date)
    return !!nextDue && nextDue > todayDateString()
}
/* A row is "final" (no more actions possible) when it's paid or cancelled. */
const isRowFinal = (type, row) => {
    if (!row) return false
    const s = String(row.status || '').toLowerCase()
    if (type === 'invoice') return ['paid', 'cancelled'].includes(s)
    if (type === 'weekly')  return s === 'paid'
    if (type === 'renewal') return s === 'cancelled' || isRenewalPaidAhead(row)
    return false
}

/* ---- Cash schedule ---- */
const cashSchedule = computed(() => props.cashSchedule || { filters: {}, items: [], monthly: [], weekly: [], calendar: [], total: 0 })
const cashView = ref('monthly')
const cashFilters = reactive({
    month: props.cashSchedule?.filters?.month || todayDateString().slice(0, 7),
    vendor_id: props.cashSchedule?.filters?.vendor_id || '',
    source: props.cashSchedule?.filters?.source || 'all',
    include_paid: !!props.cashSchedule?.filters?.include_paid,
})
watch(() => props.cashSchedule?.filters, (filters) => {
    if (!filters) return
    cashFilters.month = filters.month || cashFilters.month
    cashFilters.vendor_id = filters.vendor_id || ''
    cashFilters.source = filters.source || 'all'
    cashFilters.include_paid = !!filters.include_paid
}, { deep: true })
const refreshCashSchedule = () => {
    router.get('/payments', {
        tab: 'schedule',
        cash_month: cashFilters.month,
        cash_vendor_id: cashFilters.vendor_id || undefined,
        cash_source: cashFilters.source === 'all' ? undefined : cashFilters.source,
        cash_include_paid: cashFilters.include_paid ? 1 : undefined,
    }, { preserveScroll: true, preserveState: true, replace: true })
}
const selectedMonthTotal = computed(() => {
    const row = (cashSchedule.value.monthly || []).find(item => item.month === cashFilters.month)
    return row?.total || 0
})
const formatMonthLabel = (value) => {
    if (!value) return '—'
    const [year, month] = String(value).split('-').map(Number)
    return new Date(year, (month || 1) - 1, 1).toLocaleDateString('en-PH', { year: 'numeric', month: 'long' })
}
const formatCompactAmount = (value) => {
    const number = Number(value || 0)
    if (number >= 1000000) return `${(number / 1000000).toFixed(1)}M`
    if (number >= 1000) return `${(number / 1000).toFixed(1)}K`
    return formatAmount(number)
}
const sourceLabel = (source) => {
    if (source === 'invoice') return 'SOA'
    if (source === 'renewal') return 'Renewal'
    if (source === 'weekly') return 'Weekly'
    if (source === 'service') return 'Telco'
    return source || 'Item'
}
const calendarDays = computed(() => {
    const [year, month] = String(cashFilters.month || todayDateString().slice(0, 7)).split('-').map(Number)
    const first = new Date(year, (month || 1) - 1, 1)
    const start = new Date(first)
    start.setDate(first.getDate() - first.getDay())
    const byDate = new Map((cashSchedule.value.calendar || []).map(day => [day.date, day]))
    return Array.from({ length: 42 }, (_, index) => {
        const date = new Date(start)
        date.setDate(start.getDate() + index)
        const key = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`
        const schedule = byDate.get(key)
        return {
            key,
            day: date.getDate(),
            inMonth: date.getMonth() === first.getMonth(),
            total: schedule?.total || 0,
            items: schedule?.items || [],
        }
    })
})

/* ---- Connectivity services (per-provider blocks for one location) ---- */
const serviceModal = reactive({ open: false, store_id: '' })
const serviceProviders = ref([])
const deletedServiceIds = ref([])

function blankProvider(role = 'primary') {
    return {
        id: null, role, vendor_id: null, telco: '', account_no: '', service_id: '',
        bandwidth: '', install_type: '', installation_date: '', billing_day: null,
        mrc: 0, currency: 'PHP', status: 'active', assignee_id: null, cc_emails: '', notes: '',
    }
}
const providerFromService = (sv) => ({
    id: sv.id,
    role: sv.role || 'primary',
    vendor_id: sv.vendor_id ?? null,
    telco: sv.telco || '',
    account_no: sv.account_no || '',
    service_id: sv.service_id || '',
    bandwidth: sv.bandwidth || '',
    install_type: sv.install_type || '',
    installation_date: sv.installation_date || '',
    billing_day: sv.billing_day ?? null,
    mrc: Number(sv.mrc || 0),
    currency: sv.currency || 'PHP',
    status: sv.status || 'active',
    assignee_id: sv.assignee_id ?? null,
    cc_emails: sv.cc_emails || '',
    notes: sv.notes || '',
})
const loadProvidersForStore = (storeId) => {
    const loc = (props.locations || []).find(l => l.id === storeId)
    const svcs = loc?.services || []
    serviceProviders.value = svcs.length ? svcs.map(providerFromService) : [blankProvider('primary')]
    deletedServiceIds.value = []
}
const openServiceModal = (location = null) => {
    deletedServiceIds.value = []
    if (location) {
        serviceModal.store_id = location.id
        loadProvidersForStore(location.id)
    } else {
        serviceModal.store_id = ''
        serviceProviders.value = [blankProvider('primary')]
    }
    serviceModal.open = true
}
/* When a location is picked from the selector, load its existing providers. */
watch(() => serviceModal.store_id, (id, prev) => {
    if (serviceModal.open && id && id !== prev) loadProvidersForStore(id)
})
const addProvider = () => {
    const role = serviceProviders.value.some(p => p.role === 'primary') ? 'secondary' : 'primary'
    serviceProviders.value.push(blankProvider(role))
}
const removeProvider = (idx) => {
    const p = serviceProviders.value[idx]
    if (p?.id) deletedServiceIds.value.push(p.id)
    serviceProviders.value.splice(idx, 1)
}
const submitServices = () => {
    if (!serviceModal.store_id) { showError('Select a location first.'); return }
    if (!serviceProviders.value.length && !deletedServiceIds.value.length) {
        showError('Add at least one provider.')
        return
    }
    put(`/payments/locations/${serviceModal.store_id}/services`, {
        services: serviceProviders.value,
        deleted_ids: deletedServiceIds.value,
    }, {
        preserveScroll: true,
        onSuccess: () => { serviceModal.open = false },
        onError: (errs) => showError(Object.values(errs).flat().join(', ') || 'Save failed'),
    })
}

/* ---- Location details form ---- */
const locationModal = reactive({ open: false, current: null })
const locationForm = reactive({ address: '', legal_company: '', company_applied_with: '', monitoring_status: 'OPEN' })
const openLocationModal = (loc) => {
    locationModal.current = loc
    Object.assign(locationForm, {
        address: loc.address || '',
        legal_company: loc.legal_company || '',
        company_applied_with: loc.company_applied_with || '',
        monitoring_status: loc.monitoring_status || 'OPEN',
    })
    locationModal.open = true
}
const submitLocation = () => {
    put(`/payments/locations/${locationModal.current.id}`, locationForm, {
        preserveScroll: true,
        onSuccess: () => { locationModal.open = false },
        onError: (errs) => showError(Object.values(errs).flat().join(', ') || 'Save failed'),
    })
}

/* ---- Connectivity import ---- */
const connectivityImportModal = reactive({ open: false, loading: false, file: null })
const connectivityImportResult = ref(null)
const openConnectivityImportModal = () => {
    connectivityImportModal.open = true
    connectivityImportModal.loading = false
    connectivityImportModal.file = null
    connectivityImportResult.value = null
}
const onConnectivityImportFileChange = (event) => {
    connectivityImportModal.file = event.target.files?.[0] || null
}
const submitConnectivityImport = async () => {
    if (!connectivityImportModal.file) {
        showError('Please choose an Excel file to import.')
        return
    }
    connectivityImportModal.loading = true
    connectivityImportResult.value = null
    const formData = new FormData()
    formData.append('file', connectivityImportModal.file)
    try {
        const response = await axios.post('/payments/services/import', formData, {
            headers: { 'Content-Type': 'multipart/form-data', 'Accept': 'application/json' },
        })
        connectivityImportResult.value = response.data
        showSuccess('Connectivity import completed.')
        router.reload({ only: ['locations', 'records', 'summary', 'cashSchedule'], preserveScroll: true })
    } catch (error) {
        connectivityImportResult.value = error.response?.data || { errors: ['Import failed.'] }
        showError(connectivityImportResult.value.errors?.[0] || 'Import failed.')
    } finally {
        connectivityImportModal.loading = false
    }
}

/* ---- Renewal form ---- */
const renewalModal = reactive({ open: false, editing: false, current: null })
const renewalForm = reactive(blankRenewal())
function blankRenewal() {
    return {
        vendor_id: '', service_type: '', sub_type: '', purpose: '',
        unit_cost: 0, qty: 1, total_amount: 0, currency: 'PHP',
        cycle: 'monthly', cycle_anchor_date: '', next_due_date: '',
        expiration_date: '', payment_terms: '', assignee_user_id: null,
        cc_emails: '',
        status: 'active', notes: '',
    }
}
/* Keep renewal total_amount in sync with unit_cost × qty so the computed value is what gets submitted */
watch(
    () => [renewalForm.unit_cost, renewalForm.qty],
    ([uc, q]) => { renewalForm.total_amount = Number(uc || 0) * Number(q || 0) },
)
const openRenewalModal = (r = null) => {
    renewalModal.editing = !!r; renewalModal.current = r
    Object.assign(renewalForm, blankRenewal(), r ? {
        ...r,
        cycle_anchor_date: r.cycle_anchor_date || '',
        next_due_date: r.next_due_date || '',
        expiration_date: r.expiration_date || '',
    } : {})
    renewalModal.open = true
}
const submitRenewal = () => {
    renewalForm.total_amount = Number(renewalForm.unit_cost || 0) * Number(renewalForm.qty || 0)
    const url = renewalModal.editing ? `/payments/renewals/${renewalModal.current.id}` : '/payments/renewals'
    const method = renewalModal.editing ? put : post
    method(url, renewalForm, {
        preserveScroll: true,
        onSuccess: () => { renewalModal.open = false },
        onError: (errs) => showError(Object.values(errs).flat().join(', ') || 'Save failed'),
    })
}

/* ---- Invoice import ---- */
const importModal = reactive({ open: false, loading: false, file: null })
const importResult = ref(null)
const openImportModal = () => {
    importModal.open = true
    importModal.loading = false
    importModal.file = null
    importResult.value = null
}
const onImportFileChange = (event) => {
    importModal.file = event.target.files?.[0] || null
}
const submitInvoiceImport = async () => {
    if (!importModal.file) {
        showError('Please choose an Excel file to import.')
        return
    }

    importModal.loading = true
    importResult.value = null

    const formData = new FormData()
    formData.append('file', importModal.file)

    try {
        const response = await axios.post('/payments/invoices/import', formData, {
            headers: { 'Content-Type': 'multipart/form-data', 'Accept': 'application/json' },
        })
        importResult.value = response.data
        showSuccess('SOA invoice import completed.')
        router.reload({ only: ['invoices', 'records', 'summary', 'cashSchedule'], preserveScroll: true })
    } catch (error) {
        importResult.value = error.response?.data || { errors: ['Import failed.'] }
        showError(importResult.value.errors?.[0] || 'Import failed.')
    } finally {
        importModal.loading = false
    }
}

/* ---- Renewal import ---- */
const renewalImportModal = reactive({ open: false, loading: false, file: null })
const renewalImportResult = ref(null)
const openRenewalImportModal = () => {
    renewalImportModal.open = true
    renewalImportModal.loading = false
    renewalImportModal.file = null
    renewalImportResult.value = null
}
const onRenewalImportFileChange = (event) => {
    renewalImportModal.file = event.target.files?.[0] || null
}
const submitRenewalImport = async () => {
    if (!renewalImportModal.file) {
        showError('Please choose an Excel file to import.')
        return
    }

    renewalImportModal.loading = true
    renewalImportResult.value = null

    const formData = new FormData()
    formData.append('file', renewalImportModal.file)

    try {
        const response = await axios.post('/payments/renewals/import', formData, {
            headers: { 'Content-Type': 'multipart/form-data', 'Accept': 'application/json' },
        })
        renewalImportResult.value = response.data
        showSuccess('Renewal import completed.')
        router.reload({ only: ['renewals', 'records', 'summary', 'cashSchedule'], preserveScroll: true })
    } catch (error) {
        renewalImportResult.value = error.response?.data || { errors: ['Import failed.'] }
        showError(renewalImportResult.value.errors?.[0] || 'Import failed.')
    } finally {
        renewalImportModal.loading = false
    }
}

/* ---- Weekly plan import ---- */
const weeklyImportModal = reactive({ open: false, loading: false, file: null })
const weeklyImportResult = ref(null)
const openWeeklyImportModal = () => {
    weeklyImportModal.open = true
    weeklyImportModal.loading = false
    weeklyImportModal.file = null
    weeklyImportResult.value = null
}
const onWeeklyImportFileChange = (event) => {
    weeklyImportModal.file = event.target.files?.[0] || null
}
const submitWeeklyImport = async () => {
    if (!weeklyImportModal.file) {
        showError('Please choose an Excel file to import.')
        return
    }

    weeklyImportModal.loading = true
    weeklyImportResult.value = null

    const formData = new FormData()
    formData.append('file', weeklyImportModal.file)

    try {
        const response = await axios.post('/payments/weekly-plans/import', formData, {
            headers: { 'Content-Type': 'multipart/form-data', 'Accept': 'application/json' },
        })
        weeklyImportResult.value = response.data
        showSuccess('Weekly plan import completed.')
        router.reload({ only: ['weeklyPlans', 'records', 'summary', 'cashSchedule'], preserveScroll: true })
    } catch (error) {
        weeklyImportResult.value = error.response?.data || { errors: ['Import failed.'] }
        showError(weeklyImportResult.value.errors?.[0] || 'Import failed.')
    } finally {
        weeklyImportModal.loading = false
    }
}

/* ---- Invoice form ---- */
const invoiceModal = reactive({ open: false, editing: false, current: null })
const invoiceForm = reactive(blankInvoice())
function blankInvoice() {
    return {
        vendor_id: '', apv_no: '', store_code: '', po_number: '', si_number: '',
        si_date: '', due_date: '', invoice_amount: 0, outstanding_amount: 0,
        currency: 'PHP', status: 'Pending', remarks: '', assignee_user_id: null,
        cc_emails: '',
    }
}
const openInvoiceModal = (i = null) => {
    invoiceModal.editing = !!i; invoiceModal.current = i
    Object.assign(invoiceForm, blankInvoice(), i ? {
        ...i,
        si_date: i.si_date || '',
        due_date: i.due_date || '',
    } : {})
    invoiceModal.open = true
}
const submitInvoice = () => {
    const url = invoiceModal.editing ? `/payments/invoices/${invoiceModal.current.id}` : '/payments/invoices'
    const method = invoiceModal.editing ? put : post
    method(url, invoiceForm, {
        preserveScroll: true,
        onSuccess: () => { invoiceModal.open = false },
        onError: (errs) => showError(Object.values(errs).flat().join(', ') || 'Save failed'),
    })
}

/* ---- Overpayment ---- */
const overpaymentModal = reactive({ open: false })
const overpaymentForm = reactive({
    vendor_id: '', collection_date: '', check_details: '',
    amount: 0, remarks: '', applied_to_invoice_id: null,
})
const openOverpaymentModal = () => {
    Object.assign(overpaymentForm, { vendor_id: '', collection_date: '', check_details: '', amount: 0, remarks: '', applied_to_invoice_id: null })
    overpaymentModal.open = true
}
const submitOverpayment = () => {
    post('/payments/overpayments', overpaymentForm, {
        preserveScroll: true,
        onSuccess: () => { overpaymentModal.open = false },
        onError: (errs) => showError(Object.values(errs).flat().join(', ') || 'Save failed'),
    })
}

/* ---- Weekly Plan ---- */
const weeklyModal = reactive({ open: false, editing: false, current: null })
const weeklyForm = reactive(blankWeekly())
function blankWeekly() {
    return { vendor_id: '', project_label: '', month: '', week_no: null, week_date: '', amount: 0, category: '', notes: '', assignee_user_id: null, status: 'Planned' }
}
const openWeeklyModal = (w = null) => {
    weeklyModal.editing = !!w; weeklyModal.current = w
    Object.assign(weeklyForm, blankWeekly(), w ? { ...w, week_date: w.week_date || '' } : {})
    weeklyModal.open = true
}
const submitWeekly = () => {
    const url = weeklyModal.editing ? `/payments/weekly-plans/${weeklyModal.current.id}` : '/payments/weekly-plans'
    const method = weeklyModal.editing ? put : post
    method(url, weeklyForm, {
        preserveScroll: true,
        onSuccess: () => { weeklyModal.open = false },
        onError: (errs) => showError(Object.values(errs).flat().join(', ') || 'Save failed'),
    })
}

/* ---- Submit for approval ---- */
const submitModal = reactive({ open: false, payableType: '', payable: null })
const submitForm = reactive({ amount: 0, remarks: '', payable_type: '', payable_id: null })
const openSubmitModal = (type, payable) => {
    submitModal.payableType = type; submitModal.payable = payable; submitModal.open = true
    submitForm.payable_type = type
    submitForm.payable_id = payable.id
    submitForm.amount = type === 'invoice' ? Number(payable.outstanding_amount || 0)
                     : type === 'renewal' ? Number(payable.total_amount || 0)
                     : type === 'service' ? Number(payable.mrc || 0)
                     : Number(payable.amount || 0)
    submitForm.remarks = ''
}
const confirmSubmit = () => {
    post('/payments/records', submitForm, {
        preserveScroll: true,
        onSuccess: () => { submitModal.open = false },
        onError: (errs) => showError(Object.values(errs).flat().join(', ') || 'Submit failed'),
    })
}

/* ---- Approve modal ---- */
const approveModal = reactive({ open: false, record: null })
const approveForm = reactive({ remarks: '' })
const openApproveModal = (rec) => {
    approveModal.record = rec
    approveForm.remarks = ''
    approveModal.open = true
}
const confirmApprove = () => {
    if (!approveModal.record) return
    post(`/payments/records/${approveModal.record.id}/approve`, { remarks: approveForm.remarks }, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => { approveModal.open = false },
        onError: (errs) => showError(Object.values(errs).flat().join(', ') || 'Approve failed'),
    })
}

/* ---- Reject modal ---- */
const rejectModal = reactive({ open: false, record: null })
const rejectForm = reactive({ remarks: '' })
const openRejectModal = (rec) => {
    rejectModal.record = rec
    rejectForm.remarks = ''
    rejectModal.open = true
}
const confirmReject = () => {
    if (!rejectModal.record) return
    if (!rejectForm.remarks?.trim()) {
        showError('A rejection reason is required.')
        return
    }
    post(`/payments/records/${rejectModal.record.id}/reject`, { remarks: rejectForm.remarks }, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => { rejectModal.open = false },
        onError: (errs) => showError(Object.values(errs).flat().join(', ') || 'Reject failed'),
    })
}

/* ---- Mark Paid ---- */
const markPaidModal = reactive({ open: false, record: null })
const markPaidForm = reactive({ paid_on: new Date().toISOString().slice(0, 10), reference_no: '' })
const openMarkPaidModal = (rec) => {
    markPaidModal.record = rec
    markPaidModal.open = true
    markPaidForm.paid_on = new Date().toISOString().slice(0, 10)
    markPaidForm.reference_no = ''
}
const openMarkPaidForPayable = (payableType, payableId) => {
    const rec = (props.records?.data || []).find(
        r => r.payable_type === payableType && r.payable_id === payableId && r.status === 'approved'
    )
    if (!rec) {
        showError('Approved payment record not found. Try refreshing the page.')
        return
    }
    openMarkPaidModal(rec)
}
const confirmMarkPaid = () => {
    post(`/payments/records/${markPaidModal.record.id}/mark-paid`, markPaidForm, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => { markPaidModal.open = false },
        onError: (errs) => showError(Object.values(errs).flat().join(', ') || 'Mark paid failed'),
    })
}

const sendManualReminder = (type, id) => {
    post(`/payments/${type}/${id}/remind`, {}, {
        preserveScroll: true,
        onSuccess: () => showSuccess('Reminder sent successfully'),
        onError: (errs) => showError(Object.values(errs).flat().join(', ') || 'Failed to send reminder'),
    })
}

/* ---- Settings ---- */
const settingsForm = reactive({
    approval_levels: props.settings?.approval_levels || 2,
    default_currency: props.settings?.default_currency || 'PHP',
    global_bcc: props.settings?.global_bcc || '',
    reminders_enabled: !!props.settings?.reminders_enabled,
})
const saveSettings = () => {
    put('/payments/settings', settingsForm, { preserveScroll: true })
}

/* ---- Generic delete (modal-driven) ---- */
const deleteModal = reactive({ open: false, segment: '', row: null, label: 'row' })
const confirmDelete = (segment, row, label = 'row') => {
    deleteModal.segment = segment
    deleteModal.row = row
    deleteModal.label = label
    deleteModal.open = true
}
const performDelete = () => {
    if (!deleteModal.row) return
    destroy(`/payments/${deleteModal.segment}/${deleteModal.row.id}`, {
        preserveScroll: true,
        onSuccess: () => { deleteModal.open = false },
        onError: (errs) => showError(Object.values(errs).flat().join(', ') || 'Delete failed'),
    })
}
</script>
