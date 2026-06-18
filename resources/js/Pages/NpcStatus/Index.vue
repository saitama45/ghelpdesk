<template>
    <AppLayout title="NPC Status">
        <div class="py-8">
            <div class="mx-auto max-w-7xl space-y-5 px-4 sm:px-6 lg:px-8">

                <!-- Page Header -->
                <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:bg-gray-800 dark:border-gray-700">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                        <div>
                            <h1 class="text-xl font-bold text-gray-900 dark:text-gray-100">NPC Renewal Monitoring</h1>
                            <p class="text-sm text-gray-500 dark:text-gray-300">Track validity, renewal workflow, DPO files, assigned stores, and CCTV Seal Notices.</p>
                        </div>
                        <div class="flex items-center gap-3">
                            <label class="text-xs font-black uppercase tracking-widest text-gray-500 dark:text-gray-300">Year</label>
                            <div class="flex items-center rounded-xl border border-gray-200 bg-white shadow-sm dark:bg-gray-800 dark:border-gray-700">
                                <button
                                    type="button"
                                    @click="changeYear(selectedYear - 1)"
                                    class="rounded-l-xl px-3 py-2 text-lg font-black leading-none text-gray-600 transition-colors hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-gray-700"
                                >‹</button>
                                <span class="min-w-[70px] select-none px-4 py-2 text-center text-lg font-black text-gray-900 dark:text-gray-100">{{ selectedYear }}</span>
                                <button
                                    type="button"
                                    @click="changeYear(selectedYear + 1)"
                                    :disabled="selectedYear >= currentYear + 1"
                                    class="rounded-r-xl px-3 py-2 text-lg font-black leading-none text-gray-600 transition-colors hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-40 dark:text-gray-300 dark:hover:bg-gray-700"
                                >›</button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Status Tabs -->
                <div class="rounded-lg border border-gray-200 bg-white p-3 shadow-sm dark:bg-gray-800 dark:border-gray-700">
                    <div class="flex gap-2 overflow-x-auto custom-scrollbar">
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
                    subtitle="Status is automatic based on today's date versus Validity To."
                    search-placeholder="Search entity, renewal status, or workflow stage..."
                    empty-message="No entities found for this filter."
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
                            <th class="px-5 py-3 text-left text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-slate-300">Entity</th>
                            <th class="px-5 py-3 text-left text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-slate-300">Validity</th>
                            <th class="px-5 py-3 text-left text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-slate-300">Renewal Status</th>
                            <th class="px-5 py-3 text-left text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-slate-300">Workflow</th>
                            <th class="px-5 py-3 text-left text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-slate-300">DPO Files</th>
                            <th class="px-5 py-3 text-left text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-slate-300">Stores / CCTV</th>
                            <th class="px-5 py-3 text-right text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-slate-300">Actions</th>
                        </tr>
                    </template>

                    <template #body="{ data }">
                        <tr v-for="company in data" :key="company.id" class="hover:bg-gray-50 dark:hover:bg-gray-700">
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
                                <span v-else class="text-xs font-bold text-gray-400 dark:text-gray-400">No yearly record</span>
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

                            <td class="px-5 py-4">
                                <div v-if="company.npc_status" class="space-y-1 text-xs">
                                    <div class="font-bold text-gray-700 dark:text-gray-300">Seal: {{ attachmentCount(company, 'dpo_seal') }}</div>
                                    <div class="font-bold text-gray-700 dark:text-gray-300">Reg: {{ attachmentCount(company, 'dpo_registration') }}</div>
                                </div>
                                <span v-else class="text-xs font-bold text-gray-400 dark:text-gray-400">No files</span>
                            </td>

                            <td class="whitespace-nowrap px-5 py-4">
                                <div class="text-sm font-black text-gray-800 dark:text-gray-200">{{ company.store_count }} Stores</div>
                                <div v-if="company.npc_status" class="mt-0.5 text-[11px] font-semibold text-gray-500 dark:text-gray-300">
                                    CCTV {{ cctvSummary(company).complete }}/{{ cctvSummary(company).total }}
                                </div>
                                <span v-else class="text-xs text-gray-400 dark:text-gray-400">—</span>
                            </td>

                            <td class="px-5 py-4 text-right">
                                <div class="flex justify-end gap-1">
                                    <button
                                        v-if="canSaveRecord(company)"
                                        type="button"
                                        @click="openStatusModal(company)"
                                        class="rounded-full p-2 text-blue-600 hover:bg-blue-50 hover:text-blue-900"
                                        :title="company.npc_status ? 'Edit NPC Renewal' : 'Create NPC Renewal'"
                                    >
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                        </svg>
                                    </button>
                                    <button
                                        v-if="company.npc_status && hasPermission('npc_status.delete')"
                                        type="button"
                                        @click="deleteRecord(company)"
                                        class="rounded-full p-2 text-red-600 hover:bg-red-50 hover:text-red-900"
                                        title="Delete NPC Renewal"
                                    >
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                        </svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </template>
                </DataTable>
            </div>
        </div>

        <!-- 4-Step Stepper Modal -->
        <div v-if="showModal" class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex min-h-screen items-center justify-center px-4 py-6">
                <div class="fixed inset-0 bg-black/20 backdrop-blur-md" @click="closeModal"></div>
                <div class="relative flex max-h-[90vh] w-full max-w-4xl flex-col rounded-xl border border-gray-100 bg-white shadow-2xl dark:bg-gray-800 dark:border-gray-700">

                    <!-- Modal Header -->
                    <div class="border-b p-5">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h3 class="text-xl font-bold text-gray-900 dark:text-gray-100">{{ selectedCompany?.name }}</h3>
                                <p class="mt-0.5 text-xs font-black uppercase tracking-widest text-gray-400 dark:text-gray-400">{{ selectedCompany?.code }} — NPC Renewal</p>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <!-- Year pills -->
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
                                <span v-if="isHistoricalYear" class="rounded-full bg-amber-100 px-2.5 py-1 text-[10px] font-black text-amber-700">Read-only</span>
                                <button type="button" @click="closeModal" class="ml-1 rounded-full p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:text-gray-400 dark:hover:bg-gray-700">
                                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Step Indicator -->
                    <div class="border-b bg-gray-50 px-6 py-4 dark:bg-gray-900/50">
                        <div class="flex items-center">
                            <template v-for="(stepDef, idx) in stepDefs" :key="stepDef.key">
                                <div v-if="idx > 0" class="mx-2 h-0.5 flex-1" :class="stepDef.step <= activeStep ? 'bg-blue-400' : 'bg-gray-200 dark:bg-gray-700'"></div>
                                <button
                                    type="button"
                                    @click="goToStep(stepDef.step)"
                                    class="flex flex-shrink-0 flex-col items-center gap-1 focus:outline-none"
                                >
                                    <div :class="[
                                        'flex h-8 w-8 items-center justify-center rounded-full text-sm font-black transition-colors',
                                        activeStep === stepDef.step
                                            ? 'bg-blue-600 text-white ring-4 ring-blue-100 dark:ring-blue-900/50'
                                            : stepDef.isComplete
                                                ? 'bg-green-500 text-white'
                                                : 'bg-gray-200 text-gray-500 hover:bg-gray-300 dark:bg-gray-700 dark:text-gray-400 dark:hover:bg-gray-600'
                                    ]">
                                        <svg v-if="stepDef.isComplete && activeStep !== stepDef.step" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7" />
                                        </svg>
                                        <span v-else>{{ stepDef.step }}</span>
                                    </div>
                                    <div class="text-center">
                                        <div class="whitespace-nowrap text-xs font-bold text-gray-700 dark:text-gray-300">{{ stepDef.label }}</div>
                                        <div class="whitespace-nowrap text-[10px] font-semibold text-gray-400 dark:text-gray-400">{{ stepDef.meta }}</div>
                                    </div>
                                </button>
                            </template>
                        </div>
                    </div>

                    <!-- Step Content -->
                    <div class="flex-1 overflow-y-auto p-6">

                        <!-- Step 1: Renewal Dates -->
                        <div v-if="activeStep === 1">
                            <!-- Historical: read-only -->
                            <div v-if="isHistoricalYear" class="space-y-4">
                                <div v-if="modalNpcStatus">
                                    <div class="rounded-lg border border-gray-200 bg-gray-50 p-5 dark:bg-gray-900/50 dark:border-gray-700">
                                        <div class="mb-3 text-xs font-black uppercase tracking-widest text-gray-500 dark:text-gray-300">Validity Period — {{ effectiveModalYear }}</div>
                                        <div class="grid grid-cols-2 gap-6">
                                            <div>
                                                <div class="text-[10px] font-bold uppercase tracking-wide text-gray-400 dark:text-gray-400">From</div>
                                                <div class="mt-0.5 text-base font-bold text-gray-900 dark:text-gray-100">{{ formatDate(modalNpcStatus.validity_from) }}</div>
                                            </div>
                                            <div>
                                                <div class="text-[10px] font-bold uppercase tracking-wide text-gray-400 dark:text-gray-400">To</div>
                                                <div class="mt-0.5 text-base font-bold text-gray-900 dark:text-gray-100">{{ formatDate(modalNpcStatus.validity_to) }}</div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="mt-4 rounded-lg border border-gray-200 bg-gray-50 p-4 dark:bg-gray-900/50 dark:border-gray-700">
                                        <div class="text-xs font-black uppercase tracking-widest text-gray-500 dark:text-gray-300">Renewal Status ({{ effectiveModalYear }})</div>
                                        <div class="mt-2">
                                            <span :class="statusBadgeClass(modalNpcStatus.renewal_status || 'No Record')" class="inline-flex rounded-full px-2.5 py-1 text-xs font-black">
                                                {{ modalNpcStatus.renewal_status || 'No Record' }}
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                <div v-else class="rounded-lg border border-dashed border-gray-200 bg-gray-50 p-8 text-center dark:bg-gray-900/50 dark:border-gray-700">
                                    <p class="text-sm font-semibold text-gray-500 dark:text-gray-300">No NPC renewal record found for {{ effectiveModalYear }}.</p>
                                </div>
                            </div>

                            <!-- Current year: editable -->
                            <div v-else class="space-y-5">
                                <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
                                    <div>
                                        <label class="mb-1 block text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-gray-300">Validity From</label>
                                        <input v-model="statusForm.validity_from" type="date" required class="block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600">
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-gray-300">Validity To</label>
                                        <input v-model="statusForm.validity_to" type="date" required class="block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600">
                                    </div>
                                </div>
                                <div class="rounded-lg border border-gray-200 bg-gray-50 p-4 dark:bg-gray-900/50 dark:border-gray-700">
                                    <div class="text-xs font-black uppercase tracking-widest text-gray-500 dark:text-gray-300">Automatic Renewal Status Preview</div>
                                    <div class="mt-2 flex items-center gap-3">
                                        <span :class="statusBadgeClass(previewRenewalStatus)" class="inline-flex rounded-full px-2.5 py-1 text-xs font-black">
                                            {{ previewRenewalStatus }}
                                        </span>
                                        <span class="text-sm font-semibold text-gray-600 dark:text-gray-300">{{ previewRenewalDaysLabel }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Step 2: DPO Documents -->
                        <div v-if="activeStep === 2">
                            <!-- Historical: read-only -->
                            <div v-if="isHistoricalYear">
                                <div class="mb-3 text-xs font-black uppercase tracking-widest text-gray-500 dark:text-gray-300">DPO Documents — {{ effectiveModalYear }}</div>
                                <AttachmentHistory :groups="modalAttachmentGroups" :can-delete="false" @delete="() => {}" />
                                <div v-if="!modalAttachmentGroups.length" class="mt-3 rounded-lg border border-dashed border-gray-200 bg-gray-50 p-6 text-center text-sm font-semibold text-gray-500 dark:bg-gray-900/50 dark:text-gray-300 dark:border-gray-700">
                                    No DPO files found for {{ effectiveModalYear }}.
                                </div>
                            </div>

                            <!-- Current year -->
                            <div v-else class="space-y-4">
                                <!-- No record yet -->
                                <div v-if="!selectedCompany?.npc_status" class="rounded-lg border border-amber-200 bg-amber-50 p-4">
                                    <div class="flex items-center gap-2">
                                        <svg class="h-5 w-5 flex-shrink-0 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                                        </svg>
                                        <p class="text-sm font-bold text-amber-800">Complete Step 1 first to enable document uploads.</p>
                                    </div>
                                </div>

                                <!-- Upload form -->
                                <div v-else class="rounded-lg border border-blue-100 bg-blue-50 p-4">
                                    <div class="mb-3 text-xs font-black uppercase tracking-widest text-blue-700">Upload New DPO Document</div>
                                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                                        <div>
                                            <label class="mb-1 block text-[10px] font-black uppercase tracking-widest text-blue-700">Type</label>
                                            <select v-model="attachmentForm.type" class="block w-full rounded-lg border-blue-200 text-sm focus:border-blue-500 focus:ring-blue-500">
                                                <option value="dpo_seal">DPO Seal</option>
                                                <option value="dpo_registration">DPO Registration</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="mb-1 block text-[10px] font-black uppercase tracking-widest text-blue-700">File Validity From</label>
                                            <input v-model="attachmentForm.validity_from" type="date" class="block w-full rounded-lg border-blue-200 text-sm focus:border-blue-500 focus:ring-blue-500">
                                        </div>
                                        <div class="md:col-span-2">
                                            <label class="mb-1 block text-[10px] font-black uppercase tracking-widest text-blue-700">File</label>
                                            <input :key="fileInputKey + '-dpo-upload'" type="file" accept=".pdf,.jpg,.jpeg,.png,.webp" class="block w-full text-sm text-gray-500 file:mr-3 file:rounded-full file:border-0 file:bg-white file:px-4 file:py-2 file:text-sm file:font-semibold file:text-blue-700 dark:text-gray-300" @change="setAttachmentFile">
                                        </div>
                                        <button type="button" :disabled="isUploadingAttachment" @click="uploadAttachment" class="w-full rounded-lg bg-blue-600 px-4 py-2 text-sm font-bold text-white shadow-sm hover:bg-blue-700 disabled:opacity-50 md:w-auto">
                                            {{ isUploadingAttachment ? 'Uploading...' : 'Upload' }}
                                        </button>
                                    </div>
                                </div>

                                <AttachmentHistory
                                    :groups="modalAttachmentGroups"
                                    :can-delete="hasPermission('npc_status.edit')"
                                    @delete="deleteAttachment"
                                />
                            </div>
                        </div>

                        <!-- Step 3: Workflow Checklist -->
                        <div v-if="activeStep === 3">
                            <!-- Historical: read-only -->
                            <div v-if="isHistoricalYear">
                                <div class="mb-3 text-xs font-black uppercase tracking-widest text-gray-500 dark:text-gray-300">Application Workflow — {{ effectiveModalYear }}</div>
                                <div v-if="historicalWorkflowSteps.length" class="space-y-3">
                                    <div v-for="step in historicalWorkflowSteps" :key="step.key" class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                                        <div class="flex items-start gap-3">
                                            <span class="mt-0.5 flex h-5 w-5 flex-shrink-0 items-center justify-center rounded-full" :class="step.is_done ? 'bg-green-100 text-green-600 dark:bg-green-500/15 dark:text-green-400' : 'bg-gray-100 text-gray-400 dark:bg-gray-800 dark:text-gray-500'">
                                                <svg v-if="step.is_done" class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/>
                                                </svg>
                                                <span v-else class="h-1.5 w-1.5 rounded-full bg-gray-400"></span>
                                            </span>
                                            <div class="min-w-0 flex-1">
                                                <div class="text-sm font-bold" :class="step.is_done ? 'text-gray-900 dark:text-gray-100' : 'text-gray-500 dark:text-gray-400'">{{ step.label }}</div>
                                                <div v-if="step.completed_at" class="text-xs text-gray-500 dark:text-gray-300">Completed: {{ formatDate(step.completed_at) }}</div>
                                                <div v-if="step.remarks" class="mt-1 text-xs italic text-gray-600 dark:text-gray-300">{{ step.remarks }}</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div v-else class="rounded-lg border border-dashed border-gray-200 bg-gray-50 p-8 text-center dark:bg-gray-900/50 dark:border-gray-700">
                                    <p class="text-sm font-semibold text-gray-500 dark:text-gray-300">No workflow record for {{ effectiveModalYear }}.</p>
                                </div>
                            </div>

                            <!-- Current: editable -->
                            <div v-else>
                                <div v-if="!selectedCompany?.npc_status" class="rounded-lg border border-dashed border-gray-200 bg-gray-50 p-6 text-center text-sm font-semibold text-gray-500 dark:bg-gray-900/50 dark:text-gray-300 dark:border-gray-700">
                                    Save validity dates first (Step 1) before updating the workflow checklist.
                                </div>
                                <div v-else class="space-y-3">
                                    <div class="mb-4 flex items-center justify-between">
                                        <div class="text-xs font-black uppercase tracking-widest text-gray-500 dark:text-gray-300">Application Workflow Checklist</div>
                                        <span class="rounded-full bg-blue-50 px-2.5 py-1 text-xs font-black text-blue-700">{{ workflowProgress }}%</span>
                                    </div>
                                    <div v-for="step in workflowForm" :key="step.key" class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start">
                                            <label class="flex min-w-[190px] items-center gap-2 text-sm font-bold text-gray-800 dark:text-gray-200">
                                                <input v-model="step.is_done" type="checkbox" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 dark:border-gray-600" @change="markCompletedDate(step)">
                                                {{ step.label }}
                                            </label>
                                            <div class="grid flex-1 grid-cols-1 gap-3 md:grid-cols-[170px_minmax(260px,1fr)]">
                                                <input v-model="step.completed_at" :disabled="!step.is_done" type="date" class="rounded-lg border-gray-300 text-sm disabled:bg-gray-100 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 dark:disabled:bg-gray-800">
                                                <textarea v-model="step.remarks" rows="2" class="rounded-lg border-gray-300 text-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100" placeholder="Remarks"></textarea>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Step 4: Stores & CCTV -->
                        <div v-if="activeStep === 4">
                            <!-- Historical: info only -->
                            <div v-if="isHistoricalYear" class="rounded-lg border border-gray-200 bg-gray-50 p-8 text-center dark:bg-gray-900/50 dark:border-gray-700">
                                <div class="text-3xl font-black text-gray-900 dark:text-gray-100">{{ cctvSummary(selectedCompany).total }}</div>
                                <div class="mt-1 text-xs font-bold uppercase tracking-widest text-gray-500 dark:text-gray-300">Stores Currently Assigned</div>
                                <div class="mt-2 text-sm font-semibold text-gray-600 dark:text-gray-300">CCTV Ready: {{ cctvSummary(selectedCompany).complete }}/{{ cctvSummary(selectedCompany).total }}</div>
                                <p class="mt-4 text-xs text-gray-400 dark:text-gray-400">Store assignments are managed for the current year only. Historical assignment data is not retained.</p>
                            </div>

                            <!-- Current: editable -->
                            <div v-else>
                                <div class="mb-4 space-y-3">
                                    <input v-model="storeSearch" type="text" placeholder="Search stores by name, code, area, or brand..." class="block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600">
                                    <div class="flex overflow-x-auto">
                                        <div class="inline-flex min-w-max gap-1 rounded-lg bg-white p-1 shadow-sm ring-1 ring-gray-200 dark:bg-gray-800">
                                            <button
                                                v-for="tab in storeAssignmentTabs"
                                                :key="tab.value"
                                                type="button"
                                                @click="storeAssignmentTab = tab.value"
                                                :class="[
                                                    'rounded-md px-3 py-1.5 text-xs font-black uppercase tracking-wider',
                                                    storeAssignmentTab === tab.value ? 'bg-blue-600 text-white' : 'text-gray-500 hover:bg-gray-100 hover:text-gray-800'
                                                ]"
                                            >
                                                {{ tab.label }} {{ tab.count }}
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <div class="grid grid-cols-1 gap-3 lg:grid-cols-2">
                                    <div
                                        v-for="store in filteredStores"
                                        :key="store.id"
                                        class="rounded-lg border p-3"
                                        :class="isStoreDisabled(store) ? 'border-gray-200 bg-gray-50 opacity-60' : 'border-gray-200 bg-white'"
                                    >
                                        <div class="flex items-start gap-3">
                                            <input v-model="selectedStoreIds" :value="store.id" :disabled="isStoreDisabled(store)" type="checkbox" class="mt-1 rounded border-gray-300 text-blue-600 focus:ring-blue-500 dark:border-gray-600">
                                            <div class="min-w-0 flex-1">
                                                <div class="flex items-start justify-between gap-2">
                                                    <div class="min-w-0">
                                                        <div class="truncate text-sm font-bold text-gray-900 dark:text-gray-100">{{ store.name }}</div>
                                                        <div class="text-xs text-gray-500 dark:text-gray-300">{{ store.code }} — {{ store.area }} — {{ store.brand }}</div>
                                                    </div>
                                                    <span :class="store.cctv_seal_notice ? 'bg-green-100 text-green-800' : 'bg-amber-100 text-amber-800'" class="rounded-full px-2 py-0.5 text-[10px] font-black">
                                                        {{ store.cctv_seal_notice ? 'CCTV Ready' : 'No CCTV' }}
                                                    </span>
                                                </div>
                                                <div v-if="isStoreDisabled(store)" class="mt-1 text-[11px] font-bold text-amber-700">
                                                    Assigned to {{ store.assigned_company_name }}
                                                </div>
                                                <div v-if="selectedStoreIds.includes(store.id) && !isStoreDisabled(store)" class="mt-3 flex flex-col gap-2 rounded-md bg-gray-50 p-2 sm:flex-row sm:items-center sm:justify-between dark:bg-gray-900/50">
                                                    <a v-if="store.cctv_seal_notice" :href="store.cctv_seal_notice.url" class="truncate text-xs font-bold text-blue-600 hover:underline">
                                                        {{ store.cctv_seal_notice.name || 'Download CCTV Seal Notice' }}
                                                    </a>
                                                    <span v-else class="text-xs font-bold text-gray-500 dark:text-gray-300">Upload one-time CCTV Seal Notice</span>
                                                    <input :key="fileInputKey + '-cctv-' + store.id" type="file" accept=".pdf,.jpg,.jpeg,.png,.webp" class="max-w-[220px] text-xs text-gray-500 file:mr-2 file:rounded-full file:border-0 file:bg-blue-50 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-blue-700 dark:text-gray-300" @change="uploadCctvSealNotice(store, $event)">
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div v-if="filteredStores.length === 0" class="mt-4 rounded-xl border border-dashed border-gray-200 bg-gray-50 px-6 py-10 text-center dark:bg-gray-900/50 dark:border-gray-700">
                                    <p class="text-sm font-bold text-gray-500 dark:text-gray-300">No stores found for this tab.</p>
                                </div>
                            </div>
                        </div>

                    </div>

                    <!-- Modal Footer -->
                    <div class="flex items-center justify-between border-t bg-white p-4 dark:bg-gray-800">
                        <button
                            v-if="activeStep > 1"
                            type="button"
                            @click="activeStep--"
                            class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-300 dark:border-gray-600 dark:hover:bg-gray-700"
                        >← Back</button>
                        <div v-else></div>

                        <div class="flex items-center gap-3">
                            <button type="button" @click="closeModal" class="rounded-lg bg-gray-100 px-4 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700">Cancel</button>

                            <!-- Step 1 -->
                            <template v-if="activeStep === 1">
                                <button v-if="!isHistoricalYear" type="button" :disabled="isSavingStatus" @click="saveRenewal" class="rounded-lg bg-blue-600 px-6 py-2 text-sm font-bold text-white shadow-md hover:bg-blue-700 disabled:opacity-50">
                                    {{ isSavingStatus ? 'Saving...' : selectedCompany?.npc_status ? 'Save Renewal' : 'Create & Continue →' }}
                                </button>
                                <button v-else type="button" @click="activeStep = 2" class="rounded-lg bg-gray-700 px-6 py-2 text-sm font-bold text-white hover:bg-gray-800">Next →</button>
                            </template>

                            <!-- Step 2 -->
                            <button v-if="activeStep === 2" type="button" @click="activeStep = 3" class="rounded-lg bg-gray-700 px-6 py-2 text-sm font-bold text-white hover:bg-gray-800">Next →</button>

                            <!-- Step 3 -->
                            <template v-if="activeStep === 3">
                                <button v-if="!isHistoricalYear && selectedCompany?.npc_status" type="button" :disabled="isSavingStatus" @click="saveWorkflow" class="rounded-lg bg-blue-600 px-6 py-2 text-sm font-bold text-white shadow-md hover:bg-blue-700 disabled:opacity-50">
                                    {{ isSavingStatus ? 'Saving...' : 'Save & Next →' }}
                                </button>
                                <button v-else type="button" @click="activeStep = 4" class="rounded-lg bg-gray-700 px-6 py-2 text-sm font-bold text-white hover:bg-gray-800">Next →</button>
                            </template>

                            <!-- Step 4 -->
                            <template v-if="activeStep === 4">
                                <button v-if="!isHistoricalYear" type="button" :disabled="isSavingStores || !hasPermission('npc_status.edit')" @click="saveStores" class="rounded-lg bg-blue-600 px-6 py-2 text-sm font-bold text-white shadow-md hover:bg-blue-700 disabled:opacity-50">
                                    {{ isSavingStores ? 'Saving...' : 'Save Stores' }}
                                </button>
                                <button v-else type="button" @click="closeModal" class="rounded-lg bg-gray-700 px-6 py-2 text-sm font-bold text-white hover:bg-gray-800">Done</button>
                            </template>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { computed, defineComponent, h, onMounted, reactive, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import axios from 'axios'
import AppLayout from '@/Layouts/AppLayout.vue'
import DataTable from '@/Components/DataTable.vue'
import { useConfirm } from '@/Composables/useConfirm'
import { usePagination } from '@/Composables/usePagination'
import { usePermission } from '@/Composables/usePermission'
import { useToast } from '@/Composables/useToast'

const AttachmentHistory = defineComponent({
    props: {
        groups: { type: Array, default: () => [] },
        canDelete: Boolean,
    },
    emits: ['delete'],
    setup(props, { emit }) {
        const typeLabel = (type) => type === 'dpo_seal' ? 'DPO Seal' : 'DPO Registration'
        const hasFiles = () => props.groups.some((group) => Object.values(group.attachments || {}).some((items) => items.length))

        return () => h('div', { class: 'rounded-md bg-gray-50 p-3' }, [
            h('div', { class: 'mb-2 text-[10px] font-black uppercase tracking-widest text-gray-500' }, 'DPO Attachment History'),
            hasFiles()
                ? h('div', { class: 'space-y-3' }, props.groups.map((group) => h('div', { key: group.id, class: 'rounded bg-white p-3' }, [
                    h('div', { class: 'mb-2 flex flex-wrap items-center justify-between gap-2' }, [
                        h('div', { class: 'text-xs font-black text-gray-800' }, `Validity Year ${group.year}`),
                        h('div', { class: 'text-[11px] font-semibold text-gray-500' }, `${formatDate(group.validity_from)} to ${formatDate(group.validity_to)}`),
                    ]),
                    h('div', { class: 'space-y-2' }, Object.entries(group.attachments || {}).flatMap(([type, items]) => items.map((item) => h('div', { key: item.id, class: 'flex items-center justify-between gap-3 rounded border border-gray-100 px-3 py-2 text-xs' }, [
                        h('div', { class: 'min-w-0' }, [
                            h('div', { class: 'font-black text-gray-500' }, typeLabel(type)),
                            h('a', { href: item.url, class: 'block truncate font-bold text-blue-600 hover:underline' }, item.name || 'Download file'),
                            h('div', { class: 'font-semibold text-gray-500' }, `File Validity From: ${formatDate(item.validity_from)}`),
                        ]),
                        props.canDelete
                            ? h('button', { type: 'button', class: 'font-black text-red-600 hover:text-red-800', onClick: () => emit('delete', item) }, 'Delete')
                            : null,
                    ])))),
                ])))
                : h('div', { class: 'text-xs font-semibold text-gray-400' }, 'No DPO files uploaded.'),
        ])
    },
})

const props = defineProps({
    npcStatuses: Object,
    filters: Object,
    statuses: Array,
    statusCounts: Object,
    workflowSteps: Array,
    stores: Array,
})

const { confirm } = useConfirm()
const { hasPermission } = usePermission()
const { showSuccess, showError } = useToast()

const currentYear = new Date().getFullYear()
const selectedYear = ref(props.filters?.year || currentYear)
const selectedStatus = ref(props.filters?.status || '')
const pagination = usePagination(props.npcStatuses, 'npc-statuses.index', () => {
    const params = { year: selectedYear.value }
    if (selectedStatus.value) params.status = selectedStatus.value
    return params
})

// Modal state
const showModal = ref(false)
const selectedCompany = ref(null)
const fileInputKey = ref(0)
const isSavingStatus = ref(false)
const isSavingStores = ref(false)
const isUploadingAttachment = ref(false)
const activeStep = ref(1)
const modalYear = ref(null)

// Form state
const storeSearch = ref('')
const storeAssignmentTab = ref('all')
const selectedStoreIds = ref([])
const workflowForm = ref([])

const statusForm = reactive({
    company_id: null,
    validity_from: '',
    validity_to: '',
})

const attachmentForm = reactive({
    type: 'dpo_seal',
    validity_from: '',
    file: null,
})

onMounted(() => {
    pagination.perPage.value = props.filters?.per_page || props.npcStatuses?.per_page || 10
    pagination.search.value = props.filters?.search || ''
    pagination.updateData(props.npcStatuses)
})

watch(() => props.npcStatuses, (newData) => {
    pagination.updateData(newData)
    refreshSelectedCompanyFromData(newData)
}, { deep: true })

watch(() => props.filters?.year, (year) => {
    if (year) selectedYear.value = year
})

watch(() => props.filters?.status, (status) => {
    selectedStatus.value = status || ''
})

// ---- Modal year context ----
const effectiveModalYear = computed(() => modalYear.value ?? selectedYear.value)
const isHistoricalYear = computed(() => effectiveModalYear.value !== selectedYear.value)

const modalNpcStatus = computed(() => {
    if (!isHistoricalYear.value) return selectedCompany.value?.npc_status ?? null
    return (selectedCompany.value?.workflow_history || []).find((r) => r.year === effectiveModalYear.value) ?? null
})

const entityAvailableYears = computed(() => {
    if (!selectedCompany.value) return []
    const years = new Set([selectedYear.value])
    ;(selectedCompany.value.workflow_history || []).forEach((r) => years.add(r.year))
    ;(selectedCompany.value.attachment_history || []).forEach((r) => years.add(r.year))
    return [...years].sort((a, b) => b - a)
})

const modalAttachmentGroups = computed(() => {
    return (selectedCompany.value?.attachment_history || []).filter((g) => g.year === effectiveModalYear.value)
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

const stepDefs = computed(() => {
    const company = selectedCompany.value
    return [
        {
            key: 'renewal', label: 'Renewal', step: 1,
            meta: modalNpcStatus.value?.validity_from ? formatDate(modalNpcStatus.value.validity_from) : 'Not set',
            isComplete: !!modalNpcStatus.value?.validity_from,
        },
        {
            key: 'documents', label: 'Documents', step: 2,
            meta: (() => {
                if (isHistoricalYear.value) {
                    const total = modalAttachmentGroups.value.reduce((sum, g) => sum + Object.values(g.attachments || {}).flat().length, 0)
                    return total ? `${total} file${total !== 1 ? 's' : ''}` : 'No files'
                }
                const n = (modalNpcStatus.value?.attachments?.dpo_seal?.length || 0) + (modalNpcStatus.value?.attachments?.dpo_registration?.length || 0)
                return n ? `${n} file${n !== 1 ? 's' : ''}` : 'No files'
            })(),
            isComplete: (() => {
                if (isHistoricalYear.value) return modalAttachmentGroups.value.some((g) => Object.values(g.attachments || {}).flat().length > 0)
                return (modalNpcStatus.value?.attachments?.dpo_seal?.length || 0) > 0 || (modalNpcStatus.value?.attachments?.dpo_registration?.length || 0) > 0
            })(),
        },
        {
            key: 'workflow', label: 'Workflow', step: 3,
            meta: (() => {
                if (isHistoricalYear.value) {
                    const steps = historicalWorkflowSteps.value
                    return steps.length ? `${steps.filter((s) => s.is_done).length}/${steps.length} done` : '—'
                }
                return modalNpcStatus.value ? `${workflowProgress.value}%` : '—'
            })(),
            isComplete: isHistoricalYear.value
                ? historicalWorkflowSteps.value.length > 0 && historicalWorkflowSteps.value.every((s) => s.is_done)
                : workflowProgress.value === 100,
        },
        {
            key: 'stores', label: 'Stores & CCTV', step: 4,
            meta: isHistoricalYear.value
                ? `${company ? cctvSummary(company).total : 0} stores`
                : `${selectedStoreIds.value.length} selected`,
            isComplete: isHistoricalYear.value
                ? (company ? cctvSummary(company).total > 0 : false)
                : selectedStoreIds.value.length > 0,
        },
    ]
})

// ---- Status tabs ----
const statusTabs = computed(() => {
    const counts = props.statusCounts || {}
    const allEntities = Object.values(counts).reduce((sum, count) => sum + Number(count?.entities || 0), 0)
    const allStores = Object.values(counts).reduce((sum, count) => sum + Number(count?.stores || 0), 0)

    return [
        { label: 'All', value: '', entities: allEntities, stores: allStores },
        ...(props.statuses || []).map((status) => ({
            label: status,
            value: status,
            entities: Number(counts[status]?.entities || 0),
            stores: Number(counts[status]?.stores || 0),
        })),
    ]
})

const selectStatus = (status) => {
    if (selectedStatus.value === status) return
    selectedStatus.value = status
    pagination.currentPage.value = 1
    pagination.performSearch()
}

const changeYear = (year) => {
    selectedYear.value = year
    pagination.currentPage.value = 1
    pagination.performSearch()
}

const refreshSelectedCompanyFromData = (data = props.npcStatuses) => {
    if (!selectedCompany.value?.id) return
    const updatedCompany = (data?.data || []).find((company) => company.id === selectedCompany.value.id)
    if (updatedCompany) {
        selectedCompany.value = updatedCompany
        syncWorkflowFormToValidity()
    }
}

const replaceCompanyInPage = (company) => {
    if (!company?.id) return
    pagination.data.value = pagination.data.value.map((row) => row.id === company.id ? company : row)
    if (selectedCompany.value?.id === company.id) {
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
    return company.npc_status ? hasPermission('npc_status.edit') : hasPermission('npc_status.create')
}

const hasYearRecord = (year) => {
    if (year === selectedYear.value) return !!selectedCompany.value?.npc_status
    return (selectedCompany.value?.workflow_history || []).some((r) => r.year === year)
        || (selectedCompany.value?.attachment_history || []).some((r) => r.year === year)
}

const openStatusModal = (company) => {
    selectedCompany.value = company
    statusForm.company_id = company.id
    statusForm.validity_from = company.npc_status?.validity_from || `${selectedYear.value}-01-01`
    statusForm.validity_to = company.npc_status?.validity_to || `${selectedYear.value}-12-31`
    attachmentForm.type = 'dpo_seal'
    attachmentForm.validity_from = statusForm.validity_from
    attachmentForm.file = null
    syncWorkflowFormToValidity()
    selectedStoreIds.value = (props.stores || [])
        .filter((s) => s.assigned_npc_status_id === company.npc_status?.id)
        .map((s) => s.id)
    storeSearch.value = ''
    storeAssignmentTab.value = 'all'
    activeStep.value = 1
    modalYear.value = null
    fileInputKey.value++
    showModal.value = true
}

const closeModal = () => {
    showModal.value = false
    selectedCompany.value = null
    statusForm.company_id = null
    statusForm.validity_from = ''
    statusForm.validity_to = ''
    attachmentForm.type = 'dpo_seal'
    attachmentForm.validity_from = ''
    attachmentForm.file = null
    workflowForm.value = []
    selectedStoreIds.value = []
    storeSearch.value = ''
    storeAssignmentTab.value = 'all'
    activeStep.value = 1
    modalYear.value = null
    fileInputKey.value++
}

const switchModalYear = (year) => {
    modalYear.value = (year === selectedYear.value) ? null : year
    activeStep.value = 1
    if (!isHistoricalYear.value) {
        statusForm.validity_from = selectedCompany.value?.npc_status?.validity_from || `${selectedYear.value}-01-01`
        statusForm.validity_to = selectedCompany.value?.npc_status?.validity_to || `${selectedYear.value}-12-31`
        selectedStoreIds.value = (props.stores || [])
            .filter((s) => s.assigned_npc_status_id === selectedCompany.value?.npc_status?.id)
            .map((s) => s.id)
        syncWorkflowFormToValidity()
    }
}

const goToStep = (step) => {
    activeStep.value = step
}

const setAttachmentFile = (event) => {
    attachmentForm.file = event.target.files?.[0] || null
}

const saveRenewal = () => {
    if (!selectedCompany.value) return
    isSavingStatus.value = true
    const record = selectedCompany.value.npc_status
    const url = record ? route('npc-statuses.update', record.id) : route('npc-statuses.store')
    const payload = {
        company_id: statusForm.company_id,
        validity_from: statusForm.validity_from,
        validity_to: statusForm.validity_to,
        suppress_success_flash: true,
    }
    if (record) payload._method = 'put'

    router.post(url, payload, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            showSuccess(record ? 'NPC renewal dates updated successfully' : 'NPC renewal created successfully')
            activeStep.value = 2
        },
        onError: (errors) => showError(errorText(errors)),
        onFinish: () => { isSavingStatus.value = false },
    })
}

const saveWorkflow = () => {
    const record = selectedCompany.value?.npc_status
    if (!record) return
    isSavingStatus.value = true

    router.put(route('npc-statuses.workflow.update', record.id), {
        steps: workflowForm.value.map((step) => ({ ...step })),
        suppress_success_flash: true,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            showSuccess('Workflow checklist saved successfully')
            activeStep.value = 4
        },
        onError: (errors) => showError(errorText(errors)),
        onFinish: () => { isSavingStatus.value = false },
    })
}

const uploadAttachment = async () => {
    const record = selectedCompany.value?.npc_status
    if (!record) {
        showError('Save the renewal before uploading DPO attachments.')
        return
    }
    if (!attachmentForm.file) {
        showError('Select a DPO attachment file to upload.')
        return
    }
    isUploadingAttachment.value = true
    try {
        const formData = new FormData()
        formData.append('type', attachmentForm.type)
        formData.append('validity_from', attachmentForm.validity_from)
        formData.append('file', attachmentForm.file)
        const { data } = await axios.post(route('npc-statuses.attachments.store', record.id), formData, {
            headers: { Accept: 'application/json' },
        })
        attachmentForm.file = null
        fileInputKey.value++
        replaceCompanyInPage(data.company)
        showSuccess(data.message || 'NPC attachment uploaded successfully')
    } catch (error) {
        showError(axiosErrorText(error))
    } finally {
        isUploadingAttachment.value = false
    }
}

const deleteRecord = async (company) => {
    if (!company.npc_status) return
    const ok = await confirm({
        title: 'Delete NPC Renewal',
        message: `Delete NPC renewal for ${company.name} in ${selectedYear.value}? Store tags and uploaded DPO files will be removed.`,
    })
    if (!ok) return
    router.delete(route('npc-statuses.destroy', company.npc_status.id), {
        preserveScroll: true,
        onSuccess: () => showSuccess('NPC renewal deleted successfully'),
        onError: (errors) => showError(errorText(errors)),
    })
}

const deleteAttachment = async (attachment) => {
    const ok = await confirm({
        title: 'Delete Attachment',
        message: `Delete ${attachment.name || 'this attachment'}?`,
    })
    if (!ok) return
    try {
        const { data } = await axios.delete(route('npc-status-attachments.destroy', attachment.id), {
            headers: { Accept: 'application/json' },
        })
        replaceCompanyInPage(data.company)
        showSuccess(data.message || 'NPC attachment deleted successfully')
    } catch (error) {
        showError(axiosErrorText(error))
    }
}

const saveStores = () => {
    if (!selectedCompany.value?.npc_status) return
    isSavingStores.value = true
    router.put(route('npc-statuses.stores.update', selectedCompany.value.npc_status.id), {
        store_ids: selectedStoreIds.value,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            closeModal()
            showSuccess('Assigned stores updated successfully')
        },
        onError: (errors) => showError(errorText(errors)),
        onFinish: () => { isSavingStores.value = false },
    })
}

