/**
 * Built-in icon set for canvases ("Elements" panel). Each icon is drawn on a 24×24 grid:
 * `d` is stroked (round caps/joins), `fill` is painted solid, both in the element's colour,
 * so an icon can be recoloured and stays crisp at any size and in every export.
 */

const circle = (cx, cy, r) => `M${cx - r} ${cy}a${r} ${r} 0 1 0 ${r * 2} 0a${r} ${r} 0 1 0 ${-r * 2} 0`;
const rect = (x, y, w, h) => `M${x} ${y}h${w}v${h}h${-w}z`;
const rounded = (x, y, w, h, r) => `M${x + r} ${y}h${w - r * 2}a${r} ${r} 0 0 1 ${r} ${r}v${h - r * 2}a${r} ${r} 0 0 1 ${-r} ${r}h${-(w - r * 2)}a${r} ${r} 0 0 1 ${-r} ${-r}v${-(h - r * 2)}a${r} ${r} 0 0 1 ${r} ${-r}z`;

export const ICON_GROUPS = [
    { id: 'security', label: 'Security & CCTV' },
    { id: 'network', label: 'Network & IT' },
    { id: 'devices', label: 'Devices & POS' },
    { id: 'places', label: 'Places & people' },
    { id: 'symbols', label: 'Symbols' },
];

