<?php

namespace MEU15\ApiKeyManager\Repository;

use MEU15\ApiKeyManager\Entity\ApiKey as ApiKeyEntity;
use MEU15\ApiKeyManager\Entity\ApiKeyScopeDef;
use XF\Mvc\Entity\Finder;
use XF\Mvc\Entity\Repository;

class ApiKey extends Repository
{
    private const KEY_PREFIX = '15meu_';

    public function generateKey(): array
    {
        $raw = self::KEY_PREFIX . \XF::generateRandomString(32);
        return [
            'raw'    => $raw,
            'hash'   => hash('sha256', $raw, true),
            'prefix' => substr($raw, strlen(self::KEY_PREFIX), 8),
        ];
    }

    public function findKeysForAdminList(): Finder
    {
        // Scopes is TO_MANY; cannot eager-load via Finder::with().
        return $this->finder('MEU15\ApiKeyManager:ApiKey')
            ->with('User')
            ->setDefaultOrder('created_date', 'DESC');
    }

    public function getKeyForUser(int $userId): ?ApiKeyEntity
    {
        // Scopes is TO_MANY; cannot eager-load via Finder::with().
        /** @var ApiKeyEntity|null $key */
        $key = $this->finder('MEU15\ApiKeyManager:ApiKey')
            ->where('user_id', $userId)
            ->fetchOne();
        return $key;
    }

    public function isUserEligibleForApiKey(\XF\Entity\User $user): bool
    {
        return $user->user_state === 'valid' && !$user->is_banned;
    }

    public function syncKeyEligibilityForUser(\XF\Entity\User $user): void
    {
        $key = $this->getKeyForUser((int) $user->user_id);
        if (!$key)
        {
            return;
        }

        if (!$this->isUserEligibleForApiKey($user))
        {
            if ($key->is_active)
            {
                $key->is_active = false;
                $key->save();
            }
            return;
        }

        if (!$key->is_active)
        {
            $key->is_active = true;
            $key->save();
        }

        $this->recomputeScopesForUser((int) $user->user_id, $key);
    }

    public function createKeyForUser(int $userId): array
    {
        /** @var \XF\Entity\User|null $user */
        $user = $this->em->find('XF:User', $userId);
        if (!$user || !$this->isUserEligibleForApiKey($user))
        {
            throw new \XF\PrintableException(\XF::phrase('meu15_api_key_ineligible'));
        }

        $keyData = $this->generateKey();

        /** @var ApiKeyEntity $key */
        $key = $this->em->create('MEU15\ApiKeyManager:ApiKey');
        $key->user_id      = $userId;
        $key->key_hash     = $keyData['hash'];
        $key->key_prefix   = $keyData['prefix'];
        $key->is_active    = true;
        $key->created_date = \XF::$time;
        $key->save();

        $this->recomputeScopesForUser($userId, $key);

        return ['entity' => $key, 'raw' => $keyData['raw']];
    }

    public function rotateKeyForUser(ApiKeyEntity $key): string
    {
        /** @var \XF\Entity\User|null $user */
        $user = $this->em->find('XF:User', (int) $key->user_id);
        if (!$user || !$this->isUserEligibleForApiKey($user))
        {
            throw new \XF\PrintableException(\XF::phrase('meu15_api_key_ineligible'));
        }

        $keyData = $this->generateKey();
        $key->key_hash   = $keyData['hash'];
        $key->key_prefix = $keyData['prefix'];
        $key->save();

        $this->recomputeScopesForUser((int) $key->user_id, $key);

        return $keyData['raw'];
    }

    public function recomputeScopesForUser(int $userId, ?ApiKeyEntity $key = null): void
    {
        if (!$key)
        {
            $key = $this->finder('MEU15\ApiKeyManager:ApiKey')
                ->where('user_id', $userId)
                ->fetchOne();
            if (!$key)
            {
                return;
            }
        }

        /** @var \XF\Entity\User|null $user */
        $user = $this->em->find('XF:User', $userId);
        if (!$user)
        {
            return;
        }

        $secondaryGroupIds = is_array($user->secondary_group_ids)
            ? $user->secondary_group_ids
            : array_filter(explode(',', (string) $user->secondary_group_ids));

        $userGroupIds = array_filter(array_map('intval', array_merge(
            [(int) $user->user_group_id],
            $secondaryGroupIds
        )));

        /** @var \MEU15\ApiKeyManager\Repository\ApiKeyScopeDef $scopeRepo */
        $scopeRepo = $this->repository('MEU15\ApiKeyManager:ApiKeyScopeDef');
        $defs = $scopeRepo->findActiveScopes()->fetch();

        $grants = [];
        /** @var ApiKeyScopeDef $def */
        foreach ($defs as $def)
        {
            $defGroupIds = array_filter(array_map(
                'intval',
                explode(',', (string) $def->user_group_ids)
            ));

            if (!$defGroupIds)
            {
                $grants[] = (int) $def->scope_id;
                continue;
            }
            if (array_intersect($userGroupIds, $defGroupIds))
            {
                $grants[] = (int) $def->scope_id;
            }
        }

        $db = $this->db();
        $db->beginTransaction();
        try
        {
            $db->delete('xf_15meu_api_key_scope', 'key_id = ?', $key->key_id);
            foreach ($grants as $scopeId)
            {
                $db->insert('xf_15meu_api_key_scope', [
                    'key_id'   => $key->key_id,
                    'scope_id' => $scopeId,
                ]);
            }
            $db->commit();
        }
        catch (\Throwable $e)
        {
            $db->rollback();
            throw $e;
        }
    }
}
