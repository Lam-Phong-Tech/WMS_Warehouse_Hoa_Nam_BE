<?php

/*
|--------------------------------------------------------------------------
| Lần nạp dữ liệu cuối (final data load) — WWHN-299 / REQ-SRS-021
|--------------------------------------------------------------------------
|
| Hợp đồng vận hành của job `migration:final-load`. Tách ra config để cutover
| authority không bị hard-code trong service: SRS §13.12 yêu cầu "QL kho signs
| result" và ERD bảng 58 khai `approved_by` là "Data Owner/approver evidence",
| nên quyền duyệt phải kiểm theo permission thật chứ không phải "bất kỳ user
| ACTIVE nào" (DEV299-P1-02).
|
| Danh sách permission mặc định bám ma trận SRS §3:
|   - `warehouse.manage` chỉ Admin và QL kho được A (NV kho/NV bảo hành/Lãnh đạo
|     chỉ V) ⇒ đúng tập "Data Owner" của master/opening balance.
|   - `inventory.view` loại các account không thuộc phạm vi kho.
| Operator chỉ cần quyền đọc kho/tồn; quyền quyết định nằm ở approver, và
| separation-of-duty cấm operator tự duyệt chính mình.
|
*/

return [

    // Phiên bản Import Package và mapping đã duyệt nằm ở `config/migration.php`
    // (`migration.package_version` / `migration.mapping_version`) — file này chỉ
    // giữ hợp đồng thẩm quyền để không có hai nguồn sự thật cho cùng một thứ.

    'operator' => [
        'required_permissions' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('FINAL_DATA_LOAD_OPERATOR_PERMISSIONS', 'warehouse.view,inventory.view'))
        ))),
    ],

    'approver' => [
        'required_permissions' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('FINAL_DATA_LOAD_APPROVER_PERMISSIONS', 'warehouse.manage,inventory.view'))
        ))),

        // DEV299-P1-02: operator không được tự duyệt lần nạp của chính mình.
        'separation_of_duty' => filter_var(
            env('FINAL_DATA_LOAD_SEPARATION_OF_DUTY', true),
            FILTER_VALIDATE_BOOLEAN
        ),

        // Bắt buộc pin Decision/approval ID ngoài hệ thống (Jira comment, biên bản
        // ký duyệt opening balance) vào evidence của run COMMIT.
        'require_approval_ref' => filter_var(
            env('FINAL_DATA_LOAD_REQUIRE_APPROVAL_REF', true),
            FILTER_VALIDATE_BOOLEAN
        ),
    ],

];