const uploadCctvSealNotice = (store, event) => {
    const file = event.target.files?.[0]
    if (!file) return
    router.post(route('stores.cctv-seal-notice.store', store.id), { file }, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => showSuccess('CCTV Seal Notice saved successfully'),
        onError: (errors) => showError(errorText(errors)),
        onFinish: () => { fileInputKey.value++ },
    })
}

const filteredStores = computed(() => {
    const search = storeSearch.value.trim().toLowerCase()
    const stores = props.stores || []
    return stores.filter((store) => {
        const isAssigned = selectedStoreIds.value.includes(store.id)
        if (storeAssignmentTab.value === 'assigned' && !isAssigned) return false
        if (storeAssignmentTab.value === 'unassigned' && isAssigned) return false
        if (storeAssignmentTab.value === 'missing_cctv' && (!isAssigned || store.cctv_seal_notice)) return false
        if (!search) return true
        return [store.name, store.code, store.area, store.brand, store.assigned_company_name]
            .filter(Boolean)
            .some((value) => String(value).toLowerCase().includes(search))
    })
})

const storeAssignmentTabs = computed(() => {
    const stores = props.stores || []
    const assignedCount = stores.filter((store) => selectedStoreIds.value.includes(store.id)).length
    const missingCctv = stores.filter((store) => selectedStoreIds.value.includes(store.id) && !store.cctv_seal_notice).length
    return [
        { label: 'All', value: 'all', count: stores.length },
        { label: 'Checked', value: 'assigned', count: assignedCount },
        { label: 'Unchecked', value: 'unassigned', count: Math.max(0, stores.length - assignedCount) },
        { label: 'Missing CCTV', value: 'missing_cctv', count: missingCctv },
    ]
})

