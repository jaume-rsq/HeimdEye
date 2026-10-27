<?php

namespace App\Policies;

use App\Models\User;
use App\Support\Demo;

/** Taules que un visitant de la demo ni tan sols veu (usuaris i secrets). */
class DemoHiddenPolicy extends DemoReadOnlyPolicy
{
    public function viewAny(User $user): bool
    {
        return ! Demo::restricts($user);
    }

    public function view(User $user): bool
    {
        return ! Demo::restricts($user);
    }
}
