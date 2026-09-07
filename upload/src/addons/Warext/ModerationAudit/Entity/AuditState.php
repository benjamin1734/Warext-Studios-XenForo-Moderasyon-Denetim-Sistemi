<?php

namespace Warext\ModerationAudit\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

class AuditState extends Entity
{
    public static function getStructure(Structure $structure)
    {
        $structure->table = 'xf_warext_audit_state';
        $structure->shortName = 'Warext\ModerationAudit:AuditState';
        $structure->primaryKey = 'state_key';
        $structure->columns = [
            'state_key' => ['type' => self::STR, 'maxLength' => 50, 'required' => true],
            'state_value' => ['type' => self::STR, 'default' => ''],
            'updated_date' => ['type' => self::UINT, 'default' => 0]
        ];
        return $structure;
    }
}
