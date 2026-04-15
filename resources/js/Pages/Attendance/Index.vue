<script setup>
import { ref, onMounted, onUnmounted, computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { CameraIcon, MapPinIcon, CheckCircleIcon, ArrowPathIcon, ExclamationCircleIcon, GlobeAsiaAustraliaIcon } from '@heroicons/vue/24/outline';
import { useConfirm } from '@/Composables/useConfirm';

const props = defineProps({
    lastLog: Object,
    isSegmentComplete: Boolean,
    assignedStores: Array,
    totalAssignedCount: Number,
    todaySchedule: Object,
});

// Component state
const isMounted = ref(true);

// Camera State
const video = ref(null);
const canvas = ref(null);
const capturedImage = ref(null);
const stream = ref(null);
const isCameraReady = ref(false);
const cameraError = ref(null);

// Location State
const latitude = ref(null);
const longitude = ref(null);
const locationAccuracy = ref(null);
const isLocationStable = ref(false);
const locationError = ref(null);
const stabilityProgress = ref(0);
let stabilityInterval = null;
let watchId = null;

// Map State
const mapElement = ref(null);
let map = null;
let marker = null;
let geofenceCircles = [];

const form = useForm({
    latitude: null,
    longitude: null,
    photo: null,
    device_info: null,
    public_ip: null,
});

const DEFAULT_GRACE_PERIOD_MINUTES = 30;

const nextAction = computed(() => {
    if (!props.todaySchedule) return null;
    return (!props.lastLog || props.lastLog.type === 'time_out') ? 'Time In' : 'Time Out';
});

const isTimeOutFlow = computed(() => nextAction.value === 'Time Out');

// Current presence status label and style for the status dot/text.
// Three states:
//   'not-started' — no log at all today (fresh day, user hasn't timed in yet)
//   'in'          — last log today was a time_in (currently on-site)
//   'out'         — last log today was a time_out (finished for the day)
const presenceState = computed(() => {
    if (!props.lastLog) return 'not-started';
    return props.lastLog.type === 'time_in' ? 'in' : 'out';
});

const currentTime = ref(new Date().toLocaleTimeString('en-US', { timeZone: 'Asia/Manila', hour: '2-digit', minute: '2-digit', second: '2-digit' }));
const nowMs = ref(Date.now());

const updateClock = () => {
    if (isMounted.value) {
        const now = new Date();
        currentTime.value = now.toLocaleTimeString('en-US', { timeZone: 'Asia/Manila', hour: '2-digit', minute: '2-digit', second: '2-digit' });
        nowMs.value = now.getTime();
    }
};

// True while the 5-minute duplicate-log cooldown is still active.
// Reacts to the clock tick so the button auto-unlocks without a page reload.
const isRecentlyLogged = computed(() => {
    if (!props.lastLog?.created_at) return false;
    const loggedAt = new Date(props.lastLog.created_at).getTime();
    return (loggedAt + 5 * 60 * 1000) > nowMs.value;
});

// Remaining cooldown as a "m:ss" string, e.g. "3:42"
const recentLogCooldownLabel = computed(() => {
    if (!props.lastLog?.created_at) return '';
    const remaining = Math.max(0, (new Date(props.lastLog.created_at).getTime() + 5 * 60 * 1000) - nowMs.value);
    const totalSecs = Math.ceil(remaining / 1000);
    const m = Math.floor(totalSecs / 60);
    const s = totalSecs % 60;
    return `${m}:${String(s).padStart(2, '0')}`;
});

const scheduleWindow = computed(() => {
    if (!props.todaySchedule?.start_time || !props.todaySchedule?.end_time) return null;

    const start = new Date(props.todaySchedule.start_time);
    const end = new Date(props.todaySchedule.end_time);
    const graceStart = new Date(start.getTime() - DEFAULT_GRACE_PERIOD_MINUTES * 60 * 1000);

    if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime())) return null;

    return {
        start,
        end,
        graceStart,
    };
});

const isWithinScheduleWindow = computed(() => {
    if (isTimeOutFlow.value && props.todaySchedule && props.lastLog?.type === 'time_in') return true;
    if (!scheduleWindow.value) return false;

    return nowMs.value >= scheduleWindow.value.graceStart.getTime() &&
           nowMs.value <= scheduleWindow.value.end.getTime();
});

