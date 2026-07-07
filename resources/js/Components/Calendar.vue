<script setup>
import { ref, computed, onMounted, onUnmounted, nextTick, watch } from 'vue';
import { ChevronLeftIcon, ChevronRightIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    events: {
        type: Array,
        default: () => []
    },
    statusFilter: {
        type: Array,
        default: () => []
    },
    concernTypeFilter: {
        type: Array,
        default: () => []
    },
    priorityFilter: {
        type: Array,
        default: () => []
    },
    users: {
        type: Array,
        default: () => []
    },
    compact: {
        type: Boolean,
        default: false
    },
    heightClass: {
        type: String,
        default: 'h-[850px]'
    }
});

const emit = defineEmits(['date-click', 'event-click', 'visible-range-change', 'update:statusFilter', 'update:concernTypeFilter', 'update:priorityFilter', 'add-schedule-for-user']);

const days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
const currentDate = ref(new Date());
const MONTH_VISIBLE_EVENT_LIMIT = 3;

// ── Status filter ────────────────────────────────────────────────────────────
const STATUS_FILTERS = [
    { status: 'On-site',  label: 'On-site',  bg: 'bg-blue-600'    },
    { status: 'Off-site', label: 'Off-site', bg: 'bg-purple-600'  },
    { status: 'WFH',      label: 'WFH',      bg: 'bg-emerald-600' },
    { status: 'SL',       label: 'SL',       bg: 'bg-rose-600'    },
    { status: 'VL',       label: 'VL',       bg: 'bg-amber-500'   },
    { status: 'Restday',  label: 'Rest Day', bg: 'bg-slate-400'   },
    { status: 'Holiday',  label: 'Holiday',  bg: 'bg-yellow-500'  },
    { status: 'Offset',   label: 'Offset',   bg: 'bg-cyan-600'    },
    { status: 'N/A',      label: 'N/A',      bg: 'bg-gray-500'    },
];

const STATUS_ORDER = new Map(STATUS_FILTERS.map((status, index) => [status.status, index]));
const fallbackStatusMeta = { label: 'Unknown', bg: 'bg-gray-500' };

const getStatusMeta = (status) => {
    return STATUS_FILTERS.find(option => option.status === status) ?? {
        label: status || fallbackStatusMeta.label,
        bg: fallbackStatusMeta.bg,
    };
};

// ── Concern Type filter ───────────────────────────────────────────────────────
const CONCERN_TYPE_FILTERS = [
    { key: 'Incident',        label: 'Incident',        bg: 'bg-amber-500'  },
    { key: 'Service Request', label: 'Service Request', bg: 'bg-cyan-600'   },
    { key: 'Problem',         label: 'Problem',         bg: 'bg-rose-600'   },
];

// ── Priority filter ──────────────────────────────────────────────────────────
const PRIORITY_FILTERS = [
    { key: 'urgent', label: 'P1 - Urgent', bg: 'bg-red-600'    },
    { key: 'high',   label: 'P2 - High',   bg: 'bg-orange-500' },
    { key: 'medium', label: 'P3 - Medium', bg: 'bg-yellow-500' },
    { key: 'low',    label: 'P4 - Low',    bg: 'bg-green-600'  },
];

const filteredEvents = computed(() => {
    return props.events.filter(e => {
        const matchesStatus = props.statusFilter.includes(e.status);

        // Concern Type logic: 'none' covers schedules with no ticket / no concern_type
        const concernKey = e.ticket?.concern_type ?? 'none';
        const matchesConcernType = props.concernTypeFilter.includes(concernKey);

        // Priority logic:
        // 1. If no ticket exists, it is strictly 'none' (No Priority).
        // 2. If a ticket exists, it MUST be one of the priority keys.
        //    We default to 'low' if the ticket has no priority set.
        const priorityKey = e.ticket
            ? String(e.ticket.priority || 'low').toLowerCase()
            : 'none';

        const matchesPriority = props.priorityFilter.includes(priorityKey);

        return matchesStatus && matchesConcernType && matchesPriority;
    });
});

const allStatusSelected = computed(() => props.statusFilter.length === STATUS_FILTERS.length);
const allConcernTypeSelected = computed(() => props.concernTypeFilter.length === (CONCERN_TYPE_FILTERS.length + 1));
const allPrioritySelected = computed(() => props.priorityFilter.length === (PRIORITY_FILTERS.length + 1));

const toggleStatus = (status) => {
    let newVal = [...props.statusFilter];
    if (newVal.includes(status)) {
        newVal = newVal.filter(s => s !== status);
    } else {
        newVal.push(status);
    }
    emit('update:statusFilter', newVal);
};

const togglePriority = (priority) => {
    let newVal = [...props.priorityFilter];
    if (newVal.includes(priority)) {
        newVal = newVal.filter(p => p !== priority);
    } else {
        newVal.push(priority);
    }
    emit('update:priorityFilter', newVal);
};

const toggleAllStatuses = () => {
    emit('update:statusFilter', allStatusSelected.value ? [] : STATUS_FILTERS.map(s => s.status));
};

const toggleConcernType = (key) => {
    let newVal = [...props.concernTypeFilter];
    if (newVal.includes(key)) {
        newVal = newVal.filter(c => c !== key);
    } else {
        newVal.push(key);
    }
    emit('update:concernTypeFilter', newVal);
};

const toggleAllConcernTypes = () => {
    emit('update:concernTypeFilter', allConcernTypeSelected.value ? [] : ['none', ...CONCERN_TYPE_FILTERS.map(c => c.key)]);
};

