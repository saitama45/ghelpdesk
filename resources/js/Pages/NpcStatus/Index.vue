<template>
    <AppLayout title="NPC Status">
        <div class="py-8">
            <div class="mx-auto max-w-7xl space-y-5 px-4 sm:px-6 lg:px-8">
                <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                        <div>
                            <h1 class="text-xl font-bold text-gray-900">NPC Renewal Monitoring</h1>
                            <p class="text-sm text-gray-500">Track validity, renewal workflow, DPO files, assigned stores, and CCTV Seal Notices.</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <label class="text-xs font-black uppercase tracking-widest text-gray-500">Validity From Year</label>
                            <input
                                v-model.number="selectedYear"
                                type="number"
                                min="2000"
                                max="2100"
                                class="w-24 rounded-lg border-gray-300 text-sm font-bold focus:border-blue-500 focus:ring-blue-500"
                                @change="refreshYear"
                            >
                        </div>
                    </div>
                </div>

                <div class="rounded-lg border border-gray-200 bg-white p-3 shadow-sm">
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
                            <th class="px-5 py-3 text-left text-xs font-bold uppercase tracking-wider text-gray-500">Entity</th>
                            <th class="px-5 py-3 text-left text-xs font-bold uppercase tracking-wider text-gray-500">Validity</th>
                            <th class="px-5 py-3 text-left text-xs font-bold uppercase tracking-wider text-gray-500">Renewal Status</th>
                            <th class="px-5 py-3 text-left text-xs font-bold uppercase tracking-wider text-gray-500">Workflow</th>
                            <th class="px-5 py-3 text-left text-xs font-bold uppercase tracking-wider text-gray-500">DPO Files</th>
                            <th class="px-5 py-3 text-left text-xs font-bold uppercase tracking-wider text-gray-500">Stores / CCTV</th>
                            <th class="px-5 py-3 text-right text-xs font-bold uppercase tracking-wider text-gray-500">Actions</th>
                        </tr>
                    </template>

                    <template #body="{ data }">
                        <tr v-for="company in data" :key="company.id" class="hover:bg-gray-50">
                            <td class="px-5 py-4">
                                <div class="flex items-center gap-3">
                                    <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-slate-800 text-xs font-black text-white">
                                        {{ company.code?.slice(0, 2) || 'NP' }}
                                    </div>
                                    <div class="min-w-0">
                                        <div class="truncate text-sm font-bold text-gray-900">{{ company.name }}</div>
                                        <div class="flex items-center gap-2 text-xs text-gray-500">
                                            <span class="font-mono">{{ company.code }}</span>
                                            <span :class="company.is_active ? 'text-green-600' : 'text-red-600'" class="font-bold">
                                                {{ company.is_active ? 'Active Entity' : 'Inactive Entity' }}
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </td>

                            <td class="px-5 py-4 whitespace-nowrap">
                                <div v-if="company.npc_status" class="text-sm">
                                    <div class="font-bold text-gray-900">{{ formatDate(company.npc_status.validity_from) }}</div>
                                    <div class="text-xs text-gray-500">to {{ formatDate(company.npc_status.validity_to) }}</div>
                                </div>
                                <span v-else class="text-xs font-bold text-gray-400">No yearly record</span>
                            </td>

                            <td class="px-5 py-4 whitespace-nowrap">
                                <div class="space-y-1">
                                    <span :class="statusBadgeClass(company.npc_status?.renewal_status || 'No Record')" class="inline-flex rounded-full px-2.5 py-1 text-xs font-black">
                                        {{ company.npc_status?.renewal_status || 'No Record' }}
                                    </span>
                                    <div v-if="company.npc_status" class="text-[11px] font-semibold text-gray-500">
                                        {{ renewalDaysLabel(company.npc_status.renewal_days) }}
                                    </div>
                                </div>
                            </td>

                            <td class="px-5 py-4">
                                <div v-if="company.npc_status" class="min-w-[170px] space-y-2">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="text-xs font-black text-gray-700">{{ company.npc_status.workflow_stage }}</span>
                                        <span class="text-xs font-bold text-gray-500">{{ company.npc_status.workflow_progress }}%</span>
                                    </div>
                                    <div class="h-2 overflow-hidden rounded-full bg-gray-100">
                                        <div class="h-full rounded-full bg-blue-600" :style="{ width: `${company.npc_status.workflow_progress}%` }"></div>
                                    </div>
                                </div>
                                <span v-else class="text-xs font-bold text-gray-400">No workflow</span>
                            </td>

                            <td class="px-5 py-4">
                                <div v-if="company.npc_status" class="space-y-1 text-xs">
                                    <div class="font-bold text-gray-700">
                                        Seal: {{ attachmentCount(company, 'dpo_seal') }}
                                    </div>
                                    <div class="font-bold text-gray-700">
                                        Registration: {{ attachmentCount(company, 'dpo_registration') }}
                                    </div>
                                </div>
                                <span v-else class="text-xs font-bold text-gray-400">No files</span>
                            </td>

                            <td class="px-5 py-4 whitespace-nowrap">
                                <button
                                    type="button"
                                    @click="openStoreModal(company)"
                                    class="inline-flex items-center gap-2 rounded-lg border border-blue-200 bg-blue-50 px-3 py-1.5 text-sm font-black text-blue-700 hover:bg-blue-100"
                                >
                                    <span>{{ company.store_count }}</span>
                                    <span class="text-[10px] uppercase tracking-widest">Stores</span>
                                </button>
                                <div v-if="company.npc_status" class="mt-1 text-[11px] font-semibold text-gray-500">
                                    CCTV {{ cctvSummary(company).complete }}/{{ cctvSummary(company).total }}
                                </div>
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

        <div v-if="showStatusModal" class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex min-h-screen items-center justify-center px-4 py-6">
                <div class="fixed inset-0 bg-black/20 backdrop-blur-md" @click="closeStatusModal"></div>
                <div class="relative flex max-h-[90vh] w-full max-w-[96rem] flex-col rounded-xl border border-gray-100 bg-white shadow-2xl">
                    <div class="flex items-start justify-between border-b p-6">
                        <div>
                            <h3 class="text-xl font-bold text-gray-900">{{ selectedCompany?.name }}</h3>
                            <p class="text-xs font-black uppercase tracking-widest text-gray-400">NPC renewal for Validity From year</p>
                        </div>
                        <button @click="closeStatusModal" class="text-gray-400 hover:text-gray-600">
                            <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <form class="flex-1 overflow-y-auto p-6 lg:p-8" @submit.prevent="submitStatus">
                        <div class="grid grid-cols-1 gap-8 xl:grid-cols-[minmax(0,1.05fr)_minmax(0,1.2fr)]">
                            <section class="space-y-6">
                                <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
                                    <div>
                                        <label class="mb-1 block text-xs font-bold uppercase tracking-wider text-gray-500">Validity From</label>
                                        <input v-model="statusForm.validity_from" type="date" required class="block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-xs font-bold uppercase tracking-wider text-gray-500">Validity To</label>
                                        <input v-model="statusForm.validity_to" type="date" required class="block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                    </div>
                                </div>

                                <div class="rounded-lg border border-gray-200 bg-gray-50 p-4">
                                    <div class="text-xs font-black uppercase tracking-widest text-gray-500">Automatic Renewal Status</div>
                                    <div class="mt-2 flex items-center gap-3">
                                        <span :class="statusBadgeClass(previewRenewalStatus)" class="inline-flex rounded-full px-2.5 py-1 text-xs font-black">
                                            {{ previewRenewalStatus }}
                                        </span>
                                        <span class="text-sm font-semibold text-gray-600">{{ previewRenewalDaysLabel }}</span>
                                    </div>
                                </div>

                                <div class="rounded-lg border border-gray-200 p-4">
                                    <div class="mb-3 flex items-center justify-between">
                                        <h4 class="text-sm font-black text-gray-900">DPO Attachments</h4>
                                        <span class="text-[10px] font-black uppercase tracking-widest text-gray-400">Filtered by Validity</span>
                                    </div>

                                    <div v-if="selectedCompany?.npc_status" class="mb-4 rounded-lg border border-blue-100 bg-blue-50 p-3">
                                        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                                            <div>
                                                <label class="mb-1 block text-[10px] font-black uppercase tracking-widest text-blue-700">Type</label>
                                                <select v-model="attachmentForm.type" class="block w-full rounded-lg border-blue-200 text-sm focus:border-blue-500 focus:ring-blue-500">
                                                    <option value="dpo_seal">DPO Seal</option>
                                                    <option value="dpo_registration">DPO Registration</option>
                                                </select>
                                            </div>
                                            <div>
                                                <label class="mb-1 block text-[10px] font-black uppercase tracking-widest text-blue-700">Validity From</label>
                                                <input v-model="attachmentForm.validity_from" type="date" class="block w-full rounded-lg border-blue-200 text-sm focus:border-blue-500 focus:ring-blue-500">
                                            </div>
                                            <div class="md:col-span-2">
                                                <label class="mb-1 block text-[10px] font-black uppercase tracking-widest text-blue-700">File</label>
                                                <input :key="fileInputKey + '-dpo-upload'" type="file" accept=".pdf,.jpg,.jpeg,.png,.webp" class="block w-full text-sm text-gray-500 file:mr-3 file:rounded-full file:border-0 file:bg-white file:px-4 file:py-2 file:text-sm file:font-semibold file:text-blue-700" @change="setAttachmentFile">
                                            </div>
                                            <button type="button" :disabled="isUploadingAttachment" @click="uploadAttachment" class="w-full rounded-lg bg-blue-600 px-4 py-2 text-sm font-bold text-white shadow-sm hover:bg-blue-700 disabled:opacity-50 md:w-auto">
                                                {{ isUploadingAttachment ? 'Uploading...' : 'Upload' }}
                                            </button>
                                        </div>
                                    </div>

                                    <AttachmentHistory
                                        :groups="filteredAttachmentHistory"
                                        :can-delete="hasPermission('npc_status.edit')"
                                        @delete="deleteAttachment"
                                    />

                                    <div v-if="!selectedCompany?.npc_status" class="mt-3 rounded-lg border border-dashed border-gray-200 bg-gray-50 p-4 text-sm font-semibold text-gray-500">
                                        Save this renewal first before uploading DPO attachments.
                                    </div>
                                </div>
                            </section>

                            <section class="rounded-lg border border-gray-200 p-5">
                                <div class="mb-4 flex items-center justify-between gap-3">
                                    <div>
                                        <h4 class="text-sm font-black text-gray-900">Application Workflow</h4>
                                        <p class="text-xs text-gray-500">{{ workflowContextLabel }}</p>
                                    </div>
                                    <span class="rounded-full bg-blue-50 px-2.5 py-1 text-xs font-black text-blue-700">{{ workflowProgress }}%</span>
                                </div>

                                <div v-if="activeWorkflowRecord" class="space-y-3">
                                    <div v-for="step in workflowForm" :key="step.key" class="rounded-lg border border-gray-200 p-3">
                                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start">
                                            <label class="flex min-w-[190px] items-center gap-2 text-sm font-bold text-gray-800">
                                                <input v-model="step.is_done" type="checkbox" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500" @change="markCompletedDate(step)">
                                                {{ step.label }}
                                            </label>
                                            <div class="grid flex-1 grid-cols-1 gap-3 md:grid-cols-[170px_minmax(260px,1fr)]">
                                                <input v-model="step.completed_at" :disabled="!step.is_done" type="date" class="rounded-lg border-gray-300 text-sm disabled:bg-gray-100">
                                                <textarea v-model="step.remarks" rows="2" class="rounded-lg border-gray-300 text-sm focus:border-blue-500 focus:ring-blue-500" placeholder="Remarks"></textarea>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div v-else-if="!selectedCompany?.npc_status" class="rounded-lg border border-dashed border-gray-200 bg-gray-50 p-6 text-center text-sm font-semibold text-gray-500">
                                    Save the renewal dates first, then reopen this record to update workflow remarks.
                                </div>
                                <div v-else class="rounded-lg border border-dashed border-gray-200 bg-gray-50 p-6 text-center text-sm font-semibold text-gray-500">
                                    No application workflow record matches the selected validity range.
                                </div>
                            </section>
                        </div>
                    </form>

                    <div class="flex justify-end gap-3 border-t p-4">
                        <button type="button" @click="closeStatusModal" class="rounded-lg bg-gray-100 px-4 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-200">Cancel</button>
                        <button type="button" :disabled="isSavingStatus" @click="submitStatus" class="rounded-lg bg-blue-600 px-6 py-2 text-sm font-bold text-white shadow-md hover:bg-blue-700 disabled:opacity-50">
                            {{ isSavingStatus ? 'Saving...' : 'Save Renewal' }}
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div v-if="showStoreModal" class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex min-h-screen items-center justify-center px-4 py-6">
                <div class="fixed inset-0 bg-black/20 backdrop-blur-md" @click="closeStoreModal"></div>
                <div class="relative flex max-h-[88vh] w-full max-w-5xl flex-col rounded-xl border border-gray-100 bg-white shadow-2xl">
                    <div class="flex items-start justify-between border-b p-6">
                        <div>
                            <h3 class="text-xl font-bold text-gray-900">{{ selectedCompany?.name }}</h3>
                            <p class="text-xs font-black uppercase tracking-widest text-gray-400">Assigned stores and CCTV Seal Notices for {{ selectedYear }}</p>
                        </div>
                        <button @click="closeStoreModal" class="text-gray-400 hover:text-gray-600">
                            <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <div class="border-b bg-gray-50 p-4">
                        <input v-model="storeSearch" type="text" placeholder="Search stores by name, code, area, or brand..." class="block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        <div class="mt-3 flex justify-center overflow-x-auto">
                            <div class="inline-flex min-w-max gap-1 rounded-lg bg-white p-1 shadow-sm ring-1 ring-gray-200">
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
                                    {{ tab.label }}
                                    <span class="ml-1">{{ tab.count }}</span>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="flex-1 overflow-y-auto p-4">
                        <div class="grid grid-cols-1 gap-3 lg:grid-cols-2">
                            <div
                                v-for="store in filteredStores"
                                :key="store.id"
                                class="rounded-lg border p-3"
                                :class="isStoreDisabled(store) ? 'border-gray-200 bg-gray-50 opacity-60' : 'border-gray-200 bg-white'"
                            >
                                <div class="flex items-start gap-3">
                                    <input v-model="selectedStoreIds" :value="store.id" :disabled="isStoreDisabled(store)" type="checkbox" class="mt-1 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-start justify-between gap-2">
                                            <div class="min-w-0">
                                                <div class="truncate text-sm font-bold text-gray-900">{{ store.name }}</div>
                                                <div class="text-xs text-gray-500">{{ store.code }} - {{ store.area }} - {{ store.brand }}</div>
                                            </div>
                                            <span :class="store.cctv_seal_notice ? 'bg-green-100 text-green-800' : 'bg-amber-100 text-amber-800'" class="rounded-full px-2 py-0.5 text-[10px] font-black">
                                                {{ store.cctv_seal_notice ? 'CCTV Ready' : 'No CCTV' }}
                                            </span>
                                        </div>
                                        <div v-if="isStoreDisabled(store)" class="mt-1 text-[11px] font-bold text-amber-700">
                                            Assigned to {{ store.assigned_company_name }}
                                        </div>
                                        <div v-if="selectedStoreIds.includes(store.id) && !isStoreDisabled(store)" class="mt-3 flex flex-col gap-2 rounded-md bg-gray-50 p-2 sm:flex-row sm:items-center sm:justify-between">
                                            <a v-if="store.cctv_seal_notice" :href="store.cctv_seal_notice.url" class="truncate text-xs font-bold text-blue-600 hover:underline">
                                                {{ store.cctv_seal_notice.name || 'Download CCTV Seal Notice' }}
                                            </a>
                                            <span v-else class="text-xs font-bold text-gray-500">Upload one-time CCTV Seal Notice</span>
                                            <input :key="fileInputKey + '-cctv-' + store.id" type="file" accept=".pdf,.jpg,.jpeg,.png,.webp" class="max-w-[220px] text-xs text-gray-500 file:mr-2 file:rounded-full file:border-0 file:bg-blue-50 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-blue-700" @change="uploadCctvSealNotice(store, $event)">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div v-if="filteredStores.length === 0" class="rounded-xl border border-dashed border-gray-200 bg-gray-50 px-6 py-10 text-center">
                            <p class="text-sm font-bold text-gray-500">No stores found for this tab.</p>
                        </div>
                    </div>

                    <div class="flex items-center justify-between border-t bg-white p-4">
                        <p class="text-sm font-bold text-gray-600">{{ selectedStoreIds.length }} selected</p>
                        <div class="flex gap-3">
                            <button type="button" @click="closeStoreModal" class="rounded-lg bg-gray-100 px-4 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-200">Cancel</button>
                            <button type="button" :disabled="isSavingStores || !hasPermission('npc_status.edit')" @click="saveStores" class="rounded-lg bg-blue-600 px-6 py-2 text-sm font-bold text-white shadow-md hover:bg-blue-700 disabled:opacity-50">
                                {{ isSavingStores ? 'Saving...' : 'Save Stores' }}
                            </button>
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

