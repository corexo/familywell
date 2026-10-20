<?php

namespace Modules\Documents\Mcp;

use App\Mcp\Tool;
use App\Support\Mcp\IncomingFile;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Modules\Documents\Document;

#[IsOpenWorld]
class AddDocument extends Tool
{
    protected string $module = 'documents';

    protected string $access = 'write';

    protected string $name = 'documents_add';

    protected string $description = 'Upload and record a document, invoice, receipt, contract or warranty. Accepts file as `file` (ChatGPT attachment), `file_url` (public https URL), or `file_base64` + `file_name` (PDF, JPG, PNG, WEBP, up to 12 MB). Extract amount, vendor, category and dates where applicable.';

    public function __construct()
    {
        $this->setMeta(IncomingFile::FILE_PARAMS_META);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->description('Document title or short description, e.g. "Telekom Rechnung Oktober 2026".')->required(),
            'category' => $schema->string()->enum(Document::CATEGORIES)->description('Category: invoice, receipt, contract, warranty, insurance, tax, or other.')->required(),
            'vendor' => $schema->string()->description('Company, store or issuer, e.g. "Telekom", "IKEA", "Allianz".'),
            'amount' => $schema->number()->description('Total amount if applicable, e.g. 49.99.'),
            'currency' => $schema->string()->description('ISO currency code, e.g. "EUR" (default EUR).'),
            'issued_on' => $schema->string()->format('date')->description('Invoice/issue date (YYYY-MM-DD).'),
            'due_on' => $schema->string()->format('date')->description('Due date / payment deadline (YYYY-MM-DD).'),
            'expires_on' => $schema->string()->format('date')->description('Contract end or warranty expiry (YYYY-MM-DD).'),
            'notes' => $schema->string()->description('Additional notes or extracted line items.'),
            'tags' => $schema->array()->items($schema->string())->description('List of tag strings, e.g. ["internet", "home_office"].'),
            ...IncomingFile::schema($schema),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'category' => ['required', Rule::in(Document::CATEGORIES)],
            'vendor' => ['nullable', 'string', 'max:120'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'issued_on' => ['nullable', 'date'],
            'due_on' => ['nullable', 'date'],
            'expires_on' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:3000'],
            'tags' => ['nullable', 'array'],
        ]);

        $file = IncomingFile::from($request);

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
            'tags' => $data['tags'] ?? [],
            'created_by' => $this->user()->id,
        ]);

        if ($file) {
            $document->attachFile($file);
        }

        $appUrl = rtrim((string) config('app.url'), '/');

        return Response::structured([
            'document_id' => $document->id,
            'title' => $document->title,
            'category' => $document->category,
            'amount' => $document->amount !== null ? (float) $document->amount : null,
            'file_name' => $file?->getClientOriginalName(),
            'url' => "{$appUrl}/m/documents",
        ]);
    }
}
