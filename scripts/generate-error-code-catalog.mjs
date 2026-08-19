#!/usr/bin/env node
/**
 * WWHN-309 - Sinh catalog error code của backend API v1 từ chính source code.
 *
 * LÝ DO: danh sách error code trước đây chỉ tồn tại rải rác trong controller/
 * service. Mọi tài liệu chép tay đều lệch ngay lần refactor kế tiếp. Script này
 * quét source, sinh docs/api-contract/HN-BE-API-002_ERROR_CODE_CATALOG.{md,json}
 * và có chế độ --check để CI fail khi catalog không còn khớp code.
 *
 * Nguồn quét:
 *   - App\Support\ApiError::abort(<http>, '<CODE>', ...)
 *   - ApiResponse::respondError(..., <http>, '<CODE>')
 *   - ApiResponse::respondConflict(..., '<CODE>')      -> 409
 *   - bootstrap/app.php: 'error_code' => '<CODE>' kèm HTTP status của response
 *   - bảng mặc định theo HTTP status trong ApiResponse::respondError
 *
 * Dùng:
 *   node scripts/generate-error-code-catalog.mjs            # ghi file
 *   node scripts/generate-error-code-catalog.mjs --check    # chỉ so sánh, exit 1 nếu lệch
 */
import { readFileSync, writeFileSync, readdirSync, statSync, existsSync } from "node:fs";
import { dirname, join, relative } from "node:path";
import { fileURLToPath } from "node:url";

const root = join(dirname(fileURLToPath(import.meta.url)), "..");
const scanRoots = ["app", "Modules", "bootstrap"].map((dir) => join(root, dir));
const mdPath = join(root, "docs/api-contract/HN-BE-API-002_ERROR_CODE_CATALOG.md");
const jsonPath = join(root, "docs/api-contract/HN-BE-API-002_ERROR_CODE_CATALOG.json");
const checkOnly = process.argv.includes("--check");

const STATUS_MEANING = {
  400: "BAD_REQUEST - request sai định dạng hoặc không parse được",
  401: "UNAUTHENTICATED - thiếu hoặc hết hạn access token",
  403: "ACCESS_DENIED - thiếu permission hoặc ngoài data scope",
  404: "RESOURCE_NOT_FOUND - không tồn tại hoặc nằm ngoài phạm vi dữ liệu",
  405: "METHOD_NOT_ALLOWED - HTTP method không được hỗ trợ",
  409: "STATE_CONFLICT - xung đột trạng thái, nghiệp vụ, đồng thời hoặc idempotency",
  412: "STALE_VERSION - If-Match không khớp version hiện tại",
  422: "BUSINESS_RULE_ERROR - vi phạm validation hoặc quy tắc nghiệp vụ",
  428: "PRECONDITION_REQUIRED - thiếu If-Match bắt buộc",
  429: "TOO_MANY_REQUESTS - vượt rate limit",
  500: "INTERNAL_SERVER_ERROR - lỗi ngoài dự kiến, giao dịch đã rollback",
  503: "SERVICE_UNAVAILABLE - dependency bắt buộc không sẵn sàng",
};

/**
 * WWHN-234: bốn lớp xung đột 409 của SRS §8.2 được phát qua hằng
 * `App\Support\ConflictCode` chứ không phải string literal, nên regex literal bên dưới
 * không nhìn thấy chúng. Nếu bỏ qua, catalog *im lặng* báo thiếu đúng những mã quan
 * trọng nhất và reviewer sẽ tưởng đường ghi tồn không phát `CONCURRENCY_CONFLICT`.
 *
 * Bảng này đọc thẳng từ file hằng để không trở thành bản chép tay thứ hai.
 *
 * @type {Record<string, string>}
 */
const CONFLICT_CODE_CONSTANTS = (() => {
  const file = join(root, "app/Support/ConflictCode.php");
  if (!existsSync(file)) return {};
  const source = readFileSync(file, "utf8");
  /** @type {Record<string, string>} */
  const map = {};
  for (const m of source.matchAll(
    /public const ([A-Z][A-Z0-9_]*)\s*=\s*'([A-Z][A-Z0-9_]{2,60})'/g,
  )) {
    map[m[1]] = m[2];
  }
  return map;
})();

/** @type {Map<string, {code: string, statuses: Set<number>, hits: number, first: string}>} */
const catalog = new Map();