const scheduleWindowMessage = computed(() => {
    if (!scheduleWindow.value) return 'No active On-site/Off-site schedule for your current time.';
    if (isTimeOutFlow.value && props.todaySchedule && props.lastLog?.type === 'time_in') return '';

    if (nowMs.value < scheduleWindow.value.graceStart.getTime()) {
        return `Time In will be available at ${scheduleWindow.value.graceStart.toLocaleTimeString('en-US', {
            hour: '2-digit',
            minute: '2-digit',
            timeZone: 'Asia/Manila',
        })}.`;
    }

    if (nowMs.value > scheduleWindow.value.end.getTime()) {
        return 'This schedule window has already ended.';
    }

    return '';
});
let clockInterval;

const loadGoogleMapsScript = () => {
    const key = window.config?.google_maps_api_key;
    if (!key) {
        locationError.value = "Google Maps API Key is missing in .env";
        return;
    }
    
    if (window.google && window.google.maps) {
        updateMap();
        return;
    }

    const script = document.createElement('script');
    script.src = `https://maps.googleapis.com/maps/api/js?key=${key}&libraries=marker&loading=async`;
    script.async = true;
    script.defer = true;
    script.onload = () => {
        if (isMounted.value) updateMap();
    };
    script.onerror = () => {
        locationError.value = "Failed to load Google Maps script.";
    };
    document.head.appendChild(script);
};

onMounted(() => {
    startCamera();
    startLocationTracking();
    loadGoogleMapsScript();
    clockInterval = setInterval(updateClock, 1000);
});

onUnmounted(() => {
    isMounted.value = false;
    stopCamera();
    stopLocationTracking();
    clearInterval(clockInterval);
    if (stabilityInterval) clearInterval(stabilityInterval);
});

// Camera Functions
const startCamera = async () => {
    try {
        cameraError.value = null;
        stream.value = await navigator.mediaDevices.getUserMedia({
            video: { facingMode: 'user', width: { ideal: 640 }, height: { ideal: 480 } },
            audio: false
        });
        if (video.value && isMounted.value) {
            video.value.srcObject = stream.value;
            isCameraReady.value = true;
        }
    } catch (err) {
        if (isMounted.value) {
            cameraError.value = "Camera access denied. Please check permissions.";
        }
    }
};

const stopCamera = () => {
    if (stream.value) {
        stream.value.getTracks().forEach(track => track.stop());
    }
};

const capturePhoto = () => {
    if (!video.value || !canvas.value) return;
    const context = canvas.value.getContext('2d');
    canvas.value.width = video.value.videoWidth;
    canvas.value.height = video.value.videoHeight;
    context.drawImage(video.value, 0, 0);
    capturedImage.value = canvas.value.toDataURL('image/jpeg', 0.8);
    form.photo = capturedImage.value;
};

const retakePhoto = () => {
    capturedImage.value = null;
    form.photo = null;
};

