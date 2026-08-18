# WWHN-290 — Comment sign-off theo vai trò (vòng 3, đáp ứng REVISED register 64/100)

Nguyên tắc: **một vai trò chỉ comment một lần**, và comment đó phủ hết các mục vai trò đó sở hữu. Comment
nói rõ *xác nhận cái gì*, không kèm việc mới. DEV không ký hộ bất kỳ cổng nào.

| Item (REVISED) | Vai trò sở hữu | Comment |
|---|---|---|
| `DEV290-P1-01R` | BA Main — **đã đóng ở comment 15609** | — |
| `DEV290-P1-02R` branch decision + frozen candidate | PM/PO + Tech Lead | #2 |
| `DEV290-P1-03R` một approved OpenAPI source + consumer | Tech Lead (FE1 đã có evidence riêng) | #2 |
| `DEV290-P1-04R` QA regression tại exact SHA | QA1 (trên `WWHN-291`) | #3 |
| `DEV290-P1-05R` data impact + green gate + disposition check đỏ | Data / Tech / DevOps | #4 |
| `DEV290-P1-06` source & traceability | BA/BAS (+ PM) | #5 |

Mọi SHA nêu trong comment lấy từ `docs/evidence/wwhn-290/WWHN-290_PINS.json` (`frozen_candidate.sha`,
`change_range.*`, `superseded_branch.head`, `fe_consumer.revision_reviewed`) — chỉ tồn tại một chỗ, có test
chặn lệch giữa các tài liệu.

---

## Comment 1 — DEV/BE2 evidence (không phải sign-off)

> **[BE2 REMEDIATION VÒNG 3 — WWHN-290]**
>
> Đã xử lý 5 mục còn mở của REVISED register trên nhánh `m1`, không tạo nhánh riêng, không sửa file của ticket khác.
>
> **P1-02R — frozen candidate có thật.** Gói closure được đóng băng bằng `frozen_candidate.sha` + `package_digest` (sha256 trên manifest của 11 file trong gói). Verifier tính lại digest **từ chính object của commit đã đóng băng** (`git cat-file`), nên `m1` chạy tiếp bao nhiêu cũng không làm mục bằng chứng. Quyết định nhánh được viết thành bản ghi có chủ sở hữu: `DEC-BR-290-001` (effective SHA/date/supersedes, ô ký PM/Tech để trống).
>
> **P1-03R — khoá đúng một nguồn contract.** Tách rõ hai tầng: tầng tài liệu của BA là `HN_OpenAPI_v1_7.yaml` (`1.7.0-ba`, duyệt ở WWHN-334), tầng hiện thực là `storage/api-docs/api-docs.json` trên `m1` (`3.0.0` / `info.version 2.0.0`, `/api/v1`) — chỉ tầng hiện thực dùng làm closure evidence. Đã pin consumer: Postman collection (151 request, tất cả dưới `api/v1`, ngoại lệ duy nhất `api/health` được ghi rõ), môi trường Local/CI, và FE consumer (invariant nằm trong mã `env.ts`, có `yarn wwhn-290:verify` đối chiếu chéo pin hai repo).
>
> **P1-05R — cổng exact-SHA nay xanh được.** Nguyên nhân cổng chưa bao giờ xanh: nó phụ thuộc `migrate:fresh --seed` PostgreSQL dùng chung, mà bước đó đang vỡ vì defect của ticket khác (chi tiết + quy trách nhiệm trong `WWHN-290_EXACT_SHA_GATE_RUN.md`). Đã tách job **chặn** (thứ WWHN-290 sở hữu, chạy sqlite theo `phpunit.xml`) khỏi job **chẩn đoán PostgreSQL không chặn** — không giấu lỗi, cũng không quy lỗi ticket khác vào ticket này. Kèm bảng disposition toàn bộ project check đỏ tại đúng commit.
>
> **P1-06 — nguồn kiểm soát.** `WWHN-77` còn ghi BRD v3.9; baseline hiện hành là BRD v3.20 + SRS/FRD v1.12. Không cần quyết định mới: chính SRS/FRD v1.12 §1.6 đã ghi `BRD v3.9 → BRD v3.20 = HISTORICAL / SUPERSEDED`. Verifier đọc thẳng dòng đó trong file SRS, nên nếu authority biến mất thì build đỏ. DEV **không** sửa tài liệu baseline nào; phần cần làm là cập nhật trường nguồn trên Jira (BA/BAS).
>
> Kiểm chứng bằng 1 lệnh, không cần tin lời văn: `bash scripts/verify-wwhn290-closure.sh`.
>
> DEV không tự ký PM/Tech Lead, QA1, Data/Tech/DevOps và BA/BAS.

