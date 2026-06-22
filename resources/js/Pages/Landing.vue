<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { ref, onMounted, onBeforeUnmount } from 'vue';

defineProps({
    canLogin: {
        type: Boolean,
        default: true,
    },
});

const year = new Date().getFullYear();
const mobileOpen = ref(false);
const scrolled = ref(false);

// The nine modules featured on the brand graphic.
const modules = [
    {
        name: 'Dashboard',
        desc: 'A real-time command center — KPIs, activity feeds, and insights at a glance.',
        color: 'indigo',
        path: 'M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z',
    },
    {
        name: 'Project Tracker',
        desc: 'Plan, assign, and ship work with boards, milestones, and Gantt timelines.',
        color: 'blue',
        path: 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01',
    },
    {
        name: 'Services',
        desc: 'Streamlined service requests and ticketing with built-in SLA tracking.',
        color: 'violet',
        path: 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z',
        extraPath: 'M15 12a3 3 0 11-6 0 3 3 0 016 0z',
    },
    {
        name: 'Inventory',
        desc: 'Track assets, stock, and movement across every location in real time.',
        color: 'amber',
        path: 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4',
    },
    {
        name: 'Monitoring',
        desc: 'Keep watch over connectivity, compliance, and operations company-wide.',
        color: 'cyan',
        path: 'M15 10l4.553-2.069A1 1 0 0121 8.82v6.36a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z',
    },
    {
        name: 'Administrative',
        desc: 'Manage users, roles, departments, and access from one secure place.',
        color: 'rose',
        path: 'M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z',
    },
    {
        name: 'Reports',
        desc: 'Generate actionable reports and export insights whenever you need them.',
        color: 'emerald',
        path: 'M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
    },
    {
        name: 'References',
        desc: 'A single source of truth — knowledge base, categories, and master data.',
        color: 'sky',
        path: 'M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253',
    },
    {
        name: 'Scheduling',
        desc: 'Coordinate schedules, trips, and attendance with effortless visibility.',
        color: 'teal',
        path: 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z',
    },
];

const stats = [
    { value: '9+', label: 'Integrated Modules' },
    { value: '1', label: 'Unified Platform' },
    { value: '24/7', label: 'Always Available' },
    { value: '100%', label: 'Built for TGI' },
];

const values = [
    {
        title: 'Collaborate',
        desc: 'Work together seamlessly across teams, departments, and locations.',
        color: 'blue',
        path: 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z',
    },
    {
        title: 'Manage',
        desc: 'Oversee projects, services, and operations from a single dashboard.',
        color: 'indigo',
        path: 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z',
    },
    {
        title: 'Monitor',
        desc: 'Stay informed with real-time insights and live operational updates.',
        color: 'cyan',
        path: 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z',
    },
    {
        title: 'Secure',
        desc: 'Enterprise-grade security keeps your data protected at every layer.',
        color: 'emerald',
        path: 'M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z',
    },
];

// Elegant tones for gradients and accents
const tone = {
    indigo: { grad: 'from-indigo-400 to-indigo-600', text: 'text-indigo-300', dot: 'bg-indigo-400', border: 'border-indigo-500/20' },
    blue: { grad: 'from-blue-400 to-blue-600', text: 'text-blue-300', dot: 'bg-blue-400', border: 'border-blue-500/20' },
    violet: { grad: 'from-violet-400 to-violet-600', text: 'text-violet-300', dot: 'bg-violet-400', border: 'border-violet-500/20' },
    amber: { grad: 'from-amber-400 to-orange-500', text: 'text-amber-300', dot: 'bg-amber-400', border: 'border-amber-500/20' },
    cyan: { grad: 'from-cyan-400 to-sky-500', text: 'text-cyan-300', dot: 'bg-cyan-400', border: 'border-cyan-500/20' },
    rose: { grad: 'from-rose-400 to-pink-500', text: 'text-rose-300', dot: 'bg-rose-400', border: 'border-rose-500/20' },
    emerald: { grad: 'from-emerald-400 to-green-500', text: 'text-emerald-300', dot: 'bg-emerald-400', border: 'border-emerald-500/20' },
    sky: { grad: 'from-sky-400 to-blue-500', text: 'text-sky-300', dot: 'bg-sky-400', border: 'border-sky-500/20' },
    teal: { grad: 'from-teal-400 to-emerald-500', text: 'text-teal-300', dot: 'bg-teal-400', border: 'border-teal-500/20' },
};

