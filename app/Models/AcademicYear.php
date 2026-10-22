<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AcademicYear extends Model
{
    protected $table = 'cursos';

    protected $fillable = ['nom', 'data_inici', 'data_fi', 'es_actual'];

    protected function casts(): array
    {
        return [
            'data_inici' => 'date',
            'data_fi' => 'date',
            'es_actual' => 'boolean',
        ];
    }

    public function modules(): HasMany
    {
        return $this->hasMany(Module::class, 'curs_id');
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class, 'curs_id');
    }
}
