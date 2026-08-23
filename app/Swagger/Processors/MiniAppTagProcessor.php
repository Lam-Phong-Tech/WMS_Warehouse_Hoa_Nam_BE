<?php

namespace App\Swagger\Processors;

use OpenApi\Analysis;
use OpenApi\Annotations as OA;
use OpenApi\Context;
use OpenApi\Undefined;

/**
 * Final guardrail for the dedicated /api/v1/mini-app/* Swagger group.
 *
 * RouteSyncProcessor already maps the Mini App adapter prefix to its own tag.
 * This post-processor intentionally runs after RouteSyncProcessor and reasserts
 * that presentation-only invariant so future generic grouping changes cannot
 * silently move Mini App operations back into Platform or canonical Stock In/Out.
 *
 * It never adds/removes runtime routes and never changes request/response
 * contracts; it only protects Swagger tag metadata.
 */
final class MiniAppTagProcessor
{
    public const TAG = '22. Mini App';

    private const DESCRIPTION = 'API adapter riêng cho Warehouse Mini App. Gồm inbound local staging/ghi nhận/duyệt Post Receipt và outbound tạo DRAFT trực tiếp/queue/scan/Packing Label/Post Issue; mọi stock rule vẫn dùng canonical WMS.';

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
                // RouteSyncProcessor may have created the tag earlier with an
                // older inbound-only description. Refresh it here because this
                // processor is the final owner of Mini App presentation metadata.
                $tag->description = self::DESCRIPTION;
                return;
            }
        }

        $miniAppTag = new OA\Tag([
            'name' => self::TAG,
            'description' => self::DESCRIPTION,
            '_context' => new Context(['generated' => true]),
        ]);

        $ordered = [];
        $inserted = false;

        foreach ($tags as $tag) {
            $ordered[] = $tag;

            if (! $inserted
                && $tag instanceof OA\Tag
                && ! Undefined::isDefault($tag->name)
                && $tag->name === '21. Public Catalog') {
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
