<?php

namespace Modules\Documents\Mcp;

use App\Models\User;
use App\Support\Media\StoredImage;
use App\Support\Tenancy\CurrentFamily;
use App\Support\Trash\Trash;
use Modules\Documents\Document;

class DocumentData
{
    public static function document(mixed $id): ?Document
    {
        $familyId = (int) CurrentFamily::id();

        return Document::where('family_id', $familyId)->find((int) $id);
    }

    public static function payload(Document $d, bool $detailed = false): array
    {
        $appUrl = rtrim((string) config('app.url'), '/');
        $fileUrl = $d->file_path
            ? StoredImage::url('documents.file', [$d->id], $d->file_path)
            : null;

        $out = [
            'id' => $d->id,
            'title' => $d->title,
            'category' => $d->category,
            'vendor' => $d->vendor,
            'amount' => $d->amount !== null ? (float) $d->amount : null,
            'currency' => $d->currency,
            'issued_on' => optional($d->issued_on)->toDateString(),
            'due_on' => optional($d->due_on)->toDateString(),
            'expires_on' => optional($d->expires_on)->toDateString(),
            'is_expiring' => $d->isExpiring(),
            'is_overdue' => $d->isOverdue(),
            'has_file' => $d->file_path !== null,
            'file_name' => $d->file_name,
            'file_url' => $fileUrl,
            'tags' => $d->tags ?? [],
            'url' => "{$appUrl}/m/documents",
        ];

        if ($detailed) {
            $out['notes'] = $d->notes;
            $out['file_size'] = $d->file_size;
            $out['mime_type'] = $d->mime_type;
            $out['created_at'] = optional($d->created_at)->toIso8601String();
        }

        return $out;
    }

    public static function trashInfo(Document $d): array
    {
        return [
            'deleted_at' => $d->deleted_at->toIso8601String(),
            'purged_on' => $d->deleted_at->copy()->addDays(Trash::days())->toDateString(),
        ];
    }
}
