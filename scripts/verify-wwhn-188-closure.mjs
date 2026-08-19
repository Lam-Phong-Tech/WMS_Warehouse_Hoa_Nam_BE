#!/usr/bin/env node
/**
 * WWHN-188 — verifier tổng cho gói closure.
 *
 * Kiểm bằng máy đúng những thứ reviewer đòi ở vòng R2, để không ai (kể cả DEV) tự
 * tuyên bố "đã đóng" bằng lời:
 *
 * | Check | Review item |
 * |---|---|
 * | Pin tồn tại, candidate là commit thật, blob SHA còn khớp working tree | `DEV188-P1-05R2` |
 * | Bản trích OpenAPI khớp hash đã pin, có `Idempotency-Key` + 409 IDEMPOTENCY_CONFLICT | `DEV188-P1-03R2` |
 * | Evidence race thật: PASS, 4 kịch bản, 2 backend PID, timestamps chồng lấn, DB before/after | `DEV188-P1-02R2` |
 * | Hồ sơ trace/supersession + source register có mặt và nói đúng hai điều bắt buộc | `DEV188-P1-01R2`, `DEV188-P1-06R2` |
 * | Ma trận QA phủ đủ 401/403/409/412/422/428 + duplicate/serial/quantity/race/retry/rollback | `DEV188-P1-04R2` |
 * | Không còn fixture `WWHN187-*` trong test concurrency của WWHN-188 | `DEV188-P2-01` |
 *
 * Dùng:
 *   node scripts/verify-wwhn-188-closure.mjs
 *   node scripts/verify-wwhn-188-closure.mjs --expect-sha=<40 hex>   # reviewer khoá đúng bản đã đọc
 *
 * Thoát 0 khi mọi check PASS, 1 khi có FAIL.
 */

import { execFileSync } from "node:child_process";
import { createHash } from "node:crypto";
import { existsSync, readFileSync } from "node:fs";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), "..");
const PIN_PATH = "docs/evidence/WWHN-188/WWHN-188_CANDIDATE_PIN.json";

const expectArg = process.argv.find((a) => a.startsWith("--expect-sha="));
const expectSha = expectArg === undefined ? null : expectArg.split("=")[1];

const results = [];
const record = (id, ok, detail) => results.push({ id, ok, detail });

function read(path) {
  return readFileSync(resolve(ROOT, path), "utf8");
}

function readJson(path) {
  return JSON.parse(read(path));
}

function git(args) {
  return execFileSync("git", ["-C", ROOT, ...args], { encoding: "utf8" }).trim();
}

// ---------------------------------------------------------------------------
// DEV188-P1-05R2 — pin candidate, không dùng floating HEAD.
// ---------------------------------------------------------------------------
if (!existsSync(resolve(ROOT, PIN_PATH))) {
  record("P1-05/pin-exists", false, `Thiếu ${PIN_PATH}; chạy node scripts/pin-wwhn-188-candidate.mjs`);
  report();
}
const pin = readJson(PIN_PATH);
record("P1-05/pin-exists", true, PIN_PATH);

const candidateSha = pin?.candidate?.commit_sha ?? "";
record(
  "P1-05/candidate-is-sha",
  /^[0-9a-f]{40}$/.test(candidateSha),
  `candidate.commit_sha=${candidateSha}`,
);

let candidateResolvable = false;
try {
  candidateResolvable = git(["cat-file", "-t", candidateSha]) === "commit";
} catch {
  candidateResolvable = false;
}
record("P1-05/candidate-commit-exists", candidateResolvable, candidateSha);

// Candidate phải là commit chạm artifact, KHÔNG được mặc định bằng HEAD của nhánh.
// (HEAD được phép trùng nếu commit cuối chính là commit của task này.)
const headSha = git(["rev-parse", "HEAD"]);
const lastTouching = git([
  "log",
  "-1",
  "--format=%H",
  "--",
  ...Object.keys(pin.artifacts ?? {}),
]);
record(
  "P1-05/candidate-is-last-commit-touching-artifacts",
  lastTouching === candidateSha,
  `last-touching=${lastTouching} candidate=${candidateSha} (branch HEAD=${headSha})`,
);

if (expectSha !== null) {
  record(
    "P1-05/expect-sha",
    expectSha === candidateSha,
    `--expect-sha=${expectSha} vs candidate=${candidateSha}`,
  );
}

// Pin hai tầng: blob SHA phải còn khớp nội dung đang nằm trong working tree.
const drifted = [];
for (const [path, meta] of Object.entries(pin.artifacts ?? {})) {
  if (!existsSync(resolve(ROOT, path))) {
    drifted.push(`${path} (MISSING)`);
    continue;
  }
  const actual = git(["hash-object", path]);
  if (actual !== meta.blob_sha) drifted.push(`${path} (${meta.blob_sha} -> ${actual})`);
}
record(
  "P1-05/artifact-blobs-unchanged",
  drifted.length === 0,
  drifted.length === 0 ? `${Object.keys(pin.artifacts).length} artifact khớp blob SHA` : drifted.join("; "),
);

