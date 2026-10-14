<?php

namespace Modules\Warehouse\Support;

/** Source-owned status/readiness projection for the P12 aggregate list. */
final class P12MixedDocumentStatus
{
    public const CONTRACT = 'p12-p13-p16-status-v1';

    private const WARRANTY_LABELS = [
        'RECEIVED' => 'Đã tiếp nhận',
        'CHECKING' => 'Đang kiểm tra',
        'REPAIRING' => 'Đang sửa chữa',
        'COMPLETED' => 'Đã hoàn tất',
        'RETURNED' => 'Đã trả khách',
        'CANCELLED' => 'Đã hủy',
    ];

    public static function make(
        string $source,
        ?string $canonical,
        bool $scanComplete,
        bool $productInfoComplete,
    ): array {
        if ($canonical === null) {
            return self::result(null, null, 'UNKNOWN', false, null, null, null,
                ['SOURCE_STATUS_NOT_RECORDED'], 'Trạng thái chưa được ghi nhận', 'SOURCE_STATUS_NOT_RECORDED');
        }

        return match ($source) {
            'INBOUND' => self::inbound($canonical, $scanComplete, $productInfoComplete),
            'OUTBOUND' => self::outbound($canonical, $scanComplete),
            'WARRANTY' => self::warranty($canonical),
        };
    }

    public static function canonicalLabel(string $source, string $status): string
    {
        return match ($source) {
            'INBOUND' => match ($status) {
                'DRAFT' => 'Nhập kho - Nháp',
                'SCANNING' => 'Nhập kho - Đang xử lý',
                'POSTED' => 'Nhập kho - Đã nhập kho',
                'CANCELLED' => 'Nhập kho - Đã hủy',
                default => $status,
            },
            'OUTBOUND' => match ($status) {
                'DRAFT' => 'Xuất kho - Nháp',
                'SCANNING' => 'Xuất kho - Đang xử lý',
                'POSTED' => 'Xuất kho - Đã xuất kho',
                'CANCELLED' => 'Xuất kho - Đã hủy',
                default => $status,
            },
            'WARRANTY' => 'Bảo hành - '.(self::WARRANTY_LABELS[$status] ?? $status),
        };
    }

    private static function inbound(string $status, bool $scanComplete, bool $productInfoComplete): array
    {
        if ($status === 'POSTED') {
            return self::result($status, 'POSTED', 'TERMINAL', true, false, null, false, [],
                'Đã nhập kho', 'stock_documents.status');
        }
        if ($status === 'CANCELLED') {
            return self::result($status, 'CANCELLED', 'TERMINAL', true, false, null, false, [],
                'Đã hủy', 'stock_documents.status');
        }
        if (! in_array($status, ['DRAFT', 'SCANNING'], true)) {
            return self::result($status, null, 'UNKNOWN', false, null, null, null,
                ['SOURCE_STATUS_UNSUPPORTED'], 'Trạng thái không được hỗ trợ', 'stock_documents.status');
        }

        $readyForPost = $status === 'SCANNING' && $scanComplete;
        $canPost = $readyForPost && $productInfoComplete;
        if ($canPost) {
            return self::result($status, 'WAITING_APPROVAL', 'READY', true, true, null, true, [],
                'Chờ duyệt nhập kho', 'stock_documents.status+stock_document_lines+sku');
        }
        if ($readyForPost) {
            return self::result($status, 'WAITING_APPROVAL', 'BLOCKED', true, true, null, false,
                ['INBOUND_SKU_INFO_INCOMPLETE'], 'Chờ bổ sung thông tin SKU',
                'stock_documents.status+stock_document_lines+sku');
        }

        return self::result($status, $status, 'IN_PROGRESS', true, false, null, false,
            ['SCAN_INCOMPLETE'], $status === 'DRAFT' ? 'Nháp nhập kho' : 'Đang quét nhập kho',
            'stock_documents.status+stock_document_lines');
    }

    private static function outbound(string $status, bool $scanComplete): array
    {
        if ($status === 'POSTED') {
            return self::result($status, 'COMPLETED', 'TERMINAL', true, null, false, null, [],
                'Đã xuất kho', 'stock_documents.status');
        }
        if ($status === 'CANCELLED') {
            return self::result($status, 'CANCELLED', 'TERMINAL', true, null, false, null, [],
                'Đã hủy', 'stock_documents.status');
        }
        if (! in_array($status, ['DRAFT', 'SCANNING'], true)) {
            return self::result($status, null, 'UNKNOWN', false, null, null, null,
                ['SOURCE_STATUS_UNSUPPORTED'], 'Trạng thái không được hỗ trợ', 'stock_documents.status');
        }

        $readyForIssue = $status === 'SCANNING' && $scanComplete;
        $miniApp = $scanComplete ? 'READY_TO_ISSUE' : 'PICKING';

        return self::result($status, $miniApp, $readyForIssue ? 'READY' : 'IN_PROGRESS', true,
            null, $readyForIssue, null, $readyForIssue ? [] : ['SCAN_INCOMPLETE'],
            $scanComplete ? 'Sẵn sàng xuất kho' : 'Đang lấy hàng',
            'stock_documents.status+stock_document_lines');
    }

    private static function warranty(string $status): array
    {
        return self::result($status, $status, 'OWNER_STATUS_ONLY', false, null, null, null, [],
            self::WARRANTY_LABELS[$status] ?? $status, 'warranty_case.status');
    }

    private static function result(
        ?string $canonical,
        ?string $miniApp,
        string $state,
        bool $applicable,
        ?bool $readyForPost,
        ?bool $readyForIssue,
        ?bool $canPost,
        array $blockers,
        string $label,
        string $source,
    ): array {
        return [
            'canonical_status' => $canonical,
            'mini_app_status' => $miniApp,
            'readiness' => [
                'state' => $state,
                'applicable' => $applicable,
                'ready_for_post' => $readyForPost,
                'ready_for_issue' => $readyForIssue,
                'can_post' => $canPost,
                'blockers' => $blockers,
                'source' => $source,
            ],
            'display_status' => [
                'value' => $miniApp,
                'label' => $label,
                'availability' => 'MAPPED',
                'mapping_contract' => self::CONTRACT,
                'source' => $source,
            ],
        ];
    }
}
