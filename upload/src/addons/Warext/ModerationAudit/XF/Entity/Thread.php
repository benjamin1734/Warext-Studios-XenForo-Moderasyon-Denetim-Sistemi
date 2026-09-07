<?php

namespace Warext\ModerationAudit\XF\Entity;

use Warext\ModerationAudit\Service\Audit\SnapshotBuilder;

class Thread extends XFCP_Thread
{
    protected function _preSave()
    {
        if (!$this->isInsert() && $this->hasAuditRelevantChanges())
        {
            $this->captureAuditEvidence('before_save');
        }

        parent::_preSave();
    }

    protected function _postSave()
    {
        parent::_postSave();

        if (!$this->isInsert() && $this->hasAuditRelevantChanges())
        {
            $this->captureAuditEvidence('after_save');
        }
    }

    protected function _preDelete()
    {
        $this->captureAuditEvidence('before_delete');
        parent::_preDelete();
    }

    protected function hasAuditRelevantChanges(): bool
    {
        foreach (['title', 'discussion_state', 'discussion_open', 'sticky', 'node_id'] as $column)
        {
            if (isset($this->structure()->columns[$column]) && $this->isChanged($column))
            {
                return true;
            }
        }

        return false;
    }

    protected function captureAuditEvidence(string $stage): void
    {
        try
        {
            $builder = new SnapshotBuilder(\XF::app());
            $builder->captureThreadEntity($this, $stage);
        }
        catch (\Throwable $e)
        {
            \XF::logException($e, false, 'Warext ModerationAudit thread evidence capture: ');
        }
    }
}
