<template>
    <AppLayout title="Payments & SOA Monitoring">
        <div class="py-8">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                <!-- Header -->
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900">Payments & SOA Monitoring</h1>
                        <p class="text-sm text-gray-500">Track vendor renewals, invoices, weekly plans, and approval workflow</p>
                    </div>
                </div>

                <!-- Tabs -->
                <div class="border-b border-gray-200">
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

                <!-- DASHBOARD TAB -->
                <div v-if="currentTab === 'dashboard'" class="space-y-6">
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                        <div class="bg-white rounded-xl border border-gray-100 p-5 shadow-sm">
                            <p class="text-xs font-bold text-gray-500 uppercase tracking-wider">Total Outstanding</p>
                            <p class="text-2xl font-bold text-gray-900 mt-2">₱{{ formatAmount(summary.total_outstanding) }}</p>
                        </div>
                        <div class="bg-white rounded-xl border border-gray-100 p-5 shadow-sm">
                            <p class="text-xs font-bold text-gray-500 uppercase tracking-wider">Due This Month</p>
                            <p class="text-2xl font-bold text-orange-600 mt-2">₱{{ formatAmount(summary.due_this_month) }}</p>
                        </div>
                        <div class="bg-white rounded-xl border border-gray-100 p-5 shadow-sm">
                            <p class="text-xs font-bold text-gray-500 uppercase tracking-wider">Overdue Invoices</p>
                            <p class="text-2xl font-bold text-red-600 mt-2">{{ summary.overdue_count }}</p>
                        </div>
                        <div class="bg-white rounded-xl border border-gray-100 p-5 shadow-sm">
                            <p class="text-xs font-bold text-gray-500 uppercase tracking-wider">Upcoming Renewals (30d)</p>
                            <p class="text-2xl font-bold text-blue-600 mt-2">{{ summary.upcoming_renewals_30d }}</p>
                        </div>
                        <div class="bg-white rounded-xl border border-gray-100 p-5 shadow-sm">
                            <p class="text-xs font-bold text-gray-500 uppercase tracking-wider">Annual Renewal Spend</p>
                            <p class="text-2xl font-bold text-purple-600 mt-2">₱{{ formatAmount(summary.annual_renewal_spend) }}</p>
                        </div>
                        <div class="bg-white rounded-xl border border-gray-100 p-5 shadow-sm">
                            <p class="text-xs font-bold text-gray-500 uppercase tracking-wider">Pending Approvals</p>
                            <p class="text-2xl font-bold text-yellow-600 mt-2">{{ summary.pending_approvals }}</p>
                        </div>
                    </div>
                    <div class="bg-blue-50 border border-blue-100 rounded-lg p-4 text-sm text-blue-800">
                        <p class="font-semibold mb-1">Quick Tips</p>
                        <ul class="list-disc ml-5 space-y-1">
                            <li>Add recurring subscriptions (Google Workspace, anti-virus, firewalls, cloud) under the <strong>Renewals</strong> tab.</li>
                            <li>Track aged SOAs and apply overpayments under <strong>SOA Invoices</strong>.</li>
                            <li>Plan weekly disbursements per project (Datche, Vantage, etc.) under <strong>Weekly Plans</strong>.</li>
                            <li>Submit a payment for the approval chain → mark paid once fully approved.</li>
                            <li>Due-date reminders are sent automatically at 30 / 7 / 1 / 0 days and daily after overdue.</li>
                        </ul>
                    </div>
                </div>

                <!-- RENEWALS TAB -->
                <div v-if="currentTab === 'renewals'">
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
                            <button v-if="hasPermission('payments.create')" @click="openRenewalModal()"
                                    class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium shadow-sm whitespace-nowrap inline-flex items-center">
                                + New Renewal
                            </button>
                        </template>
                        <template #header>
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Vendor / Service</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Cycle</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Next Due</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Assignee</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                            </tr>
                        </template>
                        <template #body="{ data }">
                            <tr v-for="r in data" :key="r.id" class="hover:bg-gray-50">
                                <td class="px-4 py-3">
                                    <div class="text-sm font-medium text-gray-900">{{ r.vendor?.name || '—' }}</div>
                                    <div class="text-xs text-gray-500">{{ r.service_type }}<span v-if="r.sub_type"> · {{ r.sub_type }}</span></div>
                                    <div v-if="r.purpose" class="text-xs text-gray-400">{{ r.purpose }}</div>
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
                                        <IconBtn v-if="hasPermission('payments.submit') && !r.latest_record_status" kind="submit" title="Submit for Approval" @click="openSubmitModal('renewal', r)" />
                                        <IconBtn v-if="r.latest_record_status === 'approved' && hasPermission('payments.mark_paid')" kind="paid" title="Mark as Paid" @click="openMarkPaidForPayable('renewal', r.id)" />
                                        <IconBtn v-if="hasPermission('payments.edit') && r.latest_record_status !== 'approved'" kind="edit" title="Edit Renewal" @click="openRenewalModal(r)" />
                                        <IconBtn v-if="hasPermission('payments.delete') && !r.latest_record_status" kind="delete" title="Delete Renewal" @click="confirmDelete('renewals', r, 'renewal')" />
                                    </div>
                                    <span v-else class="text-xs text-gray-400 italic">—</span>
                                </td>
                            </tr>
                        </template>
                    </DataTable>
                </div>

                <!-- INVOICES TAB -->
                <div v-if="currentTab === 'invoices'" class="space-y-6">
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
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">APV / SI</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Vendor / Store</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Invoice</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Outstanding</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Due / Aging</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                            </tr>
                        </template>
                        <template #body="{ data }">
                            <tr v-for="i in data" :key="i.id" class="hover:bg-gray-50">
                                <td class="px-4 py-3 text-sm">
                                    <div class="font-medium text-gray-900">{{ i.apv_no || '—' }}</div>
                                    <div class="text-xs text-gray-500">SI {{ i.si_number || '—' }}</div>
                                </td>
                                <td class="px-4 py-3 text-sm">
                                    <div>{{ i.vendor?.name || '—' }}</div>
                                    <div class="text-xs text-gray-500">{{ i.store_code || '' }} {{ i.po_number ? '· PO ' + i.po_number : '' }}</div>
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
                                        <IconBtn v-if="hasPermission('payments.submit') && !i.latest_record_status" kind="submit" title="Submit for Approval" @click="openSubmitModal('invoice', i)" />
                                        <IconBtn v-if="i.latest_record_status === 'approved' && hasPermission('payments.mark_paid')" kind="paid" title="Mark as Paid" @click="openMarkPaidForPayable('invoice', i.id)" />
                                        <IconBtn v-if="hasPermission('payments.edit') && i.latest_record_status !== 'approved'" kind="edit" title="Edit Invoice" @click="openInvoiceModal(i)" />
                                        <IconBtn v-if="hasPermission('payments.delete') && !i.latest_record_status" kind="delete" title="Delete Invoice" @click="confirmDelete('invoices', i, 'invoice')" />
                                    </div>
                                    <span v-else class="text-xs text-gray-400 italic">—</span>
                                </td>
                            </tr>
                        </template>
                    </DataTable>

                    <!-- Overpayments mini-table -->
                    <div class="bg-white rounded-xl border border-gray-100 p-4 shadow-sm">
                        <div class="flex justify-between items-center mb-3">
                            <h3 class="font-semibold text-gray-900">Recent Overpayments</h3>
                        </div>
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-xs text-gray-500 uppercase border-b">
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
                                    <td class="py-2 text-gray-600">{{ op.check_details || '—' }}</td>
                                    <td class="py-2 text-right font-mono">{{ formatAmount(op.amount) }}</td>
                                    <td class="py-2 text-xs">{{ op.invoice ? ('APV ' + (op.invoice.apv_no || op.invoice.si_number || op.invoice.id)) : '— unapplied' }}</td>
                                    <td class="py-2 text-right">
                                        <IconBtn v-if="hasPermission('payments.delete')" kind="delete" title="Delete Overpayment" @click="confirmDelete('overpayments', op, 'overpayment')" />
                                    </td>
                                </tr>
                                <tr v-if="!(overpayments?.data?.length)"><td colspan="6" class="py-4 text-center text-gray-400 text-xs">No overpayments yet</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- WEEKLY PLANS TAB -->
                <div v-if="currentTab === 'weekly'">
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
                            <button v-if="hasPermission('payments.create')" @click="openWeeklyModal()"
                                    class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium shadow-sm whitespace-nowrap inline-flex items-center">
                                + New Plan Row
                            </button>
                        </template>
                        <template #header>
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Week</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Vendor / Project</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Category</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                            </tr>
                        </template>
                        <template #body="{ data }">
                            <tr v-for="w in data" :key="w.id" class="hover:bg-gray-50">
                                <td class="px-4 py-3 text-sm">
                                    <div>{{ w.month || '—' }} <span v-if="w.week_no" class="text-xs text-gray-500">/ Wk {{ w.week_no }}</span></div>
                                    <div class="text-xs text-gray-500">{{ w.week_date || '' }}</div>
                                </td>
                                <td class="px-4 py-3 text-sm">
                                    <div>{{ w.vendor?.name || '—' }}</div>
                                    <div class="text-xs text-gray-500">{{ w.project_label || '' }}</div>
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
                                    <span v-else class="text-xs text-gray-400 italic">—</span>
                                </td>
                            </tr>
                        </template>
                    </DataTable>
                </div>

                <!-- RECORDS / APPROVAL TAB -->
                <div v-if="currentTab === 'records'">
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
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">#</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Payable</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Vendor</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status / Level</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Paid On / Ref</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                            </tr>
                        </template>
                        <template #body="{ data }">
                            <tr v-for="rec in data" :key="rec.id" class="hover:bg-gray-50">
                                <td class="px-4 py-3 text-sm">{{ rec.id }}</td>
                                <td class="px-4 py-3 text-sm capitalize">{{ rec.payable_type }} #{{ rec.payable_id }}</td>
                                <td class="px-4 py-3 text-sm">{{ rec.vendor?.name || '—' }}</td>
                                <td class="px-4 py-3 text-right text-sm font-mono">{{ formatAmount(rec.amount) }}</td>
                                <td class="px-4 py-3 text-sm">
                                    <span :class="statusPill(rec.status)" class="px-2 py-0.5 text-xs rounded-full font-semibold">{{ rec.status }}</span>
                                    <div class="text-xs text-gray-500 mt-0.5">Lvl {{ rec.current_approval_level }} / {{ rec.approver_data?.levels || '?' }}</div>
                                </td>
                                <td class="px-4 py-3 text-xs">{{ rec.paid_on || '—' }}<div v-if="rec.reference_no" class="text-gray-500">{{ rec.reference_no }}</div></td>
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

                <!-- SETTINGS TAB -->
                <div v-if="currentTab === 'settings'" class="bg-white rounded-xl border border-gray-100 p-6 shadow-sm space-y-4 max-w-2xl">
                    <h3 class="font-semibold text-gray-900">Reminder & Approval Settings</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Approval Levels</label>
                            <input v-model.number="settingsForm.approval_levels" type="number" min="1" max="5"
                                   class="block w-full border-gray-300 rounded-lg text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Default Currency</label>
                            <input v-model="settingsForm.default_currency" type="text" maxlength="8"
                                   class="block w-full border-gray-300 rounded-lg text-sm">
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Global BCC (email)</label>
                        <input v-model="settingsForm.global_bcc" type="text"
                               class="block w-full border-gray-300 rounded-lg text-sm" placeholder="finance-bcc@company.com">
                    </div>
                    <div class="flex items-center">
                        <input v-model="settingsForm.reminders_enabled" type="checkbox" id="rem_enabled"
                               class="rounded border-gray-300 text-blue-600">
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
        </div>

        <!-- RENEWAL MODAL -->
        <Modal v-if="renewalModal.open" @close="renewalModal.open = false" :title="renewalModal.editing ? 'Edit Renewal' : 'New Renewal'">
            <form @submit.prevent="submitRenewal" class="space-y-3">
                <div class="grid grid-cols-2 gap-3">
                    <FormField label="Vendor" required>
                        <Autocomplete v-model="renewalForm.vendor_id" :options="vendorOptions" placeholder="Search vendor..." />
                    </FormField>
                    <FormField label="Service Type" required>
                        <input v-model="renewalForm.service_type" required class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm" />
                    </FormField>
                    <FormField label="Sub-Type">
                        <input v-model="renewalForm.sub_type" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm" />
                    </FormField>
                    <FormField label="Purpose">
                        <input v-model="renewalForm.purpose" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm" />
                    </FormField>
                    <FormField label="Unit Cost" required>
                        <input v-model.number="renewalForm.unit_cost" type="number" step="0.01" required class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm" />
                    </FormField>
                    <FormField label="Qty" required>
                        <input v-model.number="renewalForm.qty" type="number" min="1" required class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm" />
                    </FormField>
                    <FormField label="Total Amount (auto-calc)">
                        <input :value="(Number(renewalForm.unit_cost || 0) * Number(renewalForm.qty || 0)).toFixed(2)"
                               type="text" readonly
                               class="block w-full border-gray-300 bg-gray-50 rounded-lg shadow-sm text-sm font-mono text-gray-700 cursor-not-allowed" />
                    </FormField>
                    <FormField label="Currency">
                        <Autocomplete v-model="renewalForm.currency" :options="currencyOptions" placeholder="Select currency..." />
                    </FormField>
                    <FormField label="Cycle" required>
                        <Autocomplete v-model="renewalForm.cycle" :options="cycleOptions" placeholder="Select cycle..." />
                    </FormField>
                    <FormField label="Cycle Anchor Date">
                        <input v-model="renewalForm.cycle_anchor_date" type="date" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm" />
                    </FormField>
                    <FormField label="Next Due Date">
                        <input v-model="renewalForm.next_due_date" type="date" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm" />
                    </FormField>
                    <FormField label="Expiration Date">
                        <input v-model="renewalForm.expiration_date" type="date" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm" />
                    </FormField>
                    <FormField label="Payment Terms">
                        <input v-model="renewalForm.payment_terms" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm" />
                    </FormField>
                    <FormField label="Assignee">
                        <Autocomplete v-model="renewalForm.assignee_user_id" :options="userOptions" placeholder="Search user..." />
                    </FormField>
                    <FormField label="Status">
                        <Autocomplete v-model="renewalForm.status" :options="renewalStatusOptions" placeholder="Select status..." />
                    </FormField>
                </div>
                <FormField label="Notes">
                    <textarea v-model="renewalForm.notes" rows="2" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm"></textarea>
                </FormField>
                <ModalFooter @cancel="renewalModal.open = false" :submit-label="renewalModal.editing ? 'Update' : 'Create'" />
            </form>
        </Modal>

        <!-- INVOICE MODAL -->
        <Modal v-if="invoiceModal.open" @close="invoiceModal.open = false" :title="invoiceModal.editing ? 'Edit Invoice' : 'New Invoice'">
            <form @submit.prevent="submitInvoice" class="space-y-3">
                <div class="grid grid-cols-2 gap-3">
                    <FormField label="Vendor" required>
                        <Autocomplete v-model="invoiceForm.vendor_id" :options="vendorOptions" placeholder="Search vendor..." />
                    </FormField>
                    <FormField label="APV No.">
                        <input v-model="invoiceForm.apv_no" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm" />
                    </FormField>
                    <FormField label="Store Code">
                        <Autocomplete v-model="invoiceForm.store_code" :options="storeOptions" placeholder="Search store code..." />
                    </FormField>
                    <FormField label="PO Number">
                        <input v-model="invoiceForm.po_number" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm" />
                    </FormField>
                    <FormField label="SI Number">
                        <input v-model="invoiceForm.si_number" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm" />
                    </FormField>
                    <FormField label="SI Date">
                        <input v-model="invoiceForm.si_date" type="date" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm" />
                    </FormField>
                    <FormField label="Due Date">
                        <input v-model="invoiceForm.due_date" type="date" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm" />
                    </FormField>
                    <FormField label="Invoice Amount" required>
                        <input v-model.number="invoiceForm.invoice_amount" type="number" step="0.01" required class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm" />
                    </FormField>
                    <FormField label="Outstanding Amount">
                        <input v-model.number="invoiceForm.outstanding_amount" type="number" step="0.01" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm" />
                    </FormField>
                    <FormField label="Status">
                        <Autocomplete v-model="invoiceForm.status" :options="invoiceStatusOptions" placeholder="Select status..." />
                    </FormField>
                    <FormField label="Assignee">
                        <Autocomplete v-model="invoiceForm.assignee_user_id" :options="userOptions" placeholder="Search user..." />
                    </FormField>
                </div>
                <FormField label="Remarks">
                    <textarea v-model="invoiceForm.remarks" rows="2" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm"></textarea>
                </FormField>
                <ModalFooter @cancel="invoiceModal.open = false" :submit-label="invoiceModal.editing ? 'Update' : 'Create'" />
            </form>
        </Modal>

        <!-- OVERPAYMENT MODAL -->
        <Modal v-if="overpaymentModal.open" @close="overpaymentModal.open = false" title="Apply Overpayment">
            <form @submit.prevent="submitOverpayment" class="space-y-3">
                <FormField label="Vendor" required>
                    <Autocomplete v-model="overpaymentForm.vendor_id" :options="vendorOptions" placeholder="Search vendor..." />
                </FormField>
                <div class="grid grid-cols-2 gap-3">
                    <FormField label="Collection Date">
                        <input v-model="overpaymentForm.collection_date" type="date" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm" />
                    </FormField>
                    <FormField label="Check Details">
                        <input v-model="overpaymentForm.check_details" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm" />
                    </FormField>
                    <FormField label="Amount" required>
                        <input v-model.number="overpaymentForm.amount" type="number" step="0.01" required class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm" />
                    </FormField>
                    <FormField label="Apply to Invoice (optional)">
                        <Autocomplete v-model="overpaymentForm.applied_to_invoice_id" :options="invoiceForOverpaymentOptions" placeholder="Search invoice..." />
                    </FormField>
                </div>
                <FormField label="Remarks">
                    <textarea v-model="overpaymentForm.remarks" rows="2" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm"></textarea>
                </FormField>
                <ModalFooter @cancel="overpaymentModal.open = false" submit-label="Record" />
            </form>
        </Modal>

        <!-- WEEKLY PLAN MODAL -->
        <Modal v-if="weeklyModal.open" @close="weeklyModal.open = false" :title="weeklyModal.editing ? 'Edit Plan Row' : 'New Plan Row'">
            <form @submit.prevent="submitWeekly" class="space-y-3">
                <div class="grid grid-cols-2 gap-3">
                    <FormField label="Vendor" required>
                        <Autocomplete v-model="weeklyForm.vendor_id" :options="vendorOptions" placeholder="Search vendor..." />
                    </FormField>
                    <FormField label="Project Label">
                        <input v-model="weeklyForm.project_label" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm" />
                    </FormField>
                    <FormField label="Month">
                        <input v-model="weeklyForm.month" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm" placeholder="Jan" />
                    </FormField>
                    <FormField label="Week #">
                        <input v-model.number="weeklyForm.week_no" type="number" min="1" max="53" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm" />
                    </FormField>
                    <FormField label="Week Date">
                        <input v-model="weeklyForm.week_date" type="date" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm" />
                    </FormField>
                    <FormField label="Amount" required>
                        <input v-model.number="weeklyForm.amount" type="number" step="0.01" required class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm" />
                    </FormField>
                    <FormField label="Category">
                        <Autocomplete v-model="weeklyForm.category" :options="weeklyCategoryOptions" placeholder="Select category..." />
                    </FormField>
                    <FormField label="Status">
                        <Autocomplete v-model="weeklyForm.status" :options="weeklyStatusOptions" placeholder="Select status..." />
                    </FormField>
                </div>
                <FormField label="Notes">
                    <textarea v-model="weeklyForm.notes" rows="2" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm"></textarea>
                </FormField>
                <ModalFooter @cancel="weeklyModal.open = false" :submit-label="weeklyModal.editing ? 'Update' : 'Create'" />
            </form>
        </Modal>

        <!-- SUBMIT FOR APPROVAL MODAL -->
        <Modal v-if="submitModal.open" @close="submitModal.open = false" title="Submit for Approval">
            <form @submit.prevent="confirmSubmit" class="space-y-3">
                <p class="text-sm text-gray-600">Submitting <strong>{{ submitModal.payableType }}</strong> for vendor approval chain.</p>
                <FormField label="Amount" required>
                    <input v-model.number="submitForm.amount" type="number" step="0.01" required class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm" />
                </FormField>
                <FormField label="Remarks">
                    <textarea v-model="submitForm.remarks" rows="2" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm"></textarea>
                </FormField>
                <ModalFooter @cancel="submitModal.open = false" submit-label="Submit" />
            </form>
        </Modal>

        <!-- MARK PAID MODAL -->
        <Modal v-if="markPaidModal.open" @close="markPaidModal.open = false" title="Mark as Paid">
            <form @submit.prevent="confirmMarkPaid" class="space-y-3">
                <FormField label="Paid On" required>
                    <input v-model="markPaidForm.paid_on" type="date" required class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm" />
                </FormField>
                <FormField label="Reference No.">
                    <input v-model="markPaidForm.reference_no" class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm" />
                </FormField>
                <ModalFooter @cancel="markPaidModal.open = false" submit-label="Post Payment" />
            </form>
        </Modal>

        <!-- DELETE CONFIRM MODAL -->
        <Modal v-if="deleteModal.open" @close="deleteModal.open = false" title="Confirm Delete">
            <div class="space-y-4">
                <p class="text-sm text-gray-700">
                    Are you sure you want to delete this <strong>{{ deleteModal.label }}</strong>?
                    This action cannot be undone.
                </p>
                <div class="flex justify-end space-x-3 pt-4 border-t">
                    <button type="button" @click="deleteModal.open = false"
                            class="px-4 py-2 text-sm font-semibold text-gray-600 bg-gray-100 rounded-lg hover:bg-gray-200">
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
                <p class="text-sm text-gray-700">
                    Approve payment record <strong>#{{ approveModal.record?.id }}</strong>
                    for vendor <strong>{{ approveModal.record?.vendor?.name || '—' }}</strong>
                    (₱{{ formatAmount(approveModal.record?.amount) }})?
                </p>
                <FormField label="Remarks (optional)">
                    <textarea v-model="approveForm.remarks" rows="2"
                              class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm"></textarea>
                </FormField>
                <div class="flex justify-end space-x-3 pt-4 border-t">
                    <button type="button" @click="approveModal.open = false"
                            class="px-4 py-2 text-sm font-semibold text-gray-600 bg-gray-100 rounded-lg hover:bg-gray-200">
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
                <p class="text-sm text-gray-700">
                    Reject payment record <strong>#{{ rejectModal.record?.id }}</strong>?
                    Please provide a reason — this will be sent to the requester.
                </p>
                <FormField label="Reason" required>
                    <textarea v-model="rejectForm.remarks" rows="3" required
                              class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm"
                              placeholder="Explain why this payment is being rejected..."></textarea>
                </FormField>
                <div class="flex justify-end space-x-3 pt-4 border-t">
                    <button type="button" @click="rejectModal.open = false"
                            class="px-4 py-2 text-sm font-semibold text-gray-600 bg-gray-100 rounded-lg hover:bg-gray-200">
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
import AppLayout from '@/Layouts/AppLayout.vue'
import DataTable from '@/Components/DataTable.vue'
import Autocomplete from '@/Components/Autocomplete.vue'
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
    tab: { type: String, default: 'dashboard' },
    summary: { type: Object, default: () => ({}) },
    vendors: { type: Array, default: () => [] },
    stores: { type: Array, default: () => [] },
    users: { type: Array, default: () => [] },
    currencies: { type: Array, default: () => ['PHP'] },
    cycles: { type: Array, default: () => [] },
    invoiceStatuses: { type: Array, default: () => [] },
    renewalStatuses: { type: Array, default: () => [] },
    weeklyStatuses: { type: Array, default: () => [] },
    settings: { type: Object, default: () => ({}) },
    renewals: { type: Object, default: () => ({ data: [] }) },
    invoices: { type: Object, default: () => ({ data: [] }) },
    overpayments: { type: Object, default: () => ({ data: [] }) },
    weeklyPlans: { type: Object, default: () => ({ data: [] }) },
    records: { type: Object, default: () => ({ data: [] }) },
})

