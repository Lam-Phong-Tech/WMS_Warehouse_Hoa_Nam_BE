#!/usr/bin/env node

import fs from 'node:fs';
import path from 'node:path';

const outputPath = path.resolve(
  process.argv[2] ?? 'build/evidence/wwhn-131/evidence-pipeline-verification.json'
);
const root = process.cwd();

const files = {
  workflow: '.github/workflows/wwhn-131-stocktake-contract.yml',
  collector: 'scripts/collect-wwhn131-evidence.sh',
  redactor: 'scripts/redact-wwhn131-evidence.mjs',
  sourceRegister: 'docs/docs_task_jira/WWHN-131-source-register.json',
  taskDocument: 'docs/docs_task_jira/WWHN-131.md',
  requestedLog: 'docs/docs_task_jira/WWHN-130.md',
  decisionRecord: 'docs/decision-records/DEC-STK-001.md',
  testCases: 'docs/testing/WWHN-131-stocktake-test-cases.json',
  reviewProtocol: 'docs/evidence/WWHN-131/INDEPENDENT_REVIEW_AND_DECISION_PROTOCOL.md',
};

const checks = [];
const add = (id, pass, detail) => {
  checks.push({ id, status: pass ? 'PASS' : 'FAIL', detail });
};
const absolute = (relative) => path.join(root, relative);
const read = (relative) => fs.readFileSync(absolute(relative), 'utf8');

for (const [name, relative] of Object.entries(files)) {
  add(`FILE_${name.toUpperCase()}`, fs.existsSync(absolute(relative)), relative);
}

const workflow = read(files.workflow);
const collector = read(files.collector);
const redactor = read(files.redactor);
const sourceRegister = JSON.parse(read(files.sourceRegister));
const taskDocument = read(files.taskDocument);
const requestedLog = read(files.requestedLog);
const decisionRecord = read(files.decisionRecord);
const testCases = JSON.parse(read(files.testCases));
const reviewProtocol = read(files.reviewProtocol);

for (const literal of ['Cntt@1234', 'ci-jwt-secret-wwhn131']) {
  add(
    `NO_FORBIDDEN_LITERAL_${literal.replace(/[^A-Za-z0-9]/g, '_')}`,
    !workflow.includes(literal) && !collector.includes(literal),
    `workflow/collector must not contain ${literal}`
  );
}

add(
  'WORKFLOW_VALID_BLOCK_INDENTATION',
  !workflow.includes('\n`Unnexpected credential mode') &&
    !workflow.includes('\n`Unexpected credential mode'),
  'No unindented JavaScript template literal may escape a YAML run block'
);
add(
  'WORKFLOW_EXACT_SHA_ARTIFACT',
  workflow.includes('stocktake-evidence-${{ github.sha }}') &&
    workflow.includes('build-metadata.json') &&
    workflow.includes('source-tree.sha256') &&
    workflow.includes('SHA256SUMS.sha256'),
  'Exact-SHA artifact and immutable manifests'
);
add(
  'WORKFLOW_STRUCTURED_VALIDATION',
  workflow.includes('jq -e --arg sha "$GITHUB_SHA"') &&
    workflow.includes('.commit_sha == $sha') &&
    workflow.includes('.summary.failed == 0'),
  'Exact SHA and structured PASS validation'
);
add(
  'WORKFLOW_EPHEMERAL_PASSWORD',
  workflow.includes('openssl rand -hex 24') && workflow.includes('WWHN131_PASSWORD'),
  'Per-run password generation'
);
add(
  'WORKFLOW_EPHEMERAL_JWT',
  workflow.includes('openssl rand -hex 32') && workflow.includes('JWT_SECRET'),
  'Per-run JWT generation'
);
add('WORKFLOW_MASKS_SECRETS', workflow.includes('::add-mask::'), 'GitHub Actions masking');
add(
  'WORKFLOW_FAILURE_DIAGNOSTICS',
  workflow.includes('stocktake-diagnostics-${{ github.sha }}') &&
    workflow.includes('if: failure()'),
  'Redacted failure diagnostics'
);
add(
  'COLLECTOR_RUNTIME_PASSWORD',
  collector.includes('WWHN131_PASSWORD') &&
    collector.includes('--env-var "password=$WWHN131_PASSWORD"'),
  'Newman runtime credential'
);
add(
  'COLLECTOR_REDACTS_BEFORE_HASH',
  collector.indexOf('node scripts/redact-wwhn131-evidence.mjs') <
    collector.indexOf("find . -type f ! -name 'SHA256SUMS'"),
  'Redaction occurs before checksum generation'
);
add(
  'COLLECTOR_FAILURE_REDACTION_TRAP',
  collector.includes('trap finish EXIT') &&
    collector.includes('redact_diagnostics || true'),
  'Failed diagnostics are redacted or discarded'
);
add(
  'REDACTOR_NO_SECRET_DIGEST',
  redactor.includes('no credential value or digest is written'),
  'Credential report does not hash secrets'
);