// ---------------------------------------------------------------------------
// DEV188-P1-03R2 — OpenAPI hash + nội dung contract mới.
// ---------------------------------------------------------------------------
const opPath = pin?.openapi?.operation_extract;
if (opPath !== undefined && existsSync(resolve(ROOT, opPath))) {
  const parsed = readJson(opPath);
  const sha = createHash("sha256").update(JSON.stringify(parsed)).digest("hex");
  record("P1-03/openapi-hash", sha === pin.openapi.sha256_canonical, `sha256=${sha}`);

  const scan = parsed["/api/v1/inbound-documents/{id}/scan"];
  const params = (scan?.parameters ?? []).map((p) => p.name);
  record(
    "P1-03/openapi-declares-idempotency-key",
    params.includes("Idempotency-Key"),
    `parameters=${params.join(",")}`,
  );
  const idemParam = (scan?.parameters ?? []).find((p) => p.name === "Idempotency-Key");
  record(
    "P1-03/openapi-idempotency-key-optional",
    idemParam !== undefined && idemParam.required !== true,
    // Annex C không khai bắt buộc cho scan (stock-neutral) — bắt buộc sẽ phá client cũ.
    `required=${String(idemParam?.required)}`,
  );
  record(
    "P1-03/openapi-409-lists-idempotency-conflict",
    String(scan?.responses?.["409"]?.description ?? "").includes("IDEMPOTENCY_CONFLICT"),
    String(scan?.responses?.["409"]?.description ?? "").slice(0, 120),
  );
  record(
    "P1-03/openapi-documents-replayed",
    String(scan?.responses?.["200"]?.description ?? "").includes("replayed"),
    "200 description phải nói rõ data.replayed",
  );
} else {
  record("P1-03/openapi-hash", false, `Thiếu ${opPath}`);
}

