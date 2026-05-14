<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import {
    ArrowPathIcon,
    CameraIcon,
    CheckCircleIcon,
    DevicePhoneMobileIcon,
    ExclamationCircleIcon,
    GlobeAsiaAustraliaIcon,
    MapPinIcon,
} from '@heroicons/vue/24/outline';
import { useConfirm } from '@/Composables/useConfirm';
import { usePermission } from '@/Composables/usePermission';
import {
    getLocationClient,
    getLocationPlatform,
    getLocationProvider,
    isNativeLocationClient,
    startLocationWatch,
} from '@/lib/locationProvider';

const props = defineProps({
    lastLog: Object,
    isSegmentComplete: Boolean,
    assignedStores: Array,
    totalAssignedCount: Number,
    todaySchedule: Object,
});

const PRECISE_LOCATION_ACCURACY_METERS = 100;
const PRECISE_LOCATION_TIMEOUT_MS = 15000;
const DEFAULT_GRACE_PERIOD_MINUTES = 30;
const LOCATION_SAMPLE_WINDOW_MS = 30000;
const MAX_LOCATION_SAMPLES = 5;

const isMounted = ref(true);
const isNativeApp = ref(isNativeLocationClient());

const video = ref(null);
const canvas = ref(null);
const capturedImage = ref(null);
const stream = ref(null);
const isCameraReady = ref(false);
const cameraError = ref(null);

const latitude = ref(null);
const longitude = ref(null);
const locationAccuracy = ref(null);
const locationMode = ref('idle');
const locationError = ref(null);
const locationHint = ref(null);
const locationAttemptProgress = ref(0);
const locationSamples = ref([]);
const isRefreshingLocation = ref(false);
let watchHandle = null;
let currentWatchHighAccuracy = true;
let locationAttemptInterval = null;
let preciseAttemptTimeout = null;

const mapElement = ref(null);
let map = null;
let marker = null;
let geofenceCircle = null;

const form = useForm({
    latitude: null,
    longitude: null,
    location_accuracy: null,
    location_client: getLocationClient(),
    location_provider: getLocationProvider(),
    photo: null,
    device_info: null,
    public_ip: null,
});

const activeScheduleStore = computed(() => props.todaySchedule?.store || null);
const requiresGeofencing = computed(() => Boolean(props.todaySchedule && props.todaySchedule.status !== 'WFH'));
const nextAction = computed(() => {
    if (!props.todaySchedule) return null;

    return !props.lastLog || props.lastLog.type === 'time_out' ? 'Time In' : 'Time Out';
});
const isTimeOutFlow = computed(() => nextAction.value === 'Time Out');

const presenceState = computed(() => {
    if (!props.lastLog) return 'not-started';

    return props.lastLog.type === 'time_in' ? 'in' : 'out';
});

const currentTime = ref(
    new Date().toLocaleTimeString('en-US', {
        timeZone: 'Asia/Manila',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
    })
);
const nowMs = ref(Date.now());
let clockInterval = null;
let mapsPromise = null;

const hasStoreCoordinates = (store) => {
    return store?.latitude !== null &&
        store?.latitude !== undefined &&
        store?.longitude !== null &&
        store?.longitude !== undefined;
};

const activeScheduleStoreHasCoordinates = computed(() => hasStoreCoordinates(activeScheduleStore.value));

const isMissingActiveGeofence = computed(() => {
    return Boolean(props.todaySchedule && requiresGeofencing.value && !activeScheduleStoreHasCoordinates.value);
});

const scheduleWindow = computed(() => {
    if (!props.todaySchedule?.start_time || !props.todaySchedule?.end_time) return null;

    const start = new Date(props.todaySchedule.start_time);
    const end = new Date(props.todaySchedule.end_time);
    const graceStart = new Date(start.getTime() - DEFAULT_GRACE_PERIOD_MINUTES * 60 * 1000);

    if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime())) return null;

    return { start, end, graceStart };
});

