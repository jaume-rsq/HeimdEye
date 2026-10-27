<?php

namespace App\Console\Commands;

use App\Enums\Level;
use App\Enums\SecretType;
use App\Models\Secret;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * Carrega una clau SSH del servidor a un secret global de clau SSH (el crea si no hi és): la
 * clau privada (o només la ruta, amb --ruta) i la pública, del .pub o calculada amb ssh-keygen.
 */
class ImportSshKey extends Command
{
    protected $signature = 'heimdeye:import-key
        {secret : nom del secret (ssh_jaume)}
        {fitxer : ruta de la clau privada}
        {--usuari= : usuari SSH (si el secret és nou, per defecte el de la màquina)}
        {--ruta : desa la ruta del fitxer en lloc de la clau}';

    protected $description = 'Desa una clau SSH del servidor en un secret de clau SSH';

    public function handle(): int
    {
        $name = (string) $this->argument('secret');
        $path = (string) $this->argument('fitxer');
        if (! preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
            $this->error('El nom del secret ha de ser en minúscules: ssh_jaume');

            return self::FAILURE;
        }
        if (! str_starts_with($path, '/')) {
            $this->error('Cal la ruta absoluta del fitxer');

            return self::FAILURE;
        }

        $secret = Secret::firstOrNew(['nivell' => Level::Global, 'curs_id' => null, 'modul_id' => null, 'clau' => $name]);
        if ($secret->exists && $secret->tipus !== SecretType::SshKey) {
            $this->error("El secret {$name} ja existeix i no és de clau SSH");

            return self::FAILURE;
        }

        $private = null;
        if (! $this->option('ruta')) {
            if (! is_readable($path)) {
                $this->error("No es pot llegir {$path} (amb --ruta es desa només la ruta)");

                return self::FAILURE;
            }
            $private = trim((string) file_get_contents($path));
        }

        $secret->fill([
            'tipus' => SecretType::SshKey,
            'nom_usuari' => $this->option('usuari') ?? $secret->nom_usuari ?? get_current_user(),
            'valor' => $private ?? $path,
            'clau_publica' => $this->publicKey($path) ?? $secret->clau_publica,
        ]);
        $secret->descripcio ??= "Importada de {$path}";
        $secret->save();

        $this->info("Secret {$name}: ".($private === null ? "ruta {$path}" : 'clau privada desada')
            .', '.(filled($secret->clau_publica) ? 'amb clau pública' : 'sense clau pública').'.');

        return self::SUCCESS;
    }

    private function publicKey(string $path): ?string
    {
        if (is_readable("{$path}.pub")) {
            return trim((string) file_get_contents("{$path}.pub")) ?: null;
        }
        if (! is_readable($path)) {
            return null;
        }
        $process = new Process(['ssh-keygen', '-y', '-f', $path]);
        $process->run();

        return $process->isSuccessful() ? (trim($process->getOutput()) ?: null) : null;
    }
}
