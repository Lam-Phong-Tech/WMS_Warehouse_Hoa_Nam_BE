#!/usr/bin/env node
/**
 * WWHN-295 — gate đóng register 73/100 (DEV295-P1-01/P1-02/P1-03).
 *
 * Vì sao cần một verifier riêng thay vì chỉ dựa vào phpunit:
 *
 *  - DEV295-P1-01 hỏi "candidate SHA nào là canonical". Câu đó không trả lời được
 *    bằng test đơn lẻ: phải đối chiếu commit đã pin với lịch sử git thật và chứng
 *    minh source của WWHN-295 KHÔNG đổi kể từ commit đó (range invariant). Pin trần
 *    một SHA rồi để nhánh chạy tiếp là pin rot: reviewer đọc số cũ, code đã khác.
 *  - DEV295-P1-02 yêu cầu OpenAPI/Postman "cùng revision và pin hash". Hash toàn file
 *    được xác minh trên BLOB TẠI COMMIT ĐÃ PIN (bất biến, không rot); hash phần
 *    contract của health (slice) được xác minh trên working tree nên mọi lần regenerate
 *    sau vẫn phải giữ đúng contract.
 *  - DEV295-P1-03 yêu cầu xử lý phần diff của WWHN-296 bị gộp trong commit 9aa2231.
 *    Verifier liệt kê thẳng file lạ trong candidate, nên "bundled diff" là dữ kiện
 *    máy đọc được chứ không phải lời hứa trong mô tả.
 *
 * Dùng:
 *   node scripts/verify-wwhn-295-closure.mjs                # gate (CI + trước khi ký)
 *   node scripts/verify-wwhn-295-closure.mjs --write-lock   # sinh/pin lại lock file
 *   node scripts/verify-wwhn-295-closure.mjs --candidate=<sha> --write-lock
 */
import { createHash } from "node:crypto";
import { execFileSync } from "node:child_process";
import { existsSync, readFileSync, writeFileSync, mkdirSync } from "node:fs";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const root = resolve(dirname(fileURLToPath(import.meta.url)), "..");
const LOCK_PATH = "docs/evidence/WWHN-295/contract-lock.json";
const OPENAPI_JSON = "storage/api-docs/api-docs.json";
const OPENAPI_YAML = "storage/api-docs/api-docs.yaml";
const POSTMAN = "docs/postman/WMS_HoaNam API Contract - DEV.postman_collection.json";
const TASK_DOC = "docs/docs_task_jira/WWHN-295.md";
const HEALTH_PATH = "/api/v1/health";
const HEALTH_SCHEMA_PREFIX = "Health";

/**
 * File thuộc quyền sở hữu của WWHN-295. Dùng cho hai việc: (1) range invariant —
 * source phải đứng yên kể từ candidate; (2) phát hiện diff của task khác bị gộp
 * vào candidate (bài học 9aa2231 mang theo test của WWHN-296).
 */
const OWNED_PATHS = [
  ".env.example",
  ".github/workflows/wwhn-295-runtime-config-health.yml",
  "app/Console/Commands/BackendGoLiveCheck.php",
  "app/Http/Controllers/HealthController.php",
  "app/Http/Middleware/NoStoreApiResponses.php",
  "app/Swagger/HealthSchemas.php",
  "app/Swagger/Processors/RouteSyncProcessor.php",
  "bootstrap/app.php",
  "config/cache.php",
  "config/health.php",
  "config/queue.php",
  "config/trustedproxy.php",
  "docker-compose.yml",
  "docker/nginx/default.conf",
  "scripts/collect-wwhn295-runtime-evidence.sh",
  "scripts/verify-wwhn-295-closure.mjs",
  "tests/Feature/Wwhn295HealthContractTest.php",
  "tests/Feature/Wwhn295RuntimeConfigHealthTest.php",
  OPENAPI_JSON,
  OPENAPI_YAML,
  POSTMAN,
];

/**
 * Source phải đứng yên kể từ candidate.
 *
 * KHÔNG đóng băng ba artifact sinh ra (`api-docs.json|yaml`, collection DEV): chúng là
 * tài sản dùng chung, task khác thêm endpoint là chúng đổi hợp lệ. Tính toàn vẹn phần
 * contract của health được bảo vệ bằng hai lớp khác, chặt hơn và không đỏ oan:
 * `slices` (hash phần health trên working tree) và `artifacts` (hash toàn file trên
 * blob TẠI candidate, bất biến). Đóng băng cả file sẽ khiến gate WWHN-295 đỏ mỗi lần
 * một task khác regenerate spec — tức là gate tự huấn luyện người đọc bỏ qua nó.
 */
