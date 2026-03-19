import { ref, onMounted, onUnmounted } from 'vue';
import axios from 'axios';
import { usePage } from '@inertiajs/vue3';

export function usePresence() {
    const page = usePage();
    const user = page.props.auth.user;
    const idleTimer = ref(null);
    const idleTimeout = 5 * 60 * 1000; // 5 minutes
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
        if (currentStatus.value === 'idle') {
            updateStatus('online');
        }
        
        clearTimeout(idleTimer.value);
        idleTimer.value = setTimeout(() => {
            updateStatus('idle');
        }, idleTimeout);
    };

    const startHeartbeat = () => {
        heartbeatInterval.value = setInterval(() => {
            // Send a ping to keep session alive and update last_activity_at
            axios.post(route('presence.update'), { status: currentStatus.value })
                .catch(e => console.debug('Presence ping failed', e));
        }, 60 * 1000); // Every minute
    };

    const init = () => {
        if (!user) return;

        // Add event listeners for activity
        window.addEventListener('mousemove', resetIdleTimer);
        window.addEventListener('keydown', resetIdleTimer);
        window.addEventListener('scroll', resetIdleTimer);
        window.addEventListener('click', resetIdleTimer);

        resetIdleTimer();
        startHeartbeat();
        
        // Mark as online when page loads
        if (currentStatus.value === 'offline') {
            updateStatus('online');
        }
    };

    const destroy = () => {
        window.removeEventListener('mousemove', resetIdleTimer);
        window.removeEventListener('keydown', resetIdleTimer);
        window.removeEventListener('scroll', resetIdleTimer);
        window.removeEventListener('click', resetIdleTimer);
        
        clearTimeout(idleTimer.value);
        clearInterval(heartbeatInterval.value);
    };

    return {
        currentStatus,
        init,
        destroy,
        updateStatus
    };
}