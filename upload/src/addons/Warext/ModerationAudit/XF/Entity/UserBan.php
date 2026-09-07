<?php

namespace Warext\ModerationAudit\XF\Entity;

class UserBan extends XFCP_UserBan
{
    protected function _postSave()
    {
        $operation = $this->isInsert() ? 'insert' : 'update';
        $result = parent::_postSave();

        try
        {
            (new \Warext\ModerationAudit\Service\Audit\Capture($this->app()))->recordUserBan($this, $operation);
        }
        catch (\Throwable $e)
        {
            \XF::logException($e, false, 'ModerationAudit user-ban capture failed: ');
        }

        return $result;
    }

    protected function _postDelete()
    {
        $result = parent::_postDelete();

        try
        {
            (new \Warext\ModerationAudit\Service\Audit\Capture($this->app()))->recordUserBan($this, 'delete');
        }
        catch (\Throwable $e)
        {
            \XF::logException($e, false, 'ModerationAudit user-ban-delete capture failed: ');
        }

        return $result;
    }
}
