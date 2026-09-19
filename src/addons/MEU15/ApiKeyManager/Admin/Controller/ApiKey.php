<?php

namespace MEU15\ApiKeyManager\Admin\Controller;

use MEU15\ApiKeyManager\Repository\ApiKey as ApiKeyRepo;
use XF\Mvc\ParameterBag;
use XF\Mvc\Reply\AbstractReply;

class ApiKey extends \XF\Admin\Controller\AbstractController
{
    protected function preDispatchController($action, ParameterBag $params): void
    {
        $this->assertAdminPermission('user');
    }

    public function actionIndex(): AbstractReply
    {
        $keys = $this->getApiKeyRepo()->findKeysForAdminList()->fetch();

        return $this->view(
            'MEU15\ApiKeyManager:ApiKey\List',
            'meu15_api_key_list',
            ['apiKeys' => $keys]
        );
    }

    public function actionRevoke(ParameterBag $params): AbstractReply
    {
        $this->assertPostOnly();

        $keyId = $this->filter('key_id', 'uint');
        $key = $this->em()->find('MEU15\ApiKeyManager:ApiKey', $keyId);
        if (!$key)
        {
            return $this->error(\XF::phrase('meu15_api_key_not_found'));
        }

        $key->delete();

        return $this->redirect($this->buildLink('meu15-api-keys'));
    }

    protected function getApiKeyRepo(): ApiKeyRepo
    {
        return $this->repository('MEU15\ApiKeyManager:ApiKey');
    }
}
