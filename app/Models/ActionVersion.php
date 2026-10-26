<?php

namespace App\Models;

use App\Models\Concerns\ExecutableVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActionVersion extends Model
{
    use ExecutableVersion;

    const UPDATED_AT = null;

    protected $table = 'versions_accio';

    /** Una versió nova també compta com a modificació de l'acció (llista ordenada per updated_at). */
    protected $touches = ['action'];

    protected $fillable = ['accio_id', 'checks_verificacio'];

    protected function casts(): array
    {
        return [
            'checks_verificacio' => 'array',
        ];
    }

    public function action(): BelongsTo
    {
        return $this->belongsTo(Action::class, 'accio_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creat_per');
    }
}
