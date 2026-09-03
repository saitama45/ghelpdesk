<script setup>
/**
 * The one task form for a project plan.
 *
 * Both the Gantt chart and the Weekly Timeline add and edit the same rows
 * through the same endpoints, so they now open this modal instead of each
 * carrying their own copy of the fields. A field added here appears on both
 * tabs; a rule fixed here is fixed on both. Parents keep only what is genuinely
 * theirs — where a new row sorts, and what to do with the saved result.
 *
 * Open it through the exposed open() and listen for `saved`, which carries the
 * server's fresh { tasks, milestones } exactly as the Gantt's save always did.
 */
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';
import Autocomplete from '@/Components/Autocomplete.vue';
import { XMarkIcon } from '@heroicons/vue/24/outline';
import { useToast } from '@/Composables/useToast';

const props = defineProps({
    project: { type: Object, required: true },
    /** Top-level rows with their subTasks nested — the parent's live list. */
    tasks: { type: Array, default: () => [] },
    /** [{ id, name, is_external }] — who can be made responsible. */
    teamMembers: { type: Array, default: () => [] },
    manualStatuses: { type: Array, default: () => [] },
    holidays: { type: Array, default: () => [] },
    /** Which tab to return to after an Inertia-side redirect. */
    tab: { type: String, default: 'gantt' },
    /**
     * Reporting cutoff for the progress log (the Weekly Timeline reports against
     * the selected week; the Gantt reports as of now).
     */
    reportingDate: { type: String, default: null },
    /**
     * Run before saving — the parent's chance to confirm monthly-board creation.
     * Returning false cancels the save.
     */
    beforeSave: { type: Function, default: null },
});

const emit = defineEmits(['saved', 'closed']);

const { success, error } = useToast();

const isOpen = ref(false);
const isSaving = ref(false);
const isEditing = ref(false);
const editingTaskId = ref(null);
const formMode = ref('activity');
const activeParentTask = ref(null);
const activeMilestone = ref('');
const canRenameActiveMilestone = ref(true);
const progressMode = ref('done');

const form = useForm({
    project_id: props.project.id,
    parent_task_id: null,
    name: '',
    category: '',
    assigned_to: '',
    status: 'Pending',
    manual_status: '',
    task_progress: 0,
    start_date: '',
    end_date: '',
    actual_start_date: '',
    actual_end_date: '',
    lead_time_days: 1,
    depends_on_task_id: null,
    can_run_parallel: false,
    milestone_order: null,
    order: null,
    unpin_start: false,
});

/* ------------------------------------------------------------- date helpers */
// Mirrors App\Services\ScheduleCalculator: a lead time of N days is N days
// *after* the start. In 'working' mode nothing starts or ends on a weekend or a
// non-working holiday; in 'calendar' mode every day counts.
const countsEveryDay = computed(() => props.project.schedule_day_mode === 'calendar');

const holidayLookup = computed(() => {
    const map = new Map();
    (props.holidays || []).forEach(holiday => map.set(String(holiday.date).split('T')[0], holiday.name));
    return map;
});

