<?php

namespace Modules\Documents\Services;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

class DocumentOcrService
{
    /**
     * Extract text from PDF or image using pdftotext or tesseract.
     */
    public function extractText(string $filePath, ?string $mimeType = null): ?string
    {
        if (! file_exists($filePath) || ! is_readable($filePath)) {
            return null;
        }

        $mime = $mimeType ?: mime_content_type($filePath);

        // 1. If it's a PDF, first try pdftotext (fast text layer extraction)
        if ($mime === 'application/pdf' || str_ends_with(strtolower($filePath), '.pdf')) {
            $pdfText = $this->extractPdfTextLayer($filePath);
            if ($pdfText && mb_strlen(trim($pdfText)) > 60) {
                return $this->cleanText($pdfText);
            }

            // Fallback for scanned PDF without text layer: render pages and OCR with tesseract
            $ocrText = $this->ocrPdfPages($filePath);
            if ($ocrText && mb_strlen(trim($ocrText)) > 20) {
                return $this->cleanText($ocrText);
            }

            return $pdfText ? $this->cleanText($pdfText) : null;
        }

        // 2. If it's an image
        if (str_starts_with($mime, 'image/')) {
            return $this->ocrImage($filePath);
        }

        return null;
    }

    /**
     * Extract text from digital PDF using pdftotext.
     */
    protected function extractPdfTextLayer(string $filePath): ?string
    {
        $escaped = escapeshellarg($filePath);
        $output = shell_exec("pdftotext -layout {$escaped} - 2>/dev/null");

        return $output ?: null;
    }

    /**
     * OCR scanned PDF pages by converting to images first.
     */
    protected function ocrPdfPages(string $filePath): ?string
    {
        $escaped = escapeshellarg($filePath);
        $tempPrefix = '/tmp/doc_ocr_' . uniqid();
        
        // Convert first 3 pages at 200 DPI
        shell_exec("pdftoppm -png -r 200 -f 1 -l 3 {$escaped} {$tempPrefix} 2>/dev/null");

        $images = glob("{$tempPrefix}-*.png");
        if (empty($images)) {
            return null;
        }

        sort($images);
        $allText = '';

        foreach ($images as $img) {
            $imgEscaped = escapeshellarg($img);
            $pageText = shell_exec("tesseract {$imgEscaped} stdout -l deu+eng --psm 1 2>/dev/null");
            if ($pageText) {
                $allText .= "\n\n" . $pageText;
            }
            @unlink($img);
        }

        return trim($allText) ?: null;
    }

    /**
     * OCR single image file.
     */
    protected function ocrImage(string $filePath): ?string
    {
        $escaped = escapeshellarg($filePath);
        $output = shell_exec("tesseract {$escaped} stdout -l deu+eng 2>/dev/null");

        return $output ? $this->cleanText($output) : null;
    }

    /**
     * Clean and normalize OCR / extracted text.
     */
    protected function cleanText(string $text): string
    {
        // Replace excessive blank lines
        $text = preg_replace("/\n{3,}/", "\n\n", $text);
        return trim($text);
    }

    /**
     * Analyze extracted text and classify document metadata.
     */
    public function analyze(string $text, array $current = []): array
    {
        $result = [];
        $lower = mb_strtolower($text);

        // 1. Detect Vendor / Aussteller
        $vendor = $this->detectVendor($text, $lower);
        if ($vendor && (empty($current['vendor']) || $current['vendor'] === 'Unbekannt')) {
            $result['vendor'] = $vendor;
        }

        // 2. Detect Category
        $category = $this->detectCategory($lower, $current['category'] ?? null);
        if ($category && (empty($current['category']) || $current['category'] === 'other')) {
            $result['category'] = $category;
        }

        // 3. Detect Amount
        $amount = $this->detectAmount($text);
        if ($amount !== null && empty($current['amount'])) {
            $result['amount'] = $amount;
            $result['currency'] = 'EUR';
        }

        // 4. Detect Date (issued_on)
        $date = $this->detectDate($text);
        if ($date && empty($current['issued_on'])) {
            $result['issued_on'] = $date;
        }

        // 5. Detect Tags
        $detectedTags = $this->detectTags($lower);
        $existingTags = is_array($current['tags'] ?? null) ? $current['tags'] : [];
        $mergedTags = array_values(array_unique(array_merge($existingTags, $detectedTags)));
        if (! empty($mergedTags)) {
            $result['tags'] = $mergedTags;
        }

        // 6. Suggest Title if current title is generic
        $currentTitle = $current['title'] ?? '';
        if ($this->isGenericTitle($currentTitle)) {
            $v = $result['vendor'] ?? ($current['vendor'] ?? null);
            $catLabel = match ($result['category'] ?? ($current['category'] ?? 'other')) {
                'invoice' => 'Rechnung',
                'contract' => 'Vertrag',
                'insurance' => 'Versicherungsschein',
                'tax' => 'Steuerbescheid',
                default => 'Dokument',
            };
            $d = $result['issued_on'] ?? ($current['issued_on'] ?? null);

            if ($v && $d) {
                $result['title'] = "{$v} - {$catLabel} {$d}";
            } elseif ($v) {
                $result['title'] = "{$v} - {$catLabel}";
            }
        }

        return $result;
    }

