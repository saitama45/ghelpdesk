import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { APP_TIMEZONE, isValidTimezone, timezoneLabel } from '@/lib/timezone'

/**
 * The zone the signed-in user reads and types schedule/DTR times in: their
 * profile's "My timezone" (users.timezone), else Manila. Deliberately NOT the
 * device zone — a laptop that silently changes zone on a flight must not shift
 * what someone types. The travel banner offers the switch instead.
 */
export function useTimezone() {
    const page = usePage()

    const timezone = computed(() => {
        const saved = page.props.auth?.user?.timezone

        return isValidTimezone(saved) ? saved : APP_TIMEZONE
    })

    const isAppTimezone = computed(() => timezone.value === APP_TIMEZONE)
    const label = computed(() => timezoneLabel(timezone.value))

    return { timezone, isAppTimezone, label, APP_TIMEZONE }
}
