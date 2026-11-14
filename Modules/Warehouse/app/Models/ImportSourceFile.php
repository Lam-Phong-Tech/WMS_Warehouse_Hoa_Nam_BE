<?php

namespace Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class ImportSourceFile extends Model
{
    use HasUuids;

    protected $table = 'import_source_files';

    protected $primaryKey = 'source_file_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['file_path', 'identity_hash'];

    protected $casts = ['file_size_bytes' => 'integer', 'next_version' => 'integer', 'uploaded_at' => 'datetime'];

    public function available(): bool
    {
        return $this->file_path !== null && Storage::disk(self::diskName())->exists($this->file_path);
    }

    public static function diskName(): string
    {
        return (string) config('wms_import.source_disk', 'local');
    }
}
