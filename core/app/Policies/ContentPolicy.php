<?php

namespace App\Policies;

use App\Models\Media;
use App\Models\Post;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared editorial permissions.
 *
 * Administrators manage everything. Editors work on their own posts and uploads, may read
 * published posts and public files from other authors, and may create or rename shared
 * taxonomy (categories, tags and series), but only administrators can delete taxonomy
 * because every author's posts depend on it.
 */
class ContentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && in_array($user->role, ['admin', 'editor'], true);
    }

    public function view(User $user, Model $model): bool
    {
        if (! $this->viewAny($user)) {
            return false;
        }

        return match (true) {
            $model instanceof Post => $this->manages($user, $model) || $model->isPublished(),
            $model instanceof Media => $this->manages($user, $model) || $model->isPublic(),
            default => true,
        };
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Model $model): bool
    {
        return $this->isAuthored($model) ? $this->manages($user, $model) : $this->viewAny($user);
    }

    public function delete(User $user, Model $model): bool
    {
        return $this->isAuthored($model) ? $this->manages($user, $model) : $user->isAdmin();
    }

    /**
     * Bulk actions must also authorize each selected record with {@see delete()}.
     */
    public function deleteAny(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function restore(User $user, Model $model): bool
    {
        return $this->isAuthored($model) ? $this->manages($user, $model) : $user->isAdmin();
    }

    /**
     * Bulk actions must also authorize each selected record with {@see restore()}.
     */
    public function restoreAny(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function forceDelete(User $user, Model $model): bool
    {
        return $user->isAdmin();
    }

    public function forceDeleteAny(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Posts and uploads belong to the user who created them.
     */
    private function isAuthored(Model $model): bool
    {
        return $model instanceof Post || $model instanceof Media;
    }

    /**
     * Whether the user is an administrator or the active editor who owns the record.
     */
    private function manages(User $user, Model $model): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $this->viewAny($user) && $model->getAttribute('user_id') !== null
            && (int) $model->getAttribute('user_id') === (int) $user->getKey();
    }
}
