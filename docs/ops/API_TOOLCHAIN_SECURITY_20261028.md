# API Toolchain Security And Compatibility

This change upgrades the actual registry dependencies, not the advisory threshold:
`@faker-js/faker` 10.6.0, `csv-parse` 7.0.3 and `node-forge` 1.4.2. Newman
6.2.2 and the existing Postman collection engines remain installed. No package
is renamed to hide an advisory, excluded from audit, downgraded, or patched to
report a false upstream version. package-lock.json carries official tarball
integrity hashes. The application database is unchanged. A fresh Composer audit
on 28 October also identified four newly indexed PhpSpreadsheet advisories;
that one runtime library is separately upgraded from 5.9.0 to 5.10.0, with no
other PHP package version changes.

## Explicit Preparation

Keep `.npmrc` ignore-scripts=true and `npm ci --ignore-scripts`. Run
`npm run toolchain:prepare` after installation and before direct Newman/Postman
commands. The normal build, api:postman, api:smoke and toolchain:test scripts do
this explicitly, as do existing evidence collectors. The CI install step adds
toolchain:test without lowering or removing any audit/test gate. This task does
not itself trigger full CI.

Preparation validates SHA256 of the exact locked Newman 6.2.2 and Postman SDK
4.4.0/5.3.1 files before changing three imports in generated node_modules. It
checks all planned files before writing, rejects unknown upstream source, refuses
external/shared node_modules, and is idempotent. No old library engine is copied.
`node scripts/prepare-api-toolchain.mjs --check` is read-only and fails if setup is
missing or altered. A new upstream SDK version requires review of its imports
and compatibility tests, not automatic acceptance of a new hash.

The local adapters only bridge renamed Faker method names and CSV's callable
export/legacy relax option to the patched official engines. They do not parse
CSV, evaluate fake templates, implement a Postman runtime, intercept global
module loading, or send network requests. Generated image URLs retain the
legacy category-oriented semantics through Faker 10's deprecated but still
available urlLoremFlickr API. Those URLs are not fetched by tests; the exact
Faker version is pinned and a future v11 upgrade needs separate review.

## Verification

Use a clean install, full online npm audit with all severities, `npm ls --all`,
toolchain:test and the normal build/test scripts. Tests exercise every installed
Postman dynamic generator, patched package inventory, malicious interpolation,
duplicate prototype CSV columns, quoted/numeric CSV casting, two local HTTP
iterations, JSON requests and sandbox assertions, negative assertion results,
OpenAPI conversion, invalid CLI input, source hash refusal and preparation
idempotence. All test HTTP requests target a newly opened loopback server only.
They do not call WMS, log in, create business fixtures, or run seed/migrations.

## Upstream References

- Faker advisory: https://github.com/advisories/GHSA-qxc2-j82w-r537
- CSV advisory: https://github.com/advisories/GHSA-8cw4-87c7-c6xx
- Forge advisory: https://github.com/advisories/GHSA-86w9-cpqp-85rv
- Legacy finance formatting source: https://github.com/faker-js/faker/blob/v5.5.3/lib/finance.js
- Modern image API: https://fakerjs.dev/api/image.html
- Spreadsheet reader advisory and patched release: https://github.com/advisories/GHSA-j2w7-387v-m2rq
- Spreadsheet XML scanner advisory: https://github.com/advisories/GHSA-3x94-m842-937c

Passing a current registry audit is not a claim of perfect security. Release
evidence must retain exact source/lock hashes, audit date/results, compatibility
tests and actual deployment identity. Production stays OFF; this does not
authorize BOX repack, stock writes, seed or database migrations.

The PHP dependency upgrade is built in a new private vendor volume, preserving
the live/rollback vendor volume. Deployment requires both full audits to be
clean, verifies exactly the one allowed PHP package delta, and compares existing
business rows/media/schema before reopening. Tests include Unicode XLSX
roundtrip, encoded-DOCTYPE rejection, bounded/unbounded ODS repeat guards, and
existing SKU import/report download and format regressions. No unsafe sample
file is loaded into a live service and no stock/seed mutation is used as proof.
