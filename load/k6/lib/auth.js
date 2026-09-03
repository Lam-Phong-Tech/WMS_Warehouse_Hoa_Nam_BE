import http from 'k6/http';
import { CONFIG } from './config.js';

/**
 * Đăng nhập một lần cho mỗi tài khoản trong setup() và chia sẻ token cho toàn
 * bộ VU. JWT là stateless nên đây đúng với hành vi thật: người dùng đăng nhập
 * đầu ca rồi gọi API bằng token đó suốt phiên.
 */
export function loginPool() {
  const tokens = [];

  for (const username of CONFIG.usernames) {
    const res = http.post(
      `${CONFIG.baseUrl}/api/v1/auth/login`,
      JSON.stringify({ username, password: CONFIG.password }),
      { headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, tags: { name: 'setup:login' } },
    );

    if (res.status !== 200) {
      console.warn(`setup: đăng nhập thất bại cho ${username} (HTTP ${res.status})`);
      continue;
    }

    let token = null;
    try {
      token = res.json('data.access_token');
    } catch (e) {
      token = null;
    }

    if (token) {
      tokens.push({ username, token });
    }
  }

  if (tokens.length === 0) {
    throw new Error('setup: không lấy được JWT nào — kiểm tra BASE_URL / LOAD_USERS / LOAD_PASSWORD');
  }

  return tokens;
}

/** Chọn token theo VU để tải trải đều trên nhiều tài khoản/vai trò khác nhau. */
export function pickToken(tokens) {
  return tokens[(__VU + __ITER) % tokens.length];
}
