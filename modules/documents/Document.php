<?php

namespace Modules\Documents;

use App\Models\User;
use App\Support\Media\StoredImage;
use App\Support\Tenancy\BelongsToFamily;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;

class Document extends Model
{
    use BelongsToFamily;
    use SoftDeletes;

    protected $table = 'documents_documents';

    protected $fillable = [
        'family_id',
        'title',
        'category',
        'vendor',
        'amount',
        'currency',
        'issued_on',
        'due_on',
        'expires_on',
        'file_path',
        'file_name',
        'file_size',
        'mime_type',
        'notes',
        'content_text',
        'tags',
        'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'issued_on' => 'date',
        'due_on' => 'date',
        'expires_on' => 'date',
        'tags' => 'array',
        'file_size' => 'integer',
    ];

    public const CATEGORIES = [
        'invoice',
        'receipt',
        'contract',
        'warranty',
        'insurance',
        'tax',
        'other',
    ];

    public const EXPIRY_LEAD_DAYS = 30;

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function attachFile(UploadedFile $file, ?UploadedFile $thumb = null): void
    {
        if ($this->file_path) {
            StoredImage::delete($this->file_path);
        }

        $dir = 'documents/'.$this->family_id;
        $path = StoredImage::store($file, $thumb, $dir);

        $this->update([
            'file_path' => $path,
            'file_name' => $file->getClientOriginalName(),
            'file_size' => $file->getSize(),
            'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
        ]);
    }

    public function deleteWithFile(): void
    {
        $this->delete();
    }

    protected static function booted(): void
    {
        static::forceDeleting(function (self $doc) {
            if ($doc->file_path) {
                StoredImage::delete($doc->file_path);
            }
        });
    }

    public function isExpiring(?Carbon $today = null): bool
    {
        if (! $this->expires_on) {
            return false;
        }
        $today ??= Carbon::today();

        return $this->expires_on->copy()->startOfDay()->lte($today->copy()->addDays(self::EXPIRY_LEAD_DAYS));
    }

    public function isOverdue(?Carbon $today = null): bool
    {
        if (! $this->due_on) {
            return false;
        }
        $today ??= Carbon::today();

        return $this->due_on->copy()->startOfDay()->lt($today);
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(?string $fileUrl = null, ?Carbon $today = null, bool $fullOcr = false): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'category' => $this->category,
            'vendor' => $this->vendor,
            'amount' => $this->amount !== null ? (float) $this->amount : null,
            'currency' => $this->currency,
            'issuedOn' => optional($this->issued_on)->toDateString(),
            'dueOn' => optional($this->due_on)->toDateString(),
            'expiresOn' => optional($this->expires_on)->toDateString(),
            'isExpiring' => $this->isExpiring($today),
            'isOverdue' => $this->isOverdue($today),
            'hasFile' => $this->file_path !== null,
            'hasOcr' => ! empty($this->content_text),
            'contentText' => $fullOcr ? $this->content_text : ($this->content_text ? mb_substr($this->content_text, 0, 180) : null),
            'fileName' => $this->file_name,
            'fileSize' => $this->file_size,
            'mimeType' => $this->mime_type,
            'fileUrl' => $fileUrl,
            'notes' => $this->notes,
            'tags' => $this->tags ?? [],
            'createdBy' => $this->created_by,
            'createdAt' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