    protected function detectVendor(string $text, string $lower): ?string
    {
        $knownVendors = [
            'Telekom' => ['deutsche telekom', 'telekom deutschland', 't-mobile', 't-home'],
            'Vodafone' => ['vodafone gmbh', 'vodafone west', 'vodafone kabel', 'vodafone'],
            'O2 / Telefónica' => ['telefónica germany', 'telefonica germany', 'o2 online', ' o2 '],
            '1&1' => ['1&1 telecom', '1&1 mail & media', '1&1 internet'],
            'Congstar' => ['congstar gmbh', 'congstar'],
            'Amazon' => ['amazon eu s.a.r.l', 'amazon payments', 'amazon.de', 'amazon web services', ' aws '],
            'Apple' => ['apple distribution international', 'apple inc.', 'apple services', 'itunes'],
            'Google' => ['google ireland', 'google commerce', 'google cloud', 'google play'],
            'PayPal' => ['paypal (europe)', 'paypal s.à r.l', 'paypal'],
            'Klarna' => ['klarna bank', 'klarna'],
            'eBay' => ['ebay gmbh', 'ebay international'],
            'Allianz' => ['allianz versicherungs', 'allianz private krankenversicherungs', 'allianz lebensversicherungs', 'allianz'],
            'HUK-Coburg' => ['huk-coburg', 'huk24'],
            'AXA' => ['axa versicherung', 'axa konzern', 'axa'],
            'Ergo' => ['ergo versicherung', 'ergo direkt', 'ergo'],
            'DEVK' => ['devk versicherungen', 'devk'],
            'Signal Iduna' => ['signal iduna'],
            'Barmer' => ['barmer'],
            'Techniker Krankenkasse (TK)' => ['techniker krankenkasse'],
            'AOK' => ['aok bayern', 'aok baden-württemberg', 'aok rheinland', 'aok plus', 'aok '],
            'Finanzamt' => ['finanzamt', 'bundeszentralamt für steuern'],
            'Bundesagentur für Arbeit' => ['bundesagentur für arbeit', 'jobcenter'],
            'Deutsche Rentenversicherung' => ['deutsche rentenversicherung'],
            'Rundfunkbeitrag (GEZ)' => ['ard zdf deutschlandradio beitragsservice', 'rundfunkbeitrag'],
            'Stadtwerke' => ['stadtwerke münchen', 'stadtwerke', 'swm infrastructure'],
            'Vattenfall' => ['vattenfall europe', 'vattenfall'],
            'E.ON' => ['e.on energie', 'e.on'],
            'EnBW' => ['enbw energie', 'enbw'],
            'Mainova' => ['mainova ag', 'mainova'],
            'Sparkasse' => ['sparkasse', 'kreissparkasse', 'stadtsparkasse'],
            'Volksbank / Raiffeisenbank' => ['volksbank', 'raiffeisenbank'],
            'Deutsche Bank' => ['deutsche bank'],
            'Commerzbank' => ['commerzbank'],
            'ING' => ['ing-diba', 'ing diba', ' ing '],
            'DKB' => ['deutsche kreditbank', 'dkb ag', ' dkb '],
            'Postbank' => ['postbank'],
            'IKEA' => ['ikea deutschland', 'ikea'],
            'MediaMarkt' => ['mediamarkt', 'media markt'],
            'Saturn' => ['saturn electro', ' saturn '],
            'Bauhaus' => ['bauhaus ag', 'bauhaus'],
            'Hornbach' => ['hornbach baumarkt', 'hornbach'],
            'Obi' => ['obi gmbh', ' obi '],
            'Lidl' => ['lidl digital', 'lidl dienstleistung'],
            'Aldi' => ['aldi süd', 'aldi nord'],
            'Adobe' => ['adobe systems software'],
            'Microsoft' => ['microsoft ireland', 'microsoft'],
            'Spotify' => ['spotify ab'],
            'Netflix' => ['netflix international'],
        ];

        foreach ($knownVendors as $name => $patterns) {
            foreach ($patterns as $pattern) {
                if (str_contains($lower, $pattern)) {
                    return $name;
                }
            }
        }

        // Try extracting sender from early lines with GmbH / AG / e.V.
        $lines = array_slice(explode("\n", $text), 0, 20);
        foreach ($lines as $line) {
            $line = trim($line);
            if (preg_match('/^([A-ZÄÖÜ][A-Za-z0-9ÄÖÜäöüß\s\.\-&]{2,40}\s+(?:GmbH|AG|UG|GbR|e\.K\.|e\.V\.|KG))/u', $line, $matches)) {
                return trim($matches[1]);
            }
        }

        return null;
    }

