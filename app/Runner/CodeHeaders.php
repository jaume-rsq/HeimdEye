<?php

namespace App\Runner;

/**
 * Capçaleres que el codi d'un check o d'una acció declara als comentaris del principi:
 *
 *     # hosts: pve:vm_router     equips on s'executa (executor ssh)
 *     # credencial: SSH jaume    credencial a fer servir en lloc de la per defecte
 *
 * Es llegeixen les línies de comentari inicials (es poden barrejar amb línies buides,
 * el shebang o el "---" d'un YAML) fins a la primera línia de codi. Els altres
 * comentaris s'ignoren, i el codi s'executa tal com està: les capçaleres són comentaris.
 */
final readonly class CodeHeaders
{
    private const KEYS = ['hosts', 'credencial'];

    /** @param  list<string>  $hosts */
    public function __construct(
        public array $hosts = [],
        public ?string $credential = null,
    ) {}

    public static function parse(string $code): self
    {
        $values = [];
        foreach (preg_split('/\R/', $code) as $line) {
            $line = trim($line);
            if ($line === '' || $line === '---' || str_starts_with($line, '#!')) {
                continue;
            }
            if (! str_starts_with($line, '#')) {
                break;
            }
            if (preg_match('/^#\s*([a-z]+)\s*:\s*(.*?)\s*$/i', $line, $m) && in_array(strtolower($m[1]), self::KEYS, true)) {
                $values[strtolower($m[1])] ??= $m[2];
            }
        }

        return new self(
            hosts: array_values(array_filter(array_map('trim', preg_split('/[:,\s]+/', $values['hosts'] ?? '')))),
            credential: ($values['credencial'] ?? '') !== '' ? $values['credencial'] : null,
        );
    }
}
