<template>
    <AppLayout title="NPC Status" content-class="w-full max-w-none px-2 sm:px-4 lg:px-6">

        <!-- ══════════════════ STORE USER VIEW ══════════════════ -->
        <div v-if="viewMode === 'store'" class="py-8">
            <AssignedStoreSeals
                :store-seals="storeSeals"
                @downloaded="onStoreDownload"
                @download-error="onStoreDownloadError"
                @uploaded="onStoreDownload"
                @upload-error="onStoreDownloadError"
            />
        </div>

        <!-- ══════════════════ ADMIN VIEW ══════════════════ -->
        <div v-else class="py-8">
            <div class="space-y-5">

                <!-- Page Header -->
                <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:bg-gray-800 dark:border-gray-700">
                    <div>
                        <h1 class="text-xl font-bold text-gray-900 dark:text-gray-100">NPC Renewal Monitoring</h1>
                        <p class="text-sm text-gray-500 dark:text-gray-300">Track validity, renewal workflow, DPO &amp; CCTV seals, and per-store receipt confirmation. Click an entity to view its full per-year history.</p>
                    </div>
                </div>

                <div
                    v-if="canDownloadAssignedSeals"
                    class="rounded-xl border border-gray-200 bg-gray-50 p-3 shadow-sm dark:border-gray-700 dark:bg-gray-900/50"
                >
                    <div class="mb-3 flex flex-wrap items-center justify-between gap-1 px-1">
                        <p class="text-xs font-black uppercase tracking-widest text-gray-600 dark:text-gray-300">Choose a section</p>
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Click either tab to switch views</p>
                    </div>

                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2" role="tablist" aria-label="NPC sections">
                        <button
                            type="button"
                            role="tab"
                            :aria-selected="adminSection === 'monitoring'"
                            class="group relative flex cursor-pointer items-center gap-4 rounded-xl border-2 p-4 text-left transition-all duration-200 focus:outline-none focus-visible:ring-4 focus-visible:ring-blue-200"
                            :class="adminSection === 'monitoring'
                                ? 'border-blue-600 bg-blue-600 text-white shadow-lg shadow-blue-200/70 dark:shadow-none'
                                : 'border-gray-200 bg-white text-gray-800 hover:-translate-y-0.5 hover:border-blue-400 hover:shadow-md dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100'"
                            @click="adminSection = 'monitoring'"
                        >
                            <span
                                class="flex h-11 w-11 flex-none items-center justify-center rounded-lg"
                                :class="adminSection === 'monitoring' ? 'bg-white/20 text-white' : 'bg-blue-100 text-blue-600 dark:bg-blue-900/40 dark:text-blue-300'"
                            >
                                <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 13h4v8H3v-8zm7-5h4v13h-4V8zm7-5h4v18h-4V3z" />
                                </svg>
                            </span>
                            <span class="min-w-0">
                                <span class="block text-sm font-black uppercase tracking-wide">Monitoring</span>
                                <span
                                    class="mt-1 block text-xs font-medium"
                                    :class="adminSection === 'monitoring' ? 'text-blue-100' : 'text-gray-500 dark:text-gray-400'"
                                >
                                    View assigned entities and renewal progress
                                </span>
                            </span>
                            <span v-if="adminSection === 'monitoring'" class="ml-auto rounded-full bg-white px-2.5 py-1 text-[10px] font-black uppercase text-blue-700">
                                Active
                            </span>
                        </button>

                        <button
                            type="button"
                            role="tab"
                            :aria-selected="adminSection === 'downloads'"
                            class="group relative flex cursor-pointer items-center gap-4 rounded-xl border-2 p-4 text-left transition-all duration-200 focus:outline-none focus-visible:ring-4 focus-visible:ring-emerald-200"
                            :class="adminSection === 'downloads'
                                ? 'border-emerald-600 bg-emerald-600 text-white shadow-lg shadow-emerald-200/70 dark:shadow-none'
                                : 'border-gray-200 bg-white text-gray-800 hover:-translate-y-0.5 hover:border-emerald-400 hover:shadow-md dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100'"
                            @click="adminSection = 'downloads'"
                        >
                            <span
                                class="flex h-11 w-11 flex-none items-center justify-center rounded-lg"
                                :class="adminSection === 'downloads' ? 'bg-white/20 text-white' : 'bg-emerald-100 text-emerald-600 dark:bg-emerald-900/40 dark:text-emerald-300'"
                            >
                                <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v12m0 0 4-4m-4 4-4-4M5 21h14a2 2 0 002-2v-3M3 16v3a2 2 0 002 2" />
                                </svg>
                            </span>
                            <span class="min-w-0">
                                <span class="block text-sm font-black uppercase tracking-wide">MY NPC</span>
                                <span
                                    class="mt-1 block text-xs font-medium"
                                    :class="adminSection === 'downloads' ? 'text-emerald-100' : 'text-gray-500 dark:text-gray-400'"
                                >
                                    Download seals assigned to your stores
                                </span>
                            </span>
                            <span v-if="adminSection === 'downloads'" class="ml-auto rounded-full bg-white px-2.5 py-1 text-[10px] font-black uppercase text-emerald-700">
                                Active
                            </span>
                        </button>
                    </div>
                </div>

                <AssignedStoreSeals
                    v-if="adminSection === 'downloads'"
                    :store-seals="storeSeals"
                    @downloaded="onStoreDownload"
                    @download-error="onStoreDownloadError"
                    @uploaded="onStoreDownload"
                    @upload-error="onStoreDownloadError"
                />

                <template v-else>
                <!-- Status Tabs -->
                <div class="rounded-lg border border-gray-200 bg-white p-3 shadow-sm dark:bg-gray-800 dark:border-gray-700">
                    <div class="flex justify-center gap-2 overflow-x-auto custom-scrollbar">
                        <button
                            v-for="tab in statusTabs"
                            :key="tab.value || 'all'"
                            type="button"
                            @click="selectStatus(tab.value)"
                            :class="[
                                'min-w-[132px] rounded-md px-3 py-2 text-left transition-colors',
                                selectedStatus === tab.value
                                    ? 'bg-blue-600 text-white shadow-sm'
                                    : 'bg-gray-50 text-gray-600 hover:bg-gray-100'
                            ]"
                        >
                            <div class="text-[10px] font-black uppercase tracking-widest">{{ tab.label }}</div>
                            <div class="mt-1 flex items-baseline gap-2">
                                <span class="text-lg font-black">{{ tab.entities }}</span>
                                <span class="text-[10px] font-bold uppercase opacity-75">Entities</span>
                            </div>
                            <div class="text-[10px] font-bold uppercase opacity-75">{{ tab.stores }} Stores</div>
                        </button>
                    </div>
                </div>

                <!-- Data Table -->
                <DataTable
                    title="NPC Status Per Entity"
                    subtitle="Status is automatic based on today's date versus Validity To. Click a row to open the entity's per-year history."
                    search-placeholder="Search entity, renewal status, or workflow stage..."
                    empty-message="No entities found for this filter."
                    :search="pagination.search.value"
                    :data="accumulatedNpcStatuses"
                    :current-page="pagination.currentPage.value"
                    :last-page="pagination.lastPage.value"
                    :per-page="pagination.perPage.value"
                    :showing-text="npcStatusesShowingText"
                    :is-loading="pagination.isLoading.value"
                    infinite-scroll
                    :has-more="hasMoreNpcStatuses"
                    :loading-more="loadingMoreNpcStatuses"
                    @update:search="pagination.search.value = $event"
                    @load-more="loadMoreNpcStatuses"
                >
                    <template #header>
                        <tr>
                            <th class="px-5 py-3 text-left text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-slate-300">Entity</th>
                            <th class="px-5 py-3 text-left text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-slate-300">Validity</th>
                            <th class="px-5 py-3 text-left text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-slate-300">Renewal Status</th>
                            <th class="px-5 py-3 text-left text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-slate-300">Workflow</th>
                            <th class="px-5 py-3 text-left text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-slate-300">Seals</th>
                            <th class="px-5 py-3 text-left text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-slate-300">Stores</th>
                        </tr>
                    </template>

                    <template #body="{ data }">
                        <tr v-for="company in data" :key="company.id" @click="openStatusModal(company)" class="cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700">
                            <td class="px-5 py-4">
                                <div class="flex items-center gap-3">
                                    <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-lg bg-slate-800 text-xs font-black text-white">
                                        {{ company.code?.slice(0, 2) || 'NP' }}
                                    </div>
                                    <div class="min-w-0">
                                        <div class="truncate text-sm font-bold text-gray-900 dark:text-gray-100">{{ company.name }}</div>
                                        <div class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-300">
                                            <span class="font-mono">{{ company.code }}</span>
                                            <span :class="company.is_active ? 'text-green-600' : 'text-red-600'" class="font-bold">
                                                {{ company.is_active ? 'Active' : 'Inactive' }}
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </td>

                            <td class="whitespace-nowrap px-5 py-4">
                                <div v-if="company.npc_status" class="text-sm">
                                    <div class="font-bold text-gray-900 dark:text-gray-100">{{ formatDate(company.npc_status.validity_from) }}</div>
                                    <div class="text-xs text-gray-500 dark:text-gray-300">to {{ formatDate(company.npc_status.validity_to) }}</div>
                                </div>
                                <span v-else class="text-xs font-bold text-gray-400 dark:text-gray-400">No {{ currentYear }} record</span>
                            </td>

                            <td class="whitespace-nowrap px-5 py-4">
                                <div class="space-y-1">
                                    <span :class="statusBadgeClass(company.npc_status?.renewal_status || 'No Record')" class="inline-flex rounded-full px-2.5 py-1 text-xs font-black">
                                        {{ company.npc_status?.renewal_status || 'No Record' }}
                                    </span>
                                    <div v-if="company.npc_status" class="text-[11px] font-semibold text-gray-500 dark:text-gray-300">
                                        {{ renewalDaysLabel(company.npc_status.renewal_days) }}
                                    </div>
                                </div>
                            </td>

                            <td class="px-5 py-4">
                                <div v-if="company.npc_status" class="min-w-[170px] space-y-2">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="text-xs font-black text-gray-700 dark:text-gray-300">{{ company.npc_status.workflow_stage }}</span>
                                        <span class="text-xs font-bold text-gray-500 dark:text-gray-300">{{ company.npc_status.workflow_progress }}%</span>
                                    </div>
                                    <div class="h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800">
                                        <div class="h-full rounded-full bg-blue-600" :style="{ width: `${company.npc_status.workflow_progress}%` }"></div>
                                    </div>
                                </div>
                                <span v-else class="text-xs font-bold text-gray-400 dark:text-gray-400">No workflow</span>
                            </td>

                            <td class="whitespace-nowrap px-5 py-4">
                                <div v-if="company.npc_status" class="text-sm font-black text-gray-800 dark:text-gray-200">{{ sealsUploaded(company) }}/3 seals</div>
                                <span v-else class="text-xs font-bold text-gray-400 dark:text-gray-400">—</span>
                            </td>

                            <td class="whitespace-nowrap px-5 py-4">
                                <div class="text-sm font-black text-gray-800 dark:text-gray-200">{{ company.store_count }} Stores</div>
                                <div v-if="company.npc_status && company.store_count" class="mt-0.5 text-[11px] font-semibold text-gray-500 dark:text-gray-300">
                                    {{ storesConfirmed(company) }} confirmed
                                </div>
                            </td>
                        </tr>
                    </template>
                </DataTable>
                </template>
            </div>
        </div>

        <!-- ══════════════════ ENTITY HISTORY / EDITOR MODAL ══════════════════ -->
        <div v-if="showModal" class="fixed inset-0 z-[90] overflow-y-auto">
            <div class="flex min-h-screen items-center justify-center px-4 py-6">
                <div class="fixed inset-0 bg-black/20 backdrop-blur-md" @click="closeModal"></div>
                <div class="relative flex max-h-[90vh] w-full max-w-none flex-col rounded-xl border border-gray-100 bg-white shadow-2xl dark:bg-gray-800 dark:border-gray-700">

                    <!-- Header -->
                    <div class="border-b p-5 dark:border-gray-700">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h3 class="text-xl font-bold text-gray-900 dark:text-gray-100">{{ selectedCompany?.name }}</h3>
                                <p class="mt-0.5 text-xs font-black uppercase tracking-widest text-gray-400 dark:text-gray-400">{{ selectedCompany?.code }} — NPC Renewal · {{ effectiveModalYear }}</p>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <div class="flex items-center gap-1.5">
                                    <span class="text-[10px] font-black uppercase tracking-wider text-gray-400">Validity Year</span>
                                    <div class="flex items-stretch overflow-hidden rounded-lg border border-gray-300 dark:border-gray-600">
                                        <input
                                            type="number"
                                            :value="effectiveModalYear"
                                            @change="onYearInput"
                                            @keydown.enter.prevent="onYearInput"
                                            min="2000"
                                            max="2100"
                                            step="1"
                                            inputmode="numeric"
                                            aria-label="Validity year"
                                            class="w-16 border-0 bg-transparent py-1 text-center text-sm font-black text-gray-900 focus:ring-0 dark:text-gray-100 [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none"
                                        >
                                        <div class="flex flex-col border-l border-gray-300 dark:border-gray-600">
                                            <button type="button" @click="stepModalYear(1)" aria-label="Increase year" class="flex flex-1 items-center px-1.5 text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-700">
                                                <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 15l7-7 7 7"/></svg>
                                            </button>
                                            <button type="button" @click="stepModalYear(-1)" aria-label="Decrease year" class="flex flex-1 items-center border-t border-gray-300 px-1.5 text-gray-500 hover:bg-gray-100 dark:border-gray-600 dark:hover:bg-gray-700">
                                                <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M19 9l-7 7-7-7"/></svg>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <span v-if="isLoadingCompany" class="rounded-full bg-blue-50 px-2.5 py-1 text-[10px] font-black text-blue-700 dark:bg-blue-900/30 dark:text-blue-300">Loading…</span>
                                <span v-else-if="modalNpcStatus" class="rounded-full bg-blue-50 px-2.5 py-1 text-[10px] font-black text-blue-700 dark:bg-blue-900/30 dark:text-blue-300">Existing {{ effectiveModalYear }} record</span>
                                <span v-else-if="hasPermission('npc_status.create')" class="rounded-full bg-emerald-50 px-2.5 py-1 text-[10px] font-black text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300">New {{ effectiveModalYear }} renewal</span>
                                <span v-if="isModalReadOnly" class="rounded-full bg-amber-100 px-2.5 py-1 text-[10px] font-black text-amber-700">Read-only</span>
                                <button type="button" @click="closeModal" class="ml-1 rounded-full p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:text-gray-400 dark:hover:bg-gray-700">
                                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Body -->
                    <div class="flex-1 space-y-6 overflow-y-auto p-6">

                        <div v-if="isLoadingCompany" class="rounded-lg border border-blue-100 bg-blue-50 px-4 py-3 text-sm font-semibold text-blue-700 dark:border-blue-900/40 dark:bg-blue-900/20 dark:text-blue-300">
                            Loading the latest renewal record...
                        </div>
                        <div v-else-if="modalLoadError" class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 dark:border-red-900/40 dark:bg-red-900/20">
                            <span class="text-sm font-semibold text-red-700 dark:text-red-300">{{ modalLoadError }}</span>
                            <button type="button" @click="loadSelectedCompany" class="rounded-lg bg-red-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-red-700">Retry</button>
                        </div>

                        <!-- ── Validity ── -->
                        <section>
                            <h4 class="mb-3 text-xs font-black uppercase tracking-widest text-gray-500 dark:text-gray-300">Validity</h4>

                            <!-- Finalized or permission-restricted record -->
                            <div v-if="isModalReadOnly" class="rounded-lg border border-gray-200 bg-gray-50 p-5 dark:bg-gray-900/50 dark:border-gray-700">
                                <div v-if="modalNpcStatus" class="grid grid-cols-2 gap-6">
                                    <div class="col-span-2">
                                        <div class="text-[10px] font-bold uppercase tracking-wide text-gray-400">Application Type</div>
                                        <span class="mt-0.5 inline-flex rounded-full px-2.5 py-1 text-xs font-black uppercase tracking-wide" :class="computedEntryType === 'Renewal' ? 'bg-amber-100 text-amber-800' : 'bg-emerald-100 text-emerald-800'">{{ computedEntryType }}</span>
                                    </div>
                                    <div>
                                        <div class="text-[10px] font-bold uppercase tracking-wide text-gray-400">From</div>
                                        <div class="mt-0.5 text-base font-bold text-gray-900 dark:text-gray-100">{{ formatDate(modalNpcStatus.validity_from) }}</div>
                                    </div>
                                    <div>
                                        <div class="text-[10px] font-bold uppercase tracking-wide text-gray-400">To</div>
                                        <div class="mt-0.5 text-base font-bold text-gray-900 dark:text-gray-100">{{ formatDate(modalNpcStatus.validity_to) }}</div>
                                    </div>
                                </div>
                                <p v-else class="text-sm font-semibold text-gray-500 dark:text-gray-300">No NPC renewal record for {{ effectiveModalYear }}.</p>
                            </div>

                            <!-- Editable current or historical record -->
                            <div v-else class="space-y-4">
                                <div>
                                    <label class="mb-1 block text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-gray-300">Application Type</label>
                                    <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-black uppercase tracking-wide" :class="computedEntryType === 'Renewal' ? 'bg-amber-100 text-amber-800' : 'bg-emerald-100 text-emerald-800'">{{ computedEntryType }}</span>
                                    <p class="mt-1 text-[11px] font-medium text-gray-400">Set automatically — <strong>Renewal</strong> when the entity has an earlier record, otherwise <strong>New</strong>.</p>
                                    <p v-if="computedEntryType === 'Renewal' && !modalNpcStatus && priorRecord" class="mt-1 text-xs font-medium text-blue-600 dark:text-blue-300">
                                        Recent details from {{ priorRecord.year }} will be pre-filled after you save.
                                    </p>
                                </div>
                                <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
                                    <div>
                                        <label class="mb-1 block text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-gray-300">Validity From</label>
                                        <input v-model="statusForm.validity_from" :disabled="!canEditValidity" type="date" class="block w-full rounded-lg border-gray-300 text-sm shadow-sm disabled:cursor-not-allowed disabled:bg-gray-100 focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 dark:disabled:bg-gray-800">
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-gray-300">Validity To</label>
                                        <input v-model="statusForm.validity_to" :disabled="!canEditValidity" type="date" class="block w-full rounded-lg border-gray-300 text-sm shadow-sm disabled:cursor-not-allowed disabled:bg-gray-100 focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 dark:disabled:bg-gray-800">
                                    </div>
                                </div>
                                <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-gray-200 bg-gray-50 p-4 dark:bg-gray-900/50 dark:border-gray-700">
                                    <div class="flex items-center gap-3">
                                        <span :class="statusBadgeClass(previewRenewalStatus)" class="inline-flex rounded-full px-2.5 py-1 text-xs font-black">{{ previewRenewalStatus }}</span>
                                        <span class="text-sm font-semibold text-gray-600 dark:text-gray-300">{{ previewRenewalDaysLabel }}</span>
                                    </div>
                                </div>
                            </div>
                        </section>

                        <!-- ── Application Workflow ── -->
                        <section ref="workflowSection">
                            <div class="mb-3 flex items-center justify-between">
                                <h4 class="text-xs font-black uppercase tracking-widest text-gray-500 dark:text-gray-300">Application Workflow</h4>
                                <span v-if="modalNpcStatus" class="rounded-full bg-blue-50 px-2.5 py-1 text-xs font-black text-blue-700">{{ modalNpcStatus.workflow_progress ?? workflowProgress }}%</span>
                            </div>

                            <!-- Full workflow remains tabbed for both editable and read-only records. -->
                            <div>
                                <div v-if="isLoadingCompany" class="rounded-lg border border-dashed border-blue-200 bg-blue-50 p-6 text-center text-sm font-semibold text-blue-700 dark:border-blue-900/40 dark:bg-blue-900/20 dark:text-blue-300">
                                    Checking for an existing application workflow...
                                </div>
                                <div v-else-if="!modalNpcStatus" class="rounded-lg border border-dashed border-gray-200 bg-gray-50 p-6 text-center text-sm font-semibold text-gray-500 dark:bg-gray-900/50 dark:text-gray-300 dark:border-gray-700">
                                    Save the validity dates above first to start the workflow.
                                </div>
                                <div v-else class="space-y-3">
                                    <div v-if="isModalReadOnly" class="rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-xs font-bold text-blue-700 dark:border-blue-900/50 dark:bg-blue-900/20 dark:text-blue-300">
                                        This workflow is read-only. All completed step tabs and their saved details remain available to view.
                                    </div>

                                    <!-- Step tabs (a later tab stays disabled until the prior step is done) -->
                                    <div class="flex flex-wrap gap-1.5">
                                        <button
                                            v-for="(step, i) in workflowForm"
                                            :key="'tab-' + step.key"
                                            type="button"
                                            :disabled="!stepEnabled(i)"
                                            @click="goToStep(i)"
                                            :class="[
                                                'flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-xs font-black transition-colors',
                                                i === activeStep
                                                    ? 'border-blue-600 bg-blue-600 text-white shadow-sm'
                                                    : stepEnabled(i)
                                                        ? 'border-gray-200 bg-white text-gray-600 hover:border-blue-400 hover:text-blue-600 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300'
                                                        : 'cursor-not-allowed border-gray-100 bg-gray-50 text-gray-300 dark:border-gray-800 dark:bg-gray-900/40 dark:text-gray-600'
                                            ]"
                                        >
                                            <span class="flex h-5 w-5 items-center justify-center rounded-full text-[10px]" :class="step.is_done ? 'bg-green-500 text-white' : i === activeStep ? 'bg-white/25 text-white' : 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-300'">
                                                <svg v-if="step.is_done" class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                                                <template v-else>{{ i + 1 }}</template>
                                            </span>
                                            <span class="hidden md:inline">{{ step.label }}</span>
                                            <span class="md:hidden">Step {{ i + 1 }}</span>
                                        </button>
                                    </div>

                                    <!-- Active step card -->
                                    <div v-for="(step, i) in workflowForm" v-show="i === activeStep" :key="step.key" class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start">
                                            <label class="flex min-w-[210px] items-center gap-2 text-sm font-bold text-gray-800 dark:text-gray-200">
                                                <input
                                                    v-model="step.is_done"
                                                    type="checkbox"
                                                    :disabled="!canEditSelectedRecord || !stepEnabled(i)"
                                                    class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 disabled:cursor-not-allowed dark:border-gray-600"
                                                    @change="onStepToggle(i)"
                                                >
                                                <span class="flex h-5 w-5 items-center justify-center rounded-full bg-gray-100 text-[10px] font-black text-gray-500 dark:bg-gray-700 dark:text-gray-300">{{ i + 1 }}</span>
                                                Step {{ i + 1 }}: {{ step.label }}
                                            </label>
                                            <div class="grid flex-1 grid-cols-1 gap-3 md:grid-cols-[170px_minmax(220px,1fr)]">
                                                <input v-model="step.completed_at" :disabled="!canEditSelectedRecord || !step.is_done" type="date" class="rounded-lg border-gray-300 text-sm disabled:cursor-not-allowed disabled:bg-gray-100 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 dark:disabled:bg-gray-800">
                                                <textarea v-model="step.remarks" :disabled="!canEditSelectedRecord" rows="1" class="rounded-lg border-gray-300 text-sm disabled:cursor-not-allowed disabled:bg-gray-100 focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 dark:disabled:bg-gray-800" placeholder="Remarks"></textarea>
                                            </div>
                                        </div>

                                        <!-- Step 1 expansion — Account Registration -->
                                        <div v-if="i === 0 && stepEnabled(0)" class="mt-4 space-y-4 rounded-lg border border-blue-100 bg-blue-50/40 p-4 dark:border-blue-900/40 dark:bg-blue-900/10">
                                            <div>
                                                <label class="mb-1 block text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-gray-300">Email to Register <span class="text-red-500">*</span></label>
                                                <input v-model="accountForm.register_email" :disabled="!canEditSelectedRecord" type="email" autocomplete="off" placeholder="name@example.com" :data-invalid="isFieldInvalid('account.register_email') || null" :class="emailClass('account.register_email', accountForm.register_email)" class="block w-full rounded-lg border-gray-300 text-sm shadow-sm disabled:cursor-not-allowed disabled:bg-gray-100 focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 dark:disabled:bg-gray-800">
                                                <p v-if="emailMalformed(accountForm.register_email)" class="mt-1 text-[11px] font-semibold text-red-600">Enter a valid email address.</p>
                                            </div>
                                            <div>
                                                <label class="mb-1 block text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-gray-300">Email Password <span class="text-red-500">*</span></label>
                                                <div class="relative">
                                                    <input
                                                        v-model="accountForm.register_password"
                                                        :type="showPassword ? 'text' : 'password'"
                                                        :disabled="!canEditSelectedRecord || clearPasswordFlag"
                                                        autocomplete="new-password"
                                                        :placeholder="accountForm.has_password ? '•••••••• saved — leave blank to keep' : 'Enter password'"
                                                        :data-invalid="isFieldInvalid('account.register_password') || null"
                                                        :class="invalidClass('account.register_password')"
                                                        class="block w-full rounded-lg border-gray-300 pr-10 text-sm shadow-sm disabled:cursor-not-allowed disabled:bg-gray-100 focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 dark:disabled:bg-gray-800"
                                                    >
                                                    <button
                                                        v-if="hasPermission('npc_status.reveal_password')"
                                                        type="button"
                                                        @click="togglePassword"
                                                        :title="showPassword ? 'Hide password' : 'Reveal password'"
                                                        class="absolute inset-y-0 right-0 flex items-center px-3 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200"
                                                    >
                                                        <svg v-if="!showPassword" class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                                        <svg v-else class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/></svg>
                                                    </button>
                                                </div>
                                                <p v-if="!hasPermission('npc_status.reveal_password')" class="mt-1 text-[11px] font-medium text-gray-400">Password is stored encrypted. You do not have permission to reveal it.</p>
                                                <button v-if="accountForm.has_password && canEditSelectedRecord && !clearPasswordFlag" type="button" @click="clearSavedPassword" class="mt-1 text-[11px] font-black text-red-600 hover:text-red-800">Remove saved password</button>
                                                <p v-if="clearPasswordFlag" class="mt-1 text-[11px] font-bold text-red-600">Saved password will be removed on save. <button type="button" @click="clearPasswordFlag = false" class="underline">Undo</button></p>
                                            </div>
                                            <p v-if="canEditSelectedRecord && !step1Valid" class="rounded-md bg-amber-50 px-3 py-2 text-[11px] font-bold text-amber-700 dark:bg-amber-900/20 dark:text-amber-300">Fill in the email and password before you can mark Step 1 as done.</p>
                                        </div>

                                        <!-- Step 2 expansion — DPO Profile Information -->
                                        <div v-if="i === 1 && stepEnabled(1)" class="mt-4 space-y-4 rounded-lg border border-blue-100 bg-blue-50/40 p-4 dark:border-blue-900/40 dark:bg-blue-900/10">
                                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                                <div>
                                                    <label class="mb-1 block text-[11px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-300">First Name <span class="text-red-500">*</span></label>
                                                    <input v-model="dpoProfileForm.first_name" :disabled="!canEditSelectedRecord" type="text" :data-invalid="isFieldInvalid('dpo.first_name') || null" :class="invalidClass('dpo.first_name')" class="block w-full rounded-lg border-gray-300 text-sm shadow-sm disabled:bg-gray-100 focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 dark:disabled:bg-gray-800">
                                                </div>
                                                <div>
                                                    <label class="mb-1 block text-[11px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-300">Middle Initial <span class="font-normal normal-case text-gray-400">(Optional)</span></label>
                                                    <input v-model="dpoProfileForm.middle_initial" :disabled="!canEditSelectedRecord" type="text" maxlength="20" class="block w-full rounded-lg border-gray-300 text-sm shadow-sm disabled:bg-gray-100 focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 dark:disabled:bg-gray-800">
                                                </div>
                                                <div>
                                                    <label class="mb-1 block text-[11px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-300">Last Name <span class="text-red-500">*</span></label>
                                                    <input v-model="dpoProfileForm.last_name" :disabled="!canEditSelectedRecord" type="text" :data-invalid="isFieldInvalid('dpo.last_name') || null" :class="invalidClass('dpo.last_name')" class="block w-full rounded-lg border-gray-300 text-sm shadow-sm disabled:bg-gray-100 focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 dark:disabled:bg-gray-800">
                                                </div>
                                                <div>
                                                    <label class="mb-1 block text-[11px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-300">Sex <span class="text-red-500">*</span></label>
                                                    <select v-model="dpoProfileForm.sex" :disabled="!canEditSelectedRecord" :data-invalid="isFieldInvalid('dpo.sex') || null" :class="invalidClass('dpo.sex')" class="block w-full rounded-lg border-gray-300 pl-2 pr-7 text-sm shadow-sm disabled:bg-gray-100 focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 dark:disabled:bg-gray-800">
                                                        <option value="">—</option>
                                                        <option value="Male">Male</option>
                                                        <option value="Female">Female</option>
                                                    </select>
                                                </div>
                                                <div>
                                                    <label class="mb-1 block text-[11px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-300">Designation <span class="text-red-500">*</span></label>
                                                    <input v-model="dpoProfileForm.designation" :disabled="!canEditSelectedRecord" type="text" :data-invalid="isFieldInvalid('dpo.designation') || null" :class="invalidClass('dpo.designation')" class="block w-full rounded-lg border-gray-300 text-sm shadow-sm disabled:bg-gray-100 focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 dark:disabled:bg-gray-800">
                                                </div>
                                                <div>
                                                    <label class="mb-1 block text-[11px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-300">Date of Designation as DPO <span class="text-red-500">*</span></label>
                                                    <input v-model="dpoProfileForm.date_designated_dpo" :disabled="!canEditSelectedRecord" type="date" :data-invalid="isFieldInvalid('dpo.date_designated_dpo') || null" :class="invalidClass('dpo.date_designated_dpo')" class="block w-full rounded-lg border-gray-300 text-sm shadow-sm disabled:bg-gray-100 focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 dark:disabled:bg-gray-800">
                                                </div>
                                                <div>
                                                    <label class="mb-1 block text-[11px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-300">Official DPO Email <span class="text-red-500">*</span></label>
                                                    <input v-model="dpoProfileForm.official_dpo_email" :disabled="!canEditSelectedRecord" type="email" :data-invalid="isFieldInvalid('dpo.official_dpo_email') || null" :class="emailClass('dpo.official_dpo_email', dpoProfileForm.official_dpo_email)" class="block w-full rounded-lg border-gray-300 text-sm shadow-sm disabled:bg-gray-100 focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 dark:disabled:bg-gray-800">
                                                    <p v-if="emailMalformed(dpoProfileForm.official_dpo_email)" class="mt-1 text-[11px] font-semibold text-red-600">Enter a valid email address.</p>
                                                </div>
                                                <div>
                                                    <label class="mb-1 block text-[11px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-300">Mobile No. <span class="text-red-500">*</span></label>
                                                    <input v-model="dpoProfileForm.mobile_no" :disabled="!canEditSelectedRecord" type="text" :data-invalid="isFieldInvalid('dpo.mobile_no') || null" :class="invalidClass('dpo.mobile_no')" class="block w-full rounded-lg border-gray-300 text-sm shadow-sm disabled:bg-gray-100 focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 dark:disabled:bg-gray-800">
                                                </div>
                                                <div>
                                                    <label class="mb-1 block text-[11px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-300">Telephone Number <span class="font-normal normal-case text-gray-400">(Optional)</span></label>
                                                    <input v-model="dpoProfileForm.telephone_no" :disabled="!canEditSelectedRecord" type="text" class="block w-full rounded-lg border-gray-300 text-sm shadow-sm disabled:bg-gray-100 focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 dark:disabled:bg-gray-800">
                                                </div>
                                                <div>
                                                    <label class="mb-1 block text-[11px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-300">Role <span class="text-red-500">*</span></label>
                                                    <input v-model="dpoProfileForm.role" :disabled="!canEditSelectedRecord" type="text" placeholder="PIC/PIP" :data-invalid="isFieldInvalid('dpo.role') || null" :class="invalidClass('dpo.role')" class="block w-full rounded-lg border-gray-300 text-sm shadow-sm disabled:bg-gray-100 focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 dark:disabled:bg-gray-800">
                                                </div>
                                            </div>

                                            <div>
                                                <div class="mb-2 text-[11px] font-black uppercase tracking-widest text-blue-700 dark:text-blue-300">Generated Backup Codes <span class="text-red-500">*</span></div>
                                                <div :data-invalid="isFieldInvalid('dpo.backup_codes') || null" class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-5 rounded-lg" :class="isFieldInvalid('dpo.backup_codes') ? 'p-1 ring-1 ring-red-500' : ''">
                                                    <input
                                                        v-for="(code, idx) in backupCodes"
                                                        :key="idx"
                                                        v-model="backupCodes[idx]"
                                                        :disabled="!canEditSelectedRecord"
                                                        type="text"
                                                        inputmode="numeric"
                                                        :placeholder="`Code ${idx + 1}`"
                                                        class="block w-full rounded-lg border-gray-300 font-mono text-sm shadow-sm disabled:bg-gray-100 focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 dark:disabled:bg-gray-800"
                                                    >
                                                </div>
                                                <p class="mt-1 text-[11px] font-medium text-gray-400">Enter at least {{ BACKUP_CODE_COUNT }} backup codes.</p>
                                            </div>
                                            <p v-if="canEditSelectedRecord && !step2Valid" class="rounded-md bg-amber-50 px-3 py-2 text-[11px] font-bold text-amber-700 dark:bg-amber-900/20 dark:text-amber-300">Complete all required (*) fields and backup codes before you can mark Step 2 as done.</p>
                                        </div>

                                        <!-- Step 3 expansion — DPO Registration -->
                                        <div v-if="i === 2 && stepEnabled(2)" class="mt-4 space-y-2">
                                            <NpcRegistrationStep
                                                :model="registrationForm"
                                                :dpo-profile="dpoProfileForm"
                                                :documents="modalNpcStatus?.documents || {}"
                                                :document-types="documentTypes"
                                                :can-edit="canEditSelectedRecord"
                                                :invalid-keys="invalidFieldKeysArray"
                                                @upload="uploadDocument"
                                                @delete="deleteDocument"
                                                @add-dps="addDps"
                                                @remove-dps="removeDps"
                                            />
                                            <p v-if="canEditSelectedRecord && !step3Valid" class="rounded-md bg-amber-50 px-3 py-2 text-[11px] font-bold text-amber-700 dark:bg-amber-900/20 dark:text-amber-300">Complete all required (*) registration fields and upload the supporting documents before you can mark Step 3 as done.</p>
                                        </div>

                                        <!-- Step 4 expansion — Status of DPO Registration / NPC Approval -->
                                        <div v-if="i === 3 && stepEnabled(3)" class="mt-4 space-y-4 rounded-lg border border-blue-100 bg-blue-50/40 p-4 dark:border-blue-900/40 dark:bg-blue-900/10">
                                            <div class="sm:max-w-xs">
                                                <label class="mb-1 block text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-gray-300">Status of DPO Registration <span class="text-red-500">*</span></label>
                                                <select v-model="approvalForm.approval_status" :disabled="!canEditSelectedRecord" :data-invalid="isFieldInvalid('approval.status') || null" :class="invalidClass('approval.status')" class="block w-full rounded-lg border-gray-300 pl-2 pr-7 text-sm shadow-sm disabled:cursor-not-allowed disabled:bg-gray-100 focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 dark:disabled:bg-gray-800">
                                                    <option value="For Submission">For Submission</option>
                                                    <option value="Submitted">Submitted</option>
                                                    <option value="Approved">Approved</option>
                                                    <option value="Rejected">Rejected</option>
                                                </select>
                                            </div>

                                            <!-- Payment details only when Approved -->
                                            <div v-if="approvalForm.approval_status === 'Approved'" class="space-y-4 rounded-lg border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-800">
                                                <div class="text-[11px] font-black uppercase tracking-widest text-blue-700 dark:text-blue-300">Payment Details</div>
                                                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                                    <div>
                                                        <label class="mb-1 block text-[11px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-300">Year <span class="text-red-500">*</span></label>
                                                        <input v-model="approvalForm.year" :disabled="!canEditSelectedRecord" type="number" min="2000" max="2100" :data-invalid="isFieldInvalid('payment.year') || null" :class="invalidClass('payment.year')" class="block w-full rounded-lg border-gray-300 text-sm shadow-sm disabled:bg-gray-100 focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 dark:disabled:bg-gray-800">
                                                    </div>
                                                    <div>
                                                        <label class="mb-1 block text-[11px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-300">Reference No <span class="text-red-500">*</span></label>
                                                        <input v-model="approvalForm.reference_no" :disabled="!canEditSelectedRecord" type="text" :data-invalid="isFieldInvalid('payment.reference_no') || null" :class="invalidClass('payment.reference_no')" class="block w-full rounded-lg border-gray-300 text-sm shadow-sm disabled:bg-gray-100 focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 dark:disabled:bg-gray-800">
                                                    </div>
                                                    <div>
                                                        <label class="mb-1 block text-[11px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-300">Transaction No <span class="text-red-500">*</span></label>
                                                        <input v-model="approvalForm.transaction_no" :disabled="!canEditSelectedRecord" type="text" :data-invalid="isFieldInvalid('payment.transaction_no') || null" :class="invalidClass('payment.transaction_no')" class="block w-full rounded-lg border-gray-300 text-sm shadow-sm disabled:bg-gray-100 focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 dark:disabled:bg-gray-800">
                                                    </div>
                                                    <div>
                                                        <label class="mb-1 block text-[11px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-300">Date of Payment <span class="text-red-500">*</span></label>
                                                        <input v-model="approvalForm.date_of_payment" :disabled="!canEditSelectedRecord" type="date" :data-invalid="isFieldInvalid('payment.date_of_payment') || null" :class="invalidClass('payment.date_of_payment')" class="block w-full rounded-lg border-gray-300 text-sm shadow-sm disabled:bg-gray-100 focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 dark:disabled:bg-gray-800">
                                                    </div>
                                                    <div>
                                                        <label class="mb-1 block text-[11px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-300">Transaction Type <span class="text-red-500">*</span></label>
                                                        <select v-model="approvalForm.transaction_type" :disabled="!canEditSelectedRecord" :data-invalid="isFieldInvalid('payment.transaction_type') || null" :class="invalidClass('payment.transaction_type')" class="block w-full rounded-lg border-gray-300 pl-2 pr-7 text-sm shadow-sm disabled:bg-gray-100 focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 dark:disabled:bg-gray-800">
                                                            <option value="">—</option>
                                                            <option value="Registration Fees">Registration Fees</option>
                                                            <option value="Renewal">Renewal</option>
                                                        </select>
                                                    </div>
                                                    <div>
                                                        <label class="mb-1 block text-[11px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-300">Amount <span class="text-red-500">*</span></label>
                                                        <input v-model="approvalForm.amount" :disabled="!canEditSelectedRecord" type="number" step="0.01" min="0" :data-invalid="isFieldInvalid('payment.amount') || null" :class="invalidClass('payment.amount')" class="block w-full rounded-lg border-gray-300 text-sm shadow-sm disabled:bg-gray-100 focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 dark:disabled:bg-gray-800">
                                                    </div>
                                                </div>

                                                <div :data-invalid="isFieldInvalid('payment.receipt') || null" class="rounded-lg border p-3" :class="isFieldInvalid('payment.receipt') ? 'border-red-500 ring-1 ring-red-500' : 'border-gray-200 dark:border-gray-700'">
                                                    <div class="text-[11px] font-bold text-gray-700 dark:text-gray-200">Upload Receipt <span class="text-red-500">*</span></div>
                                                    <div class="mt-1.5 flex flex-wrap items-center gap-2">
                                                        <template v-if="modalNpcStatus?.documents?.payment_receipt">
                                                            <a :href="modalNpcStatus.documents.payment_receipt.url" class="truncate text-xs font-bold text-blue-600 hover:underline">{{ modalNpcStatus.documents.payment_receipt.name || 'Download' }}</a>
                                                            <button v-if="canEditSelectedRecord" type="button" @click="deleteDocument({ id: modalNpcStatus.documents.payment_receipt.id })" class="text-[11px] font-black text-red-600 hover:text-red-800">Remove</button>
                                                        </template>
                                                        <input v-else-if="canEditSelectedRecord" :key="receiptInputKey" type="file" accept=".pdf,.jpg,.jpeg,.png,.webp,.gif,.bmp,.heic,.heif" class="w-full text-xs text-gray-500 file:mr-2 file:rounded-full file:border-0 file:bg-blue-50 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-blue-700 dark:text-gray-300" @change="onReceiptFile($event)">
                                                        <span v-else class="text-xs font-semibold text-gray-400">Not uploaded</span>
                                                    </div>
                                                </div>
                                            </div>

                                            <p v-if="canEditSelectedRecord && !step4Valid" class="rounded-md bg-amber-50 px-3 py-2 text-[11px] font-bold text-amber-700 dark:bg-amber-900/20 dark:text-amber-300">Set status to <strong>Approved</strong> and complete the payment details (including receipt) — Step 5 stays locked until then.</p>
                                        </div>

                                        <!-- Step 5 expansion — Store/Office Receiving -->
                                        <div v-if="i === 4 && stepEnabled(4)" class="mt-4 space-y-5 rounded-lg border border-blue-100 bg-blue-50/40 p-4 dark:border-blue-900/40 dark:bg-blue-900/10">

                                            <!-- Seals to release -->
                                            <div>
                                                <div class="mb-2 text-[11px] font-black uppercase tracking-widest text-blue-700 dark:text-blue-300">Entity-wide Seals to Release <span class="text-red-500">*</span></div>
                                                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                                    <div v-for="sealType in entitySealTypes" :key="sealType.type" :data-invalid="isFieldInvalid('seal.' + sealType.type) || null" class="rounded-lg border bg-white p-3 dark:bg-gray-800" :class="isFieldInvalid('seal.' + sealType.type) ? 'border-red-500 ring-1 ring-red-500' : 'border-gray-200 dark:border-gray-700'">
                                                        <div class="mb-2 text-xs font-black uppercase tracking-wider text-gray-600 dark:text-gray-300">{{ sealType.label }}</div>
                                                        <template v-if="currentSeal(sealType.type)">
                                                            <a v-if="canEditNpcStatus" :href="currentSeal(sealType.type).url" class="block truncate text-xs font-bold text-blue-600 hover:underline">{{ currentSeal(sealType.type).name || 'Download' }}</a>
                                                            <span v-else class="block truncate text-xs font-semibold text-gray-500">{{ currentSeal(sealType.type).name || 'Uploaded' }}</span>
                                                            <button v-if="canEditSelectedRecord" type="button" @click="deleteSeal(currentSeal(sealType.type))" class="mt-1 text-[11px] font-black text-red-600 hover:text-red-800">Remove</button>
                                                        </template>
                                                        <template v-else-if="canEditSelectedRecord">
                                                            <input :key="fileInputKey + '-' + sealType.type" type="file" accept=".pdf,.jpg,.jpeg,.png,.webp,.gif,.bmp,.heic,.heif" class="w-full text-xs text-gray-500 file:mr-2 file:rounded-full file:border-0 file:bg-blue-50 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-blue-700 dark:text-gray-300" @change="uploadSeal(sealType.type, $event)">
                                                        </template>
                                                        <span v-else class="text-xs font-semibold text-gray-400">Not uploaded</span>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Assigned stores -->
                                            <div :data-invalid="isFieldInvalid('stores') || null" class="rounded-lg" :class="isFieldInvalid('stores') ? 'p-1 ring-1 ring-red-500' : ''">
                                                <div class="mb-2 text-[11px] font-black uppercase tracking-widest text-blue-700 dark:text-blue-300">Assigned Stores <span class="text-red-500">*</span></div>
                                                <input v-model="storeSearch" type="text" placeholder="Search stores by name, code, area, or brand..." class="mb-2 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                                                <div class="mb-2 flex gap-1 overflow-x-auto">
                                                    <button v-for="tab in storeAssignmentTabs" :key="tab.value" type="button" @click="storeAssignmentTab = tab.value" :class="['whitespace-nowrap rounded-md px-3 py-1 text-[11px] font-black uppercase tracking-wider', storeAssignmentTab === tab.value ? 'bg-blue-600 text-white' : 'bg-white text-gray-500 hover:bg-gray-100 dark:bg-gray-800']">{{ tab.label }} {{ tab.count }}</button>
                                                </div>
                                                <div class="grid max-h-56 grid-cols-1 gap-2 overflow-y-auto sm:grid-cols-2">
                                                    <label
                                                        v-for="store in filteredStores"
                                                        :key="store.id"
                                                        class="flex items-start gap-2 rounded-lg border p-2 text-xs"
                                                        :class="isStoreDisabled(store) ? 'border-gray-200 bg-gray-50 opacity-60' : 'border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800'"
                                                    >
                                                        <input v-model="selectedStoreIds" :value="store.id" :disabled="!canEditSelectedRecord || isStoreDisabled(store)" type="checkbox" class="mt-0.5 rounded border-gray-300 text-blue-600 focus:ring-blue-500 disabled:cursor-not-allowed dark:border-gray-600">
                                                        <span class="min-w-0">
                                                            <span class="block truncate font-bold text-gray-900 dark:text-gray-100">{{ store.name }}</span>
                                                            <span class="block truncate text-gray-500 dark:text-gray-300">{{ store.code }} — {{ store.area }} — {{ store.brand }}</span>
                                                            <span v-if="isStoreDisabled(store)" class="block font-bold text-amber-700">Assigned to {{ store.assigned_company_name }}</span>
                                                        </span>
                                                    </label>
                                                </div>
                                            </div>

                                            <!-- One CCTV seal per selected store assignment -->
                                            <div :data-invalid="isFieldInvalid('seal.cctv_seal') || null" class="rounded-lg border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-800">
                                                <div class="text-[11px] font-black uppercase tracking-widest text-blue-700 dark:text-blue-300">CCTV Seal per Assigned Store <span class="text-red-500">*</span></div>
                                                <p class="mt-1 text-[11px] font-semibold text-gray-500 dark:text-gray-400">Each store receives and downloads only its own CCTV Seal. Selecting a file saves the current store assignments automatically.</p>
                                                <div v-if="!selectedStoreOptions.length" class="mt-3 text-xs font-semibold text-gray-400">Assign at least one store first.</div>
                                                <div v-else class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                                                    <div v-for="store in selectedStoreOptions" :key="'cctv-' + store.id" class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                                                        <div class="text-xs font-black text-gray-800 dark:text-gray-100">{{ store.name }}</div>
                                                        <div class="mb-2 font-mono text-[10px] text-gray-500">{{ store.code }}</div>
                                                        <template v-if="exactCctvSealForStore(store.id)">
                                                            <a :href="exactCctvSealForStore(store.id).url" class="block truncate text-xs font-bold text-blue-600 hover:underline">{{ exactCctvSealForStore(store.id).name || 'Download' }}</a>
                                                            <button v-if="canEditSelectedRecord" type="button" @click="deleteSeal(exactCctvSealForStore(store.id))" class="mt-1 text-[11px] font-black text-red-600 hover:text-red-800">Remove</button>
                                                        </template>
                                                        <template v-else>
                                                            <input
                                                                v-if="canEditSelectedRecord"
                                                                :key="fileInputKey + '-cctv-' + store.id"
                                                                type="file"
                                                                accept=".pdf,.jpg,.jpeg,.png,.webp,.gif,.bmp,.heic,.heif"
                                                                class="mt-2 w-full text-xs text-gray-500 file:mr-2 file:rounded-full file:border-0 file:bg-blue-50 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-blue-700 dark:text-gray-300"
                                                                @change="uploadSeal('cctv_seal', $event, store.id)"
                                                            >
                                                            <span v-else class="text-[11px] font-bold text-amber-600">Not uploaded</span>
                                                        </template>
                                                    </div>
                                                </div>
                                            </div>

                                            <p v-if="canEditSelectedRecord && !step5Valid" class="rounded-md bg-amber-50 px-3 py-2 text-[11px] font-bold text-amber-700 dark:bg-amber-900/20 dark:text-amber-300">Upload both entity-wide seals, assign at least one store, and upload one CCTV Seal for every assigned store before marking Step 5 as done.</p>
                                        </div>

                                        <!-- Step 6 expansion — Store/Office Downloads & Confirmation -->
                                        <div v-if="i === 5 && stepEnabled(5)" class="mt-4 space-y-5 rounded-lg border border-blue-100 bg-blue-50/40 p-4 dark:border-blue-900/40 dark:bg-blue-900/10">
                                            <div v-if="receiptGrid.length">
                                                <div class="mb-2 text-[11px] font-black uppercase tracking-widest text-blue-700 dark:text-blue-300">Store Downloads &amp; Confirmation</div>
                                                <p class="mb-2 text-[11px] font-semibold text-gray-500 dark:text-gray-400">DPO Seal and DPO Registration are shared with every assigned store. CCTV Seal remains unique per store. Each file can be confirmed only after that store downloads it and uploads proof of use.</p>
                                                <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
                                                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                                        <thead class="bg-gray-50 dark:bg-gray-900/50">
                                                            <tr>
                                                                <th class="px-3 py-2 text-left text-[10px] font-black uppercase tracking-wider text-gray-500">Store</th>
                                                                <th v-for="sealType in sealTypes" :key="sealType.type" class="px-3 py-2 text-left text-[10px] font-black uppercase tracking-wider text-gray-500">{{ sealType.label }}</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                                            <tr v-for="row in receiptGrid" :key="row.store_id" :data-invalid="isFieldInvalid('step6.store.' + row.store_id) || null" :class="isFieldInvalid('step6.store.' + row.store_id) ? 'bg-red-50 dark:bg-red-900/10' : ''">
                                                                <td class="px-3 py-2">
                                                                    <div class="text-xs font-bold text-gray-900 dark:text-gray-100">{{ row.store_name }}</div>
                                                                    <div class="font-mono text-[10px] text-gray-500">{{ row.store_code }}</div>
                                                                </td>
                                                                <td v-for="sealType in sealTypes" :key="sealType.type" class="px-3 py-2 align-top">
                                                                    <div class="text-[10px] font-semibold" :class="row.seals[sealType.type]?.downloaded_at ? 'text-gray-600 dark:text-gray-300' : 'text-gray-400'">
                                                                        {{ row.seals[sealType.type]?.downloaded_at ? 'Downloaded ' + formatDateTime(row.seals[sealType.type].downloaded_at) : 'Not downloaded' }}
                                                                    </div>
                                                                    <div v-if="row.seals[sealType.type]?.name" class="mt-0.5 max-w-[160px] truncate text-[10px] font-bold text-blue-600" :title="row.seals[sealType.type].name">{{ row.seals[sealType.type].name }}</div>
                                                                    <div class="mt-1">
                                                                        <template v-if="row.seals[sealType.type]?.proof">
                                                                            <a :href="row.seals[sealType.type].proof.url" class="block max-w-[160px] truncate text-[11px] font-bold text-blue-600 hover:underline">{{ row.seals[sealType.type].proof.name || 'View proof' }}</a>
                                                                            <div class="text-[10px] text-gray-500">Proof {{ formatDateTime(row.seals[sealType.type].proof.uploaded_at) }}</div>
                                                                        </template>
                                                                        <span v-else class="text-[10px] font-bold text-amber-600">Awaiting proof</span>
                                                                    </div>
                                                                    <button
                                                                        v-if="canEditSelectedRecord"
                                                                        type="button"
                                                                        :disabled="!canToggleSeal(row, sealType.type)"
                                                                        :title="sealCheckBlockReason(row, sealType.type)"
                                                                        @click="toggleConfirm(row, sealType.type)"
                                                                        :class="[
                                                                            'mt-1 rounded-full px-2.5 py-1 text-[10px] font-black',
                                                                            row.seals[sealType.type]?.confirmed_at
                                                                                ? 'bg-green-100 text-green-800 hover:bg-green-200'
                                                                                : canToggleSeal(row, sealType.type)
                                                                                    ? 'bg-gray-100 text-gray-600 hover:bg-gray-200'
                                                                                    : 'cursor-not-allowed bg-gray-100 text-gray-400'
                                                                        ]"
                                                                    >
                                                                        {{ row.seals[sealType.type]?.confirmed_at ? 'Checked ✓' : 'Mark checked' }}
                                                                    </button>
                                                                    <span
                                                                        v-else
                                                                        class="mt-1 inline-flex rounded-full px-2.5 py-1 text-[10px] font-black"
                                                                        :class="row.seals[sealType.type]?.confirmed_at
                                                                            ? 'bg-green-100 text-green-800'
                                                                            : 'bg-gray-100 text-gray-600'"
                                                                    >
                                                                        {{ row.seals[sealType.type]?.confirmed_at ? 'Checked ✓' : 'Not checked' }}
                                                                    </span>
                                                                </td>
                                                            </tr>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                            <p v-else class="text-xs font-semibold text-gray-500 dark:text-gray-300">Assign and save stores in Step 5 to track their seal downloads.</p>
                                            <p v-if="canEditSelectedRecord && receiptGrid.length && !step6Valid" class="mt-3 rounded-md bg-amber-50 px-3 py-2 text-[11px] font-bold text-amber-700 dark:bg-amber-900/20 dark:text-amber-300">Every assigned store (highlighted) must download all 3 seals, upload proof of use for each seal, and have all 3 seals marked checked before you can mark Step 6 as done.</p>
                                        </div>
                                    </div>

                                    <!-- Wizard navigation -->
                                    <div class="flex items-center justify-between pt-1">
                                        <button
                                            type="button"
                                            :disabled="activeStep === 0"
                                            @click="goToStep(activeStep - 1)"
                                            class="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs font-bold text-gray-600 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-40 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300"
                                        >← Previous</button>
                                        <span class="text-[11px] font-bold text-gray-400">Step {{ activeStep + 1 }} of {{ workflowForm.length }}</span>
                                        <button
                                            type="button"
                                            :disabled="activeStep >= workflowForm.length - 1 || !stepEnabled(activeStep + 1)"
                                            :title="activeStep < workflowForm.length - 1 && !stepEnabled(activeStep + 1) ? 'Complete this step first' : ''"
                                            @click="goToStep(activeStep + 1)"
                                            class="rounded-lg bg-blue-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-40"
                                        >Next →</button>
                                    </div>
                                </div>
                            </div>
                        </section>
                    </div>

                    <!-- Footer -->
                    <div class="flex items-center justify-between border-t bg-white p-4 dark:bg-gray-800 dark:border-gray-700">
                        <button
                            v-if="canEditSelectedRecord && modalNpcStatus && hasPermission('npc_status.delete')"
                            type="button"
                            @click="deleteRecord(selectedCompany)"
                            class="rounded-lg border border-red-200 bg-white px-4 py-2 text-sm font-semibold text-red-600 hover:bg-red-50 dark:bg-gray-800 dark:border-red-900/50 dark:hover:bg-red-900/20"
                        >Delete Renewal</button>
                        <div v-else></div>
                        <div class="flex items-center gap-2">
                            <button
                                v-if="!isModalReadOnly && !isLoadingCompany && !modalLoadError && canSaveSelectedRecord"
                                type="button"
                                :disabled="isSavingStatus"
                                @click="saveModalChanges"
                                class="rounded-lg bg-blue-600 px-5 py-2 text-sm font-bold text-white shadow-sm hover:bg-blue-700 disabled:opacity-50"
                            >
                                {{ isSavingStatus ? 'Saving Changes...' : 'Save Changes' }}
                            </button>
                            <button type="button" :disabled="isSavingStatus" @click="closeModal" class="rounded-lg bg-gray-100 px-4 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-200 disabled:opacity-50 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600">Close</button>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import axios from 'axios'
