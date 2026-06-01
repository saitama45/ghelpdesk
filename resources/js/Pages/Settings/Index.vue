<script setup>
import { ref, computed, onMounted } from 'vue';
import { Head, useForm, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import draggable from 'vuedraggable';
import { useSidebarOrder } from '@/Composables/useSidebarOrder.js';
import {
    Cog6ToothIcon,
    EnvelopeIcon,
    ShieldCheckIcon,
    MapIcon,
    EyeIcon,
    EyeSlashIcon,
    ChartBarIcon,
    PaperAirplaneIcon,
    ServerIcon,
    AdjustmentsHorizontalIcon,
    CheckCircleIcon,
    ClockIcon,
    ArchiveBoxIcon,
    Bars3BottomLeftIcon,
    ChevronDownIcon,
    ChevronRightIcon,
    UserGroupIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({
    settings: Object,
    subUnits: Array,
    assignableStaff: Array,
});

const requestedTab = new URLSearchParams(window.location.search).get('tab');
const activeTab = ref(requestedTab || 'mail');
const selectedSubUnit = ref('global');
const selectedThresholdSubUnit = ref('global');

const tabs = [
    { id: 'mail', name: 'Mail Configuration', icon: EnvelopeIcon, description: 'Manage inbound and outbound email settings.' },
    { id: 'business_hours', name: 'Business Hours', icon: ClockIcon, description: 'Define operational hours and working days for SLA calculations.' },
    { id: 'sla_targets', name: 'SLA Targets', icon: ShieldCheckIcon, description: 'Configure response and resolution targets per ticket priority.' },
    { id: 'ticket_retention', name: 'Ticket Retention', icon: ArchiveBoxIcon, description: 'Control when archived tickets become eligible for permanent purge.' },
    { id: 'integrations', name: 'Integrations', icon: MapIcon, description: 'External API keys and third-party services.' },
    { id: 'thresholds', name: 'Health Thresholds', icon: ChartBarIcon, description: 'Ticket count limits and status labels.' },
    { id: 'sidebar_layout', name: 'Sidebar Layout', icon: Bars3BottomLeftIcon, description: 'Drag to reorder sidebar sections and sub-menu items.' },
    { id: 'auto_assignee', name: 'Auto Assignee', icon: UserGroupIcon, description: 'Automatically assign incoming tickets based on requester email rules and round-robin.' },
];

// Sidebar layout drag state
const { state: sidebarState, init: initSidebar, serialize: serializeSidebar, reset: resetSidebarOrder, updateSectionLabel, updateChildLabel, getSectionLabel, getChildLabel, ensureDynamicFormChildren } = useSidebarOrder();
const settingsPage = usePage();
const dynamicForms = computed(() => settingsPage.props.dynamicForms || []);

// Initialize sidebar state from props
const rawLayout = props.settings.sidebar_layout;
if (rawLayout) {
    try {
        const parsed = typeof rawLayout === 'string' ? JSON.parse(rawLayout) : rawLayout;
        initSidebar(parsed);
    } catch (e) {
        console.error('Failed to parse sidebar layout', e);
        initSidebar(null);
    }
} else {
    initSidebar(null);
}

// Make active Form Builder menus available in the Services sub-item editor.
ensureDynamicFormChildren(dynamicForms.value);

const sectionItems = ref(
    sidebarState.sections.map(id => ({ id, label: getSectionLabel(id) }))
);
const expandedSidebarSection = ref(null);
const expandedChildItems = ref([]);

const toggleSidebarSection = (sectionId) => {
    // Sync current children back to state before switching or closing
    if (expandedSidebarSection.value) {
        expandedChildItems.value.forEach(item => updateChildLabel(expandedSidebarSection.value, item.id, item.label));
    }

    if (expandedSidebarSection.value === sectionId) {
        expandedSidebarSection.value = null;
        expandedChildItems.value = [];
        return;
    }
    expandedSidebarSection.value = sectionId;
    expandedChildItems.value = (sidebarState.children[sectionId] || []).map(id => ({
        id,
        label: getChildLabel(sectionId, id),
    }));
};

const onSectionUpdate = () => {
    sidebarState.sections.splice(0, sidebarState.sections.length, ...sectionItems.value.map(s => s.id));
    // Sync labels
    sectionItems.value.forEach(item => updateSectionLabel(item.id, item.label));
};

const onChildUpdate = () => {
    if (expandedSidebarSection.value) {
        sidebarState.children[expandedSidebarSection.value] = expandedChildItems.value.map(c => c.id);
        // Sync labels
        expandedChildItems.value.forEach(item => updateChildLabel(expandedSidebarSection.value, item.id, item.label));
    }
};

const sidebarSaved = ref(false);
const saveSidebarLayout = () => {
    // Sync labels back to state before serializing
    sectionItems.value.forEach(item => updateSectionLabel(item.id, item.label));
    if (expandedSidebarSection.value) {
        expandedChildItems.value.forEach(item => updateChildLabel(expandedSidebarSection.value, item.id, item.label));
    }

    form.sidebar_layout = serializeSidebar();
    
    form.put(route('settings.update'), {
        preserveScroll: true,
        onSuccess: () => {
            sidebarSaved.value = true;
            setTimeout(() => { sidebarSaved.value = false; }, 2500);
        }
    });
};

const resetSidebarLayout = () => {
    resetSidebarOrder();
    ensureDynamicFormChildren(dynamicForms.value);
    sectionItems.value = sidebarState.sections.map(id => ({ id, label: getSectionLabel(id) }));
    expandedSidebarSection.value = null;
    expandedChildItems.value = [];
};

onMounted(() => {
    ensureDynamicFormChildren(dynamicForms.value);
});

// ---- Auto Assignee state ----
const parseJsonSetting = (key, fallback) => {
    const raw = props.settings[key];
    if (!raw) return fallback;
    try { return typeof raw === 'string' ? JSON.parse(raw) : raw; } catch { return fallback; }
};

const autoRules = ref(
    parseJsonSetting('auto_assignee_rules', []).map(r => ({
        email: r.email ?? '',
        assignee_ids: Array.isArray(r.assignee_ids) ? r.assignee_ids.map(Number) : [],
    }))
);
const autoDefaults = ref(parseJsonSetting('auto_assignee_defaults', []).map(Number));
const autoAssigneeSaved = ref(false);
const autoAssigneeProcessing = ref(false);

// Rules table: filter + inline-edit state
const rulesListSearch = ref('');
const editingRuleIndex = ref(null);
const ruleSearchQueries = ref(autoRules.value.map(() => ''));
const defaultSearchQuery = ref('');
const activeDropdown = ref(null); // null | 'default' | `rule-${index}`

const filteredRuleIndexes = computed(() => {
    const q = rulesListSearch.value.toLowerCase().trim();
    if (!q) return autoRules.value.map((_, i) => i);
    return autoRules.value.reduce((acc, r, i) => {
        if (r.email.toLowerCase().includes(q)) acc.push(i);
        return acc;
    }, []);
});

const getAgent = (id) => props.assignableStaff?.find(a => a.id === id) ?? null;
const getAgentInitials = (id) => {
    const name = getAgent(id)?.name ?? '';
    return name.split(' ').filter(Boolean).map(n => n[0]).join('').toUpperCase().slice(0, 2) || '?';
};

const filteredStaffForIds = (assigneeIds, query) => {
    const q = (query || '').toLowerCase();
    return (props.assignableStaff || []).filter(a =>
        !assigneeIds.includes(a.id) &&
        (q === '' || a.name.toLowerCase().includes(q) || (a.email || '').toLowerCase().includes(q))
    );
};

const filteredDefaultStaff = computed(() => {
    const q = defaultSearchQuery.value.toLowerCase();
    return (props.assignableStaff || []).filter(a =>
        !autoDefaults.value.includes(a.id) &&
        (q === '' || a.name.toLowerCase().includes(q) || (a.email || '').toLowerCase().includes(q))
    );
});

const openDropdown = (key) => { activeDropdown.value = key; };
const closeDropdown = () => { setTimeout(() => { activeDropdown.value = null; }, 150); };

const addAutoRule = () => {
    autoRules.value.unshift({ email: '', assignee_ids: [] });
    ruleSearchQueries.value.unshift('');
    editingRuleIndex.value = 0;
    rulesListSearch.value = '';
};

const removeAutoRule = (index) => {
    autoRules.value.splice(index, 1);
    ruleSearchQueries.value.splice(index, 1);
    if (editingRuleIndex.value === index) editingRuleIndex.value = null;
    else if (editingRuleIndex.value > index) editingRuleIndex.value--;
};

const toggleEditRule = (index) => {
    editingRuleIndex.value = editingRuleIndex.value === index ? null : index;
};

const selectRuleAssignee = (rule, index, agentId) => {
    if (!rule.assignee_ids.includes(agentId)) rule.assignee_ids.push(agentId);
    ruleSearchQueries.value[index] = '';
};

const removeRuleAssignee = (rule, agentId) => {
    const idx = rule.assignee_ids.indexOf(agentId);
    if (idx !== -1) rule.assignee_ids.splice(idx, 1);
};

const selectDefaultAssignee = (agentId) => {
    if (!autoDefaults.value.includes(agentId)) autoDefaults.value.push(agentId);
    defaultSearchQuery.value = '';
};

const removeDefaultAssignee = (agentId) => {
    const idx = autoDefaults.value.indexOf(agentId);
    if (idx !== -1) autoDefaults.value.splice(idx, 1);
};

// Draggable helpers — convert ID arrays to/from [{id}] objects
const getRuleAssigneeObjects = (rule) => rule.assignee_ids.map(id => ({ id }));
const setRuleAssigneeObjects = (rule, items) => {
    rule.assignee_ids.splice(0, rule.assignee_ids.length, ...items.map(o => o.id));
};
const getDefaultAssigneeObjects = computed(() => autoDefaults.value.map(id => ({ id })));
const setDefaultAssigneeObjects = (items) => {
    autoDefaults.value.splice(0, autoDefaults.value.length, ...items.map(o => o.id));
};

const saveAutoAssignee = () => {
    autoAssigneeProcessing.value = true;
    router.put(route('settings.update'), {
        auto_assignee_rules: JSON.stringify(autoRules.value),
        auto_assignee_defaults: JSON.stringify(autoDefaults.value),
    }, {
        preserveScroll: true,
        onSuccess: () => {
            autoAssigneeSaved.value = true;
            setTimeout(() => { autoAssigneeSaved.value = false; }, 2500);
        },
        onFinish: () => { autoAssigneeProcessing.value = false; },
    });
};

const currentTab = computed(() => tabs.find(t => t.id === activeTab.value) || tabs[0]);

const showImapPassword = ref(false);
const showMailPassword = ref(false);
const showMapsKey = ref(false);

const dayNames = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

const slugify = (text) => {
    return text.toString().toLowerCase()
        .replace(/\s+/g, '_')           // Replace spaces with _
        .replace(/[^\w-]+/g, '')       // Remove all non-word chars
        .replace(/--+/g, '_')           // Replace multiple - with single _
        .replace(/^-+/, '')             // Trim - from start of text
        .replace(/-+$/, '');            // Trim - from end of text
};

const parseWorkingDays = (days) => {
    if (!days) return [1, 2, 3, 4, 5];
    try {
        return typeof days === 'string' ? JSON.parse(days) : days;
    } catch (e) {
        return [1, 2, 3, 4, 5];
    }
};

const getInitialFormData = () => {
    const data = {
        imap_host: props.settings.imap_host || '',
        imap_port: props.settings.imap_port || '993',
        imap_encryption: props.settings.imap_encryption || 'ssl',
        imap_username: props.settings.imap_username || '',
        imap_password: props.settings.imap_password || '',
        mail_mailer: props.settings.mail_mailer || 'smtp',
        mail_host: props.settings.mail_host || '',
        mail_port: props.settings.mail_port || '587',
        mail_username: props.settings.mail_username || '',
        mail_password: props.settings.mail_password || '',
        mail_encryption: props.settings.mail_encryption || 'tls',
        mail_from_address: props.settings.mail_from_address || '',
        mail_from_name: props.settings.mail_from_name || '',
        google_maps_api_key: props.settings.google_maps_api_key || '',
        threshold_green_min: props.settings.threshold_green_min || 1,
        threshold_green_max: props.settings.threshold_green_max || 2,
        threshold_green_label: props.settings.threshold_green_label || 'Healthy',
        threshold_yellow_min: props.settings.threshold_yellow_min || 3,
        threshold_yellow_max: props.settings.threshold_yellow_max || 3,
        threshold_yellow_label: props.settings.threshold_yellow_label || 'Warning',
        threshold_orange_min: props.settings.threshold_orange_min || 4,
        threshold_orange_max: props.settings.threshold_orange_max || 4,
        threshold_orange_label: props.settings.threshold_orange_label || 'At-risk',
        threshold_red_min: props.settings.threshold_red_min || 5,
        threshold_red_label: props.settings.threshold_red_label || 'Critical',
        business_start_time: props.settings.business_start_time || '08:00',
        business_end_time: props.settings.business_end_time || '17:00',
        working_days: parseWorkingDays(props.settings.working_days),
        sla_low_response: props.settings.sla_low_response || 24,
        sla_low_resolution: props.settings.sla_low_resolution || 72,
        sla_low_label: props.settings.sla_low_label || 'P4',
        sla_medium_response: props.settings.sla_medium_response || 8,
        sla_medium_resolution: props.settings.sla_medium_resolution || 48,
        sla_medium_label: props.settings.sla_medium_label || 'P3',
        sla_high_response: props.settings.sla_high_response || 4,
        sla_high_resolution: props.settings.sla_high_resolution || 24,
        sla_high_label: props.settings.sla_high_label || 'P2',
        sla_urgent_response: props.settings.sla_urgent_response || 1,
        sla_urgent_resolution: props.settings.sla_urgent_resolution || 8,
        sla_urgent_label: props.settings.sla_urgent_label || 'P1',
        auto_close_resolved_hours: props.settings.auto_close_resolved_hours || 72,
        waiting_aging_alarm_days: props.settings.waiting_aging_alarm_days || 3,
        ticket_retention_value: props.settings.ticket_retention_value || 6,
        ticket_retention_unit: props.settings.ticket_retention_unit || 'months',
        sidebar_layout: props.settings.sidebar_layout || null,
    };

    // Add sub-unit specific settings
    props.subUnits.forEach(unit => {
        const slug = slugify(unit);
        data[`business_start_time_${slug}`] = props.settings[`business_start_time_${slug}`] || props.settings.business_start_time || '08:00';
        data[`business_end_time_${slug}`] = props.settings[`business_end_time_${slug}`] || props.settings.business_end_time || '17:00';
        data[`working_days_${slug}`] = parseWorkingDays(props.settings[`working_days_${slug}`] || props.settings.working_days);
    });

    // Add sub-unit specific threshold settings
    props.subUnits.forEach(unit => {
        const slug = slugify(unit);
        const defaults = {
            green_min: 1, green_max: 2, green_label: 'Healthy',
            yellow_min: 3, yellow_max: 3, yellow_label: 'Warning',
            orange_min: 4, orange_max: 4, orange_label: 'At-risk',
            red_min: 5, red_label: 'Critical',
        };
        Object.entries(defaults).forEach(([field, fallback]) => {
            const parts = field.split('_');
            const color = parts[0];
            const suffix = parts.slice(1).join('_');
            const key = `threshold_${color}_${suffix}_${slug}`;
            const globalKey = `threshold_${color}_${suffix}`;
            data[key] = props.settings[key] || props.settings[globalKey] || fallback;
        });
    });

    return data;
};

const form = useForm(getInitialFormData());

const subUnitOptions = computed(() => {
    return [
        { id: 'global', name: 'Global Default' },
        ...props.subUnits.map(unit => ({ id: slugify(unit), name: unit }))
    ];
});

const currentStartTimeKey = computed(() => {
    return selectedSubUnit.value === 'global' ? 'business_start_time' : `business_start_time_${selectedSubUnit.value}`;
});

const currentEndTimeKey = computed(() => {
    return selectedSubUnit.value === 'global' ? 'business_end_time' : `business_end_time_${selectedSubUnit.value}`;
});

const currentWorkingDaysKey = computed(() => {
    return selectedSubUnit.value === 'global' ? 'working_days' : `working_days_${selectedSubUnit.value}`;
});

const thresholdKey = (color, field) => {
    return selectedThresholdSubUnit.value === 'global'
        ? `threshold_${color}_${field}`
        : `threshold_${color}_${field}_${selectedThresholdSubUnit.value}`;
};

const submit = () => {
    form.put(route('settings.update'), {
        preserveScroll: true,
    });
};

const testingConnection = ref(false);
const syncingEmails = ref(false);
const testResult = ref(null);

const testConnection = () => {
    testingConnection.value = true;
    testResult.value = null;

    axios.post(route('settings.test-imap', {}, false), {
        imap_host: form.imap_host,
        imap_port: form.imap_port,
        imap_encryption: form.imap_encryption,
        imap_username: form.imap_username,
        imap_password: form.imap_password,
    })
    .then(response => {
        testResult.value = response.data;
    })
    .catch(error => {
        testResult.value = {
            status: 'error',
            message: 'Request failed: ' + (error.response?.data?.message || error.message)
        };
    })
    .finally(() => {
        testingConnection.value = false;
    });
};

const syncEmails = () => {
    syncingEmails.value = true;
    testResult.value = null;

    axios.post(route('tickets.sync', {}, false))
    .then(response => {
        testResult.value = response.data;
        if (response.data.status === 'success' || response.data.status === 'warning') {
             // force reload to get updated last sync time from props
             router.reload({ only: ['settings'] });
        }
    })
    .catch(error => {
        testResult.value = {
            status: 'error',
            message: 'Sync failed: ' + (error.response?.data?.message || error.message)
        };
    })
    .finally(() => {
        syncingEmails.value = false;
    });
};
</script>

<template>
    <Head title="System Settings" />

    <AppLayout>
        <template #header>
            <div class="flex items-center space-x-2">
                <Cog6ToothIcon class="w-6 h-6 text-gray-500" />
                <span>System Settings</span>
            </div>
        </template>

        <div class="flex flex-col lg:flex-row gap-8">
            <!-- Navigation Sidebar -->
            <aside class="w-full lg:w-64 flex-shrink-0">
                <nav class="space-y-1">
                    <button
                        v-for="tab in tabs"
                        :key="tab.id"
                        @click="activeTab = tab.id"
                        type="button"
                        :class="[
                            activeTab === tab.id
                                ? 'bg-blue-50 text-blue-700 border-blue-600'
                                : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900 border-transparent',
                            'group flex items-center px-4 py-3 text-sm font-bold border-l-4 transition-all w-full text-left'
                        ]"
                    >
                        <component
                            :is="tab.icon"
                            :class="[
                                activeTab === tab.id ? 'text-blue-600' : 'text-gray-400 group-hover:text-gray-500',
                                'mr-3 h-5 w-5 flex-shrink-0'
                            ]"
                        />
                        {{ tab.name }}
                    </button>
                </nav>
            </aside>

            <!-- Content Area -->
            <div class="flex-1 max-w-4xl">
                <form @submit.prevent="submit">
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                        
                        <!-- Tab Header -->
                        <div class="px-6 py-5 bg-gray-50 border-b border-gray-200">
                            <h2 class="text-xl font-black text-gray-900">
                                {{ currentTab.name }}
                            </h2>
                            <p class="text-sm text-gray-500 mt-1">
                                {{ currentTab.description }}
                            </p>
                        </div>

                        <!-- Tab Content -->
                        <div class="p-6 min-h-[400px]">
                            
                            <!-- Mail Tab -->
                            <div v-if="activeTab === 'mail'" class="space-y-10">
                                <!-- Inbound (IMAP) -->
                                <section>
                                    <div class="flex items-center justify-between mb-6">
                                        <h3 class="text-xs font-black text-blue-600 uppercase tracking-widest flex items-center">
                                            <ServerIcon class="w-4 h-4 mr-2" />
                                            Inbound Mail (IMAP)
                                        </h3>
                                        <div class="flex items-center space-x-2">
                                            <div v-if="settings.last_email_sync_at" class="flex items-center text-[10px] font-bold text-gray-400 bg-gray-100 px-3 py-1 rounded-full">
                                                <ClockIcon class="w-3 h-3 mr-1" />
                                                Last sync: {{ settings.last_email_sync_at }}
                                            </div>
                                            <button 
                                                type="button" 
                                                @click="syncEmails" 
                                                :disabled="syncingEmails"
                                                class="flex items-center text-[10px] font-bold text-blue-600 bg-blue-50 hover:bg-blue-100 px-3 py-1 rounded-full transition-colors disabled:opacity-50"
                                            >
                                                <span v-if="syncingEmails">Syncing...</span>
                                                <span v-else>Sync Now</span>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                        <div class="md:col-span-2">
                                            <InputLabel for="imap_username" value="Support Email Address" />
                                            <TextInput id="imap_username" type="email" class="mt-1 block w-full" v-model="form.imap_username" placeholder="support@company.com" />
                                            <InputError class="mt-2" :message="form.errors.imap_username" />
                                        </div>
                                        <div>
                                            <InputLabel for="imap_host" value="IMAP Host" />
                                            <TextInput id="imap_host" type="text" class="mt-1 block w-full" v-model="form.imap_host" placeholder="imap.gmail.com" />
                                            <InputError class="mt-2" :message="form.errors.imap_host" />
                                        </div>
                                        <div class="grid grid-cols-2 gap-4">
                                            <div>
                                                <InputLabel for="imap_port" value="Port" />
                                                <TextInput id="imap_port" type="text" class="mt-1 block w-full" v-model="form.imap_port" placeholder="993" />
                                            </div>
                                            <div>
                                                <InputLabel for="imap_encryption" value="Encryption" />
                                                <select v-model="form.imap_encryption" class="mt-1 block w-full border-gray-300 focus:border-blue-500 focus:ring-blue-500 rounded-md shadow-sm text-sm">
                                                    <option value="ssl">SSL</option>
                                                    <option value="tls">TLS</option>
                                                    <option value="notls">None</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="md:col-span-2">
                                            <InputLabel for="imap_password" value="IMAP Password / App Password" />
                                            <div class="relative mt-1">
                                                <TextInput id="imap_password" :type="showImapPassword ? 'text' : 'password'" class="block w-full pr-10" v-model="form.imap_password" placeholder="••••••••••••" />
                                                <button type="button" @click="showImapPassword = !showImapPassword" class="absolute inset-y-0 right-0 pr-3 flex items-center text-gray-400 hover:text-gray-600">
                                                    <EyeIcon v-if="!showImapPassword" class="h-5 w-5" />
                                                    <EyeSlashIcon v-else class="h-5 w-5" />
                                                </button>
                                            </div>
                                            <div class="mt-4 flex items-center justify-between">
                                                <p class="text-[10px] text-gray-500 italic">For Gmail accounts, please generate and use a 16-character App Password.</p>
                                                <button 
                                                    type="button" 
                                                    @click="testConnection" 
                                                    :disabled="testingConnection"
                                                    class="inline-flex items-center px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 disabled:opacity-25 transition ease-in-out duration-150"
                                                >
                                                    <span v-if="testingConnection">Testing...</span>
                                                    <span v-else>Test Connection</span>
                                                </button>
                                            </div>

                                            <div v-if="testResult" class="mt-4 p-4 rounded-lg border flex items-start space-x-3" :class="testResult.status === 'success' ? 'bg-green-50 border-green-200 text-green-700' : 'bg-red-50 border-red-200 text-red-700'">
                                                <CheckCircleIcon v-if="testResult.status === 'success'" class="w-5 h-5 flex-shrink-0 mt-0.5" />
                                                <div v-else class="w-5 h-5 flex-shrink-0 mt-0.5 rounded-full bg-red-600 text-white flex items-center justify-center text-[10px] font-bold">X</div>
                                                <p class="text-xs font-bold leading-relaxed">{{ testResult.message }}</p>
                                            </div>
                                        </div>
                                    </div>
                                </section>

                                <div class="border-t border-gray-100"></div>

                                <!-- Outbound (SMTP) -->
                                <section>
                                    <h3 class="text-xs font-black text-green-600 uppercase tracking-widest mb-6 flex items-center">
                                        <PaperAirplaneIcon class="w-4 h-4 mr-2" />
                                        Outbound Mail (SMTP)
                                    </h3>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                        <div class="md:col-span-2 grid grid-cols-1 md:grid-cols-2 gap-6">
                                            <div>
                                                <InputLabel for="mail_from_name" value="Sender Display Name" />
                                                <TextInput id="mail_from_name" type="text" class="mt-1 block w-full" v-model="form.mail_from_name" placeholder="Helpdesk Notifications" />
                                            </div>
                                            <div>
                                                <InputLabel for="mail_from_address" value="Sender Email Address" />
                                                <TextInput id="mail_from_address" type="email" class="mt-1 block w-full" v-model="form.mail_from_address" placeholder="noreply@company.com" />
                                            </div>
                                        </div>
                                        <div>
                                            <InputLabel for="mail_mailer" value="Mail Driver" />
                                            <select v-model="form.mail_mailer" class="mt-1 block w-full border-gray-300 focus:border-blue-500 focus:ring-blue-500 rounded-md shadow-sm text-sm">
                                                <option value="smtp">SMTP</option>
                                                <option value="log">Log (Testing)</option>
                                                <option value="sendmail">Sendmail</option>
                                            </select>
                                        </div>
                                        <div v-if="form.mail_mailer === 'smtp'">
                                            <InputLabel for="mail_host" value="SMTP Host" />
                                            <TextInput id="mail_host" type="text" class="mt-1 block w-full" v-model="form.mail_host" placeholder="smtp.gmail.com" />
                                        </div>
                                        <div v-if="form.mail_mailer === 'smtp'" class="grid grid-cols-2 gap-4">
                                            <div>
                                                <InputLabel for="mail_port" value="Port" />
                                                <TextInput id="mail_port" type="text" class="mt-1 block w-full" v-model="form.mail_port" placeholder="587" />
                                            </div>
                                            <div>
                                                <InputLabel for="mail_encryption" value="Encryption" />
                                                <select v-model="form.mail_encryption" class="mt-1 block w-full border-gray-300 focus:border-blue-500 focus:ring-blue-500 rounded-md shadow-sm text-sm">
                                                    <option value="tls">TLS</option>
                                                    <option value="ssl">SSL</option>
                                                    <option value="none">None</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div v-if="form.mail_mailer === 'smtp'">
                                            <InputLabel for="mail_username" value="SMTP Username" />
                                            <TextInput id="mail_username" type="text" class="mt-1 block w-full" v-model="form.mail_username" />
                                        </div>
                                        <div v-if="form.mail_mailer === 'smtp'" class="md:col-span-2">
                                            <InputLabel for="mail_password" value="SMTP Password" />
                                            <div class="relative mt-1">
                                                <TextInput id="mail_password" :type="showMailPassword ? 'text' : 'password'" class="block w-full pr-10" v-model="form.mail_password" />
                                                <button type="button" @click="showMailPassword = !showMailPassword" class="absolute inset-y-0 right-0 pr-3 flex items-center text-gray-400 hover:text-gray-600">
                                                    <EyeIcon v-if="!showMailPassword" class="h-5 w-5" />
                                                    <EyeSlashIcon v-else class="h-5 w-5" />
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </section>
                            </div>

                            <!-- Business Hours Tab -->
                            <div v-if="activeTab === 'business_hours'" class="space-y-8">
                                <div class="bg-blue-50 p-4 rounded-xl border border-blue-100 flex items-center justify-between mb-6">
                                    <div class="flex items-center space-x-3">
                                        <div class="p-2 bg-blue-600 rounded-lg">
                                            <AdjustmentsHorizontalIcon class="w-5 h-5 text-white" />
                                        </div>
                                        <div>
                                            <h4 class="text-sm font-black text-blue-900 uppercase tracking-tight">Configure Hours For:</h4>
                                            <p class="text-[10px] text-blue-600 font-bold">Select "Global Default" or a specific Sub-Unit</p>
                                        </div>
                                    </div>
                                    <select 
                                        v-model="selectedSubUnit"
                                        class="border-blue-200 focus:ring-blue-500 focus:border-blue-500 rounded-lg text-sm font-black text-blue-700 bg-white shadow-sm"
                                    >
                                        <option v-for="option in subUnitOptions" :key="option.id" :value="option.id">
                                            {{ option.name }}
                                        </option>
                                    </select>
                                </div>

                                <section>
                                    <h3 class="text-xs font-black text-blue-600 uppercase tracking-widest mb-6 flex items-center">
                                        <ClockIcon class="w-4 h-4 mr-2" />
                                        Operational Time ({{ subUnitOptions.find(o => o.id === selectedSubUnit)?.name }})
                                    </h3>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 max-w-xl">
                                        <div>
                                            <InputLabel :for="currentStartTimeKey" value="Start Time" />
                                            <TextInput :id="currentStartTimeKey" type="time" class="mt-1 block w-full" v-model="form[currentStartTimeKey]" />
                                            <InputError class="mt-2" :message="form.errors[currentStartTimeKey]" />
                                        </div>
                                        <div>
                                            <InputLabel :for="currentEndTimeKey" value="End Time" />
                                            <TextInput :id="currentEndTimeKey" type="time" class="mt-1 block w-full" v-model="form[currentEndTimeKey]" />
                                            <InputError class="mt-2" :message="form.errors[currentEndTimeKey]" />
                                        </div>
                                    </div>
                                </section>

                                <div class="border-t border-gray-100"></div>

                                <section>
                                    <h3 class="text-xs font-black text-blue-600 uppercase tracking-widest mb-6 flex items-center">
                                        <AdjustmentsHorizontalIcon class="w-4 h-4 mr-2" />
                                        Working Days ({{ subUnitOptions.find(o => o.id === selectedSubUnit)?.name }})
                                    </h3>
                                    <div class="flex flex-wrap gap-3">
                                        <label v-for="(day, index) in dayNames" :key="index" 
                                               class="inline-flex items-center px-4 py-2 rounded-xl border text-sm font-bold cursor-pointer transition-all shadow-sm"
                                               :class="form[currentWorkingDaysKey].includes(index + 1) ? 'bg-blue-600 border-blue-600 text-white' : 'bg-white border-gray-200 text-gray-500 hover:bg-gray-50'">
                                            <input type="checkbox" :value="index + 1" v-model="form[currentWorkingDaysKey]" class="hidden">
                                            {{ day }}
                                        </label>
                                    </div>
                                    <p class="mt-4 text-[10px] text-gray-400 italic">These days are used to calculate SLA deadlines and response times for {{ selectedSubUnit === 'global' ? 'all tickets by default' : 'tickets assigned to this sub-unit' }}.</p>
                                    <InputError class="mt-2" :message="form.errors[currentWorkingDaysKey]" />
                                </section>
                            </div>

                            <!-- SLA Targets Tab -->
                            <div v-if="activeTab === 'sla_targets'" class="space-y-8">
                                <div class="p-4 bg-blue-50 rounded-lg border border-blue-100 flex items-start mb-4">
                                    <ShieldCheckIcon class="w-5 h-5 text-blue-600 mt-0.5 mr-3 flex-shrink-0" />
                                    <p class="text-xs text-blue-700 leading-relaxed">
                                        Configure global SLA targets based on ticket priority. These targets will be applied to all new tickets regardless of their category.
                                    </p>
                                </div>

                                <div class="space-y-6">
                                    <!-- Urgent / P1 -->
                                    <div class="p-4 bg-red-50 rounded-xl border border-red-100 space-y-4">
                                        <div class="flex items-center justify-between">
                                            <div class="flex items-center space-x-2">
                                                <div class="w-3 h-3 rounded-full bg-red-600 shadow-sm animate-pulse"></div>
                                                <span class="text-xs font-black text-red-700 uppercase tracking-widest">Urgent Priority</span>
                                            </div>
                                            <TextInput type="text" class="!w-20 !py-1 !text-center !font-bold !bg-white border-red-200" v-model="form.sla_urgent_label" placeholder="P1" />
                                        </div>
                                        <div class="grid grid-cols-2 gap-6">
                                            <div>
                                                <InputLabel value="Response Target (Hours)" class="!text-[10px] uppercase text-red-600" />
                                                <TextInput type="number" class="mt-1 block w-full border-red-100" v-model="form.sla_urgent_response" />
                                            </div>
                                            <div>
                                                <InputLabel value="Resolution Target (Hours)" class="!text-[10px] uppercase text-red-600" />
                                                <TextInput type="number" class="mt-1 block w-full border-red-100" v-model="form.sla_urgent_resolution" />
                                            </div>
                                        </div>
                                    </div>

                                    <!-- High / P2 -->
                                    <div class="p-4 bg-orange-50 rounded-xl border border-orange-100 space-y-4">
                                        <div class="flex items-center justify-between">
                                            <div class="flex items-center space-x-2">
                                                <div class="w-3 h-3 rounded-full bg-orange-500 shadow-sm"></div>
                                                <span class="text-xs font-black text-orange-700 uppercase tracking-widest">High Priority</span>
                                            </div>
                                            <TextInput type="text" class="!w-20 !py-1 !text-center !font-bold !bg-white border-orange-200" v-model="form.sla_high_label" placeholder="P2" />
                                        </div>
                                        <div class="grid grid-cols-2 gap-6">
                                            <div>
                                                <InputLabel value="Response Target (Hours)" class="!text-[10px] uppercase text-orange-600" />
                                                <TextInput type="number" class="mt-1 block w-full border-orange-100" v-model="form.sla_high_response" />
                                            </div>
                                            <div>
                                                <InputLabel value="Resolution Target (Hours)" class="!text-[10px] uppercase text-orange-600" />
                                                <TextInput type="number" class="mt-1 block w-full border-orange-100" v-model="form.sla_high_resolution" />
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Medium / P3 -->
                                    <div class="p-4 bg-yellow-50 rounded-xl border border-yellow-100 space-y-4">
                                        <div class="flex items-center justify-between">
                                            <div class="flex items-center space-x-2">
                                                <div class="w-3 h-3 rounded-full bg-yellow-400 shadow-sm"></div>
                                                <span class="text-xs font-black text-yellow-700 uppercase tracking-widest">Medium Priority</span>
                                            </div>
                                            <TextInput type="text" class="!w-20 !py-1 !text-center !font-bold !bg-white border-yellow-200" v-model="form.sla_medium_label" placeholder="P3" />
                                        </div>
                                        <div class="grid grid-cols-2 gap-6">
                                            <div>
                                                <InputLabel value="Response Target (Hours)" class="!text-[10px] uppercase text-yellow-600" />
                                                <TextInput type="number" class="mt-1 block w-full border-yellow-100" v-model="form.sla_medium_response" />
                                            </div>
                                            <div>
                                                <InputLabel value="Resolution Target (Hours)" class="!text-[10px] uppercase text-yellow-600" />
                                                <TextInput type="number" class="mt-1 block w-full border-yellow-100" v-model="form.sla_medium_resolution" />
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Low / P4 -->
                                    <div class="p-4 bg-green-50 rounded-xl border border-green-100 space-y-4">
                                        <div class="flex items-center justify-between">
                                            <div class="flex items-center space-x-2">
                                                <div class="w-3 h-3 rounded-full bg-green-500 shadow-sm"></div>
                                                <span class="text-xs font-black text-green-700 uppercase tracking-widest">Low Priority</span>
                                            </div>
                                            <TextInput type="text" class="!w-20 !py-1 !text-center !font-bold !bg-white border-green-200" v-model="form.sla_low_label" placeholder="P4" />
                                        </div>
                                        <div class="grid grid-cols-2 gap-6">
                                            <div>
                                                <InputLabel value="Response Target (Hours)" class="!text-[10px] uppercase text-green-600" />
                                                <TextInput type="number" class="mt-1 block w-full border-green-100" v-model="form.sla_low_response" />
                                            </div>
                                            <div>
                                                <InputLabel value="Resolution Target (Hours)" class="!text-[10px] uppercase text-green-600" />
                                                <TextInput type="number" class="mt-1 block w-full border-green-100" v-model="form.sla_low_resolution" />
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Auto-close Configuration -->
                                <div class="p-4 bg-gray-50 rounded-xl border border-gray-200 space-y-4">
                                    <div class="flex items-center space-x-2">
                                        <ClockIcon class="w-4 h-4 text-gray-500" />
                                        <span class="text-xs font-black text-gray-700 uppercase tracking-widest">Auto-Close Resolved Tickets</span>
                                    </div>
                                    <div class="max-w-xs">
                                        <InputLabel value="Hours before closing Resolved tickets" class="!text-[10px] uppercase text-gray-600" />
                                        <div class="flex items-center space-x-3 mt-1">
                                            <TextInput type="number" class="block w-full" v-model="form.auto_close_resolved_hours" />
                                            <span class="text-xs font-bold text-gray-400">Hours</span>
                                        </div>
                                        <p class="mt-2 text-[9px] text-gray-400 italic">
                                            Resolved tickets will automatically change to "Closed" after these hours, respecting global business hours and working days.
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <!-- Ticket Retention Tab -->
                            <div v-if="activeTab === 'ticket_retention'" class="space-y-8">
                                <section>
                                    <h3 class="text-xs font-black text-red-600 uppercase tracking-widest mb-6 flex items-center">
                                        <ArchiveBoxIcon class="w-4 h-4 mr-2" />
                                        Archive Purge Eligibility
                                    </h3>

                                    <div class="max-w-xl space-y-6">
                                        <div class="p-4 bg-red-50 rounded-xl border border-red-100">
                                            <p class="text-sm font-black text-red-900">Manual purge retention</p>
                                            <p class="mt-1 text-xs text-red-700 leading-relaxed">
                                                Archived tickets remain restorable until they are older than this retention window. Purging is still manual and requires confirmation from the Ticket Archive page.
                                            </p>
                                        </div>

                                        <div class="grid grid-cols-1 md:grid-cols-[minmax(0,1fr)_180px] gap-4">
                                            <div>
                                                <InputLabel for="ticket_retention_value" value="Retention Length" />
                                                <TextInput
                                                    id="ticket_retention_value"
                                                    type="number"
                                                    min="1"
                                                    class="mt-1 block w-full"
                                                    v-model="form.ticket_retention_value"
                                                />
                                                <InputError class="mt-2" :message="form.errors.ticket_retention_value" />
                                            </div>
                                            <div>
                                                <InputLabel for="ticket_retention_unit" value="Unit" />
                                                <select
                                                    id="ticket_retention_unit"
                                                    v-model="form.ticket_retention_unit"
                                                    class="mt-1 block w-full border-gray-300 focus:border-blue-500 focus:ring-blue-500 rounded-md shadow-sm text-sm"
                                                >
                                                    <option value="months">Months</option>
                                                    <option value="years">Years</option>
                                                </select>
                                                <InputError class="mt-2" :message="form.errors.ticket_retention_unit" />
                                            </div>
                                        </div>
                                    </div>
                                </section>
                            </div>

                            <!-- Integrations Tab -->
                            <div v-if="activeTab === 'integrations'" class="space-y-8">
                                <section>
                                    <h3 class="text-xs font-black text-red-600 uppercase tracking-widest mb-6 flex items-center">
                                        <MapIcon class="w-4 h-4 mr-2" />
                                        Google Maps Platform
                                    </h3>
                                    <div class="max-w-xl">
                                        <InputLabel for="google_maps_api_key" value="Google Maps API Key" />
                                        <div class="relative mt-1">
                                            <TextInput
                                                id="google_maps_api_key"
                                                :type="showMapsKey ? 'text' : 'password'"
                                                class="block w-full font-mono text-sm pr-10"
                                                v-model="form.google_maps_api_key"
                                                placeholder="AIzaSy..."
                                            />
                                            <button type="button" @click="showMapsKey = !showMapsKey" class="absolute inset-y-0 right-0 pr-3 flex items-center text-gray-400 hover:text-gray-600">
                                                <EyeIcon v-if="!showMapsKey" class="h-5 w-5" />
                                                <EyeSlashIcon v-else class="h-5 w-5" />
                                            </button>
                                        </div>
                                        <p class="mt-2 text-[10px] text-gray-500 italic">This key is used for Store Geofencing and DTR location verification.</p>
                                        <InputError class="mt-2" :message="form.errors.google_maps_api_key" />
                                    </div>
                                </section>
                            </div>

                            <!-- Thresholds Tab -->
                            <div v-if="activeTab === 'thresholds'" class="space-y-6">
                                <div class="bg-blue-50 p-4 rounded-xl border border-blue-100 flex items-center justify-between mb-2">
                                    <div class="flex items-center space-x-3">
                                        <div class="p-2 bg-blue-600 rounded-lg">
                                            <AdjustmentsHorizontalIcon class="w-5 h-5 text-white" />
                                        </div>
                                        <div>
                                            <p class="text-sm font-black text-blue-900">Threshold Configuration</p>
                                            <p class="text-xs text-blue-600">Configure per sub-unit or set a global default.</p>
                                        </div>
                                    </div>
                                    <select
                                        v-model="selectedThresholdSubUnit"
                                        class="border-blue-200 focus:ring-blue-500 focus:border-blue-500 rounded-lg text-sm font-black text-blue-700 bg-white shadow-sm"
                                    >
                                        <option v-for="option in subUnitOptions" :key="option.id" :value="option.id">
                                            {{ option.name }}
                                        </option>
                                    </select>
                                </div>

                                <div class="p-4 bg-purple-50 rounded-lg border border-purple-100 flex items-start mb-8">
                                    <AdjustmentsHorizontalIcon class="w-5 h-5 text-purple-600 mt-0.5 mr-3 flex-shrink-0" />
                                    <p class="text-xs text-purple-700 leading-relaxed">
                                        Define how the system categorizes store health based on the number of open tickets. These thresholds will reflect across the <strong>Store Management</strong> and <strong>Health Reports</strong>.
                                    </p>
                                </div>

                                <div class="space-y-4">
                                    <!-- Green -->
                                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end bg-gray-50 p-4 rounded-xl border border-gray-200">
                                        <div class="col-span-1">
                                            <div class="flex items-center space-x-2 mb-2">
                                                <div class="w-3 h-3 rounded-full bg-green-500 shadow-sm"></div>
                                                <span class="text-[10px] font-black text-gray-700 uppercase tracking-wider">Healthy</span>
                                            </div>
                                            <InputLabel value="Min Tickets" class="!text-[9px] uppercase" />
                                            <TextInput type="number" class="mt-1 block w-full" v-model="form[thresholdKey('green', 'min')]" />
                                        </div>
                                        <div class="col-span-1">
                                            <InputLabel value="Max Tickets" class="!text-[9px] uppercase" />
                                            <TextInput type="number" class="mt-1 block w-full" v-model="form[thresholdKey('green', 'max')]" />
                                        </div>
                                        <div class="col-span-2">
                                            <InputLabel value="Custom Label" class="!text-[9px] uppercase" />
                                            <TextInput type="text" class="mt-1 block w-full" v-model="form[thresholdKey('green', 'label')]" />
                                        </div>
                                    </div>

                                    <!-- Yellow -->
                                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end bg-gray-50 p-4 rounded-xl border border-gray-200">
                                        <div class="col-span-1">
                                            <div class="flex items-center space-x-2 mb-2">
                                                <div class="w-3 h-3 rounded-full bg-yellow-500 shadow-sm"></div>
                                                <span class="text-[10px] font-black text-gray-700 uppercase tracking-wider">Warning</span>
                                            </div>
                                            <InputLabel value="Min Tickets" class="!text-[9px] uppercase" />
                                            <TextInput type="number" class="mt-1 block w-full" v-model="form[thresholdKey('yellow', 'min')]" />
                                        </div>
                                        <div class="col-span-1">
                                            <InputLabel value="Max Tickets" class="!text-[9px] uppercase" />
                                            <TextInput type="number" class="mt-1 block w-full" v-model="form[thresholdKey('yellow', 'max')]" />
                                        </div>
                                        <div class="col-span-2">
                                            <InputLabel value="Custom Label" class="!text-[9px] uppercase" />
                                            <TextInput type="text" class="mt-1 block w-full" v-model="form[thresholdKey('yellow', 'label')]" />
                                        </div>
                                    </div>

                                    <!-- Orange -->
                                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end bg-gray-50 p-4 rounded-xl border border-gray-200">
                                        <div class="col-span-1">
                                            <div class="flex items-center space-x-2 mb-2">
                                                <div class="w-3 h-3 rounded-full bg-orange-500 shadow-sm"></div>
                                                <span class="text-[10px] font-black text-gray-700 uppercase tracking-wider">At-risk</span>
                                            </div>
                                            <InputLabel value="Min Tickets" class="!text-[9px] uppercase" />
                                            <TextInput type="number" class="mt-1 block w-full" v-model="form[thresholdKey('orange', 'min')]" />
                                        </div>
                                        <div class="col-span-1">
                                            <InputLabel value="Max Tickets" class="!text-[9px] uppercase" />
                                            <TextInput type="number" class="mt-1 block w-full" v-model="form[thresholdKey('orange', 'max')]" />
                                        </div>
                                        <div class="col-span-2">
                                            <InputLabel value="Custom Label" class="!text-[9px] uppercase" />
                                            <TextInput type="text" class="mt-1 block w-full" v-model="form[thresholdKey('orange', 'label')]" />
                                        </div>
                                    </div>

                                    <!-- Red -->
                                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end bg-gray-50 p-4 rounded-xl border border-gray-200">
                                        <div class="col-span-1">
                                            <div class="flex items-center space-x-2 mb-2">
                                                <div class="w-3 h-3 rounded-full bg-red-500 shadow-sm"></div>
                                                <span class="text-[10px] font-black text-gray-700 uppercase tracking-wider">Critical</span>
                                            </div>
                                            <InputLabel value="Min (and up)" class="!text-[9px] uppercase" />
                                            <TextInput type="number" class="mt-1 block w-full" v-model="form[thresholdKey('red', 'min')]" />
                                        </div>
                                        <div class="col-span-3">
                                            <InputLabel value="Custom Label" class="!text-[9px] uppercase" />
                                            <TextInput type="text" class="mt-1 block w-full" v-model="form[thresholdKey('red', 'label')]" />
                                        </div>
                                    </div>

                                    <div class="border-t border-gray-100 my-6"></div>

                                    <!-- Waiting Aging Alarm -->
                                    <div class="bg-orange-50 p-4 rounded-xl border border-orange-100">
                                        <div class="flex items-center space-x-2 mb-4">
                                            <ClockIcon class="w-4 h-4 text-orange-600" />
                                            <span class="text-xs font-black text-orange-700 uppercase tracking-widest">Waiting Status Aging Alarm</span>
                                        </div>
                                        <div class="max-w-xs">
                                            <InputLabel value="Aging Days threshold" class="!text-[10px] uppercase text-orange-600" />
                                            <div class="flex items-center space-x-3 mt-1">
                                                <TextInput type="number" class="block w-full border-orange-200 focus:border-orange-500 focus:ring-orange-500" v-model="form.waiting_aging_alarm_days" />
                                                <span class="text-xs font-bold text-orange-400">Days</span>
                                            </div>
                                            <p class="mt-2 text-[9px] text-orange-400 italic">
                                                Tickets in "Waiting for service provider" or "Waiting for clients feedback?" statuses for longer than these days will trigger an alarm on the dashboard.
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Sidebar Layout Tab -->
                            <div v-if="activeTab === 'sidebar_layout'" class="p-6 space-y-5">
                                <div class="bg-indigo-50 p-4 rounded-xl border border-indigo-100 flex items-start space-x-3">
                                    <Bars3BottomLeftIcon class="w-5 h-5 text-indigo-600 mt-0.5 flex-shrink-0" />
                                    <div>
                                        <p class="text-sm font-black text-indigo-900">Sidebar Menu Layout</p>
                                        <p class="text-xs text-indigo-600 mt-0.5">Drag parent sections or sub-items to reorder and type directly to rename. Changes are saved to the database and applied globally.</p>
                                    </div>
                                </div>

                                <draggable
                                    v-model="sectionItems"
                                    item-key="id"
                                    handle=".section-drag-handle"
                                    @update="onSectionUpdate"
                                    class="space-y-2"
                                >
                                    <template #item="{ element: section }">
                                        <div class="border border-gray-200 rounded-lg overflow-hidden select-none">
                                            <div class="flex items-center bg-gray-50 px-4 py-3 gap-3">
                                                <span class="section-drag-handle cursor-grab active:cursor-grabbing text-gray-400 hover:text-gray-600 flex-shrink-0" title="Drag to reorder">
                                                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M3 15h18v-2H3v2zm0 4h18v-2H3v2zm0-8h18V9H3v2zm0-6v2h18V5H3z"/></svg>
                                                </span>
                                                <input 
                                                    type="text" 
                                                    v-model="section.label" 
                                                    class="flex-1 bg-transparent border-transparent focus:border-indigo-300 focus:ring-0 text-sm font-semibold text-gray-800 rounded px-2 py-1 transition-all"
                                                    placeholder="Section Name"
                                                >
                                                <button
                                                    v-if="(sidebarState.children[section.id] || []).length > 0"
                                                    type="button"
                                                    @click="toggleSidebarSection(section.id)"
                                                    class="flex items-center gap-1 text-xs text-gray-500 hover:text-gray-700 px-2 py-1 rounded hover:bg-gray-100 transition-colors"
                                                >
                                                    <span>Sub-items</span>
                                                    <ChevronDownIcon v-if="expandedSidebarSection === section.id" class="w-3.5 h-3.5" />
                                                    <ChevronRightIcon v-else class="w-3.5 h-3.5" />
                                                </button>
                                                <span v-else class="text-xs text-gray-400 italic px-2">No sub-items</span>
                                            </div>

                                            <div v-if="expandedSidebarSection === section.id" class="px-4 py-3 bg-white border-t border-gray-100">
                                                <draggable
                                                    v-model="expandedChildItems"
                                                    item-key="id"
                                                    handle=".child-drag-handle"
                                                    @update="onChildUpdate"
                                                    class="space-y-1"
                                                >
                                                    <template #item="{ element: child }">
                                                        <div class="flex items-center gap-3 bg-gray-50 rounded-lg px-3 py-2 select-none">
                                                            <span class="child-drag-handle cursor-grab active:cursor-grabbing text-gray-400 hover:text-gray-600 flex-shrink-0" title="Drag to reorder">
                                                                <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M3 15h18v-2H3v2zm0 4h18v-2H3v2zm0-8h18V9H3v2zm0-6v2h18V5H3z"/></svg>
                                                            </span>
                                                            <input 
                                                                type="text" 
                                                                v-model="child.label" 
                                                                class="flex-1 bg-transparent border-transparent focus:border-indigo-300 focus:ring-0 text-sm text-gray-700 rounded px-2 py-1 transition-all"
                                                                placeholder="Item Name"
                                                            >
                                                        </div>
                                                    </template>
                                                </draggable>
                                            </div>
                                        </div>
                                    </template>
                                </draggable>

                                <div class="flex items-center gap-3 pt-2">
                                    <button
                                        type="button"
                                        @click="saveSidebarLayout"
                                        class="px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold rounded-lg transition-colors shadow-sm"
                                    >
                                        Save Layout
                                    </button>
                                    <button
                                        type="button"
                                        @click="resetSidebarLayout"
                                        class="px-5 py-2.5 bg-gray-100 hover:bg-gray-200 text-gray-700 text-sm font-semibold rounded-lg transition-colors"
                                    >
                                        Reset to Default
                                    </button>
                                    <Transition enter-active-class="transition ease-in-out duration-300" enter-from-class="opacity-0" leave-active-class="transition ease-in-out duration-300" leave-to-class="opacity-0">
                                        <span v-if="sidebarSaved" class="text-sm font-bold text-green-600 flex items-center gap-1">
                                            <CheckCircleIcon class="w-4 h-4" /> Layout saved!
                                        </span>
                                    </Transition>
                                </div>
                            </div>

                            <!-- Auto Assignee Tab -->
                            <div v-if="activeTab === 'auto_assignee'" class="space-y-6">

                                <!-- Info banner -->
                                <div class="bg-blue-50 p-4 rounded-xl border border-blue-100 flex items-start gap-3">
                                    <UserGroupIcon class="w-5 h-5 text-blue-600 mt-0.5 flex-shrink-0" />
                                    <div>
                                        <p class="text-sm font-black text-blue-900">Automatic Ticket Assignment</p>
                                        <p class="text-xs text-blue-600 mt-0.5 leading-relaxed">
                                            Match incoming tickets to agents by requester email. If no rule matches, the global default agents receive the ticket via round-robin.
                                        </p>
                                    </div>
                                </div>

                                <!-- Email Rules Section -->
                                <section>
                                    <!-- Section toolbar -->
                                    <div class="flex items-center gap-3 mb-3">
                                        <h3 class="text-xs font-black text-blue-600 uppercase tracking-widest flex items-center flex-shrink-0">
                                            <UserGroupIcon class="w-4 h-4 mr-1.5" />
                                            Email Rules
                                            <span class="ml-2 px-2 py-0.5 bg-blue-100 text-blue-700 rounded-full text-[10px] font-bold tabular-nums">{{ autoRules.length }}</span>
                                        </h3>
                                        <div class="relative flex-1">
                                            <svg class="absolute left-2.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-gray-400 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M17 11A6 6 0 1 1 5 11a6 6 0 0 1 12 0z"/>
                                            </svg>
                                            <input
                                                type="text"
                                                v-model="rulesListSearch"
                                                placeholder="Filter rules by email…"
                                                class="w-full pl-8 pr-3 py-1.5 text-xs border border-gray-200 rounded-lg focus:ring-blue-500 focus:border-blue-500 bg-white"
                                            />
                                        </div>
                                        <button
                                            type="button"
                                            @click="addAutoRule"
                                            class="flex-shrink-0 flex items-center gap-1.5 px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold rounded-lg transition-colors"
                                        >
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                                            Add Rule
                                        </button>
                                    </div>

                                    <!-- Rules table -->
                                    <div class="border border-gray-200 rounded-xl overflow-hidden">
                                        <!-- Column headers -->
                                        <div class="grid grid-cols-[1fr_140px_72px] bg-gray-50 border-b border-gray-200 px-4 py-2">
                                            <span class="text-[10px] font-black text-gray-500 uppercase tracking-wider">Requester Email</span>
                                            <span class="text-[10px] font-black text-gray-500 uppercase tracking-wider">Assignees</span>
                                            <span class="text-[10px] font-black text-gray-500 uppercase tracking-wider text-right">Actions</span>
                                        </div>

                                        <!-- Empty state -->
                                        <div v-if="autoRules.length === 0" class="py-10 flex flex-col items-center gap-2 text-gray-400">
                                            <UserGroupIcon class="w-8 h-8 opacity-30" />
                                            <p class="text-sm italic">No rules yet. Click "+ Add Rule" to get started.</p>
                                        </div>

                                        <!-- No search results -->
                                        <div v-else-if="filteredRuleIndexes.length === 0" class="py-8 text-center text-sm text-gray-400 italic">
                                            No rules match "<span class="font-semibold">{{ rulesListSearch }}</span>".
                                        </div>

                                        <!-- Rule rows -->
                                        <div v-else>
                                            <div v-for="i in filteredRuleIndexes" :key="i" class="border-b border-gray-100 last:border-b-0">
                                                <!-- Collapsed row -->
                                                <div
                                                    :class="[
                                                        'grid grid-cols-[1fr_140px_72px] items-center px-4 py-3 gap-3 cursor-pointer transition-colors select-none',
                                                        editingRuleIndex === i ? 'bg-blue-50' : 'hover:bg-gray-50'
                                                    ]"
                                                    @click="toggleEditRule(i)"
                                                >
                                                    <!-- Email -->
                                                    <div class="min-w-0 flex items-center gap-2">
                                                        <div :class="['w-1.5 h-1.5 rounded-full flex-shrink-0', autoRules[i].assignee_ids.length > 0 ? 'bg-green-400' : 'bg-orange-400']"></div>
                                                        <span v-if="autoRules[i].email" class="text-sm font-medium text-gray-800 truncate">{{ autoRules[i].email }}</span>
                                                        <span v-else class="text-sm italic text-gray-400">No email set</span>
                                                    </div>

                                                    <!-- Assignee avatars -->
                                                    <div class="flex items-center gap-1">
                                                        <template v-if="autoRules[i].assignee_ids.length === 0">
                                                            <span class="text-[10px] font-semibold text-orange-500 flex items-center gap-1">
                                                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M21 12A9 9 0 1 1 3 12a9 9 0 0 1 18 0z"/></svg>
                                                                No agents
                                                            </span>
                                                        </template>
                                                        <template v-else>
                                                            <div
                                                                v-for="id in autoRules[i].assignee_ids.slice(0, 4)"
                                                                :key="id"
                                                                :title="getAgent(id)?.name ?? `User #${id}`"
                                                                class="w-6 h-6 rounded-full bg-blue-500 text-white flex items-center justify-center text-[9px] font-black -ml-1 first:ml-0 ring-2 ring-white"
                                                            >{{ getAgentInitials(id) }}</div>
                                                            <span v-if="autoRules[i].assignee_ids.length > 4" class="ml-1 text-[10px] font-bold text-gray-500">+{{ autoRules[i].assignee_ids.length - 4 }}</span>
                                                        </template>
                                                    </div>

                                                    <!-- Actions -->
                                                    <div class="flex items-center justify-end gap-1">
                                                        <span :class="['p-1.5 rounded-md transition-colors', editingRuleIndex === i ? 'bg-blue-600 text-white' : 'text-gray-400']">
                                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path v-if="editingRuleIndex === i" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"/>
                                                                <path v-else stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                                                            </svg>
                                                        </span>
                                                        <button
                                                            type="button"
                                                            @click.stop="removeAutoRule(i)"
                                                            class="p-1.5 rounded-md text-gray-400 hover:text-red-600 hover:bg-red-50 transition-colors"
                                                            title="Delete rule"
                                                        >
                                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                                            </svg>
                                                        </button>
                                                    </div>
                                                </div>

                                                <!-- Inline editor (expanded) -->
                                                <div v-if="editingRuleIndex === i" class="bg-blue-50/60 border-t border-blue-100 px-5 py-4 space-y-4">
                                                    <!-- Email input -->
                                                    <div class="max-w-sm">
                                                        <InputLabel :for="`rule_email_${i}`" value="Requester Email (exact match)" class="!text-[10px] uppercase text-gray-500" />
                                                        <TextInput
                                                            :id="`rule_email_${i}`"
                                                            type="email"
                                                            class="mt-1 block w-full"
                                                            v-model="autoRules[i].email"
                                                            placeholder="customer@company.com"
                                                        />
                                                    </div>

                                                    <!-- Assignee picker with drag-to-reorder -->
                                                    <div>
                                                        <InputLabel value="Round-Robin Assignees" class="!text-[10px] uppercase text-gray-500" />
                                                        <p class="text-[10px] text-gray-400 mt-0.5 mb-2">Add agents below, then drag <svg class="inline w-3 h-3 mb-0.5" fill="currentColor" viewBox="0 0 24 24"><path d="M3 15h18v-2H3v2zm0 4h18v-2H3v2zm0-8h18V9H3v2zm0-6v2h18V5H3z"/></svg> to set the rotation order.</p>

                                                        <!-- Search to add -->
                                                        <div class="relative max-w-lg">
                                                            <div class="flex items-center gap-2 px-3 py-2 border border-gray-300 rounded-lg bg-white focus-within:ring-2 focus-within:ring-blue-500 focus-within:border-blue-500 transition-all">
                                                                <svg class="w-3.5 h-3.5 text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M17 11A6 6 0 1 1 5 11a6 6 0 0 1 12 0z"/></svg>
                                                                <input
                                                                    type="text"
                                                                    v-model="ruleSearchQueries[i]"
                                                                    @focus="openDropdown(`rule-${i}`)"
                                                                    @blur="closeDropdown"
                                                                    placeholder="Search and add agents by name or email…"
                                                                    class="flex-1 border-0 outline-none text-xs text-gray-700 bg-transparent placeholder-gray-400"
                                                                />
                                                            </div>
                                                            <!-- Dropdown -->
                                                            <div v-if="activeDropdown === `rule-${i}`" class="absolute z-30 left-0 right-0 mt-1 bg-white border border-gray-200 rounded-xl shadow-xl overflow-hidden">
                                                                <div class="max-h-52 overflow-y-auto">
                                                                    <button
                                                                        v-for="agent in filteredStaffForIds(autoRules[i].assignee_ids, ruleSearchQueries[i])"
                                                                        :key="agent.id"
                                                                        type="button"
                                                                        @mousedown.prevent="selectRuleAssignee(autoRules[i], i, agent.id)"
                                                                        class="w-full flex items-center gap-3 px-3 py-2.5 text-left hover:bg-blue-50 transition-colors border-b border-gray-50 last:border-0"
                                                                    >
                                                                        <div class="w-7 h-7 rounded-full bg-gradient-to-br from-blue-400 to-blue-600 text-white flex items-center justify-center text-[10px] font-black flex-shrink-0">
                                                                            {{ agent.name.split(' ').filter(Boolean).map(n => n[0]).join('').toUpperCase().slice(0, 2) }}
                                                                        </div>
                                                                        <div class="flex-1 min-w-0">
                                                                            <p class="text-xs font-semibold text-gray-900 truncate">{{ agent.name }}</p>
                                                                            <p class="text-[10px] text-gray-400 truncate">{{ agent.email }}</p>
                                                                        </div>
                                                                        <svg class="w-3.5 h-3.5 text-blue-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                                                                    </button>
                                                                    <div v-if="filteredStaffForIds(autoRules[i].assignee_ids, ruleSearchQueries[i]).length === 0" class="px-4 py-5 text-center text-xs text-gray-400 italic">
                                                                        {{ ruleSearchQueries[i] ? `No agents match "${ruleSearchQueries[i]}"` : 'All assignable agents are already added.' }}
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>

                                                        <!-- Draggable rotation queue -->
                                                        <div v-if="autoRules[i].assignee_ids.length > 0" class="mt-2 border border-gray-200 rounded-lg overflow-hidden max-w-lg">
                                                            <div class="px-3 py-1.5 bg-gray-50 border-b border-gray-100 flex items-center justify-between">
                                                                <span class="text-[9px] font-black text-gray-400 uppercase tracking-wider">Rotation Order</span>
                                                                <span class="text-[9px] text-gray-400">{{ autoRules[i].assignee_ids.length }} agent{{ autoRules[i].assignee_ids.length !== 1 ? 's' : '' }}</span>
                                                            </div>
                                                            <draggable
                                                                :modelValue="getRuleAssigneeObjects(autoRules[i])"
                                                                @update:modelValue="items => setRuleAssigneeObjects(autoRules[i], items)"
                                                                item-key="id"
                                                                handle=".rule-assignee-drag-handle"
                                                                class="divide-y divide-gray-100"
                                                            >
                                                                <template #item="{ element, index: pos }">
                                                                    <div class="flex items-center gap-3 px-3 py-2.5 bg-white hover:bg-gray-50 transition-colors">
                                                                        <span class="rule-assignee-drag-handle cursor-grab active:cursor-grabbing text-gray-300 hover:text-gray-500 flex-shrink-0" title="Drag to reorder">
                                                                            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M3 15h18v-2H3v2zm0 4h18v-2H3v2zm0-8h18V9H3v2zm0-6v2h18V5H3z"/></svg>
                                                                        </span>
                                                                        <span class="w-5 text-[10px] font-black text-gray-300 text-center flex-shrink-0">{{ pos + 1 }}</span>
                                                                        <div class="w-7 h-7 rounded-full bg-blue-500 text-white flex items-center justify-center text-[10px] font-black flex-shrink-0">
                                                                            {{ getAgentInitials(element.id) }}
                                                                        </div>
                                                                        <div class="flex-1 min-w-0">
                                                                            <p class="text-xs font-semibold text-gray-800 truncate">{{ getAgent(element.id)?.name ?? `User #${element.id}` }}</p>
                                                                            <p class="text-[10px] text-gray-400 truncate">{{ getAgent(element.id)?.email }}</p>
                                                                        </div>
                                                                        <button type="button" @click="removeRuleAssignee(autoRules[i], element.id)" class="p-1 rounded text-gray-300 hover:text-red-500 hover:bg-red-50 transition-colors flex-shrink-0" title="Remove">
                                                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                                                        </button>
                                                                    </div>
                                                                </template>
                                                            </draggable>
                                                        </div>

                                                        <p v-if="autoRules[i].assignee_ids.length === 0" class="mt-2 text-[10px] text-orange-500 italic flex items-center gap-1">
                                                            <svg class="w-3 h-3 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M21 12A9 9 0 1 1 3 12a9 9 0 0 1 18 0z"/></svg>
                                                            No agents added — this rule will be skipped during assignment.
                                                        </p>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Row count -->
                                    <p v-if="autoRules.length > 0" class="mt-1.5 text-[10px] text-gray-400 text-right tabular-nums">
                                        {{ filteredRuleIndexes.length }} of {{ autoRules.length }} {{ autoRules.length === 1 ? 'rule' : 'rules' }}
                                        <span v-if="rulesListSearch"> matching "{{ rulesListSearch }}"</span>
                                    </p>
                                </section>

                                <div class="border-t border-gray-100"></div>

                                <!-- Global Default Assignees Section -->
                                <section>
                                    <h3 class="text-xs font-black text-purple-600 uppercase tracking-widest mb-1 flex items-center">
                                        <UserGroupIcon class="w-4 h-4 mr-1.5" />
                                        Global Default Assignees
                                    </h3>
                                    <p class="text-xs text-gray-500 mb-3 leading-relaxed">
                                        Tickets with no matching rule are round-robin distributed among these agents. Leave empty to keep unmatched tickets unassigned.
                                    </p>

                                    <!-- Search to add default agents -->
                                    <div class="relative max-w-lg">
                                        <div class="flex items-center gap-2 px-3 py-2 border border-gray-300 rounded-lg bg-white focus-within:ring-2 focus-within:ring-purple-500 focus-within:border-purple-500 transition-all">
                                            <svg class="w-3.5 h-3.5 text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M17 11A6 6 0 1 1 5 11a6 6 0 0 1 12 0z"/></svg>
                                            <input
                                                type="text"
                                                v-model="defaultSearchQuery"
                                                @focus="openDropdown('default')"
                                                @blur="closeDropdown"
                                                placeholder="Search and add agents by name or email…"
                                                class="flex-1 border-0 outline-none text-xs text-gray-700 bg-transparent placeholder-gray-400"
                                            />
                                        </div>
                                        <!-- Dropdown -->
                                        <div v-if="activeDropdown === 'default'" class="absolute z-30 left-0 right-0 mt-1 bg-white border border-gray-200 rounded-xl shadow-xl overflow-hidden">
                                            <div class="max-h-52 overflow-y-auto">
                                                <button
                                                    v-for="agent in filteredDefaultStaff"
                                                    :key="agent.id"
                                                    type="button"
                                                    @mousedown.prevent="selectDefaultAssignee(agent.id)"
                                                    class="w-full flex items-center gap-3 px-3 py-2.5 text-left hover:bg-purple-50 transition-colors border-b border-gray-50 last:border-0"
                                                >
                                                    <div class="w-7 h-7 rounded-full bg-gradient-to-br from-purple-400 to-purple-600 text-white flex items-center justify-center text-[10px] font-black flex-shrink-0">
                                                        {{ agent.name.split(' ').filter(Boolean).map(n => n[0]).join('').toUpperCase().slice(0, 2) }}
                                                    </div>
                                                    <div class="flex-1 min-w-0">
                                                        <p class="text-xs font-semibold text-gray-900 truncate">{{ agent.name }}</p>
                                                        <p class="text-[10px] text-gray-400 truncate">{{ agent.email }}</p>
                                                    </div>
                                                    <svg class="w-3.5 h-3.5 text-purple-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                                                </button>
                                                <div v-if="filteredDefaultStaff.length === 0" class="px-4 py-5 text-center text-xs text-gray-400 italic">
                                                    {{ defaultSearchQuery ? `No agents match "${defaultSearchQuery}"` : 'All assignable agents are already added.' }}
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Draggable default rotation queue -->
                                    <div v-if="autoDefaults.length > 0" class="mt-2 border border-gray-200 rounded-lg overflow-hidden max-w-lg">
                                        <div class="px-3 py-1.5 bg-gray-50 border-b border-gray-100 flex items-center justify-between">
                                            <span class="text-[9px] font-black text-gray-400 uppercase tracking-wider">Rotation Order</span>
                                            <span class="text-[9px] text-gray-400">{{ autoDefaults.length }} agent{{ autoDefaults.length !== 1 ? 's' : '' }}</span>
                                        </div>
                                        <draggable
                                            :modelValue="getDefaultAssigneeObjects"
                                            @update:modelValue="setDefaultAssigneeObjects"
                                            item-key="id"
                                            handle=".default-assignee-drag-handle"
                                            class="divide-y divide-gray-100"
                                        >
                                            <template #item="{ element, index: pos }">
                                                <div class="flex items-center gap-3 px-3 py-2.5 bg-white hover:bg-gray-50 transition-colors">
                                                    <span class="default-assignee-drag-handle cursor-grab active:cursor-grabbing text-gray-300 hover:text-gray-500 flex-shrink-0" title="Drag to reorder">
                                                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M3 15h18v-2H3v2zm0 4h18v-2H3v2zm0-8h18V9H3v2zm0-6v2h18V5H3z"/></svg>
                                                    </span>
                                                    <span class="w-5 text-[10px] font-black text-gray-300 text-center flex-shrink-0">{{ pos + 1 }}</span>
                                                    <div class="w-7 h-7 rounded-full bg-purple-500 text-white flex items-center justify-center text-[10px] font-black flex-shrink-0">
                                                        {{ getAgentInitials(element.id) }}
                                                    </div>
                                                    <div class="flex-1 min-w-0">
                                                        <p class="text-xs font-semibold text-gray-800 truncate">{{ getAgent(element.id)?.name ?? `User #${element.id}` }}</p>
                                                        <p class="text-[10px] text-gray-400 truncate">{{ getAgent(element.id)?.email }}</p>
                                                    </div>
                                                    <button type="button" @click="removeDefaultAssignee(element.id)" class="p-1 rounded text-gray-300 hover:text-red-500 hover:bg-red-50 transition-colors flex-shrink-0" title="Remove">
                                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                                    </button>
                                                </div>
                                            </template>
                                        </draggable>
                                    </div>

                                    <p v-if="!assignableStaff || assignableStaff.length === 0" class="mt-2 text-[10px] text-gray-400 italic">
                                        No assignable staff found. Enable "Is Assignable" on a role first.
                                    </p>
                                    <p v-else-if="autoDefaults.length === 0" class="mt-2 text-[10px] text-gray-400 italic">
                                        No default agents added — unmatched tickets will remain unassigned.
                                    </p>
                                </section>

                                <!-- Save button -->
                                <div class="flex items-center gap-3">
                                    <button
                                        type="button"
                                        @click="saveAutoAssignee"
                                        :disabled="autoAssigneeProcessing"
                                        class="px-5 py-2.5 bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-white text-sm font-semibold rounded-lg transition-colors shadow-sm"
                                    >
                                        <span v-if="autoAssigneeProcessing">Saving…</span>
                                        <span v-else>Save Auto Assignee Rules</span>
                                    </button>
                                    <Transition enter-active-class="transition ease-in-out duration-300" enter-from-class="opacity-0" leave-active-class="transition ease-in-out duration-300" leave-to-class="opacity-0">
                                        <span v-if="autoAssigneeSaved" class="text-sm font-bold text-green-600 flex items-center gap-1">
                                            <CheckCircleIcon class="w-4 h-4" /> Rules saved!
                                        </span>
                                    </Transition>
                                </div>
                            </div>

                        </div>

                        <!-- Sticky Footer -->
                        <div v-if="activeTab !== 'sidebar_layout' && activeTab !== 'auto_assignee'" class="px-6 py-4 bg-gray-50 border-t border-gray-200 flex items-center justify-between">
                            <div>
                                <Transition enter-active-class="transition ease-in-out duration-300" enter-from-class="opacity-0" leave-active-class="transition ease-in-out duration-300" leave-to-class="opacity-0">
                                    <div v-if="form.recentlySuccessful" class="text-sm font-bold text-green-600 flex items-center">
                                        <CheckCircleIcon class="w-4 h-4 mr-1" />
                                        Changes saved!
                                    </div>
                                </Transition>
                                <div v-if="!form.recentlySuccessful" class="text-[10px] text-gray-400 italic">
                                    * All changes are applied instantly after saving.
                                </div>
                            </div>
                            <PrimaryButton 
                                :class="{ 'opacity-25': form.processing }" 
                                :disabled="form.processing"
                                class="!px-8 !py-3 shadow-lg shadow-blue-100 font-black uppercase tracking-widest text-xs !bg-blue-600 hover:!bg-blue-700 text-white"
                            >
                                <span v-if="form.processing">Saving...</span>
                                <span v-else>Save Configuration</span>
                            </PrimaryButton>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
/* Focus transition for better UX */
input:focus, select:focus {
    transform: translateY(-1px);
    transition: all 0.2s ease;
}
</style>
