<template>
    <AppLayout title="NPC Status" content-class="w-full max-w-none px-2 sm:px-4 lg:px-6">

        <!-- ══════════════════ STORE USER VIEW ══════════════════ -->
        <div v-if="viewMode === 'store'" class="py-8">
            <AssignedStoreSeals
                :store-seals="storeSeals"
                @downloaded="onStoreDownload"
                @download-error="onStoreDownloadError"
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
        <div v-if="showModal" class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex min-h-screen items-center justify-center px-4 py-6">
                <div class="fixed inset-0 bg-black/20 backdrop-blur-md" @click="closeModal"></div>
                <div class="relative flex max-h-[90vh] w-full max-w-4xl flex-col rounded-xl border border-gray-100 bg-white shadow-2xl dark:bg-gray-800 dark:border-gray-700">

                    <!-- Header -->
                    <div class="border-b p-5 dark:border-gray-700">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h3 class="text-xl font-bold text-gray-900 dark:text-gray-100">{{ selectedCompany?.name }}</h3>
                                <p class="mt-0.5 text-xs font-black uppercase tracking-widest text-gray-400 dark:text-gray-400">{{ selectedCompany?.code }} — NPC Renewal · {{ effectiveModalYear }}</p>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <button
                                    v-for="year in entityAvailableYears"
                                    :key="year"
                                    type="button"
                                    @click="switchModalYear(year)"
                                    :class="[
                                        'rounded-full px-3 py-1 text-xs font-black transition-colors',
                                        year === effectiveModalYear
                                            ? 'bg-blue-600 text-white'
                                            : hasYearRecord(year)
                                                ? 'bg-gray-100 text-gray-700 hover:bg-gray-200'
                                                : 'border border-dashed border-gray-300 text-gray-400 hover:border-gray-400 hover:text-gray-600'
                                    ]"
                                >
                                    {{ year }}<span v-if="hasYearRecord(year)" class="ml-1 opacity-60">✓</span>
                                </button>
                                <span v-if="isHistoricalYear || !canEditNpcStatus" class="rounded-full bg-amber-100 px-2.5 py-1 text-[10px] font-black text-amber-700">Read-only</span>
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

                            <!-- Historical: read-only -->
                            <div v-if="isHistoricalYear" class="rounded-lg border border-gray-200 bg-gray-50 p-5 dark:bg-gray-900/50 dark:border-gray-700">
                                <div v-if="modalNpcStatus" class="grid grid-cols-2 gap-6">
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

                            <!-- Current year: editable -->
                            <div v-else class="space-y-4">
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

                            <!-- Historical read-only -->
                            <div v-if="isHistoricalYear" class="space-y-2">
                                <div v-for="step in historicalWorkflowSteps" :key="step.key" class="flex items-start gap-3 rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                                    <span class="mt-0.5 flex h-5 w-5 flex-shrink-0 items-center justify-center rounded-full" :class="step.is_done ? 'bg-green-100 text-green-600' : 'bg-gray-100 text-gray-400'">
                                        <svg v-if="step.is_done" class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                                    </span>
                                    <div class="min-w-0 flex-1">
                                        <div class="text-sm font-bold" :class="step.is_done ? 'text-gray-900 dark:text-gray-100' : 'text-gray-500'">{{ step.label }}</div>
                                        <div v-if="step.completed_at" class="text-xs text-gray-500">Completed: {{ formatDate(step.completed_at) }}</div>
                                        <div v-if="step.remarks" class="mt-1 text-xs italic text-gray-600 dark:text-gray-300">{{ step.remarks }}</div>
                                    </div>
                                </div>
                                <p v-if="!historicalWorkflowSteps.length" class="rounded-lg border border-dashed border-gray-200 bg-gray-50 p-6 text-center text-sm font-semibold text-gray-500 dark:bg-gray-900/50 dark:border-gray-700">No workflow record for {{ effectiveModalYear }}.</p>
                            </div>

                            <!-- Current year -->
                            <div v-else>
                                <div v-if="isLoadingCompany" class="rounded-lg border border-dashed border-blue-200 bg-blue-50 p-6 text-center text-sm font-semibold text-blue-700 dark:border-blue-900/40 dark:bg-blue-900/20 dark:text-blue-300">
                                    Checking for an existing application workflow...
                                </div>
                                <div v-else-if="!modalNpcStatus" class="rounded-lg border border-dashed border-gray-200 bg-gray-50 p-6 text-center text-sm font-semibold text-gray-500 dark:bg-gray-900/50 dark:text-gray-300 dark:border-gray-700">
                                    Save the validity dates above first to start the workflow.
                                </div>
                                <div v-else class="space-y-2">
                                    <div v-for="(step, i) in workflowForm" :key="step.key" class="rounded-lg border p-3" :class="stepEnabled(i) ? 'border-gray-200 dark:border-gray-700' : 'border-gray-100 bg-gray-50 opacity-60 dark:border-gray-800 dark:bg-gray-900/40'">
                                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start">
                                            <label class="flex min-w-[210px] items-center gap-2 text-sm font-bold text-gray-800 dark:text-gray-200">
                                                <input
                                                    v-model="step.is_done"
                                                    type="checkbox"
                                                    :disabled="!canEditNpcStatus || !stepEnabled(i)"
                                                    class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 disabled:cursor-not-allowed dark:border-gray-600"
                                                    @change="onStepToggle(i)"
                                                >
                                                <span class="flex h-5 w-5 items-center justify-center rounded-full bg-gray-100 text-[10px] font-black text-gray-500 dark:bg-gray-700 dark:text-gray-300">{{ i + 1 }}</span>
                                                {{ step.label }}
                                            </label>
                                            <div class="grid flex-1 grid-cols-1 gap-3 md:grid-cols-[170px_minmax(220px,1fr)]">
                                                <input v-model="step.completed_at" :disabled="!canEditNpcStatus || !step.is_done" type="date" class="rounded-lg border-gray-300 text-sm disabled:cursor-not-allowed disabled:bg-gray-100 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 dark:disabled:bg-gray-800">
                                                <textarea v-model="step.remarks" :disabled="!canEditNpcStatus" rows="1" class="rounded-lg border-gray-300 text-sm disabled:cursor-not-allowed disabled:bg-gray-100 focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 dark:disabled:bg-gray-800" placeholder="Remarks"></textarea>
                                            </div>
                                        </div>

                                        <!-- Step 6 expansion (server-confirmed) -->
                                        <div v-if="i === 5 && storeReceivingDone" class="mt-4 space-y-5 rounded-lg border border-blue-100 bg-blue-50/40 p-4 dark:border-blue-900/40 dark:bg-blue-900/10">

                                            <!-- Seals to release -->
                                            <div>
                                                <div class="mb-2 text-[11px] font-black uppercase tracking-widest text-blue-700 dark:text-blue-300">Seals to Release (entity-wide)</div>
                                                <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                                    <div v-for="sealType in sealTypes" :key="sealType.type" class="rounded-lg border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-800">
                                                        <div class="mb-2 text-xs font-black uppercase tracking-wider text-gray-600 dark:text-gray-300">{{ sealType.label }}</div>
                                                        <template v-if="currentSeal(sealType.type)">
                                                            <a v-if="canEditNpcStatus" :href="currentSeal(sealType.type).url" class="block truncate text-xs font-bold text-blue-600 hover:underline">{{ currentSeal(sealType.type).name || 'Download' }}</a>
                                                            <span v-else class="block truncate text-xs font-semibold text-gray-500">{{ currentSeal(sealType.type).name || 'Uploaded' }}</span>
                                                            <button v-if="canEditNpcStatus" type="button" @click="deleteSeal(currentSeal(sealType.type))" class="mt-1 text-[11px] font-black text-red-600 hover:text-red-800">Remove</button>
                                                        </template>
                                                        <template v-else-if="canEditNpcStatus">
                                                            <input :key="fileInputKey + '-' + sealType.type" type="file" accept=".pdf,.jpg,.jpeg,.png,.webp" class="w-full text-xs text-gray-500 file:mr-2 file:rounded-full file:border-0 file:bg-blue-50 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-blue-700 dark:text-gray-300" @change="uploadSeal(sealType.type, $event)">
                                                        </template>
                                                        <span v-else class="text-xs font-semibold text-gray-400">Not uploaded</span>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Assigned stores -->
                                            <div>
                                                <div class="mb-2 text-[11px] font-black uppercase tracking-widest text-blue-700 dark:text-blue-300">Assigned Stores</div>
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
                                                        <input v-model="selectedStoreIds" :value="store.id" :disabled="!canEditNpcStatus || isStoreDisabled(store)" type="checkbox" class="mt-0.5 rounded border-gray-300 text-blue-600 focus:ring-blue-500 disabled:cursor-not-allowed dark:border-gray-600">
                                                        <span class="min-w-0">
                                                            <span class="block truncate font-bold text-gray-900 dark:text-gray-100">{{ store.name }}</span>
                                                            <span class="block truncate text-gray-500 dark:text-gray-300">{{ store.code }} — {{ store.area }} — {{ store.brand }}</span>
                                                            <span v-if="isStoreDisabled(store)" class="block font-bold text-amber-700">Assigned to {{ store.assigned_company_name }}</span>
                                                        </span>
                                                    </label>
                                                </div>
                                            </div>

                                            <!-- Download & confirmation grid -->
                                            <div v-if="receiptGrid.length">
                                                <div class="mb-2 text-[11px] font-black uppercase tracking-widest text-blue-700 dark:text-blue-300">Store Downloads &amp; Confirmation</div>
                                                <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
                                                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                                        <thead class="bg-gray-50 dark:bg-gray-900/50">
                                                            <tr>
                                                                <th class="px-3 py-2 text-left text-[10px] font-black uppercase tracking-wider text-gray-500">Store</th>
                                                                <th v-for="sealType in sealTypes" :key="sealType.type" class="px-3 py-2 text-left text-[10px] font-black uppercase tracking-wider text-gray-500">{{ sealType.label }}</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                                            <tr v-for="row in receiptGrid" :key="row.store_id">
                                                                <td class="px-3 py-2">
                                                                    <div class="text-xs font-bold text-gray-900 dark:text-gray-100">{{ row.store_name }}</div>
                                                                    <div class="font-mono text-[10px] text-gray-500">{{ row.store_code }}</div>
                                                                </td>
                                                                <td v-for="sealType in sealTypes" :key="sealType.type" class="px-3 py-2">
                                                                    <div class="text-[10px] font-semibold" :class="row.seals[sealType.type]?.downloaded_at ? 'text-gray-600 dark:text-gray-300' : 'text-gray-400'">
                                                                        {{ row.seals[sealType.type]?.downloaded_at ? 'Downloaded ' + formatDateTime(row.seals[sealType.type].downloaded_at) : 'Not downloaded' }}
                                                                    </div>
                                                                    <button
                                                                        v-if="canEditNpcStatus"
                                                                        type="button"
                                                                        @click="toggleConfirm(row, sealType.type)"
                                                                        :class="[
                                                                            'mt-1 rounded-full px-2.5 py-1 text-[10px] font-black',
                                                                            row.seals[sealType.type]?.confirmed_at
                                                                                ? 'bg-green-100 text-green-800 hover:bg-green-200'
                                                                                : 'bg-gray-100 text-gray-600 hover:bg-gray-200'
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
                                            <p v-else class="text-xs font-semibold text-gray-500 dark:text-gray-300">Assign and save stores above to track their seal downloads.</p>
                                        </div>
                                    </div>

                                </div>
                            </div>
                        </section>
                    </div>

                    <!-- Footer -->
                    <div class="flex items-center justify-between border-t bg-white p-4 dark:bg-gray-800 dark:border-gray-700">
                        <button
                            v-if="!isHistoricalYear && modalNpcStatus && hasPermission('npc_status.delete')"
                            type="button"
                            @click="deleteRecord(selectedCompany)"
                            class="rounded-lg border border-red-200 bg-white px-4 py-2 text-sm font-semibold text-red-600 hover:bg-red-50 dark:bg-gray-800 dark:border-red-900/50 dark:hover:bg-red-900/20"
                        >Delete Renewal</button>
                        <div v-else></div>
                        <div class="flex items-center gap-2">
                            <button
                                v-if="!isHistoricalYear && !isLoadingCompany && !modalLoadError && canSaveRecord(selectedCompany)"
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
    selectedCompany.value?.npc_status
        ? canEditNpcStatus.value
        : hasPermission('npc_status.create')
))

