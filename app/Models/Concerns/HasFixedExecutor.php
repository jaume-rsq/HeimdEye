<?php

namespace App\Models\Concerns;

use LogicException;

/** L'executor d'un check o d'una acció no canvia: si canvia, és un check o una acció diferent. */
trait HasFixedExecutor
{
    public static function bootHasFixedExecutor(): void
    {
        static::updating(function (self $model) {
            if ($model->isDirty('executor')) {
                throw new LogicException("L'executor no es pot canviar un cop creat");
            }
        });
    }
}