const GENERATED_ARTIFACTS = [OPENAPI_JSON, OPENAPI_YAML, POSTMAN];
const FROZEN_PATHS = OWNED_PATHS.filter(
  (path) => !path.startsWith("docs/evidence/") && !GENERATED_ARTIFACTS.includes(path),
);

const REGISTER_IDS = [
  "DEV295-P1-01",
  "DEV295-P1-02",
  "DEV295-P1-03",
  "DEV295-P1-04",
  "DEV295-P1-05",
  "DEV295-P1-06",
  "DEV295-P2-01",
];

const args = process.argv.slice(2);
const writeLock = args.includes("--write-lock");
const candidateArg = args.find((a) => a.startsWith("--candidate="))?.split("=")[1];

const failures = [];
const notes = [];

function fail(id, message) {
  failures.push(`[${id}] ${message}`);
}

function info(message) {
  notes.push(message);
}

function git(...gitArgs) {
  return execFileSync("git", gitArgs, { cwd: root, encoding: "utf8", maxBuffer: 64 * 1024 * 1024 }).trim();
}

function gitOrNull(...gitArgs) {
  try {
    return git(...gitArgs);
  } catch {
    return null;
  }
}

function sha256(buffer) {
  return createHash("sha256").update(buffer).digest("hex");
}

function readFile(relativePath) {
  return readFileSync(join(root, relativePath));
}

function readJson(relativePath) {
  return JSON.parse(readFile(relativePath).toString("utf8"));
}

/** Chuỗi hoá ổn định (sắp khoá) để hash slice không phụ thuộc thứ tự sinh ra. */
function canonical(value) {
  if (Array.isArray(value)) return `[${value.map(canonical).join(",")}]`;
  if (value && typeof value === "object") {
    return `{${Object.keys(value)
      .sort()
      .map((key) => `${JSON.stringify(key)}:${canonical(value[key])}`)
      .join(",")}}`;
  }
  return JSON.stringify(value ?? null);
}

function healthSlices() {
  const spec = readJson(OPENAPI_JSON);
  const collection = readJson(POSTMAN);
  const operation = spec.paths?.[HEALTH_PATH]?.get ?? null;
  const schemas = Object.fromEntries(
    Object.entries(spec.components?.schemas ?? {}).filter(([name]) => name.startsWith(HEALTH_SCHEMA_PREFIX)),
  );
  const platform = (collection.item ?? []).find((folder) => folder.name === "00. Platform") ?? null;

  return {
    spec,
    collection,
    operation,
    schemas,
    platform,
    hashes: {
      openapi_health_operation: sha256(canonical(operation)),
      openapi_health_schemas: sha256(canonical(schemas)),
      postman_platform_folder: sha256(canonical(platform)),
    },
  };
}

function collectPaths(items, out = []) {
  for (const item of items ?? []) {
    if (Array.isArray(item.item)) collectPaths(item.item, out);
    else if (Array.isArray(item.request?.url?.path)) out.push(item.request.url.path.join("/"));
  }
  return out;
}