add('SOURCE_REGISTER_VERSION', sourceRegister.version === '1.3.0', `version=${sourceRegister.version}`);
add('SOURCE_REGISTER_PROPOSED', sourceRegister.status === 'PROPOSED', `status=${sourceRegister.status}`);
add(
  'SOURCE_REGISTER_BILLING_BLOCKER',
  sourceRegister.github_actions?.external_blocker?.status === 'BLOCKED' &&
    sourceRegister.github_actions?.external_blocker?.owner?.includes('administrator'),
  'External GitHub Actions billing/spending blocker is explicit'
);
add(
  'SOURCE_REGISTER_NO_FALSE_APPROVAL',
  sourceRegister.approval?.decision === 'PROPOSED' &&
    sourceRegister.approval?.approved_at === null &&
    sourceRegister.approval?.reviewers?.length === 0,
  'No fabricated approval'
);
add(
  'SOURCE_REGISTER_CORRECT_TASK',
  sourceRegister.authoritative_jira_key === 'WWHN-131' &&
    !JSON.stringify(sourceRegister.required_artifact_outputs).includes('WWHN-180'),
  'WWHN-131 is authoritative and WWHN-180 is not an artifact'
);

add(
  'TASK_DOCUMENT_VERSION',
  taskDocument.includes('Document version | `1.3.0`'),
  'WWHN-131 v1.3.0'
);
add(
  'TASK_DOCUMENT_ARCHITECTURE',
  taskDocument.includes('WMS_Hoa_Nam_Techstack_Architecture_Dev_Guide.md'),
  'Architecture baseline recorded'
);
add(
  'TASK_DOCUMENT_FINDINGS',
  ['STK-P1-01','STK-P1-02','STK-P1-03','STK-P1-04','STK-P1-05','STK-P1-06','STK-P2-01']
    .every((id) => taskDocument.includes(id)),
  'All re-review findings have a disposition'
);
add(
  'TASK_DOCUMENT_CLOSURE_MATRIX',
  taskDocument.includes('## 8. Final closure matrix') &&
    taskDocument.includes('artifact ID/name') &&
    taskDocument.includes('reviewer/date/result'),
  'P2 closure matrix is present'
);
add(
  'TASK_DOCUMENT_NO_FALSE_100',
  taskDocument.includes('formal decision and independent gates remain pending') &&
    !taskDocument.includes('**100% COMPLETE**'),
  'Truthful completion ceiling'
);
add(
  'REQUESTED_LOG_TRACEABILITY_GUARD',
  requestedLog.includes('không phải task BE1 của DEC-STK-001') &&
    requestedLog.includes('WWHN-131'),
  'WWHN-130 is not repurposed'
);

for (const heading of [
  '## Context',
  '## Options considered',
  '## Proposed decision',
  '## Consequences',
  '## Impacted tickets',
  '## Approval and effective status',
]) {
  add(
    `DECISION_${heading.replace(/[^A-Za-z0-9]/g, '_').toUpperCase()}`,
    decisionRecord.includes(heading),
    heading
  );
}
add(
  'DECISION_STATUS_PROPOSED',
  decisionRecord.includes('Status | **PROPOSED**') &&
    !decisionRecord.includes('Status | **APPROVED**'),
  'Decision is not self-approved'
);

const caseIds = new Set(testCases.cases.map((item) => item.id));
for (const id of Array.from({ length: 22 }, (_, index) => `STK-TC-${String(index + 1).padStart(3, '0')}`)) {
  add(`TEST_CASE_${id}`, caseIds.has(id), id);
}
for (const gate of ['race','retry','timeout-after-commit','target-db','security','error-hygiene']) {
  const matched = testCases.cases.find((item) => item.gate === gate);
  add(
    `INDEPENDENT_GATE_${gate.toUpperCase().replaceAll('-', '_')}`,
    matched?.execution === 'INDEPENDENT_RUNTIME_REQUIRED' && Boolean(matched.owner),
    `${gate} must remain reviewer-owned until executed`
  );
}
add(
  'REVIEW_PROTOCOL_TWO_WORKER',
  reviewProtocol.includes('two-worker race') &&
    reviewProtocol.includes('Do not replace race evidence with sequential requests'),
  'Concurrent review cannot be substituted by sequential requests'
);

const failed = checks.filter((check) => check.status === 'FAIL').length;
const report = {
  schema_version: '1.1.0',
  jira_key: 'WWHN-131',
  generated_at: new Date().toISOString(),
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
