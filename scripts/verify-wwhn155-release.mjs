import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';

const root = process.cwd();
const releaseDir = path.join(root, 'docs/api-contract/releases/WWHN-155/1.0.0');
const required = ['openapi.yaml', 'postman_collection.json', 'CHANGELOG.md', 'RELEASE_MANIFEST.json', 'REVIEW_AND_APPROVAL.md', 'SHA256SUMS'];
for (const file of required) {
  if (!fs.existsSync(path.join(releaseDir, file))) throw new Error(`Missing release artifact: ${file}`);
}

const manifest = JSON.parse(fs.readFileSync(path.join(releaseDir, 'RELEASE_MANIFEST.json'), 'utf8'));
if (manifest.version !== '1.0.0' || manifest.contract_id !== 'WWHN-156-health-v1') throw new Error('Release identity mismatch');
if (manifest.endpoint.path !== '/api/v1/health' || manifest.endpoint.legacy_expected_status !== 404) throw new Error('Endpoint baseline mismatch');

const postman = JSON.parse(fs.readFileSync(path.join(releaseDir, 'postman_collection.json'), 'utf8'));
const serializedPostman = JSON.stringify(postman);
if (!serializedPostman.includes('/api/v1/health') || !serializedPostman.includes('/api/health')) throw new Error('Postman contract paths incomplete');

const openapi = fs.readFileSync(path.join(releaseDir, 'openapi.yaml'), 'utf8');
if (!openapi.includes('  /api/v1/health:')) throw new Error('Canonical OpenAPI path missing');
if (openapi.includes('  /api/health:')) throw new Error('Legacy OpenAPI path must not be exposed');

const expected = new Map();
for (const line of fs.readFileSync(path.join(releaseDir, 'SHA256SUMS'), 'utf8').trim().split(/\r?\n/)) {
  const match = line.match(/^([a-f0-9]{64})  (.+)$/);
  if (!match) throw new Error(`Invalid SHA256SUMS line: ${line}`);
  expected.set(match[2], match[1]);
}
for (const [file, digest] of expected) {
  const actual = crypto.createHash('sha256').update(fs.readFileSync(path.join(releaseDir, file))).digest('hex');
  if (actual !== digest) throw new Error( SHA-256 mismatch: ${file}`);
}

const register = JSON.parse(fs.readFileSync(path.join(root, 'docs/docs_task_jira/WWHN-155-source-register.json'), 'utf8'));
if (register.supersession.jira_attachment_id !== '11304' || register.supersession.status !== 'SUPERSEDED') throw new Error('Jira supersession is not explicit');
if (register.risk_disposition.some((item) => !item.status)) throw new Error('Risk disposition is incomplete');

console.log(JSON.stringify({status:'PASS', jira:'WWHN-155', version:manifest.version, verified_files:[...expected.keys()]}, null, 2));
