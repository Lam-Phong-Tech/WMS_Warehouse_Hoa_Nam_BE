<?php

use Illuminate\Support\Facades\Route;
use Modules\Warehouse\Services\AttachmentUploadReceipts;

// Reconcile existing owner uploads only; no P18 upload, cancel, retry or viewer route.
Route::prefix('v1/mini-app')->middleware('auth:api')->group(function (): void {
    foreach (['inbound-documents' => 'INBOUND', 'outbound-documents' => 'OUTBOUND', 'warranty-cases' => 'WARRANTY'] as $path => $owner) {
        Route::get($path.'/{id}/attachments/requests/{operationKey}', [AttachmentUploadReceipts::class, 'show'])
            ->whereUuid('id')->whereUuid('operationKey')->defaults('attachment_owner', $owner)
            ->name(strtolower($owner).'.show');
    }
});