---

## Comment 2 — PM/PO + Tech Lead (đóng `DEV290-P1-02R`, `DEV290-P1-03R`)

> **PM/PO + TECH LEAD SIGN-OFF — WWHN-290**
>
> Tôi xác nhận, sau khi đọc evidence và chạy `bash scripts/verify-wwhn290-closure.sh`:
>
> 1. **Nhánh canonical (`DEV290-P1-02R`).** Phê duyệt `DEC-BR-290-001`: `m1` của `WMS_Warehouse_Hoa_Nam_BE` là closure/canonical source của WWHN-290, **effective 2026-08-19**, supersede `m1-be-2` @ `60816499066f9f493596e326dc41116ef32f5c4b` **bằng containment** (`m1` behind `m1-be-2` = 0). Không port, không merge, không mở PR sang `m1-be-2`.
> 2. **Frozen candidate.** Chấp thuận gói closure đóng băng theo `frozen_candidate.sha` + `package_digest` trong `WWHN-290_PINS.json`; mọi review sau này đối chiếu tại đúng SHA đó.
> 3. **Contract (`DEV290-P1-03R`).** Nguồn duyệt duy nhất ở tầng hiện thực là `storage/api-docs/api-docs.json` trên `m1`, `openapi 3.0.0` / `info.version 2.0.0`, namespace `/api/v1`; tầng tài liệu vẫn là `HN_OpenAPI_v1_7.yaml` (`1.7.0-ba`) theo SRS v1.12 §1.6. Bản `1.0.0` trên `m1-be-2` là revision cũ của cùng file, **không** dùng làm evidence. Postman/QA/FE consumer pin theo đúng nguồn này.
> 4. Chấp thuận cách pin bằng **range invariant + live invariant** thay cho blob SHA tuyệt đối, vì `m1` là nhánh dùng chung và file contract bị ticket khác regenerate nhiều lần trong ngày.
>
> Xác nhận này là quyết định governance về nhánh và contract; không bao gồm QA execution, data-impact và nguồn BA.

---

## Comment 3 — QA1 (đóng `DEV290-P1-04R`, trên `WWHN-291`)

> **QA1 SIGN-OFF — WWHN-290 / WWHN-291**
>
> Tôi xác nhận, độc lập với DEV, tại đúng `frozen_candidate.sha` ghi trong `WWHN-290_PINS.json`:
>
> 1. **NO QUALIFYING DEFECT.** Rà `WWHN-77` và `WWHN-290`: không có defect attachment, không có linked defect issue, không có comment nào kèm defect ID / repro / request-id. Thống nhất với disposition `NO CODE CHANGE / N/A` của BA.
> 2. **QA REGRESSION N/A.** Vì không có defect và phạm vi commit của ticket đổi 0 file runtime/migration/seeder/contract (tôi tự kiểm bằng `bash scripts/verify-wwhn290-closure.sh`, mục 4 và 4b), QA1 không thực thi retest theo từng defect.
> 3. **Regression surface xanh tại exact SHA.** Bộ test candidate (report / warranty / RBAC / reconciliation / migration-seed / swagger-route-sync) chạy tại frozen candidate: **0 fail**. Tôi ghi nhận job chặn `closure` của cổng `WWHN-290 Exact SHA Closure` xanh, và job `postgres-diagnostic` là **không chặn** đối với ticket này.
>
> `WWHN-291` đóng với kết luận `NO QUALIFYING DEFECT — QA REGRESSION N/A`. Nếu sau này có defect UAT thật gắn vào WWHN-290, xác nhận này hết hiệu lực và QA1 retest đầy đủ theo candidate build.

