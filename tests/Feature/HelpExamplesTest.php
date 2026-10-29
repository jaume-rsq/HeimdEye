<?php

namespace Tests\Feature;

use App\Enums\Executor;
use App\Enums\Level;
use App\Enums\SecretType;
use App\Models\Module;
use App\Models\Secret;
use App\Runner\CodeValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/** Els exemples de codi de l'ajuda s'han de poder desar tal com són. */
class HelpExamplesTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const PAGES = ['14-executors.md', '16-acces-i-secrets.md'];

    public function test_the_code_examples_pass_the_code_checks(): void
    {
        $module = Module::where('codi', '9991')->firstOrFail();
        Secret::create(['nivell' => Level::Global, 'clau' => 'ssh_heimdeye', 'tipus' => SecretType::SshKey, 'nom_usuari' => 'heimdeye', 'valor' => 'CLAU', 'clau_publica' => 'ssh-ed25519 AAAA heimdeye']);
        Secret::create(['nivell' => Level::Global, 'clau' => 'contrasenya_alumnes', 'valor' => 'x']);

        $examples = 0;
        foreach (self::PAGES as $page) {
            preg_match_all('/^```(bash|yaml)\n(.*?)^```/ms', file_get_contents(base_path("docs/ajuda/{$page}")), $blocks, PREG_SET_ORDER);
            foreach ($blocks as [, $lang, $code]) {
                $executor = match (true) {
                    $lang === 'yaml' => Executor::Ansible,
                    str_starts_with($code, '# hosts:') => Executor::Ssh,
                    default => Executor::Local,
                };
                app(CodeValidator::class)->validate($module, $executor, $code);
                $examples++;
            }
        }

        $this->assertGreaterThan(10, $examples);

        $this->expectException(InvalidArgumentException::class);
        app(CodeValidator::class)->validate($module, Executor::Ssh, "# hosts: pve\n".'echo "$HE_SECRET_CONTRASENYA_ALUMNES"');
    }
}
