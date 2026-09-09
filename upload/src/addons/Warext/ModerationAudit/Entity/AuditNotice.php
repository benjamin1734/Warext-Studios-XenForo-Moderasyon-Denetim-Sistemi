<?php

namespace Warext\ModerationAudit\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

class AuditNotice extends Entity
{
    protected function _preSave()
    {
        if ($this->exists())
        {
            foreach (['user_id', 'notice_type', 'source_type', 'source_id', 'message', 'created_date'] as $field)
            {
                if ($this->isChanged($field))
                {
                    $this->error('Bildirim kaydının özgün alanları değiştirilemez.', $field);
                    break;
                }
            }

            if ((int)$this->getExistingValue('read_date') > 0 && $this->isChanged('read_date'))
            {
                $this->error('Okunmuş denetim bildirimi tekrar okunmamış duruma getirilemez.', 'read_date');
            }
        }
        parent::_preSave();
    }

    protected function _preDelete()
    {
        throw new \LogicException('Denetim bildirimleri silinemez.');
    }

    public static function getStructure(Structure $structure)
    {
        $structure->table = 'xf_warext_audit_notice';
        $structure->shortName = 'Warext\ModerationAudit:AuditNotice';
        $structure->primaryKey = 'notice_id';
        $structure->columns = [
            'notice_id' => ['type' => self::UINT, 'autoIncrement' => true],
            'user_id' => ['type' => self::UINT, 'default' => 0],
            'notice_type' => ['type' => self::STR, 'maxLength' => 32, 'default' => ''],
            'source_type' => ['type' => self::STR, 'maxLength' => 20, 'default' => ''],
            'source_id' => ['type' => self::UINT, 'default' => 0],
            'message' => ['type' => self::STR, 'maxLength' => 255, 'default' => ''],
            'created_date' => ['type' => self::UINT, 'default' => 0],
            'read_date' => ['type' => self::UINT, 'default' => 0]
        ];
        $structure->relations = [
            'User' => [
                'entity' => 'XF:User',
                'type' => self::TO_ONE,
                'conditions' => [['user_id', '=', '$user_id']],
                'primary' => true
            ]
        ];
        return $structure;
    }
}
