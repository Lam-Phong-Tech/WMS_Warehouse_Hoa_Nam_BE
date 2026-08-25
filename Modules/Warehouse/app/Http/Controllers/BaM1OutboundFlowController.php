<?php

namespace Modules\Warehouse\Http\Controllers;

use Illuminate\Http\Request;

/**
 * BA M1 2026-08-25 outbound boundary.
 *
 * Stock Issue is committed from the fully-scanned outbound document and must not
 * depend on Packing Label lifecycle. Printing/applying the label remains an
 * independent operational step and its failure cannot roll back stock issue.
 */
class BaM1OutboundFlowController extends AlignedOutboundFlowController
{
    public function postIssue(Request $request, string $id, bool $bypassPackingLabelGate = true)
    {
        // The aligned parent owns state/version/idempotency/full-scan/stock guards.
        // Passing bypassPackingLabelGate=true removes only the legacy label gate;
        // stock mutation semantics remain exactly the canonical implementation.
        return parent::postIssue($request, $id, $bypassPackingLabelGate);
    }
}