import AppLayout from '@/Layouts/AppLayout.vue'
import AssignedStoreSeals from '@/Components/NpcStatus/AssignedStoreSeals.vue'
import NpcRegistrationStep from '@/Components/NpcStatus/NpcRegistrationStep.vue'
import DataTable from '@/Components/DataTable.vue'
import { useConfirm } from '@/Composables/useConfirm'
import { usePagination } from '@/Composables/usePagination'
import { usePermission } from '@/Composables/usePermission'
import { useToast } from '@/Composables/useToast'

const props = defineProps({
    viewMode: { type: String, default: 'admin' },
    npcStatuses: Object,
    filters: Object,
    currentYear: Number,
    statusCounts: Object,
    workflowSteps: Array,
    stores: Array,
    storeSeals: { type: Array, default: () => [] },
    canDownloadAssignedSeals: { type: Boolean, default: false },
    defaultNpcSection: { type: String, default: 'monitoring' },
})

const { confirm } = useConfirm()
const { hasPermission } = usePermission()
const { showSuccess, showError } = useToast()

const sealTypes = [
    { type: 'dpo_seal', label: 'DPO Seal' },
    { type: 'dpo_registration', label: 'DPO Registration' },
    { type: 'cctv_seal', label: 'CCTV Seal' },
]
const entitySealTypes = sealTypes.filter((seal) => seal.type !== 'cctv_seal')

