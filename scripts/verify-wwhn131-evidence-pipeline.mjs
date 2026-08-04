#!/usr/bin/env node

import fs from 'node:fs';
import path from 'node:path';

const outputPath = path.resolve(process.argv[2] ?? 'build/evidence/wwhn-131/evidence-pipeline-verification.json');
const root = process.cwd();

const files = {
  workflow: '.github/workflows/wwhn-131-stocktake-contract.yml',
  collector: 'scripts/collect-wwhn131-evidence.sh',
  redactor: 'scripts/redact-wwhn131-evidence.mjs',
  sourceRegister: 'docs/docs_task_jira/WWHN-131-source-register.json',
  taskDocument: 'docs/docs_task_jira/WWHN-131.md',
  requestedLog: 'docs/docs_task_jira/WWHN-130.md',
  decisionRecord: 'docs/decision-records/DEC-STK-001.md',
};

const checks = [];
function add(id, pass, detail) {
  checks.push({ id, status: pass ? 'PASS' : 'FAIL', detail });
}
function read(relative) {
  return fs.readFileSync(path.join(root, relative), 'utf8');
}

for (const [name, relative] of Object.entries(files)) {
  add(`FILE_${name.toUpperCase()}`, fs.existsSync(path.join(root, relative)), relative);
}

const workflow = read(files.workflow);
const collector = read(files.collector);
const redactor = read(files.redactor);
const sourceRegister = JSON.parse(read(files.sourceRegister));
const taskDocument = read(files.taskDocument);
const requestedLog = read(files.requestedLog);
const decisionRecord = read(files.decisionRecord);

const forbiddenLiterals = ['Cntt@1234', 'ci-jwt-secret-wwhn131'];
for (const literal of forbiddenLiterals) {
  add(
    `NO_FORBIDDEN_LITERAL_${literal.replace(/[^A-Za-z0-9]/g, '_')}`,
    !workflow.includes(literal) && !collector.includes(literal),
    `workflow/collector must not contain ${literal}`
  );
}

add('WORKFLOW_EPHEMERAL_PASSWORD', workflow.includes('openssl rand -hex 24') && workflow.includes('WWHN131_PASSWORD'), 'ephemeral password generation');
add('WORKFLOW_EPHEMERAL_JWT', workflow.includes('openssl rand -hex 32') && workflow.includes('JWT_SECRET'), 'ephemeral JWT generation');
add('WORKFLOW_MASKS_SECRETS', workflow.includes('::add-mask::'), 'GitHub Actions masking');
add('WORKFLOW_FAILURE_DIAGNOSTICS', workflow.includes('stocktake-diagnostics-${{ github.sha }}') && workflow.includes('if: failure()'), 'failure artifact');
add('WORKFLOW_REDACTION_GATE', workflow.includes('redact-wwhn131-evidence.mjs') && workflow.includes('credential-hygiene.json'), 'redaction and validation');
add('COLLECTOR_RUNTIME_PASSWORD', collector.includes('WWHN131_PASSWORD') && collector.includes('--env-var "password=$WWHN131_PASSWORD"'), 'Newman runtime credential');
add('COLLECTOR_REDACTS_BEFORE_HASH', collector.indexOf('node scripts/redact-wwhn131-evidence.mjs') < collector.indexOf("find . -type f ! -name 'SHA256SUMS'"), 'redaction occurs before manifest generation');
add('COLLECTOR_FAILURE_REDACTION_TRAP', collector.includes('trap finish EXIT') && collector.includes('if [[ $status -ne 0 ]]') && collector.includes('redact_diagnostics || true'), 'failed collector output is redacted or discarded before diagnostics upload');
add('REDACTOR_NO_SECRET_DIGEST', redactor.includes('no credential value or digest is written'), 'credential report does not hash secrets');

add('SOURCE_REGISTER_VERSION', sourceRegister.version === '1.3.0', `version=${sourceRegister.version}`);
add('SOURCE_REGISTER_PROPOSED', sourceRegister.status === 'PROPOSED', `status=${sourceRegister.status}`);
add('SOURCE_REGISTER_CREDENTIAL_MODE', sourceRegister.ci?.credential_mode === 'EPHEMERAL_PER_WORKFLOW_RUN', sourceRegister.ci?.credential_mode ?? 'missing');
add('SOURCE_REGISTER_NO_FALSE_APPROVAL', sourceRegister.approval?.decision === 'PROPOSED' && sourceRegister.approval?.approved_at === null, 'no fabricated sign-off');

add('TASK_DOCUMENT_VERSION', taskDocument.includes('Document version | `1.3.0`'), 'WWHN-131 v1.3.0');
add('TASK_DOCUMENT_AUTHORITATIVE_KEY', taskDocument.includes('Authoritative BE task | `WWHN-131`'), 'authoritative Jira key');
add('REQUESTED_LOG_TRACEABILITY_GUARD', requestedLog.includes('không phải task BE1 của DEC-STK-001') && requestedLog.includes('WWHN-131'), 'WWHN-130 is not repurposed');

for (const heading of ['## Context', '## Options considered', '## Proposed decision', '## Consequences', '## Impacted tickets', '## Approval and effective status']) {
  add(`DECISION_${heading.replace(/[^A-Za-z0-9]/g, '_').toUpperCase()}`, decisionRecord.includes(heading), heading);
}
add('DECISION_STATUS_PROPOSED', decisionRecord.includes('Status | **PROPOSED**') && !decisionRecord.includes('Status | **APPROVED**'), 'decision is not self-approved');

const failed = checks.filter((check) => check.status === 'FAIL').length;
const report = {
  schema_version: '1.0.0',
  jira_key: 'WWHN-131',
  summary: {
    status: failed === 0 ? 'PASS' : 'FAIL',
    total: checks.length,
    passed: checks.length - failed,
    failed,
  },
  checks,
};

fs.mkdirSync(path.dirname(outputPath), { recursive: true });
fs.writeFileSync(outputPath, `${JSON.stringify(report, null, 2)}\n`);

if (failed > 0) {
  console.error(JSON.stringify(report, null, 2));
  process.exit(1);
}

console.log(`WWHN-131 evidence pipeline PASS: ${checks.length} checks.`);
