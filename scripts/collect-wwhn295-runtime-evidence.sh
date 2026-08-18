#!/usr/bin/env bash
# WWHN-295 — thu bằng chứng runtime trên MÔI TRƯỜNG ĐÃ DEPLOY (DEV295-P1-04, P1-05).
#
# Script này KHÔNG tự sinh ra bằng chứng: nó gọi đúng backend đang chạy, buộc kết quả
# vào exact candidate SHA và ghi lại nguyên văn. Nguồn không thể tạo thay DevOps/QA các
# giá trị chỉ tồn tại trên UAT/PROD (trusted proxy thật, APP_BUILD_*, cấu hình log/queue,
# Valkey), nên phải chạy script này TRÊN chính môi trường đó rồi đính kèm thư mục output.
#
# Dùng:
#   EXPECTED_SHA=<candidate 40 hex> API_BASE_URL=https://uat.example/api/v1 \
#     ENVIRONMENT=uat bash scripts/collect-wwhn295-runtime-evidence.sh
#
# Tuỳ chọn:
#   OUTPUT_DIR=...        thư mục ghi bằng chứng (mặc định docs/evidence/WWHN-295/runtime/<env>-<sha8>)
#   SKIP_GOLIVE=1         bỏ qua wms:golive-check khi chạy ngoài container ứng dụng
#   CURL_EXTRA="-k"       tham số curl bổ sung (chỉ dùng khi có lý do ghi vào báo cáo)
set -euo pipefail

fail() { echo "[wwhn-295] FAIL: $*" >&2; exit 1; }

: "${EXPECTED_SHA:?EXPECTED_SHA (exact candidate SHA của WWHN-295) là bắt buộc}"
: "${API_BASE_URL:?API_BASE_URL (vd https://uat.example/api/v1) là bắt buộc}"
ENVIRONMENT="${ENVIRONMENT:-unknown}"
SKIP_GOLIVE="${SKIP_GOLIVE:-0}"
CURL_EXTRA="${CURL_EXTRA:-}"

case "$EXPECTED_SHA" in
  *[!0-9a-fA-F]*|"") fail "EXPECTED_SHA phải là hex" ;;
esac
[ "${#EXPECTED_SHA}" -ge 7 ] || fail "EXPECTED_SHA phải dài ít nhất 7 ký tự"

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SHORT_SHA="$(echo "$EXPECTED_SHA" | cut -c1-8)"
OUTPUT_DIR="${OUTPUT_DIR:-$ROOT/docs/evidence/WWHN-295/runtime/${ENVIRONMENT}-${SHORT_SHA}}"
mkdir -p "$OUTPUT_DIR"

HEALTH_URL="${API_BASE_URL%/}/health"
STARTED_AT="$(date -u +%Y-%m-%dT%H:%M:%SZ)"

echo "[wwhn-295] GET $HEALTH_URL"
HTTP_CODE="$(curl -sS $CURL_EXTRA \
  -o "$OUTPUT_DIR/health-body.json" \
  -D "$OUTPUT_DIR/health-headers.txt" \
  -w '%{http_code}' \
  -H 'Accept: application/json' \
  "$HEALTH_URL")" || fail "không gọi được $HEALTH_URL"

# Phân tích bằng chính PHP của artifact đang chạy — không phụ thuộc jq.
php -r '
$dir = $argv[1];
$expected = strtolower($argv[2]);
$code = (int) $argv[3];
$body = json_decode((string) file_get_contents($dir."/health-body.json"), true);
if (!is_array($body)) { fwrite(STDERR, "[wwhn-295] FAIL: health không trả JSON hợp lệ\n"); exit(1); }

// 200 đặt payload ở data; 503 dùng respondError nên payload ở errors/error.details.
$payload = $code === 200 ? ($body["data"] ?? null) : ($body["errors"] ?? null);
if (!is_array($payload)) { fwrite(STDERR, "[wwhn-295] FAIL: không tìm thấy payload health trong envelope\n"); exit(1); }

$problems = [];
foreach (["status","version","build","environment","server_time","request_id","checks"] as $field) {
    if (!array_key_exists($field, $payload)) { $problems[] = "thiếu trường ".$field; }
}
$sha = strtolower((string) ($payload["build"]["sha"] ?? ""));
if ($sha === "") { $problems[] = "build.sha rỗng — artifact không khai được danh tính"; }
elseif (!str_starts_with($sha, $expected)) { $problems[] = "build.sha ".$sha." KHÁC exact candidate ".$expected; }
if ($code !== 200 && $code !== 503) { $problems[] = "HTTP ".$code." ngoài hai kết cục hợp lệ"; }
if (($body["error_code"] ?? null) !== null && $code === 503 && $body["error_code"] !== "HEALTH_CHECK_DEGRADED") {
    $problems[] = "error_code 503 phải là HEALTH_CHECK_DEGRADED";
}

$summary = [
    "task" => "WWHN-295",
    "register" => ["DEV295-P1-04", "DEV295-P1-05"],
    "collected_at" => $argv[4],
    "environment_reported_by_backend" => $payload["environment"] ?? null,
    "expected_candidate_sha" => $expected,
    "observed" => [
        "http_status" => $code,
        "health_status" => $payload["status"] ?? null,
        "build" => $payload["build"] ?? null,
        "request_id" => $payload["request_id"] ?? null,
        "checks" => array_map(static fn ($check) => $check["status"] ?? null, (array) ($payload["checks"] ?? [])),
    ],
    "problems" => $problems,
];
file_put_contents($dir."/health-summary.json", json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
foreach ($problems as $problem) { fwrite(STDERR, "[wwhn-295] FAIL: ".$problem."\n"); }
exit($problems === [] ? 0 : 1);
' "$OUTPUT_DIR" "$EXPECTED_SHA" "$HTTP_CODE" "$STARTED_AT"

if [ "$SKIP_GOLIVE" != "1" ]; then
  echo "[wwhn-295] php artisan wms:golive-check --expect-sha=$SHORT_SHA…"
  (cd "$ROOT" && php artisan wms:golive-check \
    --expect-sha="$EXPECTED_SHA" \
    --json="$OUTPUT_DIR/golive-check.json") \
    || fail "wms:golive-check không đạt — xem $OUTPUT_DIR/golive-check.json (mọi finding đã được sanitize, không chứa secret)"
else
  echo "[wwhn-295] bỏ qua wms:golive-check theo SKIP_GOLIVE=1 — bằng chứng cutover CHƯA đủ, phải chạy trên container ứng dụng."
fi

echo "[wwhn-295] PASS — bằng chứng runtime tại $OUTPUT_DIR"
echo "[wwhn-295] Đính kèm nguyên thư mục này vào WWHN-297 (QA1) và WWHN-294 (DevOps)."
