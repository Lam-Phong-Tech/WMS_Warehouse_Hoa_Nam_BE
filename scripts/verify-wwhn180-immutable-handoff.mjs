#!/usr/bin/env node
import crypto from 'node:crypto';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';

const [manifestPath, sourceRegisterPath, outputPath] = process.argv.slice(2);
if (!manifestPath || !sourceRegisterPath || !outputPath) {
  throw new Error('Usage: verify-wwhn180-immutable-handoff.mjs <manifest> <source-register> <output>');
}

const readJson = path => JSON.parse(fs.readFileSync(path, 'utf8'));
const sha256 = buffer => crypto.createHash('sha256').update(buffer).digest('hex');
const gitBlobSha = buffer => crypto
  .createHash('sha1')
  .update(Buffer.from(`blob ${buffer.length}\0`))
  .update(buffer)
  .digest('hex');

const manifest = readJson(manifestPath);
const sourceRegister = readJson(sourceRegisterPath);
const errors = [];
const observed = [];
const entries = Array.isArray(manifest.entries) ? manifest.entries : [];
const paths = entries.map(entry => entry.path);
const duplicates = paths.filter((path, index) => paths.indexOf(path) !== index);

if (manifest.jira_key !== 'WWHN-180') errors.push('Manifest jira_key must be WWHN-180');
if (manifest.document_version !== sourceRegister.version) {
  errors.push(`Document version mismatch: manifest=${manifest.document_version}, source-register=${sourceRegister.version}`);
}
if (sourceRegister.version !== '1.7.0') errors.push('Source register must be version 1.7.0');
if (manifest.branch !== 'm1' || sourceRegister.branch !== 'm1') errors.push('Branch authority must be m1');
if (duplicates.length) errors.push(`Duplicate manifest entries: ${[...new Set(duplicates)].join(', ')}`);

const requiredPaths = [
  '.github/workflows/wwhn-180-api-posting-contract.yml',
  'scripts/collect-wwhn180-canonical-evidence.sh',
  'scripts/verify-wwhn180-canonical-handoff.mjs',
  'scripts/verify-wwhn180-evidence.mjs',
  'scripts/verify-wwhn180-immutable-handoff.mjs',
  'docs/api-contract/WWHN-180-posting-openapi.json',
  'docs/api-contract/WWHN-180-posting-openapi.yaml',
  'docs/postman/WWHN-180 Canonical Contract.postman_collection.json',
  'docs/postman/WWHN-180 Canonical Contract.postman_environment.json',
  'docs/docs_task_jira/WWHN-180-source-register.json',
  'docs/docs_task_jira/WWHN-180.md',
  'docs/evidence/WWHN-180/BE_FE_PERMISSION_MAPPING.json',
  'docs/evidence/WWHN-180/permission-catalog.json',
  'docs/testing/WWHN-180-posting-test-cases.json',
];
for (const path of requiredPaths) {
  if (!paths.includes(path)) errors.push(`Missing required manifest entry: ${path}`);
}

for (const entry of entries) {
  if (!entry?.path || !entry?.sha256 || !entry?.git_blob_sha || !entry?.classification) {
    errors.push(`Incomplete manifest entry: ${JSON.stringify(entry)}`);
    continue;
  }
  if (!fs.existsSync(entry.path) || !fs.statSync(entry.path).isFile()) {
    errors.push(`Manifest path not found: ${entry.path}`);
    continue;
  }
  const content = fs.readFileSync(entry.path);
  const actualSha256 = sha256(content);
  const actualBlobSha = gitBlobSha(content);
  observed.push({
    path: entry.path,
    expected_sha256: entry.sha256,
    actual_sha256: actualSha256,
    expected_git_blob_sha: entry.git_blob_sha,
    actual_git_blob_sha: actualBlobSha,
    status: actualSha256 === entry.sha256 && actualBlobSha === entry.git_blob_sha ? 'PASS' : 'FAIL',
  });
  if (actualSha256 !== entry.sha256) errors.push(`SHA256 mismatch: ${entry.path}`);
  if (actualBlobSha !== entry.git_blob_sha) errors.push(`Git blob SHA mismatch: ${entry.path}`);
}

let currentParentSha = null;
try {
  currentParentSha = execFileSync('git', ['rev-parse', 'HEAD^'], { encoding: 'utf8' }).trim();
} catch {
  // Local validation may run before a commit exists. CI must have a resolvable parent.
}
if (process.env.GITHUB_ACTIONS === 'true') {
  if (!currentParentSha) errors.push('Unable to resolve release commit parent in CI');
  if (currentParentSha && manifest.release_parent_sha !== currentParentSha) {
    errors.push(`Release parent mismatch: manifest=${manifest.release_parent_sha}, actual=${currentParentSha}`);
  }
}

const output = {
  schema_version: '1.0.0',
  jira_key: 'WWHN-180',
  document_version: manifest.document_version,
  branch: manifest.branch,
  release_parent_sha: manifest.release_parent_sha,
  current_parent_sha: currentParentSha,
  entry_count: entries.length,
  checked_entry_count: observed.length,
  status: errors.length ? 'FAIL' : 'PASS',
  errors,
  entries: observed,
};
fs.writeFileSync(outputPath, `${JSON.stringify(output, null, 2)}\n`);
if (errors.length) {
  console.error(JSON.stringify(output, null, 2));
  process.exit(1);
}
console.log(`WWHN-180 immutable handoff PASS: ${observed.length}/${entries.length}`);
