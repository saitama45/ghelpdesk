import { createSceneDocument, uid } from '@/lib/sceneDocument';
import { makeEdge, makeNode } from './shapes';

// Starter content for "New diagram". Each returns a fresh scene (new ids every time).

export const newDiagramPage = (number) => ({ id: uid('p'), name: `Page ${number}`, background: '#ffffff', grid: true, elements: [] });

const PAGE_OPTIONS = {
    newPage: newDiagramPage,
    normalizePage: (page) => ({
        name: page.name || 'Page',
        background: page.background || '#ffffff',
        grid: page.grid !== false,
    }),
};

export const createDiagramDocument = (scene) => createSceneDocument(scene, PAGE_OPTIONS);

const connect = (from, to, sourcePort, targetPort, extra = {}) => makeEdge({ source: from.id, sourcePort, target: to.id, targetPort, ...extra });

const flowchart = () => {
    const start = makeNode('terminator', 160, 20, { text: 'Start', fill: '#d5e8d4', stroke: '#82b366' });
    const step = makeNode('process', 160, 120, { text: 'Receive request' });
    const check = makeNode('decision', 160, 230, { text: 'Approved?', fill: '#fff2cc', stroke: '#d6b656' });
    const work = makeNode('process', 160, 370, { text: 'Do the work' });
    const fix = makeNode('process', 380, 240, { text: 'Ask for changes', fill: '#f8cecc', stroke: '#b85450' });
    const end = makeNode('terminator', 160, 480, { text: 'End', fill: '#d5e8d4', stroke: '#82b366' });
    return [
        start, step, check, work, fix, end,
        connect(start, step, 's', 'n'),
        connect(step, check, 's', 'n'),
        connect(check, work, 's', 'n', { label: 'Yes' }),
        connect(check, fix, 'e', 'w', { label: 'No' }),
        connect(fix, step, 'n', 'e'),
        connect(work, end, 's', 'n'),
    ];
};

const swimlane = () => {
    const lanes = ['Requester', 'Team lead', 'Service desk'].map((text, index) => makeNode('lane', 0, index * 150, { text, w: 760, h: 150 }));
    const submit = makeNode('rounded', 70, 45, { text: 'Submit request' });
    const review = makeNode('decision', 250, 185, { text: 'OK?', w: 100, h: 80 });
    const resubmit = makeNode('rounded', 240, 45, { text: 'Revise' });
    const assign = makeNode('rounded', 430, 345, { text: 'Assign technician' });
    const resolve = makeNode('rounded', 600, 345, { text: 'Resolve & close' });
    return [
        ...lanes,
        submit, review, resubmit, assign, resolve,
        connect(submit, review, 's', 'w'),
        connect(review, resubmit, 'n', 's', { label: 'No' }),
        connect(resubmit, submit, 'w', 'e'),
        connect(review, assign, 'e', 'n', { label: 'Yes' }),
        connect(assign, resolve, 'e', 'w'),
    ];
};

const orgChart = () => {
    const head = makeNode('rounded', 300, 20, { text: 'Department Head', fill: '#dae8fc', stroke: '#6c8ebf', bold: true });
    const managers = ['Operations', 'Finance', 'Technology'].map((text, index) => makeNode('rounded', 60 + index * 240, 150, { text: `${text} Manager`, fill: '#e1d5e7', stroke: '#9673a6' }));
    const staff = managers.flatMap((manager, index) => [0, 1].map((n) => makeNode('rectangle', 60 + index * 240, 270 + n * 80, { text: `Team member ${n + 1}`, fontSize: 13 })));
    return [
        head, ...managers, ...staff,
        ...managers.map((manager) => connect(head, manager, 's', 'n', { endArrow: 'none' })),
        ...staff.map((member, index) => connect(managers[Math.floor(index / 2)], member, 'w', 'w', { endArrow: 'none', points: [managers[Math.floor(index / 2)].x - 20, member.y + member.h / 2] })),
    ];
};

export const DIAGRAM_TEMPLATES = [
    { id: 'blank', label: 'Blank', description: 'An empty page', build: () => [] },
    { id: 'flowchart', label: 'Flowchart', description: 'Start, steps, a decision and a loop', build: flowchart },
    { id: 'swimlane', label: 'Swimlane process', description: 'Who does what, lane by lane', build: swimlane },
    { id: 'orgchart', label: 'Org chart', description: 'A head, managers and their teams', build: orgChart },
];

export const templateScene = (id) => {
    const template = DIAGRAM_TEMPLATES.find((item) => item.id === id) || DIAGRAM_TEMPLATES[0];
    return { version: 1, pages: [{ ...newDiagramPage(1), elements: template.build() }] };
};
