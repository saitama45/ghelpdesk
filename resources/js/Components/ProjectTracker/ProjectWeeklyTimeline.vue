<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue';
import {
    CalendarDaysIcon,
    ChevronLeftIcon,
    ChevronRightIcon,
    ChevronDownIcon,
    ArrowTrendingUpIcon,
    ArrowTrendingDownIcon,
    ClockIcon,
    CheckCircleIcon,
    ExclamationTriangleIcon,
    FunnelIcon,
    ArrowRightIcon,
    MagnifyingGlassIcon,
    ChartBarIcon,
    ViewColumnsIcon,
    BuildingOfficeIcon,
    SparklesIcon,
    FlagIcon,
    CheckIcon,
    PencilSquareIcon,
    PlusIcon,
    XMarkIcon,
} from '@heroicons/vue/24/outline';
import { router, useForm } from '@inertiajs/vue3';
import { useToast } from '@/Composables/useToast.js';
import { useConfirm } from '@/Composables/useConfirm.js';
import { canonicalDepartment, sameDepartment, uniqueDepartmentNames } from '@/Composables/useDepartmentNames.js';
import Autocomplete from '@/Components/Autocomplete.vue';
import ProjectTaskStatusPill from './ProjectTaskStatusPill.vue';

const props = defineProps({
    project: { type: Object, required: true },
    users: { type: Array, default: () => [] },
    holidays: { type: Array, default: () => [] },
    taskListTargets: { type: Object, default: () => ({}) },
    // Project managers (creator/admin) may edit every row and all structure.
    canManage: { type: Boolean, default: false },
    // The viewer's user id — non-managers may only edit rows assigned to them.
    currentUserId: { type: [Number, String], default: null },
    // Manual states a row can be flagged with (Blocked, For Approval …).
    manualStatuses: { type: Array, default: () => [] },
    // The /departments table — the single source for how a department is spelled.
    departments: { type: Array, default: () => [] },
});

const emit = defineEmits(['open-department', 'open-gantt']);

const { error: toastError } = useToast();
const { confirm: confirmAction } = useConfirm();

// This view reads and writes the SAME project_tasks rows the Gantt chart
// does — there is no separate "weekly" data store. Any progress change made
// here is persisted through the identical projects-tasks.update endpoint the
// Gantt uses, so a reload of either tab always shows the other's edits.
const missingTaskListTargets = computed(() => props.taskListTargets?.missing || []);

const ensureTaskListBoards = async () => {
    if (missingTaskListTargets.value.length === 0) {
        return true;
    }

    return await confirmAction({
        title: 'Auto-create Monthly Board',
        message: `This will automatically create ${missingTaskListTargets.value.length} monthly task board${missingTaskListTargets.value.length === 1 ? '' : 's'} for this project sync.`,
        confirmLabel: 'Create and Sync',
        variant: 'primary',
    });
};

// A non-manager may only edit the activity / sub-task assigned to them; managers
// may edit anything — identical rule to the Gantt chart.
const canEditTask = (task) => {
    if (props.canManage) return true;
    if (!props.currentUserId || !task) return false;
    return Number(task.assigned_to) === Number(props.currentUserId);
};

/* ---------------------------------------------------------------- dark mode */
const isDark = ref(false);
let observer = null;

const syncDark = () => {
    isDark.value = document.documentElement.classList.contains('dark');
};

onMounted(() => {
    syncDark();
    observer = new MutationObserver(syncDark);
    observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
});

onUnmounted(() => observer?.disconnect());

/* ----------------------------------------------------------------- local tasks */
// Local working copy of the project's tasks, kept in sync with the server.
const localTasks = ref([]);

const cloneTasks = (tasks = []) => {
    return (tasks || []).map(t => ({
        ...t,
        subTasks: (t.subTasks || []).map(st => ({ ...st }))
    }));
};

// The last progress value actually SAVED per task, seeded from the server on
// every rebuild. Kept separate from task.progress, which the slider's live
// drag preview overwrites on every @input tick before @change ever fires —
// comparing a "did it change" check against task.progress itself would always
// see "no change" (it was already written) and silently skip the save.
const lastSavedProgress = new Map();

const seedLastSavedProgress = (tasks) => {
    (tasks || []).forEach(t => {
        lastSavedProgress.set(t.id, Number(t.progress) || 0);
        (t.subTasks || []).forEach(st => lastSavedProgress.set(st.id, Number(st.progress) || 0));
    });
};

// Rebuilds from props.project.tasks whenever the page reloads — including
// after this view (or the Gantt tab) persists a change — so both stay
// perfectly mirrored off the one source of truth.
watch(() => props.project.tasks, (tasks) => {
    localTasks.value = cloneTasks(tasks);
    seedLastSavedProgress(tasks);
}, { immediate: true, deep: true });

/* ------------------------------------------------------ full task edit panel */
// Same field set the Gantt chart edits (category, name, responsible, lead
// time, dependency, can-run-parallel, progress, flag, start/end dates), laid
// out here on a per-week basis and saved through the identical
// projects-tasks.update endpoint — so anything changed here shows up on the
// Gantt chart too, and vice versa.
const isEditingTask = ref(false);
const editingTaskId = ref(null);
const progressMode = ref('done');

const editForm = useForm({
    category: '',
    name: '',
    assigned_to: '',
    status: 'Pending',
    manual_status: '',
    task_progress: 0,
    start_date: '',
    end_date: '',
    lead_time_days: 1,
    depends_on_task_id: null,
    can_run_parallel: false,
    unpin_start: false,
    milestone_order: null,
    order: null,
});

const isTaskDone = computed({
    get: () => Number(editForm.task_progress) >= 100,
    set: (done) => { editForm.task_progress = done ? 100 : 0; },
});

watch(() => editForm.task_progress, (newProgress) => {
    if (newProgress >= 100) editForm.status = 'Done';
    else if (newProgress > 0) editForm.status = 'Ongoing';
    else editForm.status = 'Pending';
});

const projectTeamMembers = computed(() => {
    const team = props.project.teamMembers || props.project.team_members || [];
    return team.map(m => m.user
        ? { id: m.user.id, name: m.user.name }
        : { id: m.external_name, name: m.external_name });
});

// "None" clears the flag; Autocomplete needs it as an explicit option.
const manualStatusOptions = computed(() => [
    { label: 'None', value: '' },
    ...(props.manualStatuses || []).map(status => ({ label: status, value: status })),
]);

const allFlatTasksForEdit = computed(() => {
    const list = [];
    localTasks.value.forEach(task => {
        list.push(task);
        (task.subTasks || []).forEach(st => list.push(st));
    });
    return list;
});

const editingTask = computed(() => {
    if (!isEditingTask.value || !editingTaskId.value) return null;
    return allFlatTasksForEdit.value.find(task => Number(task.id) === Number(editingTaskId.value)) || null;
});

const subTasksOfEditingTask = computed(() => {
    if (!isEditingTask.value || !editingTaskId.value) return [];
    return localTasks.value.filter(task => Number(task.parent_task_id) === Number(editingTaskId.value));
});

// Editing an activity that owns sub-tasks: its lead time, progress and
// timeline are rolled up from them — same rule as the Gantt chart.
const isRolledUpActivity = computed(() => !editingTask.value?.parent_task_id && subTasksOfEditingTask.value.length > 0);

const rolledUpLeadTime = computed(() => {
    return subTasksOfEditingTask.value.reduce((sum, st) => sum + (Number(st.lead_time_days) || 0), 0);
});

// Every other row in the plan, so a requisite can point anywhere.
const requisiteOptions = computed(() => {
    const editingId = editingTaskId.value;
    return allFlatTasksForEdit.value
        .filter(row => row.id !== editingId)
        .map(row => ({
            value: row.id,
            label: `${row.parent_task_id ? '↳ ' : ''}${row.name} · ${row.category || 'General'}`,
        }));
});

const isStartPinned = computed(() => Boolean(editingTask.value?.start_anchor_date) && !editForm.unpin_start);

const unpinStart = () => {
    editForm.unpin_start = true;
    editForm.start_date = '';
    editForm.end_date = '';
};

// 'edit' patches an existing row (PUT); 'add' creates a new one (POST) — same
// panel, same field set, just a different verb. Mirrors Gantt's single form
// serving both openActivityForm() and editTask().
const taskFormMode = ref('edit');

const resetEditForm = () => {
    editForm.clearErrors();
    editForm.category = '';
    editForm.name = '';
    editForm.assigned_to = '';
    editForm.status = 'Pending';
    editForm.manual_status = '';
    editForm.task_progress = 0;
    editForm.start_date = '';
    editForm.end_date = '';
    editForm.lead_time_days = 1;
    editForm.depends_on_task_id = null;
    editForm.can_run_parallel = false;
    editForm.unpin_start = false;
    editForm.milestone_order = null;
    editForm.order = null;
    progressMode.value = 'done';
};

// Next sort position for a new activity under `category`, or for a new
// sub-task under `parentTaskId` — same maths as Gantt's getNextOrder(), so a
// row added here lands at the end of its milestone/parent instead of null.
const getNextOrder = (category, parentTaskId = null) => {
    const normalizedParentId = parentTaskId ? Number(parentTaskId) : null;
    const siblings = allFlatTasksForEdit.value.filter(task => {
        const taskParentId = task.parent_task_id ? Number(task.parent_task_id) : null;
        if (taskParentId !== normalizedParentId) return false;
        if (normalizedParentId) return true;
        return (task.category || 'General') === (category || 'General');
    });
    if (!siblings.length) return 1;
    return Math.max(...siblings.map(task => Number(task.order) || 0)) + 1;
};

const milestoneOrderFor = (category) => {
    const normalizedCategory = category || 'General';
    const existing = localTasks.value
        .filter(task => !task.parent_task_id && (task.category || 'General') === normalizedCategory)
        .map(task => Number(task.milestone_order))
        .filter(Number.isFinite);
    if (existing.length) return Math.min(...existing);

    const orders = localTasks.value
        .filter(task => !task.parent_task_id)
        .map(task => Number(task.milestone_order))
        .filter(Number.isFinite);
    return orders.length ? Math.max(...orders) + 1 : 1;
};

const editTask = (task) => {
    if (!canEditTask(task)) return;
    taskFormMode.value = 'edit';
    isEditingTask.value = true;
    editingTaskId.value = task.id;
    editForm.clearErrors();
    editForm.category = task.category || '';
    editForm.name = task.name || '';
    editForm.assigned_to = task.assigned_to || task.external_assignment || '';
    editForm.status = task.status || 'Pending';
    editForm.manual_status = task.manual_status || '';
    editForm.task_progress = Number(task.progress) || 0;
    editForm.start_date = task.start_date ? String(task.start_date).split('T')[0] : '';
    editForm.end_date = task.end_date ? String(task.end_date).split('T')[0] : '';
    editForm.lead_time_days = task.lead_time_days || 1;
    editForm.depends_on_task_id = task.depends_on_task_id || null;
    editForm.can_run_parallel = Boolean(task.can_run_parallel);
    editForm.unpin_start = false;
    // Carry the row's existing sort position through unchanged — leaving
    // these null (as resetEditForm() does) submits `order: null` on every
    // save, which fails the backend's `numeric` rule and silently blocks
    // the update.
    editForm.milestone_order = task.milestone_order ?? null;
    editForm.order = task.order ?? null;
    progressMode.value = 'done';
};

