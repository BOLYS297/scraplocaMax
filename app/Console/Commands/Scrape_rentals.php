<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Spatie\Browsershot\Browsershot;
use Symfony\Component\DomCrawler\Crawler;
use App\Models\rental_sources as RentalSource;
use Illuminate\Support\Str;

class Scrape_rentals extends Command
{
    protected $signature = 'app:scrape_rentals
        {startUrl : URL de départ}
        {--max-pages=200 : nombre max de pages à visiter}
        {--max-depth=3 : profondeur max de crawl}
        {--delay=1000 : délai entre requêtes en ms}';

    protected $description = 'Crawl un site web à partir d\'une URL donnée, extrait les annonces de locations et les stocke en base de données.';

    // runtime state
    protected array $visited = [];
    protected int $processed = 0;

    public function handle()
    {
        $startUrl = $this->argument('startUrl');
        $maxPages = (int) $this->option('max-pages');
        $maxDepth = (int) $this->option('max-depth');
        $delayMs = (int) $this->option('delay');

        // normaliser url de départ
        $startUrl = $this->normalizeUrl($startUrl);
        $homeHost = parse_url($startUrl, PHP_URL_HOST);

        $this->info("Début du crawl à partir de : $startUrl");
        $this->info("Domaine autorisé : $homeHost");
        $this->info("Max pages: $maxPages | Max depth: $maxDepth | Delay: {$delayMs}ms");

        // queue d'urls: chaque item = ['url'=>..., 'depth'=>int]
        $queue = new \SplQueue();
        $queue->enqueue(['url' => $startUrl, 'depth' => 0]);

        // preparer progressbar
        $bar = $this->output->createProgressBar($maxPages);
        $bar->start();

        while (!$queue->isEmpty() && $this->processed < $maxPages) {
            $item = $queue->dequeue();
            $url = $item['url'];
            $depth = $item['depth'];

            // skip si déjà visité
            if (isset($this->visited[$url])) {
                continue;
            }

            // marque comme visité
            $this->visited[$url] = true;

            // log
            $this->info("\nVisite #".($this->processed+1)." → $url (depth: $depth)");

            // Charger page via Browsershot (retry avec noSandbox si plantage)
            try {
                $html = $this->loadPageHtml($url, $delayMs);
            } catch (\Throwable $e) {
                $this->error("Erreur chargement $url : ".$e->getMessage());
                $bar->advance();
                $this->processed++;
                // continuer
                continue;
            }

            // parse
            $crawler = new Crawler($html, $url);

            // 1) Extraction directe d'éventuelles annonces sur la page
            $items = $this->extractItemsFromPage($crawler, $url);

            $this->info(" → ".count($items)." item(s) extraits sur la page");

            // sauvegarde en base
            foreach ($items as $i) {
                // remplir tous les champs demandés (assurez-vous que city soit défini)
                $city = $i['city'] ?? 'Unknown';
                RentalSource::updateOrCreate(
                    ['source_url' => $i['url'] ?? $url],
                    [
                        'source_type' => $i['source_type'] ?? $this->guessSourceType($i),
                        'name_or_title' => $i['title'] ?? ($i['name'] ?? 'N/A'),
                        'phone_number' => $i['phone'] ?? null,
                        'email' => $i['email'] ?? null,
                        'property_type' => $i['property_type'] ?? null,
                        'city' => $city,
                        'district' => $i['district'] ?? null,
                        'is_qualified' => !empty($i['phone']),
                    ]
                );
            }

            // 2) Si profondeur autorisée, trouver les liens pour enqueuer
            if ($depth < $maxDepth) {
                $links = $this->extractLinks($crawler, $homeHost);
                $this->info(" → ".count($links)." lien(s) internes trouvés sur la page");

                foreach ($links as $link) {
                    $norm = $this->normalizeUrl($link, $url);
                    // conditions d'ajout : même domaine et non visité et pas de fragment
                    if ($this->isSameDomain($norm, $homeHost) && !isset($this->visited[$norm])) {
                        // éviter les ressources non-HTML (pdf, jpg, etc)
                        if ($this->looksLikeHtml($norm)) {
                            $queue->enqueue(['url' => $norm, 'depth' => $depth + 1]);
                        }
                    }
                }
            }

            // avancement
            $bar->advance();
            $this->processed++;

            // délai pour être poli
            usleep($delayMs * 1000);
        }

        $bar->finish();
        $this->newLine();
        $this->info("Crawl terminé. Pages traitées : {$this->processed}. Entrées en base: ".RentalSource::count());
        return 0;
    }