// ---------------------------------------------------------------- contract shape
function verifyContractShape({ operation, schemas, platform, collection }) {
  if (!operation) {
    fail("DEV295-P1-02", `${OPENAPI_JSON} không có ${HEALTH_PATH} — chạy php artisan l5-swagger:generate.`);
    return;
  }

  const codes = Object.keys(operation.responses ?? {}).sort();
  if (codes.join(",") !== "200,503") {
    fail(
      "DEV295-P1-02",
      `Health chỉ có hai kết cục thật (200/503) nhưng spec publish ${codes.join(", ") || "không gì cả"}.`,
    );
  }
  if (operation["x-exhaustive-responses"] !== true) {
    fail("DEV295-P1-02", "Thiếu x-exhaustive-responses — lần regenerate sau baseline error sẽ quay lại.");
  }
  if (JSON.stringify(operation.security) !== "[]") {
    fail("DEV295-P1-02", "Readiness phải public (security: []).");
  }

  const expectedRefs = {
    200: "#/components/schemas/HealthReadyEnvelope",
    503: "#/components/schemas/HealthDegradedEnvelope",
  };
  for (const [code, ref] of Object.entries(expectedRefs)) {
    const actual = operation.responses?.[code]?.content?.["application/json"]?.schema?.$ref;
    if (actual !== ref) fail("DEV295-P1-02", `Response ${code} phải trỏ ${ref}, đang là ${actual ?? "không có"}.`);
    if (!operation.responses?.[code]?.headers?.["Cache-Control"]) {
      fail("DEV295-P1-02", `Response ${code} chưa khai header Cache-Control.`);
    }
  }

  for (const required of ["status", "version", "build", "environment", "server_time", "request_id", "checks"]) {
    if (!(schemas.HealthPayload?.required ?? []).includes(required)) {
      fail("DEV295-P1-02", `HealthPayload phải khai ${required} là bắt buộc.`);
    }
  }
  for (const field of ["sha", "ref", "built_at"]) {
    if (!(schemas.HealthBuildIdentity?.properties ?? {})[field]) {
      fail("DEV295-P1-02", `HealthBuildIdentity thiếu ${field} — gate exact-SHA không đọc được danh tính artifact.`);
    }
  }

  if (!platform) {
    fail("DEV295-P1-02", "Collection DEV thiếu thư mục 00. Platform.");
    return;
  }
  const request = platform.item?.[0]?.request;
  if ((request?.url?.path ?? []).join("/") !== "api/v1/health") {
    fail(
      "DEV295-P1-02",
      `Postman gọi /${(request?.url?.path ?? []).join("/")} — routes/api.php chỉ đăng ký api/v1/health.`,
    );
  }
  const legacy = collectPaths(collection.item).filter((path) => path === "api/health");
  if (legacy.length > 0) {
    fail("DEV295-P1-02", "Còn request trỏ path legacy /api/health (route không tồn tại → 404).");
  }
  const script = (platform.item?.[0]?.event?.[0]?.script?.exec ?? []).join("\n");
  for (const field of ["build", "checks", "request_id", "expectedBuildSha"]) {
    if (!script.includes(field)) fail("DEV295-P1-02", `Test script Postman chưa kiểm ${field}.`);
  }
}

// ------------------------------------------------------------------- source pin
function verifySourcePin(lock) {
  const candidate = lock.candidate_sha;
  if (!candidate || !/^[0-9a-f]{40}$/.test(candidate)) {
    fail("DEV295-P1-01", `candidate_sha chưa được pin trong ${LOCK_PATH} (cần đúng 40 hex).`);
    return null;
  }
  // `git cat-file -e` không in gì khi thành công, nên phải kiểm bằng lệnh có output:
  // dùng nhầm cat-file ở đây làm mọi candidate hợp lệ bị báo "không tồn tại".
  if (gitOrNull("rev-parse", "--verify", "--quiet", `${candidate}^{commit}`) === null) {
    fail("DEV295-P1-01", `candidate ${candidate} không tồn tại trong repo.`);
    return null;
  }
  try {
    git("merge-base", "--is-ancestor", candidate, "HEAD");
  } catch {
    fail("DEV295-P1-01", `candidate ${candidate} không phải ancestor của HEAD — pin đang trỏ ra ngoài nhánh.`);
    return candidate;
  }

  // Range invariant: source của WWHN-295 phải đứng yên kể từ candidate. Nếu đứng yên,
  // pin không rot dù nhánh m1 đã chạy tiếp bao nhiêu commit của task khác.
  const drifted = git("diff", "--name-only", `${candidate}..HEAD`, "--", ...FROZEN_PATHS)
    .split("\n")
    .filter(Boolean);
  if (drifted.length > 0) {
    fail(
      "DEV295-P1-01",
      `Source WWHN-295 đã đổi sau candidate: ${drifted.join(", ")}. Pin lại candidate mới thay vì giữ số cũ.`,
    );
  }
  const worktreeDirty = git("status", "--porcelain", "--", ...FROZEN_PATHS)
    .split("\n")
    .filter(Boolean);
  if (worktreeDirty.length > 0) {
    fail(
      "DEV295-P1-01",
      `Working tree còn thay đổi chưa commit trên file của WWHN-295:\n    ${worktreeDirty.join("\n    ")}`,
    );
  }

  // DEV295-P1-03: candidate không được mang theo diff của task khác.
  const touched = git("show", "--name-only", "--format=", candidate)
    .split("\n")
    .map((line) => line.trim())
    .filter(Boolean);
  const foreign = touched.filter(
    (path) => !OWNED_PATHS.includes(path) && !path.startsWith("docs/evidence/WWHN-295/") && path !== TASK_DOC,
  );
  if (foreign.length > 0) {
    fail(
      "DEV295-P1-03",
      `Candidate mang theo file ngoài phạm vi WWHN-295: ${foreign.join(", ")}. Tách ra hoặc ghi disposition trong ${TASK_DOC}.`,
    );
  } else {
    info(`Candidate ${candidate.slice(0, 12)} chỉ chạm ${touched.length} file thuộc WWHN-295 — không có diff bị gộp.`);
  }

  return candidate;
}

