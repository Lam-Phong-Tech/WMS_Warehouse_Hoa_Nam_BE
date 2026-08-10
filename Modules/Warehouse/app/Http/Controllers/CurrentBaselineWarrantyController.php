<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Support\ApiError;
use Illuminate\Http\Request;

class CurrentBaselineWarrantyController extends CurrentBaselineOperationsController
{
    public function storeWarrantyCase(Request $request)
    {
        // HN-WMS-ERD-DD-001 requires a real reported_defect and limits the canonical
        // customer_phone column to varchar(30). Validate before the compatibility
        // implementation persists into the legacy runtime table and ERD mirror.
        $request->validate([
            'customer_name' => ['required', 'string', 'max:200'],
            'customer_phone' => ['required', 'string', 'max:30'],
            'description' => ['required', 'string', 'max:2000'],
            'purchase_date' => ['nullable', 'date', 'before_or_equal:today'],
        ]);

        return parent::storeWarrantyCase($request);
    }

    public function updateWarrantyCase(Request $request, string $id)
    {
        $header = trim((string) $request->header('If-Match'));
        if ($header === '') {
            ApiError::abort(428, 'PRECONDITION_REQUIRED', 'If-Match là bắt buộc cho Warranty Case mutation.');
        }

        $normalized = preg_replace('/^W\//', '', $header) ?? $header;
        $normalized = trim($normalized, '" ');
        if (! ctype_digit($normalized) || (int) $normalized < 1) {
            ApiError::abort(422, 'INVALID_VERSION', 'If-Match phải là version số nguyên dương.');
        }

        // Prevent the legacy body-version fallback in the base compatibility controller.
        $request->merge(['version' => (int) $normalized]);

        return parent::updateWarrantyCase($request, $id);
    }
}
