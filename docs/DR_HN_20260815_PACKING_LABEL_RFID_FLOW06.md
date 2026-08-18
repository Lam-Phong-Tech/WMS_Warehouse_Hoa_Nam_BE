# DR-HN-20260815 — Packing Label / RFID / Flow 06 Alignment

- Decision date: 2026-08-15
- Sources: HN_WMS_Wireframe_MVP_ALIGNED_v3.20_v1.12_20260814.html (DEC-HN-20260814-01),
  SRS/FRD v1.12 REQ-SRS-015 / DEC-HN-20260811-02 / DEC-RFID-FALLBACK-001,
  Techstack Guide §Flow 06 Baseline (2026-08-15).
- Status: ADOPTED — runtime overrides registered by `Flow06AlignmentServiceProvider`.

## Packing Label

- Each outbound document/lot has exactly 01 Packing Label; there is no per-Item label.
- The label is created/ensured only after full scan/match, while the outbound is
  pre-completion (DRAFT/SCANNING). Ready freezes one immutable snapshot per label.
- Post Issue is gated on: full match + sufficient stock + current label PRINTED +
  snapshot current + explicit label-applied confirmation. Only successful Post Issue
  decrements stock; print failure blocks completion with stock/movement unchanged.
- Reprint after completion reuses the same snapshot and is stock-neutral.

## RFID

- RFID is optional for PRODUCT outbound: mapping is stock-neutral and is never a
  Post Issue gate; Barcode/QR remains a valid identifier (DEC-RFID-FALLBACK-001).

## Flow 06 Reports

- Report groups: Inventory Balance, XNT, Document History, Movement/Trace,
  Import/Match, Packing Label/Print, Warranty/Component, Outbound by recipient/Agency.
- `report.view` gates reads; `report.export` gates XLSX/PDF; warranty PII requires
  `warranty.pii.export`. Label log aggregates one label row per outbound document.
