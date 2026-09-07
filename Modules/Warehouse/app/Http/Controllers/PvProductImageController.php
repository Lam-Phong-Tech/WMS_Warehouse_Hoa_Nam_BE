<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\WarrantyAttachmentScanner;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Modules\AuditLog\Services\AuditLogger;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * MST-PV-01 — ẢNH SẢN PHẨM APP PV: upload có xác thực, phục vụ công khai.
 *
 * Cùng lối với tệp đính kèm bảo hành (CurrentBaselineWarrantyAttachmentController):
 * client POST multipart lên API, BE kiểm nội dung THẬT rồi ghi vào object storage
 * của hệ thống (MinIO ở dev, Cloudflare R2 ở deploy — xem config/pv.php). Không
 * dùng dịch vụ ảnh bên thứ ba: ảnh sản phẩm là tài sản của Hoa Nam và phải nằm
 * cùng chỗ với mọi media khác để sao lưu/xoá theo cùng một quy trình.
 *
 * KHÁC bảo hành ở đúng một điểm: ảnh này CÔNG KHAI (App PV là mặt ẩn danh), nên
 * có thêm route đọc không cần token. Route đó chỉ phục vụ đúng prefix ảnh PV và
 * key phải khớp UUID — không có đường nào đọc sang tệp khác trong bucket.
 *
 * KHÔNG có bảng riêng: ảnh chỉ trở thành dữ liệu nghiệp vụ khi hồ sơ sản phẩm được
 * lưu (`pv_product_images` do PvAdminResourceController ghi). Tệp upload rồi bấm
 * Hủy là object mồ côi trong bucket — rẻ hơn nhiều so với việc dựng thêm một bảng
 * + vòng đời "đã upload nhưng chưa gắn" chỉ để dọn vài tấm ảnh.
 */
class PvProductImageController extends Controller
{
    use ApiResponse;

