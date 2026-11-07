# Inbound numbering and external reference implementation plan

**Goal:** Apply the user-approved daily PN-YYYYMMDD-NNNN allocator to web and app; label external references clearly and keep them optional and independent of receipt numbers.
**Architecture:** Reuse InboundDocumentNumber on both backend create paths. Keep legacy app name as title only; accept optional source_reference through P04 SAVE/RECORD and direct mini-app record. Preserve existing documents, explicit administrative document numbers and outbound behavior.
**Tech stack:** Laravel/PHPUnit, React/Vitest.
**Spec:** User request in this chat, 2026-11-07.

- [x] Add failing API tests for shared web/app number allocation, replay, missing references, explicit references and legacy name equal to receipt number.
- [x] Use the shared allocator in BaM1 and fallback canonical receipt creation; keep references separate from optional app titles.
- [x] Update inbound list/create/detail labels and translations; verify blank and explicit reference submissions.
- [x] Run focused backend and frontend tests, TypeScript and lint; review changes; push backend and frontend commits to dev.

Review focus: no renumbering of existing records; no stock mutations on create; respect reserved draft numbers; retain external references verbatim after trimming; allow missing/null/blank values; preserve idempotency and permission checks.

## Verification and decisions

- P04, scan-driven inbound and mini-app staging: 49 tests / 721 assertions passed.
- Additional internal receipt/reservation regression: observed duplicate in RED; shared allocator GREEN (1 test / 10 assertions).
- Canonical flow, idempotency and concurrency guards: 11 tests / 96 assertions passed.
- FE list/create/edit: 16 tests passed; TypeScript and changed-file ESLint passed.
- Legacy app name remains an optional title; clients must explicitly send source_reference to record an external document number. Historical rows are not rewritten.
- Source review includes allocator, controllers, StockDocumentService and active labels. Outbound numbering remains unchanged.
- Report detail label updated consistently; report detail test passed. One unrelated POSTED-edit interaction test fails identically against HEAD baseline; temporary baseline files removed.
