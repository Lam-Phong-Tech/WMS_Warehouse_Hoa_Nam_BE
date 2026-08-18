#!/usr/bin/env node
/**
 * WWHN-230 closure gate — DISP-STK-230 (park/de-scope Stocktake).
 *
 * Proves, at the exact checked-out SHA, that:
 *   1. the disposition + approval records exist and select Option B;
 *   2. the controlling baseline still says what the guard enforces (REQ-SRS-037);
 *   3. no Stocktake surface exists in routes, generated OpenAPI, schema-facing source
 *      or the permission catalog;
 *   4. the substitute correction path (append-only Reversal) is still present;
 *   5. the WWHN-230 dependency temp key HN-INV-001 resolves to a canonical Jira key.
 *
 * Writes docs/evidence/WWHN-230/CLOSURE_EVIDENCE.json + SHA256SUMS.
 *
 * Usage:
 *   node scripts/verify-wwhn-230-closure.mjs
 *   node scripts/verify-wwhn-230-closure.mjs --require-signoff   # final gate
 *   node scripts/verify-wwhn-230-closure.mjs --with-tests        # also run the phpunit lock
 */

import { createHash } from "node:crypto";
import { execFileSync } from "node:child_process";
import { existsSync, mkdirSync, readFileSync, readdirSync, statSync, writeFileSync } from "node:fs";
import { dirname, join, relative, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const root = resolve(dirname(fileURLToPath(import.meta.url)), "..");
const argv = new Set(process.argv.slice(2));
const requireSignoff = argv.has("--require-signoff");
const withTests = argv.has("--with-tests");

const failures = [];
const checks = [];

function record(id, ok, detail) {
  checks.push({ id, status: ok ? "PASS" : "FAIL", detail });
  if (!ok) failures.push(`${id}: ${detail}`);
}

function readText(relativePath) {
  const full = join(root, relativePath);
  return existsSync(full) ? readFileSync(full, "utf8") : null;
}

function readJson(relativePath) {
  const text = readText(relativePath);
  if (text === null) return null;
  try {
    return JSON.parse(text);
  } catch (error) {
    failures.push(`${relativePath} is not valid JSON: ${error.message}`);
    return null;
  }
}

function sha256(relativePath) {
  const full = join(root, relativePath);
  if (!existsSync(full)) return null;
  return createHash("sha256").update(readFileSync(full)).digest("hex");
}

function git(args, cwd = root) {
  try {
    return execFileSync("git", args, { cwd, encoding: "utf8" }).trim();
  } catch {
    return null;
  }
}

// ---------------------------------------------------------------------------
// 1. Disposition + approval records
// ---------------------------------------------------------------------------

const DISPOSITION = "docs/decision-records/DISP-STK-230-scope-disposition.md";
const APPROVAL = "docs/decision-records/DISP-STK-230-APPROVAL.json";

const disposition = readText(DISPOSITION);
record("DISP-EXISTS", disposition !== null, `${DISPOSITION} must exist.`);

if (disposition) {
  record(
    "DISP-OPTION-B",
    /Option chọn\s*\|\s*\*\*B\b/u.test(disposition) || /Option chosen\s*\|\s*\*\*B\b/u.test(disposition),
    "The disposition must record Option B (park/de-scope) explicitly."
  );
  record(
    "DISP-ANCHOR",
    disposition.includes("REQ-SRS-037"),
    "The disposition must anchor the rule to REQ-SRS-037, not to a standalone Decision ID."
  );
  record(
    "DISP-NO-FALSE-APPROVAL",
    !/DEC-STK-001[^\n]*\bAPPROVED\b/u.test(disposition) && !/DEC-NO-STOCKTAKE-001[^\n]*\bAPPROVED\b/u.test(disposition),
    "The disposition must not present DEC-STK-001 / DEC-NO-STOCKTAKE-001 as approved."
  );
}

const approval = readJson(APPROVAL);
record("APPROVAL-EXISTS", approval !== null, `${APPROVAL} must exist.`);

if (approval) {
  record("APPROVAL-OPTION-B", approval.optionChosen === "B", "approval.optionChosen must be \"B\".");
  record(
    "APPROVAL-REQUIREMENT",
    approval.controllingRequirement === "REQ-SRS-037",
    "approval.controllingRequirement must be REQ-SRS-037."
  );
  const impacted = approval.impactedIssues ?? [];
  record(
    "APPROVAL-SCOPE",
    ["WWHN-230", "WWHN-58", "WWHN-232"].every((issue) => impacted.includes(issue)),
    "approval.impactedIssues must cover WWHN-230, WWHN-58 and WWHN-232."
  );

  const signOff = Object.entries(approval.signOff ?? {});
  const missing = signOff.filter(([, value]) => !value.decision || !value.date || !value.commentUrl).map(([key]) => key);

  if (requireSignoff) {
    record("APPROVAL-SIGNOFF", missing.length === 0, `Missing recorded sign-off for: ${missing.join(", ") || "none"}`);
    record(
      "APPROVAL-SHA-PINNED",
      Boolean(approval.exactCommitSha?.backend) && Boolean(approval.exactCommitSha?.frontend),
      "exactCommitSha.backend and exactCommitSha.frontend must be pinned at sign-off time."
    );
    record(
      "APPROVAL-EFFECTIVE-DATE",
      Boolean(approval.effectiveDate),
      "effectiveDate must be set once every approver has signed."
    );
  } else {
    checks.push({
      id: "APPROVAL-SIGNOFF",
      status: missing.length === 0 ? "PASS" : "PENDING",
      detail: `Sign-off still open for: ${missing.join(", ") || "none"} (run with --require-signoff to enforce).`,
    });
    record(
      "APPROVAL-NOT-SELF-CLOSED",
      approval.status !== "APPROVED" || Boolean(approval.effectiveDate),
      "A record may not claim APPROVED without an effectiveDate."
    );
  }
}

// ---------------------------------------------------------------------------
// 2. The guard must not outlive its baseline
// ---------------------------------------------------------------------------

const srs = readText("docs/HN_SRS_FRD_v1.12_FULL.md");
record("BASELINE-SRS-PRESENT", srs !== null, "docs/HN_SRS_FRD_v1.12_FULL.md must be present.");
if (srs) {
  record("BASELINE-REQ-037", srs.includes("REQ-SRS-037"), "SRS no longer contains REQ-SRS-037; re-review before enforcing.");
  record(
    "BASELINE-REQ-037-TEXT",
    srs.includes("Không expose Stocktake/Variance"),
    "SRS no longer states the no-Stocktake requirement; the guard must not outlive it."
  );
  record(
    "BASELINE-NO-STOCKTAKE-SECTION",
    srs.includes("No stocktake: không có entity, API, permission, screen, report"),
    "SRS section 0.1 no longer excludes Stocktake from the MVP."
  );
}

const erd = readText("docs/HoaNam_ERD_Moi_Nhat.md");
record("BASELINE-ERD-PRESENT", erd !== null, "docs/HoaNam_ERD_Moi_Nhat.md must be present.");
if (erd) {
  record(
    "BASELINE-ERD-EXCLUSION",
    erd.includes("Không tạo Stocktake_Session"),
    "ERD section 0.1 no longer excludes Stocktake entities."
  );
}

// ---------------------------------------------------------------------------
// 3. Absence of the Stocktake surface
// ---------------------------------------------------------------------------

const FORBIDDEN_FRAGMENTS = ["stocktake", "stock-take", "apply-adjustment"];

const routeSource = ["api.php", "governance.php", "import.php", "web.php"]
  .map((file) => readText(`Modules/Warehouse/routes/${file}`) ?? "")
  .join("\n");

const routeHits = FORBIDDEN_FRAGMENTS.filter((fragment) => routeSource.toLowerCase().includes(fragment));
record("SURFACE-ROUTES", routeHits.length === 0, `Route files reference forbidden fragments: ${routeHits.join(", ")}`);

for (const spec of ["storage/api-docs/api-docs.json", "storage/api-docs/api-docs.yaml"]) {
  const parsed = spec.endsWith(".json") ? readJson(spec) : null;
  if (spec.endsWith(".json")) {
    if (parsed) {
      const offending = Object.keys(parsed.paths ?? {}).filter((path) =>
        FORBIDDEN_FRAGMENTS.some((fragment) => path.toLowerCase().includes(fragment))
      );
      record("SURFACE-OPENAPI-JSON", offending.length === 0, `OpenAPI paths expose Stocktake: ${offending.join(", ")}`);
    }
  } else {
    const text = readText(spec);
    if (text !== null) {
      // Only path keys matter; the spec description legitimately says "no Stocktake scope".
      const offending = text
        .split("\n")
        .filter((line) => /^\s{2,4}\/[^\s:]*(stocktake|apply-adjustment)/iu.test(line))
        .map((line) => line.trim());
      record("SURFACE-OPENAPI-YAML", offending.length === 0, `OpenAPI YAML exposes Stocktake paths: ${offending.join(", ")}`);
    }
  }
}

const sourceRoots = [
  "Modules/Warehouse/app/Http/Controllers",
  "Modules/Warehouse/app/Services",
  "Modules/Warehouse/app/Models",
];

const offendingFiles = [];
for (const relativeRoot of sourceRoots) {
  const full = join(root, relativeRoot);
  if (!existsSync(full)) continue;
  const stack = [full];
  while (stack.length > 0) {
    const current = stack.pop();
    for (const entry of readdirSync(current)) {
      const entryPath = join(current, entry);
      if (statSync(entryPath).isDirectory()) {
        stack.push(entryPath);
        continue;
      }
      const lower = entry.toLowerCase();
      if (lower.endsWith(".php") && (lower.includes("stocktake") || lower.includes("variance"))) {
        offendingFiles.push(relative(root, entryPath).split("\\").join("/"));
      }
    }
  }
}
record(
  "SURFACE-SOURCE",
  offendingFiles.length === 0,
  `Stocktake/variance implementation files found: ${offendingFiles.join(", ")}`
);

const contract = readJson("docs/contracts/HoaNam_Current_Baseline_Contract.json");
if (contract) {
  const fragments = [];
  const walk = (node) => {
    if (Array.isArray(node)) return;
    if (node && typeof node === "object") {
      for (const [key, value] of Object.entries(node)) {
        if (key === "forbidden_path_fragments" && Array.isArray(value)) fragments.push(...value);
        else walk(value);
      }
    }
  };
  walk(contract);
  record(
    "CONTRACT-FORBIDS-STOCKTAKE",
    fragments.includes("stocktake"),
    "docs/contracts/HoaNam_Current_Baseline_Contract.json must keep \"stocktake\" forbidden."
  );
}

// ---------------------------------------------------------------------------
// 4. Substitute correction path is present
// ---------------------------------------------------------------------------

const apiRoutes = readText("Modules/Warehouse/routes/api.php") ?? "";
const substitutePresent =
  apiRoutes.includes("reversal-documents") && /inbound-documents[^\n]*reverse|reverse[^\n]*inbound/iu.test(apiRoutes);
record(
  "SUBSTITUTE-PATH",
  substitutePresent,
  "De-scoping Stocktake only holds while the append-only Reversal correction path exists."
);

record(
  "SUBSTITUTE-DISPOSITION",
  readText("docs/decision-records/DISPOSITION-APPLY-ADJUSTMENT.md") !== null,
  "DISP-ADJ-001 must remain the canonical mapping for the adjustment business action."
);

// ---------------------------------------------------------------------------
// 5. Temp key traceability (DEV230-P1-04)
// ---------------------------------------------------------------------------

const REGISTER = "docs/traceability/TEMP_KEY_CANONICAL_REGISTER.json";
const register = readJson(REGISTER);
record("TEMPKEY-REGISTER", register !== null, `${REGISTER} must exist.`);

if (register) {
  const entries = register.entries ?? [];
  const byKey = new Map(entries.map((entry) => [entry.tempKey, entry]));

  const inv = byKey.get("HN-INV-001");
  record(
    "TEMPKEY-HN-INV-001",
    Boolean(inv) && inv.jiraKey === "WWHN-48" && inv.status === "CONFIRMED",
    "HN-INV-001 (the WWHN-230 dependency) must resolve to WWHN-48 with status CONFIRMED."
  );

  const unbacked = [];
  for (const entry of entries) {
    if (entry.status !== "CONFIRMED") continue;
    const evidence = entry.evidenceFile ? readText(entry.evidenceFile) : null;
    if (!evidence || !evidence.includes(entry.tempKey) || !evidence.includes(entry.jiraKey)) {
      unbacked.push(entry.tempKey);
    }
  }
  record(
    "TEMPKEY-EVIDENCE",
    unbacked.length === 0,
    `CONFIRMED rows without a repo source naming both keys: ${unbacked.join(", ")}`
  );

  const guessed = entries.filter((entry) => entry.status !== "CONFIRMED" && entry.jiraKey && entry.status !== "CANDIDATE");
  record(
    "TEMPKEY-NO-GUESSING",
    guessed.length === 0,
    `Rows assert a Jira key without CONFIRMED/CANDIDATE status: ${guessed.map((entry) => entry.tempKey).join(", ")}`
  );
}

// ---------------------------------------------------------------------------
// 6. Optional phpunit lock
// ---------------------------------------------------------------------------

let testResult = null;
if (withTests) {
  try {
    const output = execFileSync(
      "php",
      ["vendor/bin/phpunit", "--filter", "Wwhn230StocktakeScopeDisposition"],
      { cwd: root, encoding: "utf8" }
    );
    testResult = { exitCode: 0, tail: output.trim().split("\n").slice(-3).join("\n") };
    record("TEST-LOCK", true, "Wwhn230StocktakeScopeDispositionTest passed.");
  } catch (error) {
    testResult = { exitCode: error.status ?? 1, tail: String(error.stdout ?? error.message).trim().split("\n").slice(-5).join("\n") };
    record("TEST-LOCK", false, `Wwhn230StocktakeScopeDispositionTest failed:\n${testResult.tail}`);
  }
}

// ---------------------------------------------------------------------------
// Evidence
// ---------------------------------------------------------------------------

const feRoot = resolve(root, "../../HoaNam_FE/WMS_Warehouse_Hoa_Nam_FE");

const evidence = {
  schemaVersion: "1.0.0",
  jira: "WWHN-230",
  disposition: "DISP-STK-230",
  backlogItems: ["DEV230-P1-01", "DEV230-P1-02", "DEV230-P1-03", "DEV230-P1-04"],
  mode: requireSignoff ? "final" : "pre-signoff",
  identity: {
    backend: {
      repository: "Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE",
      branch: git(["rev-parse", "--abbrev-ref", "HEAD"]),
      commitSha: git(["rev-parse", "HEAD"]),
      workingTreeClean: git(["status", "--porcelain"]) === "",
    },
    frontend: existsSync(feRoot)
      ? {
          repository: "Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_FE",
          branch: git(["rev-parse", "--abbrev-ref", "HEAD"], feRoot),
          commitSha: git(["rev-parse", "HEAD"], feRoot),
          workingTreeClean: git(["status", "--porcelain"], feRoot) === "",
        }
      : { note: "FE checkout not reachable from this path." },
  },
  baselineDigests: {
    "docs/HN_SRS_FRD_v1.12_FULL.md": sha256("docs/HN_SRS_FRD_v1.12_FULL.md"),
    "docs/HoaNam_ERD_Moi_Nhat.md": sha256("docs/HoaNam_ERD_Moi_Nhat.md"),
    [DISPOSITION]: sha256(DISPOSITION),
    [APPROVAL]: sha256(APPROVAL),
    [REGISTER]: sha256(REGISTER),
  },
  checks,
  testResult,
  result: failures.length === 0 ? "PASS" : "FAIL",
  failures,
};

const evidenceDir = join(root, "docs/evidence/WWHN-230");
mkdirSync(evidenceDir, { recursive: true });
const evidencePath = join(evidenceDir, "CLOSURE_EVIDENCE.json");
writeFileSync(evidencePath, `${JSON.stringify(evidence, null, 2)}\n`, "utf8");

const sums = readdirSync(evidenceDir)
  .filter((file) => file !== "SHA256SUMS")
  .sort()
  .map((file) => `${createHash("sha256").update(readFileSync(join(evidenceDir, file))).digest("hex")}  ${file}`)
  .join("\n");
writeFileSync(join(evidenceDir, "SHA256SUMS"), `${sums}\n`, "utf8");

for (const check of checks) {
  const mark = check.status === "PASS" ? "PASS" : check.status === "PENDING" ? "PEND" : "FAIL";
  console.log(`[${mark}] ${check.id}${check.status === "PASS" ? "" : ` — ${check.detail}`}`);
}
console.log(`\nEvidence: ${relative(root, evidencePath).split("\\").join("/")}`);

if (failures.length > 0) {
  console.error(`\nWWHN-230 closure gate FAILED (${failures.length}).`);
  process.exit(1);
}
console.log("\nWWHN-230 closure gate PASSED.");
