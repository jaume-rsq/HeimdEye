<?php

namespace App\Auth;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use UnexpectedValueException;

/**
 * Valida el JWT que Cloudflare Access afegeix a cada petició i en retorna el correu.
 * Les claus públiques de l'equip es desen a la memòria cau una hora.
 */
class CloudflareAccess
{
    public function __construct(
        private string $teamDomain,
        private string $audience,
    ) {}

    public static function fromConfig(): self
    {
        $config = config('heimdeye.auth.cloudflare');
        if (blank($config['team_domain']) || blank($config['aud'])) {
            throw new UnexpectedValueException('Cal definir CF_ACCESS_TEAM_DOMAIN i CF_ACCESS_AUD');
        }

        return new self($config['team_domain'], $config['aud']);
    }

    /** @throws \Throwable si el token no és vàlid o no es poden obtenir les claus */
    public function email(string $jwt): string
    {
        $claims = JWT::decode($jwt, JWK::parseKeySet($this->keys()));

        if (! in_array($this->audience, (array) ($claims->aud ?? []), true)) {
            throw new UnexpectedValueException('El token no és per a aquesta aplicació');
        }
        if (($claims->iss ?? null) !== "https://{$this->teamDomain}") {
            throw new UnexpectedValueException('El token no és d\'aquest equip de Cloudflare');
        }
        if (blank($claims->email ?? null)) {
            throw new UnexpectedValueException('El token no porta correu');
        }

        return strtolower($claims->email);
    }

    /** @return array<string, mixed> */
    private function keys(): array
    {
        return Cache::remember("cf-access-certs:{$this->teamDomain}", 3600, fn () => Http::timeout(5)
            ->get("https://{$this->teamDomain}/cdn-cgi/access/certs")
            ->throw()
            ->json());
    }
}