const selectedYear = ref(props.filters?.year || new Date().getFullYear())
const selectedStatus = ref(props.filters?.status || '')
const pagination = usePagination(props.npcStatuses, 'npc-statuses.index', () => {
    const params = { year: selectedYear.value }

    if (selectedStatus.value) {
        params.status = selectedStatus.value
    }

    return params
})

const showStatusModal = ref(false)
const showStoreModal = ref(false)
const selectedCompany = ref(null)
const fileInputKey = ref(0)
const isSavingStatus = ref(false)
const isSavingStores = ref(false)
const isUploadingAttachment = ref(false)
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

watch([() => statusForm.validity_from, () => statusForm.validity_to], () => {
    syncWorkflowFormToValidity()
})

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

const refreshYear = () => {
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

const workflowRecordMatchesValidity = (record) => {
    if (!record) return false

    const from = parseDateOnly(statusForm.validity_from)
    const to = parseDateOnly(statusForm.validity_to)
    const recordFrom = parseDateOnly(record.validity_from)
    const recordTo = parseDateOnly(record.validity_to)

    if (from && recordFrom && recordFrom.getFullYear() !== from.getFullYear()) return false
    if (to && recordTo && recordTo.getFullYear() !== to.getFullYear()) return false
    if (from && recordTo && recordTo < from) return false
    if (to && recordFrom && recordFrom > to) return false

    return true
}

const activeWorkflowRecord = computed(() => {
    const history = selectedCompany.value?.workflow_history || []
    const matched = history.find((record) => workflowRecordMatchesValidity(record))

    return matched || null
})

const syncWorkflowFormToValidity = () => {
    workflowForm.value = activeWorkflowRecord.value
        ? workflowStepsFrom(activeWorkflowRecord.value.workflow_steps)
        : []
}

const workflowContextLabel = computed(() => {
    if (!activeWorkflowRecord.value) {
        return 'Checklist is recorded per validity year.'
    }

    return `Checklist for Validity Year ${activeWorkflowRecord.value.year}: ${formatDate(activeWorkflowRecord.value.validity_from)} to ${formatDate(activeWorkflowRecord.value.validity_to)}`
})

const canSaveRecord = (company) => {
    return company.npc_status ? hasPermission('npc_status.edit') : hasPermission('npc_status.create')
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
    fileInputKey.value++
    showStatusModal.value = true
}

const closeStatusModal = () => {
    showStatusModal.value = false
    selectedCompany.value = null
    statusForm.company_id = null
    statusForm.validity_from = ''
    statusForm.validity_to = ''
    attachmentForm.type = 'dpo_seal'
    attachmentForm.validity_from = ''
    attachmentForm.file = null
    workflowForm.value = []
    fileInputKey.value++
}

const setAttachmentFile = (event) => {
    attachmentForm.file = event.target.files?.[0] || null
}

const submitStatus = () => {
    if (!selectedCompany.value) return

    isSavingStatus.value = true
    const record = selectedCompany.value.npc_status
    const workflowRecord = activeWorkflowRecord.value
    const workflowStepsPayload = workflowForm.value.map((step) => ({ ...step }))

    if (record && workflowRecord?.id && workflowRecord.id !== record.id) {
        router.put(route('npc-statuses.workflow.update', workflowRecord.id), {
            steps: workflowStepsPayload,
            suppress_success_flash: true,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                closeStatusModal()
                showSuccess('NPC renewal workflow saved successfully')
            },
            onError: (errors) => showError(errorText(errors)),
            onFinish: () => {
                isSavingStatus.value = false
            },
        })
        return
    }

    if (record && !workflowRecord) {
        showError('Select a validity range that matches an existing renewal record before saving workflow.')
        isSavingStatus.value = false
        return
    }

    const url = record ? route('npc-statuses.update', record.id) : route('npc-statuses.store')
    const payload = {
        company_id: statusForm.company_id,
        validity_from: statusForm.validity_from,
        validity_to: statusForm.validity_to,
        suppress_success_flash: true,
    }

    if (record) {
        payload._method = 'put'
    }

    router.post(url, payload, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            if (!record) {
                closeStatusModal()
                showSuccess('NPC renewal saved successfully')
                return
            }

            router.put(route('npc-statuses.workflow.update', workflowRecord?.id || record.id), {
                steps: workflowStepsPayload,
                suppress_success_flash: true,
            }, {
                preserveScroll: true,
                onSuccess: () => {
                    closeStatusModal()
                    showSuccess('NPC renewal saved successfully')
                },
                onError: (errors) => showError(errorText(errors)),
                onFinish: () => {
                    isSavingStatus.value = false
                },
            })
        },
        onError: (errors) => {
            showError(errorText(errors))
            isSavingStatus.value = false
        },
        onFinish: () => {
            if (!record) {
                isSavingStatus.value = false
            }
        },
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

const openStoreModal = (company) => {
    if (!company.npc_status) {
        showError('Save validity dates before tagging stores.')
        return
    }

    selectedCompany.value = company
    selectedStoreIds.value = (props.stores || [])
        .filter((store) => store.assigned_npc_status_id === company.npc_status.id)
        .map((store) => store.id)
    storeSearch.value = ''
    storeAssignmentTab.value = 'all'
    showStoreModal.value = true
}

const closeStoreModal = () => {
    showStoreModal.value = false
    selectedCompany.value = null
    selectedStoreIds.value = []
    storeSearch.value = ''
    storeAssignmentTab.value = 'all'
}

const saveStores = () => {
    if (!selectedCompany.value?.npc_status) return

    isSavingStores.value = true
    router.put(route('npc-statuses.stores.update', selectedCompany.value.npc_status.id), {
        store_ids: selectedStoreIds.value,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            closeStoreModal()
            showSuccess('Assigned stores updated successfully')
        },
        onError: (errors) => showError(errorText(errors)),
        onFinish: () => {
            isSavingStores.value = false
        },
    })
}