function record(code, status, location) {
  if (!/^[A-Z][A-Z0-9_]{2,60}$/.test(code)) return;
  const entry = catalog.get(code) ?? { code, statuses: new Set(), hits: 0, first: location };
  if (Number.isInteger(status)) entry.statuses.add(status);
  entry.hits += 1;
  catalog.set(code, entry);
}

function* phpFiles(dir) {
  if (!existsSync(dir)) return;
  for (const name of readdirSync(dir)) {
    if (name === "vendor" || name === "node_modules") continue;
    const full = join(dir, name);
    const st = statSync(full);
    if (st.isDirectory()) yield* phpFiles(full);
    else if (name.endsWith(".php")) yield full;
  }
}

function lineOf(source, index) {
  return source.slice(0, index).split("\n").length;
}

/**
 * WWHN-217 (FE217-P1-05): `IDEMPOTENCY_CONFLICT` — một trong bốn lớp 409 bắt buộc của
 * SRS §8.2 — bị THIẾU khỏi catalog dù runtime phát nó thật (đo bằng
 * `tests/Feature/Wwhn217RuntimeErrorContractTest.php`). Nguyên nhân: mã đó không xuất
 * hiện dưới dạng literal cạnh HTTP status mà đi qua HAI lớp gián tiếp —
 * `IdempotencyStore::CONFLICT_PAYLOAD_DIFFERS = ConflictCode::IDEMPOTENCY`, rồi được gán
 * vào biến bên trong `switch ($status) { case 409: ... }` của ApiConventionMiddleware.
 * Cả hai regex cũ đều không nhìn thấy, nên hợp đồng công bố cho FE im lặng thiếu đúng
 * nhánh "phải sinh Idempotency-Key mới" — nhánh quyết định của luồng duplicate/retry.
 *
 * Bảng dưới đây phân giải mọi hằng lớp trỏ (trực tiếp hoặc bắc cầu) về một mã lỗi, để
 * pass quét chính nhận ra chúng ở bất kỳ vị trí nào, kể cả trong nhánh `case`.
 *
 * @type {Record<string, string>}
 */
const ALIASED_CODE_CONSTANTS = (() => {
  /** @type {Record<string, string>} */
  const map = {};
  for (const dir of scanRoots) {
    for (const file of phpFiles(dir)) {
      const className = file
        .replaceAll("\\", "/")
        .split("/")
        .pop()
        .replace(/\.php$/, "");
      const source = readFileSync(file, "utf8");

      for (const m of source.matchAll(
        /public const ([A-Z][A-Z0-9_]*)\s*=\s*ConflictCode::([A-Z][A-Z0-9_]*)\s*;/g,
      )) {
        const resolved = CONFLICT_CODE_CONSTANTS[m[2]];
        if (resolved) map[`${className}::${m[1]}`] = resolved;
      }
    }
  }
  for (const [constant, value] of Object.entries(CONFLICT_CODE_CONSTANTS)) {
    map[`ConflictCode::${constant}`] = value;
  }
  return map;
})();

/**
 * HTTP status của một tham chiếu hằng nằm trong thân `switch ($status)`: lấy nhãn
 * `case <status>:` gần nhất phía trước. Không tìm thấy thì trả undefined và mã sẽ bị
 * loại ở bước lọc `http_status.length > 0` — thà thiếu còn hơn gán bừa một hạng sai.
 */
function statusFromNearestCase(source, index) {
  const before = source.slice(0, index);
  const matches = [...before.matchAll(/case\s+(\d{3})\s*:/g)];
  if (matches.length === 0) return undefined;
  const status = Number(matches[matches.length - 1][1]);
  return status in STATUS_MEANING ? status : undefined;
}

