<?php

namespace App\Runner;

/** Fitxers temporals d'una execució (claus, inventari, extra vars), esborrats en acabar. */
class TempFiles
{
    /** @var list<string> */
    private array $paths = [];

    private ?string $dir = null;

    public function write(string $name, string $content): string
    {
        $this->dir ??= $this->makeDir();
        $path = $this->dir.'/'.$name;
        file_put_contents($path, $content);
        chmod($path, 0600);
        $this->paths[] = $path;

        return $path;
    }

    public function cleanup(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }
        if ($this->dir) {
            @rmdir($this->dir);
        }
        $this->paths = [];
        $this->dir = null;
    }

    private function makeDir(): string
    {
        $dir = sys_get_temp_dir().'/heimdeye-'.bin2hex(random_bytes(8));
        mkdir($dir, 0700);

        return $dir;
    }
}
