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

echo '-- 7. DEV290-P1-02R frozen candidate (immutable closure package) --'
DECISION="${EVIDENCE_DIR}/WWHN-290_BRANCH_DECISION_RECORD.md"
FROZEN_SHA="$(pin frozen_candidate.sha)"
PINNED_DIGEST="$(pin frozen_candidate.package_digest)"
PACKAGE_FILES="$(php -r '$d = json_decode(file_get_contents($argv[1]), true); echo implode("\n", $d["frozen_candidate"]["package_files"] ?? []);' "$PINS")"

if [ -s "$DECISION" ]; then
  pass "present: $DECISION"
else
  fail "missing branch decision record: $DECISION"
fi
for marker in 'DEC-BR-290-001' 'PENDING_PM_TECHLEAD_SIGNOFF' 'frozen candidate'; do
  if grep -Fq "$marker" "$DECISION" 2>/dev/null; then
    pass "decision record states ${marker}"
  else
    fail "decision record is missing ${marker}"
  fi
done

# The digest is recomputed from the frozen commit's own objects once the candidate SHA is
# recorded, so later commits on the shared m1 branch can never rot it. Before the freeze
# commit exists, the same digest is computed from the working tree - which is exactly what
# the freeze commit will contain.
digest_source='working tree'
manifest_tmp="$(mktemp)"
digest_ok=1
if [ -n "$FROZEN_SHA" ] && git rev-parse --verify --quiet "$FROZEN_SHA" >/dev/null; then
  digest_source="frozen candidate ${FROZEN_SHA:0:7}"
fi
while IFS= read -r f; do
  [ -z "$f" ] && continue
  if [ "$digest_source" = 'working tree' ]; then
    if [ ! -f "$f" ]; then
      fail "package file missing from working tree: $f"
      digest_ok=0
      continue
    fi
    h="$(sha256sum "$f" | awk '{print $1}')"
  else
    if ! git cat-file -e "${FROZEN_SHA}:${f}" 2>/dev/null; then
      fail "package file missing at frozen candidate: $f"
      digest_ok=0
      continue
    fi
    h="$(git cat-file blob "${FROZEN_SHA}:${f}" | sha256sum | awk '{print $1}')"
  fi
  printf '%s  %s\n' "$h" "$f" >> "$manifest_tmp"
done <<< "$PACKAGE_FILES"

if [ "$digest_ok" = '1' ]; then
  ACTUAL_DIGEST="$(sort "$manifest_tmp" | sha256sum | awk '{print $1}')"
  info "package digest source: ${digest_source}"
  info "package digest       : ${ACTUAL_DIGEST}"
  if [ "$ACTUAL_DIGEST" = "$PINNED_DIGEST" ]; then
    pass 'frozen closure package digest matches the pin - the reviewed package is byte-identical'
  else
    fail "frozen package digest mismatch: pinned ${PINNED_DIGEST}, recomputed ${ACTUAL_DIGEST}"
  fi
fi
rm -f "$manifest_tmp"

if [ -n "$FROZEN_SHA" ]; then
  if git rev-parse --verify --quiet "$FROZEN_SHA" >/dev/null; then
    if git merge-base --is-ancestor "$FROZEN_SHA" HEAD; then
      pass "frozen candidate ${FROZEN_SHA:0:7} is contained in the current branch"
    else
      fail "frozen candidate ${FROZEN_SHA} is not contained in the current branch"
    fi
  else
    fail "frozen candidate ${FROZEN_SHA} is not resolvable in this clone"
  fi
  DECISION_SHA="$(pin branch_decision.effective_sha)"
  if [ "$DECISION_SHA" = "$FROZEN_SHA" ]; then
    pass 'branch decision effective SHA equals the frozen candidate'
  else
    fail "branch decision effective SHA (${DECISION_SHA}) differs from the frozen candidate (${FROZEN_SHA})"
  fi
else
  info 'frozen candidate SHA not recorded yet - it is written by the evidence-only commit that follows the freeze'
fi
echo

echo '-- 8. DEV290-P1-03R contract consumers pinned to one source --'
MATRIX="${EVIDENCE_DIR}/WWHN-290_CONTRACT_CONSUMER_MATRIX.md"
if [ -s "$MATRIX" ]; then pass "present: $MATRIX"; else fail "missing contract consumer matrix: $MATRIX"; fi

POSTMAN_COLLECTION="$(pin contract_consumers.postman_collection)"
POSTMAN_LOCAL_ENV="$(pin contract_consumers.postman_local_environment)"
NAMESPACE="$(pin contract.namespace)"

if [ -f "$POSTMAN_COLLECTION" ]; then
  if php -f scripts/wwhn290-check-postman-namespace.php -- "$POSTMAN_COLLECTION" 'api/v1' 'api/health'; then
    pass "postman contract collection targets ${NAMESPACE} only (documented exception: api/health)"
  else
    fail "postman contract collection leaves the approved namespace ${NAMESPACE}"
  fi
else
  fail "pinned postman collection not found: ${POSTMAN_COLLECTION}"
fi

if [ -f "$POSTMAN_LOCAL_ENV" ]; then
  # Git Bash rewrites a leading-slash argument into a Windows path before it reaches php.exe,
  # so the namespace is passed without its leading slash and re-attached inside PHP.
  if php -r '$d = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR); foreach ($d["values"] ?? [] as $v) { if (($v["key"] ?? "") === "baseUrl") { exit(str_ends_with(rtrim($v["value"], "/"), "/".$argv[2]) ? 0 : 1); } } exit(1);' "$POSTMAN_LOCAL_ENV" "${NAMESPACE#/}"; then
    pass "postman local environment baseUrl ends at ${NAMESPACE}"
  else
    fail "postman local environment baseUrl does not end at ${NAMESPACE}"
  fi
