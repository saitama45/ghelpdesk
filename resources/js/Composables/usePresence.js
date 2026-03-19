import { ref, onMounted, onUnmounted } from 'vue';
import axios from 'axios';
import { usePage } from '@inertiajs/vue3';

export function usePresence() {
    const page = usePage();
    const user = page.props.auth.user;
    const idleTimer = ref(null);
    const idleTimeout = 3 * 60 * 1000; // 3 minutes
    const heartbeatInterval = ref(null);
    const currentStatus = ref(user?.status || 'offline');

    const updateStatus = async (status) => {
        if (!user || currentStatus.value === status) return;
        
        try {
            await axios.post(route('presence.update'), { status });
            currentStatus.value = status;
        } catch (error) {
            console.error('Failed to update status:', error);
        }
    };

    const resetIdleTimer = () => {
        // If we were idle, immediately mark as online upon movement
        if (currentStatus.value === 'idle') {
            updateStatus('online');
        }
        
        // Reset the timer
        if (idleTimer.value) clearTimeout(idleTimer.value);
        
        idleTimer.value = setTimeout(() => {
            // After 3 minutes of no activity, mark as idle
            if (currentStatus.value === 'online') {
                updateStatus('idle');
            }
        }, idleTimeout);
    };

    const startHeartbeat = () => {
        if (heartbeatInterval.value) clearInterval(heartbeatInterval.value);
        
        heartbeatInterval.value = setInterval(() => {
            // Heartbeat keeps last_activity_at fresh and sends current status
            if (user) {
                axios.post(route('presence.update'), { status: currentStatus.value })
                    .catch(e => console.debug('Presence heartbeat failed', e));
            }
        }, 60 * 1000); // Every 1 minute
    };

    const init = () => {
        if (!user) return;

        // Mouse move resets the idle timer
        window.addEventListener('mousemove', resetIdleTimer);
        window.addEventListener('keydown', resetIdleTimer);
        window.addEventListener('scroll', resetIdleTimer);
        window.addEventListener('click', resetIdleTimer);

        // Initial setup
        startHeartbeat();
        resetIdleTimer();
        
        // If they are offline in the DB but the app is open, they are now online
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
    };

    return {
        currentStatus,
        init,
        destroy,
        updateStatus
    };
}