const toggleAllPriorities = () => {
    emit('update:priorityFilter', allPrioritySelected.value ? [] : ['none', ...PRIORITY_FILTERS.map(p => p.key)]);
};
// ─────────────────────────────────────────────────────────────────────────────

// ── Unscheduled users ─────────────────────────────────────────────────────────
const allEventsByDate = computed(() => {
    const grouped = new Map()
    for (const event of props.events) {
        for (const key of getEventDateKeys(event)) {
            if (!grouped.has(key)) grouped.set(key, new Set())
            grouped.get(key).add(event.user_id)
        }
    }
    return grouped
})
// ─────────────────────────────────────────────────────────────────────────────

// ── Day view state ────────────────────────────────────────────────────────────
const HOUR_HEIGHT = 64; // px per hour (24 × 64 = 1536 px total grid)
const calendarView = ref('month'); // 'month' | 'day'
const currentDayDate = ref(new Date());
const dayScrollRef = ref(null);
const nowDate = ref(new Date());

const dayHeaderLabel = computed(() =>
    new Intl.DateTimeFormat('en-US', {
        weekday: 'long', month: 'long', day: 'numeric', year: 'numeric',
    }).format(currentDayDate.value)
);

const isViewingToday = computed(() =>
    currentDayDate.value.toDateString() === nowDate.value.toDateString()
);

const currentTimeTop = computed(() => {
    const mins = nowDate.value.getHours() * 60 + nowDate.value.getMinutes();
    return (mins / 60) * HOUR_HEIGHT;
});

const formatHourLabel = (h) => {
    if (h === 0)  return '12 AM';
    if (h < 12)   return `${h} AM`;
    if (h === 12) return '12 PM';
    return `${h - 12} PM`;
};

const prevDay = () => {
    const d = new Date(currentDayDate.value);
    d.setDate(d.getDate() - 1);
    currentDayDate.value = d;
};

const nextDay = () => {
    const d = new Date(currentDayDate.value);
    d.setDate(d.getDate() + 1);
    currentDayDate.value = d;
};

const scrollDayToTime = () => {
    if (!dayScrollRef.value) return;
    const mins = nowDate.value.getHours() * 60 + nowDate.value.getMinutes();
    dayScrollRef.value.scrollTop = Math.max(0, (mins / 60) * HOUR_HEIGHT - 200);
};

const switchToDay = (date) => {
    currentDayDate.value = new Date(date);
    calendarView.value = 'day';
    nextTick(() => scrollDayToTime());
};

const dailyEventsLayout = computed(() => {
    const dayEvents = getEventsForDate(currentDayDate.value);
    if (!dayEvents.length) return [];

    const sorted = [...dayEvents].sort((a, b) => {
        const sd = new Date(a.start_time) - new Date(b.start_time);
        if (sd !== 0) return sd;
        return (new Date(b.end_time) - new Date(b.start_time)) - (new Date(a.end_time) - new Date(a.start_time));
    });

    // Greedy column assignment
    const colEnds = [];
    const assignments = [];
    for (const event of sorted) {
        const startMs = new Date(event.start_time).getTime();
        let placed = false;
        for (let c = 0; c < colEnds.length; c++) {
            if (colEnds[c] <= startMs) {
                colEnds[c] = new Date(event.end_time).getTime();
                assignments.push({ event, col: c });
                placed = true;
                break;
            }
        }
        if (!placed) {
            assignments.push({ event, col: colEnds.length });
            colEnds.push(new Date(event.end_time).getTime());
        }
    }

    const totalCols = Math.max(1, colEnds.length);
    return assignments.map(({ event, col }) => {
        const start = new Date(event.start_time);
        const end   = new Date(event.end_time);
        const startMins = start.getHours() * 60 + start.getMinutes();
        let   endMins   = end.getHours()   * 60 + end.getMinutes();
        if (endMins <= startMins) endMins = startMins + 60;
        const top      = (startMins / 60) * HOUR_HEIGHT;
        const height   = Math.max(((endMins - startMins) / 60) * HOUR_HEIGHT, 28);
        const leftPct  = (col / totalCols) * 100;
        const widthPct = (1 / totalCols) * 100;
        return { event, col, totalCols, top, height, leftPct, widthPct };
    });
});
// ─────────────────────────────────────────────────────────────────────────────

const currentMonth = computed(() => currentDate.value.getMonth());
const currentYear = computed(() => currentDate.value.getFullYear());

const toDateKey = (value) => {
    return new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Manila' }).format(new Date(value));
};

const parseDateKey = (key) => {
    const [year, month, day] = key.split('-').map(Number);
    return new Date(year, month - 1, day);
};

const getEventDateKeys = (event) => {
    if (event.calendar_date_key) {
        return [event.calendar_date_key];
    }

    const keys = [];
    let cursor = parseDateKey(toDateKey(event.start_time));
    const end = parseDateKey(toDateKey(event.end_time));

    while (cursor <= end) {
        keys.push(toDateKey(cursor));
        cursor.setDate(cursor.getDate() + 1);
    }

    return keys;
};

const sortEvents = (events) => {
    return [...events].sort((a, b) => {
        const priorityRank = { urgent: 1, high: 2, medium: 3, low: 4 };
        const rankA = a.ticket?.priority ? (priorityRank[String(a.ticket.priority).toLowerCase()] ?? 5) : 6;
        const rankB = b.ticket?.priority ? (priorityRank[String(b.ticket.priority).toLowerCase()] ?? 5) : 6;
        if (rankA !== rankB) return rankA - rankB;

        const durationA = new Date(a.end_time) - new Date(a.start_time);
        const durationB = new Date(b.end_time) - new Date(b.start_time);
        if (durationB !== durationA) return durationB - durationA;

        return new Date(a.start_time) - new Date(b.start_time);
    });
};

