<?php

namespace Tests;

/**
 * Mật khẩu dùng trong bộ test — CHỈ cho CSDL test dùng một lần.
 *
 * Trước 10/09/2026 bộ test đăng nhập bằng đúng mật khẩu canonical của môi trường thật,
 * nên mỗi file test là thêm một chỗ "công bố" credential đó. Giá trị dưới đây khớp
 * `WMS_FIXTURE_PASSWORD` trong phpunit.xml / phpunit.pgsql.xml — seeder dùng nó cho tài
 * khoản fixture ở môi trường testing; AuthLoginContractSecurityTest khoá hai nơi này
 * không được lệch nhau.
 */
final class TestCredentials
{
    public const PASSWORD = 'Fixture#Only-Test-2026';
}
