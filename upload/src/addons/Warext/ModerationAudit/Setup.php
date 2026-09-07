<?php

namespace Warext\ModerationAudit;

use XF\AddOn\AbstractSetup;
use XF\AddOn\StepRunnerInstallTrait;
use XF\AddOn\StepRunnerUninstallTrait;
use XF\AddOn\StepRunnerUpgradeTrait;
use XF\Db\Schema\Create;

class Setup extends AbstractSetup
{
    use StepRunnerInstallTrait;
    use StepRunnerUpgradeTrait;
    use StepRunnerUninstallTrait;

    public function installStep1(): void
    {
        $sm = $this->schemaManager();

        $sm->createTable('xf_warext_audit_case', function (Create $table)
        {
            $table->checkExists(true);
            $table->addColumn('case_id', 'int')->autoIncrement();
            $table->addColumn('event_uid', 'varbinary', 64)->setDefault('');
            $table->addColumn('source_type', 'varchar', 32)->setDefault('');
            $table->addColumn('source_id', 'int')->setDefault(0);
            $table->addColumn('source_log_id', 'int')->setDefault(0);
            $table->addColumn('moderator_user_id', 'int')->setDefault(0);
            $table->addColumn('target_user_id', 'int')->setDefault(0);
            $table->addColumn('content_type', 'varbinary', 25)->setDefault('');
            $table->addColumn('content_id', 'int')->setDefault(0);
            $table->addColumn('action', 'varchar', 50)->setDefault('');
            $table->addColumn('action_date', 'int')->setDefault(0);
            $table->addColumn('risk_level', 'enum')->values(['normal', 'elevated', 'critical'])->setDefault('normal');
            $table->addColumn('status', 'enum')->values(['pending', 'assigned', 'in_review', 'reviewed', 'final', 'skipped'])->setDefault('pending');
            $table->addColumn('rule_key', 'varchar', 50)->setDefault('');
            $table->addColumn('reason', 'varchar', 255)->setDefault('');
            $table->addColumn('required_reviews', 'tinyint')->setDefault(1);
            $table->addColumn('priority', 'smallint')->setDefault(0);
            $table->addColumn('is_critical', 'tinyint')->setDefault(0);
            $table->addColumn('final_verdict', 'varchar', 25)->setDefault('');
            $table->addColumn('finalized_date', 'int')->setDefault(0);
            $table->addColumn('metadata', 'mediumblob');
            $table->addColumn('created_date', 'int')->setDefault(0);
            $table->addColumn('updated_date', 'int')->setDefault(0);
            $table->addPrimaryKey('case_id');
            $table->addUniqueKey('event_uid', 'event_uid');
            $table->addKey(['status', 'action_date'], 'status_action_date');
            $table->addKey(['moderator_user_id', 'action_date'], 'moderator_action_date');
            $table->addKey(['target_user_id', 'action_date'], 'target_action_date');
            $table->addKey(['source_type', 'source_log_id'], 'source_log');
            $table->addKey(['risk_level', 'action_date'], 'risk_action_date');
        });

        $sm->createTable('xf_warext_audit_snapshot', function (Create $table)
        {
            $table->checkExists(true);
            $table->addColumn('snapshot_id', 'int')->autoIncrement();
            $table->addColumn('case_id', 'int')->setDefault(0);
            $table->addColumn('snapshot_type', 'varchar', 25)->setDefault('primary');
            $table->addColumn('snapshot_data', 'mediumblob');
            $table->addColumn('data_hash', 'varbinary', 64)->setDefault('');
            $table->addColumn('is_sensitive', 'tinyint')->setDefault(0);
            $table->addColumn('redaction_level', 'varchar', 20)->setDefault('none');
            $table->addColumn('created_date', 'int')->setDefault(0);
            $table->addPrimaryKey('snapshot_id');
            $table->addUniqueKey(['case_id', 'snapshot_type'], 'case_snapshot_type');
            $table->addKey('created_date');
        });

        $sm->createTable('xf_warext_audit_review', function (Create $table)
        {
            $table->checkExists(true);
            $table->addColumn('review_id', 'int')->autoIncrement();
            $table->addColumn('case_id', 'int')->setDefault(0);
            $table->addColumn('auditor_user_id', 'int')->setDefault(0);
            $table->addColumn('verdict', 'varchar', 25)->setDefault('');
            $table->addColumn('rule_rating', 'varchar', 25)->setDefault('');
            $table->addColumn('penalty_rating', 'varchar', 25)->setDefault('');
            $table->addColumn('communication_rating', 'varchar', 25)->setDefault('');
            $table->addColumn('confidence', 'tinyint')->setDefault(0);
            $table->addColumn('reason', 'mediumblob');
            $table->addColumn('revision', 'smallint')->setDefault(1);
            $table->addColumn('created_date', 'int')->setDefault(0);
            $table->addColumn('updated_date', 'int')->setDefault(0);
            $table->addColumn('locked_date', 'int')->setDefault(0);
            $table->addPrimaryKey('review_id');
            $table->addUniqueKey(['case_id', 'auditor_user_id'], 'case_auditor');
            $table->addKey(['auditor_user_id', 'created_date'], 'auditor_created');
            $table->addKey(['verdict', 'created_date'], 'verdict_created');
        });

        $sm->createTable('xf_warext_audit_review_revision', function (Create $table)
        {
            $table->checkExists(true);
            $table->addColumn('revision_id', 'int')->autoIncrement();
            $table->addColumn('review_id', 'int')->setDefault(0);
            $table->addColumn('revision', 'smallint')->setDefault(1);
            $table->addColumn('review_data', 'mediumblob');
            $table->addColumn('data_hash', 'varbinary', 64)->setDefault('');
            $table->addColumn('created_date', 'int')->setDefault(0);
            $table->addPrimaryKey('revision_id');
            $table->addUniqueKey(['review_id', 'revision'], 'review_revision');
            $table->addKey('created_date');
        });

        $sm->createTable('xf_warext_audit_assignment', function (Create $table)
        {
            $table->checkExists(true);
            $table->addColumn('assignment_id', 'int')->autoIncrement();
            $table->addColumn('case_id', 'int')->setDefault(0);
            $table->addColumn('auditor_user_id', 'int')->setDefault(0);
            $table->addColumn('status', 'enum')->values(['assigned', 'opened', 'completed', 'recused', 'cancelled'])->setDefault('assigned');
            $table->addColumn('blind_mode', 'enum')->values(['none', 'moderator', 'full'])->setDefault('moderator');
            $table->addColumn('assignment_source', 'enum')->values(['auto', 'replacement', 'legacy'])->setDefault('auto');
            $table->addColumn('assigned_by_user_id', 'int')->setDefault(0);
            $table->addColumn('replacement_for_assignment_id', 'int')->setDefault(0);
            $table->addColumn('assigned_date', 'int')->setDefault(0);
            $table->addColumn('opened_date', 'int')->setDefault(0);
            $table->addColumn('completed_date', 'int')->setDefault(0);
            $table->addPrimaryKey('assignment_id');
            $table->addUniqueKey(['case_id', 'auditor_user_id'], 'case_auditor');
            $table->addKey(['auditor_user_id', 'status', 'assigned_date'], 'auditor_status_date');
            $table->addKey(['case_id', 'status'], 'case_status');
        });

        $sm->createTable('xf_warext_audit_conflict', function (Create $table)
        {
            $table->checkExists(true);
            $table->addColumn('conflict_id', 'int')->autoIncrement();
            $table->addColumn('case_id', 'int')->setDefault(0);
            $table->addColumn('auditor_user_id', 'int')->setDefault(0);
            $table->addColumn('conflict_type', 'varchar', 30)->setDefault('other');
            $table->addColumn('reason', 'varchar', 255)->setDefault('');
            $table->addColumn('created_by_user_id', 'int')->setDefault(0);
            $table->addColumn('created_date', 'int')->setDefault(0);
            $table->addPrimaryKey('conflict_id');
            $table->addKey(['case_id', 'created_date'], 'case_created');
            $table->addKey(['auditor_user_id', 'created_date'], 'auditor_created');
        });

        $sm->createTable('xf_warext_audit_auditor', function (Create $table)
        {
            $table->checkExists(true);
            $table->addColumn('user_id', 'int')->setDefault(0);
            $table->addColumn('status', 'enum')->values(['active', 'paused', 'suspended'])->setDefault('active');
            $table->addColumn('max_active_assignments', 'tinyint')->setDefault(20);
            $table->addColumn('joined_date', 'int')->setDefault(0);
            $table->addColumn('last_assigned_date', 'int')->setDefault(0);
            $table->addColumn('assigned_by_user_id', 'int')->setDefault(0);
            $table->addColumn('note', 'varchar', 255)->setDefault('');
            $table->addPrimaryKey('user_id');
            $table->addKey(['status', 'last_assigned_date'], 'status_last_assigned');
        });

        $sm->createTable('xf_warext_audit_report', function (Create $table)
        {
            $table->checkExists(true);
            $table->addColumn('report_id', 'int')->autoIncrement();
            $table->addColumn('period_type', 'enum')->values(['weekly', 'monthly', 'custom'])->setDefault('weekly');
            $table->addColumn('period_start', 'int')->setDefault(0);
            $table->addColumn('period_end', 'int')->setDefault(0);
            $table->addColumn('status', 'enum')->values(['draft', 'final'])->setDefault('draft');
            $table->addColumn('generated_by', 'int')->setDefault(0);
            $table->addColumn('generated_date', 'int')->setDefault(0);
            $table->addColumn('summary_data', 'mediumblob');
            $table->addColumn('report_hash', 'varbinary', 64)->setDefault('');
            $table->addPrimaryKey('report_id');
            $table->addUniqueKey(['period_type', 'period_start', 'period_end'], 'period');
            $table->addKey(['status', 'generated_date'], 'status_generated');
        });

        $sm->createTable('xf_warext_audit_state', function (Create $table)
        {
            $table->checkExists(true);
            $table->addColumn('state_key', 'varbinary', 50)->setDefault('');
            $table->addColumn('state_value', 'mediumblob');
            $table->addColumn('updated_date', 'int')->setDefault(0);
            $table->addPrimaryKey('state_key');
        });
    }

