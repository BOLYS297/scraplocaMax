<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Spatie\Browsershot\Browsershot;
use Symfony\Component\DomCrawler\Crawler;
use App\Models\rental_sources as RentalSource;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class Scrape_rentals extends Command
{
    protected $signature = 'app:scrape_rentals
        {startUrl : URL de départ}
        {--max-pages=200 : nombre max de pages à visiter}
        {--max-depth=3 : profondeur max de crawl}
        {--delay=800 : délai entre requêtes en ms}
        {--browser-path=null : chemin vers binaire du navigateur (override .env)}
        {--no-sandbox=true : activer --no-sandbox (true/false)}
        {--user-agent=null : User-Agent à utiliser (override .env)}';

    protected $description = 'Crawl un site de locations et extrait les sources de locations (agences, particuliers)';

    // runtime
    protected array $visited = [];
    protected int $processed = 0;
    protected int $saved = 0;

    public function handle()
    {
        $startUrl = $this->argument('startUrl');
        $maxPages = (int)$this->option('max-pages');
        $maxDepth = (int)$this->option('max-depth');
        $delayMs = (int)$this->option('delay');

        $envBrowserPath = env('BROWSER_PATH', 'C:\Program Files\Google\Chrome\Application\chrome.exe');
        $browserPath = $this->option('browser-path') !== 'null' ? $this->option('browser-path') : $envBrowserPath;
        $noSandboxOpt = $this->option('no-sandbox') === 'true' || (string)env('BROWSERSHOT_NO_SANDBOX', 'true') === 'true';
        $envUA = env('SCRAPER_USER_AGENT', null);
        $userAgent = $this->option('user-agent') !== 'null' ? $this->option('user-agent') : ($envUA ?? 'Mozilla/5.0 (compatible; ScrapBot/1.0)');

        $startUrl = $this->normalizeUrl($startUrl);
        $homeHost = parse_url($startUrl, PHP_URL_HOST);

        $this->info("Crawl démarré → $startUrl");
        $this->info("Domaine autorisé : $homeHost");
        $this->info("MaxPages: $maxPages | MaxDepth: $maxDepth | Delay: {$delayMs}ms | BrowserPath: $browserPath");

        $queue = new \SplQueue();
        $queue->enqueue(['url' => $startUrl, 'depth' => 0]);

        // progressbar max = maxPages (s'affiche dans CMD)
        $bar = $this->output->createProgressBar($maxPages);
        $bar->start();

        while (!$queue->isEmpty() && $this->processed < $maxPages) {
            $item = $queue->dequeue();
            $url = $item['url'];
            $depth = $item['depth'];

            // normalize absolute url
            $url = $this->normalizeUrl($url, $startUrl);

            if (isset($this->visited[$url])) {
                continue;
            }

            // mark visited
            $this->visited[$url] = true;

            $this->info("\nVisite #".($this->processed + 1)." → $url (depth: $depth)");

            // load html with Browsershot
            try {
                $html = $this->loadPageHtml(
                    $url,
                    $browserPath,
                    $noSandboxOpt,
                    $userAgent,
                    $delayMs
                );
            } catch (\Throwable $e) {
                $this->error("Erreur chargement $url : " . $e->getMessage());
                $bar->advance();
                $this->processed++;
                // Wait polite delay
                usleep($delayMs * 1000);
                continue;
            }

            // parse with Crawler
            $crawler = new Crawler($html, $url);

            // extract items on this page (listings or single)
            $items = $this->extractItemsFromPage($crawler, $url);

            $this->info(" → ".count($items)." item(s) extraits sur la page");

            // save items (transaction per item to avoid partial failure)
            foreach ($items as $i) {
                // guarantee minimal city
                $city = $i['city'] ?? 'Unknown';

                try {
                    DB::transaction(function () use ($i, $url, $city) {
                        $srcUrl = $i['url'] ?? $url;

                        $entry = RentalSource::updateOrCreate(
                            ['source_url' => $srcUrl],
                            [
                                'source_type' => $i['source_type'] ?? $this->guessSourceType($i),
                                'name_or_title' => $i['title'] ?? ($i['name'] ?? 'N/A'),
                                'phone_number' => $this->cleanPhone($i['phone'] ?? null),
                                'email' => $i['email'] ?? null,
                                'property_type' => $i['property_type'] ?? null,
                                'city' => $city,
                                'district' => $i['district'] ?? null,
                                'is_qualified' => !empty($i['phone']),
                            ]
                        );

                        if ($entry->wasRecentlyCreated) {
                            $this->saved++;
                        }
                    });
                } catch (\Throwable $e) {
                    $this->error("Erreur sauvegarde item: " . $e->getMessage());
                }
            }

            // if depth allowed, enqueue internal links
            if ($depth < $maxDepth) {
                $links = $this->extractLinks($crawler);
                $this->info(" → ".count($links)." lien(s) trouvés sur la page");

                foreach ($links as $link) {
                    $norm = $this->normalizeUrl($link, $url);
                    if ($this->isSameDomain($norm, $homeHost) && !$this->looksLikeResource($norm) && !isset($this->visited[$norm])) {
                        $queue->enqueue(['url' => $norm, 'depth' => $depth + 1]);
                    }
                }
            }

            $bar->advance();
            $this->processed++;

            // polite delay (prevent DDOS)
            usleep($delayMs * 1000);
        }

        $bar->finish();
        $this->newLine();
        $this->info("Crawl terminé. Pages traitées: {$this->processed}. Nouveaux enregistrements: {$this->saved}.");
        $this->info("Total en base: " . RentalSource::count());

        return 0;
    }

    /**
     * Charge une page via Browsershot (avec fallback noSandbox).
     */
    protected function loadPageHtml(string $url, string $browserPath, bool $noSandbox, string $userAgent, int $delayMs): string
    {
        $baseOptions = [
            '--window-size=1280,900',
            '--disable-dev-shm-usage',
            '--disable-accelerated-2d-canvas',
            '--disable-gpu',
        ];

        if ($noSandbox) {
            $baseOptions[] = '--no-sandbox';
            $baseOptions[] = '--disable-setuid-sandbox';
        }

        // option to set custom user agent and args
        try {
            $bs = Browsershot::url($url)
                ->setOption('args', $baseOptions)
                ->setChromePath($browserPath)
                ->setUserAgent($userAgent)
                ->waitUntilNetworkIdle()
                ->setDelay(max(500, $delayMs))
                ->timeout(60000)
                ->windowSize(1280, 900);

            // get HTML final
            return $bs->bodyHtml();
        } catch (\Throwable $e) {
            // fallback: try with noSandbox forced
            try {
                $fallbackArgs = $baseOptions;
                if (!in_array('--no-sandbox', $fallbackArgs)) {
                    $fallbackArgs[] = '--no-sandbox';
                    $fallbackArgs[] = '--disable-setuid-sandbox';
                }
                return Browsershot::url($url)
                    ->setOption('args', $fallbackArgs)
                    ->setChromePath($browserPath)
                    ->setUserAgent($userAgent)
                    ->noSandbox()
                    ->waitUntilNetworkIdle()
                    ->setDelay(max(500, $delayMs))
                    ->timeout(90000)
                    ->windowSize(1280, 900)
                    ->bodyHtml();
            } catch (\Throwable $e2) {
                throw $e2;
            }
        }
    }

    /**
     * Extrait items depuis une page : tente de trouver des listings (blocs) sinon tente d'extraire la page comme fiche unique.
     */
    protected function extractItemsFromPage(Crawler $crawler, string $baseUrl): array
    {
        $candidates = [];

        // selectors probables pour listes
        $listSelectors = ['.annonce', '.listing', '.item', '.card', 'article', '.property', '.result', '.offer', '.listing-item'];

        foreach ($listSelectors as $sel) {
            if ($crawler->filter($sel)->count() > 0) {
                $crawler->filter($sel)->each(function (Crawler $node) use (&$candidates, $baseUrl) {
                    $title = $this->tryText($node, ['.titre', '.title', 'h2', 'h3', '.name', '.offer-title']);
                    $phone = $this->tryText($node, ['.tel', '.phone', '.contact', '.telephone']);
                    $email = $this->tryAttr($node, ['a[href^="mailto:"]'], 'href') ? $this->stripMailto($this->tryAttr($node, ['a[href^="mailto:"]'], 'href')) : $this->extractEmail($node->html());
                    $city = $this->tryText($node, ['.city', '.ville', '.location', '.place']) ?? $this->extractCityFromText($node->text());
                    $url = $this->tryAttr($node, ['a'], 'href') ? $this->normalizeUrl($this->tryAttr($node, ['a'], 'href'), $baseUrl) : $baseUrl;
                    $propertyType = $this->tryText($node, ['.type', '.property-type']);
                    $district = $this->tryText($node, ['.district', '.quartier']);

                    $candidates[] = [
                        'title' => $title,
                        'phone' => $phone ? $this->cleanPhone($phone) : null,
                        'email' => $email,
                        'city' => $city,
                        'url' => $url,
                        'property_type' => $propertyType,
                        'district' => $district,
                    ];
                });

                if (!empty($candidates)) {
                    return $candidates;
                }
            }
        }

        // fallback : attempt page-level extraction
        $pageText = $crawler->filter('body')->count() ? $crawler->filter('body')->text() : $crawler->html();
        $phone = $this->extractPhone($pageText);
        $email = $this->extractEmail($pageText);
        $title = $this->extractTitleFromCrawler($crawler) ?? $this->extractMetaTitle($crawler);
        $city = $this->extractCityFromText($pageText) ?? 'Unknown';
        $propertyType = $this->guessPropertyTypeFromText($pageText);
        $district = $this->extractDistrictFromText($pageText);

        if ($title || $phone || $email) {
            return [[
                'title' => $title ?? 'N/A',
                'phone' => $this->cleanPhone($phone),
                'email' => $email,
                'city' => $city,
                'url' => $baseUrl,
                'property_type' => $propertyType,
                'district' => $district,
            ]];
        }

        return [];
    }

    // -------------------- helpers --------------------

    protected function tryText(Crawler $c, array $selectors)
    {
        foreach ($selectors as $s) {
            try {
                $node = $c->filter($s);
                if ($node->count()) {
                    $t = trim($node->first()->text(null));
                    if ($t !== '') return $t;
                }
            } catch (\Throwable $e) { /* ignore */ }
        }
        return null;
    }

    protected function tryAttr(Crawler $c, array $selectors, string $attr)
    {
        foreach ($selectors as $s) {
            try {
                $node = $c->filter($s);
                if ($node->count() && $node->first()->attr($attr)) {
                    return trim($node->first()->attr($attr));
                }
            } catch (\Throwable $e) { /* ignore */ }
        }
        return null;
    }

    protected function stripMailto($val)
    {
        if (!$val) return null;
        return preg_replace('/^mailto:/i','',$val);
    }

    protected function extractLinks(Crawler $crawler): array
    {
        $links = $crawler->filter('a')->each(function (Crawler $node) {
            return $node->attr('href');
        });

        $out = [];
        foreach ($links as $href) {
            if (!$href) continue;
            if (Str::startsWith($href, ['#','javascript:','mailto:','tel:'])) continue;
            $out[] = $href;
        }
        return array_values(array_unique($out));
    }

    protected function normalizeUrl(string $url, ?string $base = null): string
    {
        $url = trim($url);
        if ($base && !Str::startsWith($url, ['http://','https://'])) {
            if (Str::startsWith($url, '//')) {
                $scheme = parse_url($base, PHP_URL_SCHEME) ?: 'https';
                return $scheme . ':' . $url;
            }
            // relative path
            $base = rtrim($base, '/');
            return $base . '/' . ltrim($url, '/');
        }
        return $url;
    }

    protected function isSameDomain(string $url, string $homeHost): bool
    {
        $h = parse_url($url, PHP_URL_HOST) ?: '';
        return $h === $homeHost || str_ends_with($h, '.'.$homeHost);
    }

    protected function looksLikeResource(string $url): bool
    {
        $no = ['.jpg','.jpeg','.png','.gif','.svg','.webp','.pdf','.zip','.rar','.mp4','.mp3','.css','.js'];
        foreach ($no as $ext) if (Str::endsWith(strtolower($url), $ext)) return true;
        return false;
    }

    protected function extractPhone(?string $text = null) : ?string
    {
        if (!$text) return null;
        if (preg_match('/(\+?\d[\d\-\s]{6,}\d)/', $text, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    protected function cleanPhone($phone)
    {
        if (!$phone) return null;
        $clean = preg_replace('/[^\d+]/','',$phone);
        if (strlen(preg_replace('/\D/','',$clean)) < 6) return null;
        return $clean;
    }

    protected function extractEmail(?string $text = null) : ?string
    {
        if (!$text) return null;
        if (preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $text, $m)) {
            return $m[0];
        }
        return null;
    }

    protected function extractMetaTitle(Crawler $c)
    {
        try {
            $t = $c->filter('meta[property="og:title"]')->first()->attr('content') ?? null;
            if ($t) return trim($t);
        } catch (\Throwable $e) {}
        try {
            $t = $c->filter('title')->first()->text();
            if ($t) return trim($t);
        } catch (\Throwable $e) {}
        return null;
    }

    protected function extractTitleFromCrawler(Crawler $c)
    {
        return $this->tryText($c, ['h1','h2','.title','.heading','.post-title']);
    }

    protected function extractCityFromText(string $text)
    {
        if (preg_match('/Ville[:\s]+([A-Za-zÀ-ÿ\-\']{2,})/i', $text, $m)) return trim($m[1]);
        if (preg_match('/(Douala|Yaounde|Yaoundé|Lagos|Abidjan|Bamako|Dakar)/i', $text, $m)) return trim($m[1]);
        return null;
    }

    protected function extractDistrictFromText(string $text)
    {
        if (preg_match('/(quartier|district|arrondissement)[:\s]+([A-Za-z0-9\-\s]+)/i', $text, $m)) {
            return trim($m[2]);
        }
        return null;
    }

    protected function guessPropertyTypeFromText(string $text)
    {
        $keywords = ['apartment'=>'Apartment','appartement'=>'Appartement','maison'=>'Maison','studio'=>'Studio','chambre'=>'Chambre','villa'=>'Villa'];
        foreach ($keywords as $k=>$v) {
            if (stripos($text, $k) !== false) return $v;
        }
        return null;
    }

    protected function guessSourceType(array $item)
    {
        $hay = strtolower(($item['title'] ?? '') . ' ' . ($item['property_type'] ?? ''));
        if (str_contains($hay, 'agence') || str_contains($hay, 'agency') || str_contains($hay, 'immobili')) {
            return 'AGENCY';
        }
        return 'PRIVATE';
    }
}
