<?php

namespace Modules\Documents\Services;

class DocumentOcrService
{
    /**
     * Extract text from PDF or image using pdftotext or fast tesseract.
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
     * Uses fast 150 DPI and --psm 3 (no slow OSD orientation check).
     */
    protected function ocrPdfPages(string $filePath): ?string
    {
        $escaped = escapeshellarg($filePath);
        $tempPrefix = '/tmp/doc_ocr_' . uniqid();

        // Convert first 2 pages at 150 DPI for fast & sharp recognition
        shell_exec("pdftoppm -png -r 150 -f 1 -l 2 {$escaped} {$tempPrefix} 2>/dev/null");

        $images = glob("{$tempPrefix}-*.png");
        if (empty($images)) {
            return null;
        }

        sort($images);
        $allText = '';

        foreach ($images as $img) {
            $imgEscaped = escapeshellarg($img);
            $pageText = shell_exec("tesseract {$imgEscaped} stdout -l deu+eng --psm 3 2>/dev/null");
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
        $output = shell_exec("tesseract {$escaped} stdout -l deu+eng --psm 3 2>/dev/null");

        return $output ? $this->cleanText($output) : null;
    }

    /**
     * Clean and normalize OCR / extracted text.
     */
    protected function cleanText(string $text): string
    {
        $text = preg_replace("/\n{3,}/", "\n\n", $text);
        return trim($text);
    }

    /**
     * Analyze extracted text and classify document metadata:
     * - Document Type / Was (Kontoauszug, Gehaltsabrechnung, Stromrechnung, Steuerbescheid, etc.)
     * - Vendor / Aussteller (Sparkasse, Telekom, Amazon, Allianz, etc.)
     * - Category (invoice, contract, insurance, tax, other)
     * - Amount & Currency
     * - Date & Month/Year period (e.g. "Oktober 2024")
     * - Meaningful Title (e.g. "Kontoauszug Sparkasse - Oktober 2024")
     * - Tags
     */
    public function analyze(string $text, array $current = []): array
    {
        $result = [];
        $lower = mb_strtolower($text);

        // 1. Detect Vendor / Bank / Institution
        $vendor = $this->detectVendor($text, $lower);
        if ($vendor) {
            $result['vendor'] = $vendor;
        }

        // 2. Detect Specific Document Type ("Was")
        $docType = $this->detectDocType($lower);

        // 3. Detect Category
        $category = $this->detectCategory($lower, $docType);
        if ($category) {
            $result['category'] = $category;
        }

        // 4. Detect Amount
        $amount = $this->detectAmount($text);
        if ($amount !== null && empty($current['amount'])) {
            $result['amount'] = $amount;
            $result['currency'] = 'EUR';
        }

        // 5. Detect Date (issued_on)
        $date = $this->detectDate($text);
        if ($date && empty($current['issued_on'])) {
            $result['issued_on'] = $date;
        }

        // 6. Detect Period / Month & Year (e.g. "Oktober 2024")
        $period = $this->detectPeriod($text, $lower, $date ?: ($current['issued_on'] ?? null));

        // 7. Detect Tags
        $detectedTags = $this->detectTags($lower, $docType);
        $existingTags = is_array($current['tags'] ?? null) ? $current['tags'] : [];
        $mergedTags = array_values(array_unique(array_merge($existingTags, $detectedTags)));
        if (! empty($mergedTags)) {
            $result['tags'] = $mergedTags;
        }

        // 8. Generate Precise, Human-Readable Title
        $v = $result['vendor'] ?? ($current['vendor'] ?? null);
        $smartTitle = $this->generateTitle($docType, $v, $period, $date ?: ($current['issued_on'] ?? null), $result['amount'] ?? ($current['amount'] ?? null));

        $currentTitle = $current['title'] ?? '';
        if ($smartTitle && ($this->isGenericTitle($currentTitle) || empty($currentTitle))) {
            $result['title'] = $smartTitle;
        }

        return $result;
    }

    /**
     * Detect concrete document type ("Was").
     */
    protected function detectDocType(string $lower): string
    {
        if (str_contains($lower, 'kontoauszug') || (str_contains($lower, 'auszug') && str_contains($lower, 'iban'))) {
            return 'Kontoauszug';
        }
        if (str_contains($lower, 'kreditkartenabrechnung') || str_contains($lower, 'kreditkarten-abrechnung')) {
            return 'Kreditkartenabrechnung';
        }
        if (preg_match('/(gehaltsabrechnung|entgeltabrechnung|lohnabrechnung|verdienstbescheinigung)/', $lower)) {
            return 'Gehaltsabrechnung';
        }
        if (preg_match('/(steuerbescheid|einkommensteuerbescheid|festsetzungsbescheid)/', $lower)) {
            return 'Einkommensteuerbescheid';
        }
        if (preg_match('/(stromrechnung|stromabrechnung|jahresabrechnung strom)/', $lower)) {
            return 'Stromrechnung';
        }
        if (preg_match('/(gasrechnung|gasabrechnung|jahresabrechnung gas)/', $lower)) {
            return 'Gasrechnung';
        }
        if (preg_match('/(wasserrechnung|wasserabrechnung)/', $lower)) {
            return 'Wasserrechnung';
        }
        if (preg_match('/(nebenkostenabrechnung|betriebskostenabrechnung)/', $lower)) {
            return 'Nebenkostenabrechnung';
        }
        if (preg_match('/(mobilfunkrechnung|handyrechnung|monatsrechnung mobilfunk)/', $lower)) {
            return 'Mobilfunkrechnung';
        }
        if (preg_match('/(festnetz|internet-rechnung|dsl-rechnung|glasfaser-rechnung)/', $lower)) {
            return 'Internet-/Festnetzrechnung';
        }
        if (preg_match('/(kfz-versicherung|kraftfahrt-versicherung|fahrzeug-versicherung)/', $lower)) {
            return 'Kfz-Versicherung';
        }
        if (preg_match('/(haftpflichtversicherung|privathaftpflicht)/', $lower)) {
            return 'Haftpflichtversicherung';
        }
        if (preg_match('/(hausratversicherung|wohngebäudeversicherung)/', $lower)) {
            return 'Hausrat-/Wohngebäudeversicherung';
        }
        if (preg_match('/(krankenversicherung|versicherungsnachweis|mitgliedsbescheinigung)/', $lower)) {
            return 'Krankenversicherung';
        }
        if (preg_match('/(arbeitsvertrag|anstellungsvertrag)/', $lower)) {
            return 'Arbeitsvertrag';
        }
        if (preg_match('/(mietvertrag|wohnungsmietvertrag)/', $lower)) {
            return 'Mietvertrag';
        }
        if (preg_match('/(mahnung|zahlungserinnerung)/', $lower)) {
            return 'Zahlungserinnerung';
        }
        if (preg_match('/(bestellbestätigung|auftragsbestätigung)/', $lower)) {
            return 'Auftragsbestätigung';
        }
        if (preg_match('/(rechnung|invoice|rechnungs-nr|rechnungsnummer)/', $lower)) {
            return 'Rechnung';
        }
        if (preg_match('/(vertrag|police|versicherungsschein)/', $lower)) {
            return 'Vertrag/Police';
        }

        return 'Dokument';
    }

    /**
     * Detect period / month & year (e.g. "Oktober 2024").
     */
    protected function detectPeriod(string $text, string $lower, ?string $fallbackDate): ?string
    {
        $months = [
            1 => 'Januar', 2 => 'Februar', 3 => 'März', 4 => 'April',
            5 => 'Mai', 6 => 'Juni', 7 => 'Juli', 8 => 'August',
            9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Dezember',
        ];

        // Search for Month + Year pattern, e.g. "Oktober 2024" or "Abrechnung für Oktober 2024"
        foreach ($months as $num => $monthName) {
            $mLower = mb_strtolower($monthName);
            if (preg_match("/(?:abrechnung|zeitraum|monat|für|auszug|stand|vom)?\s*{$mLower}\s*(20[12][0-9])/iu", $lower, $matches)) {
                return "{$monthName} {$matches[1]}";
            }
        }

        // Search for "10/2024" or "10.2024"
        if (preg_match('/\b(0?[1-9]|1[0-2])[\.\/](20[12][0-9])\b/', $text, $matches)) {
            $mNum = (int) $matches[1];
            $year = $matches[2];
            if (isset($months[$mNum])) {
                return "{$months[$mNum]} {$year}";
            }
        }

        // Fallback to month of issued date if available
        if ($fallbackDate && preg_match('/^(20[12][0-9])-(0?[1-9]|1[0-2])-[0-3][0-9]$/', $fallbackDate, $m)) {
            $mNum = (int) $m[2];
            $year = $m[1];
            if (isset($months[$mNum])) {
                return "{$months[$mNum]} {$year}";
            }
        }

        return null;
    }

    /**
     * Generate descriptive title.
     * E.g. "Kontoauszug Sparkasse - Oktober 2024"
     */
    protected function generateTitle(string $docType, ?string $vendor, ?string $period, ?string $date, ?float $amount): string
    {
        $parts = [];

        // Main type & vendor
        if ($docType === 'Kontoauszug') {
            $main = $vendor ? "Kontoauszug {$vendor}" : 'Kontoauszug';
        } elseif ($docType === 'Gehaltsabrechnung') {
            $main = $vendor ? "Gehaltsabrechnung {$vendor}" : 'Gehaltsabrechnung';
        } elseif ($vendor && $docType !== 'Dokument') {
            $main = "{$vendor} {$docType}";
        } elseif ($vendor) {
            $main = $vendor;
        } else {
            $main = $docType;
        }

        $parts[] = $main;

        // Add Period or Date
        if ($period) {
            $parts[] = $period;
        } elseif ($date) {
            $parts[] = $date;
        }

        $title = implode(' - ', $parts);

        // Add amount if invoice and reasonable
        if (in_array($docType, ['Rechnung', 'Stromrechnung', 'Gasrechnung', 'Mobilfunkrechnung'], true) && $amount) {
            $formatted = number_format($amount, 2, ',', '.') . ' €';
            $title .= " ({$formatted})";
        }

        return $title;
    }

    protected function detectVendor(string $text, string $lower): ?string
    {
        $knownVendors = [
            'Sparkasse' => ['sparkasse', 'kreissparkasse', 'stadtsparkasse', 'berliner sparkasse', 'frankfurter sparkasse', 'haspa'],
            'Volksbank / Raiffeisenbank' => ['volksbank', 'raiffeisenbank', 'vr bank', 'vr-bank'],
            'Deutsche Bank' => ['deutsche bank ag', 'deutsche bank'],
            'Commerzbank' => ['commerzbank ag', 'commerzbank', 'comdirect'],
            'ING' => ['ing-diba', 'ing diba', ' ing '],
            'DKB' => ['deutsche kreditbank', 'dkb ag', ' dkb '],
            'Postbank' => ['postbank', 'deutsche postbank'],
            'N26' => ['n26 bank', ' n26 '],
            'Trade Republic' => ['trade republic'],
            'Scalable Capital' => ['scalable capital', 'scalable'],
            'Telekom' => ['deutsche telekom', 'telekom deutschland', 't-mobile', 't-home', 'congstar'],
            'Vodafone' => ['vodafone gmbh', 'vodafone west', 'vodafone kabel', 'vodafone'],
            'O2 / Telefónica' => ['telefónica germany', 'telefonica germany', 'o2 online', ' o2 '],
            '1&1' => ['1&1 telecom', '1&1 mail & media', '1&1 internet'],
            'Amazon' => ['amazon eu s.a.r.l', 'amazon payments', 'amazon.de', 'amazon web services', ' aws '],
            'Apple' => ['apple distribution international', 'apple inc.', 'apple services', 'itunes'],
            'Google' => ['google ireland', 'google commerce', 'google cloud', 'google play'],
            'PayPal' => ['paypal (europe)', 'paypal s.à r.l', 'paypal'],
            'Klarna' => ['klarna bank', 'klarna'],
            'eBay' => ['ebay gmbh', 'ebay international'],
            'Allianz' => ['allianz versicherungs', 'allianz private krankenversicherungs', 'allianz lebensversicherungs', 'allianz'],
            'HUK-Coburg' => ['huk-coburg', 'huk24', 'huk coburg'],
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
            'Stadtwerke München (SWM)' => ['stadtwerke münchen', 'swm infrastructure'],
            'Stadtwerke' => ['stadtwerke'],
            'Vattenfall' => ['vattenfall europe', 'vattenfall'],
            'E.ON' => ['e.on energie', 'e.on'],
            'EnBW' => ['enbw energie', 'enbw'],
            'Mainova' => ['mainova ag', 'mainova'],
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

        // Try extracting sender company name from early lines
        $lines = array_slice(explode("\n", $text), 0, 20);
        foreach ($lines as $line) {
            $line = trim($line);
            if (preg_match('/^([A-ZÄÖÜ][A-Za-z0-9ÄÖÜäöüß\s\.\-&]{2,40}\s+(?:GmbH|AG|UG|GbR|e\.K\.|e\.V\.|KG))/u', $line, $matches)) {
                return trim($matches[1]);
            }
        }

        return null;
    }

    protected function detectCategory(string $lower, string $docType): ?string
    {
        if (in_array($docType, ['Einkommensteuerbescheid'], true) || preg_match('/(steuerbescheid|finanzamt|einkommensteuer|umsatzsteuer|gewerbesteuer)/', $lower)) {
            return 'tax';
        }

        if (str_contains($docType, 'versicherung') || preg_match('/(versicherungsschein|police-nr|schadennummer|krankenkasse|haftpflicht|hausrat)/', $lower)) {
            return 'insurance';
        }

        if (in_array($docType, ['Rechnung', 'Stromrechnung', 'Gasrechnung', 'Wasserrechnung', 'Mobilfunkrechnung', 'Internet-/Festnetzrechnung', 'Nebenkostenabrechnung'], true)
            || preg_match('/(rechnung|rechnungsnummer|rechnungs-nr|invoice|rechnungsbetrag|zahlungsziel|fälligkeitsdatum|gesamtbetrag brutto|ust-idnr)/', $lower)) {
            return 'invoice';
        }

        if (in_array($docType, ['Mietvertrag', 'Arbeitsvertrag', 'Vertrag/Police'], true)
            || preg_match('/(vertrag|vertragsnummer|vertragsunterlagen|vereinbarung|arbeitsvertrag|mietvertrag|kündigungsbestätigung)/', $lower)) {
            return 'contract';
        }

        if (in_array($docType, ['Kontoauszug', 'Kreditkartenabrechnung', 'Gehaltsabrechnung'], true)) {
            return 'receipt';
        }

        return null;
    }

    protected function detectAmount(string $text): ?float
    {
        $patterns = [
            '/(?:rechnungsbetrag|gesamtbetrag|endbetrag|zu zahlen|überweisungsbetrag|brutto(?:betrag)?|total|auszahlungsbetrag|rechnungsabschluss|neuer kontostand|endsaldo)\s*(?:in\s*eur|eur|€)?\s*[:\.]?\s*([0-9]{1,3}(?:\.[0-9]{3})*,[0-9]{2})/i',
            '/(?:rechnungsbetrag|gesamtbetrag|endbetrag|zu zahlen|überweisungsbetrag|brutto(?:betrag)?|total)\s*(?:in\s*eur|eur|€)?\s*[:\.]?\s*([0-9]+,[0-9]{2})/i',
            '/([0-9]{1,3}(?:\.[0-9]{3})*,[0-9]{2})\s*(?:€|eur)/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                $cleaned = str_replace('.', '', $matches[1]);
                $cleaned = str_replace(',', '.', $cleaned);
                $val = (float) $cleaned;
                if ($val > 0 && $val < 10000000) {
                    return $val;
                }
            }
        }

        return null;
    }

    protected function detectDate(string $text): ?string
    {
        $patterns = [
            '/(?:rechnungsdatum|datum|ausstellungsdatum|vom|kontoauszug vom)\s*[:\.]?\s*([0-3]?[0-9][\.\/\-][0-1]?[0-9][\.\/\-](?:20[0-9]{2}|19[0-9]{2}))/i',
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

    protected function detectTags(string $lower, string $docType): array
    {
        $tags = [];

        if ($docType === 'Kontoauszug') {
            $tags[] = 'kontoauszug';
            $tags[] = 'bank';
        }
        if ($docType === 'Gehaltsabrechnung') {
            $tags[] = 'gehalt';
        }

        $map = [
            'strom' => ['strom', 'kwh', 'stromrechnung', 'stromverbrauch', 'abschlag'],
            'gas' => ['gasrechnung', 'gasverbrauch', 'erdgas'],
            'wasser' => ['wasserrechnung', 'abwasser', 'trinkwasser'],
            'internet' => ['dsl', 'glasfaser', 'breitband', 'router', 'fritzbox'],
            'mobilfunk' => ['mobilfunk', 'sim-karte', 'tarif', 'roaming', 'lte', '5g'],
            'kfz' => ['kfz', 'kraftfahrzeug', 'auto', 'tüv', 'hauptuntersuchung', 'kennzeichen'],
            'krankenkasse' => ['krankenkasse', 'krankenversicherung', 'gesundheit'],
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
