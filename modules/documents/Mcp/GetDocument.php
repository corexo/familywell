<?php

namespace Modules\Documents\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class GetDocument extends Tool
{
    protected string $module = 'documents';

    protected string $name = 'documents_get';

    protected string $description = 'Get full details of a specific document, invoice or contract, including download link, notes, and metadata.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'document_id' => $schema->integer()->description('The document id.')->required(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $doc = DocumentData::document($request->get('document_id'));
        if (! $doc) {
            return $this->notFound('Document');
        }

        return Response::structured(DocumentData::payload($doc, true));
    }
}
