#!/usr/bin/env node
/**
 * WWHN-273 hard gate — release sign-off provenance/evidence contract.
 *
 * Bối cảnh (DEV273-P1-01): gate `wwhn-273-backend-release-signoff.yml` được dựng
 * đầy đủ nhưng CHƯA BAO GIỜ xanh — 7/7 run đều failure. Vì mọi file evidence đều
 * do một bước ở giữa job ghi ra, một run đỏ không để lại artifact nào, và bảng
 * "bằng chứng chạy tại chỗ" trong task doc đã lấp chỗ trống đó. Kết quả: task
 * được trình bày là EVIDENCE READY trong khi exact run/artifact không truy xuất
 * được.
 *
 * Script này biến ba thứ thành ràng buộc chạy được:
 *   1. Pin evidence bằng sha256 nội dung từng file WWHN-273 sở hữu (range
 *      invariant — không pin theo HEAD, nên commit gộp của ticket khác không làm
 *      pin rot, nhưng sửa nội dung file của WWHN-273 thì bị bắt).
 *   2. Hợp đồng giữa RC pin ↔ workflow ↔ task doc: danh sách artifact bắt buộc
 *      và bộ probe absence không được lệch nhau ở ba nơi.
 *   3. Tính trung thực: khi `gate.green_at_sha` còn null thì task doc BẮT BUỘC
 *      hiển thị trạng thái đỏ và nêu tên defect đang chặn — không thể tuyên bố
 *      sign-off lần nữa trong lúc gate đỏ.
 *
 * Chạy độc lập, không boot Laravel/DB nên dùng được cả trong CI lẫn local:
 *
 *   node scripts/verify-wwhn-273-release-signoff.mjs
 *   node scripts/verify-wwhn-273-release-signoff.mjs --emit
 *
 * `--emit` ghi lại khối `owned_files[].sha256` trong docs/evidence/WWHN-273/RC_PIN.json
 * (dùng khi cố ý thay đổi một file WWHN-273 sở hữu).
 */
import { createHash } from "node:crypto";
import { existsSync, readFileSync, writeFileSync } from "node:fs";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), "..");
const EMIT = process.argv.includes("--emit");

const PIN_PATH = "docs/evidence/WWHN-273/RC_PIN.json";

const failures = [];
const checks = [];

const fail = (id, message) => failures.push(`[${id}] ${message}`);
const pass = (id, message) => checks.push(`[${id}] ${message}`);

const readIfExists = (rel) => {
  const abs = join(ROOT, rel);
  return existsSync(abs) ? readFileSync(abs, "utf8") : null;
};

const sha256 = (rel) => {
  const abs = join(ROOT, rel);
  return existsSync(abs) ? createHash("sha256").update(readFileSync(abs)).digest("hex") : null;
};

const pinRaw = readIfExists(PIN_PATH);
if (pinRaw === null) {
  console.error(`WWHN-273 verifier: thiếu ${PIN_PATH}`);
  process.exit(1);
}

let pin;
try {
  pin = JSON.parse(pinRaw);
} catch (error) {
  console.error(`WWHN-273 verifier: ${PIN_PATH} không phải JSON hợp lệ — ${error.message}`);
  process.exit(1);
}

/* ------------------------------------------------------------------ R1 pin */

const ownedFiles = Array.isArray(pin.owned_files) ? pin.owned_files : [];
if (ownedFiles.length === 0) {
  fail("R1", "RC_PIN.owned_files rỗng — không có gì được pin");
}

let emitted = false;
for (const entry of ownedFiles) {
  const actual = sha256(entry.path);
  if (actual === null) {
    fail("R1", `file được pin không tồn tại: ${entry.path}`);
    continue;
  }
  if (entry.sha256 === actual) {
    pass("R1", `pin khớp: ${entry.path}`);
    continue;
  }
  if (EMIT) {
    entry.sha256 = actual;
    emitted = true;
    pass("R1", `pin được ghi lại: ${entry.path}`);
    continue;
  }
  fail(
    "R1",
    `pin rot: ${entry.path} đã đổi nội dung (pin ${String(entry.sha256).slice(0, 12)}…, thực tế ${actual.slice(0, 12)}…). ` +
      "Nếu đổi có chủ ý, chạy lại với --emit và ghi lý do vào task doc."
  );
}

