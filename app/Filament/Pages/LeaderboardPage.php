<?php

namespace App\Filament\Pages;

use App\Http\Controllers\LeaderboardController;
use App\Models\Module;
use App\Support\Leaderboard;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

/**
 * El leaderboard amb els noms reals, per al professor. La versió pública és a /leaderboard. Es tria
 * el grup-classe i, d'aquest, un mòdul on hi hagi algun alumne del grup matriculat.
 */
class LeaderboardPage extends Page
{
    protected static ?string $slug = 'leaderboard';

    protected static ?string $title = 'Leaderboard';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTrophy;

    protected static ?int $navigationSort = -10;

    protected string $view = 'filament.pages.leaderboard';

    protected Width|string|null $maxContentWidth = Width::Full;

    #[Url]
    public ?int $grup = null;

    #[Url]
    public ?int $modul = null;

    public function mount(): void
    {
        $this->ensureGroup();
        $this->ensureModule();
    }

    public function updatedGrup(): void
    {
        $this->ensureGroup();
        unset($this->modules);
        $this->ensureModule();
    }

    /** Sempre hi ha un grup triat (no hi ha «Tots els grups»): si no és vàlid, el primer. */
    private function ensureGroup(): void
    {
        if ($this->grup === null || ! $this->groups()->contains('id', $this->grup)) {
            $this->grup = $this->groups()->first()?->id;
        }
    }

    /** El mòdul ha de ser un dels del grup; si no, el primer. */
    private function ensureModule(): void
    {
        if ($this->modul === null || ! $this->modules()->contains('id', $this->modul)) {
            $this->modul = $this->modules()->first()?->id;
        }
    }

    #[Computed]
    public function groups(): Collection
    {
        return LeaderboardController::groups();
    }

    #[Computed]
    public function modules(): Collection
    {
        return LeaderboardController::modules($this->grup);
    }

    #[Computed]
    public function board(): ?array
    {
        $module = $this->modul ? Module::find($this->modul) : null;

        return $module ? app(Leaderboard::class)->build($module, groupId: $this->grup) : null;
    }

    public function getHeader(): ?View
    {
        $url = e(route('leaderboard', array_filter(['grup' => $this->grup, 'modul' => $this->modul])));

        return view('filament.partials.group-module-header', [
            'title' => static::$title,
            'groups' => $this->groups(),
            'modules' => $this->modules(),
            'actions' => new HtmlString('<a href="'.$url.'" target="_blank" rel="noopener" style="padding: .4rem .65rem; border: 1px solid rgba(128,128,128,.35); border-radius: .5rem; font-size: .875rem; white-space: nowrap">Versió pública ↗</a>'),
        ]);
    }
}
