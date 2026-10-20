<?php

namespace Modules\Documents;

use App\Http\Controllers\Controller;
use App\Support\Media\StoredImage;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Documents\Services\DocumentOcrService;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentsController extends Controller
{
    public function index(Request $request): Response
    {
        $familyId = (int) CurrentFamily::id();
        $query = Document::query()->where('family_id', $familyId);

        $category = $request->query('category');
        if ($category && in_array($category, Document::CATEGORIES, true)) {
            $query->where('category', $category);
        }

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('vendor', 'like', "%{$search}%")
                    ->orWhere('notes', 'like', "%{$search}%")
                    ->orWhere('content_text', 'like', "%{$search}%");
            });
        }

        $tag = trim((string) $request->query('tag', ''));
        if ($tag !== '') {
            $query->whereJsonContains('tags', $tag);
        }

        $status = $request->query('status');
        $today = now()->toDateString();
        if ($status === 'expiring') {
            $limitDate = now()->addDays(Document::EXPIRY_LEAD_DAYS)->toDateString();
            $query->whereNotNull('expires_on')->where('expires_on', '<=', $limitDate)->where('expires_on', '>=', $today);
        } elseif ($status === 'overdue') {
            $query->whereNotNull('due_on')->where('due_on', '<', $today);
        }

        // 1. Fast SQL Aggregates for stats (Zero memory overhead)
        $baseQuery = Document::where('family_id', $familyId);
        $totalCount = (clone $baseQuery)->count();
        $totalAmount = (float) (clone $baseQuery)->whereIn('category', ['invoice', 'receipt'])->sum('amount');
        $limitDate = now()->addDays(Document::EXPIRY_LEAD_DAYS)->toDateString();
        $expiringCount = (clone $baseQuery)->whereNotNull('expires_on')->where('expires_on', '<=', $limitDate)->where('expires_on', '>=', $today)->count();
        $overdueCount = (clone $baseQuery)->whereNotNull('due_on')->where('due_on', '<', $today)->count();

        // 2. Fetch tags list efficiently
        $allTags = Document::where('family_id', $familyId)
            ->whereNotNull('tags')
            ->select('tags')
            ->pluck('tags')
            ->flatten()
            ->filter()
            ->unique()
            ->values()
            ->all();

        // 3. Paginate to 30 items per page with light payloads
        $paginated = $query->orderByDesc('created_at')->paginate(30)->withQueryString();

        $documents = collect($paginated->items())
            ->map(fn (Document $d) => $d->toPayload($this->fileUrl($d), null, false))
            ->values();

        return Inertia::render('documents/index', [
            'documents' => $documents,
            'pagination' => [
                'currentPage' => $paginated->currentPage(),
                'lastPage' => $paginated->lastPage(),
                'perPage' => $paginated->perPage(),
                'total' => $paginated->total(),
                'from' => $paginated->firstItem(),
                'to' => $paginated->lastItem(),
                'hasMorePages' => $paginated->hasMorePages(),
                'prevPageUrl' => $paginated->previousPageUrl(),
                'nextPageUrl' => $paginated->nextPageUrl(),
            ],
            'categories' => Document::CATEGORIES,
            'allTags' => $allTags,
            'stats' => [
                'total' => $totalCount,
                'totalAmount' => $totalAmount,
                'expiringCount' => $expiringCount,
                'overdueCount' => $overdueCount,
            ],
            'filters' => [
                'category' => $category,
                'search' => $search,
                'tag' => $tag,
                'status' => $status,
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateDocument($request);

        $document = Document::create([
            'family_id' => (int) CurrentFamily::id(),
            'title' => $data['title'],
            'category' => $data['category'],
            'vendor' => $data['vendor'] ?? null,
            'amount' => $data['amount'] ?? null,
            'currency' => $data['currency'] ?? 'EUR',
            'issued_on' => $data['issued_on'] ?? null,
            'due_on' => $data['due_on'] ?? null,
            'expires_on' => $data['expires_on'] ?? null,
            'notes' => $data['notes'] ?? null,
            'tags' => $this->parseTags($data['tags'] ?? null),
            'created_by' => $request->user()->id,
        ]);

        if ($request->hasFile('file')) {
            $document->attachFile($request->file('file'), $request->file('file_thumb'));
            $this->processOcr($document);
        }

        return back();
    }

    public function update(Request $request, Document $document): RedirectResponse
    {
        $this->authorizeDocument($document);
        $data = $this->validateDocument($request);

        $document->update([
            'title' => $data['title'],
            'category' => $data['category'],
            'vendor' => $data['vendor'] ?? null,
            'amount' => $data['amount'] ?? null,
            'currency' => $data['currency'] ?? 'EUR',
            'issued_on' => $data['issued_on'] ?? null,
            'due_on' => $data['due_on'] ?? null,
            'expires_on' => $data['expires_on'] ?? null,
            'notes' => $data['notes'] ?? null,
            'tags' => $this->parseTags($data['tags'] ?? null),
        ]);

        if ($request->hasFile('file')) {
            $document->attachFile($request->file('file'), $request->file('file_thumb'));
            $this->processOcr($document);
        }

        return back();
    }

    public function destroy(Request $request, Document $document): RedirectResponse
    {
        $this->authorizeDocument($document);
        $document->deleteWithFile();

        return back();
    }

    public function fileShow(Request $request, Document $document): StreamedResponse
    {
        $this->authorizeDocument($document);
        abort_unless($document->file_path, 404);

        return StoredImage::response($document->file_path, $request->query('v'));
    }

    public function showDetail(Document $document): JsonResponse
    {
        $this->authorizeDocument($document);

        return response()->json([
            'id' => $document->id,
            'contentText' => $document->content_text,
            'notes' => $document->notes,
        ]);
    }

    private function validateDocument(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'category' => ['required', Rule::in(Document::CATEGORIES)],
            'vendor' => ['nullable', 'string', 'max:120'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'issued_on' => ['nullable', 'date'],
            'due_on' => ['nullable', 'date'],
            'expires_on' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:3000'],
            'tags' => ['nullable'],
            'file' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp,heic', 'max:12288'],
            'file_thumb' => ['nullable', 'image', 'max:4096'],
        ]);
    }

    private function parseTags(mixed $tags): array
    {
        if (is_array($tags)) {
            return array_values(array_filter(array_map('trim', $tags)));
        }
        if (is_string($tags)) {
            return array_values(array_filter(array_map('trim', explode(',', $tags))));
        }

        return [];
    }

    private function fileUrl(Document $document): ?string
    {
        return $document->file_path
            ? StoredImage::url('documents.file', [$document->id], $document->file_path)
            : null;
    }

    private function authorizeDocument(Document $document): void
    {
        abort_unless((int) $document->family_id === (int) CurrentFamily::id(), 404);
    }

    private function processOcr(Document $document): void
    {
        try {
            $disk = Storage::disk(StoredImage::disk());
            if (! $document->file_path || ! $disk->exists($document->file_path)) {
                return;
            }
            $ocr = app(DocumentOcrService::class);
            $text = $ocr->extractText($disk->path($document->file_path), $document->mime_type);
            if ($text && mb_strlen(trim($text)) >= 10) {
                $updates = ['content_text' => $text];
                $analysis = $ocr->analyze($text, [
                    'title' => $document->title,
                    'vendor' => $document->vendor,
                    'category' => $document->category,
                    'amount' => $document->amount,
                    'issued_on' => optional($document->issued_on)->toDateString(),
                    'tags' => $document->tags ?? [],
                ]);
                $document->update(array_merge($updates, $analysis));
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
