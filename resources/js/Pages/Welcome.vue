<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import MainNavigation from '@/Components/MainNavigation.vue';

defineProps({
    canLogin: {
        type: Boolean,
    },
    canRegister: {
        type: Boolean,
    },
    laravelVersion: {
        type: String,
        required: true,
    },
    phpVersion: {
        type: String,
        required: true,
    },
});

const contactForm = ref({
    firstName: '',
    lastName: '',
    email: '',
    phone: ''
});

// Form submission state
const isSubmitting = ref(false);
const submissionMessage = ref('');
const submissionError = ref('');

// Video state
const heroVideo = ref(null);
const isVideoExpanded = ref(false);


// Phone number formatting
const formatPhoneNumber = (event) => {
    let input = event.target.value.replace(/\D/g, '');

    // Limit to 11 digits maximum (09 + 9 more digits)
    if (input.length > 11) {
        input = input.substring(0, 11);
    }

    // Format the phone number - user types 09 manually
    if (input.length === 0) {
        contactForm.value.phone = '';
    } else if (input.length <= 4) {
        // 09XX
        contactForm.value.phone = input;
    } else if (input.length <= 7) {
        // 09XX-XXX
        contactForm.value.phone = `${input.substring(0, 4)}-${input.substring(4)}`;
    } else {
        // 09XX-XXX-XXXX (take all remaining digits for the last part)
        contactForm.value.phone = `${input.substring(0, 4)}-${input.substring(4, 7)}-${input.substring(7)}`;
    }
};

// Uppercase formatting for names
const formatName = (field) => {
    contactForm.value[field] = contactForm.value[field].toUpperCase();
};

const handleQuickContact = async () => {
    // Clear previous messages
    submissionMessage.value = '';
    submissionError.value = '';

    // Basic client-side validation
    if (!contactForm.value.firstName || !contactForm.value.lastName ||
        !contactForm.value.email || !contactForm.value.phone) {
        submissionError.value = 'Please fill in all required fields.';
        return;
    }

    // Email validation
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailRegex.test(contactForm.value.email)) {
        submissionError.value = 'Please enter a valid email address.';
        return;
    }

    // Phone validation (must match 09XX-XXX-XXXX format)
    const phoneRegex = /^09\d{2}-\d{3}-\d{4}$/;
    if (!phoneRegex.test(contactForm.value.phone)) {
        submissionError.value = 'Please enter a valid phone number (09XX-XXX-XXXX).';
        return;
    }

    isSubmitting.value = true;

    try {
        const response = await fetch('/api/contact/submit', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
            },
            body: JSON.stringify({
                firstName: contactForm.value.firstName.toUpperCase(),
                lastName: contactForm.value.lastName.toUpperCase(),
                email: contactForm.value.email.toLowerCase().trim(),
                phone: contactForm.value.phone
            })
        });

        const data = await response.json();

        if (data.success) {
            submissionMessage.value = data.message;
            // Clear form on success
            contactForm.value = {
                firstName: '',
                lastName: '',
                email: '',
                phone: ''
            };
        } else {
            submissionError.value = data.message || 'An error occurred. Please try again.';
        }
    } catch (error) {
        console.error('Contact form error:', error);
        submissionError.value = 'Network error. Please check your connection and try again.';
    } finally {
        isSubmitting.value = false;
    }
};

// Scroll to contact form and focus on first name
const scrollToContactForm = () => {
    const contactSection = document.getElementById('contact');
    const firstNameInput = document.getElementById('firstName');

    if (contactSection) {
        // Use the simplest scroll method
        // contactSection.scrollIntoView({
           // behavior: 'smooth',
           // block: 'start'
       // });

        // Focus on first name input after scroll completes
        setTimeout(() => {
            if (firstNameInput) {
                firstNameInput.focus();
            }
        }, 1000); // Slightly longer delay
    }
};

