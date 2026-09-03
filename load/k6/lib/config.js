// Cấu hình chung cho bộ load-test WMS Hoà Nam.
//
// Mọi tham số đọc từ biến môi trường (k6 `-e KEY=VALUE`) để một script chạy
// được cả smoke lẫn kịch bản 3000 người dùng, trên máy dev lẫn server thật,
// mà không phải sửa file.

function num(name, fallback) {
  const raw = __ENV[name];
  if (raw === undefined || raw === '') return fallback;
  const parsed = Number(raw);
  return Number.isFinite(parsed) ? parsed : fallback;
}

function str(name, fallback) {
  const raw = __ENV[name];
  return raw === undefined || raw === '' ? fallback : raw;
}

function bool(name, fallback) {
  const raw = __ENV[name];
  if (raw === undefined || raw === '') return fallback;
  return ['1', 'true', 'yes', 'on'].includes(String(raw).toLowerCase());
}

export const CONFIG = {
  // Mặc định trỏ thẳng vào container nginx trong mạng `wms`. Chạy k6 cùng mạng
  // docker loại được lớp port-forward của Docker Desktop trên Windows — lớp đó
  // tự nó thêm hàng chục ms và bão hoà sớm, làm nhiễu số đo của backend.
  baseUrl: str('BASE_URL', 'http://nginx'),

  // Số người dùng đồng thời cần mô phỏng.
  users: num('USERS', 3000),

  // Thời gian "suy nghĩ" trung bình giữa hai thao tác của MỘT người dùng (giây).
  // 3000 người với think-time 8s => 375 request/s đổ vào hệ thống.
  thinkSeconds: num('THINK', 8),

  // smoke | baseline | target | stress
  profile: str('PROFILE', 'smoke'),

  // Tài khoản dùng để lấy JWT trong setup(). Load test KHÔNG đăng nhập lại mỗi
  // vòng lặp vì người dùng thật cũng chỉ đăng nhập một lần rồi dùng token.
  password: str('LOAD_PASSWORD', 'Cntt@1234'),
  usernames: str('LOAD_USERS', 'admin,manager01,keeper01,staff01,approver01,viewer01')
    .split(',')
    .map((s) => s.trim())
    .filter(Boolean),

  // Tỉ lệ request đăng nhập mới trên tổng số vòng lặp (mô phỏng người dùng vào
  // ca mới). Đặt 0 để tắt hẳn — login tốn bcrypt nên rất nặng CPU.
  loginRatio: num('LOGIN_RATIO', 0.01),

  // Bật nhóm kịch bản GHI (tạo phiếu, cập nhật). MẶC ĐỊNH TẮT để không sinh rác
  // vào cơ sở dữ liệu đang dùng.
  enableWrites: bool('ENABLE_WRITES', false),

  // Ngưỡng đạt/không đạt.
  p95Ms: num('P95_MS', 800),
  errorRate: num('ERROR_RATE', 0.01),

  tag: str('RUN_TAG', 'run'),
};

// Tốc độ request mục tiêu suy ra từ (số người dùng / think-time).
export const TARGET_RPS = Math.max(1, Math.round(CONFIG.users / CONFIG.thinkSeconds));

export function authHeaders(token) {
  return {
    headers: {
      Authorization: `Bearer ${token}`,
      Accept: 'application/json',
      'Content-Type': 'application/json',
    },
  };
}