const monthName = computed(() => {
    return new Intl.DateTimeFormat('en-US', { month: 'short' }).format(currentDate.value);
});

const calendarDays = computed(() => {
    const firstDayOfMonth = new Date(currentYear.value, currentMonth.value, 1);
    const lastDayOfMonth = new Date(currentYear.value, currentMonth.value + 1, 0);
    
    const startDay = firstDayOfMonth.getDay();
    const totalDays = lastDayOfMonth.getDate();
    
    const daysArray = [];
    
    // Previous month blank cells keep weekday alignment without showing other months.
    for (let i = 0; i < startDay; i++) {
        daysArray.push({
            date: null,
            isCurrentMonth: false,
            isBlank: true
        });
    }
    
    // Current month days
    for (let i = 1; i <= totalDays; i++) {
        daysArray.push({
            date: new Date(currentYear.value, currentMonth.value, i),
            isCurrentMonth: true,
            isBlank: false
        });
    }
    
    // Next month blank cells keep a stable six-row month grid.
    const remainingSlots = 42 - daysArray.length;
    for (let i = 1; i <= remainingSlots; i++) {
        daysArray.push({
            date: null,
            isCurrentMonth: false,
            isBlank: true
        });
    }
    
    return daysArray;
});

const weeks = computed(() => {
    const days = calendarDays.value;
    const weekArray = [];
    for (let i = 0; i < days.length; i += 7) {
        weekArray.push(days.slice(i, i + 7));
    }
    return weekArray;
});

const eventsByDate = computed(() => {
    const grouped = new Map();

    for (const event of filteredEvents.value) {
        for (const key of getEventDateKeys(event)) {
            if (!grouped.has(key)) grouped.set(key, []);
            grouped.get(key).push(event);
        }
    }

    for (const [key, events] of grouped.entries()) {
        grouped.set(key, sortEvents(events));
    }

    return grouped;
});

const getEventsForDate = (date) => {
    return eventsByDate.value.get(toDateKey(date)) ?? [];
};

const getActualTimesForDate = (event, date) => {
    if (!event || !date) {
        return { actual_time_in: null, actual_time_out: null };
    }

    const dateKey = toDateKey(date);

    if (event.actual_times_by_date?.[dateKey]) {
        return event.actual_times_by_date[dateKey];
    }

    return {
        actual_time_in: event.actual_time_in && toDateKey(event.actual_time_in) === dateKey ? event.actual_time_in : null,
        actual_time_out: event.actual_time_out && toDateKey(event.actual_time_out) === dateKey ? event.actual_time_out : null,
    };
};

const getEventStatus = (event, date) => {
    const d = new Date(date);
    d.setHours(0, 0, 0, 0);
    const start = new Date(event.start_time);
    start.setHours(0, 0, 0, 0);
    const end = new Date(event.end_time);
    end.setHours(0, 0, 0, 0);

    return {
        isStart: d.getTime() === start.getTime(),
        isEnd: d.getTime() === end.getTime(),
        isMiddle: d > start && d < end
    };
};

const getEventColor = (status) => {
    switch (status) {
        case 'On-site': return 'bg-blue-600 text-white border-blue-700';
        case 'Off-site': return 'bg-purple-600 text-white border-purple-700';
        case 'WFH': return 'bg-emerald-600 text-white border-emerald-700';
        case 'SL': return 'bg-rose-600 text-white border-rose-700';
        case 'VL': return 'bg-amber-500 text-white border-amber-600';
        case 'Restday': return 'bg-slate-400 text-white border-slate-500';
        case 'Holiday': return 'bg-yellow-500 text-white border-yellow-600';
        case 'Offset': return 'bg-cyan-600 text-white border-cyan-700';
        default: return 'bg-slate-500 text-white border-slate-600';
    }
};

const getTicketPriorityColor = (priority) => {
    switch (String(priority || '').toLowerCase()) {
        case 'urgent': return 'bg-red-600 text-white border-red-700';
        case 'high':   return 'bg-orange-500 text-white border-orange-600';
        case 'medium': return 'bg-yellow-500 text-white border-yellow-600';
        case 'low':    return 'bg-green-600 text-white border-green-700';
        default:       return 'bg-slate-500 text-white border-slate-600';
    }
};

const getChipColor = (event) => {
    if (event.ticket?.priority) {
        return getTicketPriorityColor(event.ticket.priority);
    }
    return getEventColor(event.status);
};

const isUrgentTicket = (event) => {
    return String(event.ticket?.priority || '').toLowerCase() === 'urgent';
};

const formatTime = (dateString) => {
    return new Date(dateString).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
};

const nextMonth = () => {
    currentDate.value = new Date(currentYear.value, currentMonth.value + 1, 1);
};

const prevMonth = () => {
    currentDate.value = new Date(currentYear.value, currentMonth.value - 1, 1);
};

const goToToday = () => {
    if (calendarView.value === 'day') {
        currentDayDate.value = new Date();
        nextTick(() => scrollDayToTime());
    } else {
        currentDate.value = new Date();
    }
};

// ── Go-to-date ────────────────────────────────────────────────────────────────
const goToDateInput = ref('');

