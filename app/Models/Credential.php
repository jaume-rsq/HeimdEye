<?php

namespace App\Models;

use App\Enums\CredentialType;
use Illuminate\Database\Eloquent\Model;

class Credential extends Model
{
    protected $fillable = ['name', 'type', 'username', 'secret'];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return [
            'type' => CredentialType::class,
            'secret' => 'encrypted',
        ];
    }
}
