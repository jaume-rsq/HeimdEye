<?php

namespace App\Models;

use App\Models\Concerns\ExecutableVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CheckVersion extends Model
{
    use ExecutableVersion;

    const UPDATED_AT = null;

    protected $table = 'versions_check';

    /** Una versió nova també compta com a modificació del check (llista ordenada per updated_at). */
    protected $touches = ['check'];

    protected $fillable = ['check_id'];

    public function check(): BelongsTo
    {
        return $this->belongsTo(Check::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creat_per');
    }
}
