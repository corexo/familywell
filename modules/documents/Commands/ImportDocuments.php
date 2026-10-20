<?php

namespace Modules\Documents\Commands;

use App\Models\Family;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Modules\Documents\Document;

class ImportDocuments extends Command
{
    protected $signature = 'documents:import 
                            {path=/haushalt : Path to scan for documents}
                            {--family=1 : Target family id}
                            {--limit= : Max documents to import}
                            {--dry-run : Only show what would be imported}';

    protected $description = 'Import documents from a directory into Familywell';

    public function handle(): int
    {
        $path = $this->argument('path');
        if (! is_dir($path)) {
            $this->error("Directory not found: {$path}");
            return self::FAILURE;
        }

        $familyId = (int) $this->option('family');
        $family = Family::find($familyId);
        if (! $family) {
            $this->error("Family not found: {$familyId}");
            return self::FAILURE;
        }

        $creator = User::where('id', $family->owner_id)->first() ?? User::first();
        $isDryRun = (bool) $this->option('dry-run');
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;

        $this->info("Scanning {$path} for documents (Family: {$family->name}, Dry-Run: " . ($isDryRun ? 'YES' : 'NO') . ")...");

        $extensions = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                continue;
            }

            $fileName = $item->getFilename();
            $filePath = $item->getPathname();

            // Skip AppleDouble junk & hidden files
            if (str_starts_with($fileName, '._') || str_starts_with($fileName, '.')) {
                continue;
            }

            // Skip quarantine / temp / duplicate folders
            if (str_contains($filePath, '_duplicates') ||
                str_contains($filePath, '.obsidian') ||
                str_contains($filePath, '.analyse') ||
                str_contains($filePath, 'chromadb')) {
                continue;
            }

