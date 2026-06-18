<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import { Link } from '@inertiajs/vue3';
import axios from 'axios';

const bellRef = ref(null);
const isOpen = ref(false);
const isFetching = ref(false);
const notifications = ref([]);
const total = ref(0);

let pollInterval = null;

const fetchNotifications = async () => {
    if (isFetching.value) return;
    isFetching.value = true;
    try {
        const res = await axios.get(route('notifications.summary'));
        notifications.value = res.data.notifications;
        total.value = res.data.total;
    } catch {
        // silent fail — do not disrupt the UI
    } finally {
        isFetching.value = false;
    }
};

const handleOutsideClick = (e) => {
    if (bellRef.value && !bellRef.value.contains(e.target)) {
        isOpen.value = false;
    }
};

onMounted(() => {
    fetchNotifications();
    pollInterval = setInterval(fetchNotifications, 60_000);
    document.addEventListener('click', handleOutsideClick);
});

onUnmounted(() => {
    clearInterval(pollInterval);
    document.removeEventListener('click', handleOutsideClick);
});

const severityClasses = {
    warning: {
        bg: 'bg-amber-50',
        icon: 'text-amber-500',
        border: 'border-amber-100',
    },
    info: {
        bg: 'bg-blue-50',
        icon: 'text-blue-500',
        border: 'border-blue-100',
    },
    success: {
        bg: 'bg-green-50',
        icon: 'text-green-500',
        border: 'border-green-100',
    },
};
</script>

<template>
    <div ref="bellRef" class="relative">
        <!-- Bell button -->
        <button
            @click.stop="isOpen = !isOpen"
            class="relative p-2 rounded-full hover:bg-gray-100 transition-colors focus:outline-none focus:ring-2 focus:ring-blue-500 dark:hover:bg-gray-700"
            title="Notifications"
        >
            <!-- Bell icon -->
            <svg class="w-5 h-5 text-gray-600 dark:text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
            </svg>

            <!-- Badge -->
            <span
                v-if="total > 0"
                class="absolute top-0.5 right-0.5 flex h-4 w-4 items-center justify-center rounded-full bg-red-500 text-[10px] font-black text-white leading-none"
            >
                {{ total > 9 ? '9+' : total }}
            </span>
        </button>

        <!-- Dropdown panel -->
        <div
            v-if="isOpen"
            class="absolute right-0 top-full mt-2 w-80 bg-white rounded-xl shadow-xl border border-gray-100 z-50 overflow-hidden dark:bg-gray-800 dark:border-gray-700"
        >
            <!-- Header -->
            <div class="px-4 py-3 border-b border-gray-100 flex items-center justify-between bg-gray-50 dark:bg-gray-900/50 dark:border-gray-700">
                <span class="text-sm font-bold text-gray-700 dark:text-gray-300">Notifications</span>
                <span class="text-xs text-gray-400 font-medium dark:text-gray-400">{{ total }} active</span>
            </div>

            <!-- Empty state -->
            <div v-if="notifications.length === 0" class="px-4 py-8 text-center">
                <svg class="w-8 h-8 text-gray-300 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <p class="text-sm text-gray-400 italic font-medium dark:text-gray-400">All clear — no notifications</p>
            </div>

            <!-- Notification rows -->
            <div v-else class="divide-y divide-gray-50">
                <Link
                    v-for="n in notifications"
                    :key="n.type"
                    :href="route(n.route)"
                    @click="isOpen = false"
                    class="flex items-start gap-3 px-4 py-3 hover:bg-gray-50 transition-colors dark:hover:bg-gray-700"
                >
                    <!-- Icon container -->
                    <div
                        class="shrink-0 mt-0.5 h-8 w-8 rounded-lg flex items-center justify-center border"
                        :class="[severityClasses[n.severity]?.bg, severityClasses[n.severity]?.border]"
                    >
                        <!-- Schedule / warning icon -->
                        <svg v-if="n.type === 'schedule'"
                            class="w-4 h-4"
                            :class="severityClasses[n.severity]?.icon"
                            fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                        </svg>

                        <!-- Tickets / info icon -->
                        <svg v-else-if="n.type === 'tickets'"
                            class="w-4 h-4"
                            :class="severityClasses[n.severity]?.icon"
                            fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M15 5v2m0 4v2m0 4v2M5 5a2 2 0 00-2 2v3a2 2 0 110 4v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 110-4V7a2 2 0 00-2-2H5z" />
                        </svg>

                        <!-- Points / success icon -->
                        <svg v-else-if="n.type === 'points'"
                            class="w-4 h-4"
                            :class="severityClasses[n.severity]?.icon"
                            fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z" />
                        </svg>
                    </div>

                    <!-- Text -->
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-semibold text-gray-800 truncate dark:text-gray-200">{{ n.title }}</p>
                        <p class="text-xs text-gray-500 mt-0.5 dark:text-gray-300">{{ n.message }}</p>
                    </div>

                    <!-- Count badge for tickets/points -->
                    <span
                        v-if="n.count !== null"
                        class="shrink-0 mt-1 text-xs font-black px-1.5 py-0.5 rounded-full"
                        :class="{
                            'bg-blue-100 text-blue-700': n.severity === 'info',
                            'bg-green-100 text-green-700': n.severity === 'success',
                            'bg-amber-100 text-amber-700': n.severity === 'warning',
                        }"
                    >
                        {{ n.count }}
                    </span>
                </Link>
            </div>

            <!-- Footer hint -->
            <div class="px-4 py-2 border-t border-gray-100 bg-gray-50 dark:bg-gray-900/50 dark:border-gray-700">
                <p class="text-[10px] text-gray-400 text-center font-medium uppercase tracking-wide dark:text-gray-400">
                    Updates every 60 seconds
                </p>
            </div>
        </div>
    </div>
</template>
