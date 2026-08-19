/**
 * WWHN-199 — dựng lại TOÀN BỘ bằng chứng của register DEV199 bằng một lệnh.
 *
 * Lý do tồn tại: DEV199-P1-03R bác bằng chứng cũ vì nó gắn với "HEAD lúc bắt đầu"
 * và không ai chạy lại được. DEV199-P1-05R yêu cầu QA1 retest **đúng exact build**.
 * Cả hai chỉ đóng được khi việc dựng bằng chứng là một lệnh xác định, không phải
 * một chuỗi thao tác tay được kể lại trong file .md.
 *
 * Script này chạy đúng 6 bước, fail-closed ở từng bước:
 *   1. dựng lại DB candidate từ số 0 (DROP + CREATE) — không tái dùng schema cũ;
 *   2. `php artisan migrate --force` trên PostgreSQL, ghi raw log;
 *   3. dựng fixture race (user/kho/SKU/item/toa/phiếu) qua Laravel thật;
 *   4. bật HAI server process riêng biệt (hai cổng, hai pool connection);
 *   5. bắn race thật: item-level (6 vòng) + toa-level (1 vòng);
 *   6. đọc DB after-state + pg_indexes + duplicate probe, ghi db-invariants.json.
 *
 * Dùng:
 *   node scripts/run-wwhn199-evidence.mjs
 *   node scripts/run-wwhn199-evidence.mjs --database=hn_wwhn199 --port-a=8199 --port-b=8299
 *   node scripts/run-wwhn199-evidence.mjs --keep-database   # giữ DB lại để soi tay
 *
 * Yêu cầu môi trường: PostgreSQL đang chạy và .env có DB_USERNAME/DB_PASSWORD trỏ tới
 * tài khoản được phép CREATE DATABASE. Script KHÔNG đụng vào DB nghiệp vụ trong .env —
 * nó luôn làm việc trên DB candidate tách rời (mặc định `hn_wwhn199`) và từ chối chạy
 * nếu tên DB candidate trùng DB_DATABASE trong .env.
 */
import { spawn } from 'node:child_process'
import { mkdir, readFile, writeFile } from 'node:fs/promises'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const EVIDENCE_DIR = 'build/evidence/wwhn-199'

function flag(name, fallback) {
  const hit = process.argv.find((a) => a.startsWith(`--${name}=`))
  return hit === undefined ? fallback : hit.split('=').slice(1).join('=')
}

const DATABASE = flag('database', 'hn_wwhn199')
const PORT_A = Number(flag('port-a', '8199'))
const PORT_B = Number(flag('port-b', '8299'))
const KEEP_DATABASE = process.argv.includes('--keep-database')

/**
 * `.env` của máy dev để DB_PASSWORD rỗng, nhưng PostgreSQL cục bộ đòi mật khẩu. Mọi
 * runbook QA trong repo này đều truyền `DB_USERNAME=postgres DB_PASSWORD=postgres` qua
 * biến môi trường (xem docs/evidence/WWHN-190/QA1_INDEPENDENT_TEST_PACK.md,
 * WWHN-204/QA1_RUNBOOK.md, ...), nên giữ đúng quy ước đó làm mặc định thay vì bắt người
 * chạy phải sửa .env — sửa .env sẽ đụng vào cấu hình dùng chung của các phiên khác.
 */
const DB_USERNAME = flag('username', process.env.DB_USERNAME || 'postgres')
const DB_PASSWORD = flag('password', process.env.DB_PASSWORD || 'postgres')

const steps = []
let failed = false

function log(line) {
  process.stdout.write(`${line}\n`)
}

function fail(message) {
  failed = true
  log(`[FAIL] ${message}`)
  throw new Error(message)
}

/** Đọc .env thủ công: chỉ cần 4 khoá kết nối, không kéo cả Laravel vào Node. */
async function readEnv() {
  const raw = await readFile(resolve(ROOT, '.env'), 'utf8')
  const env = {}
  for (const line of raw.split(/\r?\n/)) {
    const trimmed = line.trim()
    if (trimmed === '' || trimmed.startsWith('#')) continue
    const eq = trimmed.indexOf('=')
    if (eq <= 0) continue
    let value = trimmed.slice(eq + 1).trim()
    if (
      (value.startsWith('"') && value.endsWith('"')) ||
      (value.startsWith("'") && value.endsWith("'"))
    ) {
      value = value.slice(1, -1)
    }
    env[trimmed.slice(0, eq).trim()] = value
  }
  return env
}

