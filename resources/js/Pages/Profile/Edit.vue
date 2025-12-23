<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useToast } from '@/Composables/useToast';

const props = defineProps({
    user: Object,
});

const activeTab = ref('profile');
const { showSuccess, showError } = useToast();

const profileForm = useForm({
    name: props.user.name,
    email: props.user.email,
    department: props.user.department || '',
    position: props.user.position || '',
});

const passwordForm = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

const updateProfile = () => {
    profileForm.put(route('profile.update'), {
        onSuccess: () => {
            showSuccess('Profile updated successfully');
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
            showSuccess('Password updated successfully');
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
            <div class="bg-white rounded-lg shadow-sm p-6">
                <div class="flex items-center space-x-4">
                    <div class="h-20 w-20 bg-blue-600 rounded-full flex items-center justify-center">
                        <span class="text-2xl font-bold text-white">{{ user.name.charAt(0) }}</span>
                    </div>
                    <div>
                        <h2 class="text-2xl font-bold text-gray-900">{{ user.name }}</h2>
                        <p class="text-gray-600">{{ user.email }}</p>
                        <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-blue-100 text-blue-800 mt-1">
                            {{ user.roles[0]?.name || 'User' }}
                        </span>
                    </div>
                </div>
            </div>

            <!-- Tabs -->
            <div class="bg-white rounded-lg shadow-sm">
                <div class="border-b border-gray-200">
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
                    </nav>
                </div>

                <!-- Profile Information Tab -->
                <div v-show="activeTab === 'profile'" class="p-6">
                    <form @submit.prevent="updateProfile" class="space-y-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Full Name</label>
                                <input 
                                    v-model="profileForm.name" 
                                    type="text" 
                                    required 
                                    class="w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                                >
                                <div v-if="profileForm.errors.name" class="text-red-600 text-sm mt-1">{{ profileForm.errors.name }}</div>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Email Address</label>
                                <input 
                                    v-model="profileForm.email" 
                                    type="email" 
                                    required 
                                    class="w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                                >
                                <div v-if="profileForm.errors.email" class="text-red-600 text-sm mt-1">{{ profileForm.errors.email }}</div>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Department</label>
                                <input 
                                    v-model="profileForm.department" 
                                    type="text" 
                                    class="w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                                >
                                <div v-if="profileForm.errors.department" class="text-red-600 text-sm mt-1">{{ profileForm.errors.department }}</div>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Position</label>
                                <input 
                                    v-model="profileForm.position" 
                                    type="text" 
                                    class="w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                                >
                                <div v-if="profileForm.errors.position" class="text-red-600 text-sm mt-1">{{ profileForm.errors.position }}</div>
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
                            <label class="block text-sm font-medium text-gray-700 mb-2">Current Password</label>
                            <input 
                                v-model="passwordForm.current_password" 
                                type="password" 
                                required 
                                class="w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                            >
                            <div v-if="passwordForm.errors.current_password" class="text-red-600 text-sm mt-1">{{ passwordForm.errors.current_password }}</div>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">New Password</label>
                            <input 
                                v-model="passwordForm.password" 
                                type="password" 
                                required 
                                class="w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
                            >
                            <div v-if="passwordForm.errors.password" class="text-red-600 text-sm mt-1">{{ passwordForm.errors.password }}</div>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Confirm New Password</label>
                            <input 
                                v-model="passwordForm.password_confirmation" 
                                type="password" 
                                required 
                                class="w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
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
            </div>
        </div>
    </AppLayout>
</template>