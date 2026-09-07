/**
 * Bộ đo tải NHẮM VÀO NHÓM ENDPOINT BÁO CÁO — bổ sung cho `load/k6/wms-load.js`.
 *
 * Vì sao có thêm bộ này bên cạnh k6: hồ sơ k6 đo MIX nghiệp vụ 31 endpoint để
 * tìm điểm gãy của cả hệ thống. Ở đây cần một câu hỏi hẹp hơn và trả lời được
 * nhanh: "nhóm /reports/* — nơi một request từng tốn 2 MB và vài giây — cư xử
 * thế nào khi nhiều vai trò gọi cùng lúc, và bản sửa khoá cache movement-trace
 * đổi được gì". Chạy bằng Node có sẵn nên không phải kéo image k6 lên.
 *
 * Chạy:
 *   node load/report-endpoints-load.mjs --concurrency 25 --seconds 30 --label after
 *
 * NHIỀU VAI TRÒ ĐỒNG THỜI: đăng nhập sẵn một pool tài khoản thuộc các vai khác
 * nhau rồi mỗi request bốc ngẫu nhiên một token — RBAC, data-scope theo kho và
 * cache mang scope đều bị đụng đồng thời, không phải chạy tuần tự từng vai.
 *
 * CẢNH BÁO KHI ĐỌC SỐ: bộ sinh tải chạy CÙNG MÁY với hệ thống bị đo, và stack
 * đang chạy là stack DEV (1 backend, mã phục vụ qua bind mount Windows) chứ
 * không phải hồ sơ chịu tải ở docker-compose.perf.yml. Mọi con số ở đây là CẬN
 * DƯỚI và KHÔNG được dùng để tuyên bố năng lực production — xem
 * docs/perf/LOAD_TEST_10000_USERS.md.
 */
const BASE = process.env.WMS_BASE_URL ?? "http://localhost:8080/api/v1";
const PASSWORD = process.env.WMS_TEST_PASSWORD ?? "Cntt@1234";

const ACCOUNTS = [
  "super-admin-01@gmail.com",
  "admin-01@gmail.com",
  "leadership-01@gmail.com",
  "warehouse-manager-01@gmail.com",
  "warehouse-staff-01@gmail.com",
  "viewer-01@gmail.com",
];

/**
 * Mix đọc của nhóm báo cáo. `weight` là số lần endpoint đó xuất hiện trong bánh
 * xe quay — phản ánh thói quen thật: mở dashboard nhiều, mở báo cáo nặng ít.
 */
const ENDPOINTS = [
  { name: "dashboard-quick", weight: 6, path: "/reports/dashboard-quick" },
  { name: "inventory-list", weight: 5, path: "/inventory?per_page=20" },
  { name: "warehouses", weight: 3, path: "/warehouses?per_page=20" },
  {
    name: "movement-trace-paged",
    weight: 4,
    path: "/reports/movement-trace?from_date=2026-01-01&to_date=2026-12-31&per_page=50&page=1",
  },
  {
    name: "movement-trace-full",
    weight: 2,
    path: "/reports/movement-trace?from_date=2026-01-01&to_date=2026-12-31",
  },
  {
    name: "inventory-balance",
    weight: 3,
    path: "/reports/inventory-balance?per_page=50",
  },
];

const args = process.argv.slice(2);
const argOf = (name, fallback) => {
  const i = args.indexOf(`--${name}`);
  return i >= 0 && args[i + 1] ? args[i + 1] : fallback;
};
const CONCURRENCY = Number(argOf("concurrency", "20"));
const SECONDS = Number(argOf("seconds", "30"));
const LABEL = argOf("label", "run");

const wheel = ENDPOINTS.flatMap((e) => Array(e.weight).fill(e));

async function login(email) {
  const res = await fetch(`${BASE}/auth/login`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ email, password: PASSWORD }),
  });
  if (!res.ok) return null;
  const body = await res.json();
  return body?.data?.access_token ?? null;
}

const percentile = (sorted, p) =>
  sorted.length === 0
    ? 0
    : sorted[Math.min(sorted.length - 1, Math.floor((sorted.length * p) / 100))];

async function main() {
  const tokens = (await Promise.all(ACCOUNTS.map(login))).filter(Boolean);
  if (tokens.length === 0) {
    console.error("Không đăng nhập được tài khoản nào — dừng.");
    process.exit(1);
  }
  console.log(
    `Đăng nhập ${tokens.length}/${ACCOUNTS.length} vai. Chạy ${CONCURRENCY} luồng trong ${SECONDS}s...`,
  );

  const stats = new Map();
  const record = (name, ms, ok, bytes) => {
    let s = stats.get(name);
    if (!s) {
      s = { times: [], errors: 0, bytes: 0, count: 0 };
      stats.set(name, s);
    }
    s.count += 1;
    s.bytes += bytes;
    if (ok) s.times.push(ms);
    else s.errors += 1;
  };

  const deadline = Date.now() + SECONDS * 1000;
  const worker = async () => {
    while (Date.now() < deadline) {
      const endpoint = wheel[Math.floor(Math.random() * wheel.length)];
      const token = tokens[Math.floor(Math.random() * tokens.length)];
      const started = performance.now();
      try {
        const res = await fetch(BASE + endpoint.path, {
          headers: { Authorization: `Bearer ${token}` },
        });
        const body = await res.arrayBuffer();
        // 403 là kết quả ĐÚNG của RBAC với vai không có quyền — không tính lỗi,
        // nếu không tỉ lệ lỗi sẽ nói dối về sức khoẻ hệ thống.
        const ok = res.status < 500 && res.status !== 429;
        record(endpoint.name, performance.now() - started, ok, body.byteLength);
      } catch {
        record(endpoint.name, performance.now() - started, false, 0);
      }
    }
  };

  const startedAt = Date.now();
  await Promise.all(Array.from({ length: CONCURRENCY }, worker));
  const elapsed = (Date.now() - startedAt) / 1000;

  let total = 0;
  let errors = 0;
  const all = [];
  console.log(
    `\n=== TẢI "${LABEL}" — ${CONCURRENCY} luồng, ${elapsed.toFixed(1)}s ===`,
  );
  console.log(
    "endpoint".padEnd(24) +
      "n".padStart(6) +
      "p50".padStart(9) +
      "p95".padStart(9) +
      "p99".padStart(9) +
      "lỗi".padStart(7) +
      "KB/req".padStart(9),
  );
  for (const [name, s] of stats) {
    const sorted = [...s.times].sort((a, b) => a - b);
    all.push(...sorted);
    total += s.count;
    errors += s.errors;
    console.log(
      name.padEnd(24) +
        String(s.count).padStart(6) +
        `${Math.round(percentile(sorted, 50))}ms`.padStart(9) +
        `${Math.round(percentile(sorted, 95))}ms`.padStart(9) +
        `${Math.round(percentile(sorted, 99))}ms`.padStart(9) +
        String(s.errors).padStart(7) +
        String(Math.round(s.bytes / Math.max(1, s.count) / 1024)).padStart(9),
    );
  }
  all.sort((a, b) => a - b);
  console.log(
    `\nTỔNG  ${total} request · ${(total / elapsed).toFixed(1)} req/s · lỗi ${((errors / Math.max(1, total)) * 100).toFixed(2)}%` +
      ` · p50 ${Math.round(percentile(all, 50))}ms · p95 ${Math.round(percentile(all, 95))}ms · p99 ${Math.round(percentile(all, 99))}ms`,
  );
}

await main();
