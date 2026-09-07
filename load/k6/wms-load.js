// ---------------------------------------------------------------------------
// Bộ đo tải WMS Hoà Nam — chạy bằng k6.
//
//   docker compose -f load/docker-compose.k6.yml run --rm k6 \
//     run -e PROFILE=target -e USERS=3000 /scripts/wms-load.js
//
// Mô hình tải: OPEN MODEL (constant/ramping-arrival-rate).
// Lý do: câu hỏi "3000 người dùng cùng lúc" là câu hỏi về LƯỢNG YÊU CẦU ĐỔ VÀO,
// không phải về số tiến trình k6. 3000 người, mỗi người thao tác một lần mỗi
// THINK giây, tạo ra USERS/THINK request mỗi giây. Open model giữ nguyên tốc độ
// đó kể cả khi hệ thống chậm lại, nên khi hệ thống quá tải ta THẤY được
// (dropped_iterations tăng, p95 tăng) thay vì bị closed model tự giảm tải và
// che mất điểm gãy.
// ---------------------------------------------------------------------------

import http from 'k6/http';
import { check, sleep } from 'k6';
import { Rate, Trend, Counter } from 'k6/metrics';
import { randomSeed } from 'k6';
import { CONFIG, TARGET_RPS, authHeaders } from './lib/config.js';
import { loginPool, pickToken } from './lib/auth.js';
import { pickFlow } from './lib/flows.js';

const failures = new Rate('wms_failed_requests');
const flowDuration = new Trend('wms_flow_duration', true);
const rateLimited = new Counter('wms_rate_limited');
const serverErrors = new Counter('wms_server_errors');

const PROFILES = {
  // Kiểm tra nhanh script và môi trường trước khi đo thật.
  smoke: {
    executor: 'constant-vus',
    vus: 5,
    duration: '30s',
  },

  // Mức nền: 1/4 tải mục tiêu, dùng để so sánh trước/sau khi tối ưu.
  baseline: {
    executor: 'ramping-arrival-rate',
    startRate: 5,
    timeUnit: '1s',
    preAllocatedVUs: Math.min(300, Math.max(50, Math.ceil(TARGET_RPS / 2))),
    maxVUs: Math.min(1200, Math.max(200, TARGET_RPS * 2)),
    stages: [
      { target: Math.max(5, Math.round(TARGET_RPS / 4)), duration: '1m' },
      { target: Math.max(5, Math.round(TARGET_RPS / 4)), duration: '3m' },
      { target: 0, duration: '30s' },
    ],
  },

  // Kịch bản chính: đạt đúng tải của USERS người dùng và giữ trong 5 phút.
  target: {
    executor: 'ramping-arrival-rate',
    startRate: 10,
    timeUnit: '1s',
    preAllocatedVUs: Math.min(800, Math.max(100, TARGET_RPS)),
    maxVUs: Math.min(3000, Math.max(400, TARGET_RPS * 4)),
    stages: [
      { target: Math.round(TARGET_RPS / 2), duration: '1m' },
      { target: TARGET_RPS, duration: '1m' },
      { target: TARGET_RPS, duration: '5m' },
      { target: 0, duration: '30s' },
    ],
  },

  // Tìm điểm gãy: đẩy tới 2x tải mục tiêu.
  stress: {
    executor: 'ramping-arrival-rate',
    startRate: 10,
    timeUnit: '1s',
    preAllocatedVUs: Math.min(1000, Math.max(200, TARGET_RPS)),
    maxVUs: Math.min(4000, Math.max(600, TARGET_RPS * 6)),
    stages: [
      { target: Math.round(TARGET_RPS / 2), duration: '1m' },
      { target: TARGET_RPS, duration: '2m' },
      { target: Math.round(TARGET_RPS * 1.5), duration: '2m' },
      { target: TARGET_RPS * 2, duration: '2m' },
      { target: 0, duration: '30s' },
    ],
  },
};

export const options = {
  discardResponseBodies: false,
  scenarios: {
    wms: PROFILES[CONFIG.profile] || PROFILES.smoke,
  },
  thresholds: {
    // Ngưỡng "mượt": 95% request dưới P95_MS và tỉ lệ lỗi dưới ERROR_RATE.
    'http_req_duration{expected_response:true}': [`p(95)<${CONFIG.p95Ms}`],
    // Ngưỡng theo NHÓM cố ý nới lỏng — chỉ để k6 ghi p50/p95/p99 của từng nhóm
    // vào --summary-export (k6 chỉ xuất sub-metric có threshold). Không phải gate;
    // gate thật là dòng trên + error rate + dropped_iterations.
    ...Object.fromEntries(
      ['dashboard', 'inventory', 'master', 'documents', 'reports', 'iam', 'notification', 'session', 'public']
        .map((g) => [`http_req_duration{group:${g}}`, ['p(99)<60000']]),
    ),
    wms_failed_requests: [`rate<${CONFIG.errorRate}`],
    // Iteration bị bỏ nghĩa là k6 không kịp phát request theo tốc độ yêu cầu —
    // dấu hiệu hệ thống (hoặc máy chạy k6) đã bão hoà.
    dropped_iterations: ['count<100'],
  },
  summaryTrendStats: ['avg', 'min', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
  tags: { run: CONFIG.tag, profile: CONFIG.profile },
};

export function setup() {
  randomSeed(20260903);
  const tokens = loginPool();
  const admin = tokens.find((t) => t.username === 'admin') || tokens[0];
  console.log(
    `setup: ${tokens.length} phiên đăng nhập | profile=${CONFIG.profile} | ` +
      `users=${CONFIG.users} think=${CONFIG.thinkSeconds}s => mục tiêu ${TARGET_RPS} req/s`,
  );
  return { tokens, admin };
}

export default function (data) {
  const flow = pickFlow(Math.random());

  let params;
  if (flow.roles === 'public') {
    params = { headers: { Accept: 'application/json' } };
  } else if (flow.roles === 'admin') {
    params = authHeaders(data.admin.token);
  } else {
    params = authHeaders(pickToken(data.tokens).token);
  }

  // Tag theo nhóm nghiệp vụ + đường dẫn để báo cáo tách được từng màn hình.
  params.tags = Object.assign({}, params.tags, {
    group: flow.group,
    name: flow.path.split('?')[0],
  });

  const res = http.get(`${CONFIG.baseUrl}${flow.path}`, params);

  const ok = check(res, {
    'HTTP 2xx': (r) => r.status >= 200 && r.status < 300,
  });

  failures.add(!ok);
  flowDuration.add(res.timings.duration, { group: flow.group });
  if (res.status === 429) rateLimited.add(1);
  if (res.status >= 500) serverErrors.add(1);

  // Trong open model, sleep KHÔNG điều tiết tốc độ phát request (executor lo
  // việc đó) nhưng giữ VU bận đúng như một người dùng thật đang đọc trang, nên
  // số VU đang hoạt động phản ánh đúng số người online.
  if (CONFIG.profile === 'smoke') {
    sleep(CONFIG.thinkSeconds / 4);
  }
}
