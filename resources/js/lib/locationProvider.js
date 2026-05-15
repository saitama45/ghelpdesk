import { Capacitor } from '@capacitor/core';
import { Geolocation } from '@capacitor/geolocation';

const isNative = Capacitor.isNativePlatform();

const normalizePosition = (position) => {
    const coords = position?.coords ?? {};

    return {
        coords: {
            latitude: Number(coords.latitude),
            longitude: Number(coords.longitude),
            accuracy: Number(coords.accuracy ?? Number.POSITIVE_INFINITY),
        },
        timestamp: position?.timestamp ?? Date.now(),
        receivedAt: Date.now(),
    };
};

const normalizeError = (error) => {
    if (!error) {
        return { code: 'UNKNOWN', message: 'Location failed.' };
    }

    if (typeof error === 'string') {
        return { code: 'UNKNOWN', message: error };
    }

    return {
        code: error.code ?? 'UNKNOWN',
        message: error.message ?? 'Location failed.',
    };
};

export const isNativeLocationClient = () => isNative;

export const getLocationClient = () => (isNative ? 'native' : 'web');

export const getLocationProvider = () => (isNative ? 'capacitor' : 'browser');

export const getLocationPlatform = () => Capacitor.getPlatform();

export const getCurrentLocation = async ({ highAccuracy = true } = {}) => {
    if (isNative) {
        await Geolocation.requestPermissions();

        const position = await Geolocation.getCurrentPosition({
            enableHighAccuracy: highAccuracy,
            timeout: highAccuracy ? 15000 : 30000,
            maximumAge: highAccuracy ? 0 : 15000,
        });

        return normalizePosition(position);
    }

    if (!navigator.geolocation) {
        throw new Error('Geolocation is not supported by this browser.');
    }

    return new Promise((resolve, reject) => {
        navigator.geolocation.getCurrentPosition(
            (position) => resolve(normalizePosition(position)),
            (error) => reject(normalizeError(error)),
            {
                enableHighAccuracy: highAccuracy,
                timeout: highAccuracy ? 15000 : 30000,
                maximumAge: highAccuracy ? 0 : 15000,
            }
        );
    });
};

export const startLocationWatch = async ({ highAccuracy = true, onPosition, onError }) => {
    if (isNative) {
        await Geolocation.requestPermissions();

        const id = await Geolocation.watchPosition(
            {
                enableHighAccuracy: highAccuracy,
                timeout: highAccuracy ? 15000 : 30000,
                maximumAge: highAccuracy ? 0 : 15000,
            },
            (position, error) => {
                if (error) {
                    onError?.(normalizeError(error));
                    return;
                }

                if (!position) {
                    return;
                }

                onPosition?.(normalizePosition(position));
            }
        );

        return {
            stop: async () => {
                await Geolocation.clearWatch({ id });
            },
        };
    }

    if (!navigator.geolocation) {
        throw new Error('Geolocation is not supported by this browser.');
    }

    const id = navigator.geolocation.watchPosition(
        (position) => onPosition?.(normalizePosition(position)),
        (error) => onError?.(normalizeError(error)),
        {
            enableHighAccuracy: highAccuracy,
            timeout: highAccuracy ? 15000 : 30000,
            maximumAge: highAccuracy ? 0 : 15000,
        }
    );

    return {
        stop: async () => {
            navigator.geolocation.clearWatch(id);
        },
    };
};
