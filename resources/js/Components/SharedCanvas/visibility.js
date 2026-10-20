import { GlobeAltIcon, LockClosedIcon, UserGroupIcon } from '@heroicons/vue/24/outline';

/** Who can see a shared canvas (diagram, whiteboard) — mirrors App\Support\SharedCanvasAccess. */
export const VISIBILITY = {
    private: { label: 'Private', hint: 'Only you', icon: LockClosedIcon },
    department: { label: 'Department', hint: 'Your “I belong to” department', icon: UserGroupIcon },
    everyone: { label: 'Everyone', hint: 'Anyone with access to this module', icon: GlobeAltIcon },
};
