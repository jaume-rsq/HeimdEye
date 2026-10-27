<?php

namespace App\Policies;

use App\Models\Action;
use App\Models\User;
use App\Support\Demo;

/** Accions: a la demo, un visitant només edita o esborra les que ha creat ell. */
class ActionPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Action $action): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Action $action): bool
    {
        return Demo::ownsAction($user, $action);
    }

    public function delete(User $user, Action $action): bool
    {
        return Demo::ownsAction($user, $action);
    }

    public function deleteAny(User $user): bool
    {
        return ! Demo::restricts($user);
    }
}
