<?php

namespace App\Models;

use App\Enums\CredentialType;
use Illuminate\Database\Eloquent\Model;

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
}
