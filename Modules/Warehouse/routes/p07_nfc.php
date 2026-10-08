<?php

use Illuminate\Support\Facades\Route;
use Modules\Warehouse\Http\Controllers\P07NfcController;

// Existing NFC routes resolve to P07NfcController through the provider binding.
// No duplicate route or replacement writer; audit /events remains independent.
Route::prefix('v1/mini-app')->middleware('auth:api')->group(function (): void {
    Route::get('nfc-tags/{physicalCodeId}', [P07NfcController::class, 'showTag'])
        ->whereUuid('physicalCodeId')->middleware('permission:physical_code.assign_rfid|inventory.trace')->name('nfc.show');
    Route::get('items/{itemId}/nfc/confirmation', [P07NfcController::class, 'confirmation'])
        ->whereUuid('itemId')->middleware('permission:physical_code.assign_rfid')->name('nfc.confirmation');
    Route::post('items/{itemId}/nfc/release', [P07NfcController::class, 'release'])
        ->whereUuid('itemId')->middleware('permission:physical_code.assign_rfid')->name('nfc.release');
});
