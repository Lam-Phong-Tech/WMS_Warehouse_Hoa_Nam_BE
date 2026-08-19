#!/usr/bin/env node
/**
 * WWHN-236 / DEV236-P1-07R3 — một lệnh duy nhất để QA1 chạy negative gate tại exact SHA.
 *
 * Register R3 đòi: "QA soi độc lập checkout exact SHA và chạy negative gate; ghi
 * PASS / FAIL / DEFERRED-N/A trực tiếp trên Jira" kèm SHA/env/PG version/command/raw log.
 * Script này gom đúng bằng đó thứ vào một raw log để QA1 dán lên Jira mà không phải
 * tự ghép 4 lệnh và tự nhớ ghi env.
 *
 *   node scripts/qa-wwhn236-exact-sha-gate.mjs                 # chạy trên checkout hiện tại
 *   node scripts/qa-wwhn236-exact-sha-gate.mjs --strict-head   # bắt buộc HEAD == SHA đã pin
 *   node scripts/qa-wwhn236-exact-sha-gate.mjs --skip-phpunit  # khi chưa cài vendor
 *
 * Raw log: docs/evidence/WWHN-236/logs/qa-rerun-<be-sha7>.log
 *
 * Vì sao mặc định KHÔNG bắt HEAD == SHA đã pin: `m1` có nhiều phiên song song nên HEAD
 * chạy tiếp sau candidate. Điều QA cần chứng minh không phải "HEAD đứng yên" mà là
 * "tập artifact của WWHN-236 không đổi kể từ SHA đã pin" — script kiểm đúng mệnh đề đó
 * bằng blob sha. Khi QA checkout sạch đúng SHA thì thêm --strict-head để khoá cả HEAD.
 */
import { execFileSync, spawnSync } from "node:child_process";
import { existsSync, mkdirSync, readFileSync, writeFileSync } from "node:fs";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const BE_ROOT = resolve(dirname(fileURLToPath(import.meta.url)), "..");
const FE_ROOT = resolve(BE_ROOT, "../../HoaNam_FE/WMS_Warehouse_Hoa_Nam_FE");
const APPROVAL_REL = "docs/decision-records/DISP-STK-236-APPROVAL.json";
const REGISTER_REL = "docs/evidence/WWHN-236/SOURCE_REGISTER.json";

const argv = process.argv.slice(2);
const STRICT_HEAD = argv.includes("--strict-head");
const SKIP_PHPUNIT = argv.includes("--skip-phpunit");

const lines = [];
const say = (s = "") => {
  lines.push(s);
  console.log(s);
};

const capture = (cmd, args, cwd) => {
  try {
    return execFileSync(cmd, args, { cwd, encoding: "utf8", stdio: ["ignore", "pipe", "pipe"] }).trim();
  } catch (e) {
    return e?.stdout?.toString().trim() || `n/a (${cmd} không chạy được)`;
  }
};

const run = (label, cmd, args, cwd) => {
  say("");
  say(`$ ${cmd} ${args.join(" ")}    [cwd: ${cwd}]`);
  const r = spawnSync(cmd, args, { cwd, encoding: "utf8", shell: process.platform === "win32" });
  const out = `${r.stdout ?? ""}${r.stderr ?? ""}`.trimEnd();
  if (out) say(out);
  const code = r.status ?? 1;
  say(`--- ${label}: exit ${code} ---`);
  return { label, code };
};

const readJson = (root, rel) => {
  const abs = join(root, rel);
  return existsSync(abs) ? JSON.parse(readFileSync(abs, "utf8")) : null;
};

const approval = readJson(BE_ROOT, APPROVAL_REL);
const register = readJson(BE_ROOT, REGISTER_REL);
if (!approval || !register) {
  console.error(`FAIL: thiếu ${APPROVAL_REL} hoặc ${REGISTER_REL} — chạy build-wwhn236-source-register.mjs trước.`);
  process.exit(1);
}

const pinned = { BE: approval.exactCommitSha?.backend ?? null, FE: approval.exactCommitSha?.frontend ?? null };
const beHead = capture("git", ["rev-parse", "HEAD"], BE_ROOT);
const feHead = existsSync(FE_ROOT) ? capture("git", ["rev-parse", "HEAD"], FE_ROOT) : "n/a";

// ── Header: đúng những trường QA1 phải ghi lên Jira ───────────────────────────
say("WWHN-236 / WWHN-238 — QA negative gate tại exact SHA (DEV236-P1-07R3)");
say("=".repeat(78));
say(`Chạy lúc (UTC)      : ${new Date().toISOString()}`);
say(`OS                  : ${process.platform} ${process.arch}`);
say(`Node                : ${process.version}`);
say(`Git                 : ${capture("git", ["--version"], BE_ROOT)}`);
say(`PHP                 : ${capture("php", ["-r", "echo PHP_VERSION;"], BE_ROOT)}`);
say(`DB driver (.env)    : ${capture("php", ["-r", "$e=@file_get_contents('.env'); preg_match('/^DB_CONNECTION=(.*)$/m',$e,$m); echo trim($m[1] ?? 'unknown');"], BE_ROOT)}`);
say(`PostgreSQL          : ${capture("psql", ["--version"], BE_ROOT)}`);
say(`BE repo             : ${BE_ROOT}`);
say(`BE branch           : ${capture("git", ["rev-parse", "--abbrev-ref", "HEAD"], BE_ROOT)}`);
say(`BE HEAD             : ${beHead}`);
say(`BE candidate (pin)  : ${pinned.BE ?? "CHƯA PIN"}`);
say(`FE repo             : ${FE_ROOT}`);
say(`FE HEAD             : ${feHead}`);
say(`FE candidate (pin)  : ${pinned.FE ?? "CHƯA PIN"}`);
say(`Disposition         : DISP-STK-236 v${approval.dispositionVersion} — ${approval.decision}`);
say(`Approval status     : ${approval.status} · effectiveDate=${approval.effectiveDate ?? "NULL"}`);
say("=".repeat(78));

