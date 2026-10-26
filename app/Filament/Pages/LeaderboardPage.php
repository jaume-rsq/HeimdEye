<?php

namespace App\Filament\Pages;

use App\Http\Controllers\LeaderboardController;
use App\Models\Module;
use App\Support\Leaderboard;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

/** El leaderboard amb els noms reals, per al professor. La versió pública és a /leaderboard. */
class LeaderboardPage extends Page
{
    protected static ?string $slug = 'leaderboard';

    protected static ?string $title = 'Leaderboard';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTrophy;

    protected static ?int $navigationSort = -10;

    protected string $view = 'filament.pages.leaderboard';

    protected Width|string|null $maxContentWidth = Width::Full;

    #[Url]
    public ?int $modul = null;

    public function mount(): void
    {
        $this->modul ??= $this->modules()->first()?->id;
    }

    #[Computed]
    public function modules(): Collection
    {
        return LeaderboardController::modules();
    }

    #[Computed]
    public function board(): ?array
    {
        $module = $this->modul ? Module::find($this->modul) : null;

        return $module ? app(Leaderboard::class)->build($module) : null;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('public')
                ->label('Versió pública')
                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->color('gray')
                ->url(fn () => route('leaderboard', ['modul' => $this->modul]), shouldOpenInNewTab: true),
        ];
    }
}