    /**
     * Utilise Browsershot pour récupérer le HTML rendu.
     * Si échec, retente en activant noSandbox().
     */
    protected function loadPageHtml(string $url, int $delayMs): string
    {
        try {
            $bs = Browsershot::url($url)
                ->waitUntilNetworkIdle()
                ->setDelay(max(500, $delayMs)) // attendre JS
                ->timeout(60000) // ms
                ->windowSize(1280, 900);
            return $bs->bodyHtml();
        } catch (\Throwable $e) {
            // essai de secours (noSandbox)
            try {
                return Browsershot::url($url)
                    ->noSandbox()
                    ->waitUntilNetworkIdle()
                    ->setDelay(max(500, $delayMs))
                    ->timeout(60000)
                    ->windowSize(1280, 900)
                    ->bodyHtml();
            } catch (\Throwable $e2) {
                throw $e2;
            }
        }
    }

    /**
     * Extrait les "items" (annonces / sources) depuis le HTML.
     * Utilise heuristiques : cherche éléments typiques (.annonce, .listing, article, .card)
     * Retourne un tableau d'items : ['title','phone','email','city','url',...]
     */
    protected function extractItemsFromPage(Crawler $crawler, string $baseUrl): array
    {
        $candidates = [];

        // 1) Si la page a des blocs d'annonce identifiables
        $selectors = ['.annonce', '.listing', '.item', '.card', 'article', '.property', '.result'];
        foreach ($selectors as $sel) {
            $nodes = $crawler->filter($sel);
            if ($nodes->count() > 0) {
                foreach ($nodes as $node) {
                    $nodeCrawler = new Crawler($node, $baseUrl);
                    $title = $this->tryText($nodeCrawler, ['.titre', '.title', 'h2', 'h3', '.name']);
                    $phone = $this->tryText($nodeCrawler, ['.tel', '.phone', '.contact', '.telephone']);
                    $email = $this->tryText($nodeCrawler, ['a[href^="mailto:"]']) ?? $this->extractEmail($nodeCrawler->html());
                    $city = $this->tryText($nodeCrawler, ['.city', '.ville', '.location']) ?? $this->extractCityFromText($nodeCrawler->text());
                    $url = $this->tryAttr($nodeCrawler, ['a'], 'href') ? $this->normalizeUrl($this->tryAttr($nodeCrawler, ['a'], 'href'), $baseUrl) : $baseUrl;
                    $propertyType = $this->tryText($nodeCrawler, ['.type', '.property-type']);
                    $district = $this->tryText($nodeCrawler, ['.district', '.quartier']);

                    $candidates[] = [
                        'title' => $title,
                        'phone' => $this->cleanPhone($phone),
                        'email' => $email,
                        'city' => $city,
                        'url' => $url,
                        'property_type' => $propertyType,
                        'district' => $district,
                    ];
                }
                // si on a trouvé sur ce selecteur, on peut retourner ces candidats
                if (!empty($candidates)) {
                    return $candidates;
                }
            }
        }

        // 2) Sinon, la page peut être une fiche détail -> extraire "page entière"
        $pageText = $crawler->filter('body')->count() ? $crawler->filter('body')->text() : $crawler->html();
        $phone = $this->extractPhone($pageText);
        $email = $this->extractEmail($pageText);
        $title = $this->extractTitleFromCrawler($crawler) ?? $this->extractMetaTitle($crawler);
        $city = $this->extractCityFromText($pageText) ?? 'Unknown';
        $propertyType = $this->guessPropertyTypeFromText($pageText);
        $district = $this->extractDistrictFromText($pageText);

        // if we found anything at page level, return single item
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

        return []; // rien d'extrait
    }

