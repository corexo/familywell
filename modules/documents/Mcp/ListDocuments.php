<?php

namespace Modules\Documents\Mcp;

use App\Mcp\Tool;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Modules\Documents\Document;

#[IsReadOnly]
class ListDocuments extends Tool
{
    protected string $module = 'documents';

    protected string $name = 'documents_list';

    protected string $description = 'List family documents, invoices, receipts, contracts, warranties. Filter by category, vendor or search query.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'category' => $schema->string()->enum(Document::CATEGORIES)->description('Filter by document category.'),
            'vendor' => $schema->string()->description('Filter by vendor / company name.'),
            'search' => $schema->string()->description('Search term for title, vendor or notes.'),
            'tag' => $schema->string()->description('Filter by tag.'),
            'status' => $schema->string()->enum(['all', 'expiring', 'overdue'])->description('Filter by expiry or overdue status.'),
            ...$this->pageSchema($schema),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $familyId = (int) CurrentFamily::id();
        $query = Document::query()->where('family_id', $familyId);

        $category = $request->get('category');
        if ($category && in_array($category, Document::CATEGORIES, true)) {
            $query->where('category', $category);
        }

        $vendor = trim((string) $request->get('vendor', ''));
        if ($vendor !== '') {
            $query->where('vendor', 'like', "%{$vendor}%");
        }

        $search = trim((string) $request->get('search', ''));
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('vendor', 'like', "%{$search}%")
                    ->orWhere('notes', 'like', "%{$search}%");
            });
        }

        $tag = trim((string) $request->get('tag', ''));
        if ($tag !== '') {
            $query->whereJsonContains('tags', $tag);
        }

        $status = $request->get('status');
        $today = now()->toDateString();
        if ($status === 'expiring') {
            $limitDate = now()->addDays(Document::EXPIRY_LEAD_DAYS)->toDateString();
            $query->whereNotNull('expires_on')->where('expires_on', '<=', $limitDate)->where('expires_on', '>=', $today);
        } elseif ($status === 'overdue') {
            $query->whereNotNull('due_on')->where('due_on', '<', $today);
        }

        $query->orderByDesc('created_at')->orderByDesc('id');
        $page = $this->page($query, $request);

        return Response::structured([
            'documents' => $page['items']->map(fn (Document $d) => DocumentData::payload($d, false))->values(),
            'categories' => Document::CATEGORIES,
            'next_cursor' => $page['next_cursor'],
        ]);
    }
}
