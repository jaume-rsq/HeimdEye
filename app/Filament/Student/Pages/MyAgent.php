<?php

namespace App\Filament\Student\Pages;

use App\Models\Student;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;

/**
 * La pàgina «El meu agent» del panell de l'alumne: li mostra la comanda per instal·lar l'agent a la
 * seva instància, amb el seu token ja posat. El token el genera i el revoca el professor; l'alumne
 * només el pot veure aquí (abans només sortia a la fitxa del professor). Si encara no en té, se
 * l'informa que el demani. També hi veu les instàncies que ha registrat.
 */
class MyAgent extends Page
{
    protected static ?string $slug = 'agent';

    protected static ?string $title = 'El meu agent';

    protected static ?string $navigationLabel = 'El meu agent';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCpuChip;

    protected string $view = 'filament.student.pages.my-agent';

    /** Només surt al menú si el professor ja li ha generat un token (si no, no hi ha res a fer-hi). */
    public static function shouldRegisterNavigation(): bool
    {
        return ! empty(Auth::user()?->student?->token_agent);
    }

    #[Computed]
    public function student(): ?Student
    {
        return Auth::user()?->student;
    }

    #[Computed]
    public function installCommand(): ?string
    {
        return $this->student()?->agentInstallCommand();
    }

    /** Instàncies que l'alumne ha registrat amb l'agent. @return Collection<int, \App\Models\AgentInstance> */
    #[Computed]
    public function instances(): Collection
    {
        return $this->student()?->agentInstances()->orderBy('hostname')->get() ?? collect();
    }
}
