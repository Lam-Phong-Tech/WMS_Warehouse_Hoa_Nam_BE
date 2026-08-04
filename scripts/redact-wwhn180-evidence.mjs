#!/usr/bin/env node

import fs from 'node:fs';
import path from 'node:path';

const root = path.resolve(process.argv[2] ?? 'build/evidence/api-data');
const output = path.resolve(process.argv[3] ?? path.join(root, 'credential-hygiene.json'));

const runtimeSecrets = [
  process.env.CI_DEMO_PASSWORD,
  process.env.WWHN180_PASSWORD,
  process.env.WWHN180_NO_PERMISSION_PASSWORD,
  process.env.JWT_SECRET,
].filter((value, index, values) =>
  typeof value === 'string' && value.length > 0 && values.indexOf(value) === index
);

if (runtimeSecrets.length === 0) {
  throw new Error('No runtime credential was supplied to the WWHN-180 evidence redactor.');
}

const jwtPattern = /eyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}/g;
const bearerPattern = /Bearer\s+[A-Za-z0-9._~-]{20,}/gi;
const excluded = new Set([
  path.basename(output),
  'SHA256SUMS',
  'SHA256SUMS.sha256',
]);

const stats = {
  schema_version: '1.0.0',
  jira_key: 'WWHN-180',
  credential_mode: 'EPHEMERAL_PER_WORKFLOW_RUN',
  root: path.relative(process.cwd(), root) || '.',
  scanned_text_files: 0,
  skipped_binary_files: 0,
  redacted_runtime_secret_occurrences: 0,
  redacted_jwt_occurrences: 0,
  redacted_bearer_occurrences: 0,
  residual_runtime_secret_count: 0,
  residual_jwt_count: 0,
  status: 'PENDING',
  checks: [
    'runtime passwords are not committed and are generated per workflow run',
    'runtime passwords are replaced before artifact hashing and upload',
    'JWT access tokens are replaced before artifact hashing and upload',
    'no credential value or credential digest is written to this report',
  ],
};

function walk(directory) {
  return fs.readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
    const fullPath = path.join(directory, entry.name);
    return entry.isDirectory() ? walk(fullPath) : [fullPath];
  });
}

function isText(buffer) {
  if (buffer.includes(0)) return false;
  try {
    new TextDecoder('utf-8', { fatal: true }).decode(buffer);
    return true;
  } catch {
    return false;
  }
}

function countOccurrences(text, needle) {
  if (!needle) return 0;
  let count = 0;
  let offset = 0;
  while ((offset = text.indexOf(needle, offset)) !== -1) {
    count += 1;
    offset += needle.length;
  }
  return count;
}

fs.mkdirSync(root, { recursive: true });

for (const file of walk(root)) {
  if (excluded.has(path.basename(file))) continue;

  const buffer = fs.readFileSync(file);
  if (!isText(buffer)) {
    stats.skipped_binary_files += 1;
    continue;
  }

  stats.scanned_text_files += 1;
  let text = buffer.toString('utf8');

  for (const secret of runtimeSecrets) {
    const occurrences = countOccurrences(text, secret);
    if (occurrences > 0) {
      stats.redacted_runtime_secret_occurrences += occurrences;
      text = text.split(secret).join('[REDACTED_RUNTIME_CREDENTIAL]');
    }
  }

  const jwtMatches = text.match(jwtPattern) ?? [];
  if (jwtMatches.length > 0) {
    stats.redacted_jwt_occurrences += jwtMatches.length;
    text = text.replace(jwtPattern, '[REDACTED_JWT]');
  }

  const bearerMatches = text.match(bearerPattern) ?? [];
  if (bearerMatches.length > 0) {
    stats.redacted_bearer_occurrences += bearerMatches.length;
    text = text.replace(bearerPattern, 'Bearer [REDACTED_TOKEN]');
  }

  fs.writeFileSync(file, text);
}

for (const file of walk(root)) {
  if (excluded.has(path.basename(file))) continue;

  const buffer = fs.readFileSync(file);
  if (!isText(buffer)) continue;

  const text = buffer.toString('utf8');
  for (const secret of runtimeSecrets) {
    stats.residual_runtime_secret_count += countOccurrences(text, secret);
  }
  stats.residual_jwt_count += (text.match(jwtPattern) ?? []).length;
}

stats.status =
  stats.residual_runtime_secret_count === 0 && stats.residual_jwt_count === 0
    ? 'PASS'
    : 'FAIL';

fs.writeFileSync(output, `${JSON.stringify(stats, null, 2)}\n`);

if (stats.status !== 'PASS') {
  throw new Error(
    `Credential hygiene failed: ${stats.residual_runtime_secret_count} runtime secret(s), ` +
      `${stats.residual_jwt_count} JWT(s) remain.`
  );
}

console.log(
  `WWHN-180 credential hygiene PASS: ${stats.scanned_text_files} text files scanned, ` +
    `${stats.redacted_runtime_secret_occurrences} runtime credential occurrence(s) redacted, ` +
    `${stats.redacted_jwt_occurrences + stats.redacted_bearer_occurrences} token occurrence(s) redacted.`
);
