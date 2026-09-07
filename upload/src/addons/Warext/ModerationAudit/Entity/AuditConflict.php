<?php

namespace Warext\ModerationAudit\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

class AuditConflict extends Entity
{
    public static function getStructure(Structure $structure)
    {
        $structure->table = 'xf_warext_audit_conflict';
        $structure->shortName = 'Warext\ModerationAudit:AuditConflict';
        $structure->primaryKey = 'conflict_id';
        $structure->columns = [
            'conflict_id' => ['type' => self::UINT, 'autoIncrement' => true],
            'case_id' => ['type' => self::UINT, 'required' => true],
            'auditor_user_id' => ['type' => self::UINT, 'required' => true],
            'conflict_type' => ['type' => self::STR, 'maxLength' => 30, 'default' => 'other'],
            'reason' => ['type' => self::STR, 'maxLength' => 255, 'default' => ''],
            'created_by_user_id' => ['type' => self::UINT, 'default' => 0],
            'created_date' => ['type' => self::UINT, 'default' => 0]
        ];
        $structure->relations = [
            'Case' => [
                'entity' => 'Warext\ModerationAudit:AuditCase',
                'type' => self::TO_ONE,
                'conditions' => 'case_id',
                'primary' => true
            ],
            'Auditor' => [
                'entity' => 'XF:User',
                'type' => self::TO_ONE,
                'conditions' => [['user_id', '=', '$auditor_user_id']],
                'primary' => true
            ],
            'CreatedBy' => [
                'entity' => 'XF:User',
                'type' => self::TO_ONE,
                'conditions' => [['user_id', '=', '$created_by_user_id']]
            ]
        ];
        return $structure;
    }
}
