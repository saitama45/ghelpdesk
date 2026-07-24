import {
    HomeIcon,
    ClipboardDocumentListIcon,
    QueueListIcon,
    PresentationChartLineIcon,
    BriefcaseIcon,
    BuildingOfficeIcon,
    UserGroupIcon,
    Cog6ToothIcon,
    TicketIcon,
    ClockIcon,
    Squares2X2Icon,
    ComputerDesktopIcon,
    CircleStackIcon,
    GiftIcon,
    CubeIcon,
    ArrowDownTrayIcon,
    ArrowsRightLeftIcon,
    InboxArrowDownIcon,
    DocumentChartBarIcon,
    ShieldCheckIcon,
    VideoCameraIcon,
    TrophyIcon,
    BanknotesIcon,
    DocumentTextIcon,
    SignalIcon,
    CalendarDaysIcon,
    ClipboardDocumentCheckIcon,
    CalendarIcon,
    TruckIcon,
    UserCircleIcon,
    BookOpenIcon,
    BuildingOffice2Icon,
    MapIcon,
    BuildingStorefrontIcon,
    WrenchScrewdriverIcon,
    RectangleStackIcon,
    TagIcon,
    ArchiveBoxIcon,
    ListBulletIcon,
    PuzzlePieceIcon,
    ChartBarIcon,
    UsersIcon,
    KeyIcon,
    ArchiveBoxArrowDownIcon,
    ChatBubbleLeftRightIcon,
    StarIcon,
} from '@heroicons/vue/24/outline';

/**
 * Single source of truth for the application's module structure.
 *
 * Consumed by:
 *  - Sidebar.vue          — renders sections and their children
 *  - Hub pages            — renders each section's children as box buttons
 *  - Settings layout UI   — drag-orders sections/children
 *
 * Order/labels the user has customised live in useSidebarOrder.js, which derives
 * its defaults from this file. Adding a module means adding one entry here.
 *
 * Section fields:
 *   id            stable key used by the order/label store
 *   label         default display label
 *   verb          prototype-style action word shown under the label on hubs
 *   description   one-line summary shown on the hub header
 *   icon          heroicon component
 *   iconPath      raw SVG path, used instead of `icon` where the existing
 *                 sidebar drew an inline SVG (keeps the glyph identical)
 *   direct        true = plain link, no children and no hub
 *   routeName     required when `direct`
 *   activeMatch   route patterns that light the section up (direct sections)
 *   permission    gate for direct sections
 *   requires      extra permission ANDed with child visibility (group sections)
 *
 * Child fields:
 *   id, label, description, icon, routeName, routeParams, activeMatch
 *   permission    string, or array meaning "any of"
 *   countsForVisibility
 *                 false = present but does not by itself keep the parent
 *                 section visible (e.g. My Profile, which everyone can reach)
 */
