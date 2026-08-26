<?php

namespace Tests\Unit\Warehouse;

use Modules\Warehouse\Http\Controllers\AlignedOutboundFlowController;
use Modules\Warehouse\Http\Controllers\BaM1OutboundFlowController;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionNamedType;

final class BaM1OutboundFlowControllerSignatureTest extends TestCase
{
    public function test_post_issue_signature_stays_compatible_with_aligned_parent(): void
    {
        $parent = new ReflectionMethod(AlignedOutboundFlowController::class, 'postIssue');
        $child = new ReflectionMethod(BaM1OutboundFlowController::class, 'postIssue');

        self::assertSame($parent->getNumberOfParameters(), $child->getNumberOfParameters());
        self::assertSame($parent->getNumberOfRequiredParameters(), $child->getNumberOfRequiredParameters());

        foreach ($parent->getParameters() as $index => $parentParameter) {
            $childParameter = $child->getParameters()[$index];

            self::assertSame($parentParameter->getName(), $childParameter->getName());
            self::assertSame($this->typeName($parentParameter->getType()), $this->typeName($childParameter->getType()));
            self::assertSame($parentParameter->isOptional(), $childParameter->isOptional());
        }

        self::assertTrue($child->getParameters()[2]->isDefaultValueAvailable());
        self::assertTrue($child->getParameters()[2]->getDefaultValue());
    }

    private function typeName(?\ReflectionType $type): ?string
    {
        if ($type instanceof ReflectionNamedType) {
            return $type->getName();
        }

        return $type?->__toString();
    }
}