const currentYear = computed(() => props.currentYear || new Date().getFullYear())
const adminSection = ref(props.defaultNpcSection)
const selectedStatus = ref(props.filters?.status || '')
const pagination = usePagination(props.npcStatuses || {}, 'npc-statuses.index', () => {
    const params = {}
    if (selectedStatus.value) params.status = selectedStatus.value
    return params
})

// Infinite scroll accumulation follows the Tickets index pattern: page 1
// replaces the buffer, while later pages append unique company rows.
const accumulatedNpcStatuses = ref([...(props.npcStatuses?.data || [])])
const npcStatusesMeta = ref({
    current_page: props.npcStatuses?.current_page || 1,
    last_page: props.npcStatuses?.last_page || 1,
    total: props.npcStatuses?.total || 0,
})
const loadingMoreNpcStatuses = ref(false)

const mergeNpcStatusPage = (payload) => {
    if (!payload) return

    const incoming = payload.data || []
    if ((payload.current_page || 1) <= 1) {
        accumulatedNpcStatuses.value = [...incoming]
    } else {
        const incomingById = new Map(incoming.map((company) => [String(company.id), company]))
        const updated = accumulatedNpcStatuses.value.map(
            (company) => incomingById.get(String(company.id)) || company
        )
        const seen = new Set(updated.map((company) => String(company.id)))
        accumulatedNpcStatuses.value = [
            ...updated,
            ...incoming.filter((company) => !seen.has(String(company.id))),
        ]
    }

    npcStatusesMeta.value = {
        current_page: payload.current_page || 1,
        last_page: payload.last_page || 1,
        total: payload.total || 0,
    }
}

