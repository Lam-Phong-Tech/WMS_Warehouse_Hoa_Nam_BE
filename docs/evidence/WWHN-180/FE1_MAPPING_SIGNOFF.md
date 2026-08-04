# WWHN-180 — FE1 API/Permission Mapping Sign-off

> Trạng thái: **PROPOSED / PENDING FE1 REVIEW**. BE1 đã đối chiếu source FE ở chế độ read-only; nội dung dưới đây không phải chữ ký FE1.

## 1. Exact source đã khóa

| Trường | Giá trị |
|---|---|
| BE repository | `Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE` |
| BE branch | `m1` |
| Exact BE SHA | `<build-metadata.json.commit_sha>` |
| CI run | `<build-metadata.json.workflow_run_url>` |
| Artifact | `api-data-evidence-<exact BE SHA>` |
| Artifact digest | `<SHA256SUMS.sha256>` |
| FE repository | `Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_FE` |
| FE branch | `m1` |
| Exact FE SHA observed | `5cd10ec46021cecd24949075cb4eeefaa1fcc9b2` |
| FE baseline | `docs/api-evidence/WMS_FE_CONTRACT_BASELINE.json` |
| FE baseline blob SHA | `86cc8f024b1f520ebb355eb086f453d0438d3de1` |

Không dùng branch name động hoặc artifact từ run FAIL.

## 2. Mapping cần FE1 xác nhận

| FE action | API runtime | BE middleware | FE guard observed | BE disposition | FE1 result |
|---|---|---|---|---|---|
| Scan classify | `POST /api/v1/scan/classify` | `scan.classify` | `scan.classify` | Exact | PENDING |
| Scan inbound | `POST /api/v1/inbound-documents/{id}/scan` | `stock_document.scan` | `scan.inbound` | Safe alias seeded; BE middleware canonical | PENDING |
| Scan outbound | `POST /api/v1/outbound-documents/{id}/scan` | `stock_document.scan` | `scan.outbound` | Safe alias seeded; BE middleware canonical | PENDING |
| Post receipt | `POST /api/v1/inbound-documents/{id}/post-receipt` | `stock_document.post` | `inbound.post` | Safe alias seeded; BE middleware canonical | PENDING |
| Post issue | `POST /api/v1/outbound-documents/{id}/post-issue` | `stock_document.post` | `outbound.post` | Safe alias seeded; BE middleware canonical | PENDING |
| Apply adjustment | `POST /api/v1/stocktake/sessions/{id}/apply-adjustment` | `stocktake.adjust` | `stocktake.adjust` | FE route alias added | PENDING |
| Warranty TEMP_ONLY | `POST /api/v1/warranty-cases/{id}/temp-only` | `warranty.temp_only` | `warranty.temp_only` | Exact | PENDING |
| Reconciliation | `GET /api/v1/reports/inventory-reconciliation` | `report.view` | `report.view` | Exact | PENDING |
| Report export | `GET /api/v1/reports/inventory-xnt/export-excel` | `report.view` | `report.export` | FE narrower action alias; manager only | PENDING |

Machine-readable source: `BE_FE_PERMISSION_MAPPING.json`.

## 3. Contract assertions

- Scan trả `stock_effect=NONE`, không tạo movement/balance/posting event.
- Receipt/issue bắt buộc `Idempotency-Key`.
- Missing/blank key: 428; invalid length: 422.
- Same key/same payload replay exact response; FE không tự cộng/trừ tồn lần hai.
- Same key/different payload: 409 `IDEMPOTENCY_ERROR`.
- Different key khi document đã post: 409 `STATE_CONFLICT`.
- Backend canonical middleware là enforcement cuối cùng.
- Compatibility alias chỉ điều khiển FE route/action visibility.
- `NO_PERMISSION` không nhận alias.

## 4. FE1 independent result

- Reviewer FE1: `<name/Jira user>`
- Reviewed at: `<ISO-8601 +07:00>`
- Exact BE SHA: `<sha>`
- Exact FE SHA: `5cd10ec46021cecd24949075cb4eeefaa1fcc9b2`
- CI run/artifact: `<url/name>`
- Manifest digest: `<SHA256SUMS.sha256>`
- Runtime environment/build: `<value>`
- Result: `PASS | FAIL | CONDITIONAL`
- Findings/blockers: `<NONE or IDs>`
- Jira evidence URL: `<url>`

Chỉ comment do FE1 thực hiện trên cùng exact SHA/digest mới được tính là independent sign-off.