const goToDate = () => {
    if (!goToDateInput.value) return;
    const target = new Date(goToDateInput.value + 'T00:00:00'); // suffix avoids UTC-shift
    if (isNaN(target.getTime())) return;

    if (calendarView.value === 'day') {
        switchToDay(target);
    } else {
        currentDate.value = new Date(target.getFullYear(), target.getMonth(), 1);
    }
    goToDateInput.value = '';
};
// ─────────────────────────────────────────────────────────────────────────────

const handleKeydown = (e) => {
    if (e.key === 'Escape' && showDayModal.value) {
        closeDayModal();
    }
};

onMounted(() => {
    setInterval(() => { nowDate.value = new Date(); }, 60000);
    emitVisibleRange();
    document.addEventListener('keydown', handleKeydown);
});

onUnmounted(() => {
    document.removeEventListener('keydown', handleKeydown);
});

const emitVisibleRange = () => {
    if (calendarView.value === 'day') {
        const dayKey = toDateKey(currentDayDate.value);
        emit('visible-range-change', { start: dayKey, end: dayKey });
        return;
    }

    const start = new Date(currentYear.value, currentMonth.value, 1);
    const end = new Date(currentYear.value, currentMonth.value + 1, 0);

    emit('visible-range-change', {
        start: toDateKey(start),
        end: toDateKey(end),
    });
};

watch([calendarView, currentDate, currentDayDate], () => {
    emitVisibleRange();
}, { deep: false });

const isToday = (date) => {
    return date.toDateString() === new Date().toDateString();
};

const showDayModal = ref(false);
const selectedDayDate = ref(null);
const selectedDayEvents = ref([]);
const unscheduledUsers = ref([]);
const expandedModalGroups = ref({});

const toggleModalGroup = (groupId) => {
    expandedModalGroups.value[groupId] = !expandedModalGroups.value[groupId];
};

const selectedDayEventGroups = computed(() => {
    const grouped = new Map();

    for (const event of selectedDayEvents.value) {
        const baseStatus = event.status || fallbackStatusMeta.label;
        let groupKey = baseStatus;
        if (event.store?.name) {
            groupKey = `${baseStatus} (${event.store.name})`;
        }
        
        if (!grouped.has(groupKey)) grouped.set(groupKey, { status: baseStatus, events: [] });
        grouped.get(groupKey).events.push(event);
    }

    return [...grouped.entries()]
        .sort(([keyA, dataA], [keyB, dataB]) => {
            const rankA = STATUS_ORDER.get(dataA.status) ?? STATUS_FILTERS.length;
            const rankB = STATUS_ORDER.get(dataB.status) ?? STATUS_FILTERS.length;

            if (rankA !== rankB) return rankA - rankB;

            return keyA.localeCompare(keyB);
        })
        .map(([key, data]) => {
            const meta = getStatusMeta(data.status);

            return {
                id: key,
                status: data.status,
                label: key,
                colorClass: meta.bg,
                events: data.events,
            };
        });
});

const openDayModal = (date) => {
    selectedDayDate.value = date;
    selectedDayEvents.value = getEventsForDate(date);
    const dateKey = toDateKey(date);
    const scheduledIds = allEventsByDate.value.get(dateKey) ?? new Set();
    unscheduledUsers.value = props.users.filter(u => {
        if (scheduledIds.has(u.id)) return false;
        if (u.date_hired && u.date_hired > dateKey) return false;
        return true;
    });
    
    const initialExpanded = {};
    for (const group of selectedDayEventGroups.value) {
        initialExpanded[group.id] = true;
    }
    expandedModalGroups.value = initialExpanded;
    
    showDayModal.value = true;
};

const closeDayModal = () => {
    showDayModal.value = false;
    unscheduledUsers.value = [];
};

const formatDateLong = (date) => {
    return new Intl.DateTimeFormat('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' }).format(date);
};

const hideTimeStatuses = new Set(['SL', 'VL', 'Restday', 'N/A']);
const shouldShowTime = (status) => !hideTimeStatuses.has(status);
</script>

