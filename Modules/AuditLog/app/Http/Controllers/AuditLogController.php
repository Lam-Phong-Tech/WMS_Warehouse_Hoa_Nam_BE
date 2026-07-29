<?php

namespace Modules\AuditLog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Modules\AuditLog\Repositories\Contracts\AuditLogRepositoryInterface;

class AuditLogController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly AuditLogRepositoryInterface $auditLogs) {}

    public function index(Request $request)
    {
        return $this->respondPaginated(
            $this->auditLogs->paginate($request->all(), (int) $request->input('per_page', 15))
        );
    }
}
