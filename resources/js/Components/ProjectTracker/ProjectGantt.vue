<script setup>
import { ref, computed, watch, nextTick } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import { 
    PlusIcon, 
    TrashIcon, 
    ChevronRightIcon, 
    CalendarIcon,
    ClockIcon,
    CheckCircleIcon,
    ArrowPathIcon,
    ExclamationCircleIcon,
    FunnelIcon,
    ArrowsPointingOutIcon,
    PencilSquareIcon,
    XMarkIcon,
    DocumentDuplicateIcon,
    UserPlusIcon,
    DocumentChartBarIcon,
    TicketIcon,
    Squares2X2Icon,
    ArrowTopRightOnSquareIcon
} from '@heroicons/vue/24/outline';

import { useToast } from '@/Composables/useToast.js';
import { useConfirm } from '@/Composables/useConfirm.js';
import { usePermission } from '@/Composables/usePermission.js';
import { canonicalDepartment, sameDepartment, uniqueDepartmentNames } from '@/Composables/useDepartmentNames.js';
import Modal from '@/Components/Modal.vue';
import ProjectTaskFormModal from './ProjectTaskFormModal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import Autocomplete from '@/Components/Autocomplete.vue';
import MultiAutocomplete from '@/Components/MultiAutocomplete.vue';

const props = defineProps({
    project: Object,
    users: Array,
    stores: { type: Array, default: () => [] },
    projectTemplates: Array,
    taskListTargets: Object,
    // Project managers (creator/admin) may edit every row and all structure.
    canManage: { type: Boolean, default: false },
    // The viewer's user id — non-managers may only edit rows assigned to them.
    currentUserId: { type: [Number, String], default: null },
    // Milestone ownership: [{ category, assigned_to, owner_name }]. A milestone
    // owner may add/edit/delete everything inside that milestone. Mirrors what
    // App\Support\ProjectPlanAccess enforces on the server.
    milestones: { type: Array, default: () => [] },
    // Whether the viewer may start a milestone of their own (managers, and anyone
    // who already owns one here).
    canAddMilestone: { type: Boolean, default: false },
    // Non-working Philippine holidays ({ date, name, type }) — skipped in the
    // lead-time maths exactly like weekends, and shaded on the timeline.
    holidays: { type: Array, default: () => [] },
    // Manual states a row can be put into (Blocked, For Approval …). Sourced from
    // reference_options; empty simply hides the picker.
    manualStatuses: { type: Array, default: () => [] },
    // Set by the Reports tab: focus the plan on one department. Watched below —
    // applies the department filter and scrolls to that department's first row.
    focusDepartment: { type: String, default: null },
    // The /departments table — the single source for how a department is spelled.
    // Task departments are free text on two columns and disagree on case, so this
    // is what keeps one department from appearing twice in the filter.
    departments: { type: Array, default: () => [] },
});

// Y-m-d -> holiday name, for the working-day helpers and the column tooltips.
const holidayLookup = computed(() => {
    const map = new Map();
    (props.holidays || []).forEach(holiday => map.set(String(holiday.date).split('T')[0], holiday.name));
    return map;
});

const holidayNameFor = (date) => {
    const pad = (n) => String(n).padStart(2, '0');
    return holidayLookup.value.get(`${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`) || null;
};

const isHoliday = (date) => holidayNameFor(date) !== null;

/* --------------------------------------------------------------- plan access
 *
 * The mirror of App\Support\ProjectPlanAccess — the server enforces the same
 * rule, this only decides what to render. Access follows the branch you own:
 *
 *  - manager (creator/admin): everything;
 *  - milestone owner: every row inside that milestone, plus the milestone itself,
 *    plus starting a milestone of their own;
 *  - activity assignee: that activity, and adding/editing/deleting its sub-tasks;
 *  - sub-task assignee: that sub-task. Nothing is ever added under a sub-task —
 *    it is the last level of the plan.
 */

// project_tasks.category is nullable and a blank one renders as "General", so a
// milestone has to be looked up under the same name the header shows.
const normaliseCategory = (category) => {
    const trimmed = String(category ?? '').trim();
    return trimmed !== '' ? trimmed : 'General';
};

const localMilestones = ref([]);

const milestoneOwners = computed(() => {
    const map = new Map();
    localMilestones.value.forEach(milestone => map.set(normaliseCategory(milestone.category), milestone));
    return map;
});

const milestoneOwner = (category) => milestoneOwners.value.get(normaliseCategory(category)) || null;

const milestoneOwnerName = (category) => milestoneOwner(category)?.owner_name || '';

const isAssignedToMe = (task) => {
    if (!task || !props.currentUserId || task.assigned_to === null || task.assigned_to === undefined) return false;
    return Number(task.assigned_to) === Number(props.currentUserId);
};

// Managers count as owning every milestone — same shortcut the server takes.
const ownsMilestone = (category) => {
    if (props.canManage) return true;
    const owner = milestoneOwner(category);
    return Boolean(owner) && props.currentUserId != null && Number(owner.assigned_to) === Number(props.currentUserId);
};

const canEditTask = (task) => {
    if (props.canManage) return true;
    if (!task) return false;
    if (ownsMilestone(task.category)) return true;
    if (isAssignedToMe(task)) return true;

    // A sub-task belongs to whoever is running the activity above it.
    if (task.parent_task_id) {
        return isAssignedToMe(taskLookup.value.get(Number(task.parent_task_id)));
    }

    return false;
};

// Deleting a row follows exactly the same branch rule as editing it.
const canDeleteTask = (task) => canEditTask(task);

// Adding an activity is the milestone owner's call.
const canAddActivityIn = (category) => ownsMilestone(category);

// A sub-task is added by the milestone owner or by the activity's assignee.
// Sub-tasks themselves never take children.
const canAddSubTaskTo = (task) => {
    if (!task || task.parent_task_id) return false;
    return ownsMilestone(task.category) || isAssignedToMe(task);
};

// Renaming or deleting a milestone, and changing who owns it.
const canManageMilestone = (category) => ownsMilestone(category);

const canStartMilestone = computed(() => props.canManage || props.canAddMilestone);

// Retyping the Milestone field on an open row renames it / moves the row, so it
// is only writable for someone who runs that milestone.
// Which milestones this viewer runs — used for the read-only banner's wording.
const myMilestones = computed(() => {
    if (props.canManage || props.currentUserId == null) return [];
    return localMilestones.value
        .filter(milestone => Number(milestone.assigned_to) === Number(props.currentUserId))
        .map(milestone => normaliseCategory(milestone.category));
});

const { success, info, error } = useToast();
const { confirm: confirmAction } = useConfirm();
const { hasPermission } = usePermission();
const showFilters = ref(false);
const showDepartmentSummary = ref(false);
const isApplyingTemplates = ref(false);
const showTemplateModal = ref(false);
const selectedTemplateId = ref('');
const selectedStoreIds = ref([]);
const localTasks = ref([]);
const draggedTaskId = ref(null);
const dragOverTaskId = ref(null);
const draggedMilestone = ref(null);
const dragOverMilestone = ref(null);
const isSavingTaskOrder = ref(false);
const quickAssignEnabled = ref(false);
const quickAssigneeId = ref('');
const quickOnlyUnassigned = ref(false);
const quickIncludeSubtasks = ref(true);
const isBulkAssigning = ref(false);
const collapsedMilestones = ref([]);
const collapsedActivities = ref([]);
const showTicketCreateModal = ref(false);
const showTicketDetailsModal = ref(false);
const activeTicketTask = ref(null);
const ticketItems = ref([]);
const isLoadingTicketItems = ref(false);
const isCreatingTicket = ref(false);

const ticketForm = useForm({
    project_task_id: null,
    company_id: props.project.company_id || '',
    store_id: '',
    item_id: '',
    title: '',
    description: '',
    type: 'task',
    status: 'open',
    priority: 'medium',
    severity: 'minor',
    assignee_id: '',
    is_self_requester: true,
    notify_requester: true,
});

const fetchTicketItems = async () => {
    if (ticketItems.value.length || isLoadingTicketItems.value) return;

    isLoadingTicketItems.value = true;
    try {
        const response = await fetch(route('tickets.data.items', undefined, false), {
            headers: { Accept: 'application/json' },
        });
        if (!response.ok) throw new Error('Unable to load ticket items.');
        ticketItems.value = await response.json();
    } catch (exception) {
        error(exception.message || 'Unable to load ticket items.');
    } finally {
        isLoadingTicketItems.value = false;
    }
};

const openTicketCreate = async (task) => {
    if (!task?.parent_task_id || !hasPermission('tickets.create')) return;

    activeTicketTask.value = task;
    ticketForm.reset();
    ticketForm.clearErrors();
    ticketForm.project_task_id = task.id;
    ticketForm.company_id = props.project.company_id || '';
    ticketForm.store_id = task.store_id || props.project.store_id || '';
    ticketForm.title = task.name;
    ticketForm.description = `Project: ${props.project.name}\nMilestone: ${normaliseCategory(task.category)}\nSub-task: ${task.name}`;
    ticketForm.assignee_id = hasPermission('tickets.assign') ? (task.assigned_to || '') : '';
    showTicketCreateModal.value = true;
    await fetchTicketItems();
};

const closeTicketCreate = () => {
    if (isCreatingTicket.value) return;
    showTicketCreateModal.value = false;
    activeTicketTask.value = null;
    ticketForm.clearErrors();
};

watch(() => ticketForm.item_id, (itemId) => {
    const item = ticketItems.value.find(candidate => String(candidate.id) === String(itemId));
    if (item?.priority) ticketForm.priority = String(item.priority).toLowerCase();
});

const createSubTaskTicket = async () => {
    if (isCreatingTicket.value) return;

    isCreatingTicket.value = true;
    ticketForm.clearErrors();

    try {
        const response = await axios.post(route('tickets.store'), ticketForm.data(), {
            headers: { Accept: 'application/json' },
        });
        const createdTicket = response.data.ticket;

        localTasks.value = localTasks.value.map(task => Number(task.id) === Number(ticketForm.project_task_id)
            ? { ...task, tickets: [createdTicket, ...(task.tickets || [])] }
            : task);

        showTicketCreateModal.value = false;
        activeTicketTask.value = null;
        success(`${createdTicket.ticket_key} created and linked to the sub-task.`);
    } catch (exception) {
        const validationErrors = exception.response?.data?.errors;

        if (validationErrors) {
            ticketForm.setError(Object.fromEntries(
                Object.entries(validationErrors).map(([field, messages]) => [
                    field,
                    Array.isArray(messages) ? messages[0] : messages,
                ]),
            ));
        }

        error(exception.response?.data?.message || 'Please check the ticket details and try again.');
    } finally {
        isCreatingTicket.value = false;
    }
};

const openTicketDetails = (task) => {
    activeTicketTask.value = task;
    showTicketDetailsModal.value = true;
};

const closeTicketDetails = () => {
    showTicketDetailsModal.value = false;
    activeTicketTask.value = null;
};

const ticketEditUrl = (ticket) => route('tickets.edit', ticket.ticket_key || ticket.id);

const formatTicketDue = (value) => value
    ? new Date(value).toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' })
    : 'No target';

const ticketStatusLabel = (status) => String(status || '').replaceAll('_', ' ');

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

// Refs for scroll syncing (Simplified to single container)
const mainWorkspaceRef = ref(null);

// Day maths, mirroring App\Services\ScheduleCalculator: a lead time of N days is
// N days *after* the start date. In 'working' mode no row starts or ends on a
// weekend or a non-working holiday; in 'calendar' mode every day counts.
const countsEveryDay = computed(() => props.project.schedule_day_mode === 'calendar');

const isNonWorkingDay = (date) => !countsEveryDay.value && (isWeekend(date) || isHoliday(date));

const toWorkingDay = (date) => {
    const shifted = new Date(date.getTime());
    while (isNonWorkingDay(shifted)) shifted.setDate(shifted.getDate() + 1);
    return shifted;
};

// Days covered by `start`..`end` inclusive, counted in the project's own day
// mode. Mirrors ScheduleCalculator::daysBetween().
const daysBetween = (start, end) => {
    const cursor = toWorkingDay(start);
    let days = 1;

    while (cursor < end) {
        cursor.setDate(cursor.getDate() + 1);
        if (!isNonWorkingDay(cursor)) days++;
    }

    return Math.max(1, days);
};

const toDateInput = (date) => {
    const pad = (n) => String(n).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
};

const sortTasks = (tasks = []) => {
    return [...tasks].sort((a, b) => {
        const aOrder = Number.isFinite(Number(a.order)) ? Number(a.order) : Number.MAX_SAFE_INTEGER;
        const bOrder = Number.isFinite(Number(b.order)) ? Number(b.order) : Number.MAX_SAFE_INTEGER;

        if (aOrder !== bOrder) return aOrder - bOrder;
        return a.id - b.id;
    });
};

watch(() => props.project.tasks, (tasks) => {
    localTasks.value = sortTasks(tasks || []);
}, { immediate: true, deep: true });

watch(() => props.milestones, (milestones) => {
    localMilestones.value = [...(milestones || [])];
}, { immediate: true, deep: true });

const stats = computed(() => {
    const tasks = localTasks.value;
    const total = tasks.length;
    const completed = tasks.filter(t => t.status === 'Done').length;
    const ongoing = tasks.filter(t => t.status === 'Ongoing').length;
    const pending = tasks.filter(t => t.status === 'Pending').length;

    // Calculate average progress
    const totalProgressSum = tasks.reduce((sum, t) => sum + (t.progress || 0), 0);
    const progress = total > 0 ? Math.round(totalProgressSum / total) : 0;

    return { total, completed, ongoing, pending, progress };
});

// Store rollout is measured against the approved target, not merely against the
// stores selected so far. A store's percentage is the average of its Per Store
// root activities; their sub-tasks already roll up into those roots.
const storeRollout = computed(() => {
    const rootsByStore = new Map();

    localTasks.value
        .filter(task => !task.parent_task_id && task.activity_mode === 'per_store' && task.store_id)
        .forEach(task => {
            const key = Number(task.store_id);
            if (!rootsByStore.has(key)) rootsByStore.set(key, []);
            rootsByStore.get(key).push(task);
        });

    const stores = [...rootsByStore.entries()].map(([storeId, tasks]) => ({
        storeId,
        progress: Math.round(tasks.reduce((sum, task) => sum + (Number(task.progress) || 0), 0) / tasks.length),
    }));
    const selected = stores.length;
    const target = Math.max(Number(props.project.target_store_count || 0), selected);
    const completed = stores.filter(store => store.progress >= 100).length;
    const progress = target > 0
        ? Math.round(stores.reduce((sum, store) => sum + store.progress, 0) / target)
        : 0;

    return { selected, target, completed, progress };
});

