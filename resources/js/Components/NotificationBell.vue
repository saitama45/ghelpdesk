<script setup>
import { ref, onMounted, onUnmounted, computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import { useSidebarOrder } from '@/Composables/useSidebarOrder.js';

const { getSectionOrder, getChildOrder, getSectionLabel, getChildLabel } = useSidebarOrder();

// Maps a notification/reminder to a Sidebar module (section + optional child) so
// the bell can group items exactly like the sidebar's parent/child menus.
const URL_MODULE_MAP = {
    'tickets': ['services', 'tickets'],
    'task-boards': ['services', 'task-boards'],
    'queue': ['services', 'queue'],
    'pos-requests': ['services', 'pos-requests'],
    'sap-requests': ['services', 'sap-requests'],
    'stamps': ['services', 'stamps'],
    'projects': ['projectTracker', null],
    'npc-statuses': ['monitoring', 'npc-status'],
    'payments': ['monitoring', 'payments'],
    'cctv-monitoring': ['monitoring', 'cctv-monitoring'],
    'wigs': ['monitoring', 'wigs'],
    'mall-hookups': ['monitoring', 'mall-hookups'],
    'schedules': ['adminTask', 'scheduling'],
    'attendance': ['adminTask', 'dtr'],
    'presence': ['adminTask', 'presence'],
    'kb-articles': ['adminTask', 'kb-articles'],
    'service-vehicle-trips': ['adminTask', 'service-vehicle-trips'],
    'users': ['userManagement', 'users'],
    'roles': ['userManagement', 'roles'],
    'leadership-points': ['settings', 'leadership-points'],
};

const DOMAIN_MODULE_MAP = {
    ticket: ['services', 'tickets'],
    task_card: ['services', 'task-boards'],
    project_task: ['projectTracker', null],
    npc_status: ['monitoring', 'npc-status'],
    approval: ['services', null],
};

const REMINDER_MODULE_MAP = {
    missing_schedule: ['adminTask', 'scheduling'],
    missing_time_in: ['adminTask', 'scheduling'],
    missing_time_out: ['adminTask', 'scheduling'],
    tickets: ['services', 'tickets'],
    sla_breached: ['services', 'tickets'],
    sla_due_1d: ['services', 'tickets'],
    sla_due_2d: ['services', 'tickets'],
    points: ['dashboard', null],
};

const moduleFromUrl = (url) => {
    if (!url) return null;
    try {
        const path = url.startsWith('http') ? new URL(url).pathname : url;
        const seg = path.split('?')[0].split('/').filter(Boolean)[0];
        return seg && URL_MODULE_MAP[seg] ? URL_MODULE_MAP[seg] : null;
    } catch {
        return null;
    }
};

const moduleFromRoute = (routeName) => {
    if (!routeName) return null;
    const map = {
        schedules: ['adminTask', 'scheduling'],
        tickets: ['services', 'tickets'],
        dashboard: ['dashboard', null],
    };
    return map[routeName.split('.')[0]] || null;
};

const bellRef = ref(null);
const isOpen = ref(false);
const isFetching = ref(false);
const notifications = ref([]);
const reminders = ref([]);
const unread = ref(0);
const total = ref(0);

let pollInterval = null;

const fetchNotifications = async () => {
    if (isFetching.value) return;
    isFetching.value = true;
    try {
        const res = await axios.get(route('notifications.summary'));
        notifications.value = res.data.notifications ?? [];
        reminders.value = res.data.reminders ?? [];
        unread.value = res.data.unread ?? 0;
        total.value = res.data.total ?? 0;
    } catch {
        // silent fail — do not disrupt the UI
    } finally {
        isFetching.value = false;
    }
};

const hasContent = computed(() => notifications.value.length > 0 || reminders.value.length > 0);

// Merge activity notifications + reminders, tag each with its Sidebar module, and
// group them into section → child (parent menu → child menu) following the same
// order/labels the sidebar uses.
const groupedSections = computed(() => {
    const items = [];

    for (const n of notifications.value) {
        const mod = moduleFromUrl(n.url) || DOMAIN_MODULE_MAP[n.domain] || ['general', null];
        items.push({ kind: 'activity', key: 'a-' + n.id, section: mod[0], child: mod[1], data: n });
    }

    for (const r of reminders.value) {
        const mod = REMINDER_MODULE_MAP[r.type] || moduleFromRoute(r.route) || ['general', null];
        items.push({ kind: 'reminder', key: 'r-' + r.type, section: mod[0], child: mod[1], data: r });
    }

    const sectionMap = new Map();
    for (const it of items) {
        if (!sectionMap.has(it.section)) sectionMap.set(it.section, []);
        sectionMap.get(it.section).push(it);
    }

    const sections = [];
    for (const [sectionKey, secItems] of sectionMap.entries()) {
        const childMap = new Map();
        for (const it of secItems) {
            const ck = it.child || '__none';
            if (!childMap.has(ck)) childMap.set(ck, []);
            childMap.get(ck).push(it);
        }

        const children = [...childMap.entries()]
            .map(([childKey, cItems]) => ({
                childKey: childKey === '__none' ? null : childKey,
                childLabel: childKey === '__none' ? null : getChildLabel(sectionKey, childKey),
                order: childKey === '__none' ? -1 : getChildOrder(sectionKey, childKey),
                items: cItems,
            }))
            .sort((a, b) => a.order - b.order);

        sections.push({
            sectionKey,
            sectionLabel: sectionKey === 'general' ? 'General' : getSectionLabel(sectionKey),
            order: sectionKey === 'general' ? 998 : getSectionOrder(sectionKey),
            count: secItems.length,
            children,
        });
    }

    return sections.sort((a, b) => a.order - b.order);
});

const openNotification = async (n) => {
    isOpen.value = false;
    if (!n.read) {
        try {
            const res = await axios.post(route('notifications.read', n.id));
            unread.value = res.data.unread ?? Math.max(0, unread.value - 1);
            n.read = true;
        } catch {
            // ignore — still navigate
        }
    }
    if (n.url) {
        router.visit(n.url);
    }
};

const markAllRead = async () => {
    try {
        await axios.post(route('notifications.read-all'));
        notifications.value.forEach((n) => { n.read = true; });
        unread.value = 0;
        total.value = reminders.value.length;
    } catch {
        // ignore
    }
};

const relativeTime = (value) => {
    if (!value) return '';
    const then = new Date(value).getTime();
    if (Number.isNaN(then)) return '';
    const diff = Math.floor((Date.now() - then) / 1000);
    if (diff < 60) return 'just now';
    if (diff < 3600) return `${Math.floor(diff / 60)}m ago`;
    if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`;
    if (diff < 604800) return `${Math.floor(diff / 86400)}d ago`;
    return new Date(value).toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
};

const handleOutsideClick = (e) => {
    if (bellRef.value && !bellRef.value.contains(e.target)) {
        isOpen.value = false;
    }
};

onMounted(() => {
    fetchNotifications();
    pollInterval = setInterval(fetchNotifications, 30_000);
    document.addEventListener('click', handleOutsideClick);
});

onUnmounted(() => {
    clearInterval(pollInterval);
    document.removeEventListener('click', handleOutsideClick);
});

const severityClasses = {
    warning: { bg: 'bg-amber-50 dark:bg-amber-500/10', icon: 'text-amber-500', border: 'border-amber-100 dark:border-amber-500/20' },
    info: { bg: 'bg-blue-50 dark:bg-blue-500/10', icon: 'text-blue-500', border: 'border-blue-100 dark:border-blue-500/20' },
    success: { bg: 'bg-green-50 dark:bg-green-500/10', icon: 'text-green-500', border: 'border-green-100 dark:border-green-500/20' },
};

const domainIcon = {
    ticket: 'M15 5v2m0 4v2m0 4v2M5 5a2 2 0 00-2 2v3a2 2 0 110 4v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 110-4V7a2 2 0 00-2-2H5z',
    task_card: 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4',
    project_task: 'M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
    approval: 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z',
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
            <svg class="w-5 h-5 text-gray-600 dark:text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
            </svg>

            <span
                v-if="total > 0"
                class="absolute top-0.5 right-0.5 flex h-4 min-w-4 px-0.5 items-center justify-center rounded-full bg-red-500 text-[10px] font-black text-white leading-none"
            >
                {{ total > 9 ? '9+' : total }}
            </span>
        </button>

        <!-- Dropdown panel -->
        <div
            v-if="isOpen"
            class="absolute right-0 top-full mt-2 w-96 max-w-[calc(100vw-2rem)] bg-white rounded-xl shadow-xl border border-gray-100 z-50 overflow-hidden dark:bg-gray-800 dark:border-gray-700"
        >
            <!-- Header -->
            <div class="px-4 py-3 border-b border-gray-100 flex items-center justify-between bg-gray-50 dark:bg-gray-900/50 dark:border-gray-700">
                <span class="text-sm font-bold text-gray-700 dark:text-gray-300">Notifications</span>
                <button
                    v-if="unread > 0"
                    @click.stop="markAllRead"
                    class="text-xs font-bold text-blue-600 hover:text-blue-700 hover:underline dark:text-blue-400"
                >
                    Mark all read
                </button>
                <span v-else class="text-xs text-gray-400 font-medium dark:text-gray-400">{{ total }} active</span>
            </div>

            <!-- Empty state -->
            <div v-if="!hasContent" class="px-4 py-8 text-center">
                <svg class="w-8 h-8 text-gray-300 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <p class="text-sm text-gray-400 italic font-medium dark:text-gray-400">All clear — no notifications</p>
            </div>

            <div v-else class="max-h-[28rem] overflow-y-auto">
                <!-- Grouped by Sidebar module: parent section → child menu -->
                <div
                    v-for="section in groupedSections"
                    :key="section.sectionKey"
                    class="border-b border-gray-100 last:border-b-0 dark:border-gray-700"
                >
                    <!-- Parent menu header -->
                    <div class="flex items-center justify-between px-4 pt-3 pb-1.5 bg-gray-50/60 dark:bg-gray-900/40">
                        <p class="text-[10px] font-black uppercase tracking-widest text-gray-500 dark:text-gray-300">{{ section.sectionLabel }}</p>
                        <span class="text-[10px] font-black text-gray-400 dark:text-gray-500">{{ section.count }}</span>
                    </div>

                    <div v-for="child in section.children" :key="section.sectionKey + '-' + (child.childKey || 'none')">
                        <!-- Child menu sub-header -->
                        <p
                            v-if="child.childLabel"
                            class="px-4 pt-1.5 pb-0.5 text-[10px] font-bold text-gray-400 dark:text-gray-500 flex items-center gap-1.5"
                        >
                            <span class="inline-block h-1 w-1 rounded-full bg-gray-300 dark:bg-gray-600"></span>
                            {{ child.childLabel }}
                        </p>

                        <div class="divide-y divide-gray-50 dark:divide-gray-700/60">
                            <template v-for="item in child.items" :key="item.key">
                                <!-- Activity notification -->
                                <button
                                    v-if="item.kind === 'activity'"
                                    @click.stop="openNotification(item.data)"
                                    class="w-full text-left flex items-start gap-3 px-4 py-3 transition-colors hover:bg-gray-50 dark:hover:bg-gray-700"
                                    :class="!item.data.read ? 'bg-blue-50/40 dark:bg-blue-500/10' : ''"
                                >
                                    <div
                                        class="shrink-0 mt-0.5 h-8 w-8 rounded-lg flex items-center justify-center border"
                                        :class="[severityClasses[item.data.severity]?.bg, severityClasses[item.data.severity]?.border]"
                                    >
                                        <svg class="w-4 h-4" :class="severityClasses[item.data.severity]?.icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="domainIcon[item.data.domain] || domainIcon.ticket" />
                                        </svg>
                                    </div>

                                    <div class="min-w-0 flex-1">
                                        <p class="text-sm font-semibold text-gray-800 truncate dark:text-gray-200">{{ item.data.title }}</p>
                                        <p class="text-xs text-gray-500 mt-0.5 line-clamp-2 dark:text-gray-300">{{ item.data.message }}</p>
                                        <p class="text-[10px] text-gray-400 mt-1 font-medium dark:text-gray-400">
                                            <span v-if="item.data.actor_name" class="font-bold text-gray-500 dark:text-gray-300">by {{ item.data.actor_name }}</span><span v-if="item.data.actor_name"> &middot; </span>{{ relativeTime(item.data.created_at) }}
                                        </p>
                                    </div>

                                    <span v-if="!item.data.read" class="shrink-0 mt-1.5 h-2 w-2 rounded-full bg-blue-500"></span>
                                </button>

                                <!-- Ambient reminder -->
                                <Link
                                    v-else
                                    :href="route(item.data.route, item.data.params || {})"
                                    @click="isOpen = false"
                                    class="flex items-start gap-3 px-4 py-3 hover:bg-gray-50 transition-colors dark:hover:bg-gray-700"
                                >
                                    <div
                                        class="shrink-0 mt-0.5 h-8 w-8 rounded-lg flex items-center justify-center border"
                                        :class="[severityClasses[item.data.severity]?.bg, severityClasses[item.data.severity]?.border]"
                                    >
                                        <svg v-if="item.data.type === 'schedule' || item.data.type.startsWith('missing_')" class="w-4 h-4" :class="severityClasses[item.data.severity]?.icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                        </svg>
                                        <svg v-else-if="item.data.type.startsWith('sla_')" class="w-4 h-4" :class="severityClasses[item.data.severity]?.icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                        </svg>
                                        <svg v-else-if="item.data.type === 'tickets'" class="w-4 h-4" :class="severityClasses[item.data.severity]?.icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 5v2m0 4v2m0 4v2M5 5a2 2 0 00-2 2v3a2 2 0 110 4v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 110-4V7a2 2 0 00-2-2H5z" />
                                        </svg>
                                        <svg v-else class="w-4 h-4" :class="severityClasses[item.data.severity]?.icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z" />
                                        </svg>
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <p class="text-sm font-semibold text-gray-800 truncate dark:text-gray-200">{{ item.data.title }}</p>
                                        <p class="text-xs text-gray-500 mt-0.5 dark:text-gray-300">{{ item.data.message }}</p>
                                    </div>
                                    <span
                                        v-if="item.data.count !== null"
                                        class="shrink-0 mt-1 text-xs font-black px-1.5 py-0.5 rounded-full"
                                        :class="{
                                            'bg-blue-100 text-blue-700': item.data.severity === 'info',
                                            'bg-green-100 text-green-700': item.data.severity === 'success',
                                            'bg-amber-100 text-amber-700': item.data.severity === 'warning',
                                        }"
                                    >
                                        {{ item.data.count }}
                                    </span>
                                </Link>
                            </template>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Footer hint -->
            <div class="px-4 py-2 border-t border-gray-100 bg-gray-50 dark:bg-gray-900/50 dark:border-gray-700">
                <p class="text-[10px] text-gray-400 text-center font-medium uppercase tracking-wide dark:text-gray-400">
                    Updates every 30 seconds
                </p>
            </div>
        </div>
    </div>
</template>
