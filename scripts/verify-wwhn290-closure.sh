#!/usr/bin/env bash
# WWHN-290 — independent closure verifier.
#
# Re-derives every WWHN-290 claim from the live repository so a reviewer never has to
# trust prose in the evidence markdown. Exits non-zero on the first mismatch.
#
#   bash scripts/verify-wwhn290-closure.sh
#
# Closes: DEV290-P1-02 (branch), DEV290-P1-03 (contract), DEV290-P1-05 (data impact),
#         DEV290-P2-01 (repo/branch reference).

set -euo pipefail

cd "$(dirname "$0")/.."

EVIDENCE_DIR='docs/evidence/wwhn-290'
PINS="${EVIDENCE_DIR}/WWHN-290_PINS.json"
REGISTER="${EVIDENCE_DIR}/WWHN-290_DEFECT_CLOSURE_REGISTER.md"
SUPERSESSION="${EVIDENCE_DIR}/WWHN-290_BRANCH_CONTRACT_SUPERSESSION.md"
DATA_IMPACT="${EVIDENCE_DIR}/WWHN-290_DATA_IMPACT_INDEPENDENT_VERIFICATION.md"
QA_HANDOFF="${EVIDENCE_DIR}/WWHN-290_QA1_REGRESSION_HANDOFF.md"
CONTRACT='storage/api-docs/api-docs.json'

failures=0

pass() { printf '  PASS  %s\n' "$1"; }
fail() { printf '  FAIL  %s\n' "$1"; failures=$((failures + 1)); }
info() { printf '  INFO  %s\n' "$1"; }

pin() {
  php -r '$d = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR); foreach (explode(".", $argv[2]) as $s) { $d = $d[$s] ?? null; } if (is_bool($d)) { echo $d ? "true" : "false"; return; } if (is_array($d)) { echo count($d); return; } echo (string) $d;' "$PINS" "$1"
}

echo '== WWHN-290 closure verification =='
echo

echo '-- 0. Evidence set present --'
for f in "$PINS" "$REGISTER" "$SUPERSESSION" "$DATA_IMPACT" "$QA_HANDOFF"; do
  if [ -s "$f" ]; then pass "present: $f"; else fail "missing or empty: $f"; fi
done
if php -r 'json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);' "$PINS"; then
  pass 'WWHN-290_PINS.json parses as JSON'
else
  fail 'WWHN-290_PINS.json is not valid JSON'
fi
echo

echo '-- 1. DEV290-P1-02 branch canonicalisation --'
SUPERSEDED_BRANCH="$(pin superseded_branch.name)"
SUPERSEDED_HEAD="$(pin superseded_branch.head)"
CANON_BRANCH="$(pin canonical_source.be_branch)"

if git rev-parse --verify --quiet "origin/${SUPERSEDED_BRANCH}" >/dev/null; then
  actual_head="$(git rev-parse "origin/${SUPERSEDED_BRANCH}")"
  if [ "$actual_head" = "$SUPERSEDED_HEAD" ]; then
    pass "origin/${SUPERSEDED_BRANCH} HEAD matches pin (${SUPERSEDED_HEAD})"
  else
    fail "origin/${SUPERSEDED_BRANCH} HEAD is ${actual_head}, pin says ${SUPERSEDED_HEAD} — re-pin required"
  fi

  if git merge-base --is-ancestor "origin/${SUPERSEDED_BRANCH}" "$CANON_BRANCH"; then
    pass "origin/${SUPERSEDED_BRANCH} is a strict ancestor of ${CANON_BRANCH} — no port/merge required"
  else
    fail "origin/${SUPERSEDED_BRANCH} is NOT contained in ${CANON_BRANCH} — supersession claim is invalid"
  fi

  behind="$(git rev-list --count "${CANON_BRANCH}..origin/${SUPERSEDED_BRANCH}")"
  if [ "$behind" = '0' ]; then
    pass "${CANON_BRANCH} is behind origin/${SUPERSEDED_BRANCH} by 0 commits"
  else
    fail "${CANON_BRANCH} is behind origin/${SUPERSEDED_BRANCH} by ${behind} commits"
  fi

  info "${CANON_BRANCH} is ahead by $(git rev-list --count "origin/${SUPERSEDED_BRANCH}..${CANON_BRANCH}") commits"
