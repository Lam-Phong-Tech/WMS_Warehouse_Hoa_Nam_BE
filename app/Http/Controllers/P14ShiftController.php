<?php

namespace App\Http\Controllers;

use App\Support\P14ShiftService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class P14ShiftController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly P14ShiftService $shifts) {}

    public function check(Request $request, string $shiftId): JsonResponse
    {
        $data = $this->shifts->check($request, strtolower($shiftId));

        return $this->reply($data)->header('ETag', '"'.$data['expected_version'].'"');
    }

    public function end(Request $request, string $shiftId): JsonResponse
    {
        $result = $this->shifts->end($request, strtolower($shiftId));

        return $this->reply($result['receipt'])->header('Idempotent-Replay', $result['replayed'] ? 'true' : 'false')
            ->header('ETag', '"'.$result['receipt']['version'].'"');
    }

    public function reconcile(Request $request, string $shiftId, string $requestId): JsonResponse
    {
        return $this->reply($this->shifts->reconcile($request, strtolower($shiftId), $requestId));
    }

    public function summary(Request $request, string $shiftId): JsonResponse
    {
        return $this->reply($this->shifts->summary($request, strtolower($shiftId)));
    }

    private function reply(array $data): JsonResponse
    {
        $response = $this->respondSuccess($data);
        $body = $response->getData(true);
        $body['meta']['contract'] = P14ShiftService::CONTRACT;

        return $response->setData($body)->header('Cache-Control', 'private, no-store');
    }
}
