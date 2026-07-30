import { existsSync, readFileSync } from 'node:fs';

const requiredFiles = [
    'resources/js/app.js',
    'resources/js/shared/env.js',
    'resources/js/shared/health.js',
    'resources/views/welcome.blade.php',
    'vite.config.js',
];

const requiredEnvKeys = [
    'VITE_APP_NAME',
    'VITE_APP_ENV',
    'VITE_API_BASE_URL',
    'VITE_HEALTH_ENDPOINT',
    'VITE_BUILD_VERSION',
    'VITE_BUILD_SHA',
];

const failures = [];

for (const file of requiredFiles) {
    if (!existsSync(file)) {
        failures.push(`Missing required frontend baseline file: ${file}`);
    }
}

const envExample = existsSync('.env.example') ? readFileSync('.env.example', 'utf8') : '';
for (const key of requiredEnvKeys) {
    if (!new RegExp(`^${key}=`, 'm').test(envExample)) {
        failures.push(`Missing frontend env key in .env.example: ${key}`);
    }
}

const appView = existsSync('resources/views/welcome.blade.php') ? readFileSync('resources/views/welcome.blade.php', 'utf8') : '';
for (const marker of ['data-state="loading"', 'data-state="success"', 'data-state="error"', 'data-state="empty"', 'data-state="permission"']) {
    if (!appView.includes(marker)) {
        failures.push(`Missing UI state marker in resources/views/welcome.blade.php: ${marker}`);
    }
}

const healthJs = existsSync('resources/js/shared/health.js') ? readFileSync('resources/js/shared/health.js', 'utf8') : '';
if (!healthJs.includes('fetch(') || !healthJs.includes('healthEndpoint')) {
    failures.push('Health service must call the configured healthEndpoint.');
}

if (failures.length > 0) {
    console.error(failures.join('\n'));
    process.exit(1);
}

console.log('Frontend env/build/health baseline verified.');
