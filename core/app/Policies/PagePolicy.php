<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class PagePolicy extends ContentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, Model $model): bool
    {
        return $this->viewAny($user) && $model->kind === 'custom';
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, Model $model): bool
    {
        return $this->delete($user, $model);
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }
}
