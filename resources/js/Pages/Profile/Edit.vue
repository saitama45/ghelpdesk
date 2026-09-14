<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Autocomplete from '@/Components/Autocomplete.vue';
import { useToast } from '@/Composables/useToast';
import { APP_TIMEZONE, deviceTimezone, formatIn, timezoneLabel, timezoneOptions } from '@/lib/timezone';

const props = defineProps({
    user: Object,
});

const TABS = ['profile', 'password', 'timezone'];
const initialTab = new URLSearchParams(window.location.search).get('tab');
const activeTab = ref(TABS.includes(initialTab) ? initialTab : 'profile');
const { showError } = useToast();

// Keep the tab in the URL so a save (or the schedules banner's "Change" link)
// lands on the same tab.
watch(activeTab, (tab) => {
    const url = new URL(window.location.href);
    url.searchParams.set('tab', tab);
    window.history.replaceState(window.history.state, '', url);
});

/* ------------------------------------------------------------ My timezone */
const zoneOptions = timezoneOptions();
const device = deviceTimezone();
const timezoneForm = useForm({
    timezone: props.user.timezone || APP_TIMEZONE,
});

watch(() => props.user.timezone, (value) => {
    timezoneForm.timezone = value || APP_TIMEZONE;
});

const now = new Date();
const previewTime = (zone) => formatIn(now, zone, { weekday: 'short', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit', hour12: true });
const selectedPreview = computed(() => timezoneForm.timezone ? previewTime(timezoneForm.timezone) : '');

const updateTimezone = () => {
    timezoneForm.patch(route('profile.timezone'), {
        preserveScroll: true,
        preserveState: true,
        onError: (errors) => {
            showError(Object.values(errors).flat().join(', ') || 'Failed to update timezone');
        },
    });
};

const profileForm = useForm({
    name: props.user.name,
    photo: null,
});

const photoInput = ref(null);
const photoPreview = ref(null);

const selectNewPhoto = () => {
    photoInput.value.click();
};

const updatePhotoPreview = () => {
    const photo = photoInput.value.files[0];

    if (! photo) return;

    const reader = new FileReader();

    reader.onload = (e) => {
        photoPreview.value = e.target.result;
    };

    reader.readAsDataURL(photo);
    profileForm.photo = photo;
};

const passwordForm = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

const updateProfile = () => {
    if (profileForm.photo) {
        // Method spoofing for file upload via PUT route
        profileForm.transform((data) => ({
            ...data,
            _method: 'PATCH',
        })).post(route('profile.update'), {
        onSuccess: () => {
                photoPreview.value = null;
                const fileInput = document.getElementById('photo');
                if (fileInput) fileInput.value = null;
            },
            onError: (errors) => {
                const errorMessage = Object.values(errors).flat().join(', ') || 'Failed to update profile';
                showError(errorMessage);
            }
        });
        return;
    }

    profileForm.patch(route('profile.update'), {
        onSuccess: () => {
        },
        onError: (errors) => {
            const errorMessage = Object.values(errors).flat().join(', ') || 'Failed to update profile';
            showError(errorMessage);
        }
    });
};

const updatePassword = () => {
    passwordForm.put(route('profile.password'), {
        onSuccess: () => {
            passwordForm.reset();
        },
        onError: (errors) => {
            const errorMessage = Object.values(errors).flat().join(', ') || 'Failed to update password';
            showError(errorMessage);
        }
    });
};
</script>

<template>
    <Head title="Profile - Help Desk" />

    <AppLayout>
        <template #header>
            Profile
        </template>

        <div class="max-w-4xl mx-auto space-y-6">
            <!-- Profile Header -->
            <div class="bg-white rounded-lg shadow-sm p-6 dark:bg-gray-800">
                <div class="flex items-center space-x-4">
                    <div class="relative group cursor-pointer" @click="selectNewPhoto">
                        <div v-if="photoPreview" class="h-20 w-20 rounded-full overflow-hidden border-2 border-gray-200 dark:border-gray-700">
                            <img :src="photoPreview" class="h-full w-full object-cover">
                        </div>
                        <div v-else-if="user.profile_photo" class="h-20 w-20 rounded-full overflow-hidden border-2 border-gray-200 dark:border-gray-700">
                            <img :src="'/serve-storage/' + user.profile_photo" class="h-full w-full object-cover">
                        </div>
                        <div v-else class="h-20 w-20 bg-blue-600 rounded-full flex items-center justify-center border-2 border-white shadow-sm">
                            <span class="text-2xl font-bold text-white">{{ user.name.charAt(0) }}</span>
                        </div>
                        
                        <!-- Overlay -->
                        <div class="absolute inset-0 bg-black bg-opacity-40 rounded-full flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity">
                            <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                        </div>
                        <input ref="photoInput" type="file" class="hidden" @change="updatePhotoPreview" accept="image/*">
                    </div>
                    <div>
                        <h2 class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ user.name }}</h2>
                        <p class="text-gray-600 dark:text-gray-300">{{ user.email }}</p>
                        <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-blue-100 text-blue-800 mt-1">
                            {{ user.roles[0]?.name || 'User' }}
                        </span>
                    </div>
                </div>
            </div>

            <!-- Tabs -->
            <div class="bg-white rounded-lg shadow-sm dark:bg-gray-800">
                <div class="border-b border-gray-200 dark:border-gray-700">
                    <nav class="flex space-x-8 px-6">
                        <button
                            @click="activeTab = 'profile'"
                            :class="[
                                activeTab === 'profile' 
                                    ? 'border-blue-500 text-blue-600' 
                                    : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300',
                                'whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm'
                            ]"
                        >
                            Profile Information
                        </button>
                        <button
                            @click="activeTab = 'password'"
                            :class="[
                                activeTab === 'password' 
                                    ? 'border-blue-500 text-blue-600' 
                                    : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300',
                                'whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm'
                            ]"
                        >
                            Change Password
                        </button>
                        <button
                            @click="activeTab = 'timezone'"
                            :class="[
                                activeTab === 'timezone'
                                    ? 'border-blue-500 text-blue-600'
                                    : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300',
                                'whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm'
                            ]"
                        >
                            Timezone
                        </button>
                    </nav>
                </div>

                <!-- Profile Information Tab -->
                <div v-show="activeTab === 'profile'" class="p-6">
                    <form @submit.prevent="updateProfile" class="space-y-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2 dark:text-gray-300">Full Name</label>
                                <input 
                                    v-model="profileForm.name" 
                                    type="text" 
                                    required 
                                    class="w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 dark:border-gray-600"
                                >
                                <div v-if="profileForm.errors.name" class="text-red-600 text-sm mt-1">{{ profileForm.errors.name }}</div>
                            </div>

                            <div>
                                <span class="block text-sm font-medium text-gray-700 mb-2 dark:text-gray-300">Employee ID No</span>
                                <p class="min-h-10 rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-gray-900 dark:border-gray-700 dark:bg-gray-900/40 dark:text-gray-100">
                                    {{ user.employee_id_no || '-' }}
                                </p>
                            </div>

                            <div>
                                <span class="block text-sm font-medium text-gray-700 mb-2 dark:text-gray-300">Email</span>
                                <p class="min-h-10 rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-gray-900 dark:border-gray-700 dark:bg-gray-900/40 dark:text-gray-100">
                                    {{ user.email }}
                                </p>
                            </div>

                            <div>
                                <span class="block text-sm font-medium text-gray-700 mb-2 dark:text-gray-300">Department</span>
                                <p class="min-h-10 rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-gray-900 dark:border-gray-700 dark:bg-gray-900/40 dark:text-gray-100">
                                    {{ user.department || '-' }}
                                </p>
                            </div>

                            <div>
                                <span class="block text-sm font-medium text-gray-700 mb-2 dark:text-gray-300">Position</span>
                                <p class="min-h-10 rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-gray-900 dark:border-gray-700 dark:bg-gray-900/40 dark:text-gray-100">
                                    {{ user.position || '-' }}
                                </p>
                            </div>
                        </div>

                        <div class="flex justify-end">
                            <button 
                                type="submit" 
                                :disabled="profileForm.processing"
                                class="bg-blue-600 text-white px-6 py-2 rounded-md hover:bg-blue-700 disabled:opacity-50 flex items-center space-x-2"
                            >
                                <svg v-if="profileForm.processing" class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                <span>{{ profileForm.processing ? 'Updating...' : 'Update Profile' }}</span>
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Change Password Tab -->
                <div v-show="activeTab === 'password'" class="p-6">
                    <form @submit.prevent="updatePassword" class="space-y-6 max-w-md">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2 dark:text-gray-300">Current Password</label>
                            <input 
                                v-model="passwordForm.current_password" 
                                type="password" 
                                required 
                                class="w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 dark:border-gray-600"
                            >
                            <div v-if="passwordForm.errors.current_password" class="text-red-600 text-sm mt-1">{{ passwordForm.errors.current_password }}</div>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2 dark:text-gray-300">New Password</label>
                            <input 
                                v-model="passwordForm.password" 
                                type="password" 
                                required 
                                class="w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 dark:border-gray-600"
                            >
                            <div v-if="passwordForm.errors.password" class="text-red-600 text-sm mt-1">{{ passwordForm.errors.password }}</div>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2 dark:text-gray-300">Confirm New Password</label>
                            <input 
                                v-model="passwordForm.password_confirmation" 
                                type="password" 
                                required 
                                class="w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 dark:border-gray-600"
                            >
                        </div>

                        <div class="flex justify-end">
                            <button 
                                type="submit" 
                                :disabled="passwordForm.processing"
                                class="bg-yellow-600 text-white px-6 py-2 rounded-md hover:bg-yellow-700 disabled:opacity-50 flex items-center space-x-2"
                            >
                                <svg v-if="passwordForm.processing" class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                <span>{{ passwordForm.processing ? 'Updating...' : 'Change Password' }}</span>
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Timezone Tab -->
                <div v-show="activeTab === 'timezone'" class="p-6">
                    <form @submit.prevent="updateTimezone" class="space-y-5 max-w-xl">
                        <div>
                            <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">My timezone</h3>
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                                Scheduling and DTR show and accept times in this timezone, which helps while you work abroad.
                                Company reports and payroll always stay on Manila time.
                            </p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2 dark:text-gray-300">Timezone</label>
                            <Autocomplete
                                v-model="timezoneForm.timezone"
                                :options="zoneOptions"
                                placeholder="Search a city or region..."
                            />
                            <p v-if="selectedPreview" class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                                It is now <strong class="text-gray-800 dark:text-gray-200">{{ selectedPreview }}</strong> there.
                            </p>
                            <div v-if="timezoneForm.errors.timezone" class="text-red-600 text-sm mt-1">{{ timezoneForm.errors.timezone }}</div>
                        </div>

                        <div class="flex flex-wrap gap-2">
                            <button
                                v-if="device !== timezoneForm.timezone"
                                type="button"
                                class="rounded-md border border-gray-300 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700"
                                @click="timezoneForm.timezone = device"
                            >
                                Use this device's timezone ({{ timezoneLabel(device) }})
                            </button>
                            <button
                                v-if="timezoneForm.timezone !== APP_TIMEZONE"
                                type="button"
                                class="rounded-md border border-gray-300 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700"
                                @click="timezoneForm.timezone = APP_TIMEZONE"
                            >
                                Reset to Manila
                            </button>
                        </div>

                        <div class="flex justify-end">
                            <button
                                type="submit"
                                :disabled="timezoneForm.processing || !timezoneForm.timezone"
                                class="bg-blue-600 text-white px-6 py-2 rounded-md hover:bg-blue-700 disabled:opacity-50"
                            >
                                {{ timezoneForm.processing ? 'Saving...' : 'Save Timezone' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
