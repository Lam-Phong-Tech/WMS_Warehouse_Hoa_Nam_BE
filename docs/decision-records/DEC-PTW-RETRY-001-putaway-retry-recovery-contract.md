# DEC-PTW-RETRY-001 — Hợp đồng phục hồi retry cho Putaway confirm/cancel

| Trường | Giá trị |
|---|---|
| Quyết định | DEC-PTW-RETRY-001 |
| Jira | WWHN-193 (HN-IN-004) · register `DEV193-P1-03R` |
| Phạm vi | `POST /api/v1/putaway-tasks/{id}/confirm`, `POST /api/v1/putaway-tasks/{id}/cancel` (và alias legacy `/putaway/tasks/...`) |
| Nguồn chuẩn | SRS/FRD v1.12 §6 (State model · Putaway Task), §8.2 (bảng mã lỗi 409), §13.2 (Headers), Annex C `confirmPutawayTask` / `cancelPutawayTask`, AC-SRS-009 |
| Trạng thái | ADOPTED — cần Tech Lead + BA duyệt |

## 1. Vấn đề

Confirm/cancel chỉ được bảo vệ bằng `If-Match`. Khi request **đã commit** nhưng client
**mất response** (timeout mạng, đóng tab, proxy cắt, mobile mất sóng), lần gửi lại mang
đúng `If-Match` cũ rơi vào nhánh `status !== 'DRAFT'` và nhận `409 STATE_CONFLICT` trần.

Từ phía client, 409 đó không phân biệt được hai tình huống hoàn toàn khác nhau:

1. **Chính request của mình đã thành công**, chỉ là không nhận được kết quả → nên đi tiếp.
2. **Người khác vừa kết thúc tác vụ này** theo hướng khác → phải dừng và đọc lại.

Vì không phân biệt được, FE chỉ còn cách hiện lỗi chung chung, và người vận hành kho có xu
hướng thao tác lại bằng tay — đúng lớp rủi ro mà reviewer gọi là *"controlled 409 nhưng chưa
formal recovery semantics"*.

Ràng buộc: Annex C khai `confirmPutawayTask`/`cancelPutawayTask` ở cột *Idempotency/If-Match*
**chỉ có `If-Match`**. Không được biến `Idempotency-Key` thành bắt buộc (sẽ phá contract và
làm client hiện hữu nhận 428).

## 2. Quyết định

Hợp đồng hai tầng. Cả hai tầng đều **stock-neutral tuyệt đối** (AC-SRS-009: putaway không
sinh `Inventory_Movement`).

### Tầng 1 — có `Idempotency-Key` (khuyến nghị, đường phục hồi xác định)

Header là **tuỳ chọn**. Khi có, request đi qua `App\Support\IdempotencyStore` với
`operation = putaway_confirm_{id}` / `putaway_cancel_{id}`:

| Tình huống | Kết quả |
|---|---|
| Retry cùng key + cùng payload sau khi đã commit | `200` + snapshot cũ + `data.replayed = true` + header `Idempotent-Replay: true` |
| Cùng key, payload khác | `409 IDEMPOTENCY_CONFLICT` (SRS §8.2) |
| Cùng key, lease của request trước còn hạn | `409 CONCURRENCY_CONFLICT` (SRS §8.2) |
| Request lỗi (4xx/5xx) | Lease bị xoá — client sửa dữ liệu rồi thử lại được, không bị "khoá vĩnh viễn" |

Điều này bám đúng câu của SRS §8.2: *"State, stock and movement unchanged **unless this is an
idempotent replay of a prior success**"*, và §13.2 đã liệt kê `Idempotency-Key` +
`Idempotency-Replayed` trong bộ header chuẩn.

`If-Match` **vẫn bắt buộc** như trước; thiếu nó vẫn là `428 PRECONDITION_REQUIRED`.

### Tầng 2 — không có key (client cũ): 409 kèm ngữ nghĩa phục hồi

Vẫn `409 STATE_CONFLICT` đúng contract, nhưng `error.details` mang thêm khối `recovery`
máy-đọc-được:

```jsonc
{
  "error": {
    "code": "STATE_CONFLICT",
    "details": {
      "putaway_event_id": "…",
      "operation": "confirm",
      "current_status": "CONFIRMED",
      "current_version": 2,
      "expected_version": 1,
      "confirmed_at": "…",
      "recovery": {
        "contract": "DEC-PTW-RETRY-001",
        "outcome": "ALREADY_APPLIED_BY_CALLER",
        "applied_by_caller": true,
        "safe_to_treat_as_success": true,
        "next_action": "RELOAD_AND_CONTINUE",
        "idempotent_retry_hint": "Gửi lại cùng Idempotency-Key để nhận đúng response cũ thay vì 409."
      },
      "stock_effect": "NONE",
      "movement_created": false
    }
  }
}
```

