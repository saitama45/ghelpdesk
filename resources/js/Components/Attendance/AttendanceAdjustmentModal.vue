<script setup>
/**
 * DTR "approval needed" prompt + request form, shown after a Time In on a rest day or outside
 * the assigned store. The clock-in is already recorded; this only files a schedule change
 * request (scoped to the clock-in day) for the "Reports To" leader to approve on /schedules.
 */
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';
import Autocomplete from '@/Components/Autocomplete.vue';
import { useTimezone } from '@/Composables/useTimezone';
import { APP_TIMEZONE, convertInputValue, formatIn, inputValueIn } from '@/lib/timezone';

const props = defineProps({
    show: { type: Boolean, default: false },
    adjustment: { type: Object, default: null },
    stores: { type: Array, default: () => [] },
    // 'prompt' right after clock-in; 'form' when reopened from the DTR banner.
    initialStep: { type: String, default: 'prompt' },
});

const emit = defineEmits(['close']);

const WORK_TYPES = ['On-site', 'Off-site', 'WFH'];

const { timezone: viewerTimezone } = useTimezone();
const step = ref(props.initialStep);

const isRestDay = computed(() => props.adjustment?.reason === 'rest_day');
const assignedStoreName = computed(() => props.adjustment?.assigned_store?.name || 'your assigned store');
const detectedStoreName = computed(() => props.adjustment?.detected_store?.name || null);
const actionLabel = computed(() => isRestDay.value ? 'Request Schedule Adjustment' : 'Request Location Adjustment');
const clockInLabel = computed(() => props.adjustment?.log_time
    ? formatIn(props.adjustment.log_time, viewerTimezone.value, { hour: '2-digit', minute: '2-digit' })
    : '');

const storeOptions = computed(() => props.stores.map((store) => ({
    value: store.id,
    label: store.code && store.code !== store.name ? `${store.name} (${store.code})` : store.name,
})));

const form = useForm({
    status: 'On-site',
    store_id: null,
    start_time: '',
    end_time: '',
    requester_remarks: '',
});

const generalError = computed(() => form.errors.approver || form.errors.request || form.errors.stores || form.errors.user_id || null);

const resetForm = () => {
    const defaults = props.adjustment?.defaults ?? {};

    form.clearErrors();
    form.status = defaults.status || 'On-site';
    form.store_id = defaults.store_id ?? null;
    form.start_time = inputValueIn(defaults.start_time, viewerTimezone.value);
    form.end_time = inputValueIn(defaults.end_time, viewerTimezone.value);
    form.requester_remarks = '';
};

watch(() => props.show, (visible) => {
    if (!visible) return;

    step.value = props.initialStep;
    resetForm();
}, { immediate: true });

const close = () => {
    if (form.processing) return;
    emit('close');
};

const submit = () => {
    if (!props.adjustment?.log_id || form.processing) return;

    form
        .transform((data) => ({
            ...data,
            // Inputs show "My timezone"; the server contract is Manila wall time.
            start_time: convertInputValue(data.start_time, viewerTimezone.value, APP_TIMEZONE),
            end_time: convertInputValue(data.end_time, viewerTimezone.value, APP_TIMEZONE),
        }))
        .post(route('attendance.adjustment-request', props.adjustment.log_id), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => emit('close'),
        });
};
</script>

