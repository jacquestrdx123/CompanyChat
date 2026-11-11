<?php

namespace App\Support;

use App\Enums\AppRole;
use App\Models\User;

/**
 * Who may invite, deactivate and change the role of whom. Mirrors supabase/functions/_shared/policy.ts
 * and the set_app_role function. Each method returns null when allowed, or the reason it is not.
 */
class UserPolicy
{
    public static function canInvite(User $actor): ?string
    {
        return $actor->isAdmin() ? null : 'Only admins can invite users.';
    }

    /** Used for both deactivating and reactivating. */
    public static function canChangeActive(User $actor, User $target): ?string
    {
        if (! $actor->isAdmin()) {
            return 'Only admins can deactivate users.';
        }
        if ($actor->id === $target->id) {
            return 'You cannot deactivate yourself.';
        }
        if ($target->app_role === AppRole::SuperAdmin) {
            return 'The super admin cannot be deactivated.';
        }
        if ($target->app_role === AppRole::Admin && $actor->app_role !== AppRole::SuperAdmin) {
            return 'Only the super admin can deactivate an admin.';
        }

        return null;
    }

    /**
     * The super admin sets any role except super_admin. Admins may switch people between member and
     * viewer. Nobody changes a super admin, and nobody changes their own role.
     */
    public static function canSetRole(User $actor, User $target, AppRole $newRole): ?string
    {
        if (! $actor->is_active || ! $actor->isAdmin()) {
            return 'Only admins can change roles.';
        }
        if ($newRole === AppRole::SuperAdmin) {
            return 'The super admin role cannot be assigned.';
        }
        if ($actor->id === $target->id) {
            return 'You cannot change your own role.';
        }
        if ($target->app_role === AppRole::SuperAdmin) {
            return 'Cannot change the role of this user.';
        }
        if ($actor->app_role === AppRole::Admin && ($target->app_role === AppRole::Admin || $newRole === AppRole::Admin)) {
            return 'Only the super admin can promote or demote admins.';
        }

        return null;
    }
}
