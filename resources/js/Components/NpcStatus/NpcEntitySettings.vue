<template>
    <div class="rounded-lg border border-gray-200 bg-white shadow-sm dark:bg-gray-800 dark:border-gray-700">
        <div class="flex flex-wrap items-start justify-between gap-3 border-b border-gray-200 p-4 dark:border-gray-700">
            <div class="min-w-0">
                <h2 class="text-base font-bold text-gray-900 dark:text-gray-100">Hidden entities</h2>
                <p class="mt-1 max-w-3xl text-sm text-gray-500 dark:text-gray-300">
                    Checked entities are left out of the Monitoring list and its status counts. Their NPC records,
                    seals and store downloads are kept, and they return to the list as soon as they are unchecked.
                </p>
            </div>
            <div class="flex items-center gap-2 text-xs font-bold uppercase tracking-wide">
                <span class="rounded-full bg-gray-100 px-2.5 py-1 text-gray-700 dark:bg-gray-900 dark:text-gray-300">{{ shownCount }} shown</span>
                <span class="rounded-full bg-amber-100 px-2.5 py-1 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">{{ hiddenIds.length }} hidden</span>
            </div>
        </div>

        <div class="space-y-3 p-4">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <input
                    v-model="search"
                    type="text"
                    placeholder="Search entities by name or code..."
                    class="block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:max-w-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"
                >
                <button
                    type="button"
                    @click="hiddenIds = []"
                    :disabled="!hiddenIds.length"
                    class="self-start text-xs font-bold text-blue-600 hover:text-blue-800 disabled:cursor-not-allowed disabled:text-gray-400 sm:self-auto dark:text-blue-300"
                >
                    Show all entities
                </button>
            </div>

            <div v-if="filteredEntities.length" class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                <label
                    v-for="entity in filteredEntities"
                    :key="entity.id"
                    class="flex cursor-pointer items-center gap-3 rounded-lg border p-3 transition-colors"
                    :class="isHidden(entity.id)
                        ? 'border-amber-300 bg-amber-50 dark:border-amber-700 dark:bg-amber-900/20'
                        : 'border-gray-200 hover:border-blue-300 hover:bg-blue-50/40 dark:border-gray-700 dark:hover:bg-gray-700/40'"
                >
                    <input
                        v-model="hiddenIds"
                        :value="entity.id"
                        type="checkbox"
                        class="rounded border-gray-300 text-amber-600 focus:ring-amber-500 dark:border-gray-600"
                    >
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-semibold text-gray-900 dark:text-gray-100" :title="entity.name">{{ entity.name }}</span>
                        <span class="block text-xs font-mono text-gray-500 dark:text-gray-400">{{ entity.code }}</span>
                    </span>
                    <span v-if="!entity.is_active" class="rounded bg-gray-100 px-1.5 py-0.5 text-[10px] font-bold uppercase text-gray-500 dark:bg-gray-900 dark:text-gray-400">Inactive</span>
                    <span v-if="isHidden(entity.id)" class="rounded bg-amber-200 px-1.5 py-0.5 text-[10px] font-black uppercase text-amber-900 dark:bg-amber-800 dark:text-amber-100">Hidden</span>
                </label>
            </div>
            <p v-else class="py-6 text-center text-sm text-gray-500 dark:text-gray-400">No entities match "{{ search }}".</p>
        </div>

        <div class="flex flex-wrap items-center justify-end gap-3 border-t border-gray-200 p-4 dark:border-gray-700">
            <span v-if="isDirty" class="mr-auto text-xs font-semibold text-amber-700 dark:text-amber-300">Unsaved changes</span>
            <button
                type="button"
                @click="reset"
                :disabled="!isDirty || saving"
                class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700"
            >
                Reset
            </button>
            <button
                type="button"
                @click="save"
                :disabled="!isDirty || saving"
                class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50"
            >
                {{ saving ? 'Saving...' : 'Save Changes' }}
            </button>
        </div>
    </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { useToast } from '@/Composables/useToast'

// Settings tab of /npc-statuses, rendered only for npc_status.settings holders
// (the server sends `settings` to them alone and gates the save route too).
const props = defineProps({
    settings: { type: Object, required: true },
})

const { showError } = useToast()

const savedIds = computed(() => (props.settings.hidden_company_ids || []).map(Number))
const hiddenIds = ref([...savedIds.value])
const search = ref('')
const saving = ref(false)

// A save redirects back with fresh settings; adopt them as the new baseline.
watch(savedIds, (ids) => { hiddenIds.value = [...ids] })

const entities = computed(() => props.settings.entities || [])
const filteredEntities = computed(() => {
    const needle = search.value.trim().toLowerCase()
    if (!needle) return entities.value
    return entities.value.filter((entity) =>
        `${entity.name} ${entity.code || ''}`.toLowerCase().includes(needle)
    )
})

const isHidden = (id) => hiddenIds.value.includes(id)
const shownCount = computed(() => entities.value.filter((entity) => !isHidden(entity.id)).length)
const isDirty = computed(() => {
    const current = [...hiddenIds.value].sort((a, b) => a - b).join(',')
    const saved = [...savedIds.value].sort((a, b) => a - b).join(',')
    return current !== saved
})

const reset = () => { hiddenIds.value = [...savedIds.value] }

const save = () => {
    saving.value = true
    router.put(route('npc-statuses.settings.update'), { hidden_company_ids: hiddenIds.value }, {
        preserveScroll: true,
        preserveState: true,
        onError: (errors) => showError(Object.values(errors).flat().join(', ') || 'Failed to save NPC settings.'),
        onFinish: () => { saving.value = false },
    })
}
</script>
