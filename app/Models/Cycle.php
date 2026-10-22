<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cycle extends Model
{
    protected $table = 'cicles';

    protected $fillable = ['codi', 'nom'];

    public function modules(): HasMany
    {
        return $this->hasMany(Module::class, 'cicle_id');
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class, 'cicle_id');
    }
}
