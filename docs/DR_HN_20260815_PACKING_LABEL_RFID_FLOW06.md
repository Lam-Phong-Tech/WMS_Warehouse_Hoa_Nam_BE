# DR-HN-20260815 — Packing Label / RFID / Flow 06 alignment

## Status

Implementation baseline captured from the project-owner instruction dated 2026-08-15. BAS/QA independent sign-off remains a release gate; this record does not fabricate that approval.

## Supersession

For branch `m1`, this decision supersedes older downstream wording that says `01 nhãn/item` or requires RFID before every PRODUCT Post Issue.

## Canonical outbound sequence

`Scan -> aggregate full outbound lot -> preview/create exactly 01 Packing Label -> print -> confirm label applied -> Post Issue / complete outbound`.

Rules:

1. Scan/match, label create/preview/print/reprint/apply, and RFID assignment are stock-neutral.
2. Inventory is decremented only by successful `Post Issue` / completion, inside the same atomic stock transaction.
3. Exactly one Packing Label snapshot is canonical for each outbound document/lot, independent of item/SKU count.
4. Packing Label contains recipient name, recipient address, and customer phone supplied for the shipment, plus aggregate item/SKU summary.
5. PRODUCT outbound still requires concrete item matching for every unit before Post Issue.
6. RFID is optional metadata for PRODUCT outbound. Missing RFID must not block Post Issue. COMPONENT issue remains a separate warranty-only flow.
7. No partial outbound. Every line must satisfy `scanned_qty = required_qty` before label creation and Post Issue.
8. Legacy item-level label snapshots are retained as immutable history only; no new current-baseline write may create per-item Packing Labels.

## Flow 06 report contract

- Label/Print report is document-level: `label_count = 1` per canonical outbound lot.
- Import/Match filters must be applied by BE, not only rendered by FE: status, keyword, batch, request, SKU and row/error status where supplied.
- Report read permission: `report.view`.
- Report export permission: `report.export`.
- XLSX and PDF are supported through the approved export endpoint using `format=xlsx|pdf`; export is audited and capped at 10,000 rows.
- Server pagination is supported with `page` and `per_page` (max 100) for aligned report reads.

## Traceability

Affected runtime areas:

- `AlignedOutboundFlowController`
- `AlignedOutboundStockService`
- `AlignedOutboundDocumentController`
- `AlignedImportMatchReportController`
- `AlignedLabelLogReportController`
- migration `2026_08_15_140000_align_packing_label_to_outbound_document.php`
- FE outbound detail/list and Flow 06 report clients

Release acceptance still requires exact-SHA CI, QA1 reconciliation/RBAC evidence, QA2 responsive/filter/export evidence, and BAS definition approval on Jira WWHN-67/68 children.
