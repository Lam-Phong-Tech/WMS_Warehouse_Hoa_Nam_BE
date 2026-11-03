<?php

namespace App\Support;

use RuntimeException;

final class BuildIdentity
{
    /**
     * The CI archive marker names the source actually mounted by the DEV receiver.
     * A legacy compose service can still carry an older APP_BUILD_SHA and override
     * the refreshed env_file, so the verified archive marker takes precedence.
     *
     * @return array{sha: ?string, ref: ?string, built_at: ?string}
     */
    public static function resolve(
        string $marker,
        ?string $environmentSha,
        ?string $environmentRef,
        ?string $environmentBuiltAt,
    ): array {
        if (! file_exists($marker) && ! is_link($marker)) {
            return [
                'sha' => $environmentSha ?: null,
                'ref' => $environmentRef ?: null,
                'built_at' => $environmentBuiltAt ?: null,
            ];
        }

        if (is_link($marker) || ! is_file($marker)) {
            throw new RuntimeException('Invalid CI source SHA marker');
        }

        $sha = trim((string) file_get_contents($marker));
        if (! preg_match('/\A[0-9a-f]{40}\z/', $sha)) {
            throw new RuntimeException('Invalid CI source SHA marker');
        }

        $modifiedAt = filemtime($marker);
        if ($modifiedAt === false) {
            throw new RuntimeException('Cannot read CI source SHA marker timestamp');
        }

        return [
            'sha' => $sha,
            'ref' => 'dev@'.$sha,
            'built_at' => gmdate('Y-m-d\TH:i:s\Z', $modifiedAt),
        ];
    }
}