<template>
    <div
        class="bg-white rounded-2xl shadow-2xl border border-gray-200 overflow-hidden flex flex-col dark:bg-gray-800 dark:border-gray-700"
        :class="heightClass"
    >
        <!-- Calendar Header -->
        <div
            class="border-b border-gray-200 flex flex-col gap-3 bg-white lg:flex-row lg:items-center lg:justify-between dark:bg-gray-800 dark:border-gray-700"
            :class="compact ? 'p-3 lg:p-4' : 'p-6'"
        >
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center" :class="compact ? 'sm:gap-4' : 'sm:gap-6'">
                <!-- Title -->
                <h2 class="font-black text-gray-900 tracking-tight dark:text-gray-100" :class="compact ? 'text-xl' : 'text-2xl'">
                    <template v-if="calendarView === 'month'">
                        {{ monthName }} <span class="text-gray-400 font-light dark:text-gray-400">{{ currentYear }}</span>
                    </template>
                    <template v-else>
                        <span :class="compact ? 'text-lg' : 'text-xl'">{{ dayHeaderLabel }}</span>
                    </template>
                </h2>
                <!-- Navigation -->
                <div class="flex items-center bg-gray-100 p-1 rounded-xl shadow-inner dark:bg-gray-800">
                    <button @click="calendarView === 'month' ? prevMonth() : prevDay()" class="p-2 hover:bg-white hover:shadow-sm rounded-lg transition-all duration-200 dark:hover:bg-gray-700">
                        <ChevronLeftIcon class="w-5 h-5 text-gray-600 dark:text-gray-300" />
                    </button>
                    <button @click="goToToday" class="px-4 py-2 text-sm font-bold text-gray-700 hover:bg-white hover:shadow-sm rounded-lg transition-all duration-200 mx-1 dark:text-gray-300 dark:hover:bg-gray-700">
                        Today
                    </button>
                    <button @click="calendarView === 'month' ? nextMonth() : nextDay()" class="p-2 hover:bg-white hover:shadow-sm rounded-lg transition-all duration-200 dark:hover:bg-gray-700">
                        <ChevronRightIcon class="w-5 h-5 text-gray-600 dark:text-gray-300" />
                    </button>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2" :class="compact ? 'lg:justify-end' : 'lg:gap-3'">
                <!-- Go-to-date input -->
                <div class="flex items-center gap-1">
                    <input
                        v-model="goToDateInput"
                        type="date"
                        @keydown.enter="goToDate"
                        class="border border-gray-200 rounded-lg pl-3 pr-2 py-1.5 text-xs text-gray-700 focus:outline-none focus:ring-2 focus:ring-blue-400 bg-white shadow-sm cursor-pointer dark:bg-gray-800 dark:text-gray-300 dark:border-gray-700"
                        title="Go to date"
                    />
                    <button
                        @click="goToDate"
                        class="px-3 py-1.5 text-xs font-bold bg-blue-600 hover:bg-blue-700 text-white rounded-lg shadow-sm transition-colors whitespace-nowrap"
                    >Go</button>
                </div>

                <!-- Month / Day view toggle -->
                <div class="flex items-center bg-gray-100 p-1 rounded-xl shadow-inner dark:bg-gray-800">
                    <button
                        @click="calendarView = 'month'"
                        class="px-3 py-1.5 text-xs font-bold rounded-lg transition-all duration-200"
                        :class="calendarView === 'month' ? 'bg-white shadow-sm text-gray-900' : 'text-gray-500 hover:text-gray-700'"
                    >Month</button>
                    <button
                        @click="switchToDay(currentDayDate)"
                        class="px-3 py-1.5 text-xs font-bold rounded-lg transition-all duration-200"
                        :class="calendarView === 'day' ? 'bg-white shadow-sm text-gray-900' : 'text-gray-500 hover:text-gray-700'"
                    >Day</button>
                </div>
                <slot name="actions"></slot>
            </div>
        </div>

        <!-- Days of Week Header (month view only) -->
        <div v-if="calendarView === 'month'" class="grid grid-cols-7 border-b border-gray-100 bg-gray-50/50 dark:border-gray-700">
            <div v-for="day in days" :key="day" class="text-center text-[11px] font-bold text-gray-400 uppercase tracking-[0.2em] dark:text-gray-400" :class="compact ? 'py-2' : 'py-3'">
                {{ day }}
            </div>
        </div>

        <!-- Calendar Grid (month view only) -->
        <div
            v-if="calendarView === 'month'"
            class="flex-1 min-h-0 bg-gray-100/30"
            :class="compact ? 'grid grid-rows-[repeat(6,minmax(156px,1fr))] overflow-y-auto custom-scrollbar' : 'overflow-y-auto custom-scrollbar'"
        >
            <div
                v-for="(week, wIndex) in weeks"
                :key="wIndex"
                class="grid grid-cols-7 border-b border-gray-100 bg-white min-h-0 dark:bg-gray-800 dark:border-gray-700"
                :class="compact ? '' : 'min-h-[140px]'"
            >
                <div 
                    v-for="(day, dIndex) in week" 
                    :key="day.date ? toDateKey(day.date) : `blank-${wIndex}-${dIndex}`"
                    @click="day.date && emit('date-click', day.date)"
                    class="relative transition-colors hover:bg-gray-50/80 cursor-pointer border-r border-gray-50 last:border-r-0"
                    :class="[
                        compact ? 'p-1.5' : 'p-2',
                        day.isBlank ? 'bg-gray-50/30 cursor-default hover:bg-gray-50/30' : ''
                    ]"
                >
                    <!-- Date Number -->
                    <div v-if="!day.isBlank" class="flex justify-start" :class="compact ? 'mb-1' : 'mb-2'">
                        <span
                            @click.stop="switchToDay(day.date)"
                            class="text-xs font-bold flex items-center justify-center rounded-full transition-all duration-300 hover:ring-2 hover:ring-blue-300"
                            :class="[compact ? 'w-6 h-6' : 'w-7 h-7', isToday(day.date) ? 'bg-blue-600 text-white shadow-lg scale-110' : 'text-gray-500']"
                            title="Open day view"
                        >
                            {{ day.date.getDate() }}
                        </span>
                    </div>

                    <!-- Events List -->
                    <div v-if="!day.isBlank" class="relative z-10">
                        <div class="overflow-hidden" :class="compact ? 'space-y-1 max-h-24' : 'space-y-1.5'">
                        <div 
                            v-for="event in getEventsForDate(day.date).slice(0, MONTH_VISIBLE_EVENT_LIMIT)"
                            :key="event.id"
                            @click.stop="emit('event-click', { event, date: day.date })"
                            class="group relative text-[10px] leading-none shadow-sm transition-all duration-200 hover:scale-[1.02] hover:z-20 border"
                            :class="[
                                compact ? 'py-0.5 px-1.5' : 'py-1 px-2',
                                getChipColor(event),
                                getEventStatus(event, day.date).isStart ? 'rounded-l-lg ml-1 border-l-2' : '-ml-2 border-l-0',
                                getEventStatus(event, day.date).isEnd ? 'rounded-r-lg mr-1 border-r-2' : '-mr-2 border-r-0',
                                !getEventStatus(event, day.date).isStart && !getEventStatus(event, day.date).isEnd ? 'border-x-0' : '',
                                isUrgentTicket(event) ? 'ring-2 ring-red-400 ring-offset-1 shadow-red-300/60 shadow-md' : ''
                            ]"
                            :title="`${event.user?.name}: ${event.status}${event.ticket ? ` [${event.ticket.ticket_key}] ${String(event.ticket.priority).toUpperCase()}` : ''}${isUrgentTicket(event) ? ' ⚠ URGENT P1' : ''}`"
                        >
                            <div class="flex flex-col gap-0.5">
                                <div class="font-black truncate flex items-center gap-1">
                                    <span v-if="getEventStatus(event, day.date).isStart" class="w-1 h-1 rounded-full bg-white animate-pulse dark:bg-gray-800"></span>
                                    <span
                                        v-if="isUrgentTicket(event)"
                                        class="inline-flex items-center px-1 rounded text-[8px] font-black bg-red-500 text-white leading-tight animate-pulse flex-shrink-0"
                                    >P1</span>
                                    <span v-if="event.ticket" class="opacity-75 font-normal">[{{ event.ticket.ticket_key }}]</span>
                                    {{ event.user?.name }}
                                </div>
                                <div
                                    v-if="compact && shouldShowTime(event.status) && (getActualTimesForDate(event, day.date).actual_time_in || getActualTimesForDate(event, day.date).actual_time_out)"
                                    class="truncate text-[8px] font-bold leading-tight opacity-95"
                                >
                                    <span v-if="getActualTimesForDate(event, day.date).actual_time_in">Time In {{ formatTime(getActualTimesForDate(event, day.date).actual_time_in) }}</span>
                                    <span v-if="getActualTimesForDate(event, day.date).actual_time_out" class="ml-1">Out {{ formatTime(getActualTimesForDate(event, day.date).actual_time_out) }}</span>
                                </div>
                                <div v-if="!compact && getEventStatus(event, day.date).isStart" class="opacity-90 font-medium truncate flex justify-between">
                                    <span>{{ event.status }}</span>
                                    <span v-if="shouldShowTime(event.status)" class="text-[9px]">{{ formatTime(event.start_time) }}</span>
                                </div>
                                <div
                                    v-if="!compact && shouldShowTime(event.status) && (getActualTimesForDate(event, day.date).actual_time_in || getActualTimesForDate(event, day.date).actual_time_out)"
                                    class="flex flex-wrap gap-x-2 gap-y-0.5 text-[8px] font-bold opacity-95 leading-tight"
                                >
                                    <span v-if="getActualTimesForDate(event, day.date).actual_time_in">
                                        Actual In: {{ formatTime(getActualTimesForDate(event, day.date).actual_time_in) }}
                                    </span>
                                    <span v-if="getActualTimesForDate(event, day.date).actual_time_out">
                                        Actual Out: {{ formatTime(getActualTimesForDate(event, day.date).actual_time_out) }}
                                    </span>
                                </div>
                            </div>
                        </div>

                        </div>

                        <!-- More Indicator -->
                        <div
                            v-if="getEventsForDate(day.date).length > MONTH_VISIBLE_EVENT_LIMIT"
                            @click.stop="openDayModal(day.date)"
                            class="mt-1 text-[10px] font-bold text-blue-600 hover:text-blue-800 transition-colors px-2 leading-tight"
                            :class="compact ? 'py-0' : 'py-1'"
                        >
                            +{{ getEventsForDate(day.date).length - MONTH_VISIBLE_EVENT_LIMIT }} more
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Day View (time grid) -->
        <div v-if="calendarView === 'day'" ref="dayScrollRef" class="flex-1 overflow-y-auto custom-scrollbar">
            <div class="relative" :style="{ height: `${HOUR_HEIGHT * 24}px` }">

                <!-- Hour rows -->
                <template v-for="h in 24" :key="h - 1">
                    <div
                        class="absolute w-full border-b border-gray-100 flex dark:border-gray-700"
                        :style="{ top: `${(h - 1) * HOUR_HEIGHT}px`, height: `${HOUR_HEIGHT}px` }"
                    >
                        <!-- Time label -->
                        <div class="w-16 shrink-0 flex items-start justify-end pr-3 pt-1">
                            <span class="text-[10px] font-medium text-gray-400 select-none dark:text-gray-400">
                                {{ formatHourLabel(h - 1) }}
                            </span>
                        </div>
                        <!-- Hour lane background -->
                        <div class="flex-1 border-l border-gray-100 dark:border-gray-700"></div>
                    </div>
                </template>

                <!-- Current-time red line -->
                <div
                    v-if="isViewingToday"
                    class="absolute left-16 right-0 z-10 pointer-events-none"
                    :style="{ top: `${currentTimeTop}px` }"
                >
                    <div class="relative flex items-center">
                        <div class="w-2.5 h-2.5 rounded-full bg-red-500 -ml-1.5 shrink-0 shadow-sm"></div>
                        <div class="flex-1 border-t-2 border-red-500 opacity-80"></div>
                    </div>
                </div>

                <!-- Events layer -->
                <div class="absolute left-16 right-2 top-0 bottom-0">
                    <div
                        v-for="item in dailyEventsLayout"
                        :key="item.event.id"
                        @click="emit('event-click', { event: item.event, date: currentDayDate })"
                        class="absolute cursor-pointer rounded-lg border shadow-sm overflow-hidden hover:z-20 hover:shadow-md transition-shadow"
                        :class="[getChipColor(item.event), isUrgentTicket(item.event) ? 'ring-2 ring-red-400 ring-offset-1' : '']"
                        :style="{
                            top:    `${item.top + 1}px`,
                            height: `${item.height - 2}px`,
                            left:   `calc(${item.leftPct}% + 2px)`,
                            width:  `calc(${item.widthPct}% - 4px)`,
                        }"
                        :title="`${item.event.user?.name}: ${item.event.status}${item.event.ticket ? ` [${item.event.ticket.ticket_key}] ${String(item.event.ticket.priority).toUpperCase()}` : ''}`"
                    >
                        <div class="p-1.5 flex flex-col h-full overflow-hidden gap-0.5">
                            <div class="flex items-center gap-1 flex-wrap">
                                <span
                                    v-if="isUrgentTicket(item.event)"
                                    class="inline-flex items-center px-1 rounded text-[8px] font-black bg-red-500 text-white leading-tight animate-pulse shrink-0"
                                >P1</span>
                                <span v-if="item.event.ticket" class="text-[10px] font-bold opacity-75 shrink-0">[{{ item.event.ticket.ticket_key }}]</span>
                                <span class="text-[11px] font-bold truncate">{{ item.event.user?.name }}</span>
                            </div>
                            <span class="text-[10px] opacity-90 truncate">{{ item.event.status }}</span>
                            <span v-if="shouldShowTime(item.event.status)" class="text-[9px] opacity-75">{{ formatTime(item.event.start_time) }} – {{ formatTime(item.event.end_time) }}</span>
                            <p v-if="item.event.store" class="text-[9px] opacity-75 italic truncate">@ {{ item.event.store.name }}</p>
                            <div
                                v-if="shouldShowTime(item.event.status) && (getActualTimesForDate(item.event, currentDayDate).actual_time_in || getActualTimesForDate(item.event, currentDayDate).actual_time_out)"
                                class="flex flex-wrap gap-x-2 gap-y-0.5 text-[9px] font-bold opacity-95 leading-tight"
                            >
                                <span v-if="getActualTimesForDate(item.event, currentDayDate).actual_time_in">
                                    Actual In: {{ formatTime(getActualTimesForDate(item.event, currentDayDate).actual_time_in) }}
                                </span>
                                <span v-if="getActualTimesForDate(item.event, currentDayDate).actual_time_out">
                                    Actual Out: {{ formatTime(getActualTimesForDate(item.event, currentDayDate).actual_time_out) }}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Empty state -->
                <div v-if="dailyEventsLayout.length === 0" class="absolute inset-0 flex items-center justify-center ml-16 pointer-events-none">
                    <div class="text-center pointer-events-auto">
                        <p class="text-sm font-medium text-gray-400 dark:text-gray-400">No schedules for this day</p>
                        <button
                            @click="emit('date-click', currentDayDate)"
                            class="mt-2 text-xs font-bold text-blue-600 hover:text-blue-800 transition-colors"
                        >+ Add Schedule</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Day Events Modal (Google Calendar Style Popover) -->
        <Teleport to="body">
            <div v-if="showDayModal" class="fixed inset-0 z-[10000] flex items-center justify-center p-4">
                <div class="fixed inset-0 bg-black/20 backdrop-blur-sm" @click="closeDayModal"></div>
                <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden relative animate-in fade-in zoom-in duration-200 dark:bg-gray-800">
                    <div class="p-4 border-b border-gray-100 flex justify-between items-center bg-gray-50/50 dark:border-gray-700">
                        <div>
                            <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest dark:text-gray-400">{{ monthName }}</p>
                            <h3 class="text-lg font-black text-gray-900 dark:text-gray-100">{{ formatDateLong(selectedDayDate) }}</h3>
                        </div>
                        <button @click="closeDayModal" class="p-2 hover:bg-white rounded-full transition-colors text-gray-400 dark:text-gray-400 dark:hover:bg-gray-700">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                    <div class="p-4 max-h-[400px] overflow-y-auto custom-scrollbar space-y-2">
                        <!-- Unscheduled Users (No Schedule Plotted) -->
                        <div v-if="unscheduledUsers.length > 0" class="mb-3 pb-3 border-b border-dashed border-gray-200 dark:border-gray-700">
                            <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2 flex items-center gap-1.5 dark:text-gray-400">
                                <span class="inline-block w-2 h-2 rounded-full bg-gray-300 shrink-0"></span>
                                No Schedule Plotted ({{ unscheduledUsers.length }})
                            </p>
                            <div class="space-y-1">
                                <div
                                    v-for="user in unscheduledUsers"
                                    :key="user.id"
                                    class="flex items-center gap-2 px-2 py-1.5 rounded-lg hover:bg-gray-50 transition-colors group dark:hover:bg-gray-700"
                                >
                                    <div class="w-2 h-2 rounded-full bg-gray-200 shrink-0 dark:bg-gray-700"></div>
                                    <span class="text-xs text-gray-600 font-medium flex-1 truncate dark:text-gray-300">{{ user.name }}</span>
                                    <span
                                        v-if="user.department_reference?.code"
                                        class="text-[10px] font-bold text-gray-400 bg-gray-100 border border-gray-200 px-1.5 py-0.5 rounded shrink-0 dark:bg-gray-800 dark:text-gray-400 dark:border-gray-700"
                                    >{{ user.department_reference.code }}</span>
                                    <button
                                        @click.stop="() => { emit('add-schedule-for-user', { user, date: selectedDayDate }); closeDayModal(); }"
                                        class="shrink-0 w-5 h-5 flex items-center justify-center rounded-full bg-blue-100 text-blue-600 hover:bg-blue-600 hover:text-white transition-colors"
                                        title="Add schedule"
                                    >
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M12 4v16m8-8H4" /></svg>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div
                            v-for="group in selectedDayEventGroups"
                            :key="group.id"
                            class="space-y-1.5 pt-1 first:pt-0"
                        >
                            <button 
                                @click="toggleModalGroup(group.id)"
                                class="w-full flex items-center justify-between text-[10px] font-black text-gray-400 uppercase tracking-widest gap-1.5 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 p-1.5 rounded transition-colors -ml-1.5"
                            >
                                <div class="flex items-center gap-1.5">
                                    <span class="inline-block w-2 h-2 rounded-full shrink-0" :class="group.colorClass"></span>
                                    {{ group.label }} ({{ group.events.length }})
                                </div>
                                <svg 
                                    class="w-3 h-3 transition-transform duration-200" 
                                    :class="expandedModalGroups[group.id] ? 'rotate-180' : ''" 
                                    fill="none" stroke="currentColor" viewBox="0 0 24 24"
                                >
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                </svg>
                            </button>

                            <div v-show="expandedModalGroups[group.id]" class="space-y-1.5">
                            <div
                                v-for="event in group.events"
                                :key="event.id"
                                @click="() => { emit('event-click', { event, date: selectedDayDate }); closeDayModal(); }"
                                class="p-3 rounded-xl border border-transparent hover:border-gray-100 hover:bg-gray-50 transition-all cursor-pointer group dark:hover:bg-gray-700"
                                :class="isUrgentTicket(event) ? 'border-l-2 !border-l-red-500 pl-2' : ''"
                            >
                                <div class="flex items-start gap-3">
                                    <div class="w-3 h-3 rounded-full mt-1 shrink-0" :class="getChipColor(event).split(' ')[0]"></div>
                                    <div class="flex-1">
                                    <div class="flex justify-between items-start">
                                        <div class="flex items-center gap-1.5 flex-wrap">
                                            <p class="text-sm font-bold text-gray-900 dark:text-gray-100">{{ event.user?.name }}</p>
                                            <span
                                                v-if="isUrgentTicket(event)"
                                                class="inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-black bg-red-500 text-white animate-pulse"
                                            >⚠ P1 URGENT</span>
                                            <span
                                                v-else-if="event.ticket?.priority"
                                                class="inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-bold text-white"
                                                :class="getChipColor(event).split(' ')[0]"
                                            >{{ String(event.ticket.priority).toUpperCase() }}</span>
                                        </div>
                                        <span v-if="shouldShowTime(event.status)" class="text-[10px] font-medium text-gray-400 shrink-0 ml-2 dark:text-gray-400">{{ formatTime(event.start_time) }} - {{ formatTime(event.end_time) }}</span>
                                    </div>
                                    <p class="text-xs text-gray-500 font-medium dark:text-gray-300">
                                        {{ event.status || 'Unknown' }}<span v-if="event.ticket" class="ml-1 text-gray-400 dark:text-gray-400">[{{ event.ticket.ticket_key }}]</span>
                                    </p>
                                    <!-- Multiple deployment locations for this day: show each store with its own time window. -->
                                    <div v-if="event.day_segments && event.day_segments.length > 1" class="mt-1 space-y-0.5">
                                        <div
                                            v-for="(seg, i) in event.day_segments"
                                            :key="i"
                                            class="flex items-center justify-between gap-2"
                                        >
                                            <span class="text-[10px] text-blue-600 italic truncate">@ {{ seg.store_name || 'No location' }}</span>
                                            <span v-if="shouldShowTime(event.status)" class="text-[10px] font-medium text-gray-400 shrink-0 dark:text-gray-400">{{ formatTime(seg.start_time) }} - {{ formatTime(seg.end_time) }}</span>
                                        </div>
                                    </div>
                                    <p v-else-if="event.store" class="text-[10px] text-blue-600 mt-1 italic">@ {{ event.store.name }}</p>
                                    <div v-if="shouldShowTime(event.status) && (getActualTimesForDate(event, selectedDayDate).actual_time_in || getActualTimesForDate(event, selectedDayDate).actual_time_out)"
                                         class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-[10px] font-bold">
                                        <span v-if="getActualTimesForDate(event, selectedDayDate).actual_time_in" class="text-emerald-600">
                                            Actual In: {{ formatTime(getActualTimesForDate(event, selectedDayDate).actual_time_in) }}
                                        </span>
                                        <span v-if="getActualTimesForDate(event, selectedDayDate).actual_time_out" class="text-orange-500">
                                            Actual Out: {{ formatTime(getActualTimesForDate(event, selectedDayDate).actual_time_out) }}
                                        </span>
                                    </div>
                                    </div>
                                </div>
                            </div>
                            </div>
                        </div>
                    </div>
                    <div class="p-4 bg-gray-50 border-t border-gray-100 text-center dark:bg-gray-900/50 dark:border-gray-700">
                        <button 
                            @click="() => { emit('date-click', selectedDayDate); closeDayModal(); }"
                            class="text-xs font-bold text-blue-600 hover:text-blue-800 transition-colors"
                        >
                            + Add New Schedule
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>
    </div>
</template>

<style scoped>
.custom-scrollbar::-webkit-scrollbar {
    width: 4px;
}
.custom-scrollbar::-webkit-scrollbar-track {
    background: transparent;
}
.custom-scrollbar::-webkit-scrollbar-thumb {
    background: #e2e8f0;
    border-radius: 10px;
}
.custom-scrollbar::-webkit-scrollbar-thumb:hover {
    background: #cbd5e1;
}
</style>