export const MODULE_REGISTRY = [
    {
        id: 'dashboard',
        label: 'Dashboard',
        verb: 'See',
        description: 'Your daily overview of what needs attention.',
        icon: HomeIcon,
        direct: true,
        routeName: 'dashboard',
        activeMatch: ['dashboard'],
        children: [],
    },
    {
        id: 'projectTracker',
        label: 'Project Tracker',
        verb: 'Deliver',
        description: 'Portfolio status from planning through go-live.',
        icon: ClipboardDocumentListIcon,
        direct: true,
        routeName: 'projects.index',
        activeMatch: ['projects.*'],
        permission: 'projects.view',
        children: [],
    },
    {
        id: 'services',
        label: 'Services',
        verb: 'Request',
        description: 'Raise, track, and fulfil requests across the organisation.',
        icon: QueueListIcon,
        children: [
            {
                id: 'tickets',
                label: 'Tickets',
                description: 'Service desk, SLA tracking, and request intake',
                icon: TicketIcon,
                routeName: 'tickets.index',
                activeMatch: ['tickets.*'],
                permission: 'tickets.view',
                eta: '4 business hours',
            },
            {
                id: 'queue',
                label: 'Queue Monitor',
                description: 'Live SLA-ordered queue and walk-in kiosk',
                icon: ClockIcon,
                routeName: 'queue.index',
                activeMatch: ['queue.*'],
                permission: 'queue.view',
                eta: 'Live',
            },
            {
                id: 'task-boards',
                label: 'Task Board',
                description: 'Boards, cards, checklists, and assignments',
                icon: Squares2X2Icon,
                routeName: 'task-boards.index',
                activeMatch: ['task-boards.*'],
                permission: 'task_boards.view',
                eta: 'Same day',
            },
            {
                id: 'pos-requests',
                label: 'POS Requests',
                description: 'Store POS access, configuration, and support',
                icon: ComputerDesktopIcon,
                routeName: 'pos-requests.index',
                activeMatch: ['pos-requests.*'],
                permission: 'pos_requests.view',
                eta: '1 business day',
            },
            {
                id: 'sap-requests',
                label: 'SAP Requests',
                description: 'Roles, authorisation, master data, enhancements',
                icon: CircleStackIcon,
                routeName: 'sap-requests.index',
                activeMatch: ['sap-requests.*'],
                permission: 'sap_requests.view',
                eta: '2 business days',
            },
            {
                id: 'stamps',
                label: 'Loyalty Stamps',
                description: 'Stamp programs, cards, and redemptions',
                icon: GiftIcon,
                routeName: 'stamps.index',
                activeMatch: ['stamps.*'],
                permission: 'stamps.view',
                eta: '1 business day',
            },
        ],
    },
    {
        id: 'inventory',
        label: 'Inventory',
        verb: 'Supply',
        description: 'Asset governance and end-to-end stock movement.',
        // Kept as a raw path so the glyph matches the previous inline SVG exactly.
        iconPath: 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4',
        children: [
            {
                id: 'assets',
                label: 'Assets',
                description: 'Asset master, classifications, and properties',
                icon: CubeIcon,
                routeName: 'assets.index',
                activeMatch: ['assets.*'],
                permission: 'assets.view',
            },
            {
                id: 'stock-ins',
                label: 'Stock In',
                description: 'Record stock arrivals and quantities',
                icon: ArrowDownTrayIcon,
                routeName: 'stock-ins.index',
                activeMatch: ['stock-ins.*'],
                permission: 'stock_ins.view',
            },
            {
                id: 'stock-transfers',
                label: 'Stock Transfer',
                description: 'Internal stock movement between locations',
                icon: ArrowsRightLeftIcon,
                routeName: 'stock-transfers.index',
                activeMatch: ['stock-transfers.*'],
                permission: 'stock_transfers.view',
            },
            {
                id: 'stock-receivings',
                label: 'Receiving Stock',
                description: 'Confirm receipt of transferred stock',
                icon: InboxArrowDownIcon,
                routeName: 'stock-receivings.index',
                activeMatch: ['stock-receivings.*'],
                permission: 'stock_receivings.view',
            },
            {
                id: 'inventory-report',
                label: 'Inventory Report',
                description: 'Stock on hand, value, and movement history',
                icon: DocumentChartBarIcon,
                routeName: 'reports.inventory',
                activeMatch: ['reports.inventory'],
                permission: 'reports.inventory',
            },
        ],
    },
    {
        id: 'monitoring',
        label: 'Monitoring',
        verb: 'Prevent',
        description: 'Detect degradation before it becomes a ticket.',
        icon: PresentationChartLineIcon,
        children: [
            {
                id: 'npc-status',
                label: 'NPC Status',
                description: 'NPC/DPO registration, seals, and receipts',
                icon: ShieldCheckIcon,
                routeName: 'npc-statuses.index',
                activeMatch: ['npc-statuses.*'],
                permission: ['npc_status.view', 'npc_status.download'],
            },
            {
                id: 'cctv-monitoring',
                label: 'CCTV Monitoring',
                description: 'Camera systems, inspections, and coverage',
                icon: VideoCameraIcon,
                routeName: 'cctv-monitoring.index',
                activeMatch: ['cctv-monitoring.*'],
                permission: 'cctv_monitoring.view',
            },
            {
                id: 'alaga',
                label: 'ALAGA',
                description: 'Store IT-asset condition assessments & scorecards',
                icon: ClipboardDocumentCheckIcon,
                routeName: 'alaga.index',
                activeMatch: ['alaga.*'],
                permission: 'alaga.view',
            },
            {
                id: 'wigs',
                label: 'WIGS',
                description: 'Yardstick, PCF, PAF, and quarterly grading',
                icon: TrophyIcon,
                routeName: 'wigs.index',
                activeMatch: ['wigs.*'],
                permission: 'wigs.view',
            },
            {
                id: 'payments',
                label: 'Payments & SOA',
                description: 'Recurring payables, invoices, and renewals',
                icon: BanknotesIcon,
                routeName: 'payments.index',
                activeMatch: ['payments.*'],
                permission: 'payments.view',
            },
            {
                id: 'accounting-documents',
                label: 'Accounting Documents',
                description: 'Document review and approval events',
                icon: DocumentTextIcon,
                routeName: 'accounting-documents.index',
                activeMatch: ['accounting-documents.*'],
                permission: 'accounting-documents.view',
            },
            {
                id: 'mall-hookups',
                label: 'Mall Hookup',
                description: 'Daily POS auto-sending compliance',
                icon: SignalIcon,
                routeName: 'mall-hookups.index',
                activeMatch: ['mall-hookups.*'],
                permission: 'mall_hookup.view',
            },
        ],
    },
    {
        id: 'adminTask',
        label: 'Administrative',
        verb: 'Manage',
        description: 'Schedules, attendance, and fleet coordination.',
        icon: BriefcaseIcon,
        children: [
            {
                id: 'dtr',
                label: 'DTR',
                description: 'Daily time records and reporting',
                icon: CalendarDaysIcon,
                routeName: 'attendance.index',
                activeMatch: ['attendance.index'],
                permission: 'attendance.view',
            },
            {
                id: 'attendance-logs',
                label: 'Attendance Logs',
                description: 'Raw attendance log entries',
                icon: ClipboardDocumentCheckIcon,
                routeName: 'attendance.logs',
                activeMatch: ['attendance.logs'],
                permission: 'attendance.logs',
            },
            {
                id: 'scheduling',
                label: 'Scheduling',
                description: 'Shift planning and schedule changes',
                icon: CalendarIcon,
                routeName: 'schedules.index',
                activeMatch: ['schedules.*'],
                permission: 'schedules.view',
            },
            {
                id: 'service-vehicle-trips',
                label: 'Service Vehicle Trips',
                description: 'Vehicle trip logging and monitoring',
                icon: TruckIcon,
                routeName: 'service-vehicle-trips.index',
                activeMatch: ['service-vehicle-trips.*'],
                permission: 'service_vehicle_trips.view',
            },
            {
                id: 'presence',
                label: 'Presence',
                description: 'Who is online and current status',
                icon: UserCircleIcon,
                routeName: 'presence.index',
                activeMatch: ['presence.*'],
                permission: 'presence.view',
            },
            {
                id: 'kb-articles',
                label: 'KB Articles',
                description: 'Knowledge base articles and guides',
                icon: BookOpenIcon,
                routeName: 'kb-articles.index',
                activeMatch: ['kb-articles.*'],
                permission: 'kb_articles.view',
            },
        ],
    },
    {
        id: 'references',
        label: 'References',
        verb: 'Know',
        description: 'Master data behind every module.',
        icon: BuildingOfficeIcon,
        children: [
            {
                id: 'companies',
                label: 'Companies',
                description: 'Entities and brands',
                icon: BuildingOffice2Icon,
                routeName: 'companies.index',
                activeMatch: ['companies.*'],
                permission: 'companies.view',
            },
            {
                id: 'departments',
                label: 'Departments',
                description: 'Department hierarchy and sub-units',
                icon: UserGroupIcon,
                routeName: 'departments.index',
                activeMatch: ['departments.*'],
                permission: 'departments.view',
            },
            {
                id: 'clusters',
                label: 'Clusters',
                description: 'Store clusters and groupings',
                icon: MapIcon,
                routeName: 'clusters.index',
                activeMatch: ['clusters.*'],
                permission: 'clusters.view',
            },
            {
                id: 'stores',
                label: 'Stores',
                description: 'Store and office master records',
                icon: BuildingStorefrontIcon,
                routeName: 'stores.index',
                activeMatch: ['stores.*'],
                permission: 'stores.view',
            },
            {
                id: 'vendors',
                label: 'Vendors',
                description: 'Suppliers and service providers',
                icon: WrenchScrewdriverIcon,
                routeName: 'vendors.index',
                activeMatch: ['vendors.*'],
                permission: 'vendors.view',
            },
            {
                id: 'activity-templates',
                label: 'Activity Templates',
                description: 'Reusable project activity structures',
                icon: RectangleStackIcon,
                routeName: 'activity-templates.index',
                activeMatch: ['activity-templates.*'],
                permission: 'activity_templates.view',
            },
            {
                id: 'categories',
                label: 'Categories',
                description: 'Top-level ticket categories',
                icon: TagIcon,
                routeName: 'categories.index',
                activeMatch: ['categories.*'],
                permission: 'categories.view',
            },
            {
                id: 'sub-categories',
                label: 'Sub-Categories',
                description: 'Second-level ticket classification',
                icon: TagIcon,
                routeName: 'sub-categories.index',
                activeMatch: ['sub-categories.*'],
                permission: 'subcategories.view',
            },
            {
                id: 'items',
                label: 'Items',
                description: 'Requestable item catalogue',
                icon: ArchiveBoxIcon,
                routeName: 'items.index',
                activeMatch: ['items.*'],
                permission: 'items.view',
            },
            {
                id: 'request-types',
                label: 'Request Types',
                description: 'Request type definitions and routing',
                icon: ListBulletIcon,
                routeName: 'request-types.index',
                activeMatch: ['request-types.*'],
                permission: 'request_types.view',
            },
            {
                id: 'form-builder',
                label: 'Form Builder',
                description: 'Build and publish dynamic forms',
                icon: PuzzlePieceIcon,
                routeName: 'form-builder.index',
                activeMatch: ['form-builder.*'],
                permission: 'form_builder.view',
            },
        ],
    },
    {
        id: 'reports',
        label: 'Reports',
        verb: 'Analyze',
        description: 'Performance intelligence across service delivery.',
        icon: PresentationChartLineIcon,
        requires: 'reports.view',
        children: [
            {
                id: 'store-health',
                label: 'Store Health Report',
                description: 'Live store and office health signals',
                icon: ChartBarIcon,
                routeName: 'reports.store-health',
                activeMatch: ['reports.store-health'],
                permission: 'reports.store_health',
            },
            {
                id: 'sla-performance',
                label: 'SLA Performance Report',
                description: 'Response and resolution compliance',
                icon: ClockIcon,
                routeName: 'reports.sla-performance',
                activeMatch: ['reports.sla-performance'],
                permission: 'reports.sla_performance',
            },
            {
                id: 'assignee-performance',
                label: 'Assignee Performance',
                description: 'Per-assignee workload and outcomes',
                icon: UsersIcon,
                routeName: 'reports.assignee-performance',
                activeMatch: ['reports.assignee-performance'],
                permission: 'reports.assignee_performance',
            },
        ],
    },
    {
        id: 'userManagement',
        label: 'User Management',
        verb: 'Govern',
        description: 'People, roles, and what they can reach.',
        icon: UserGroupIcon,
        children: [
            {
                id: 'users',
                label: 'Users',
                description: 'User accounts and org placement',
                icon: UsersIcon,
                routeName: 'users.index',
                activeMatch: ['users.*'],
                permission: 'users.view',
            },
            {
                id: 'roles',
                label: 'Roles & Permissions',
                description: 'Role definitions and permission grants',
                icon: KeyIcon,
                routeName: 'roles.index',
                activeMatch: ['roles.*'],
                permission: 'roles.view',
            },
        ],
    },
    {
        id: 'settings',
        label: 'Settings',
        verb: 'Configure',
        description: 'System configuration and personal preferences.',
        icon: Cog6ToothIcon,
        children: [
            {
                id: 'system-settings',
                label: 'System Settings',
                description: 'Application-wide configuration',
                icon: Cog6ToothIcon,
                routeName: 'settings.index',
                activeMatch: ['settings.index'],
                permission: 'settings.view',
            },
            {
                id: 'ticket-archive',
                label: 'Ticket Archive',
                description: 'Archived and purged ticket records',
                icon: ArchiveBoxArrowDownIcon,
                routeName: 'ticket-archive.index',
                activeMatch: ['ticket-archive.*'],
                permission: 'settings.view',
            },
            {
                id: 'canned-messages',
                label: 'Canned Messages',
                description: 'Reusable ticket reply templates',
                icon: ChatBubbleLeftRightIcon,
                routeName: 'canned-messages.index',
                activeMatch: ['canned-messages.*'],
                permission: 'canned_messages.view',
            },
            {
                id: 'leadership-points',
                label: 'Leadership Points',
                description: 'Agent points, quests, and standings',
                icon: StarIcon,
                routeName: 'leadership-points.index',
                activeMatch: ['leadership-points.*'],
                permission: 'leadership_points.view',
            },
            {
                id: 'profile',
                label: 'My Profile',
                description: 'Your account details and preferences',
                icon: UserCircleIcon,
                routeName: 'profile.edit',
                activeMatch: ['profile.edit'],
                // Everyone can reach their profile, so it must not on its own
                // keep the Settings section visible for users without any
                // settings permission.
                countsForVisibility: false,
            },
        ],
    },
];