/* -------------------------------------------------------------- R2 gate wiring */

const workflowPath = pin.gate?.workflow;
const workflow = workflowPath ? readIfExists(workflowPath) : null;
if (workflow === null) {
  fail("R2", `không đọc được workflow của gate: ${workflowPath ?? "(chưa khai trong pin)"}`);
} else {
  for (const file of pin.gate?.required_artifact_files ?? []) {
    if (workflow.includes(file)) {
      pass("R2", `workflow thực sự ghi artifact bắt buộc: ${file}`);
    } else {
      fail("R2", `RC_PIN đòi artifact "${file}" nhưng workflow không ghi file đó`);
    }
  }

  // Bước status luôn chạy chính là thứ làm cho một run đỏ vẫn có artifact.
  for (const marker of ["if: always()", "gate-status.txt", "job_status="]) {
    if (workflow.includes(marker)) {
      pass("R2", `workflow giữ cơ chế evidence-on-red: ${marker}`);
    } else {
      fail("R2", `workflow thiếu cơ chế evidence-on-red: ${marker}`);
    }
  }
}

/* ------------------------------------------------------- R3 absence probes */

const probes = pin.stocktake_absence?.probes ?? [];
if (probes.length === 0) {
  fail("R3", "RC_PIN.stocktake_absence.probes rỗng — AC-SRS-037 không được kiểm ở tầng runtime");
}
if (workflow !== null) {
  for (const probe of probes) {
    if (workflow.includes(probe)) {
      pass("R3", `probe absence có trong gate: ${probe}`);
    } else {
      fail("R3", `probe absence "${probe}" khai trong RC_PIN nhưng không có trong gate`);
    }
  }
  if (/REQ-SRS-037 violated/.test(workflow)) {
    pass("R3", "gate fail-closed khi bề mặt Stocktake được serve");
  } else {
    fail("R3", "gate không có khẳng định fail-closed cho REQ-SRS-037");
  }
}

/* ------------------------------------------------------------- R4 honesty */

const taskDocPath = pin.task_doc ?? "docs/docs_task_jira/WWHN-273.md";
const taskDoc = readIfExists(taskDocPath);
if (taskDoc === null) {
  fail("R4", `không đọc được task doc: ${taskDocPath}`);
} else {
  const greenAt = pin.gate?.green_at_sha ?? null;
  if (greenAt === null) {
    if (taskDoc.includes("GATE_STATUS_AT_PIN=RED")) {
      pass("R4", "gate chưa xanh và task doc hiển thị đúng trạng thái đỏ");
    } else {
      fail(
        "R4",
        "gate.green_at_sha = null nhưng task doc không mang marker GATE_STATUS_AT_PIN=RED — " +
          "không được trình bày task là evidence-ready trong lúc gate đỏ"
      );
    }
    for (const blocker of pin.gate?.blocking_defects ?? []) {
      const id = blocker.id ?? "";
      if (id !== "" && taskDoc.includes(id)) {
        pass("R4", `task doc nêu tên defect đang chặn: ${id}`);
      } else {
        fail("R4", `task doc không nêu defect đang chặn: ${id || "(thiếu id)"}`);
      }
    }
  } else if (taskDoc.includes("GATE_STATUS_AT_PIN=RED")) {
    fail("R4", `gate.green_at_sha = ${greenAt} nhưng task doc vẫn còn marker GATE_STATUS_AT_PIN=RED`);
  } else {
    pass("R4", `gate xanh tại ${greenAt} và task doc không còn marker đỏ`);
  }

  /* ------------------------------------------------- R5 local-evidence label */

  if (taskDoc.includes("NON-CANDIDATE / DEV-ONLY")) {
    pass("R5", "bằng chứng chạy tại chỗ được dán nhãn NON-CANDIDATE / DEV-ONLY");
  } else {
    fail(
      "R5",
      "task doc không dán nhãn NON-CANDIDATE / DEV-ONLY cho bằng chứng chạy tại chỗ (DEV273-P2-01)"
    );
  }
}

