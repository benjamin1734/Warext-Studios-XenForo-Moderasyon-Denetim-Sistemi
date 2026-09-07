<?php

namespace Warext\ModerationAudit\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

class AuditReviewRevision extends Entity
{
    public function isIntegrityValid(): bool
    {
        $data = (string)$this->review_data;
        $hash = (string)$this->data_hash;
        return $hash !== '' && hash_equals($hash, hash('sha256', $data));
    }

    protected function _preSave()
    {
        parent::_preSave();

        if ($this->exists())
        {
            throw new \LogicException('Denetim değerlendirme revizyonları değiştirilemez.');
        }
    }

    protected function _preDelete()
    {
        parent::_preDelete();
        throw new \LogicException('Denetim değerlendirme revizyonları silinemez.');
    }

    public static function getStructure(Structure $structure)
    {
        $structure->table = 'xf_warext_audit_review_revision';
        $structure->shortName = 'Warext\ModerationAudit:AuditReviewRevision';
        $structure->primaryKey = 'revision_id';
        $structure->columns = [
            'revision_id' => ['type' => self::UINT, 'autoIncrement' => true],
            'review_id' => ['type' => self::UINT, 'required' => true],
            'revision' => ['type' => self::UINT, 'required' => true],
            'review_data' => ['type' => self::STR, 'default' => ''],
            'data_hash' => ['type' => self::STR, 'maxLength' => 64, 'default' => ''],
            'created_date' => ['type' => self::UINT, 'default' => 0]
        ];
        $structure->relations = [
            'Review' => [
                'entity' => 'Warext\ModerationAudit:AuditReview',
                'type' => self::TO_ONE,
                'conditions' => 'review_id',
                'primary' => true
            ]
        ];
        return $structure;
    }
}