| `outcome` | Điều kiện | `safe_to_treat_as_success` | `next_action` |
|---|---|---|---|
| `ALREADY_APPLIED_BY_CALLER` | Tác vụ đã ở đúng trạng thái đích **và** người thực hiện cuối là chính caller **và** `current_version == expected_version + 1` | `true` | `RELOAD_AND_CONTINUE` |
| `ALREADY_APPLIED_BY_OTHER` | Tác vụ đã ở đúng trạng thái đích nhưng do người khác/nhiều bước | `true` | `RELOAD_AND_CONTINUE` |
| `FINALIZED_DIFFERENT_OUTCOME` | Tác vụ đã kết thúc ở trạng thái **khác** (vd. bấm Hủy trên task đã CONFIRMED) | `false` | `RELOAD_AND_REVIEW` |

Hai nhánh `ALREADY_APPLIED_*` đều an toàn vì `putaway_events.id` định danh **một tác vụ cụ
thể**: trạng thái đích đạt được nghĩa là đúng kết quả nghiệp vụ caller muốn trên chính tác vụ
đó, bất kể ai bấm nút.

**Không trả về id của actor khác.** Caller có thể không có quyền đọc hồ sơ người dùng, nên
envelope chỉ phát cờ boolean `applied_by_caller`.

## 3. Phía FE

`src/modules/putaway/services/putawayService.ts`:

- Gửi `Idempotency-Key` **suy diễn**, không random: `ptw-{operation}-{id}-v{version}`. Cùng
  version = cùng ý định = cùng khoá, nên retry sau mất response được replay; version đổi
  nghĩa là thao tác khác nên khoá khác. (Khoá random mỗi lần bấm sẽ vô hiệu hoá toàn bộ tầng 1.)
- Bắt 409 và tra `error.details.recovery`: nếu `safe_to_treat_as_success` thì coi như thành
  công đã-đánh-dấu (`recovery` đính kèm để UI thông báo), rồi `invalidate` để tải lại danh sách
  đúng như `next_action = RELOAD_AND_CONTINUE`. `FINALIZED_DIFFERENT_OUTCOME` và mọi mã lỗi
  khác (403/412/500…) vẫn ném ra như cũ.

## 4. Bằng chứng

| Chiều | Test |
|---|---|
| Timeout-after-commit / mất response | `Wwhn193PutawayRetryRecoveryContractTest::test_confirm_retry_with_same_idempotency_key_replays_committed_result` |
| Cùng key khác payload | `::test_confirm_retry_with_same_key_but_different_payload_is_idempotency_conflict` |
| Same-operation concurrent (tranh chấp lease) | `::test_concurrent_retry_on_live_lease_returns_concurrency_conflict` |
| Recovery — chính mình | `::test_confirm_retry_without_key_returns_recovery_already_applied_by_caller` |
| Recovery — người khác, không lộ danh tính | `::test_confirm_retry_by_other_actor_is_reported_as_applied_by_other` |
| Terminal khác nhau không được nuốt | `::test_cancel_after_confirm_is_reported_as_finalized_different_outcome` |
| Cancel replay | `::test_cancel_retry_with_same_idempotency_key_replays_and_stays_stock_neutral` |
| Không phá Annex C (key tuỳ chọn, If-Match vẫn bắt buộc) | `::test_missing_idempotency_key_is_not_a_precondition_error` |
| Độ dài key theo contract | `::test_idempotency_key_shorter_than_contract_minimum_is_rejected` |
| FE recovery + khoá suy diễn | `src/modules/putaway/services/putawayService.test.ts` |

Số liệu thô: `build/evidence/wwhn-193/retry-recovery-proof.json` (sinh bởi chính lần chạy,
fail-closed nếu không ghi được).

## 5. Hệ quả

- Không thay đổi tập mã lỗi: `STATE_CONFLICT` / `IDEMPOTENCY_CONFLICT` / `CONCURRENCY_CONFLICT`
  đều đã nằm trong SRS §8.2.
- Không thay đổi tính bắt buộc của header nào → client cũ không vỡ.
- Bảng `idempotency_keys` có thêm hai `operation` mới; retention/dọn dẹp dùng chung cơ chế sẵn có.
- Putaway vẫn stock-neutral: không nhánh nào chạm `stock_movements` / `inventory_balances`.
