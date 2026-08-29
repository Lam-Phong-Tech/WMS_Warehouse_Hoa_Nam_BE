<?php

namespace Modules\Warehouse\Http\Controllers\PublicApi\Concerns;

use App\Support\PublicApiError;
use App\Support\PublicApiErrorCode;
use Illuminate\Http\Request;
use Modules\Warehouse\Services\PublicCatalogReadModel;

/**
 * Đọc tham số truy vấn của nhóm `/api/v1/public/*` TỪ QUERY STRING THÔ.
 *
 * Bắt buộc phải tự parse, không dùng được $request->query(), vì hợp đồng FE có
 * hai dạng tham số mà PHP làm hỏng trước khi code chạm tới:
 *
 * 1. `spec.<code>` — PHP đổi dấu chấm trong TÊN tham số thành gạch dưới khi
 *    dựng $_GET, nên `?spec.power=750W` vào tới ứng dụng thành `spec_power`.
 *    Còn $request->query('spec.power') lại hiểu dấu chấm là truy cập mảng lồng
 *    nhau, cũng không thấy. Cả hai đường đều trả null trong im lặng — bộ lọc
 *    coi như không tồn tại mà không ai báo lỗi.
 *
 * 2. `feature` lặp nhiều lần — `?feature=A&feature=B` không có `[]`, PHP chỉ
 *    giữ giá trị CUỐI. Hợp đồng nói rõ "feature có thể lặp nhiều lần", nên mất
 *    giá trị đầu là sai kết quả lọc.
 *
 * Vì vậy mọi tham số đọc qua rawQuery(): tách trên `&`, decode từng vế, giữ
 * nguyên dấu chấm và gom mọi lần lặp.
 */
trait ParsesPublicQuery
{
    /**
     * Khoá memo hoá đặt trên CHÍNH REQUEST, không phải trên thuộc tính của
     * controller. Laravel giữ lại instance controller bên trong đối tượng Route,
     * và đối tượng đó sống suốt vòng đời ứng dụng — nên một thuộc tính cấp
     * instance sẽ mang query string của request TRƯỚC sang request SAU. Trên
     * php-fpm mỗi request là một tiến trình riêng nên lỗi này vô hình; nó chỉ lộ
     * ra khi nhiều request dùng chung một tiến trình (bộ test, và Octane/Swoole
     * nếu về sau triển khai như vậy). Gắn vào attributes của request thì phạm vi
     * đúng bằng một request, theo đúng định nghĩa.
     */
    private const RAW_QUERY_ATTRIBUTE = 'public_api.raw_query';

    /**
     * @return array<string, array<int, string>>
     */
    protected function rawQuery(Request $request): array
    {
        $cached = $request->attributes->get(self::RAW_QUERY_ATTRIBUTE);
        if (is_array($cached)) {
            return $cached;
        }

        $queryString = (string) $request->server->get('QUERY_STRING', '');
        $params = [];

        foreach (explode('&', $queryString) as $pair) {
            if ($pair === '') {
                continue;
            }

            $parts = explode('=', $pair, 2);
            $name = urldecode($parts[0]);
            $value = isset($parts[1]) ? urldecode($parts[1]) : '';

            if ($name === '') {
                continue;
            }

            // Đọc query string THÔ tức là đi vòng qua guard UTF-8 của
            // ApiConventionMiddleware (guard đó chỉ soát $request->query->all()).
            // Byte hỏng lọt tới đây sẽ nổ ở json_encode lúc dựng response và
            // biến một lỗi tham số 400 thành 500. Chặn ngay tại cửa, và KHÔNG
            // in lại byte hỏng ra thông báo — đó chính là thứ vừa làm vỡ encode.
            if (! mb_check_encoding($name, 'UTF-8') || ! mb_check_encoding($value, 'UTF-8')) {
                PublicApiError::invalidQuery('Tham số truy vấn chứa ký tự không hợp lệ (không phải UTF-8).');
            }

            // Client vẫn có thể gửi kiểu mảng PHP (`feature[]=A`); chuẩn hoá về
            // cùng một tên để hai cách viết cho cùng kết quả.
            $name = preg_replace('/\[\]$/', '', $name) ?? $name;

            $params[$name][] = $value;
        }

        $request->attributes->set(self::RAW_QUERY_ATTRIBUTE, $params);

        return $params;
    }