function run(command, args, { env = {}, capture = true } = {}) {
  return new Promise((resolvePromise) => {
    const child = spawn(command, args, {
      cwd: ROOT,
      env: { ...process.env, ...env },
      shell: process.platform === 'win32',
    })
    let stdout = ''
    let stderr = ''
    if (capture) {
      child.stdout.on('data', (chunk) => {
        stdout += chunk
      })
      child.stderr.on('data', (chunk) => {
        stderr += chunk
      })
    } else {
      child.stdout.pipe(process.stdout)
      child.stderr.pipe(process.stderr)
    }
    child.on('close', (code) => resolvePromise({ code, stdout, stderr }))
    child.on('error', (error) => resolvePromise({ code: -1, stdout, stderr: String(error) }))
  })
}

function serve(port, env) {
  const child = spawn('php', ['artisan', 'serve', `--port=${port}`, '--host=127.0.0.1'], {
    cwd: ROOT,
    env: { ...process.env, ...env },
    shell: process.platform === 'win32',
    detached: false,
  })
  child.stdout.on('data', () => {})
  child.stderr.on('data', () => {})
  return child
}

async function waitForHealth(base, attempts = 40) {
  for (let i = 0; i < attempts; i++) {
    try {
      const response = await fetch(`${base}/api/v1/health`, { method: 'GET' })
      // 200 hay 503 đều chứng minh HTTP stack đã lên; ta chỉ cần server nhận request.
      if (response.status > 0) return true
    } catch {
      /* server chưa lên */
    }
    await new Promise((r) => setTimeout(r, 500))
  }
  return false
}

async function step(name, fn) {
  const startedAt = Date.now()
  log(`\n=== ${name} ===`)
  try {
    const detail = await fn()
    steps.push({ name, ok: true, ms: Date.now() - startedAt, detail: detail ?? null })
    log(`[OK] ${name}`)
  } catch (error) {
    steps.push({ name, ok: false, ms: Date.now() - startedAt, detail: String(error.message ?? error) })
    throw error
  }
}

const env = await readEnv()
if ((env.DB_DATABASE ?? '') === DATABASE) {
  log(
    `[REFUSE] DB candidate "${DATABASE}" trùng DB_DATABASE trong .env. ` +
      'Script này DROP DATABASE nên không được phép trỏ vào DB nghiệp vụ.',
  )
  process.exit(2)
}

const dbEnv = {
  DB_CONNECTION: 'pgsql',
  DB_HOST: env.DB_HOST || '127.0.0.1',
  DB_PORT: env.DB_PORT || '5432',
  DB_DATABASE: DATABASE,
  DB_USERNAME,
  DB_PASSWORD,
  APP_ENV: 'local',
}

const servers = []
let exitCode = 0