// Hiện thực phải thật sự dùng fingerprint tất định, không phải cửa sổ thời gian.
const controller = read(
  "Modules/Warehouse/app/Http/Controllers/CurrentBaselineStockFlowController.php",
);
record(
  "P1-03/controller-uses-fingerprint",
  controller.includes("ScanRequestFingerprint::for('inbound_scan'") &&
    controller.includes("IdempotencyKeyGuard::optional($request)"),
  "inboundScan() phải dùng fingerprint tất định + key optional",
);
const fingerprint = read("app/Support/ScanRequestFingerprint.php");
record(
  "P1-03/fingerprint-excludes-version",
  !/['"]version['"]\s*=>/.test(fingerprint) && !fingerprint.includes("If-Match'"),
  "fingerprint không được chứa version/If-Match, nếu không re-fetch retry vẫn double-count",
);

// ---------------------------------------------------------------------------
// DEV188-P1-02R2 — evidence race thật.
// ---------------------------------------------------------------------------
const RUNTIME = "docs/evidence/WWHN-188/runtime/runtime-concurrency-summary.json";
if (existsSync(resolve(ROOT, RUNTIME))) {
  const summary = readJson(RUNTIME);
  const required = [
    "same_document_same_line",
    "same_document_different_line",
    "same_item_different_document",
    "same_idempotency_key_quantity_mode",
  ];
  record("P1-02/runtime-status-pass", summary.status === "PASS", `status=${summary.status}`);
  record(
    "P1-02/runtime-scenarios",
    required.every((s) => Object.keys(summary.scenarios ?? {}).includes(s)),
    Object.keys(summary.scenarios ?? {}).join(","),
  );
  record(
    "P1-02/runtime-postgres-version",
    /^PostgreSQL \d+/.test(String(summary.postgres_version ?? "")),
    String(summary.postgres_version ?? "").slice(0, 60),
  );
  record(
    "P1-02/runtime-pins-a-sha",
    /^[0-9a-f]{40}$/.test(String(summary.git_sha ?? "")),
    `git_sha=${summary.git_sha}`,
  );

  const problems = [];
  for (const [name, sc] of Object.entries(summary.scenarios ?? {})) {
    if (sc.passed !== true) problems.push(`${name}: not passed`);
    if (sc.distinct_backend_pid_count !== 2) problems.push(`${name}: pids=${sc.distinct_backend_pid_count}`);
    if (sc.timing?.workers_in_flight_simultaneously !== true) problems.push(`${name}: no overlap`);
    if (sc.db_before === undefined || sc.db_after === undefined) problems.push(`${name}: no db snapshot`);
  }
  record(
    "P1-02/runtime-two-connections-overlap-and-db-evidence",
    problems.length === 0,
    problems.length === 0 ? "4/4 kịch bản: 2 backend PID, chồng lấn thời gian, có DB before/after" : problems.join("; "),
  );

  // Mắt xích chống pin rot mạnh nhất: bằng chứng race phải được sinh ra từ ĐÚNG
  // nội dung đang được pin. Commit SHA có thể trôi vì phiên khác commit trên m1,
  // nhưng blob SHA của artifact thì không.
  const evidenceBlobs = summary.artifact_blob_sha ?? {};
  const blobMismatch = [];
  for (const [path, sha] of Object.entries(evidenceBlobs)) {
    const pinned = pin.artifacts?.[path]?.blob_sha;
    if (pinned !== undefined && pinned !== sha) blobMismatch.push(`${path} (evidence ${sha} vs pin ${pinned})`);
  }
  record(
    "P1-02/runtime-evidence-matches-pinned-artifacts",
    Object.keys(evidenceBlobs).length > 0 && blobMismatch.length === 0,
    blobMismatch.length === 0
      ? `${Object.keys(evidenceBlobs).length} artifact khớp giữa evidence và pin`
      : blobMismatch.join("; "),
  );

  const idem = summary.scenarios?.same_idempotency_key_quantity_mode;
  const lineId = idem?.line_id;
  record(
    "P1-03/runtime-no-double-count",
    idem?.db_after?.lines?.[lineId]?.scanned_qty === 2 && idem?.db_after?.document_version === 2,
    `scanned_qty=${idem?.db_after?.lines?.[lineId]?.scanned_qty} version=${idem?.db_after?.document_version}`,
  );
} else {
  record("P1-02/runtime-status-pass", false, `Thiếu ${RUNTIME}`);
}

// ---------------------------------------------------------------------------
// DEV188-P1-01R2 / P1-06R2 — hồ sơ trace + nguồn.
// ---------------------------------------------------------------------------
const trace = "docs/evidence/WWHN-188/WWHN-188_TRACE_SUPERSESSION.md";
if (existsSync(resolve(ROOT, trace))) {
  const text = read(trace);
  record(
    "P1-01/trace-states-both-roles",
    /WWHN-187\s*=\s*implementation/i.test(text) && /WWHN-188\s*=\s*concurrency/i.test(text),
    "phải nói rõ WWHN-187 = implementation, WWHN-188 = concurrency",
  );
  record("P1-01/trace-supersedes-attachment", text.includes("ATT-11852"), "phải nêu đích danh attachment stale");
} else {
  record("P1-01/trace-states-both-roles", false, `Thiếu ${trace}`);
}

const sourceRegister = "docs/evidence/WWHN-188/WWHN-188_SOURCE_REGISTER.md";
if (existsSync(resolve(ROOT, sourceRegister))) {
  const text = read(sourceRegister);
  record(
    "P1-06/source-register-pins-current-and-superseded",
    text.includes("BRD v3.9") && text.includes("BRD Hoa Nam v3.20") && text.includes("SRS/FRD v1.12"),
    "phải nêu cả nguồn hiện hành lẫn supersession v3.9",
  );
  record("P1-06/source-register-names-parent", text.includes("WWHN-42"), "phải neo vào task cha WWHN-42");
} else {
  record("P1-06/source-register-pins-current-and-superseded", false, `Thiếu ${sourceRegister}`);
}

// ---------------------------------------------------------------------------
// DEV188-P1-04R2 — ma trận QA phủ đủ ô reviewer liệt kê.
// ---------------------------------------------------------------------------
const matrix = "docs/evidence/WWHN-188/WWHN-188_QA_TEST_MATRIX.md";
if (existsSync(resolve(ROOT, matrix))) {
  const text = read(matrix);
  const cells = [
    "401",
    "403",
    "409",
    "412",
    "422",
    "428",
    "DUPLICATE_ITEM_IN_DOCUMENT",
    "SERIAL_QUANTITY_MUST_BE_ONE",
    "SCAN_EXCEEDS_REQUIRED",
    "IDEMPOTENCY_CONFLICT",
    "same_idempotency_key_quantity_mode",
    "test_lost_response_retry_with_same_key_replays_and_does_not_double_count",
    "test_refetch_retry_with_same_key_and_current_version_does_not_double_count",
    "test_failed_scan_does_not_poison_the_idempotency_key",
  ];
  const missing = cells.filter((c) => !text.includes(c));
  record("P1-04/qa-matrix-covers-required-cells", missing.length === 0, missing.length === 0 ? `${cells.length}/${cells.length} ô` : `thiếu: ${missing.join(", ")}`);
} else {
  record("P1-04/qa-matrix-covers-required-cells", false, `Thiếu ${matrix}`);
}

// ---------------------------------------------------------------------------
// DEV188-P2-01 — fixture không còn mang key sai.
// ---------------------------------------------------------------------------
const concurrencyTest = read("tests/Feature/InboundScanConcurrencyBehaviorTest.php");
const strayFixtures = concurrencyTest.match(/WWHN187-[A-Z0-9-]+|NCC WWHN-187|wwhn187-[a-z0-9-]+/g) ?? [];
record(
  "P2-01/no-stale-wwhn187-fixture-strings",
  strayFixtures.length === 0,
  strayFixtures.length === 0 ? "sạch" : strayFixtures.join(", "),
);

report();

function report() {
  const failed = results.filter((r) => !r.ok);
  for (const r of results) {
    console.log(`${r.ok ? "PASS" : "FAIL"}  ${r.id.padEnd(52)} ${r.detail}`);
  }
  console.log(
    `\nWWHN-188 closure verifier: ${results.length - failed.length}/${results.length} PASS` +
      (failed.length === 0 ? "" : ` — FAIL: ${failed.map((f) => f.id).join(", ")}`),
  );
  process.exit(failed.length === 0 ? 0 : 1);
}
