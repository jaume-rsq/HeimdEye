<?php

namespace App\Models;

use App\Enums\ModuleKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Module extends Model
{
    protected $fillable = [
        'academic_year_id', 'cycle_id', 'code', 'name', 'description', 'kind',
        'combines', 'derived_vars', 'credential_id',
    ];

    protected function casts(): array
    {
        return [
            'kind' => ModuleKind::class,
            'combines' => 'array',
            'derived_vars' => 'array',
        ];
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(Cycle::class);
    }

    public function credential(): BelongsTo
    {
        return $this->belongsTo(Credential::class);
    }

    public function teachers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'module_teacher');
    }

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class, 'enrollments');
    }

    public function blocks(): HasMany
    {
        return $this->hasMany(Block::class)->orderBy('position');
    }

    public function tasks(): HasManyThrough
    {
        return $this->hasManyThrough(Task::class, Block::class);
    }
}
