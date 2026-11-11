<?php

namespace App\Swagger;

use OpenApi\Attributes as OA;

#[OA\Schema(schema: 'P05SaveNumberingCapability', type: 'object', description: 'data.numbering on mini-app-p05-v1 context. Opt-in P05 manual create only; selected Web sources retain their doc_no. No GET allocates a number and SAVE creates no WMS document or stock reservation.', properties: [
    new OA\Property(property: 'contract', type: 'string', enum: ['p05-save-numbering-v1']),
    new OA\Property(property: 'revision', type: 'string', enum: ['p05-save-numbering-r1']),
    new OA\Property(property: 'mode', type: 'string', enum: ['MANUAL_CREATE_ONLY']),
    new OA\Property(property: 'schema_ready', type: 'boolean'),
    new OA\Property(property: 'allowed', type: 'boolean', description: 'Actual active scoped grants, never a role-name bypass.'),
    new OA\Property(property: 'format', type: 'string', enum: ['PX-YYYYMMDDHHmmss']),
    new OA\Property(property: 'timezone', type: 'string', enum: ['Asia/Ho_Chi_Minh']),
    new OA\Property(property: 'cancel_policy', type: 'string', enum: ['RETAIN_NEVER_REUSE']),
    new OA\Property(property: 'source_linked_policy', type: 'string', enum: ['KEEP_SOURCE_DOCUMENT_NO']),
])]
#[OA\Schema(schema: 'P05NumberedStagingExtension', type: 'object', description: 'Additive fields of a P03 staging draft in p05-save-numbering-v1 representation. SAVE response: data.document.*; detail: data.*; reconcile current state: data.document.*. Numbered SAVE and durable original response commit atomically. Nested form object/array shapes stay unchanged. RECORD returns the same number at data.doc_no, without fabricating a name or posting stock.', properties: [
    new OA\Property(property: 'document_no', type: 'string', pattern: '^PX-[0-9]{14}$', nullable: true, description: 'Official BE reservation. Null only for a legacy unnumbered draft/original receipt; requires a new explicit SAVE with current version and resolved owner intent.'),
    new OA\Property(property: 'numbering_status', type: 'string', enum: ['ASSIGNED', 'AWAITING_EXPLICIT_SAVE']),
])]
final class P05SaveNumberingSchemas {}
