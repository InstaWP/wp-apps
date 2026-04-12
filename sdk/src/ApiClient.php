<?php

declare(strict_types=1);

namespace WPApps\SDK;

use WPApps\SDK\Auth\TokenStore;

class ApiClient
{
    private string $accessToken = '';
    private string $refreshToken = '';

    public function __construct(
        private readonly string $siteUrl,
        private readonly string $appId,
        private readonly TokenStore $tokenStore
    ) {
        $tokens = $this->tokenStore->load($this->siteUrl);
        $this->accessToken = $tokens['access_token'] ?? '';
        $this->refreshToken = $tokens['refresh_token'] ?? '';
    }

    public function setTokens(string $accessToken, string $refreshToken): void
    {
        $this->accessToken = $accessToken;
        $this->refreshToken = $refreshToken;
        $this->tokenStore->save($this->siteUrl, [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
        ]);
    }

    /**
     * @return array|null Decoded JSON response, or null on failure.
     */
    public function get(string $path, array $query = []): ?array
    {
        return $this->request('GET', $path, query: $query);
    }

    public function post(string $path, array $data = []): ?array
    {
        return $this->request('POST', $path, body: $data);
    }

    public function put(string $path, array $data = []): ?array
    {
        return $this->request('PUT', $path, body: $data);
    }

    public function delete(string $path): ?array
    {
        return $this->request('DELETE', $path);
    }

    private function request(
        string $method,
        string $path,
        array $body = [],
        array $query = [],
        bool $retry = true
    ): ?array {
        $url = rtrim($this->siteUrl, '/') . '/wp-json' . $path;

        if ($query) {
            $url .= '?' . http_build_query($query);
        }

        $headers = [
            'Authorization: Bearer ' . $this->accessToken,
            'X-App-Id: ' . $this->appId,
            'X-Request-Id: ' . $this->uuid(),
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        if ($body && in_array($method, ['POST', 'PUT', 'PATCH'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        // Allow self-signed certs for localhost dev
        $host = parse_url($this->siteUrl, PHP_URL_HOST);
        if (in_array($host, ['localhost', '127.0.0.1'])) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            return null;
        }

        // Auto-refresh on 401
        if ($httpCode === 401 && $retry && $this->refreshToken) {
            if ($this->doRefresh()) {
                return $this->request($method, $path, $body, $query, retry: false);
            }
        }

        return json_decode($response, true);
    }

    private function doRefresh(): bool
    {
        $url = rtrim($this->siteUrl, '/') . '/wp-json/apps/v1/token/refresh';

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'app_id' => $this->appId,
            'refresh_token' => $this->refreshToken,
        ]));
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        $host = parse_url($this->siteUrl, PHP_URL_HOST);
        if (in_array($host, ['localhost', '127.0.0.1'])) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            return false;
        }

        $data = json_decode($response, true);
        if (!isset($data['access_token'], $data['refresh_token'])) {
            return false;
        }

        $this->setTokens($data['access_token'], $data['refresh_token']);
        return true;
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
