<?php

namespace Modules\Documents;

use App\Support\Engagement;
use App\Support\Mcp\McpTools;
use App\Support\Trash\Trash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Notifications\NotificationCategories;

class DocumentsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Engagement::track('documents', Document::class);

        McpTools::register('documents', 'Documents', [
            Mcp\ListDocuments::class,
            Mcp\GetDocument::class,
            Mcp\AddDocument::class,
            Mcp\UpdateDocument::class,
            Mcp\DeleteDocument::class,
            Mcp\ListDeleted::class,
            Mcp\Restore::class,
        ]);

        Trash::source('documents', DocumentsTrash::class);
        Trash::register(Document::class);

        NotificationCategories::register('documents', 'Documents', 'file-text');

        if ($this->app->runningInConsole()) {
            $this->commands([
                Commands\ImportDocuments::class,
            ]);
        }

        Route::middleware(['web', 'auth', 'family', 'module:documents'])
            ->prefix('m/documents')
            ->name('documents.')
            ->group(function () {
                Route::get('/', [DocumentsController::class, 'index'])->name('index');
                Route::post('/', [DocumentsController::class, 'store'])->name('store');
                Route::patch('/{document}', [DocumentsController::class, 'update'])->name('update');
                Route::delete('/{document}', [DocumentsController::class, 'destroy'])->name('destroy');
                Route::get('/{document}/file', [DocumentsController::class, 'fileShow'])->name('file');
            });
    }
}
