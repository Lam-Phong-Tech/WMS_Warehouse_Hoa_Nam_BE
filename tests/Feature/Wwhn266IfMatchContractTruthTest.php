<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use ReflectionMethod;
use Tests\TestCase;

/**
 * WWHN-266 · BE266-D01 — hợp đồng `If-Match` phải nói đúng hành vi runtime.
 *
 * Defect gốc: `RouteSyncProcessor::contractHeaders()` hard-code
 * `'required' => false` cho If-Match trong khi `actionContractFlags()` chỉ ghi
 * nhận "action có đọc header hay không". Hệ quả đo được tại commit trước fix:
 * **46/52 operation bắt buộc If-Match lúc chạy (ném 428 PRECONDITION_REQUIRED)
 * lại công bố `required: false`** trong `storage/api-docs/api-docs.json` và
 * Postman collection sinh ra từ đó — gồm cả ba action đổi tồn
 * `post-issue`, `reversal-documents/{id}/post`, `component-issue-documents/{id}/post`.
 * FE/QA sinh client từ contract sẽ bỏ header rồi ăn 428 lúc tích hợp; đây đúng
 * là lớp lỗi mà WWHN-309 đã sửa cho `Idempotency-Key` nhưng bỏ sót ở If-Match.
 *
 * Bộ này khoá cả hai chiều: contract khớp runtime (tĩnh, toàn bộ route) và
 * runtime đúng như contract (động, qua HTTP thật).
 */
