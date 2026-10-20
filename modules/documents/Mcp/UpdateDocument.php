<?php

namespace Modules\Documents\Mcp;

use App\Mcp\Tool;
use App\Support\Mcp\IncomingFile;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Modules\Documents\Document;

class UpdateDocument extends Tool
{
    protected string $module = 'documents';

    protected string $access = 'write';

    protected string $name = 'documents_update';

    protected string $description = 'Update details of an existing document or invoice, or replace its file.';

    public function __construct()
    {
        $this->setMeta(IncomingFile::FILE_PARAMS_META);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'document_id' => $schema->integer()->description('The document id.')->required(),
            'title' => $schema->string()->description('New title.'),
            'category' => $schema->string()->enum(Document::CATEGORIES),
            'vendor' => $schema->string()->description('Issuer or vendor.'),
            'amount' => $schema->number()->description('Amount.'),
            'currency' => $schema->string()->description('Currency code.'),
            'issued_on' => $schema->string()->format('date'),
            'due_on' => $schema->string()->format('date'),
            'expires_on' => $schema->string()->format('date'),
            'notes' => $schema->string(),
            'tags' => $schema->array()->items($schema->string()),
            ...IncomingFile::schema($schema),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $doc = DocumentData::document($request->get('document_id'));
        if (! $doc) {
            return $this->notFound('Document');
        }

        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:150'],
            'category' => ['nullable', Rule::in(Document::CATEGORIES)],
            'vendor' => ['nullable', 'string', 'max:120'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'issued_on' => ['nullable', 'date'],
            'due_on' => ['nullable', 'date'],
            'expires_on' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:3000'],
            'tags' => ['nullable', 'array'],
        ]);

        $updates = array_filter($data, fn ($val) => $val !== null);
        if ($updates) {
            $doc->update($updates);
        }

        $file = IncomingFile::from($request);
        if ($file) {
            $doc->attachFile($file);
        }

        return Response::structured(DocumentData::payload($doc->fresh(), true));
    }
}