const { showSuccess, showError } = useToast()
const { confirm } = useConfirm()
const { post, put, destroy } = useErrorHandler()
const { hasPermission } = usePermission()

const tabList = [
    { id: 'dashboard', label: 'Dashboard' },
    { id: 'renewals', label: 'Renewals' },
    { id: 'invoices', label: 'SOA Invoices' },
    { id: 'weekly', label: 'Weekly Plans' },
    { id: 'records', label: 'Approval Records' },
    { id: 'settings', label: 'Settings' },
]

const currentTab = ref(props.tab || 'dashboard')
const switchTab = (id) => {
    currentTab.value = id
    router.get('/payments', { tab: id }, { preserveScroll: true, preserveState: true, replace: true })
}

const summary = computed(() => props.summary || {})
const weeklyCategories = ['POS', 'CCTV', 'Internet', 'Speaker', 'Anti-virus', 'Router', 'Google']

/* ---- Autocomplete option lists ---- */
const vendorOptions = computed(() => (props.vendors || []).map(v => ({ label: v.name, value: v.id })))
const storeOptions = computed(() => (props.stores || []).map(s => ({
    label: s.code + (s.name ? ` — ${s.name}` : ''),
    value: s.code,
})))
const userOptions = computed(() => [{ label: '—', value: null }, ...(props.users || []).map(u => ({ label: u.name, value: u.id }))])
const currencyOptions = computed(() => (props.currencies || []).map(c => ({ label: c, value: c })))
const cycleOptions = computed(() => (props.cycles || []).map(c => ({ label: c.replace('_', ' '), value: c })))
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

