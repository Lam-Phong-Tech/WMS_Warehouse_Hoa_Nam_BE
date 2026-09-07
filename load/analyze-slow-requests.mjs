/**
 * Gom log `slow_request` (App\Http\Middleware\SlowRequestLog) của các container
 * backend thành bảng theo route: số lần, p50/p95/max tổng thời gian, SQL ms trung
 * bình, số câu SQL, thời gian chờ lock cache, tỉ lệ miss cache.
 *
 *   docker logs --since 15m wms_warehouse_hoa_nam_be-backend-1 2>&1 | node load/analyze-slow-requests.mjs
 *   (docker logs ... backend-1; docker logs ... backend-2) 2>&1 | node load/analyze-slow-requests.mjs
 *
 * Trả lời câu "màn này lag" bằng route cụ thể thay vì đoán: nếu db_ms ≈ duration
 * thì nghẽn ở SQL; nếu lock_wait cao thì stampede; nếu cả hai nhỏ mà duration lớn
 * thì tiến trình đang chờ CPU (máy bão hoà) hoặc I/O ngoài SQL.
 */
import readline from 'node:readline';

const rows = [];
const rl = readline.createInterface({ input: process.stdin });
rl.on('line', (line) => {
  const i = line.indexOf('slow_request {');
  if (i < 0) return;
  const start = i + 'slow_request '.length;
  let depth = 0;
  let end = -1;
  for (let k = start; k < line.length; k += 1) {
    if (line[k] === '{') depth += 1;
    else if (line[k] === '}') { depth -= 1; if (depth === 0) { end = k + 1; break; } }
  }
  if (end < 0) return;
  try { rows.push(JSON.parse(line.slice(start, end))); } catch { /* bỏ dòng hỏng */ }
});
rl.on('close', () => {
  if (rows.length === 0) { console.log('không có dòng slow_request'); return; }
  const by = new Map();
  for (const r of rows) {
    const k = `${r.method} ${r.route}`;
    const g = by.get(k) ?? { n: 0, dur: [], db: 0, q: 0, lock: 0, hit: 0, miss: 0, st: {} };
    g.n += 1; g.dur.push(r.duration_ms); g.db += r.db_ms; g.q += r.db_queries; g.lock += r.cache_lock_wait_ms;
    g.hit += r.cache_hits; g.miss += r.cache_misses; g.st[r.status] = (g.st[r.status] ?? 0) + 1;
    by.set(k, g);
  }
  const pct = (a, p) => { const s = [...a].sort((x, y) => x - y); return s[Math.min(s.length - 1, Math.floor(s.length * p))]; };
  const pad = (s, n) => String(s).padEnd(n);
  const padl = (s, n) => String(s).padStart(n);
  console.log(`${rows.length} request chậm | ${by.size} route`);
  console.log(pad('route', 52), padl('n', 5), padl('p50', 7), padl('p95', 7), padl('max', 7), padl('db_ms', 7), padl('sql#', 5), padl('lock', 6), padl('miss%', 6), ' status');
  for (const [k, g] of [...by.entries()].sort((a, b) => b[1].n - a[1].n)) {
    const missPct = g.hit + g.miss ? Math.round((100 * g.miss) / (g.hit + g.miss)) : 0;
    console.log(pad(k.slice(0, 52), 52), padl(g.n, 5), padl(pct(g.dur, 0.5), 7), padl(pct(g.dur, 0.95), 7), padl(Math.max(...g.dur), 7), padl(Math.round(g.db / g.n), 7), padl((g.q / g.n).toFixed(1), 5), padl(Math.round(g.lock / g.n), 6), padl(missPct, 6), ' ' + JSON.stringify(g.st));
  }
  const totalDur = rows.reduce((s, r) => s + r.duration_ms, 0);
  const totalDb = rows.reduce((s, r) => s + r.db_ms, 0);
  const totalLock = rows.reduce((s, r) => s + r.cache_lock_wait_ms, 0);
  console.log(`\nphân rã tổng: duration ${Math.round(totalDur / 1000)} s = SQL ${Math.round((100 * totalDb) / totalDur)} % + chờ lock cache ${Math.round((100 * totalLock) / totalDur)} % + còn lại (CPU / chờ CPU / khác) ${Math.round((100 * (totalDur - totalDb - totalLock)) / totalDur)} %`);
});
