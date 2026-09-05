<template>
    <AppLayout title="Vendors" content-class="w-full max-w-none px-2 sm:px-4 lg:px-6">
        <div class="py-12">
            <div
                v-if="pendingCount > 0 && hasPermission('vendors.approve')"
                class="mb-4 flex items-center justify-between gap-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 dark:border-amber-900/50 dark:bg-amber-900/20"
            >
                <div class="flex items-center gap-3">
                    <svg class="w-5 h-5 text-amber-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M5.07 19h13.86a2 2 0 001.74-3L13.74 4a2 2 0 00-3.48 0L3.33 16a2 2 0 001.74 3z" />
                    </svg>
                    <p class="text-sm font-semibold text-amber-900 dark:text-amber-200">
                        {{ pendingCount }} vendor {{ pendingCount === 1 ? 'registration is' : 'registrations are' }}
                        awaiting approval from the vendor portal.
                    </p>
                </div>
                <button
                    @click="statusFilter = 'pending'"
                    class="whitespace-nowrap rounded-lg bg-amber-600 px-3 py-1.5 text-xs font-bold text-white transition-colors hover:bg-amber-700"
                >
                    Review now
                </button>
            </div>

            <DataTable
                title="Vendor Management"
                subtitle="Manage supplier and service provider records, and approve vendor portal registrations"
                search-placeholder="Search by name, code, contact person or email..."
                empty-message="No vendors found. Create your first vendor to get started."
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
                    <div class="w-56">
                        <Autocomplete
                            v-model="statusFilter"
                            :options="statusFilterOptions"
                            size="sm"
                            placeholder="All vendors"
                        />
                    </div>
                    <button
                        v-if="hasPermission('vendors.create')"
                        @click="openCreateModal"
                        class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors flex items-center space-x-2 shadow-sm whitespace-nowrap"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                        </svg>
                        <span>Create Vendor</span>
                    </button>
                </template>

                <template #header>
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-slate-300">Vendor</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-slate-300">Type / Contact</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-slate-300">Phone</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-slate-300">Status</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-slate-300">Actions</th>
                    </tr>
                </template>

                <template #body="{ data }">
                    <tr v-for="vendor in data" :key="vendor.id" class="hover:bg-gray-50 transition-colors dark:hover:bg-gray-700">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="flex items-center">
                                <div class="h-10 w-10 bg-gradient-to-br from-emerald-500 to-emerald-600 rounded-full flex items-center justify-center shadow-sm flex-shrink-0">
                                    <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                                    </svg>
                                </div>
                                <div class="ml-4">
                                    <div class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ vendor.name }}</div>
                                    <div class="text-xs text-gray-500 dark:text-gray-300">{{ vendor.code || '-' }}</div>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-xs font-semibold uppercase tracking-wider text-emerald-700">{{ vendor.vendor_type || 'Supplier' }}</div>
                            <div class="text-sm text-gray-900 mt-1 dark:text-gray-100">{{ vendor.contact_person || '-' }}</div>
                            <div class="text-xs text-gray-500 dark:text-gray-300">{{ vendor.email || '' }}</div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600 dark:text-gray-300">
                            {{ vendor.phone || '-' }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="flex flex-wrap items-center gap-1">
                                <span :class="statusBadge(vendor).class" class="inline-flex px-2 py-1 text-xs font-semibold rounded-full">
                                    {{ statusBadge(vendor).label }}
                                </span>
                                <span
                                    v-if="vendor.has_portal_access"
                                    class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-indigo-100 text-indigo-800"
                                    title="Registered through the vendor portal"
                                >
                                    Portal
                                </span>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                            <div class="flex justify-end space-x-1">
                                <button
                                    v-if="vendor.has_portal_access && hasPermission('vendors.approve')"
                                    @click="openApprovalModal(vendor)"
                                    class="p-2 text-amber-600 hover:text-amber-900 hover:bg-amber-50 rounded-full transition-colors"
                                    title="Review portal account"
                                >
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                                    </svg>
                                </button>
                                <button
                                    v-if="canIssueOrResetPassword(vendor)"
                                    @click="openPasswordModal(vendor)"
                                    class="p-2 text-purple-600 hover:text-purple-900 hover:bg-purple-50 rounded-full transition-colors"
                                    :title="vendor.has_portal_access ? 'Reset portal password' : 'Create cashier portal login'"
                                >
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
                                    </svg>
                                </button>
                                <button
                                    v-if="hasPermission('vendors.edit')"
                                    @click="editVendor(vendor)"
                                    class="p-2 text-blue-600 hover:text-blue-900 hover:bg-blue-50 rounded-full transition-colors"
                                    title="Edit Vendor"
                                >
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                    </svg>
                                </button>
                                <button
                                    v-if="hasPermission('vendors.delete') && !vendor.has_portal_access"
                                    @click="deleteVendor(vendor)"
                                    class="p-2 text-red-600 hover:text-red-900 hover:bg-red-50 rounded-full transition-colors"
                                    title="Delete Vendor"
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

        <!-- Create / Edit Vendor -->
        <div v-if="showModal" class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex items-center justify-center min-h-screen px-4 py-6">
                <div class="fixed inset-0 bg-black/20 backdrop-blur-md" @click="closeModal"></div>
                <div
                    class="relative bg-white rounded-2xl shadow-2xl w-full border border-gray-100 transform transition-all dark:bg-gray-800 dark:border-gray-700 overflow-hidden flex flex-col my-8"
                    :class="isEditing ? 'max-w-5xl xl:max-w-6xl' : 'max-w-lg'"
                >
                    <!-- Modal Header -->
                    <div class="flex justify-between items-center px-6 py-4 border-b border-gray-100 dark:border-gray-700 shrink-0">
                        <div class="flex items-center gap-2.5 min-w-0">
                            <h3 class="text-xl font-bold text-gray-900 dark:text-gray-100 truncate">
                                {{ isEditing ? 'Edit Vendor' : 'Create Vendor' }}
                            </h3>
                            <span
                                v-if="isEditing && currentVendor"
                                class="hidden sm:inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300 truncate"
                            >
                                {{ currentVendor.code || currentVendor.name }}
                            </span>
                        </div>
                        <button
                            type="button"
                            @click="closeModal"
                            class="text-gray-400 hover:text-gray-600 transition-colors dark:text-gray-400 p-1 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700"
                        >
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <!-- Modal Body -->
                    <div class="flex-1" :class="isEditing ? 'lg:flex lg:divide-x lg:divide-gray-100 dark:lg:divide-gray-700' : ''">
                        <!-- Left Column: Form -->
                        <div :class="isEditing ? 'w-full lg:w-7/12 p-6 overflow-y-auto max-h-[calc(85vh-80px)]' : 'p-6'">
                            <form @submit.prevent="submitForm" class="space-y-4">
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1 dark:text-gray-300">Code</label>
                                        <input v-model="form.code" type="text" maxlength="50"
                                               class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600"
                                               placeholder="e.g. VND-001">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1 dark:text-gray-300">Vendor Name <span class="text-red-500">*</span></label>
                                        <input v-model="form.name" type="text" required maxlength="255"
                                               class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600">
                                    </div>
                                </div>

                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1 dark:text-gray-300">Vendor Type <span class="text-red-500">*</span></label>
                                        <ManageableAutocomplete
                                            v-model="form.vendor_type"
                                            :options="localVendorTypes"
                                            option-type="vendor_type"
                                            placeholder="Select a vendor type..."
                                            :can-create="hasPermission('reference_options.create')"
                                            :can-edit="hasPermission('reference_options.edit')"
                                            :can-delete="hasPermission('reference_options.delete')"
                                            @options-changed="localVendorTypes = $event"
                                        />
                                    </div>
                                    <!-- A Cashier signs in to the portal and runs Campaigns for
                                         one till, so their store is what every card, stamp and
                                         voucher redemption they make is booked against. -->
                                    <div v-if="isCashierType">
                                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1 dark:text-gray-300">Assigned Store <span class="text-red-500">*</span></label>
                                        <Autocomplete v-model="form.store_id" :options="storeOptions" placeholder="Select the cashier's store..." />
                                        <p class="mt-1 text-[10px] font-bold uppercase italic text-gray-400">
                                            Campaigns in the vendor portal is scoped to this store.
                                        </p>
                                    </div>
                                </div>

                                <div>
                                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1 dark:text-gray-300">Contact Person</label>
                                    <input v-model="form.contact_person" type="text" maxlength="255"
                                           class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600">
                                </div>

                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1 dark:text-gray-300">Email</label>
                                        <input v-model="form.email" type="email" maxlength="255" :disabled="editingIsPortalVendor"
                                               class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm disabled:bg-gray-100 disabled:text-gray-500 dark:border-gray-600 dark:disabled:bg-gray-900">
                                        <p v-if="editingIsPortalVendor" class="mt-1 text-[10px] font-bold uppercase italic text-gray-400">
                                            This is the vendor's portal sign-in address and cannot be changed here.
                                        </p>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1 dark:text-gray-300">Phone</label>
                                        <input v-model="form.phone" type="text" maxlength="50"
                                               class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600">
                                    </div>
                                </div>

                                <div>
                                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1 dark:text-gray-300">Address</label>
                                    <textarea v-model="form.address" rows="3"
                                              class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600"></textarea>
                                </div>

                                <!-- Reference vendors are switched on and off here; portal
                                     accounts are governed by the approval matrix instead. -->
                                <div v-if="isEditing && !editingIsPortalVendor" class="flex items-center">
                                    <input v-model="form.is_active" type="checkbox" id="vendor_is_active" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 dark:border-gray-600">
                                    <label for="vendor_is_active" class="ml-2 text-sm font-medium text-gray-700 dark:text-gray-300">Active Vendor</label>
                                </div>

                                <div v-if="editingIsPortalVendor" class="rounded-xl border border-indigo-100 bg-indigo-50 p-4 dark:border-indigo-900/50 dark:bg-indigo-900/20">
                                    <div class="flex items-center justify-between">
                                        <p class="text-xs font-bold uppercase tracking-wider text-indigo-900 dark:text-indigo-200">Vendor Portal Account</p>
                                        <span :class="statusBadge(currentVendor).class" class="inline-flex px-2 py-1 text-xs font-semibold rounded-full">
                                            {{ statusBadge(currentVendor).label }}
                                        </span>
                                    </div>
                                    <div class="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2">
                                        <div>
                                            <p class="text-[10px] font-bold uppercase tracking-wider text-indigo-400">Email verified</p>
                                            <p class="text-sm font-semibold text-indigo-900 dark:text-indigo-200">{{ formatAuditDate(currentVendor?.email_verified_at) }}</p>
                                        </div>
                                        <div>
                                            <p class="text-[10px] font-bold uppercase tracking-wider text-indigo-400">Last sign-in</p>
                                            <p class="text-sm font-semibold text-indigo-900 dark:text-indigo-200">{{ formatAuditDate(currentVendor?.last_login_at) }}</p>
                                        </div>
                                    </div>
                                    <button
                                        v-if="hasPermission('vendors.approve')"
                                        type="button"
                                        @click="openApprovalModal(currentVendor, { fromEdit: true })"
                                        class="mt-3 text-xs font-bold text-indigo-700 underline hover:text-indigo-900 dark:text-indigo-300"
                                    >
                                        Open approval matrix
                                    </button>
                                </div>

                                <div v-if="isEditing" class="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-6 border-t mt-6">
                                    <div class="rounded-lg bg-gray-50 border border-gray-100 px-3 py-2 dark:bg-gray-900/50 dark:border-gray-700">
                                        <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wider dark:text-gray-400">Created By</p>
                                        <p class="text-sm font-semibold text-gray-800 truncate dark:text-gray-200">{{ auditUserLabel(currentVendor?.creator, currentVendor?.created_by) }}</p>
                                    </div>
                                    <div class="rounded-lg bg-gray-50 border border-gray-100 px-3 py-2 dark:bg-gray-900/50 dark:border-gray-700">
                                        <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wider dark:text-gray-400">Updated By</p>
                                        <p class="text-sm font-semibold text-gray-800 truncate dark:text-gray-200">{{ auditUserLabel(currentVendor?.updater, currentVendor?.updated_by) }}</p>
                                    </div>
                                    <div class="rounded-lg bg-gray-50 border border-gray-100 px-3 py-2 dark:bg-gray-900/50 dark:border-gray-700">
                                        <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wider dark:text-gray-400">Created At</p>
                                        <p class="text-sm font-semibold text-gray-800 dark:text-gray-200">{{ formatAuditDate(currentVendor?.created_at) }}</p>
                                    </div>
                                    <div class="rounded-lg bg-gray-50 border border-gray-100 px-3 py-2 dark:bg-gray-900/50 dark:border-gray-700">
                                        <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wider dark:text-gray-400">Updated At</p>
                                        <p class="text-sm font-semibold text-gray-800 dark:text-gray-200">{{ formatAuditDate(currentVendor?.updated_at) }}</p>
                                    </div>
                                </div>

                                <div class="flex justify-end space-x-3 pt-6 border-t mt-6">
                                    <button type="button" @click="closeModal"
                                            class="px-4 py-2 text-sm font-semibold text-gray-600 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700">
                                        Cancel
                                    </button>
                                    <button type="submit"
                                            class="px-6 py-2 bg-blue-600 text-white text-sm font-bold rounded-lg hover:bg-blue-700 shadow-md transition-all">
                                        {{ isEditing ? 'Update' : 'Create' }}
                                    </button>
                                </div>
                            </form>
                        </div>

                        <!-- Right Column: what the vendor keeps in the portal -->
                        <div v-if="isEditing" class="w-full lg:w-5/12 bg-gray-50/70 p-6 dark:bg-gray-900/40 border-t lg:border-t-0 overflow-y-auto max-h-[calc(85vh-80px)] space-y-6">
                            <VendorProfilePanel
                                :vendor="currentVendor"
                                :profile="vendorProfile.data"
                                :contacts="vendorProfile.contacts"
                                :bank-accounts="vendorProfile.bankAccounts"
                                :loading="vendorProfile.loading"
                                :error="vendorProfile.error"
                                :can-review="vendorProfile.canReview"
                                :reviewing="vendorProfile.reviewing"
                                :can-verify-bank="vendorProfile.canVerifyBank"
                                :verifying-bank-id="vendorProfile.verifyingBankId"
                                @review="reviewProfile"
                                @review-bank="reviewBankAccount"
                            />

                            <VendorDocumentsPanel
                                :vendor="currentVendor"
                                :documents="vendorDocuments.items"
                                :loading="vendorDocuments.loading"
                                :error="vendorDocuments.error"
                                :can-review="vendorDocuments.canReview"
                                :reviewing-id="vendorDocuments.reviewingId"
                                @preview-image="openImagePreview"
                                @review="reviewDocument"
                            />
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Approval matrix -->
        <div v-if="showApprovalModal" class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex items-center justify-center min-h-screen px-4 py-6">
                <div class="fixed inset-0 bg-black/20 backdrop-blur-md" @click="showApprovalModal = false"></div>
                <div class="relative bg-white rounded-xl shadow-2xl w-full max-w-3xl p-6 border border-gray-100 transform transition-all dark:bg-gray-800 dark:border-gray-700 max-h-[92vh] overflow-y-auto">
                    <div class="flex justify-between items-start mb-6">
                        <div>
                            <h3 class="text-xl font-bold text-gray-900 dark:text-gray-100">Vendor Portal Approval</h3>
                            <p class="text-sm text-gray-500 dark:text-gray-300">{{ approvalVendor?.name }} · {{ approvalVendor?.email }}</p>
                        </div>
                        <button @click="showApprovalModal = false" class="text-gray-400 hover:text-gray-600 transition-colors dark:text-gray-400">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <div class="mb-4 flex items-center justify-between rounded-lg border border-gray-100 bg-gray-50 px-4 py-3 dark:border-gray-700 dark:bg-gray-900/50">
                        <div>
                            <p class="text-[10px] font-bold uppercase tracking-wider text-gray-400">Current status</p>
                            <p class="text-sm font-bold text-gray-800 dark:text-gray-200">{{ statusBadge(approvalVendor).label }}</p>
                        </div>
                        <div class="text-right">
                            <p class="text-[10px] font-bold uppercase tracking-wider text-gray-400">Approved by</p>
                            <p class="text-sm font-semibold text-gray-800 dark:text-gray-200">
                                {{ approvalVendor?.approved_at ? auditUserLabel(approvalVendor?.approver, approvalVendor?.approved_by) : '—' }}
                            </p>
                        </div>
                    </div>

                    <p class="mb-3 text-xs text-gray-500 dark:text-gray-300">
                        A pending vendor can sign in to complete their profile and upload accreditation documents,
                        but cannot transact until the account is approved.
                    </p>

                    <!-- The vendor's own submissions are the basis for the
                         account decision, so both are reviewed in this modal. -->
                    <div
                        v-if="vendorProfile.loading || vendorProfile.data"
                        class="mb-4 rounded-xl border p-3"
                        :class="vendorProfile.data?.has_pending_changes
                            ? 'border-amber-200 bg-amber-50/60 dark:border-amber-900/50 dark:bg-amber-900/10'
                            : 'border-gray-100 bg-gray-50/70 dark:border-gray-700 dark:bg-gray-900/40'"
                    >
                        <VendorProfilePanel
                            compact
                            :vendor="approvalVendor"
                            :profile="vendorProfile.data"
                            :contacts="vendorProfile.contacts"
                            :bank-accounts="vendorProfile.bankAccounts"
                            :loading="vendorProfile.loading"
                            :error="vendorProfile.error"
                            :can-review="vendorProfile.canReview"
                            :reviewing="vendorProfile.reviewing"
                            :can-verify-bank="vendorProfile.canVerifyBank"
                            :verifying-bank-id="vendorProfile.verifyingBankId"
                            @review="reviewProfile"
                            @review-bank="reviewBankAccount"
                        />
                    </div>

                    <!-- The accreditation files are the basis for the account
                         decision, so they are reviewed here, in the same modal. -->
                    <div class="mb-5 rounded-xl border border-gray-100 bg-gray-50/70 p-3 dark:border-gray-700 dark:bg-gray-900/40">
                        <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                            <p class="text-[10px] font-bold uppercase tracking-wider text-gray-400">
                                Accreditation documents
                            </p>
                            <div class="flex flex-wrap items-center gap-1.5">
                                <span
                                    v-if="documentSummary.pending"
                                    class="rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-bold text-amber-800 dark:bg-amber-950/50 dark:text-amber-300"
                                >
                                    {{ documentSummary.pending }} awaiting review
                                </span>
                                <span
                                    v-if="documentSummary.approved"
                                    class="rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-bold text-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-300"
                                >
                                    {{ documentSummary.approved }} approved
                                </span>
                                <span
                                    v-if="documentSummary.rejected"
                                    class="rounded-full bg-red-100 px-2 py-0.5 text-[10px] font-bold text-red-800 dark:bg-red-950/50 dark:text-red-300"
                                >
                                    {{ documentSummary.rejected }} rejected
                                </span>
                                <span
                                    v-if="documentSummary.expired"
                                    class="rounded-full bg-red-100 px-2 py-0.5 text-[10px] font-bold text-red-800 dark:bg-red-950/50 dark:text-red-300"
                                >
                                    {{ documentSummary.expired }} expired
                                </span>
                            </div>
                        </div>

                        <p
                            v-if="documentSummary.pending && !vendorDocuments.loading"
                            class="mb-2 text-[11px] font-semibold text-amber-800 dark:text-amber-300"
                        >
                            Review the outstanding documents before deciding on the account.
                        </p>

                        <VendorDocumentsPanel
                            compact
                            :vendor="approvalVendor"
                            :documents="vendorDocuments.items"
                            :loading="vendorDocuments.loading"
                            :error="vendorDocuments.error"
                            :can-review="vendorDocuments.canReview"
                            :reviewing-id="vendorDocuments.reviewingId"
                            @preview-image="openImagePreview"
                            @review="reviewDocument"
                        />
                    </div>

                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                        <button
                            v-for="action in availableApprovalActions"
                            :key="action.value"
                            type="button"
                            @click="approvalForm.action = action.value"
                            :class="approvalForm.action === action.value ? action.activeClass : 'border-gray-200 text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300'"
                            class="rounded-lg border px-3 py-2 text-xs font-bold transition-colors"
                        >
                            {{ action.label }}
                        </button>
                    </div>

                    <div class="mt-4">
                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1 dark:text-gray-300">
                            Remarks
                            <span v-if="remarksRequired" class="text-red-500">*</span>
                        </label>
                        <textarea v-model="approvalForm.remarks" rows="3" maxlength="1000"
                                  :placeholder="remarksRequired ? 'Tell the vendor why — this is shown to them.' : 'Optional note for the record.'"
                                  class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600"></textarea>
                    </div>

                    <div v-if="approvalVendor?.approvals?.length" class="mt-5 border-t pt-4 dark:border-gray-700">
                        <p class="mb-2 text-[10px] font-bold uppercase tracking-wider text-gray-400">Decision history</p>
                        <ul class="max-h-40 space-y-2 overflow-y-auto">
                            <li v-for="entry in approvalVendor.approvals" :key="entry.id" class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-gray-900/50">
                                <p class="text-xs font-bold capitalize text-gray-800 dark:text-gray-200">
                                    {{ entry.action }} by {{ auditUserLabel(entry.decider, entry.decided_by) }}
                                    <span class="font-normal text-gray-400">· {{ formatAuditDate(entry.decided_at) }}</span>
                                </p>
                                <p v-if="entry.remarks" class="mt-0.5 text-xs text-gray-600 dark:text-gray-300">{{ entry.remarks }}</p>
                            </li>
                        </ul>
                    </div>

                    <div class="flex justify-end space-x-3 pt-6">
                        <button type="button" @click="showApprovalModal = false"
                                class="px-4 py-2 text-sm font-semibold text-gray-600 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700">
                            Cancel
                        </button>
                        <button type="button" @click="submitApproval" :disabled="!approvalForm.action"
                                class="px-6 py-2 bg-blue-600 text-white text-sm font-bold rounded-lg hover:bg-blue-700 shadow-md transition-all disabled:opacity-50">
                            Record Decision
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Portal password reset -->
        <div v-if="showPasswordModal" class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex items-center justify-center min-h-screen px-4 py-6">
                <div class="fixed inset-0 bg-black/20 backdrop-blur-md" @click="showPasswordModal = false"></div>
                <div class="relative bg-white rounded-xl shadow-2xl w-full max-w-md p-6 border border-gray-100 transform transition-all dark:bg-gray-800 dark:border-gray-700">
                    <div class="flex justify-between items-center mb-6">
                        <h3 class="text-xl font-bold text-gray-900 dark:text-gray-100">Reset Portal Password</h3>
                        <button @click="showPasswordModal = false" class="text-gray-400 hover:text-gray-600 transition-colors dark:text-gray-400">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <div class="mb-6 p-4 bg-yellow-50 rounded-lg border border-yellow-100">
                        <p class="text-sm text-yellow-800">
                            {{ passwordVendor?.has_portal_access ? 'Resetting the vendor portal password for' : 'Creating the vendor portal login for' }}
                            <strong class="font-bold text-yellow-900">{{ passwordVendor?.name }}</strong>
                            ({{ passwordVendor?.email }}). Give them the password yourself — it is not emailed.
                        </p>
                        <p v-if="!passwordVendor?.has_portal_access" class="mt-2 text-xs text-yellow-800">
                            This activates the account immediately: a cashier login is issued by an approver, so there is
                            nothing left to review.
                        </p>
                    </div>

                    <form @submit.prevent="submitPasswordReset" class="space-y-4">
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1 dark:text-gray-300">New Password <span class="text-red-500">*</span></label>
                            <input v-model="passwordForm.password" type="password" required minlength="8"
                                   class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600">
                            <p class="mt-1 text-[10px] font-bold uppercase italic text-gray-400">At least 8 characters, with letters and numbers.</p>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1 dark:text-gray-300">Confirm Password <span class="text-red-500">*</span></label>
                            <input v-model="passwordForm.password_confirmation" type="password" required minlength="8"
                                   class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500 text-sm dark:border-gray-600">
                        </div>
                        <div class="flex justify-end space-x-3 pt-2">
                            <button type="button" @click="showPasswordModal = false"
                                    class="px-4 py-2 text-sm font-semibold text-gray-600 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700">
                                Cancel
                            </button>
                            <button type="submit"
                                    class="px-6 py-2 bg-purple-600 text-white text-sm font-bold rounded-lg hover:bg-purple-700 shadow-md transition-all">
                                Reset Password
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Image Viewer Modal (Zoom & Pan) -->
        <VendorImageViewerModal
            :show="imageViewer.show"
            :title="imageViewer.title"
            :file-name="imageViewer.fileName"
            :image-url="imageViewer.imageUrl"
            @close="imageViewer.show = false"
        />
    </AppLayout>
</template>

<script setup>
import { ref, reactive, computed, onMounted, watch } from 'vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import Autocomplete from '@/Components/Autocomplete.vue'
import ManageableAutocomplete from '@/Components/ManageableAutocomplete.vue'
import DataTable from '@/Components/DataTable.vue'
import VendorDocumentsPanel from '@/Components/Vendors/VendorDocumentsPanel.vue'
import VendorProfilePanel from '@/Components/Vendors/VendorProfilePanel.vue'
import VendorImageViewerModal from '@/Components/Vendors/VendorImageViewerModal.vue'
import { useToast } from '@/Composables/useToast'
import { useConfirm } from '@/Composables/useConfirm'
import { useErrorHandler } from '@/Composables/useErrorHandler'
import { usePagination } from '@/Composables/usePagination'
import { usePermission } from '@/Composables/usePermission'

const props = defineProps({
    vendors: Object,
    filters: {
        type: Object,
        default: () => ({}),
    },
    pendingCount: {
        type: Number,
        default: 0,
    },
    vendorTypes: {
        type: Array,
        default: () => [],
    },
    stores: {
        type: Array,
        default: () => [],
    },
    cashierType: {
        type: String,
        default: 'Cashier',
    },
})

const { showSuccess, showError } = useToast()
const { confirm } = useConfirm()
const { post, put, destroy } = useErrorHandler()
const { hasPermission } = usePermission()

const statusFilter = ref(props.filters.status || '')
const pagination = usePagination(props.vendors, 'vendors.index', () => ({ status: statusFilter.value }))

const statusFilterOptions = [
    { value: '', label: 'All vendors' },
    { value: 'pending', label: 'Awaiting approval' },
    { value: 'portal', label: 'Portal accounts' },
    { value: 'active', label: 'Active' },
    { value: 'inactive', label: 'Inactive' },
]

// Managed inline through ManageableAutocomplete, so the list is local state
// that the component replaces after every add/rename/delete.
const localVendorTypes = ref([...(props.vendorTypes || [])])

const storeOptions = computed(() =>
    (props.stores || []).map((store) => ({ value: store.id, label: `${store.code} — ${store.name}` }))
)

const showModal = ref(false)
const isEditing = ref(false)
const currentVendor = ref(null)

const showApprovalModal = ref(false)
const approvalVendor = ref(null)
const approvalForm = reactive({ action: '', remarks: '' })

const showPasswordModal = ref(false)
const passwordVendor = ref(null)
const passwordForm = reactive({ password: '', password_confirmation: '' })

// Accreditation files the vendor submitted through the portal. Fetched when the
// edit modal opens rather than shipped with the listing, which paginates and is
// mostly reference vendors with nothing to show.
const vendorDocuments = reactive({
    // Whose documents these are — the edit modal and the approval modal both
    // read this one store, and a decision posts against this id.
    vendorId: null,
    items: [],
    loading: false,
    error: '',
    // Whether this account may accredit a document (vendor-documents.approve).
    // Not the same right as deciding on the vendor's ACCOUNT.
    canReview: false,
    reviewingId: null,
})

const loadDocuments = async (vendor, { force = false } = {}) => {
    if (!vendor?.id) {
        vendorDocuments.vendorId = null
        vendorDocuments.items = []

        return
    }

    // Opening the approval matrix from inside the edit modal is the same
    // vendor: keep what is already on screen instead of flashing a skeleton.
    if (!force && vendorDocuments.vendorId === vendor.id && !vendorDocuments.error) {
        return
    }

    vendorDocuments.vendorId = vendor.id
    vendorDocuments.items = []
    vendorDocuments.error = ''
    vendorDocuments.loading = true

    try {
        // window.axios, not a bare import: it carries this app's per-app CSRF
        // cookie name, which linkportal on the same host would otherwise clash with.
        const { data } = await window.axios.get(route('vendors.documents.index', vendor.id))
        vendorDocuments.items = data.documents || []
        vendorDocuments.canReview = !!data.can_review
    } catch (e) {
        vendorDocuments.error = 'Could not load this vendor\'s documents.'
    } finally {
        vendorDocuments.loading = false
    }
}

/**
 * Accredits or refuses one uploaded document. Distinct from the account
 * approval matrix: this decides on the FILE, and never touches portal access.
 */
const reviewDocument = async ({ document, action, remarks }) => {
    vendorDocuments.reviewingId = document.id

    try {
        const { data } = await window.axios.put(
            route('vendors.documents.review', [vendorDocuments.vendorId, document.id]),
            { action, remarks }
        )

        // Swap in the row the server just decided on, so its status, reviewer
        // and remarks are what was actually stored.
        vendorDocuments.items = vendorDocuments.items
            .map((item) => (item.id === document.id ? data.document : item))

        showSuccess(data.message)
    } catch (e) {
        // 409 when someone else reviewed it first, 422 when the reason is missing.
        showError(
            e?.response?.data?.message
            || Object.values(e?.response?.data?.errors || {}).flat().join(', ')
            || 'Could not record the document decision.'
        )
    } finally {
        vendorDocuments.reviewingId = null
    }
}

// The company profile the vendor keeps in the portal, and its staged changes.
const vendorProfile = reactive({
    vendorId: null,
    data: null,
    // Same fetch: the portal keeps all three on its /vendor/profile page.
    contacts: [],
    bankAccounts: [],
    loading: false,
    error: '',
    canReview: false,
    reviewing: false,
    // Verifying bank details is its own right — payments follow it.
    canVerifyBank: false,
    verifyingBankId: null,
})

const loadProfile = async (vendor, { force = false } = {}) => {
    if (!vendor?.id) {
        vendorProfile.vendorId = null
        vendorProfile.data = null
        vendorProfile.contacts = []
        vendorProfile.bankAccounts = []

        return
    }

    if (!force && vendorProfile.vendorId === vendor.id && !vendorProfile.error) {
        return
    }

    vendorProfile.vendorId = vendor.id
    vendorProfile.data = null
    vendorProfile.contacts = []
    vendorProfile.bankAccounts = []
    vendorProfile.error = ''
    vendorProfile.loading = true

    try {
        const { data } = await window.axios.get(route('vendors.profile.show', vendor.id))
        vendorProfile.data = data.profile
        vendorProfile.contacts = data.contacts || []
        vendorProfile.bankAccounts = data.bank_accounts || []
        vendorProfile.canReview = !!data.can_review
        vendorProfile.canVerifyBank = !!data.can_verify_bank
    } catch (e) {
        vendorProfile.error = "Could not load this vendor's company profile."
    } finally {
        vendorProfile.loading = false
    }
}

/**
 * Accepts or refuses the changes the vendor staged in the portal. Approving
 * copies them onto the live profile; the modal stays open either way.
 */
const reviewProfile = async ({ action, remarks }) => {
    vendorProfile.reviewing = true

    try {
        const { data } = await window.axios.put(
            route('vendors.profile.review', vendorProfile.vendorId),
            { action, remarks }
        )

        vendorProfile.data = data.profile
        showSuccess(data.message)
    } catch (e) {
        showError(
            e?.response?.data?.message
            || Object.values(e?.response?.data?.errors || {}).flat().join(', ')
            || 'Could not record the profile decision.'
        )
    } finally {
        vendorProfile.reviewing = false
    }
}

/**
 * Verifies or refuses one bank account. Separate decision, separate permission:
 * payments are released against a verified account, so this never touches the
 * vendor's portal access or its profile.
 */
const reviewBankAccount = async ({ account, action, remarks }) => {
    vendorProfile.verifyingBankId = account.id

    try {
        const { data } = await window.axios.put(
            route('vendors.bank-accounts.review', [vendorProfile.vendorId, account.id]),
            { action, remarks }
        )

        vendorProfile.bankAccounts = vendorProfile.bankAccounts
            .map((item) => (item.id === account.id ? data.bank_account : item))

        showSuccess(data.message)
    } catch (e) {
        showError(
            e?.response?.data?.message
            || Object.values(e?.response?.data?.errors || {}).flat().join(', ')
            || 'Could not record the bank account decision.'
        )
    } finally {
        vendorProfile.verifyingBankId = null
    }
}

const imageViewer = reactive({
    show: false,
    title: '',
    fileName: '',
    imageUrl: '',
})

const openImagePreview = ({ title, fileName, imageUrl }) => {
    imageViewer.title = title
    imageViewer.fileName = fileName
    imageViewer.imageUrl = imageUrl
    imageViewer.show = true
}

const form = reactive({
    code: '',
    name: '',
    vendor_type: 'Supplier',
    store_id: null,
    contact_person: '',
    email: '',
    phone: '',
    address: '',
    is_active: true,
})

const isCashierType = computed(() => form.vendor_type === props.cashierType)

// A cashier never self-registers (the portal's registration form is for
// suppliers), so this same button ISSUES their first login as well as resetting
// a later one. The server enforces both the type and the assigned store.
const canIssueOrResetPassword = (vendor) => hasPermission('vendors.reset_password')
    && (vendor.has_portal_access || (vendor.vendor_type === props.cashierType && !!vendor.store_id))

const editingIsPortalVendor = computed(() => isEditing.value && !!currentVendor.value?.has_portal_access)

// Only the transitions that make sense from where the account currently stands.
const availableApprovalActions = computed(() => {
    const status = approvalVendor.value?.status
    // "Account" is in every label on purpose: the documents listed right above
    // carry their own Approve/Reject, and the two decisions are different.
    const all = [
        { value: 'approve', label: 'Approve Account', activeClass: 'border-emerald-600 bg-emerald-600 text-white' },
        { value: 'reject', label: 'Reject Account', activeClass: 'border-red-600 bg-red-600 text-white' },
        { value: 'suspend', label: 'Suspend Account', activeClass: 'border-amber-600 bg-amber-600 text-white' },
        { value: 'reactivate', label: 'Reactivate Account', activeClass: 'border-emerald-600 bg-emerald-600 text-white' },
    ]

    if (status === 'active') {
        return all.filter((a) => a.value === 'suspend')
    }

    if (status === 'rejected' || status === 'suspended' || status === 'deactivated') {
        return all.filter((a) => a.value === 'reactivate' || a.value === 'reject')
    }

    return all.filter((a) => a.value === 'approve' || a.value === 'reject')
})

const remarksRequired = computed(() => ['reject', 'suspend'].includes(approvalForm.action))

/** What the approver is deciding on top of, counted at a glance. */
const documentSummary = computed(() => {
    const items = vendorDocuments.items
    const countOf = (status) => items.filter((d) => (d.status || '').toLowerCase() === status).length

    return {
        total: items.length,
        pending: countOf('pending'),
        approved: countOf('approved'),
        rejected: countOf('rejected'),
        expired: items.filter((d) => d.is_expired).length,
    }
})

const statusBadge = (vendor) => {
    if (!vendor) {
        return { label: '—', class: 'bg-gray-100 text-gray-800' }
    }

    if (vendor.has_portal_access) {
        return {
            pending: { label: 'Awaiting Approval', class: 'bg-amber-100 text-amber-800' },
            active: { label: 'Approved', class: 'bg-green-100 text-green-800' },
            rejected: { label: 'Rejected', class: 'bg-red-100 text-red-800' },
            suspended: { label: 'Suspended', class: 'bg-orange-100 text-orange-800' },
            deactivated: { label: 'Deactivated', class: 'bg-gray-200 text-gray-700' },
        }[vendor.status] || { label: 'Awaiting Approval', class: 'bg-amber-100 text-amber-800' }
    }

    return vendor.is_active
        ? { label: 'Active', class: 'bg-green-100 text-green-800' }
        : { label: 'Inactive', class: 'bg-red-100 text-red-800' }
}

const auditUserLabel = (user, userId = null) => {
    if (user?.name || user?.email) {
        return user.name || user.email
    }

    if (userId) {
        return `User #${userId}`
    }

    // Portal self-registrations are created with no back-office user behind them.
    return 'Vendor Portal'
}

const formatAuditDate = (value) => {
    if (!value) {
        return '—'
    }

    const date = new Date(value)

    return Number.isNaN(date.getTime())
        ? '—'
        : date.toLocaleString('en-US', {
            year: 'numeric', month: 'short', day: 'numeric',
            hour: '2-digit', minute: '2-digit',
        })
}

onMounted(() => {
    pagination.updateData(props.vendors)
})

watch(() => props.vendors, (newVendors) => {
    pagination.updateData(newVendors)

    // Keep the open modals looking at the freshly saved row.
    if (approvalVendor.value) {
        approvalVendor.value = newVendors?.data?.find((v) => v.id === approvalVendor.value.id) || approvalVendor.value
    }
    if (currentVendor.value) {
        currentVendor.value = newVendors?.data?.find((v) => v.id === currentVendor.value.id) || currentVendor.value
    }
}, { deep: true })

watch(statusFilter, () => {
    pagination.goToPage(1)
})

const openCreateModal = () => {
    isEditing.value = false
    currentVendor.value = null
    form.code = ''
    form.name = ''
    form.vendor_type = 'Supplier'
    form.store_id = null
    form.contact_person = ''
    form.email = ''
    form.phone = ''
    form.address = ''
    form.is_active = true
    showModal.value = true
}

const editVendor = (vendor) => {
    isEditing.value = true
    currentVendor.value = vendor
    // Forced: someone may have decided on a document or a profile change since
    // this row was last opened, and these panels are what an approver reads.
    loadDocuments(vendor, { force: true })
    loadProfile(vendor, { force: true })
    form.code = vendor.code || ''
    form.name = vendor.name
    form.vendor_type = vendor.vendor_type || 'Supplier'
    form.store_id = vendor.store_id ?? null
    form.contact_person = vendor.contact_person || ''
    form.email = vendor.email || ''
    form.phone = vendor.phone || ''
    form.address = vendor.address || ''
    form.is_active = vendor.is_active
    showModal.value = true
}

const closeModal = () => {
    showModal.value = false
}

const submitForm = () => {
    const url = isEditing.value ? `/vendors/${currentVendor.value.id}` : '/vendors'
    const requestMethod = isEditing.value ? put : post

    requestMethod(url, form, {
        preserveState: true,
        onSuccess: () => closeModal(),
        onError: (errors) => {
            const errorMessage = Object.values(errors).flat().join(', ') || 'An error occurred'
            showError(errorMessage)
        },
    })
}

const openApprovalModal = (vendor, { fromEdit = false } = {}) => {
    approvalVendor.value = vendor
    // Opened from the edit modal, the panels beside it are already current;
    // opened from a row, fetch fresh.
    loadDocuments(vendor, { force: !fromEdit })
    loadProfile(vendor, { force: !fromEdit })
    approvalForm.action = ''
    approvalForm.remarks = ''
    showApprovalModal.value = true
}

const submitApproval = () => {
    if (remarksRequired.value && !approvalForm.remarks.trim()) {
        showError('Please give a reason — the vendor is shown this.')
        return
    }

    put(`/vendors/${approvalVendor.value.id}/approval`, { ...approvalForm }, {
        preserveState: true,
        onSuccess: () => {
            showApprovalModal.value = false
        },
        onError: (errors) => {
            const errorMessage = Object.values(errors).flat().join(', ') || 'Could not record the decision'
            showError(errorMessage)
        },
    })
}

const openPasswordModal = (vendor) => {
    passwordVendor.value = vendor
    passwordForm.password = ''
    passwordForm.password_confirmation = ''
    showPasswordModal.value = true
}

const submitPasswordReset = () => {
    put(`/vendors/${passwordVendor.value.id}/password`, { ...passwordForm }, {
        preserveState: true,
        onSuccess: () => {
            showPasswordModal.value = false
        },
        onError: (errors) => {
            const errorMessage = Object.values(errors).flat().join(', ') || 'Could not reset the password'
            showError(errorMessage)
        },
    })
}

const deleteVendor = async (vendor) => {
    const confirmed = await confirm({
        title: 'Delete Vendor',
        message: `Are you sure you want to delete "${vendor.name}"? This action cannot be undone.`,
    })

    if (confirmed) {
        destroy(`/vendors/${vendor.id}`, {
            preserveState: true,
            onError: (errors) => {
                const errorMessage = Object.values(errors).flat().join(', ') || 'Cannot delete vendor'
                showError(errorMessage)
            },
        })
    }
}
</script>
