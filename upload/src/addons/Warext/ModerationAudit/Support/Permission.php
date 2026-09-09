<?php

namespace Warext\ModerationAudit\Support;

use XF\Entity\User;

final class Permission
{
    public static function isSuperAdmin(?User $user = null): bool
    {
        $user ??= \XF::visitor();
        return (bool)$user->is_super_admin;
    }

    public static function has(User $user, string $permissionId): bool
    {
        return self::isSuperAdmin($user)
            || $user->hasPermission('general', $permissionId);
    }
}