    /** Đuôi tệp hợp lệ theo MIME thật — cùng allowlist với ảnh bảo hành. */
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    /**
     * Key ảnh: `<uuid>.<ext>`. Regex này vừa là ràng buộc route vừa là chốt chặn
     * path traversal — không có dấu `/`, không có `..`, nên không cách nào ghép ra
     * key trỏ ngoài thư mục ảnh PV.
     */
    public const KEY_PATTERN = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}\.(?:jpg|jpeg|png|webp)';

    private const CONTENT_TYPES = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
    ];

    #[OA\Post(
        path: '/api/v1/pv-admin/product-images',
        operationId: 'uploadPvProductImage',
        summary: 'PV Admin — tải ảnh sản phẩm App PV lên object storage',
        description: 'Trả về URL công khai để gắn vào `images[]` của product-profiles.',
        security: [['bearerAuth' => []]],
        tags: ['12. App PV'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['file'],
                    properties: [new OA\Property(property: 'file', type: 'string', format: 'binary')]
                )
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'PvProductImage {url, storage_key, bytes, mime_type}'),
            new OA\Response(response: 403, description: 'Không có quyền pv.master.manage'),
            new OA\Response(response: 422, description: 'VALIDATION_ERROR / ATTACHMENT_CONTENT_TYPE_INVALID / ATTACHMENT_MALWARE_DETECTED'),
        ]
    )]
    public function store(Request $request)
    {
        $maxBytes = (int) config('pv.image_max_bytes', 10 * 1024 * 1024);

        $request->validate([
            'file' => ['required', 'file', 'max:'.(int) ceil($maxBytes / 1024)],
        ]);

        $file = $request->file('file');

        // Kiểm MIME THẬT (magic bytes) trước mọi thứ khác: đổi tên `.php` thành
        // `.jpg` là cách tấn công đầu tiên người ta thử với mọi ô upload, và
        // `$file->getMimeType()` suy từ phần mở rộng nên không bắt được.
        if (WarrantyAttachmentScanner::detectMediaKind($file) !== 'IMAGE') {
            ApiError::abort(422, 'ATTACHMENT_CONTENT_TYPE_INVALID', 'Ảnh sản phẩm chỉ nhận JPG/PNG/WEBP.', [
                'allowed_mime' => array_keys(WarrantyAttachmentScanner::ALLOWED_MIME_EXTENSIONS),
            ]);
        }

        ['mime' => $mime, 'extension' => $extension] = WarrantyAttachmentScanner::assertContentType($file);
        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            ApiError::abort(422, 'ATTACHMENT_CONTENT_TYPE_INVALID', 'Ảnh sản phẩm chỉ nhận JPG/PNG/WEBP.');
        }

        $scan = WarrantyAttachmentScanner::scanForMalware($file);
        if ($scan['result'] === WarrantyAttachmentScanner::RESULT_INFECTED) {
            ApiError::abort(422, 'ATTACHMENT_MALWARE_DETECTED', 'Tệp không vượt qua kiểm tra mã độc.', [
                'scan_result' => $scan['result'],
            ]);
        }

        $key = ((string) Str::uuid()).'.'.$extension;
        $prefix = trim((string) config('pv.image_prefix', 'pv-product-images'), '/');
        $disk = (string) config('pv.image_disk', 's3');

        // `putFileAs` trả false khi disk lỗi (bucket thiếu, sai credential). Không
        // kiểm là client nhận 201 kèm URL 404 và chỉ phát hiện lúc mở App PV.
        $stored = Storage::disk($disk)->putFileAs($prefix, $file, $key);
        if ($stored === false) {
            ApiError::abort(503, 'STORAGE_UNAVAILABLE', 'Không ghi được ảnh lên kho lưu trữ. Kiểm tra cấu hình bucket rồi thử lại.');
        }

        AuditLogger::record(
            action: 'PV_PRODUCT_IMAGE_UPLOADED',
            objectType: 'pv_product_image',
            objectId: $key,
            actorId: $request->user()?->user_id,
            requestId: $request->attributes->get('request_id'),
            afterData: [
                'storage_disk' => $disk,
                'storage_key' => $prefix.'/'.$key,
                'file_size_bytes' => $file->getSize(),
                'mime_type' => $mime,
                'scan_result' => $scan['result'],
                'stock_effect' => 'NONE',
            ],
        );

        return $this->respondCreated([
            'url' => self::publicUrl($key),
            'storage_key' => $prefix.'/'.$key,
            'bytes' => $file->getSize(),
            'mime_type' => $mime,
        ], 'Tải ảnh sản phẩm thành công.');
    }

    #[OA\Get(
        path: '/api/v1/pv/product-images/{key}',
        operationId: 'showPvProductImage',
        summary: 'App PV — ảnh sản phẩm (ẩn danh)',
        security: [],
        tags: ['12. App PV'],
        parameters: [
            new OA\Parameter(name: 'key', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Nội dung ảnh'),
            new OA\Response(response: 404, description: 'Không tìm thấy ảnh'),
        ]
    )]
    public function show(string $key): StreamedResponse|Response
    {
        // Ràng buộc route đã lọc, nhưng kiểm lại ở đây để hàm không phụ thuộc vào
        // nơi khai báo route — key là thành phần duy nhất đi vào đường dẫn tệp.
        if (preg_match('/^'.self::KEY_PATTERN.'$/', $key) !== 1) {
            abort(404);
        }

        $disk = (string) config('pv.image_disk', 's3');
        $prefix = trim((string) config('pv.image_prefix', 'pv-product-images'), '/');
        $path = $prefix.'/'.$key;

        if (! Storage::disk($disk)->exists($path)) {
            abort(404);
        }

        $extension = strtolower((string) pathinfo($key, PATHINFO_EXTENSION));

        // KHÔNG đặt Cache-Control ở đây: `NoStoreApiResponses` chạy ở lớp ngoài
        // cùng và ép 'no-store, private' cho MỌI response `/api/*` (WWHN-325 —
        // payload API có thể chứa phiên đăng nhập/PII/dữ liệu vận hành). Đặt
        // header rồi bị ghi đè chỉ tạo ra một dòng code nói dối về runtime.
        //
        // Hệ quả: ảnh phục vụ qua đường này KHÔNG được trình duyệt cache. Chấp
        // nhận được cho màn quản trị và catalog cỡ vừa. Muốn cache/CDN thật thì
        // mở bucket ra domain công khai rồi đặt `PV_IMAGE_PUBLIC_BASE` — khi đó
        // ảnh đi thẳng từ R2, không qua PHP và không qua middleware này.
        return Storage::disk($disk)->response($path, $key, [
            'Content-Type' => self::CONTENT_TYPES[$extension] ?? 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * URL công khai của một ảnh.
     *
     * Mặc định trỏ về chính API (bucket giữ private). Đặt `PV_IMAGE_PUBLIC_BASE`
     * khi bucket đã có domain công khai thì ảnh MỚI đi thẳng CDN; ảnh cũ vẫn chạy
     * vì URL đã nằm trong `pv_product_images.image_url` và không được viết lại.
     */
    public static function publicUrl(string $key): string
    {
        $base = (string) config('pv.image_public_base', '');
        if ($base !== '') {
            return $base.'/'.trim((string) config('pv.image_prefix', 'pv-product-images'), '/').'/'.$key;
        }

        return URL::to('/api/v1/pv/product-images/'.$key);
    }
}
