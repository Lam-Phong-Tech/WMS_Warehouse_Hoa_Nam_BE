/**
 * Đọc các file `--summary-export` của k6 trong load/results và in một bảng so sánh.
 *
 *   node load/summarize.mjs                      # mọi file trong load/results
 *   node load/summarize.mjs baseline-perf-1replica baseline-perf-2replica
 *
 * Cột: tải đích (req/s, tính từ USERS/THINK ghi trong tag nếu có), thông lượng đạt,
 * p50/p95/p99 của request thành công, tỉ lệ lỗi, dropped_iterations (open model:
 * số lượt k6 KHÔNG phát được vì hệ thống không nhận kịp — dấu hiệu bão hoà).
 */
import fs from 'node:fs';
import path from 'node:path';

const dir = path.join(path.dirname(new URL(import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, '$1')), 'results');
const wanted = process.argv.slice(2);
const files = fs.readdirSync(dir).filter((f) => f.endsWith('.json') && (wanted.length === 0 || wanted.some((w) => f.includes(w))));

const ms = (v) => (v === undefined ? '—' : v >= 1000 ? (v / 1000).toFixed(2).replace('.', ',') + ' s' : Math.round(v) + ' ms');
const pct = (v) => (v === undefined ? '—' : (v * 100).toFixed(2).replace('.', ',') + ' %');
const pad = (s, n) => String(s).padEnd(n);

console.log(pad('lượt', 30), pad('đạt', 12), pad('p50', 9), pad('p95', 9), pad('p99', 9), pad('lỗi', 8), pad('dropped', 8), 'iterations');
for (const f of files) {
  const d = JSON.parse(fs.readFileSync(path.join(dir, f), 'utf8'));
  const m = d.metrics ?? {};
  const dur = m['http_req_duration{expected_response:true}'] ?? m.http_req_duration ?? {};
  const reqs = m.http_reqs ?? {};
  const failed = m.wms_failed_requests ?? m.http_req_failed ?? {};
  const dropped = m.dropped_iterations ?? {};
  const iters = m.iterations ?? {};
  console.log(
    pad(f.replace('.json', ''), 30),
    pad((reqs.rate ?? 0).toFixed(1).replace('.', ',') + ' req/s', 12),
    pad(ms(dur.med), 9), pad(ms(dur['p(95)']), 9), pad(ms(dur['p(99)']), 9),
    pad(pct(failed.value ?? failed.rate), 8), pad(dropped.count ?? 0, 8), iters.count ?? '—',
  );
}
