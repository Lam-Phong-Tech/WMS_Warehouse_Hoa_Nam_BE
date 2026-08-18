<?php

/**
 * WWHN-295 — Readiness/identity contract của /api/v1/health.
 *
 * `version` là nhãn phát hành do người vận hành đặt. `build` là DANH TÍNH CHÍNH
 * XÁC của artifact đang chạy: mọi gate exact-SHA của dự án (WWHN-273/290/296/300)
 * đều yêu cầu bằng chứng gắn với đúng commit đã deploy, mà trước đây không có
 * cách nào đọc được SHA đó từ backend đang chạy — reviewer phải tin lời khai.
 * DevOps/CI inject APP_BUILD_SHA/REF/TIME lúc build image; thiếu chúng ở môi
 * trường ngoài local/testing bị `wms:golive-check` chặn (release.build_identity).
 */
return [
    'version' => env('APP_VERSION', '1.0.0'),

    'build' => [
        'sha' => env('APP_BUILD_SHA') ?: null,
        'ref' => env('APP_BUILD_REF') ?: null,
        'built_at' => env('APP_BUILD_TIME') ?: null,
    ],
];
