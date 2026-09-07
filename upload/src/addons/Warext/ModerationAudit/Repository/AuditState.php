<?php

namespace Warext\ModerationAudit\Repository;

use XF\Mvc\Entity\Repository;

class AuditState extends Repository
{
    public function get(string $key, mixed $default = null): mixed
    {
        $entity = $this->finder('Warext\ModerationAudit:AuditState')
            ->where('state_key', $key)
            ->fetchOne();

        return $entity ? $entity->state_value : $default;
    }

    public function set(string $key, string $value): void
    {
        $entity = $this->finder('Warext\ModerationAudit:AuditState')
            ->where('state_key', $key)
            ->fetchOne();

        if (!$entity)
        {
            $entity = $this->em->create('Warext\ModerationAudit:AuditState');
            $entity->state_key = $key;
        }

        $entity->state_value = $value;
        $entity->updated_date = time();
        $entity->save();
    }
}
