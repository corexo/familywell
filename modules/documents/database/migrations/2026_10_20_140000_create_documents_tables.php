<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();

            $table->string('title');
            $table->string('category')->default('other');
            $table->string('vendor')->nullable();

            $table->decimal('amount', 12, 2)->nullable();
            $table->string('currency', 3)->default('EUR');

            $table->date('issued_on')->nullable();
            $table->date('due_on')->nullable();
            $table->date('expires_on')->nullable();

            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('mime_type')->nullable();

            $table->text('notes')->nullable();
            $table->json('tags')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['family_id', 'category']);
            $table->index(['family_id', 'expires_on']);
            $table->index(['family_id', 'due_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents_documents');
    }
};
