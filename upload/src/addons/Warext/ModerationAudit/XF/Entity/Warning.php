<?php

namespace Warext\ModerationAudit\XF\Entity;

class Warning extends XFCP_Warning
{
    protected function _postSave()
    {
        $operation = $this->isInsert() ? 'insert' : 'update';
        $result = parent::_postSave();

        try
        {
            (new \Warext\ModerationAudit\Service\Audit\Capture($this->app()))->recordWarning($this, $operation);
        }
        catch (\Throwable $e)
        {
            \XF::logException($e, false, 'ModerationAudit warning capture failed: ');
        }

        return $result;
    }

    protected function _postDelete()
    {
        $result = parent::_postDelete();

        try
        {
            (new \Warext\ModerationAudit\Service\Audit\Capture($this->app()))->recordWarning($this, 'delete');
        }
        catch (\Throwable $e)
        {
            \XF::logException($e, false, 'ModerationAudit warning-delete capture failed: ');
        }

        return $result;
    }
}
