<?php

namespace Tests\Feature;

require_once __DIR__.'/ReceiptProbe.php';

class P14NfcProbeTest extends P14LifecycleIntegrationTest
{
    use P14ReceiptProbe;

    public function test_p07_confirm_uses_native_durable_receipt_not_a_fake_saved_draft(): void
    {
        $this->probeRun(fn () => parent::test_p07_confirm_uses_native_durable_receipt_not_a_fake_saved_draft());
    }
}
