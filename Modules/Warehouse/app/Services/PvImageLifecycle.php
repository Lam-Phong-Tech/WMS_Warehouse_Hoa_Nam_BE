<?php

namespace Modules\Warehouse\Services;

use App\Support\ApiError;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\LazyCollection;
use Modules\Warehouse\Http\Controllers\PvProductImageController;

/** Pending uploads are separate from product images; claiming is part of the product transaction. */
class PvImageLifecycle
{
    public function register(string $disk, string $path, string $url): void
    {
        DB::table('pv_image_uploads')->insertOrIgnore($this->pending($disk, $path, $url, now()->addHours($this->graceHours())));
    }

    /** Must run inside the same transaction as writing pv_product_images. */
    public function claim(string $url): void
    {
        $record = DB::table('pv_image_uploads')->where('public_url', $url)->first();
        if (! $record) {
            $key = basename((string) parse_url($url, PHP_URL_PATH));
            if (! $this->validKey($key) || $url !== PvProductImageController::publicUrl($key)) {
                return; // An external/legacy CDN URL is not owned by this upload lifecycle.
            }
            $disk = (string) config('pv.image_disk', 's3');
            $path = trim((string) config('pv.image_prefix', 'pv-product-images'), '/').'/'.$key;
            $data = $this->pending($disk, $path, $url, now()->addHours($this->graceHours()));
            DB::table('pv_image_uploads')->insertOrIgnore($data);
            $id = $data['id'];
        } else {
            $id = $record->id;
        }
        // Same row lock as prune: a saved reference cannot race with physical deletion.
        $record = DB::table('pv_image_uploads')->where('id', $id)->lockForUpdate()->first();
        if (! $record || ! Storage::disk($record->disk)->exists($record->storage_key)) {
            ApiError::abort(422, 'IMAGE_UPLOAD_EXPIRED', 'Ảnh tạm đã hết hạn hoặc không còn tồn tại. Vui lòng thêm lại ảnh rồi lưu sản phẩm.');
        }
        DB::table('pv_image_uploads')->where('id', $id)->update(['claimed_at' => now(), 'expires_at' => null]);
    }

    public function prune(bool $dryRun = false, int $limit = 500): int
    {
        $count = $this->discoverLegacyUploads($dryRun, $limit);
        $ids = DB::table('pv_image_uploads')->whereNull('claimed_at')->where('expires_at', '<=', now())
            ->orderBy('expires_at')->limit($limit - $count)->pluck('id');
        foreach ($ids as $id) {
            $count += DB::transaction(function () use ($id, $dryRun): int {
                $record = DB::table('pv_image_uploads')->where('id', $id)->lockForUpdate()->first();
                if (! $record || $record->claimed_at !== null || $record->expires_at === null || $record->expires_at > now()->toDateTimeString()) {
                    return 0;
                }
                $key = basename($record->storage_key);
                if (! $this->validKey($key)) {
                    throw new \RuntimeException('Invalid PV image storage key; cleanup stopped.');
                }
                // Check both canonical and compatibility columns, all statuses (including hidden products).
                if ($this->referenced($key)) {
                    if (! $dryRun) {
                        DB::table('pv_image_uploads')->where('id', $id)->update(['claimed_at' => now(), 'expires_at' => null]);
                    }

                    return 0;
                }
                if (! $dryRun) {
                    $storage = Storage::disk($record->disk);
                    if ($storage->exists($record->storage_key) && ! $storage->delete($record->storage_key)) {
                        throw new \RuntimeException('Could not delete pending PV image; retry on next cleanup.');
                    }
                    DB::table('pv_image_uploads')->where('id', $id)->delete();
                }

                return 1;
            });
        }

        return $count;
    }

    /** Old uploads had no registry: enroll only UUID image files in the dedicated PV prefix. */
    private function discoverLegacyUploads(bool $dryRun, int $limit): int
    {
        $disk = (string) config('pv.image_disk', 's3');
        $prefix = trim((string) config('pv.image_prefix', 'pv-product-images'), '/');
        if ($prefix === '') {
            throw new \RuntimeException('PV image prefix must not be empty.');
        }
        $storage = Storage::disk($disk);
        $cutoff = now()->subHours($this->graceHours())->getTimestamp();
        $enrolled = 0;
        $items = LazyCollection::make(function () use ($storage, $prefix) {
            yield from $storage->getDriver()->listContents($prefix, false);
        })->filter(fn ($item) => $item->isFile() && $item->path() === $prefix.'/'.basename($item->path()) && $this->validKey(basename($item->path())));
        // Batch registry lookups; don't issue one query for every previously saved image.
        foreach ($items->chunk(250) as $batch) {
            $ids = $batch->map(fn ($item) => $this->id($disk, $item->path()))->all();
            $known = array_fill_keys(DB::table('pv_image_uploads')->whereIn('id', $ids)->pluck('id')->all(), true);
            foreach ($batch as $item) {
                $path = $item->path();
                $key = basename($path);
                if (isset($known[$this->id($disk, $path)])) {
                    continue;
                }
                $modified = $item->lastModified() ?? $storage->lastModified($path);
                if ($modified > $cutoff || ($dryRun && $this->referenced($key))) {
                    continue;
                }
                if (! $dryRun) {
                    DB::table('pv_image_uploads')->insertOrIgnore($this->pending($disk, $path, PvProductImageController::publicUrl($key), now()));
                }
                if (++$enrolled >= $limit) {
                    return $dryRun ? $enrolled : 0;
                }
            }
        }

        return $dryRun ? $enrolled : 0;
    }

    private function referenced(string $key): bool
    {
        return DB::table('pv_product_images')->where('image_url', 'like', '%'.$key.'%')->orWhere('storage_url', 'like', '%'.$key.'%')->exists();
    }

    private function pending(string $disk, string $path, string $url, \DateTimeInterface $expiry): array
    {
        return ['id' => $this->id($disk, $path), 'disk' => $disk, 'storage_key' => $path, 'public_url' => $url,
            'expires_at' => $expiry, 'claimed_at' => null, 'created_at' => now()];
    }

    private function id(string $disk, string $path): string
    {
        return hash('sha256', $disk."\0".$path);
    }

    private function validKey(string $key): bool
    {
        return preg_match('/^'.PvProductImageController::KEY_PATTERN.'$/D', $key) === 1;
    }

    private function graceHours(): int
    {
        return max(1, (int) config('pv.image_pending_hours', 24));
    }
}