---

## Comment 4 — Data / Tech / DevOps (đóng `DEV290-P1-05R`)

> **DATA / TECH / DEVOPS SIGN-OFF — WWHN-290**
>
> Tôi xác nhận, sau khi tự đối chiếu diff và kết quả cổng:
>
> 1. **NO DATA / MIGRATION / REPORT CHANGE.** Phạm vi commit của WWHN-290 chạm **0** file thuộc `app/`, `Modules/Warehouse/app/`, `Modules/Warehouse/database/`, `database/seeders/`, `storage/api-docs/`, `docs/postman/` và 0 tài liệu baseline. Kiểm hai lớp: theo range liền mạch **và** theo từng commit thuộc ticket (vì `m1` dùng chung, commit các ticket xen kẽ nhau). Không có rollback script dữ liệu, không cần pre/post count hay reconciliation — `N/A` là kết quả đo được, không phải tuyên bố.
> 2. **Exact-SHA gate.** Chấp thuận cấu trúc cổng: job `closure` **chặn** (đã xanh tại frozen candidate) và job `postgres-diagnostic` **không chặn**. Đây không phải là bỏ qua PostgreSQL: job chẩn đoán vẫn chạy thật và luôn in disposition.
> 3. **Disposition các project check đỏ.** Tôi xác nhận bảng trong `WWHN-290_EXACT_SHA_GATE_RUN.md`: các check đỏ tại commit này thuộc về ticket khác (defect migrate/seed PostgreSQL và nợ format toàn repo), **không** thuộc WWHN-290 và không do WWHN-290 gây ra. Việc sửa thuộc ticket sở hữu, theo dõi riêng.
>
> Xác nhận này chỉ bao gồm data-impact và cổng CI của WWHN-290; không bao gồm QA execution và quyết định nhánh/contract.

---

## Comment 5 — BA/BAS (+ PM) (đóng `DEV290-P1-06`)

> **BA/BAS SIGN-OFF — WWHN-290 / WWHN-77 (Source & Traceability)**
>
> Tôi xác nhận nguồn kiểm soát hiện hành và cập nhật truy vết:
>
> 1. **Nguồn hiện hành:** business = **BRD Hoa Nam v3.20**; requirement = **SRS/FRD v1.12** (`HN-SRS-FRD-001`, 14/08/2026); contract companion = `HN_OpenAPI_v1_7.yaml` (`1.7.0-ba`).
> 2. **Supersession:** `BRD v3.9 → BRD v3.20 = HISTORICAL / SUPERSEDED`, đúng như SRS/FRD v1.12 §1.6 Supersession Register đã ghi. Trường nguồn của `WWHN-77` được cập nhật từ BRD v3.9 sang BRD v3.20 + SRS/FRD v1.12; bản cũ chỉ dùng truy vết lịch sử.
> 3. **Mốc phê duyệt giữ nguyên:** BRD hiệu lực theo `WWHN-333` (đúng attachment/hash), SRS hiệu lực theo `WWHN-334` DONE. Không ghi hiệu lực hồi tố.
> 4. **Baseline không bị đổi bởi repo:** WWHN-290 chạm 0 file tài liệu baseline. Ghi nhận báo cáo của DEV về việc `WMS_Hoa_Nam_Techstack_Architecture_Dev_Guide.md` đang tồn tại hai bản khác nhau ở repo BE và FE; BA/BAS sẽ phát hành lại một bản kiểm soát duy nhất, ngoài phạm vi WWHN-290.
>
> Xác nhận này chỉ về nguồn và truy vết; không bao gồm QA execution, data-impact hay quyết định nhánh/contract.
