<?php

namespace Modules\Auth\Support;

use App\Support\ApiError;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Self-service preferences never grant module access or change account policy. */
final class ProfilePreferences
{
    public const CATEGORIES = ['stock_documents', 'inventory', 'warranty', 'account'];

    public static function defaults(): array
    {
        return ['locale' => 'vi', 'timezone' => 'Asia/Ho_Chi_Minh', 'date_format' => 'DD/MM/YYYY',
            'default_homepage' => 'dashboard', 'theme' => 'system', 'accent' => 'ocean',
            'font_size' => 'medium', 'density' => 'comfortable', 'reduced_motion' => false,
            'notifications' => array_fill_keys(self::CATEGORIES, ['in_app' => true, 'email' => false])];
    }

    public static function read(string $user): array
    {
        $row = DB::table('user_profile_preferences')->where('user_id', $user)->first();

        return ['version' => (int) ($row->version ?? 1),
            ...array_replace_recursive(self::defaults(), $row === null ? [] : json_decode($row->preferences, true, 512, JSON_THROW_ON_ERROR))];
    }

    public static function validate(array $body): array
    {
        if ($body === [] || array_diff(array_keys($body), array_keys(self::defaults())) !== []) {
            ApiError::abort(422, 'PREFERENCE_FIELD_NOT_EDITABLE', 'Chỉ gửi các tùy chọn cá nhân được hỗ trợ.');
        }
        $rules = [
            'locale' => ['sometimes', 'required', Rule::in(['vi', 'en', 'zh'])],
            'timezone' => ['sometimes', 'required', 'string', Rule::in(\DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC))],
            'date_format' => ['sometimes', 'required', Rule::in(['DD/MM/YYYY', 'MM/DD/YYYY', 'YYYY-MM-DD'])],
            'default_homepage' => ['sometimes', 'required', Rule::in(['dashboard', 'inbound', 'outbound', 'inventory'])],
            'theme' => ['sometimes', 'required', Rule::in(['light', 'dark', 'system'])],
            'accent' => ['sometimes', 'required', Rule::in(['ocean', 'teal', 'indigo', 'graphite'])],
            'font_size' => ['sometimes', 'required', Rule::in(['small', 'medium', 'large'])],
            'density' => ['sometimes', 'required', Rule::in(['comfortable', 'compact'])],
            'reduced_motion' => ['sometimes', 'required', 'boolean'],
            'notifications' => ['sometimes', 'required', 'array:'.implode(',', self::CATEGORIES)],
        ];
        foreach (self::CATEGORIES as $category) {
            $rules['notifications.'.$category] = ['sometimes', 'required', 'array:in_app,email'];
            $rules['notifications.'.$category.'.in_app'] = ['sometimes', 'required', 'boolean'];
            $rules['notifications.'.$category.'.email'] = ['sometimes', 'required', 'boolean'];
        }
        $data = Validator::make($body, $rules)->validate();
        if (isset($data['notifications']['account']['in_app']) && ! $data['notifications']['account']['in_app']) {
            ApiError::abort(422, 'ACCOUNT_NOTIFICATION_REQUIRED', 'Thông báo tài khoản và bảo mật trong ứng dụng là bắt buộc.');
        }
        // Laravel accepts 0/1 as booleans; publish one canonical JSON boolean type.
        if (array_key_exists('reduced_motion', $data)) {
            $data['reduced_motion'] = (bool) $data['reduced_motion'];
        }
        foreach ($data['notifications'] ?? [] as $category => $channels) {
            foreach ($channels as $channel => $value) {
                $data['notifications'][$category][$channel] = (bool) $value;
            }
        }

        return $data;
    }

    /** Caller holds the parent user lock, just like profile and session commands. */
    public static function put(string $user, array $changes, int $expected): array
    {
        $current = self::read($user);
        if ($current['version'] !== $expected) {
            ApiError::abort(412, 'STALE_VERSION', 'Tùy chọn đã thay đổi; đọc lại trước khi lưu.', ['current_version' => $current['version']]);
        }
        unset($current['version']);
        $preferences = array_replace_recursive($current, $changes);
        DB::table('user_profile_preferences')->updateOrInsert(['user_id' => $user], [
            'version' => $expected + 1, 'preferences' => json_encode($preferences, JSON_THROW_ON_ERROR), 'updated_at' => now('UTC'),
        ]);

        return ['version' => $expected + 1, ...$preferences];
    }

    public static function category(string $event, string $module): ?string
    {
        return match (true) {
            str_starts_with($event, 'inbound.'), str_starts_with($event, 'outbound.') => 'stock_documents',
            str_starts_with($event, 'stock.'), str_starts_with($event, 'reversal.'), $module === 'inventory' => 'inventory',
            str_starts_with($event, 'warranty.'), $module === 'warranty', $module === 'component' => 'warranty',
            str_starts_with($event, 'user.'), str_starts_with($event, 'role.'), str_starts_with($event, 'security.'), $module === 'auth' => 'account',
            default => null,
        };
    }
}
