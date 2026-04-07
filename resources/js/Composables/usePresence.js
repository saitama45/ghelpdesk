import { ref } from 'vue';
import axios from 'axios';
import { usePage } from '@inertiajs/vue3';

// Minimum ms between any two status-update requests (30 seconds)
const MIN_UPDATE_INTERVAL = 30 * 1000;

export function usePresence() {
    const page = usePage();
    const user = page.props.auth.user;
    const idleTimer = ref(null);
    const idleTimeout = 3 * 60 * 1000; // 3 minutes
    const heartbeatInterval = ref(null);
    const currentStatus = ref(user?.status || 'offline');

    // Guards against concurrent requests and rapid re-fires
    let isUpdating = false;
    let lastUpdateAt = 0;
    // Debounce handle for "back to online" so mousemove spam doesn't fire many requests
    let wakeDebounce = null;

    const updateStatus = async (status) => {
        if (!user) return;
        if (currentStatus.value === status) return;
        if (isUpdating) return;

        const now = Date.now();
        if (now - lastUpdateAt < MIN_UPDATE_INTERVAL) return;

        isUpdating = true;
        lastUpdateAt = now;

        try {
            await axios.post(route('presence.update'), { status });
            currentStatus.value = status;
        } catch (error) {
            console.error('Failed to update status:', error);
        } finally {
            isUpdating = false;
        }
    };

    const resetIdleTimer = () => {
        // Debounce the "wake from idle" update — only fire once per burst of activity
        if (currentStatus.value === 'idle') {
            if (wakeDebounce) clearTimeout(wakeDebounce);
            wakeDebounce = setTimeout(() => updateStatus('online'), 500);
        }

        // Reset the inactivity countdown
        if (idleTimer.value) clearTimeout(idleTimer.value);

        idleTimer.value = setTimeout(() => {
            if (currentStatus.value === 'online') {
                updateStatus('idle');
            }
        }, idleTimeout);
    };

    const startHeartbeat = () => {
        if (heartbeatInterval.value) clearInterval(heartbeatInterval.value);

        heartbeatInterval.value = setInterval(() => {
            if (!user || isUpdating) return;
            const now = Date.now();
            if (now - lastUpdateAt < MIN_UPDATE_INTERVAL) return;

            lastUpdateAt = now;
            axios.post(route('presence.update'), { status: currentStatus.value })
                .catch(e => console.debug('Presence heartbeat failed', e));
        }, 60 * 1000); // Every 1 minute
    };

    const init = () => {
        if (!user) return;

        window.addEventListener('mousemove', resetIdleTimer);
        window.addEventListener('keydown', resetIdleTimer);
        window.addEventListener('scroll', resetIdleTimer);
        window.addEventListener('click', resetIdleTimer);

        startHeartbeat();
        resetIdleTimer();

        // Mark online if the DB thinks they're offline
        if (currentStatus.value === 'offline') {
            updateStatus('online');
        }
    };

    const destroy = () => {
        window.removeEventListener('mousemove', resetIdleTimer);
        window.removeEventListener('keydown', resetIdleTimer);
        window.removeEventListener('scroll', resetIdleTimer);
        window.removeEventListener('click', resetIdleTimer);

        if (idleTimer.value) clearTimeout(idleTimer.value);
        if (heartbeatInterval.value) clearInterval(heartbeatInterval.value);
        if (wakeDebounce) clearTimeout(wakeDebounce);
    };

    return {
        currentStatus,
        init,
        destroy,
        updateStatus,
    };
}