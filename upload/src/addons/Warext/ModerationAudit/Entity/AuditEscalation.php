<?php

namespace Warext\ModerationAudit\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

class AuditEscalation extends Entity
{
    protected function _preSave()
    {
        if ($this->exists())
        {
            foreach (['source_type', 'source_id', 'level', 'reason', 'created_date', 'event_hash'] as $field)
            {
                if ($this->isChanged($field))
                {
                    $this->error('Eskalasyon kaydının özgün alanları değiştirilemez.', $field);
                    break;
                }
            }

            if ((int)$this->getExistingValue('resolved_date') > 0)
            {
                foreach (['resolved_by_user_id', 'resolved_date', 'resolution_note', 'resolution_hash'] as $field)
                {
                    if ($this->isChanged($field))
                    {
                        $this->error('Çözümlenmiş eskalasyonun kapanış kaydı değiştirilemez.', $field);
                        break;
                    }
                }
            }
        }
        parent::_preSave();
    }

    protected function _preDelete()
    {
        throw new \LogicException('Eskalasyon kayıtları silinemez.');
    }

    public static function getStructure(Structure $structure)
    {
        $structure->table = 'xf_warext_audit_escalation';
        $structure->shortName = 'Warext\ModerationAudit:AuditEscalation';
        $structure->primaryKey = 'escalation_id';
        $structure->columns = [
            'escalation_id' => ['type' => self::UINT, 'autoIncrement' => true],
            'source_type' => ['type' => self::STR, 'allowedValues' => ['case', 'feedback'], 'default' => 'case'],
            'source_id' => ['type' => self::UINT, 'default' => 0],
            'level' => ['type' => self::UINT, 'default' => 1],
            'reason' => ['type' => self::STR, 'maxLength' => 255, 'default' => ''],
            'created_date' => ['type' => self::UINT, 'default' => 0],
            'event_hash' => ['type' => self::STR, 'maxLength' => 64, 'default' => ''],
            'resolved_by_user_id' => ['type' => self::UINT, 'default' => 0],
            'resolved_date' => ['type' => self::UINT, 'default' => 0],
            'resolution_note' => ['type' => self::STR, 'default' => ''],
            'resolution_hash' => ['type' => self::STR, 'maxLength' => 64, 'default' => '']
        ];
        $structure->relations = [
            'ResolvedBy' => [
                'entity' => 'XF:User',
                'type' => self::TO_ONE,
                'conditions' => [['user_id', '=', '$resolved_by_user_id']],
                'primary' => true
            ]
        ];
        return $structure;
    }
}
