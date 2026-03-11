<script setup>
import { ref, computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
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
    ClockIcon
} from '@heroicons/vue/24/outline';

const props = defineProps({
    settings: Object,
    subUnits: Array
});

const activeTab = ref('mail');
const selectedSubUnit = ref('global');

const tabs = [
    { id: 'mail', name: 'Mail Configuration', icon: EnvelopeIcon, description: 'Manage inbound and outbound email settings.' },
    { id: 'business_hours', name: 'Business Hours', icon: ClockIcon, description: 'Define operational hours and working days for SLA calculations.' },
    { id: 'sla_targets', name: 'SLA Targets', icon: ShieldCheckIcon, description: 'Configure response and resolution targets per ticket priority.' },
    { id: 'integrations', name: 'Integrations', icon: MapIcon, description: 'External API keys and third-party services.' },
    { id: 'thresholds', name: 'Health Thresholds', icon: ChartBarIcon, description: 'Ticket count limits and status labels.' },
];

const currentTab = computed(() => tabs.find(t => t.id === activeTab.value));

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
    };

    // Add sub-unit specific settings
    props.subUnits.forEach(unit => {
        const slug = slugify(unit);
        data[`business_start_time_${slug}`] = props.settings[`business_start_time_${slug}`] || props.settings.business_start_time || '08:00';
        data[`business_end_time_${slug}`] = props.settings[`business_end_time_${slug}`] || props.settings.business_end_time || '17:00';
        data[`working_days_${slug}`] = parseWorkingDays(props.settings[`working_days_${slug}`] || props.settings.working_days);
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

const submit = () => {
    form.put(route('settings.update'), {
        preserveScroll: true,
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
                                    <h3 class="text-xs font-black text-blue-600 uppercase tracking-widest mb-6 flex items-center">
                                        <ServerIcon class="w-4 h-4 mr-2" />
                                        Inbound Mail (IMAP)
                                    </h3>
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
                                            <p class="mt-2 text-[10px] text-gray-500 italic">For Gmail accounts, please generate and use a 16-character App Password.</p>
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
                                            <TextInput type="number" class="mt-1 block w-full" v-model="form.threshold_green_min" />
                                        </div>
                                        <div class="col-span-1">
                                            <InputLabel value="Max Tickets" class="!text-[9px] uppercase" />
                                            <TextInput type="number" class="mt-1 block w-full" v-model="form.threshold_green_max" />
                                        </div>
                                        <div class="col-span-2">
                                            <InputLabel value="Custom Label" class="!text-[9px] uppercase" />
                                            <TextInput type="text" class="mt-1 block w-full" v-model="form.threshold_green_label" />
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
                                            <TextInput type="number" class="mt-1 block w-full" v-model="form.threshold_yellow_min" />
                                        </div>
                                        <div class="col-span-1">
                                            <InputLabel value="Max Tickets" class="!text-[9px] uppercase" />
                                            <TextInput type="number" class="mt-1 block w-full" v-model="form.threshold_yellow_max" />
                                        </div>
                                        <div class="col-span-2">
                                            <InputLabel value="Custom Label" class="!text-[9px] uppercase" />
                                            <TextInput type="text" class="mt-1 block w-full" v-model="form.threshold_yellow_label" />
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
                                            <TextInput type="number" class="mt-1 block w-full" v-model="form.threshold_orange_min" />
                                        </div>
                                        <div class="col-span-1">
                                            <InputLabel value="Max Tickets" class="!text-[9px] uppercase" />
                                            <TextInput type="number" class="mt-1 block w-full" v-model="form.threshold_orange_max" />
                                        </div>
                                        <div class="col-span-2">
                                            <InputLabel value="Custom Label" class="!text-[9px] uppercase" />
                                            <TextInput type="text" class="mt-1 block w-full" v-model="form.threshold_orange_label" />
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
                                            <TextInput type="number" class="mt-1 block w-full" v-model="form.threshold_red_min" />
                                        </div>
                                        <div class="col-span-3">
                                            <InputLabel value="Custom Label" class="!text-[9px] uppercase" />
                                            <TextInput type="text" class="mt-1 block w-full" v-model="form.threshold_red_label" />
                                        </div>
                                    </div>
                                </div>
                            </div>

                        </div>

                        <!-- Sticky Footer -->
                        <div class="px-6 py-4 bg-gray-50 border-t border-gray-200 flex items-center justify-between">
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