const renewalsPagination = usePagination(props.renewals, 'payments.index')
const invoicesPagination = usePagination(props.invoices, 'payments.index')
const weeklyPagination = usePagination(props.weeklyPlans, 'payments.index')
const recordsPagination = usePagination(props.records, 'payments.index')

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
    if (['paid', 'posted', 'approved', 'released'].includes(k)) return 'bg-green-100 text-green-800'
    if (['overdue', 'rejected'].includes(k)) return 'bg-red-100 text-red-800'
    if (['due', 'pending', 'planned', 'active'].includes(k)) return 'bg-blue-100 text-blue-800'
    if (['paused', 'cancelled'].includes(k)) return 'bg-gray-200 text-gray-700'
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

/* ---- Renewal form ---- */
const renewalModal = reactive({ open: false, editing: false, current: null })
const renewalForm = reactive(blankRenewal())
function blankRenewal() {
    return {
        vendor_id: '', service_type: '', sub_type: '', purpose: '',
        unit_cost: 0, qty: 1, total_amount: 0, currency: 'PHP',
        cycle: 'monthly', cycle_anchor_date: '', next_due_date: '',
        expiration_date: '', payment_terms: '', assignee_user_id: null,
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
    // Always recompute total_amount at submit so watcher misses (e.g. unchanged
    // defaults on a fresh modal) can't send a stale 0 to the backend.
    renewalForm.total_amount = Number(renewalForm.unit_cost || 0) * Number(renewalForm.qty || 0)
    const url = renewalModal.editing ? `/payments/renewals/${renewalModal.current.id}` : '/payments/renewals'
    const method = renewalModal.editing ? put : post
    method(url, renewalForm, {
        preserveScroll: true,
        onSuccess: () => { renewalModal.open = false },
        onError: (errs) => showError(Object.values(errs).flat().join(', ') || 'Save failed'),
    })
}

/* ---- Invoice form ---- */
const invoiceModal = reactive({ open: false, editing: false, current: null })
const invoiceForm = reactive(blankInvoice())
function blankInvoice() {
    return {
        vendor_id: '', apv_no: '', store_code: '', po_number: '', si_number: '',
        si_date: '', due_date: '', invoice_amount: 0, outstanding_amount: 0,
        currency: 'PHP', status: 'Pending', remarks: '', assignee_user_id: null,
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
