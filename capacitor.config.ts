import type { CapacitorConfig } from '@capacitor/cli';

const serverUrl = process.env.CAPACITOR_SERVER_URL || 'http://localhost:8000';

const config: CapacitorConfig = {
    appId: 'com.ghelpdesk.app',
    appName: 'GHelpdesk',
    webDir: 'public/build',
    server: {
        url: serverUrl,
        cleartext: serverUrl.startsWith('http://'),
    },
};

export default config;
