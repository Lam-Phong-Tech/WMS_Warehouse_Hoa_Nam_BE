# DISP-OUT-199 — Disposition rà soát unique index reservation (ACTIVE-item)

- Disposition ID: `DISP-OUT-199`
- Version: 1.0.0 — effective 2026-08-19
- Register item: **DEV199-P2-01** (P2) — *"Review hai equivalent ACTIVE-item unique indexes;
  consolidate nếu redundant và migration history cho phép"*; evidence yêu cầu:
  *"Schema/index review disposition"*
- Owner: BE1 (DEV) · Reviewer: Tech Lead / DBA
- Nguồn kiểm chứng: `build/evidence/wwhn-199/db-invariants.json` → `checks.reservation_unique_indexes`
  (truy vấn thẳng `pg_indexes` trên DB candidate `hn_wwhn199`, PostgreSQL 16.14, dựng mới
  từ `migrate --force`)

---

## 1. Sự việc

Trên `outbound_item_matches` tồn tại **hai** unique partial index, do hai migration khác
nhau tạo ra:

| Index | Migration tạo | Định nghĩa PostgreSQL trả về (đã chuẩn hoá) |
|---|---|---|
| `uk_outbound_match_active_item` | `2026_08_10_000670:291` | `UNIQUE btree (item_id) WHERE ((item_id IS NOT NULL) AND ((status)::text = 'ACTIVE'::text))` |
| `ux_erd_outbound_active_item` | `2026_08_10_000770:238` | `UNIQUE btree (item_id) WHERE ((item_id IS NOT NULL) AND ((status)::text = 'ACTIVE'::text))` |

Sau khi PostgreSQL chuẩn hoá, hai chuỗi giống nhau **từng ký tự** ngoại trừ tên index.

## 2. Đánh giá: redundant, không phải hai lớp phòng thủ

Hai index này **không** cho thêm một lớp an toàn nào:

- Cùng bảng, cùng cột khoá, cùng predicate ⇒ tập vi phạm mà chúng bắt là **hoàn toàn
  trùng nhau**. Không có INSERT nào lọt qua index này mà bị index kia chặn.
- Chi phí thật: mỗi INSERT/UPDATE trên `outbound_item_matches` phải bảo trì **hai** B-tree
  thay vì một; mỗi `REINDEX`/`VACUUM` trả giá gấp đôi cho cùng một ràng buộc.
- Chi phí lớn hơn nằm ở **evidence**: khi QA/DevOps đọc `pg_indexes` để trả lời "cái gì
  đang chặn double-booking", họ nhận hai câu trả lời và không có cách nào biết cái nào là
  nguồn sự thật. Chính sự mơ hồ này là thứ làm vòng đánh giá trước phải hỏi lại.

Lớp phòng thủ thật sự cho bất biến "một item chỉ thuộc một phiếu xuất đang mở" là **bốn**
lớp khác nhau về bản chất, không phải hai index trùng tên:

| # | Lớp | Vị trí |
|---|---|---|
| 1 | Row lock `item_instances` (`lockForUpdate()`) | `CurrentBaselineStockService::resolveItem()` |
| 2 | Business check → 409 `ITEM_ALREADY_MATCHED` | `assertItemAvailableForIssue()` |
| 3 | Trigger DB | `trg_erd_outbound_item_exclusive` |
| 4 | Unique partial index | `ux_erd_outbound_active_item` |

## 3. Quyết định

**CONSOLIDATE.** Giữ `ux_erd_outbound_active_item`; bỏ `uk_outbound_match_active_item`.

Lý do chọn bản giữ lại:

1. `ux_erd_*` là quy ước đặt tên neo theo ERD (`HoaNam_ERD_Moi_Nhat.md`, bảng 41
   `outbound_item_matches`), giống hệt phía inbound `ux_erd_inbound_active_item` (bảng 28).
   Giữ nó thì evidence hai phía đối xứng và đọc được bằng một quy tắc.
2. `uk_*` là tên từ đợt alignment `000670`, đã có tiền lệ bị thay thế: `000710:14` đã DROP
   `uk_inbound_scan_active_item` do chính `000670` tạo.

### 3.1. Migration history có cho phép không — CÓ

Câu hỏi này phải trả lời trước khi được đụng vào, vì `000670`/`000770` đã chạy ở mọi môi
trường và migration đã áp dụng là **bất biến**.

- **Không sửa** `000670`/`000770`. Việc dọn đi bằng **migration tiến mới**:
  `2026_08_19_120000_consolidate_outbound_active_item_unique_index`.
- Tiền lệ trong chính repo: `000710:14` cũng dọn index của `000670` bằng bước tiến.
- Cả `000670` và `000770` đều `down()` rỗng (fail-forward) ⇒ **không tồn tại** đường
  rollback để sửa tại chỗ; bước tiến là con đường duy nhất hợp lệ. Chi tiết và runbook
  khôi phục: `docs/evidence/WWHN-199/PG_MIGRATION_AND_INDEX_EVIDENCE.md` §4.

### 3.2. Fail-closed hai tầng trong migration hợp nhất

Migration **không** DROP mù. Trước khi bỏ, nó bắt buộc:

1. `ux_erd_outbound_active_item` phải đang tồn tại (nếu không → ném lỗi, vì DROP lúc đó
   sẽ gỡ mất backstop cuối cùng chứ không phải dọn bản sao);
2. hai `indexdef` phải **thực sự tương đương** — so sánh dấu vân tay gồm tính UNIQUE +
   danh sách cột + **tập** atom của predicate đã sắp xếp (`A AND B` = `B AND A`). Nếu đã
   phân kỳ → ném lỗi kèm cả hai định nghĩa để người review phán quyết.