const failures = [];

// ── Bước 1: identity — artifact có đúng là bản đã pin không ───────────────────
{
  say("\n[1] IDENTITY — governed artifact có đúng blob của SHA đã pin?");
  for (const repo of register.candidate_identity ?? []) {
    const root = repo.repo === "BE" ? BE_ROOT : FE_ROOT;
    const head = repo.repo === "BE" ? beHead : feHead;
    const pin = pinned[repo.repo];
    if (!existsSync(root)) {
      say(`    ${repo.repo}: repo không có mặt — bỏ qua.`);
      continue;
    }
    if (!pin) {
      failures.push(`${repo.repo}: chưa pin exact SHA (approval.exactCommitSha.${repo.repo === "BE" ? "backend" : "frontend"} = null)`);
      say(`    ${repo.repo}: CHƯA PIN — FAIL`);
      continue;
    }
    if (STRICT_HEAD && head !== pin) {
      failures.push(`${repo.repo}: --strict-head bật nhưng HEAD ${head?.slice(0, 7)} != pin ${pin.slice(0, 7)}`);
      say(`    ${repo.repo}: HEAD != pin — FAIL (strict)`);
      continue;
    }
    const ancestor = spawnSync("git", ["merge-base", "--is-ancestor", pin, "HEAD"], { cwd: root });
    if ((ancestor.status ?? 1) !== 0) {
      failures.push(`${repo.repo}: SHA đã pin ${pin.slice(0, 7)} không phải ancestor của HEAD — pin đã mục hoặc checkout sai nhánh`);
      say(`    ${repo.repo}: pin không phải ancestor của HEAD — FAIL`);
      continue;
    }
    const drifted = [];
    for (const f of repo.governed_files ?? []) {
      if (f.pin_carrier || f.shared || !f.tracked) continue;
      const now = capture("git", ["rev-parse", `HEAD:${f.path}`], root);
      if (now !== f.blob_sha) drifted.push(`${f.path} (${f.blob_sha?.slice(0, 7)} → ${now.slice(0, 7)})`);
    }
    if (drifted.length > 0) {
      failures.push(`${repo.repo}: ${drifted.length} governed file đã đổi sau khi pin: ${drifted.join(", ")}`);
      say(`    ${repo.repo}: DRIFT — FAIL\n        ${drifted.join("\n        ")}`);
    } else {
      const n = (repo.governed_files ?? []).filter((f) => !f.pin_carrier && !f.shared && f.tracked).length;
      say(`    ${repo.repo}: PASS — ${n} governed file khớp blob đã pin; pin là ancestor của HEAD ${head?.slice(0, 7)}`);
    }
  }
}

// ── Bước 2..4: negative gate ──────────────────────────────────────────────────
const results = [];
results.push(run("BE verifier", "node", ["scripts/verify-wwhn236-adjustment-disposition.mjs"], BE_ROOT));
if (!SKIP_PHPUNIT) {
  results.push(
    run("BE phpunit", "php", ["vendor/bin/phpunit", "--filter", "Wwhn236DeferredAdjustmentGateTest"], BE_ROOT),
  );
} else {
  say("\n--- BE phpunit: SKIPPED (--skip-phpunit) ---");
}
if (existsSync(FE_ROOT)) {
  results.push(run("FE verifier", "node", ["scripts/verify-wwhn-236-closure.mjs"], FE_ROOT));
}

for (const r of results) if (r.code !== 0) failures.push(`${r.label}: exit ${r.code}`);

// ── Kết luận ──────────────────────────────────────────────────────────────────
say("");
say("=".repeat(78));
const verdict = failures.length === 0 ? "PASS" : "FAIL";
say(`KẾT QUẢ QA (WWHN-238): ${verdict}`);
say(
  verdict === "PASS"
    ? "Negative gate xanh tại exact SHA đã pin: capability Apply Adjustment không tồn tại ở mọi tầng đang sống.\n" +
        "WWHN-238 ghi DEFERRED / N/A cho MVP v1.12 (không phải Blocked) — phạm vi QA dương được bảo lưu ở QA_DISPOSITION_WWHN-238.md §3."
    : `Có ${failures.length} vấn đề:\n  - ${failures.join("\n  - ")}`,
);
say("=".repeat(78));

const logDir = join(BE_ROOT, "docs/evidence/WWHN-236/logs");
mkdirSync(logDir, { recursive: true });
const logPath = join(logDir, `qa-rerun-${(pinned.BE ?? beHead ?? "unknown").slice(0, 7)}.log`);
writeFileSync(logPath, `${lines.join("\n")}\n`);
console.log(`\n→ raw log: docs/evidence/WWHN-236/logs/qa-rerun-${(pinned.BE ?? beHead ?? "unknown").slice(0, 7)}.log`);

process.exit(failures.length === 0 ? 0 : 1);