// Form state
const storeSearch = ref('')
const storeAssignmentTab = ref('all')
const selectedStoreIds = ref([])
const storeOptions = ref([...(props.stores || [])])
const workflowForm = ref([])

const statusForm = reactive({
    company_id: null,
    validity_from: '',
    validity_to: '',
})

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
    if (!isHistoricalYear.value) return selectedCompany.value?.npc_status ?? null
    return (selectedCompany.value?.workflow_history || []).find((r) => r.year === effectiveModalYear.value) ?? null
})

const entityAvailableYears = computed(() => {
    if (!selectedCompany.value) return []
    const years = new Set([currentYear.value])
    ;(selectedCompany.value.workflow_history || []).forEach((r) => years.add(r.year))
    ;(selectedCompany.value.attachment_history || []).forEach((r) => years.add(r.year))
    return [...years].sort((a, b) => b - a)
})

const historicalWorkflowSteps = computed(() => {
    if (!isHistoricalYear.value) return []
    const record = (selectedCompany.value?.workflow_history || []).find((r) => r.year === effectiveModalYear.value)
    return record ? workflowStepsFrom(record.workflow_steps || []) : []
})

const workflowProgress = computed(() => {
    if (!workflowForm.value.length) return 0
    const done = workflowForm.value.filter((step) => step.is_done).length
    return Math.round((done / workflowForm.value.length) * 100)
})

