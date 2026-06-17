<template>
    <div class="space-y-4">
        <!-- toolbar -->
        <div class="flex items-center justify-between bg-white rounded-2xl border border-gray-100 shadow-sm px-5 py-3.5">
            <div>
                <p class="text-sm font-black text-gray-800">Performance Commitment Forms</p>
                <p class="text-xs text-gray-400 mt-0.5">
                    {{ pcfs.length }} record{{ pcfs.length !== 1 ? 's' : '' }} for {{ year }}
                </p>
            </div>
            <button v-if="can.create" @click="openCreate"
                    class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 text-white text-sm font-bold rounded-xl hover:bg-blue-700 transition-colors shadow-sm">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                </svg>
                New PCF
            </button>
        </div>

        <div v-if="pcfs.length === 0" class="bg-white rounded-xl border border-dashed border-gray-200 p-10 text-center text-gray-400 text-sm">
            No PCF records for {{ year }} yet.
        </div>

        <div v-else class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="bg-gray-50 text-[11px] uppercase tracking-wider text-gray-500">
                            <th class="px-4 py-3 text-left font-bold">Team Member</th>
                            <th class="px-4 py-3 text-left font-bold">Organization</th>
                            <th class="px-4 py-3 text-center font-bold">WIGs</th>
                            <th class="px-4 py-3 text-center font-bold">Q1 / Q2 / Q3 / Q4 Weight</th>
                            <th class="px-4 py-3 text-center font-bold">Status</th>
                            <th class="px-4 py-3 text-right font-bold">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="p in pcfs" :key="p.id" class="hover:bg-gray-50/60">
                            <td class="px-4 py-3">
                                <p class="font-bold text-gray-800">{{ p.user?.name }}</p>
                                <p class="text-xs text-gray-500">{{ p.user?.position }}</p>
                            </td>
                            <td class="px-4 py-3 text-xs text-gray-500">
                                {{ [p.level_1, p.level_2, p.level_3].filter(Boolean).join(' › ') || '—' }}
                            </td>
                            <td class="px-4 py-3 text-center font-bold text-gray-700">{{ p.items.length }}</td>
                            <td class="px-4 py-3 text-center">
                                <div class="flex justify-center gap-1 text-[11px] font-bold">
                                    <span v-for="q in [1,2,3,4]" :key="q"
                                          :class="weightOk(p.quarter_weight_totals['' + q]) ? 'text-green-600' : 'text-amber-600'">
                                        {{ fmt(p.quarter_weight_totals['' + q]) }}%
                                    </span>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <span :class="p.status === 'confirmed' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600'"
                                      class="inline-flex px-2 py-1 text-[11px] font-bold rounded-full uppercase tracking-wider">
                                    {{ p.status }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex justify-end gap-1">
                                    <button @click="openEdit(p)" v-if="can.edit"
                                            class="p-2 text-blue-600 hover:text-blue-900 hover:bg-blue-50 rounded-full transition-colors" title="Edit PCF">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
                                    </button>
                                    <button @click="toggleConfirm(p)" v-if="can.edit"
                                            class="p-2 rounded-full transition-colors"
                                            :class="p.status === 'confirmed' ? 'text-amber-600 hover:bg-amber-50' : 'text-green-600 hover:bg-green-50'"
                                            :title="p.status === 'confirmed' ? 'Revert to draft' : 'Confirm PCF'">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>
                                    </button>
                                    <button @click="remove(p)" v-if="can.delete"
                                            class="p-2 text-red-600 hover:text-red-900 hover:bg-red-50 rounded-full transition-colors" title="Delete PCF">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <PcfFormModal
            v-if="modalOpen"
            :pcf="editing"
            :year="year"
            :standard-options="standardOptions"
            :value-options="valueOptions"
            :selectable-users="selectableUsers"
            :current-user-id="currentUserId"
            :taken-pcf="takenPcf"
            @close="modalOpen = false"
        />
    </div>
</template>

<script setup>
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { useToast } from '@/Composables/useToast'
import { useConfirm } from '@/Composables/useConfirm'
import PcfFormModal from './_PcfFormModal.vue'

const props = defineProps({
    pcfs: { type: Array, default: () => [] },
    can: { type: Object, default: () => ({}) },
    standardOptions: { type: Array, default: () => [] },
    valueOptions: { type: Array, default: () => [] },
    selectableUsers: { type: Array, default: () => [] },
    currentUserId: { type: Number, default: null },
    takenPcf: { type: Array, default: () => [] },
    year: { type: Number, required: true },
})

const { showSuccess, showError } = useToast()
const { confirm } = useConfirm()

const modalOpen = ref(false)
const editing = ref(null)

const fmt = (v) => Number(v ?? 0).toFixed(0)
const weightOk = (v) => Number(v ?? 0) === 100

const openCreate = () => { editing.value = null; modalOpen.value = true }
const openEdit = (p) => { editing.value = p; modalOpen.value = true }

const toggleConfirm = async (p) => {
    const reverting = p.status === 'confirmed'
    const ok = await confirm({
        title: reverting ? 'Revert PCF to Draft' : 'Confirm PCF',
        message: reverting
            ? `Revert ${p.user?.name}'s ${p.year} PCF back to draft?`
            : `Confirm ${p.user?.name}'s ${p.year} PCF for ${p.year}? This marks the commitment as agreed.`,
        confirmLabel: reverting ? 'Revert' : 'Confirm',
        variant: reverting ? 'danger' : 'primary',
    })
    if (!ok) return
    router.post(route('wigs.pcf.confirm', p.id), {}, {
        preserveScroll: true,
        onSuccess: () => showSuccess('PCF status updated.'),
        onError: () => showError('Could not update status.'),
    })
}

const remove = async (p) => {
    const ok = await confirm({
        title: 'Delete PCF',
        message: `Delete the PCF for ${p.user?.name} (${p.year})? This also removes its quarterly grades.`,
        confirmLabel: 'Delete',
        variant: 'danger',
    })
    if (!ok) return
    router.delete(route('wigs.pcf.destroy', p.id), {
        preserveScroll: true,
        onSuccess: () => showSuccess('PCF deleted.'),
        onError: () => showError('Could not delete PCF.'),
    })
}
</script>
