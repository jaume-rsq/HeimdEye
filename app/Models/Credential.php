<?php

namespace App\Models;

use App\Enums\CredentialType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Credential extends Model
{
    protected $table = 'credencials';

    protected $fillable = ['nom', 'tipus', 'nom_usuari', 'secret'];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return [
            'tipus' => CredentialType::class,
            'secret' => 'encrypted',
        ];
    }

    public function modules(): HasMany
    {
        return $this->hasMany(Module::class, 'credencial_id');
    }

    public function hostTypes(): HasMany
    {
        return $this->hasMany(HostType::class, 'credencial_id');
    }

    /** Versions vigents o antigues de checks i accions que l'anomenen a la capçalera "# credencial:". */
    public function headerUses(): int
    {
        $pattern = '%# credencial:%'.$this->nom.'%';

        return CheckVersion::where('codi', 'like', $pattern)->count() + ActionVersion::where('codi', 'like', $pattern)->count();
    }
}
