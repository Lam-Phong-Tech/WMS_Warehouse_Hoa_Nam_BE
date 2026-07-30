import { frontendEnv } from './env';

const HEALTH_TIMEOUT_MS = 5000;

export async function checkFrontendHealth() {
    const startedAt = performance.now();
    const controller = new AbortController();
    const timeoutId = window.setTimeout(() => controller.abort(), HEALTH_TIMEOUT_MS);

    try {
        const response = await fetch(frontendEnv.healthEndpoint, {
            headers: {
                Accept: 'application/json, text/plain;q=0.9, */*;q=0.8',
            },
            signal: controller.signal,
        });

        const latencyMs = Math.round(performance.now() - startedAt);
        const body = await response.text();

        return {
            ok: response.ok,
            status: response.status,
            latencyMs,
            body: body.slice(0, 160),
            checkedAt: new Date().toISOString(),
        };
    } catch (error) {
        return {
            ok: false,
            status: 0,
            latencyMs: Math.round(performance.now() - startedAt),
            body: error.name === 'AbortError' ? 'Health check timeout' : error.message,
            checkedAt: new Date().toISOString(),
        };
    } finally {
        window.clearTimeout(timeoutId);
    }
}