    public function installStep2(): void
    {
        $db = $this->db();
        $now = time();
        $startModeratorLogId = (int)$db->fetchOne('SELECT COALESCE(MAX(moderator_log_id), 0) FROM xf_moderator_log');

        $state = [
            'installed_at' => (string)$now,
            'audit_start_date' => (string)$now,
            'start_moderator_log_id' => (string)$startModeratorLogId,
            'schema_version' => '4',
            'report_anon_salt' => bin2hex(random_bytes(32)),
            'assignment_policy' => 'balanced',
            'normal_blind_mode' => 'moderator',
            'elevated_blind_mode' => 'moderator',
            'critical_blind_mode' => 'full'
        ];

        foreach ($state as $key => $value)
        {
            $db->insert('xf_warext_audit_state', [
                'state_key' => $key,
                'state_value' => $value,
                'updated_date' => $now
            ], false, 'state_value = VALUES(state_value), updated_date = VALUES(updated_date)');
        }
    }

    public function upgrade1000030Step1(): void
    {
        $this->schemaManager()->alterTable('xf_warext_audit_snapshot', function (\XF\Db\Schema\Alter $table)
        {
            $table->addColumn('is_sensitive', 'tinyint')->setDefault(0);
            $table->addColumn('redaction_level', 'varchar', 20)->setDefault('none');
        });

        $this->db()->insert('xf_warext_audit_state', [
            'state_key' => 'schema_version',
            'state_value' => '2',
            'updated_date' => time()
        ], false, 'state_value = VALUES(state_value), updated_date = VALUES(updated_date)');
    }

