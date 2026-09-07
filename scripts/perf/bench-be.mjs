/**
 * Đo độ trễ từng endpoint ĐỌC của API — cold (miss) và warm (hit).
 *
 * Không có con số nào được suy diễn: mỗi giá trị in ra là một vòng round-trip
 * thật, đo bằng đồng hồ tường, tới `BASE_URL`.
 *
 *   node scripts/perf/bench-be.mjs
 *   BASE_URL=http://localhost:8080 TAG=after node scripts/perf/bench-be.mjs
 *
 * Danh sách endpoint lấy TỪ CHÍNH bảng route của ứng dụng (`route:list --json`)
 * nên không bao giờ lệch với code. Bỏ qua route có tham số đường dẫn, route tải
 * file và route tài liệu — ba nhóm đó cần dữ liệu dựng sẵn, đo riêng.
 *
 * "cold" ở đây là lần gọi ĐẦU cho mỗi endpoint trong lượt chạy; muốn cold thật
 * sự thì `php artisan cache:clear` trước khi chạy.
 */
import fs from 'node:fs';
import { execSync } from 'node:child_process';

const BASE = process.env.BASE_URL ?? 'http://localhost:8080';
const TAG = process.env.TAG ?? 'run';
const EMAIL = process.env.BENCH_EMAIL ?? 'admin-01@gmail.com';
const PASSWORD = process.env.BENCH_PASSWORD ?? 'Cntt@1234';
const OUT_DIR = process.env.BENCH_OUT ?? 'build/perf';

function readRoutes() {
  if (process.env.ROUTES_JSON) {
    return JSON.parse(fs.readFileSync(process.env.ROUTES_JSON, 'utf8'));
  }
  const cmd =
    process.env.ARTISAN ??
    'docker compose exec -T backend php artisan route:list --json';
  return JSON.parse(execSync(cmd, { maxBuffer: 64 * 1024 * 1024 }).toString());
}

async function login() {
  const res = await fetch(`${BASE}/api/v1/auth/login`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({ email: EMAIL, password: PASSWORD }),
  });
  const body = await res.json();
  const token = body?.data?.access_token ?? body?.access_token;
  if (!token) throw new Error('không lấy được JWT: ' + JSON.stringify(body).slice(0, 300));
  return token;
}

async function timed(url, token) {
  const started = performance.now();
  try {
    const res = await fetch(url, {
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
    });
    const buf = await res.arrayBuffer();
    return { ms: +(performance.now() - started).toFixed(1), status: res.status, bytes: buf.byteLength };
  } catch {
    return { ms: +(performance.now() - started).toFixed(1), status: -1, bytes: 0 };
  }
}

const targets = [
  ...new Set(
    readRoutes()
      .filter((r) => {
        const methods = String(r.method).split('|');
        return methods.includes('GET') && !methods.includes('POST') && r.uri.startsWith('api/');
      })
      .map((r) => '/' + r.uri)
      .filter((u) => !u.includes('{'))
      .filter((u) => !/documentation|oauth2|export-excel|download|template/.test(u)),
  ),
];

const token = await login();
console.log('# endpoint đọc:', targets.length, ' base:', BASE);

const rows = [];
for (const path of targets) {
  const url = BASE + path;
  const cold = await timed(url, token);
  const warm = [];
  for (let i = 0; i < 3; i += 1) warm.push((await timed(url, token)).ms);
  warm.sort((a, b) => a - b);
  rows.push({ path, status: cold.status, bytes: cold.bytes, cold: cold.ms, warm: warm[1] });
}

rows.sort((a, b) => b.cold - a.cold);
console.log('\n' + 'path'.padEnd(58) + 'st'.padEnd(5) + 'cold_ms'.padStart(9) + 'warm_ms'.padStart(9) + 'KB'.padStart(9));
for (const r of rows) {
  console.log(
    r.path.padEnd(58) + String(r.status).padEnd(5) +
    String(r.cold).padStart(9) + String(r.warm).padStart(9) +
    (r.bytes / 1024).toFixed(0).padStart(9),
  );
}

const ok = rows.filter((r) => r.status === 200);
const pct = (arr, p) => (arr.length ? arr.slice().sort((a, b) => a - b)[Math.min(arr.length - 1, Math.floor(arr.length * p))] : 0);
const colds = ok.map((r) => r.cold);
const warms = ok.map((r) => r.warm);
console.log('\n=== TỔNG KẾT (chỉ tính status 200, n=' + ok.length + ') ===');
console.log('cold  p50=' + pct(colds, 0.5) + 'ms  p95=' + pct(colds, 0.95) + 'ms  max=' + Math.max(...colds) + 'ms');
console.log('warm  p50=' + pct(warms, 0.5) + 'ms  p95=' + pct(warms, 0.95) + 'ms  max=' + Math.max(...warms) + 'ms');
const bad = rows.filter((r) => r.status !== 200);
console.log('không phải 200:', bad.length ? bad.map((r) => r.status + ' ' + r.path).join(' | ') : 'không có');

fs.mkdirSync(OUT_DIR, { recursive: true });
fs.writeFileSync(`${OUT_DIR}/bench-be-${TAG}.json`, JSON.stringify(rows, null, 1));
console.log('\nĐã ghi ' + OUT_DIR + '/bench-be-' + TAG + '.json');
