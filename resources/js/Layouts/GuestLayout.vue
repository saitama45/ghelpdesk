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
            <div class="relative z-10 flex flex-col justify-center text-white w-full max-w-2xl px-16 py-16">
                <div class="mb-12">
                    <div class="inline-flex items-center justify-center w-20 h-20 bg-white/10 rounded-3xl backdrop-blur-xl shadow-2xl border border-white/10 mb-8">
                        <ApplicationLogo class="h-10 w-10 text-white" />
                    </div>

                    <h1 class="text-5xl lg:text-6xl font-black mb-6 tracking-tight leading-[1.1] bg-clip-text text-transparent bg-gradient-to-r from-white to-white/70">
                        TGI Service Center
                    </h1>
                    <p class="text-lg text-slate-300 leading-relaxed max-w-xl font-medium">
                        Your unified portal for all internal services. Whether it's IT Support, HR requests, Supply Chain Management, Marketing assets, Accounting needs, or Facilities operations—we streamline it all in one place.
                    </p>
                </div>

                <!-- Feature Highlights -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="bg-white/5 backdrop-blur-md rounded-2xl p-5 border border-white/5 hover:bg-white/10 transition-colors">
                        <div class="w-10 h-10 bg-indigo-500/20 rounded-xl flex items-center justify-center shrink-0 mb-4">
                            <svg class="w-5 h-5 text-indigo-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                        </div>
                        <h3 class="font-bold text-white text-base mb-1">Centralized Workflows</h3>
                        <p class="text-sm text-slate-400">One hub for every department.</p>
                    </div>
                    
                    <div class="bg-white/5 backdrop-blur-md rounded-2xl p-5 border border-white/5 hover:bg-white/10 transition-colors">
                        <div class="w-10 h-10 bg-blue-500/20 rounded-xl flex items-center justify-center shrink-0 mb-4">
                            <svg class="w-5 h-5 text-blue-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path></svg>
                        </div>
                        <h3 class="font-bold text-white text-base mb-1">Secure & Trackable</h3>
                        <p class="text-sm text-slate-400">Full visibility on every request.</p>
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
                    <h1 class="text-3xl font-black text-slate-900 tracking-tight">TGI Service Center</h1>
                </div>

                <!-- Form Container -->
                <div class="bg-white rounded-[2rem] shadow-xl shadow-slate-200/50 border border-slate-100 p-8 sm:p-10">
                    <slot />
                </div>

                <!-- Footer -->
                <div class="mt-10 text-center">
                    <p class="text-xs font-medium text-slate-500">
                        &copy; {{ new Date().getFullYear() }} TGI Service Center. All rights reserved.
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
