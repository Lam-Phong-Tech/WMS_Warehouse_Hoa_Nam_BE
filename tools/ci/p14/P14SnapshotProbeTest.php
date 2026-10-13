<?php

namespace Tests\Feature;

require_once __DIR__.'/ReceiptProbe.php';

class P14SnapshotProbeTest extends P14RecoveryShiftContractTest
{
    use P14ReceiptProbe;

    public function test_saved_p03_snapshot_is_immutable_paged_and_does_not_change_owner(): void
    {
        $this->probeRun(fn () => parent::test_saved_p03_snapshot_is_immutable_paged_and_does_not_change_owner());
    }
}
