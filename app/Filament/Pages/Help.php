<?php

namespace App\Filament\Pages;

use App\Support\HelpDocs;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

/**
 * Ajuda: les pàgines Markdown de docs/ajuda per al públic d'aquest panell (HelpDocs). Les que
 * s'han actualitzat fa poc surten marcades, i el menú ho indica amb un número.
 */
class Help extends Page
{
    protected static ?string $slug = 'ajuda';

    protected static ?string $title = 'Ajuda';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQuestionMarkCircle;

    protected static ?int $navigationSort = 100;

    protected string $view = 'filament.pages.help';

    /** Públic de les pàgines: professors (panell admin) o alumnes. */
    protected static string $audience = 'professors';

    #[Url]
    public ?string $doc = null;

    public function mount(): void
    {
        if (! $this->docs()->has((string) $this->doc)) {
            $this->doc = $this->docs()->keys()->first();
        }
    }

    /** @return Collection<string, array<string, mixed>> */
    #[Computed]
    public function docs(): Collection
    {
        return app(HelpDocs::class)->for(static::$audience);
    }

    /** @return array<string, mixed>|null */
    public function current(): ?array
    {
        return $this->docs()->get((string) $this->doc);
    }

    public function getTitle(): string|Htmlable
    {
        return 'Ajuda'.(($doc = $this->current()) ? ' · '.$doc['titol'] : '');
    }

    /** Quantes pàgines s'han actualitzat fa poc. */
    public static function getNavigationBadge(): ?string
    {
        $recent = app(HelpDocs::class)->for(static::$audience)->where('recent', true)->count();

        return $recent ? (string) $recent : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Pàgines d\'ajuda actualitzades els darrers '.HelpDocs::RECENT_DAYS.' dies';
    }
}
