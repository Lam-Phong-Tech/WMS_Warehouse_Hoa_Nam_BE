<?php

namespace App\Swagger;

use OpenApi\Attributes as OA;

/** Candidate is OFF by default; these routes cannot authorize replay or WMS takeover. */
final class P11SecurityCommandsOpenApi
{
    #[OA\Get(
        path: '/api/v1/auth/security-requests/{key}', operationId: 'p11SecurityRequestRead',
        summary: 'Doi chieu lenh bao mat theo key goc',
        description: 'p11-security-command-v1, default OFF. Original active family can read its receipt. Another active family needs fresh password plus the original pre-existing MFA binding and a nonexpired DB assurance. Revoked tokens cannot read. Missing/uncertain outcome is UNKNOWN/CHECK_ONLY, never proof of rollback or permission to replay. No query/body. See docs/contracts/p11-security-command-v1.json.',
        security: [['bearerAuth' => []]], tags: ['01. Authentication'],
        parameters: [
            new OA\Parameter(name: 'key', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'X-Security-Command-Contract', in: 'header', required: true, schema: new OA\Schema(type: 'string', enum: ['p11-security-command-v1'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Known receipt or UNKNOWN with CHECK_ONLY; no null/default facts'),
            new OA\Response(response: 401, description: 'Unauthenticated, expired or revoked/replaced family'),
            new OA\Response(response: 403, description: 'Owner/MFA assurance denied or expired'),
            new OA\Response(response: 409, description: 'Original MFA source unavailable/changed; audited support required'),
            new OA\Response(response: 422, description: 'Invalid key or unexpected body/query'),
            new OA\Response(response: 428, description: 'Explicit contract header required'),
            new OA\Response(response: 503, description: 'Candidate disabled or schema unavailable'),
        ]
    )]
    public function read(): void {}

    #[OA\Post(
        path: '/api/v1/auth/security-requests/{key}/verify', operationId: 'p11SecurityRequestStepUp',
        summary: 'Xac minh bo sung de doc receipt bao mat cua chinh minh',
        description: 'Read-authority verification only, not original command replay. Fresh current_password and TOTP/one-use recovery code from the factor bound at original dispatch. Assurance expires after 300 seconds, is bound to actor/current family/original key/factor/credential epoch, and never authorizes a WMS journal takeover. No mail/OTP fallback.',
        security: [['bearerAuth' => []]], tags: ['01. Authentication'],
        parameters: [
            new OA\Parameter(name: 'key', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'X-Security-Command-Contract', in: 'header', required: true, schema: new OA\Schema(type: 'string', enum: ['p11-security-command-v1'])),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['current_password', 'code'], properties: [
            new OA\Property(property: 'current_password', type: 'string', maxLength: 255),
            new OA\Property(property: 'code', type: 'string', maxLength: 100),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Verified read-only assurance; original command not replayed'),
            new OA\Response(response: 401, description: 'Unauthenticated/revoked family'),
            new OA\Response(response: 403, description: 'Wrong password/factor, changed binding or old request lacks durable identity'),
            new OA\Response(response: 422, description: 'Unexpected/invalid fields'),
            new OA\Response(response: 429, description: 'MFA/account/IP rate limit'),
            new OA\Response(response: 503, description: 'Candidate disabled/schema unavailable'),
        ]
    )]
    public function verify(): void {}
}