class Wwhn266IfMatchContractTruthTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, string>|null */
    private static ?array $sourceCache = null;

    /**
     * Sinh lại spec từ source rồi mới đọc — cùng quy ước với
     * DocumentationBaselineContractTest. Đọc thẳng bản đã commit thì bộ này chỉ
     * kiểm một file tĩnh: BE266-D01 có thể tái phát trong RouteSyncProcessor mà
     * test vẫn xanh vì artifact cũ đang đúng.
     */
    private function openApi(): array
    {
        Artisan::call('l5-swagger:generate');
        $path = storage_path('api-docs/api-docs.json');
        $this->assertFileExists($path, 'l5-swagger:generate phải sinh được storage/api-docs/api-docs.json.');

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    private function methodSource(string $class, string $method): ?string
    {
        try {
            $reflection = new ReflectionMethod($class, $method);
        } catch (\ReflectionException) {
            return null;
        }
        $file = $reflection->getFileName();
        if ($file === false) {
            return null;
        }
        $lines = file($file) ?: [];

        return implode('', array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1
        ));
    }

    /**
     * Cùng thuật toán quét đệ quy `$this->helper()` mà RouteSyncProcessor dùng,
     * viết lại độc lập ở đây để test không chỉ lặp lại code sản phẩm.
     *
     * @return array<int, string>|null từng thân method riêng, không nối lại
     */
    private function actionBodies(string $action): ?array
    {
        if (! str_contains($action, '@')) {
            return null;
        }
        [$class, $method] = explode('@', $action, 2);
        if (! class_exists($class) || ! method_exists($class, $method)) {
            return null;
        }
        $source = $this->methodSource($class, $method);
        if ($source === null) {
            return null;
        }
        $seen = [$method => true];
        $pending = [$source];
        $bodies = [$source];
        while ($pending !== []) {
            $current = array_pop($pending);
            preg_match_all('/\$this->([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $current, $matches);
            foreach (array_unique($matches[1] ?? []) as $helper) {
                if (isset($seen[$helper]) || ! method_exists($class, $helper)) {
                    continue;
                }
                $seen[$helper] = true;
                $helperSource = $this->methodSource($class, $helper);
                if ($helperSource === null) {
                    continue;
                }
                $bodies[] = $helperSource;
                $pending[] = $helperSource;
            }
        }

        return $bodies;
    }

    /**
     * @return array{required: array<int, string>, optional: array<int, string>}
     */
    private function classifyRoutes(): array
    {
        $required = [];
        $optional = [];

        foreach (Route::getRoutes() as $route) {
            $bodies = $this->actionBodies($route->getActionName());
            if ($bodies === null || preg_match("/header\(\s*'If-Match'/", implode("\n", $bodies)) !== 1) {
                continue;
            }

            // Bắt buộc = CÙNG một thân method vừa đọc header vừa ném 428. Kiểm
            // trên bản nối sẽ dính map `428 => 'PRECONDITION_REQUIRED'` của trait
            // ApiResponse mà mọi action đều kéo vào.
            $bucket = 'optional';
            foreach ($bodies as $body) {
                if (preg_match("/header\(\s*'If-Match'/", $body) === 1 && str_contains($body, 'PRECONDITION_REQUIRED')) {
                    $bucket = 'required';
                    break;
                }
            }
            foreach ($route->methods() as $verb) {
                if (in_array($verb, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }
                $key = strtolower($verb).' /'.ltrim($route->uri(), '/');
                if ($bucket === 'required') {
                    $required[] = $key;
                } else {
                    $optional[] = $key;
                }
            }
        }

        return ['required' => array_values(array_unique($required)), 'optional' => array_values(array_unique($optional))];
    }

    private function declaredIfMatch(array $docs, string $key): ?bool
    {
        [$verb, $uri] = explode(' ', $key, 2);
        $parameters = $docs['paths'][$uri][$verb]['parameters'] ?? null;
        if ($parameters === null) {
            return null;
        }
        foreach ($parameters as $parameter) {
            if (($parameter['name'] ?? '') === 'If-Match') {
                return (bool) ($parameter['required'] ?? false);
            }
        }

        return null;
    }

    public function test_every_operation_that_hard_requires_if_match_declares_it_required(): void
    {
        $docs = $this->openApi();
        $buckets = $this->classifyRoutes();

        $this->assertNotEmpty($buckets['required'], 'Phải có operation bắt buộc If-Match để bộ này có ý nghĩa.');

        $violations = [];
        foreach ($buckets['required'] as $key) {
            $declared = $this->declaredIfMatch($docs, $key);
            if ($declared !== true) {
                $violations[$key] = $declared === null ? 'không khai header If-Match' : 'khai required:false';
            }
        }

        $this->assertSame([], $violations, sprintf(
            "Các operation này ném 428 PRECONDITION_REQUIRED khi thiếu If-Match nhưng contract không nói vậy:\n%s",
            json_encode($violations, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        ));
    }

    public function test_operations_that_only_check_if_match_when_provided_stay_optional(): void
    {
        $docs = $this->openApi();
        $buckets = $this->classifyRoutes();

        $violations = [];
        foreach ($buckets['optional'] as $key) {
            if ($this->declaredIfMatch($docs, $key) === true) {
                $violations[] = $key;
            }
        }

        $this->assertSame([], $violations, 'Operation chỉ kiểm If-Match khi client có gửi không được khai là bắt buộc: '.implode(', ', $violations));
    }

    /** Ba action đổi tồn là nhóm chịu hard gate CORE_TRANSACTION — khoá đích danh. */
    public function test_stock_changing_actions_declare_if_match_and_idempotency_as_required(): void
    {
        $docs = $this->openApi();

        $stockChanging = [
            'post /api/v1/inbound-documents/{id}/post-receipt',
            'post /api/v1/outbound-documents/{id}/post-issue',
            'post /api/v1/reversal-documents/{id}/post',
            'post /api/v1/component-issue-documents/{id}/post',
        ];

        foreach ($stockChanging as $key) {
            [$verb, $uri] = explode(' ', $key, 2);
            $operation = $docs['paths'][$uri][$verb] ?? null;
            $this->assertNotNull($operation, "Contract phải mô tả action đổi tồn {$key}.");

            $headers = [];
            foreach ($operation['parameters'] ?? [] as $parameter) {
                if (($parameter['in'] ?? '') === 'header') {
                    $headers[$parameter['name']] = (bool) ($parameter['required'] ?? false);
                }
            }

            $this->assertArrayHasKey('If-Match', $headers, "{$key} phải khai header If-Match.");
            $this->assertTrue($headers['If-Match'], "{$key} bắt buộc If-Match lúc chạy nên contract phải required:true.");
            $this->assertArrayHasKey('Idempotency-Key', $headers, "{$key} phải khai header Idempotency-Key.");
            $this->assertTrue($headers['Idempotency-Key'], "{$key} bắt buộc Idempotency-Key.");
        }
    }

    /**
     * Chiều động: contract nói bắt buộc thì runtime phải thật sự từ chối khi
     * thiếu header — nếu không thì ta chỉ đổi tài liệu cho khớp một lời khai sai.
     */
    public function test_runtime_rejects_missing_if_match_on_component_issue_post(): void
    {
        $role = Role::create([
            'role_code' => 'SUPER_ADMIN',
            'role_name' => 'Super Admin',
            'scope_type' => 'SYSTEM',
            'status' => 'ACTIVE',
        ]);
        $user = User::create([
            'username' => 'w266h-'.Str::lower(Str::random(6)),
            'display_name' => 'WWHN-266 header fixture',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);
        $headers = ['Authorization' => 'Bearer '.auth('api')->login($user)];

        $response = $this->postJson('/api/v1/component-issue-documents/'.(string) Str::uuid().'/post', [], $headers + [
            'Idempotency-Key' => 'w266-missing-if-match',
        ]);

        $response->assertStatus(428)->assertJsonPath('error_code', 'PRECONDITION_REQUIRED');
        $this->assertNotNull($response->json('meta.request_id'));
    }
}
