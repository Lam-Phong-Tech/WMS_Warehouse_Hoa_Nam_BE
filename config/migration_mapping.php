<?php

/**
 * Mapping dữ liệu migration đã duyệt (WWHN-260 — DEV260-P1-02, DEV260-P1-11).
 *
 * File này chỉ giữ **hợp đồng dữ liệu**: mỗi dataset của Import Package v1.2 gắn
 * với một data owner chịu trách nhiệm nguồn và remediation của dòng bị loại. Bản
 * giấy tương ứng là `docs/migration/DATA_MAPPING_v1.0.md`; hai bản phải cùng
 * `mapping_version`.
 *
 * Hợp đồng **vận hành** của cùng job (quyền operator/approver, package version
 * được chấp nhận) nằm ở `config/final_data_load.php` — hai mối quan tâm khác
 * nhau, không trộn vào một file.
 */
return [
    /*
     * Phiên bản mapping đã duyệt. Đổi mapping ⇒ tăng version ⇒ phải chạy lại
     * dry-run vì ngữ nghĩa cột nguồn đã khác.
     */
    'mapping_version' => env('MIGRATION_MAPPING_VERSION', '1.0'),

    /*
     * Data owner theo dataset — nguồn của cột `data_owner` trong error file và
     * trong cleansing log. Giá trị là vai trò, không phải cá nhân, để không phải
     * sửa code khi đổi nhân sự.
     */
    'dataset_owners' => [
        'AGENCY' => 'BAS / Kinh doanh (chủ dữ liệu đại lý)',
        'SKU' => 'BAS / Danh mục sản phẩm (chủ dữ liệu SKU)',
        'PHYSICAL_CODE' => 'BE2 / Quản trị mã vật lý',
        'CONTAINER' => 'QL kho (chủ dữ liệu thùng/khay)',
        'ITEM' => 'QL kho (chủ dữ liệu item/serial)',
        'OPENING_BALANCE' => 'QL kho (chủ dữ liệu tồn đầu kỳ)',
    ],

    /*
     * NFR-SRS-017: dry-run phải chịu được tối thiểu 10.000 dòng mỗi file. Ngưỡng
     * này được kiểm bằng test capacity, không phải tuyên bố trong tài liệu.
     */
    'capacity' => [
        'min_rows_per_file' => 10000,
    ],
];
