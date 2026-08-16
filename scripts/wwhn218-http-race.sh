#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${WWHN218_BASE_URL:-http://127.0.0.1:8080}"
EVIDENCE_DIR="${WWHN218_EVIDENCE_DIR:-storage/app/wwhn-218-evidence}"
mkdir -p "$EVIDENCE_DIR/http"

# PHPUnit regression classes legitimately use RefreshDatabase/transactions and
# must not be relied on to leave seeded login principals behind. Restore only
# the canonical Auth seed immediately before the independent HTTP phase. Do not
# rerun warehouse/business seeders here: test fixtures may already have valid
# business rows and PostgreSQL correctly protects their uniqueness constraints.
php artisan db:seed --class="Modules\\Auth\\Database\\Seeders\\AuthDatabaseSeeder" --force > "$EVIDENCE_DIR/runtime-auth-reseed.log"

json_get() {
  jq -er "$2" "$1"
}

login() {
  local email="$1" password="$2" output="$3"
  curl --fail-with-body -sS \
    -H 'Content-Type: application/json' \
    -d "$(jq -nc --arg email "$email" --arg password "$password" '{email:$email,password:$password}')" \
    "$BASE_URL/api/v1/auth/login" > "$output"
  jq -er '.data.access_token' "$output"
}

request_http() {
  local name="$1" method="$2" path="$3" token="$4" version="$5" idem="$6" body_file="$7"
  curl -sS \
    -X "$method" \
    -D "$EVIDENCE_DIR/http/${name}.headers" \
    -o "$EVIDENCE_DIR/http/${name}.body.json" \
    -w '%{http_code}\n' \
    -H "Authorization: Bearer $token" \
    -H 'Content-Type: application/json' \
    -H "If-Match: \"$version\"" \
    -H "Idempotency-Key: $idem" \
    --data-binary "@$body_file" \
    "$BASE_URL$path" > "$EVIDENCE_DIR/http/${name}.status"
}

status_of() {
  tr -d '\r\n ' < "$EVIDENCE_DIR/http/$1.status"
}

header_value() {
  local file="$1" key="$2"
  awk -v IGNORECASE=1 -v key="$key" 'BEGIN{FS=":"} tolower($1)==tolower(key){sub(/^[^:]+:[[:space:]]*/,""); gsub(/\r/,""); print; exit}' "$file"
}

# Retry login endpoint reachability only; the workflow has already asserted a
# non-5xx application response and four PHP workers.
for _ in $(seq 1 30); do
  HTTP_CODE="$(curl -sS -o /dev/null -w '%{http_code}' "$BASE_URL/api/v1/auth/login" || true)"
  if [[ "$HTTP_CODE" =~ ^[234][0-9][0-9]$ ]]; then break; fi
  sleep 1
done

KEEPER_TOKEN="$(login keeper01@gmail.com 'Cntt@1234' "$EVIDENCE_DIR/http/login-keeper.json")"
STAFF_TOKEN="$(login staff01@gmail.com 'Cntt@1234' "$EVIDENCE_DIR/http/login-staff.json")"

php scripts/wwhn218-fixture.php prepare-post > "$EVIDENCE_DIR/post-fixture.json"
DOC_ID="$(json_get "$EVIDENCE_DIR/post-fixture.json" '.document_id')"
POST_VERSION="$(json_get "$EVIDENCE_DIR/post-fixture.json" '.version')"
printf '{}\n' > "$EVIDENCE_DIR/http/empty.json"
php scripts/wwhn218-fixture.php snapshot "$DOC_ID" > "$EVIDENCE_DIR/db-before-post.json"

POST_KEY_A="wwhn218-post-a-${GITHUB_RUN_ID:-local}"
POST_KEY_B="wwhn218-post-b-${GITHUB_RUN_ID:-local}"
request_http post-a POST "/api/v1/inbound-documents/$DOC_ID/post-receipt" "$KEEPER_TOKEN" "$POST_VERSION" "$POST_KEY_A" "$EVIDENCE_DIR/http/empty.json" &
PID_A=$!
request_http post-b POST "/api/v1/inbound-documents/$DOC_ID/post-receipt" "$KEEPER_TOKEN" "$POST_VERSION" "$POST_KEY_B" "$EVIDENCE_DIR/http/empty.json" &
PID_B=$!
wait "$PID_A"
wait "$PID_B"
POST_A="$(status_of post-a)"
POST_B="$(status_of post-b)"