const isWithinScheduleWindow = computed(() => {
    if (isTimeOutFlow.value && props.todaySchedule && props.lastLog?.type === 'time_in') return true;
    if (!scheduleWindow.value) return false;

    return nowMs.value >= scheduleWindow.value.graceStart.getTime() &&
        nowMs.value <= scheduleWindow.value.end.getTime();
});

const scheduleWindowMessage = computed(() => {
    if (!scheduleWindow.value) return 'No active On-site, Off-site, or WFH schedule for your current time.';
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

const isRecentlyLogged = computed(() => {
    if (!props.lastLog?.created_at) return false;

    const loggedAt = new Date(props.lastLog.created_at).getTime();

    return loggedAt + 5 * 60 * 1000 > nowMs.value;
});

const recentLogCooldownLabel = computed(() => {
    if (!props.lastLog?.created_at) return '';

    const remaining = Math.max(0, new Date(props.lastLog.created_at).getTime() + 5 * 60 * 1000 - nowMs.value);
    const totalSeconds = Math.ceil(remaining / 1000);
    const minutes = Math.floor(totalSeconds / 60);
    const seconds = totalSeconds % 60;

    return `${minutes}:${String(seconds).padStart(2, '0')}`;
});

const activeScheduleStoreDistance = computed(() => {
    if (latitude.value === null || longitude.value === null || !activeScheduleStoreHasCoordinates.value) {
        return Infinity;
    }

    return calculateDistance(
        latitude.value,
        longitude.value,
        activeScheduleStore.value.latitude,
        activeScheduleStore.value.longitude
    );
});

const isPreciseEnough = computed(() => {
    return locationAccuracy.value !== null && locationAccuracy.value <= PRECISE_LOCATION_ACCURACY_METERS;
});

const isWithinStoreVicinity = computed(() => {
    if (!props.todaySchedule) return false;
    if (!requiresGeofencing.value) return true;
    if (latitude.value === null || longitude.value === null || !activeScheduleStoreHasCoordinates.value) return false;

    const radius = activeScheduleStore.value.radius_meters || 100;

    return activeScheduleStoreDistance.value <= radius;
});

const hasLocationFix = computed(() => latitude.value !== null && longitude.value !== null);

const locationSubmissionReady = computed(() => {
    if (!hasLocationFix.value) return false;
    return isWithinStoreVicinity.value;
});

const locationReadinessLabel = computed(() => {
    if (locationMode.value === 'ready' && requiresGeofencing.value && isNativeApp.value) return 'Precise Native GPS Ready';
    if (locationMode.value === 'ready' && requiresGeofencing.value) return 'Location Ready';
    if (locationMode.value === 'ready') return 'Location Ready';
    if (locationMode.value === 'approximate') return 'Approximate Fix';
    if (locationMode.value === 'error') return 'Location Error';

    return 'Securing Location';
});

const locationReadinessTone = computed(() => {
    if (locationMode.value === 'ready') return 'text-green-600 font-bold';
    if (locationMode.value === 'approximate') return 'text-amber-700 font-bold';
    if (locationMode.value === 'error') return 'text-red-600 font-medium';

    return 'text-orange-600 font-medium';
});

const locationDistanceLabel = computed(() => {
    if (!Number.isFinite(activeScheduleStoreDistance.value)) return 'Waiting for location';

    return `${Math.round(activeScheduleStoreDistance.value)}m from store`;
});

const canSave = computed(() => {
    return hasPermission('attendance.create') &&
        !!props.todaySchedule &&
        isWithinScheduleWindow.value &&
        !props.isSegmentComplete &&
        !isRecentlyLogged.value &&
        !!capturedImage.value &&
        locationSubmissionReady.value &&
        !form.processing;
});

const statusMessage = computed(() => {
    if (!hasPermission('attendance.create')) return 'You do not have permission to log attendance. Please contact your manager or administrator.';
    if (!props.todaySchedule) return 'No active On-site, Off-site, or WFH schedule for your current time.';
    if (!isWithinScheduleWindow.value) return scheduleWindowMessage.value;
    if (props.isSegmentComplete) return 'You have already completed Time In and Time Out for this schedule.';
    if (isRecentlyLogged.value) return `A log was already recorded recently. Please wait ${recentLogCooldownLabel.value} before logging again.`;
    if (isMissingActiveGeofence.value) {
        return activeScheduleStore.value
            ? `The active schedule store ${activeScheduleStore.value.name} has no GPS coordinates configured.`
            : 'The active schedule has no store assigned.';
    }
    if (!capturedImage.value) return 'Please take a selfie first.';
    if (!hasLocationFix.value) return 'Acquiring location...';
    if (locationMode.value === 'error') return locationError.value || 'Unable to secure your location.';
    if (requiresGeofencing.value && isNativeApp.value && !isPreciseEnough.value) {
        return 'Waiting for a precise native GPS fix within 100m accuracy. Enable precise/full accuracy location if your device is only giving an approximate pin.';
    }
    if (requiresGeofencing.value && !isWithinStoreVicinity.value) {
        return `You are outside the active schedule store vicinity for ${activeScheduleStore.value?.name ?? 'the scheduled store'} (${locationDistanceLabel.value}).`;
    }

    return `Ready to ${nextAction.value}`;
});

const updateClock = () => {
    if (!isMounted.value) return;

    const now = new Date();
    currentTime.value = now.toLocaleTimeString('en-US', {
        timeZone: 'Asia/Manila',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
    });
    nowMs.value = now.getTime();
};

const clearLocationAttemptTimers = () => {
    if (locationAttemptInterval) {
        clearInterval(locationAttemptInterval);
        locationAttemptInterval = null;
    }

    if (preciseAttemptTimeout) {
        clearTimeout(preciseAttemptTimeout);
        preciseAttemptTimeout = null;
    }
};

const startLocationAttemptProgress = () => {
    clearLocationAttemptTimers();
    locationAttemptProgress.value = 0;

    const startedAt = Date.now();
    locationAttemptInterval = setInterval(() => {
        const elapsed = Date.now() - startedAt;
        locationAttemptProgress.value = Math.min(100, Math.round((elapsed / PRECISE_LOCATION_TIMEOUT_MS) * 100));
    }, 250);

    preciseAttemptTimeout = setTimeout(() => {
        if (!isMounted.value) return;

        locationAttemptProgress.value = 100;

        if (hasLocationFix.value && isNativeApp.value && !isPreciseEnough.value) {
            locationMode.value = 'approximate';
            locationHint.value = requiresGeofencing.value
                ? 'The device is still reporting an approximate pin. Keep the app open, move to a clearer area, and enable precise/full accuracy location.'
                : 'Using the best available fix while location continues updating.';
        } else if (!hasLocationFix.value && !locationError.value) {
            locationHint.value = 'Still trying to obtain a location fix. Check that location services are enabled and move to an area with better signal.';
        }

        clearLocationAttemptTimers();

        if (currentWatchHighAccuracy) {
            void startTracking(false, false);
        }
    }, PRECISE_LOCATION_TIMEOUT_MS);
};

const getRecentLocationSamples = () => {
    const cutoff = Date.now() - LOCATION_SAMPLE_WINDOW_MS;

    return locationSamples.value.filter((sample) => sample.timestamp >= cutoff);
};

const applySelectedLocationSample = (sample) => {
    if (!sample) return;

    latitude.value = sample.latitude;
    longitude.value = sample.longitude;
    locationAccuracy.value = sample.accuracy;
    form.latitude = sample.latitude;
    form.longitude = sample.longitude;
    form.location_accuracy = sample.accuracy;
};

const updateSelectedLocationSample = () => {
    const recentSamples = getRecentLocationSamples();
    if (!recentSamples.length) return;

    locationSamples.value = recentSamples;

    const bestSample = [...recentSamples].sort((left, right) => {
        if (left.accuracy !== right.accuracy) {
            return left.accuracy - right.accuracy;
        }

        return right.timestamp - left.timestamp;
    })[0];

    applySelectedLocationSample(bestSample);

    if (!requiresGeofencing.value) {
        locationMode.value = 'ready';
        locationHint.value = null;
        locationAttemptProgress.value = 100;
        clearLocationAttemptTimers();
        return;
    }

    if (!isNativeApp.value || bestSample.accuracy <= PRECISE_LOCATION_ACCURACY_METERS) {
        locationMode.value = 'ready';
        locationHint.value = isWithinStoreVicinity.value
            ? null
            : 'GPS is precise now. Move within the assigned store radius to unlock attendance.';
        locationAttemptProgress.value = 100;
        clearLocationAttemptTimers();
        return;
    }

    locationMode.value = 'approximate';
    locationHint.value = 'Approximate/coarse location is still being reported. Enable precise/full accuracy location and wait for the fused GPS fix to improve.';
};

const recordLocationSample = (position) => {
    const sample = {
        latitude: position.coords.latitude,
        longitude: position.coords.longitude,
        accuracy: Number.isFinite(position.coords.accuracy) ? position.coords.accuracy : Number.POSITIVE_INFINITY,
        timestamp: position.timestamp ?? Date.now(),
    };

    locationSamples.value = [...getRecentLocationSamples(), sample]
        .sort((left, right) => right.timestamp - left.timestamp)
        .slice(0, MAX_LOCATION_SAMPLES);

    updateSelectedLocationSample();
};

const stopLocationTracking = async () => {
    const handle = watchHandle;
    watchHandle = null;

    if (handle) {
        await handle.stop();
    }

    clearLocationAttemptTimers();
    isRefreshingLocation.value = false;
};

const startTracking = async (highAccuracy = true, resetSamples = true) => {
    if (isMissingActiveGeofence.value) {
        locationMode.value = 'idle';
        return;
    }

    await stopLocationTracking();

    if (resetSamples) {
        locationSamples.value = [];
        latitude.value = null;
        longitude.value = null;
        locationAccuracy.value = null;
        form.latitude = null;
        form.longitude = null;
        form.location_accuracy = null;
    }

    currentWatchHighAccuracy = highAccuracy;
    locationError.value = null;
    locationHint.value = null;
    locationMode.value = 'acquiring';
    isRefreshingLocation.value = true;

    if (highAccuracy) {
        startLocationAttemptProgress();
    }

    try {
        watchHandle = await startLocationWatch({
            highAccuracy,
            onPosition: (position) => {
                if (!isMounted.value) return;

                isRefreshingLocation.value = false;
                locationError.value = null;
                recordLocationSample(position);
                void updateMap();
            },
            onError: (error) => {
                if (!isMounted.value) return;

                isRefreshingLocation.value = false;

                const normalizedMessage = error.message || 'Location failed.';
                const lowerMessage = normalizedMessage.toLowerCase();

                if (lowerMessage.includes('denied') || lowerMessage.includes('permission')) {
                    clearLocationAttemptTimers();
                    locationMode.value = 'error';
                    locationError.value = 'Location permission was denied. Allow precise/full accuracy location access and try again.';
                    return;
                }

                if (highAccuracy) {
                    locationHint.value = 'High-accuracy GPS is still settling. Falling back to broader fused location updates while waiting for a precise fix.';
                    void startTracking(false, false);
                    return;
                }

                if (hasLocationFix.value) {
                    locationMode.value = requiresGeofencing.value && !isPreciseEnough.value ? 'approximate' : 'ready';
                    locationHint.value = 'Location signal is unstable. The app will keep listening for a better fix.';
                    return;
                }

                clearLocationAttemptTimers();
                locationMode.value = 'error';
                locationError.value = normalizedMessage;
            },
        });
    } catch (error) {
        clearLocationAttemptTimers();
        isRefreshingLocation.value = false;
        locationMode.value = 'error';
        locationError.value = error?.message || 'Unable to start location services.';
    }
};

const refreshGps = async () => {
    await startTracking(true, true);
};

const startCamera = async () => {
    try {
        cameraError.value = null;
        stream.value = await navigator.mediaDevices.getUserMedia({
            video: { facingMode: 'user', width: { ideal: 640 }, height: { ideal: 480 } },
            audio: false,
        });

        if (video.value && isMounted.value) {
            video.value.srcObject = stream.value;
            isCameraReady.value = true;
        }
    } catch {
        if (isMounted.value) {
            cameraError.value = 'Camera access denied. Please check permissions.';
        }
    }
};

const stopCamera = () => {
    if (!stream.value) return;

    stream.value.getTracks().forEach((track) => track.stop());
    stream.value = null;
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

const loadGoogleMaps = () => {
    if (mapsPromise) return mapsPromise;

    mapsPromise = new Promise((resolve, reject) => {
        const key = window.config?.google_maps_api_key;
        if (!key) {
            reject(new Error('Google Maps API Key is missing.'));
            return;
        }

        if (window.google?.maps?.Map) {
            resolve();
            return;
        }

        if (document.querySelector('script[src*="maps.googleapis.com/maps/api/js"]')) {
            const checkInterval = setInterval(() => {
                if (window.google?.maps?.Map) {
                    clearInterval(checkInterval);
                    resolve();
                }
            }, 100);
            return;
        }

        window.initGoogleMapsCallback = () => {
            if (window.google?.maps?.Map) {
                resolve();
            }
        };

        const script = document.createElement('script');
        script.src = `https://maps.googleapis.com/maps/api/js?key=${key}&v=weekly&callback=initGoogleMapsCallback&loading=async`;
        script.async = true;
        script.defer = true;
        script.onerror = () => reject(new Error('Failed to load Google Maps script.'));
        document.head.appendChild(script);
    });

    return mapsPromise;
};

const updateMap = async () => {
    if (!mapElement.value || latitude.value === null || longitude.value === null) return;

    try {
        await loadGoogleMaps();

        if (!window.google?.maps?.Map) return;

        const currentPosition = { lat: latitude.value, lng: longitude.value };

        if (!map) {
            map = new window.google.maps.Map(mapElement.value, {
                center: currentPosition,
                zoom: 17,
                disableDefaultUI: true,
            });

            marker = new window.google.maps.Marker({
                position: currentPosition,
                map,
                title: 'You are here',
            });
        } else {
            map.setCenter(currentPosition);
            marker?.setPosition(currentPosition);
        }

        if (requiresGeofencing.value && hasStoreCoordinates(activeScheduleStore.value)) {
            const geofenceCenter = {
                lat: Number(activeScheduleStore.value.latitude),
                lng: Number(activeScheduleStore.value.longitude),
            };

            if (!geofenceCircle) {
                geofenceCircle = new window.google.maps.Circle({
                    strokeColor: '#2563EB',
                    strokeOpacity: 0.8,
                    strokeWeight: 2,
                    fillColor: '#2563EB',
                    fillOpacity: 0.15,
                    map,
                    center: geofenceCenter,
                    radius: activeScheduleStore.value.radius_meters || 100,
                });
            } else {
                geofenceCircle.setCenter(geofenceCenter);
                geofenceCircle.setRadius(activeScheduleStore.value.radius_meters || 100);
            }
        }
    } catch (error) {
        console.error('Map update failed', error);
    }
};

const calculateDistance = (lat1, lon1, lat2, lon2) => {
    const leftLat = Number(lat1);
    const leftLon = Number(lon1);
    const rightLat = Number(lat2);
    const rightLon = Number(lon2);

    if ([leftLat, leftLon, rightLat, rightLon].some(Number.isNaN)) return Infinity;

    const radius = 6371000;
    const deltaLat = (rightLat - leftLat) * Math.PI / 180;
    const deltaLon = (rightLon - leftLon) * Math.PI / 180;
    const a = Math.sin(deltaLat / 2) * Math.sin(deltaLat / 2) +
        Math.cos(leftLat * Math.PI / 180) * Math.cos(rightLat * Math.PI / 180) *
        Math.sin(deltaLon / 2) * Math.sin(deltaLon / 2);
    const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));

    return radius * c;
};

const getRefinedDeviceInfo = async () => {
    let info = navigator.userAgent;
    const hardwareDetails = [];

    try {
        const probeCanvas = document.createElement('canvas');
        const gl = probeCanvas.getContext('webgl') || probeCanvas.getContext('experimental-webgl');
        if (gl) {
            const debugInfo = gl.getExtension('WEBGL_debug_renderer_info');
            if (debugInfo) {
                const renderer = gl.getParameter(debugInfo.UNMASKED_RENDERER_WEBGL);
                hardwareDetails.push(`Hardware: ${renderer}`);
            }
        }
    } catch {
        // Ignore GPU probe failures.
    }

    hardwareDetails.push(`Display: ${window.screen.width}x${window.screen.height} (${window.devicePixelRatio}x)`);
    hardwareDetails.push(`Location Client: ${getLocationClient()}`);
    hardwareDetails.push(`Location Provider: ${getLocationProvider()}`);
    hardwareDetails.push(`Platform: ${getLocationPlatform()}`);

    if (navigator.userAgentData) {
        try {
            const highEntropy = await navigator.userAgentData.getHighEntropyValues([
                'model',
                'platform',
                'platformVersion',
                'architecture',
                'bitness',
            ]);

            const brands = navigator.userAgentData.brands.map((brand) => brand.brand).join('/');
            const model = highEntropy.model || 'Desktop/Laptop';
            const platform = highEntropy.platform || navigator.userAgentData.platform;
            const architecture = highEntropy.architecture
                ? `${highEntropy.architecture} ${highEntropy.bitness}-bit`
                : '';

            info = `OS: ${platform} ${highEntropy.platformVersion} (${architecture}) | Device: ${model} | ${hardwareDetails.join(' | ')} | Browser: ${brands}`;
        } catch {
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
    } catch {
        return null;
    }
};

const { confirm } = useConfirm();
const { hasPermission } = usePermission();

const submit = async () => {
    if (!canSave.value) return;

    const action = nextAction.value;
    const locationName = props.todaySchedule?.status === 'WFH'
        ? 'Work From Home'
        : (props.todaySchedule?.store?.name ?? 'your assigned store');

    const ok = await confirm({
        title: `Confirm ${action}`,
        message: `You are about to record ${action} at ${locationName}. Continue?`,
        confirmLabel: action,
        cancelLabel: 'Cancel',
        variant: 'primary',
    });

    if (!ok) return;

    form.location_client = getLocationClient();
    form.location_provider = getLocationProvider();
    form.location_accuracy = locationAccuracy.value;
    form.device_info = await getRefinedDeviceInfo();
    form.public_ip = await getPublicIp();

    form.post(route('attendance.log'), {
        preserveScroll: true,
        onSuccess: () => {
            capturedImage.value = null;
            form.reset();
            form.location_client = getLocationClient();
            form.location_provider = getLocationProvider();
            form.latitude = latitude.value;
            form.longitude = longitude.value;
            form.location_accuracy = locationAccuracy.value;
        },
    });
};

onMounted(async () => {
    await startCamera();

    if (!isMissingActiveGeofence.value) {
        await startTracking(true, true);
    }

    void loadGoogleMaps().catch(() => {});
    clockInterval = setInterval(updateClock, 1000);
});

onUnmounted(async () => {
    isMounted.value = false;
    stopCamera();
    await stopLocationTracking();

    if (clockInterval) {
        clearInterval(clockInterval);
    }
});
</script>

<template>
    <Head title="Daily Time Record" />

    <AppLayout>
        <template #header>
            Daily Time Record (DTR)
        </template>

        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg border border-gray-200">
            <div v-if="isMissingActiveGeofence" class="p-8 text-center bg-red-50">
                <ExclamationCircleIcon class="w-12 h-12 text-red-500 mx-auto mb-4" />
                <h3 class="text-lg font-bold text-red-900">Active Schedule Geofence Required</h3>
                <p v-if="todaySchedule?.store" class="text-red-700 max-w-md mx-auto">
                    The active schedule store {{ todaySchedule.store.name }} is missing GPS coordinates. Please contact HR to configure this store's location.
                </p>
                <p v-else class="text-red-700 max-w-md mx-auto">
                    The active schedule has no store assigned. Please contact your supervisor before logging attendance.
                </p>
            </div>

            <div v-else class="p-6 space-y-6">
                <div v-if="todaySchedule" class="flex items-center gap-3 bg-blue-50 border border-blue-200 rounded-xl px-4 py-3">
                    <div class="flex-shrink-0 w-9 h-9 bg-blue-100 rounded-lg flex items-center justify-center">
                        <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2Z" />
                        </svg>
                    </div>
                    <div class="min-w-0">
                        <p class="text-xs font-black text-blue-500 uppercase tracking-widest">Active Schedule</p>
                        <p class="text-sm font-bold text-blue-900">
                            <span
                                class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-black mr-2"
                                :class="todaySchedule.status === 'On-site' ? 'bg-green-100 text-green-700' : todaySchedule.status === 'WFH' ? 'bg-purple-100 text-purple-700' : 'bg-orange-100 text-orange-700'"
                            >
                                {{ todaySchedule.status }}
                            </span>
                            {{ todaySchedule.store?.name ?? 'WFH' }}
                            ·
                            {{ new Date(todaySchedule.start_time).toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', timeZone: 'Asia/Manila' }) }}
                            -
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
                        <p class="text-sm font-bold text-amber-900">No On-site, Off-site, or WFH schedule found for your current time. Attendance logging is disabled.</p>
                    </div>
                </div>

                <div
                    v-if="false"
                    class="rounded-2xl border border-amber-300 bg-amber-50 p-5 flex flex-col gap-3 md:flex-row md:items-center md:justify-between"
                >
                    <div class="flex items-start gap-3">
                        <DevicePhoneMobileIcon class="w-6 h-6 text-amber-700 mt-0.5" />
                        <div>
                            <p class="text-sm font-black text-amber-900 uppercase tracking-widest">Mobile App Required</p>
                            <p class="text-sm text-amber-800">
                                {{ todaySchedule?.status }} attendance uses native fused location now. Open this page inside the Capacitor mobile app to continue. Browser access remains available for WFH.
                            </p>
                        </div>
                    </div>
                    <div class="text-xs font-bold text-amber-800 bg-white/70 border border-amber-200 rounded-lg px-3 py-2">
                        Client: Web · Required: Native
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
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

                    <div class="space-y-4">
                        <div class="flex items-center justify-between gap-3">
                            <h3 class="text-lg font-bold text-gray-900 flex items-center gap-2">
                                <MapPinIcon class="w-5 h-5 text-red-600" />
                                2. Confirm Location
                            </h3>
                            <div class="flex items-center gap-2 flex-wrap justify-end">
                                <span class="text-[10px] font-bold px-2 py-1 rounded bg-slate-100 text-slate-700 uppercase">
                                    {{ isNativeApp ? 'Native App' : 'Web Browser' }}
                                </span>
                                <span v-if="todaySchedule?.status === 'WFH'" class="text-[10px] font-bold text-purple-600 flex items-center gap-1 bg-purple-50 px-2 py-1 rounded">
                                    <GlobeAsiaAustraliaIcon class="w-3 h-3" /> WFH MODE
                                </span>
                                <span v-else-if="isWithinStoreVicinity" class="text-[10px] font-bold text-blue-600 flex items-center gap-1 bg-blue-50 px-2 py-1 rounded">
                                    <CheckCircleIcon class="w-3 h-3" /> IN RANGE
                                </span>
                            </div>
                        </div>

                        <div v-if="false" class="rounded-lg border border-amber-200 bg-amber-50 p-5 text-sm text-amber-900">
                            <p class="font-bold mb-1">Browser geolocation is disabled for geofenced attendance.</p>
                            <p>Use the Android or iOS mobile app so attendance is checked against native fused GPS, Wi-Fi, and cellular location.</p>
                        </div>

                        <template v-else>
                            <div class="relative aspect-video bg-gray-100 rounded-lg border-2 overflow-hidden" :class="isWithinStoreVicinity ? 'border-blue-500' : 'border-gray-200'">
                                <div ref="mapElement" class="w-full h-full"></div>

                                <div v-if="!latitude || locationError" class="absolute inset-0 flex flex-col items-center justify-center bg-gray-50/90 p-4 text-center">
                                    <template v-if="!latitude && !locationError">
                                        <ArrowPathIcon class="w-8 h-8 animate-spin text-gray-400 mb-2" />
                                        <p class="text-gray-500">Acquiring location...</p>
                                    </template>
                                    <p v-else-if="locationError" class="text-red-500 text-sm font-medium">{{ locationError }}</p>
                                </div>
                            </div>

                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <p class="text-xs text-gray-500 uppercase tracking-widest font-black">Provider</p>
                                    <p class="text-sm font-bold text-gray-900">{{ form.location_provider }} · {{ getLocationPlatform() }}</p>
                                </div>
                                <SecondaryButton @click="refreshGps" :disabled="form.processing || isRefreshingLocation" class="justify-center px-4 py-2">
                                    <ArrowPathIcon class="w-4 h-4 mr-1" :class="isRefreshingLocation ? 'animate-spin' : ''" />
                                    Refresh GPS
                                </SecondaryButton>
                            </div>

                            <div class="space-y-3 rounded-xl border border-gray-200 p-4 bg-gray-50">
                                <div class="flex justify-between text-sm">
                                    <span class="text-gray-600 font-medium">GPS Accuracy</span>
                                    <span :class="locationReadinessTone">{{ locationReadinessLabel }}</span>
                                </div>
                                <div class="w-full bg-gray-200 rounded-full h-2">
                                    <div class="bg-blue-600 h-2 rounded-full transition-all duration-300" :style="{ width: `${locationAttemptProgress}%` }"></div>
                                </div>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
                                    <div class="rounded-lg bg-white border border-gray-200 px-3 py-2">
                                        <p class="text-[10px] uppercase tracking-widest text-gray-500 font-black">Accuracy</p>
                                        <p class="font-bold text-gray-900">
                                            <span v-if="locationAccuracy !== null">±{{ locationAccuracy.toFixed(1) }}m</span>
                                            <span v-else>Waiting for fix</span>
                                        </p>
                                    </div>
                                    <div class="rounded-lg bg-white border border-gray-200 px-3 py-2">
                                        <p class="text-[10px] uppercase tracking-widest text-gray-500 font-black">Distance To Store</p>
                                        <p class="font-bold text-gray-900">{{ requiresGeofencing ? locationDistanceLabel : 'Not required for WFH' }}</p>
                                    </div>
                                </div>
                                <p v-if="locationHint" class="text-[11px] text-amber-700 font-medium">
                                    {{ locationHint }}
                                </p>
                                <p v-if="latitude" class="text-[10px] text-gray-400 font-mono">
                                    {{ latitude.toFixed(6) }}, {{ longitude.toFixed(6) }}
                                </p>
                            </div>
                        </template>
                    </div>

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
                                    <div class="flex items-center gap-1.5 text-[9px] sm:text-[10px] uppercase font-black" :class="hasLocationFix ? 'text-green-600' : 'text-gray-400'">
                                        <CheckCircleIcon class="w-3 h-3 sm:w-3.5 sm:h-3.5" />
                                        Location
                                    </div>
                                    <div class="flex items-center gap-1.5 text-[9px] sm:text-[10px] uppercase font-black" :class="locationSubmissionReady ? 'text-green-600' : 'text-gray-400'">
                                        <CheckCircleIcon class="w-3 h-3 sm:w-3.5 sm:h-3.5" />
                                        Ready
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <canvas ref="canvas" style="display:none;"></canvas>
    </AppLayout>
</template>

<style scoped>
.mirrored {
    transform: scaleX(-1);
}
</style>
