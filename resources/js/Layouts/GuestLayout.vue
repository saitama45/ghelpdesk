<script setup>
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <div class="min-h-screen flex font-sans">
        <!-- Left Panel - Branding -->
        <div class="hidden lg:flex lg:w-1/2 bg-slate-900 relative overflow-hidden items-center justify-center">
            <!-- Dynamic Background Pattern -->
            <div class="absolute inset-0 z-0">
                <!-- Base subtle gradient -->
                <div class="absolute inset-0 bg-gradient-to-br from-indigo-900 via-slate-900 to-blue-900 opacity-90"></div>
                <!-- Animated decorative blobs -->
                <div class="absolute top-0 left-0 w-full h-full overflow-hidden opacity-30 mix-blend-screen pointer-events-none">
                    <div class="absolute -top-[20%] -left-[10%] w-[70%] h-[70%] rounded-full bg-indigo-600 blur-[120px] animate-blob"></div>
                    <div class="absolute top-[20%] -right-[20%] w-[60%] h-[60%] rounded-full bg-blue-600 blur-[120px] animate-blob animation-delay-2000"></div>
                    <div class="absolute -bottom-[20%] left-[20%] w-[80%] h-[80%] rounded-full bg-purple-600 blur-[120px] animate-blob animation-delay-4000"></div>
                </div>
            </div>

            <!-- Content -->
            <div class="relative z-10 flex flex-col justify-center items-center text-center text-white w-full max-w-2xl px-16 py-16">
                <div class="mb-12 flex flex-col items-center">
                    <div class="inline-flex items-center justify-center w-32 h-32 bg-white rounded-[2.5rem] shadow-2xl border border-white/10 mb-8 p-2">
                        <ApplicationLogo class="h-full w-full transform scale-110" />
                    </div>

                    <h1 class="text-5xl lg:text-6xl font-black mb-6 tracking-tight leading-[1.1] bg-clip-text text-transparent bg-gradient-to-r from-white to-white/70">
                        TAS Service Center
                    </h1>
                    <p class="text-lg text-slate-300 leading-relaxed max-w-xl font-medium">
                        Streamlining technical operations and IT service delivery. From managing helpdesk tickets and SLA performance to coordinating infrastructure projects, SAP requests, and store deployments—everything you need in one unified hub.
                    </p>
                </div>

                <!-- Feature Highlights -->
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 text-left">
                    <div class="bg-white/5 backdrop-blur-md rounded-2xl p-5 border border-white/5 hover:bg-white/10 transition-all hover:scale-[1.02] duration-300">
                        <div class="w-10 h-10 bg-indigo-500/20 rounded-xl flex items-center justify-center shrink-0 mb-4">
                            <svg class="w-5 h-5 text-indigo-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 5v2m0 4v2m0 4v2M5 5a2 2 0 012-2h10a2 2 0 012 2v14a2 2 0 01-2 2H7a2 2 0 01-2-2V5z"></path></svg>
                        </div>
                        <h3 class="font-bold text-white text-sm mb-1">Helpdesk & SLA</h3>
                        <p class="text-[11px] leading-relaxed text-slate-400">Smart incident tracking with real-time resolution metrics.</p>
                    </div>
                    
                    <div class="bg-white/5 backdrop-blur-md rounded-2xl p-5 border border-white/5 hover:bg-white/10 transition-all hover:scale-[1.02] duration-300">
                        <div class="w-10 h-10 bg-blue-500/20 rounded-xl flex items-center justify-center shrink-0 mb-4">
                            <svg class="w-5 h-5 text-blue-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path></svg>
                        </div>
                        <h3 class="font-bold text-white text-sm mb-1">NSO Rollouts</h3>
                        <p class="text-[11px] leading-relaxed text-slate-400">Gantt-based coordination for store deployment projects.</p>
                    </div>

                    <div class="bg-white/5 backdrop-blur-md rounded-2xl p-5 border border-white/5 hover:bg-white/10 transition-all hover:scale-[1.02] duration-300">
                        <div class="w-10 h-10 bg-emerald-500/20 rounded-xl flex items-center justify-center shrink-0 mb-4">
                            <svg class="w-5 h-5 text-emerald-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.756 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                        </div>
                        <h3 class="font-bold text-white text-sm mb-1">System Ops</h3>
                        <p class="text-[11px] leading-relaxed text-slate-400">Unified management for SAP, POS, and tech requests.</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Panel - Login Form -->
        <div class="flex-1 flex flex-col justify-center items-center bg-gray-50 px-6 py-12 lg:px-24 relative">
            <!-- Subtle background pattern for form side -->
            <div class="absolute inset-0 z-0 opacity-[0.03] pointer-events-none" style="background-image: radial-gradient(#4f46e5 1px, transparent 1px); background-size: 24px 24px;"></div>

            <div class="w-full max-w-[420px] relative z-10">
                <!-- Mobile Logo -->
                <div class="lg:hidden text-center mb-10">
                    <div class="inline-flex items-center justify-center w-16 h-16 bg-indigo-600 rounded-2xl shadow-xl shadow-indigo-200 mb-6">
                        <ApplicationLogo class="h-8 w-8 text-white" />
                    </div>
                    <h1 class="text-3xl font-black text-slate-900 tracking-tight">TAS Service Center</h1>
                </div>

                <!-- Form Container -->
                <div class="bg-white rounded-[2rem] shadow-xl shadow-slate-200/50 border border-slate-100 p-8 sm:p-10">
                    <slot />
                </div>

                <!-- Footer -->
                <div class="mt-10 text-center">
                    <p class="text-xs font-medium text-slate-500">
                        &copy; {{ new Date().getFullYear() }} TAS Service Center. All rights reserved.
                    </p>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
@keyframes blob {
  0% { transform: translate(0px, 0px) scale(1); }
  33% { transform: translate(30px, -50px) scale(1.1); }
  66% { transform: translate(-20px, 20px) scale(0.9); }
  100% { transform: translate(0px, 0px) scale(1); }
}
.animate-blob { 
    animation: blob 10s infinite alternate; 
}
.animation-delay-2000 { 
    animation-delay: 2s; 
}
.animation-delay-4000 { 
    animation-delay: 4s; 
}
</style>