const hasMoreNpcStatuses = computed(
    () => npcStatusesMeta.value.current_page < npcStatusesMeta.value.last_page
)

const npcStatusesShowingText = computed(() => {
    const total = npcStatusesMeta.value.total || 0
    if (total === 0) return 'No records found'
    return `Showing ${accumulatedNpcStatuses.value.length} of ${total} records`
})

const loadMoreNpcStatuses = () => {
    if (loadingMoreNpcStatuses.value || !hasMoreNpcStatuses.value) return

    loadingMoreNpcStatuses.value = true
    router.reload({
        only: ['npcStatuses'],
        data: {
            status: selectedStatus.value || undefined,
            search: pagination.search.value,
            per_page: pagination.perPage.value,
            page: npcStatusesMeta.value.current_page + 1,
        },
        preserveScroll: true,
        preserveState: true,
        onFinish: () => {
            loadingMoreNpcStatuses.value = false
        },
    })
}

// Modal state
const showModal = ref(false)
const selectedCompany = ref(null)
const fileInputKey = ref(0)
const isSavingStatus = ref(false)
const isLoadingCompany = ref(false)
const modalLoadError = ref('')
const modalYear = ref(null)
const workflowSection = ref(null)
let companyLoadRequest = 0
const canEditNpcStatus = computed(() => hasPermission('npc_status.edit'))
const canEditValidity = computed(() => (
    modalNpcStatus.value
        ? canEditSelectedRecord.value
        : hasPermission('npc_status.create')
))

