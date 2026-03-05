import { usePage } from '@inertiajs/vue3';

export function usePermission() {
    const hasPermission = (name) => {
        const user = usePage().props.auth.user || {};
        const roles = user.roles?.map(r => r.name) || [];
        
        // Super Admin check
        if (roles.some(role => ['Admin'].includes(role))) {
            return true;
        }

        const permissions = usePage().props.auth.permissions || [];
        return permissions.includes(name);
    };

    const hasAnyPermission = (names) => {
        return names.some(name => hasPermission(name));
    };

    return { hasPermission, hasAnyPermission };
}