const hasStoreRollout = computed(() => storeRollout.value.target > 0 || storeRollout.value.selected > 0);

const taskStoreLabel = (task) => {
    if (!task?.store_id) return '';
    const store = task.store || (props.stores || []).find(candidate => Number(candidate.id) === Number(task.store_id));
    return store?.code || store?.name || `Store #${task.store_id}`;
};

const projectTeamMembers = computed(() => {
    // Relationship is named teamMembers in the Model
    const team = props.project.teamMembers || props.project.team_members || [];
    
    return team.map(m => {
        if (m.user) {
            return {
                id: m.user.id,
                name: m.user.name,
                is_external: false
            };
        } else {
            return {
                id: m.external_name, // Use name as ID for external
                name: m.external_name,
                is_external: true
            };
        }
    });
});

const internalProjectTeamMembers = computed(() => projectTeamMembers.value.filter(member => !member.is_external));
const quickAssignReady = computed(() => quickAssigneeId.value !== '');

const quickAssignmentTaskIds = (tasks, includeSubtasks = quickIncludeSubtasks.value) => {
    return [...new Set((tasks || []).flatMap(task => [
        Number(task.id),
        ...(includeSubtasks ? (task.subTasks || []).map(subTask => Number(subTask.id)) : []),
    ]))];
};

const quickAssignRows = async (taskIds, scopeLabel) => {
    if (!props.canManage || isBulkAssigning.value) return;
    if (!quickAssignReady.value) {
        info('Choose a project team member first.');
        return;
    }

    const uniqueIds = [...new Set((taskIds || []).map(Number).filter(Boolean))];
    if (!uniqueIds.length) {
        info('There are no rows in this scope.');
        return;
    }

    const clearing = quickAssigneeId.value === '__unassign__';
    const assignedTo = clearing ? null : Number(quickAssigneeId.value);
    isBulkAssigning.value = true;

    try {
        const response = await window.axios.patch(route('projects.tasks.bulk-assign', props.project.id), {
            task_ids: uniqueIds,
            assigned_to: assignedTo,
            only_unassigned: quickOnlyUnassigned.value,
        }, {
            headers: {
                'Accept': 'application/json',
            },
        });
        const payload = response.data;

        const changedIds = new Set((payload.task_ids || []).map(Number));
        localTasks.value = localTasks.value.map(task => changedIds.has(Number(task.id)) ? {
            ...task,
            assigned_to: payload.assigned_to,
            assigned_user: payload.assignee,
            external_assignment: null,
        } : task);

        if (payload.updated > 0) {
            success(`${clearing ? 'Cleared' : 'Assigned'} ${payload.updated} row${payload.updated === 1 ? '' : 's'} in ${scopeLabel}.`);
        } else {
            info(`No assignments changed in ${scopeLabel}.`);
        }
    } catch (exception) {
        const payload = exception.response?.data || {};
        const message = Object.values(payload.errors || {}).flat()[0]
            || payload.message
            || exception.message
            || 'Unable to assign the selected rows.';
        error(message);
    } finally {
        isBulkAssigning.value = false;
    }
};

const quickAssignActivity = (task) => quickAssignRows(
    quickAssignmentTaskIds([task]),
    task.name
);

const quickAssignMilestone = (category, tasks) => quickAssignRows(
    quickAssignmentTaskIds(groupedTasks.value[category] || tasks),
    `milestone ${category}`
);

const quickAssignProject = () => {
    const roots = Object.values(groupedTasks.value).flat();
    return quickAssignRows(quickAssignmentTaskIds(roots), 'the whole project');
};

const applyActivityTemplates = () => {
    if (!props.canManage) return;
    if (!props.projectTemplates || props.projectTemplates.length === 0) {
        info('No activity templates are available.');
        return;
    }
    showTemplateModal.value = true;
};

const selectedTemplate = computed(() =>
    (props.projectTemplates || []).find(template => Number(template.id) === Number(selectedTemplateId.value)) || null
);

const templateNeedsStores = computed(() => Number(selectedTemplate.value?.per_store_activities_count || 0) > 0);

const storeOptions = computed(() => (props.stores || []).map(store => ({
    label: store.code ? `${store.code} — ${store.name}` : store.name,
    value: store.id,
})));

const existingRolloutStoreIds = computed(() => [...new Set(
    localTasks.value.filter(task => task.store_id).map(task => Number(task.store_id))
)]);

watch(selectedTemplateId, () => {
    selectedStoreIds.value = templateNeedsStores.value ? [...existingRolloutStoreIds.value] : [];
});

const confirmApplyTemplate = async () => {
    if (!selectedTemplateId.value) {
        error('Please select a template first.');
        return;
    }

    const template = props.projectTemplates.find(t => t.id === selectedTemplateId.value);

    if (templateNeedsStores.value && selectedStoreIds.value.length === 0) {
        error('Select at least one rollout store for the Per Store activities.');
        return;
    }

    const targetCount = Number(props.project.target_store_count || 0);
    if (targetCount > 0 && selectedStoreIds.value.length > targetCount) {
        error(`Select no more than the ${targetCount}-store project target.`);
        return;
    }
    
    // Close selection modal first to allow confirmation dialog to take focus
    showTemplateModal.value = false;

    const ok = await confirmAction({
        title: 'Apply Template',
        message: templateNeedsStores.value
            ? `Apply "${template.name}" to ${selectedStoreIds.value.length} selected store${selectedStoreIds.value.length === 1 ? '' : 's'}? Standard rows are added once; Per Store activities and sub-tasks are added once for each store without duplicating existing rollout rows.`
            : `Are you sure you want to apply "${template.name}"? This will add ${template.activities_count} activity rows to the project. Existing activities and sub-tasks with the same name will not be duplicated.`,
        confirmLabel: 'Apply now',
        variant: 'primary'
    });

    if (ok) {
        const syncOk = await ensureTaskListBoards();
        if (!syncOk) return;

        isApplyingTemplates.value = true;
        
        router.post(route('projects.apply-templates', props.project.id), {
            project_template_id: selectedTemplateId.value,
            store_ids: templateNeedsStores.value ? selectedStoreIds.value : [],
            auto_create_monthly_boards: true,
        }, {
            preserveScroll: true,
            // A rejected apply comes back as a redirect carrying an error bag that
            // nothing on this page renders — the modal is already closed by then, so
            // the button looked like it simply did nothing. Say what went wrong.
            onError: (errors) => {
                error(errors.store_ids || errors.project_template_id || 'The template could not be applied.');
            },
            onFinish: () => {
                isApplyingTemplates.value = false;
                selectedTemplateId.value = '';
                selectedStoreIds.value = [];
            }
        });
    } else {
        // Optional: Re-open selection if cancelled
        showTemplateModal.value = true;
    }
};

const getNextOrder = (category, parentTaskId = null) => {
    const normalizedParentId = parentTaskId ? Number(parentTaskId) : null;
    const siblings = localTasks.value.filter(task => {
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

    return existing.length ? Math.min(...existing) : getNextMilestoneOrder();
};

const getNextMilestoneOrder = () => {
    const orders = localTasks.value
        .filter(task => !task.parent_task_id)
        .map(task => Number(task.milestone_order))
        .filter(Number.isFinite);

    return orders.length ? Math.max(...orders) + 1 : 1;
};

const taskFormModal = ref(null);

// The four entry points into the shared form. Each one only works out where the
// new row belongs — the modal owns every field, rule and save from there.
const openMilestoneForm = () => {
    if (!canStartMilestone.value) return;

    taskFormModal.value?.open({
        mode: 'milestone',
        defaults: { milestone_order: getNextMilestoneOrder(), order: getNextOrder('', null) },
    });
};

const openActivityForm = (category) => {
    if (!canAddActivityIn(category)) return;

    const milestone = category || 'General';

    taskFormModal.value?.open({
        mode: 'activity',
        milestone,
        defaults: { milestone_order: milestoneOrderFor(milestone), order: getNextOrder(milestone, null) },
    });
};

const openSubTaskForm = (task) => {
    if (!canAddSubTaskTo(task)) return;

    const milestone = task.category || 'General';

    taskFormModal.value?.open({
        mode: 'subtask',
        parentTask: task,
        milestone,
        defaults: {
            milestone_order: task.milestone_order ?? milestoneOrderFor(milestone),
            order: getNextOrder(milestone, task.id),
        },
    });
};

const editTask = (task) => {
    if (!canEditTask(task)) return;

    taskFormModal.value?.open({
        mode: task.parent_task_id ? 'subtask' : 'activity',
        task,
        parentTask: task.parent_task_id
            ? localTasks.value.find(candidate => Number(candidate.id) === Number(task.parent_task_id)) || null
            : null,
        milestone: task.category || 'General',
        canRenameMilestone: canManageMilestone(task.category || 'General'),
    });
};

// The server hands back the whole plan after every save, so the chart re-reads
// it rather than patching a single row.
const onTaskSaved = ({ tasks, milestones }) => {
    localTasks.value = sortTasks(tasks || localTasks.value);
    localMilestones.value = milestones || localMilestones.value;
};

const updateTaskField = async (task, field, value) => {
    if (task[field] === value) return;

    const syncOk = await ensureTaskListBoards();
    if (!syncOk) return;

    const data = { [field]: value, auto_create_monthly_boards: true };
    
    // Auto-update status if progress is changed
    if (field === 'progress') {
        const prog = parseInt(value);
        if (prog >= 100) data.status = 'Done';
        else if (prog > 0) data.status = 'Ongoing';
        else data.status = 'Pending';
    }

    // Use router directly instead of useForm to avoid property conflicts
    router.put(route('projects-tasks.update', { 'projects_task': task.id, tab: 'gantt' }), data, {
        preserveScroll: true
    });
};

// Shortcut on every row that owns its own progress: ticking the box marks it
// 100% done, unticking sends it back to 0%. Only an activity WITH sub-tasks is
// excluded — its progress is rolled up from them and is not editable here.
const isTaskComplete = (task) => Number(task?.progress) >= 100;

const hasSubTasks = (task) => (task?.subTasks?.length || 0) > 0;

const canToggleDone = (task) => !hasSubTasks(task) && canEditTask(task);

const toggleTaskDone = async (task) => {
    if (!canToggleDone(task)) return;

    await updateTaskField(task, 'progress', isTaskComplete(task) ? 0 : 100);
};

const deleteTask = async (taskId) => {
    if (!canDeleteTask(taskLookup.value.get(Number(taskId)))) return;
    const ok = await confirmAction({
        title: 'Delete Task',
        message: 'Are you sure you want to permanently delete this task? This cannot be undone.',
        confirmLabel: 'Delete',
        variant: 'danger'
    });
    
    if (ok) {
        const syncOk = await ensureTaskListBoards();
        if (!syncOk) return;

        useForm({ auto_create_monthly_boards: true }).delete(route('projects-tasks.destroy', { 'projects_task': taskId, tab: 'gantt' }), {
            preserveScroll: true
        });
    }
};

const deleteMilestone = async (category, tasks = []) => {
    if (!canManageMilestone(category)) return;
    const rowCount = visibleTaskCount(tasks);
    const ok = await confirmAction({
        title: 'Delete Milestone',
        message: `Delete "${category}" and all ${rowCount} task row${rowCount === 1 ? '' : 's'} under it? This cannot be undone.`,
        confirmLabel: 'Delete',
        variant: 'danger'
    });

    if (ok) {
        useForm({
            category,
            auto_create_monthly_boards: false,
        }).delete(route('projects.milestones.destroy', props.project.id), {
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => {
                success('Milestone deleted successfully.');
            }
        });
    }
};

/* ------------------------------------------------------- milestone ownership */

const showOwnerModal = ref(false);
const ownerModalCategory = ref('');
const ownerModalUserId = ref('');
const isSavingOwner = ref(false);

// Anyone on the project team can be handed a milestone; external members have a
// name for an id and cannot own one, so only internal members are offered.
const milestoneOwnerOptions = computed(() => [
    { label: 'Unassigned', value: '' },
    ...internalProjectTeamMembers.value.map(member => ({ label: member.name, value: member.id })),
]);

const openOwnerModal = (category) => {
    if (!canManageMilestone(category)) return;
    ownerModalCategory.value = normaliseCategory(category);
    ownerModalUserId.value = milestoneOwner(category)?.assigned_to ?? '';
    showOwnerModal.value = true;
};

const saveMilestoneOwner = () => {
    if (!canManageMilestone(ownerModalCategory.value) || isSavingOwner.value) return;
    isSavingOwner.value = true;

    useForm({
        category: ownerModalCategory.value,
        assigned_to: ownerModalUserId.value === '' ? null : ownerModalUserId.value,
    }).put(route('projects.milestones.owner', props.project.id), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            showOwnerModal.value = false;
        },
        onError: (errors) => {
            error(errors.assigned_to || errors.category || 'The milestone owner could not be saved.');
        },
        onFinish: () => {
            isSavingOwner.value = false;
        },
    });
};

const parseLocalDate = (dateString) => {
    if (!dateString) return null;
    const datePart = dateString.split('T')[0];
    const [year, month, day] = datePart.split('-').map(Number);
    return new Date(year, month - 1, day);
};

const formatDisplayDate = (dateString) => {
    const d = parseLocalDate(dateString);
    return d ? d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' }) : '-';
};

const formatDateRange = (start, end) => {
    if (!start || !end) return null;
    const s = parseLocalDate(start);
    const e = parseLocalDate(end);
    if (!s || !e) return null;
    const sMonth = s.toLocaleString('en-US', { month: 'short' });
    const eMonth = e.toLocaleString('en-US', { month: 'short' });
    if (sMonth === eMonth && s.getFullYear() === e.getFullYear()) {
        return `${sMonth} ${s.getDate()} – ${e.getDate()}`;
    }
    return `${sMonth} ${s.getDate()} – ${eMonth} ${e.getDate()}`;
};

const calculateDays = (start, end) => {
    if (!start || !end) return '-';
    const s = parseLocalDate(start);
    const e = parseLocalDate(end);
    const diffTime = Math.abs(e - s);
    return Math.ceil(diffTime / (1000 * 60 * 60 * 24)) + 1;
};

const getStatusStyles = (status) => {
    switch (status) {
        case 'Done': return 'bg-emerald-100 text-emerald-700 border-emerald-200 dark:bg-emerald-500/15 dark:text-emerald-200 dark:border-emerald-400/30';
        case 'Ongoing': return 'bg-sky-100 text-sky-700 border-sky-200 dark:bg-sky-500/15 dark:text-sky-200 dark:border-sky-400/30';
        case 'Pending': return 'bg-amber-100 text-amber-700 border-amber-200 dark:bg-amber-500/15 dark:text-amber-200 dark:border-amber-400/30';
        default: return 'bg-slate-100 text-slate-700 border-slate-200 dark:bg-slate-800 dark:text-slate-200 dark:border-slate-600';
    }
};