// Form state
const storeSearch = ref('')
const storeAssignmentTab = ref('all')
const selectedStoreIds = ref([])
const storeOptions = ref([...(props.stores || [])])
const workflowForm = ref([])
// Wizard: which step tab is currently shown. Tabs are gated by stepEnabled().
const activeStep = ref(0)

const firstIncompleteStep = () => {
    const idx = workflowForm.value.findIndex((step) => !step.is_done)
    return idx === -1 ? Math.max(0, workflowForm.value.length - 1) : idx
}

const goToStep = (index) => {
    if (index < 0 || index >= workflowForm.value.length) return
    if (!stepEnabled(index)) return
    activeStep.value = index
}

const BACKUP_CODE_COUNT = 10

const statusForm = reactive({
    company_id: null,
    validity_from: '',
    validity_to: '',
})

// Step 1 — Account Registration
const accountForm = reactive({
    register_email: '',
    register_password: '',
    has_password: false,
})
const showPassword = ref(false)
const clearPasswordFlag = ref(false)

// Step 2 — DPO Profile Information
const dpoProfileForm = reactive({
    first_name: '',
    middle_initial: '',
    last_name: '',
    sex: '',
    designation: '',
    date_designated_dpo: '',
    official_dpo_email: '',
    mobile_no: '',
    telephone_no: '',
    role: 'PIC/PIP',
})
const backupCodes = ref(Array.from({ length: BACKUP_CODE_COUNT }, () => ''))

// Step 4 — NPC Approval + payment
const approvalForm = reactive({
    approval_status: 'For Submission',
    year: '',
    reference_no: '',
    transaction_no: '',
    date_of_payment: '',
    transaction_type: '',
    amount: '',
})

// Step 3 — DPO Registration (stored as one JSON document).
const documentTypes = [
    { type: 'secretary_certificate', label: "Duly notarized Secretary's Certificate authorizing the appointment or designation of the DPO" },
    { type: 'other_appointment_document', label: 'Other document that demonstrates the validity of the appointment with an accompanying valid document conferring authority to appoint persons to positions within the organization' },
    { type: 'sec_certificate', label: 'SEC Certificate of Registration' },
    { type: 'gis', label: 'Certified true copy of current General Information Sheet' },
    { type: 'business_permit', label: 'Valid business permit' },
]