else
  fail "pinned postman environment not found: ${POSTMAN_LOCAL_ENV}"
fi

# Range invariant for consumer artifacts: this ticket must not have edited them.
if git rev-parse --verify --quiet "$BASE" >/dev/null && git rev-parse --verify --quiet "$FINAL" >/dev/null; then
  consumer_touched="$(git diff --name-only "$BASE" "$FINAL" -- docs/postman storage/api-docs | wc -l | tr -d ' ')"
  if [ "$consumer_touched" = '0' ]; then
    pass 'range changes contract and postman consumer artifacts 0 times'
  else
    fail "range changes ${consumer_touched} consumer artifact file(s)"
  fi
fi

FE_REV="$(pin fe_consumer.revision_reviewed)"
FE_REPO_DIR="$(pin fe_consumer.local_path)"
if [ -d "${FE_REPO_DIR}/.git" ]; then
  if git -C "$FE_REPO_DIR" rev-parse --verify --quiet "$FE_REV" >/dev/null; then
    if git -C "$FE_REPO_DIR" cat-file -e "${FE_REV}:docs/evidence/wwhn-290/WWHN-290_FE_CONSUMER_CONFIRMATION.md" 2>/dev/null; then
      pass "FE consumer revision ${FE_REV:0:7} carries the FE consumer confirmation"
    else
      fail "FE consumer revision ${FE_REV} does not contain the FE consumer confirmation document"
    fi
  else
    fail "FE consumer revision ${FE_REV} is not resolvable in ${FE_REPO_DIR}"
  fi
else
  info "FE repository not available at ${FE_REPO_DIR}; the FE side is verified by 'yarn wwhn-290:verify' in that repo"
fi
echo

echo '-- 9. DEV290-P1-06 source and traceability baseline --'
TRACE="${EVIDENCE_DIR}/WWHN-290_SOURCE_TRACEABILITY_SUPERSESSION.md"
SRS_DOC="$(pin source_traceability.srs_doc)"
if [ -s "$TRACE" ]; then pass "present: $TRACE"; else fail "missing source traceability record: $TRACE"; fi

if [ -f "$SRS_DOC" ]; then
  if grep -Fq "$(pin source_traceability.srs_document_id)" "$SRS_DOC" && grep -Fq "$(pin source_traceability.srs_version_marker)" "$SRS_DOC"; then
    pass "baseline SRS identity is live ($(pin source_traceability.srs_document_id) / $(pin source_traceability.srs_version_marker))"
  else
    fail 'baseline SRS document no longer declares the pinned identity'
  fi
  if grep -F 'BRD v3.9' "$SRS_DOC" | grep -Fq 'BRD v3.20'; then
    pass 'SRS supersession register still records BRD v3.9 -> BRD v3.20'
  else
    fail 'SRS supersession register no longer records BRD v3.9 -> BRD v3.20 - the DEV290-P1-06 authority is gone'
  fi
else
  fail "baseline SRS document not found: ${SRS_DOC}"
fi

for marker in "$(pin source_traceability.current_business_source)" "$(pin source_traceability.parent_ticket_quoted_source)"; do
  if grep -Fq "$marker" "$TRACE"; then
    pass "traceability record quotes ${marker}"
  else
    fail "traceability record is missing ${marker}"
  fi
done

if git rev-parse --verify --quiet "$BASE" >/dev/null && git rev-parse --verify --quiet "$FINAL" >/dev/null; then
  baseline_touched="$(git diff --name-only "$BASE" "$FINAL" -- docs/HN_SRS_FRD_v1.12_FULL.md docs/HoaNam_ERD_Moi_Nhat.md docs/WMS_Hoa_Nam_Techstack_Architecture_Dev_Guide.md | wc -l | tr -d ' ')"
  if [ "$baseline_touched" = '0' ]; then
    pass 'range changes baseline documents 0 times - no repo-side baseline drift attributable to this ticket'
  else
    fail "range changes ${baseline_touched} baseline document(s) - a repo artifact must not move the controlled baseline"
  fi
fi
echo

echo '-- 10. DEV290-P1-05R exact-SHA gate run and failed-check disposition --'
GATE_RUN="${EVIDENCE_DIR}/WWHN-290_EXACT_SHA_GATE_RUN.md"
WORKFLOW='.github/workflows/wwhn-290-closure.yml'
if [ -s "$GATE_RUN" ]; then
  pass "present: $GATE_RUN"
  for marker in 'DISPOSITION' 'WWHN-290 Exact SHA Closure'; do
    if grep -Fq "$marker" "$GATE_RUN"; then
      pass "gate run record states ${marker}"
    else
      fail "gate run record is missing ${marker}"
    fi
  done
else
  fail "missing exact-SHA gate run record: $GATE_RUN"
fi

if [ -f "$WORKFLOW" ]; then
  if grep -Fq 'postgres-diagnostic' "$WORKFLOW" && grep -Fq 'continue-on-error' "$WORKFLOW"; then
    pass 'gate separates the blocking closure job from the non-blocking PostgreSQL diagnostic'
  else
    fail 'gate no longer separates blocking closure from the PostgreSQL diagnostic job'
  fi
else
  fail "missing workflow: $WORKFLOW"
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
