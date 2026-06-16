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
                    <div class="relative inline-flex items-center justify-center w-52 h-52 mb-8">
                        <!-- Outer glow ring -->
                        <div class="absolute inset-0 bg-white/10 blur-xl rounded-full"></div>
                        <!-- Blob background -->
                        <div class="absolute inset-0 bg-white shadow-2xl shadow-white/20" style="border-radius: 62% 38% 46% 54% / 60% 44% 56% 40%;"></div>
                        <ApplicationLogo class="relative z-10 h-4/5 w-4/5" />
                    </div>

                    <h1 class="text-4xl lg:text-5xl font-black mb-3 tracking-tight leading-[1.1] bg-clip-text text-transparent bg-gradient-to-r from-white to-white/70">
                        The Digital Office of TGI
                    </h1>
                    <p class="text-base text-indigo-300 font-semibold tracking-wide mb-8">
                        One Platform. One Experience. One TGI.
                    </p>
                </div>

                <!-- Module Grid -->
                <div class="grid grid-cols-3 gap-3 text-center w-full">
                    <div class="bg-white/5 backdrop-blur-md rounded-2xl p-4 border border-white/5 hover:bg-white/10 transition-all hover:scale-[1.03] duration-300 flex flex-col items-center gap-2">
                        <div class="w-9 h-9 bg-indigo-500/20 rounded-xl flex items-center justify-center">
                            <svg class="w-4 h-4 text-indigo-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"></path></svg>
                        </div>
                        <span class="text-xs font-bold text-white">Dashboard</span>
                    </div>

                    <div class="bg-white/5 backdrop-blur-md rounded-2xl p-4 border border-white/5 hover:bg-white/10 transition-all hover:scale-[1.03] duration-300 flex flex-col items-center gap-2">
                        <div class="w-9 h-9 bg-blue-500/20 rounded-xl flex items-center justify-center">
                            <svg class="w-4 h-4 text-blue-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"></path></svg>
                        </div>
                        <span class="text-xs font-bold text-white">Projects</span>
                    </div>

                    <div class="bg-white/5 backdrop-blur-md rounded-2xl p-4 border border-white/5 hover:bg-white/10 transition-all hover:scale-[1.03] duration-300 flex flex-col items-center gap-2">
                        <div class="w-9 h-9 bg-violet-500/20 rounded-xl flex items-center justify-center">
                            <svg class="w-4 h-4 text-violet-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 5.636l-3.536 3.536m0 5.656l3.536 3.536M9.172 9.172L5.636 5.636m3.536 9.192l-3.536 3.536M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-5 0a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>
                        </div>
                        <span class="text-xs font-bold text-white">Services</span>
                    </div>

                    <div class="bg-white/5 backdrop-blur-md rounded-2xl p-4 border border-white/5 hover:bg-white/10 transition-all hover:scale-[1.03] duration-300 flex flex-col items-center gap-2">
                        <div class="w-9 h-9 bg-amber-500/20 rounded-xl flex items-center justify-center">
                            <svg class="w-4 h-4 text-amber-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path></svg>
                        </div>
                        <span class="text-xs font-bold text-white">Inventory</span>
                    </div>

                    <div class="bg-white/5 backdrop-blur-md rounded-2xl p-4 border border-white/5 hover:bg-white/10 transition-all hover:scale-[1.03] duration-300 flex flex-col items-center gap-2">
                        <div class="w-9 h-9 bg-cyan-500/20 rounded-xl flex items-center justify-center">
                            <svg class="w-4 h-4 text-cyan-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.069A1 1 0 0121 8.82v6.36a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"></path></svg>
                        </div>
                        <span class="text-xs font-bold text-white">Monitoring</span>
                    </div>

                    <div class="bg-white/5 backdrop-blur-md rounded-2xl p-4 border border-white/5 hover:bg-white/10 transition-all hover:scale-[1.03] duration-300 flex flex-col items-center gap-2">
                        <div class="w-9 h-9 bg-rose-500/20 rounded-xl flex items-center justify-center">
                            <svg class="w-4 h-4 text-rose-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                        </div>
                        <span class="text-xs font-bold text-white">Admin</span>
                    </div>

                    <div class="bg-white/5 backdrop-blur-md rounded-2xl p-4 border border-white/5 hover:bg-white/10 transition-all hover:scale-[1.03] duration-300 flex flex-col items-center gap-2">
                        <div class="w-9 h-9 bg-emerald-500/20 rounded-xl flex items-center justify-center">
                            <svg class="w-4 h-4 text-emerald-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                        </div>
                        <span class="text-xs font-bold text-white">Reports</span>
                    </div>

                    <div class="bg-white/5 backdrop-blur-md rounded-2xl p-4 border border-white/5 hover:bg-white/10 transition-all hover:scale-[1.03] duration-300 flex flex-col items-center gap-2">
                        <div class="w-9 h-9 bg-slate-500/20 rounded-xl flex items-center justify-center">
                            <svg class="w-4 h-4 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path></svg>
                        </div>
                        <span class="text-xs font-bold text-white">References</span>
                    </div>

                    <div class="bg-white/5 backdrop-blur-md rounded-2xl p-4 border border-white/5 hover:bg-white/10 transition-all hover:scale-[1.03] duration-300 flex flex-col items-center gap-2">
                        <div class="w-9 h-9 bg-teal-500/20 rounded-xl flex items-center justify-center">
                            <svg class="w-4 h-4 text-teal-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                        </div>
                        <span class="text-xs font-bold text-white">Scheduling</span>
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
                    <h1 class="text-2xl font-black text-slate-900 tracking-tight">The Digital Office of TGI</h1>
                    <p class="text-xs font-semibold text-indigo-500 mt-1">One Platform. One Experience. One TGI.</p>
                </div>

                <!-- Form Container -->
                <div class="bg-white rounded-[2rem] shadow-xl shadow-slate-200/50 border border-slate-100 p-8 sm:p-10">
                    <slot />
                </div>

                <!-- Footer -->
                <div class="mt-10 text-center">
                    <p class="text-xs font-medium text-slate-500">
                        &copy; {{ new Date().getFullYear() }} The Digital Office of TGI. All rights reserved.
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
