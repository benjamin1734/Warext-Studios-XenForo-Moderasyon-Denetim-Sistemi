<?php

namespace Warext\ModerationAudit\Service\Audit;

use XF\App;
use XF\Mvc\Entity\Entity;
use XF\Service\AbstractService;

class SnapshotBuilder extends AbstractService
{
    protected Redactor $redactor;

    public function __construct(App $app)
    {
        parent::__construct($app);
        $this->redactor = new Redactor($app);
    }

    public function forModeratorLog(Entity $log, array $baseSnapshot): array
    {
        $contentType = (string)$this->value($log, 'content_type', '');
        $contentId = (int)$this->value($log, 'content_id', 0);
        $buffer = EvidenceBuffer::get($contentType, $contentId);

        $sensitive = [
            'base' => $baseSnapshot,
            'buffer' => $buffer,
            'content' => $this->fetchContent($contentType, $contentId),
            'context' => $this->fetchContext($contentType, $contentId, $buffer)
        ];

        return $this->pair($sensitive);
    }

    public function forWarning(Entity $warning, string $operation, array $baseSnapshot): array
    {
        $contentType = (string)$this->value($warning, 'content_type', '');
        $contentId = (int)$this->value($warning, 'content_id', 0);
        $sensitive = [
            'base' => $baseSnapshot,
            'content' => $this->fetchContent($contentType, $contentId),
            'context' => $this->fetchContext($contentType, $contentId, EvidenceBuffer::get($contentType, $contentId)),
            'existing' => $this->changedValues($warning, ['warning_definition_id', 'title', 'points', 'expiry_date', 'is_expired'])
        ];

        return $this->pair($sensitive);
    }

    public function forUserBan(Entity $ban, string $operation, array $baseSnapshot): array
    {
        $userId = (int)$this->value($ban, 'user_id', 0);
        $user = $userId ? \XF::em()->find('XF:User', $userId) : null;
        $sensitive = [
            'base' => $baseSnapshot,
            'user' => $user ? [
                'user_id' => (int)$user->user_id,
                'username' => (string)$user->username,
                'email' => (string)$user->email,
                'user_state' => (string)$user->user_state,
                'is_banned' => (bool)$user->is_banned,
                'register_date' => (int)$user->register_date,
                'last_activity' => (int)$user->last_activity
            ] : null,
            'existing' => $this->changedValues($ban, ['end_date', 'user_reason', 'triggered'])
        ];

        return $this->pair($sensitive);
    }

    public function forReport(Entity $report, array $baseSnapshot): array
    {
        $contentType = (string)$this->value($report, 'content_type', '');
        $contentId = (int)$this->value($report, 'content_id', 0);
        $sensitive = [
            'base' => $baseSnapshot,
            'content' => $this->fetchContent($contentType, $contentId),
            'context' => $this->fetchContext($contentType, $contentId, EvidenceBuffer::get($contentType, $contentId)),
            'report' => [
                'report_id' => (int)$this->value($report, 'report_id', 0),
                'report_state' => (string)$this->value($report, 'report_state', ''),
                'assigned_user_id' => (int)$this->value($report, 'assigned_user_id', 0),
                'report_count' => (int)$this->value($report, 'report_count', 0),
                'comment_count' => (int)$this->value($report, 'comment_count', 0),
                'first_report_date' => (int)$this->value($report, 'first_report_date', 0),
                'last_modified_date' => (int)$this->value($report, 'last_modified_date', 0)
            ],
            'report_comments' => $this->fetchReportComments($reportId)
        ];

        return $this->pair($sensitive);
    }

    public function capturePostEntity(Entity $post, string $stage): void
    {
        $postId = (int)$this->value($post, 'post_id', 0);
        if (!$postId)
        {
            return;
        }

        $data = [
            'post' => $stage === 'before_save' ? $this->postArrayBeforeSave($post) : $this->postArray($post)
        ];

        if ($stage === 'before_delete')
        {
            $threadId = (int)$this->value($post, 'thread_id', 0);
            $position = (int)$this->value($post, 'position', 0);
            $data['thread'] = $this->fetchThread($threadId);
            $data['surrounding_posts'] = $this->fetchPostContext($threadId, $position, $postId);
        }

        EvidenceBuffer::put('post', $postId, $stage, $data);
    }

