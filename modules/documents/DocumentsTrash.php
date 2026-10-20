<?php

namespace Modules\Documents;

use App\Models\User;
use App\Support\Trash\Trash;
use App\Support\Trash\TrashSource;
use Illuminate\Support\Collection;

class DocumentsTrash implements TrashSource
{
    public static function list(User $user): Collection
    {
        return Document::onlyTrashed()->get()
            ->map(fn (Document $d) => Trash::row($d, 'document', $d->title, $d->vendor ?: ucfirst($d->category)));
    }

    public static function restore(User $user, string $type, int $id): ?string
    {
        $doc = $type === 'document' ? Document::onlyTrashed()->find($id) : null;
        if (! $doc) {
            return null;
        }
        $doc->restore();

        return '/m/documents';
    }
}
