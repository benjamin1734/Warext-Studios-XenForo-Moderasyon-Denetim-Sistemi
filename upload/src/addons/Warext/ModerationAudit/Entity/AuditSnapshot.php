<?php

namespace Warext\ModerationAudit\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

class AuditSnapshot extends Entity
{
    public static function getStructure(Structure $structure)
    {
        $structure->table = 'xf_warext_audit_snapshot';
        $structure->shortName = 'Warext\ModerationAudit:AuditSnapshot';
        $structure->primaryKey = 'snapshot_id';
        $structure->columns = [
            'snapshot_id' => ['type' => self::UINT, 'autoIncrement' => true],
            'case_id' => ['type' => self::UINT, 'required' => true],
            'snapshot_type' => ['type' => self::STR, 'maxLength' => 25, 'default' => 'primary'],
            'snapshot_data' => ['type' => self::STR, 'default' => ''],
            'data_hash' => ['type' => self::STR, 'maxLength' => 64, 'default' => ''],
            'is_sensitive' => ['type' => self::BOOL, 'default' => false],
            'redaction_level' => ['type' => self::STR, 'maxLength' => 20, 'default' => 'none'],
            'created_date' => ['type' => self::UINT, 'default' => 0]
        ];
        $structure->relations = [
            'Case' => [
                'entity' => 'Warext\ModerationAudit:AuditCase',
                'type' => self::TO_ONE,
                'conditions' => 'case_id',
                'primary' => true
            ]
        ];
        return $structure;
    }

    public function isIntegrityValid(): bool
    {
        $data = (string)$this->snapshot_data;
        $hash = (string)$this->data_hash;
        return $data !== '' && $hash !== '' && hash_equals($hash, hash('sha256', $data));
    }

    protected function _preSave()
    {
        if (!$this->isInsert())
        {
            throw new \LogicException('Audit snapshots are immutable.');
        }

        parent::_preSave();
    }

    protected function _preDelete()
    {
        throw new \LogicException('Audit snapshots cannot be deleted through the entity layer.');
    }
}
