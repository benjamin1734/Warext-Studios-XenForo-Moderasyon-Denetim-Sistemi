<?php

namespace Warext\ModerationAudit\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

class AuditCase extends Entity
{
    public static function getStructure(Structure $structure)
    {
        $structure->table = 'xf_warext_audit_case';
        $structure->shortName = 'Warext\ModerationAudit:AuditCase';
        $structure->primaryKey = 'case_id';
        $structure->columns = [
            'case_id' => ['type' => self::UINT, 'autoIncrement' => true],
            'event_uid' => ['type' => self::STR, 'maxLength' => 64, 'default' => ''],
            'source_type' => ['type' => self::STR, 'maxLength' => 32, 'default' => ''],
            'source_id' => ['type' => self::UINT, 'default' => 0],
            'source_log_id' => ['type' => self::UINT, 'default' => 0],
            'moderator_user_id' => ['type' => self::UINT, 'default' => 0],
            'target_user_id' => ['type' => self::UINT, 'default' => 0],
            'content_type' => ['type' => self::STR, 'maxLength' => 25, 'default' => ''],
            'content_id' => ['type' => self::UINT, 'default' => 0],
            'action' => ['type' => self::STR, 'maxLength' => 50, 'default' => ''],
            'action_date' => ['type' => self::UINT, 'default' => 0],
            'risk_level' => ['type' => self::STR, 'allowedValues' => ['normal', 'elevated', 'critical'], 'default' => 'normal'],
            'status' => ['type' => self::STR, 'allowedValues' => ['pending', 'assigned', 'in_review', 'reviewed', 'final', 'skipped'], 'default' => 'pending'],
            'rule_key' => ['type' => self::STR, 'maxLength' => 50, 'default' => ''],
            'reason' => ['type' => self::STR, 'maxLength' => 255, 'default' => ''],
            'required_reviews' => ['type' => self::UINT, 'max' => 3, 'default' => 1],
            'priority' => ['type' => self::UINT, 'default' => 0],
            'is_critical' => ['type' => self::BOOL, 'default' => false],
            'final_verdict' => ['type' => self::STR, 'maxLength' => 25, 'default' => ''],
            'finalized_date' => ['type' => self::UINT, 'default' => 0],
            'metadata' => ['type' => self::STR, 'default' => ''],
            'created_date' => ['type' => self::UINT, 'default' => 0],
            'updated_date' => ['type' => self::UINT, 'default' => 0]
        ];
        $structure->relations = [
            'Moderator' => [
                'entity' => 'XF:User',
                'type' => self::TO_ONE,
                'conditions' => [['user_id', '=', '$moderator_user_id']],
                'primary' => true
            ],
            'TargetUser' => [
                'entity' => 'XF:User',
                'type' => self::TO_ONE,
                'conditions' => [['user_id', '=', '$target_user_id']],
                'primary' => true
            ]
        ];
        return $structure;
    }
}
