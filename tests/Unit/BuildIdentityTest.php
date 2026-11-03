<?php

namespace Tests\Unit;

use App\Support\BuildIdentity;
use PHPUnit\Framework\TestCase;

class BuildIdentityTest extends TestCase
{
    public function test_archive_marker_overrides_stale_compose_build_identity(): void
    {
        $marker = tempnam(sys_get_temp_dir(), 'source-sha-');
        try {
            file_put_contents($marker, "0123456789abcdef0123456789abcdef01234567\n");

            $identity = BuildIdentity::resolve(
                $marker,
                'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
                'dev-p05-save-numbering@aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
                '2026-01-01T00:00:00Z',
            );

            $this->assertSame('0123456789abcdef0123456789abcdef01234567', $identity['sha']);
            $this->assertSame('dev@0123456789abcdef0123456789abcdef01234567', $identity['ref']);
            $this->assertNotSame('2026-01-01T00:00:00Z', $identity['built_at']);
        } finally {
            unlink($marker);
        }
    }

    public function test_no_archive_marker_preserves_existing_environment_identity(): void
    {
        $identity = BuildIdentity::resolve(
            sys_get_temp_dir().'/missing-source-sha-'.bin2hex(random_bytes(8)),
            'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
            'release-b',
            '2026-11-03T00:00:00Z',
        );

        $this->assertSame('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', $identity['sha']);
        $this->assertSame('release-b', $identity['ref']);
        $this->assertSame('2026-11-03T00:00:00Z', $identity['built_at']);
    }

    public function test_malformed_archive_marker_fails_closed(): void
    {
        $marker = tempnam(sys_get_temp_dir(), 'source-sha-');
        try {
            file_put_contents($marker, "invalid\n");
            $this->expectException(\RuntimeException::class);
            BuildIdentity::resolve($marker, null, null, null);
        } finally {
            unlink($marker);
        }
    }
}
