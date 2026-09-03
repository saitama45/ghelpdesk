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
} from '@heroicons/vue/24/outline';
import { router } from '@inertiajs/vue3';
import { useToast } from '@/Composables/useToast.js';
import { useConfirm } from '@/Composables/useConfirm.js';
import { canonicalDepartment, sameDepartment, uniqueDepartmentNames } from '@/Composables/useDepartmentNames.js';
import ProjectTaskStatusPill from './ProjectTaskStatusPill.vue';
import ProjectTaskFormModal from './ProjectTaskFormModal.vue';

const props = defineProps({
    project: { type: Object, required: true },
    progressHistory: { type: Array, default: () => [] },
    weeklyProgress: { type: Object, default: () => ({}) },
    users: { type: Array, default: () => [] },
    holidays: { type: Array, default: () => [] },
    taskListTargets: { type: Object, default: () => ({}) },
    // Project managers (creator/admin) may edit every row and all structure.
    canManage: { type: Boolean, default: false },
    // The viewer's user id — non-managers may only edit rows assigned to them.
    currentUserId: { type: [Number, String], default: null },
    // Milestone ownership, same payload the Gantt tab gets — the two tabs edit
    // the same rows, so they must agree on who may edit what.
    milestones: { type: Array, default: () => [] },
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
// Mirrors App\Support\ProjectPlanAccess, exactly as ProjectGantt.vue does: a
// milestone owner runs every row in their milestone, an activity assignee their
// activity and its sub-tasks, a sub-task assignee their own row.
const normaliseCategory = (category) => {
    const trimmed = String(category ?? '').trim();
    return trimmed !== '' ? trimmed : 'General';
};

const milestoneOwners = computed(() => {
    const map = new Map();
    (props.milestones || []).forEach(milestone => map.set(normaliseCategory(milestone.category), milestone));
    return map;
});

const ownsMilestone = (category) => {
    if (props.canManage) return true;
    const owner = milestoneOwners.value.get(normaliseCategory(category));
    return Boolean(owner) && props.currentUserId != null && Number(owner.assigned_to) === Number(props.currentUserId);
};

const isAssignedToMe = (task) => {
    if (!task || !props.currentUserId || task.assigned_to === null || task.assigned_to === undefined) return false;
    return Number(task.assigned_to) === Number(props.currentUserId);
};

const findTask = (taskId) => (props.project?.tasks || []).find(task => Number(task.id) === Number(taskId)) || null;

const canEditTask = (task) => {
    if (props.canManage) return true;
    if (!task) return false;
    if (ownsMilestone(task.category)) return true;
    if (isAssignedToMe(task)) return true;

    return Boolean(task.parent_task_id) && isAssignedToMe(findTask(task.parent_task_id));
};

// A sub-task is added by the milestone owner or the activity's assignee; a
// sub-task itself never takes children.
const canAddSubTaskTo = (task) => {
    if (!task || task.parent_task_id) return false;
    return ownsMilestone(task.category) || isAssignedToMe(task);
};

// A new activity belongs to whoever owns that milestone.
const canAddActivityIn = (category) => ownsMilestone(category);

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
const taskFormModal = ref(null);

// The project's team, in the modal's { id, name } shape. Members may be a linked
// user or a free-text external name, exactly as on the Gantt tab.
const formTeamMembers = computed(() => {
    const team = props.project.teamMembers || props.project.team_members || [];

    return team.map(member => (member.user
        ? { id: member.user.id, name: member.user.name, is_external: false }
        : { id: member.id, name: member.external_name || member.name || 'External member', is_external: true }));
});

const allFlatTasksForEdit = computed(() => {
    const list = [];
    localTasks.value.forEach(task => {
        list.push(task);
        (task.subTasks || []).forEach(st => list.push(st));
    });
    return list;
});

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

    taskFormModal.value?.open({
        mode: task.parent_task_id ? 'subtask' : 'activity',
        task,
        parentTask: task.parent_task_id
            ? allFlatTasksForEdit.value.find(row => Number(row.id) === Number(task.parent_task_id)) || null
            : null,
        milestone: task.category || 'General',
        canRenameMilestone: ownsMilestone(task.category || 'General'),
    });
};

// Adding a brand-new activity is a management action — same rule as Gantt's
// "+ Add Activity" per milestone. Pre-fills the Milestone field with the
// currently selected week's milestone filter (if any) so a manager working a
// specific milestone doesn't have to retype it.
const openAddTaskForm = (category = null) => {
    const milestone = category || selectedMilestoneFilter.value || '';
    if (!canAddActivityIn(milestone)) return;

    taskFormModal.value?.open({
        mode: 'activity',
        milestone,
        defaults: { milestone_order: milestoneOrderFor(milestone), order: getNextOrder(milestone) },
    });
};

// The "+" on a top-level activity row — inherits the parent's milestone,
// responsible and dates, exactly as the Gantt's sub-task button does.
const openAddSubtaskForm = (parentTask) => {
    if (!canAddSubTaskTo(parentTask)) return;

    const milestone = parentTask.category || 'General';

    taskFormModal.value?.open({
        mode: 'subtask',
        parentTask,
        milestone,
        defaults: {
            milestone_order: parentTask.milestone_order ?? milestoneOrderFor(milestone),
            order: getNextOrder(milestone, parentTask.id),
        },
    });
};

// The server returns the whole plan after a save, so the week re-reads it.
const onTaskSaved = ({ tasks }) => {
    if (tasks) localTasks.value = tasks;
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

const formatLocalDate = (date) => {
    if (!date) return null;
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
};

const getDayOfWeekIndex = (date) => {
    // 0 = Mon, 6 = Sun
    const day = date.getDay();
    return day === 0 ? 6 : day - 1;
};

/* -------------------------------------------------------------- project weeks */
const timelineScope = ref('project'); // project | full
const hasValidProjectWindow = computed(() => {
    const start = parseLocalDate(props.project.day1_date);
    const end = parseLocalDate(props.project.target_go_live);
    return Boolean(start && end && end >= start);
});

// A complete, valid Day 1 -> Target Go-Live pair is the authoritative reporting
// window. Imported task schedules may overrun that window, but they must not make
// otherwise identical projects show different numbers of weekly boxes.
const timelineBounds = computed(() => {
    const projectStart = parseLocalDate(props.project.day1_date);
    const projectEnd = parseLocalDate(props.project.target_go_live);
    const hasProjectWindow = projectStart && projectEnd && projectEnd >= projectStart;
    const useProjectWindow = timelineScope.value === 'project' && hasProjectWindow;
    let minDate = projectStart;
    let maxDate = projectEnd;

    if (!useProjectWindow) {
        localTasks.value.forEach(t => {
            if (t.start_date) {
                const s = parseLocalDate(t.start_date);
                if (s && (!minDate || s < minDate)) minDate = s;
            }
            if (t.end_date) {
                const e = parseLocalDate(t.end_date);
                if (e && (!maxDate || e > maxDate)) maxDate = e;
            }
        });
    }

    // Fallbacks if project has no dates yet
    if (!minDate) {
        minDate = new Date();
    }
    if (!maxDate || maxDate < minDate) {
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

const scheduleOutsideProjectWindow = computed(() => {
    const start = parseLocalDate(props.project.day1_date);
    const end = parseLocalDate(props.project.target_go_live);

    if (!start || !end || end < start) {
        return { before: 0, after: 0, total: 0 };
    }

    let before = 0;
    let after = 0;
    const outsideIds = new Set();

    localTasks.value.forEach((task) => {
        const taskStart = parseLocalDate(task.start_date);
        const taskEnd = parseLocalDate(task.end_date);

        if (taskStart && taskStart < start) {
            before++;
            outsideIds.add(Number(task.id));
        }
        if (taskEnd && taskEnd > end) {
            after++;
            outsideIds.add(Number(task.id));
        }
    });

    return { before, after, total: outsideIds.size };
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

// Curves and forecasts use executable leaf rows. Parent Activities already roll
// up their Sub-Tasks, so counting both would inflate the same work twice.
const executableProgressTasks = computed(() => {
    const parentIds = new Set(
        allFlatTasks.value
            .map(task => task.parent_task_id ? Number(task.parent_task_id) : null)
            .filter(Boolean)
    );

    return allFlatTasks.value.filter(task => !parentIds.has(Number(task.id)));
});

const taskProgressWeight = (task) => {
    const milestone = Number(task.milestone_weight) > 0 ? Number(task.milestone_weight) / 100 : 1;
    const activity = Number(task.activity_weight) > 0 ? Number(task.activity_weight) / 100 : 1;
    const subTask = task.parent_task_id && Number(task.sub_task_weight) > 0
        ? Number(task.sub_task_weight) / 100
        : 1;

    return milestone * activity * subTask;
};

const forecastAtGoLive = computed(() => {
    const target = parseLocalDate(props.project.target_go_live);
    const tasks = executableProgressTasks.value;

    if (!target || tasks.length === 0) {
        return { progress: 0, overrunWeeks: 0 };
    }

    let weightedForecast = 0;
    let weightTotal = 0;
    let latestEnd = null;

    tasks.forEach((task) => {
        const weight = taskProgressWeight(task);
        const progress = Math.min(100, Math.max(0, Number(task.progress) || 0));
        const end = parseLocalDate(task.end_date);
        const expectedAtGoLive = progress >= 100 || (end && end <= target) ? 100 : progress;

        weightedForecast += expectedAtGoLive * weight;
        weightTotal += weight;
        if (end && (!latestEnd || end > latestEnd)) latestEnd = end;
    });

    const overrunDays = latestEnd && latestEnd > target
        ? Math.ceil((latestEnd - target) / (1000 * 60 * 60 * 24))
        : 0;

    return {
        progress: weightTotal > 0 ? Math.round(weightedForecast / weightTotal) : 0,
        overrunWeeks: Math.ceil(overrunDays / 7),
    };
});

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

    // The task's configured department owns the process checkpoint. The
    // assignee's department identifies the executor and is only a fallback when
    // no accountable department was set on the row.
    const name = (task.department || '').trim() || (assignee?.department || '').trim() || '';

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
/**
 * The span the work really occupied: actual_start_date to actual_end_date, or
 * running to today while it is unfinished. Null until an actual start has been
 * reported. Same rule as the Gantt's actualSpan().
 */
const actualSpan = (task) => {
    const start = parseLocalDate(task?.actual_start_date);
    if (!start) return null;

    const finished = parseLocalDate(task?.actual_end_date);
    const end = finished || new Date();

    return { start, end: end < start ? start : end, inProgress: !finished };
};

// A row belongs to a week if its plan OR its actual work touches that week —
// otherwise a task that started a week early would be invisible in the week it
// actually began.
const isTaskActiveInWeek = (task, week) => {
    if (!week) return false;

    const s = parseLocalDate(task.start_date);
    const e = parseLocalDate(task.end_date);
    if (s && e && s <= week.end && e >= week.start) return true;

    const actual = actualSpan(task);

    return !!actual && actual.start <= week.end && actual.end >= week.start;
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

/** The actual span, clipped to the selected week's Mon-Sun columns. */
const getActualDaySpan = (task, week) => {
    const actual = week ? actualSpan(task) : null;
    if (!actual) return { active: false };
    if (actual.start > week.end || actual.end < week.start) return { active: false };

    const dayIndex = (date) => Math.max(0, Math.min(6,
        Math.floor((date - week.start) / (1000 * 60 * 60 * 24))));

    const startDayIdx = actual.start > week.start ? dayIndex(actual.start) : 0;
    const endDayIdx = actual.end < week.end ? dayIndex(actual.end) : 6;

    return {
        active: true,
        startCol: startDayIdx + 1,
        span: Math.max(1, endDayIdx - startDayIdx + 1),
        inProgress: actual.inProgress,
    };
};

/** Reads the same way as the Gantt's actual-bar tooltip. */
const actualBarTitle = (task) => {
    const actual = actualSpan(task);
    if (!actual) return '';

    const fmt = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    const planned = parseLocalDate(task.start_date);
    const finish = actual.inProgress ? 'in progress' : fmt(actual.end);

    if (!planned) return `Actual: ${fmt(actual.start)} to ${finish}`;

    const drift = Math.round((actual.start - planned) / 86400000);
    const timing = drift > 0
        ? `started ${drift} day${drift === 1 ? '' : 's'} late`
        : drift < 0
            ? `started ${Math.abs(drift)} day${Math.abs(drift) === 1 ? '' : 's'} early`
            : 'started on plan';

    return `Actual: ${fmt(actual.start)} to ${finish} — ${timing}`;
};

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
/**
 * When a row's work actually began. The reported actual start wins over the
 * planned one, so a task that started early lifts the curve in the week it
 * really started — the same date the Gantt draws its actual bar from. Mirrors
 * App\Services\ProjectWeeklyProgressService::workStart().
 */
const workStart = (task) => parseLocalDate(task?.actual_start_date || task?.start_date);

// Planned is the cumulative weighted amount that should be complete by each
// week end, based on each leaf task's scheduled start/end dates. Actual replays
// the append-only task progress log at that same cutoff so every point is a real
// historical snapshot, not today's value copied backwards.
const progressHistoryByTask = computed(() => {
    const grouped = new Map();
    const taskById = new Map(executableProgressTasks.value.map(task => [Number(task.id), task]));

    (props.progressHistory || []).forEach(entry => {
        const taskId = Number(entry.project_task_id);
        const recordedAt = new Date(entry.recorded_at);
        if (!taskId || Number.isNaN(recordedAt.getTime())) return;

        // Old entries only stored the save time. If work for a future task was
        // entered early, keep it in that task's reporting period instead of
        // pulling the red line backwards into the current week. The anchor is
        // the date work really began — the same date the Gantt's actual bar
        // starts from — so a genuinely early start is NOT pushed forward.
        const scheduledStart = workStart(taskById.get(taskId));
        const effectiveAt = scheduledStart && recordedAt < scheduledStart
            ? scheduledStart
            : recordedAt;

        if (!grouped.has(taskId)) grouped.set(taskId, []);
        grouped.get(taskId).push({
            at: effectiveAt,
            progress: Math.min(100, Math.max(0, Number(entry.progress) || 0)),
        });
    });

    grouped.forEach(entries => entries.sort((a, b) => a.at - b.at));
    return grouped;
});

const taskProgressAt = (task, cutoff) => {
    const scheduledStart = workStart(task);
    if (scheduledStart && cutoff < scheduledStart) return 0;

    const entries = progressHistoryByTask.value.get(Number(task.id)) || [];
    let value = 0;

    for (const entry of entries) {
        if (entry.at > cutoff) break;
        value = entry.progress;
    }

    // Legacy rows without any log use their current value, matching the existing
    // project progress chart's backwards-compatible fallback.
    if (entries.length === 0) {
        return Math.min(100, Math.max(0, Number(task.progress) || 0));
    }

    return value;
};

const actualProgressHorizon = computed(() => {
    let latest = new Date();

    progressHistoryByTask.value.forEach(entries => {
        entries.forEach(entry => {
            if (entry.at > latest) latest = entry.at;
        });
    });

    return latest;
});

const locallyCalculatedWeeklyCurveData = computed(() => {
    const weeks = projectWeeks.value;
    const tasks = executableProgressTasks.value;
    const totalWeight = tasks.reduce((sum, task) => sum + taskProgressWeight(task), 0) || 1;

    const plannedValues = [];
    const actualValues = [];

    const actualThrough = actualProgressHorizon.value;

    weeks.forEach((week) => {
        let plannedCompletionSum = 0;
        let actualCompletionSum = 0;

        tasks.forEach(t => {
            const s = parseLocalDate(t.start_date);
            const e = parseLocalDate(t.end_date);
            const weight = taskProgressWeight(t);

            if (s && e) {
                // Planned calculation
                if (week.end >= e) {
                    plannedCompletionSum += 100 * weight;
                } else if (week.end >= s) {
                    const totalDuration = Math.max(1, e - s);
                    const elapsed = Math.max(0, week.end - s);
                    const ratio = Math.min(1, elapsed / totalDuration);
                    plannedCompletionSum += ratio * 100 * weight;
                }

                // A selected future reporting week is intentionally plotted up
                // to that week; later weeks remain blank until they are reported.
                if (week.start <= actualThrough || week.isCurrentWeek) {
                    const cutoff = week.end > actualThrough ? actualThrough : week.end;
                    actualCompletionSum += taskProgressAt(t, cutoff) * weight;
                }
            } else {
                if (week.start <= actualThrough || week.isCurrentWeek) {
                    const cutoff = week.end > actualThrough ? actualThrough : week.end;
                    actualCompletionSum += taskProgressAt(t, cutoff) * weight;
                }
                plannedCompletionSum += (t.status === 'Done' ? 100 : 0) * weight;
            }
        });

        const plannedPercent = Math.round(plannedCompletionSum / totalWeight);
        const actualPercent = week.start <= actualThrough || week.isCurrentWeek
            ? Math.min(100, Math.round(actualCompletionSum / totalWeight))
            : null;

        plannedValues.push(plannedPercent);
        actualValues.push(actualPercent);
    });

    return {
        planned: plannedValues,
        actual: actualValues,
    };
});

// The default project view consumes the same server-built series as the PDF.
// The local calculation remains available for the optional full-schedule scope.
const weeklyCurveData = computed(() => {
    const serverPlanned = props.weeklyProgress?.planned;
    const serverActual = props.weeklyProgress?.actual;
    const matchesProjectWindow = timelineScope.value === 'project'
        && Array.isArray(serverPlanned)
        && Array.isArray(serverActual)
        && serverPlanned.length === projectWeeks.value.length
        && serverActual.length === projectWeeks.value.length;

    return matchesProjectWindow
        ? { planned: serverPlanned, actual: serverActual }
        : locallyCalculatedWeeklyCurveData.value;
});

/* ------------------------------------------------------- SVG Geometry & Curves */
const SVG_W = 1000;
const SVG_H = 380;
const SVG_PAD = { top: 34, right: 36, bottom: 72, left: 56 };
const plotW = SVG_W - SVG_PAD.left - SVG_PAD.right;
const plotH = SVG_H - SVG_PAD.top - SVG_PAD.bottom;

const xAtWeek = (index, total = projectWeeks.value.length) => {
    if (total <= 1) return SVG_PAD.left + plotW / 2;
    return SVG_PAD.left + ((index - 1) * plotW) / (total - 1);
};

const yAtVal = (val) => {
    return SVG_PAD.top + ((100 - val) / 100) * plotH;
};

const curveLabelY = (series, index) => {
    const value = weeklyCurveData.value[series][index];
    if (value === null || value === undefined) return 0;

    const otherSeries = series === 'planned' ? 'actual' : 'planned';
    const otherValue = weeklyCurveData.value[otherSeries][index];
    const linesAreClose = otherValue !== null && otherValue !== undefined
        && Math.abs(value - otherValue) < 9;
    const putBelow = value >= 94 || (series === 'actual' && linesAreClose);

    return Math.max(SVG_PAD.top + 12, Math.min(yAtVal(0) - 6, yAtVal(value) + (putBelow ? 18 : -10)));
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
    if (!week) return { total: 0, completed: 0, ongoing: 0, overdue: 0, plannedProg: 0, actualProg: 0, forecastProg: 0, forecastOverrunWeeks: 0, variance: 0, prevProg: 0, wowDelta: 0 };

    const tasks = allFlatTasks.value.filter(t => isTaskActiveInWeek(t, week));
    const total = tasks.length;
    const completed = tasks.filter(t => (Number(t.progress) || 0) >= 100 || t.status === 'Done').length;
    const ongoing = tasks.filter(t => (Number(t.progress) || 0) > 0 && (Number(t.progress) || 0) < 100).length;
    const overdue = tasks.filter(t => getTaskWeekStatus(t, week) === 'overdue').length;

    const weekIdx = week.index - 1;
    const plannedProg = weeklyCurveData.value.planned[weekIdx] ?? 0;
    // A week with no recorded actual yet must stay at 0. Falling back to the
    // planned S-curve made newly imported 0%-progress templates appear complete.
    const actualProg = weeklyCurveData.value.actual[weekIdx] ?? 0;
    const variance = actualProg - plannedProg;

    const prevWeekIdx = weekIdx - 1;
    const prevProg = prevWeekIdx >= 0 ? (weeklyCurveData.value.actual[prevWeekIdx] ?? 0) : 0;
    const wowDelta = actualProg - prevProg;

    return {
        total,
        completed,
        ongoing,
        overdue,
        plannedProg,
        actualProg,
        forecastProg: forecastAtGoLive.value.progress,
        forecastOverrunWeeks: forecastAtGoLive.value.overrunWeeks,
        variance,
        prevProg,
        wowDelta,
    };
});

/* ----------------------------------------------- Selected Week Movements & Highlights */
const showWeeklyMovements = ref(true);

const selectedWeekMovements = computed(() => {
    const week = selectedWeek.value;
    if (!week) return { completed: [], inProgress: [], critical: [], lookahead: [] };

    const allTasks = allFlatTasks.value;
    const activeTasks = allTasks.filter(t => isTaskActiveInWeek(t, week));

    const completed = activeTasks.filter(t => (Number(t.progress) || 0) >= 100 || t.status === 'Done');
    const inProgress = activeTasks.filter(t => (Number(t.progress) || 0) > 0 && (Number(t.progress) || 0) < 100);
    const critical = allTasks.filter(t => {
        if ((Number(t.progress) || 0) >= 100 || t.status === 'Done') return false;
        const manual = (t.manual_status || '').toLowerCase();
        if (manual === 'blocked' || manual === 'for approval' || manual === 'delayed') return true;
        return getTaskWeekStatus(t, week) === 'overdue';
    });

    const nextWeek = projectWeeks.value.find(w => w.index === week.index + 1);
    const lookahead = nextWeek ? allTasks.filter(t => {
        const s = parseLocalDate(t.start_date);
        return s && s >= nextWeek.start && s <= nextWeek.end;
    }) : [];

    return {
        completed,
        inProgress,
        critical,
        lookahead,
        nextWeekLabel: nextWeek?.label || '',
        nextWeekRange: nextWeek?.formattedRange || '',
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
        progress_recorded_at: formatLocalDate(selectedWeek.value?.end),
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
                        <div v-if="hasValidProjectWindow" class="flex rounded-lg border border-gray-200 bg-gray-50 p-0.5 dark:border-gray-700 dark:bg-gray-900">
                            <button type="button"
                                    @click="timelineScope = 'project'"
                                    :class="[
                                        'rounded-md px-2.5 py-1 text-[10px] font-black transition',
                                        timelineScope === 'project'
                                            ? 'bg-white text-blue-700 shadow-sm dark:bg-gray-700 dark:text-blue-300'
                                            : 'text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200'
                                    ]">
                                Project Window
                            </button>
                            <button type="button"
                                    @click="timelineScope = 'full'"
                                    :class="[
                                        'rounded-md px-2.5 py-1 text-[10px] font-black transition',
                                        timelineScope === 'full'
                                            ? 'bg-white text-blue-700 shadow-sm dark:bg-gray-700 dark:text-blue-300'
                                            : 'text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200'
                                    ]">
                                Full Schedule
                            </button>
                        </div>
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

                <div v-if="timelineScope === 'project' && scheduleOutsideProjectWindow.total > 0"
                     class="mt-2 flex flex-wrap items-center justify-between gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-[11px] font-semibold text-amber-800 dark:border-amber-700 dark:bg-amber-900/20 dark:text-amber-300">
                    <span>
                        {{ scheduleOutsideProjectWindow.total }} scheduled {{ scheduleOutsideProjectWindow.total === 1 ? 'row falls' : 'rows fall' }} outside the Day 1–Target Go-Live reporting window
                        <template v-if="scheduleOutsideProjectWindow.after"> · {{ scheduleOutsideProjectWindow.after }} finish after Go-Live</template>
                        <template v-if="scheduleOutsideProjectWindow.before"> · {{ scheduleOutsideProjectWindow.before }} start before Day 1</template>.
                    </span>
                    <div class="flex items-center gap-3">
                        <button type="button" @click="timelineScope = 'full'" class="font-black text-amber-900 underline underline-offset-2 dark:text-amber-200">
                            View Full Schedule
                        </button>
                        <button type="button" @click="jumpToGantt()" class="font-black text-amber-900 underline underline-offset-2 dark:text-amber-200">
                            Review in Gantt
                        </button>
                    </div>
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
                        {{ projectWeeks.length }} {{ timelineScope === 'project' ? 'Project Reporting' : 'Full Schedule' }} Weeks · {{ showMilestonesHorizon ? 'Collapse' : 'Show' }}
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
        <div class="grid grid-cols-2 gap-2.5 sm:grid-cols-5">
            <div class="rounded-xl border border-gray-200 bg-white p-3 shadow-xs dark:border-gray-700 dark:bg-gray-800">
                <span class="text-[10px] font-bold uppercase tracking-wider text-gray-400 dark:text-gray-400">Week Target Progress</span>
                <div class="mt-0.5 flex items-baseline justify-between">
                    <p class="text-xl font-black text-blue-600 dark:text-blue-400">
                        {{ selectedWeekStats.plannedProg }}%
                    </p>
                    <span class="text-[10px] font-bold text-gray-400">Baseline</span>
                </div>
            </div>

            <div class="rounded-xl border border-gray-200 bg-white p-3 shadow-xs dark:border-gray-700 dark:bg-gray-800">
                <span class="text-[10px] font-bold uppercase tracking-wider text-gray-400 dark:text-gray-400">Actual Completion</span>
                <div class="mt-0.5 flex items-baseline justify-between">
                    <p class="text-xl font-black text-emerald-600 dark:text-emerald-400">
                        {{ selectedWeekStats.actualProg }}%
                    </p>
                    <div class="text-right">
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
                        <div v-if="selectedWeekStats.wowDelta !== 0" class="text-[9px] font-bold text-indigo-600 dark:text-indigo-400">
                            {{ selectedWeekStats.wowDelta > 0 ? '+' : '' }}{{ selectedWeekStats.wowDelta }}% WoW
                        </div>
                    </div>
                </div>
            </div>

            <div class="rounded-xl border border-gray-200 bg-white p-3 shadow-xs dark:border-gray-700 dark:bg-gray-800">
                <span class="text-[10px] font-bold uppercase tracking-wider text-gray-400 dark:text-gray-400">Forecast at Go-Live</span>
                <div class="mt-0.5 flex items-baseline justify-between gap-2">
                    <p :class="['text-xl font-black', selectedWeekStats.forecastProg >= 100 ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-600 dark:text-amber-400']">
                        {{ selectedWeekStats.forecastProg }}%
                    </p>
                    <span v-if="selectedWeekStats.forecastOverrunWeeks > 0" class="text-right text-[9px] font-black text-rose-600 dark:text-rose-400">
                        +{{ selectedWeekStats.forecastOverrunWeeks }}w overrun
                    </span>
                    <span v-else class="text-[10px] font-bold text-emerald-600 dark:text-emerald-400">On schedule</span>
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

        <!-- Weekly Movements & Highlights (WoW Comparison Box) -->
        <div class="rounded-xl border border-gray-200 bg-white p-3.5 sm:p-4 shadow-xs dark:border-gray-700 dark:bg-gray-800">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <div class="flex h-6 w-6 items-center justify-center rounded-md bg-indigo-50 text-indigo-600 dark:bg-indigo-900/30 dark:text-indigo-400">
                        <SparklesIcon class="h-4 w-4" />
                    </div>
                    <div>
                        <h4 class="text-xs font-black uppercase tracking-wider text-gray-900 dark:text-gray-100">
                            Weekly Highlights &amp; Movement Stream
                        </h4>
                    </div>
                </div>
                <div class="flex items-center gap-2 text-xs">
                    <span class="rounded bg-indigo-50 px-2 py-0.5 text-[10px] font-bold text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300">
                        WoW Pace: {{ selectedWeekStats.wowDelta >= 0 ? '+' : '' }}{{ selectedWeekStats.wowDelta }}% vs Prev Week
                    </span>
                    <button
                        type="button"
                        @click="showWeeklyMovements = !showWeeklyMovements"
                        class="text-[11px] font-bold text-gray-400 hover:text-gray-600 dark:hover:text-gray-200"
                    >
                        {{ showWeeklyMovements ? 'Collapse' : 'Expand' }}
                    </button>
                </div>
            </div>

            <div v-show="showWeeklyMovements" class="mt-3 grid grid-cols-1 gap-2.5 sm:grid-cols-3">
                <!-- Column 1: Completed This Week -->
                <div class="rounded-lg border border-emerald-100 bg-emerald-50/50 p-2.5 dark:border-emerald-800/40 dark:bg-emerald-950/20">
                    <div class="flex items-center justify-between border-b border-emerald-100 pb-1.5 dark:border-emerald-800/40">
                        <span class="text-[10px] font-black uppercase tracking-wider text-emerald-800 dark:text-emerald-300">
                            Completed ({{ selectedWeekMovements.completed.length }})
                        </span>
                        <CheckCircleIcon class="h-3.5 w-3.5 text-emerald-600 dark:text-emerald-400" />
                    </div>
                    <div class="mt-2 space-y-1.5 max-h-40 overflow-y-auto pr-1">
                        <div v-for="t in selectedWeekMovements.completed" :key="t.id" class="rounded bg-white p-1.5 border border-emerald-100 shadow-2xs dark:bg-gray-800 dark:border-emerald-900/30">
                            <p class="truncate text-[11px] font-bold text-gray-900 dark:text-gray-100">{{ t.name }}</p>
                            <p class="text-[9px] text-gray-500 dark:text-gray-400">{{ t.category }} · {{ getAssigneeName(t) }}</p>
                        </div>
                        <p v-if="!selectedWeekMovements.completed.length" class="text-[10px] text-gray-400 italic py-1">No completed tasks in this window.</p>
                    </div>
                </div>

                <!-- Column 2: In Progress / Active -->
                <div class="rounded-lg border border-blue-100 bg-blue-50/50 p-2.5 dark:border-blue-800/40 dark:bg-blue-950/20">
                    <div class="flex items-center justify-between border-b border-blue-100 pb-1.5 dark:border-blue-800/40">
                        <span class="text-[10px] font-black uppercase tracking-wider text-blue-800 dark:text-blue-300">
                            In Progress ({{ selectedWeekMovements.inProgress.length }})
                        </span>
                        <ClockIcon class="h-3.5 w-3.5 text-blue-600 dark:text-blue-400" />
                    </div>
                    <div class="mt-2 space-y-1.5 max-h-40 overflow-y-auto pr-1">
                        <div v-for="t in selectedWeekMovements.inProgress" :key="t.id" class="rounded bg-white p-1.5 border border-blue-100 shadow-2xs dark:bg-gray-800 dark:border-blue-900/30">
                            <div class="flex items-center justify-between">
                                <p class="truncate text-[11px] font-bold text-gray-900 dark:text-gray-100">{{ t.name }}</p>
                                <span class="text-[9px] font-black text-blue-600 dark:text-blue-400 ml-1">{{ t.progress }}%</span>
                            </div>
                            <p class="text-[9px] text-gray-500 dark:text-gray-400">{{ t.category }} · {{ getAssigneeName(t) }}</p>
                        </div>
                        <p v-if="!selectedWeekMovements.inProgress.length" class="text-[10px] text-gray-400 italic py-1">No tasks currently in progress.</p>
                    </div>
                </div>

                <!-- Column 3: Critical / Overdue -->
                <div class="rounded-lg border border-rose-100 bg-rose-50/50 p-2.5 dark:border-rose-800/40 dark:bg-rose-950/20">
                    <div class="flex items-center justify-between border-b border-rose-100 pb-1.5 dark:border-rose-800/40">
                        <span class="text-[10px] font-black uppercase tracking-wider text-rose-800 dark:text-rose-300">
                            Critical / Overdue ({{ selectedWeekMovements.critical.length }})
                        </span>
                        <ExclamationTriangleIcon class="h-3.5 w-3.5 text-rose-600 dark:text-rose-400" />
                    </div>
                    <div class="mt-2 space-y-1.5 max-h-40 overflow-y-auto pr-1">
                        <div v-for="t in selectedWeekMovements.critical" :key="t.id" class="rounded bg-white p-1.5 border border-rose-100 shadow-2xs dark:bg-gray-800 dark:border-rose-900/30">
                            <div class="flex items-center justify-between">
                                <p class="truncate text-[11px] font-bold text-gray-900 dark:text-gray-100">{{ t.name }}</p>
                                <span v-if="t.manual_status" class="rounded bg-rose-100 px-1 text-[8px] font-black uppercase text-rose-700 dark:bg-rose-900/40 dark:text-rose-300">{{ t.manual_status }}</span>
                                <span v-else class="text-[9px] font-black text-rose-600 dark:text-rose-400">{{ t.progress }}%</span>
                            </div>
                            <p class="text-[9px] text-gray-500 dark:text-gray-400">{{ t.category }} · {{ t.end_date ? formatShortDate(parseLocalDate(t.end_date)) : 'Overdue' }}</p>
                        </div>
                        <p v-if="!selectedWeekMovements.critical.length" class="text-[10px] text-emerald-600 font-bold py-1">✓ All clear! No overdue items.</p>
                    </div>
                </div>
            </div>

            <!-- Lookahead Next Week -->
            <div v-if="selectedWeekMovements.lookahead.length" class="mt-2.5 rounded-lg border border-gray-100 bg-gray-50/60 px-3 py-1.5 dark:border-gray-700/60 dark:bg-gray-900/40">
                <span class="text-[9px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                    Next Week Focus ({{ selectedWeekMovements.nextWeekLabel }} · {{ selectedWeekMovements.nextWeekRange }}):
                </span>
                <span class="ml-2 text-[10px] font-semibold text-gray-700 dark:text-gray-300">
                    <span v-for="(t, i) in selectedWeekMovements.lookahead.slice(0, 5)" :key="t.id">
                        {{ t.name }}<span v-if="i < Math.min(4, selectedWeekMovements.lookahead.length - 1)"> • </span>
                    </span>
                    <span v-if="selectedWeekMovements.lookahead.length > 5" class="text-gray-400"> +{{ selectedWeekMovements.lookahead.length - 5 }} more</span>
                </span>
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
                        Planned Progress vs Actual Progress
                    </h4>
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        Planned is the cumulative weighted target from scheduled task dates; Actual is the recorded cumulative progress at each week end.
                    </p>
                </div>

                <!-- Legend -->
                <div class="flex items-center gap-3 text-xs">
                    <div class="flex items-center gap-1.5">
                        <span class="h-0.5 w-5 rounded bg-blue-500"></span>
                        <span class="font-bold text-gray-600 dark:text-gray-300 text-[11px]">Planned Progress</span>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <span class="h-0.5 w-5 rounded bg-red-500"></span>
                        <span class="font-bold text-gray-600 dark:text-gray-300 text-[11px]">Actual Progress</span>
                    </div>
                </div>
            </div>

            <!-- SVG Chart Area -->
            <div class="relative mt-4 overflow-hidden rounded-lg bg-gray-50/50 p-2 dark:bg-gray-900/40">
                <svg
                    ref="svgPlotRef"
                    viewBox="0 0 1000 380"
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
                                :y="SVG_H - SVG_PAD.bottom + 18"
                                :transform="`rotate(-42 ${xAtWeek(w.index, projectWeeks.length)} ${SVG_H - SVG_PAD.bottom + 18})`"
                                text-anchor="end"
                                font-size="10"
                                font-weight="700"
                                :fill="w.index === selectedWeekIndex ? (isDark ? '#60a5fa' : '#2563eb') : (isDark ? '#9ca3af' : '#6b7280')"
                            >
                                Week {{ w.index }}
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

                    <!-- Planned Progress (Blue) -->
                    <path
                        :d="plannedPathD"
                        fill="none"
                        stroke="#3b82f6"
                        stroke-width="2.75"
                        stroke-linecap="round"
                    />

                    <!-- Actual Progress (Red) -->
                    <path
                        v-if="actualPathD"
                        :d="actualPathD"
                        fill="none"
                        stroke="#ef5547"
                        stroke-width="2.75"
                        stroke-linecap="round"
                    />

                    <!-- Always-visible plotted percentages, matching the report style. -->
                    <g v-for="(value, index) in weeklyCurveData.planned" :key="`planned-${index}`">
                        <circle
                            :cx="xAtWeek(index + 1, projectWeeks.length)"
                            :cy="yAtVal(value)"
                            r="3"
                            fill="#3b82f6"
                        />
                        <text
                            :x="xAtWeek(index + 1, projectWeeks.length)"
                            :y="curveLabelY('planned', index)"
                            text-anchor="middle"
                            font-size="10"
                            font-weight="800"
                            fill="#60a5fa"
                        >{{ value }}%</text>
                    </g>

                    <g
                        v-for="(value, index) in weeklyCurveData.actual"
                        v-show="value !== null && value !== undefined"
                        :key="`actual-${index}`"
                    >
                        <circle
                            :cx="xAtWeek(index + 1, projectWeeks.length)"
                            :cy="yAtVal(value ?? 0)"
                            r="3"
                            fill="#ef5547"
                        />
                        <text
                            :x="xAtWeek(index + 1, projectWeeks.length)"
                            :y="curveLabelY('actual', index)"
                            text-anchor="middle"
                            font-size="10"
                            font-weight="800"
                            fill="#f87171"
                        >{{ value }}%</text>
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
                            fill="#ef5547"
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
                            <span class="ml-1 font-black text-red-600 dark:text-red-400">
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
                                v-if="canAddActivityIn(group.category)"
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
                                                v-if="canAddSubTaskTo(task)"
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
                                        <!-- Planned span bar -->
                                        <div
                                            :style="{
                                                gridRowStart: 1,
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
                                            <!-- The hatch runs across the bar, so the text carries its own
                                                 backdrop rather than relying on the bar colour behind it. -->
                                            <span class="relative z-30 truncate rounded-sm bg-black/55 px-1 py-px">{{ task.name }}</span>
                                            <span class="relative z-30 ml-1 shrink-0 rounded-sm bg-black/55 px-1 py-px">{{ task.progress || 0 }}%</span>
                                        </div>
                                        <!-- Actual span, centred on the same line as the plan and drawn ON
                                             TOP of it, so the hatch reads across the whole span of the real
                                             work. The planned bar's label moves up out of its way.
                                             Row 1 explicitly, since overlapping grid items are otherwise
                                             auto-placed onto a second implicit row and stack apart. -->
                                        <div
                                            v-if="getActualDaySpan(task, selectedWeek).active"
                                            :style="{
                                                gridRowStart: 1,
                                                gridColumnStart: getActualDaySpan(task, selectedWeek).startCol,
                                                gridColumnEnd: `span ${getActualDaySpan(task, selectedWeek).span}`,
                                                height: '9px',
                                            }"
                                            :class="['actual-hatch z-20 self-center rounded-[2px] pointer-events-auto cursor-help',
                                                     getActualDaySpan(task, selectedWeek).inProgress ? 'actual-hatch--open' : '']"
                                            :title="actualBarTitle(task)"
                                        ></div>
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
        <!-- The plan's one task form — the same component the Gantt tab opens, so
             both tabs always offer the same fields and the same rules. -->
        <ProjectTaskFormModal
            ref="taskFormModal"
            :project="project"
            :tasks="localTasks"
            :team-members="formTeamMembers"
            :manual-statuses="manualStatuses"
            :holidays="holidays"
            tab="weekly-timeline"
            :reporting-date="formatLocalDate(selectedWeek?.end)"
            :before-save="ensureTaskListBoards"
            @saved="onTaskSaved"
        />
    </div>
</template>