// Location Functions
const startLocationTracking = () => {
    if (!navigator.geolocation) {
        locationError.value = "Geolocation not supported.";
        return;
    }

    watchId = navigator.geolocation.watchPosition(
        (position) => {
            if (!isMounted.value) return;
            latitude.value = position.coords.latitude;
            longitude.value = position.coords.longitude;
            locationAccuracy.value = position.coords.accuracy;
            form.latitude = latitude.value;
            form.longitude = longitude.value;

            updateMap();

            if (locationAccuracy.value < 100) {
                startStabilityTimer();
            } else if (!isLocationStable.value) {
                // Only reset if we haven't achieved a stable lock yet
                resetStabilityTimer();
            }
        },
        (err) => {
            if (isMounted.value) locationError.value = "GPS Error: " + err.message;
        },
        { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
    );
};

const startStabilityTimer = () => {
    if (isLocationStable.value || stabilityInterval) return;
    stabilityInterval = setInterval(() => {
        stabilityProgress.value += 10;
        if (stabilityProgress.value >= 100) {
            isLocationStable.value = true;
            clearInterval(stabilityInterval);
            stabilityInterval = null;
        }
    }, 200);
};

const resetStabilityTimer = () => {
    isLocationStable.value = false;
    stabilityProgress.value = 0;
    if (stabilityInterval) {
        clearInterval(stabilityInterval);
        stabilityInterval = null;
    }
};

const stopLocationTracking = () => {
    if (watchId !== null) navigator.geolocation.clearWatch(watchId);
};

// Geofencing logic
const isWithinStoreVicinity = computed(() => {
    if (!latitude.value || !longitude.value || !props.assignedStores?.length) return false;
    
    return props.assignedStores.some(store => {
        const dist = calculateDistance(latitude.value, longitude.value, store.latitude, store.longitude);
        return dist <= store.radius_meters;
    });
});

const calculateDistance = (lat1, lon1, lat2, lon2) => {
    const R = 6371000; // Earth radius in meters
    const dLat = (lat2 - lat1) * Math.PI / 180;
    const dLon = (lon2 - lon1) * Math.PI / 180;
    const a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
              Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
              Math.sin(dLon / 2) * Math.sin(dLon / 2);
    const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    return R * c;
};

const updateMap = async () => {
    if (!mapElement.value || !latitude.value || !longitude.value) return;

    if (window.google && window.google.maps) {
        const pos = { lat: latitude.value, lng: longitude.value };
        
        try {
            if (!map) {
                // Try to use Advanced Markers first (requires Map ID)
                try {
                    const { Map } = await google.maps.importLibrary("maps");
                    const { AdvancedMarkerElement } = await google.maps.importLibrary("marker");
                    
                    if (!mapElement.value) return;

                    map = new Map(mapElement.value, {
                        center: pos,
                        zoom: 17,
                        mapId: 'DTR_MAP_ID',
                        disableDefaultUI: true,
                    });
                    
                    marker = new AdvancedMarkerElement({
                        map: map,
                        position: pos,
                        title: "You are here",
                    });

                    // Draw circles for assigned stores
                    props.assignedStores.forEach(store => {
                        if (store.latitude && store.longitude) {
                            const circle = new google.maps.Circle({
                                strokeColor: "#3B82F6",
                                strokeOpacity: 0.8,
                                strokeWeight: 2,
                                fillColor: "#3B82F6",
                                fillOpacity: 0.15,
                                map: map,
                                center: { lat: store.latitude, lng: store.longitude },
                                radius: store.radius_meters || 100,
                            });
                            geofenceCircles.push(circle);
                        }
                    });
                } catch (advError) {
                    console.warn("Advanced markers failed, falling back to standard markers", advError);
                    // Standard Fallback
                    map = new google.maps.Map(mapElement.value, {
                        center: pos,
                        zoom: 17,
                        disableDefaultUI: true,
                    });
                    marker = new google.maps.Marker({
                        position: pos,
                        map: map,
                        title: "You are here"
                    });
                    
                    props.assignedStores.forEach(store => {
                        if (store.latitude && store.longitude) {
                            const circle = new google.maps.Circle({
                                strokeColor: "#3B82F6",
                                strokeOpacity: 0.8,
                                strokeWeight: 2,
                                fillColor: "#3B82F6",
                                fillOpacity: 0.15,
                                map: map,
                                center: { lat: store.latitude, lng: store.longitude },
                                radius: store.radius_meters || 100,
                            });
                            geofenceCircles.push(circle);
                        }
                    });
                }
            } else {
                // Map already exists, just update position
                map.setCenter(pos);
                if (marker) {
                    if (typeof marker.setPosition === 'function') {
                        marker.setPosition(pos);
                    } else {
                        marker.position = pos;
                    }
                }
            }
        } catch (globalError) {
            console.error("Map update failed", globalError);
            locationError.value = "Map display error. Check API Key restrictions.";
        }
    }
};

const getRefinedDeviceInfo = async () => {
    let info = navigator.userAgent;
    let hardwareDetails = [];

    // 1. Extract GPU Information (The most specific hardware hint available in a browser)
    try {
        const canvas = document.createElement('canvas');
        const gl = canvas.getContext('webgl') || canvas.getContext('experimental-webgl');
        if (gl) {
            const debugInfo = gl.getExtension('WEBGL_debug_renderer_info');
            if (debugInfo) {
                const renderer = gl.getParameter(debugInfo.UNMASKED_RENDERER_WEBGL);
                hardwareDetails.push(`Hardware: ${renderer}`);
            }
        }
    } catch (e) {
        console.warn("GPU inspection failed", e);
    }

    // 2. Display Details
    hardwareDetails.push(`Display: ${window.screen.width}x${window.screen.height} (${window.devicePixelRatio}x)`);

    if (navigator.userAgentData) {
        try {
            const highEntropy = await navigator.userAgentData.getHighEntropyValues([
                'model', 
                'platform', 
                'platformVersion', 
                'architecture',
                'bitness'
            ]);
            
            const brands = navigator.userAgentData.brands
                .map(b => b.brand)
                .join('/');
                
            const model = highEntropy.model || 'Desktop/Laptop';
            const platform = highEntropy.platform || navigator.userAgentData.platform;
            const arch = highEntropy.architecture ? `${highEntropy.architecture} ${highEntropy.bitness}-bit` : '';
            
            info = `OS: ${platform} ${highEntropy.platformVersion} (${arch}) | Device: ${model} | ${hardwareDetails.join(' | ')} | Browser: ${brands}`;
        } catch (e) {
            info = `${info} | ${hardwareDetails.join(' | ')}`;
        }
    } else {
        info = `${info} | ${hardwareDetails.join(' | ')}`;
    }
    return info;
};

const getPublicIp = async () => {
    try {
        const response = await fetch('https://api.ipify.org?format=json');
        const data = await response.json();
        return data.ip;
    } catch (e) {
        console.warn("Could not fetch public IP", e);
        return null;
    }
};

const { confirm } = useConfirm();

const submit = async () => {
    if (!canSave.value) return;

    const action = nextAction.value; // 'Time In' or 'Time Out'
    const store  = props.todaySchedule?.store?.name ?? 'your assigned store';

    const ok = await confirm({
        title: `Confirm ${action}`,
        message: `You are about to record ${action} at ${store}. Continue?`,
        confirmLabel: action,
        cancelLabel: 'Cancel',
        variant: 'primary',
    });

    if (!ok) return;

    form.device_info = await getRefinedDeviceInfo();
    form.public_ip = await getPublicIp();

    form.post(route('attendance.log'), {
        preserveScroll: true,
        onSuccess: () => {
            capturedImage.value = null;
            form.reset();
        },
    });
};

const canSave = computed(() => {
    return !!props.todaySchedule &&
           isWithinScheduleWindow.value &&
           !props.isSegmentComplete &&
           !isRecentlyLogged.value &&
           !!capturedImage.value &&
           isLocationStable.value === true &&
           isWithinStoreVicinity.value === true &&
           !form.processing;
});

const statusMessage = computed(() => {
    if (!props.todaySchedule) return 'No active On-site/Off-site schedule for your current time.';
    if (!isWithinScheduleWindow.value) return scheduleWindowMessage.value;
    if (props.isSegmentComplete) return 'You have already completed Time In and Time Out for this schedule.';
    if (isRecentlyLogged.value) return `A log was already recorded recently. Please wait ${recentLogCooldownLabel.value} before logging again.`;
    if (props.assignedStores.length === 0) return 'No assigned work sites found.';
    if (!capturedImage.value) return 'Please take a selfie first.';
    if (!latitude.value) return 'Acquiring GPS...';
    if (!isLocationStable.value) return 'Waiting for location to stabilize...';
    if (!isWithinStoreVicinity.value) return 'You are outside the office vicinity.';
    return `Ready to ${nextAction.value}`;
});

</script>

<template>
    <Head title="Daily Time Record" />

    <AppLayout>
        <template #header>
            Daily Time Record (DTR)
        </template>

        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg border border-gray-200">
            <div v-if="assignedStores.length === 0" class="p-8 text-center bg-red-50">
                <ExclamationCircleIcon class="w-12 h-12 text-red-500 mx-auto mb-4" />
                <h3 class="text-lg font-bold text-red-900">Geofencing Restricted</h3>
                <p v-if="totalAssignedCount > 0" class="text-red-700 max-w-md mx-auto">
                    You have {{ totalAssignedCount }} work site(s) assigned, but they are missing GPS coordinates or are inactive. Please contact HR to configure your work site's location.
                </p>
                <p v-else class="text-red-700 max-w-md mx-auto">
                    No active work sites are assigned to your account. You must be assigned to a specific office or store to use the DTR module.
                </p>
            </div>

            <div v-else class="p-6 space-y-6">

                <!-- Schedule Status Banner -->
                <div v-if="todaySchedule" class="flex items-center gap-3 bg-blue-50 border border-blue-200 rounded-xl px-4 py-3">
                    <div class="flex-shrink-0 w-9 h-9 bg-blue-100 rounded-lg flex items-center justify-center">
                        <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                        </svg>
                    </div>
                    <div class="min-w-0">
                        <p class="text-xs font-black text-blue-500 uppercase tracking-widest">Active Schedule</p>
                        <p class="text-sm font-bold text-blue-900">
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-black mr-2"
                                :class="todaySchedule.status === 'On-site' ? 'bg-green-100 text-green-700' : 'bg-purple-100 text-purple-700'">
                                {{ todaySchedule.status }}
                            </span>
                            {{ todaySchedule.store?.name ?? 'Unknown Store' }}
                            &nbsp;·&nbsp;
                            {{ new Date(todaySchedule.start_time).toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', timeZone: 'Asia/Manila' }) }}
                            –
                            {{ new Date(todaySchedule.end_time).toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', timeZone: 'Asia/Manila' }) }}
                        </p>
                    </div>
                </div>
                <div v-else class="flex items-center gap-3 bg-amber-50 border border-amber-300 rounded-xl px-4 py-3">
                    <div class="flex-shrink-0 w-9 h-9 bg-amber-100 rounded-lg flex items-center justify-center">
                        <ExclamationCircleIcon class="w-5 h-5 text-amber-600" />
                    </div>
                    <div>
                        <p class="text-xs font-black text-amber-600 uppercase tracking-widest">No Active Schedule</p>
                        <p class="text-sm font-bold text-amber-900">No On-site or Off-site schedule found for your current time. Attendance logging is disabled. Please contact your supervisor.</p>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-8">

                <!-- Camera Section -->
                <div class="space-y-4">
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-bold text-gray-900 flex items-center gap-2">
                            <CameraIcon class="w-5 h-5 text-blue-600" />
                            1. Take a Selfie
                        </h3>
                        <span v-if="capturedImage" class="text-xs font-bold text-green-600 flex items-center gap-1 bg-green-50 px-2 py-1 rounded">
                            <CheckCircleIcon class="w-3 h-3" /> CAPTURED
                        </span>
                    </div>
                    
                    <div class="relative aspect-video bg-gray-900 rounded-lg overflow-hidden border-2" :class="capturedImage ? 'border-green-500' : 'border-gray-200'">
                        <video v-show="!capturedImage" ref="video" autoplay playsinline class="w-full h-full object-cover mirrored"></video>
                        <img v-if="capturedImage" :src="capturedImage" class="w-full h-full object-cover mirrored" />
                        
                        <div v-if="cameraError" class="absolute inset-0 flex items-center justify-center p-4 bg-gray-900/80 text-white text-center">
                            <p>{{ cameraError }}</p>
                        </div>
                        
                        <div v-if="!isCameraReady && !cameraError && !capturedImage" class="absolute inset-0 flex items-center justify-center">
                            <ArrowPathIcon class="w-10 h-10 text-white animate-spin" />
                        </div>
                    </div>
                    
                    <div class="flex justify-center gap-4">
                        <SecondaryButton v-if="!capturedImage" @click="capturePhoto" :disabled="!isCameraReady || form.processing" class="w-full justify-center py-3">
                            Capture Photo
                        </SecondaryButton>
                        <SecondaryButton v-else @click="retakePhoto" :disabled="form.processing" class="w-full justify-center py-3">
                            Retake Photo
                        </SecondaryButton>
                    </div>
                </div>

                <!-- Map Section -->
                <div class="space-y-4">
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-bold text-gray-900 flex items-center gap-2">
                            <MapPinIcon class="w-5 h-5 text-red-600" />
                            2. Confirm Location
                        </h3>
                        <div class="flex gap-2">
                            <span v-if="isLocationStable" class="text-[10px] font-bold text-green-600 flex items-center gap-1 bg-green-50 px-2 py-1 rounded">
                                <CheckCircleIcon class="w-3 h-3" /> STABLE
                            </span>
                            <span v-if="isWithinStoreVicinity" class="text-[10px] font-bold text-blue-600 flex items-center gap-1 bg-blue-50 px-2 py-1 rounded">
                                <GlobeAsiaAustraliaIcon class="w-3 h-3" /> IN VICINITY
                            </span>
                        </div>
                    </div>

                    <div class="relative aspect-video bg-gray-100 rounded-lg border-2 overflow-hidden" :class="isWithinStoreVicinity ? 'border-blue-500' : 'border-gray-200'">
                        <div ref="mapElement" class="w-full h-full"></div>
                        
                        <div v-if="!latitude || locationError" class="absolute inset-0 flex flex-col items-center justify-center bg-gray-50 p-4 text-center">
                            <template v-if="!latitude && !locationError">
                                <ArrowPathIcon class="w-8 h-8 animate-spin text-gray-400 mb-2" />
                                <p class="text-gray-500">Acquiring GPS...</p>
                            </template>
                            <p v-else-if="locationError" class="text-red-500 text-sm font-medium">{{ locationError }}</p>
                        </div>
                    </div>

                    <div class="space-y-2">
                        <div class="flex justify-between text-sm">
                            <span class="text-gray-600 font-medium">GPS Accuracy</span>
                            <span :class="isLocationStable ? 'text-green-600 font-bold' : 'text-orange-600 font-medium'">
                                {{ isLocationStable ? 'Location Secured' : 'Stabilizing Signal...' }}
                            </span>
                        </div>
                        <div class="w-full bg-gray-200 rounded-full h-2">
                            <div class="bg-blue-600 h-2 rounded-full transition-all duration-300" :style="{ width: stabilityProgress + '%' }"></div>
                        </div>
                        <p v-if="latitude" class="text-[10px] text-gray-400 font-mono text-center">
                            {{ latitude.toFixed(6) }}, {{ longitude.toFixed(6) }} (±{{ locationAccuracy?.toFixed(1) }}m)
                        </p>
                    </div>
                </div>

                <!-- Action Section -->
                <div class="md:col-span-2 mt-4 pt-6 border-t border-gray-100">
                    <div class="bg-gray-50 rounded-xl p-4 sm:p-6 flex flex-col md:flex-row items-center justify-between gap-6 border border-gray-100 shadow-inner">
                        <div class="text-center md:text-left">
                            <p class="text-[10px] sm:text-xs text-gray-500 uppercase tracking-widest font-black">Current Manila Time</p>
                            <p class="text-3xl sm:text-4xl font-black text-gray-900 tabular-nums">{{ currentTime }}</p>
                            <div class="flex items-center gap-2 mt-1 justify-center md:justify-start">
                                <div :class="['w-2 h-2 rounded-full animate-pulse', presenceState === 'in' ? 'bg-green-500' : presenceState === 'out' ? 'bg-red-500' : 'bg-gray-400']"></div>
                                <p class="text-xs sm:text-sm font-bold" :class="presenceState === 'in' ? 'text-green-600' : presenceState === 'out' ? 'text-red-600' : 'text-gray-500'">
                                    Status: {{ presenceState === 'in' ? 'IN' : presenceState === 'out' ? 'OUT' : 'Not Yet Logged' }}
                                </p>
                            </div>
                        </div>

                        <div class="flex flex-col items-center gap-3 w-full sm:w-auto">
                            <div v-if="!canSave" class="flex items-center gap-2 text-orange-600 bg-orange-50 px-3 py-2 rounded-lg border border-orange-100 text-[10px] sm:text-xs font-bold text-center">
                                <ExclamationCircleIcon class="w-4 h-4 flex-shrink-0" />
                                {{ statusMessage }}
                            </div>

                            <PrimaryButton 
                                @click="submit" 
                                :disabled="!canSave"
                                class="w-full sm:px-16 py-4 sm:py-5 text-lg sm:text-xl font-black shadow-xl uppercase tracking-widest transition-all"
                                :class="[
                                    canSave 
                                        ? (nextAction === 'Time In' ? 'bg-green-600 hover:bg-green-700 active:scale-95' : 'bg-orange-600 hover:bg-orange-700 active:scale-95') 
                                        : 'bg-gray-300'
                                ]"
                            >
                                <template v-if="form.processing">
                                    <ArrowPathIcon class="w-5 h-5 sm:w-6 sm:h-6 animate-spin mr-2" />
                                    Saving...
                                </template>
                                <template v-else>
                                    {{ nextAction }}
                                </template>
                            </PrimaryButton>
                            
                            <div class="flex gap-4 sm:gap-6">
                                <div class="flex items-center gap-1.5 text-[9px] sm:text-[10px] uppercase font-black" :class="capturedImage ? 'text-green-600' : 'text-gray-400'">
                                    <CheckCircleIcon class="w-3 h-3 sm:w-3.5 sm:h-3.5" />
                                    Selfie
                                </div>
                                <div class="flex items-center gap-1.5 text-[9px] sm:text-[10px] uppercase font-black" :class="isLocationStable ? 'text-green-600' : 'text-gray-400'">
                                    <CheckCircleIcon class="w-3 h-3 sm:w-3.5 sm:h-3.5" />
                                    Stable
                                </div>
                                <div class="flex items-center gap-1.5 text-[9px] sm:text-[10px] uppercase font-black" :class="isWithinStoreVicinity ? 'text-green-600' : 'text-gray-400'">
                                    <CheckCircleIcon class="w-3 h-3 sm:w-3.5 sm:h-3.5" />
                                    Vicinity
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                </div><!-- end grid -->
            </div>
        </div>
        <canvas ref="canvas" style="display:none;"></canvas>
    </AppLayout>
</template>

<style scoped>
.mirrored { transform: scaleX(-1); }
</style>

