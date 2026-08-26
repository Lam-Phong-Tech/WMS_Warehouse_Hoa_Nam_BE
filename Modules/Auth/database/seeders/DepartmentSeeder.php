<?php

namespace Modules\Auth\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Auth\Models\Department;

/**
 * GĐ1-REQ-011: phòng ban master gán cho user trong IAM — hiển thị ở cột
 * "Phòng ban" của màn "Danh sách người dùng & phân quyền".
 */
class DepartmentSeeder extends Seeder
{
    private const DEPARTMENTS = [
        [
            'code' => 'KINH_DOANH',
            'name' => 'Phòng kinh doanh',
            'description' => 'Phụ trách bán hàng, chăm sóc khách hàng và đơn hàng đầu ra.',
        ],
        [
            'code' => 'BAO_HANH',
            'name' => 'Phòng bảo hành',
            'description' => 'Phụ trách tiếp nhận, xử lý case bảo hành và xuất linh kiện thay thế.',
        ],
        [
            'code' => 'QUAN_LY_KHO',
            'name' => 'Phòng quản lý kho',
            'description' => 'Phụ trách nhập kho, xuất kho, kiểm kê và vận hành kho vật lý.',
        ],
    ];

    public function run(): void
    {
        foreach (self::DEPARTMENTS as $department) {
            Department::updateOrCreate(
                ['department_code' => $department['code']],
                [
                    'department_name' => $department['name'],
                    'status' => 'ACTIVE',
                    'description' => $department['description'],
                ]
            );
        }
    }
}