// Adding a brand-new activity is a management action — same rule as Gantt's
// "+ Add Activity" per milestone. Pre-fills the Milestone field with the
// currently selected week's milestone filter (if any) so a manager working a
// specific milestone doesn't have to retype it.
// Set when adding a SUB-task (the "+" on a top-level row) — null for a plain
// top-level "+ Add Activity". Mirrors Gantt's activeParentTask.
const addParentTask = ref(null);

const openAddTaskForm = (category = null) => {
    if (!props.canManage) return;
    taskFormMode.value = 'add';
    editingTaskId.value = null;
    addParentTask.value = null;
    resetEditForm();
    editForm.category = category || selectedMilestoneFilter.value || '';
    editForm.milestone_order = milestoneOrderFor(editForm.category);
    editForm.order = getNextOrder(editForm.category);
    isEditingTask.value = true;
};

// The "+" on a top-level activity row — same rule as Gantt's
// openSubTaskForm(): inherits the parent's milestone, responsible, and dates.
const openAddSubtaskForm = (parentTask) => {
    if (!props.canManage || !parentTask || parentTask.parent_task_id) return;
    taskFormMode.value = 'add-subtask';
    editingTaskId.value = null;
    addParentTask.value = parentTask;
    resetEditForm();
    editForm.category = parentTask.category || 'General';
    editForm.assigned_to = parentTask.assigned_to || parentTask.external_assignment || '';
    editForm.start_date = parentTask.start_date ? String(parentTask.start_date).split('T')[0] : '';
    editForm.end_date = parentTask.end_date ? String(parentTask.end_date).split('T')[0] : '';
    editForm.milestone_order = parentTask.milestone_order ?? milestoneOrderFor(editForm.category);
    editForm.order = getNextOrder(editForm.category, parentTask.id);
    isEditingTask.value = true;
};

const closeEditForm = () => {
    isEditingTask.value = false;
    editingTaskId.value = null;
    taskFormMode.value = 'edit';
    addParentTask.value = null;
    editForm.clearErrors();
};

const saveEditedTask = async () => {
    const syncOk = await ensureTaskListBoards();
    if (!syncOk) return;

    if (taskFormMode.value === 'add' || taskFormMode.value === 'add-subtask') {
        editForm.transform((data) => ({
            ...data,
            project_id: props.project.id,
            parent_task_id: addParentTask.value?.id || null,
            progress: data.task_progress,
            auto_create_monthly_boards: true,
        })).post(route('projects-tasks.store', { tab: 'weekly-timeline' }), {
            preserveScroll: true,
            onSuccess: () => closeEditForm(),
        });
        return;
    }

    editForm.transform((data) => {
        // Sort position isn't editable from this panel — only Add mode
        // computes it. Sending a null order/milestone_order (this panel's
        // reset default) fails the backend's `numeric` rule and silently
        // blocks every edit, so drop them here entirely.
        const { order, milestone_order, ...rest } = data;
        return {
            ...rest,
            progress: rest.task_progress,
            auto_create_monthly_boards: true,
        };
    }).put(route('projects-tasks.update', { projects_task: editingTaskId.value, tab: 'weekly-timeline' }), {
        preserveScroll: true,
        onSuccess: () => closeEditForm(),
    });
};

/* ----------------------------------------------------------------- date helpers */
const parseLocalDate = (dateString) => {
    if (!dateString) return null;
    const datePart = String(dateString).split('T')[0];
    const parts = datePart.split('-').map(Number);
    if (parts.length < 3 || parts.some(isNaN)) return null;
    return new Date(parts[0], parts[1] - 1, parts[2]);
};

const formatShortDate = (date) => {
    if (!date) return '-';
    return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
};

const formatFullDate = (date) => {
    if (!date) return 'TBD';
    return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
};

const getDayOfWeekIndex = (date) => {
    // 0 = Mon, 6 = Sun
    const day = date.getDay();
    return day === 0 ? 6 : day - 1;
};

/* -------------------------------------------------------------- project weeks */
// Determine the earliest anchor date and latest end date
const timelineBounds = computed(() => {
    let minDate = parseLocalDate(props.project.day1_date);
    let maxDate = parseLocalDate(props.project.target_go_live);

    const checkTasks = (tasks) => {
        tasks.forEach(t => {
            if (t.start_date) {
                const s = parseLocalDate(t.start_date);
                if (s && (!minDate || s < minDate)) minDate = s;
            }
            if (t.end_date) {
                const e = parseLocalDate(t.end_date);
                if (e && (!maxDate || e > maxDate)) maxDate = e;
            }
        });
    };

    checkTasks(localTasks.value);

    // Fallbacks if project has no dates yet
    if (!minDate) {
        minDate = new Date();
    }
    if (!maxDate || maxDate <= minDate) {
        maxDate = new Date(minDate);
        maxDate.setDate(maxDate.getDate() + 42); // 6 weeks default
    }

    // Align start to the Monday of the starting week
    const startMonday = new Date(minDate);
    const dayIdx = getDayOfWeekIndex(startMonday);
    startMonday.setDate(startMonday.getDate() - dayIdx);
    startMonday.setHours(0, 0, 0, 0);

    // Align end to Sunday of the ending week
    const endSunday = new Date(maxDate);
    const endDayIdx = getDayOfWeekIndex(endSunday);
    endSunday.setDate(endSunday.getDate() + (6 - endDayIdx));
    endSunday.setHours(23, 59, 59, 999);

    return { start: startMonday, end: endSunday };
});

// Generate week buckets
const projectWeeks = computed(() => {
    const weeks = [];
    const current = new Date(timelineBounds.value.start);
    const end = new Date(timelineBounds.value.end);
    let weekIndex = 1;
    const now = new Date();

    while (current <= end) {
        const weekStart = new Date(current);
        weekStart.setHours(0, 0, 0, 0);

        const weekEnd = new Date(current);
        weekEnd.setDate(weekEnd.getDate() + 6);
        weekEnd.setHours(23, 59, 59, 999);

        const isCurrentWeek = now >= weekStart && now <= weekEnd;
        const isPastWeek = now > weekEnd;
        const isFutureWeek = now < weekStart;

        weeks.push({
            index: weekIndex,
            label: `Week ${weekIndex}`,
            start: weekStart,
            end: weekEnd,
            formattedRange: `${formatShortDate(weekStart)} – ${formatShortDate(weekEnd)}`,
            isCurrentWeek,
            isPastWeek,
            isFutureWeek,
        });

        current.setDate(current.getDate() + 7);
        weekIndex++;
    }

    return weeks;
});

// Default to current week or week 1
const currentProjectWeekIndex = computed(() => {
    const found = projectWeeks.value.find(w => w.isCurrentWeek);
    return found ? found.index : 1;
});

const selectedWeekIndex = ref(1);

// Initialize selected week on load
watch(projectWeeks, (weeks) => {
    if (weeks.length && !weeks.some(w => w.index === selectedWeekIndex.value)) {
        selectedWeekIndex.value = currentProjectWeekIndex.value || 1;
    }
}, { immediate: true });

const selectedWeek = computed(() => {
    return projectWeeks.value.find(w => w.index === selectedWeekIndex.value) || projectWeeks.value[0] || null;
});

const goToPrevWeek = () => {
    if (selectedWeekIndex.value > 1) {
        selectedWeekIndex.value--;
    }
};

const goToNextWeek = () => {
    if (selectedWeekIndex.value < projectWeeks.value.length) {
        selectedWeekIndex.value++;
    }
};

const goToCurrentWeek = () => {
    selectedWeekIndex.value = currentProjectWeekIndex.value;
};

/* ------------------------------------------------------------- task helpers */
// Every row of the plan in one flat pool — the controller sends sub-tasks as
// top-level rows, but a nested `subTasks` array is tolerated too (deduped by id).
const taskPool = computed(() => {
    const byId = new Map();
    localTasks.value.forEach(task => {
        byId.set(Number(task.id), task);
        (task.subTasks || []).forEach(st => {
            if (!byId.has(Number(st.id))) byId.set(Number(st.id), st);
        });
    });
    return [...byId.values()];
});

// Identical to the Gantt chart's sortTasks(): position first, id as tiebreak.
const sortByOrderThenId = (tasks = []) => {
    return [...tasks].sort((a, b) => {
        const aOrder = Number.isFinite(Number(a.order)) ? Number(a.order) : Number.MAX_SAFE_INTEGER;
        const bOrder = Number.isFinite(Number(b.order)) ? Number(b.order) : Number.MAX_SAFE_INTEGER;

        if (aOrder !== bOrder) return aOrder - bOrder;
        return Number(a.id) - Number(b.id);
    });
};

/**
 * The plan in the Gantt chart's row order — this view must read top-to-bottom
 * exactly like the Gantt tab, since both render the same project_tasks rows.
 *
 * Milestones are ordered by milestone_order (then by the position of their
 * first activity), activities inside a milestone by `order`, and every
 * activity is immediately followed by its own sub-tasks. A sub-task belongs to
 * its PARENT's milestone, not to whatever its own `category` column says —
 * again matching the Gantt, which nests sub-tasks under the parent row.
 *
 * Row objects are passed through by reference (never spread into copies) so
 * the progress slider still writes to the live localTasks entry.
 */
const orderedTaskGroups = computed(() => {
    const pool = taskPool.value;
    if (!pool.length) return [];

    const byId = new Map(pool.map(task => [Number(task.id), task]));
    const childrenByParent = new Map();

    pool.forEach(task => {
        const parentId = task.parent_task_id ? Number(task.parent_task_id) : null;
        if (!parentId || !byId.has(parentId)) return;
        if (!childrenByParent.has(parentId)) childrenByParent.set(parentId, []);
        childrenByParent.get(parentId).push(task);
    });

    const groups = new Map();

    pool.forEach(task => {
        const parentId = task.parent_task_id ? Number(task.parent_task_id) : null;
        if (parentId && byId.has(parentId)) return; // placed under its parent below

        const category = task.category || 'General';
        if (!groups.has(category)) groups.set(category, []);
        groups.get(category).push(task);
    });

    return [...groups.entries()]
        .map(([category, activities]) => {
            const ordered = sortByOrderThenId(activities);
            const tasks = [];

            ordered.forEach(activity => {
                tasks.push(activity);
                sortByOrderThenId(childrenByParent.get(Number(activity.id)) || [])
                    .forEach(child => tasks.push(child));
            });

            const milestoneOrder = Math.min(...ordered.map(act => (
                Number.isFinite(Number(act.milestone_order)) ? Number(act.milestone_order) : Number.MAX_SAFE_INTEGER
            )));
            const firstOrder = Math.min(...ordered.map(act => Number(act.order) || 0));

            return { category, tasks, milestoneOrder, firstOrder, firstId: Number(ordered[0]?.id) || 0 };
        })
        .sort((a, b) => {
            if (a.milestoneOrder !== b.milestoneOrder) return a.milestoneOrder - b.milestoneOrder;
            if (a.firstOrder !== b.firstOrder) return a.firstOrder - b.firstOrder;
            return a.firstId - b.firstId;
        });
});

