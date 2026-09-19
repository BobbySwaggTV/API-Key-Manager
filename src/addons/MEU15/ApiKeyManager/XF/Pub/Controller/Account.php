<?php

namespace MEU15\ApiKeyManager\XF\Pub\Controller;

use MEU15\ApiKeyManager\Repository\ApiKey as ApiKeyRepo;
use XF\Mvc\ParameterBag;
use XF\Mvc\Reply\AbstractReply;

class Account extends XFCP_Account
{
    public function actionApiKey(): AbstractReply
    {
        $visitor = \XF::visitor();
        $key = $this->getApiKeyRepo()->getKeyForUser($visitor->user_id);

        $view = $this->view(
            'MEU15\ApiKeyManager:Account\ApiKey',
            'meu15_api_key_account',
            ['apiKey' => $key]
        );
        return $this->addAccountWrapperParams($view, 'meu15_api_key');
    }

    public function actionApiKeyCreate(): AbstractReply
    {
        $this->assertPostOnly();
        $visitor = \XF::visitor();
        $repo = $this->getApiKeyRepo();

        if (!$repo->isUserEligibleForApiKey($visitor))
        {
            return $this->error(\XF::phrase('meu15_api_key_ineligible'));
        }

        if ($repo->getKeyForUser($visitor->user_id))
        {
            return $this->error(\XF::phrase('meu15_api_key_already_exists'));
        }

        $result = $repo->createKeyForUser($visitor->user_id);
        \XF::session()->set('meu15_api_key_new', $result['raw']);

        return $this->redirect($this->buildLink('account/api-key-new-key'));
    }

    public function actionApiKeyRotate(): AbstractReply
    {
        $this->assertPostOnly();
        $visitor = \XF::visitor();
        $repo = $this->getApiKeyRepo();

        if (!$repo->isUserEligibleForApiKey($visitor))
        {
            return $this->error(\XF::phrase('meu15_api_key_ineligible'));
        }

        $key = $repo->getKeyForUser($visitor->user_id);
        if (!$key)
        {
            return $this->error(\XF::phrase('meu15_api_key_not_found'));
        }

        $rawKey = $repo->rotateKeyForUser($key);
        \XF::session()->set('meu15_api_key_new', $rawKey);

        return $this->redirect($this->buildLink('account/api-key-new-key'));
    }

    public function actionApiKeyRevoke(): AbstractReply
    {
        $this->assertPostOnly();
        $visitor = \XF::visitor();
        $repo = $this->getApiKeyRepo();

        $key = $repo->getKeyForUser($visitor->user_id);
        if ($key)
        {
            $key->delete();
        }

        return $this->redirect($this->buildLink('account/api-key'));
    }

    public function actionApiKeyNewKey(): AbstractReply
    {
        $rawKey = \XF::session()->get('meu15_api_key_new');
        if (!$rawKey)
        {
            return $this->redirect($this->buildLink('account/api-key'));
        }

        \XF::session()->remove('meu15_api_key_new');

        $view = $this->view(
            'MEU15\ApiKeyManager:Account\ApiKeyNewKey',
            'meu15_api_key_account_newkey',
            ['rawKey' => $rawKey]
        );
        return $this->addAccountWrapperParams($view, 'meu15_api_key');
    }

    protected function getApiKeyRepo(): ApiKeyRepo
    {
        return $this->repository('MEU15\ApiKeyManager:ApiKey');
    }
}