POST_SUCCESS=0
[[ "$POST_A" =~ ^2 ]] && POST_SUCCESS=$((POST_SUCCESS + 1))
[[ "$POST_B" =~ ^2 ]] && POST_SUCCESS=$((POST_SUCCESS + 1))
if [[ "$POST_SUCCESS" -ne 1 ]]; then
  echo "Expected exactly one successful concurrent post; got A=$POST_A B=$POST_B" >&2
  exit 10
fi
if [[ "$POST_A" != "412" && "$POST_B" != "412" ]]; then
  echo "Expected the losing post to prove If-Match optimistic conflict with HTTP 412; got A=$POST_A B=$POST_B" >&2
  exit 11
fi
php scripts/wwhn218-fixture.php snapshot "$DOC_ID" > "$EVIDENCE_DIR/db-after-post.json"

# Backend permission is authoritative: WAREHOUSE_STAFF lacks inbound.post.
curl -sS \
  -X POST \
  -D "$EVIDENCE_DIR/http/rbac-post-deny.headers" \
  -o "$EVIDENCE_DIR/http/rbac-post-deny.body.json" \
  -w '%{http_code}\n' \
  -H "Authorization: Bearer $STAFF_TOKEN" \
  -H 'Content-Type: application/json' \
  -H "If-Match: \"$POST_VERSION\"" \
  -H "Idempotency-Key: wwhn218-rbac-post-${GITHUB_RUN_ID:-local}" \
  -d '{}' \
  "$BASE_URL/api/v1/inbound-documents/$DOC_ID/post-receipt" > "$EVIDENCE_DIR/http/rbac-post-deny.status"
[[ "$(status_of rbac-post-deny)" == "403" ]] || { echo "Expected backend 403 for WAREHOUSE_STAFF inbound.post" >&2; exit 12; }

php scripts/wwhn218-fixture.php reverse-payload "$DOC_ID" > "$EVIDENCE_DIR/reverse-fixture.json"
REVERSE_VERSION="$(json_get "$EVIDENCE_DIR/reverse-fixture.json" '.version')"
jq '.payload' "$EVIDENCE_DIR/reverse-fixture.json" > "$EVIDENCE_DIR/http/reverse.json"

REV_KEY_A="wwhn218-reverse-a-${GITHUB_RUN_ID:-local}"
REV_KEY_B="wwhn218-reverse-b-${GITHUB_RUN_ID:-local}"
request_http reverse-a POST "/api/v1/inbound-documents/$DOC_ID/reverse" "$KEEPER_TOKEN" "$REVERSE_VERSION" "$REV_KEY_A" "$EVIDENCE_DIR/http/reverse.json" &
RID_A=$!
request_http reverse-b POST "/api/v1/inbound-documents/$DOC_ID/reverse" "$KEEPER_TOKEN" "$REVERSE_VERSION" "$REV_KEY_B" "$EVIDENCE_DIR/http/reverse.json" &
RID_B=$!
wait "$RID_A"
wait "$RID_B"
REV_A="$(status_of reverse-a)"
REV_B="$(status_of reverse-b)"

REV_SUCCESS=0
[[ "$REV_A" =~ ^2 ]] && REV_SUCCESS=$((REV_SUCCESS + 1))
[[ "$REV_B" =~ ^2 ]] && REV_SUCCESS=$((REV_SUCCESS + 1))
if [[ "$REV_SUCCESS" -ne 1 ]]; then
  echo "Expected exactly one successful concurrent reversal; got A=$REV_A B=$REV_B" >&2
  exit 20
fi
if [[ "$REV_A" =~ ^2 ]]; then
  WINNER="a"; WIN_KEY="$REV_KEY_A"
else
  WINNER="b"; WIN_KEY="$REV_KEY_B"
fi
php scripts/wwhn218-fixture.php snapshot "$DOC_ID" > "$EVIDENCE_DIR/db-after-reverse.json"

ORIGINAL_QTY="$(jq -er '.original_movements.receipt_quantity' "$EVIDENCE_DIR/db-after-reverse.json")"
REVERSED_QTY="$(jq -er '.reversal_movements.quantity' "$EVIDENCE_DIR/db-after-reverse.json")"
POSTED_REVERSALS="$(jq -er '.reversal_documents.posted_count' "$EVIDENCE_DIR/db-after-reverse.json")"
if [[ "$REVERSED_QTY" -gt "$ORIGINAL_QTY" || "$POSTED_REVERSALS" -ne 1 ]]; then
  echo "Reversal race produced duplicate stock effect: original=$ORIGINAL_QTY reversed=$REVERSED_QTY posted_reversals=$POSTED_REVERSALS" >&2
  exit 21