const isStoreDisabled = (store) => {
    const currentRecordId = selectedCompany.value?.npc_status?.id
    return Boolean(store.assigned_npc_status_id && store.assigned_npc_status_id !== currentRecordId)
}

const selectedCompanyStores = (company) => {
    if (!company?.npc_status) return []
    return (props.stores || []).filter((store) => store.assigned_npc_status_id === company.npc_status.id)
}

const cctvSummary = (company) => {
    const stores = selectedCompanyStores(company)
    return {
        total: stores.length,
        complete: stores.filter((store) => store.cctv_seal_notice).length,
    }
}

const attachmentCount = (company, type) => {
    return company.npc_status?.attachments?.[type]?.length || 0
}

const markCompletedDate = (step) => {
    if (step.is_done && !step.completed_at) step.completed_at = todayString()
    if (!step.is_done) step.completed_at = ''
}

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

const formatDate = (value) => {
    if (!value) return 'Not set'
    return new Date(`${value}T00:00:00`).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: '2-digit',
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

const dayDifference = (fromDate, toDate) => {
    return Math.round((toDate.getTime() - fromDate.getTime()) / (24 * 60 * 60 * 1000))
}

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

const errorText = (errors) => {
    return Object.values(errors || {}).flat().join(', ') || 'Unable to save changes.'
}

const axiosErrorText = (error) => {
    const data = error?.response?.data
    if (data?.errors) return errorText(data.errors)
    if (data?.message) return data.message
    return 'Unable to save changes.'
}
</script>
