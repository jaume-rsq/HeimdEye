<?php

namespace Tests\Unit;

use App\Runner\ProcessRunner;
use Tests\TestCase;

/** Embolcall heimdeye-sandbox de la demo: rep el temps màxim i el nom de les variables. */
class SandboxWrapperTest extends TestCase
{
    public function test_the_sandbox_wrapper_gets_timeout_and_env_names(): void
    {
        $dir = sys_get_temp_dir().'/wrapper-'.bin2hex(random_bytes(4));
        mkdir($dir);
        $wrapper = $dir.'/heimdeye-sandbox';
        file_put_contents($wrapper, "#!/bin/sh\necho \"\$@\"\necho \"FOO=\$FOO\"\n");
        chmod($wrapper, 0755);
        config(['heimdeye.runner.wrapper' => $wrapper]);

        $run = app(ProcessRunner::class)->execute(['bash', '-s'], ['FOO' => 'bar'], 'echo hola', 5);

        $this->assertSame("--timeout=5 --setenv=FOO -- bash -s\nFOO=bar\n", $run['stdout']);
        unlink($wrapper);
        rmdir($dir);
    }
}
