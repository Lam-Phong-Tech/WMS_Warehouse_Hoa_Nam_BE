<?php

namespace Tests\Feature;

use App\Support\HoaNamErdSchema;
use Tests\TestCase;

class ErdCompilerSourcePinTest extends TestCase
{
    public function test_signed_erd_document_compiles_to_exactly_59_canonical_tables(): void
    {
        $schema = HoaNamErdSchema::parse();

        $this->assertCount(HoaNamErdSchema::TABLE_COUNT, $schema);
        $this->assertSame(59, HoaNamErdSchema::TABLE_COUNT);
        $this->assertSame('HN-WMS-ERD-DD-001', HoaNamErdSchema::DOCUMENT_ID);
    }
}