const getBarColorClass = (status) => {
    switch (status) {
        case 'Done': return 'bg-emerald-500';
        case 'Ongoing': return 'bg-indigo-500';
        case 'Pending': return 'bg-amber-500';
        default: return 'bg-slate-400';
    }
};

const timelineBounds = computed(() => {
    if (!props.project.tasks || props.project.tasks.length === 0) {
        const today = new Date();
        const twoWeeks = new Date(today);
        twoWeeks.setDate(twoWeeks.getDate() + 14);
        return { start: today, end: twoWeeks };
    }

    let minDate = null;
    let maxDate = null;

    // Actual dates are folded in as well: work that started before the plan, or
    // overran it, would otherwise be drawn outside the timeline and vanish.
    // localTasks is the live copy, so a freshly stamped date widens the chart
    // without a page reload.
    const sourceTasks = localTasks.value.length ? localTasks.value : props.project.tasks;

    sourceTasks.forEach(task => {
        [task.start_date, task.actual_start_date].forEach(value => {
            if (!value) return;
            const s = parseLocalDate(value);
            if (!minDate || s < minDate) minDate = s;
        });

        [task.end_date, task.actual_end_date].forEach(value => {
            if (!value) return;
            const e = parseLocalDate(value);
            if (!maxDate || e > maxDate) maxDate = e;
        });
    });

    if (minDate) minDate.setDate(minDate.getDate() - 5);
    if (maxDate) maxDate.setDate(maxDate.getDate() + 10);

    return { 
        start: minDate || new Date(), 
        end: maxDate || new Date(new Date().setDate(new Date().getDate() + 20)) 
    };
});

const timelineDays = computed(() => {
    const days = [];
    let current = new Date(timelineBounds.value.start);
    const end = new Date(timelineBounds.value.end);

    while (current <= end) {
        days.push(new Date(current));
        current.setDate(current.getDate() + 1);
    }
    return days;
});

const getGanttBarStyles = (task) => {
    if (!task.start_date || !task.end_date) return { display: 'none' };
    
    const taskStart = parseLocalDate(task.start_date);
    const taskEnd = parseLocalDate(task.end_date);
    
    const startIndex = timelineDays.value.findIndex(d => d.toDateString() === taskStart.toDateString());
    let endIndex = timelineDays.value.findIndex(d => d.toDateString() === taskEnd.toDateString());
    
    if (startIndex === -1) return { display: 'none' };
    if (endIndex === -1) endIndex = startIndex;

    return {
        // Row 1 explicitly: grid auto-placement would drop the actual bar onto
        // an implicit second row wherever the two spans share a column, which
        // is exactly where they must overlap.
        gridRowStart: 1,
        gridColumnStart: startIndex + 1,
        gridColumnEnd: endIndex + 2
    };
};

// Planned vs actual. The plan is the solid bar; the Actual layer below is laid
// over it, from the dates the work really ran. The toggle in the toolbar hides
// the actual layer for a clean read of the schedule alone.
const showActual = ref(true);

// The Actual layer. Unlike the old progress fill — which was painted inside the
// planned bar and so always began on the planned start — this is a span of its
// own, drawn from the dates the work really ran. That is what lets it sit to the
// LEFT of the plan when a row started early, or overhang it when it ran late.
//
// A row that has started but not finished has no end date yet, so the bar runs
// to today and is drawn open-ended.
const actualSpan = (task) => {
    if (!task?.actual_start_date) return null;

    const start = String(task.actual_start_date).split('T')[0];
    const finished = task.actual_end_date ? String(task.actual_end_date).split('T')[0] : null;
    const end = finished || toDateInput(new Date());

    // A same-day stamp still deserves a visible bar.
    return {
        start,
        end: end < start ? start : end,
        inProgress: !finished,
    };
};

const hasActualBar = (task) => showActual.value && actualSpan(task) !== null;

const getActualBarStyles = (task) => {
    const span = actualSpan(task);
    if (!span) return { display: 'none' };

    const start = parseLocalDate(span.start);
    const end = parseLocalDate(span.end);

    // Clamp to the rendered timeline rather than dropping the bar: a span that
    // runs off either edge should still show the part that fits.
    const days = timelineDays.value;
    if (!days.length) return { display: 'none' };

    const firstDay = days[0];
    const lastDay = days[days.length - 1];
    if (end < firstDay || start > lastDay) return { display: 'none' };

    const indexOf = (date) => days.findIndex(day => day.toDateString() === date.toDateString());
    const startIndex = start < firstDay ? 0 : indexOf(start);
    const rawEndIndex = end > lastDay ? days.length - 1 : indexOf(end);

    if (startIndex === -1) return { display: 'none' };

    return {
        gridRowStart: 1,
        gridColumnStart: startIndex + 1,
        gridColumnEnd: (rawEndIndex === -1 ? startIndex : rawEndIndex) + 2,
    };
};

// How the actual start compares with the planned start, for the tooltip — the
// number the chart exists to show.
const actualTooltip = (task) => {
    const span = actualSpan(task);
    if (!span) return '';

    const finish = span.inProgress ? 'in progress' : span.end;
    const plannedStart = task.start_date ? String(task.start_date).split('T')[0] : null;

    if (!plannedStart) return `Actual: ${span.start} to ${finish}`;

    const drift = Math.round((parseLocalDate(span.start) - parseLocalDate(plannedStart)) / 86400000);
    const timing = drift > 0
        ? `started ${drift} day${drift === 1 ? '' : 's'} late`
        : drift < 0
            ? `started ${Math.abs(drift)} day${Math.abs(drift) === 1 ? '' : 's'} early`
            : 'started on plan';

    return `Actual: ${span.start} to ${finish} — ${timing}`;
};

const taskLookup = computed(() => {
    return new Map(localTasks.value.map(task => [Number(task.id), task]));
});

const groupedTasks = computed(() => {
    if (!localTasks.value.length) return {};

    const childrenByParent = new Map();

    localTasks.value.forEach(task => {
        const parentId = task.parent_task_id ? Number(task.parent_task_id) : null;

        if (!parentId || !taskLookup.value.has(parentId)) return;

        if (!childrenByParent.has(parentId)) {
            childrenByParent.set(parentId, []);
        }

        childrenByParent.get(parentId).push(task);
    });

    const groups = localTasks.value.reduce((groups, task) => {
        const parentId = task.parent_task_id ? Number(task.parent_task_id) : null;

        if (parentId && taskLookup.value.has(parentId)) {
            return groups;
        }

        const category = task.category || 'General';
        if (!groups[category]) groups[category] = [];
        groups[category].push({
            ...task,
            subTasks: sortTasks(childrenByParent.get(Number(task.id)) || []),
        });
        return groups;
    }, {});

    // Sort the parent tasks within each category explicitly by their order
    Object.keys(groups).forEach(category => {
        groups[category] = sortTasks(groups[category]);
    });

    const sorted = Object.entries(groups).sort(([, a], [, b]) => {
        const aMilestoneOrder = Math.min(...a.map(act => Number.isFinite(Number(act.milestone_order)) ? Number(act.milestone_order) : Number.MAX_SAFE_INTEGER));
        const bMilestoneOrder = Math.min(...b.map(act => Number.isFinite(Number(act.milestone_order)) ? Number(act.milestone_order) : Number.MAX_SAFE_INTEGER));
        if (aMilestoneOrder !== bMilestoneOrder) return aMilestoneOrder - bMilestoneOrder;

        const aMin = Math.min(...a.map(act => Number(act.order) || 0));
        const bMin = Math.min(...b.map(act => Number(act.order) || 0));
        if (aMin !== bMin) return aMin - bMin;

        return (a[0]?.id || 0) - (b[0]?.id || 0);
    });

    return Object.fromEntries(sorted);
});

/* ------------------------------------------------------------------ filters */

// Sentinels for "has no assignee" / "has no department" — real ids and names can
// never collide with these.
const UNASSIGNED = '__unassigned__';
const NO_DEPARTMENT = '__no_department__';

const filterUsers = ref([]);
const filterDepartments = ref([]);
const filterMilestones = ref([]);

// "None" clears the flag; Autocomplete needs it as an explicit option.
const manualStatusOptions = computed(() => [
    { label: 'None', value: '' },
    ...(props.manualStatuses || []).map(status => ({ label: status, value: status })),
]);

const userFilterOptions = computed(() => [
    { label: 'Unassigned', value: UNASSIGNED },
    ...(props.users || []).map(user => ({
        label: user.department ? `${user.name} — ${user.department}` : user.name,
        value: user.id,
    })),
]);

// Only departments actually present on this plan, so the list stays short. One
// department appears once: the spellings differ by case across user records, and
// two entries reading "Technology and Solutions" is a broken filter, not a choice.
const departmentFilterOptions = computed(() => {
    const names = localTasks.value.map(taskDepartment).filter(Boolean);

    return [
        { label: 'No department', value: NO_DEPARTMENT },
        ...uniqueDepartmentNames(names, props.departments).map(name => ({ label: name, value: name })),
    ];
});

const milestoneFilterOptions = computed(() =>
    Object.keys(groupedTasks.value).map(category => ({ label: category, value: category }))
);

const hasFilters = computed(() =>
    filterMilestones.value.length > 0
    || filterUsers.value.length > 0
    || filterDepartments.value.length > 0
);

const matchesFilters = (task) => {
    if (filterUsers.value.length) {
        const assigned = task.assigned_to ? Number(task.assigned_to) : null;
        const wantsUnassigned = filterUsers.value.includes(UNASSIGNED);
        const matched = assigned
            ? filterUsers.value.some(value => Number(value) === assigned)
            : wantsUnassigned;

        if (!matched) return false;
    }

    if (filterDepartments.value.length) {
        const department = taskDepartment(task);
        const wantsNone = filterDepartments.value.includes(NO_DEPARTMENT);
        const matched = department
            ? filterDepartments.value.some(value => sameDepartment(value, department))
            : wantsNone;

        if (!matched) return false;
    }

    return true;
};

/**
 * groupedTasks stays whole — scheduling and the lead-time totals must still see
 * every row. This is the display copy: an activity that matches keeps all of its
 * sub-tasks, an activity that doesn't is kept only as context for whichever
 * sub-tasks matched, and an empty milestone drops out entirely.
 */
const visibleGroupedTasks = computed(() => {
    if (!hasFilters.value) return groupedTasks.value;

    const result = {};

    Object.entries(groupedTasks.value).forEach(([category, tasks]) => {
        if (filterMilestones.value.length && !filterMilestones.value.includes(category)) {
            return;
        }

        const kept = [];

        tasks.forEach((task) => {
            const subTasks = task.subTasks || [];

            if (matchesFilters(task)) {
                kept.push(task);
                return;
            }

            const matchingSubTasks = subTasks.filter(matchesFilters);

            if (matchingSubTasks.length) {
                kept.push({ ...task, subTasks: matchingSubTasks });
            }
        });

        if (kept.length) result[category] = kept;
    });

    return result;
});

const visibleRowCount = computed(() =>
    Object.values(visibleGroupedTasks.value)
        .reduce((sum, tasks) => sum + tasks.reduce((n, task) => n + 1 + (task.subTasks?.length || 0), 0), 0)
);

const clearFilters = () => {
    filterMilestones.value = [];
    filterUsers.value = [];
    filterDepartments.value = [];
};

// Reports tab → "show me this department's rows". Applies the filter, then puts
// that department's first activity at the top of the viewport.
watch(() => props.focusDepartment, (department) => {
    if (!department) return;

    filterMilestones.value = [];
    filterUsers.value = [];
    filterDepartments.value = [department];
    showFilters.value = true;

    nextTick(() => {
        const firstRow = mainWorkspaceRef.value?.querySelector('[data-task-row]');

        if (firstRow) {
            firstRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
        } else if (mainWorkspaceRef.value) {
            mainWorkspaceRef.value.scrollTop = 0;
        }
    });
}, { immediate: true });

const taskRows = (task) => {
    return [
        { task, isSubTask: false, parent: null },
        ...(!collapsedActivities.value.includes(Number(task.id)) ? (task.subTasks || []) : [])
            .map(subTask => ({ task: subTask, isSubTask: true, parent: task })),
    ];
};

const isMilestoneCollapsed = (category) => collapsedMilestones.value.includes(category);
const toggleMilestoneCollapsed = (category) => {
    collapsedMilestones.value = isMilestoneCollapsed(category)
        ? collapsedMilestones.value.filter(value => value !== category)
        : [...collapsedMilestones.value, category];
};

const isActivityCollapsed = (task) => collapsedActivities.value.includes(Number(task.id));
const toggleActivityCollapsed = (task) => {
    const taskId = Number(task.id);
    collapsedActivities.value = isActivityCollapsed(task)
        ? collapsedActivities.value.filter(value => value !== taskId)
        : [...collapsedActivities.value, taskId];
};

const allMilestoneCategories = computed(() => Object.keys(groupedTasks.value));

const collapsibleActivityIds = computed(() =>
    Object.values(groupedTasks.value)
        .flat()
        .filter(hasSubTasks)
        .map(task => Number(task.id))
);

const hasCollapsed = computed(() =>
    collapsedMilestones.value.length > 0 || collapsedActivities.value.length > 0
);

const toggleAllCollapse = () => {
    if (hasCollapsed.value) {
        collapsedMilestones.value = [];
        collapsedActivities.value = [];
        return;
    }

    collapsedMilestones.value = [...allMilestoneCategories.value];
    collapsedActivities.value = [...collapsibleActivityIds.value];
};

const visibleTaskCount = (tasks = []) => {
    return tasks.reduce((count, task) => count + 1 + (task.subTasks?.length || 0), 0);
};

// An activity with sub-tasks carries their summed lead time, so only root rows
// are totalled — counting sub-tasks as well would double them.
const effectiveLeadTime = (task) => {
    const subTasks = task.subTasks || [];

    return subTasks.length
        ? subTasks.reduce((sum, subTask) => sum + (Number(subTask.lead_time_days) || 0), 0)
        : (Number(task.lead_time_days) || 0);
};

const milestoneLeadTime = (tasks = []) => {
    return tasks.reduce((sum, task) => sum + effectiveLeadTime(task), 0);
};

/**
 * How long a milestone runs on the chart — its first start to its last finish,
 * inclusive. Like the grand total, this is a span rather than the sum of its
 * rows, so parallel activities inside the milestone are not counted twice.
 */
