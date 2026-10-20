<?php

namespace Modules\Documents\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class DeleteDocument extends Tool
{
    protected string $module = 'documents';

    protected string $access = 'write';

    protected string $name = 'documents_delete';

    protected string $description = 'Move a document to the trash (recoverable for 30 days with documents_restore).';

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

        $id = $doc->id;
        $title = $doc->title;
        $doc->deleteWithFile();

        return Response::structured([
            'deleted' => true,
            'document_id' => $id,
            'title' => $title,
            'message' => "Document \"{$title}\" moved to trash (restorable for 30 days).",
        ]);
    }
}
