<?php

namespace App\Support\Migration;

use Modules\Auth\Models\User;

/**
 * Cổng quyền của lần nạp dữ liệu cuối (WWHN-299 / DEV299-P1-02).
 *
 * Trước đây job chỉ đòi `--approved-by` là **một user ACTIVE bất kỳ**, và không
 * cấm operator tự điền chính mình vào ô approver. Như vậy trường `approved_by`
 * của ERD bảng 58 ("Data Owner/approver evidence") không chứng minh được điều gì:
 * một tài khoản không có thẩm quyền dữ liệu vẫn mở được COMMIT ghi tồn đầu.
 *
 * Lớp này đóng đúng ba lỗ hổng đó, fail-closed và trước khi tạo run:
 *   1. Approver phải giữ đủ permission Data Owner (SRS §13.12 "QL kho signs
 *      result"; ma trận §3: `warehouse.manage` chỉ Admin/QL kho được Allow).
 *   2. Separation of duty: approver không được trùng operator.
 *   3. Approval reference bên ngoài (Jira decision/biên bản ký opening balance)
 *      phải được pin vào evidence của run.
 *
 * Cấu hình ở `config/final_data_load.php` để môi trường khác đổi được tập
 * permission mà không phải sửa code.
 */
class CutoverAuthority
{
    /** @return array{id:string, username:string, permissions:list<string>} */
    public function resolveOperator(string $username): array
    {
        $actor = $this->resolveActor($username, 'OPERATOR');

        $missing = $this->missingPermissions($actor, (array) config('final_data_load.operator.required_permissions', []));
        if ($missing !== []) {
            throw new CutoverAuthorityException(
                'MIGRATION_OPERATOR_FORBIDDEN',
                sprintf('Operator "%s" thiếu permission bắt buộc: %s.', $username, implode(', ', $missing))
            );
        }

        return $actor;
    }

    /**
     * @return array{id:string, username:string, permissions:list<string>, approval_ref:string}
     */
    public function resolveApprover(string $username, string $operatorId, ?string $approvalRef): array
    {
        $actor = $this->resolveActor($username, 'APPROVER');

        $missing = $this->missingPermissions($actor, (array) config('final_data_load.approver.required_permissions', []));
        if ($missing !== []) {
            throw new CutoverAuthorityException(
                'MIGRATION_APPROVER_FORBIDDEN',
                sprintf(
                    'Approver "%s" không phải Data Owner của opening balance; thiếu permission: %s.',
                    $username,
                    implode(', ', $missing)
                )
            );
        }

        if (config('final_data_load.approver.separation_of_duty', true) && $actor['id'] === $operatorId) {
            throw new CutoverAuthorityException(
                'MIGRATION_APPROVER_SAME_AS_OPERATOR',
                'Separation of duty: người chạy cutover không được tự ký duyệt lần nạp dữ liệu cuối.'
            );
        }

        $approvalRef = trim((string) $approvalRef);
        if (config('final_data_load.approver.require_approval_ref', true) && $approvalRef === '') {
            throw new CutoverAuthorityException(
                'MIGRATION_APPROVAL_REF_REQUIRED',
                'COMMIT bắt buộc --approval-ref là mã quyết định/biên bản ký duyệt opening balance đã được duyệt.'
            );
        }
        if (mb_strlen($approvalRef) > 120) {
            throw new CutoverAuthorityException(
                'MIGRATION_APPROVAL_REF_INVALID',
                '--approval-ref tối đa 120 ký tự.'
            );
        }

        return $actor + ['approval_ref' => $approvalRef];
    }

    /** @return array{id:string, username:string, permissions:list<string>} */
    private function resolveActor(string $username, string $kind): array
    {
        $username = trim($username);
        if ($username === '') {
            throw new CutoverAuthorityException(
                "MIGRATION_{$kind}_UNKNOWN",
                $kind === 'OPERATOR'
                    ? '--executed-by bắt buộc là username của cutover operator.'
                    : 'COMMIT bắt buộc --approved-by là username của Data Owner.'
            );
        }

        $user = User::query()->where('username', $username)->where('status', 'ACTIVE')->first();
        if ($user === null) {
            throw new CutoverAuthorityException(
                "MIGRATION_{$kind}_UNKNOWN",
                sprintf('Không tìm thấy user "%s" đang ACTIVE.', $username)
            );
        }

        return [
            'id' => (string) $user->getKey(),
            'username' => $username,
            'permissions' => $user->permissionCodes(),
        ];
    }

    /**
     * @param  array{id:string, username:string, permissions:list<string>}  $actor
     * @param  array<int, string>  $required
     * @return list<string>
     */
    private function missingPermissions(array $actor, array $required): array
    {
        $required = array_values(array_filter(array_map('trim', $required)));

        return array_values(array_diff($required, $actor['permissions']));
    }
}