const storeReceivingDone = computed(() => {
    const step = (modalNpcStatus.value?.workflow_steps || []).find((s) => s.key === 'store_distribution')
    return !!step?.is_done
})

const receiptGrid = computed(() => selectedCompany.value?.npc_status?.store_receipts || [])

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
        if (!isHistoricalYear.value) {
            statusForm.validity_from = company.npc_status?.validity_from || statusForm.validity_from
            statusForm.validity_to = company.npc_status?.validity_to || statusForm.validity_to
        }
        syncWorkflowFormToValidity()
    }
}

const replaceCompanyInPage = (company) => {
    if (!company?.id) return
    pagination.data.value = pagination.data.value.map((row) => String(row.id) === String(company.id) ? company : row)
    accumulatedNpcStatuses.value = accumulatedNpcStatuses.value.map((row) => String(row.id) === String(company.id) ? company : row)
    if (String(selectedCompany.value?.id) === String(company.id)) {
        selectedCompany.value = company
        syncWorkflowFormToValidity()
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
    workflowForm.value = selectedCompany.value?.npc_status
        ? workflowStepsFrom(selectedCompany.value.npc_status.workflow_steps || [])
        : []
}

const canSaveRecord = (company) => {
    return company?.npc_status ? hasPermission('npc_status.edit') : hasPermission('npc_status.create')
}

const hasYearRecord = (year) => {
    if (year === currentYear.value) return !!selectedCompany.value?.npc_status
    return (selectedCompany.value?.workflow_history || []).some((r) => r.year === year)
        || (selectedCompany.value?.attachment_history || []).some((r) => r.year === year)
}

const syncSelectedStores = () => {
    const currentRecordId = selectedCompany.value?.npc_status?.id
    if (!currentRecordId) {
        selectedStoreIds.value = []
        return
    }

    selectedStoreIds.value = storeOptions.value
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
            headers: { Accept: 'application/json' },
        })

        if (request !== companyLoadRequest || String(selectedCompany.value?.id) !== String(companyId)) return

        applyFreshCompany(data.company)
        syncSelectedStores()

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
    statusForm.validity_from = company.npc_status?.validity_from || `${currentYear.value}-01-01`
    statusForm.validity_to = company.npc_status?.validity_to || `${currentYear.value}-12-31`
    syncWorkflowFormToValidity()
    syncSelectedStores()
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
    if (!isHistoricalYear.value) {
        statusForm.validity_from = selectedCompany.value?.npc_status?.validity_from || `${currentYear.value}-01-01`
        statusForm.validity_to = selectedCompany.value?.npc_status?.validity_to || `${currentYear.value}-12-31`
        syncSelectedStores()
        syncWorkflowFormToValidity()
    }
}

