<?php

namespace MEU15\ApiKeyManager;

use XF\AddOn\AbstractSetup;
use XF\AddOn\StepRunnerInstallTrait;
use XF\AddOn\StepRunnerUpgradeTrait;
use XF\AddOn\StepRunnerUninstallTrait;

class Setup extends AbstractSetup
{
    use StepRunnerInstallTrait;
    use StepRunnerUpgradeTrait;
    use StepRunnerUninstallTrait;

    public function installStep1(): void
    {
        $this->db()->query("
            CREATE TABLE IF NOT EXISTS `xf_15meu_api_key` (
                `key_id`          INT UNSIGNED   NOT NULL AUTO_INCREMENT,
                `user_id`         INT UNSIGNED   NOT NULL,
                `key_hash`        VARBINARY(32)  NOT NULL,
                `key_prefix`      VARCHAR(12)    NOT NULL,
                `is_active`       TINYINT(1)     NOT NULL DEFAULT 1,
                `created_date`    INT UNSIGNED   NOT NULL DEFAULT 0,
                `last_used_date`  INT UNSIGNED   NOT NULL DEFAULT 0,
                PRIMARY KEY (`key_id`),
                UNIQUE KEY `key_hash` (`key_hash`),
                UNIQUE KEY `user_id` (`user_id`),
                KEY `is_active` (`is_active`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function installStep2(): void
    {
        $this->db()->query("
            CREATE TABLE IF NOT EXISTS `xf_15meu_api_key_scope_def` (
                `scope_id`         INT UNSIGNED   NOT NULL AUTO_INCREMENT,
                `scope_name`       VARCHAR(50)    NOT NULL,
                `title`            VARCHAR(100)   NOT NULL,
                `description`      TEXT           NOT NULL,
                `user_group_ids`   VARCHAR(255)   NOT NULL DEFAULT '',
                `is_active`        TINYINT(1)     NOT NULL DEFAULT 1,
                `display_order`    INT UNSIGNED   NOT NULL DEFAULT 0,
                PRIMARY KEY (`scope_id`),
                UNIQUE KEY `scope_name` (`scope_name`),
                KEY `is_active_display_order` (`is_active`, `display_order`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function installStep3(): void
    {
        $this->db()->query("
            CREATE TABLE IF NOT EXISTS `xf_15meu_api_key_scope` (
                `key_id`   INT UNSIGNED NOT NULL,
                `scope_id` INT UNSIGNED NOT NULL,
                PRIMARY KEY (`key_id`, `scope_id`),
                KEY `scope_id` (`scope_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function installStep4(): void
    {
        $db = $this->db();

        $exists = (bool) $db->fetchOne(
            "SELECT scope_id FROM xf_15meu_api_key_scope_def WHERE scope_name = 'read'"
        );
        if ($exists)
        {
            return;
        }

        $db->insert('xf_15meu_api_key_scope_def', [
            'scope_name'    => 'read',
            'title'         => 'Read',
            'description'   => 'Read access to public API data.',
            'is_active'     => 1,
            'display_order' => 0,
        ]);
    }

    public function uninstallStep1(): void
    {
        $this->db()->query("DROP TABLE IF EXISTS `xf_15meu_api_key_scope`");
    }

    public function uninstallStep2(): void
    {
        $this->db()->query("DROP TABLE IF EXISTS `xf_15meu_api_key_scope_def`");
    }

    public function uninstallStep3(): void
    {
        $this->db()->query("DROP TABLE IF EXISTS `xf_15meu_api_key`");
    }
}
