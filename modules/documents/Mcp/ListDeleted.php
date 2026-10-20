<?php

namespace Modules\Documents\Mcp;

use App\Mcp\Tool;
use App\Support\Trash\Trash;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Modules\Documents\DocumentsTrash;

#[IsReadOnly]
class ListDeleted extends Tool
{
    protected string $module = 'documents';

    protected string $access = 'write';

    protected string $name = 'documents_list_deleted';

    protected string $description = 'Documents in the trash for this family, newest first, with the date each will be removed permanently.';

    public function schema(JsonSchema $schema): array
    {
        return $this->pageSchema($schema);
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $page = $this->page(Trash::sorted(DocumentsTrash::list($this->user())), $request);

        return Response::structured(['deleted' => $page['items']->values(), 'next_cursor' => $page['next_cursor']]);
    }
}
