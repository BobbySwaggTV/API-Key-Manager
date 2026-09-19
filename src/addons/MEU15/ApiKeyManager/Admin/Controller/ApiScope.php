<?php

namespace MEU15\ApiKeyManager\Admin\Controller;

use MEU15\ApiKeyManager\Entity\ApiKeyScopeDef;
use MEU15\ApiKeyManager\Repository\ApiKeyScopeDef as ApiKeyScopeDefRepo;
use XF\Mvc\FormAction;
use XF\Mvc\ParameterBag;
use XF\Mvc\Reply\AbstractReply;

class ApiScope extends \XF\Admin\Controller\AbstractController
{
    protected function preDispatchController($action, ParameterBag $params): void
    {
        $this->assertAdminPermission('user');
    }

    public function actionIndex(): AbstractReply
    {
        $scopes = $this->getScopeRepo()->findScopesForList()->fetch();

        return $this->view(
            'MEU15\ApiKeyManager:ApiScope\List',
            'meu15_api_scope_list',
            ['scopes' => $scopes]
        );
    }

    public function actionAdd(): AbstractReply
    {
        /** @var ApiKeyScopeDef $scope */
        $scope = $this->em()->create('MEU15\ApiKeyManager:ApiKeyScopeDef');
        return $this->scopeAddEdit($scope);
    }

    public function actionEdit(ParameterBag $params): AbstractReply
    {
        $scope = $this->assertScopeExists($params->scope_id);
        return $this->scopeAddEdit($scope);
    }

    protected function scopeAddEdit(ApiKeyScopeDef $scope): AbstractReply
    {
        /** @var \XF\Repository\UserGroup $userGroupRepo */
        $userGroupRepo = $this->repository('XF:UserGroup');

        return $this->view(
            'MEU15\ApiKeyManager:ApiScope\Edit',
            'meu15_api_scope_edit',
            [
                'scope'      => $scope,
                'userGroups' => $userGroupRepo->getUserGroupTitlePairs(),
            ]
        );
    }

    public function actionSave(ParameterBag $params): AbstractReply
    {
        $this->assertPostOnly();

        if ($params->scope_id)
        {
            $scope = $this->assertScopeExists($params->scope_id);
        }
        else
        {
            /** @var ApiKeyScopeDef $scope */
            $scope = $this->em()->create('MEU15\ApiKeyManager:ApiKeyScopeDef');
        }

        $this->scopeSaveProcess($scope)->run();

        return $this->redirect($this->buildLink('meu15-api-scopes'));
    }

    protected function scopeSaveProcess(ApiKeyScopeDef $scope): FormAction
    {
        $form = $this->formAction();

        $filterMap = [
            'title'         => 'str',
            'description'   => 'str',
            'is_active'     => 'bool',
            'display_order' => 'uint',
        ];

        if (!$scope->exists())
        {
            $filterMap = ['scope_name' => 'str'] + $filterMap;
        }

        $input = $this->filter($filterMap);

        // Assembled separately so basicEntitySave's bulkSet($input) does not
        // overwrite it. Keep user_group_ids out of $filterMap.
        $groupIds = $this->filter('user_group_ids', 'array-uint');
        $scope->user_group_ids = implode(',', array_filter($groupIds));

        $form->basicEntitySave($scope, $input);

        return $form;
    }

    public function actionDelete(ParameterBag $params): AbstractReply
    {
        $scope = $this->assertScopeExists($params->scope_id);

        if ($this->isPost())
        {
            $scope->delete();
            return $this->redirect($this->buildLink('meu15-api-scopes'));
        }

        $viewParams = [
            'scope' => $scope,
        ];
        return $this->view(
            'MEU15\ApiKeyManager:ApiScope\Delete',
            'meu15_api_scope_delete',
            $viewParams
        );
    }

    protected function assertScopeExists(int $scopeId): ApiKeyScopeDef
    {
        /** @var ApiKeyScopeDef|null $scope */
        $scope = $this->em()->find('MEU15\ApiKeyManager:ApiKeyScopeDef', $scopeId);
        if (!$scope)
        {
            throw $this->exception($this->notFound(\XF::phrase('meu15_api_scope_not_found')));
        }
        return $scope;
    }

    protected function getScopeRepo(): ApiKeyScopeDefRepo
    {
        return $this->repository('MEU15\ApiKeyManager:ApiKeyScopeDef');
    }
}
