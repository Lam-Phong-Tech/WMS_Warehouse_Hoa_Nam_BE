import fs from 'node:fs';
import path from 'node:path';

const [root] = process.argv.slice(2);
if (!root) {
  throw new Error('Usage: node redact-wwhn150-evidence.mjs <evidence-dir>');
}

const secretValues = [
  process.env.WWHN150_PASSWORD,
  process.env.WWHN150_NO_PERMISSION_PASSWORD,
  process.env.CI_DEMO_PASSWORD,
  process.env.JWT_SECRET,
].filter((value) => typeof value === 'string' && value.length > 0);

const textExtensions = new Set(['.json', '.log', '.txt', '.md', '.yaml', '.yml', '.diff']);
const jwtPattern = /\beyJ[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]{5,}\b/g;
const bearerPattern = /Bearer\s+eyJ[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]{5,}/g;

const files = [];
const walk = (directory) => {
  for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
    const fullPath = path.join(directory, entry.name);
    if (entry.isDirectory()) walk(fullPath);
    else files.push(fullPath);
  }
};
walk(root);

let replacements = 0;
for (const file of files) {
  if (!textExtensions.has(path.extname(file).toLowerCase())) continue;
  let content = fs.readFileSync(file, 'utf8');
  const original = content;
  for (const secret of secretValues) {
    content = content.split(secret).join('<redacted-secret>');
  }
  content = content
    .replace(bearerPattern, 'Bearer <redacted-jwt>')
    .replace(jwtPattern, '<redacted-jwt>');
  if (content !== original) {
    replacements += 1;
    fs.writeFileSync(file, content);
  }
}

let residualSecretCount = 0;
let residualJwtCount = 0;
for (const file of files) {
  if (!textExtensions.has(path.extname(file).toLowerCase())) continue;
  const content = fs.readFileSync(file, 'utf8');
  residualSecretCount += secretValues.filter((secret) => content.includes(secret)).length;
  residualJwtCount += (content.match(jwtPattern) ?? []).length;
}

const output = {
  schema_version: '1.0.0',
  generated_at_utc: new Date().toISOString(),
  files_scanned: files.length,
  files_redacted: replacements,
  residual_runtime_secret_count: residualSecretCount,
  residual_jwt_count: residualJwtCount,
  status: residualSecretCount === 0 && residualJwtCount === 0 ? 'PASS' : 'FAIL',
  summary: {
    checked: 2,
    passed: (residualSecretCount === 0 ? 1 : 0) + (residualJwtCount === 0 ? 1 : 0),
    failed: (residualSecretCount > 0 ? 1 : 0) + (residualJwtCount > 0 ? 1 : 0),
    status: residualSecretCount === 0 && residualJwtCount === 0 ? 'PASS' : 'FAIL',
  },
};
fs.writeFileSync(path.join(root, 'credential-hygiene.json'), `${JSON.stringify(output, null, 2)}\n`);
if (output.status !== 'PASS') {
  console.error(JSON.stringify(output, null, 2));
  process.exit(1);
}
console.log(`WWHN-150 credential hygiene PASS; redacted ${replacements} files.`);