    protected function detectCategory(string $lower, ?string $currentCategory = null): ?string
    {
        if (preg_match('/(steuerbescheid|einkommensteuer|umsatzsteuer|gewerbesteuer|steuererklärung|steuernummer|finanzamt)/', $lower)) {
            return 'tax';
        }

        if (preg_match('/(versicherungsschein|police-nr|schadennummer|krankenkasse|beitragssatz|haftpflicht|hausrat|kaskoversicherung)/', $lower)) {
            return 'insurance';
        }

        if (preg_match('/(rechnung|rechnungsnummer|rechnungs-nr|invoice|rechnungsbetrag|zahlungsziel|fälligkeitsdatum|gesamtbetrag brutto|ust-idnr)/', $lower)) {
            return 'invoice';
        }

        if (preg_match('/(vertrag|vertragsnummer|vertragsunterlagen|vereinbarung|arbeitsvertrag|mietvertrag|kündigungsbestätigung)/', $lower)) {
            return 'contract';
        }

        return null;
    }

    protected function detectAmount(string $text): ?float
    {
        $patterns = [
            '/(?:rechnungsbetrag|gesamtbetrag|endbetrag|zu zahlen|überweisungsbetrag|brutto(?:betrag)?|total)\s*(?:in\s*eur|eur|€)?\s*[:\.]?\s*([0-9]{1,3}(?:\.[0-9]{3})*,[0-9]{2})/i',
            '/(?:rechnungsbetrag|gesamtbetrag|endbetrag|zu zahlen|überweisungsbetrag|brutto(?:betrag)?|total)\s*(?:in\s*eur|eur|€)?\s*[:\.]?\s*([0-9]+,[0-9]{2})/i',
            '/([0-9]{1,3}(?:\.[0-9]{3})*,[0-9]{2})\s*(?:€|eur)/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                $cleaned = str_replace('.', '', $matches[1]);
                $cleaned = str_replace(',', '.', $cleaned);
                $val = (float) $cleaned;
                if ($val > 0 && $val < 1000000) {
                    return $val;
                }
            }
        }

        return null;
    }

    protected function detectDate(string $text): ?string
    {
        $patterns = [
            '/(?:rechnungsdatum|datum|ausstellungsdatum|vom)\s*[:\.]?\s*([0-3]?[0-9][\.\/\-][0-1]?[0-9][\.\/\-](?:20[0-9]{2}|19[0-9]{2}))/i',
            '/\b([0-3]?[0-9]\.[0-1]?[0-9]\.(?:20[0-9]{2}|19[0-9]{2}))\b/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                $dateStr = str_replace('/', '.', $matches[1]);
                $parts = explode('.', $dateStr);
                if (count($parts) === 3) {
                    $day = str_pad($parts[0], 2, '0', STR_PAD_LEFT);
                    $month = str_pad($parts[1], 2, '0', STR_PAD_LEFT);
                    $year = $parts[2];
                    if (checkdate((int) $month, (int) $day, (int) $year)) {
                        return "{$year}-{$month}-{$day}";
                    }
                }
            }
        }

        return null;
    }

    protected function detectTags(string $lower): array
    {
        $tags = [];
        $map = [
            'strom' => ['strom', 'kwh', 'stromrechnung', 'stromverbrauch', 'abschlag'],
            'gas' => ['gasrechnung', 'gasverbrauch', 'erdgas'],
            'wasser' => ['wasserrechnung', 'abwasser', 'trinkwasser'],
            'internet' => ['dsl', 'glasfaser', 'breitband', 'router', 'fritzbox'],
            'mobilfunk' => ['mobilfunk', 'sim-karte', 'tarif', 'roaming', 'lte', '5g'],
            'kfz' => ['kfz', 'kraftfahrzeug', 'auto', 'tüv', 'hauptuntersuchung', 'kennzeichen'],
            'krankenkasse' => ['krankenkasse', 'krankenversicherung', 'gesundheit'],
            'gehalt' => ['gehaltsabrechnung', 'lohnabrechnung', 'brutto-netto', 'entgeltabrechnung'],
            'wohnung' => ['miete', 'mietvertrag', 'nebenkosten', 'hausverwaltung', 'warmmiete'],
            'steuer' => ['steuerbescheid', 'einkommensteuer', 'finanzamt', 'steuernummer'],
            'abo' => ['abonnement', 'monatlich', 'mitgliedschaft'],
        ];

        foreach ($map as $tag => $keywords) {
            foreach ($keywords as $kw) {
                if (str_contains($lower, $kw)) {
                    $tags[] = $tag;
                    break;
                }
            }
        }

        return $tags;
    }

    protected function isGenericTitle(string $title): bool
    {
        if (empty($title)) {
            return true;
        }

        $lower = strtolower($title);

        if (preg_match('/^(scan|doc|dokument|file|pdf|img|image|unbenannt)[\s_\-\d]*$/i', $lower)) {
            return true;
        }

        if (preg_match('/^[0-9_\-\.\s]{5,}$/', $lower)) {
            return true;
        }

        return false;
    }
}