    protected function tryText(Crawler $c, array $selectors)
    {
        foreach ($selectors as $s) {
            try {
                $node = $c->filter($s);
                if ($node->count()) {
                    $t = trim($node->first()->text(null));
                    if ($t !== '') return $t;
                }
            } catch (\Exception $e) { /* ignore */ }
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
            } catch (\Exception $e) { /* ignore */ }
        }
        return null;
    }

    protected function extractLinks(Crawler $crawler, string $homeHost): array
    {
        $links = [];
        foreach ($crawler->filter('a') as $a) {
            $href = $a->getAttribute('href');
            if (!$href) continue;
            // skip javascript: links, mailto, tel
            if (Str::startsWith($href, ['#','javascript:','mailto:','tel:'])) continue;
            $links[] = $href;
        }
        // unique
        return array_values(array_unique($links));
    }

    // ---------- Helpers & heuristics ----------

    protected function normalizeUrl(string $url, ?string $base = null): string
    {
        // si url relative, construire absolute à partir de base
        if ($base && !Str::startsWith($url, ['http://', 'https://'])) {
            // gérer protocol-relative //domain/path
            if (Str::startsWith($url, '//')) {
                $scheme = parse_url($base, PHP_URL_SCHEME) ?: 'https';
                return $scheme . ':' . $url;
            }
            // path-relative
            return rtrim($base, '/') . '/' . ltrim($url, '/');
        }
        return $url;
    }

    protected function isSameDomain(string $url, string $homeHost): bool
    {
        $h = parse_url($url, PHP_URL_HOST);
        return $h === $homeHost || str_ends_with($h, '.'.$homeHost);
    }

    protected function looksLikeHtml(string $url): bool
    {
        // exclude common non-html extensions
        $no = ['.jpg','.jpeg','.png','.gif','.svg','.webp','.pdf','.zip','.rar','.mp4','.mp3'];
        foreach ($no as $ext) if (Str::endsWith(strtolower($url), $ext)) return false;
        return true;
    }

    protected function extractPhone(?string $text = null): ?string
    {
        if (!$text) return null;
        // regex simple: captures +xxx ... numbers with spaces/dashes
        if (preg_match('/(\+?\d[\d\-\s]{6,}\d)/', $text, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    protected function cleanPhone($phone)
    {
        if (!$phone) return null;
        // keep digits and plus
        $clean = preg_replace('/[^\d+]/','',$phone);
        // basic validation
        if (strlen(preg_replace('/\D/','',$clean)) < 6) return null;
        return $clean;
    }

    protected function extractEmail(?string $text = null): ?string
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
        } catch (\Exception $e) {}
        try {
            $t = $c->filter('title')->first()->text();
            if ($t) return trim($t);
        } catch (\Exception $e) {}
        return null;
    }

    protected function extractTitleFromCrawler(Crawler $c)
    {
        return $this->tryText($c, ['h1', 'h2', '.title', '.heading', '.post-title']);
    }

    protected function extractCityFromText(string $text)
    {
        // heuristique simple: chercher mots-clés de villes fréquentes ou "Ville: X"
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
        $keywords = [
            'apartment'=>'Apartment',
            'appartement'=>'Appartement',
            'maison'=>'Maison',
            'studio'=>'Studio',
            'chambre'=>'Chambre',
            'villa'=>'Villa'
        ];
        foreach ($keywords as $k=>$v) {
            if (stripos($text, $k) !== false) return $v;
        }
        return null;
    }

    protected function tryTextOrNull($node)
    {
        try { return trim($node->text(null)); } catch (\Exception $e) { return null; }
    }

    protected function guessSourceType(array $item)
    {
        // heuristique : si le texte contient 'agence' -> AGENCY, sinon PRIVATE
        $hay = strtolower( ($item['title'] ?? '') . ' ' . ($item['property_type'] ?? '') );
        if (str_contains($hay, 'agence') || str_contains($hay, 'agency') || str_contains($hay, 'immobili')) {
            return 'AGENCY';
        }
        return 'PRIVATE';
    }
}
