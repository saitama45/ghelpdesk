<script setup>
import { ref, computed, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
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
    DocumentDuplicateIcon
} from '@heroicons/vue/24/outline';

import { useToast } from '@/Composables/useToast.js';
import { useConfirm } from '@/Composables/useConfirm.js';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';

const props = defineProps({
    project: Object,
    users: Array,
    projectTemplates: Array,
    taskListTargets: Object,
    // Project managers (creator/admin) may edit every row and all structure.
    canManage: { type: Boolean, default: false },
    // The viewer's user id — non-managers may only edit rows assigned to them.
    currentUserId: { type: [Number, String], default: null },
});

// A non-manager may only edit the activity / sub-task assigned to them; managers
// may edit anything. Structural actions (add/delete/reorder/templates) are
// manager-only and gated on props.canManage directly.
const canEditTask = (task) => {
    if (props.canManage) return true;
    if (!props.currentUserId || !task) return false;
    return Number(task.assigned_to) === Number(props.currentUserId);
};

const { success, info, error } = useToast();
const { confirm: confirmAction } = useConfirm();
const isAddingTask = ref(false);
const isEditing = ref(false);
const editingTaskId = ref(null);
const formMode = ref('activity');
const activeParentTask = ref(null);
const activeMilestone = ref('');
const showFilters = ref(false);
const isApplyingTemplates = ref(false);
const showTemplateModal = ref(false);
const selectedTemplateId = ref('');
const localTasks = ref([]);
const draggedTaskId = ref(null);
const dragOverTaskId = ref(null);

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

const form = useForm({
    project_id: props.project.id,
    parent_task_id: null,
    name: '',
    category: '',
    assigned_to: '',
    status: 'Pending',
    task_progress: 0,
    start_date: '',
    end_date: '',
    lead_time_days: 1,
    milestone_order: null,
    order: null,
});

// Progress entry defaults to a simple Done/Not-done toggle; user can switch to
// typing an exact percentage. isTaskDone reads/writes form.task_progress so a
// value the user never touches (e.g. an existing 45%) is left untouched.
const progressMode = ref('done');
const isTaskDone = computed({
    get: () => Number(form.task_progress) >= 100,
    set: (done) => { form.task_progress = done ? 100 : 0; },
});

// Sync status with progress in the form
watch(() => form.task_progress, (newProgress) => {
    if (newProgress >= 100) {
        form.status = 'Done';
    } else if (newProgress > 0) {
        form.status = 'Ongoing';
    } else {
        form.status = 'Pending';
    }
});

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

const stats = computed(() => {
    const tasks = props.project.tasks || [];
    const total = tasks.length;
    const completed = tasks.filter(t => t.status === 'Done').length;
    const ongoing = tasks.filter(t => t.status === 'Ongoing').length;
    const pending = tasks.filter(t => t.status === 'Pending').length;

    // Calculate average progress
    const totalProgressSum = tasks.reduce((sum, t) => sum + (t.progress || 0), 0);
    const progress = total > 0 ? Math.round(totalProgressSum / total) : 0;

    return { total, completed, ongoing, pending, progress };
});

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

const applyActivityTemplates = () => {
    if (!props.canManage) return;
    if (!props.projectTemplates || props.projectTemplates.length === 0) {
        info('No activity templates are available.');
        return;
    }
    showTemplateModal.value = true;
};

const resetTaskForm = () => {
    form.reset();
    form.project_id = props.project.id;
    form.parent_task_id = null;
    form.name = '';
    form.category = '';
    form.assigned_to = '';
    form.status = 'Pending';
    form.task_progress = 0;
    form.start_date = '';
    form.end_date = '';
    form.lead_time_days = 1;
    form.milestone_order = null;
    form.order = null;
    progressMode.value = 'done';
};

const confirmApplyTemplate = async () => {
    if (!selectedTemplateId.value) {
        error('Please select a template first.');
        return;
    }

    const template = props.projectTemplates.find(t => t.id === selectedTemplateId.value);
    
    // Close selection modal first to allow confirmation dialog to take focus
    showTemplateModal.value = false;

    const ok = await confirmAction({
        title: 'Apply Template',
        message: `Are you sure you want to apply "${template.name}"? This will add ${template.activities_count} activity rows to the project. Existing activities and sub-tasks with the same name will not be duplicated.`,
        confirmLabel: 'Apply now',
        variant: 'primary'
    });

    if (ok) {
        const syncOk = await ensureTaskListBoards();
        if (!syncOk) return;

        isApplyingTemplates.value = true;
        
        router.post(route('projects.apply-templates', props.project.id), {
            project_template_id: selectedTemplateId.value,
            auto_create_monthly_boards: true,
        }, {
            preserveScroll: true,
            onFinish: () => {
                isApplyingTemplates.value = false;
                selectedTemplateId.value = '';
            }
        });
    } else {
        // Optional: Re-open selection if cancelled
        showTemplateModal.value = true;
    }
};