export const CANVAS_ICONS = {
    // ── Security & CCTV ───────────────────────────────────────────────────────
    'cctv-bullet': {
        label: 'Bullet camera', group: 'security', keywords: 'cctv camera security surveillance bullet',
        d: `${rounded(2, 6, 14, 6, 1.5)} M16 8l5-1.5v7L16 12 M8 12v3H4 M4 13v6`,
        fill: circle(5, 9, 0.9),
    },
    'cctv-dome': {
        label: 'Dome camera', group: 'security', keywords: 'cctv camera security surveillance dome ceiling',
        d: `M3 5h18 M5 5v2h14V5 M5 7a7 7 0 0 0 14 0 ${circle(12, 11, 2.3)}`,
        fill: circle(12, 11, 0.8),
    },
    'cctv-ptz': {
        label: 'PTZ camera', group: 'security', keywords: 'cctv ptz pan tilt zoom camera',
        d: `M12 2v3 ${rounded(6, 5, 12, 3, 1)} M7 8a5 5 0 0 0 10 0 ${circle(12, 11, 1.6)} M3 14a9 9 0 0 0 3.5 5.5 M21 14a9 9 0 0 1-3.5 5.5`,
    },
    nvr: {
        label: 'NVR / DVR recorder', group: 'security', keywords: 'nvr dvr recorder cctv storage',
        d: `${rounded(2, 8, 20, 8, 1.5)} M5 12h7`,
        fill: `${circle(16, 12, 1)} ${circle(19, 12, 1)}`,
    },
    'access-control': {
        label: 'Door access control', group: 'security', keywords: 'door access control keypad entry',
        d: `M4 21V3h10v18 M2 21h14 ${rounded(17, 7, 5, 9, 1)}`,
        fill: `${circle(11.5, 12, 0.9)} ${circle(18.7, 9.6, 0.55)} ${circle(20.3, 9.6, 0.55)} ${circle(18.7, 11.6, 0.55)} ${circle(20.3, 11.6, 0.55)} ${circle(18.7, 13.6, 0.55)} ${circle(20.3, 13.6, 0.55)}`,
    },
    alarm: {
        label: 'Alarm / siren', group: 'security', keywords: 'alarm siren alert security',
        d: 'M6 18v-5a6 6 0 0 1 12 0v5 M4 18h16v3H4z M12 2v2 M3.5 6l1.5 1.5 M20.5 6L19 7.5 M10 13a2 2 0 0 1 2-2',
    },
    lock: {
        label: 'Lock', group: 'security', keywords: 'lock secure padlock',
        d: 'M5 11h14v10H5z M8 11V7a4 4 0 0 1 8 0v4',
        fill: circle(12, 16, 1.2),
    },
    shield: {
        label: 'Shield', group: 'security', keywords: 'shield protection security safe',
        d: 'M12 3l8 3v6c0 4.5-3.4 8.3-8 9-4.6-.7-8-4.5-8-9V6z M9 12l2 2 4-4',
    },

    // ── Network & IT ──────────────────────────────────────────────────────────
    router: {
        label: 'Router', group: 'network', keywords: 'router network internet modem',
        d: `${rounded(2, 13, 20, 6, 1.5)} M7 13L5.5 6 M17 13l1.5-7 M14 16h4`,
        fill: `${circle(6, 16, 1)} ${circle(9.5, 16, 1)}`,
    },
    switch: {
        label: 'Network switch', group: 'network', keywords: 'switch network ports lan',
        d: `${rounded(2, 8, 20, 8, 1.5)} ${rect(5, 11, 2, 2)} ${rect(9, 11, 2, 2)} ${rect(13, 11, 2, 2)} ${rect(17, 11, 2, 2)}`,
    },
    'access-point': {
        label: 'Wi-Fi access point', group: 'network', keywords: 'wifi access point wireless ap',
        d: 'M3 18a9 3 0 0 0 18 0a9 3 0 0 0-18 0 M8.5 11a5 5 0 0 1 7 0 M6 8.5a8.5 8.5 0 0 1 12 0',
        fill: circle(12, 13.5, 1),
    },
    server: {
        label: 'Server', group: 'network', keywords: 'server rack computer data',
        d: `${rounded(4, 3, 16, 5, 1)} ${rounded(4, 9.5, 16, 5, 1)} ${rounded(4, 16, 16, 5, 1)} M11 5.5h6 M11 12h6 M11 18.5h6`,
        fill: `${circle(7, 5.5, 0.9)} ${circle(7, 12, 0.9)} ${circle(7, 18.5, 0.9)}`,
    },
    database: {
        label: 'Database', group: 'network', keywords: 'database storage sql data',
        d: 'M4 6c0-1.7 3.6-3 8-3s8 1.3 8 3-3.6 3-8 3-8-1.3-8-3z M4 6v12c0 1.7 3.6 3 8 3s8-1.3 8-3V6 M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3',
    },
    cloud: {
        label: 'Cloud', group: 'network', keywords: 'cloud internet azure online',
        d: 'M7 19a4.5 4.5 0 0 1-.6-8.96A6 6 0 0 1 17.8 9.2a4.9 4.9 0 0 1-.3 9.8z',
    },
    firewall: {
        label: 'Firewall', group: 'network', keywords: 'firewall security wall network',
        d: 'M3 5h18v14H3z M3 9.7h18 M3 14.3h18 M9 5v4.7 M15 5v4.7 M6 9.7v4.6 M12 9.7v4.6 M18 9.7v4.6 M9 14.3V19 M15 14.3V19',
    },
    wifi: {
        label: 'Wi-Fi signal', group: 'network', keywords: 'wifi wireless signal internet',
        d: 'M2 9a15 15 0 0 1 20 0 M5 12.5a10 10 0 0 1 14 0 M8.5 16a5 5 0 0 1 7 0',
        fill: circle(12, 19.5, 1.2),
    },
    network: {
        label: 'Network', group: 'network', keywords: 'network topology nodes connection',
        d: `${circle(12, 5, 2.5)} ${circle(5, 19, 2.5)} ${circle(19, 19, 2.5)} M10.9 7.2L6.1 16.8 M13.1 7.2l4.8 9.6 M7.5 19h9`,
    },

    // ── Devices & POS ─────────────────────────────────────────────────────────
    desktop: {
        label: 'Desktop computer', group: 'devices', keywords: 'desktop computer monitor pc workstation',
        d: 'M3 4h18v12H3z M8 20h8 M12 16v4',
    },
    laptop: {
        label: 'Laptop', group: 'devices', keywords: 'laptop notebook computer',
        d: 'M5 5h14v10H5z M3 15h18l1.5 4h-21z',
    },
    tablet: {
        label: 'Tablet', group: 'devices', keywords: 'tablet ipad device',
        d: `${rounded(5, 2, 14, 20, 2)} M11 18.5h2`,
    },
    phone: {
        label: 'Mobile phone', group: 'devices', keywords: 'phone mobile smartphone cellphone',
        d: `${rounded(7, 2, 10, 20, 2)} M11 18.5h2`,
    },
    printer: {
        label: 'Printer', group: 'devices', keywords: 'printer print office',
        d: 'M7 9V3h10v6 M7 18H5a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2 M7 14h10v7H7z',
    },
    'receipt-printer': {
        label: 'Receipt printer', group: 'devices', keywords: 'receipt printer pos thermal',
        d: 'M4 11h16v9H4z M7 11V3h10v8 M9 6h6 M9 8.5h4 M7 15h10',
    },
    'pos-terminal': {
        label: 'POS terminal', group: 'devices', keywords: 'pos terminal cashier register point of sale',
        d: 'M5 3h14v9H5z M10 12l-1 3h6l-1-3 M3 15h18v6H3z M9 18h6',
    },
    'card-terminal': {
        label: 'Card terminal', group: 'devices', keywords: 'card terminal payment credit debit edc',
        d: `${rounded(6, 2, 12, 20, 2)} M8.5 4.5h7v5h-7z`,
        fill: [9.5, 12, 14.5].flatMap((x) => [13, 15.7, 18.4].map((y) => circle(x, y, 0.8))).join(' '),
    },
    'cash-drawer': {
        label: 'Cash drawer', group: 'devices', keywords: 'cash drawer money till',
        d: 'M2 12h20v8H2z M2 12l2.5-4h15L22 12 M10 16h4',
    },
    barcode: {
        label: 'Barcode', group: 'devices', keywords: 'barcode scan sku label',
        d: '',
        fill: `${rect(4, 5, 1.4, 14)} ${rect(6.6, 5, 0.8, 14)} ${rect(8.6, 5, 2, 14)} ${rect(12, 5, 0.8, 14)} ${rect(14, 5, 1.6, 14)} ${rect(16.8, 5, 0.8, 14)} ${rect(18.6, 5, 1.4, 14)}`,
    },
    tv: {
        label: 'TV / display', group: 'devices', keywords: 'tv display screen monitor signage',
        d: 'M2 5h20v12H2z M8 21h8 M12 17v4',
    },
    ups: {
        label: 'UPS / battery', group: 'devices', keywords: 'ups battery power backup',
        d: 'M3 7h16v10H3z M19 10h2v4h-2 M8 12h5 M10.5 9.5v5',
    },
    headset: {
        label: 'Support headset', group: 'devices', keywords: 'headset support helpdesk call',
        d: 'M4 15v-3a8 8 0 0 1 16 0v3 M4 15h3v5H4z M17 15h3v5h-3z M20 20c0 1.5-1.5 2-3 2h-3',
    },

    // ── Places & people ───────────────────────────────────────────────────────
    store: {
        label: 'Store', group: 'places', keywords: 'store shop branch retail outlet',
        d: 'M3 9l1.5-5h15L21 9 M3 9a3 3 0 0 0 6 0a3 3 0 0 0 6 0a3 3 0 0 0 6 0 M5 12v9h14v-9 M10 21v-5h4v5',
    },
    warehouse: {
        label: 'Warehouse', group: 'places', keywords: 'warehouse storage depot commissary',
        d: 'M3 21V9l9-5 9 5v12 M7 21v-8h10v8 M7 17h10',
    },
    office: {
        label: 'Office building', group: 'places', keywords: 'office building head office corporate',
        d: 'M4 21V3h11v18 M15 9h5v12 M2 21h20 M7 7h2 M11 7h2 M7 11h2 M11 11h2 M7 15h2 M11 15h2',
    },
    person: {
        label: 'Person', group: 'places', keywords: 'person user staff employee',
        d: `${circle(12, 8, 4)} M4 21a8 8 0 0 1 16 0`,
    },
    team: {
        label: 'Team', group: 'places', keywords: 'team people group users',
        d: `${circle(9, 8, 3.5)} M2 20a7 7 0 0 1 14 0 M16 4.5a3.5 3.5 0 0 1 0 7 M18 13.5a7 7 0 0 1 4 6.5`,
    },
    truck: {
        label: 'Delivery truck', group: 'places', keywords: 'truck delivery logistics vehicle',
        d: `M2 6h12v10H2z M14 9h4l4 4v3h-8 ${circle(6, 18, 2)} ${circle(18, 18, 2)}`,
    },
    package: {
        label: 'Package', group: 'places', keywords: 'package box parcel stock inventory',
        d: 'M3 7l9-4 9 4v10l-9 4-9-4z M3 7l9 4 9-4 M12 11v10',
    },
    document: {
        label: 'Document', group: 'places', keywords: 'document file paper form',
        d: 'M6 2h8l4 4v16H6z M14 2v4h4 M9 12h6 M9 16h6',
    },
    ticket: {
        label: 'Ticket', group: 'places', keywords: 'ticket helpdesk request issue',
        d: 'M3 7h18v3a2 2 0 0 0 0 4v3H3v-3a2 2 0 0 0 0-4z M14 7v2 M14 11v2 M14 15v2',
    },
    calendar: {
        label: 'Calendar', group: 'places', keywords: 'calendar date schedule',
        d: 'M4 5h16v16H4z M4 10h16 M8 3v4 M16 3v4',
    },
    location: {
        label: 'Location pin', group: 'places', keywords: 'location pin map place address',
        d: `M12 21s-7-6.2-7-11a7 7 0 0 1 14 0c0 4.8-7 11-7 11z ${circle(12, 10, 2.5)}`,
    },

    // ── Symbols ───────────────────────────────────────────────────────────────
    warning: {
        label: 'Warning', group: 'symbols', keywords: 'warning caution alert danger',
        d: 'M12 3l10 18H2z M12 10v5',
        fill: circle(12, 18, 1),
    },
    check: {
        label: 'Done', group: 'symbols', keywords: 'check done ok approved yes',
        d: `${circle(12, 12, 9)} M8 12l3 3 5-6`,
    },
    cross: {
        label: 'Not OK', group: 'symbols', keywords: 'cross no rejected error fail',
        d: `${circle(12, 12, 9)} M9 9l6 6 M15 9l-6 6`,
    },
    info: {
        label: 'Info', group: 'symbols', keywords: 'info information note',
        d: `${circle(12, 12, 9)} M12 11v6`,
        fill: circle(12, 7.6, 1),
    },
    question: {
        label: 'Question', group: 'symbols', keywords: 'question help unknown',
        d: `${circle(12, 12, 9)} M9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.6.3-1 .9-1 1.6V14`,
        fill: circle(12, 17, 1),
    },
    star: {
        label: 'Star', group: 'symbols', keywords: 'star favourite important',
        d: 'M12 3l2.8 5.7 6.2.9-4.5 4.4 1.1 6.2L12 17.3l-5.6 2.9 1.1-6.2L3 9.6l6.2-.9z',
    },
    flag: {
        label: 'Flag', group: 'symbols', keywords: 'flag milestone mark',
        d: 'M5 21V4 M5 4h13l-2.5 4L18 12H5',
    },
    idea: {
        label: 'Idea', group: 'symbols', keywords: 'idea lightbulb tip',
        d: 'M9 18h6 M10 21h4 M12 3a6 6 0 0 0-3.5 10.9V16h7v-2.1A6 6 0 0 0 12 3z',
    },
    clock: {
        label: 'Clock', group: 'symbols', keywords: 'clock time sla deadline',
        d: `${circle(12, 12, 9)} M12 7v5l3 2`,
    },
    power: {
        label: 'Power plug', group: 'symbols', keywords: 'power plug electricity',
        d: 'M9 2v5 M15 2v5 M6 7h12v4a6 6 0 0 1-12 0z M12 17v5',
    },
    'arrow-right': {
        label: 'Arrow', group: 'symbols', keywords: 'arrow next direction flow',
        d: 'M4 12h15 M13 6l6 6-6 6',
    },
};

export const iconOf = (key) => CANVAS_ICONS[key] || null;
