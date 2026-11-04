<?php

namespace Modules\Auth\Support;

use App\Support\ApiError;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class ProfileAvatar
{
    public static function metadata(string $user): ?array
    {
        $row = DB::table('user_profile_avatars')->where('user_id', $user)->first(['mime_type', 'size', 'updated_at']);

        return $row === null ? null : ['mime_type' => $row->mime_type, 'size' => (int) $row->size,
            'updated_at' => Carbon::parse($row->updated_at, 'UTC')->toIso8601String(), 'url' => '/api/v1/auth/me/avatar'];
    }

    /** Decode and re-encode supported raster images to strip EXIF and trailing content. */
    public static function image(UploadedFile $file): array
    {
        if (! function_exists('imagecreatefromstring')) {
            ApiError::abort(503, 'AVATAR_PROCESSING_UNAVAILABLE', 'Dịch vụ xử lý ảnh chưa sẵn sàng. Vui lòng thử lại sau.');
        }
        $bytes = file_get_contents($file->getRealPath());
        $info = @getimagesizefromstring($bytes);
        if ($info === false || $info[0] > 4096 || $info[1] > 4096 || ! in_array($info['mime'], ['image/png', 'image/jpeg', 'image/webp'], true)) {
            ApiError::abort(422, 'INVALID_AVATAR', 'Chọn ảnh PNG, JPEG hoặc WebP có kích thước tối đa 4096 × 4096.');
        }
        $writer = match ($info['mime']) {
            'image/jpeg' => 'imagejpeg', 'image/webp' => 'imagewebp', default => 'imagepng',
        };
        if (! function_exists($writer)) {
            ApiError::abort(503, 'AVATAR_PROCESSING_UNAVAILABLE', 'Dịch vụ xử lý định dạng ảnh này chưa sẵn sàng. Vui lòng thử lại sau.');
        }
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            ApiError::abort(422, 'INVALID_AVATAR', 'Không thể đọc ảnh đã chọn.');
        }
        try {
            imagealphablending($image, false);
            imagesavealpha($image, true);
            ob_start();
            try {
                $ok = match ($info['mime']) {
                    'image/jpeg' => imagejpeg($image, null, 90),
                    'image/webp' => imagewebp($image, null, 90),
                    default => imagepng($image),
                };
                $encoded = ob_get_contents();
            } finally {
                ob_end_clean();
            }
        } finally {
            imagedestroy($image);
        }
        if (! $ok || $encoded === '' || strlen($encoded) > 2 * 1024 * 1024) {
            ApiError::abort(422, 'INVALID_AVATAR', 'Ảnh sau xử lý phải nhỏ hơn hoặc bằng 2 MiB.');
        }

        return ['mime_type' => $info['mime'], 'size' => strlen($encoded), 'image_base64' => base64_encode($encoded)];
    }
}