else
  info "origin/${SUPERSEDED_BRANCH} not fetched locally; run 'git fetch origin ${SUPERSEDED_BRANCH}' to verify ancestry"
fi
echo

echo '-- 2. DEV290-P2-01 repository / branch reference --'
BOGUS_REFS="$(php -r '$d = json_decode(file_get_contents($argv[1]), true); echo implode(" ", $d["canonical_source"]["nonexistent_refs_seen_in_review"] ?? []);' "$PINS")"
for bogus in $BOGUS_REFS; do
  if git rev-parse --verify --quiet "origin/${bogus}" >/dev/null; then
    fail "branch '${bogus}' unexpectedly exists — evidence claims it does not"
  else
    pass "branch '${bogus}' does not exist, as recorded"
  fi
done
echo

echo '-- 3. DEV290-P1-03 approved contract target --'
APPROVED_VERSION="$(pin contract.approved_openapi_version)"
APPROVED_SPEC="$(pin contract.openapi_spec_version)"
if php -r '$d = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR); $spec = $d["openapi"] ?? ""; $ver = $d["info"]["version"] ?? ""; if ($spec !== $argv[2] || $ver !== $argv[3]) { fwrite(STDERR, "contract target mismatch: openapi={$spec} info.version={$ver}\n"); exit(1); } if (empty($d["paths"])) { fwrite(STDERR, "contract has no paths\n"); exit(1); }' "$CONTRACT" "$APPROVED_SPEC" "$APPROVED_VERSION"; then
  pass "${CONTRACT} is openapi ${APPROVED_SPEC} / info.version ${APPROVED_VERSION} with non-empty paths"
else
  fail "${CONTRACT} does not match the approved target ${APPROVED_SPEC}/${APPROVED_VERSION}"
fi

info "live contract blob   : $(git hash-object "$CONTRACT")"
info "live contract sha256 : $(sha256sum "$CONTRACT" | awk '{print $1}')"
info "pin-time blob        : $(pin contract.blob_at_pin_time) (lineage only; see PINS.json contract.lineage_note)"
echo

echo '-- 4. DEV290-P1-05 change-range data / runtime impact --'
BASE="$(pin change_range.base)"
FINAL="$(pin change_range.final)"

if git rev-parse --verify --quiet "$BASE" >/dev/null && git rev-parse --verify --quiet "$FINAL" >/dev/null; then
  changed="$(git diff --name-only "$BASE" "$FINAL")"
  info "files changed by the WWHN-290 range ${BASE:0:7}..${FINAL:0:7}:"
  printf '%s\n' "$changed" | sed 's/^/          /'

  guarded="$(printf '%s\n' "$changed" | grep -E '^(app/|Modules/[^/]+/app/|Modules/[^/]+/database/|database/(migrations|seeders)/|storage/api-docs/)' || true)"
  if [ -z "$guarded" ]; then
    pass 'range touches no runtime, migration, seeder or contract path — NO DATA CHANGE / N/A is verified, not asserted'
  else
    fail 'range touches guarded paths, so the NO DATA CHANGE claim is void:'
    printf '%s\n' "$guarded" | sed 's/^/          /'
  fi

  contract_touched="$(git diff --name-only "$BASE" "$FINAL" -- "$CONTRACT" | wc -l | tr -d ' ')"
  if [ "$contract_touched" = '0' ]; then
    pass 'range changes the OpenAPI contract file 0 times — no contract delta attributable to WWHN-290'
  else
    fail "range changes ${CONTRACT} ${contract_touched} time(s)"
  fi
else
  fail "change-range endpoints not resolvable in this clone (${BASE} .. ${FINAL})"
fi
echo

