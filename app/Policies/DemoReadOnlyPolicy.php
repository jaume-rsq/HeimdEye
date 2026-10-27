<?php

namespace App\Policies;

use App\Models\User;
use App\Support\Demo;

/**
 * Taules que un visitant de la demo pot consultar però no modificar (estructura, alumnes,
 * inventari). Fora del mode demo no restringeix res.
 */
class DemoReadOnlyPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return ! Demo::restricts($user);
    }

    public function update(User $user): bool
    {
        return ! Demo::restricts($user);
    }

    public function delete(User $user): bool
    {
        return ! Demo::restricts($user);
    }

    public function deleteAny(User $user): bool
    {
        return ! Demo::restricts($user);
    }

    public function replicate(User $user): bool
    {
        return ! Demo::restricts($user);
    }

    public function reorder(User $user): bool
    {
        return ! Demo::restricts($user);
    }

    public function restore(User $user): bool
    {
        return ! Demo::restricts($user);
    }

    public function restoreAny(User $user): bool
    {
        return ! Demo::restricts($user);
    }

    public function forceDelete(User $user): bool
    {
        return ! Demo::restricts($user);
    }

    public function forceDeleteAny(User $user): bool
    {
        return ! Demo::restricts($user);
    }
}
