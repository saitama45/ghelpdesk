<script setup>
import { ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import { Cog6ToothIcon, EnvelopeIcon, ShieldCheckIcon, MapIcon, EyeIcon, EyeSlashIcon, ChartBarIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    settings: Object
});

const showImapPassword = ref(false);
const showMapsKey = ref(false);

const form = useForm({
    imap_host: props.settings.imap_host || '',
    imap_port: props.settings.imap_port || '993',
    imap_encryption: props.settings.imap_encryption || 'ssl',
    imap_username: props.settings.imap_username || '',
    imap_password: props.settings.imap_password || '',
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
            System Settings
        </template>

        <div class="space-y-6">
            <!-- Email Settings -->
            <div class="bg-white p-6 rounded-lg shadow-sm border border-gray-200">
                <div class="flex items-center space-x-3 mb-6 border-b pb-4">
                    <div class="p-2 bg-blue-100 rounded-lg">
                        <EnvelopeIcon class="w-6 h-6 text-blue-600" />
                    </div>
                    <div>
                        <h3 class="text-lg font-bold text-gray-900">Email Ticketing (IMAP)</h3>
                        <p class="text-sm text-gray-500">Configure how the system fetches tickets from your support email inbox.</p>
                    </div>
                </div>

                <form @submit.prevent="submit" class="space-y-6 max-w-2xl">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <InputLabel for="imap_host" value="IMAP Host" />
                            <TextInput
                                id="imap_host"
                                type="text"
                                class="mt-1 block w-full"
                                v-model="form.imap_host"
                                placeholder="imap.gmail.com"
                            />
                            <InputError class="mt-2" :message="form.errors.imap_host" />
                        </div>

                        <div>
                            <InputLabel for="imap_port" value="IMAP Port" />
                            <TextInput
                                id="imap_port"
                                type="text"
                                class="mt-1 block w-full"
                                v-model="form.imap_port"
                                placeholder="993"
                            />
                            <InputError class="mt-2" :message="form.errors.imap_port" />
                        </div>

                        <div>
                            <InputLabel for="imap_encryption" value="Encryption" />
                            <select
                                id="imap_encryption"
                                v-model="form.imap_encryption"
                                class="mt-1 block w-full border-gray-300 focus:border-blue-500 focus:ring-blue-500 rounded-md shadow-sm"
                            >
                                <option value="ssl">SSL</option>
                                <option value="tls">TLS</option>
                                <option value="notls">None</option>
                            </select>
                            <InputError class="mt-2" :message="form.errors.imap_encryption" />
                        </div>

                        <div>
                            <InputLabel for="imap_username" value="Support Email" />
                            <TextInput
                                id="imap_username"
                                type="email"
                                class="mt-1 block w-full"
                                v-model="form.imap_username"
                                placeholder="support@example.com"
                            />
                            <InputError class="mt-2" :message="form.errors.imap_username" />
                        </div>

                        <div class="md:col-span-2">
                            <InputLabel for="imap_password" value="Password / App Password" />
                            <div class="relative mt-1">
                                <TextInput
                                    id="imap_password"
                                    :type="showImapPassword ? 'text' : 'password'"
                                    class="block w-full pr-10"
                                    v-model="form.imap_password"
                                    placeholder="••••••••••••"
                                />
                                <button 
                                    type="button"
                                    @click="showImapPassword = !showImapPassword"
                                    class="absolute inset-y-0 right-0 pr-3 flex items-center text-gray-400 hover:text-gray-600"
                                >
                                    <EyeIcon v-if="!showImapPassword" class="h-5 w-5" />
                                    <EyeSlashIcon v-else class="h-5 w-5" />
                                </button>
                            </div>
                            <p class="mt-2 text-xs text-gray-500 flex items-center">
                                <ShieldCheckIcon class="w-3 h-3 mr-1 text-green-600" />
                                For Gmail, use a 16-character App Password.
                            </p>
                            <InputError class="mt-2" :message="form.errors.imap_password" />
                        </div>
                    </div>

                    <!-- Maps Settings -->
                    <div class="pt-8 mt-8 border-t border-gray-100">
                        <div class="flex items-center space-x-3 mb-6">
                            <div class="p-2 bg-red-100 rounded-lg">
                                <MapIcon class="w-6 h-6 text-red-600" />
                            </div>
                            <div>
                                <h3 class="text-lg font-bold text-gray-900">Google Maps API</h3>
                                <p class="text-sm text-gray-500">Configure the API key used for DTR location tracking and maps.</p>
                            </div>
                        </div>

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
                                <button 
                                    type="button"
                                    @click="showMapsKey = !showMapsKey"
                                    class="absolute inset-y-0 right-0 pr-3 flex items-center text-gray-400 hover:text-gray-600"
                                >
                                    <EyeIcon v-if="!showMapsKey" class="h-5 w-5" />
                                    <EyeSlashIcon v-else class="h-5 w-5" />
                                </button>
                            </div>
                            <InputError class="mt-2" :message="form.errors.google_maps_api_key" />
                        </div>
                    </div>

                    <!-- Ticket Threshold Settings -->
                    <div class="pt-8 mt-8 border-t border-gray-100">
                        <div class="flex items-center space-x-3 mb-6">
                            <div class="p-2 bg-purple-100 rounded-lg">
                                <ChartBarIcon class="w-6 h-6 text-purple-600" />
                            </div>
                            <div>
                                <h3 class="text-lg font-bold text-gray-900">Ticket Count Thresholds (per store)</h3>
                                <p class="text-sm text-gray-500">Configure status colors and labels based on the number of open tickets per store.</p>
                            </div>
                        </div>

                        <div class="space-y-4">
                            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end bg-gray-50 p-4 rounded-lg border border-gray-100">
                                <div class="col-span-1">
                                    <div class="flex items-center space-x-2 mb-2">
                                        <div class="w-3 h-3 rounded-full bg-green-500"></div>
                                        <span class="text-sm font-medium text-gray-700">Healthy</span>
                                    </div>
                                    <InputLabel value="Min Tickets" />
                                    <TextInput type="number" class="mt-1 block w-full" v-model="form.threshold_green_min" />
                                </div>
                                <div class="col-span-1">
                                    <InputLabel value="Max Tickets" />
                                    <TextInput type="number" class="mt-1 block w-full" v-model="form.threshold_green_max" />
                                </div>
                                <div class="col-span-2">
                                    <InputLabel value="Label" />
                                    <TextInput type="text" class="mt-1 block w-full" v-model="form.threshold_green_label" />
                                </div>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end bg-gray-50 p-4 rounded-lg border border-gray-100">
                                <div class="col-span-1">
                                    <div class="flex items-center space-x-2 mb-2">
                                        <div class="w-3 h-3 rounded-full bg-yellow-500"></div>
                                        <span class="text-sm font-medium text-gray-700">Warning</span>
                                    </div>
                                    <InputLabel value="Min Tickets" />
                                    <TextInput type="number" class="mt-1 block w-full" v-model="form.threshold_yellow_min" />
                                </div>
                                <div class="col-span-1">
                                    <InputLabel value="Max Tickets" />
                                    <TextInput type="number" class="mt-1 block w-full" v-model="form.threshold_yellow_max" />
                                </div>
                                <div class="col-span-2">
                                    <InputLabel value="Label" />
                                    <TextInput type="text" class="mt-1 block w-full" v-model="form.threshold_yellow_label" />
                                </div>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end bg-gray-50 p-4 rounded-lg border border-gray-100">
                                <div class="col-span-1">
                                    <div class="flex items-center space-x-2 mb-2">
                                        <div class="w-3 h-3 rounded-full bg-orange-500"></div>
                                        <span class="text-sm font-medium text-gray-700">At-risk</span>
                                    </div>
                                    <InputLabel value="Min Tickets" />
                                    <TextInput type="number" class="mt-1 block w-full" v-model="form.threshold_orange_min" />
                                </div>
                                <div class="col-span-1">
                                    <InputLabel value="Max Tickets" />
                                    <TextInput type="number" class="mt-1 block w-full" v-model="form.threshold_orange_max" />
                                </div>
                                <div class="col-span-2">
                                    <InputLabel value="Label" />
                                    <TextInput type="text" class="mt-1 block w-full" v-model="form.threshold_orange_label" />
                                </div>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end bg-gray-50 p-4 rounded-lg border border-gray-100">
                                <div class="col-span-1">
                                    <div class="flex items-center space-x-2 mb-2">
                                        <div class="w-3 h-3 rounded-full bg-red-500"></div>
                                        <span class="text-sm font-medium text-gray-700">Critical</span>
                                    </div>
                                    <InputLabel value="Min Tickets (and up)" />
                                    <TextInput type="number" class="mt-1 block w-full" v-model="form.threshold_red_min" />
                                </div>
                                <div class="col-span-3">
                                    <InputLabel value="Label" />
                                    <TextInput type="text" class="mt-1 block w-full" v-model="form.threshold_red_label" />
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center justify-end pt-4 border-t">
                        <PrimaryButton :class="{ 'opacity-25': form.processing }" :disabled="form.processing">
                            Save Configuration
                        </PrimaryButton>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
