# WWHN-128 R8 — Review and Approval Protocol

## Immutable review target

Every reviewer must quote the same values:

- revision `1.8-R8` and contract version `1.7.0`;
- exact BE commit and branch `m1`;
- exact FE commit and baseline blob;
- workflow run/attempt;
- artifact ID, name and `sha256:` digest;
- successful `SHA256SUMS` and `SHA256SUMS.sha256` verification;
- environment and DB engine.

A branch URL, local copy, Jira Markdown attachment, or BE1 statement alone is not an immutable review target.

## Role boundaries

- **BE1** publishes code, contract, automation and evidence. BE1 does not sign for FE1, QA2 or BA.
- **FE1 / WWHN-181** returns `PASS`, `FAIL` or `CONDITIONAL` for endpoint → payload → error → permission → UI mapping.
- **QA2 / WWHN-182** returns `PASS`, `FAIL` or `CONDITIONAL` after the QA2 matrix and raw API/DB/race/reconciliation evidence.
- **BA / WWHN-183** returns `GO`, `CONDITIONAL_GO` or `NO_GO` in G1 minutes.
- **WWHN-40** is derived from those dispositions and objective integrity/open-defect facts.

## Fail-closed rules

1. Missing exact SHA, artifact digest, reviewer account, date, or evidence URL keeps the gate closed.
2. Any P0 failure results in `NO_GO`.
3. `CONDITIONAL_GO` requires no P0 and each P1 condition has owner, due date and objective evidence.
4. Automation PASS is technical evidence only; it cannot be copied as QA2 or BA approval.
5. FE review uses the current binding `2b88d559.../e2e760c2...`; the prior `f6e137.../656b...` binding is invalidated.
6. A rerun on a different commit or digest requires signatures to be repeated or explicitly carried forward by each signer.
