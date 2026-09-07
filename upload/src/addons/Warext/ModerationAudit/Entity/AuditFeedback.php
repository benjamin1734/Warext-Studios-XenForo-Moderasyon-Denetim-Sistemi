<?php

namespace Warext\ModerationAudit\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

class AuditFeedback extends Entity
{
    protected function _preSave()
    {
        if ($this->exists())
        {
            foreach (['feedback_type', 'case_id', 'submitted_by_user_id', 'subject', 'message', 'created_date'] as $field)
            {
                if ($this->isChanged($field))
                {
                    $this->error('İtiraz / öneri başvurusunun özgün içeriği sonradan değiştirilemez.', $field);
                    break;
                }
            }
        }

        parent::_preSave();
    }

    protected function _preDelete()
    {
        throw new \LogicException('Denetim itirazı / önerisi silinemez. Kayıt, denetim izi olarak korunur.');
    }

    public static function getStructure(Structure $structure)
    {
        $structure->table = 'xf_warext_audit_feedback';
        $structure->shortName = 'Warext\ModerationAudit:AuditFeedback';
        $structure->primaryKey = 'feedback_id';
        $structure->columns = [
            'feedback_id' => ['type' => self::UINT, 'autoIncrement' => true],
            'feedback_type' => ['type' => self::STR, 'allowedValues' => ['appeal', 'suggestion'], 'default' => 'suggestion'],
            'case_id' => ['type' => self::UINT, 'default' => 0],
            'submitted_by_user_id' => ['type' => self::UINT, 'default' => 0],
            'subject' => ['type' => self::STR, 'maxLength' => 150, 'default' => ''],
            'message' => ['type' => self::STR, 'default' => ''],
            'status' => ['type' => self::STR, 'allowedValues' => ['open', 'under_review', 'accepted', 'rejected', 'implemented', 'closed'], 'default' => 'open'],
            'priority' => ['type' => self::STR, 'allowedValues' => ['low', 'normal', 'high', 'critical'], 'default' => 'normal'],
            'assigned_to_user_id' => ['type' => self::UINT, 'default' => 0],
            'last_response_date' => ['type' => self::UINT, 'default' => 0],
            'created_date' => ['type' => self::UINT, 'default' => 0],
            'updated_date' => ['type' => self::UINT, 'default' => 0]
        ];
        $structure->relations = [
            'Case' => [
                'entity' => 'Warext\ModerationAudit:AuditCase',
                'type' => self::TO_ONE,
                'conditions' => [['case_id', '=', '$case_id']],
                'primary' => true
            ],
            'Submitter' => [
                'entity' => 'XF:User',
                'type' => self::TO_ONE,
                'conditions' => [['user_id', '=', '$submitted_by_user_id']],
                'primary' => true
            ],
            'Assignee' => [
                'entity' => 'XF:User',
                'type' => self::TO_ONE,
                'conditions' => [['user_id', '=', '$assigned_to_user_id']],
                'primary' => true
            ],
            'Events' => [
                'entity' => 'Warext\ModerationAudit:AuditFeedbackEvent',
                'type' => self::TO_MANY,
                'conditions' => [['feedback_id', '=', '$feedback_id']],
                'order' => ['created_date', 'ASC']
            ]
        ];
        return $structure;
    }
}
