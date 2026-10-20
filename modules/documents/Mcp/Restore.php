<?php

namespace Modules\Documents\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Modules\Documents\DocumentsTrash;

class Restore extends Tool
{
    protected string $module = 'documents';

    protected string $access = 'write';

    protected string $name = 'documents_restore';

    protected string $description = 'Bring a deleted document back from the trash.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('The document id from documents_list_deleted.')->required(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $url = DocumentsTrash::restore($this->user(), 'document', (int) $request->get('id'));
        if (! $url) {
            return $this->notFound('Trashed document');
        }

        $appUrl = rtrim((string) config('app.url'), '/');

        return Response::structured([
            'restored' => true,
            'url' => $appUrl.$url,
        ]);
    }
}
