# WWHN-309 — Sổ ghi run CI tại exact SHA (DEV309-P1-03)

| Trường | Giá trị |
| --- | --- |
| Task | WWHN-309 — [BE] Hoàn thiện tài liệu kiến trúc/API/vận hành |
| Repository | `Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE` |
| Nhánh | `m1` |
| Workflow | `.github/workflows/wwhn-309-backend-handover-evidence.yml` |
| Candidate SHA | xem `docs/evidence/WWHN-309/CANDIDATE_MANIFEST.json` → `candidate_sha` |
| Cập nhật | 2026-08-19 |

## 1. Vì sao cần tài liệu này

Vòng review trước ghi: *"Workflow có, accepted run chưa pin"*. Có workflow không đồng
nghĩa có bằng chứng — bằng chứng là **một run cụ thể, tại một SHA cụ thể, có log thô
và checksum**. Trước khi có run đó, mọi phát biểu "CI PASS" đều là lời khai.

Tài liệu này là chỗ duy nhất được ghi kết quả run, và nó **fail-closed theo mặc
định**: mọi ô chưa điền phải giữ nguyên chữ `PENDING CI EXECUTION`.

## 2. Cách dispatch đúng cách

```bash
gh workflow run wwhn-309-backend-handover-evidence.yml \
  --ref m1 \
  -f source_sha=<PIN_COMMIT>
```

Ba điều bắt buộc, vì đây chính là các lỗi đã bị bắt ở vòng trước:

1. **Truyền `source_sha` tường minh (PIN_COMMIT — commit chứa CANDIDATE_MANIFEST.json).** Chạy trên floating head của `m1` sinh evidence
   cho một SHA khác với SHA đang review — evidence đó không dùng để đóng task được.
2. **Chạy trên PostgreSQL.** Workflow đã khai service `postgres:16`; kết quả SQLite
   không phải căn cứ nghiệm thu (bất biến trong `docs/docs_task_jira/WWHN-309.md` §1).
3. **Pin đủ 5 trường** ở bảng §3: run ID, run URL, resolved SHA, kết luận từng step,
   và checksum của artifact.

## 3. Bảng pin kết quả (QA1 điền, không phải DEV tự điền)

| Trường | Giá trị |
| --- | --- |
| Run ID | `PENDING CI EXECUTION` |
| Run URL | `PENDING CI EXECUTION` |
| Resolved source SHA (step *Pin immutable source identity*) | `PENDING CI EXECUTION` |
| Kết luận `Handover regression tests` | `PENDING CI EXECUTION` |
| Kết luận `Permission catalog evidence on PostgreSQL` | `PENDING CI EXECUTION` |
| Kết luận `Scheduled operations evidence` | `PENDING CI EXECUTION` |
| Kết luận `OpenAPI contract evidence` | `PENDING CI EXECUTION` |
| Kết luận `Handover documentation contract gate` | `PENDING CI EXECUTION` |
| Kết luận `Candidate pin integrity` | `PENDING CI EXECUTION` |
| Kết luận `Health contract evidence` | `PENDING CI EXECUTION` |
| Tên artifact | `wwhn-309-backend-handover-<resolved_sha>` |
| SHA-256 của `SHA256SUMS` trong artifact | `PENDING CI EXECUTION` |
| Người pin | `PENDING` |
| Ngày pin | `PENDING` |

**Quy tắc chấp nhận:** run chỉ được coi là accepted khi step `Candidate pin integrity`
PASS tại `Resolved source SHA` đó. Cổng này chứng minh hai điều cùng lúc: manifest khai
đúng `candidate_sha`, và **không commit nào giữa candidate và SHA đang chạy chạm vào tập
artifact bàn giao**. Vì vậy không cần (và không nên) đòi `Resolved source SHA` bằng đúng
`candidate_sha` — file manifest nằm ở commit kế tiếp candidate là điều tất yếu.

## 4. Khi chưa dispatch được CI (môi trường không có runner/mạng)

Chạy bộ gate tương đương ngay tại chỗ và nộp log thô — đây là **evidence dự phòng**,
không thay thế được run CI, và phải ghi rõ là local:

```bash
bash scripts/collect-wwhn309-handover-evidence.sh
```

Script sinh `build/evidence/wwhn-309/local/<sha>/` gồm log thô từng gate, `manifest.txt`
(SHA + môi trường + exit code) và `SHA256SUMS`. Trong tài liệu/Jira phải ghi nguồn là
`LOCAL EVIDENCE` kèm SHA, không được ghi là run CI.

## 5. Điều kiện đóng DEV309-P1-03

- [ ] Có run tại đúng `candidate_sha`, không phải floating head.
- [ ] Toàn bộ step trong bảng §3 điền kết luận thật, không còn `PENDING CI EXECUTION`.
- [ ] Artifact `wwhn-309-backend-handover-<sha>` tải về được và `SHA256SUMS` khớp.
- [ ] QA1 (WWHN-312) đã đối chiếu run này với `QA_RETEST_PLAN.md`.
