# TC41 Field Sources And Legacy Limits

Contract: `contracts/p23/scan-session-read-v4.json`.
Opt in on both existing GETs with `X-Scan-Session-Contract: mini-app-p23-read-v4`.
Header-free/v3 is unchanged. Detail keeps the P08 envelope; consume `data.p23`.
Do not send `X-Read-Fields-Contract`: v4 preserves null with explicit fieldSources.

| Returned field | Persisted source and verification | Missing/redaction behavior |
| --- | --- | --- |
| sessionId | scan_batch.scan_batch_id; scan_batches.scan_batch_id; canonical precedence | Never use auth family UUID as batch number |
| batchNo | Selected batch.batch_no only when present | null / NOT_RECORDED; currently absent in both DEV sources |
| createdAt | Selected batch.created_at, history-timestamps-v1 | Not a session start or end |
| startedAt / endedAt / status | Selected batch.started_at / ended_at / status | null / NOT_RECORDED; not restored from events or POSTED |
| warehouseId | Batch.warehouse_id intersected with selected warehouse + tenant | Cross-scope batch hidden/denied |
| warehouse.code/name | Authorized warehouses.code/name by exact warehouse ID | CURRENT_DIRECTORY_NOT_HISTORICAL_SNAPSHOT, not a historical rename snapshot |
| actor.userId | Batch.created_by | Explicit BATCH_CREATOR_NOT_EVENT_ACTOR; name historical snapshot absent |
| scanners / event.actor.userId | Event.actor_id; verified successful SCAN audit entity_id + after_data.scan_batch_id; or verified scan-owner selected_by/scanned_by/matched_by | No batch creator/current viewer fallback; missing -> null; partial source marked |
| event.actor.displayName | Event.actor_display_name only + iam.user.view | Missing -> NOT_RECORDED; denied -> REDACTED_PERMISSION; no app_user lookup |
| eventId / activityId | scan_events.id or scan_record_events.scan_event_id; exact P08 canonical dedup/order | IDs unchanged across pages/representations |
| occurredAt / time | Event.created_at, typed source timezone convention | RFC3339 UTC; preserve actual source precision; never device time |
| status / acceptanceStatus | Exact event.result_status | RESOLVED is not ACCEPTED; OTHER is not REJECTED |
| displayCode | Event.raw_code + inventory.trace + safe grammar/type gate | Sensitive/untyped format hidden even with permission; rawCode always null |
| codeType | Event.code_type allowlist QR/BARCODE/RFID/NFC | Null when absent; scan_source and current physical-code type are not historical carrier evidence |
| quantity | Event.quantity or verified component/inbound/outbound scan row.quantity | Positive integer or null, never default1/current BOX amount |
| summary.verifiedValidCodes | Distinct persisted code identity among ACCEPTED or owner-verified VALID events | Unverified relation/identity/partial -> null with reason, not zero |
| summary.explicitRejectedAttempts | Exact REJECTED event count over full retained session | Missing source -> null; not an aggregate of OTHER/errors |
| summary.duplicateAttempts | Exact DUPLICATE event count over full retained session | Does not increase unique valid-code total |
| summary.issuedComponentQuantity | component_issue_items.scan_event_id -> line/document; POSTED document + posted_at/items, quantities matching negative stock_movements source/doc/line/SKU/warehouse | Null until complete posting evidence. Per-line ledger checked, only this batch's item evidence attributed |
| primaryDocument / relatedDocuments | Exact event-owner row FK -> line/document, batch reference + resolved target + warehouse/tenant + owner permissions | source_id alone insufficient; no fallback URL; explicit denied/unavailable/not-established status |
| link.number/status/version | Verified current stock/component/warranty owner columns | Marked CURRENT_OWNER_DOCUMENT, not historical status snapshot |
| link.readTarget | Allowlisted relative GET path + exact warehouse_id; owner read rechecks ACL | No remote URL/token/storage URL, no mutation |
| latestEvent | Highest full-source normalized event time then stable activity ID at asOf | Not last row of visible page; null when partial |

## What Cannot Be Recovered

Observed DEV schemas do not contain batch_no, started_at, ended_at or batch status.
No read adapter can reconstruct these facts from UUID, created_at or latest event.
Legacy scan_record_events lacks actor, carrier type, quantity and historical name.
Current scan actor names cannot replace historical snapshots. Old generic source_id
without matching owner event/line records cannot establish a navigation relation.
No approved whole-batch version or retention rule exists. Missing posted item/ledger
evidence cannot be replaced by session completion or a document's display label.

Complete optional-metadata JSON is an explicitly labeled persisted fixture in an
isolated disposable DB, NOT a fully populated DEV historical batch. Existing DEV
and legacy examples must keep their genuine nulls; do not seed/repair for screenshots.

## App Coordination

New `p23V4Projection.ts` and opt-in `createLinkedHistoryRead(..., {p23Contract:
'mini-app-p23-read-v4'})` coexist with v3. Use summary metric objects, not old
counts.accepted/issuedQuantity. Display absent batchNo as an absent business number;
technical sessionId can be separately labeled, never masquerade as batchNo.
Keep `rawCode=null`, render only authorized displayCode, and keep creator/scanner
separate. Use exact verified readTarget for related owner reads/navigation. Do not
patch GET responses with current master data or infer posting from end/status.

APK/Web publication and visual acceptance are separate owner work. The App repo
has unrelated local changes; this delivery must not commit/publish them wholesale.
TC41 remains OPEN until both builds read the same real batch and reference states.
