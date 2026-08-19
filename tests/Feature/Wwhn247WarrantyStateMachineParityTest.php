<?php

namespace Tests\Feature;

use Modules\Warehouse\Http\Controllers\CurrentBaselineOperationsController;
use Modules\Warehouse\Http\Controllers\CurrentBaselineWarrantyController;
use ReflectionClass;
use Tests\TestCase;

/**
 * WWHN-247 — chống "status enum mismatch" tái phát.
 *
 * Luồng 04 đã lệch trạng thái ba lần vì cùng một state machine được chép ra nhiều nơi và
 * chỉ một nơi được sửa: CurrentBaselineWarrantyController (route thật),
 * CurrentBaselineOperationsController (handler chết nhưng vẫn giữ hằng số), và
 * WARRANTY_NEXT_STATUS phía FE. Mỗi nơi tự test chính mình nên không ai đỏ khi chúng
 * trôi khỏi nhau.
 *
 * Test này khoá đúng chỗ hở đó ở phía BE. Phía FE có
 * src/test/wwhn-247-warranty-closure.test.ts và scripts/verify-wwhn-247-closure.mjs
 * (verifier đọc chéo hai repo) làm việc tương ứng.
 */
class Wwhn247WarrantyStateMachineParityTest extends TestCase
{
    /** Ma trận đã chốt: DEC-WAR-STATE-001 + SRS v1.12 §6. */
    private const DECIDED = [
        'RECEIVED' => ['CANCELLED', 'CHECKING'],
        'CHECKING' => ['CANCELLED', 'COMPLETED', 'REPAIRING'],
        'REPAIRING' => ['CANCELLED', 'COMPLETED'],
        'COMPLETED' => ['CANCELLED', 'RETURNED'],
        'RETURNED' => [],
        'CANCELLED' => [],
    ];

    /**
     * @return array<string, array<int, string>>
     */
    private function constantOf(string $class, string $name): array
    {
        $reflection = new ReflectionClass($class);
        $this->assertTrue(
            $reflection->hasConstant($name),
            "{$class} phải giữ hằng số {$name} để ma trận trạng thái có một nơi đọc được."
        );

        /** @var array<string, array<int, string>> $value */
        $value = $reflection->getConstant($name);
        foreach ($value as $from => $targets) {
            sort($targets);
            $value[$from] = $targets;
        }
        ksort($value);

        return $value;
    }

    private function decided(): array
    {
        $decided = self::DECIDED;
        ksort($decided);

        return $decided;
    }

    public function test_routed_warranty_controller_matches_the_decided_matrix(): void
    {
        $this->assertSame(
            $this->decided(),
            $this->constantOf(
                CurrentBaselineWarrantyController::class,
                'TRANSITIONS'
            ),
            'CurrentBaselineWarrantyController::TRANSITIONS đã trôi khỏi DEC-WAR-STATE-001.'
        );
    }

    public function test_duplicate_matrix_matches_the_routed_one(): void
    {
        $this->assertSame(
            $this->decided(),
            $this->constantOf(
                CurrentBaselineOperationsController::class,
                'WARRANTY_TRANSITIONS'
            ),
            'Bản sao WARRANTY_TRANSITIONS lệch ma trận đã chốt — đây chính là cái bẫy '
            .'khiến lần sửa sau sửa nhầm file và FE/BE lệch trạng thái lần nữa.'
        );
    }

    /**
     * §6: RETURNED/CANCELLED là terminal ở MỌI bản sao — không bản nào được mở thêm cạnh
     * đi ra khỏi trạng thái đã đóng.
     */
    public function test_terminal_states_have_no_outgoing_edge_anywhere(): void
    {
        foreach ([
            [CurrentBaselineWarrantyController::class, 'TRANSITIONS'],
            [CurrentBaselineOperationsController::class, 'WARRANTY_TRANSITIONS'],
        ] as [$class, $constant]) {
            $matrix = $this->constantOf($class, $constant);
            foreach (['RETURNED', 'CANCELLED'] as $terminal) {
                $this->assertSame(
                    [],
                    $matrix[$terminal] ?? [],
                    "{$class}::{$constant}[{$terminal}] phải rỗng — trạng thái terminal."
                );
            }
        }
    }

    /**
     * Ma trận chỉ được dùng 6 trạng thái của §6; alias legacy (OPEN/PROCESSING/READY/
     * WAITING_PARTS/CLOSED/RETURNED_TO_CUSTOMER) không được lọt vào state machine.
     */
    public function test_no_legacy_status_alias_leaks_into_the_matrix(): void
    {
        $allowed = ['RECEIVED', 'CHECKING', 'REPAIRING', 'COMPLETED', 'RETURNED', 'CANCELLED'];

        foreach ([
            [CurrentBaselineWarrantyController::class, 'TRANSITIONS'],
            [CurrentBaselineOperationsController::class, 'WARRANTY_TRANSITIONS'],
        ] as [$class, $constant]) {
            $matrix = $this->constantOf($class, $constant);
            foreach ($matrix as $from => $targets) {
                $this->assertContains($from, $allowed, "{$class}::{$constant} chứa trạng thái lạ: {$from}");
                foreach ($targets as $to) {
                    $this->assertContains($to, $allowed, "{$class}::{$constant} chứa đích lạ: {$to}");
                }
            }
        }
    }
}