const uploadCctvSealNotice = (store, event) => {
    const file = event.target.files?.[0]

    if (!file) return

    router.post(route('stores.cctv-seal-notice.store', store.id), {
        file,
    }, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => showSuccess('CCTV Seal Notice saved successfully'),
        onError: (errors) => showError(errorText(errors)),
        onFinish: () => {
            fileInputKey.value++
        },
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

const filteredAttachmentHistory = computed(() => {
    const from = parseDateOnly(statusForm.validity_from)
    const to = parseDateOnly(statusForm.validity_to)

    return (selectedCompany.value?.attachment_history || [])
        .map((group) => {
            const attachments = Object.fromEntries(
                Object.entries(group.attachments || {}).map(([type, items]) => [
                    type,
                    (items || []).filter((item) => {
                        const fileDate = parseDateOnly(item.validity_from)

                        if (!fileDate) return false
                        if (from && fileDate < from) return false
                        if (to && fileDate > to) return false

                        return true
                    }),
                ])
            )

            return { ...group, attachments }
        })
        .filter((group) => Object.values(group.attachments || {}).some((items) => items.length))
})

const workflowProgress = computed(() => {
    if (!workflowForm.value.length) return 0

    const done = workflowForm.value.filter((step) => step.is_done).length
    return Math.round((done / workflowForm.value.length) * 100)
})

const markCompletedDate = (step) => {
    if (step.is_done && !step.completed_at) {
        step.completed_at = todayString()
    }

    if (!step.is_done) {
        step.completed_at = ''
    }
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
    const millisecondsPerDay = 24 * 60 * 60 * 1000

    return Math.round((toDate.getTime() - fromDate.getTime()) / millisecondsPerDay)
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
