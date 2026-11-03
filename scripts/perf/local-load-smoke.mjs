// Read-only smoke test for a local server. This is not a production capacity test.
const url = new URL(process.env.LOAD_URL ?? 'http://127.0.0.1:18080/up');
if (!['127.0.0.1', 'localhost', '::1'].includes(url.hostname)) {
  throw new Error('Only loopback targets are allowed by this smoke test.');
}

const levels = (process.env.LOAD_LEVELS ?? '1,10,50,200,500').split(',').map(Number);
const timeoutMs = Number(process.env.LOAD_TIMEOUT_MS ?? 12000);
const token = process.env.LOAD_TOKEN;

for (const users of levels) {
  const start = performance.now();
  const samples = await Promise.all(Array.from({ length: users }, async () => {
    const begun = performance.now();
    try {
      const response = await fetch(url, {
        method: 'GET',
        cache: 'no-store',
        headers: token ? { Authorization: `Bearer ${token}` } : {},
        signal: AbortSignal.timeout(timeoutMs),
      });
      await response.arrayBuffer();
      return { status: response.status, ms: performance.now() - begun };
    } catch (error) {
      return { status: 0, ms: performance.now() - begun, error: error.name };
    }
  }));
  const elapsed = performance.now() - start;
  const success = samples.filter((s) => s.status === 200);
  const sorted = success.map((s) => s.ms).sort((a, b) => a - b);
  const percentile = (p) => sorted.length ? Math.round(sorted[Math.ceil(sorted.length * p) - 1]) : null;
  const result = {
    users,
    success: success.length,
    failed: samples.length - success.length,
    p50_ms: percentile(0.5),
    p95_ms: percentile(0.95),
    wall_ms: Math.round(elapsed),
    responses_per_second: Number((samples.length / (elapsed / 1000)).toFixed(1)),
  };
  console.log(JSON.stringify(result));
  if (result.failed || (result.p95_ms ?? 0) > timeoutMs * 0.8) {
    console.log('Stopped: this local server is already saturated at this level.');
    break;
  }
}