const pad = (n) => String(n).padStart(2, '0');
const toDateInput = (date) => `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;

const parseLocalDate = (value) => {
    if (!value) return null;
    const [year, month, day] = String(value).split('T')[0].split('-').map(Number);
    if (!year || Number.isNaN(month) || Number.isNaN(day)) return null;
    return new Date(year, month - 1, day);
};

const isWeekend = (date) => date.getDay() === 0 || date.getDay() === 6;
const isHoliday = (date) => holidayLookup.value.has(toDateInput(date));
const isNonWorkingDay = (date) => !countsEveryDay.value && (isWeekend(date) || isHoliday(date));

const toWorkingDay = (date) => {
    const shifted = new Date(date.getTime());
    while (isNonWorkingDay(shifted)) shifted.setDate(shifted.getDate() + 1);
    return shifted;
};

const addWorkingDays = (date, days) => {
    const cursor = toWorkingDay(date);
    for (let i = 0; i < days; i++) {
        do { cursor.setDate(cursor.getDate() + 1); } while (isNonWorkingDay(cursor));
    }
    return cursor;
};

/** Finish = Start + Lead Time − 1, the start counted as day 1. */
const endOfSpan = (date, days) => addWorkingDays(date, Math.max(1, days) - 1);

const daysBetween = (start, end) => {
    const cursor = toWorkingDay(start);
    let days = 1;

    while (cursor < end) {
        cursor.setDate(cursor.getDate() + 1);
        if (!isNonWorkingDay(cursor)) days++;
    }

    return Math.max(1, days);
};

/* --------------------------------------------------------- derived form state */
const flatTasks = computed(() => (props.tasks || []).flatMap(task => [task, ...(task.subTasks || [])]));

const editingTask = computed(() => (isEditing.value && editingTaskId.value
    ? flatTasks.value.find(task => Number(task.id) === Number(editingTaskId.value)) || null
    : null));

const subTasksOfEditingTask = computed(() => (isEditing.value && editingTaskId.value
    ? flatTasks.value.filter(task => Number(task.parent_task_id) === Number(editingTaskId.value))
    : []));

// An activity that owns sub-tasks derives its lead time, progress and span from
// them, so those inputs are locked.
const isRolledUpActivity = computed(() => formMode.value === 'activity' && subTasksOfEditingTask.value.length > 0);

const rolledUpLeadTime = computed(() =>
    subTasksOfEditingTask.value.reduce((sum, subTask) => sum + (Number(subTask.lead_time_days) || 0), 0));

const isStartPinned = computed(() => Boolean(editingTask.value?.start_anchor_date) && !form.unpin_start);

const requisiteOptions = computed(() => flatTasks.value
    .filter(row => row.id !== editingTask.value?.id)
    .map(row => ({
        value: row.id,
        label: `${row.parent_task_id ? '↳ ' : ''}${row.name} · ${row.category || 'General'}`,
    })));

const manualStatusOptions = computed(() => [
    { label: 'None', value: '' },
    ...(props.manualStatuses || []).map(status => ({ label: status, value: status })),
]);

const formTitle = computed(() => {
    if (isEditing.value) return formMode.value === 'subtask' ? 'Edit Sub-task' : 'Edit Activity';
    if (formMode.value === 'milestone') return 'Add Milestone';
    if (formMode.value === 'subtask') return 'Add Sub-task';
    return 'Add Activity';
});

const activityFieldLabel = computed(() => (formMode.value === 'subtask' ? 'Sub-task' : 'Activity'));

const saveButtonLabel = computed(() => {
    if (isEditing.value) return 'Update';
    if (formMode.value === 'milestone') return 'Add';
    return formMode.value === 'subtask' ? 'Add Sub-task' : 'Add Activity';
});

const savedKind = computed(() => {
    if (formMode.value === 'milestone') return 'Milestone';
    return formMode.value === 'subtask' ? 'Sub-task' : 'Activity';
});

const isTaskDone = computed({
    get: () => Number(form.task_progress) >= 100,
    set: (done) => { form.task_progress = done ? 100 : 0; },
});

watch(() => form.task_progress, (value) => {
    form.status = value >= 100 ? 'Done' : (value > 0 ? 'Ongoing' : 'Pending');
});

/* ---------------------------------------------- timeline ↔ lead time syncing */
const syncEndDateFromLeadTime = (value = form.lead_time_days) => {
    const leadTime = Math.max(1, Number(value) || 1);
    if (isRolledUpActivity.value) return;

    form.lead_time_days = leadTime;
    if (!isOpen.value || !form.start_date) return;

    const start = toWorkingDay(parseLocalDate(form.start_date));

    form.start_date = toDateInput(start);
    form.end_date = toDateInput(endOfSpan(start, leadTime));
};

const syncLeadTimeFromTimeline = (changedField) => {
    if (!isOpen.value || isRolledUpActivity.value || !form.start_date) return;

    const start = toWorkingDay(parseLocalDate(form.start_date));
    form.start_date = toDateInput(start);

    if (!form.end_date) {
        syncEndDateFromLeadTime();
        return;
    }

    const end = parseLocalDate(form.end_date);
    if (end < start) {
        if (changedField === 'start') {
            syncEndDateFromLeadTime();
        } else {
            form.lead_time_days = 1;
            form.end_date = toDateInput(start);
        }
        return;
    }

    const leadTime = daysBetween(start, end);
    form.lead_time_days = leadTime;
    // Normalise a weekend/holiday finish to the span the server will save.
    form.end_date = toDateInput(endOfSpan(start, leadTime));
};

const unpinStart = () => {
    form.unpin_start = true;
    form.start_date = '';
    form.end_date = '';
};

/* ------------------------------------------------------------ open and close */
const resetForm = () => {
    form.reset();
    form.clearErrors();
    form.project_id = props.project.id;
    form.parent_task_id = null;
    form.name = '';
    form.category = '';
    form.assigned_to = '';
    form.status = 'Pending';
    form.manual_status = '';
    form.task_progress = 0;
    form.start_date = '';
    form.end_date = '';
    form.actual_start_date = '';
    form.actual_end_date = '';
    form.lead_time_days = 1;
    form.depends_on_task_id = null;
    form.can_run_parallel = false;
    form.milestone_order = null;
    form.order = null;
    form.unpin_start = false;
    progressMode.value = 'done';
};

const asDateInput = (value) => (value ? String(value).split('T')[0] : '');

/**
 * open({ mode, task, parentTask, milestone, canRenameMilestone, defaults })
 *
 * mode: 'milestone' | 'activity' | 'subtask'. Pass `task` to edit it; pass
 * `defaults` for anything the parent computes (order, milestone_order, and any
 * prefill an "add" flow inherits from its parent row).
 */
const open = ({
    mode = 'activity',
    task = null,
    parentTask = null,
    milestone = '',
    canRenameMilestone = true,
    defaults = {},
} = {}) => {
    resetForm();

    formMode.value = mode;
    isEditing.value = Boolean(task);
    editingTaskId.value = task?.id ?? null;
    activeParentTask.value = parentTask;
    activeMilestone.value = milestone || task?.category || parentTask?.category || '';
    canRenameActiveMilestone.value = canRenameMilestone;

    if (task) {
        form.parent_task_id = task.parent_task_id || null;
        form.name = task.name || '';
        form.category = task.category || '';
        form.milestone_order = task.milestone_order ?? null;
        form.assigned_to = task.assigned_to || task.external_assignment || '';
        form.status = task.status || 'Pending';
        form.manual_status = task.manual_status || '';
        form.task_progress = Number(task.progress) || 0;
        form.start_date = asDateInput(task.start_date);
        form.end_date = asDateInput(task.end_date);
        form.actual_start_date = asDateInput(task.actual_start_date);
        form.actual_end_date = asDateInput(task.actual_end_date);
        form.lead_time_days = task.lead_time_days || 1;
        form.depends_on_task_id = task.depends_on_task_id || null;
        form.can_run_parallel = Boolean(task.can_run_parallel);
        form.order = task.order ?? null;
    } else if (parentTask) {
        form.parent_task_id = parentTask.id;
        form.category = parentTask.category || 'General';
        form.assigned_to = parentTask.assigned_to || parentTask.external_assignment || '';
        form.start_date = asDateInput(parentTask.start_date);
        form.end_date = asDateInput(parentTask.end_date);
    } else if (milestone) {
        form.category = milestone;
    }

    Object.entries(defaults).forEach(([key, value]) => {
        if (value !== undefined) form[key] = value;
    });

    isOpen.value = true;
};

const close = () => {
    if (isSaving.value) return;
    isOpen.value = false;
    isEditing.value = false;
    editingTaskId.value = null;
    activeParentTask.value = null;
    activeMilestone.value = '';
    formMode.value = 'activity';
    resetForm();
    emit('closed');
};

/* ------------------------------------------------------------------- saving */
const save = async () => {
    if (isSaving.value) return;

    if (props.beforeSave) {
        const proceed = await props.beforeSave();
        if (!proceed) return;
    }

    const wasEditing = isEditing.value;
    const kind = savedKind.value;
    const payload = {
        ...form.data(),
        parent_task_id: form.parent_task_id || null,
        progress: form.task_progress,
        auto_create_monthly_boards: true,
        tab: props.tab,
    };

    // Only an edit logs progress against a reporting week; a brand-new row has
    // no history to date-stamp.
    if (wasEditing && props.reportingDate) {
        payload.progress_recorded_at = props.reportingDate;
    }

    form.clearErrors();
    isSaving.value = true;

    try {
        const response = wasEditing
            ? await window.axios.put(route('projects-tasks.update', editingTaskId.value), payload)
            : await window.axios.post(route('projects-tasks.store'), payload);

        isSaving.value = false;
        close();
        emit('saved', { ...response.data, kind, wasEditing });
        success(`${kind} ${wasEditing ? 'updated' : 'added'} successfully.`);
    } catch (exception) {
        isSaving.value = false;

        const errors = exception?.response?.data?.errors || {};
        if (Object.keys(errors).length) form.setError(errors);

        error(Object.values(errors).flat().find(Boolean)
            || exception?.response?.data?.message
            || `Unable to save ${kind.toLowerCase()}.`);
    }
};

defineExpose({ open, close });
</script>

<template>
    <Modal :show="isOpen" max-width="7xl" :closeable="!isSaving" @close="close">
        <div class="flex max-h-[90vh] flex-col bg-white dark:bg-slate-900">
            <div class="flex shrink-0 flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-6 py-4 dark:border-slate-700">
                <div>
                    <h4 class="text-sm font-black text-indigo-950 uppercase tracking-widest dark:text-indigo-100">{{ formTitle }}</h4>
                    <p v-if="activeParentTask" class="mt-1 text-xs font-semibold text-slate-500 dark:text-slate-300">
                        Under {{ activeParentTask.name }} in {{ activeMilestone }}
                    </p>
                    <p v-else-if="activeMilestone" class="mt-1 text-xs font-semibold text-slate-500 dark:text-slate-300">
                        Milestone: {{ activeMilestone }}
                    </p>
                </div>
                <button type="button"
                        @click="close"
                        :disabled="isSaving"
                        class="rounded-lg p-1.5 text-slate-400 transition-colors hover:bg-slate-100 hover:text-slate-700 disabled:cursor-wait disabled:opacity-40 dark:hover:bg-slate-800 dark:hover:text-slate-100"
                        aria-label="Close task form"
                        title="Close">
                    <XMarkIcon class="h-5 w-5" />
                </button>
            </div>
            <div class="overflow-y-auto bg-slate-50/80 p-6 dark:bg-slate-950/60">
                <div class="grid grid-cols-1 items-start gap-x-6 gap-y-4 md:grid-cols-12">
                    <div class="md:col-span-12 rounded-xl border border-slate-200 bg-white px-4 py-3 dark:border-slate-700 dark:bg-slate-900">
                        <h5 class="text-xs font-black uppercase tracking-widest text-slate-700 dark:text-slate-200">Task details</h5>
                        <p class="mt-1 text-[11px] font-medium text-slate-400">Use clear names so the complete Milestone and Activity/Sub-task remain easy to identify.</p>
                    </div>
                    <div class="min-w-0 md:col-span-4">
                        <label class="block text-[10px] font-bold text-indigo-900 uppercase tracking-widest mb-1.5 ml-1 dark:text-indigo-200">Milestone</label>
                        <input v-model="form.category" type="text" placeholder="Milestone name"
                               :readonly="formMode === 'subtask' || (formMode !== 'milestone' && !isEditing) || (isEditing && !canRenameActiveMilestone)"
                               class="w-full text-sm border-slate-200 rounded-xl shadow-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition-all read-only:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 dark:read-only:bg-slate-800">
                        <div v-if="form.errors.category" class="text-red-500 text-[10px] mt-1 ml-1 font-bold italic">{{ form.errors.category }}</div>
                    </div>
                    <div class="min-w-0 md:col-span-5">
                        <label class="block text-[10px] font-bold text-indigo-900 uppercase tracking-widest mb-1.5 ml-1 dark:text-indigo-200">{{ activityFieldLabel }}</label>
                        <input v-model="form.name" type="text" placeholder="What needs to be done?" class="w-full text-sm border-slate-200 rounded-xl shadow-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition-all dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100">
                        <div v-if="form.errors.name" class="text-red-500 text-[10px] mt-1 ml-1 font-bold italic">{{ form.errors.name }}</div>
                    </div>
                    <div class="min-w-0 md:col-span-3">
                        <label class="block text-[10px] font-bold text-indigo-900 uppercase tracking-widest mb-1.5 ml-1 dark:text-indigo-200">Responsible</label>
                        <select v-model="form.assigned_to" class="w-full text-sm border-slate-200 rounded-xl shadow-sm pl-2 pr-7 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition-all dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100">
                            <option value="">Unassigned</option>
                            <option v-for="member in teamMembers" :key="member.id" :value="member.id">{{ member.name }}</option>
                        </select>
                        <div v-if="form.errors.assigned_to" class="text-red-500 text-[10px] mt-1 ml-1 font-bold italic">{{ form.errors.assigned_to }}</div>
                    </div>

                    <div class="md:col-span-12 mt-1 rounded-xl border border-slate-200 bg-white px-4 py-3 dark:border-slate-700 dark:bg-slate-900">
                        <h5 class="text-xs font-black uppercase tracking-widest text-slate-700 dark:text-slate-200">Planning and progress</h5>
                        <p class="mt-1 text-[11px] font-medium text-slate-400">Keep duration, dependencies, status, and timeline aligned in one section.</p>
                    </div>
                    <div :class="formMode === 'milestone' ? 'md:col-span-4' : 'md:col-span-2'">
                        <label class="block text-[10px] font-bold text-indigo-900 uppercase tracking-widest mb-1.5 ml-1 dark:text-indigo-200">Lead Time (Days)</label>
                        <input :value="isRolledUpActivity ? rolledUpLeadTime : form.lead_time_days"
                               @input="syncEndDateFromLeadTime($event.target.value)"
                               type="number" min="1"
                               :disabled="isRolledUpActivity"
                               class="w-full text-sm border-slate-200 rounded-xl shadow-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition-all disabled:bg-slate-100 disabled:text-slate-500 disabled:cursor-not-allowed dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 dark:disabled:bg-slate-800 dark:disabled:text-slate-400">
                        <div v-if="form.errors.lead_time_days" class="text-red-500 text-[10px] mt-1 ml-1 font-bold italic">{{ form.errors.lead_time_days }}</div>
                        <p v-if="isRolledUpActivity" class="mt-1 ml-1 text-[9px] font-semibold text-slate-500 dark:text-slate-400">
                            Summed from {{ subTasksOfEditingTask.length }} sub-task{{ subTasksOfEditingTask.length === 1 ? '' : 's' }} — edit those instead.
                        </p>
                        <p v-else-if="isEditing && project.day1_date" class="mt-1 ml-1 text-[9px] font-semibold text-emerald-600 dark:text-emerald-400">Saving will re-chain every row's dates from Day 1.</p>
                        <p v-else-if="isEditing" class="mt-1 ml-1 text-[9px] font-semibold text-amber-600 dark:text-amber-400">No Day 1 Date set on this project — dates won't auto-schedule.</p>
                    </div>
                    <div class="min-w-0 md:col-span-4" v-if="formMode !== 'milestone'">
                        <label class="block text-[10px] font-bold text-indigo-900 uppercase tracking-widest mb-1.5 ml-1 dark:text-indigo-200">Dependency (Requisite)</label>
                        <Autocomplete
                            :model-value="form.depends_on_task_id"
                            @update:model-value="value => form.depends_on_task_id = value"
                            :options="requisiteOptions"
                            size="sm"
                            placeholder="Previous row"
                        />
                        <p class="mt-1 ml-1 text-[9px] font-semibold text-slate-500 dark:text-slate-400">Leave empty to follow the row above.</p>
                        <div v-if="form.errors.depends_on_task_id" class="text-red-500 text-[10px] mt-1 ml-1 font-bold italic">{{ form.errors.depends_on_task_id }}</div>
                    </div>
                    <div class="md:col-span-2" v-if="formMode !== 'milestone'">
                        <label class="block text-[10px] font-bold text-indigo-900 uppercase tracking-widest mb-1.5 ml-1 dark:text-indigo-200">Can Run Parallel?</label>
                        <button type="button" @click="form.can_run_parallel = !form.can_run_parallel"
                                :title="form.can_run_parallel ? 'Starts off its requisite only — may overlap rows that are still running' : 'Waits for its requisite AND the row above it'"
                                class="h-[38px] w-full rounded-xl border text-xs font-black uppercase tracking-wider transition-colors"
                                :class="form.can_run_parallel
                                    ? 'border-emerald-300 bg-emerald-50 text-emerald-700 hover:bg-emerald-100 dark:border-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-300'
                                    : 'border-slate-200 bg-white text-slate-500 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300'">
                            {{ form.can_run_parallel ? 'Yes' : 'No' }}
                        </button>
                    </div>
                    <div :class="formMode === 'milestone'
                        ? (manualStatuses.length ? 'md:col-span-4' : 'md:col-span-8')
                        : (manualStatuses.length ? 'md:col-span-2' : 'md:col-span-4')">
                        <div class="flex items-center justify-between mb-1.5 ml-1">
                            <label class="block text-[10px] font-bold text-indigo-900 uppercase tracking-widest dark:text-indigo-200">Progress</label>
                            <button v-if="!isRolledUpActivity" type="button" @click="progressMode = progressMode === 'done' ? 'manual' : 'done'"
                                    class="text-[9px] font-bold text-indigo-500 hover:text-indigo-700 underline dark:text-indigo-300">
                                {{ progressMode === 'done' ? 'Use %' : 'Use Yes/No' }}
                            </button>
                        </div>
                        <div v-if="isRolledUpActivity"
                             class="flex h-[38px] items-center justify-center gap-2 rounded-xl border border-slate-200 bg-slate-100 dark:border-slate-700 dark:bg-slate-800">
                            <span class="text-xs font-bold text-slate-500 dark:text-slate-400">{{ form.task_progress }}% from sub-tasks</span>
                        </div>
                        <label v-else-if="progressMode === 'done'"
                               class="flex h-[38px] items-center justify-center gap-2 rounded-xl border border-slate-200 cursor-pointer dark:border-slate-700 dark:bg-slate-900">
                            <input type="checkbox" v-model="isTaskDone" class="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                            <span class="text-xs font-bold text-slate-700 dark:text-slate-200">{{ isTaskDone ? 'Done (100%)' : 'Not done' }}</span>
                        </label>
                        <input v-else v-model="form.task_progress" type="number" min="0" max="100" class="w-full text-sm border-slate-200 rounded-xl shadow-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition-all dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100">
                        <div v-if="form.errors.progress" class="text-red-500 text-[10px] mt-1 ml-1 font-bold italic">{{ form.errors.progress }}</div>
                    </div>
                    <!--
                        A flag beside the percentage, not a replacement for it. The
                        derived Pending/Ongoing/Done still comes from progress; this
                        says "and it is stuck", and clears itself at 100%.
                    -->
                    <div :class="formMode === 'milestone' ? 'md:col-span-4' : 'md:col-span-2'" v-if="manualStatuses.length">
                        <label class="block text-[10px] font-bold text-indigo-900 uppercase tracking-widest mb-1.5 ml-1 dark:text-indigo-200">Flag</label>
                        <Autocomplete
                            v-model="form.manual_status"
                            :options="manualStatusOptions"
                            placeholder="None"
                        />
                        <p class="mt-1 ml-1 text-[9px] font-semibold text-slate-500 dark:text-slate-400">
                            Shown on Overview &amp; Monitoring. Cleared when the row hits 100%.
                        </p>
                        <div v-if="form.errors.manual_status" class="text-red-500 text-[10px] mt-1 ml-1 font-bold italic">{{ form.errors.manual_status }}</div>
                    </div>
                    <div class="min-w-0 md:col-span-8">
                        <label class="block text-[10px] font-bold text-indigo-900 uppercase tracking-widest mb-1.5 ml-1 dark:text-indigo-200">Timeline</label>
                        <div class="flex items-center space-x-2">
                            <input v-model="form.start_date" @change="syncLeadTimeFromTimeline('start')" type="date" :disabled="isRolledUpActivity" class="w-full text-xs border-slate-200 rounded-xl shadow-sm focus:ring-2 focus:ring-indigo-500 disabled:bg-slate-100 disabled:text-slate-500 disabled:cursor-not-allowed dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 dark:disabled:bg-slate-800 dark:disabled:text-slate-400">
                            <span class="text-slate-400 dark:text-slate-300">to</span>
                            <input v-model="form.end_date" @change="syncLeadTimeFromTimeline('end')" type="date" :disabled="isRolledUpActivity" class="w-full text-xs border-slate-200 rounded-xl shadow-sm focus:ring-2 focus:ring-indigo-500 disabled:bg-slate-100 disabled:text-slate-500 disabled:cursor-not-allowed dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 dark:disabled:bg-slate-800 dark:disabled:text-slate-400">
                        </div>
                        <p v-if="isRolledUpActivity" class="mt-1 ml-1 text-[9px] font-semibold text-slate-500 dark:text-slate-400">
                            Spans its sub-tasks — set the dates on those.
                        </p>
                        <p v-else-if="isStartPinned" class="mt-1 ml-1 text-[9px] font-semibold text-indigo-600 dark:text-indigo-300">
                            Start Date is pinned — later rows chain from it.
                            <button type="button" @click="unpinStart" class="underline hover:text-indigo-800 dark:hover:text-indigo-200">Unpin</button>
                        </p>
                        <p v-else-if="isEditing" class="mt-1 ml-1 text-[9px] font-semibold text-slate-500 dark:text-slate-400">
                            Setting a Start Date pins this row and shifts every row after it.
                        </p>
                        <div v-if="form.errors.start_date || form.errors.end_date" class="text-red-500 text-[10px] mt-1 ml-1 font-bold italic">{{ form.errors.start_date || form.errors.end_date }}</div>
                    </div>
                    <div v-if="isEditing" class="min-w-0 md:col-span-8">
                        <label class="block text-[10px] font-bold text-rose-900 uppercase tracking-widest mb-1.5 ml-1 dark:text-rose-200">Actual (Reported)</label>
                        <div class="flex items-center space-x-2">
                            <input v-model="form.actual_start_date" type="date" :disabled="isRolledUpActivity" class="w-full text-xs border-slate-200 rounded-xl shadow-sm focus:ring-2 focus:ring-rose-500 disabled:bg-slate-100 disabled:text-slate-500 disabled:cursor-not-allowed dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 dark:disabled:bg-slate-800 dark:disabled:text-slate-400">
                            <span class="text-slate-400 dark:text-slate-300">to</span>
                            <input v-model="form.actual_end_date" type="date" :disabled="isRolledUpActivity" class="w-full text-xs border-slate-200 rounded-xl shadow-sm focus:ring-2 focus:ring-rose-500 disabled:bg-slate-100 disabled:text-slate-500 disabled:cursor-not-allowed dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 dark:disabled:bg-slate-800 dark:disabled:text-slate-400">
                        </div>
                        <p v-if="isRolledUpActivity" class="mt-1 ml-1 text-[9px] font-semibold text-slate-500 dark:text-slate-400">
                            Rolled up from its sub-tasks — report the actual dates on those.
                        </p>
                        <p v-else class="mt-1 ml-1 text-[9px] font-semibold text-slate-500 dark:text-slate-400">
                            When work really ran. Filled in automatically on first progress and at 100% — correct them here.
                            This is the hatched bar on the chart, so it may start before the plan.
                        </p>
                        <div v-if="form.errors.actual_start_date || form.errors.actual_end_date" class="text-red-500 text-[10px] mt-1 ml-1 font-bold italic">{{ form.errors.actual_start_date || form.errors.actual_end_date }}</div>
                    </div>
                    <div class="flex items-center space-x-2 self-end md:col-span-4">
                        <button type="button" @click="save" :disabled="isSaving" class="flex-1 bg-indigo-600 text-white font-bold py-2.5 rounded-xl hover:bg-indigo-700 shadow-md transition-all active:scale-95 disabled:opacity-50 text-sm whitespace-nowrap">
                            {{ isSaving ? 'Saving…' : saveButtonLabel }}
                        </button>
                        <button type="button" @click="close" :disabled="isSaving" class="flex-1 px-3 py-2.5 bg-white text-slate-500 font-bold border border-slate-200 rounded-xl hover:bg-slate-50 transition-all text-sm whitespace-nowrap disabled:cursor-wait disabled:opacity-40 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800">
                            Cancel
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </Modal>
</template>