const emptyDps = () => ({
    is_manual_or_automated: '',
    system_name: '',
    basis_of_processing_info: '',
    basis_of_processing_sensitive: '',
    purpose: '',
    data_subjects_categories: '',
    data_categories: '',
    recipients: '',
    pic_or_pip: '',
    outsourced_or_subcontracted: '',
    life_cycle: { when_collected: '', retention_period: '', disposal_procedure: '' },
    security_measures: {
        organizational: '', physical: '', technical: '',
        transferred_outside_ph: '', data_sharing_agreements: '', publicly_facing: '',
        external_internal_facing: '', automated_decision_notification: '', lawful_basis: '',
        other_lawful_basis_info: '', consent_used: '', consent_form: '', other_consent_proof: '',
        processed_retention_period: '', automated_methods_logic: '', possible_decisions: '',
    },
})

const emptyRegistration = () => ({
    organization: { name: '', website: '', country: '', address: '', region: '', province: '', city: '', zip: '', area_of_coverage: '', email: '', contact_no: '' },
    sector: { sector: '', sub_sector: '' },
    head_of_org: { first_name: '', middle_initial: '', last_name: '', official_designation: '', email: '', contact_no: '' },
    compliance_officer: '',
    classification: '',
    sub_classification: '',
    data_processing_systems: [emptyDps()],
})

const registrationForm = ref(emptyRegistration())

// Overlay a serialized registration payload onto a fresh, fully-keyed base so
// missing keys never break v-model bindings.
const mergeRegistration = (src) => {
    const base = emptyRegistration()
    if (!src || typeof src !== 'object') return base

    Object.assign(base.organization, src.organization || {})
    Object.assign(base.sector, src.sector || {})
    Object.assign(base.head_of_org, src.head_of_org || {})
    base.compliance_officer = src.compliance_officer || ''
    base.classification = src.classification || ''
    base.sub_classification = src.sub_classification || ''

    const systems = Array.isArray(src.data_processing_systems) ? src.data_processing_systems : []
    if (systems.length) {
        base.data_processing_systems = systems.map((system) => {
            const dps = emptyDps()
            Object.keys(dps).forEach((key) => {
                if (key === 'life_cycle' || key === 'security_measures') {
                    Object.assign(dps[key], system?.[key] || {})
                } else {
                    dps[key] = system?.[key] ?? ''
                }
            })
            return dps
        })
    }

    return base
}

const applyStep3 = (source) => {
    registrationForm.value = mergeRegistration(source?.registration)
}

const addDps = () => {
    registrationForm.value.data_processing_systems.push(emptyDps())
}

const removeDps = (index) => {
    registrationForm.value.data_processing_systems.splice(index, 1)
    if (!registrationForm.value.data_processing_systems.length) {
        registrationForm.value.data_processing_systems.push(emptyDps())
    }
}

const applyStep4 = (source) => {
    approvalForm.approval_status = source?.approval_status || 'For Submission'
    const p = source?.payment || {}
    approvalForm.year = p.year ?? ''
    approvalForm.reference_no = p.reference_no || ''
    approvalForm.transaction_no = p.transaction_no || ''
    approvalForm.date_of_payment = p.date_of_payment || ''
    approvalForm.transaction_type = p.transaction_type || ''
    approvalForm.amount = p.amount ?? ''
}

// ── Per-step required-field validation ──────────────────────────────────────
// Each function returns the list of *invalid* required-field keys for its step.
// Optional fields (Website, Middle Initial, Telephone, Compliance Officer) are
// never included. The keys double as red-border / scroll anchors in the DOM.
const isBlank = (value) => String(value ?? '').trim() === ''

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
const isValidEmail = (value) => EMAIL_RE.test(String(value ?? '').trim())
// A required email field is invalid when blank OR malformed.
const emailFieldInvalid = (value) => isBlank(value) || !isValidEmail(value)
// Live (during-typing) malformed check: only flags a non-empty bad email.
const emailMalformed = (value) => !isBlank(value) && !isValidEmail(value)

const step1InvalidKeys = () => {
    const keys = []
    if (emailFieldInvalid(accountForm.register_email)) keys.push('account.register_email')
    const hasPassword = Boolean(accountForm.register_password) || (accountForm.has_password && !clearPasswordFlag.value)
    if (!hasPassword) keys.push('account.register_password')
    return keys
}

const step2InvalidKeys = () => {
    const keys = []
    const required = ['first_name', 'last_name', 'sex', 'designation', 'date_designated_dpo', 'mobile_no', 'role']
    required.forEach((field) => { if (isBlank(dpoProfileForm[field])) keys.push(`dpo.${field}`) })
    if (emailFieldInvalid(dpoProfileForm.official_dpo_email)) keys.push('dpo.official_dpo_email')
    const filledCodes = backupCodes.value.filter((code) => !isBlank(code)).length
    if (filledCodes < BACKUP_CODE_COUNT) keys.push('dpo.backup_codes')
    return keys
}

const step3InvalidKeys = () => {
    const keys = []
    const r = registrationForm.value
    if (!r) return ['reg']

    const optionalOrg = ['website']
    const optionalHead = ['middle_initial']

    Object.keys(r.organization).forEach((k) => { if (!optionalOrg.includes(k) && isBlank(r.organization[k])) keys.push(`reg.organization.${k}`) })
    Object.keys(r.sector).forEach((k) => { if (isBlank(r.sector[k])) keys.push(`reg.sector.${k}`) })
    Object.keys(r.head_of_org).forEach((k) => { if (!optionalHead.includes(k) && isBlank(r.head_of_org[k])) keys.push(`reg.head_of_org.${k}`) })
    // Email fields must also be well-formed (not just non-blank).
    if (emailMalformed(r.organization.email)) keys.push('reg.organization.email')
    if (emailMalformed(r.head_of_org.email)) keys.push('reg.head_of_org.email')
    if (isBlank(r.classification)) keys.push('reg.classification')
    if (isBlank(r.sub_classification)) keys.push('reg.sub_classification')

    r.data_processing_systems.forEach((system, idx) => {
        Object.keys(system).forEach((k) => {
            if (k === 'life_cycle' || k === 'security_measures') {
                Object.keys(system[k]).forEach((nk) => { if (isBlank(system[k][nk])) keys.push(`reg.dps.${idx}.${k}.${nk}`) })
            } else if (isBlank(system[k])) {
                keys.push(`reg.dps.${idx}.${k}`)
            }
        })
    })

    const docs = modalNpcStatus.value?.documents || {}
    documentTypes.forEach((doc) => { if (!docs[doc.type]) keys.push(`reg.doc.${doc.type}`) })

    return keys
}

const step4InvalidKeys = () => {
    const keys = []
    if (approvalForm.approval_status !== 'Approved') {
        keys.push('approval.status')
        return keys
    }
    const required = ['year', 'reference_no', 'transaction_no', 'date_of_payment', 'transaction_type', 'amount']
    required.forEach((field) => { if (isBlank(approvalForm[field])) keys.push(`payment.${field}`) })
    if (!modalNpcStatus.value?.documents?.payment_receipt) keys.push('payment.receipt')
    return keys
}

const step5InvalidKeys = () => {
    const keys = []
    const seals = modalNpcStatus.value?.seals || {}
    entitySealTypes.forEach((seal) => { if (!seals[seal.type]?.available) keys.push(`seal.${seal.type}`) })
    if (!selectedStoreIds.value.length) keys.push('stores')
    selectedStoreIds.value.forEach((storeId) => {
        if (!cctvSealForStore(storeId)) keys.push('seal.cctv_seal')
    })
    return keys
}

// Step 6 — every assigned store must have downloaded all 3 seals, uploaded
// proof, and had all 3 seals confirmed before the step can be marked done.
const step6InvalidKeys = () => {
    const keys = []
    const grid = receiptGrid.value
    if (!grid.length) return ['step6.no_stores']
    grid.forEach((row) => {
        // Each of the 3 seals must be downloaded, have its own proof, and be confirmed.
        const complete = sealTypes.every((s) => {
            const seal = row.seals?.[s.type]
            return seal?.downloaded_at && seal?.proof && seal?.confirmed_at
        })
        if (!complete) keys.push(`step6.store.${row.store_id}`)
    })
    return keys
}

const stepInvalidKeys = (index) => {
    if (index === 0) return step1InvalidKeys()
    if (index === 1) return step2InvalidKeys()
    if (index === 2) return step3InvalidKeys()
    if (index === 3) return step4InvalidKeys()
    if (index === 4) return step5InvalidKeys()
    if (index === 5) return step6InvalidKeys()
    return []
}

const stepFieldsSatisfied = (index) => stepInvalidKeys(index).length === 0

// Kept for the inline step hints.
const step1Valid = computed(() => step1InvalidKeys().length === 0)
const step2Valid = computed(() => step2InvalidKeys().length === 0)
const step3Valid = computed(() => step3InvalidKeys().length === 0)
const step4Valid = computed(() => step4InvalidKeys().length === 0)
const step5Valid = computed(() => step5InvalidKeys().length === 0)
const step6Valid = computed(() => step6InvalidKeys().length === 0)

// Which steps have had a failed "mark done" attempt — only then do we paint
// their unfilled required fields red. Recomputes live, so red clears as the
// user fills each field.
const attemptedSteps = ref(new Set())
const invalidFieldKeys = computed(() => {
    const set = new Set()
    attemptedSteps.value.forEach((index) => {
        stepInvalidKeys(index).forEach((key) => set.add(key))
    })
    return set
})
const isFieldInvalid = (key) => invalidFieldKeys.value.has(key)
const invalidClass = (key) => (isFieldInvalid(key) ? 'border-red-500 ring-1 ring-red-500' : '')
// Red border for an email input: flagged by a check attempt OR (live) malformed.
const emailClass = (key, value) => ((isFieldInvalid(key) || emailMalformed(value)) ? 'border-red-500 ring-1 ring-red-500' : '')
// Array form of the invalid keys for passing to the Step 3 child component.
const invalidFieldKeysArray = computed(() => Array.from(invalidFieldKeys.value))

const scrollToFirstInvalid = async () => {
    await nextTick()
    const el = document.querySelector('[data-invalid="true"]')
    if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center' })
}

onMounted(() => {
    if (props.viewMode !== 'admin') return
    pagination.perPage.value = props.filters?.per_page || props.npcStatuses?.per_page || 10
    pagination.search.value = props.filters?.search || ''
    pagination.updateData(props.npcStatuses)
    mergeNpcStatusPage(props.npcStatuses)
})

watch(() => props.npcStatuses, (newData) => {
    if (props.viewMode !== 'admin') return
    pagination.updateData(newData)
    mergeNpcStatusPage(newData)
    refreshSelectedCompanyFromData(newData)
}, { deep: true })

watch(() => props.filters?.status, (status) => {
    selectedStatus.value = status || ''
})

watch(() => props.stores, (stores) => {
    storeOptions.value = [...(stores || [])]
}, { deep: true })

// ── Modal year context ──
const effectiveModalYear = computed(() => modalYear.value ?? currentYear.value)
const isHistoricalYear = computed(() => effectiveModalYear.value !== currentYear.value)

const modalNpcStatus = computed(() => {
    const fullRecord = selectedCompany.value?.npc_status
    if (Number(fullRecord?.year) === Number(effectiveModalYear.value)) {
        return fullRecord
    }

    // The company detail endpoint returns the selected year as npc_status with
    // every tab's data. workflow_history is intentionally lightweight, so use
    // it only while switching years before that full response has arrived.
    return (selectedCompany.value?.workflow_history || [])
        .find((record) => Number(record.year) === Number(effectiveModalYear.value)) ?? null
})
// Most recent record from an earlier year — the source for renewal pre-fill.
const priorRecord = computed(() => {
    const history = selectedCompany.value?.workflow_history || []
    return history
        .filter((record) => Number(record.year) < effectiveModalYear.value)
        .sort((a, b) => Number(b.year) - Number(a.year))[0] || null
})

// Application Type is automatic: Renewal when an earlier-year record exists for
// the entity, otherwise New. (No longer a user-editable dropdown.)
const computedEntryType = computed(() => (priorRecord.value ? 'Renewal' : 'New'))

const canEditSelectedRecord = computed(() => (
    canEditNpcStatus.value
    && Boolean(modalNpcStatus.value)
    && !modalNpcStatus.value.is_finalized
))
const isModalReadOnly = computed(() => (
    Boolean(modalNpcStatus.value)
        ? !canEditSelectedRecord.value
        : !hasPermission('npc_status.create')
))
const canSaveSelectedRecord = computed(() => (
    modalNpcStatus.value ? canEditSelectedRecord.value : canEditValidity.value
))

const workflowProgress = computed(() => {
    if (!workflowForm.value.length) return 0
    const done = workflowForm.value.filter((step) => step.is_done).length
    return Math.round((done / workflowForm.value.length) * 100)
})

const receiptGrid = computed(() => modalNpcStatus.value?.store_receipts || [])

