<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GlobalVar extends Model
{
    protected $table = 'variables_globals';

    protected $fillable = ['clau', 'valor'];
}
