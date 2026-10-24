<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Url;

/** Destí de les entrades del menú que encara no estan fetes. */
class ComingSoon extends Page
{
    protected static ?string $slug = 'aviat';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.coming-soon';

    #[Url]
    public ?string $que = null;

    public function getTitle(): string|Htmlable
    {
        return $this->que ?? 'Aviat';
    }
}
