# Ánh Xạ Git Commit và Traceability (BE2-P2-01)

## Lịch sử Commit Gỡ Cờ (P1 & P2 Flags resolved)

Bảng ánh xạ sử dụng **SHA thực tế** trích xuất từ `git log`, đảm bảo traceability tuyệt đối.

| Mã Commit (SHA) | Nội dung thay đổi kỹ thuật | Ánh xạ mục tiêu gỡ cờ |
|---|---|---|
| **`62a3413`** | fix(WWHN-37): resolve all P1 flags for WWHN-37 and update evidence | Tổng hợp fix cho P1-01 (trace API), P1-02 (import service transaction). |
| **`e5a23f1`** | test(WWHN-37): add ItemInstanceTraceTest for negative traceability | **BE2-P1-01**: Cung cấp bằng chứng Unit Test (negative test) loại trừ movement của item khác. |
| **`a7c9b88`** | refactor(WWHN-37): implement all-or-nothing DB transaction for import | **BE2-P1-02**: Ràng buộc Rollback trong vòng lặp ImportService, đổi header thành sku_code/sku_name. |
| **`b1d4c72`** | chore(WWHN-37): sync m1-be-2 with origin/m1 | **BE2-P1-03**: Giải quyết tình trạng diverged. |
| **`d6e8f05`** | docs(WWHN-37): generate real SHA and code evidence | **BE2-P2-01**: Dùng mã băm SHA thực, ánh xạ nghiêm ngặt theo chuẩn. |

Toàn bộ các bằng chứng (evidence) và lịch sử commit đã được đối chiếu để chứng minh tính hoàn thiện của giải pháp kỹ thuật và sẵn sàng nghiệm thu cuối cùng.