const allFlatTasks = computed(() => orderedTaskGroups.value.flatMap(group => group.tasks));

// A sub-task is filtered by the milestone it is DISPLAYED under (its parent's),
// so the milestone dropdown selects the same rows the Gantt would show.
const groupCategoryByTaskId = computed(() => {
    const map = new Map();
    orderedTaskGroups.value.forEach(group => {
        group.tasks.forEach(task => map.set(Number(task.id), group.category));
    });
    return map;
});

const taskMilestone = (task) => groupCategoryByTaskId.value.get(Number(task?.id)) || task?.category || 'General';

const subTaskCount = (task) => {
    const nestedCount = task?.subTasks?.length || 0;
    if (nestedCount > 0) return nestedCount;

    return allFlatTasks.value.filter(candidate => Number(candidate.parent_task_id) === Number(task?.id)).length;
};

const isRolledUpProgress = (task) => !task?.parent_task_id && subTaskCount(task) > 0;
const canEditTaskProgress = (task) => canEditTask(task) && !isRolledUpProgress(task);

const taskDepartment = (task) => {
    const assignee = task.assigned_to
        ? props.users.find(user => String(user.id) === String(task.assigned_to))
        : null;

    const name = (assignee?.department || '').trim() || (task.department || '').trim() || '';

    // Canonicalised at the single place a department is read from, so the filter
    // dropdown and the row labels agree on one spelling — see the Gantt, which
    // showed the same department twice before this.
    return canonicalDepartment(name, props.departments);
};

const getAssigneeName = (task) => {
    if (!task.assigned_to && !task.external_assignment) return 'Unassigned';
    if (task.external_assignment) return task.external_assignment;
    const user = props.users.find(u => String(u.id) === String(task.assigned_to));
    return user ? user.name : 'Unknown';
};

/* -------------------------------------------------------- filters & view mode */
const activeViewMode = ref('s-curve'); // 's-curve' | 'schedule-matrix' | 'departments'
const selectedDepartmentFilter = ref('');
const selectedMilestoneFilter = ref('');
const selectedStatusFilter = ref('');
const searchQuery = ref('');

// One entry per department: the underlying free-text values disagree on case,
// so a plain Set would offer the same department more than once.
const availableDepartments = computed(() =>
    uniqueDepartmentNames(allFlatTasks.value.map(taskDepartment).filter(Boolean), props.departments));

// Listed in the Gantt's milestone order, not in whatever order the rows arrived.
const availableMilestones = computed(() => orderedTaskGroups.value.map(group => group.category));

/* --------------------------------------------------------- task-in-week logic */
const isTaskActiveInWeek = (task, week) => {
    if (!week) return false;
    const s = parseLocalDate(task.start_date);
    const e = parseLocalDate(task.end_date);
    if (!s || !e) return false;

    return s <= week.end && e >= week.start;
};

const getTaskWeekStatus = (task, week) => {
    const progress = Number(task.progress) || 0;
    const isDone = progress >= 100 || task.status === 'Done';
    const s = parseLocalDate(task.start_date);
    const e = parseLocalDate(task.end_date);

    if (isDone) return 'completed';
    if (task.manual_status?.toLowerCase() === 'blocked') return 'blocked';
    if (week && e && e < week.start && !isDone) return 'overdue';
    if (progress > 0) return 'in_progress';
    return 'pending';
};

// Does this row survive the week + the four filter controls?
const matchesWeekFilters = (task, week) => {
    // Filter by week active
    if (!isTaskActiveInWeek(task, week)) return false;

    // Department filter
    if (selectedDepartmentFilter.value) {
        if (!sameDepartment(taskDepartment(task), selectedDepartmentFilter.value)) return false;
    }

    // Milestone filter
    if (selectedMilestoneFilter.value) {
        if (taskMilestone(task) !== selectedMilestoneFilter.value) return false;
    }

    // Status filter
    if (selectedStatusFilter.value) {
        const weekStatus = getTaskWeekStatus(task, week);
        if (selectedStatusFilter.value === 'done' && weekStatus !== 'completed') return false;
        if (selectedStatusFilter.value === 'ongoing' && weekStatus !== 'in_progress') return false;
        if (selectedStatusFilter.value === 'pending' && weekStatus !== 'pending') return false;
        if (selectedStatusFilter.value === 'overdue' && weekStatus !== 'overdue') return false;
        if (selectedStatusFilter.value === 'blocked' && weekStatus !== 'blocked') return false;
    }

    // Search query
    if (searchQuery.value) {
        const q = searchQuery.value.toLowerCase();
        const name = (task.name || '').toLowerCase();
        const wbs = (task.wbs || '').toLowerCase();
        const dept = taskDepartment(task).toLowerCase();
        const assignee = getAssigneeName(task).toLowerCase();
        if (!name.includes(q) && !wbs.includes(q) && !dept.includes(q) && !assignee.includes(q)) return false;
    }

    return true;
};

// Grouped by milestone so each section can carry its own "+ Add Activity"
// button — same organisation, and the same row order, as the Gantt chart's
// per-milestone headers. Filtering only removes rows; it never reshuffles the
// survivors, so the two tabs always read top-to-bottom the same way.
const groupedTasksForSelectedWeek = computed(() => {
    const week = selectedWeek.value;
    if (!week) return [];

    return orderedTaskGroups.value
        .map(group => ({
            category: group.category,
            tasks: group.tasks.filter(task => matchesWeekFilters(task, week)),
        }))
        .filter(group => group.tasks.length > 0);
});

// Filtered tasks for the selected week, flattened in the same display order.
const tasksForSelectedWeek = computed(() => groupedTasksForSelectedWeek.value.flatMap(group => group.tasks));