    public function upgrade1000061Step1(): void
    {
        $sm = $this->schemaManager();
        $sm->createTable('xf_warext_audit_auditor', function (Create $table)
        {
            $table->checkExists(true);
            $table->addColumn('user_id', 'int')->setDefault(0);
            $table->addColumn('status', 'enum')->values(['active', 'paused', 'suspended'])->setDefault('active');
            $table->addColumn('max_active_assignments', 'tinyint')->setDefault(20);
            $table->addColumn('joined_date', 'int')->setDefault(0);
            $table->addColumn('last_assigned_date', 'int')->setDefault(0);
            $table->addColumn('assigned_by_user_id', 'int')->setDefault(0);
            $table->addColumn('note', 'varchar', 255)->setDefault('');
            $table->addPrimaryKey('user_id');
            $table->addKey(['status', 'last_assigned_date'], 'status_last_assigned');
        });

        $sm->alterTable('xf_warext_audit_assignment', function (\XF\Db\Schema\Alter $table)
        {
            $table->addColumn('blind_mode', 'enum')->values(['none', 'moderator', 'full'])->setDefault('moderator');
            $table->addColumn('assignment_source', 'enum')->values(['auto', 'replacement', 'legacy'])->setDefault('auto');
            $table->addColumn('assigned_by_user_id', 'int')->setDefault(0);
            $table->addColumn('replacement_for_assignment_id', 'int')->setDefault(0);
        });

        $sm->alterTable('xf_warext_audit_conflict', function (\XF\Db\Schema\Alter $table)
        {
            $table->addColumn('created_by_user_id', 'int')->setDefault(0);
        });

        $now = time();
        $db = $this->db();
        $reviews = $db->fetchAll('SELECT review_id, case_id, auditor_user_id, created_date, updated_date FROM xf_warext_audit_review');
        foreach ($reviews as $review)
        {
            $existing = (int)$db->fetchOne(
                'SELECT assignment_id FROM xf_warext_audit_assignment WHERE case_id = ? AND auditor_user_id = ?',
                [(int)$review['case_id'], (int)$review['auditor_user_id']]
            );
            if (!$existing)
            {
                $db->insert('xf_warext_audit_assignment', [
                    'case_id' => (int)$review['case_id'],
                    'auditor_user_id' => (int)$review['auditor_user_id'],
                    'status' => 'completed',
                    'blind_mode' => 'none',
                    'assignment_source' => 'legacy',
                    'assigned_by_user_id' => 0,
                    'replacement_for_assignment_id' => 0,
                    'assigned_date' => (int)($review['created_date'] ?: $now),
                    'opened_date' => (int)($review['created_date'] ?: $now),
                    'completed_date' => (int)($review['updated_date'] ?: $now)
                ]);
            }
        }

        $auditorIds = $db->fetchAllColumn('SELECT DISTINCT auditor_user_id FROM xf_warext_audit_assignment WHERE auditor_user_id > 0');
        foreach ($auditorIds as $auditorId)
        {
            try
            {
                $user = \XF::em()->find('XF:User', (int)$auditorId);
                if (!$user || (string)$user->user_state !== 'valid' || (bool)$user->is_admin || (bool)$user->is_moderator)
                {
                    continue;
                }
                if ($user->hasPermission('general', 'warextAuditManage') || !$user->hasPermission('general', 'warextAuditView') || !$user->hasPermission('general', 'warextAuditReview'))
                {
                    continue;
                }
                $db->insert('xf_warext_audit_auditor', [
                    'user_id' => (int)$auditorId,
                    'status' => 'active',
                    'max_active_assignments' => 20,
                    'joined_date' => $now,
                    'last_assigned_date' => 0,
                    'assigned_by_user_id' => 0,
                    'note' => 'Alpha 5 geçmiş değerlendirme migrasyonu'
                ], false, 'user_id = VALUES(user_id)');
            }
            catch (\Throwable $e)
            {
                \XF::logException($e, false, 'Warext ModerationAudit auditor migration: ');
            }
        }

        foreach ([
            'schema_version' => '3',
            'assignment_policy' => 'balanced',
            'normal_blind_mode' => 'moderator',
            'elevated_blind_mode' => 'moderator',
            'critical_blind_mode' => 'full'
        ] as $key => $value)
        {
            $db->insert('xf_warext_audit_state', [
                'state_key' => $key,
                'state_value' => $value,
                'updated_date' => $now
            ], false, 'state_value = VALUES(state_value), updated_date = VALUES(updated_date)');
        }
    }

    public function upgrade1000070Step1(): void
    {
        $now = time();
        $db = $this->db();
        foreach ([
            'schema_version' => '4',
            'report_anon_salt' => bin2hex(random_bytes(32))
        ] as $key => $value)
        {
            if ($key === 'report_anon_salt')
            {
                $exists = (string)$db->fetchOne('SELECT state_value FROM xf_warext_audit_state WHERE state_key = ?', $key);
                if ($exists !== '')
                {
                    continue;
                }
            }
            $db->insert('xf_warext_audit_state', [
                'state_key' => $key,
                'state_value' => $value,
                'updated_date' => $now
            ], false, 'state_value = VALUES(state_value), updated_date = VALUES(updated_date)');
        }
    }

    public function uninstallStep1(): void
    {
        $tables = [
            'xf_warext_audit_review_revision',
            'xf_warext_audit_review',
            'xf_warext_audit_assignment',
            'xf_warext_audit_conflict',
            'xf_warext_audit_auditor',
            'xf_warext_audit_snapshot',
            'xf_warext_audit_report',
            'xf_warext_audit_case',
            'xf_warext_audit_state'
        ];

        foreach ($tables as $table)
        {
            $this->schemaManager()->dropTable($table);
        }
    }
}