try {
  await step(`1. Dựng lại DB candidate "${DATABASE}" từ số 0`, async () => {
    const result = await run('php', ['scripts/wwhn199-recreate-candidate-database.php', DATABASE], {
      env: dbEnv,
    })
    if (result.code !== 0) fail(`Không dựng lại được DB: ${result.stdout}${result.stderr}`)
    return result.stdout.trim()
  })

  await step('2. php artisan migrate --force trên PostgreSQL', async () => {
    const result = await run('php', ['artisan', 'migrate', '--force', '--no-interaction'], { env: dbEnv })
    await mkdir(resolve(ROOT, EVIDENCE_DIR), { recursive: true })
    // .txt chứ không phải .log — `.gitignore` bỏ qua `*.log`, mà đây là bằng chứng
    // migration mà DEV199-P1-02R đòi phải commit được.
    await writeFile(
      resolve(ROOT, EVIDENCE_DIR, 'migrate-pgsql-candidate.raw.txt'),
      `${result.stdout}${result.stderr}`,
      'utf8',
    )
    if (result.code !== 0) fail(`migrate --force thất bại (exit ${result.code}). Xem raw log.`)
    const done = (result.stdout.match(/DONE/g) ?? []).length
    if (done < 100) fail(`Chỉ có ${done} migration DONE — nghi ngờ DB không được dựng mới.`)
    return `${done} migration DONE`
  })

  await step('3. Dựng fixture race', async () => {
    const result = await run(
      'php',
      ['scripts/wwhn199-race-fixture.php', 'build', `${EVIDENCE_DIR}/race-fixture.json`],
      { env: dbEnv },
    )
    if (result.code !== 0) fail(`Fixture thất bại: ${result.stdout}${result.stderr}`)
    return result.stdout.trim().split(/\r?\n/).slice(-1)[0]
  })

  await step(`4. Bật hai server process (${PORT_A}, ${PORT_B})`, async () => {
    servers.push(serve(PORT_A, dbEnv), serve(PORT_B, dbEnv))
    const [a, b] = await Promise.all([
      waitForHealth(`http://127.0.0.1:${PORT_A}`),
      waitForHealth(`http://127.0.0.1:${PORT_B}`),
    ])
    if (!a || !b) fail(`Server không lên: A=${a} B=${b}`)
    return `A=http://127.0.0.1:${PORT_A} B=http://127.0.0.1:${PORT_B}`
  })

  await step('5. Race thật (item-level + toa-level)', async () => {
    const result = await run(
      'node',
      [
        'scripts/run-wwhn199-reservation-race.mjs',
        `${EVIDENCE_DIR}/race-fixture.json`,
        `${EVIDENCE_DIR}/race-report.json`,
        `http://127.0.0.1:${PORT_A}`,
        `http://127.0.0.1:${PORT_B}`,
      ],
      {},
    )
    log(result.stdout)
    if (result.code !== 0) fail(`Race FAIL (exit ${result.code}): ${result.stderr}`)
    const report = JSON.parse(await readFile(resolve(ROOT, EVIDENCE_DIR, 'race-report.json'), 'utf8'))
    if (report.item_level.passed_rounds !== report.item_level.rounds) {
      fail('Có vòng item-level không đạt bất biến một-winner-một-conflict.')
    }
    if (report.item_level.overlapped_rounds !== report.item_level.rounds) {
      fail('Có vòng không chồng lấn thật — race không chứng minh được gì.')
    }
    if (report.request_level.passed !== true || report.request_level.overlapped !== true) {
      fail('Vòng toa-level không đạt hoặc không chồng lấn.')
    }
    return `item-level ${report.item_level.passed_rounds}/${report.item_level.rounds}, toa-level PASS`
  })

  await step('6. DB after-state + pg_indexes + duplicate probe', async () => {
    const result = await run(
      'php',
      [
        'scripts/verify-wwhn199-reservation-invariants.php',
        `${EVIDENCE_DIR}/race-fixture.json`,
        `${EVIDENCE_DIR}/db-invariants.json`,
      ],
      { env: dbEnv },
    )
    log(result.stdout)
    if (result.code !== 0) fail(`Invariant verifier FAIL: ${result.stderr}`)
    const invariants = JSON.parse(await readFile(resolve(ROOT, EVIDENCE_DIR, 'db-invariants.json'), 'utf8'))
    if (invariants.result !== 'PASS') fail(`db-invariants.json = ${invariants.result}`)
    if (invariants.checks.outbound_active_item_index_count !== 1) {
      fail(
        `outbound_item_matches phải có đúng 1 unique index ACTIVE-item, đang có ` +
          `${invariants.checks.outbound_active_item_index_count} (DEV199-P2-01).`,
      )
    }
    if (invariants.checks.duplicate_active_match_probe?.blocked !== true) {
      fail('Duplicate probe không bị DB chặn — backstop không tồn tại.')
    }
    return `PASS trên PostgreSQL ${invariants.server_version}`
  })
} catch {
  exitCode = 1
} finally {
  for (const child of servers) {
    try {
      child.kill()
    } catch {
      /* đã tắt */
    }
  }
  if (!KEEP_DATABASE) {
    log('\n(giữ nguyên DB candidate để verifier/QA soi lại; dùng --database khác nếu cần cô lập)')
  }
}

const summary = {
  schema_version: '1.0.0',
  task: 'WWHN-199',
  register_items: ['DEV199-P1-01R', 'DEV199-P1-02R', 'DEV199-P1-05R', 'DEV199-P2-01'],
  database: DATABASE,
  endpoints: { racer_a: `http://127.0.0.1:${PORT_A}`, racer_b: `http://127.0.0.1:${PORT_B}` },
  steps,
  result: failed || exitCode !== 0 ? 'FAIL' : 'PASS',
}

await writeFile(
  resolve(ROOT, EVIDENCE_DIR, 'evidence-run.json'),
  `${JSON.stringify(summary, null, 2)}\n`,
  'utf8',
)

log(`\n[${summary.result}] ${EVIDENCE_DIR}/evidence-run.json`)
process.exit(exitCode)