const saveTask = async () => {
    const syncOk = await ensureTaskListBoards();
    if (!syncOk) return;

    if (isEditing.value) {
        form.transform((data) => ({
            ...data,
            parent_task_id: data.parent_task_id || null,
            progress: data.task_progress,
            auto_create_monthly_boards: true,
        })).put(route('projects-tasks.update', { 'projects_task': editingTaskId.value, tab: 'gantt' }), {
            preserveScroll: true,
            onSuccess: () => {
                isAddingTask.value = false;
                isEditing.value = false;
                editingTaskId.value = null;
                formMode.value = 'activity';
                activeParentTask.value = null;
                activeMilestone.value = '';
                resetTaskForm();
            }

        });
    } else {
        form.transform((data) => ({
            ...data,
            parent_task_id: data.parent_task_id || null,
            progress: data.task_progress,
            auto_create_monthly_boards: true,
        })).post(route('projects-tasks.store', { tab: 'gantt' }), {
            preserveScroll: true,
            onSuccess: () => {
                isAddingTask.value = false;
                formMode.value = 'activity';
                activeParentTask.value = null;
                activeMilestone.value = '';
                resetTaskForm();
            },
            onError: (errors) => {
                console.error('Task Creation Failed:', errors);
            }
        });
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

const openMilestoneForm = () => {
    if (!props.canManage) return;
    isEditing.value = false;
    editingTaskId.value = null;
    formMode.value = 'milestone';
    activeParentTask.value = null;
    activeMilestone.value = '';
    resetTaskForm();
    form.milestone_order = getNextMilestoneOrder();
    form.order = getNextOrder('', null);
    isAddingTask.value = true;
};

const openActivityForm = (category) => {
    if (!props.canManage) return;
    isEditing.value = false;
    editingTaskId.value = null;
    formMode.value = 'activity';
    activeParentTask.value = null;
    activeMilestone.value = category || 'General';
    resetTaskForm();
    form.category = category || 'General';
    form.milestone_order = milestoneOrderFor(form.category);
    form.order = getNextOrder(form.category, null);
    isAddingTask.value = true;
};

const openSubTaskForm = (task) => {
    if (!props.canManage) return;
    isEditing.value = false;
    editingTaskId.value = null;
    formMode.value = 'subtask';
    activeParentTask.value = task;
    activeMilestone.value = task.category || 'General';
    resetTaskForm();
    form.parent_task_id = task.id;
    form.category = task.category || 'General';
    form.milestone_order = task.milestone_order ?? milestoneOrderFor(form.category);
    form.assigned_to = task.assigned_to || task.external_assignment || '';
    form.start_date = task.start_date ? task.start_date.split('T')[0] : '';
    form.end_date = task.end_date ? task.end_date.split('T')[0] : '';
    form.order = getNextOrder(form.category, task.id);
    isAddingTask.value = true;
};

const editTask = (task) => {
    if (!canEditTask(task)) return;
    isEditing.value = true;
    editingTaskId.value = task.id;
    formMode.value = task.parent_task_id ? 'subtask' : 'activity';
    activeParentTask.value = task.parent_task_id
        ? localTasks.value.find(candidate => Number(candidate.id) === Number(task.parent_task_id)) || null
        : null;
    activeMilestone.value = task.category || 'General';
    isAddingTask.value = true;
    
    form.project_id = props.project.id;
    form.parent_task_id = task.parent_task_id || null;
    form.name = task.name;
    form.category = task.category;
    form.milestone_order = task.milestone_order;
    form.assigned_to = task.assigned_to || task.external_assignment || '';
    form.status = task.status;
    form.task_progress = task.progress;
    form.start_date = task.start_date ? task.start_date.split('T')[0] : '';
    form.end_date = task.end_date ? task.end_date.split('T')[0] : '';
    form.lead_time_days = task.lead_time_days || 1;
    form.order = task.order;
    progressMode.value = 'done';
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

const deleteTask = async (taskId) => {
    if (!props.canManage) return;
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
    if (!props.canManage) return;
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

const closeForm = () => {
    isAddingTask.value = false;
    isEditing.value = false;
    editingTaskId.value = null;
    formMode.value = 'activity';
    activeParentTask.value = null;
    activeMilestone.value = '';
    resetTaskForm();
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

    props.project.tasks.forEach(task => {
        if (task.start_date) {
            const s = parseLocalDate(task.start_date);
            if (!minDate || s < minDate) minDate = s;
        }
        if (task.end_date) {
            const e = parseLocalDate(task.end_date);
            if (!maxDate || e > maxDate) maxDate = e;
        }
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
        gridColumnStart: startIndex + 1,
        gridColumnEnd: endIndex + 2
    };
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

const taskRows = (task) => {
    return [
        { task, isSubTask: false, parent: null },
        ...(task.subTasks || []).map(subTask => ({ task: subTask, isSubTask: true, parent: task })),
    ];
};

const visibleTaskCount = (tasks = []) => {
    return tasks.reduce((count, task) => count + 1 + (task.subTasks?.length || 0), 0);
};

const formTitle = computed(() => {
    if (isEditing.value) {
        return formMode.value === 'subtask' ? 'Edit Sub-task' : 'Edit Activity';
    }

    if (formMode.value === 'milestone') return 'Add Milestone';
    if (formMode.value === 'subtask') return 'Add Sub-task';
    return 'Add Activity';
});

const activityFieldLabel = computed(() => {
    return formMode.value === 'subtask' ? 'Sub-task' : 'Activity';
});

const saveButtonLabel = computed(() => {
    if (isEditing.value) return 'Update';
    if (formMode.value === 'milestone') return 'Add';
    return formMode.value === 'subtask' ? 'Add Sub-task' : 'Add Activity';
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

const taskOrganizationLabel = (task) => {
    return [task.department, task.sub_unit].filter(Boolean).join(' / ');
};

const persistTaskOrder = async () => {
    const syncOk = await ensureTaskListBoards();
    if (!syncOk) return;

    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

    fetch(route('projects.tasks.gantt-update'), {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': csrfToken,
        },
        body: JSON.stringify({
            tasks: localTasks.value.map((task, index) => ({
                id: task.id,
                milestone_order: task.milestone_order,
                order: index,
            })),
            auto_create_monthly_boards: true,
        })
    })
        .then(async (response) => {
            if (!response.ok) {
                throw new Error('Failed to update task order.');
            }

            return response.json();
        })
        .then(() => {
            success('Task order updated.');
        })
        .catch(() => {
            info('Unable to save task order.');
        });
};

const handleTaskDragStart = (task) => {
    if (!props.canManage) return; // reordering is a manager-only action
    draggedTaskId.value = task.id;
};

const handleTaskDragOver = (task) => {
    if (!props.canManage) return;
    if (!draggedTaskId.value || draggedTaskId.value === task.id) return;
    dragOverTaskId.value = task.id;
};

const handleTaskDrop = (targetTask) => {
    if (!props.canManage) return;
    if (!draggedTaskId.value || draggedTaskId.value === targetTask.id) {
        draggedTaskId.value = null;
        dragOverTaskId.value = null;
        return;
    }

    const reorderedTasks = [...localTasks.value];
    const fromIndex = reorderedTasks.findIndex(task => task.id === draggedTaskId.value);
    const toIndex = reorderedTasks.findIndex(task => task.id === targetTask.id);

    if (fromIndex === -1 || toIndex === -1) {
        draggedTaskId.value = null;
        dragOverTaskId.value = null;
        return;
    }

    const [movedTask] = reorderedTasks.splice(fromIndex, 1);
    reorderedTasks.splice(toIndex, 0, movedTask);
    localTasks.value = reorderedTasks.map((task, index) => ({
        ...task,
        order: index,
    }));

    draggedTaskId.value = null;
    dragOverTaskId.value = null;
    persistTaskOrder();
};

const handleTaskDragEnd = () => {
    draggedTaskId.value = null;
    dragOverTaskId.value = null;
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
        <div class="bg-white px-6 py-4 border-b border-slate-200 flex flex-wrap items-center justify-between gap-4 dark:border-slate-700 dark:bg-slate-900">
            <div class="flex items-center space-x-4">
                <div class="p-2 bg-indigo-50 rounded-lg dark:bg-indigo-500/15">
                    <CalendarIcon class="w-6 h-6 text-indigo-600 dark:text-indigo-300" />
                </div>
                <div>
                    <h3 class="text-lg font-bold text-slate-900 dark:text-slate-100">Project Timeline</h3>
                    <p class="text-xs text-slate-500 font-medium dark:text-slate-300">Manage tasks and schedule visualize</p>
                </div>
            </div>

            <!-- Stats Summary -->
            <div class="hidden lg:flex items-center space-x-6 px-6 border-l border-r border-slate-100 dark:border-slate-700">
                <div class="text-center">
                    <p class="text-[10px] uppercase tracking-wider font-bold text-slate-400 dark:text-slate-300">Completion</p>
                    <p class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ stats.progress }}%</p>
                </div>
                <div class="h-8 w-px bg-slate-100 dark:bg-slate-700"></div>
                <div class="text-center">
                    <p class="text-[10px] uppercase tracking-wider font-bold text-slate-400 dark:text-slate-300">Total Tasks</p>
                    <p class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ stats.total }}</p>
                </div>
                <div class="h-8 w-px bg-slate-100 dark:bg-slate-700"></div>
                <div class="text-center">
                    <p class="text-[10px] uppercase tracking-wider font-bold text-emerald-500 dark:text-emerald-300">Done</p>
                    <p class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ stats.completed }}</p>
                </div>
            </div>

            <div class="flex items-center space-x-2">
                <span v-if="!canManage"
                      class="inline-flex items-center gap-1.5 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-[11px] font-bold text-amber-700 dark:border-amber-400/30 dark:bg-amber-500/10 dark:text-amber-200"
                      title="Only the project owner can change the plan. You can edit the rows assigned to you.">
                    <PencilSquareIcon class="w-4 h-4" />
                    You can edit only your assigned rows
                </span>
                <button v-if="canManage" @click="applyActivityTemplates"
                        class="inline-flex items-center px-4 py-2 bg-white border border-indigo-200 hover:bg-indigo-50 text-indigo-700 text-sm font-bold rounded-lg shadow-sm transition-all transform active:scale-95 disabled:opacity-50 dark:border-indigo-400/30 dark:bg-slate-900 dark:text-indigo-200 dark:hover:bg-indigo-500/15"
                        :disabled="isApplyingTemplates"
                >
                    <DocumentDuplicateIcon class="w-4 h-4 mr-2" />
                    {{ isApplyingTemplates ? 'Applying...' : 'Apply Templates' }}
                </button>
                <button @click="showFilters = !showFilters" class="p-2 text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition-colors dark:text-slate-300 dark:hover:bg-indigo-500/15 dark:hover:text-indigo-200">
                    <FunnelIcon class="w-5 h-5" />
                </button>
                <button
                    v-if="canManage"
                    @click="openMilestoneForm"
                    class="inline-flex items-center px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-bold rounded-lg shadow-sm transition-all transform active:scale-95"
                >
                    <PlusIcon class="w-4 h-4 mr-2" />
                    Add Milestone
                </button>
            </div>
        </div>

        <transition
            enter-active-class="transition duration-300 ease-out"
            enter-from-class="transform -translate-y-4 opacity-0"
            enter-to-class="transform translate-y-0 opacity-100"
            leave-active-class="transition duration-200 ease-in"
            leave-from-class="transform translate-y-0 opacity-100"
            leave-to-class="transform -translate-y-4 opacity-0"
        >
            <div v-if="isAddingTask" class="p-6 bg-indigo-50/30 border-b border-indigo-100 z-30 dark:border-indigo-400/20 dark:bg-indigo-500/10">
                <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h4 class="text-sm font-black text-indigo-950 uppercase tracking-widest dark:text-indigo-100">{{ formTitle }}</h4>
                        <p v-if="activeParentTask" class="mt-1 text-xs font-semibold text-slate-500 dark:text-slate-300">
                            Under {{ activeParentTask.name }} in {{ activeMilestone }}
                        </p>
                        <p v-else-if="activeMilestone" class="mt-1 text-xs font-semibold text-slate-500 dark:text-slate-300">
                            Milestone: {{ activeMilestone }}
                        </p>
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-12 gap-x-8 gap-y-4 items-end">
                    <div class="md:col-span-2">
                        <label class="block text-[10px] font-bold text-indigo-900 uppercase tracking-widest mb-1.5 ml-1 dark:text-indigo-200">Milestone</label>
                        <input v-model="form.category" type="text" placeholder="Milestone name" :readonly="formMode === 'subtask' || (formMode !== 'milestone' && !isEditing)" class="w-full text-sm border-slate-200 rounded-xl shadow-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition-all read-only:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 dark:read-only:bg-slate-800">
                        <div v-if="form.errors.category" class="text-red-500 text-[10px] mt-1 ml-1 font-bold italic">{{ form.errors.category }}</div>
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-[10px] font-bold text-indigo-900 uppercase tracking-widest mb-1.5 ml-1 dark:text-indigo-200">{{ activityFieldLabel }}</label>
                        <input v-model="form.name" type="text" placeholder="What needs to be done?" class="w-full text-sm border-slate-200 rounded-xl shadow-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition-all dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100">
                        <div v-if="form.errors.name" class="text-red-500 text-[10px] mt-1 ml-1 font-bold italic">{{ form.errors.name }}</div>
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-[10px] font-bold text-indigo-900 uppercase tracking-widest mb-1.5 ml-1 dark:text-indigo-200">Responsible</label>
                        <select v-model="form.assigned_to" class="w-full text-sm border-slate-200 rounded-xl shadow-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition-all dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100">
                            <option value="">Unassigned</option>
                            <option v-for="member in projectTeamMembers" :key="member.id" :value="member.id">{{ member.name }}</option>
                        </select>
                        <div v-if="form.errors.assigned_to" class="text-red-500 text-[10px] mt-1 ml-1 font-bold italic">{{ form.errors.assigned_to }}</div>
                    </div>
                    <div class="md:col-span-1">
                        <label class="block text-[10px] font-bold text-indigo-900 uppercase tracking-widest mb-1.5 ml-1 dark:text-indigo-200">Lead Time (Days)</label>
                        <input v-model.number="form.lead_time_days" type="number" min="1" class="w-full text-sm border-slate-200 rounded-xl shadow-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition-all dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100">
                        <div v-if="form.errors.lead_time_days" class="text-red-500 text-[10px] mt-1 ml-1 font-bold italic">{{ form.errors.lead_time_days }}</div>
                        <p v-if="isEditing && project.day1_date" class="mt-1 ml-1 text-[9px] font-semibold text-emerald-600 dark:text-emerald-400">Saving will re-chain every row's dates from Day 1.</p>
                        <p v-else-if="isEditing" class="mt-1 ml-1 text-[9px] font-semibold text-amber-600 dark:text-amber-400">No Day 1 Date set on this project — dates won't auto-schedule.</p>
                    </div>
                    <div class="md:col-span-2">
                        <div class="flex items-center justify-between mb-1.5 ml-1">
                            <label class="block text-[10px] font-bold text-indigo-900 uppercase tracking-widest dark:text-indigo-200">Progress</label>
                            <button type="button" @click="progressMode = progressMode === 'done' ? 'manual' : 'done'"
                                    class="text-[9px] font-bold text-indigo-500 hover:text-indigo-700 underline dark:text-indigo-300">
                                {{ progressMode === 'done' ? 'Use %' : 'Use Yes/No' }}
                            </button>
                        </div>
                        <label v-if="progressMode === 'done'"
                               class="flex h-[38px] items-center justify-center gap-2 rounded-xl border border-slate-200 cursor-pointer dark:border-slate-700 dark:bg-slate-900">
                            <input type="checkbox" v-model="isTaskDone" class="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                            <span class="text-xs font-bold text-slate-700 dark:text-slate-200">{{ isTaskDone ? 'Done (100%)' : 'Not done' }}</span>
                        </label>
                        <input v-else v-model="form.task_progress" type="number" min="0" max="100" class="w-full text-sm border-slate-200 rounded-xl shadow-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition-all dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100">
                        <div v-if="form.errors.progress" class="text-red-500 text-[10px] mt-1 ml-1 font-bold italic">{{ form.errors.progress }}</div>
                    </div>
                    <div class="md:col-span-3">
                        <label class="block text-[10px] font-bold text-indigo-900 uppercase tracking-widest mb-1.5 ml-1 dark:text-indigo-200">Timeline</label>
                        <div class="flex items-center space-x-2">
                            <input v-model="form.start_date" type="date" class="w-full text-xs border-slate-200 rounded-xl shadow-sm focus:ring-2 focus:ring-indigo-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100">
                            <span class="text-slate-400 dark:text-slate-300">to</span>
                            <input v-model="form.end_date" type="date" class="w-full text-xs border-slate-200 rounded-xl shadow-sm focus:ring-2 focus:ring-indigo-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100">
                        </div>
                        <div v-if="form.errors.start_date || form.errors.end_date" class="text-red-500 text-[10px] mt-1 ml-1 font-bold italic">{{ form.errors.start_date || form.errors.end_date }}</div>
                    </div>
                    <div class="md:col-span-2 flex items-center space-x-2 pl-4">
                        <button @click="saveTask" :disabled="form.processing" class="flex-1 bg-indigo-600 text-white font-bold py-2.5 rounded-xl hover:bg-indigo-700 shadow-md transition-all active:scale-95 disabled:opacity-50 text-sm whitespace-nowrap">
                            {{ saveButtonLabel }}
                        </button>
                        <button @click="closeForm" class="flex-1 px-3 py-2.5 bg-white text-slate-500 font-bold border border-slate-200 rounded-xl hover:bg-slate-50 transition-all text-sm whitespace-nowrap dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800">
                            Cancel
                        </button>
                    </div>
                </div>
            </div>
        </transition>

        <!-- Main Workspace: Unified Scroll -->
        <div class="flex-1 overflow-auto relative bg-[#fafbfc] dark:bg-slate-950" ref="mainWorkspaceRef">
            <div :style="{ width: (480 + timelineDays.length * 48) + 'px' }" class="relative min-h-full">
                
                <!-- STICKY HEADER ROW -->
                <div class="sticky top-0 z-50 flex h-14 bg-white border-b border-slate-200 dark:border-slate-700 dark:bg-slate-900">
                    <!-- Left Header -->
                    <div class="sticky left-0 z-50 w-[480px] h-full flex items-center bg-slate-50 px-4 text-[10px] font-bold text-slate-500 uppercase tracking-widest border-r border-slate-200 shadow-[8px_0_15px_-10px_rgba(0,0,0,0.05)] dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300 dark:shadow-black/20">
                        <div class="w-1/2">Activity</div>
                        <div class="w-1/4 px-2 text-center">Responsible</div>
                        <div class="w-1/4 pl-2 pr-6 text-right">Status</div>
                    </div>
                    <!-- Right Header (Timeline) -->
                    <div class="flex-1 flex flex-col z-0">
                        <div class="h-7 flex items-center px-4 border-b border-slate-100 text-[10px] font-black text-slate-400 uppercase tracking-widest relative bg-white dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300">
                            <template v-for="(day, idx) in timelineDays" :key="'m'+idx">
                                <div v-if="day.getDate() === 1 || idx === 0" 
                                     class="absolute flex items-center space-x-2"
                                     :style="{ left: (idx * 48 + 16) + 'px' }">
                                    <span class="text-slate-900 dark:text-slate-100">{{ day.toLocaleString('en-US', { month: 'short' }) }}</span>
                                    <span class="text-slate-300 dark:text-slate-400">{{ day.getFullYear() }}</span>
                                </div>
                            </template>
                        </div>
                        <div class="h-7 flex text-[10px] font-bold text-slate-500 bg-white dark:bg-slate-900 dark:text-slate-300">
                            <div v-for="(day, idx) in timelineDays" :key="idx" 
                                 class="flex-shrink-0 w-12 flex items-center justify-center border-r border-slate-100 dark:border-slate-800"
                                 :class="[
                                    isWeekend(day) ? 'bg-slate-50/50 text-slate-300 dark:bg-slate-800/60 dark:text-slate-400' : 'text-slate-400 dark:text-slate-300',
                                    isToday(day) ? 'bg-indigo-600 text-white z-20 rounded-t-sm shadow-lg' : ''
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
                         <div class="w-[480px] flex-shrink-0"></div>
                         <div v-for="(day, idx) in timelineDays" :key="'grid'+idx" 
                             class="flex-shrink-0 w-12 border-r border-slate-100 h-full dark:border-slate-800"
                             :class="[
                                isWeekend(day) ? 'bg-slate-50/10 dark:bg-slate-800/30' : '',
                                isToday(day) ? 'bg-indigo-50/20 dark:bg-indigo-500/10' : ''
                             ]">
                        </div>
                    </div>

                    <!-- Today Indicator Line -->
                    <div v-for="(day, idx) in timelineDays" :key="'line'+idx">
                         <div v-if="isToday(day)" class="absolute h-full w-px bg-indigo-500/30 z-0 pointer-events-none" :style="{ left: (480 + idx * 48 + 24) + 'px' }">
                            <div class="bg-indigo-600 text-[8px] text-white px-1.5 py-0.5 rounded-b shadow-sm absolute top-0 transform -translate-x-1/2 font-black uppercase tracking-tighter whitespace-nowrap">Today</div>
                         </div>
                    </div>

                    <!-- Rows -->
                    <template v-for="(tasks, category) in groupedTasks" :key="category">
                        <!-- Category Row -->
                        <div class="flex sticky top-14 z-30">
                            <div class="sticky left-0 z-40 w-[480px] h-10 bg-slate-100 flex items-center justify-between px-4 border-b border-slate-200 border-r shadow-[8px_0_15px_-10px_rgba(0,0,0,0.05)] dark:border-slate-700 dark:bg-slate-800 dark:shadow-black/20">
                                <div class="flex items-center space-x-2">
                                    <ChevronRightIcon class="w-3 h-3 text-slate-400 transform rotate-90 dark:text-slate-300" />
                                    <span class="text-[11px] font-black text-slate-600 uppercase tracking-wider dark:text-slate-100">{{ category }}</span>
                                    <span class="ml-2 px-1.5 py-0.5 bg-slate-200 text-slate-500 rounded text-[9px] font-bold dark:bg-slate-700 dark:text-slate-200">{{ visibleTaskCount(tasks) }}</span>
                                </div>
                                <div v-if="canManage" class="flex items-center gap-1.5">
                                    <button type="button"
                                            @click.stop="openActivityForm(category)"
                                            class="inline-flex items-center px-2.5 py-1 bg-white border border-indigo-100 text-[10px] font-black text-indigo-700 uppercase tracking-wider rounded-md hover:bg-indigo-50 transition-colors dark:border-indigo-400/30 dark:bg-slate-900 dark:text-indigo-200 dark:hover:bg-indigo-500/15">
                                        <PlusIcon class="w-3.5 h-3.5 mr-1" />
                                        Add Activity
                                    </button>
                                    <button type="button"
                                            @click.stop="deleteMilestone(category, tasks)"
                                            class="p-1.5 bg-white border border-red-100 text-red-400 hover:text-red-600 hover:bg-red-50 rounded-md transition-colors dark:border-red-400/30 dark:bg-slate-900 dark:text-red-300 dark:hover:bg-red-500/15"
                                            title="Delete Milestone">
                                        <TrashIcon class="w-3.5 h-3.5" />
                                    </button>
                                </div>
                            </div>
                            <div class="flex-1 h-10 bg-slate-100/30 border-b border-slate-200 dark:border-slate-700 dark:bg-slate-800/40"></div>
                        </div>

                        <!-- Task Rows -->
                        <template v-for="task in tasks" :key="task.id">
                            <div v-for="row in taskRows(task)" :key="row.task.id" @click="editTask(row.task)"
                                 @dragover.prevent="handleTaskDragOver(row.task)"
                                 @drop.prevent="handleTaskDrop(row.task)"
                                 :class="[
                                     dragOverTaskId === row.task.id ? 'bg-indigo-50/60 ring-1 ring-inset ring-indigo-200 dark:bg-indigo-500/10 dark:ring-indigo-400/30' : '',
                                    row.isSubTask ? 'min-h-[3rem]' : 'min-h-[3.5rem]',
                                    canEditTask(row.task) ? 'cursor-pointer' : 'cursor-default'
                                 ]"
                                  class="flex border-b border-slate-100 hover:bg-indigo-50/10 group transition-colors relative z-10 dark:border-slate-800 dark:hover:bg-indigo-500/5">
                                
                                <!-- Left Task Info (Sticky) -->
                                <div class="sticky left-0 z-30 w-[480px] flex items-center border-r border-slate-200 shadow-[8px_0_15px_-10px_rgba(0,0,0,0.05)] dark:border-slate-800 dark:shadow-black/20"
                                     :class="row.isSubTask ? 'bg-slate-50 group-hover:bg-slate-100/70 dark:bg-slate-900/80 dark:group-hover:bg-slate-800' : 'bg-white group-hover:bg-slate-50 dark:bg-slate-950 dark:group-hover:bg-slate-900'">
                                    <div class="w-1/2 flex items-center space-x-3 py-2" :class="row.isSubTask ? 'pl-9 pr-4' : 'px-4'">
                                        <div class="relative flex-shrink-0" @click.stop>
                                            <div class="w-5 h-5 rounded-full border-2 flex items-center justify-center transition-colors"
                                                  :class="row.task.status === 'Done' ? 'border-emerald-500 bg-emerald-50 dark:bg-emerald-500/15' : 'border-slate-200 group-hover:border-indigo-300 dark:border-slate-700 dark:group-hover:border-indigo-400'">
                                                <CheckCircleIcon v-if="row.task.status === 'Done'" class="w-3.5 h-3.5 text-emerald-600" />
                                            </div>
                                        </div>
                                        <div v-if="canManage" class="flex items-center self-stretch" @click.stop>
                                            <button type="button"
                                                    draggable="true"
                                                    @dragstart="handleTaskDragStart(row.task)"
                                                    @dragend="handleTaskDragEnd"
                                                     class="h-full px-1.5 text-slate-300 hover:text-indigo-500 cursor-grab active:cursor-grabbing transition-colors dark:text-slate-500 dark:hover:text-indigo-300"
                                                    title="Drag to reorder task">
                                                <ArrowsPointingOutIcon class="w-4 h-4" />
                                            </button>
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <div class="flex items-center justify-between mb-0.5">
                                                <div class="font-bold text-slate-700 whitespace-normal break-words leading-tight mr-2 dark:text-slate-100"
                                                     :class="row.isSubTask ? 'text-[12px]' : 'text-[13px]'">
                                                    <span v-if="row.isSubTask" class="mr-1 text-[10px] font-black text-slate-400 uppercase dark:text-slate-300">Sub</span>
                                                    {{ row.task.name }}
                                                </div>
                                                <span class="text-[10px] font-black text-slate-400 tabular-nums flex-shrink-0 dark:text-slate-300">{{ row.task.progress }}%</span>
                                            </div>
                                            <div v-if="!row.isSubTask && taskOrganizationLabel(row.task)" class="mb-1 truncate text-[10px] font-black uppercase tracking-wider text-indigo-500">
                                                {{ taskOrganizationLabel(row.task) }}
                                            </div>
                                            <div class="w-full bg-slate-100 h-1 rounded-full overflow-hidden dark:bg-slate-800">
                                                <div class="h-full transition-all duration-500" 
                                                     :class="getBarColorClass(row.task.status)" 
                                                     :style="{ width: row.task.progress + '%' }"></div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="w-1/4 px-2 text-center py-2">
                                         <div v-if="getAssigneeName(row.task)" class="mx-auto h-7 w-7 rounded-lg bg-indigo-100 flex items-center justify-center text-[10px] font-bold text-indigo-700 border border-indigo-200 dark:border-indigo-400/30 dark:bg-indigo-500/15 dark:text-indigo-200" :title="getAssigneeName(row.task)">
                                            {{ getAssigneeInitial(row.task) }}
                                        </div>
                                        <div v-else class="mx-auto h-7 w-7 rounded-lg border border-dashed border-slate-200 flex items-center justify-center text-slate-300 dark:border-slate-700 dark:text-slate-500">?</div>
                                    </div>
                                    <div class="w-1/4 pl-2 pr-6 flex items-center justify-end gap-2 group/actions relative py-2">
                                        <div class="flex-shrink-0">
                                            <span class="px-3 py-1 border rounded-full text-[10px] font-black uppercase tracking-widest transition-all shadow-sm min-w-[70px] inline-block text-center"
                                                  :class="getStatusStyles(row.task.status)">
                                                {{ row.task.status }}
                                            </span>
                                        </div>
                                        <div v-if="canManage" class="flex items-center">
                                            <button v-if="!row.isSubTask"
                                                    @click.stop="openSubTaskForm(row.task)"
                                                    class="p-1 text-indigo-400 hover:text-indigo-700 transition-colors opacity-40 group-hover:opacity-100 flex-shrink-0"
                                                    title="Add Sub-task">
                                                <PlusIcon class="w-4 h-4" />
                                            </button>
                                            <button @click.stop="deleteTask(row.task.id)" class="p-1 text-red-400 hover:text-red-600 transition-colors opacity-40 group-hover:opacity-100 flex-shrink-0" title="Delete Task">
                                                <TrashIcon class="w-4 h-4" />
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <!-- Right Gantt Bar Area -->
                                <div class="flex-1 relative">
                                    <div class="absolute inset-0 grid h-full py-2.5 px-[1px]" :style="{ gridTemplateColumns: `repeat(${timelineDays.length}, 48px)` }">
                                        <div v-if="row.task.start_date && row.task.end_date"
                                             class="h-full rounded-lg relative overflow-hidden group/bar transition-all hover:scale-[1.01] hover:shadow-lg cursor-pointer z-20"
                                             :class="row.isSubTask ? 'opacity-85' : ''"
                                             :style="getGanttBarStyles(row.task)"
                                             @click="isAddingTask = false"
                                        >
                                            <div class="absolute inset-0" :class="getBarColorClass(row.task.status)"></div>
                                            <div class="absolute top-0 left-0 h-full bg-black/15 flex items-center justify-end pr-2 overflow-hidden" :style="{ width: row.task.progress + '%' }">
                                                <div v-if="row.task.progress > 0" class="h-full w-full bg-gradient-to-r from-transparent to-white/10"></div>
                                            </div>
                                            <div class="absolute inset-0 flex items-center px-2 justify-between gap-1.5">
                                                <span class="text-[10px] font-black text-white truncate shadow-sm tracking-tight flex-1">{{ row.task.name }}</span>
                                                <span class="text-[9px] font-bold text-white/80 whitespace-nowrap">{{ row.task.progress }}%</span>
                                            </div>
                                            <div class="absolute bottom-full left-1/2 transform -translate-x-1/2 mb-2 px-2 py-1 bg-slate-800 text-white text-[9px] rounded opacity-0 group-hover/bar:opacity-100 pointer-events-none transition-opacity whitespace-nowrap z-50 font-bold">
                                                {{ row.task.name }}: {{ row.task.start_date.split('T')[0] }} to {{ row.task.end_date.split('T')[0] }}
                                            </div>
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
            </div>
            <div class="flex items-center space-x-2">
                <ClockIcon class="w-3 h-3" />
                <span>Last updated: {{ new Date().toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'}) }}</span>
            </div>
        </div>

        <!-- Template Selection Modal -->
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
                        Start/End dates will be auto-scheduled from Day 1 Date ({{ formatDisplayDate(project.day1_date) }}) using each row's lead time.
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
                </div>

                <div class="flex justify-end space-x-3 pt-6 border-t mt-6 dark:border-slate-700">
                    <SecondaryButton @click="showTemplateModal = false">
                        Cancel
                    </SecondaryButton>
                    <PrimaryButton @click="confirmApplyTemplate" :disabled="!selectedTemplateId || isApplyingTemplates" class="bg-indigo-600 hover:bg-indigo-700">
                        {{ isApplyingTemplates ? 'Applying...' : 'Apply Template' }}
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