const navLinks = [
    { label: 'Platform', target: 'platform' },
    { label: 'Modules', target: 'modules' },
    { label: 'About', target: 'about' },
    { label: 'Contact', target: 'contact' },
];

const scrollTo = (id) => {
    mobileOpen.value = false;
    const el = document.getElementById(id);
    // Adjust scroll to account for the sticky header height (80px)
    if (el) {
        const y = el.getBoundingClientRect().top + window.scrollY - 80;
        window.scrollTo({ top: y, behavior: 'smooth' });
    }
};

let observer = null;
const onScroll = () => { scrolled.value = window.scrollY > 16; };

onMounted(() => {
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();

    const els = document.querySelectorAll('[data-reveal]');
    observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (entry.isIntersecting) {
                entry.target.classList.add('reveal-in');
                observer.unobserve(entry.target);
            }
        });
    }, { threshold: 0.1, rootMargin: '0px 0px -40px 0px' });
    els.forEach((el) => observer.observe(el));

    // Failsafe
    setTimeout(() => els.forEach((el) => el.classList.add('reveal-in')), 1500);
});

onBeforeUnmount(() => {
    window.removeEventListener('scroll', onScroll);
    if (observer) observer.disconnect();
});
</script>

<template>
    <Head title="LINK HUB — The Digital Office of TGI">
        <meta name="description" content="LINK HUB is the digital office of TGI — one platform that unifies projects, services, inventory, monitoring, and reporting for every TGI team." />
    </Head>

    <div class="relative min-h-screen bg-[#02040a] text-slate-300 antialiased selection:bg-indigo-500/40 selection:text-white font-sans overflow-x-hidden">
        
        <!-- Subtle Ambient Glow -->
        <div class="pointer-events-none fixed inset-0 z-0 flex justify-center">
            <div class="absolute -top-[20%] w-[80%] h-[50%] bg-indigo-500/10 blur-[160px] rounded-full"></div>
            <div class="absolute top-[40%] -right-[10%] w-[50%] h-[50%] bg-blue-500/5 blur-[160px] rounded-full"></div>
        </div>

        <div class="relative z-10 pt-[80px]">
            <!-- ============ SOLID NAVBAR (No transparency/overlap) ============ -->
            <header
                class="fixed top-0 inset-x-0 z-[100] bg-[#050814] transition-all duration-300 border-b"
                :class="scrolled ? 'border-white/10 shadow-xl shadow-black/60 py-3' : 'border-transparent py-5'"
            >
                <nav class="mx-auto flex max-w-7xl items-center justify-between px-6 lg:px-8">
                    <button class="flex items-center gap-3 group" @click="scrollTo('home')">
                        <div class="relative flex h-10 items-center justify-center transition-transform group-hover:scale-105 bg-[#ffffff] rounded-lg px-2 py-1 shadow-sm">
                            <img src="/images/company_logo.png" alt="LINK HUB" class="relative h-8 object-contain" />
                        </div>
                        <span class="flex flex-col items-start leading-none text-left">
                            <span class="text-lg font-black tracking-tight text-white">LINK <span class="text-indigo-400">HUB</span></span>
                            <span class="text-[9px] font-bold uppercase tracking-[0.2em] text-slate-500 mt-1">Digital Office</span>
                        </span>
                    </button>

                    <div class="hidden items-center gap-1 md:flex bg-white/[0.02] p-1.5 rounded-full border border-white/5">
                        <button
                            v-for="link in navLinks"
                            :key="link.target"
                            @click="scrollTo(link.target)"
                            class="rounded-full px-5 py-2 text-sm font-semibold text-slate-400 transition-all hover:bg-white/10 hover:text-white hover:shadow-sm"
                        >
                            {{ link.label }}
                        </button>
                    </div>

                    <div class="flex items-center gap-4">
                        <Link
                            v-if="canLogin"
                            :href="route('login')"
                            class="hidden items-center gap-2 rounded-full bg-[#ffffff] px-6 py-2.5 text-sm font-bold text-[#050814] shadow-lg shadow-white/10 transition-all hover:bg-slate-200 hover:shadow-white/20 active:scale-95 sm:inline-flex"
                        >
                            Log In
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M14 5l7 7m0 0l-7 7m7-7H3" /></svg>
                        </Link>

                        <button
                            class="inline-flex h-10 w-10 items-center justify-center rounded-xl text-slate-300 hover:bg-white/10 md:hidden"
                            @click="mobileOpen = !mobileOpen"
                            aria-label="Toggle menu"
                        >
                            <svg v-if="!mobileOpen" class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" /></svg>
                            <svg v-else class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                        </button>
                    </div>
                </nav>

                <!-- Mobile Menu -->
                <transition
                    enter-active-class="transition duration-200 ease-out" enter-from-class="-translate-y-4 opacity-0" enter-to-class="translate-y-0 opacity-100"
                    leave-active-class="transition duration-150 ease-in" leave-from-class="translate-y-0 opacity-100" leave-to-class="-translate-y-4 opacity-0"
                >
                    <div v-if="mobileOpen" class="absolute left-0 right-0 top-full border-b border-white/10 bg-[#050814] px-4 pb-6 pt-4 md:hidden shadow-2xl shadow-black">
                        <button
                            v-for="link in navLinks"
                            :key="link.target"
                            @click="scrollTo(link.target)"
                            class="block w-full rounded-xl px-4 py-3.5 text-left text-sm font-bold text-slate-300 hover:bg-white/5 hover:text-white mb-1"
                        >
                            {{ link.label }}
                        </button>
                        <Link
                            v-if="canLogin"
                            :href="route('login')"
                            class="mt-4 flex w-full items-center justify-center gap-2 rounded-xl bg-[#ffffff] px-5 py-3.5 text-sm font-bold text-[#050814] shadow-lg shadow-white/10"
                        >
                            Log In Securely
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M14 5l7 7m0 0l-7 7m7-7H3" /></svg>
                        </Link>
                    </div>
                </transition>
            </header>

            <!-- ============ HERO ============ -->
            <section id="home" class="relative px-6 pb-24 pt-20 lg:px-8 lg:pt-32 min-h-[calc(100vh-5rem)] flex items-center">
                <div class="mx-auto max-w-7xl text-center flex flex-col items-center">
                    <span class="inline-flex items-center gap-2 rounded-full border border-indigo-500/30 bg-indigo-500/10 px-4 py-1.5 text-xs font-bold uppercase tracking-[0.2em] text-indigo-300 backdrop-blur-md mb-8 animate-fade-in-up">
                        <span class="relative flex h-2 w-2">
                            <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-indigo-400 opacity-75"></span>
                            <span class="relative inline-flex h-2 w-2 rounded-full bg-indigo-400"></span>
                        </span>
                        Enterprise Digital Office
                    </span>

                    <h1 class="text-5xl font-black leading-[1.1] tracking-tight text-white sm:text-7xl lg:text-8xl animate-fade-in-up drop-shadow-2xl" style="animation-delay: 100ms;">
                        The Workspace of
                        <br class="hidden sm:block" />
                        <span style="background: linear-gradient(to right, #818cf8, #67e8f9, #60a5fa); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; color: transparent; display: inline-block;">Tomorrow</span>
                    </h1>

                    <p class="mx-auto mt-8 max-w-2xl text-lg font-medium text-slate-400 sm:text-xl animate-fade-in-up" style="animation-delay: 200ms;">
                        One intelligent platform unifying projects, services, inventory, and reporting. Designed exclusively for TGI to keep every team moving forward, together.
                    </p>

                    <div class="mt-10 flex flex-col items-center gap-4 sm:flex-row justify-center animate-fade-in-up" style="animation-delay: 300ms;">
                        <Link
                            v-if="canLogin"
                            :href="route('login')"
                            class="group relative inline-flex items-center justify-center gap-3 rounded-full border border-transparent bg-[#ffffff] px-8 py-4 text-base font-black text-[#050814] shadow-[0_0_40px_-10px_rgba(255,255,255,0.3)] transition-all hover:scale-105 hover:shadow-[0_0_60px_-15px_rgba(255,255,255,0.5)] active:scale-95"
                        >
                            Access Portal
                            <svg class="h-5 w-5 transition-transform group-hover:translate-x-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M14 5l7 7m0 0l-7 7m7-7H3" /></svg>
                        </Link>
                        <button
                            @click="scrollTo('modules')"
                            class="inline-flex items-center justify-center gap-3 rounded-full border border-white/10 bg-white/5 px-8 py-4 text-base font-bold text-white backdrop-blur-md transition-all hover:bg-white/10 hover:border-white/20 active:scale-95"
                        >
                            Explore Platform
                        </button>
                    </div>

                    <div class="mt-10 flex flex-wrap items-center justify-center gap-8 text-sm font-semibold text-slate-500 animate-fade-in-up" style="animation-delay: 400ms;">
                        <span class="flex items-center gap-2"><svg class="h-5 w-5 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg> Enterprise Security</span>
                        <span class="flex items-center gap-2"><svg class="h-5 w-5 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 10V3L4 14h7v7l9-11h-7z" /></svg> Real-time Sync</span>
                        <span class="flex items-center gap-2"><svg class="h-5 w-5 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" /></svg> SSO Integrated</span>
                    </div>
                </div>
            </section>

            <!-- ============ PLATFORM / VALUES ============ -->
            <section id="platform" class="relative px-6 pt-12 pb-24 sm:px-8 lg:pt-16 lg:pb-32 bg-[#030612] border-t border-white/5">
                <div class="mx-auto max-w-7xl">
                    <div class="text-center max-w-3xl mx-auto mb-20" data-reveal>
                        <h2 class="text-sm font-black uppercase tracking-[0.2em] text-indigo-500 mb-4">The Foundation</h2>
                        <h3 class="text-3xl font-black text-white sm:text-5xl tracking-tight leading-tight">Built to amplify your team's potential</h3>
                    </div>

                    <div class="grid grid-cols-1 gap-6 md:grid-cols-2 lg:grid-cols-4">
                        <div
                            v-for="(v, i) in values"
                            :key="v.title"
                            data-reveal
                            :style="{ transitionDelay: `${i * 100}ms` }"
                            class="group relative overflow-hidden rounded-[2rem] border border-white/5 bg-[#070b1a] p-8 transition-all hover:-translate-y-2 hover:border-white/10 hover:shadow-2xl hover:shadow-indigo-500/10"
                        >
                            <div class="absolute top-0 left-0 w-full h-1 bg-gradient-to-r opacity-0 transition-opacity group-hover:opacity-100" :class="tone[v.color].grad"></div>
                            
                            <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-[#0a1024] border border-white/5 mb-6 group-hover:scale-110 transition-transform duration-300">
                                <svg class="h-7 w-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="v.path" /></svg>
                            </div>
                            <h4 class="text-xl font-bold text-white mb-3">{{ v.title }}</h4>
                            <p class="text-sm leading-relaxed text-slate-400">{{ v.desc }}</p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ============ MODULES ============ -->
            <section id="modules" class="relative px-6 pt-12 pb-24 sm:px-8 lg:pt-16 lg:pb-32 border-t border-white/5">
                <div class="mx-auto max-w-7xl">
                    <div class="flex flex-col md:flex-row md:items-end justify-between gap-8 mb-20" data-reveal>
                        <div class="max-w-2xl">
                            <h2 class="text-sm font-black uppercase tracking-[0.2em] text-blue-500 mb-4">Core Capabilities</h2>
                            <h3 class="text-3xl font-black text-white sm:text-5xl tracking-tight leading-tight">Everything you need, nothing you don't</h3>
                        </div>
                        <p class="text-lg text-slate-400 max-w-md">Nine purpose-built modules designed to work perfectly together, eliminating silos and friction.</p>
                    </div>

                    <div class="grid grid-cols-1 gap-5 md:grid-cols-2 lg:grid-cols-3">
                        <div
                            v-for="(m, i) in modules"
                            :key="m.name"
                            data-reveal
                            :style="{ transitionDelay: `${(i % 3) * 100}ms` }"
                            class="group relative flex flex-col rounded-[2rem] border border-white/5 bg-[#050814] p-8 transition-all hover:bg-[#070b1a] hover:border-white/10"
                        >
                            <div class="flex items-center gap-4 mb-6">
                                <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-gradient-to-br shadow-lg transition-transform group-hover:scale-110" :class="tone[m.color].grad">
                                    <svg class="h-6 w-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="m.path" />
                                        <path v-if="m.extraPath" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="m.extraPath" />
                                    </svg>
                                </div>
                                <h4 class="text-lg font-bold text-white">{{ m.name }}</h4>
                            </div>
                            <p class="text-sm leading-relaxed text-slate-400 flex-grow">{{ m.desc }}</p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ============ STATS / ABOUT ============ -->
            <section id="about" class="relative px-6 pt-12 pb-24 sm:px-8 lg:pt-16 lg:pb-32 bg-[#030612] border-t border-white/5">
                <div class="mx-auto max-w-7xl">
                    <div class="grid lg:grid-cols-2 gap-16 items-center">
                        <div data-reveal>
                            <h2 class="text-sm font-black uppercase tracking-[0.2em] text-cyan-500 mb-4">The Impact</h2>
                            <h3 class="text-3xl font-black text-white sm:text-5xl tracking-tight leading-tight mb-6">Empowering the entire organization</h3>
                            <p class="text-lg text-slate-400 leading-relaxed mb-8">
                                LINK HUB is more than software—it's the operational nervous system for TGI. By centralizing our most critical workflows, we ensure that every team from administration to the front lines has the context they need to excel.
                            </p>
                            <div class="grid grid-cols-2 gap-6">
                                <div v-for="(s, i) in stats" :key="s.label" class="border-l-2 pl-4" :class="tone.indigo.border">
                                    <div class="text-3xl font-black text-white">{{ s.value }}</div>
                                    <div class="text-sm font-medium text-slate-500 mt-1">{{ s.label }}</div>
                                </div>
                            </div>
                        </div>

                        <div class="relative" data-reveal>
                            <div class="absolute inset-0 bg-gradient-to-tr from-indigo-500/20 to-blue-500/20 rounded-[3rem] blur-2xl transform -rotate-3"></div>
                            <div class="relative rounded-[3rem] border border-white/10 bg-[#070b1a] p-10 shadow-2xl overflow-hidden">
                                <div class="absolute top-0 right-0 w-64 h-64 bg-indigo-500/10 blur-3xl rounded-full"></div>
                                <h4 class="text-2xl font-bold text-white mb-6 relative z-10">System Architecture</h4>
                                <div class="space-y-4 relative z-10">
                                    <div class="h-14 rounded-xl border border-white/5 bg-[#ffffff]/[0.02] flex items-center px-4 gap-4 transition-colors hover:bg-[#ffffff]/[0.05]">
                                        <div class="h-3 w-3 shrink-0 rounded-full bg-indigo-500 shadow-[0_0_10px_rgba(99,102,241,0.8)]"></div>
                                        <div class="flex flex-col">
                                            <span class="text-sm font-bold text-white leading-none">Cloud Infrastructure</span>
                                            <span class="text-[11px] text-slate-400 mt-1">High-availability redundant servers</span>
                                        </div>
                                    </div>
                                    <div class="h-14 rounded-xl border border-white/5 bg-[#ffffff]/[0.02] flex items-center px-4 gap-4 transition-colors hover:bg-[#ffffff]/[0.05]">
                                        <div class="h-3 w-3 shrink-0 rounded-full bg-cyan-500 shadow-[0_0_10px_rgba(6,182,212,0.8)]"></div>
                                        <div class="flex flex-col">
                                            <span class="text-sm font-bold text-white leading-none">Real-time Sync</span>
                                            <span class="text-[11px] text-slate-400 mt-1">WebSockets with zero-latency updates</span>
                                        </div>
                                    </div>
                                    <div class="h-14 rounded-xl border border-white/5 bg-[#ffffff]/[0.02] flex items-center px-4 gap-4 transition-colors hover:bg-[#ffffff]/[0.05]">
                                        <div class="h-3 w-3 shrink-0 rounded-full bg-emerald-500 shadow-[0_0_10px_rgba(16,185,129,0.8)]"></div>
                                        <div class="flex flex-col">
                                            <span class="text-sm font-bold text-white leading-none">End-to-End Encryption</span>
                                            <span class="text-[11px] text-slate-400 mt-1">AES-256 data protection at rest</span>
                                        </div>
                                    </div>
                                    <div class="h-14 rounded-xl border border-white/5 bg-[#ffffff]/[0.02] flex items-center px-4 gap-4 transition-colors hover:bg-[#ffffff]/[0.05]">
                                        <div class="h-3 w-3 shrink-0 rounded-full bg-amber-500 shadow-[0_0_10px_rgba(245,158,11,0.8)]"></div>
                                        <div class="flex flex-col">
                                            <span class="text-sm font-bold text-white leading-none">Role-Based Access</span>
                                            <span class="text-[11px] text-slate-400 mt-1">Granular enterprise permissions</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ============ CTA ============ -->
            <section id="contact" class="relative px-6 pt-12 pb-24 sm:px-8 lg:pt-16 lg:pb-32 border-t border-white/5">
                <div class="mx-auto max-w-5xl" data-reveal>
                    <div class="relative overflow-hidden rounded-[3rem] bg-gradient-to-br from-indigo-900 to-[#050814] border border-indigo-500/30 p-12 text-center shadow-2xl sm:p-20">
                        <div class="absolute top-0 left-1/2 -translate-x-1/2 w-[800px] h-[400px] bg-indigo-500/20 blur-[120px] rounded-full pointer-events-none"></div>
                        
                        <h2 class="relative z-10 text-4xl font-black tracking-tight text-white sm:text-6xl mb-6">Enter the Workspace</h2>
                        <p class="relative z-10 mx-auto max-w-2xl text-lg text-indigo-200/80 mb-10">
                            Log in with your authorized TGI credentials to securely access your dedicated portals, workflows, and tools.
                        </p>
                        
                        <div class="relative z-10 flex justify-center">
                            <Link
                                v-if="canLogin"
                                :href="route('login')"
                                class="group flex items-center gap-3 rounded-full bg-[#ffffff] px-10 py-5 text-lg font-black text-[#050814] shadow-[0_0_30px_-5px_rgba(255,255,255,0.3)] transition-all hover:scale-105 active:scale-95"
                            >
                                Secure Login
                                <svg class="h-5 w-5 transition-transform group-hover:translate-x-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M14 5l7 7m0 0l-7 7m7-7H3" /></svg>
                            </Link>
                        </div>
                        <p class="relative z-10 mt-8 text-sm font-medium text-indigo-400">Restricted to authorized personnel. Contact IT Support for access.</p>
                    </div>
                </div>
            </section>

            <!-- ============ FOOTER ============ -->
            <footer class="bg-[#02040a] border-t border-white/5 py-12">
                <div class="mx-auto max-w-7xl px-6 lg:px-8 flex flex-col md:flex-row items-center justify-between gap-6">
                    <div class="flex items-center gap-3">
                        <div class="bg-[#ffffff] rounded p-1">
                            <img src="/images/company_logo.png" alt="LINK HUB" class="h-6 object-contain grayscale opacity-80" />
                        </div>
                        <span class="text-sm font-bold tracking-tight text-slate-500">LINK HUB</span>
                    </div>
                    <p class="text-sm font-medium text-slate-600">&copy; {{ year }} TGI Digital Office. All rights reserved.</p>
                </div>
            </footer>
        </div>
    </div>
</template>

<style scoped>
/* Typography improvements */
h1, h2, h3, h4, h5, h6 {
    font-family: 'Inter', system-ui, -apple-system, sans-serif;
}

[data-reveal] {
    opacity: 0;
    transform: translateY(30px);
    transition: opacity 0.8s cubic-bezier(0.16, 1, 0.3, 1), transform 0.8s cubic-bezier(0.16, 1, 0.3, 1);
    will-change: opacity, transform;
}
[data-reveal].reveal-in {
    opacity: 1;
    transform: translateY(0);
}

@keyframes fadeInUp {
    from { opacity: 0; transform: translateY(20px); }
    to { opacity: 1; transform: translateY(0); }
}
.animate-fade-in-up {
    animation: fadeInUp 0.8s cubic-bezier(0.16, 1, 0.3, 1) both;
}

@media (prefers-reduced-motion: reduce) {
    [data-reveal] { opacity: 1; transform: none; transition: none; }
    .animate-fade-in-up { animation: none; opacity: 1; transform: none; }
}
</style>