    protected function stringParam(Request $request, string $name): ?string
    {
        $values = $this->rawQuery($request)[$name] ?? [];
        if ($values === []) {
            return null;
        }

        $value = trim((string) end($values));

        return $value === '' ? null : $value;
    }

    /**
     * @return array<int, string>
     */
    protected function listParam(Request $request, string $name): array
    {
        $values = $this->rawQuery($request)[$name] ?? [];

        $normalized = [];
        foreach ($values as $value) {
            // Chấp nhận cả `feature=A&feature=B` lẫn `feature=A,B` — FE nào cũng
            // gửi được mà không cần hỏi lại BE.
            foreach (explode(',', $value) as $piece) {
                $piece = trim($piece);
                if ($piece !== '') {
                    $normalized[] = $piece;
                }
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * Chỉ nhận `spec.<code>` có trong allowlist config. Khoá lạ bị từ chối
     * TƯỜNG MINH bằng 400 chứ không bỏ qua im lặng: bỏ qua im lặng sẽ trả về
     * kết quả rộng hơn người dùng tưởng, và họ không có cách nào biết.
     *
     * @return array<string, string>
     */
    protected function specParams(Request $request): array
    {
        $allowed = (array) config('public_api.spec_facets', []);
        $specs = [];

        foreach ($this->rawQuery($request) as $name => $values) {
            if (! str_starts_with($name, 'spec.')) {
                continue;
            }

            $code = substr($name, 5);
            if ($code === '' || ! array_key_exists($code, $allowed)) {
                // Không dội nguyên văn $code vào message: đó là chuỗi do client
                // kiểm soát, và message này đi thẳng vào UI. Danh sách khoá hợp
                // lệ nằm ở `errors` là đủ để FE sửa.
                PublicApiError::invalidQuery(
                    'Bộ lọc spec không được hỗ trợ.',
                    ['spec' => array_keys($allowed)]
                );
            }

            $value = trim((string) end($values));
            if ($value !== '') {
                $specs[$code] = $value;
            }
        }

        return $specs;
    }

    protected function sortParam(Request $request): string
    {
        $sort = $this->stringParam($request, 'sort');
        if ($sort === null) {
            return PublicCatalogReadModel::SORT_FEATURED;
        }

        if (! in_array($sort, PublicCatalogReadModel::SORTS, true)) {
            PublicApiError::abort(
                400,
                PublicApiErrorCode::INVALID_SORT,
                'Giá trị sort không hợp lệ.',
                ['sort' => PublicCatalogReadModel::SORTS]
            );
        }

        return $sort;
    }

    /**
     * Vượt trần là LỖI, không phải âm thầm cắt xuống trần: client cắt ngầm sẽ
     * tưởng đã nhận đủ trang và dừng cuộn sớm.
     */
    protected function limitParam(Request $request): int
    {
        $max = max(1, (int) config('public_api.max_limit', 50));
        $raw = $this->stringParam($request, 'limit');

        if ($raw === null) {
            return min($max, max(1, (int) config('public_api.default_limit', 20)));
        }

        if (! ctype_digit($raw) || (int) $raw < 1) {
            PublicApiError::invalidQuery('limit phải là số nguyên dương.');
        }

        if ((int) $raw > $max) {
            PublicApiError::invalidQuery("limit tối đa là {$max}.");
        }

        return (int) $raw;
    }

    protected function domainParam(Request $request): ?string
    {
        $domain = $this->stringParam($request, 'domain');
        if ($domain === null) {
            return null;
        }

        $domains = array_keys((array) config('public_api.domains', []));
        if (! in_array($domain, $domains, true)) {
            PublicApiError::abort(
                400,
                PublicApiErrorCode::INVALID_DOMAIN,
                'Giá trị domain không hợp lệ.',
                ['domain' => $domains]
            );
        }

        return $domain;
    }

    /**
     * @return array<string, mixed>
     */
    protected function catalogFilters(Request $request): array
    {
        return array_filter([
            'q' => $this->stringParam($request, 'q'),
            'domain' => $this->domainParam($request),
            'category' => $this->stringParam($request, 'category'),
            'power_source' => $this->stringParam($request, 'power_source'),
            'feature' => $this->listParam($request, 'feature'),
            'spec' => $this->specParams($request),
        ], static fn ($value): bool => $value !== null && $value !== '' && $value !== []);
    }
}
