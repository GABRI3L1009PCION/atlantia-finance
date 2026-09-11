<?php

namespace App\Models;

use Database\Factories\OperationalRecordFileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class OperationalRecordFile extends Model
{
    /** @use HasFactory<OperationalRecordFileFactory> */
    use HasFactory;

    protected $fillable = [
        'operational_record_id', 'company_id', 'uploaded_by', 'original_name',
        'storage_path', 'mime_type', 'size_bytes', 'sha256',
    ];

    protected static function booted(): void
    {
        static::creating(function (OperationalRecordFile $file): void {
            $file->public_id ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return ['size_bytes' => 'integer'];
    }

    /** @return BelongsTo<OperationalRecord, $this> */
    public function record(): BelongsTo
    {
        return $this->belongsTo(OperationalRecord::class, 'operational_record_id');
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