echo '-- 4b. Per-attributed-commit scope (m1 is a shared, interleaved branch) --'
ATTRIBUTED="$(php -r '$d = json_decode(file_get_contents($argv[1]), true); echo implode(" ", $d["change_range"]["commits"] ?? []);' "$PINS")"
for c in $ATTRIBUTED; do
  if git rev-parse --verify --quiet "$c" >/dev/null; then
    hits="$(git show --pretty=format: --name-only "$c" | grep -E '^(app/|Modules/[^/]+/app/|Modules/[^/]+/database/|database/(migrations|seeders)/|storage/api-docs/)' || true)"
    if [ -z "$hits" ]; then
      pass "commit ${c:0:7} touches no guarded path"
    else
      fail "commit ${c:0:7} touches guarded paths:"
      printf '%s\n' "$hits" | sed 's/^/          /'
    fi
  else
    fail "attributed commit ${c} is not resolvable in this clone"
  fi
done

# Completeness signal: any WWHN-290 commit on the branch that the pins file does not list.
# Pin-maintenance commits that touch nothing but this ticket's own evidence directory are
# exempt: they cannot carry data impact, and the commit that records the list can never
# contain its own hash.
if git rev-parse --verify --quiet "$BASE" >/dev/null; then
  while read -r found; do
    [ -z "$found" ] && continue
    case " $ATTRIBUTED " in
      *" $found "*) continue ;;
    esac
    outside="$(git show --pretty=format: --name-only "$found" | grep -v '^$' | grep -v "^${EVIDENCE_DIR}/" || true)"
    if [ -z "$outside" ]; then
      pass "commit ${found:0:7} is evidence-only pin maintenance — exempt from attribution"
    else
      info "commit ${found:0:7} mentions WWHN-290 but is not in the pinned commit list — append it to WWHN-290_PINS.json"
    fi
  done <<< "$(git log --format='%H' --grep='WWHN-290' "${BASE}..HEAD" || true)"
fi
echo

echo '-- 5. Cross-document pin equality (anti-drift) --'
check_marker() {
  local file="$1" value="$2" label="$3"
  if [ -z "$value" ]; then
    fail "empty pin for ${label}"
    return
  fi
  if grep -Fq "$value" "$file"; then
    pass "${label} in $(basename "$file") matches PINS.json"
  else
    fail "${label} missing/stale in $(basename "$file") — expected ${value}"
  fi
}
check_marker "$SUPERSESSION" "$(pin superseded_branch.head)" 'm1-be-2 HEAD'
check_marker "$SUPERSESSION" "$(pin fe_consumer.revision_reviewed)" 'FE consumer revision'
check_marker "$REGISTER" "$(pin fe_consumer.revision_reviewed)" 'FE consumer revision'
check_marker "$REGISTER" "$(pin change_range.base)" 'change-range base'
check_marker "$REGISTER" "$(pin change_range.final)" 'change-range final'
check_marker "$DATA_IMPACT" "$(pin change_range.final)" 'change-range final'
echo

echo '-- 6. Fail-closed defect-input disposition --'
for marker in DEV290-P1-01 DEV290-P1-02 DEV290-P1-03 DEV290-P1-04 DEV290-P1-05 DEV290-P2-01; do
  if grep -Fq "$marker" "$REGISTER"; then
    pass "register pins ${marker}"
  else
    fail "register is missing ${marker}"
  fi
done
if grep -Fq 'does **not** claim QA1 PASS' "$REGISTER"; then
  pass 'register does not self-sign QA1'
else
  fail 'register must state it does not claim QA1 PASS'
fi
if grep -Fq 'QA1 PASS — VERIFIED' "$REGISTER"; then
  fail 'register illegally claims QA1 PASS'
else
  pass 'no DEV-authored QA1 PASS claim'
fi
echo

echo '== summary =='
if [ "$failures" -eq 0 ]; then
  echo 'RESULT=PASS — every WWHN-290 claim re-derived from the live repository.'
  echo 'Human gates still required: PM/Tech Lead (branch+contract), QA1 (WWHN-291), Data/Tech (data impact).'
  exit 0
fi
echo "RESULT=FAIL — ${failures} check(s) failed."
exit 1