/** Registry keyed by section id, for direct lookups. */
export const MODULE_SECTIONS = Object.fromEntries(
    MODULE_REGISTRY.map((section) => [section.id, section])
);

/** Section ids in their default order. */
export const REGISTRY_SECTION_ORDER = MODULE_REGISTRY.map((section) => section.id);

/** Default section labels, keyed by section id. */
export const REGISTRY_SECTION_LABELS = Object.fromEntries(
    MODULE_REGISTRY.map((section) => [section.id, section.label])
);

/** Default child id order, keyed by section id. */
export const REGISTRY_CHILD_ORDER = Object.fromEntries(
    MODULE_REGISTRY.map((section) => [
        section.id,
        section.children.map((child) => child.id),
    ])
);

/** Default child labels, keyed by section id then child id. */
export const REGISTRY_CHILD_LABELS = Object.fromEntries(
    MODULE_REGISTRY
        .filter((section) => section.children.length > 0)
        .map((section) => [
            section.id,
            Object.fromEntries(section.children.map((child) => [child.id, child.label])),
        ])
);

/** Look up a single child definition. */
export function findChild(sectionId, childId) {
    return MODULE_SECTIONS[sectionId]?.children.find((child) => child.id === childId) || null;
}

export function useModuleRegistry() {
    return {
        registry: MODULE_REGISTRY,
        sections: MODULE_SECTIONS,
        findChild,
    };
}
