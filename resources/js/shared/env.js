const trimTrailingSlash = (value) => String(value || '').replace(/\/+$/, '');

const defaultOrigin = window.location.origin;

export const frontendEnv = Object.freeze({
    appName: import.meta.env.VITE_APP_NAME || 'WMS_HoaNam',
    appEnv: import.meta.env.VITE_APP_ENV || import.meta.env.MODE || 'local',
    apiBaseUrl: trimTrailingSlash(import.meta.env.VITE_API_BASE_URL || `${defaultOrigin}/api/v1`),
    healthEndpoint: import.meta.env.VITE_HEALTH_ENDPOINT || `${defaultOrigin}/up`,
    buildVersion: import.meta.env.VITE_BUILD_VERSION || 'local-dev',
    buildSha: import.meta.env.VITE_BUILD_SHA || 'local',
});

export const isProductionLike = ['production', 'uat'].includes(frontendEnv.appEnv);
