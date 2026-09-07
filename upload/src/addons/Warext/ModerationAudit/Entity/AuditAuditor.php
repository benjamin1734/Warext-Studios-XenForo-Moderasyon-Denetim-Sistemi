<?php

namespace Warext\ModerationAudit\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

class AuditAuditor extends Entity
{
    public static function getStructure(Structure $structure)
    {
        $structure->table = 'xf_warext_audit_auditor';
        $structure->shortName = 'Warext\ModerationAudit:AuditAuditor';
        $structure->primaryKey = 'user_id';
        $structure->columns = [
            'user_id' => ['type' => self::UINT, 'required' => true],
            'status' => ['type' => self::STR, 'allowedValues' => ['active', 'paused', 'suspended'], 'default' => 'active'],
            'max_active_assignments' => ['type' => self::UINT, 'max' => 100, 'default' => 20],
            'joined_date' => ['type' => self::UINT, 'default' => 0],
            'last_assigned_date' => ['type' => self::UINT, 'default' => 0],
            'assigned_by_user_id' => ['type' => self::UINT, 'default' => 0],
            'note' => ['type' => self::STR, 'maxLength' => 255, 'default' => '']
        ];
        $structure->relations = [
            'User' => [
                'entity' => 'XF:User',
                'type' => self::TO_ONE,
                'conditions' => 'user_id',
                'primary' => true
            ],
            'AssignedBy' => [
                'entity' => 'XF:User',
                'type' => self::TO_ONE,
                'conditions' => [['user_id', '=', '$assigned_by_user_id']]
            ]
        ];
        return $structure;
    }
}