// --------------------------------------------------------------- artifact hashes
function verifyArtifacts(lock, candidate, hashes) {
  for (const [slice, expected] of Object.entries(lock.slices ?? {})) {
    if (hashes[slice] !== expected) {
      fail(
        "DEV295-P1-02",
        `Slice ${slice} đã đổi (lock ${expected.slice(0, 12)} ≠ hiện tại ${hashes[slice].slice(0, 12)}). ` +
          "Regenerate rồi pin lại bằng --write-lock, kèm review contract.",
      );
    }
  }

  for (const artifact of lock.artifacts ?? []) {
    const live = sha256(readFile(artifact.path));
    if (candidate) {
      const blob = gitOrNull("show", `${candidate}:${artifact.path}`);
      if (blob === null) {
        fail("DEV295-P1-02", `${artifact.path} không tồn tại tại candidate ${candidate}.`);
      } else {
        const pinned = sha256(
          execFileSync("git", ["show", `${candidate}:${artifact.path}`], {
            cwd: root,
            maxBuffer: 64 * 1024 * 1024,
          }),
        );
        if (pinned !== artifact.sha256) {
          fail(
            "DEV295-P1-02",
            `sha256 của ${artifact.path} tại candidate là ${pinned} nhưng lock ghi ${artifact.sha256}.`,
          );
        }
        if (live !== pinned) {
          info(`${artifact.path}: working tree đã khác bản tại candidate (sha256 ${live.slice(0, 12)}).`);
        }
      }
    }
  }
}

// ------------------------------------------------------------------ evidence doc
function verifyTaskDoc(candidate) {
  if (!existsSync(join(root, TASK_DOC))) {
    fail("DEV295-P1-01", `Thiếu ${TASK_DOC}.`);
    return;
  }
  const doc = readFile(TASK_DOC).toString("utf8");
  for (const id of REGISTER_IDS) {
    if (!doc.includes(id)) fail("DEV295-P1-01", `${TASK_DOC} không nhắc ${id} — register của reviewer bị rơi.`);
  }
  if (candidate && !doc.includes(candidate)) {
    fail("DEV295-P1-01", `${TASK_DOC} chưa ghi exact candidate ${candidate}.`);
  }
}

// ------------------------------------------------------------------------- chạy
const slices = healthSlices();

if (writeLock) {
  const candidate = candidateArg ?? git("rev-parse", "HEAD");
  const lock = {
    task: "WWHN-295",
    register: "DEV295-P1-01 (pin) · DEV295-P1-02 (contract) · DEV295-P1-03 (candidate sạch)",
    generated_by: "php artisan l5-swagger:generate && node scripts/verify-wwhn-295-closure.mjs --write-lock",
    openapi_revision: slices.spec.info?.version ?? null,
    candidate_sha: candidate,
    slices: slices.hashes,
    artifacts: [OPENAPI_JSON, OPENAPI_YAML, POSTMAN].map((path) => ({
      path,
      sha256: sha256(readFile(path)),
      bytes: readFile(path).length,
    })),
    frozen_source_paths: FROZEN_PATHS,
  };
  mkdirSync(join(root, dirname(LOCK_PATH)), { recursive: true });
  writeFileSync(join(root, LOCK_PATH), `${JSON.stringify(lock, null, 2)}\n`);
  console.log(`[wwhn-295] lock đã ghi tại ${LOCK_PATH} (candidate ${candidate}).`);
  process.exit(0);
}

if (!existsSync(join(root, LOCK_PATH))) {
  console.error(`[wwhn-295] FAIL: thiếu ${LOCK_PATH}. Chạy --write-lock sau khi commit candidate.`);
  process.exit(1);
}

const lock = readJson(LOCK_PATH);
verifyContractShape(slices);
const candidate = verifySourcePin(lock);
verifyArtifacts(lock, candidate, slices.hashes);
verifyTaskDoc(candidate);

for (const note of notes) console.log(`[wwhn-295] ${note}`);

if (failures.length > 0) {
  for (const failure of failures) console.error(`[wwhn-295] FAIL ${failure}`);
  console.error(`[wwhn-295] ${failures.length} gate chưa đạt.`);
  process.exit(1);
}

console.log(
  `[wwhn-295] PASS — contract health khớp spec/Postman đã pin, candidate ${lock.candidate_sha} sạch và source chưa trôi.`,
);