// ── Status tabs ──
const statusTabs = computed(() => {
    const counts = props.statusCounts || {}
    const group = (key) => ({
        entities: Number(counts[key]?.entities || 0),
        stores: Number(counts[key]?.stores || 0),
    })

    return [
        { label: 'All', value: '', ...group('all') },
        { label: 'Active', value: 'active', ...group('active') },
        { label: 'For Renewal', value: 'for_renewal', ...group('for_renewal') },
    ]
})

const selectStatus = (status) => {
    if (selectedStatus.value === status) return
    selectedStatus.value = status
    pagination.currentPage.value = 1
    pagination.performSearch()
}

const refreshSelectedCompanyFromData = (data = props.npcStatuses) => {
    if (!selectedCompany.value?.id) return
    const updatedCompany = (data?.data || []).find(
        (company) => String(company.id) === String(selectedCompany.value.id)
    )
    if (updatedCompany) {
        selectedCompany.value = updatedCompany
        syncWorkflowFormToValidity()
    }
}

// Apply a fresh serialized company row (returned by the modal's axios saves)
// directly to the open modal + background row. Deterministic — no reliance on
// Inertia's props watcher, which router.reload can skip.
const applyFreshCompany = (company) => {
    if (!company?.id) return
    pagination.data.value = pagination.data.value.map((row) => String(row.id) === String(company.id) ? company : row)
    accumulatedNpcStatuses.value = accumulatedNpcStatuses.value.map((row) => String(row.id) === String(company.id) ? company : row)
    if (String(selectedCompany.value?.id) === String(company.id)) {
        selectedCompany.value = company
        syncModalForms()
    }
}

// Used after an immediate server mutation (seal/document upload & delete, seal
// confirmation). These refresh only server-derived, read-only data that the
// template reads straight from modalNpcStatus (attachments, documents,
// store_receipts). It intentionally does NOT call syncModalForms(), so the
// user's unsaved Step 1–4 form edits are preserved (e.g. uploading a document
// mid-edit must not wipe the registration/profile fields being typed).
const replaceCompanyInPage = (company) => {
    if (!company?.id) return
    pagination.data.value = pagination.data.value.map((row) => String(row.id) === String(company.id) ? company : row)
    accumulatedNpcStatuses.value = accumulatedNpcStatuses.value.map((row) => String(row.id) === String(company.id) ? company : row)
    if (String(selectedCompany.value?.id) === String(company.id)) {
        selectedCompany.value = company
    }
}

const workflowStepsFrom = (steps = []) => (steps.length ? steps : props.workflowSteps || []).map((step) => ({
    key: step.key,
    label: step.label,
    is_done: Boolean(step.is_done),
    completed_at: step.completed_at || '',
    remarks: step.remarks || '',
}))

const syncWorkflowFormToValidity = () => {
    workflowForm.value = modalNpcStatus.value
        ? workflowStepsFrom(modalNpcStatus.value.workflow_steps || [])
        : []
    // Open the wizard on the first not-yet-done step (clamped to enabled).
    activeStep.value = workflowForm.value.length ? firstIncompleteStep() : 0
}

const syncModalForms = () => {
    const record = modalNpcStatus.value
    statusForm.validity_from = record?.validity_from || `${effectiveModalYear.value}-01-01`
    statusForm.validity_to = record?.validity_to || `${effectiveModalYear.value}-12-31`

    if (record) {
        applyStep1And2(record)
        applyStep3(record)
        applyStep4(record)
    } else {
        // No record yet: pre-fill from the prior year when this is a Renewal.
        applyStep1And2(computedEntryType.value === 'Renewal' ? priorRecord.value : null)
        applyStep3(null)
        applyStep4(null)
    }

    syncWorkflowFormToValidity()
    syncSelectedStores()
}

// Populate the Step 1 (account) and Step 2 (DPO profile) forms from a
// serialized record. Passing null resets them to blank defaults.
const applyStep1And2 = (source) => {
    accountForm.register_email = source?.account?.register_email || ''
    accountForm.has_password = Boolean(source?.account?.has_password)
    accountForm.register_password = ''
    showPassword.value = false
    clearPasswordFlag.value = false

    const profile = source?.dpo_profile || {}
    dpoProfileForm.first_name = profile.first_name || ''
    dpoProfileForm.middle_initial = profile.middle_initial || ''
    dpoProfileForm.last_name = profile.last_name || ''
    dpoProfileForm.sex = profile.sex || ''
    dpoProfileForm.designation = profile.designation || ''
    dpoProfileForm.date_designated_dpo = profile.date_designated_dpo || ''
    dpoProfileForm.official_dpo_email = profile.official_dpo_email || ''
    dpoProfileForm.mobile_no = profile.mobile_no || ''
    dpoProfileForm.telephone_no = profile.telephone_no || ''
    dpoProfileForm.role = profile.role || 'PIC/PIP'

    const codes = source?.backup_codes || []
    const count = Math.max(BACKUP_CODE_COUNT, codes.length)
    backupCodes.value = Array.from({ length: count }, (_, idx) => codes[idx] || '')
}

const togglePassword = async () => {
    if (showPassword.value) {
        showPassword.value = false
        return
    }
    // Reveal the saved password only when the field is empty (i.e. the user is
    // not typing a replacement) and a stored password exists.
    if (!accountForm.register_password && accountForm.has_password && modalNpcStatus.value && !clearPasswordFlag.value) {
        try {
            const { data } = await axios.get(route('npc-statuses.register-password.reveal', modalNpcStatus.value.id), {
                headers: { Accept: 'application/json' },
            })
            accountForm.register_password = data.register_password || ''
        } catch (error) {
            showError(axiosErrorText(error))
            return
        }
    }
    showPassword.value = true
}

const clearSavedPassword = () => {
    clearPasswordFlag.value = true
    accountForm.register_password = ''
    showPassword.value = false
}

const syncSelectedStores = () => {
    const currentRecordId = modalNpcStatus.value?.id
    if (!currentRecordId) {
        selectedStoreIds.value = []
        return
    }

    const receiptStoreIds = (modalNpcStatus.value?.store_receipts || []).map((row) => row.store_id)
    selectedStoreIds.value = receiptStoreIds.length
        ? receiptStoreIds
        : storeOptions.value
            .filter((store) => String(store.assigned_npc_status_id) === String(currentRecordId))
            .map((store) => store.id)
}

const scrollToWorkflow = async () => {
    await nextTick()
    workflowSection.value?.scrollIntoView({ behavior: 'smooth', block: 'start' })
}

const loadSelectedCompany = async ({ scrollIfFound = false } = {}) => {
    const companyId = selectedCompany.value?.id
    if (!companyId) return

    const request = ++companyLoadRequest
    isLoadingCompany.value = true
    modalLoadError.value = ''

    try {
        const { data } = await axios.get(route('npc-statuses.companies.show', companyId), {
            params: { year: effectiveModalYear.value },
            headers: { Accept: 'application/json' },
        })

        if (request !== companyLoadRequest || String(selectedCompany.value?.id) !== String(companyId)) return

        storeOptions.value = [...(data.stores || [])]
        applyFreshCompany(data.company)
        syncModalForms()

        if (scrollIfFound && data.company?.npc_status) {
            await scrollToWorkflow()
        }
    } catch (error) {
        if (request !== companyLoadRequest || String(selectedCompany.value?.id) !== String(companyId)) return
        modalLoadError.value = axiosErrorText(error)
    } finally {
        if (request === companyLoadRequest) {
            isLoadingCompany.value = false
        }
    }
}

const openStatusModal = (company) => {
    const shouldScrollIfFound = !company.npc_status
    selectedCompany.value = company
    statusForm.company_id = company.id
    attemptedSteps.value = new Set()
    syncModalForms()
    storeSearch.value = ''
    storeAssignmentTab.value = 'all'
    modalYear.value = null
    fileInputKey.value++
    showModal.value = true
    loadSelectedCompany({ scrollIfFound: shouldScrollIfFound })
}

const closeModal = () => {
    companyLoadRequest++
    showModal.value = false
    selectedCompany.value = null
    statusForm.company_id = null
    statusForm.validity_from = ''
    statusForm.validity_to = ''
    workflowForm.value = []
    activeStep.value = 0
    applyStep1And2(null)
    applyStep3(null)
    applyStep4(null)
    attemptedSteps.value = new Set()
    selectedStoreIds.value = []
    storeSearch.value = ''
    storeAssignmentTab.value = 'all'
    isLoadingCompany.value = false
    modalLoadError.value = ''
    modalYear.value = null
    fileInputKey.value++
}

const switchModalYear = (year) => {
    modalYear.value = (year === currentYear.value) ? null : year
    attemptedSteps.value = new Set()
    syncModalForms()
    loadSelectedCompany()
}

const clampYear = (year) => Math.min(2100, Math.max(2000, year))

// Numeric year stepper: typing (on change/enter) or the up/down arrows pick the
// validity year to view. An existing record loads its status; a year with no
// record opens the create fields.
const onYearInput = (event) => {
    const raw = Number.parseInt(event.target.value, 10)
    if (!Number.isFinite(raw)) {
        event.target.value = effectiveModalYear.value
        return
    }
    const year = clampYear(raw)
    event.target.value = year
    if (year !== effectiveModalYear.value) switchModalYear(year)
}

const stepModalYear = (delta) => {
    const year = clampYear(effectiveModalYear.value + delta)
    if (year !== effectiveModalYear.value) switchModalYear(year)
}

// ── Workflow step gating ──
const stepEnabled = (index) => {
    if (index === 0) return true
    return workflowForm.value.slice(0, index).every((step) => step.is_done)
}

const onStepToggle = (index) => {
    if (!canEditSelectedRecord.value) return
    const step = workflowForm.value[index]
    if (step.is_done) {
        // Required fields for this step must be complete before it can be done.
        if (!stepFieldsSatisfied(index)) {
            step.is_done = false
            attemptedSteps.value = new Set(attemptedSteps.value).add(index)
            showError('Please complete all required (*) fields for this step before marking it done.')
            scrollToFirstInvalid()
            return
        }
        if (!step.completed_at) step.completed_at = todayString()
    } else {
        // Cascade: unchecking a step invalidates all later steps.
        for (let j = index; j < workflowForm.value.length; j++) {
            workflowForm.value[j].is_done = false
            workflowForm.value[j].completed_at = ''
        }
    }
}

const applySavedStoreAssignments = (company, savedStoreIds) => {
    const currentRecordId = company?.npc_status?.id
    const selectedIds = new Set(savedStoreIds.map((storeId) => String(storeId)))

    storeOptions.value = storeOptions.value.map((store) => {
        if (selectedIds.has(String(store.id))) {
            return {
                ...store,
                assigned_npc_status_id: currentRecordId,
                assigned_company_id: company.id,
                assigned_company_name: company.name,
            }
        }

        if (String(store.assigned_npc_status_id) === String(currentRecordId)) {
            return {
                ...store,
                assigned_npc_status_id: null,
                assigned_company_id: null,
                assigned_company_name: null,
            }
        }

        return store
    })
}

const saveModalChanges = async () => {
    if (!selectedCompany.value || !canSaveSelectedRecord.value || isSavingStatus.value) return

    isSavingStatus.value = true
    const existingRecord = modalNpcStatus.value
    const workflowSteps = workflowForm.value.map((step) => ({ ...step }))
    const storeIds = [...selectedStoreIds.value]
    // Snapshot Step 1 & 2 forms before any applyFreshCompany() re-sync so the
    // user's typed values survive the multi-request save sequence.
    const accountSnapshot = {
        register_email: accountForm.register_email || null,
        register_password: accountForm.register_password || null,
        clear_password: clearPasswordFlag.value,
    }
    const dpoProfileSnapshot = {
        ...dpoProfileForm,
        role: dpoProfileForm.role || 'PIC/PIP',
        backup_codes: [...backupCodes.value],
    }
    const registrationSnapshot = JSON.parse(JSON.stringify(registrationForm.value))
    const approvalSnapshot = {
        approval_status: approvalForm.approval_status,
        payment: {
            year: approvalForm.year || null,
            reference_no: approvalForm.reference_no || null,
            transaction_no: approvalForm.transaction_no || null,
            date_of_payment: approvalForm.date_of_payment || null,
            transaction_type: approvalForm.transaction_type || null,
            amount: approvalForm.amount === '' ? null : approvalForm.amount,
        },
    }

    try {
        let response = existingRecord
            ? await axios.put(route('npc-statuses.update', existingRecord.id), {
                validity_from: statusForm.validity_from,
                validity_to: statusForm.validity_to,
            }, { headers: { Accept: 'application/json' } })
            : await axios.post(route('npc-statuses.store'), {
                company_id: statusForm.company_id,
                validity_from: statusForm.validity_from,
                validity_to: statusForm.validity_to,
            }, { headers: { Accept: 'application/json' } })
        let company = response.data.company
        replaceCompanyInPage(company)

        // Dependent sections are only submitted when they were loaded for an
        // existing record. This avoids wiping data if a stale modal discovers
        // an existing renewal during the validity request.
        if (existingRecord && hasPermission('npc_status.edit')) {
            response = await axios.put(route('npc-statuses.account.update', existingRecord.id), accountSnapshot, { headers: { Accept: 'application/json' } })
            company = response.data.company
            replaceCompanyInPage(company)

            response = await axios.put(route('npc-statuses.dpo-profile.update', existingRecord.id), dpoProfileSnapshot, { headers: { Accept: 'application/json' } })
            company = response.data.company
            replaceCompanyInPage(company)

            response = await axios.put(route('npc-statuses.registration.update', existingRecord.id), { details: registrationSnapshot }, { headers: { Accept: 'application/json' } })
            company = response.data.company
            replaceCompanyInPage(company)

            response = await axios.put(route('npc-statuses.approval.update', existingRecord.id), approvalSnapshot, { headers: { Accept: 'application/json' } })
            company = response.data.company
            replaceCompanyInPage(company)

            response = await axios.put(route('npc-statuses.stores.update', existingRecord.id), {
                store_ids: storeIds,
            }, { headers: { Accept: 'application/json' } })
            company = response.data.company
            replaceCompanyInPage(company)

            response = await axios.put(route('npc-statuses.workflow.update', existingRecord.id), {
                steps: workflowSteps,
            }, { headers: { Accept: 'application/json' } })
            company = response.data.company
            replaceCompanyInPage(company)
        }

        showSuccess('NPC renewal changes saved successfully')
        await loadSelectedCompany()

        if (!existingRecord) {
            await scrollToWorkflow()
        }
    } catch (error) {
        showError(axiosErrorText(error))
    } finally {
        isSavingStatus.value = false
    }
}

