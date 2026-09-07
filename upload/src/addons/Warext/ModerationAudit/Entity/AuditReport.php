<?php

namespace Warext\ModerationAudit\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

class AuditReport extends Entity
{
    protected function _preSave()
    {
        if ($this->exists() && (string)$this->getExistingValue('status') === 'final')
        {
            foreach (['period_type', 'period_start', 'period_end', 'status', 'generated_by', 'generated_date', 'summary_data', 'report_hash'] as $field)
            {
                if ($this->isChanged($field))
                {
                    $this->error('Sonuçlandırılmış denetim raporları değiştirilemez.', $field);
                    break;
                }
            }
        }
        parent::_preSave();
    }

    protected function _preDelete()
    {
        if ((string)$this->status === 'final')
        {
            throw new \LogicException('Sonuçlandırılmış denetim raporları silinemez.');
        }
        parent::_preDelete();
    }

    public static function getStructure(Structure $structure)
    {
        $structure->table = 'xf_warext_audit_report';
        $structure->shortName = 'Warext\ModerationAudit:AuditReport';
        $structure->primaryKey = 'report_id';
        $structure->columns = [
            'report_id' => ['type' => self::UINT, 'autoIncrement' => true],
            'period_type' => ['type' => self::STR, 'allowedValues' => ['weekly', 'monthly', 'custom'], 'default' => 'weekly'],
            'period_start' => ['type' => self::UINT, 'default' => 0],
            'period_end' => ['type' => self::UINT, 'default' => 0],
            'status' => ['type' => self::STR, 'allowedValues' => ['draft', 'final'], 'default' => 'draft'],
            'generated_by' => ['type' => self::UINT, 'default' => 0],
            'generated_date' => ['type' => self::UINT, 'default' => 0],
            'summary_data' => ['type' => self::STR, 'default' => ''],
            'report_hash' => ['type' => self::STR, 'maxLength' => 64, 'default' => '']
        ];
        $structure->relations = [
            'GeneratedBy' => [
                'entity' => 'XF:User',
                'type' => self::TO_ONE,
                'conditions' => [['user_id', '=', '$generated_by']],
                'primary' => true
            ]
        ];
        return $structure;
    }
}
