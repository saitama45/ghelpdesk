<script setup>
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { useTimezone } from '@/Composables/useTimezone'
import { APP_TIMEZONE, deviceTimezone, timezoneLabel, timezoneOffsetLabel } from '@/lib/timezone'

/*
 * Google-Calendar-style travel prompt for pages that show company times.
 * When the device clock is in a different offset from "My timezone", offer to
 * switch. Otherwise, if the user is not on Manila time, keep a quiet reminder of
 * which zone the page is showing, with a one-click way back.
 */
const { timezone, isAppTimezone } = useTimezone()

const device = deviceTimezone()
const dismissKey = `timezone-banner-dismissed:${device}`
const saving = ref(false)

const readDismissed = () => {
    try {
        return window.localStorage.getItem(dismissKey) === timezone.value
    } catch {
        return false
    }
}
const dismissed = ref(readDismissed())

const deviceDiffers = computed(() =>
    timezoneOffsetLabel(device) !== timezoneOffsetLabel(timezone.value)
)

const showTravelPrompt = computed(() => deviceDiffers.value && !dismissed.value)
const showReminder = computed(() => !showTravelPrompt.value && !isAppTimezone.value)

const saveTimezone = (value) => {
    saving.value = true
    router.patch(route('profile.timezone'), { timezone: value }, {
        preserveScroll: true,
        preserveState: true,
        onFinish: () => { saving.value = false },
    })
}

const keepCurrent = () => {
    try {
        window.localStorage.setItem(dismissKey, timezone.value)
    } catch {
        // Private mode / blocked storage: the prompt simply returns next visit.
    }
    dismissed.value = true
}
</script>

<template>
    <div
        v-if="showTravelPrompt"
        class="mb-3 flex flex-col gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 sm:flex-row sm:items-center sm:justify-between dark:border-amber-900/40 dark:bg-amber-900/20 dark:text-amber-200"
        role="status"
    >
        <p>
            Your device is set to <strong>{{ timezoneLabel(device) }}</strong>, but times here are shown in
            <strong>{{ timezoneLabel(timezone) }}</strong>.
        </p>
        <div class="flex shrink-0 flex-wrap gap-2">
            <button
                type="button"
                :disabled="saving"
                class="rounded-lg bg-amber-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-amber-700 disabled:opacity-50"
                @click="saveTimezone(device)"
            >
                Use {{ timezoneLabel(device) }} time
            </button>
            <button
                type="button"
                :disabled="saving"
                class="rounded-lg border border-amber-300 bg-white px-3 py-1.5 text-xs font-bold text-amber-800 hover:bg-amber-100 disabled:opacity-50 dark:border-amber-800 dark:bg-transparent dark:text-amber-200 dark:hover:bg-amber-900/40"
                @click="keepCurrent"
            >
                Keep {{ timezoneLabel(timezone) }}
            </button>
        </div>
    </div>

    <div
        v-else-if="showReminder"
        class="mb-3 flex flex-wrap items-center gap-x-3 gap-y-1 rounded-xl border border-blue-100 bg-blue-50 px-4 py-2 text-xs text-blue-900 dark:border-blue-900/40 dark:bg-blue-900/20 dark:text-blue-200"
        role="status"
    >
        <span>Times are shown in <strong>{{ timezoneLabel(timezone) }}</strong>.</span>
        <button
            type="button"
            :disabled="saving"
            class="font-bold underline underline-offset-2 hover:text-blue-700 disabled:opacity-50 dark:hover:text-blue-100"
            @click="saveTimezone(APP_TIMEZONE)"
        >
            Switch back to Manila time
        </button>
        <a :href="route('profile.edit', { tab: 'timezone' })" class="font-bold underline underline-offset-2 hover:text-blue-700 dark:hover:text-blue-100">Change</a>
    </div>
</template>
