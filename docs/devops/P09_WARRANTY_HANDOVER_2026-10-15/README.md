# P09 COMPLETED -> RETURNED handover

Date: 2026-10-15 Asia/Saigon
Contract: `p09-warranty-handover-v1`
Owner PATCH: `PATCH /api/v1/mini-app/warranty-cases/{case_id}`

## Mapping

The existing P09 owner protocol remains authoritative:

- `If-Match`: current warranty case version. A stale value returns `412 STALE_VERSION`.
- `Idempotency-Key`: stable original P09 operation key.
- `X-Request-ID`: stable original operation UUID; the receipt and handover row keep it.
- Warehouse scope: persisted `warranty_case_warehouse_owners`, active warehouse and
  the confirmed session warehouse must agree.
- Case transition: only `COMPLETED -> RETURNED`.

The RETURNED body adds validated fields without changing the route:

```json
{
  "status": "RETURNED",
  "receiver": "Nguyen Van A",
  "handover_date": "2026-10-15",
  "handover_checked": true,
  "note": "Khach da kiem tra va ky nhan."
}
```

`receiver`, `handover_date`, `handover_checked` and `note` are persisted as
separate handover fields. They are never concatenated into `note`. The server's
`warranty_case.returned_at` remains a separate server transition timestamp.

## Schema

Migration: `2026_10_15_060000_create_warranty_handovers_table.php`

Table: `warranty_handovers`

- `warranty_case_id` primary key and FK to the canonical warranty case
- `receiver_ciphertext` and `note_ciphertext`, encrypted with dedicated AAD
- `handover_date` DATE from the user, never derived from `returned_at`
- `handover_checked` BOOLEAN, required true
- `recorded_by` actor UUID
- `request_id` original `X-Request-ID`
- `created_at`, `updated_at` server persistence timestamps

The current case read exposes `data.handover`; receiver is masked and note is
redacted without `warranty.pii.view`.

## Guards

Before the transition, P09 verifies authentication, `warranty.manage`, owner
warehouse, active current context, case existence, version, transition matrix,
and linked component issue documents. Every linked component issue document must
be in the owner warehouse and `POSTED`; no linked document is allowed.

## Isolated test evidence

`tests/Feature/P09WarrantyHandoverContractTest.php` passed:

- 2 tests
- 39 assertions
- 412 stale version leaves the case at COMPLETED
- Same key and same request ID replay the successful receipt
- Retry creates no second handover row or RETURN event
- DRAFT component issue blocks return; POSTED component issue allows it

## DEV/TEST release status

The current live DEV/TEST serving release was not changed in this task. No live
migration, seed, repair, restore, or write smoke was run. Authenticated DEV
smoke must be rerun after the candidate SHA is deployed and migration
`2026_10_15_060000_create_warranty_handovers_table.php` is applied. Production
activation remains OFF.
