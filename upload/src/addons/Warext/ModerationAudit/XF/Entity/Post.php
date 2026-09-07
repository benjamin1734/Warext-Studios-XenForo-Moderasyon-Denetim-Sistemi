<?php

namespace Warext\ModerationAudit\XF\Entity;

use Warext\ModerationAudit\Service\Audit\SnapshotBuilder;

class Post extends XFCP_Post
{
    protected function _preSave()
    {
        if (!$this->isInsert() && ($this->isChanged('message') || $this->isChanged('message_state')))
        {
            $this->captureAuditEvidence('before_save');
        }

        parent::_preSave();
    }

    protected function _postSave()
    {
        parent::_postSave();

        if (!$this->isInsert() && ($this->isChanged('message') || $this->isChanged('message_state')))
        {
            $this->captureAuditEvidence('after_save');
        }
    }

    protected function _preDelete()
    {
        $this->captureAuditEvidence('before_delete');
        parent::_preDelete();
    }

    protected function captureAuditEvidence(string $stage): void
    {
        try
        {
            $builder = new SnapshotBuilder(\XF::app());
            $builder->capturePostEntity($this, $stage);
        }
        catch (\Throwable $e)
        {
            \XF::logException($e, false, 'Warext ModerationAudit post evidence capture: ');
        }
    }
}
