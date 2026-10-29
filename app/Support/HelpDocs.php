<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\FrontMatter\FrontMatterExtension;
use League\CommonMark\Extension\FrontMatter\Output\RenderedContentWithFrontMatter;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;

/**
 * Pàgines d'ajuda: els fitxers Markdown de docs/ajuda, llegits a cada petició. Per afegir o
 * canviar ajuda només cal editar-los; no cal tocar codi. Cada fitxer porta una capçalera YAML:
 *
 *   ---
 *   titol: Checks
 *   ordre: 20                 # posició al menú d'ajuda
 *   per: [professors]         # professors, alumnes o tots
 *   actualitzat: 2026-10-29   # data del darrer canvi; es marca com a recent uns dies
 *   ---
 *
 * L'identificador (?doc=) és el nom del fitxer sense el número del davant: 20-checks.md → checks.
 */
final class HelpDocs
{
    /** Dies que una pàgina actualitzada surt marcada com a recent. */
    public const RECENT_DAYS = 14;

    public function __construct(private ?string $dir = null)
    {
        $this->dir ??= base_path('docs/ajuda');
    }

    /**
     * Pàgines visibles per a un públic, per ordre.
     *
     * @param  'professors'|'alumnes'  $audience
     * @return Collection<string, array{slug: string, titol: string, ordre: int, actualitzat: ?Carbon, recent: bool, html: string}>
     */
    public function for(string $audience): Collection
    {
        return collect(glob($this->dir.'/*.md') ?: [])
            ->map(fn (string $path) => $this->parse($path))
            ->filter(fn (array $doc) => in_array('tots', $doc['per'], true) || in_array($audience, $doc['per'], true))
            ->sortBy([['ordre', 'asc'], ['titol', 'asc']])
            ->mapWithKeys(fn (array $doc) => [$doc['slug'] => array_diff_key($doc, ['per' => true])]);
    }

    /** @return array{slug: string, titol: string, ordre: int, per: list<string>, actualitzat: ?Carbon, recent: bool, html: string} */
    private function parse(string $path): array
    {
        $result = $this->converter()->convert((string) file_get_contents($path));
        $meta = $result instanceof RenderedContentWithFrontMatter ? (array) $result->getFrontMatter() : [];
        $slug = preg_replace('/^\d+[-_]/', '', pathinfo($path, PATHINFO_FILENAME));
        $updated = isset($meta['actualitzat']) ? Carbon::parse(is_int($meta['actualitzat']) ? '@'.$meta['actualitzat'] : $meta['actualitzat']) : null;

        return [
            'slug' => $slug,
            'titol' => (string) ($meta['titol'] ?? ucfirst(str_replace('-', ' ', $slug))),
            'ordre' => (int) ($meta['ordre'] ?? 100),
            'per' => array_values((array) ($meta['per'] ?? ['professors'])),
            'actualitzat' => $updated,
            'recent' => $updated !== null && $updated->greaterThanOrEqualTo(now()->subDays(self::RECENT_DAYS)->startOfDay()),
            'html' => $result->getContent(),
        ];
    }

    private function converter(): MarkdownConverter
    {
        // Fitxers del repositori: es permet HTML, però no enllaços javascript:.
        $environment = new Environment(['html_input' => 'allow', 'allow_unsafe_links' => false]);
        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new GithubFlavoredMarkdownExtension);
        $environment->addExtension(new FrontMatterExtension);

        return new MarkdownConverter($environment);
    }
}