/* -------------------------------------------------- R6 disposition state */

const disp = readIfExists("docs/decision-records/DISP-REL-273-STOCKTAKE-RELEASE-CRITERIA.md");
if (disp === null) {
  fail("R6", "thiếu docs/decision-records/DISP-REL-273-STOCKTAKE-RELEASE-CRITERIA.md (DEV273-P1-05)");
} else {
  if (/Status \|\s*\*\*PROPOSED/.test(disp)) {
    pass("R6", "DISP-REL-273 vẫn PROPOSED — không tự nhận approved");
  } else {
    fail("R6", "DISP-REL-273 phải giữ Status = PROPOSED cho tới khi có approver trên Jira");
  }
  if (/Effective date \|\s*`NULL/.test(disp)) {
    pass("R6", "DISP-REL-273 Effective date = NULL");
  } else {
    fail("R6", "DISP-REL-273 phải giữ Effective date = NULL");
  }
  for (const anchor of ["REQ-SRS-037", "AC-SRS-037"]) {
    if (disp.includes(anchor)) {
      pass("R6", `DISP-REL-273 neo vào ${anchor}`);
    } else {
      fail("R6", `DISP-REL-273 phải neo vào ${anchor}`);
    }
  }
}

const decStk = readIfExists("docs/decision-records/DEC-STK-001.md");
if (decStk === null) {
  fail("R6", "thiếu DEC-STK-001.md — không kiểm được rằng nó vẫn chưa được duyệt");
} else if (/APPROVED/i.test(decStk) && !/PROPOSED/i.test(decStk)) {
  fail("R6", "DEC-STK-001 bị trình bày như APPROVED — DISP-REL-273 không duyệt nó");
} else {
  pass("R6", "DEC-STK-001 vẫn chưa được duyệt (mô hình kiểm kê A không có hiệu lực)");
}

/* ------------------------------------------------------ R7 run traceability */

const runs = pin.gate?.observed_runs ?? [];
if (runs.length === 0) {
  fail("R7", "RC_PIN.gate.observed_runs rỗng — exact run vẫn không truy xuất được (DEV273-P1-01)");
} else {
  const incomplete = runs.filter((run) => !run.run_id || !run.sha || !run.conclusion);
  if (incomplete.length > 0) {
    fail("R7", `${incomplete.length} run được ghi thiếu run_id/sha/conclusion`);
  } else {
    pass("R7", `${runs.length} run của gate được ghi kèm run_id/sha/conclusion`);
  }
}

/* --------------------------------------------------------- R8 provenance */

const provenance = readIfExists("docs/evidence/WWHN-273/CANDIDATE_PROVENANCE.md");
if (provenance === null) {
  fail("R8", "thiếu docs/evidence/WWHN-273/CANDIDATE_PROVENANCE.md (DEV273-P1-06)");
} else {
  for (const marker of ["PR = N/A", "compare"]) {
    if (provenance.includes(marker)) {
      pass("R8", `provenance khai rõ: ${marker}`);
    } else {
      fail("R8", `provenance thiếu khai báo: ${marker}`);
    }
  }
}

/* ------------------------------------------------------------------ output */

if (EMIT && emitted) {
  writeFileSync(join(ROOT, PIN_PATH), `${JSON.stringify(pin, null, 2)}\n`, "utf8");
  console.log(`RC pin đã được ghi lại: ${PIN_PATH}`);
}

for (const line of checks) {
  console.log(`PASS ${line}`);
}
if (failures.length > 0) {
  console.error("");
  for (const line of failures) {
    console.error(`FAIL ${line}`);
  }
  console.error(`\nWWHN-273 release sign-off contract: ${failures.length} vi phạm.`);
  process.exit(1);
}

console.log(`\nWWHN-273 release sign-off contract: ${checks.length} khẳng định PASS.`);