// Video functions
const toggleVideoExpanded = () => {
    isVideoExpanded.value = !isVideoExpanded.value;
    if (isVideoExpanded.value) {
        document.body.style.overflow = 'hidden';
    } else {
        document.body.style.overflow = 'auto';
    }
};

const closeVideoExpanded = () => {
    isVideoExpanded.value = false;
    document.body.style.overflow = 'auto';
};

// Smooth scroll function
const scrollToSection = (sectionId) => {
    const element = document.getElementById(sectionId);
    if (element) {
        element.scrollIntoView({
            behavior: 'smooth',
            block: 'start'
        });
    }
};
</script>

<template>
    <Head title="Memorial Cemetery" />
    <div class="min-h-screen bg-gradient-to-b from-slate-50 to-blue-50">
        <!-- Navigation Header -->
        <MainNavigation />

        <!-- Hero Section -->
        <section id="home" class="relative min-h-screen flex items-center justify-center overflow-hidden">
            <!-- Video Background with Overlay -->
            <div class="absolute inset-0">
                <video
                    ref="heroVideo"
                    src="/video/loyola_tan.mp4"
                    class="w-full h-full object-cover"
                    autoplay
                    muted
                    loop
                    playsinline
                    @click="toggleVideoExpanded"
                ></video>
                <div class="absolute inset-0 bg-gradient-to-b from-transparent via-slate-900/40 to-slate-900/70"></div>
            </div>

            <div class="relative z-10 text-center text-white px-4 sm:px-6 lg:px-8 max-w-4xl mx-auto">
                <h1 class="text-5xl md:text-7xl font-serif mb-6 leading-tight">
                    A Perfect Sanctuary for Those Who Value<br>
                    <span class="text-blue-200">Privacy and Prestige</span>
                </h1>

                <!-- Hero Content -->
                <div class="flex flex-col sm:flex-row gap-4 justify-center">
                    <button
                        @click="scrollToSection('about')"
                        class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-4 px-8 rounded-lg transition-all duration-300 transform hover:scale-105 shadow-xl hover:shadow-2xl"
                    >
                        Discover Our Story
                    </button>
                    <button
                        @click="toggleVideoExpanded"
                        class="border-2 border-white text-white hover:bg-white hover:text-slate-900 font-bold py-4 px-8 rounded-lg transition-all duration-300"
                    >
                        <span class="flex items-center">
                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4"></path>
                            </svg>
                            Expand Video
                        </span>
                    </button>
                </div>
            </div>

            <!-- Scroll Indicator -->
            <div class="absolute bottom-8 left-1/2 transform -translate-x-1/2 animate-bounce">
                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3"></path>
                </svg>
            </div>
        </section>

        <!-- New Content Section -->
        <section id="new-about" class="py-20 bg-gradient-to-b from-blue-50 to-slate-50">
            <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
                <!-- Main Content -->
                <div class="text-center mb-16">
                    <h2 class="text-4xl font-serif text-slate-800 mb-6">Our Legacy of Peace and Dignity</h2>
                    <div class="max-w-4xl mx-auto">
                        <p class="text-lg text-slate-600 leading-relaxed mb-8">
                            For over 20 years, Loyola Gardens of Tanauan has been a haven of peace, dignity, and reverence — a place where every "Don" and "Doña" of Batangas finds a Spanish Hacienda–inspired resting place that celebrates life, legacy, and love.
                        </p>
                        <p class="text-lg text-slate-600 leading-relaxed mb-8">
                            Nestled in the heart of Tanauan, Loyola Gardens is tastefully designed to reflect the intricate taste of Batangueños. Its landscape splendor, peaceful ambiance, and viewing chapels offer comfort and convenience for families who seek to honor their loved ones with grace.
                        </p>
                        <p class="text-lg text-slate-600 leading-relaxed">
                            Our 20 years of dedicated service to the Tanauan community reflect our unwavering commitment to creating a space that embodies beauty, serenity, and respect for every cherished memory.
                        </p>
                    </div>
                </div>

                <!-- Taglines Section -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-12 mb-16">
                    <!-- Give an Amazing Gift of Love -->
                    <div class="bg-gradient-to-br from-blue-50 to-indigo-50 rounded-2xl p-8 shadow-lg border border-blue-100 hover:shadow-xl transition-all duration-300">
                        <div class="text-center">
                            <div class="text-5xl mb-6">💝</div>
                            <h3 class="text-2xl font-serif text-blue-800 mb-4">Give an Amazing Gift of Love</h3>
                            <p class="text-lg text-blue-700 font-medium italic mb-6">"You never know when, but you should know how."</p>
                            <p class="text-slate-600">
                                Pre-planning your memorial arrangements is one of the most thoughtful gifts you can give your family—a gift of love, foresight, and peace of mind during their time of need.
                            </p>
                        </div>
                    </div>

                    <!-- Leave No Worries Behind -->
                    <div class="bg-gradient-to-br from-green-50 to-emerald-50 rounded-2xl p-8 shadow-lg border border-green-100 hover:shadow-xl transition-all duration-300">
                        <div class="text-center">
                            <div class="text-5xl mb-6">🌿</div>
                            <h3 class="text-2xl font-serif text-green-800 mb-4">Leave No Worries Behind</h3>
                            <p class="text-lg text-green-700 font-medium mb-6">Plan with confidence, live with peace</p>
                            <p class="text-slate-600">
                                By making arrangements today, you ensure that your wishes are honored while relieving your loved ones of emotional and financial burdens tomorrow.
                            </p>
                        </div>
                    </div>
                </div>

              </div>
        </section>

  

  
        <!-- Pre-Planning Section -->
        <section id="preplanning" class="py-20 bg-gradient-to-b from-slate-50 to-blue-50">
            <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
                <!-- Section Header -->
                <div class="text-center mb-16">
                    <div class="flex justify-center items-center mb-6">
                        <div class="w-16 h-16 bg-blue-100 rounded-full flex items-center justify-center mr-4">
                            <svg class="w-8 h-8 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"></path>
                            </svg>
                        </div>
                        <h2 class="text-4xl font-serif text-slate-800">In Time of Grief...</h2>
                    </div>
                    <h3 class="text-3xl font-serif text-blue-600 mb-6">Spare Your Family the Burden of Planning</h3>
                    <div class="max-w-3xl mx-auto">
                        <p class="text-lg text-slate-600 leading-relaxed mb-4">
                            Nobody wants to plan for their death, but the fact is that many significant financial decisions must be made when planning funeral. These decisions can be overwhelming and emotionally taxing for a family in mourning.
                        </p>
                        <p class="text-lg text-blue-700 font-semibold">
                            You can make those decisions now and relieve your loved ones of unnecessary stress and financial concern when the time comes.
                        </p>
                    </div>
                </div>

                <!-- Two Column Content -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-12">
                    <!-- Left Column - Advantages of Pre-Planning -->
                    <div class="bg-white rounded-2xl shadow-xl p-8 h-full">
                        <div class="flex items-center mb-8">
                            <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center mr-4">
                                <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                            </div>
                            <h3 class="text-2xl font-bold text-slate-800">Advantages of Pre-Planning</h3>
                        </div>

                        <div class="space-y-6">
                            <div class="flex items-start group">
                                <div class="w-8 h-8 bg-blue-50 rounded-lg flex items-center justify-center mr-4 mt-1 group-hover:bg-blue-100 transition-colors">
                                    <svg class="w-4 h-4 text-blue-600" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                    </svg>
                                </div>
                                <div>
                                    <h4 class="font-semibold text-slate-800 mb-2">Protect Your Family from Distress</h4>
                                    <p class="text-slate-600">You can protect your family from the distress of making difficult decisions at an emotional time.</p>
                                </div>
                            </div>

                            <div class="flex items-start group">
                                <div class="w-8 h-8 bg-blue-50 rounded-lg flex items-center justify-center mr-4 mt-1 group-hover:bg-blue-100 transition-colors">
                                    <svg class="w-4 h-4 text-blue-600" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                    </svg>
                                </div>
                                <div>
                                    <h4 class="font-semibold text-slate-800 mb-2">Relieve Financial Burden</h4>
                                    <p class="text-slate-600">Your family will be relieved from any financial burden during their time of grief.</p>
                                </div>
                            </div>

                            <div class="flex items-start group">
                                <div class="w-8 h-8 bg-blue-50 rounded-lg flex items-center justify-center mr-4 mt-1 group-hover:bg-blue-100 transition-colors">
                                    <svg class="w-4 h-4 text-blue-600" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                    </svg>
                                </div>
                                <div>
                                    <h4 class="font-semibold text-slate-800 mb-2">Ensure Your Wishes are Honored</h4>
                                    <p class="text-slate-600">You can ensure that your exact wishes are made known and are carried out precisely.</p>
                                </div>
                            </div>

                            <div class="flex items-start group">
                                <div class="w-8 h-8 bg-blue-50 rounded-lg flex items-center justify-center mr-4 mt-1 group-hover:bg-blue-100 transition-colors">
                                    <svg class="w-4 h-4 text-blue-600" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                    </svg>
                                </div>
                                <div>
                                    <h4 class="font-semibold text-slate-800 mb-2">Make Wise Decisions Together</h4>
                                    <p class="text-slate-600">You make wise decisions with your loved ones when emotions are not overwhelming.</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Right Column - Key Features -->
                    <div class="bg-gradient-to-br from-blue-600 to-indigo-600 rounded-2xl shadow-xl p-8 h-full text-white">
                        <div class="flex items-center mb-8">
                            <div class="w-12 h-12 bg-white/20 backdrop-blur-sm rounded-lg flex items-center justify-center mr-4">
                                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                            </div>
                            <h3 class="text-2xl font-bold">Key Benefits & Features</h3>
                        </div>

                        <div class="space-y-4">
                            <div class="flex items-center bg-white/10 backdrop-blur-sm rounded-lg p-4 hover:bg-white/20 transition-colors">
                                <div class="w-10 h-10 bg-white/20 rounded-lg flex items-center justify-center mr-4">
                                    <svg class="w-5 h-5 text-yellow-300" fill="currentColor" viewBox="0 0 20 20">
                                        <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"></path>
                                    </svg>
                                </div>
                                <div>
                                    <h4 class="font-semibold text-white">Lifetime Maintenance Care</h4>
                                    <p class="text-blue-100 text-sm">Comprehensive care for lasting peace of mind</p>
                                </div>
                            </div>

                            <div class="flex items-center bg-white/10 backdrop-blur-sm rounded-lg p-4 hover:bg-white/20 transition-colors">
                                <div class="w-10 h-10 bg-white/20 rounded-lg flex items-center justify-center mr-4">
                                    <svg class="w-5 h-5 text-yellow-300" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M2.166 4.999A11.954 11.954 0 0010 1.944 11.954 11.954 0 0017.834 5c.11.65.166 1.32.166 2.001 0 5.225-3.34 9.67-8 11.317C5.34 16.67 2 12.225 2 7c0-.682.057-1.35.166-2.001zm11.541 3.708a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                    </svg>
                                </div>
                                <div>
                                    <h4 class="font-semibold text-white">Free Insurance Coverage</h4>
                                    <p class="text-blue-100 text-sm">Added protection for your investment</p>
                                </div>
                            </div>

                            <div class="flex items-center bg-white/10 backdrop-blur-sm rounded-lg p-4 hover:bg-white/20 transition-colors">
                                <div class="w-10 h-10 bg-white/20 rounded-lg flex items-center justify-center mr-4">
                                    <svg class="w-5 h-5 text-yellow-300" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path>
                                    </svg>
                                </div>
                                <div>
                                    <h4 class="font-semibold text-white">Transferable Benefits</h4>
                                    <p class="text-blue-100 text-sm">Flexible options for your family's needs</p>
                                </div>
                            </div>

                            <div class="flex items-center bg-white/10 backdrop-blur-sm rounded-lg p-4 hover:bg-white/20 transition-colors">
                                <div class="w-10 h-10 bg-white/20 rounded-lg flex items-center justify-center mr-4">
                                    <svg class="w-5 h-5 text-yellow-300" fill="currentColor" viewBox="0 0 20 20">
                                        <path d="M8.433 7.418c.155-.103.346-.196.567-.267v1.698a2.305 2.305 0 01-.567-.267C8.07 8.34 8 8.114 8 8c0-.114.07-.34.433-.582zM11 12.849v-1.698c.22.071.412.164.567.267.364.243.433.468.433.582 0 .114-.07.34-.433.582a2.305 2.305 0 01-.567.267z"></path>
                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-13a1 1 0 10-2 0v.092a4.535 4.535 0 00-1.676.662V5a3 3 0 013 3v.092a4.535 4.535 0 001.676.662V8a3 3 0 013-3h-1V4a1 1 0 10-2 0v.092a4.535 4.535 0 00-1.676-.662V4z" clip-rule="evenodd"></path>
                                    </svg>
                                </div>
                                <div>
                                    <h4 class="font-semibold text-white">Low Cash Outlay</h4>
                                    <p class="text-blue-100 text-sm">Affordable entry with flexible payment terms</p>
                                </div>
                            </div>

                            <div class="flex items-center bg-white/10 backdrop-blur-sm rounded-lg p-4 hover:bg-white/20 transition-colors">
                                <div class="w-10 h-10 bg-white/20 rounded-lg flex items-center justify-center mr-4">
                                    <svg class="w-5 h-5 text-yellow-300" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M12 7a1 1 0 110-2h5a1 1 0 011 1v5a1 1 0 11-2 0V8.414l-4.293 4.293a1 1 0 01-1.414 0L8 10.414l-4.293 4.293a1 1 0 01-1.414-1.414l5-5a1 1 0 011.414 0L11 10.586 14.586 7H12z" clip-rule="evenodd"></path>
                                    </svg>
                                </div>
                                <div>
                                    <h4 class="font-semibold text-white">High Return on Investment</h4>
                                    <p class="text-blue-100 text-sm">Secure financial growth for your family's future</p>
                                </div>
                            </div>

                            <div class="flex items-center bg-white/10 backdrop-blur-sm rounded-lg p-4 hover:bg-white/20 transition-colors">
                                <div class="w-10 h-10 bg-white/20 rounded-lg flex items-center justify-center mr-4">
                                    <svg class="w-5 h-5 text-yellow-300" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M6 2a1 1 0 00-1 1v1H4a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-1V3a1 1 0 10-2 0v1H7V3a1 1 0 00-1-1zM4 8h12v8H4V8z" clip-rule="evenodd"></path>
                                    </svg>
                                </div>
                                <div>
                                    <h4 class="font-semibold text-white">Preparation for the "Inevitable"</h4>
                                    <p class="text-blue-100 text-sm">Thoughtful planning brings peace of mind</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Call to Action -->
                <div class="text-center mt-12">
                    <div class="bg-blue-50 border border-blue-200 rounded-xl p-6 max-w-2xl mx-auto">
                        <h4 class="text-xl font-bold text-blue-800 mb-3">Ready to Plan Ahead?</h4>
                        <p class="text-blue-700 mb-6">Take the first step in providing peace of mind for yourself and your loved ones.</p>
                        <button @click="scrollToContactForm" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 px-8 rounded-lg transition-colors shadow-lg hover:shadow-xl transform hover:scale-105 transition-transform">
                            Learn More About Pre-Planning
                        </button>
                    </div>
                </div>
            </div>
        </section>

        <!-- Services Section -->
        <section id="services" class="py-20 bg-white">
            <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="text-center mb-16">
                    <h2 class="text-4xl font-serif text-slate-800 mb-4">Our Products and Services</h2>
                    <p class="text-lg text-slate-600 max-w-2xl mx-auto">
                        Comprehensive memorial products and services designed to honor your loved ones with dignity and respect
                    </p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
                    <!-- Garden Lot -->
                    <div class="bg-slate-50 rounded-xl p-8 hover:shadow-lg transition-shadow">
                        <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center mb-6">
                            <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path>
                            </svg>
                        </div>
                        <h3 class="text-xl font-serif text-slate-800 mb-4">Garden Lot</h3>
                        <p class="text-slate-600 leading-relaxed">
                            Beautiful garden-style memorial lots surrounded by lush landscaping and peaceful gardens, providing a serene final resting place for your loved ones.
                        </p>
                    </div>

                    <!-- Garden Estate -->
                    <div class="bg-slate-50 rounded-xl p-8 hover:shadow-lg transition-shadow">
                        <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center mb-6">
                            <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path>
                            </svg>
                        </div>
                        <h3 class="text-xl font-serif text-slate-800 mb-4">Garden Estate</h3>
                        <p class="text-slate-600 leading-relaxed">
                            Premium garden estates offering exclusive memorial spaces with enhanced landscaping, privacy, and personalized design elements for distinguished tributes.
                        </p>
                    </div>

                    <!-- Family Estate -->
                    <div class="bg-slate-50 rounded-xl p-8 hover:shadow-lg transition-shadow">
                        <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center mb-6">
                            <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 0 0-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 0 1 5.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 0 1 9.288 0M15 7a3 3 0 1 1-6 0 3 3 0 0 1 6 0zm6 3a2 2 0 1 1-4 0 2 2 0 0 1 4 0zM7 10a2 2 0 1 1-4 0 2 2 0 0 1 4 0z"></path>
                            </svg>
                        </div>
                        <h3 class="text-xl font-serif text-slate-800 mb-4">Family Estate</h3>
                        <p class="text-slate-600 leading-relaxed">
                            Spacious family estates designed to accommodate multiple generations, providing a dignified and exclusive memorial space for entire families.
                        </p>
                    </div>

                    <!-- Chapel Rent -->
                    <div class="bg-slate-50 rounded-xl p-8 hover:shadow-lg transition-shadow">
                        <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center mb-6">
                            <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                            </svg>
                        </div>
                        <h3 class="text-xl font-serif text-slate-800 mb-4">Chapel Rent</h3>
                        <p class="text-slate-600 leading-relaxed">
                            Elegant chapel services for memorial rites and funeral ceremonies, providing a sacred space for families to gather and honor their departed loved ones.
                        </p>
                    </div>

                    <!-- Interment Services -->
                    <div class="bg-slate-50 rounded-xl p-8 hover:shadow-lg transition-shadow">
                        <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center mb-6">
                            <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                        </div>
                        <h3 class="text-xl font-serif text-slate-800 mb-4">Interment Services</h3>
                        <p class="text-slate-600 leading-relaxed">
                            Professional interment services with careful attention to detail and respect, ensuring dignified burial ceremonies conducted by our experienced staff.
                        </p>
                    </div>

                    <!-- Perpetual Care -->
                    <div class="bg-slate-50 rounded-xl p-8 hover:shadow-lg transition-shadow">
                        <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center mb-6">
                            <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"></path>
                            </svg>
                        </div>
                        <h3 class="text-xl font-serif text-slate-800 mb-4">Perpetual Care</h3>
                        <p class="text-slate-600 leading-relaxed">
                            Perpetual care services including tomb maintenance, flower offerings, and regular upkeep to ensure lasting beauty and dignity.
                        </p>
                    </div>

                </div>

              </div>
        </section>

        <!-- Contact Form Section -->
        <section id="contact" class="py-24 bg-gradient-to-br from-blue-50 via-slate-50 to-indigo-100">
            <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
                <!-- Section Header -->
                <div class="text-center mb-12">
                    <h2 class="text-4xl font-serif text-slate-800 mb-4">Get in Touch With Us</h2>
                    <p class="text-lg text-slate-600 max-w-2xl mx-auto">
                        Ready to learn more about our memorial services? Contact our caring team today.
                    </p>
                </div>

                <!-- Contact Form Card -->
                <div class="bg-white rounded-3xl shadow-2xl overflow-hidden border border-blue-100">
                    <!-- Form Header -->
                    <div class="bg-gradient-to-r from-blue-600 to-indigo-600 px-8 py-6 text-center">
                        <div class="inline-flex items-center mb-2">
                            <svg class="w-8 h-8 text-white mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0l1.48-1a2 2 0 012.22 0l1.48 1a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                            </svg>
                            <h3 class="text-2xl font-bold text-white">Request More Information</h3>
                        </div>
                        <p class="text-blue-100">We'll respond within 24 hours</p>
                    </div>

                    <div class="p-8">
                        <!-- Success Message -->
                        <div v-if="submissionMessage" class="mb-6 p-4 bg-green-50 border-2 border-green-200 text-green-800 rounded-xl flex items-center">
                            <svg class="w-6 h-6 text-green-600 mr-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                            </svg>
                            <span class="font-medium">{{ submissionMessage }}</span>
                        </div>

                        <!-- Error Message -->
                        <div v-if="submissionError" class="mb-6 p-4 bg-red-50 border-2 border-red-200 text-red-800 rounded-xl flex items-center">
                            <svg class="w-6 h-6 text-red-600 mr-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 001-1v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>
                            </svg>
                            <span class="font-medium">{{ submissionError }}</span>
                        </div>

                        <form @submit.prevent="handleQuickContact" class="space-y-6">
                            <!-- Name Fields Row -->
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                        <svg class="w-5 h-5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14"></path>
                                        </svg>
                                    </div>
                                    <input
                                        id="firstName"
                                        type="text"
                                        v-model="contactForm.firstName"
                                        @input="formatName('firstName')"
                                        placeholder="First Name"
                                        class="w-full pl-10 pr-4 py-3 border-2 border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent uppercase bg-white/50 backdrop-blur-sm text-slate-800 placeholder-slate-500"
                                        required
                                    >
                                </div>
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                        <svg class="w-5 h-5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14"></path>
                                        </svg>
                                    </div>
                                    <input
                                        type="text"
                                        v-model="contactForm.lastName"
                                        @input="formatName('lastName')"
                                        placeholder="Last Name"
                                        class="w-full pl-10 pr-4 py-3 border-2 border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent uppercase bg-white/50 backdrop-blur-sm text-slate-800 placeholder-slate-500"
                                        required
                                    >
                                </div>
                            </div>

                            <!-- Email Field -->
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <svg class="w-5 h-5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0l1.48-1a2 2 0 012.22 0l1.48 1a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                                    </svg>
                                </div>
                                <input
                                    type="email"
                                    v-model="contactForm.email"
                                    placeholder="Email Address"
                                    class="w-full pl-10 pr-4 py-3 border-2 border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent bg-white/50 backdrop-blur-sm text-slate-800 placeholder-slate-500"
                                    required
                                >
                            </div>

                            <!-- Phone Field -->
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <svg class="w-5 h-5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 1.498a1 1 0 01.998.986 1.726.65a1 1 0 01.488.362l.548.22A1 1 0 0112.5.578c.468 0 .897.196 1.191.425.13.042.027.086.053.137.075A2 2 0 0113 7.578V8a2 2 0 01-1 1.722V16a2 2 0 001-1h3.28a1 1 0 01.948-.684l1.498-1.498A1 1 0 0112 16.226V13.5a2 2 0 01-.522.648l-.548-.22A1 1 0 0012.5.078V5a2 2 0 00-2-2H3z"></path>
                                    </svg>
                                </div>
                                <input
                                    type="tel"
                                    v-model="contactForm.phone"
                                    @input="formatPhoneNumber"
                                    placeholder="0912-345-6789"
                                    maxlength="13"
                                    class="w-full pl-10 pr-4 py-3 border-2 border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent bg-white/50 backdrop-blur-sm text-slate-800 placeholder-slate-500"
                                    required
                                >
                            </div>

                            <!-- Submit Button -->
                            <button
                                type="submit"
                                class="w-full bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 text-white font-bold py-4 px-6 rounded-lg transition-all duration-300 transform hover:scale-105 shadow-xl disabled:opacity-60 disabled:cursor-not- disabled:transform-none"
                                :disabled="isSubmitting"
                            >
                                <span v-if="isSubmitting" class="flex items-center justify-center">
                                    <svg class="animate-spin -ml-1 mr-3 h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                    Submitting Request...
                                </span>
                                <span v-else class="flex items-center justify-center">
                                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0l1.48-1a2 2 0 012.22 0l1.48 1a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                                    </svg>
                                    Send Message
                                </span>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </section>


        <!-- Footer -->
        <footer class="bg-slate-800 text-white py-12">
            <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                    <div>
                        <h3 class="text-xl font-serif mb-4">Loyola Tanauan</h3>
                        <p class="text-slate-300">
                            A place of peace and remembrance, serving families with dignity and compassion for generations.
                        </p>
                    </div>

                    <div>
                        <h4 class="font-semibold mb-4">Quick Links</h4>
                        <ul class="space-y-2 text-slate-300">
                            <li><a href="#home" class="hover:text-white transition-colors">Home</a></li>
                            <li><a href="#about" class="hover:text-white transition-colors">About</a></li>
                            <li><a href="#services" class="hover:text-white transition-colors">Services</a></li>
                            <li><a href="#contact" class="hover:text-white transition-colors">Contact</a></li>
                        </ul>
                    </div>

                    <div>
                        <h4 class="font-semibold mb-4">Contact Info</h4>
                        <ul class="space-y-2 text-slate-300">
                            <li>Phone: (043) 778-1234</li>
                            <li>Email: info@loyolatanauan.com</li>
                            <li>Emergency: 0917-123-4567</li>
                        </ul>
                    </div>
                </div>

                <div class="border-t border-slate-700 mt-8 pt-8 text-center text-slate-400">
                    <p>&copy; 2025 Loyola Tanauan Memorial Cemetery. All rights reserved.</p>
                </div>
            </div>
        </footer>

        <!-- Expanded Video Modal -->
        <div
            v-if="isVideoExpanded"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-90"
            @click="closeVideoExpanded"
        >
            <div class="relative w-full h-full flex items-center justify-center p-4" @click.stop>
                <!-- Close Button -->
                <button
                    @click="closeVideoExpanded"
                    class="absolute top-4 right-4 text-white hover:text-slate-300 transition-colors z-10"
                >
                    <svg class="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>

                <!-- Expanded Video -->
                <div class="relative w-full max-w-6xl aspect-video">
                    <video
                        ref="heroVideo"
                        class="w-full h-full object-contain rounded-lg"
                        autoplay
                        controls
                        playsinline
                    >
                        <source src="/video/loyola_tan.mp4" type="video/mp4">
                        Your browser does not support the video tag.
                    </video>
                </div>

                <!-- Video Info -->
                <div class="absolute bottom-4 left-4 right-4 text-center">
                    <p class="text-white text-lg">
                        LOYOLA Gardens of Tanauan - A Perfect Sanctuary
                    </p>
                </div>
            </div>
        </div>
    </div>

  </template>