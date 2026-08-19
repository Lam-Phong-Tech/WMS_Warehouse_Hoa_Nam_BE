#!/usr/bin/env bash
# WWHN-309 (DEV309-P1-03) - Thu bằng chứng gói bàn giao tại ĐÚNG commit đang review.
#
# Dùng khi chưa dispatch được GitHub Actions (không runner/không mạng). Đây là
# evidence DỰ PHÒNG: nó chạy đúng bộ gate của workflow và sinh log thô + SHA256SUMS,
# nhưng phải được ghi rõ nguồn là LOCAL, không được ghi là run CI.
#
#   bash scripts/collect-wwhn309-handover-evidence.sh
#
# Máy dev chạy Docker (vendor/ nằm trong volume, không có trên host) thì đặt:
#   WWHN309_ARTISAN="docker exec <container> php artisan" bash scripts/collect-...
#
# Exit code khác 0 = có gate đỏ; KHÔNG được ký handover khi còn đỏ.
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

ARTISAN="${WWHN309_ARTISAN:-php artisan}"
SHA="$(git rev-parse HEAD)"
SHORT="$(git rev-parse --short HEAD)"
OUT="build/evidence/wwhn-309/local/${SHORT}"
LOGS="${OUT}/logs"

# Do BAN cua working tree phai duoc do TRUOC khi script tu ghi output cua chinh no,
# va phai bo qua chinh thu muc evidence - neu khong script luon tu bao "dirty".
DIRTY="clean"
if [ -n "$(git status --porcelain -- . ":(exclude)build/evidence/wwhn-309/local")" ]; then
  DIRTY="dirty"
fi

mkdir -p "$LOGS"

FAILED=0

run_gate() {
  local name="$1"
  shift
  echo "==> ${name}"
  if "$@" >"${LOGS}/${name}.log" 2>&1; then
    echo "    PASS  -> ${LOGS}/${name}.log"
    echo "${name}=PASS" >>"${OUT}/results.txt"
  else
    local code=$?
    echo "    FAIL (exit ${code}) -> ${LOGS}/${name}.log"
    echo "${name}=FAIL:${code}" >>"${OUT}/results.txt"
    FAILED=1
  fi
}

: >"${OUT}/results.txt"

run_gate error-code-catalog node scripts/generate-error-code-catalog.mjs --check
run_gate endpoint-permission-matrix node scripts/generate-endpoint-permission-matrix.mjs --check
run_gate contract-metrics node scripts/generate-api-contract-metrics.mjs --check
run_gate handover-contract node scripts/verify-wwhn-309-handover-contract.mjs
run_gate candidate-pin node scripts/verify-wwhn-309-candidate-pin.mjs

# Bằng chứng vận hành: định nghĩa job nền phải đọc từ runtime thật.
echo "==> schedule-list"
if $ARTISAN schedule:list >"${LOGS}/schedule-list.log" 2>&1; then
  MISSING=""
  for cmd in inventory:reconcile audit:reconcile import:purge-error-files warranty:purge-pii; do
    grep -Fq "$cmd" "${LOGS}/schedule-list.log" || MISSING="${MISSING} ${cmd}"
  done
  if [ -n "$MISSING" ]; then
    echo "    FAIL - thiếu job nền:${MISSING}"
    echo "schedule-list=FAIL:missing${MISSING}" >>"${OUT}/results.txt"
    FAILED=1
  else
    echo "    PASS  -> ${LOGS}/schedule-list.log"
    echo "schedule-list=PASS" >>"${OUT}/results.txt"
  fi
else
  echo "    FAIL -> ${LOGS}/schedule-list.log"
  echo "schedule-list=FAIL" >>"${OUT}/results.txt"
  FAILED=1
fi

{
  echo "task=WWHN-309"
  echo "scope=backend-handover-architecture-api-operations"
  echo "evidence_source=LOCAL (khong phai run CI)"
  echo "source_sha=${SHA}"
  echo "branch=$(git rev-parse --abbrev-ref HEAD)"
  echo "worktree=${DIRTY}"
  echo "artisan=${ARTISAN}"
  echo "node=$(node -v 2>/dev/null || echo unknown)"
  echo "collected_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  echo "result=$([ "$FAILED" -eq 0 ] && echo PASS || echo FAIL)"
} >"${OUT}/manifest.txt"

(cd "$OUT" && find . -type f ! -name SHA256SUMS -print0 | sort -z | xargs -0 sha256sum >SHA256SUMS)

echo ""
cat "${OUT}/manifest.txt"
echo ""
echo "Evidence: ${OUT}"

if [ "$DIRTY" = "dirty" ]; then
  echo "CANH BAO: working tree DIRTY - evidence nay khong gan duoc vao mot commit duy nhat." >&2
fi

exit "$FAILED"
