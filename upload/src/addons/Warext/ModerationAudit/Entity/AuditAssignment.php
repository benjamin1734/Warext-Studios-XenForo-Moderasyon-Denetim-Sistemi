<?php

namespace Warext\ModerationAudit\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

class AuditAssignment extends Entity
{
    public static function getStructure(Structure $structure)
    {
        $structure->table = 'xf_warext_audit_assignment';
        $structure->shortName = 'Warext\ModerationAudit:AuditAssignment';
        $structure->primaryKey = 'assignment_id';
        $structure->columns = [
            'assignment_id' => ['type' => self::UINT, 'autoIncrement' => true],
            'case_id' => ['type' => self::UINT, 'required' => true],
            'auditor_user_id' => ['type' => self::UINT, 'required' => true],
            'status' => ['type' => self::STR, 'allowedValues' => ['assigned', 'opened', 'completed', 'recused', 'cancelled'], 'default' => 'assigned'],
            'blind_mode' => ['type' => self::STR, 'allowedValues' => ['none', 'moderator', 'full'], 'default' => 'moderator'],
            'assignment_source' => ['type' => self::STR, 'allowedValues' => ['auto', 'replacement', 'legacy'], 'default' => 'auto'],
            'assigned_by_user_id' => ['type' => self::UINT, 'default' => 0],
            'replacement_for_assignment_id' => ['type' => self::UINT, 'default' => 0],
            'assigned_date' => ['type' => self::UINT, 'default' => 0],
            'opened_date' => ['type' => self::UINT, 'default' => 0],
            'completed_date' => ['type' => self::UINT, 'default' => 0]
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
            'AssignedBy' => [
                'entity' => 'XF:User',
                'type' => self::TO_ONE,
                'conditions' => [['user_id', '=', '$assigned_by_user_id']]
            ]
        ];
        return $structure;
    }
}