const currentSeal = (type) => modalNpcStatus.value?.attachments?.[type]?.[0] || null
const exactCctvSealForStore = (storeId) => (modalNpcStatus.value?.attachments?.cctv_seal || [])
    .find((attachment) => String(attachment.store_id || '') === String(storeId)) || null
const cctvSealForStore = (storeId) => exactCctvSealForStore(storeId)

const uploadSeal = async (type, event, storeId = null) => {
    if (!canEditSelectedRecord.value) return
    const file = event.target.files?.[0]
    if (!file) return
    const record = modalNpcStatus.value
    if (!record) {
        showError('Save the renewal before uploading seals.')
        return
    }
    try {
        if (type === 'cctv_seal' && storeId) {
            const assignmentResponse = await axios.put(route('npc-statuses.stores.update', record.id), {
                store_ids: [...selectedStoreIds.value],
            }, { headers: { Accept: 'application/json' } })
            replaceCompanyInPage(assignmentResponse.data.company)
        }

        const formData = new FormData()
        formData.append('type', type)
        formData.append('validity_from', statusForm.validity_from || `${currentYear.value}-01-01`)
        formData.append('file', file)
        if (storeId) formData.append('store_id', storeId)
        const { data } = await axios.post(route('npc-statuses.attachments.store', record.id), formData, {
            headers: { Accept: 'application/json' },
        })
        fileInputKey.value++
        replaceCompanyInPage(data.company)
        showSuccess(data.message || 'Seal uploaded successfully')
    } catch (error) {
        showError(axiosErrorText(error))
    }
}

const deleteSeal = async (attachment) => {
    if (!canEditSelectedRecord.value || !attachment) return
    const ok = await confirm({
        title: 'Remove Seal',
        message: `Remove ${attachment.name || 'this seal'}? Stores will no longer be able to download it.`,
    })
    if (!ok) return
    try {
        const { data } = await axios.delete(route('npc-status-attachments.destroy', attachment.id), {
            headers: { Accept: 'application/json' },
        })
        replaceCompanyInPage(data.company)
        showSuccess(data.message || 'Seal removed successfully')
    } catch (error) {
        showError(axiosErrorText(error))
    }
}

// ── Step 3 document uploads ──
const uploadDocument = async ({ type, file }) => {
    if (!canEditSelectedRecord.value || !file) return
    const record = modalNpcStatus.value
    if (!record) {
        showError('Save the renewal before uploading documents.')
        return
    }
    try {
        const formData = new FormData()
        formData.append('doc_type', type)
        formData.append('file', file)
        const { data } = await axios.post(route('npc-statuses.documents.store', record.id), formData, {
            headers: { Accept: 'application/json' },
        })
        replaceCompanyInPage(data.company)
        showSuccess(data.message || 'Document uploaded successfully')
    } catch (error) {
        showError(axiosErrorText(error))
    }
}

const receiptInputKey = ref(0)
const onReceiptFile = (event) => {
    const file = event.target.files?.[0]
    if (!file) return
    uploadDocument({ type: 'payment_receipt', file })
    receiptInputKey.value++
}

const deleteDocument = async ({ id }) => {
    if (!canEditSelectedRecord.value || !id) return
    const ok = await confirm({
        title: 'Remove Document',
        message: 'Remove this supporting document?',
    })
    if (!ok) return
    try {
        const { data } = await axios.delete(route('npc-documents.destroy', id), {
            headers: { Accept: 'application/json' },
        })
        replaceCompanyInPage(data.company)
        showSuccess(data.message || 'Document removed successfully')
    } catch (error) {
        showError(axiosErrorText(error))
    }
}

// A seal can only be *checked* once the store has downloaded it and uploaded
// proof. An already-checked seal can always be unchecked.
const sealCheckBlockReason = (row, type) => {
    const seal = row.seals?.[type]
    if (seal?.confirmed_at) return ''
    if (!seal?.downloaded_at) return 'Store must download this seal first.'
    if (!seal?.proof) return 'Store must upload proof of use for this seal first.'
    return ''
}
const canToggleSeal = (row, type) => {
    const seal = row.seals?.[type]
    return Boolean(seal?.confirmed_at) || (Boolean(seal?.proof) && Boolean(seal?.downloaded_at))
}

const toggleConfirm = async (row, type) => {
    if (!canEditSelectedRecord.value) return
    const record = modalNpcStatus.value
    if (!record) return
    const confirmed = !row.seals[type]?.confirmed_at
    if (confirmed && !canToggleSeal(row, type)) {
        showError(sealCheckBlockReason(row, type) || 'This seal cannot be confirmed yet.')
        return
    }
    try {
        const { data } = await axios.post(route('npc-statuses.stores.seal.confirm', [record.id, row.store_id, type]), {
            confirmed,
        }, { headers: { Accept: 'application/json' } })
        replaceCompanyInPage(data.company)
        showSuccess(data.message || 'Updated')
    } catch (error) {
        showError(axiosErrorText(error))
    }
}

const deleteRecord = async (company) => {
    if (!company?.npc_status) return
    const ok = await confirm({
        title: 'Delete NPC Renewal',
        message: `Delete NPC renewal for ${company.name} in ${currentYear.value}? Store tags, seals, and download records will be removed.`,
    })
    if (!ok) return
    router.delete(route('npc-statuses.destroy', company.npc_status.id), {
        preserveScroll: true,
        onSuccess: () => {
            closeModal()
            showSuccess('NPC renewal deleted successfully')
        },
        onError: (errors) => showError(errorText(errors)),
    })
}

// ── Store assignment helpers ──
const isStoreSelected = (store) => {
    return selectedStoreIds.value.some((storeId) => String(storeId) === String(store.id))
}

const selectedStoreOptions = computed(() => storeOptions.value
    .filter(isStoreSelected)
    .sort((a, b) => String(a.name || '').localeCompare(String(b.name || ''))))

const isStoreAssignedElsewhere = (store) => {
    const assignedRecordId = store.assigned_npc_status_id
    const currentRecordId = modalNpcStatus.value?.id
    return Boolean(assignedRecordId)
        && String(assignedRecordId) !== String(currentRecordId)
}

const filteredStores = computed(() => {
    const search = storeSearch.value.trim().toLowerCase()
    const stores = storeOptions.value
    return stores.filter((store) => {
        const isSelected = isStoreSelected(store)
        const isAssignedElsewhere = isStoreAssignedElsewhere(store)

        if (storeAssignmentTab.value === 'all' && isAssignedElsewhere) return false
        if (storeAssignmentTab.value === 'assigned' && !isSelected) return false
        if (storeAssignmentTab.value === 'assigned_elsewhere' && !isAssignedElsewhere) return false
        if (!search) return true
        return [store.name, store.code, store.area, store.brand, store.assigned_company_name]
            .filter(Boolean)
            .some((value) => String(value).toLowerCase().includes(search))
    })
})

const storeAssignmentTabs = computed(() => {
    const stores = storeOptions.value
    const assignedCount = stores.filter(isStoreSelected).length
    const assignedElsewhereCount = stores.filter(isStoreAssignedElsewhere).length
    return [
        { label: 'All', value: 'all', count: stores.length - assignedElsewhereCount },
        { label: 'Checked', value: 'assigned', count: assignedCount },
        { label: 'Assigned Elsewhere', value: 'assigned_elsewhere', count: assignedElsewhereCount },
    ]
})

const isStoreDisabled = (store) => {
    return isStoreAssignedElsewhere(store)
}

// ── Row summary helpers ──
const sealsUploaded = (company) => Object.values(company.npc_status?.seals || {}).filter((s) => s.available).length

const storesConfirmed = (company) => {
    const rows = company.npc_status?.store_receipts || []
    return rows.filter((r) => sealTypes.every((t) => r.seals?.[t.type]?.confirmed_at)).length
}

// ── Store-user view ──
const onStoreDownload = () => {
    // The download response only completes after the receipt is committed.
    router.reload({ only: ['storeSeals'] })
}

const onStoreDownloadError = (error) => {
    showError(axiosErrorText(error))
}

// ── Renewal preview ──
const previewRenewalStatus = computed(() => {
    const days = previewRenewalDays.value
    if (days === null) return 'No Record'
    if (days < 0) return 'Overdue'
    if (days === 0) return 'Due Today'
    if (days <= 30) return 'Critical Renewal'
    if (days <= 90) return 'Renewal Window'
    return 'Active'
})

const previewRenewalDays = computed(() => {
    const validityTo = parseDateOnly(statusForm.validity_to)
    if (!validityTo) return null
    return dayDifference(todayDateOnly(), validityTo)
})

const previewRenewalDaysLabel = computed(() => renewalDaysLabel(previewRenewalDays.value))

// ── Date utils ──
const dateOnly = (value) => (value ? String(value).slice(0, 10) : null)

const formatDate = (value) => {
    if (!value) return 'Not set'
    return new Date(`${value}T00:00:00`).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: '2-digit' })
}

const formatDateTime = (value) => {
    if (!value) return 'Not set'
    const date = new Date(value)
    if (Number.isNaN(date.getTime())) return String(value)

    return date.toLocaleString('en-US', {
        year: 'numeric',
        month: 'short',
        day: '2-digit',
        hour: 'numeric',
        minute: '2-digit',
    })
}

const parseDateOnly = (value) => {
    if (!value) return null
    const [year, month, day] = String(value).split('-').map(Number)
    if (!year || !month || !day) return null
    return new Date(year, month - 1, day)
}

const todayDateOnly = () => {
    const now = new Date()
    return new Date(now.getFullYear(), now.getMonth(), now.getDate())
}

const todayString = () => {
    const now = todayDateOnly()
    const month = String(now.getMonth() + 1).padStart(2, '0')
    const day = String(now.getDate()).padStart(2, '0')
    return `${now.getFullYear()}-${month}-${day}`
}

const dayDifference = (fromDate, toDate) => Math.round((toDate.getTime() - fromDate.getTime()) / (24 * 60 * 60 * 1000))

const renewalDaysLabel = (days) => {
    if (days === null || days === undefined) return 'No validity date'
    if (days < 0) return `Overdue by ${Math.abs(days)} day${Math.abs(days) === 1 ? '' : 's'}`
    if (days === 0) return 'Due today'
    return `${days} day${days === 1 ? '' : 's'} before expiry`
}

const statusBadgeClass = (status) => {
    return {
        'No Record': 'bg-gray-100 text-gray-600',
        Active: 'bg-green-100 text-green-800',
        'Renewal Window': 'bg-blue-100 text-blue-800',
        'Critical Renewal': 'bg-orange-100 text-orange-800',
        'Due Today': 'bg-amber-100 text-amber-800',
        Overdue: 'bg-red-100 text-red-800',
    }[status] || 'bg-gray-100 text-gray-700'
}

const errorText = (errors) => Object.values(errors || {}).flat().join(', ') || 'Unable to save changes.'

const axiosErrorText = (error) => {
    const data = error?.response?.data
    if (data?.errors) return errorText(data.errors)
    if (data?.message) return data.message
    return 'Unable to save changes.'
}
</script>