    public function captureThreadEntity(Entity $thread, string $stage): void
    {
        $threadId = (int)$this->value($thread, 'thread_id', 0);
        if (!$threadId)
        {
            return;
        }

        $data = [
            'thread' => $stage === 'before_save' ? $this->threadArrayBeforeSave($thread) : $this->threadArray($thread)
        ];

        if ($stage === 'before_delete')
        {
            $data['edge_posts'] = $this->fetchThreadEdgePosts($threadId);
        }

        EvidenceBuffer::put('thread', $threadId, $stage, $data);
    }

    protected function pair(array $sensitive): array
    {
        $redacted = $this->redactor->redactArray($sensitive);
        $snapshots = [
            'primary' => [
                'data' => $redacted,
                'is_sensitive' => false,
                'redaction_level' => 'standard'
            ]
        ];

        if ($redacted !== $sensitive)
        {
            $snapshots['sensitive'] = [
                'data' => $sensitive,
                'is_sensitive' => true,
                'redaction_level' => 'none'
            ];
        }

        return $snapshots;
    }

    protected function fetchReportComments(int $reportId): array
    {
        if ($reportId <= 0)
        {
            return [];
        }

        try
        {
            $finder = \XF::finder('XF:ReportComment')
                ->where('report_id', $reportId)
                ->order('comment_date', 'ASC')
                ->limit(100);

            $rows = [];
            foreach ($finder->fetch() as $comment)
            {
                $rows[] = $this->genericEntity($comment, [
                    'report_comment_id', 'comment_date', 'user_id', 'username',
                    'message', 'state_change', 'is_report'
                ]);
            }
            return $rows;
        }
        catch (\Throwable $e)
        {
            return [];
        }
    }

    protected function fetchContent(string $contentType, int $contentId): ?array
    {
        if ($contentId <= 0)
        {
            return null;
        }

        try
        {
            return match ($contentType)
            {
                'post' => ($entity = \XF::em()->find('XF:Post', $contentId)) ? $this->postArray($entity) : null,
                'thread' => ($entity = \XF::em()->find('XF:Thread', $contentId)) ? $this->threadArray($entity) : null,
                'profile_post' => ($entity = \XF::em()->find('XF:ProfilePost', $contentId)) ? $this->genericEntity($entity, ['profile_post_id', 'profile_user_id', 'user_id', 'username', 'post_date', 'message', 'message_state']) : null,
                'profile_post_comment' => ($entity = \XF::em()->find('XF:ProfilePostComment', $contentId)) ? $this->genericEntity($entity, ['profile_post_comment_id', 'profile_post_id', 'user_id', 'username', 'comment_date', 'message', 'message_state']) : null,
                default => null
            };
        }
        catch (\Throwable $e)
        {
            return null;
        }
    }

    protected function fetchContext(string $contentType, int $contentId, array $buffer): array
    {
        if ($contentType === 'post' && $contentId > 0)
        {
            $threadId = 0;
            $position = 0;
            foreach (['before_delete', 'before_save', 'after_save'] as $stage)
            {
                if (!empty($buffer[$stage]['data']['post']))
                {
                    $threadId = (int)($buffer[$stage]['data']['post']['thread_id'] ?? 0);
                    $position = (int)($buffer[$stage]['data']['post']['position'] ?? 0);
                    if ($threadId)
                    {
                        break;
                    }
                }
            }

            if (!$threadId)
            {
                try
                {
                    $post = \XF::em()->find('XF:Post', $contentId);
                    if ($post)
                    {
                        $threadId = (int)$post->thread_id;
                        $position = (int)$post->position;
                    }
                }
                catch (\Throwable $e)
                {
                }
            }

            return [
                'buffer' => $buffer,
                'thread' => $this->fetchThread($threadId),
                'surrounding_posts' => $this->fetchPostContext($threadId, $position, $contentId)
            ];
        }

        if ($contentType === 'thread' && $contentId > 0)
        {
            return [
                'buffer' => $buffer,
                'thread' => $this->fetchThread($contentId),
                'edge_posts' => $this->fetchThreadEdgePosts($contentId)
            ];
        }

        return $buffer ? ['buffer' => $buffer] : [];
    }

    protected function postArray(Entity $post): array
    {
        return $this->genericEntity($post, [
            'post_id', 'thread_id', 'user_id', 'username', 'post_date', 'message', 'message_state',
            'position', 'last_edit_date', 'last_edit_user_id', 'edit_count', 'warning_id'
        ]);
    }

