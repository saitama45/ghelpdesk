import { newPage, STICKY_COLORS, uid } from './whiteboard';

// Starter content for "New whiteboard" (standalone whiteboards). Fresh ids every call.

const text = (x, y, value, size = 28, color = '#111827') => ({ id: uid(), type: 'text', x, y, text: value, color, size });
const sticky = (x, y, value, color = STICKY_COLORS[0]) => ({ id: uid(), type: 'sticky', x, y, w: 200, h: 160, text: value, color });
const frame = (x1, y1, x2, y2, color = '#cbd5e1') => ({ id: uid(), type: 'rect', x1, y1, x2, y2, color, size: 2, fill: null });

const retrospective = () => {
    const columns = [
        { title: 'Went well', color: STICKY_COLORS[3], note: 'Fast ticket turnaround' },
        { title: 'To improve', color: STICKY_COLORS[1], note: 'Handover between shifts' },
        { title: 'Actions', color: STICKY_COLORS[4], note: 'Owner + due date' },
    ];
    return columns.flatMap((column, index) => {
        const x = index * 300;
        return [
            frame(x, 0, x + 270, 620),
            text(x + 20, 20, column.title),
            sticky(x + 35, 90, column.note, column.color),
        ];
    });
};

const brainstorm = () => {
    const center = { x: 500, y: 330 };
    const topic = { id: uid(), type: 'ellipse', x1: center.x - 130, y1: center.y - 60, x2: center.x + 130, y2: center.y + 60, color: '#4f46e5', size: 4, fill: '#4f46e533' };
    const ideas = Array.from({ length: 6 }, (_, index) => {
        const angle = (index / 6) * Math.PI * 2 - Math.PI / 2;
        const x = Math.round(center.x + Math.cos(angle) * 330 - 100);
        const y = Math.round(center.y + Math.sin(angle) * 250 - 80);
        return { note: sticky(x, y, `Idea ${index + 1}`, STICKY_COLORS[index % STICKY_COLORS.length]), angle };
    });
    const links = ideas.map(({ angle }) => ({
        id: uid(),
        type: 'line',
        x1: Math.round(center.x + Math.cos(angle) * 135),
        y1: Math.round(center.y + Math.sin(angle) * 65),
        x2: Math.round(center.x + Math.cos(angle) * 230),
        y2: Math.round(center.y + Math.sin(angle) * 170),
        color: '#94a3b8',
        size: 2,
    }));
    return [topic, text(center.x - 52, center.y - 18, 'Topic', 30, '#312e81'), ...links, ...ideas.map(({ note }) => note)];
};

export const WHITEBOARD_TEMPLATES = [
    { id: 'blank', label: 'Blank', description: 'An empty dotted page', build: () => [] },
    { id: 'retro', label: 'Retrospective', description: 'Went well · To improve · Actions', build: retrospective },
    { id: 'brainstorm', label: 'Brainstorm', description: 'A topic with ideas around it', build: brainstorm },
];

export const whiteboardTemplateScene = (id) => {
    const template = WHITEBOARD_TEMPLATES.find((item) => item.id === id) || WHITEBOARD_TEMPLATES[0];
    return { version: 1, pages: [{ ...newPage(1), elements: template.build() }] };
};
