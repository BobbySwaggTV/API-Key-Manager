<?php

namespace MEU15\ApiKeyManager;

use XF\Mvc\Entity\Entity;

class Listener
{
    public static function entityPostSave(Entity $entity): void
    {
        if ($entity instanceof \XF\Entity\User)
        {
            self::onUserSave($entity);
            return;
        }
        if ($entity instanceof \MEU15\ApiKeyManager\Entity\ApiKeyScopeDef)
        {
            self::onScopeDefSave($entity);
            return;
        }
    }

    public static function entityPostDelete(Entity $entity): void
    {
        if (!$entity instanceof \XF\Entity\User)
        {
            return;
        }

        /** @var \MEU15\ApiKeyManager\Repository\ApiKey $repo */
        $repo = \XF::repository('MEU15\ApiKeyManager:ApiKey');
        $key = $repo->getKeyForUser((int) $entity->user_id);
        if ($key)
        {
            // ApiKey::_postDelete() removes the key's scope rows.
            $key->delete();
        }
    }

    protected static function onUserSave(\XF\Entity\User $user): void
    {
        $changes = $user->getNewValues();

        $eligibilityChanged = isset($changes['user_state']) || isset($changes['is_banned']);
        $groupsChanged = isset($changes['user_group_id']) || isset($changes['secondary_group_ids']);

        if (!$eligibilityChanged && !$groupsChanged)
        {
            return;
        }

        /** @var \MEU15\ApiKeyManager\Repository\ApiKey $repo */
        $repo = \XF::repository('MEU15\ApiKeyManager:ApiKey');

        if ($eligibilityChanged)
        {
            // Deactivates/reactivates the key as needed; when the user is
            // eligible it also recomputes scopes, covering any simultaneous
            // group change without a duplicate recompute.
            $repo->syncKeyEligibilityForUser($user);
            return;
        }

        if ($repo->isUserEligibleForApiKey($user))
        {
            $repo->recomputeScopesForUser((int) $user->user_id);
        }
    }

    protected static function onScopeDefSave(\MEU15\ApiKeyManager\Entity\ApiKeyScopeDef $def): void
    {
        $changes = $def->getNewValues();
        if (!$def->isInsert()
            && !isset($changes['user_group_ids'])
            && !isset($changes['is_active']))
        {
            return;
        }

        // $manual = false so xf:run-jobs (the standard cron) picks it up.
        // Default is true, which would queue it for manual-trigger only.
        \XF::app()->jobManager()->enqueueUnique(
            'meu15_recompute_scopes_all',
            'MEU15\ApiKeyManager:RecomputeKeyScopes',
            ['all' => true],
            false
        );
    }
}
