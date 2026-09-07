<?php

namespace Warext\ModerationAudit\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

class AuditReview extends Entity
{
    public static function getStructure(Structure $structure)
    {
        $structure->table = 'xf_warext_audit_review';
        $structure->shortName = 'Warext\ModerationAudit:AuditReview';
        $structure->primaryKey = 'review_id';
        $structure->columns = [
            'review_id' => ['type' => self::UINT, 'autoIncrement' => true],
            'case_id' => ['type' => self::UINT, 'required' => true],
            'auditor_user_id' => ['type' => self::UINT, 'required' => true],
            'verdict' => ['type' => self::STR, 'maxLength' => 25, 'allowedValues' => ['', 'correct', 'partly_correct', 'wrong', 'insufficient_evidence'], 'default' => ''],
            'rule_rating' => ['type' => self::STR, 'maxLength' => 25, 'allowedValues' => ['', 'correct', 'partly_correct', 'wrong', 'not_applicable', 'insufficient_evidence'], 'default' => ''],
            'penalty_rating' => ['type' => self::STR, 'maxLength' => 25, 'allowedValues' => ['', 'proportionate', 'too_light', 'too_severe', 'not_applicable', 'insufficient_evidence'], 'default' => ''],
            'communication_rating' => ['type' => self::STR, 'maxLength' => 25, 'allowedValues' => ['', 'professional', 'needs_improvement', 'inappropriate', 'not_applicable', 'insufficient_evidence'], 'default' => ''],
            'confidence' => ['type' => self::UINT, 'max' => 100, 'default' => 0],
            'reason' => ['type' => self::STR, 'default' => ''],
            'revision' => ['type' => self::UINT, 'default' => 1],
            'created_date' => ['type' => self::UINT, 'default' => 0],
            'updated_date' => ['type' => self::UINT, 'default' => 0],
            'locked_date' => ['type' => self::UINT, 'default' => 0]
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
            ]
        ];
        return $structure;
    }
}