// ── Workflow step gating ──
const stepEnabled = (index) => {
    if (index === 0) return true
    return workflowForm.value.slice(0, index).every((step) => step.is_done)
}

const onStepToggle = (index) => {
    if (!canEditNpcStatus.value) return
    const step = workflowForm.value[index]
    if (step.is_done) {
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
    if (!selectedCompany.value || !canSaveRecord(selectedCompany.value) || isSavingStatus.value) return

    isSavingStatus.value = true
    const existingRecord = selectedCompany.value.npc_status
    const workflowSteps = workflowForm.value.map((step) => ({ ...step }))
    const storeIds = [...selectedStoreIds.value]

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
        applyFreshCompany(company)

        // Dependent sections are only submitted when they were loaded for an
        // existing record. This avoids wiping data if a stale modal discovers
        // an existing renewal during the validity request.
        if (existingRecord && hasPermission('npc_status.edit')) {
            response = await axios.put(route('npc-statuses.workflow.update', company.npc_status.id), {
                steps: workflowSteps,
            }, { headers: { Accept: 'application/json' } })
            company = response.data.company
            applyFreshCompany(company)

            response = await axios.put(route('npc-statuses.stores.update', company.npc_status.id), {
                store_ids: storeIds,
            }, { headers: { Accept: 'application/json' } })
            company = response.data.company
            applyFreshCompany(company)
            applySavedStoreAssignments(company, storeIds)
        }

        showSuccess('NPC renewal changes saved successfully')

        if (!existingRecord) {
            await scrollToWorkflow()
        }
    } catch (error) {
        showError(axiosErrorText(error))
    } finally {
        isSavingStatus.value = false
    }
}

const currentSeal = (type) => selectedCompany.value?.npc_status?.attachments?.[type]?.[0] || null

const uploadSeal = async (type, event) => {
    if (!canEditNpcStatus.value) return
    const file = event.target.files?.[0]
    if (!file) return
    const record = selectedCompany.value?.npc_status
    if (!record) {
        showError('Save the renewal before uploading seals.')
        return
    }
    try {
        const formData = new FormData()
        formData.append('type', type)
        formData.append('validity_from', statusForm.validity_from || `${currentYear.value}-01-01`)
        formData.append('file', file)
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
    if (!canEditNpcStatus.value || !attachment) return
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

const toggleConfirm = async (row, type) => {
    if (!canEditNpcStatus.value) return
    const record = selectedCompany.value?.npc_status
    if (!record) return
    const confirmed = !row.seals[type]?.confirmed_at
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

const isStoreAssignedElsewhere = (store) => {
    const assignedRecordId = store.assigned_npc_status_id
    const currentRecordId = selectedCompany.value?.npc_status?.id
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
