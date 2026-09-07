<?php

namespace Warext\ModerationAudit\Service\Audit;

class EvidenceBuffer
{
    protected static array $items = [];

    public static function put(string $contentType, int $contentId, string $stage, array $data): void
    {
        if ($contentId <= 0)
        {
            return;
        }

        $key = $contentType . ':' . $contentId;
        self::$items[$key][$stage] = [
            'captured_at' => time(),
            'data' => $data
        ];
    }

    public static function get(string $contentType, int $contentId): array
    {
        if ($contentId <= 0)
        {
            return [];
        }

        return self::$items[$contentType . ':' . $contentId] ?? [];
    }

    public static function clear(string $contentType, int $contentId): void
    {
        unset(self::$items[$contentType . ':' . $contentId]);
    }
}