for (const dir of scanRoots) {
  for (const file of phpFiles(dir)) {
    const source = readFileSync(file, "utf8");
    const rel = relative(root, file).replaceAll("\\", "/");

    // Hằng lỗi dùng gián tiếp: `$defaultErrorCode = IdempotencyStore::CONFLICT_PAYLOAD_DIFFERS;`
    for (const m of source.matchAll(/\b([A-Z][A-Za-z0-9_]*)::([A-Z][A-Z0-9_]*)\b/g)) {
      const resolved = ALIASED_CODE_CONSTANTS[`${m[1]}::${m[2]}`];
      if (!resolved) continue;
      const inlineStatus = source
        .slice(Math.max(0, m.index - 12), m.index)
        .match(/(\d{3})\s*,\s*$/);
      const status = inlineStatus
        ? Number(inlineStatus[1])
        : statusFromNearestCase(source, m.index);
      if (status === undefined || !(status in STATUS_MEANING)) continue;
      record(resolved, status, rel + ":" + lineOf(source, m.index));
    }

    // ApiError::abort(409, 'CODE', ...) và respondError(..., 409, 'CODE')
    for (const m of source.matchAll(/(\d{3})\s*,\s*'([A-Z][A-Z0-9_]{2,60})'/g)) {
      const status = Number(m[1]);
      if (!(status in STATUS_MEANING)) continue;
      record(m[2], status, rel + ":" + lineOf(source, m.index));
    }

    // ApiError::abort(409, ConflictCode::CONCURRENCY, ...) — xem CONFLICT_CODE_CONSTANTS.
    for (const m of source.matchAll(
      /(\d{3})\s*,\s*ConflictCode::([A-Z][A-Z0-9_]*)/g,
    )) {
      const status = Number(m[1]);
      if (!(status in STATUS_MEANING)) continue;
      const resolved = CONFLICT_CODE_CONSTANTS[m[2]];
      if (!resolved) {
        throw new Error(
          `${rel}:${lineOf(source, m.index)} dùng ConflictCode::${m[2]} nhưng hằng đó ` +
            "không tồn tại trong app/Support/ConflictCode.php.",
        );
      }
      record(resolved, status, rel + ":" + lineOf(source, m.index));
    }

    // respondConflict(..., 'CODE') -> 409
    for (const m of source.matchAll(/respondConflict\(([^;]*?)\)/gs)) {
      const code = m[1].match(/'([A-Z][A-Z0-9_]{2,60})'/);
      if (code) record(code[1], 409, rel + ":" + lineOf(source, m.index));
    }

    // 'error_code' => 'CODE' (handler toàn cục và payload dựng tay)
    for (const m of source.matchAll(/'error_code'\s*=>\s*'([A-Z][A-Z0-9_]{2,60})'/g)) {
      const tail = source.slice(m.index, m.index + 900);
      const status = tail.match(/\]\s*,\s*(\d{3})\s*\)/);
      record(m[1], status ? Number(status[1]) : undefined, rel + ":" + lineOf(source, m.index));
    }
  }
}

// Mặc định theo HTTP status (App\Traits\ApiResponse::respondError) - luôn hợp lệ
// kể cả khi caller không truyền errorCode.
for (const [status, meaning] of Object.entries(STATUS_MEANING)) {
  record(meaning.split(" - ")[0], Number(status), "app/Traits/ApiResponse.php (default map)");
}
record("RESOURCE_NOT_FOUND", 404, "app/Traits/ApiResponse.php::respondNotFound");
record("VALIDATION_ERROR", 422, "bootstrap/app.php (ValidationException)");

const rows = [...catalog.values()]
  .map((entry) => ({
    code: entry.code,
    http_status: [...entry.statuses].sort((a, b) => a - b),
    occurrences: entry.hits,
    first_seen: entry.first,
  }))
  .filter((row) => row.http_status.length > 0)
  .sort((a, b) => a.http_status[0] - b.http_status[0] || a.code.localeCompare(b.code));

const byStatus = new Map();
for (const row of rows) {
  for (const status of row.http_status) {
    if (!byStatus.has(status)) byStatus.set(status, []);
    byStatus.get(status).push(row);
  }
}

const json =
  JSON.stringify(
    {
      document: "HN-BE-API-002",
      title: "WMS Hoa Nam - Backend API v1 error code catalog",
      task: "WWHN-309",
      generator: "scripts/generate-error-code-catalog.mjs",
      envelope: {
        shape:
          "{success:false, message, error_code, errors, error:{code, details}, meta:{request_id, timestamp}}",
        note: "error_code và error.code luôn bằng nhau; client không được coi khoá nào là optional.",
      },
      total_codes: rows.length,
      codes: rows,
    },
    null,
    2,
  ) + "\n";

const BT = String.fromCharCode(96);
const code = (value) => BT + value + BT;

