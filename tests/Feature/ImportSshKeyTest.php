<?php

namespace Tests\Feature;

use App\Enums\Level;
use App\Enums\SecretType;
use App\Models\Secret;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportSshKeyTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/heimdeye-key-'.uniqid();
        mkdir($this->dir);
        file_put_contents("{$this->dir}/id_ed25519", "-----BEGIN OPENSSH PRIVATE KEY-----\nabc\n-----END OPENSSH PRIVATE KEY-----\n");
        file_put_contents("{$this->dir}/id_ed25519.pub", "ssh-ed25519 AAAA heimdeye\n");
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob("{$this->dir}/*"));
        rmdir($this->dir);
        parent::tearDown();
    }

    public function test_it_stores_the_key_and_the_public_key_in_a_global_secret(): void
    {
        $this->artisan('heimdeye:import-key', ['secret' => 'ssh_prova', 'fitxer' => "{$this->dir}/id_ed25519", '--usuari' => 'jaume'])
            ->assertSuccessful();

        $secret = Secret::where('clau', 'ssh_prova')->sole();
        $this->assertSame(Level::Global, $secret->nivell);
        $this->assertSame(SecretType::SshKey, $secret->tipus);
        $this->assertSame('jaume', $secret->nom_usuari);
        $this->assertStringStartsWith('-----BEGIN OPENSSH PRIVATE KEY-----', $secret->valor);
        $this->assertSame('ssh-ed25519 AAAA heimdeye', $secret->clau_publica);
    }

    public function test_with_ruta_only_the_path_is_stored(): void
    {
        $this->artisan('heimdeye:import-key', ['secret' => 'ssh_prova', 'fitxer' => '/keys/no/hi/es', '--ruta' => true, '--usuari' => 'alumne'])
            ->assertSuccessful();

        $this->assertSame('/keys/no/hi/es', Secret::where('clau', 'ssh_prova')->sole()->valor);
    }

    public function test_it_does_not_overwrite_a_secret_of_another_type(): void
    {
        Secret::create(['nivell' => Level::Global, 'clau' => 'jaume', 'tipus' => SecretType::Login, 'nom_usuari' => 'jaume']);

        $this->artisan('heimdeye:import-key', ['secret' => 'jaume', 'fitxer' => "{$this->dir}/id_ed25519"])->assertFailed();
    }

    public function test_the_migration_moves_the_env_key_path_into_empty_ssh_key_secrets(): void
    {
        $empty = Secret::create(['nivell' => Level::Global, 'clau' => 'ssh_buit', 'tipus' => SecretType::SshKey, 'nom_usuari' => 'jaume']);
        $full = Secret::create(['nivell' => Level::Global, 'clau' => 'ssh_ple', 'tipus' => SecretType::SshKey, 'nom_usuari' => 'jaume', 'valor' => 'CLAU']);
        config(['heimdeye.runner.ssh_key_path' => "{$this->dir}/id_ed25519", 'heimdeye.runner.ssh_pubkey_path' => "{$this->dir}/id_ed25519.pub"]);

        (require database_path('migrations/2026_10_28_000001_move_ssh_key_path_into_secrets.php'))->up();

        $this->assertSame("{$this->dir}/id_ed25519", $empty->fresh()->valor);
        $this->assertSame('ssh-ed25519 AAAA heimdeye', $empty->fresh()->clau_publica);
        $this->assertSame('CLAU', $full->fresh()->valor);
        $this->assertSame('ssh-ed25519 AAAA heimdeye', $full->fresh()->clau_publica);
    }
}
