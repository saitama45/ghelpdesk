<script setup>
import { ref, reactive, watch, onMounted } from 'vue'
import { Head, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import DataTable from '@/Components/DataTable.vue'
import { useToast } from '@/Composables/useToast'
import { useConfirm } from '@/Composables/useConfirm'
import { useErrorHandler } from '@/Composables/useErrorHandler'
import { usePagination } from '@/Composables/usePagination'
import { usePermission } from '@/Composables/usePermission'
import {
    TrophyIcon,
    PlusIcon,
    PencilSquareIcon,
    TrashIcon,
    SparklesIcon,
} from '@heroicons/vue/24/outline'

const props = defineProps({
    settings: Object,
    quests: Object,
})

const { showSuccess, showError } = useToast()
const { confirm } = useConfirm()
const { put, post, destroy } = useErrorHandler()
const pagination = usePagination(props.quests, 'leadership-points.index', {}, { dataKey: 'quests' })
const { hasPermission } = usePermission()

const activeTab = ref('points')

// --- Points Settings Form ---
const pointsForm = reactive({ ...props.settings })

const savePoints = () => {
    put(route('leadership-points.update'), pointsForm, {
        onSuccess: () => showSuccess('Settings saved.'),
        onError: (errors) => showError(Object.values(errors).flat().join(', ') || 'An error occurred'),
    })
}

// --- Quests ---
const showQuestModal = ref(false)
const isEditingQuest = ref(false)
const currentQuest = ref(null)

const emptyQuestForm = () => ({
    title: '',
    description: '',
    criteria_type: 'tickets_resolved',
    criteria_value: 10,
    badge_name: '',
    bonus_points: 0,
    is_active: true,
    starts_at: '',
    ends_at: '',
})

const questForm = reactive(emptyQuestForm())

const criteriaTypeLabels = {
    tickets_resolved: 'Tickets Resolved',
    tickets_resolved_fast: 'Tickets Resolved Fast (< 1hr)',
    tickets_fcr: 'First Call Resolution Tickets',
    tickets_with_awesome_rating: 'Tickets with Awesome Rating',
}

onMounted(() => pagination.updateData(props.quests))
watch(() => props.quests, (v) => pagination.updateData(v), { deep: true })

const openCreateQuest = () => {
    isEditingQuest.value = false
    currentQuest.value = null
    Object.assign(questForm, emptyQuestForm())
    showQuestModal.value = true
}

const openEditQuest = (quest) => {
    isEditingQuest.value = true
    currentQuest.value = quest
    Object.assign(questForm, {
        title: quest.title,
        description: quest.description || '',
        criteria_type: quest.criteria_type,
        criteria_value: quest.criteria_value,
        badge_name: quest.badge_name || '',
        bonus_points: quest.bonus_points,
        is_active: quest.is_active,
        starts_at: quest.starts_at || '',
        ends_at: quest.ends_at || '',
    })
    showQuestModal.value = true
}

const closeQuestModal = () => { showQuestModal.value = false }

const submitQuestForm = () => {
    if (isEditingQuest.value) {
        put(route('leadership-points.quests.update', currentQuest.value.id), questForm, {
            onSuccess: closeQuestModal,
            onError: (errors) => showError(Object.values(errors).flat().join(', ') || 'An error occurred'),
        })
    } else {
        post(route('leadership-points.quests.store'), questForm, {
            onSuccess: closeQuestModal,
            onError: (errors) => showError(Object.values(errors).flat().join(', ') || 'An error occurred'),
        })
    }
}

const deleteQuest = async (quest) => {
    const confirmed = await confirm({
        title: 'Delete Quest',
        message: `Are you sure you want to delete "${quest.title}"?`,
        confirmText: 'Delete',
        cancelText: 'Cancel',
        type: 'danger',
    })
    if (!confirmed) return

    destroy(route('leadership-points.quests.destroy', quest.id), {}, {
        onError: (errors) => showError(Object.values(errors).flat().join(', ') || 'An error occurred'),
    })
}

const levels = [
    { key: 'leadership.level_beginner',     label: 'Beginner' },
    { key: 'leadership.level_intermediate',  label: 'Intermediate' },
    { key: 'leadership.level_professional',  label: 'Professional' },
    { key: 'leadership.level_expert',        label: 'Expert' },
    { key: 'leadership.level_master',        label: 'Master' },
    { key: 'leadership.level_guru',          label: 'Guru' },
]
</script>

<template>
    <AppLayout>
        <Head title="Leadership Points Settings" />

        <div class="max-w-5xl mx-auto py-8 px-4 sm:px-6">
            <!-- Header -->
            <div class="flex items-center gap-3 mb-6">
                <TrophyIcon class="w-7 h-7 text-yellow-500" />
                <div>
                    <h1 class="text-xl font-bold text-gray-900 dark:text-white">Leadership Points Settings</h1>
                    <p class="text-sm text-gray-500">Configure how agents earn points, levels, and manage quests.</p>
                </div>
            </div>

            <!-- Tabs -->
            <div class="border-b border-gray-200 dark:border-gray-700 mb-6">
                <nav class="flex gap-6">
                    <button
                        v-for="tab in [{ id: 'points', label: 'Points' }, { id: 'quests', label: 'Quests' }]"
                        :key="tab.id"
                        @click="activeTab = tab.id"
                        :class="[
                            'pb-3 text-sm font-medium border-b-2 transition-colors',
                            activeTab === tab.id
                                ? 'border-blue-500 text-blue-600 dark:text-blue-400'
                                : 'border-transparent text-gray-500 hover:text-gray-700 dark:hover:text-gray-300'
                        ]"
                    >{{ tab.label }}</button>
                </nav>
            </div>

            <!-- ===== POINTS TAB ===== -->
            <div v-if="activeTab === 'points'" class="space-y-8">

                <!-- Award Points -->
                <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6">
                    <h2 class="text-base font-semibold text-gray-800 dark:text-white mb-1">Award Points</h2>
                    <p class="text-sm text-gray-500 mb-5">Points awarded to the agent when a ticket is closed, based on resolution speed.</p>

                    <div class="grid grid-cols-3 gap-4">
                        <div class="text-center">
                            <div class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-2">Fast (&lt; 1hr)</div>
                            <input
                                v-model.number="pointsForm['leadership.fast_points']"
                                type="number"
                                class="w-full text-center rounded-lg border border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-900 text-gray-900 dark:text-white text-sm py-2 px-3 focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                            />
                            <div class="text-xs text-gray-400 mt-1">Points</div>
                        </div>
                        <div class="text-center">
                            <div class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-2">On Time (Within SLA)</div>
                            <input
                                v-model.number="pointsForm['leadership.ontime_points']"
                                type="number"
                                class="w-full text-center rounded-lg border border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-900 text-gray-900 dark:text-white text-sm py-2 px-3 focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                            />
                            <div class="text-xs text-gray-400 mt-1">Points</div>
                        </div>
                        <div class="text-center">
                            <div class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-2">Late (Overdue)</div>
                            <input
                                v-model.number="pointsForm['leadership.late_points']"
                                type="number"
                                class="w-full text-center rounded-lg border border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-900 text-gray-900 dark:text-white text-sm py-2 px-3 focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                            />
                            <div class="text-xs text-gray-400 mt-1">Points</div>
                        </div>
                    </div>
                </div>

                <!-- Bonus Points -->
                <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6">
                    <h2 class="text-base font-semibold text-gray-800 dark:text-white mb-1">Bonus Points</h2>
                    <p class="text-sm text-gray-500 mb-5">Additional points awarded based on resolution quality.</p>

                    <div class="grid grid-cols-3 gap-4">
                        <div class="text-center">
                            <div class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-2">First Call Resolution</div>
                            <input
                                v-model.number="pointsForm['leadership.fcr_bonus']"
                                type="number"
                                class="w-full text-center rounded-lg border border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-900 text-gray-900 dark:text-white text-sm py-2 px-3 focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                            />
                            <div class="text-xs text-gray-400 mt-1">Bonus Points</div>
                        </div>
                        <div class="text-center">
                            <div class="text-xs font-medium text-green-600 uppercase tracking-wide mb-2">Happy Customer</div>
                            <input
                                v-model.number="pointsForm['leadership.happy_customer_bonus']"
                                type="number"
                                class="w-full text-center rounded-lg border border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-900 text-gray-900 dark:text-white text-sm py-2 px-3 focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                            />
                            <div class="text-xs text-gray-400 mt-1">Bonus Points</div>
                        </div>
                        <div class="text-center">
                            <div class="text-xs font-medium text-red-500 uppercase tracking-wide mb-2">Unhappy Customer</div>
                            <input
                                v-model.number="pointsForm['leadership.unhappy_customer_penalty']"
                                type="number"
                                class="w-full text-center rounded-lg border border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-900 text-gray-900 dark:text-white text-sm py-2 px-3 focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                            />
                            <div class="text-xs text-gray-400 mt-1">Points (negative)</div>
                        </div>
                    </div>
                </div>

                <!-- Agent Levels -->
                <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6">
                    <h2 class="text-base font-semibold text-gray-800 dark:text-white mb-1">Agent Levels</h2>
                    <p class="text-sm text-gray-500 mb-5">Set the cumulative points threshold required to reach each level.</p>

                    <div class="grid grid-cols-3 sm:grid-cols-6 gap-4">
                        <div v-for="level in levels" :key="level.key" class="text-center">
                            <div class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-2">{{ level.label }}</div>
                            <input
                                v-model.number="pointsForm[level.key]"
                                type="number"
                                min="0"
                                class="w-full text-center rounded-lg border border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-900 text-gray-900 dark:text-white text-sm py-2 px-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                            />
                            <div class="text-xs text-gray-400 mt-1">pts</div>
                        </div>
                    </div>
                </div>

                <!-- Trophies Info -->
                <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6">
                    <h2 class="text-base font-semibold text-gray-800 dark:text-white mb-1">Monthly Trophies</h2>
                    <p class="text-sm text-gray-500 mb-4">Awarded automatically each month to the top agent per category.</p>
                    <div class="grid grid-cols-2 gap-3">
                        <div v-for="trophy in [
                            { icon: '🏆', name: 'Most Valuable Player', desc: 'Agent with the most overall points this month' },
                            { icon: '⭐', name: 'Customer Wow Champion', desc: 'Agent with the most customer satisfaction points' },
                            { icon: '🧙', name: 'Wizard', desc: 'Agent with the highest First Call Resolution points' },
                            { icon: '🏎️', name: 'Speed Racer', desc: 'Agent with the most fast-resolution points' },
                        ]" :key="trophy.name"
                            class="flex items-start gap-3 p-3 rounded-lg bg-gray-50 dark:bg-gray-900">
                            <span class="text-2xl">{{ trophy.icon }}</span>
                            <div>
                                <div class="text-sm font-medium text-gray-800 dark:text-white">{{ trophy.name }}</div>
                                <div class="text-xs text-gray-500">{{ trophy.desc }}</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="flex justify-end" v-if="hasPermission('leadership_points.edit')">
                    <button
                        @click="savePoints"
                        class="inline-flex items-center gap-2 px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors"
                    >Save Settings</button>
                </div>
            </div>

            <!-- ===== QUESTS TAB ===== -->
            <div v-if="activeTab === 'quests'" class="space-y-4">
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="text-base font-semibold text-gray-800 dark:text-white">Quests</h2>
                        <p class="text-sm text-gray-500">Business-specific goals that challenge agents to achieve milestones.</p>
                    </div>
                    <button
                        v-if="hasPermission('leadership_points.edit')"
                        @click="openCreateQuest"
                        class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors"
                    >
                        <PlusIcon class="w-4 h-4" />
                        Add Quest
                    </button>
                </div>

                <DataTable
                    title="Leadership Quests"
                    subtitle="Manage reward challenges for support agents."
                    search-placeholder="Search quests..."
                    empty-message="No quests configured."
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
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Quest</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Criteria</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Target</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Bonus Pts</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Status</th>
                            <th v-if="hasPermission('leadership_points.edit')" class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500">Actions</th>
                        </tr>
                    </template>

                    <template #body="{ data }">
                        <tr v-for="row in data" :key="row.id" class="hover:bg-gray-50">
                            <td class="px-4 py-3">
                                <div class="font-medium text-gray-900 dark:text-white">{{ row.title }}</div>
                                <div v-if="row.description" class="max-w-xs truncate text-xs text-gray-500">{{ row.description }}</div>
                                <div v-if="row.badge_name" class="mt-1 inline-flex items-center gap-1 rounded-full bg-yellow-100 px-2 py-0.5 text-xs text-yellow-700">
                                    <SparklesIcon class="h-3 w-3" /> {{ row.badge_name }}
                                </div>
                            </td>
                            <td class="px-4 py-3 text-sm font-semibold text-slate-900">
                                {{ criteriaTypeLabels[row.criteria_type] || row.criteria_type }}
                            </td>
                            <td class="px-4 py-3 text-sm font-medium">{{ row.criteria_value }}</td>
                            <td class="px-4 py-3">
                                <span :class="['text-sm font-semibold', row.bonus_points >= 0 ? 'text-green-600' : 'text-red-500']">
                                    {{ row.bonus_points >= 0 ? '+' : '' }}{{ row.bonus_points }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <span :class="['inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium', row.is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500']">
                                    {{ row.is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </td>
                            <td v-if="hasPermission('leadership_points.edit')" class="px-4 py-3">
                                <div class="flex items-center justify-end gap-2">
                                    <button @click="openEditQuest(row)" class="text-blue-500 hover:text-blue-700" title="Edit quest">
                                        <PencilSquareIcon class="h-4 w-4" />
                                    </button>
                                    <button @click="deleteQuest(row)" class="text-red-500 hover:text-red-700" title="Delete quest">
                                        <TrashIcon class="h-4 w-4" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </template>
                </DataTable>
            </div>
        </div>

        <!-- Quest Modal -->
        <Teleport to="body">
            <div v-if="showQuestModal" class="fixed inset-0 z-50 flex items-center justify-center p-4">
                <div class="absolute inset-0 bg-black/50" @click="closeQuestModal" />
                <div class="relative bg-white dark:bg-gray-800 rounded-xl shadow-xl w-full max-w-lg p-6 space-y-4">
                    <h3 class="text-base font-semibold text-gray-900 dark:text-white">
                        {{ isEditingQuest ? 'Edit Quest' : 'New Quest' }}
                    </h3>

                    <div class="space-y-3">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Title *</label>
                            <input v-model="questForm.title" type="text" placeholder="e.g. Earn Customer Love!"
                                class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-900 text-gray-900 dark:text-white text-sm py-2 px-3 focus:ring-2 focus:ring-blue-500 focus:border-transparent" />
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Description</label>
                            <textarea v-model="questForm.description" rows="2" placeholder="Describe the quest challenge…"
                                class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-900 text-gray-900 dark:text-white text-sm py-2 px-3 focus:ring-2 focus:ring-blue-500 focus:border-transparent" />
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Criteria *</label>
                                <select v-model="questForm.criteria_type"
                                    class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-900 text-gray-900 dark:text-white text-sm py-2 px-3 focus:ring-2 focus:ring-blue-500">
                                    <option v-for="(label, val) in criteriaTypeLabels" :key="val" :value="val">{{ label }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Target Count *</label>
                                <input v-model.number="questForm.criteria_value" type="number" min="1"
                                    class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-900 text-gray-900 dark:text-white text-sm py-2 px-3 focus:ring-2 focus:ring-blue-500" />
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Badge Name</label>
                                <input v-model="questForm.badge_name" type="text" placeholder="e.g. Heart"
                                    class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-900 text-gray-900 dark:text-white text-sm py-2 px-3 focus:ring-2 focus:ring-blue-500" />
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Bonus Points *</label>
                                <input v-model.number="questForm.bonus_points" type="number"
                                    class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-900 text-gray-900 dark:text-white text-sm py-2 px-3 focus:ring-2 focus:ring-blue-500" />
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Starts At</label>
                                <input v-model="questForm.starts_at" type="date"
                                    class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-900 text-gray-900 dark:text-white text-sm py-2 px-3 focus:ring-2 focus:ring-blue-500" />
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Ends At</label>
                                <input v-model="questForm.ends_at" type="date"
                                    class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-900 text-gray-900 dark:text-white text-sm py-2 px-3 focus:ring-2 focus:ring-blue-500" />
                            </div>
                        </div>

                        <div class="flex items-center gap-2">
                            <input id="is_active" v-model="questForm.is_active" type="checkbox"
                                class="rounded border-gray-300 text-blue-600 focus:ring-blue-500" />
                            <label for="is_active" class="text-sm text-gray-700 dark:text-gray-300">Active</label>
                        </div>
                    </div>

                    <div class="flex justify-end gap-3 pt-2">
                        <button @click="closeQuestModal"
                            class="px-4 py-2 text-sm text-gray-600 hover:text-gray-800 dark:text-gray-400 dark:hover:text-white transition-colors">
                            Cancel
                        </button>
                        <button @click="submitQuestForm"
                            class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors">
                            {{ isEditingQuest ? 'Update' : 'Create' }}
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>