const milestoneSpanDays = (tasks = []) => {
    const dated = tasks.flatMap(task => [task, ...(task.subTasks || [])])
        .filter(task => task.start_date && task.end_date);

    if (!dated.length) return milestoneLeadTime(tasks);

    const starts = dated.map(task => parseLocalDate(task.start_date).getTime());
    const ends = dated.map(task => parseLocalDate(task.end_date).getTime());

    return daysBetween(new Date(Math.min(...starts)), new Date(Math.max(...ends)));
};

/**
 * How long the whole plan actually runs: the first start to the last finish,
 * inclusive, counted in the project's day mode.
 *
 * NOT the sum of the rows' lead times. Parallel rows overlap, so summing them
 * double-counts the shared days — a plan of 10 + 5 + 5 + 5 days where three rows
 * start together runs 15 days, not 25. Without a Day 1 Date no row has dates
 * yet, so the sum is the only figure available and stands in.
 */
const grandTotalLeadTime = computed(() => {
    const dated = localTasks.value.filter(task => task.start_date && task.end_date);

    if (!dated.length) {
        return Object.values(groupedTasks.value).reduce((sum, tasks) => sum + milestoneLeadTime(tasks), 0);
    }

    const starts = dated.map(task => parseLocalDate(task.start_date).getTime());
    const ends = dated.map(task => parseLocalDate(task.end_date).getTime());

    return daysBetween(new Date(Math.min(...starts)), new Date(Math.max(...ends)));
});

const getAssigneeName = (task) => {
    return task.assigned_user?.name
        || props.users.find(user => user.id == task.assigned_to)?.name
        || task.external_assignment
        || '';
};

const getAssigneeInitial = (task) => {
    return (getAssigneeName(task) || 'U').charAt(0);
};

/**
 * The department accountable for a row — mirrors ProjectTask::resolvedDepartment()
 * on the server. The row's department is the accountable process department and
 * wins for monitoring. Assignee department is only a fallback for rows whose
 * department was left blank.
 */
const taskDepartment = (task) => {
    const assignee = task.assigned_to
        ? props.users.find(user => user.id == task.assigned_to)
        : null;

    const name = (task.department || '').trim() || (assignee?.department || '').trim() || '';

    // Canonicalised here, at the one place every label, filter option and
    // milestone tally reads a department from — otherwise the same department
    // renders under two spellings depending on who is assigned to the row.
    return canonicalDepartment(name, props.departments);
};

// Department progress uses executable leaf rows. Activities with sub-tasks are
// roll-up rows, so including both would count the same work twice. Imported WBS
// weights preserve the intended contribution of every process checkpoint.
const departmentProgressSummary = computed(() => {
    const parentIds = new Set(
        localTasks.value
            .map(task => task.parent_task_id ? Number(task.parent_task_id) : null)
            .filter(Boolean)
    );
    const groups = new Map();

    localTasks.value
        .filter(task => !parentIds.has(Number(task.id)))
        .forEach((task) => {
            const department = taskDepartment(task);
            if (!department) return;

            const key = department.toLocaleLowerCase();
            if (!groups.has(key)) {
                groups.set(key, {
                    name: department,
                    assignments: 0,
                    completed: 0,
                    weightedProgress: 0,
                    weight: 0,
                });
            }

            const milestoneWeight = Number(task.milestone_weight) > 0 ? Number(task.milestone_weight) / 100 : 1;
            const activityWeight = Number(task.activity_weight) > 0 ? Number(task.activity_weight) / 100 : 1;
            const subTaskWeight = task.parent_task_id && Number(task.sub_task_weight) > 0
                ? Number(task.sub_task_weight) / 100
                : 1;
            const weight = milestoneWeight * activityWeight * subTaskWeight;
            const progress = Math.min(100, Math.max(0, Number(task.progress) || 0));
            const group = groups.get(key);

            group.assignments += 1;
            group.completed += progress >= 100 || task.status === 'Done' ? 1 : 0;
            group.weightedProgress += progress * weight;
            group.weight += weight;
        });

    return [...groups.values()]
        .map(group => ({
            ...group,
            progress: group.weight > 0 ? Math.round(group.weightedProgress / group.weight) : 0,
        }))
        .sort((a, b) => a.name.localeCompare(b.name));
});

const departmentProgressTone = (progress) => {
    if (progress >= 75) return 'text-emerald-600 dark:text-emerald-400';
    if (progress >= 40) return 'text-amber-600 dark:text-amber-400';
    return 'text-rose-600 dark:text-rose-400';
};

const focusDepartmentOnGantt = (department) => {
    showDepartmentSummary.value = false;
    filterMilestones.value = [];
    filterUsers.value = [];
    filterDepartments.value = [department];
    showFilters.value = true;

    nextTick(() => {
        mainWorkspaceRef.value?.querySelector('[data-task-row]')?.scrollIntoView({
            behavior: 'smooth',
            block: 'center',
        });
    });
};

const taskOrganizationLabel = (task) => {
    const department = taskDepartment(task);

    if (!department) return '';

    // sub_unit belongs to the row, so it only makes sense alongside the row's own
    // department — pairing it with an assignee's department would invent an org path.
    const subUnit = sameDepartment(department, task.department) ? (task.sub_unit || '').trim() : '';

    return [department, subUnit].filter(Boolean).join(' / ');
};

/**
 * A milestone has no department of its own — it is only a category string on its
 * rows — so it shows the department most of its activities and sub-tasks resolve
 * to, and says "Mixed" when no single one holds a majority.
 */
const milestoneDepartmentLabel = (tasks = []) => {
    const counts = {};

    tasks.forEach((task) => {
        [task, ...(task.subTasks || [])].forEach((row) => {
            const department = taskDepartment(row);
            if (department) counts[department] = (counts[department] || 0) + 1;
        });
    });

    const ranked = Object.entries(counts).sort((a, b) => b[1] - a[1]);

    if (!ranked.length) return '';

    const [name, count] = ranked[0];
    const total = ranked.reduce((sum, [, value]) => sum + value, 0);

    return count * 2 > total ? name : `Mixed · ${ranked.length} departments`;
};

const taskOrderErrorMessage = (exception) => {
    const errors = exception?.response?.data?.errors;
    const validationMessage = errors
        ? Object.values(errors).flat().find(Boolean)
        : null;

    return validationMessage
        || exception?.response?.data?.message
        || 'Unable to save task order.';
};

const persistTaskOrder = async (updates, previousTasks) => {
    const syncOk = await ensureTaskListBoards();
    if (!syncOk) {
        localTasks.value = previousTasks;
        return;
    }

    isSavingTaskOrder.value = true;

    try {
        await window.axios.post(route('projects.tasks.gantt-update'), {
            tasks: updates,
            auto_create_monthly_boards: true,
        });

        success('Task order updated.');
    } catch (exception) {
        localTasks.value = previousTasks;
        error(taskOrderErrorMessage(exception));
    } finally {
        isSavingTaskOrder.value = false;
    }
};

const handleTaskDragStart = (task, event) => {
    if (!props.canManage || isSavingTaskOrder.value) return; // reordering is a manager-only action
    draggedTaskId.value = task.id;
    event?.dataTransfer?.setData('text/plain', String(task.id));
    if (event?.dataTransfer) event.dataTransfer.effectAllowed = 'move';
};

const handleTaskDragOver = (task) => {
    if (!props.canManage) return;
    if (!draggedTaskId.value || draggedTaskId.value === task.id) return;
    dragOverTaskId.value = task.id;
};

const handleTaskDrop = async (targetTask) => {
    if (!props.canManage || isSavingTaskOrder.value) return;
    if (!draggedTaskId.value || Number(draggedTaskId.value) === Number(targetTask.id)) {
        draggedTaskId.value = null;
        dragOverTaskId.value = null;
        return;
    }

    const movedTask = localTasks.value.find(task => Number(task.id) === Number(draggedTaskId.value));
    const sameParent = Number(movedTask?.parent_task_id || 0) === Number(targetTask.parent_task_id || 0);
    const sameMilestone = normaliseCategory(movedTask?.category) === normaliseCategory(targetTask.category);

    if (!movedTask || !sameParent || (!movedTask.parent_task_id && !sameMilestone)) {
        draggedTaskId.value = null;
        dragOverTaskId.value = null;
        info(movedTask?.parent_task_id
            ? 'Sub-tasks can only be reordered within the same activity.'
            : 'Activities can only be reordered within the same milestone.');
        return;
    }

    const siblings = sortTasks(localTasks.value.filter(task => {
        const hasSameParent = Number(task.parent_task_id || 0) === Number(movedTask.parent_task_id || 0);
        return hasSameParent && (movedTask.parent_task_id || normaliseCategory(task.category) === normaliseCategory(movedTask.category));
    }));
    const siblingIds = siblings.map(task => Number(task.id));
    const fromIndex = siblingIds.indexOf(Number(movedTask.id));
    const toIndex = siblingIds.indexOf(Number(targetTask.id));

    if (fromIndex === -1 || toIndex === -1) return;

    const previousTasks = localTasks.value;
    const [movedId] = siblingIds.splice(fromIndex, 1);
    siblingIds.splice(toIndex, 0, movedId);
    const orderById = new Map(siblingIds.map((id, index) => [id, index + 1]));

    localTasks.value = localTasks.value.map(task => orderById.has(Number(task.id))
        ? { ...task, order: orderById.get(Number(task.id)) }
        : task);

    draggedTaskId.value = null;
    dragOverTaskId.value = null;
    await persistTaskOrder(
        siblingIds.map(id => ({ id, order: orderById.get(id) })),
        previousTasks
    );
};

const handleTaskDragEnd = () => {
    draggedTaskId.value = null;
    dragOverTaskId.value = null;
};

const handleMilestoneDragStart = (category, event) => {
    if (!props.canManage || isSavingTaskOrder.value) return;
    draggedMilestone.value = normaliseCategory(category);
    event?.dataTransfer?.setData('text/plain', draggedMilestone.value);
    if (event?.dataTransfer) event.dataTransfer.effectAllowed = 'move';
};

const handleMilestoneDragOver = (category) => {
    if (!draggedMilestone.value || isSavingTaskOrder.value) return;
    dragOverMilestone.value = normaliseCategory(category);
};

const handleMilestoneDrop = async (targetCategory) => {
    const source = draggedMilestone.value;
    const target = normaliseCategory(targetCategory);
    draggedMilestone.value = null;
    dragOverMilestone.value = null;

    if (!props.canManage || isSavingTaskOrder.value || !source || source === target) return;

    const categories = Object.keys(groupedTasks.value).map(normaliseCategory);
    const fromIndex = categories.indexOf(source);
    const toIndex = categories.indexOf(target);
    if (fromIndex === -1 || toIndex === -1) return;

    const previousTasks = localTasks.value;
    const [movedCategory] = categories.splice(fromIndex, 1);
    categories.splice(toIndex, 0, movedCategory);
    const orderByCategory = new Map(categories.map((category, index) => [category, index + 1]));

    localTasks.value = localTasks.value.map(task => ({
        ...task,
        milestone_order: orderByCategory.get(normaliseCategory(task.category)),
    }));

    await persistTaskOrder(
        localTasks.value.map(task => ({
            id: task.id,
            milestone_order: task.milestone_order,
        })),
        previousTasks
    );
};

const handleMilestoneDragEnd = () => {
    draggedMilestone.value = null;
    dragOverMilestone.value = null;
};

const isToday = (date) => {
    const today = new Date();
    return date.getDate() === today.getDate() &&
           date.getMonth() === today.getMonth() &&
           date.getFullYear() === today.getFullYear();
};

const isWeekend = (date) => {
    const day = date.getDay();
    return day === 0 || day === 6;
};
</script>