<template>
    <Modal :show="show" max-width="xl" centered :closeable="!form.processing" @close="close">
        <div v-if="adjustment" class="p-6 sm:p-8" data-testid="attendance-adjustment-modal">
            <template v-if="step === 'prompt'">
                <h2 class="text-center text-xl font-black text-gray-900 dark:text-gray-100">
                    {{ isRestDay ? 'Schedule mismatch — approval needed' : 'Location mismatch — approval needed' }}
                </h2>

                <p v-if="isRestDay" class="mt-4 text-center text-base text-gray-700 dark:text-gray-300">
                    Today is marked as a rest day, but you clocked in.
                    Your clock-in was recorded. Would you like to request a schedule adjustment for your leader's approval?
                </p>
                <p v-else class="mt-4 text-center text-base text-gray-700 dark:text-gray-300">
                    Your assigned work location is <strong class="text-gray-900 dark:text-gray-100">{{ assignedStoreName }}</strong>,
                    but your clock-in was detected
                    <template v-if="detectedStoreName">in the <strong class="text-gray-900 dark:text-gray-100">{{ detectedStoreName }}</strong> area.</template>
                    <template v-else>outside its area.</template>
                    Your clock-in was recorded. Would you like to request a location adjustment for your leader's approval?
                </p>

                <div class="mt-8 grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <button
                        type="button"
                        class="rounded-lg bg-blue-600 px-4 py-3 text-sm font-bold text-white shadow-sm transition-colors hover:bg-blue-700"
                        @click="step = 'form'"
                    >
                        {{ actionLabel }}
                    </button>
                    <button
                        type="button"
                        class="rounded-lg border border-gray-300 bg-white px-4 py-3 text-sm font-bold text-gray-700 shadow-sm transition-colors hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
                        @click="close"
                    >
                        Not Now
                    </button>
                </div>
            </template>

            <form v-else class="space-y-5" @submit.prevent="submit">
                <div>
                    <h2 class="text-lg font-black text-gray-900 dark:text-gray-100">{{ actionLabel }}</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        {{ isRestDay ? 'Rest-day clock-in' : 'Clock-in outside ' + assignedStoreName }}
                        at {{ clockInLabel }}<template v-if="detectedStoreName"> · detected at {{ detectedStoreName }}</template>.
                        Your leader will review this on the Scheduling page.
                    </p>
                </div>

                <div v-if="generalError" class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm font-semibold text-red-700 dark:border-red-800 dark:bg-red-900/30 dark:text-red-300">
                    {{ generalError }}
                </div>

                <div>
                    <label class="mb-1 block text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-gray-300">Work type</label>
                    <div class="grid grid-cols-3 gap-2" role="radiogroup" aria-label="Work type">
                        <button
                            v-for="workType in WORK_TYPES"
                            :key="workType"
                            type="button"
                            role="radio"
                            :aria-checked="form.status === workType"
                            class="rounded-lg border px-3 py-2 text-sm font-bold transition-colors"
                            :class="form.status === workType
                                ? 'border-blue-600 bg-blue-50 text-blue-700 dark:border-blue-400 dark:bg-blue-900/30 dark:text-blue-200'
                                : 'border-gray-200 bg-white text-gray-600 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700'"
                            @click="form.status = workType"
                        >
                            {{ workType }}
                        </button>
                    </div>
                    <p v-if="form.errors.status" class="mt-1 text-xs font-semibold text-red-600">{{ form.errors.status }}</p>
                </div>

                <div>
                    <label class="mb-1 block text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-gray-300">Location</label>
                    <Autocomplete
                        v-model="form.store_id"
                        :options="storeOptions"
                        placeholder="Search the location you are working at..."
                    />
                    <p v-if="form.errors.store_id" class="mt-1 text-xs font-semibold text-red-600">{{ form.errors.store_id }}</p>
                </div>

                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div>
                        <label for="adjustment-start" class="mb-1 block text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-gray-300">Start</label>
                        <input
                            id="adjustment-start"
                            v-model="form.start_time"
                            type="datetime-local"
                            required
                            class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
                        />
                        <p v-if="form.errors.start_time" class="mt-1 text-xs font-semibold text-red-600">{{ form.errors.start_time }}</p>
                    </div>
                    <div>
                        <label for="adjustment-end" class="mb-1 block text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-gray-300">End</label>
                        <input
                            id="adjustment-end"
                            v-model="form.end_time"
                            type="datetime-local"
                            required
                            class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
                        />
                        <p v-if="form.errors.end_time" class="mt-1 text-xs font-semibold text-red-600">{{ form.errors.end_time }}</p>
                    </div>
                </div>

                <div>
                    <label for="adjustment-reason" class="mb-1 block text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-gray-300">Reason</label>
                    <textarea
                        id="adjustment-reason"
                        v-model="form.requester_remarks"
                        rows="3"
                        maxlength="1000"
                        required
                        class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 dark:placeholder-gray-400"
                        :placeholder="isRestDay ? 'Why are you working on your rest day?' : 'Why are you working at this location?'"
                    ></textarea>
                    <p v-if="form.errors.requester_remarks" class="mt-1 text-xs font-semibold text-red-600">{{ form.errors.requester_remarks }}</p>
                </div>

                <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                    <button
                        type="button"
                        class="rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-bold text-gray-700 hover:bg-gray-50 disabled:opacity-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
                        :disabled="form.processing"
                        @click="initialStep === 'prompt' ? (step = 'prompt') : close()"
                    >
                        {{ initialStep === 'prompt' ? 'Back' : 'Cancel' }}
                    </button>
                    <button
                        type="submit"
                        class="rounded-lg bg-blue-600 px-5 py-2.5 text-sm font-bold text-white shadow-sm hover:bg-blue-700 disabled:opacity-50"
                        :disabled="form.processing"
                    >
                        {{ form.processing ? 'Sending...' : 'Send to Leader' }}
                    </button>
                </div>
            </form>
        </div>
    </Modal>
</template>
