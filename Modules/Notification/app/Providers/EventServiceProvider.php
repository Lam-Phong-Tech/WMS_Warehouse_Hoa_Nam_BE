<?php

namespace Modules\Notification\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Modules\Auth\Events\RolePermissionsChanged;
use Modules\Auth\Events\SecurityAlertRaised;
use Modules\Auth\Events\UserAccountChanged;
use Modules\Notification\Listeners\SendInboundCompletedNotification;
use Modules\Notification\Listeners\SendInboundSkuInfoPendingNotification;
use Modules\Notification\Listeners\SendOutboundPostedNotification;
use Modules\Notification\Listeners\SendReversalCancelledNotification;
use Modules\Notification\Listeners\SendReversalDraftCreatedNotification;
use Modules\Notification\Listeners\SendReversalPostedNotification;
use Modules\Notification\Listeners\SendRolePermissionsChangedNotification;
use Modules\Notification\Listeners\SendSecurityAlertNotification;
use Modules\Notification\Listeners\SendStockDocumentCancelledNotification;
use Modules\Notification\Listeners\SendStockDocumentCreatedNotification;
use Modules\Notification\Listeners\SendStockDocumentScanStartedNotification;
use Modules\Notification\Listeners\SendStockShortageNotification;
use Modules\Notification\Listeners\SendUserAccountChangedNotification;
use Modules\Notification\Listeners\SendWarrantyCaseCreatedNotification;
use Modules\Notification\Listeners\SendWarrantyStatusChangedNotification;
use Modules\Warehouse\Events\InboundCompleted;
use Modules\Warehouse\Events\InboundSkuInfoPending;
use Modules\Warehouse\Events\OutboundPosted;
use Modules\Warehouse\Events\ReversalCancelled;
use Modules\Warehouse\Events\ReversalDraftCreated;
use Modules\Warehouse\Events\ReversalPosted;
use Modules\Warehouse\Events\StockDocumentCancelled;
use Modules\Warehouse\Events\StockDocumentCreated;
use Modules\Warehouse\Events\StockDocumentScanStarted;
use Modules\Warehouse\Events\StockShortageDetected;
use Modules\Warehouse\Events\WarrantyCaseCreated;
use Modules\Warehouse\Events\WarrantyStatusChanged;
use Modules\Warehouse\Support\StockDocumentEventEvidence;

class EventServiceProvider extends ServiceProvider
{
    /**
     * Đăng ký tường minh thay vì dựa vào event discovery: nwidart module
     * EventServiceProvider generated không override discoverEventsWithin(), nên
     * $shouldDiscoverEvents=true chỉ quét app/Listeners ở app gốc, không quét
     * Listeners của module này — xác nhận qua AuditLog (không có Listener nào dù
     * bật discovery). Khai rõ ở đây để chắc chắn wiring hoạt động.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        InboundCompleted::class => [SendInboundCompletedNotification::class],
        InboundSkuInfoPending::class => [SendInboundSkuInfoPendingNotification::class],
        OutboundPosted::class => [SendOutboundPostedNotification::class],
        StockShortageDetected::class => [SendStockShortageNotification::class],
        WarrantyStatusChanged::class => [SendWarrantyStatusChangedNotification::class],
        UserAccountChanged::class => [SendUserAccountChangedNotification::class],
        RolePermissionsChanged::class => [SendRolePermissionsChangedNotification::class],
        SecurityAlertRaised::class => [SendSecurityAlertNotification::class],
        StockDocumentCreated::class => [StockDocumentEventEvidence::class, SendStockDocumentCreatedNotification::class],
        StockDocumentScanStarted::class => [SendStockDocumentScanStartedNotification::class],
        StockDocumentCancelled::class => [SendStockDocumentCancelledNotification::class],
        WarrantyCaseCreated::class => [SendWarrantyCaseCreatedNotification::class],
        ReversalDraftCreated::class => [SendReversalDraftCreatedNotification::class],
        ReversalPosted::class => [SendReversalPostedNotification::class],
        ReversalCancelled::class => [SendReversalCancelledNotification::class],
    ];

    /**
     * @var bool
     */
    protected static $shouldDiscoverEvents = false;

    protected function configureEmailVerification(): void {}
}
