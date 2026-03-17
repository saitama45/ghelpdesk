<script setup>
import { ref, computed, onMounted } from 'vue';
import { ChevronLeftIcon, ChevronRightIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    events: {
        type: Array,
        default: () => []
    }
});

const emit = defineEmits(['date-click', 'event-click']);

const days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
const currentDate = ref(new Date());

const currentMonth = computed(() => currentDate.value.getMonth());
const currentYear = computed(() => currentDate.value.getFullYear());

const monthName = computed(() => {
    return new Intl.DateTimeFormat('en-US', { month: 'long' }).format(currentDate.value);
});

const calendarDays = computed(() => {
    const firstDayOfMonth = new Date(currentYear.value, currentMonth.value, 1);
    const lastDayOfMonth = new Date(currentYear.value, currentMonth.value + 1, 0);
    
    const startDay = firstDayOfMonth.getDay();
    const totalDays = lastDayOfMonth.getDate();
    
    const daysArray = [];
    
    // Previous month filler days
    const prevMonthLastDay = new Date(currentYear.value, currentMonth.value, 0).getDate();
    for (let i = startDay - 1; i >= 0; i--) {
        daysArray.push({
            date: new Date(currentYear.value, currentMonth.value - 1, prevMonthLastDay - i),
            isCurrentMonth: false
        });
    }
    
    // Current month days
    for (let i = 1; i <= totalDays; i++) {
        daysArray.push({
            date: new Date(currentYear.value, currentMonth.value, i),
            isCurrentMonth: true
        });
    }
    
    // Next month filler days
    const remainingSlots = 42 - daysArray.length;
    for (let i = 1; i <= remainingSlots; i++) {
        daysArray.push({
            date: new Date(currentYear.value, currentMonth.value + 1, i),
            isCurrentMonth: false
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

const getEventsForDate = (date) => {
    const d = new Date(date);
    d.setHours(0, 0, 0, 0);

    return props.events
        .filter(event => {
            const start = new Date(event.start_time);
            start.setHours(0, 0, 0, 0);
            
            const end = new Date(event.end_time);
            end.setHours(0, 0, 0, 0);
            
            return d >= start && d <= end;
        })
        .sort((a, b) => {
            const durationA = new Date(a.end_time) - new Date(a.start_time);
            const durationB = new Date(b.end_time) - new Date(b.start_time);
            if (durationB !== durationA) return durationB - durationA;
            return new Date(a.start_time) - new Date(b.start_time);
        });
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
    currentDate.value = new Date();
};

const isToday = (date) => {
    return date.toDateString() === new Date().toDateString();
};

const showDayModal = ref(false);
const selectedDayDate = ref(null);
const selectedDayEvents = ref([]);

const openDayModal = (date) => {
    selectedDayDate.value = date;
    selectedDayEvents.value = getEventsForDate(date);
    showDayModal.value = true;
};

const closeDayModal = () => {
    showDayModal.value = false;
};

const formatDateLong = (date) => {
    return new Intl.DateTimeFormat('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' }).format(date);
};
</script>

<template>
    <div class="bg-white rounded-2xl shadow-2xl border border-gray-200 overflow-hidden flex flex-col h-[850px]">
        <!-- Calendar Header -->
        <div class="p-6 border-b border-gray-200 flex items-center justify-between bg-white">
            <div class="flex items-center space-x-6">
                <h2 class="text-2xl font-black text-gray-900 tracking-tight">{{ monthName }} <span class="text-gray-400 font-light">{{ currentYear }}</span></h2>
                <div class="flex items-center bg-gray-100 p-1 rounded-xl shadow-inner">
                    <button @click="prevMonth" class="p-2 hover:bg-white hover:shadow-sm rounded-lg transition-all duration-200">
                        <ChevronLeftIcon class="w-5 h-5 text-gray-600" />
                    </button>
                    <button @click="goToToday" class="px-4 py-2 text-sm font-bold text-gray-700 hover:bg-white hover:shadow-sm rounded-lg transition-all duration-200 mx-1">
                        Today
                    </button>
                    <button @click="nextMonth" class="p-2 hover:bg-white hover:shadow-sm rounded-lg transition-all duration-200">
                        <ChevronRightIcon class="w-5 h-5 text-gray-600" />
                    </button>
                </div>
            </div>
            
            <div class="flex items-center space-x-3">
                <slot name="actions"></slot>
            </div>
        </div>

        <!-- Days of Week Header -->
        <div class="grid grid-cols-7 border-b border-gray-100 bg-gray-50/50">
            <div v-for="day in days" :key="day" class="py-3 text-center text-[11px] font-bold text-gray-400 uppercase tracking-[0.2em]">
                {{ day }}
            </div>
        </div>

        <!-- Calendar Grid -->
        <div class="flex-1 overflow-y-auto custom-scrollbar bg-gray-100/30">
            <div v-for="(week, wIndex) in weeks" :key="wIndex" class="grid grid-cols-7 border-b border-gray-100 min-h-[140px] bg-white">
                <div 
                    v-for="(day, dIndex) in week" 
                    :key="dIndex"
                    @click="emit('date-click', day.date)"
                    class="relative p-2 transition-colors hover:bg-gray-50/80 cursor-pointer border-r border-gray-50 last:border-r-0"
                    :class="[!day.isCurrentMonth ? 'opacity-40 bg-gray-50/30' : '']"
                >
                    <!-- Date Number -->
                    <div class="flex justify-start mb-2">
                        <span 
                            class="text-xs font-bold w-7 h-7 flex items-center justify-center rounded-full transition-all duration-300"
                            :class="[
                                isToday(day.date) ? 'bg-blue-600 text-white shadow-lg scale-110' : 'text-gray-500'
                            ]"
                        >
                            {{ day.date.getDate() }}
                        </span>
                    </div>

                    <!-- Events List -->
                    <div class="space-y-1.5 relative z-10">
                        <div 
                            v-for="(event, eIndex) in getEventsForDate(day.date).slice(0, 2)" 
                            :key="event.id"
                            @click.stop="emit('event-click', event)"
                            class="group relative py-1 px-2 text-[10px] leading-none shadow-sm transition-all duration-200 hover:scale-[1.02] hover:z-20 border"
                            :class="[
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
                                    <span v-if="getEventStatus(event, day.date).isStart" class="w-1 h-1 rounded-full bg-white animate-pulse"></span>
                                    <span
                                        v-if="isUrgentTicket(event)"
                                        class="inline-flex items-center px-1 rounded text-[8px] font-black bg-red-500 text-white leading-tight animate-pulse flex-shrink-0"
                                    >P1</span>
                                    <span v-if="event.ticket" class="opacity-75 font-normal">[{{ event.ticket.ticket_key }}]</span>
                                    {{ event.user?.name }}
                                </div>
                                <div v-if="getEventStatus(event, day.date).isStart" class="opacity-90 font-medium truncate flex justify-between">
                                    <span>{{ event.status }}</span>
                                    <span class="text-[9px]">{{ formatTime(event.start_time) }}</span>
                                </div>
                            </div>
                        </div>

                        <!-- More Indicator -->
                        <div 
                            v-if="getEventsForDate(day.date).length > 2"
                            @click.stop="openDayModal(day.date)"
                            class="text-[10px] font-bold text-blue-600 hover:text-blue-800 transition-colors px-2 py-1"
                        >
                            +{{ getEventsForDate(day.date).length - 2 }} more
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Day Events Modal (Google Calendar Style Popover) -->
        <Teleport to="body">
            <div v-if="showDayModal" class="fixed inset-0 z-[10000] flex items-center justify-center p-4">
                <div class="fixed inset-0 bg-black/20 backdrop-blur-sm" @click="closeDayModal"></div>
                <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden relative animate-in fade-in zoom-in duration-200">
                    <div class="p-4 border-b border-gray-100 flex justify-between items-center bg-gray-50/50">
                        <div>
                            <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest">{{ monthName }}</p>
                            <h3 class="text-lg font-black text-gray-900">{{ formatDateLong(selectedDayDate) }}</h3>
                        </div>
                        <button @click="closeDayModal" class="p-2 hover:bg-white rounded-full transition-colors text-gray-400">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                    <div class="p-4 max-h-[400px] overflow-y-auto custom-scrollbar space-y-2">
                        <div 
                            v-for="event in selectedDayEvents" 
                            :key="event.id"
                            @click="() => { emit('event-click', event); closeDayModal(); }"
                            class="p-3 rounded-xl border border-transparent hover:border-gray-100 hover:bg-gray-50 transition-all cursor-pointer group"
                            :class="isUrgentTicket(event) ? 'border-l-2 !border-l-red-500 pl-2' : ''"
                        >
                            <div class="flex items-start gap-3">
                                <div class="w-3 h-3 rounded-full mt-1 shrink-0" :class="getChipColor(event).split(' ')[0]"></div>
                                <div class="flex-1">
                                    <div class="flex justify-between items-start">
                                        <div class="flex items-center gap-1.5 flex-wrap">
                                            <p class="text-sm font-bold text-gray-900">{{ event.user?.name }}</p>
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
                                        <span class="text-[10px] font-medium text-gray-400 shrink-0 ml-2">{{ formatTime(event.start_time) }} - {{ formatTime(event.end_time) }}</span>
                                    </div>
                                    <p class="text-xs text-gray-500 font-medium">
                                        {{ event.status }}<span v-if="event.ticket" class="ml-1 text-gray-400">[{{ event.ticket.ticket_key }}]</span>
                                    </p>
                                    <p v-if="event.store" class="text-[10px] text-blue-600 mt-1 italic">@ {{ event.store.name }}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="p-4 bg-gray-50 border-t border-gray-100 text-center">
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