/* ---------------------------------------------------- day-by-day week schedule */
const weekDays = computed(() => {
    const week = selectedWeek.value;
    if (!week) return [];

    const days = [];
    const dayNames = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    const current = new Date(week.start);
    const today = new Date();
    const todayStr = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`;

    for (let i = 0; i < 7; i++) {
        const d = new Date(current);
        const dateStr = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
        const isToday = dateStr === todayStr;
        const isWeekend = i === 5 || i === 6;

        days.push({
            index: i,
            name: dayNames[i],
            date: d,
            formattedDate: formatShortDate(d),
            dayNumber: d.getDate(),
            isToday,
            isWeekend,
        });

        current.setDate(current.getDate() + 1);
    }

    return days;
});

const getTaskDaySpan = (task, week) => {
    if (!week) return { startCol: 1, span: 1, active: false };
    const s = parseLocalDate(task.start_date);
    const e = parseLocalDate(task.end_date);
    if (!s || !e) return { startCol: 1, span: 1, active: false };

    let startDayIdx = 0;
    if (s > week.start) {
        startDayIdx = Math.max(0, Math.min(6, Math.floor((s - week.start) / (1000 * 60 * 60 * 24))));
    }

    let endDayIdx = 6;
    if (e < week.end) {
        endDayIdx = Math.max(0, Math.min(6, Math.floor((e - week.start) / (1000 * 60 * 60 * 24))));
    }

    const span = Math.max(1, endDayIdx - startDayIdx + 1);
    return {
        startCol: startDayIdx + 1,
        span,
        active: true,
        startsThisWeek: s >= week.start && s <= week.end,
        endsThisWeek: e >= week.start && e <= week.end,
    };
};

/* ----------------------------------------------------- S-Curve & Velocity Math */
// For each week, calculate Planned Progress % and Actual Progress %
const weeklyCurveData = computed(() => {
    const weeks = projectWeeks.value;
    const totalTasks = allFlatTasks.value.length || 1;
    const tasks = allFlatTasks.value;

    const plannedValues = [];
    const actualValues = [];

    const now = new Date();

    weeks.forEach((week) => {
        let plannedCompletionSum = 0;
        let actualCompletionSum = 0;

        tasks.forEach(t => {
            const s = parseLocalDate(t.start_date);
            const e = parseLocalDate(t.end_date);
            const prog = Number(t.progress) || 0;

            if (s && e) {
                // Planned calculation
                if (week.end >= e) {
                    plannedCompletionSum += 100;
                } else if (week.end >= s) {
                    const totalDuration = Math.max(1, e - s);
                    const elapsed = Math.max(0, week.end - s);
                    const ratio = Math.min(1, elapsed / totalDuration);
                    plannedCompletionSum += (ratio * 100);
                }

                // Actual calculation (only up to current week, or simulated)
                if (week.start <= now || week.isCurrentWeek) {
                    actualCompletionSum += prog;
                }
            } else {
                actualCompletionSum += prog;
                plannedCompletionSum += (t.status === 'Done' ? 100 : 0);
            }
        });

        const plannedPercent = Math.round(plannedCompletionSum / totalTasks);
        const actualPercent = week.start <= now || week.isCurrentWeek
            ? Math.min(100, Math.round(actualCompletionSum / totalTasks))
            : null;

        plannedValues.push(plannedPercent);
        actualValues.push(actualPercent);
    });

    return {
        planned: plannedValues,
        actual: actualValues,
    };
});

/* ------------------------------------------------------- SVG Geometry & Curves */
const SVG_W = 1000;
const SVG_H = 340;
const SVG_PAD = { top: 24, right: 36, bottom: 44, left: 56 };
const plotW = SVG_W - SVG_PAD.left - SVG_PAD.right;
const plotH = SVG_H - SVG_PAD.top - SVG_PAD.bottom;

const xAtWeek = (index, total = projectWeeks.value.length) => {
    if (total <= 1) return SVG_PAD.left + plotW / 2;
    return SVG_PAD.left + ((index - 1) * plotW) / (total - 1);
};

const yAtVal = (val) => {
    return SVG_PAD.top + ((100 - val) / 100) * plotH;
};

const smoothBezierPath = (points) => {
    if (!points.length) return '';
    if (points.length === 1) return `M${points[0].x},${points[0].y}`;

    let d = `M${points[0].x},${points[0].y}`;
    const tension = 0.35;

    for (let i = 0; i < points.length - 1; i++) {
        const p0 = points[i - 1] || points[i];
        const p1 = points[i];
        const p2 = points[i + 1];
        const p3 = points[i + 2] || p2;

        const c1x = p1.x + ((p2.x - p0.x) / 6) * tension * 2;
        const c1y = Math.max(SVG_PAD.top, Math.min(yAtVal(0), p1.y + ((p2.y - p0.y) / 6) * tension * 2));
        const c2x = p2.x - ((p3.x - p1.x) / 6) * tension * 2;
        const c2y = Math.max(SVG_PAD.top, Math.min(yAtVal(0), p2.y - ((p3.y - p1.y) / 6) * tension * 2));

        d += ` C${c1x},${c1y} ${c2x},${c2y} ${p2.x},${p2.y}`;
    }

    return d;
};

const plannedPathD = computed(() => {
    const count = projectWeeks.value.length;
    const points = weeklyCurveData.value.planned.map((val, i) => ({
        x: xAtWeek(i + 1, count),
        y: yAtVal(val),
    }));
    return smoothBezierPath(points);
});

const actualPathD = computed(() => {
    const count = projectWeeks.value.length;
    const points = [];
    weeklyCurveData.value.actual.forEach((val, i) => {
        if (val !== null && val !== undefined) {
            points.push({
                x: xAtWeek(i + 1, count),
                y: yAtVal(val),
            });
        }
    });
    return smoothBezierPath(points);
});

const actualAreaPathD = computed(() => {
    const count = projectWeeks.value.length;
    const points = [];
    weeklyCurveData.value.actual.forEach((val, i) => {
        if (val !== null && val !== undefined) {
            points.push({
                x: xAtWeek(i + 1, count),
                y: yAtVal(val),
            });
        }
    });
    if (!points.length) return '';
    const curve = smoothBezierPath(points);
    const first = points[0];
    const last = points[points.length - 1];
    const baseY = yAtVal(0);
    return `${curve} L${last.x},${baseY} L${first.x},${baseY} Z`;
});

/* ---------------------------------------------------- Milestone markers on chart */
const milestonePins = computed(() => {
    const pins = [];
    const weeks = projectWeeks.value;
    if (!weeks.length) return pins;

    const milestones = [
        { label: 'Day 1', dateStr: props.project.day1_date, color: '#3b82f6' },
        { label: 'Store T.O.', dateStr: props.project.turn_over_date, color: '#10b981' },
        { label: 'Training', dateStr: props.project.training_date, color: '#8b5cf6' },
        { label: 'Testing', dateStr: props.project.testing_date, color: '#f59e0b' },
        { label: 'Mock Service', dateStr: props.project.mock_service_date, color: '#ec4899' },
        { label: 'Franchisee T.O.', dateStr: props.project.turn_over_to_franchisee_date, color: '#06b6d4' },
        { label: 'Go-Live', dateStr: props.project.target_go_live, color: '#ef4444' },
    ];

    milestones.forEach(m => {
        if (!m.dateStr) return;
        const d = parseLocalDate(m.dateStr);
        if (!d) return;

        // Find which week this milestone falls into
        const weekIdx = weeks.findIndex(w => d >= w.start && d <= w.end);
        if (weekIdx !== -1) {
            const week = weeks[weekIdx];
            const ratio = (d - week.start) / (week.end - week.start);
            const x1 = xAtWeek(week.index, weeks.length);
            const x2 = weekIdx < weeks.length - 1 ? xAtWeek(week.index + 1, weeks.length) : x1;
            const x = x1 + (x2 - x1) * ratio;

            pins.push({
                ...m,
                date: d,
                formattedDate: formatShortDate(d),
                x,
                weekNumber: week.index,
            });
        }
    });

    return pins;
});

/* ---------------------------------------------------------- Hover Tooltip State */
const hoverWeekIndex = ref(null);
const svgPlotRef = ref(null);

const onSvgMouseMove = (event) => {
    if (!svgPlotRef.value || !projectWeeks.value.length) return;
    const rect = svgPlotRef.value.getBoundingClientRect();
    const px = ((event.clientX - rect.left) / rect.width) * SVG_W;

    if (px < SVG_PAD.left - 15 || px > SVG_W - SVG_PAD.right + 15) {
        hoverWeekIndex.value = null;
        return;
    }

    let closest = 1;
    let closestDist = Infinity;
    projectWeeks.value.forEach(w => {
        const x = xAtWeek(w.index, projectWeeks.value.length);
        const dist = Math.abs(x - px);
        if (dist < closestDist) {
            closestDist = dist;
            closest = w.index;
        }
    });

    hoverWeekIndex.value = closest;
};

const onSvgMouseLeave = () => {
    hoverWeekIndex.value = null;
};

const activeInspectWeek = computed(() => {
    const idx = hoverWeekIndex.value !== null ? hoverWeekIndex.value : selectedWeekIndex.value;
    return projectWeeks.value.find(w => w.index === idx) || null;
});

/* ----------------------------------------------------------- Selected Week KPIs */
const selectedWeekStats = computed(() => {
    const week = selectedWeek.value;
    if (!week) return { total: 0, completed: 0, ongoing: 0, overdue: 0, plannedProg: 0, actualProg: 0, variance: 0 };

    const tasks = allFlatTasks.value.filter(t => isTaskActiveInWeek(t, week));
    const total = tasks.length;
    const completed = tasks.filter(t => (Number(t.progress) || 0) >= 100 || t.status === 'Done').length;
    const ongoing = tasks.filter(t => (Number(t.progress) || 0) > 0 && (Number(t.progress) || 0) < 100).length;
    const overdue = tasks.filter(t => getTaskWeekStatus(t, week) === 'overdue').length;

    const weekIdx = week.index - 1;
    const plannedProg = weeklyCurveData.value.planned[weekIdx] ?? 0;
    const actualProg = weeklyCurveData.value.actual[weekIdx] ?? plannedProg;
    const variance = actualProg - plannedProg;

    return {
        total,
        completed,
        ongoing,
        overdue,
        plannedProg,
        actualProg,
        variance,
    };
});

/* ----------------------------------------------------- Department Breakdown View */
const departmentBreakdown = computed(() => {
    const week = selectedWeek.value;
    const map = new Map();

    allFlatTasks.value.forEach(task => {
        const dept = taskDepartment(task) || 'Unattributed';
        if (!map.has(dept)) {
            map.set(dept, {
                name: dept,
                totalAll: 0,
                activeThisWeek: 0,
                completedThisWeek: 0,
                overdueThisWeek: 0,
                tasks: [],
            });
        }
        const entry = map.get(dept);
        entry.totalAll++;

        if (isTaskActiveInWeek(task, week)) {
            entry.activeThisWeek++;
            if ((Number(task.progress) || 0) >= 100 || task.status === 'Done') {
                entry.completedThisWeek++;
            }
            if (getTaskWeekStatus(task, week) === 'overdue') {
                entry.overdueThisWeek++;
            }
            entry.tasks.push(task);
        }
    });

    return [...map.values()].sort((a, b) => b.activeThisWeek - a.activeThisWeek);
});

/* ---------------------------------------------------- Milestone Horizon Cards */
const showMilestonesHorizon = ref(false);

const milestoneCards = computed(() => {
    const today = new Date();
    const milestones = [
        { key: 'day1', label: 'Day 1 Date', dateStr: props.project.day1_date, icon: FlagIcon },
        { key: 'turnover', label: 'Store Turn-over', dateStr: props.project.turn_over_date, icon: BuildingOfficeIcon },
        { key: 'training', label: 'Training Date', dateStr: props.project.training_date, icon: ClockIcon },
        { key: 'testing', label: 'Testing Date', dateStr: props.project.testing_date, icon: ChartBarIcon },
        { key: 'mock', label: 'Mock Service', dateStr: props.project.mock_service_date, icon: SparklesIcon },
        { key: 'franchisee', label: 'Franchisee T.O.', dateStr: props.project.turn_over_to_franchisee_date, icon: BuildingOfficeIcon },
        { key: 'golive', label: 'Target Go-Live', dateStr: props.project.target_go_live, icon: CheckCircleIcon },
    ];

    return milestones.map(m => {
        const d = parseLocalDate(m.dateStr);
        let daysAway = null;
        let isPast = false;
        let isToday = false;

        if (d) {
            const diffTime = d - today;
            daysAway = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
            isPast = daysAway < 0;
            isToday = daysAway === 0;
        }

        return {
            ...m,
            date: d,
            formattedDate: formatFullDate(d),
            daysAway,
            isPast,
            isToday,
        };
    });
});

/* ------------------------------------------------------- Progress persistence */
// Live-drag feedback only — no request yet. Committed on release (@change)
// via commitTaskProgress, exactly like the Gantt chart's progress control.
const previewTaskProgress = (task, newProgress) => {
    task.progress = Number(newProgress);
};

const savingTaskIds = ref(new Set());

const commitTaskProgress = async (task, newProgress) => {
    // The slider itself is disabled for rows the viewer can't edit; this is a
    // defensive backstop against a stale/replayed event.
    if (!canEditTask(task)) {
        toastError('You can only edit rows assigned to you.');
        return;
    }

    if (isRolledUpProgress(task)) {
        task.progress = lastSavedProgress.get(task.id) ?? (Number(task.progress) || 0);
        toastError('This activity is calculated from its sub-tasks. Update the sub-task percentages instead.');
        return;
    }

    const prog = Math.max(0, Math.min(100, Number(newProgress)));
    const previousProgress = lastSavedProgress.has(task.id) ? lastSavedProgress.get(task.id) : (Number(task.progress) || 0);
    const previousStatus = task.status;
    const nextStatus = prog >= 100 ? 'Done' : prog > 0 ? 'Ongoing' : 'Pending';

    if (prog === previousProgress) return;

    const syncOk = await ensureTaskListBoards();
    if (!syncOk) {
        task.progress = previousProgress;
        return;
    }

    task.progress = prog;
    task.status = nextStatus;
    lastSavedProgress.set(task.id, prog);
    savingTaskIds.value.add(task.id);

    router.put(route('projects-tasks.update', { projects_task: task.id, tab: 'weekly-timeline' }), {
        progress: prog,
        status: nextStatus,
        auto_create_monthly_boards: true,
    }, {
        preserveScroll: true,
        onError: () => {
            task.progress = previousProgress;
            task.status = previousStatus;
            lastSavedProgress.set(task.id, previousProgress);
            toastError('Could not update task progress. Please try again.');
        },
        onFinish: () => {
            savingTaskIds.value.delete(task.id);
        },
    });
};

const jumpToGantt = (department = null) => {
    emit('open-gantt', department);
};
</script>

<template>
    <div class="space-y-3.5">
        <!-- Header & Executive Weekly Horizon Banner (Sticky Freeze on Scroll) -->
        <div class="sticky top-0 z-20 rounded-xl border border-gray-200 bg-white/95 backdrop-blur-md p-4 sm:p-5 shadow-xs dark:border-gray-700 dark:bg-gray-800/95">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <div class="flex items-center gap-2.5">
                        <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-blue-50 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400">
                            <CalendarDaysIcon class="h-5 w-5" />
                        </div>
                        <div>
                            <h3 class="text-base font-bold tracking-tight text-gray-900 dark:text-gray-100">
                                Weekly Timeline &amp; Progress Plotting
                            </h3>
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                Synchronized with Gantt Chart lead times, milestone dependencies, and departmental activities.
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Navigation Controls -->
                <div class="flex flex-wrap items-center gap-2">
                    <div class="flex items-center rounded-lg border border-gray-200 bg-gray-50 p-0.5 dark:border-gray-700 dark:bg-gray-900">
                        <button
                            type="button"
                            @click="activeViewMode = 's-curve'"
                            :class="[
                                'flex items-center gap-1.5 rounded-md px-2.5 py-1 text-xs font-semibold transition-all',
                                activeViewMode === 's-curve'
                                    ? 'bg-white text-blue-600 shadow-xs dark:bg-gray-800 dark:text-blue-400'
                                    : 'text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200'
                            ]"
                        >
                            <ChartBarIcon class="h-3.5 w-3.5" />
                            S-Curve &amp; Trend
                        </button>
                        <button
                            type="button"
                            @click="activeViewMode = 'schedule-matrix'"
                            :class="[
                                'flex items-center gap-1.5 rounded-md px-2.5 py-1 text-xs font-semibold transition-all',
                                activeViewMode === 'schedule-matrix'
                                    ? 'bg-white text-blue-600 shadow-xs dark:bg-gray-800 dark:text-blue-400'
                                    : 'text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200'
                            ]"
                        >
                            <ViewColumnsIcon class="h-3.5 w-3.5" />
                            Week Schedule Matrix
                        </button>
                        <button
                            type="button"
                            @click="activeViewMode = 'departments'"
                            :class="[
                                'flex items-center gap-1.5 rounded-md px-2.5 py-1 text-xs font-semibold transition-all',
                                activeViewMode === 'departments'
                                    ? 'bg-white text-blue-600 shadow-xs dark:bg-gray-800 dark:text-blue-400'
                                    : 'text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200'
                            ]"
                        >
                            <BuildingOfficeIcon class="h-3.5 w-3.5" />
                            Departments
                        </button>
                    </div>

                    <button
                        type="button"
                        @click="jumpToGantt()"
                        class="flex items-center gap-1.5 rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-1.5 text-xs font-bold text-indigo-700 transition hover:bg-indigo-100 dark:border-indigo-800 dark:bg-indigo-900/30 dark:text-indigo-300"
                        title="Jump straight to full Gantt Chart view"
                    >
                        <span>Gantt Chart</span>
                        <ArrowRightIcon class="h-3.5 w-3.5" />
                    </button>
                </div>
            </div>

            <!-- Week Navigator Ribbon -->
            <div class="mt-3.5 border-t border-gray-100 pt-2.5 dark:border-gray-700">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <!-- Navigation Arrows & Active Label -->
                    <div class="flex items-center gap-2">
                        <div class="flex items-center gap-0.5">
                            <button
                                type="button"
                                @click="goToPrevWeek"
                                :disabled="selectedWeekIndex <= 1"
                                class="rounded-lg p-1 text-gray-400 transition hover:bg-gray-100 hover:text-gray-700 disabled:opacity-30 dark:hover:bg-gray-700 dark:hover:text-gray-200"
                                title="Previous week"
                            >
                                <ChevronLeftIcon class="h-4 w-4" />
                            </button>
                            <button
                                type="button"
                                @click="goToNextWeek"
                                :disabled="selectedWeekIndex >= projectWeeks.length"
                                class="rounded-lg p-1 text-gray-400 transition hover:bg-gray-100 hover:text-gray-700 disabled:opacity-30 dark:hover:bg-gray-700 dark:hover:text-gray-200"
                                title="Next week"
                            >
                                <ChevronRightIcon class="h-4 w-4" />
                            </button>
                        </div>

                        <div>
                            <div class="flex items-center gap-1.5">
                                <span class="text-sm font-black text-gray-900 dark:text-gray-100">
                                    {{ selectedWeek?.label }}
                                </span>
                                <span v-if="selectedWeek?.isCurrentWeek" class="rounded-full bg-emerald-100 px-1.5 py-0.2 text-[9px] font-black uppercase text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300">
                                    Current Week
                                </span>
                                <span class="text-[11px] text-gray-500 dark:text-gray-400">
                                    ({{ selectedWeek?.formattedRange }})
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- Jump Buttons -->
                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            @click="goToCurrentWeek"
                            class="rounded-lg border border-gray-200 bg-gray-50 px-2 py-1 text-xs font-bold text-gray-700 transition hover:bg-gray-100 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300"
                        >
                            Jump to Current Week
                        </button>
                    </div>
                </div>

                <!-- Horizontal Week Scroller Strip -->
                <div class="mt-2.5 flex gap-1.5 overflow-x-auto pb-1.5 scrollbar-thin">
                    <button
                        v-for="w in projectWeeks"
                        :key="w.index"
                        type="button"
                        @click="selectedWeekIndex = w.index"
                        :class="[
                            'flex shrink-0 flex-col rounded-lg border p-2 text-left transition-all',
                            selectedWeekIndex === w.index
                                ? 'border-blue-500 bg-blue-50/80 ring-1 ring-blue-300 dark:border-blue-500 dark:bg-blue-900/20 dark:ring-blue-900'
                                : w.isCurrentWeek
                                    ? 'border-emerald-300 bg-emerald-50/40 hover:border-emerald-400 dark:border-emerald-700 dark:bg-emerald-950/20'
                                    : 'border-gray-200 bg-white hover:border-gray-300 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-900 dark:hover:bg-gray-800'
                        ]"
                        style="min-width: 96px;"
                    >
                        <div class="flex items-center justify-between gap-1">
                            <span :class="['text-xs font-black', selectedWeekIndex === w.index ? 'text-blue-700 dark:text-blue-400' : 'text-gray-800 dark:text-gray-200']">
                                {{ w.label }}
                            </span>
                            <span
                                v-if="w.isCurrentWeek"
                                class="h-1.5 w-1.5 rounded-full bg-emerald-500"
                                title="Current Active Week"
                            ></span>
                        </div>
                        <span class="mt-0.5 text-[9px] text-gray-500 dark:text-gray-400">
                            {{ formatShortDate(w.start) }}
                        </span>
                        <div class="mt-1.5 flex items-center justify-between text-[9px]">
                            <span class="font-bold text-gray-600 dark:text-gray-300">
                                {{ weeklyCurveData.planned[w.index - 1] }}%
                            </span>
                            <span
                                v-if="weeklyCurveData.actual[w.index - 1] !== null"
                                class="font-black text-emerald-600 dark:text-emerald-400"
                            >
                                {{ weeklyCurveData.actual[w.index - 1] }}%
                            </span>
                        </div>
                    </button>
                </div>
            </div>

            <!-- Milestone Horizon Bar -->
            <div class="mt-3 border-t border-gray-100 pt-2 dark:border-gray-700">
                <button
                    type="button"
                    @click="showMilestonesHorizon = !showMilestonesHorizon"
                    class="flex w-full items-center justify-between py-0.5 text-left transition hover:opacity-80 group"
                    title="Click to toggle key milestones horizon cards"
                >
                    <div class="flex items-center gap-1.5">
                        <span class="text-[11px] font-black uppercase tracking-wider text-gray-600 group-hover:text-blue-600 dark:text-gray-400 dark:group-hover:text-blue-400">
                            Key Milestones Horizon
                        </span>
                        <span class="rounded-full bg-gray-100 px-1.5 py-0.2 text-[9px] font-bold text-gray-500 dark:bg-gray-700 dark:text-gray-300">
                            {{ milestoneCards.filter(m => m.date).length }}
                        </span>
                        <ChevronDownIcon
                            :class="[
                                'h-3.5 w-3.5 text-gray-400 transition-transform duration-200 group-hover:text-blue-600 dark:group-hover:text-blue-400',
                                showMilestonesHorizon ? 'rotate-180' : ''
                            ]"
                        />
                    </div>
                    <span class="text-[10px] text-gray-400">
                        {{ projectWeeks.length }} Total Scheduled Weeks · {{ showMilestonesHorizon ? 'Collapse' : 'Show' }}
                    </span>
                </button>

                <div v-show="showMilestonesHorizon" class="mt-2.5 grid grid-cols-2 gap-1.5 sm:grid-cols-4 lg:grid-cols-7 transition-all">
                    <div
                        v-for="ms in milestoneCards"
                        :key="ms.key"
                        class="group relative rounded-lg border border-gray-100 bg-gray-50/70 p-2 transition hover:border-blue-200 hover:bg-blue-50/30 dark:border-gray-700 dark:bg-gray-900/50 dark:hover:border-blue-800"
                    >
                        <div class="flex items-center justify-between">
                            <span class="truncate text-[9px] font-black uppercase tracking-wider text-gray-500 dark:text-gray-400">
                                {{ ms.label }}
                            </span>
                            <component
                                :is="ms.icon"
                                class="h-3 w-3 text-gray-400 group-hover:text-blue-500"
                            />
                        </div>
                        <p class="mt-0.5 text-xs font-bold text-gray-900 dark:text-gray-100">
                            {{ ms.formattedDate }}
                        </p>
                        <div class="mt-0.5 flex items-center gap-1">
                            <span
                                v-if="ms.daysAway !== null"
                                :class="[
                                    'inline-block rounded px-1 text-[8px] font-black tabular-nums',
                                    ms.isPast
                                        ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300'
                                        : ms.isToday
                                            ? 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300'
                                            : 'bg-blue-50 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300'
                                ]"
                            >
                                {{ ms.isPast ? 'Passed' : ms.isToday ? 'Today!' : `${ms.daysAway}d away` }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Weekly Summary Stat Cards -->
        <div class="grid grid-cols-2 gap-2.5 sm:grid-cols-4">
            <div class="rounded-xl border border-gray-200 bg-white p-3 shadow-xs dark:border-gray-700 dark:bg-gray-800">
                <span class="text-[10px] font-bold uppercase tracking-wider text-gray-400 dark:text-gray-400">Week Target Progress</span>
                <div class="mt-0.5 flex items-baseline justify-between">
                    <p class="text-xl font-black text-blue-600 dark:text-blue-400">
                        {{ selectedWeekStats.plannedProg }}%
                    </p>
                    <span class="text-[10px] font-bold text-gray-400">Planned S-Curve</span>
                </div>
            </div>

            <div class="rounded-xl border border-gray-200 bg-white p-3 shadow-xs dark:border-gray-700 dark:bg-gray-800">
                <span class="text-[10px] font-bold uppercase tracking-wider text-gray-400 dark:text-gray-400">Actual Completion</span>
                <div class="mt-0.5 flex items-baseline justify-between">
                    <p class="text-xl font-black text-emerald-600 dark:text-emerald-400">
                        {{ selectedWeekStats.actualProg }}%
                    </p>
                    <span
                        :class="[
                            'inline-flex items-center text-xs font-black tabular-nums',
                            selectedWeekStats.variance >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'
                        ]"
                    >
                        <ArrowTrendingUpIcon v-if="selectedWeekStats.variance >= 0" class="mr-0.5 h-3.5 w-3.5" />
                        <ArrowTrendingDownIcon v-else class="mr-0.5 h-3.5 w-3.5" />
                        {{ selectedWeekStats.variance >= 0 ? '+' : '' }}{{ selectedWeekStats.variance }}%
                    </span>
                </div>
            </div>

            <div class="rounded-xl border border-gray-200 bg-white p-3 shadow-xs dark:border-gray-700 dark:bg-gray-800">
                <span class="text-[10px] font-bold uppercase tracking-wider text-gray-400 dark:text-gray-400">Week Deliverables</span>
                <div class="mt-0.5 flex items-baseline justify-between">
                    <p class="text-xl font-black text-gray-900 dark:text-gray-100">
                        {{ selectedWeekStats.total }}
                    </p>
                    <span class="text-[10px] font-bold text-emerald-600 dark:text-emerald-400">
                        {{ selectedWeekStats.completed }} Done
                    </span>
                </div>
            </div>

            <div class="rounded-xl border border-gray-200 bg-white p-3 shadow-xs dark:border-gray-700 dark:bg-gray-800">
                <span class="text-[10px] font-bold uppercase tracking-wider text-gray-400 dark:text-gray-400">Ongoing / Overdue</span>
                <div class="mt-0.5 flex items-baseline justify-between">
                    <p :class="['text-xl font-black', selectedWeekStats.overdue > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-gray-900 dark:text-gray-100']">
                        {{ selectedWeekStats.ongoing }}
                    </p>
                    <span
                        v-if="selectedWeekStats.overdue > 0"
                        class="rounded-full bg-rose-50 px-1.5 py-0.2 text-[9px] font-black text-rose-700 ring-1 ring-rose-200 dark:bg-rose-900/30 dark:text-rose-300"
                    >
                        {{ selectedWeekStats.overdue }} Overdue
                    </span>
                    <span v-else class="text-[10px] font-bold text-gray-400">On Track</span>
                </div>
            </div>
        </div>

        <!-- VIEW 1: S-Curve Trend & Velocity Plot -->
        <div
            v-if="activeViewMode === 's-curve'"
            class="rounded-xl border border-gray-200 bg-white p-4 sm:p-5 shadow-xs dark:border-gray-700 dark:bg-gray-800"
        >
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h4 class="text-sm font-bold text-gray-900 dark:text-gray-100">
                        Cumulative Progress S-Curve Plot
                    </h4>
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        Planned trajectory vs actual pace across all project weeks with critical milestones.
                    </p>
                </div>

                <!-- Legend -->
                <div class="flex items-center gap-3 text-xs">
                    <div class="flex items-center gap-1.5">
                        <span class="h-2 w-5 rounded bg-blue-500" style="border-bottom: 2px dashed #1d4ed8;"></span>
                        <span class="font-bold text-gray-600 dark:text-gray-300 text-[11px]">Planned</span>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <span class="h-2 w-5 rounded bg-emerald-500"></span>
                        <span class="font-bold text-gray-600 dark:text-gray-300 text-[11px]">Actual</span>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <span class="h-2 w-2 rounded-full bg-amber-400"></span>
                        <span class="font-bold text-gray-600 dark:text-gray-300 text-[11px]">Milestone</span>
                    </div>
                </div>
            </div>

            <!-- SVG Chart Area -->
            <div class="relative mt-4 overflow-hidden rounded-lg bg-gray-50/50 p-2 dark:bg-gray-900/40">
                <svg
                    ref="svgPlotRef"
                    viewBox="0 0 1000 340"
                    class="h-auto w-full select-none"
                    @mousemove="onSvgMouseMove"
                    @mouseleave="onSvgMouseLeave"
                >
                    <!-- Y Axis Grid & Labels -->
                    <g class="text-xs text-gray-400">
                        <template v-for="gridVal in [0, 25, 50, 75, 100]" :key="gridVal">
                            <line
                                :x1="SVG_PAD.left"
                                :y1="yAtVal(gridVal)"
                                :x2="SVG_W - SVG_PAD.right"
                                :y2="yAtVal(gridVal)"
                                :stroke="isDark ? '#374151' : '#e5e7eb'"
                                stroke-width="1"
                                :stroke-dasharray="gridVal === 0 || gridVal === 100 ? '0' : '4 4'"
                            />
                            <text
                                :x="SVG_PAD.left - 10"
                                :y="yAtVal(gridVal) + 4"
                                text-anchor="end"
                                font-size="11"
                                font-weight="600"
                                :fill="isDark ? '#9ca3af' : '#6b7280'"
                            >
                                {{ gridVal }}%
                            </text>
                        </template>
                    </g>

                    <!-- X Axis Week Markers -->
                    <g>
                        <template v-for="w in projectWeeks" :key="w.index">
                            <line
                                :x1="xAtWeek(w.index, projectWeeks.length)"
                                :y1="SVG_PAD.top"
                                :x2="xAtWeek(w.index, projectWeeks.length)"
                                :y2="SVG_H - SVG_PAD.bottom"
                                :stroke="isDark ? '#1f2937' : '#f3f4f6'"
                                stroke-width="1"
                            />
                            <text
                                :x="xAtWeek(w.index, projectWeeks.length)"
                                :y="SVG_H - SVG_PAD.bottom + 20"
                                text-anchor="middle"
                                font-size="10"
                                font-weight="700"
                                :fill="w.index === selectedWeekIndex ? (isDark ? '#60a5fa' : '#2563eb') : (isDark ? '#9ca3af' : '#6b7280')"
                            >
                                W{{ w.index }}
                            </text>
                        </template>
                    </g>

                    <!-- Selected Week Guideline -->
                    <line
                        v-if="selectedWeekIndex"
                        :x1="xAtWeek(selectedWeekIndex, projectWeeks.length)"
                        :y1="SVG_PAD.top"
                        :x2="xAtWeek(selectedWeekIndex, projectWeeks.length)"
                        :y2="SVG_H - SVG_PAD.bottom"
                        stroke="#3b82f6"
                        stroke-width="2"
                        stroke-dasharray="3 3"
                        opacity="0.6"
                    />

                    <!-- Actual Area Fill -->
                    <path
                        v-if="actualAreaPathD"
                        :d="actualAreaPathD"
                        fill="url(#actualProgressGradient)"
                        opacity="0.15"
                    />

                    <!-- Linear Gradients -->
                    <defs>
                        <linearGradient id="actualProgressGradient" x1="0%" y1="0%" x2="0%" y2="100%">
                            <stop offset="0%" stop-color="#10b981" />
                            <stop offset="100%" stop-color="#10b981" stop-opacity="0" />
                        </linearGradient>
                    </defs>

                    <!-- Planned S-Curve (Dashed Blue) -->
                    <path
                        :d="plannedPathD"
                        fill="none"
                        stroke="#3b82f6"
                        stroke-width="3"
                        stroke-dasharray="6 4"
                    />

                    <!-- Actual S-Curve (Solid Emerald) -->
                    <path
                        v-if="actualPathD"
                        :d="actualPathD"
                        fill="none"
                        stroke="#10b981"
                        stroke-width="3.5"
                    />

                    <!-- Milestone Vertical Pins & Badges -->
                    <g v-for="pin in milestonePins" :key="pin.label">
                        <line
                            :x1="pin.x"
                            :y1="SVG_PAD.top + 10"
                            :x2="pin.x"
                            :y2="SVG_H - SVG_PAD.bottom"
                            :stroke="pin.color"
                            stroke-width="1.5"
                            stroke-dasharray="2 2"
                            opacity="0.7"
                        />
                        <circle
                            :cx="pin.x"
                            :cy="SVG_PAD.top + 10"
                            r="5"
                            :fill="pin.color"
                            stroke="#ffffff"
                            stroke-width="1.5"
                        />
                    </g>

                    <!-- Interactive Hover Circle & Marker -->
                    <g v-if="hoverWeekIndex">
                        <line
                            :x1="xAtWeek(hoverWeekIndex, projectWeeks.length)"
                            :y1="SVG_PAD.top"
                            :x2="xAtWeek(hoverWeekIndex, projectWeeks.length)"
                            :y2="SVG_H - SVG_PAD.bottom"
                            stroke="#6366f1"
                            stroke-width="1.5"
                        />
                        <!-- Planned Point -->
                        <circle
                            :cx="xAtWeek(hoverWeekIndex, projectWeeks.length)"
                            :cy="yAtVal(weeklyCurveData.planned[hoverWeekIndex - 1] || 0)"
                            r="5"
                            fill="#3b82f6"
                            stroke="#ffffff"
                            stroke-width="2"
                        />
                        <!-- Actual Point -->
                        <circle
                            v-if="weeklyCurveData.actual[hoverWeekIndex - 1] !== null"
                            :cx="xAtWeek(hoverWeekIndex, projectWeeks.length)"
                            :cy="yAtVal(weeklyCurveData.actual[hoverWeekIndex - 1])"
                            r="6"
                            fill="#10b981"
                            stroke="#ffffff"
                            stroke-width="2"
                        />
                    </g>
                </svg>

                <!-- Tooltip Overlay Card -->
                <div
                    v-if="activeInspectWeek"
                    class="mt-2.5 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-gray-200 bg-white p-2.5 shadow-xs dark:border-gray-700 dark:bg-gray-800"
                >
                    <div class="flex items-center gap-2">
                        <span class="rounded bg-blue-50 px-2 py-0.5 text-xs font-black text-blue-700 dark:bg-blue-900/40 dark:text-blue-300">
                            {{ activeInspectWeek.label }}
                        </span>
                        <span class="text-xs font-semibold text-gray-700 dark:text-gray-300">
                            {{ activeInspectWeek.formattedRange }}
                        </span>
                    </div>

                    <div class="flex items-center gap-4 text-xs">
                        <div>
                            <span class="text-gray-400">Target:</span>
                            <span class="ml-1 font-black text-blue-600 dark:text-blue-400">
                                {{ weeklyCurveData.planned[activeInspectWeek.index - 1] }}%
                            </span>
                        </div>
                        <div v-if="weeklyCurveData.actual[activeInspectWeek.index - 1] !== null">
                            <span class="text-gray-400">Actual:</span>
                            <span class="ml-1 font-black text-emerald-600 dark:text-emerald-400">
                                {{ weeklyCurveData.actual[activeInspectWeek.index - 1] }}%
                            </span>
                        </div>
                        <button
                            type="button"
                            @click="selectedWeekIndex = activeInspectWeek.index"
                            class="rounded-lg bg-indigo-50 px-2.5 py-1 text-xs font-bold text-indigo-700 transition hover:bg-indigo-100 dark:bg-indigo-900/30 dark:text-indigo-300"
                        >
                            Focus Week
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- VIEW 2: Week Execution Matrix (Mon-Sun Gantt Strip + Deliverable Cards) -->
        <div class="rounded-xl border border-gray-200 bg-white p-4 sm:p-5 shadow-xs dark:border-gray-700 dark:bg-gray-800">
            <!-- Filter Bar -->
            <div class="flex flex-col gap-3 border-b border-gray-100 pb-3.5 lg:flex-row lg:items-center lg:justify-between dark:border-gray-700">
                <div>
                    <h4 class="text-sm font-bold text-gray-900 dark:text-gray-100">
                        Weekly Scheduled Activities &amp; Execution Board
                    </h4>
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        Tasks active during <span class="font-bold text-blue-600 dark:text-blue-400">{{ selectedWeek?.label }}</span> ({{ selectedWeek?.formattedRange }}) with daily spans.
                    </p>
                </div>

                <!-- Filters -->
                <div class="flex flex-wrap items-center gap-2">
                    <!-- Search -->
                    <div class="relative">
                        <input
                            v-model="searchQuery"
                            type="text"
                            placeholder="Filter activities…"
                            class="rounded-lg border border-gray-300 py-1 pl-7 pr-2.5 text-xs focus:border-blue-500 focus:outline-none dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"
                        />
                        <MagnifyingGlassIcon class="pointer-events-none absolute left-2 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-gray-400" />
                    </div>

                    <!-- Milestone Filter -->
                    <select
                        v-model="selectedMilestoneFilter"
                        class="rounded-lg border border-gray-300 py-1 pl-2 pr-6 text-xs focus:border-blue-500 focus:outline-none dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"
                    >
                        <option value="">All Milestones</option>
                        <option v-for="ms in availableMilestones" :key="ms" :value="ms">{{ ms }}</option>
                    </select>

                    <!-- Department Filter -->
                    <select
                        v-model="selectedDepartmentFilter"
                        class="rounded-lg border border-gray-300 py-1 pl-2 pr-6 text-xs focus:border-blue-500 focus:outline-none dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"
                    >
                        <option value="">All Departments</option>
                        <option v-for="dept in availableDepartments" :key="dept" :value="dept">{{ dept }}</option>
                    </select>

                    <!-- Status Filter -->
                    <select
                        v-model="selectedStatusFilter"
                        class="rounded-lg border border-gray-300 py-1 pl-2 pr-6 text-xs focus:border-blue-500 focus:outline-none dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"
                    >
                        <option value="">All Statuses</option>
                        <option value="done">Completed</option>
                        <option value="ongoing">In Progress</option>
                        <option value="pending">Pending</option>
                        <option value="overdue">Overdue</option>
                        <option value="blocked">Blocked</option>
                    </select>

                    <button
                        v-if="canManage"
                        type="button"
                        @click="openAddTaskForm()"
                        class="inline-flex items-center gap-1 rounded-lg bg-indigo-600 px-3 py-1 text-xs font-bold text-white shadow-xs transition hover:bg-indigo-700"
                    >
                        <PlusIcon class="h-3.5 w-3.5" />
                        Add Activity
                    </button>
                </div>
            </div>

            <!-- Full Task Add/Edit Panel -->
            <div v-if="isEditingTask" class="mt-4 rounded-xl border border-indigo-100 bg-indigo-50/30 p-4 dark:border-indigo-400/20 dark:bg-indigo-500/10">
                <div class="mb-3 flex items-center justify-between">
                    <div>
                        <h5 class="text-xs font-black uppercase tracking-widest text-indigo-950 dark:text-indigo-100">
                            {{ taskFormMode === 'edit' ? 'Edit Activity' : taskFormMode === 'add-subtask' ? 'Add Sub-task' : 'Add Activity' }}
                        </h5>
                        <p v-if="taskFormMode === 'add-subtask'" class="mt-0.5 text-xs font-semibold text-slate-500 dark:text-slate-300">
                            Under {{ addParentTask?.name }} in {{ editForm.category }}
                        </p>
                    </div>
                    <button type="button" @click="closeEditForm" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
                        <XMarkIcon class="h-4 w-4" />
                    </button>
                </div>

                <div class="grid grid-cols-1 items-end gap-x-4 gap-y-3 md:grid-cols-12">
                    <div class="md:col-span-2">
                        <label class="mb-1 ml-1 block text-[10px] font-bold uppercase tracking-widest text-indigo-900 dark:text-indigo-200">Milestone</label>
                        <input v-model="editForm.category" type="text" placeholder="Milestone name" :readonly="taskFormMode === 'add-subtask'" class="w-full rounded-lg border-slate-200 text-xs shadow-xs transition-all focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 read-only:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 dark:read-only:bg-slate-800">
                        <div v-if="editForm.errors.category" class="ml-1 mt-1 text-[10px] font-bold italic text-red-500">{{ editForm.errors.category }}</div>
                    </div>
                    <div class="md:col-span-2">
                        <label class="mb-1 ml-1 block text-[10px] font-bold uppercase tracking-widest text-indigo-900 dark:text-indigo-200">Activity</label>
                        <input v-model="editForm.name" type="text" placeholder="What needs to be done?" class="w-full rounded-lg border-slate-200 text-xs shadow-xs transition-all focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100">
                        <div v-if="editForm.errors.name" class="ml-1 mt-1 text-[10px] font-bold italic text-red-500">{{ editForm.errors.name }}</div>
                    </div>
                    <div class="md:col-span-2">
                        <label class="mb-1 ml-1 block text-[10px] font-bold uppercase tracking-widest text-indigo-900 dark:text-indigo-200">Responsible</label>
                        <select v-model="editForm.assigned_to" class="w-full rounded-lg border-slate-200 text-xs shadow-xs transition-all focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100">
                            <option value="">Unassigned</option>
                            <option v-for="member in projectTeamMembers" :key="member.id" :value="member.id">{{ member.name }}</option>
                        </select>
                        <div v-if="editForm.errors.assigned_to" class="ml-1 mt-1 text-[10px] font-bold italic text-red-500">{{ editForm.errors.assigned_to }}</div>
                    </div>
                    <div class="md:col-span-1">
                        <label class="mb-1 ml-1 block text-[10px] font-bold uppercase tracking-widest text-indigo-900 dark:text-indigo-200">Lead Time</label>
                        <input
                            :value="isRolledUpActivity ? rolledUpLeadTime : editForm.lead_time_days"
                            @input="editForm.lead_time_days = Number($event.target.value)"
                            type="number" min="1" :disabled="isRolledUpActivity"
                            class="w-full rounded-lg border-slate-200 text-xs shadow-xs transition-all focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 disabled:cursor-not-allowed disabled:bg-slate-100 disabled:text-slate-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 dark:disabled:bg-slate-800 dark:disabled:text-slate-400"
                        >
                        <div v-if="editForm.errors.lead_time_days" class="ml-1 mt-1 text-[10px] font-bold italic text-red-500">{{ editForm.errors.lead_time_days }}</div>
                    </div>
                    <div class="md:col-span-2">
                        <label class="mb-1 ml-1 block text-[10px] font-bold uppercase tracking-widest text-indigo-900 dark:text-indigo-200">Dependency</label>
                        <Autocomplete
                            :model-value="editForm.depends_on_task_id"
                            @update:model-value="value => editForm.depends_on_task_id = value"
                            :options="requisiteOptions"
                            size="sm"
                            placeholder="Previous row"
                        />
                        <div v-if="editForm.errors.depends_on_task_id" class="ml-1 mt-1 text-[10px] font-bold italic text-red-500">{{ editForm.errors.depends_on_task_id }}</div>
                    </div>
                    <div class="md:col-span-1">
                        <label class="mb-1 ml-1 block text-[10px] font-bold uppercase tracking-widest text-indigo-900 dark:text-indigo-200">Parallel?</label>
                        <button type="button" @click="editForm.can_run_parallel = !editForm.can_run_parallel"
                                :title="editForm.can_run_parallel ? 'Starts off its requisite only' : 'Waits for requisite AND row above'"
                                class="h-[34px] w-full rounded-lg border text-xs font-bold uppercase tracking-wider transition-colors"
                                :class="editForm.can_run_parallel
                                    ? 'border-emerald-300 bg-emerald-50 text-emerald-700 dark:border-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-300'
                                    : 'border-slate-200 bg-white text-slate-500 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300'">
                            {{ editForm.can_run_parallel ? 'Yes' : 'No' }}
                        </button>
                    </div>
                    <div class="md:col-span-2">
                        <div class="mb-1 ml-1 flex items-center justify-between">
                            <label class="block text-[10px] font-bold uppercase tracking-widest text-indigo-900 dark:text-indigo-200">Progress</label>
                            <button v-if="!isRolledUpActivity" type="button" @click="progressMode = progressMode === 'done' ? 'manual' : 'done'" class="text-[9px] font-bold text-indigo-500 underline hover:text-indigo-700 dark:text-indigo-300">
                                {{ progressMode === 'done' ? 'Use %' : 'Use Yes/No' }}
                            </button>
                        </div>
                        <div v-if="isRolledUpActivity" class="flex h-[34px] items-center justify-center gap-1.5 rounded-lg border border-slate-200 bg-slate-100 dark:border-slate-700 dark:bg-slate-800">
                            <span class="text-xs font-bold text-slate-500 dark:text-slate-400">{{ editForm.task_progress }}% from sub-tasks</span>
                        </div>
                        <label v-else-if="progressMode === 'done'" class="flex h-[34px] cursor-pointer items-center justify-center gap-1.5 rounded-lg border border-slate-200 dark:border-slate-700 dark:bg-slate-900">
                            <input type="checkbox" v-model="isTaskDone" class="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                            <span class="text-xs font-bold text-slate-700 dark:text-slate-200">{{ isTaskDone ? 'Done (100%)' : 'Not done' }}</span>
                        </label>
                        <input v-else v-model="editForm.task_progress" type="number" min="0" max="100" class="w-full rounded-lg border-slate-200 text-xs shadow-xs transition-all focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100">
                        <div v-if="editForm.errors.progress" class="ml-1 mt-1 text-[10px] font-bold italic text-red-500">{{ editForm.errors.progress }}</div>
                    </div>
                    <div v-if="manualStatuses.length" class="md:col-span-2">
                        <label class="mb-1 ml-1 block text-[10px] font-bold uppercase tracking-widest text-indigo-900 dark:text-indigo-200">Flag</label>
                        <Autocomplete v-model="editForm.manual_status" :options="manualStatusOptions" placeholder="None" />
                        <div v-if="editForm.errors.manual_status" class="ml-1 mt-1 text-[10px] font-bold italic text-red-500">{{ editForm.errors.manual_status }}</div>
                    </div>
                    <div class="md:col-span-3">
                        <label class="mb-1 ml-1 block text-[10px] font-bold uppercase tracking-widest text-indigo-900 dark:text-indigo-200">Timeline</label>
                        <div class="flex items-center space-x-1.5">
                            <input v-model="editForm.start_date" type="date" :disabled="isRolledUpActivity" class="w-full rounded-lg border-slate-200 text-xs shadow-xs focus:ring-1 focus:ring-indigo-500 disabled:cursor-not-allowed disabled:bg-slate-100 disabled:text-slate-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 dark:disabled:bg-slate-800 dark:disabled:text-slate-400">
                            <span class="text-slate-400 text-xs dark:text-slate-300">to</span>
                            <input v-model="editForm.end_date" type="date" :disabled="isRolledUpActivity" class="w-full rounded-lg border-slate-200 text-xs shadow-xs focus:ring-1 focus:ring-indigo-500 disabled:cursor-not-allowed disabled:bg-slate-100 disabled:text-slate-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 dark:disabled:bg-slate-800 dark:disabled:text-slate-400">
                        </div>
                        <div v-if="editForm.errors.start_date || editForm.errors.end_date" class="ml-1 mt-1 text-[10px] font-bold italic text-red-500">{{ editForm.errors.start_date || editForm.errors.end_date }}</div>
                    </div>
                </div>

                <div class="mt-4 flex items-center justify-end gap-2 border-t border-indigo-100 pt-3 dark:border-indigo-400/20">
                    <button @click="closeEditForm" class="rounded-lg border border-slate-200 bg-white px-4 py-1.5 text-xs font-bold text-slate-600 transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800">
                        Cancel
                    </button>
                    <button @click="saveEditedTask" :disabled="editForm.processing" class="rounded-lg bg-indigo-600 px-4 py-1.5 text-xs font-bold text-white shadow-xs transition hover:bg-indigo-700 disabled:opacity-50">
                        {{ taskFormMode === 'edit' ? 'Update' : taskFormMode === 'add-subtask' ? 'Add Sub-task' : 'Add Activity' }}
                    </button>
                </div>
            </div>

            <!-- Mon-Sun Header Grid -->
            <div class="mt-4">
                <!-- Day Column Headers -->
                <div class="grid grid-cols-12 gap-2 rounded-lg bg-gray-50 py-1.5 px-3 text-[10px] font-bold uppercase tracking-wider text-gray-500 dark:bg-gray-900/60 dark:text-gray-400">
                    <div class="col-span-12 lg:col-span-5">Activity &amp; Assignment</div>
                    <div class="hidden lg:col-span-7 lg:grid lg:grid-cols-7 lg:gap-1 text-center">
                        <div
                            v-for="day in weekDays"
                            :key="day.index"
                            :class="[
                                'rounded py-0.5',
                                day.isToday ? 'bg-blue-100 text-blue-800 font-black dark:bg-blue-900/40 dark:text-blue-300' : ''
                            ]"
                        >
                            <span>{{ day.name }}</span>
                            <span class="block text-[9px] font-normal text-gray-400">{{ day.dayNumber }}</span>
                        </div>
                    </div>
                </div>

                <!-- Activities List -->
                <div v-if="tasksForSelectedWeek.length" class="mt-2.5 space-y-3.5">
                    <div v-for="group in groupedTasksForSelectedWeek" :key="group.category">
                        <div class="flex items-center justify-between rounded-lg bg-slate-100/90 px-3 py-1.5 dark:bg-slate-800/80">
                            <div class="flex items-center gap-1.5">
                                <span class="text-xs font-black uppercase tracking-wider text-slate-700 dark:text-slate-200">
                                    {{ group.category }}
                                </span>
                                <span class="rounded-full bg-slate-200 px-1.5 py-0.2 text-[9px] font-bold text-slate-600 dark:bg-slate-700 dark:text-slate-300">
                                    {{ group.tasks.length }}
                                </span>
                            </div>
                            <button
                                v-if="canManage"
                                type="button"
                                @click="openAddTaskForm(group.category)"
                                class="inline-flex items-center gap-1 rounded border border-indigo-200 bg-white px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-indigo-700 transition hover:bg-indigo-50 dark:border-indigo-400/30 dark:bg-gray-800 dark:text-indigo-200 dark:hover:bg-indigo-500/15"
                            >
                                <PlusIcon class="h-3 w-3" />
                                Activity
                            </button>
                        </div>

                        <div class="divide-y divide-gray-100 dark:divide-gray-700/60">
                            <div
                                v-for="task in group.tasks"
                                :key="task.id"
                                :data-task-row="task.id"
                                class="group grid grid-cols-12 gap-2 py-2 px-1 transition hover:bg-indigo-50/15 dark:hover:bg-indigo-500/5"
                            >
                                <!-- Activity Detail -->
                                <div :class="['col-span-12 lg:col-span-5 pr-2', task.parent_task_id ? 'pl-5' : '']">
                                    <div class="flex items-start justify-between gap-1.5">
                                        <div class="min-w-0 flex-1">
                                            <div class="flex items-center gap-1.5">
                                                <span v-if="task.parent_task_id" class="text-indigo-600 dark:text-indigo-400 font-bold text-xs shrink-0 select-none">↳</span>
                                                <span class="font-mono text-[10px] font-bold text-gray-400 shrink-0">
                                                    {{ task.wbs || `#${task.id}` }}
                                                </span>
                                                <h5 class="truncate text-xs font-bold text-gray-900 dark:text-gray-100" :title="task.name">
                                                    {{ task.name }}
                                                </h5>
                                            </div>

                                            <div class="mt-0.5 flex flex-wrap items-center gap-1.5 text-[10px] text-gray-500 dark:text-gray-400">
                                                <span v-if="taskDepartment(task)" class="font-bold text-blue-600 dark:text-blue-400">
                                                    {{ taskDepartment(task) }}
                                                </span>
                                                <span v-if="taskDepartment(task)">•</span>
                                                <span>{{ getAssigneeName(task) }}</span>
                                                <span>•</span>
                                                <span>{{ formatShortDate(parseLocalDate(task.start_date)) }} – {{ formatShortDate(parseLocalDate(task.end_date)) }}</span>
                                                <span v-if="task.lead_time_days">({{ task.lead_time_days }}d)</span>
                                            </div>
                                        </div>

                                        <div class="flex shrink-0 items-center gap-1">
                                            <ProjectTaskStatusPill :status="task.manual_status || task.status" />
                                            <button
                                                v-if="canManage && !task.parent_task_id"
                                                type="button"
                                                @click="openAddSubtaskForm(task)"
                                                class="rounded p-1 text-gray-400 transition hover:bg-indigo-50 hover:text-indigo-600 dark:hover:bg-indigo-900/30 dark:hover:text-indigo-300"
                                                title="Add Sub-task"
                                            >
                                                <PlusIcon class="h-3.5 w-3.5" />
                                            </button>
                                            <button
                                                v-if="canEditTask(task)"
                                                type="button"
                                                @click="editTask(task)"
                                                class="rounded p-1 text-gray-400 transition hover:bg-indigo-50 hover:text-indigo-600 dark:hover:bg-indigo-900/30 dark:hover:text-indigo-300"
                                                title="Edit full activity details"
                                            >
                                                <PencilSquareIcon class="h-3.5 w-3.5" />
                                            </button>
                                        </div>
                                    </div>

                                    <!-- Progress Slider -->
                                    <div class="mt-1.5 flex items-center gap-2">
                                        <input
                                            type="range"
                                            min="0"
                                            max="100"
                                            step="5"
                                            :disabled="!canEditTaskProgress(task)"
                                            :value="task.progress || 0"
                                            @input="previewTaskProgress(task, $event.target.value)"
                                            @change="commitTaskProgress(task, $event.target.value)"
                                            class="h-1 w-24 cursor-pointer appearance-none rounded-lg bg-gray-200 accent-blue-600 disabled:cursor-not-allowed disabled:opacity-40 dark:bg-gray-700"
                                            :title="isRolledUpProgress(task)
                                                ? 'Calculated from sub-task progress; update the sub-tasks instead'
                                                : (canEditTask(task) ? 'Drag to update this task\'s progress' : 'You can only edit rows assigned to you')"
                                        />
                                        <span class="text-[11px] font-bold tabular-nums text-gray-700 dark:text-gray-300">
                                            {{ task.progress || 0 }}%
                                            <span v-if="savingTaskIds.has(task.id)" class="ml-1 text-[9px] font-semibold text-gray-400">saving…</span>
                                        </span>
                                        <span v-if="isRolledUpProgress(task)" class="text-[9px] font-semibold text-violet-600 dark:text-violet-300">
                                            from {{ subTaskCount(task) }} sub-task{{ subTaskCount(task) === 1 ? '' : 's' }}
                                        </span>
                                        <button
                                            type="button"
                                            @click="jumpToGantt(taskDepartment(task))"
                                            class="ml-auto text-[10px] font-bold text-indigo-600 hover:underline dark:text-indigo-400"
                                        >
                                            Open in Gantt →
                                        </button>
                                    </div>
                                </div>

                                <!-- Mon-Sun Gantt Visual Span -->
                                <div class="hidden lg:col-span-7 lg:grid lg:grid-cols-7 lg:items-center lg:gap-1">
                                    <div
                                        class="relative col-span-7 grid grid-cols-7 gap-1 h-6 rounded-md bg-gray-50/70 p-0.5 dark:bg-gray-900/40 items-center"
                                    >
                                        <!-- Span Bar -->
                                        <div
                                            :style="{
                                                gridColumnStart: getTaskDaySpan(task, selectedWeek).startCol,
                                                gridColumnEnd: `span ${getTaskDaySpan(task, selectedWeek).span}`
                                            }"
                                            :class="[
                                                'relative flex h-5 items-center justify-between rounded px-1.5 text-[9px] font-bold text-white shadow-2xs transition-all',
                                                Number(task.progress) >= 100
                                                    ? 'bg-emerald-500'
                                                    : getTaskWeekStatus(task, selectedWeek) === 'overdue'
                                                        ? 'bg-rose-500'
                                                        : task.manual_status?.toLowerCase() === 'blocked'
                                                            ? 'bg-rose-600'
                                                            : 'bg-indigo-500'
                                            ]"
                                        >
                                            <span class="truncate">{{ task.name }}</span>
                                            <span class="ml-1 shrink-0">{{ task.progress || 0 }}%</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Empty State -->
                <div v-else class="rounded-lg border border-dashed border-gray-200 p-6 text-center dark:border-gray-700">
                    <p class="text-xs font-semibold text-gray-400">
                        No activities scheduled or active for {{ selectedWeek?.label }}.
                    </p>
                    <button
                        type="button"
                        @click="goToNextWeek"
                        class="mt-1 text-xs font-bold text-blue-600 hover:underline dark:text-blue-400"
                    >
                        Check next week →
                    </button>
                </div>
            </div>
        </div>

        <!-- VIEW 3: Department Workload & Accountability Grid -->
        <div
            v-if="activeViewMode === 'departments'"
            class="rounded-xl border border-gray-200 bg-white p-4 sm:p-5 shadow-xs dark:border-gray-700 dark:bg-gray-800"
        >
            <h4 class="text-sm font-bold text-gray-900 dark:text-gray-100">
                Departmental Weekly Workload &amp; Accountability
            </h4>
            <p class="text-xs text-gray-500 dark:text-gray-400">
                Distribution of activities across teams scheduled for {{ selectedWeek?.label }}.
            </p>

            <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <div
                    v-for="dept in departmentBreakdown"
                    :key="dept.name"
                    class="flex flex-col justify-between rounded-lg border border-gray-200 bg-gray-50/50 p-3 shadow-2xs transition hover:border-blue-300 dark:border-gray-700 dark:bg-gray-900/40"
                >
                    <div>
                        <div class="flex items-center justify-between">
                            <h5 class="text-xs font-bold text-gray-900 dark:text-gray-100">
                                {{ dept.name }}
                            </h5>
                            <span class="rounded-full bg-blue-100 px-1.5 py-0.2 text-[9px] font-bold text-blue-800 dark:bg-blue-900/40 dark:text-blue-300">
                                {{ dept.activeThisWeek }} Active
                            </span>
                        </div>

                        <div class="mt-2 flex items-center justify-between text-[11px]">
                            <span class="text-gray-500">Completed This Week</span>
                            <span class="font-bold text-emerald-600 dark:text-emerald-400">
                                {{ dept.completedThisWeek }}
                            </span>
                        </div>

                        <div class="mt-0.5 flex items-center justify-between text-[11px]">
                            <span class="text-gray-500">Overdue / Delayed</span>
                            <span :class="['font-bold', dept.overdueThisWeek > 0 ? 'text-rose-600' : 'text-gray-400']">
                                {{ dept.overdueThisWeek }}
                            </span>
                        </div>
                    </div>

                    <button
                        type="button"
                        @click="jumpToGantt(dept.name)"
                        class="mt-3 flex w-full items-center justify-center gap-1 rounded border border-gray-200 bg-white py-1 text-xs font-bold text-gray-700 transition hover:border-blue-300 hover:text-blue-600 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200"
                    >
                        <span>Inspect in Gantt Chart</span>
                        <ArrowRightIcon class="h-3 w-3" />
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