    protected function threadArray(Entity $thread): array
    {
        return $this->genericEntity($thread, [
            'thread_id', 'node_id', 'user_id', 'username', 'post_date', 'title', 'discussion_state',
            'discussion_open', 'sticky', 'first_post_id', 'last_post_id', 'reply_count', 'view_count'
        ]);
    }

    protected function postArrayBeforeSave(Entity $post): array
    {
        return $this->genericEntityBeforeSave($post, [
            'post_id', 'thread_id', 'user_id', 'username', 'post_date', 'message', 'message_state',
            'position', 'last_edit_date', 'last_edit_user_id', 'edit_count', 'warning_id'
        ]);
    }

    protected function threadArrayBeforeSave(Entity $thread): array
    {
        return $this->genericEntityBeforeSave($thread, [
            'thread_id', 'node_id', 'user_id', 'username', 'post_date', 'title', 'discussion_state',
            'discussion_open', 'sticky', 'first_post_id', 'last_post_id', 'reply_count', 'view_count'
        ]);
    }

    protected function fetchThread(int $threadId): ?array
    {
        if ($threadId <= 0)
        {
            return null;
        }

        try
        {
            $thread = \XF::em()->find('XF:Thread', $threadId);
            return $thread ? $this->threadArray($thread) : null;
        }
        catch (\Throwable $e)
        {
            return null;
        }
    }

    protected function fetchPostContext(int $threadId, int $position, int $focusPostId): array
    {
        if ($threadId <= 0)
        {
            return [];
        }

        try
        {
            $finder = \XF::finder('XF:Post')
                ->where('thread_id', $threadId)
                ->where('position', '>=', max(0, $position - 3))
                ->where('position', '<=', $position + 3)
                ->order('position', 'ASC');

            $rows = [];
            foreach ($finder->fetch() as $post)
            {
                $row = $this->postArray($post);
                $row['is_focus'] = ((int)$post->post_id === $focusPostId);
                $rows[] = $row;
            }

            return $rows;
        }
        catch (\Throwable $e)
        {
            return [];
        }
    }

    protected function fetchThreadEdgePosts(int $threadId): array
    {
        if ($threadId <= 0)
        {
            return [];
        }

        try
        {
            $rows = [];
            $seen = [];
            foreach (['ASC', 'DESC'] as $direction)
            {
                $finder = \XF::finder('XF:Post')
                    ->where('thread_id', $threadId)
                    ->order('position', $direction)
                    ->limit(3);

                foreach ($finder->fetch() as $post)
                {
                    $postId = (int)$post->post_id;
                    if (isset($seen[$postId]))
                    {
                        continue;
                    }
                    $seen[$postId] = true;
                    $rows[] = $this->postArray($post);
                }
            }

            usort($rows, static fn(array $a, array $b) => ((int)($a['position'] ?? 0)) <=> ((int)($b['position'] ?? 0)));
            return $rows;
        }
        catch (\Throwable $e)
        {
            return [];
        }
    }

    protected function changedValues(Entity $entity, array $columns): array
    {
        $changes = [];
        foreach ($columns as $column)
        {
            if (!$this->hasColumn($entity, $column) || !$entity->isChanged($column))
            {
                continue;
            }
            $changes[$column] = [
                'before' => $entity->getExistingValue($column),
                'after' => $entity->get($column)
            ];
        }
        return $changes;
    }

    protected function genericEntity(Entity $entity, array $columns): array
    {
        $data = [];
        foreach ($columns as $column)
        {
            if ($this->hasColumn($entity, $column))
            {
                $data[$column] = $entity->get($column);
            }
        }
        return $data;
    }

    protected function genericEntityBeforeSave(Entity $entity, array $columns): array
    {
        $data = [];
        foreach ($columns as $column)
        {
            if (!$this->hasColumn($entity, $column))
            {
                continue;
            }

            $data[$column] = $entity->isChanged($column)
                ? $entity->getExistingValue($column)
                : $entity->get($column);
        }
        return $data;
    }

    protected function hasColumn(Entity $entity, string $column): bool
    {
        return isset($entity->structure()->columns[$column]);
    }

    protected function value(Entity $entity, string $column, mixed $default = null): mixed
    {
        if (!$this->hasColumn($entity, $column))
        {
            return $default;
        }
        $value = $entity->get($column);
        return $value === null ? $default : $value;
    }
}
