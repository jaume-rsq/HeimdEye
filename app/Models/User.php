<?php

namespace App\Models;

use App\Enums\Role;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser, HasName
{
    protected $table = 'usuaris';

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'nom',
        'email',
        'password',
        'rol',
        'es_actiu',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'rol' => Role::class,
            'es_actiu' => 'boolean',
        ];
    }

    /** Mòduls que imparteix, si és professor. */
    public function modules(): BelongsToMany
    {
        return $this->belongsToMany(Module::class, 'modul_professor', 'usuari_id', 'modul_id');
    }

    /** Fitxa d'alumne, si és alumne. */
    public function student(): HasOne
    {
        return $this->hasOne(Student::class, 'usuari_id');
    }

    public function isAdmin(): bool
    {
        return $this->rol === Role::Admin;
    }

    public function isTeacher(): bool
    {
        return in_array($this->rol, [Role::Admin, Role::Teacher], true);
    }

    /** Pot editar un check: un administrador, o un professor del mòdul del check. */
    public function canEditCheck(Check $check): bool
    {
        return $this->es_actiu && ($this->isAdmin()
            || ($this->rol === Role::Teacher && $this->modules()->whereKey($check->task?->block?->modul_id)->exists()));
    }

    /** Al panell admin hi entren els administradors i els professors actius; al d'alumne, els alumnes actius amb fitxa. */
    public function canAccessPanel(Panel $panel): bool
    {
        if (! $this->es_actiu) {
            return false;
        }

        return $panel->getId() === 'alumne'
            ? $this->rol === Role::Student && $this->student()->exists()
            : $this->isTeacher();
    }

    /** Panell on ha d'anar l'usuari quan entra. */
    public function homePath(): string
    {
        return $this->rol === Role::Student ? '/alumne' : '/admin';
    }

    public function getFilamentName(): string
    {
        return $this->nom;
    }
}
