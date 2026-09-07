<?php

namespace Warext\ModerationAudit\Service\Audit;

use XF\App;
use XF\Service\AbstractService;

class Redactor extends AbstractService
{
    protected array $sensitiveKeys = [
        'password', 'passwd', 'pass', 'secret', 'token', 'access_token', 'refresh_token',
        'api_key', 'apikey', 'authorization', 'auth', 'cookie', 'session', 'session_id',
        'ip', 'ip_address', 'email', 'phone', 'telephone'
    ];

    public function __construct(App $app)
    {
        parent::__construct($app);
    }

    public function redactArray(array $data): array
    {
        $output = [];
        foreach ($data as $key => $value)
        {
            $keyString = strtolower((string)$key);
            if ($this->isSensitiveKey($keyString))
            {
                $output[$key] = '[MASKELENDI]';
                continue;
            }

            if (is_array($value))
            {
                $output[$key] = $this->redactArray($value);
            }
            elseif (is_string($value))
            {
                $output[$key] = $this->redactText($value);
            }
            else
            {
                $output[$key] = $value;
            }
        }

        return $output;
    }

    public function redactText(string $text): string
    {
        if ($text === '')
        {
            return $text;
        }

        $text = preg_replace('/\b[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}\b/i', '[E-POSTA MASKELENDI]', $text) ?? $text;
        $text = preg_replace('/\b(?:(?:25[0-5]|2[0-4]\d|1?\d?\d)\.){3}(?:25[0-5]|2[0-4]\d|1?\d?\d)\b/', '[IP MASKELENDI]', $text) ?? $text;
        $text = preg_replace('/([?&](?:token|access_token|auth|key|api_key|apikey|signature|sig)=)[^&\s]+/i', '$1[MASKELENDI]', $text) ?? $text;

        return $text;
    }

    protected function isSensitiveKey(string $key): bool
    {
        foreach ($this->sensitiveKeys as $needle)
        {
            if ($key === $needle || str_ends_with($key, '_' . $needle) || str_contains($key, $needle . '_'))
            {
                return true;
            }
        }

        return false;
    }
}
