<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Student extends Model
{
    protected $fillable = [
        'user_id', 'academic_year_id', 'cycle_id', 'username', 'full_name',
        'display_name', 'table_no', 'x', 'vars', 'active',
    ];

    protected function casts(): array
    {
        return [
            'vars' => 'array',
            'active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(Cycle::class);
    }

    public function modules(): BelongsToMany
    {
        return $this->belongsToMany(Module::class, 'enrollments');
    }

    public function checkStates(): HasMany
    {
        return $this->hasMany(CheckStudent::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(CheckRun::class);
    }

    /** Nom que surt al leaderboard públic: l'àlies, o la taula si no en té. */
    public function publicName(): string
    {
        return $this->display_name ?? sprintf('Taula %02d', $this->table_no);
    }
}