<template>
    <div class="bg-slate-50 rounded-xl border border-slate-200 shadow-xl flex flex-col h-[750px] overflow-hidden dark:border-slate-700 dark:bg-slate-950 dark:shadow-black/30">
        <!-- Modern Toolbar -->
        <div class="bg-white px-5 py-3 border-b border-slate-200 flex flex-wrap items-center justify-between gap-3 dark:border-slate-700 dark:bg-slate-900">
            <div class="flex items-center space-x-3">
                <div class="p-2 bg-indigo-50 rounded-lg dark:bg-indigo-500/15">
                    <CalendarIcon class="w-5 h-5 text-indigo-600 dark:text-indigo-300" />
                </div>
                <div>
                    <h3 class="text-base font-bold text-slate-900 dark:text-slate-100">Project Timeline</h3>
                    <p class="text-xs text-slate-500 font-medium dark:text-slate-400">Gantt schedule and progress tracking</p>
                </div>
            </div>

            <!-- Stats Summary -->
            <div class="hidden lg:flex items-center space-x-4 px-4 py-1 bg-slate-50 rounded-xl border border-slate-100 dark:border-slate-700/60 dark:bg-slate-800/50">
                <div class="text-center px-2">
                    <p class="text-[9px] uppercase tracking-wider font-bold text-slate-400 dark:text-slate-400">Completion</p>
                    <p class="text-xs font-black text-indigo-600 dark:text-indigo-300">{{ stats.progress }}%</p>
                </div>
                <div class="h-6 w-px bg-slate-200 dark:bg-slate-700"></div>
                <div class="text-center px-2">
                    <p class="text-[9px] uppercase tracking-wider font-bold text-slate-400 dark:text-slate-400">Total Tasks</p>
                    <p class="text-xs font-black text-slate-800 dark:text-slate-100">{{ stats.total }}</p>
                </div>
                <div class="h-6 w-px bg-slate-200 dark:bg-slate-700"></div>
                <div class="text-center px-2">
                    <p class="text-[9px] uppercase tracking-wider font-bold text-emerald-600 dark:text-emerald-400">Done</p>
                    <p class="text-xs font-black text-emerald-600 dark:text-emerald-400">{{ stats.completed }}</p>
                </div>
                <div class="h-6 w-px bg-slate-200 dark:bg-slate-700"></div>
                <div class="text-center px-2" :title="`How long the plan runs end to end — the first start to the last finish, counted in ${countsEveryDay ? 'calendar' : 'working'} days. Rows that run in parallel share those days, so this is not the sum of every row's lead time.`">
                    <p class="text-[9px] uppercase tracking-wider font-bold text-indigo-500 dark:text-indigo-400">Duration</p>
                    <p class="text-xs font-black text-slate-800 dark:text-slate-100">{{ grandTotalLeadTime }} {{ grandTotalLeadTime === 1 ? 'day' : 'days' }}</p>
                </div>
            </div>

            <div class="flex items-center flex-wrap gap-2">
                <span v-if="!canManage && myMilestones.length"
                      class="inline-flex items-center gap-1.5 rounded-lg border border-emerald-200 bg-emerald-50 px-2.5 py-1.5 text-[11px] font-bold text-emerald-700 dark:border-emerald-400/30 dark:bg-emerald-500/10 dark:text-emerald-200"
                      :title="`You own ${myMilestones.join(', ')} — you can add, edit and delete everything inside. Elsewhere you can edit the rows assigned to you.`">
                    <PencilSquareIcon class="w-3.5 h-3.5" />
                    You run {{ myMilestones.length }} milestone{{ myMilestones.length === 1 ? '' : 's' }}
                </span>
                <span v-else-if="!canManage"
                      class="inline-flex items-center gap-1.5 rounded-lg border border-amber-200 bg-amber-50 px-2.5 py-1.5 text-[11px] font-bold text-amber-700 dark:border-amber-400/30 dark:bg-amber-500/10 dark:text-amber-200"
                      title="Only the project owner can change the plan. You can edit the rows assigned to you, and add sub-tasks under your own activities.">
                    <PencilSquareIcon class="w-3.5 h-3.5" />
                    You can edit only your assigned rows
                </span>
                <button v-if="canManage"
                        type="button"
                        @click="quickAssignEnabled = !quickAssignEnabled"
                        :class="[
                            'inline-flex items-center px-3 py-1.5 border text-xs font-bold rounded-lg shadow-xs transition-all active:scale-95',
                            quickAssignEnabled
                                ? 'border-violet-600 bg-violet-600 text-white hover:bg-violet-700'
                                : 'border-violet-200 bg-white text-violet-700 hover:bg-violet-50 dark:border-violet-400/30 dark:bg-slate-900 dark:text-violet-200 dark:hover:bg-violet-500/15'
                        ]">
                    <UserPlusIcon class="w-3.5 h-3.5 mr-1.5" />
                    Quick Assign
                </button>
                <button type="button"
                        @click="showActual = !showActual"
                        :class="[
                            'inline-flex items-center px-3 py-1.5 border text-xs font-bold rounded-lg shadow-xs transition-all active:scale-95',
                            showActual
                                ? 'border-slate-700 bg-slate-700 text-white hover:bg-slate-800'
                                : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800'
                        ]"
                        title="Overlay the reported actual dates on each planned bar. Rows with nothing reported yet draw the plan alone.">
                    <Squares2X2Icon class="w-3.5 h-3.5 mr-1.5" />
                    Planned vs Actual
                </button>
                <a :href="route('projects.gantt-pdf', project.id)"
                   target="_blank"
                   rel="noopener"
                   class="inline-flex items-center rounded-lg border border-emerald-200 bg-white px-3 py-1.5 text-xs font-bold text-emerald-700 shadow-xs transition-all hover:bg-emerald-50 active:scale-95 dark:border-emerald-400/30 dark:bg-slate-900 dark:text-emerald-200 dark:hover:bg-emerald-500/15"
                   title="Export project Gantt chart as PDF">
                    <DocumentChartBarIcon class="mr-1.5 h-3.5 w-3.5" />
                    Export PDF
                </a>
                <button v-if="departmentProgressSummary.length"
                        type="button"
                        @click="showDepartmentSummary = true"
                        class="inline-flex items-center rounded-lg border border-sky-200 bg-white px-3 py-1.5 text-xs font-bold text-sky-700 shadow-xs transition-all hover:bg-sky-50 active:scale-95 dark:border-sky-400/30 dark:bg-slate-900 dark:text-sky-200 dark:hover:bg-sky-500/15"
                        title="View weighted progress by department">
                    <DocumentChartBarIcon class="mr-1.5 h-3.5 w-3.5" />
                    Department Progress
                </button>
                <button v-if="canManage" @click="applyActivityTemplates"
                        class="inline-flex items-center px-3 py-1.5 bg-white border border-indigo-200 hover:bg-indigo-50 text-indigo-700 text-xs font-bold rounded-lg shadow-xs transition-all active:scale-95 disabled:opacity-50 dark:border-indigo-400/30 dark:bg-slate-900 dark:text-indigo-200 dark:hover:bg-indigo-500/15"
                        :disabled="isApplyingTemplates"
                >
                    <DocumentDuplicateIcon class="w-3.5 h-3.5 mr-1.5" />
                    {{ isApplyingTemplates ? 'Applying...' : 'Apply Templates' }}
                </button>
                <button @click="showFilters = !showFilters"
                        :class="[
                            'relative p-1.5 rounded-lg border transition-colors',
                            hasFilters
                                ? 'text-indigo-600 bg-indigo-50 border-indigo-200 dark:bg-indigo-500/20 dark:border-indigo-400/30 dark:text-indigo-200'
                                : 'text-slate-400 border-slate-200 hover:text-indigo-600 hover:bg-indigo-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-indigo-500/15 dark:hover:text-indigo-200'
                        ]"
                        title="Filter the plan by milestone, assigned user, or department">
                    <FunnelIcon class="w-4 h-4" />
                    <span v-if="hasFilters" class="absolute -top-0.5 -right-0.5 h-2 w-2 rounded-full bg-indigo-600"></span>
                </button>
                <button
                    v-if="canStartMilestone"
                    @click="openMilestoneForm"
                    class="inline-flex items-center px-3.5 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold rounded-lg shadow-xs transition-all active:scale-95"
                >
                    <PlusIcon class="w-3.5 h-3.5 mr-1.5" />
                    Add Milestone
                </button>
            </div>
        </div>

        <!-- Compact KPI strip; the established dense Gantt dimensions stay intact. -->
        <div v-if="hasStoreRollout"
             class="flex flex-wrap items-center gap-x-5 gap-y-1 border-b border-cyan-200 bg-cyan-50/80 px-5 py-2 text-[11px] dark:border-cyan-400/25 dark:bg-cyan-500/10">
            <span class="font-black uppercase tracking-wider text-cyan-800 dark:text-cyan-200">Store Rollout</span>
            <span class="font-bold text-slate-700 dark:text-slate-200">
                <strong class="text-cyan-700 dark:text-cyan-300">{{ storeRollout.progress }}%</strong> of target
            </span>
            <span class="font-semibold text-slate-600 dark:text-slate-300">
                {{ storeRollout.completed }} / {{ storeRollout.target }} stores completed
            </span>
            <span class="font-semibold text-slate-500 dark:text-slate-400">
                {{ storeRollout.selected }} / {{ storeRollout.target }} stores selected
            </span>
            <div class="h-1.5 min-w-[120px] flex-1 overflow-hidden rounded-full bg-cyan-100 dark:bg-slate-800">
                <div class="h-full rounded-full bg-cyan-600 transition-all" :style="{ width: `${storeRollout.progress}%` }"></div>
            </div>
        </div>

        <!-- Choose once, then assign project/milestone/activity/sub-task with one click. -->
        <div v-if="quickAssignEnabled && canManage"
             class="border-b border-violet-200 bg-violet-50/80 px-5 py-3 dark:border-violet-400/30 dark:bg-violet-500/10">
            <div class="flex flex-wrap items-end gap-3">
                <div class="min-w-[240px] flex-1">
                    <label class="mb-1 block text-[10px] font-black uppercase tracking-widest text-violet-700 dark:text-violet-200">
                        Assign to
                    </label>
                    <select v-model="quickAssigneeId"
                            class="w-full rounded-xl border-violet-200 bg-white text-sm font-semibold focus:border-violet-500 focus:ring-violet-500 dark:border-violet-400/30 dark:bg-slate-900 dark:text-slate-100">
                        <option value="">Choose a project team member</option>
                        <option v-for="member in internalProjectTeamMembers" :key="member.id" :value="member.id">{{ member.name }}</option>
                        <option value="__unassign__">Clear assignment</option>
                    </select>
                </div>
                <label class="flex h-[38px] items-center gap-2 rounded-xl border border-violet-200 bg-white px-3 text-xs font-bold text-slate-600 dark:border-violet-400/30 dark:bg-slate-900 dark:text-slate-200">
                    <input v-model="quickIncludeSubtasks" type="checkbox" class="rounded border-slate-300 text-violet-600 focus:ring-violet-500">
                    Include sub-tasks
                </label>
                <label class="flex h-[38px] items-center gap-2 rounded-xl border border-violet-200 bg-white px-3 text-xs font-bold text-slate-600 dark:border-violet-400/30 dark:bg-slate-900 dark:text-slate-200">
                    <input v-model="quickOnlyUnassigned" type="checkbox" class="rounded border-slate-300 text-violet-600 focus:ring-violet-500">
                    Only unassigned
                </label>
                <button type="button"
                        @click="quickAssignProject"
                        :disabled="!quickAssignReady || isBulkAssigning"
                        class="inline-flex h-[38px] items-center rounded-xl bg-violet-600 px-4 text-sm font-black text-white shadow-sm transition hover:bg-violet-700 disabled:cursor-not-allowed disabled:opacity-40">
                    <UserPlusIcon class="mr-2 h-4 w-4" />
                    {{ isBulkAssigning ? 'Assigning...' : 'Assign Entire Project' }}
                </button>
                <button type="button" @click="quickAssignEnabled = false"
                        class="h-[38px] rounded-xl px-3 text-xs font-black text-violet-600 hover:bg-violet-100 dark:text-violet-200 dark:hover:bg-violet-500/20">
                    Done
                </button>
            </div>
            <p class="mt-2 text-[11px] font-semibold text-violet-700 dark:text-violet-200">
                Choose a user, then click a milestone's Assign button or any Responsible circle. Activity clicks include its sub-tasks when enabled.
            </p>
            <p v-if="internalProjectTeamMembers.length === 0" class="mt-1 text-[11px] font-bold text-amber-700 dark:text-amber-300">
                Add internal users to the Project Team first; external members cannot receive user assignments.
            </p>
        </div>

        <!-- Filter the visible plan without changing scheduling calculations. -->
        <div v-if="showFilters" class="border-b border-slate-200 bg-slate-50/60 p-4 dark:border-slate-700 dark:bg-slate-900/40">
            <div class="flex flex-col gap-3 md:flex-row md:items-end">
                <div class="flex-1">
                    <label class="mb-1.5 ml-1 block text-[10px] font-bold uppercase tracking-widest text-slate-500 dark:text-slate-300">
                        Milestone
                    </label>
                    <MultiAutocomplete
                        v-model="filterMilestones"
                        :options="milestoneFilterOptions"
                        placeholder="Any milestone"
                    />
                </div>
                <div class="flex-1">
                    <label class="mb-1.5 ml-1 block text-[10px] font-bold uppercase tracking-widest text-slate-500 dark:text-slate-300">
                        Assigned user
                    </label>
                    <MultiAutocomplete
                        v-model="filterUsers"
                        :options="userFilterOptions"
                        placeholder="Any assignee"
                    />
                </div>
                <div class="flex-1">
                    <label class="mb-1.5 ml-1 block text-[10px] font-bold uppercase tracking-widest text-slate-500 dark:text-slate-300">
                        Department
                    </label>
                    <MultiAutocomplete
                        v-model="filterDepartments"
                        :options="departmentFilterOptions"
                        placeholder="Any department"
                    />
                </div>
                <button
                    v-if="hasFilters"
                    type="button"
                    @click="clearFilters"
                    class="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-bold text-slate-500 transition-all hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800"
                >
                    Clear
                </button>
            </div>

            <p v-if="hasFilters" class="mt-2 ml-1 text-[11px] font-semibold text-indigo-600 dark:text-indigo-300">
                Showing {{ visibleRowCount }} of {{ stats.total }} rows. An activity is kept when it or one of its
                sub-tasks matches — totals and scheduling still use the whole plan.
            </p>
        </div>

        <!-- The plan's one task form. Shared with the Weekly Timeline tab, so a
             field or rule changed there changes here too. -->
        <ProjectTaskFormModal
            ref="taskFormModal"
            :project="project"
            :tasks="localTasks"
            :team-members="projectTeamMembers"
            :manual-statuses="manualStatuses"
            :holidays="holidays"
            tab="gantt"
            :before-save="ensureTaskListBoards"
            @saved="onTaskSaved"
        />

        <!-- Main Workspace: Unified Scroll -->
        <div class="flex-1 overflow-auto relative bg-[#fafbfc] dark:bg-slate-950" ref="mainWorkspaceRef">
            <div :style="{ width: (760 + timelineDays.length * 40) + 'px' }" class="relative min-h-full">
                
                <!-- STICKY HEADER ROW -->
                <div class="sticky top-0 z-50 flex h-12 bg-white border-b border-slate-200 dark:border-slate-700 dark:bg-slate-900">
                    <!-- Left Header -->
                    <div class="sticky left-0 z-50 w-[760px] h-full flex items-center bg-slate-50 border-r border-slate-200 shadow-[8px_0_15px_-10px_rgba(0,0,0,0.05)] dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300 dark:shadow-black/20 text-[10px] font-bold text-slate-500 uppercase tracking-widest">
                        <div class="flex w-[480px] items-center justify-between gap-3 px-3">
                            <span>Activity / Sub-task</span>
                            <button type="button"
                                    @click.stop="toggleAllCollapse"
                                    class="inline-flex items-center gap-1 rounded border border-slate-200 bg-white px-2 py-1 text-[9px] font-black tracking-wide text-slate-500 transition-colors hover:border-indigo-200 hover:bg-indigo-50 hover:text-indigo-600 disabled:cursor-not-allowed disabled:opacity-40 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300 dark:hover:border-indigo-400/30 dark:hover:bg-indigo-500/15 dark:hover:text-indigo-200"
                                    :disabled="allMilestoneCategories.length === 0 && collapsibleActivityIds.length === 0"
                                    :title="hasCollapsed ? 'Expand all milestones and activities' : 'Collapse all milestones and activities'">
                                <ChevronRightIcon class="h-3 w-3 transition-transform" :class="hasCollapsed ? '' : 'rotate-90'" />
                                {{ hasCollapsed ? 'Expand All' : 'Collapse All' }}
                            </button>
                        </div>
                        <div class="w-[130px] px-2 text-left">Responsible</div>
                        <div class="w-[150px] px-3 text-right">Status</div>
                    </div>
                    <!-- Right Header (Timeline) -->
                    <div class="flex-1 flex flex-col z-0">
                        <div class="h-6 flex items-center px-2 border-b border-slate-100 text-[10px] font-black text-slate-400 uppercase tracking-widest relative bg-white dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300">
                            <template v-for="(day, idx) in timelineDays" :key="'m'+idx">
                                <div v-if="day.getDate() === 1 || idx === 0" 
                                     class="absolute flex items-center space-x-1.5"
                                     :style="{ left: (idx * 40 + 10) + 'px' }">
                                    <span class="text-slate-900 font-bold dark:text-slate-100">{{ day.toLocaleString('en-US', { month: 'short' }) }}</span>
                                    <span class="text-slate-400 dark:text-slate-500 font-normal">{{ day.getFullYear() }}</span>
                                </div>
                            </template>
                        </div>
                        <div class="h-6 flex text-[10px] font-bold text-slate-500 bg-white dark:bg-slate-900 dark:text-slate-300">
                            <div v-for="(day, idx) in timelineDays" :key="idx" 
                                 :title="holidayNameFor(day) || ''"
                                 class="flex-shrink-0 w-10 flex items-center justify-center border-r border-slate-100 dark:border-slate-800"
                                 :class="[
                                    isWeekend(day) ? 'bg-slate-50/70 text-slate-300 dark:bg-slate-800/60 dark:text-slate-400' : 'text-slate-500 dark:text-slate-300',
                                    isHoliday(day) && !isToday(day) ? 'bg-rose-50 text-rose-400 dark:bg-rose-500/15 dark:text-rose-300' : '',
                                    isToday(day) ? 'bg-indigo-600 text-white z-20 font-black shadow-sm' : ''
                                 ]">
                                {{ day.getDate() }}
                            </div>
                        </div>
                    </div>
                </div>

                <!-- BODY -->
                <div class="relative">
                    <!-- Vertical Grid Lines (Background) -->
                    <div class="absolute inset-0 flex pointer-events-none z-0">
                         <div class="w-[760px] flex-shrink-0"></div>
                         <div v-for="(day, idx) in timelineDays" :key="'grid'+idx" 
                             class="flex-shrink-0 w-10 border-r border-slate-100 h-full dark:border-slate-800"
                             :class="[
                                 isWeekend(day) ? 'bg-slate-50/20 dark:bg-slate-800/30' : '',
                                 isHoliday(day) ? 'bg-rose-50/40 dark:bg-rose-500/10' : '',
                                 isToday(day) ? 'bg-indigo-50/20 dark:bg-indigo-500/10' : ''
                             ]">
                        </div>
                    </div>

                    <!-- Today Indicator Line -->
                    <div v-for="(day, idx) in timelineDays" :key="'line'+idx">
                         <div v-if="isToday(day)" class="absolute h-full w-px bg-indigo-500/40 z-0 pointer-events-none" :style="{ left: (760 + idx * 40 + 20) + 'px' }">
                            <div class="bg-indigo-600 text-[8px] text-white px-1 py-0.5 rounded-b shadow-sm absolute top-0 transform -translate-x-1/2 font-black uppercase tracking-tighter whitespace-nowrap">Today</div>
                         </div>
                    </div>

                    <!-- Rows -->
                    <template v-for="(tasks, category) in visibleGroupedTasks" :key="category">
                        <!-- Category Row -->
                        <div class="flex sticky top-12 z-30"
                             @dragover.prevent="handleMilestoneDragOver(category)"
                             @drop.prevent="handleMilestoneDrop(category)"
                             :class="dragOverMilestone === normaliseCategory(category) ? 'ring-1 ring-inset ring-indigo-300 dark:ring-indigo-400' : ''">
                            <div class="sticky left-0 z-40 w-[760px] h-9 bg-slate-100/95 flex items-center justify-between px-3 border-b border-slate-200 border-r shadow-[8px_0_15px_-10px_rgba(0,0,0,0.05)] dark:border-slate-700 dark:bg-slate-800 dark:shadow-black/20">
                                <div class="flex items-center space-x-2 min-w-0 mr-2">
                                    <button v-if="canManage"
                                            type="button"
                                            :draggable="!isSavingTaskOrder"
                                            @dragstart.stop="handleMilestoneDragStart(category, $event)"
                                            @dragend="handleMilestoneDragEnd"
                                            class="shrink-0 cursor-grab rounded p-0.5 text-slate-400 transition-colors hover:bg-slate-200 hover:text-indigo-600 active:cursor-grabbing dark:text-slate-300 dark:hover:bg-slate-700 dark:hover:text-indigo-300"
                                            title="Drag to reorder milestone">
                                        <ArrowsPointingOutIcon class="h-3.5 w-3.5" />
                                    </button>
                                    <button type="button"
                                            @click.stop="toggleMilestoneCollapsed(category)"
                                            class="shrink-0 rounded p-0.5 text-slate-400 transition-colors hover:bg-slate-200 hover:text-indigo-600 dark:text-slate-300 dark:hover:bg-slate-700 dark:hover:text-indigo-300"
                                            :title="isMilestoneCollapsed(category) ? `Expand ${category}` : `Collapse ${category}`"
                                            :aria-label="isMilestoneCollapsed(category) ? `Expand ${category}` : `Collapse ${category}`"
                                            :aria-expanded="!isMilestoneCollapsed(category)">
                                        <ChevronRightIcon class="h-3.5 w-3.5 transition-transform" :class="isMilestoneCollapsed(category) ? '' : 'rotate-90'" />
                                    </button>
                                    <span class="text-xs font-black text-slate-700 uppercase tracking-wider truncate max-w-[380px] dark:text-slate-100" :title="category">{{ category }}</span>
                                    <span class="px-1.5 py-0.5 bg-slate-200 text-slate-600 rounded text-[9px] font-bold shrink-0 dark:bg-slate-700 dark:text-slate-200">{{ visibleTaskCount(tasks) }}</span>
                                    <span class="px-1.5 py-0.5 bg-indigo-100 text-indigo-700 rounded text-[9px] font-black uppercase whitespace-nowrap shrink-0 dark:bg-indigo-500/20 dark:text-indigo-200"
                                          :title="`How long this milestone runs — its first start to its last finish. Its rows total ${milestoneLeadTime(tasks)} lead-time days, but rows running in parallel share the same days.`">
                                        {{ milestoneSpanDays(tasks) }} {{ milestoneSpanDays(tasks) === 1 ? 'day' : 'days' }}
                                    </span>
                                    <span v-if="milestoneDepartmentLabel(tasks)"
                                          class="truncate px-1.5 py-0.5 text-[9px] font-black uppercase tracking-wider text-indigo-500 shrink-0 dark:text-indigo-300"
                                          :title="`Department: ${milestoneDepartmentLabel(tasks)}`">
                                        {{ milestoneDepartmentLabel(tasks) }}
                                    </span>
                                    <!-- Who runs this milestone. The owner may add, edit and delete
                                         every activity and sub-task under it. -->
                                    <button v-if="canManageMilestone(category)"
                                            type="button"
                                            @click.stop="openOwnerModal(category)"
                                            class="inline-flex items-center gap-1 shrink-0 rounded border border-emerald-200 bg-white px-1.5 py-0.5 text-[9px] font-black uppercase tracking-wider text-emerald-700 transition-colors hover:bg-emerald-50 dark:border-emerald-400/30 dark:bg-slate-900 dark:text-emerald-200 dark:hover:bg-emerald-500/15"
                                            :title="milestoneOwnerName(category) ? `Owner: ${milestoneOwnerName(category)} — click to change` : 'No owner yet — click to assign one'">
                                        <UserPlusIcon class="h-2.5 w-2.5" />
                                        {{ milestoneOwnerName(category) || 'No owner' }}
                                    </button>
                                    <span v-else-if="milestoneOwnerName(category)"
                                          class="truncate shrink-0 px-1.5 py-0.5 text-[9px] font-black uppercase tracking-wider text-emerald-600 dark:text-emerald-300"
                                          :title="`Milestone owner: ${milestoneOwnerName(category)}`">
                                        {{ milestoneOwnerName(category) }}
                                    </span>
                                </div>
                                <div v-if="canManageMilestone(category)" class="flex items-center gap-1 shrink-0">
                                    <button v-if="quickAssignEnabled && canManage"
                                            type="button"
                                            @click.stop="quickAssignMilestone(category, tasks)"
                                            :disabled="!quickAssignReady || isBulkAssigning"
                                            class="inline-flex items-center rounded border border-violet-200 bg-white px-2 py-0.5 text-[9px] font-black uppercase tracking-wider text-violet-700 transition-colors hover:bg-violet-50 disabled:cursor-not-allowed disabled:opacity-40 dark:border-violet-400/30 dark:bg-slate-900 dark:text-violet-200 dark:hover:bg-violet-500/15">
                                        <UserPlusIcon class="mr-1 h-3 w-3" />
                                        Assign
                                    </button>
                                    <button type="button"
                                            @click.stop="openActivityForm(category)"
                                            class="inline-flex items-center px-2 py-0.5 bg-white border border-indigo-200 text-[10px] font-bold text-indigo-700 uppercase tracking-wider rounded hover:bg-indigo-50 transition-colors dark:border-indigo-400/30 dark:bg-slate-900 dark:text-indigo-200 dark:hover:bg-indigo-500/15">
                                        <PlusIcon class="w-3 h-3 mr-0.5" />
                                        Activity
                                    </button>
                                    <button type="button"
                                            @click.stop="deleteMilestone(category, tasks)"
                                            class="p-1 bg-white border border-red-100 text-red-400 hover:text-red-600 hover:bg-red-50 rounded transition-colors dark:border-red-400/30 dark:bg-slate-900 dark:text-red-300 dark:hover:bg-red-500/15"
                                            title="Delete Milestone">
                                        <TrashIcon class="w-3 h-3" />
                                    </button>
                                </div>
                            </div>
                            <div class="flex-1 h-9 bg-slate-100/30 border-b border-slate-200 dark:border-slate-700 dark:bg-slate-800/40"></div>
                        </div>

                        <!-- Task Rows -->
                        <template v-for="task in (isMilestoneCollapsed(category) ? [] : tasks)" :key="task.id">
                            <div v-for="row in taskRows(task)" :key="row.task.id" @click="editTask(row.task)"
                                 :data-task-row="row.task.id"
                                 @dragover.prevent="handleTaskDragOver(row.task)"
                                 @drop.prevent="handleTaskDrop(row.task)"
                                 :class="[
                                     dragOverTaskId === row.task.id ? 'bg-indigo-50/60 ring-1 ring-inset ring-indigo-200 dark:bg-indigo-500/10 dark:ring-indigo-400/30' : '',
                                     row.isSubTask ? 'min-h-[36px]' : 'min-h-[40px]',
                                     canEditTask(row.task) ? 'cursor-pointer' : 'cursor-default'
                                 ]"
                                  class="flex border-b border-slate-100 hover:bg-indigo-50/15 group transition-colors relative z-10 dark:border-slate-800 dark:hover:bg-indigo-500/5">
                                
                                <!-- Left Task Info (Sticky) -->
                                <div class="sticky left-0 z-30 w-[760px] flex items-center border-r border-slate-200 shadow-[8px_0_15px_-10px_rgba(0,0,0,0.05)] dark:border-slate-800 dark:shadow-black/20"
                                     :class="row.isSubTask ? 'bg-slate-50/90 group-hover:bg-slate-100/70 dark:bg-slate-900/80 dark:group-hover:bg-slate-800' : 'bg-white group-hover:bg-slate-50 dark:bg-slate-950 dark:group-hover:bg-slate-900'">
                                    
                                    <!-- Activity / Sub-task Column -->
                                    <div class="w-[480px] flex items-center gap-2 py-1.5" :class="row.isSubTask ? 'pl-9 pr-3' : 'pl-5 pr-3'">
                                        <div class="relative flex-shrink-0" @click.stop>
                                            <input v-if="!hasSubTasks(row.task)"
                                                   type="checkbox"
                                                   :checked="isTaskComplete(row.task)"
                                                   :disabled="!canToggleDone(row.task)"
                                                   @change="toggleTaskDone(row.task)"
                                                   :title="canToggleDone(row.task) ? (isTaskComplete(row.task) ? 'Mark as not done (0%)' : 'Mark as done (100%)') : 'You cannot edit this row'"
                                                   class="w-4 h-4 rounded border border-slate-300 text-emerald-600 cursor-pointer transition-colors focus:ring-1 focus:ring-emerald-500 focus:ring-offset-0 disabled:cursor-not-allowed disabled:opacity-50 dark:border-slate-600 dark:bg-slate-900">
                                            <button v-else
                                                    type="button"
                                                    @click.stop="toggleActivityCollapsed(row.task)"
                                                    class="flex h-4 w-4 items-center justify-center rounded text-slate-400 transition-colors hover:bg-indigo-50 hover:text-indigo-600 dark:text-slate-300 dark:hover:bg-indigo-500/15 dark:hover:text-indigo-300"
                                                    :title="isActivityCollapsed(row.task) ? `Expand ${row.task.name}` : `Collapse ${row.task.name}`"
                                                    :aria-label="isActivityCollapsed(row.task) ? `Expand ${row.task.name}` : `Collapse ${row.task.name}`"
                                                    :aria-expanded="!isActivityCollapsed(row.task)">
                                                <ChevronRightIcon class="h-3.5 w-3.5 transition-transform" :class="isActivityCollapsed(row.task) ? '' : 'rotate-90'" />
                                            </button>
                                        </div>
                                        <div v-if="canManage" class="flex items-center shrink-0" @click.stop>
                                            <button type="button"
                                                    :draggable="!isSavingTaskOrder"
                                                    @dragstart="handleTaskDragStart(row.task, $event)"
                                                    @dragend="handleTaskDragEnd"
                                                    class="p-0.5 text-slate-300 hover:text-indigo-500 cursor-grab active:cursor-grabbing transition-colors dark:text-slate-500 dark:hover:text-indigo-300"
                                                    title="Drag to reorder task">
                                                <ArrowsPointingOutIcon class="w-3.5 h-3.5" />
                                            </button>
                                        </div>
                                        <span v-if="row.isSubTask" class="text-indigo-600 dark:text-indigo-400 font-bold text-xs shrink-0 select-none mr-0.5">↳</span>
                                        <div class="flex-1 min-w-0">
                                            <div class="flex items-center justify-between gap-2 mb-0.5">
                                                <div class="flex items-center gap-1.5 whitespace-nowrap">
                                                    <div class="font-semibold text-slate-800 dark:text-slate-100 whitespace-nowrap"
                                                         :class="row.isSubTask ? 'text-[11px]' : 'text-xs'"
                                                         :title="row.task.name">
                                                        {{ row.task.name }}
                                                    </div>
                                                    <span v-if="taskStoreLabel(row.task)"
                                                          class="shrink-0 rounded border border-cyan-200 bg-cyan-50 px-1.5 py-0.5 text-[8px] font-black uppercase tracking-wide text-cyan-700 dark:border-cyan-400/30 dark:bg-cyan-500/10 dark:text-cyan-200"
                                                          :title="row.task.store?.name || taskStoreLabel(row.task)">
                                                        {{ taskStoreLabel(row.task) }}
                                                    </span>
                                                </div>
                                                <span class="text-[10px] font-bold text-slate-400 tabular-nums shrink-0 dark:text-slate-400">{{ row.task.progress }}%</span>
                                            </div>
                                            <div class="flex items-center gap-1.5 text-[9px] text-slate-400 dark:text-slate-400 whitespace-nowrap leading-tight">
                                                <span v-if="formatDateRange(row.task.start_date, row.task.end_date)" class="font-medium text-slate-500 dark:text-slate-400">
                                                    {{ formatDateRange(row.task.start_date, row.task.end_date) }}
                                                </span>
                                                <span v-if="formatDateRange(row.task.start_date, row.task.end_date) && (taskOrganizationLabel(row.task) || row.task.lead_time_days)">·</span>
                                                <span v-if="taskOrganizationLabel(row.task)" class="font-bold text-indigo-500 whitespace-nowrap">
                                                    {{ taskOrganizationLabel(row.task) }}
                                                </span>
                                            </div>
                                            <div v-if="row.isSubTask && row.task.tickets?.length && hasPermission('tickets.view')"
                                                 class="mt-1 flex min-w-0 items-center gap-1"
                                                 @click.stop>
                                                <TicketIcon class="h-3 w-3 shrink-0 text-sky-500" />
                                                <a v-for="ticket in row.task.tickets.slice(0, 2)"
                                                   :key="ticket.id"
                                                   :href="ticketEditUrl(ticket)"
                                                   target="_blank"
                                                   rel="noopener noreferrer"
                                                   class="inline-flex shrink-0 items-center gap-0.5 rounded border border-sky-200 bg-sky-50 px-1 py-0.5 text-[8px] font-black text-sky-700 hover:bg-sky-100 hover:text-sky-900 dark:border-sky-400/30 dark:bg-sky-500/10 dark:text-sky-200"
                                                   :title="`${ticket.ticket_key}: ${ticket.title}`">
                                                    {{ ticket.ticket_key }}
                                                    <ArrowTopRightOnSquareIcon class="h-2.5 w-2.5" />
                                                </a>
                                                <button v-if="row.task.tickets.length > 2"
                                                        type="button"
                                                        @click="openTicketDetails(row.task)"
                                                        class="shrink-0 text-[8px] font-black text-sky-600 hover:underline dark:text-sky-300">
                                                    +{{ row.task.tickets.length - 2 }} more
                                                </button>
                                            </div>
                                            <div class="w-full bg-slate-100 h-1 rounded-full overflow-hidden mt-0.5 dark:bg-slate-800">
                                                <div class="h-full transition-all duration-300" 
                                                     :class="getBarColorClass(row.task.status)" 
                                                     :style="{ width: row.task.progress + '%' }"></div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Responsible Column -->
                                    <div class="w-[130px] px-2 py-1 flex items-center justify-start gap-1.5"
                                         @click.stop="quickAssignEnabled ? quickAssignActivity(row.task) : editTask(row.task)"
                                         :title="quickAssignEnabled ? (row.isSubTask ? 'Assign this sub-task' : 'Assign this activity and optionally its sub-tasks') : 'Edit assignment'"
                                         :class="quickAssignEnabled ? 'cursor-copy rounded-lg hover:bg-violet-100 dark:hover:bg-violet-500/15' : 'cursor-pointer'">
                                        <div v-if="getAssigneeName(row.task)" class="h-6 w-6 shrink-0 rounded-md bg-indigo-100 flex items-center justify-center text-[10px] font-bold text-indigo-700 border border-indigo-200 dark:border-indigo-400/30 dark:bg-indigo-500/15 dark:text-indigo-200" :title="getAssigneeName(row.task)">
                                            {{ getAssigneeInitial(row.task) }}
                                        </div>
                                        <div v-else class="h-6 w-6 shrink-0 rounded-md border border-dashed border-slate-300 flex items-center justify-center text-[10px] text-slate-400 dark:border-slate-700 dark:text-slate-500">?</div>
                                        <span class="text-[11px] font-medium text-slate-700 truncate max-w-[65px] dark:text-slate-300" :title="getAssigneeName(row.task) || 'Unassigned'">
                                            {{ getAssigneeName(row.task) || 'Unassigned' }}
                                        </span>
                                        <button v-if="quickAssignEnabled && canManage"
                                                type="button"
                                                @click.stop="quickAssignActivity(row.task)"
                                                :disabled="!quickAssignReady || isBulkAssigning"
                                                class="inline-flex items-center rounded border border-violet-200 bg-white px-1 py-0.5 text-[8px] font-black uppercase tracking-wide text-violet-700 transition hover:bg-violet-50 disabled:cursor-not-allowed disabled:opacity-40 shrink-0 dark:border-violet-400/30 dark:bg-slate-900 dark:text-violet-200 dark:hover:bg-violet-500/15"
                                                :title="row.isSubTask ? 'Assign this sub-task' : (quickIncludeSubtasks ? 'Assign this activity and its sub-tasks' : 'Assign this activity only')">
                                            <UserPlusIcon class="h-2.5 w-2.5" />
                                        </button>
                                    </div>

                                    <!-- Status & Actions Column -->
                                    <div class="w-[150px] px-3 py-1 flex items-center justify-between gap-1">
                                        <div class="shrink-0">
                                            <span class="px-2 py-0.5 border rounded-full text-[9px] font-bold uppercase tracking-wider inline-block text-center shadow-2xs"
                                                  :class="getStatusStyles(row.task.status)">
                                                {{ row.task.status }}
                                            </span>
                                        </div>
                                        <div v-if="canAddSubTaskTo(row.task) || canDeleteTask(row.task) || (row.isSubTask && (hasPermission('tickets.create') || (hasPermission('tickets.view') && row.task.tickets?.length)))"
                                             class="flex items-center gap-0.5 shrink-0 transition-opacity"
                                             :class="row.isSubTask && hasPermission('tickets.create') ? 'opacity-100' : 'opacity-0 group-hover:opacity-100'">
                                            <button v-if="row.isSubTask && hasPermission('tickets.view') && row.task.tickets?.length"
                                                    type="button"
                                                    @click.stop="openTicketDetails(row.task)"
                                                    class="inline-flex items-center gap-0.5 rounded bg-sky-50 px-1 py-0.5 text-[8px] font-black text-sky-700 hover:bg-sky-100 dark:bg-sky-500/10 dark:text-sky-200"
                                                    :title="`View ${row.task.tickets.length} linked ticket${row.task.tickets.length === 1 ? '' : 's'}`">
                                                <TicketIcon class="h-3 w-3" />
                                                {{ row.task.tickets.length }}
                                            </button>
                                            <button v-if="row.isSubTask && hasPermission('tickets.create')"
                                                    type="button"
                                                    @click.stop="openTicketCreate(row.task)"
                                                    class="p-1 text-sky-500 hover:text-sky-700 hover:bg-sky-50 rounded transition-colors dark:hover:bg-sky-500/20"
                                                    title="Create ticket for this sub-task">
                                                <TicketIcon class="w-3.5 h-3.5" />
                                            </button>
                                            <button v-if="canAddSubTaskTo(row.task)"
                                                    @click.stop="openSubTaskForm(row.task)"
                                                    class="p-1 text-indigo-500 hover:text-indigo-700 hover:bg-indigo-50 rounded transition-colors dark:hover:bg-indigo-500/20"
                                                    title="Add Sub-task">
                                                <PlusIcon class="w-3.5 h-3.5" />
                                            </button>
                                            <button v-if="canDeleteTask(row.task)"
                                                    @click.stop="deleteTask(row.task.id)"
                                                    class="p-1 text-red-400 hover:text-red-600 hover:bg-red-50 rounded transition-colors dark:hover:bg-red-500/20"
                                                    :title="row.isSubTask ? 'Delete Sub-task' : 'Delete Activity'">
                                                <TrashIcon class="w-3.5 h-3.5" />
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <!-- Right Gantt Bar Area -->
                                <div class="flex-1 relative">
                                    <div class="absolute inset-0 grid h-full py-1.5 px-[1px] items-center" :style="{ gridTemplateColumns: `repeat(${timelineDays.length}, 40px)` }">
                                        <div v-if="row.task.start_date && row.task.end_date"
                                             class="rounded-md relative overflow-hidden group/bar transition-all hover:scale-[1.01] hover:shadow-md cursor-pointer z-10 flex items-center"
                                             :class="row.isSubTask ? 'h-5 opacity-90' : 'h-6'"
                                             :style="getGanttBarStyles(row.task)"
                                        >
                                            <!-- Planned: the row's full scheduled span -->
                                            <div class="absolute inset-0" :class="getBarColorClass(row.task.status)"></div>
                                            <!-- Fallback progress fill for rows with no reported actual dates yet -->
                                            <div v-if="row.task.progress > 0 && !hasActualBar(row.task)"
                                                 class="actual-hatch absolute left-0.5 top-1/2 -translate-y-1/2 rounded-[2px] overflow-hidden pointer-events-none z-10"
                                                 :style="{ width: `calc(${Math.min(100, Math.max(0, row.task.progress))}% - 4px)` }"></div>
                                            <!-- Retained percentage label text centered vertically on the right -->
                                            <div class="absolute inset-0 flex items-center justify-end px-1.5 pointer-events-none z-20">
                                                <span class="text-[9px] font-bold text-white drop-shadow-[0_1px_2px_rgba(0,0,0,0.9)] whitespace-nowrap tabular-nums">{{ row.task.progress }}%</span>
                                            </div>
                                            <div class="absolute bottom-full left-1/2 transform -translate-x-1/2 mb-1.5 px-2 py-1 bg-slate-800 text-white text-[9px] rounded-md opacity-0 group-hover/bar:opacity-100 pointer-events-none transition-opacity whitespace-nowrap z-50 font-bold shadow-lg">
                                                {{ row.task.name }}
                                                <span class="block font-semibold text-slate-300">
                                                    Planned: {{ row.task.start_date.split('T')[0] }} to {{ row.task.end_date.split('T')[0] }}
                                                </span>
                                                <span class="block font-semibold text-amber-200">
                                                    {{ hasActualBar(row.task) ? actualTooltip(row.task) : `Actual: ${row.task.progress}% complete` }}
                                                </span>
                                            </div>
                                        </div>

                                        <!-- Actual (Reported) Layer: spans according to actual reported dates, centered vertically in the row -->
                                        <div v-if="hasActualBar(row.task)"
                                             class="actual-hatch self-center rounded-[2px] z-30 pointer-events-auto cursor-help"
                                             :class="actualSpan(row.task).inProgress ? 'actual-hatch--open' : ''"
                                             :style="getActualBarStyles(row.task)"
                                             :title="actualTooltip(row.task)"
                                             @click.stop>
                                        </div>
                                    </div> 
                                </div>
                            </div>
                        </template>
                    </template>
                </div>
            </div>
        </div>

        <!-- Footer / Shortcuts -->
        <div class="h-10 bg-white border-t border-slate-200 px-6 flex items-center justify-between text-[10px] text-slate-400 font-bold uppercase tracking-widest dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300">
            <div class="flex items-center space-x-6">
                <div class="flex items-center space-x-2">
                    <div class="w-2 h-2 rounded-full bg-indigo-500"></div>
                    <span>Project Plan</span>
                </div>
                <div class="flex items-center space-x-2">
                    <div class="w-2 h-2 rounded-full bg-emerald-500"></div>
                    <span>Completed</span>
                </div>
                <div class="flex items-center space-x-2">
                    <div class="w-2 h-2 rounded-full bg-slate-200 dark:bg-slate-600"></div>
                    <span>Weekend</span>
                </div>
                <div class="flex items-center space-x-2">
                    <div class="actual-hatch w-5 rounded-sm"></div>
                    <span>Actual</span>
                </div>
                <div class="flex items-center space-x-2">
                    <div class="h-2.5 w-5 rounded-sm bg-indigo-500"></div>
                    <span>Planned</span>
                </div>
            </div>
            <div class="flex items-center space-x-2">
                <ClockIcon class="w-3 h-3" />
                <span>Last updated: {{ new Date().toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'}) }}</span>
            </div>
        </div>

        <!-- Template Selection Modal -->
        <Modal :show="showDepartmentSummary" @close="showDepartmentSummary = false" maxWidth="5xl">
            <div class="p-6 dark:bg-slate-900">
                <div class="mb-5 flex items-start justify-between gap-4">
                    <div>
                        <h3 class="text-xl font-black text-slate-900 dark:text-slate-100">Department Progress Summary</h3>
                        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                            Weighted from executable Activities/Sub-Tasks. Parent roll-ups are excluded to prevent double counting.
                        </p>
                    </div>
                    <button type="button" @click="showDepartmentSummary = false"
                            class="rounded-lg p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-slate-800 dark:hover:text-slate-200">
                        <XMarkIcon class="h-5 w-5" />
                    </button>
                </div>

                <div class="grid max-h-[65vh] grid-cols-1 gap-3 overflow-y-auto pr-1 sm:grid-cols-2 lg:grid-cols-3">
                    <button
                        v-for="department in departmentProgressSummary"
                        :key="department.name"
                        type="button"
                        @click="focusDepartmentOnGantt(department.name)"
                        class="rounded-xl border border-slate-200 bg-slate-50 p-4 text-left transition hover:border-indigo-300 hover:bg-indigo-50/60 focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:border-slate-700 dark:bg-slate-800/60 dark:hover:border-indigo-400/50 dark:hover:bg-indigo-500/10"
                        :title="`Show ${department.name} work on the Gantt`"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <span class="text-sm font-black text-slate-800 dark:text-slate-100">{{ department.name }}</span>
                            <span :class="['shrink-0 text-base font-black tabular-nums', departmentProgressTone(department.progress)]">
                                {{ department.progress }}%
                            </span>
                        </div>
                        <div class="mt-3 h-2.5 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700">
                            <div class="h-full rounded-full bg-indigo-500 transition-all duration-500"
                                 :style="{ width: `${department.progress}%` }"></div>
                        </div>
                        <div class="mt-3 flex justify-between text-[11px] font-bold text-slate-500 dark:text-slate-400">
                            <span>{{ department.assignments }} deliverables</span>
                            <span>{{ department.completed }} completed</span>
                        </div>
                    </button>
                </div>

                <p class="mt-4 text-xs font-semibold text-indigo-600 dark:text-indigo-300">
                    Select a department to close this summary and filter the existing dense Gantt view to its rows.
                </p>
            </div>
        </Modal>

        <Modal :show="showTemplateModal" @close="showTemplateModal = false" maxWidth="lg">
            <div class="p-6 dark:bg-slate-900">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-xl font-bold text-slate-900 dark:text-slate-100">Apply Activity Template</h3>
                    <button @click="showTemplateModal = false" class="text-slate-400 hover:text-slate-600 transition-colors dark:text-slate-400 dark:hover:text-slate-200">
                        <XMarkIcon class="w-6 h-6" />
                    </button>
                </div>

                <div class="space-y-4">
                    <p class="text-sm text-slate-600 dark:text-slate-300">Select a predefined activity blueprint to apply to this project. This will automatically create the associated tasks.</p>
                    <p v-if="project.day1_date" class="text-xs font-semibold text-emerald-600 dark:text-emerald-400">
                        Start/End dates will be auto-scheduled from Day 1 Date ({{ formatDisplayDate(project.day1_date) }}) using each row's lead time, counted in {{ countsEveryDay ? 'calendar days (every day)' : 'working days (weekends and PH holidays skipped)' }}.
                    </p>
                    <p v-else class="text-xs font-semibold text-amber-600 dark:text-amber-400">
                        No Day 1 Date is set for this project — applied activities won't get Start/End dates. Set it under Edit Project first to auto-schedule.
                    </p>

                    <div class="space-y-3 max-h-96 overflow-y-auto pr-2 custom-scrollbar">
                        <label v-for="template in projectTemplates" :key="template.id" 
                               :class="[
                                    'relative flex items-center p-4 cursor-pointer rounded-xl border-2 transition-all',
                                    selectedTemplateId === template.id 
                                        ? 'border-indigo-600 bg-indigo-50 shadow-md dark:border-indigo-400 dark:bg-indigo-500/15' 
                                        : 'border-slate-100 hover:border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-950 dark:hover:border-slate-600'
                                ]"
                        >
                            <input type="radio" :value="template.id" v-model="selectedTemplateId" class="sr-only">
                            <div class="flex-1">
                                <div class="flex items-center justify-between mb-1">
                                    <span class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ template.name }}</span>
                                    <span class="px-2 py-0.5 bg-indigo-100 text-indigo-700 text-[10px] font-black uppercase rounded dark:bg-indigo-500/15 dark:text-indigo-200">{{ template.project_type }}</span>
                                </div>
                                <div class="flex items-center text-xs text-slate-500 font-medium space-x-3 dark:text-slate-300">
                                    <span>{{ template.activities_count }} activity rows</span>
                                    <span class="h-1 w-1 bg-slate-300 rounded-full dark:bg-slate-600"></span>
                                    <span>{{ template.store_class }} Class</span>
                                </div>
                            </div>
                            <div v-if="selectedTemplateId === template.id" class="ml-3 text-indigo-600">
                                <CheckCircleIcon class="w-6 h-6 fill-indigo-600 text-white" />
                            </div>
                        </label>
                    </div>

                    <div v-if="templateNeedsStores" class="rounded-xl border border-cyan-200 bg-cyan-50/70 p-4 dark:border-cyan-400/30 dark:bg-cyan-500/10">
                        <div class="mb-2 flex items-center justify-between gap-3">
                            <label class="text-xs font-black uppercase tracking-wider text-cyan-800 dark:text-cyan-200">Rollout Stores</label>
                            <span class="text-[11px] font-bold text-cyan-700 dark:text-cyan-300">
                                {{ selectedStoreIds.length }} / {{ project.target_store_count || 'no target' }} selected
                            </span>
                        </div>
                        <MultiAutocomplete
                            v-model="selectedStoreIds"
                            :options="storeOptions"
                            placeholder="Search and select NONOS stores"
                        />
                        <p class="mt-2 text-[11px] font-semibold text-slate-600 dark:text-slate-300">
                            Standard rows are created once. Each selected store receives its own Per Store activity and sub-tasks. Reapply later to add another rollout wave.
                        </p>
                        <p v-if="project.target_store_count" class="mt-1 text-[10px] font-bold text-cyan-700 dark:text-cyan-300">
                            Overall progress always uses {{ project.target_store_count }} as the denominator, including stores not selected yet.
                        </p>
                    </div>
                </div>

                <div class="flex justify-end space-x-3 pt-6 border-t mt-6 dark:border-slate-700">
                    <SecondaryButton @click="showTemplateModal = false">
                        Cancel
                    </SecondaryButton>
                    <PrimaryButton @click="confirmApplyTemplate" :disabled="!selectedTemplateId || isApplyingTemplates || (templateNeedsStores && selectedStoreIds.length === 0)" class="bg-indigo-600 hover:bg-indigo-700">
                        {{ isApplyingTemplates ? 'Applying...' : 'Apply Template' }}
                    </PrimaryButton>
                </div>
            </div>
        </Modal>

        <!-- Create through the normal ticket store flow so numbering, assignment,
             notifications and SLA metrics remain identical to /tickets. -->
        <Modal :show="showTicketCreateModal" maxWidth="lg" :closeable="!isCreatingTicket" @close="closeTicketCreate">
            <form class="p-6 dark:bg-slate-900" @submit.prevent="createSubTaskTicket">
                <div class="mb-5 flex items-start justify-between gap-4">
                    <div>
                        <div class="flex items-center gap-2">
                            <span class="rounded-lg bg-sky-100 p-2 text-sky-700 dark:bg-sky-500/15 dark:text-sky-200">
                                <TicketIcon class="h-5 w-5" />
                            </span>
                            <h3 class="text-base font-black text-slate-900 dark:text-slate-100">Create Sub-task Ticket</h3>
                        </div>
                        <p class="mt-2 text-xs font-semibold text-slate-500 dark:text-slate-400">
                            {{ activeTicketTask?.name }}
                        </p>
                    </div>
                    <button type="button" @click="closeTicketCreate" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200">
                        <XMarkIcon class="h-5 w-5" />
                    </button>
                </div>

                <div class="space-y-4">
                    <div>
                        <label class="mb-1.5 block text-[10px] font-black uppercase tracking-widest text-slate-500 dark:text-slate-400">
                            SLA Item <span class="text-red-500">*</span>
                        </label>
                        <Autocomplete
                            v-model="ticketForm.item_id"
                            :options="ticketItems"
                            label-key="display_name"
                            value-key="id"
                            :placeholder="isLoadingTicketItems ? 'Loading items...' : 'Select the item that sets the SLA'"
                            :disabled="isLoadingTicketItems"
                            size="sm"
                        />
                        <p v-if="ticketForm.errors.item_id" class="mt-1 text-xs font-semibold text-red-600">{{ ticketForm.errors.item_id }}</p>
                        <p v-else class="mt-1 text-[10px] text-slate-500 dark:text-slate-400">The selected item supplies the ticket priority and SLA targets.</p>
                    </div>

                    <div>
                        <label class="mb-1.5 block text-[10px] font-black uppercase tracking-widest text-slate-500 dark:text-slate-400">Title <span class="text-red-500">*</span></label>
                        <input v-model="ticketForm.title" type="text" maxlength="255" required class="block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-sky-500 focus:ring-sky-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
                        <p v-if="ticketForm.errors.title" class="mt-1 text-xs font-semibold text-red-600">{{ ticketForm.errors.title }}</p>
                    </div>

                    <div>
                        <label class="mb-1.5 block text-[10px] font-black uppercase tracking-widest text-slate-500 dark:text-slate-400">Description</label>
                        <textarea v-model="ticketForm.description" rows="5" class="block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-sky-500 focus:ring-sky-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"></textarea>
                        <p v-if="ticketForm.errors.description" class="mt-1 text-xs font-semibold text-red-600">{{ ticketForm.errors.description }}</p>
                    </div>

                    <div v-if="hasPermission('tickets.assign')">
                        <label class="mb-1.5 block text-[10px] font-black uppercase tracking-widest text-slate-500 dark:text-slate-400">Assign To</label>
                        <Autocomplete v-model="ticketForm.assignee_id" :options="users" label-key="name" value-key="id" placeholder="Leave unassigned" size="sm" />
                        <p v-if="ticketForm.errors.assignee_id" class="mt-1 text-xs font-semibold text-red-600">{{ ticketForm.errors.assignee_id }}</p>
                    </div>

                    <div class="rounded-lg border border-sky-100 bg-sky-50 p-3 text-[11px] font-semibold text-sky-800 dark:border-sky-400/20 dark:bg-sky-500/10 dark:text-sky-200">
                        You can create more than one ticket for this sub-task. Each ticket will appear beside the row after creation.
                    </div>
                </div>

                <div class="mt-6 flex justify-end gap-3 border-t border-slate-200 pt-5 dark:border-slate-700">
                    <SecondaryButton type="button" @click="closeTicketCreate" :disabled="isCreatingTicket">Cancel</SecondaryButton>
                    <PrimaryButton type="submit" :disabled="isCreatingTicket || !ticketForm.item_id || !ticketForm.title" class="bg-sky-600 hover:bg-sky-700">
                        {{ isCreatingTicket ? 'Creating...' : 'Create Ticket' }}
                    </PrimaryButton>
                </div>
            </form>
        </Modal>

        <Modal :show="showTicketDetailsModal" maxWidth="2xl" @close="closeTicketDetails">
            <div class="p-6 dark:bg-slate-900">
                <div class="mb-5 flex items-start justify-between gap-4">
                    <div>
                        <h3 class="text-base font-black text-slate-900 dark:text-slate-100">Sub-task Tickets</h3>
                        <p class="mt-1 text-xs font-semibold text-slate-500 dark:text-slate-400">{{ activeTicketTask?.name }}</p>
                    </div>
                    <button type="button" @click="closeTicketDetails" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200">
                        <XMarkIcon class="h-5 w-5" />
                    </button>
                </div>

                <div class="max-h-[60vh] space-y-3 overflow-y-auto pr-1">
                    <div v-for="ticket in (activeTicketTask?.tickets || [])" :key="ticket.id" class="rounded-xl border border-slate-200 p-4 dark:border-slate-700 dark:bg-slate-950/50">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <a :href="ticketEditUrl(ticket)" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1 text-sm font-black text-sky-700 hover:underline dark:text-sky-300">
                                    {{ ticket.ticket_key }}
                                    <ArrowTopRightOnSquareIcon class="h-3.5 w-3.5" />
                                </a>
                                <p class="mt-1 text-sm font-semibold text-slate-800 dark:text-slate-100">{{ ticket.title }}</p>
                            </div>
                            <span class="shrink-0 rounded-full bg-slate-100 px-2 py-1 text-[9px] font-black uppercase tracking-wider text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                                {{ ticketStatusLabel(ticket.status) }}
                            </span>
                        </div>
                        <div class="mt-3 grid gap-2 text-[10px] font-semibold text-slate-500 sm:grid-cols-3 dark:text-slate-400">
                            <div><span class="block font-black uppercase tracking-wider">Priority</span>{{ ticket.priority || 'Not set' }}</div>
                            <div><span class="block font-black uppercase tracking-wider">Assignee</span>{{ ticket.assignee?.name || 'Unassigned' }}</div>
                            <div><span class="block font-black uppercase tracking-wider">Resolution target</span>{{ formatTicketDue(ticket.sla_metric?.resolution_target_at) }}</div>
                        </div>
                    </div>
                </div>

                <div class="mt-6 flex justify-between border-t border-slate-200 pt-5 dark:border-slate-700">
                    <button v-if="hasPermission('tickets.create')" type="button" @click="showTicketDetailsModal = false; openTicketCreate(activeTicketTask)" class="inline-flex items-center gap-1.5 rounded-lg bg-sky-600 px-3 py-2 text-xs font-black text-white hover:bg-sky-700">
                        <TicketIcon class="h-4 w-4" />
                        Create Another Ticket
                    </button>
                    <SecondaryButton type="button" class="ml-auto" @click="closeTicketDetails">Close</SecondaryButton>
                </div>
            </div>
        </Modal>

        <!-- Milestone owner: the level above the per-row assignee. -->
        <Modal :show="showOwnerModal" @close="showOwnerModal = false" maxWidth="md">
            <div class="p-6 dark:bg-slate-900">
                <div class="flex items-start justify-between mb-1">
                    <h3 class="text-base font-black text-slate-900 dark:text-slate-100">Milestone Owner</h3>
                    <button type="button" @click="showOwnerModal = false" class="text-slate-400 hover:text-slate-600 transition-colors dark:text-slate-400 dark:hover:text-slate-200">
                        <XMarkIcon class="w-5 h-5" />
                    </button>
                </div>
                <p class="mb-4 text-xs font-semibold text-slate-500 dark:text-slate-400">
                    Who runs <strong class="text-slate-700 dark:text-slate-200">{{ ownerModalCategory }}</strong>. The owner can add, edit and
                    delete every activity and sub-task inside it, and rename or delete the milestone itself.
                </p>

                <label class="mb-1.5 block text-[10px] font-black uppercase tracking-widest text-slate-500 dark:text-slate-400">Owner</label>
                <Autocomplete
                    :model-value="ownerModalUserId"
                    @update:model-value="value => ownerModalUserId = value ?? ''"
                    :options="milestoneOwnerOptions"
                    size="sm"
                    placeholder="Search a project team member"
                />
                <p class="mt-2 text-[10px] font-semibold text-slate-500 dark:text-slate-400">
                    Only project team members can own a milestone. Add them under Manage Team first.
                </p>

                <div class="flex justify-end space-x-3 pt-6 mt-6 border-t dark:border-slate-700">
                    <SecondaryButton @click="showOwnerModal = false">Cancel</SecondaryButton>
                    <PrimaryButton @click="saveMilestoneOwner" :disabled="isSavingOwner" class="bg-indigo-600 hover:bg-indigo-700">
                        {{ isSavingOwner ? 'Saving...' : 'Save Owner' }}
                    </PrimaryButton>
                </div>
            </div>
        </Modal>
    </div>
</template>

<style scoped>
.no-scrollbar::-webkit-scrollbar {
    display: none;
}
.no-scrollbar {
    -ms-overflow-style: none;
    scrollbar-width: none;
}

/* Custom Horizontal Scrollbar */
::-webkit-scrollbar {
    height: 10px;
    width: 6px;
}
::-webkit-scrollbar-track {
    background: #f8fafc;
}
::-webkit-scrollbar-thumb {
    background: #e2e8f0;
    border-radius: 10px;
    border: 2px solid #f8fafc;
}
::-webkit-scrollbar-thumb:hover {
    background: #cbd5e1;
}

/* Scroll Syncing Smoothness */
.scroll-sync {
    will-change: transform;
}
</style>
