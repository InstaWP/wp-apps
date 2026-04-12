<?php

declare(strict_types=1);

namespace WPApps\SDK\Auth;

class TokenStore
{
    private string $storagePath;

    public function __construct(string $storagePath)
    {
        $this->storagePath = rtrim($storagePath, '/');

        if (!is_dir($this->storagePath)) {
            mkdir($this->storagePath, 0700, true);
        }
    }

    public function save(string $siteUrl, array $tokens): void
    {
        $file = $this->getFilePath($siteUrl);
        file_put_contents($file, json_encode($tokens, JSON_PRETTY_PRINT), LOCK_EX);
        chmod($file, 0600);
    }

    public function load(string $siteUrl): array
    {
        $file = $this->getFilePath($siteUrl);

        if (!file_exists($file)) {
            return [];
        }

        $data = json_decode(file_get_contents($file), true);
        return is_array($data) ? $data : [];
    }

    public function delete(string $siteUrl): void
    {
        $file = $this->getFilePath($siteUrl);

        if (file_exists($file)) {
            unlink($file);
        }
    }

    private function getFilePath(string $siteUrl): string
    {
        $hash = hash('sha256', $siteUrl);
        return "{$this->storagePath}/{$hash}.json";
    }
}