            $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            if (in_array($ext, $extensions, true)) {
                $files[] = $filePath;
                if ($limit && count($files) >= $limit) {
                    break;
                }
            }
        }

        $this->info("Found " . count($files) . " candidate files to process.");

        $imported = 0;
        $skipped = 0;
        $errors = 0;

        $bar = $this->output->createProgressBar(count($files));
        $bar->start();

        foreach ($files as $filePath) {
            $fileName = basename($filePath);
            $fileSize = filesize($filePath);

            // Duplicate detection: check if a document with same title or filename & size already exists for this family
            $exists = Document::where('family_id', $familyId)
                ->where(function ($q) use ($fileName, $fileSize) {
                    $q->where('file_name', $fileName)
                      ->orWhere('file_size', $fileSize);
                })
                ->exists();

            if ($exists) {
                $skipped++;
                $bar->advance();
                continue;
            }

            $meta = $this->extractMetadata($filePath);

            if ($isDryRun) {
                $imported++;
                $bar->advance();
                continue;
            }

            try {
                $doc = Document::create([
                    'family_id' => $familyId,
                    'title' => $meta['title'],
                    'category' => $meta['category'],
                    'vendor' => $meta['vendor'],
                    'amount' => $meta['amount'],
                    'currency' => 'EUR',
                    'issued_on' => $meta['issued_on'],
                    'due_on' => $meta['due_on'],
                    'expires_on' => $meta['expires_on'],
                    'notes' => $meta['notes'],
                    'tags' => $meta['tags'],
                    'created_by' => $creator?->id,
                ]);

                $mime = mime_content_type($filePath) ?: 'application/octet-stream';
                $uploadFile = new UploadedFile($filePath, $fileName, $mime, null, true);
                $doc->attachFile($uploadFile);

                $imported++;
            } catch (\Throwable $e) {
                $errors++;
                $this->newLine();
                $this->error("Error importing {$fileName}: " . $e->getMessage());
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->table(
            ['Metric', 'Count'],
            [
                ['Total Candidates', count($files)],
                ['Imported', $imported],
                ['Skipped (Duplicates/Existing)', $skipped],
                ['Errors', $errors],
            ]
        );

        return self::SUCCESS;
    }

    private function extractMetadata(string $path): array
    {
        $fileName = pathinfo($path, PATHINFO_FILENAME);
        $fullPath = strtolower($path);
        $nameLower = strtolower($fileName);

        // Category deduction
        $category = 'other';
        if (preg_match('/rechnung|invoice|rg[-_ ]|_rg|\.rg/i', $fileName)) {
            $category = 'invoice';
        } elseif (preg_match('/beleg|quittung|kassenbon|bon/i', $fileName)) {
            $category = 'receipt';
        } elseif (preg_match('/vertrag|contract|k[uü]ndigung|best[aä]tigung|police|vereinbarung/i', $fileName)) {
            $category = 'contract';
        } elseif (preg_match('/garantie|warranty|gew[aä]hrleistung/i', $fileName)) {
            $category = 'warranty';
        } elseif (preg_match('/versicherung|haftpflicht|rechtschutz|allianz|generali|ergo|huk/i', $fileName)) {
            $category = 'insurance';
        } elseif (preg_match('/steuer|finanzamt|kst|ust|bescheid|elster|gewerbesteuer/i', $fileName)) {
            $category = 'tax';
        }

        // Vendor extraction
        $vendor = null;
        $knownVendors = [
            'Telekom', 'Vodafone', 'o2', 'Allianz', 'Ionos', 'Generali', 'Dahremöller',
            'IKEA', 'Amazon', 'Barmer', 'AOK', 'HUK', 'Check24', '1&1', 'Sparkasse',
            'Volksbank', 'PayPal', 'Finanzamt Herford', 'Finanzamt', 'Lidl', 'Aldi',
            'Rewe', 'Edeka', 'MediaMarkt', 'Saturn', 'Apple', 'Google',
        ];
        foreach ($knownVendors as $v) {
            if (stripos($fileName, $v) !== false || stripos($path, $v) !== false) {
                $vendor = $v;
                break;
            }
        }

        // Date extraction from filename
        $issuedOn = null;
        if (preg_match('/(20\d{2})[-_.](0[1-9]|1[0-2])[-_.](0[1-9]|[12]\d|3[01])/', $fileName, $m)) {
            $issuedOn = "{$m[1]}-{$m[2]}-{$m[3]}";
        } elseif (preg_match('/(0[1-9]|[12]\d|3[01])[-_.](0[1-9]|1[0-2])[-_.](20\d{2})/', $fileName, $m)) {
            $issuedOn = "{$m[3]}-{$m[2]}-{$m[1]}";
        } elseif (preg_match('/(20\d{2})(0[1-9]|1[0-2])(0[1-9]|[12]\d|3[01])/', $fileName, $m)) {
            $issuedOn = "{$m[1]}-{$m[2]}-{$m[3]}";
        }

        // Fallback date from folder (e.g. /Privat/2026/ or /Firma/2025/)
        if (! $issuedOn && preg_match('/[\/\\\\](20[12]\d)[\/\\\\]/', $path, $m)) {
            $issuedOn = "{$m[1]}-01-01";
        }

        // Tags based on path components
        $tags = [];
        if (str_contains($fullPath, 'firma')) {
            $tags[] = 'firma';
        }
        if (str_contains($fullPath, 'privat')) {
            $tags[] = 'privat';
        }
        if (preg_match('/(20[12]\d)/', $path, $m)) {
            $tags[] = $m[1];
        }
        if ($category !== 'other') {
            $tags[] = $category;
        }

        // Clean title
        $title = str_replace(['_', '-'], ' ', $fileName);
        $title = preg_replace('/\s+/', ' ', trim($title));
        if (mb_strlen($title) > 140) {
            $title = mb_substr($title, 0, 137) . '...';
        }

        return [
            'title' => $title ?: $fileName,
            'category' => $category,
            'vendor' => $vendor,
            'amount' => null,
            'issued_on' => $issuedOn,
            'due_on' => null,
            'expires_on' => null,
            'notes' => "Imported from {$fileName}",
            'tags' => array_values(array_unique($tags)),
        ];
    }
}
