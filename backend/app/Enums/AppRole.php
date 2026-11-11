<?php

namespace App\Enums;

/**
 * super_admin  everything; the only one who can promote/demote and deactivate admins. The first user.
 * admin        full access: clients, projects, who is in them, users.
 * member       read and write in the clients/projects they were added to, and DMs.
 * viewer       read only in the clients/projects they were added to, but can still DM.
 */
enum AppRole: string
{
    case SuperAdmin = 'super_admin';
    case Admin = 'admin';
    case Member = 'member';
    case Viewer = 'viewer';

    public function isAdmin(): bool
    {
        return $this === self::SuperAdmin || $this === self::Admin;
    }
}