fi

# Same idempotency key + identical request after completion must replay the committed snapshot.
curl -sS \
  -X POST \
  -D "$EVIDENCE_DIR/http/reverse-replay.headers" \
  -o "$EVIDENCE_DIR/http/reverse-replay.body.json" \
  -w '%{http_code}\n' \
  -H "Authorization: Bearer $KEEPER_TOKEN" \
  -H 'Content-Type: application/json' \
  -H "If-Match: \"$REVERSE_VERSION\"" \
  -H "Idempotency-Key: $WIN_KEY" \
  --data-binary "@$EVIDENCE_DIR/http/reverse.json" \
  "$BASE_URL/api/v1/inbound-documents/$DOC_ID/reverse" > "$EVIDENCE_DIR/http/reverse-replay.status"
[[ "$(status_of reverse-replay)" == "200" ]] || { echo "Expected 200 idempotent replay" >&2; exit 22; }
REPLAY_HEADER="$(header_value "$EVIDENCE_DIR/http/reverse-replay.headers" 'Idempotent-Replay')"
[[ "${REPLAY_HEADER,,}" == "true" ]] || { echo "Expected Idempotent-Replay: true" >&2; exit 23; }
php scripts/wwhn218-fixture.php snapshot "$DOC_ID" > "$EVIDENCE_DIR/db-after-replay.json"
cmp -s <(jq '.reversal_movements,.reversal_documents' "$EVIDENCE_DIR/db-after-reverse.json") <(jq '.reversal_movements,.reversal_documents' "$EVIDENCE_DIR/db-after-replay.json") || {
  echo "Replay changed reversal DB state" >&2
  exit 24
}

# Backend permission is authoritative for reversal as well.
curl -sS \
  -X POST \
  -D "$EVIDENCE_DIR/http/rbac-reverse-deny.headers" \
  -o "$EVIDENCE_DIR/http/rbac-reverse-deny.body.json" \
  -w '%{http_code}\n' \
  -H "Authorization: Bearer $STAFF_TOKEN" \
  -H 'Content-Type: application/json' \
  -H "If-Match: \"$REVERSE_VERSION\"" \
  -H "Idempotency-Key: wwhn218-rbac-reverse-${GITHUB_RUN_ID:-local}" \
  --data-binary "@$EVIDENCE_DIR/http/reverse.json" \
  "$BASE_URL/api/v1/inbound-documents/$DOC_ID/reverse" > "$EVIDENCE_DIR/http/rbac-reverse-deny.status"
[[ "$(status_of rbac-reverse-deny)" == "403" ]] || { echo "Expected backend 403 for WAREHOUSE_STAFF inventory.reversal.post" >&2; exit 25; }

jq -n \
  --arg generated_at "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
  --arg document_id "$DOC_ID" \
  --arg post_a "$POST_A" --arg post_b "$POST_B" \
  --arg reverse_a "$REV_A" --arg reverse_b "$REV_B" \
  --arg reverse_winner "$WINNER" \
  --arg replay_status "$(status_of reverse-replay)" \
  --arg replay_header "$REPLAY_HEADER" \
  --arg rbac_post "$(status_of rbac-post-deny)" \
  --arg rbac_reverse "$(status_of rbac-reverse-deny)" \
  --argjson original_qty "$ORIGINAL_QTY" \
  --argjson reversed_qty "$REVERSED_QTY" \
  --argjson posted_reversals "$POSTED_REVERSALS" \
  '{generated_at:$generated_at,document_id:$document_id,concurrent_post:{request_a_status:$post_a,request_b_status:$post_b,exactly_one_success:true,optimistic_conflict_412:true},concurrent_reverse:{request_a_status:$reverse_a,request_b_status:$reverse_b,winner:$reverse_winner,exactly_one_success:true,original_quantity:$original_qty,reversed_quantity:$reversed_qty,posted_reversal_count:$posted_reversals,no_duplicate_stock_effect:($reversed_qty <= $original_qty and $posted_reversals == 1)},idempotency_replay:{status:$replay_status,idempotent_replay_header:$replay_header,db_unchanged:true},rbac:{warehouse_staff_post_status:$rbac_post,warehouse_staff_reverse_status:$rbac_reverse}}' \
  > "$EVIDENCE_DIR/runtime-summary.json"

cat "$EVIDENCE_DIR/runtime-summary.json"
