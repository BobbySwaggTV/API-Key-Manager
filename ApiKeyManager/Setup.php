<?php

namespace Cav7\ApiKeyManager;

use XF\AddOn\AbstractSetup;
use XF\AddOn\StepRunnerInstallTrait;
use XF\AddOn\StepRunnerUninstallTrait;
use XF\AddOn\StepRunnerUpgradeTrait;

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

    public function upgrade1010010Step1(): void
    {
        // For installs that already had v1.0.0 schema with `scope_read` column.
        // Create new tables, seed the implicit "read" scope, backfill junction
        // rows for every existing key, drop the legacy column.

        $db = $this->db();

        $db->query("
            CREATE TABLE IF NOT EXISTS `xf_cav7_api_key_scope_def` (
                `scope_id`              INT UNSIGNED   NOT NULL AUTO_INCREMENT,
                `scope_name`            VARCHAR(50)    NOT NULL,
                `title`                 VARCHAR(100)   NOT NULL,
                `description`           TEXT           NOT NULL,
                `permission_group_id`   VARCHAR(25)    NOT NULL DEFAULT '',
                `permission_id`         VARCHAR(25)    NOT NULL DEFAULT '',
                `is_active`             TINYINT(1)     NOT NULL DEFAULT 1,
                `display_order`         INT UNSIGNED   NOT NULL DEFAULT 0,
                PRIMARY KEY (`scope_id`),
                UNIQUE KEY `scope_name` (`scope_name`),
                KEY `is_active_display_order` (`is_active`, `display_order`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->query("
            CREATE TABLE IF NOT EXISTS `xf_cav7_api_key_scope` (
                `key_id`   INT UNSIGNED NOT NULL,
                `scope_id` INT UNSIGNED NOT NULL,
                PRIMARY KEY (`key_id`, `scope_id`),
                KEY `scope_id` (`scope_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $readScopeId = (int) $db->fetchOne(
            "SELECT scope_id FROM xf_cav7_api_key_scope_def WHERE scope_name = 'read'"
        );

        if (!$readScopeId)
        {
            $db->insert('xf_cav7_api_key_scope_def', [
                'scope_name'          => 'read',
                'title'               => 'Read',
                'description'         => 'Read access to public API data.',
                'permission_group_id' => '',
                'permission_id'       => '',
                'is_active'           => 1,
                'display_order'       => 0,
            ]);
            $readScopeId = (int) $db->lastInsertId();
        }

        $db->query(
            "INSERT IGNORE INTO xf_cav7_api_key_scope (key_id, scope_id)
             SELECT key_id, ? FROM xf_cav7_api_key",
            [$readScopeId]
        );

        if ($this->columnExists('xf_cav7_api_key', 'scope_read'))
        {
            $db->query("ALTER TABLE xf_cav7_api_key DROP COLUMN `scope_read`");
        }
    }

    public function upgrade1020010Step1(): void
    {
        $sm = $this->schemaManager();

        $sm->alterTable('xf_cav7_api_key_scope_def', function ($t) {
            if (!$this->columnExists('xf_cav7_api_key_scope_def', 'user_group_ids'))
            {
                $t->addColumn('user_group_ids', 'varchar', 255)
                    ->setDefault('');
            }
            if ($this->columnExists('xf_cav7_api_key_scope_def', 'permission_group_id'))
            {
                $t->dropColumns(['permission_group_id', 'permission_id']);
            }
        });
    }

    public function upgrade1020410Step1(): void
    {
        // 1.2.4 renamed the add-on's tables from xf_cav7_* to xf_15meu_*.
        // Rename in place to preserve all existing key/scope data. Each rename
        // is guarded so the step is idempotent and never clobbers an existing
        // xf_15meu_* table.
        $sm = $this->schemaManager();

        $renames = [
            'xf_cav7_api_key'           => 'xf_15meu_api_key',
            'xf_cav7_api_key_scope_def' => 'xf_15meu_api_key_scope_def',
            'xf_cav7_api_key_scope'     => 'xf_15meu_api_key_scope',
        ];

        foreach ($renames as $oldTable => $newTable)
        {
            $oldExists = $this->tableExists($oldTable);
            $newExists = $this->tableExists($newTable);

            if ($oldExists && $newExists)
            {
                throw new \RuntimeException(
                    "Cannot migrate API key tables: both `{$oldTable}` and `{$newTable}` exist. "
                    . "Automatic migration cannot safely determine which table contains "
                    . "authoritative data. Inspect and resolve the conflict manually "
                    . "(drop or archive one of the tables), then retry the upgrade."
                );
            }

            if ($oldExists)
            {
                $sm->renameTable($oldTable, $newTable);
            }
        }
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
