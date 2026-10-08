<?php

use Illuminate\Support\Facades\Route;
use Modules\Warehouse\Http\Controllers\P08HistoryController;

Route::prefix('v1/mini-app/history')->middleware(['auth:api', 'permission:history.read'])->group(function (): void {
    Route::get('records', [P08HistoryController::class, 'records'])->name('ReadHistoryRecords');
    Route::get('summary', [P08HistoryController::class, 'summary'])->name('ReadHistorySummary');
    Route::get('scan-sessions/{sessionId}', [P08HistoryController::class, 'scanSession'])->whereUuid('sessionId')->name('ReadScanSession');
    Route::get('records/{recordId}', [P08HistoryController::class, 'detail'])->name('ReadHistoryRecordDetail');
});
