<?php

namespace Tests\Feature;

use Tests\TestCase;

class VietnameseValidationEnvelopeTest extends TestCase
{
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
