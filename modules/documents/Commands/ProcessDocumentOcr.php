<?php

namespace Modules\Documents\Commands;

use App\Support\Media\StoredImage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Modules\Documents\Document;
use Modules\Documents\Services\DocumentOcrService;

class ProcessDocumentOcr extends Command
{
    protected $signature = 'documents:ocr
                            {--family= : Specific family ID}
                            {--limit= : Limit number of documents to process}
                            {--all : Force re-OCR and re-classification of all documents}
                            {--classify : Auto-update category, vendor, amount, date and tags if found}
                            {--dry-run : Only show what would be done without saving}';

    protected $description = 'Extract text via OCR (pdftotext/tesseract) and auto-classify documents';

    public function handle(DocumentOcrService $ocr): int
    {
        $familyId = $this->option('family');
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;
        $forceAll = (bool) $this->option('all');
        $autoClassify = (bool) $this->option('classify') || true; // default enabled
        $dryRun = (bool) $this->option('dry-run');

        $query = Document::query();
        if ($familyId) {
            $query->where('family_id', $familyId);
        }
        if (! $forceAll) {
            $query->where(function ($q) {
                $q->whereNull('content_text')->orWhere('content_text', '');
            });
        }
        if ($limit) {
            $query->limit($limit);
        }

        $docs = $query->get();
        $total = $docs->count();

        $this->info("Starting OCR & Auto-Categorization on {$total} documents (Dry-Run: ".($dryRun ? 'YES' : 'NO').")...");

        if ($total === 0) {
            $this->info('No documents pending OCR.');
            return 0;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $stats = [
            'ocr_success' => 0,
            'ocr_empty' => 0,
            'classified' => 0,
            'file_missing' => 0,
        ];

        $disk = Storage::disk(StoredImage::disk());

        foreach ($docs as $doc) {
            $bar->advance();

            if (! $doc->file_path || ! $disk->exists($doc->file_path)) {
                $stats['file_missing']++;
                continue;
            }

            $absolutePath = $disk->path($doc->file_path);
            $text = $ocr->extractText($absolutePath, $doc->mime_type);

            if (! $text || mb_strlen(trim($text)) < 10) {
                $stats['ocr_empty']++;
                continue;
            }

            $stats['ocr_success']++;
            $updates = [
                'content_text' => $text,
            ];

            if ($autoClassify) {
                $analysis = $ocr->analyze($text, [
                    'title' => $doc->title,
                    'vendor' => $doc->vendor,
                    'category' => $doc->category,
                    'amount' => $doc->amount,
                    'issued_on' => optional($doc->issued_on)->toDateString(),
                    'tags' => $doc->tags ?? [],
                ]);

                if (! empty($analysis)) {
                    $stats['classified']++;
                    $updates = array_merge($updates, $analysis);
                }
            }

            if (! $dryRun) {
                $doc->update($updates);
            }
        }

        $bar->finish();
        $this->newLine(2);

        $this->table(
            ['Metric', 'Count'],
            [
                ['Total Processed', $total],
                ['OCR Text Extracted', $stats['ocr_success']],
                ['Auto-Classified / Enriched', $stats['classified']],
                ['No Text Detected / Empty', $stats['ocr_empty']],
                ['File Missing on Disk', $stats['file_missing']],
            ]
        );

        return 0;
    }
}