Nghĩa là nếu ai đó sau này đổi predicate của một trong hai index, migration sẽ **đỏ** thay
vì âm thầm xoá một ràng buộc khác nghĩa.

### 3.3. Chiều nghịch có thật

`down()` của migration hợp nhất tạo lại đúng `uk_outbound_match_active_item`. Bước dọn dẹp
này đảo ngược được bằng `migrate:rollback`, khác với `000670`/`000770`.

## 4. Bằng chứng đóng

| Kiểm tra | Kết quả | Nguồn |
|---|---|---|
| `outbound_item_matches` có đúng **1** unique index ACTIVE-item | `outbound_active_item_index_count = 1` | `db-invariants.json` |
| Index còn lại đúng là bản neo ERD | `ux_erd_outbound_active_item` | `db-invariants.json` |
| `inbound_scan_entries` đối xứng, cũng đúng 1 | `inbound_active_item_index_count = 1` | `db-invariants.json` |
| Backstop vẫn chặn thật sau khi dọn | INSERT trùng → **SQLSTATE 23505** trên `ux_erd_outbound_active_item` | `checks.duplicate_active_match_probe` |
| Race thật vẫn một-winner-một-conflict sau khi dọn | 6/6 vòng item-level + 1 vòng toa-level PASS | `race-report.json` |
| Cổng hồi quy | `Wwhn199ReservationIndexContractTest` — assert **đúng 1** index và đúng tên; skip trên sqlite | `tests/Feature/` |

Cổng hồi quy là phần quan trọng nhất của disposition này: nó biến "đã dọn xong" thành
"không thể mọc lại mà không ai biết". Nếu một migration tương lai tạo bản sao thứ hai,
test đỏ ngay.

## 5. Hệ quả với artifact của WWHN-198 (bắt buộc đọc)

WWHN-198 chạy race reservation riêng **trước** khi bản sao được dọn, nên một số artifact
của task đó mô tả schema lúc bấy giờ và có nhắc **cả hai** tên index:

| Artifact | Vị trí | Trạng thái sau DISP-OUT-199 |
|---|---|---|
| `docs/api-contract/WWHN-198-reserved-qty-contract.json` | `reservation_source_of_truth.database_guards` | **Đã cập nhật** ở vòng này: guard canonical = `ux_erd_outbound_active_item`; tên cũ chuyển sang `superseded_database_guards` |
| `docs/decision-records/DEC-INV-RESERVED-001-...md` | §2, dòng "Item vật lý này đã bị phiếu khác chiếm chưa?" | **Đã cập nhật** ở vòng này |
| `docs/evidence/WWHN-198/reservation-race-summary.json` | dump `pg_indexes` lúc chạy | **Giữ nguyên** — evidence lịch sử, đúng tại thời điểm sinh ra |
| `docs/evidence/WWHN-198/WWHN-198_QA1_TEST_PACK.md` | bảng `unique_index_guard` | **Giữ nguyên** — mô tả lần chạy đã diễn ra |
| `docs/decision-records/DISP-OUT-198-DUPLICATE-ITEM-MATCH.md` | bảng lớp phòng thủ | **Giữ nguyên** — kết luận (DB chặn bằng 23505) không đổi, chỉ còn một tên index |

Nguyên tắc áp dụng: **evidence lịch sử không bị viết lại**; chỉ **contract/decision đang
có hiệu lực** mới phải khớp schema hiện hành. Việc còn hai tên trong một file evidence đã
chạy không phải mâu thuẫn — nó là ảnh chụp đúng của một trạng thái đã qua.

Không có cổng nào của WWHN-198 bị ảnh hưởng: `scripts/verify-wwhn-198-closure.mjs` kiểm
`semantics`/`constant_value`/`is_authoritative_for_availability`/`reservation_model` và sự
tồn tại của decision record — không kiểm nội dung mảng `database_guards`;
`scripts/wwhn198-outbound-reservation-race.php` liệt kê `pg_indexes` động chứ không hardcode
tên index.

## 6. Nằm ngoài phạm vi disposition này

Cùng một hình thái "hai index tương đương" còn xuất hiện ở hai bảng khác. Chúng **không**
thuộc WWHN-199 (task này là luồng reservation xuất kho) và **không** được đụng tới ở đây:

| Bảng | Cặp index | Mức bằng chứng | Xử lý |
|---|---|---|---|
| `component_issue_items` | `uk_component_issue_active_item` (`000690:84`) và `ux_erd_component_active_item` (`000770:239`) | **Đã quan sát trên `pg_indexes`** của DB candidate — hai định nghĩa trùng từng ký tự | Chuyển BE2/DBA theo luồng Component Issue (§5.4.1 SRS) |
| `container_items` | `uk_container_items_one_active_item` (`000670:289`) và `ux_erd_container_active_item` (`000780:38`) | **Chỉ đọc từ source**, chưa truy vấn `pg_indexes` ở vòng này — predicate viết khác thứ tự toán hạng nên phải so bằng dấu vân tay chứ không so chuỗi | Chuyển BE2/DBA theo luồng Putaway/Container |

Ghi rõ mức bằng chứng của từng dòng là có chủ ý: dòng `container_items` **chưa** được
chứng minh bằng PostgreSQL và không được trích dẫn như thể đã chứng minh — đó đúng là lỗi
mà DEV199-P1-02R đã bác ở vòng trước.

Disposition này cũng **không** đụng tới: trigger `trg_erd_outbound_item_exclusive`, các
index `ux_erd_canonical_key_*`, hay bất kỳ ràng buộc nào không thuộc nhóm "ACTIVE-item
unique".