const md = [
  "# HN-BE-API-002 - Error code catalog (backend API v1)",
  "",
  "> **Tệp sinh tự động - không sửa tay.** Nguồn: " +
    code("scripts/generate-error-code-catalog.mjs") +
    " quét " +
    code("app/") +
    ", " +
    code("Modules/") +
    ", " +
    code("bootstrap/") +
    ".",
  "> Sửa error code trong code rồi chạy lại " +
    code("node scripts/generate-error-code-catalog.mjs") +
    ". CI chạy " +
    code("--check") +
    " và fail khi lệch.",
  "",
  "Task: WWHN-309 - [BE] Hoàn thiện tài liệu kiến trúc/API/vận hành.",
  "",
  "## 1. Envelope lỗi",
  "",
  BT + BT + BT + "json",
  "{",
  '  "success": false,',
  '  "message": "Thông điệp tiếng Việt hiển thị được cho người dùng",',
  '  "error_code": "STATE_CONFLICT",',
  '  "errors": { "field": ["chi tiết"] },',
  '  "error": { "code": "STATE_CONFLICT", "details": { "field": ["chi tiết"] } },',
  '  "meta": { "request_id": "uuid", "timestamp": "2026-08-18T10:00:00+07:00" }',
  "}",
  BT + BT + BT,
  "",
  code("error_code") +
    " và " +
    code("error.code") +
    " **luôn bằng nhau**. " +
    code("error_code") +
    " là khoá FE hiện dùng, " +
    code("error.code") +
    " là dạng lồng theo Annex C.",
  "Không client nào được coi một trong hai là optional. " +
    code("message") +
    " không bao giờ chứa stack trace, câu SQL hay secret.",
  "",
  "## 2. Ý nghĩa HTTP status",
  "",
  "| HTTP | Mã mặc định khi caller không truyền error code | Ý nghĩa |",
  "| --- | --- | --- |",
  ...Object.entries(STATUS_MEANING).map(([status, meaning]) => {
    const [defaultCode, text] = meaning.split(" - ");
    return "| " + status + " | " + code(defaultCode) + " | " + text + " |";
  }),
  "",
  "## 3. Danh mục error code (" + rows.length + " mã)",
  "",
  ...[...byStatus.keys()]
    .sort((a, b) => a - b)
    .flatMap((status) => [
      "### HTTP " + status,
      "",
      "| Error code | Số lần dùng | Vị trí đầu tiên |",
      "| --- | --- | --- |",
      ...byStatus
        .get(status)
        .map((row) => "| " + code(row.code) + " | " + row.occurrences + " | " + code(row.first_seen) + " |"),
      "",
    ]),
  "## 4. Quy tắc dùng cho FE/QA",
  "",
  "1. Bắt lỗi theo " + code("error_code") + ", không bắt theo " + code("message") + " (message có thể đổi từ ngữ).",
  "2. " +
    code("412 STALE_VERSION") +
    " và " +
    code("428 PRECONDITION_REQUIRED") +
    " thuộc cơ chế optimistic locking " +
    code("If-Match") +
    ": tải lại bản ghi rồi thử lại, không retry mù.",
  "3. " +
    code("409") +
    " là xung đột trạng thái/đồng thời: hiển thị thông điệp và cho người dùng làm mới, không tự động retry vì có thể trùng nghiệp vụ.",
  "4. " + code("422") + " gắn " + code("errors") + " theo field: map thẳng vào form.",
  "5. " +
    code("403") +
    " có hai nhánh: thiếu permission (" +
    code("ACCESS_DENIED") +
    "/" +
    code("PERMISSION_DENIED") +
    ") và ngoài phạm vi dữ liệu (" +
    code("WAREHOUSE_SCOPE_DENIED") +
    "/" +
    code("DATA_SCOPE_DENIED") +
    ").",
  "6. Mã mới phải được thêm trong code trước, chạy lại generator, rồi mới dùng ở FE.",
  "",
].join("\n");

if (checkOnly) {
  const problems = [];
  for (const [path, expected] of [
    [mdPath, md],
    [jsonPath, json],
  ]) {
    const rel = relative(root, path).replaceAll("\\", "/");
    if (!existsSync(path)) problems.push(rel + " chưa tồn tại");
    else if (readFileSync(path, "utf8") !== expected) problems.push(rel + " đã lệch so với source code");
  }
  if (problems.length) {
    problems.forEach((problem) => console.error("[error-catalog] ERROR: " + problem));
    console.error("[error-catalog] Chạy: node scripts/generate-error-code-catalog.mjs");
    process.exit(1);
  }
  console.log("[error-catalog] PASS: catalog khớp source code (" + rows.length + " error code).");
} else {
  writeFileSync(mdPath, md, "utf8");
  writeFileSync(jsonPath, json, "utf8");
  console.log("[error-catalog] Đã sinh " + rows.length + " error code -> " + relative(root, mdPath));
}
