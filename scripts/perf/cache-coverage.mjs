/**
 * ĐO ĐỘ PHỦ CACHE THẬT — không suy từ code, mà từ hành vi của Valkey.
 *
 * Với mỗi endpoint đọc: xoá sạch cache → gọi một lần → đếm số khoá MỚI xuất hiện
 * trong Valkey. Có khoá mới ⇒ endpoint đó thật sự ghi cache. Không có ⇒ nó đọc
 * thẳng DB mỗi lần, bất kể trong code trông như thế nào (nhiều controller cache ở
 * tầng repository nên phân tích tĩnh ở tầng controller sẽ đếm thiếu).
 *
 *   node scripts/perf/cache-coverage.mjs
 *
 * Bỏ qua khoá của lớp auth (`auth:user:*`) khi đếm: mọi request đã xác thực đều
 * sinh ra chúng, nên chúng không nói gì về việc endpoint có cache payload hay không.
 */
import { execSync } from 'node:child_process';
import fs from 'node:fs';

const BASE = process.env.BASE_URL ?? 'http://localhost:8080';
const EMAIL = process.env.BENCH_EMAIL ?? 'admin-01@gmail.com';
const PASSWORD = process.env.BENCH_PASSWORD ?? 'Cntt@1234';
const OUT_DIR = process.env.BENCH_OUT ?? 'build/perf';

const sh = (cmd) => execSync(cmd, { maxBuffer: 64 * 1024 * 1024 }).toString();

function cacheKeys() {
  const raw = sh('docker compose exec -T valkey redis-cli -n 1 --scan --count 5000');
  return raw
    .split('\n')
    .map((line) => line.trim())
    .filter(Boolean)
    .filter((key) => !key.includes(':auth:user:'));
}

const flush = () => sh('docker compose exec -T backend php artisan cache:clear');

const routes = JSON.parse(sh('docker compose exec -T backend php artisan route:list --json'));
const targets = [
  ...new Set(
    routes
      .filter((r) => {
        const methods = String(r.method).split('|');
        return methods.includes('GET') && !methods.includes('POST') && r.uri.startsWith('api/');
      })
      .map((r) => '/' + r.uri)
      .filter((u) => !u.includes('{'))
      .filter((u) => !/documentation|oauth2|export-excel|download|template/.test(u)),
  ),
];

const loginRes = await fetch(`${BASE}/api/v1/auth/login`, {
  method: 'POST',
  headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
  body: JSON.stringify({ email: EMAIL, password: PASSWORD }),
});
const token = (await loginRes.json())?.data?.access_token;
if (!token) throw new Error('không lấy được JWT');

const rows = [];
for (const path of targets) {
  flush();
  const before = cacheKeys().length;
  const res = await fetch(BASE + path, {
    headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
  });
  await res.arrayBuffer();
  const after = cacheKeys();
  const added = after.length - before;
  rows.push({
    path,
    status: res.status,
    cached: added > 0,
    keysAdded: added,
    sample: added > 0 ? (after[after.length - 1] ?? '').replace(/^wms[^:]*:[^:]+:/, '') : '',
  });
}

const ok = rows.filter((r) => r.status === 200);
const cached = ok.filter((r) => r.cached);
console.log('path'.padEnd(58) + 'st'.padEnd(6) + 'cached'.padEnd(8) + 'khoá');
for (const r of rows) {
  console.log(
    r.path.padEnd(58) + String(r.status).padEnd(6) + (r.cached ? 'CÓ' : 'không').padEnd(8) + r.sample.slice(0, 60),
  );
}
console.log('\n=== ĐỘ PHỦ CACHE (chỉ tính endpoint trả 200) ===');
console.log('tổng endpoint đọc đo được :', ok.length);
console.log('có ghi cache              :', cached.length);
console.log('không ghi cache           :', ok.length - cached.length);
console.log('tỉ lệ                     :', ((cached.length / Math.max(1, ok.length)) * 100).toFixed(1) + '%');
console.log('\nKhông ghi cache:');
for (const r of ok.filter((x) => !x.cached)) console.log('  ' + r.path);

fs.mkdirSync(OUT_DIR, { recursive: true });
fs.writeFileSync(`${OUT_DIR}/cache-coverage.json`, JSON.stringify(rows, null, 1));
console.log('\nĐã ghi ' + OUT_DIR + '/cache-coverage.json');
