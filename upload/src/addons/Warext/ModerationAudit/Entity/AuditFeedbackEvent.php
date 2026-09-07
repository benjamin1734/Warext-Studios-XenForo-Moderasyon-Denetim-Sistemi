<?php

namespace Warext\ModerationAudit\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

class AuditFeedbackEvent extends Entity
{
    protected function _preSave()
    {
        if ($this->exists())
        {
            foreach (['feedback_id', 'event_type', 'actor_user_id', 'from_status', 'to_status', 'event_data', 'data_hash', 'created_date'] as $field)
            {
                if ($this->isChanged($field))
                {
                    $this->error('İtiraz / öneri olay geçmişi değiştirilemez.', $field);
                    break;
                }
            }
        }

        parent::_preSave();
    }

    protected function _preDelete()
    {
        throw new \LogicException('İtiraz / öneri olay geçmişi silinemez.');
    }

    public static function getStructure(Structure $structure)
    {
        $structure->table = 'xf_warext_audit_feedback_event';
        $structure->shortName = 'Warext\ModerationAudit:AuditFeedbackEvent';
        $structure->primaryKey = 'event_id';
        $structure->columns = [
            'event_id' => ['type' => self::UINT, 'autoIncrement' => true],
            'feedback_id' => ['type' => self::UINT, 'default' => 0],
            'event_type' => ['type' => self::STR, 'maxLength' => 32, 'default' => ''],
            'actor_user_id' => ['type' => self::UINT, 'default' => 0],
            'from_status' => ['type' => self::STR, 'maxLength' => 25, 'default' => ''],
            'to_status' => ['type' => self::STR, 'maxLength' => 25, 'default' => ''],
            'event_data' => ['type' => self::STR, 'default' => ''],
            'data_hash' => ['type' => self::STR, 'maxLength' => 64, 'default' => ''],
            'created_date' => ['type' => self::UINT, 'default' => 0]
        ];
        $structure->relations = [
            'Feedback' => [
                'entity' => 'Warext\ModerationAudit:AuditFeedback',
                'type' => self::TO_ONE,
                'conditions' => [['feedback_id', '=', '$feedback_id']],
                'primary' => true
            ],
            'Actor' => [
                'entity' => 'XF:User',
                'type' => self::TO_ONE,
                'conditions' => [['user_id', '=', '$actor_user_id']],
                'primary' => true
            ]
        ];
        return $structure;
    }
}
