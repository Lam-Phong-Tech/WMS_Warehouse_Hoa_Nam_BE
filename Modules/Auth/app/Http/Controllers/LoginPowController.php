<?php

namespace Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Auth\Support\LoginPow;

class LoginPowController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly LoginPow $pow,
    ) {}

    public function challenge(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'max:255'],
        ]);

        $challenge = $this->pow->challenge(
            (string) $validated['email'],
            (string) $request->ip(),
        );

        return $this->respondSuccess($challenge, 'Đã khởi tạo thử thách xác minh thiết bị.');
    }
}
