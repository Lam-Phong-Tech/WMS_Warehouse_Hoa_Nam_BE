<?php

namespace App\Swagger\Processors;

use OpenApi\Analysis;
use OpenApi\Annotations as OA;
use OpenApi\Context;
use OpenApi\Undefined;

/**
 * Keep every /api/v1/mini-app/* operation in a dedicated Swagger group.
 *
 * RouteSyncProcessor derives its tag from the first resource segment after
 * api/v1. For Mini App routes that segment is "mini-app", which is not a
 * canonical WMS resource such as inbound-documents. Without this post-processor
 * RouteSyncProcessor falls back to "00. Platform" and Swagger UI appears to
 * "lose" all Mini App endpoints even though the paths are present in /docs.
 *
 * This processor deliberately runs after RouteSyncProcessor and only changes
 * presentation metadata (tags). It never adds/removes runtime routes and never
 * changes request/response contracts.
 */
final class MiniAppTagProcessor
{
    public const TAG = '09A. Mini App';

    private const DESCRIPTION = 'Adapter API cho Warehouse Mini App. Hiện tại bao gồm local staging, ghi nhận batch nhập kho, hàng chờ duyệt, scan evidence và Post Receipt qua canonical WMS.';

    /** @var array<int, string> */
    private const METHODS = ['get', 'post', 'put', 'patch', 'delete', 'options', 'head', 'trace'];

    public function __invoke(Analysis $analysis): void
    {
        $openApi = $analysis->openapi;
        if ($openApi === null) {
            return;
        }

        $this->ensureTag($openApi);

        if (Undefined::isDefault($openApi->paths)) {
            return;
        }

        foreach ($openApi->paths as $pathItem) {
            if (Undefined::isDefault($pathItem->path)) {
                continue;
            }

            $path = (string) $pathItem->path;
            if (! str_starts_with($path, '/api/v1/mini-app/')) {
                continue;
            }

            foreach (self::METHODS as $method) {
                if (Undefined::isDefault($pathItem->{$method})) {
                    continue;
                }

                $pathItem->{$method}->tags = [self::TAG];
            }
        }
    }

    private function ensureTag(OA\OpenApi $openApi): void
    {
        $tags = Undefined::isDefault($openApi->tags) ? [] : (array) $openApi->tags;

        foreach ($tags as $tag) {
            if ($tag instanceof OA\Tag
                && ! Undefined::isDefault($tag->name)
                && $tag->name === self::TAG) {
                return;
            }
        }

        $miniAppTag = new OA\Tag([
            'name' => self::TAG,
            'description' => self::DESCRIPTION,
            '_context' => new Context(['generated' => true]),
        ]);

        // Keep the dedicated Mini App section directly after canonical inbound
        // so Swagger UI remains easy to navigate and visually deterministic.
        $ordered = [];
        $inserted = false;

        foreach ($tags as $tag) {
            $ordered[] = $tag;

            if (! $inserted
                && $tag instanceof OA\Tag
                && ! Undefined::isDefault($tag->name)
                && $tag->name === '09. Stock In (Inbound)') {
                $ordered[] = $miniAppTag;
                $inserted = true;
            }
        }

        if (! $inserted) {
            $ordered[] = $miniAppTag;
        }

        $openApi->tags = $ordered;
    }
}
