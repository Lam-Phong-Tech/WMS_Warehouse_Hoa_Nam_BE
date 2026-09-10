<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Database\Seeders\AuthDatabaseSeeder;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Tests\TestCase;
use Tests\TestCredentials;
use Tymon\JWTAuth\Facades\JWTAuth;

class VietnameseValidationEnvelopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_form_request_validation_keeps_error_contract_and_returns_vietnamese_messages(): void
    {
        $response = $this->postJson('/api/v1/auth/login', []);

        $response
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'BUSINESS_RULE_ERROR')
            ->assertJsonPath('error_code', 'BUSINESS_RULE_ERROR')
            ->assertJsonPath('message', 'Dữ liệu gửi lên không hợp lệ.');

        $messages = $this->flattenStrings($response->json('errors', []));
        $this->assertNotEmpty($messages);

        foreach ($messages as $message) {
            $this->assertDoesNotMatchRegularExpression(
                '/\b(the|field|must|required|invalid|prohibits)\b/i',
                $message,
                "Validation message còn tiếng Anh: {$message}"
            );
        }
    }

    /**
     * Thông báo lỗi phải nói rõ chuyện gì xảy ra ở ô nào, không được lùi về
     * kiểu mô tả cấu trúc dữ liệu ("Giá trị trường email đã tồn tại", "Trường
     * display_name là bắt buộc") — người dùng cuối không hiểu khái niệm
     * "trường" lẫn tên field snake_case của API.
     */
    public function test_validation_messages_are_written_for_end_users_not_for_developers(): void
    {
        $this->seed(AuthDatabaseSeeder::class);

        $existing = User::create([
            'username' => 'nguoidungcu',
            'display_name' => 'Nguoi Dung Cu',
            'email' => 'nguoidungcu@gmail.com',
            'password_hash' => Hash::make(TestCredentials::PASSWORD),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);

        $response = $this->postJson('/api/v1/users', [
            'username' => 'nguoidungmoi',
            'email' => $existing->email,
            'phone' => '123',
            'password' => 'abc',
        ], ['Authorization' => 'Bearer '.JWTAuth::fromUser($this->superAdmin())]);

        $response->assertStatus(422)
            // Email trùng: phải nói rõ email đã có người dùng, kèm hướng xử lý.
            ->assertJsonPath('errors.email.0', 'Email này đã được dùng cho một tài khoản khác. Vui lòng nhập email khác.')
            // Thiếu bắt buộc: gọi đúng tên tiếng Việt của ô, không phải "display_name".
            ->assertJsonPath('errors.display_name.0', 'Vui lòng nhập họ và tên.')
            ->assertJsonPath('errors.phone.0', 'Số điện thoại phải gồm đúng 10 chữ số và bắt đầu bằng số 0.')
            ->assertJsonPath('errors.password.0', 'Mật khẩu phải có ít nhất 8 ký tự.');

        foreach ($this->flattenStrings($response->json('errors', [])) as $message) {
            $this->assertStringNotContainsString(
                'Giá trị trường',
                $message,
                "Thông báo lỗi lùi về kiểu mô tả cấu trúc dữ liệu: {$message}"
            );
            $this->assertDoesNotMatchRegularExpression(
                '/\b[a-z]+_[a-z_]+\b/',
                $message,
                "Thông báo lỗi lộ tên field snake_case của API ra UI: {$message}"
            );
        }
    }

    private function superAdmin(): User
    {
        $role = Role::where('role_code', 'SUPER_ADMIN')->firstOrFail();
        $admin = User::create([
            'username' => 'admin-kiem-tra-message',
            'display_name' => 'Admin Kiem Tra',
            'email' => 'admin-kiem-tra@gmail.com',
            'password_hash' => Hash::make(TestCredentials::PASSWORD),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $admin->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);

        return $admin;
    }

    /** @return list<string> */
    private function flattenStrings(mixed $value): array
    {
        if (is_string($value)) {
            return [$value];
        }
        if (! is_array($value)) {
            return [];
        }

        $messages = [];
        foreach ($value as $child) {
            array_push($messages, ...$this->flattenStrings($child));
        }

        return $messages;
    }
}
