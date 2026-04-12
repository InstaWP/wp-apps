<?php

declare(strict_types=1);

namespace WPApps\SDK;

use WPApps\SDK\Auth\HmacValidator;
use WPApps\SDK\Auth\TokenStore;

class App
{
    private array $manifest;
    private string $appId;
    private string $sharedSecret = '';

    /** @var array<string, callable> Tier 1: async event handlers (preferred) */
    private array $eventHandlers = [];

    /** @var array<string, callable> Tier 2: render-path filter handlers (escape hatch) */
    private array $filterHandlers = [];

    /** @var array<string, callable> Legacy action handlers */
    private array $actionHandlers = [];

    /** @var array<string, callable> Block render handlers */
    private array $blockHandlers = [];

    /** @var array<string, array<string, callable>> */
    private array $surfaceHandlers = [];

    /** @var callable|null */
    private mixed $healthHandler = null;
    /** @var callable|null */
    private mixed $installHandler = null;
    /** @var callable|null */
    private mixed $activateHandler = null;
    /** @var callable|null */
    private mixed $deactivateHandler = null;
    /** @var callable|null */
    private mixed $uninstallHandler = null;

    private TokenStore $tokenStore;
    private ?HmacValidator $hmacValidator = null;

    public function __construct(string $manifestPath)
    {
        $json = file_get_contents($manifestPath);
        if ($json === false) {
            throw new \RuntimeException("Cannot read manifest: {$manifestPath}");
        }

        $this->manifest = json_decode($json, true);
        if (!is_array($this->manifest)) {
            throw new \RuntimeException("Invalid manifest JSON: {$manifestPath}");
        }

        $this->appId = $this->manifest['app']['id'];
        $this->tokenStore = new TokenStore(dirname($manifestPath) . '/.wp-apps-tokens');
    }

    /**
     * Set the shared secret for HMAC validation (received during installation).
     */
    public function setSharedSecret(string $secret): self
    {
        $this->sharedSecret = $secret;
        $this->hmacValidator = new HmacValidator($secret);
        return $this;
    }

    /**
     * Register an event handler (Tier 1 — preferred).
     * Events are async webhooks fired when WordPress data changes.
     */
    public function onEvent(string $event, callable $handler): self
    {
        $this->eventHandlers[$event] = $handler;
        return $this;
    }

    /**
     * Register a block render handler.
     * Blocks are the preferred way to render frontend UI.
     */
    public function onBlock(string $blockName, callable $handler): self
    {
        $this->blockHandlers[$blockName] = $handler;
        return $this;
    }

    /**
     * Register a filter hook handler (Tier 2 — escape hatch, adds page-load latency).
     */
    public function onFilter(string $hook, callable $handler): self
    {
        $this->filterHandlers[$hook] = $handler;
        return $this;
    }

    /**
     * Register an action hook handler.
     */
    public function onAction(string $hook, callable $handler): self
    {
        $this->actionHandlers[$hook] = $handler;
        return $this;
    }

    /**
     * Register a surface render/interaction handler.
     */
    public function onSurface(string $surfaceId, string $action, callable $handler): self
    {
        $this->surfaceHandlers[$surfaceId][$action] = $handler;
        return $this;
    }

    /**
     * Register the health check handler.
     */
    public function onHealth(callable $handler): self
    {
        $this->healthHandler = $handler;
        return $this;
    }

    /**
     * Register lifecycle handlers.
     */
    public function onInstall(callable $handler): self
    {
        $this->installHandler = $handler;
        return $this;
    }

    public function onActivate(callable $handler): self
    {
        $this->activateHandler = $handler;
        return $this;
    }

    public function onDeactivate(callable $handler): self
    {
        $this->deactivateHandler = $handler;
        return $this;
    }

    public function onUninstall(callable $handler): self
    {
        $this->uninstallHandler = $handler;
        return $this;
    }

    /**
     * Start the app server and route requests.
     */
    public function run(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

        // Remove base path if the app is behind a prefix
        $webhookPath = $this->manifest['runtime']['webhook_path'] ?? '/hooks';
        $healthPath = $this->manifest['runtime']['health_check'] ?? '/health';
        $authCallback = $this->manifest['runtime']['auth_callback'] ?? '/auth/callback';

        try {
            if ($path === $healthPath && $method === 'GET') {
                $this->handleHealth();
            } elseif ($path === $authCallback && $method === 'POST') {
                $this->handleAuthCallback();
            } elseif ($path === $webhookPath && $method === 'POST') {
                $this->handleWebhook();
            } elseif (str_starts_with($path, '/lifecycle/') && $method === 'POST') {
                $this->handleLifecycle($path);
            } elseif (str_starts_with($path, '/surfaces/') && $method === 'POST') {
                $this->handleSurface();
            } else {
                $this->sendJson(['error' => 'Not found'], 404);
            }
        } catch (\Throwable $e) {
            $this->sendJson([
                'status' => 'error',
                'error' => [
                    'code' => 'internal_error',
                    'message' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    private function handleHealth(): void
    {
        if ($this->healthHandler) {
            $response = ($this->healthHandler)();
            $this->sendResponse($response);
        } else {
            $this->sendJson([
                'status' => 'healthy',
                'version' => $this->manifest['app']['version'],
                'uptime_seconds' => (int) (microtime(true) - ($_SERVER['REQUEST_TIME_FLOAT'] ?? time())),
            ]);
        }
    }

    private function handleAuthCallback(): void
    {
        $body = file_get_contents('php://input');
        $data = json_decode($body, true);

        if (!$data || !isset($data['site_url'], $data['auth_code'])) {
            $this->sendJson(['error' => 'Invalid auth callback'], 400);
            return;
        }

        // Store the shared secret if provided
        // Exchange auth code for tokens
        $siteUrl = $data['site_url'];
        $authCode = $data['auth_code'];

        $tokenStore = $this->tokenStore;
        $apiClient = new ApiClient($siteUrl, $this->appId, $tokenStore);

        // Exchange code for tokens
        $ch = curl_init(rtrim($siteUrl, '/') . '/wp-json/apps/v1/token');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'app_id' => $this->appId,
            'code' => $authCode,
        ]));
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        $host = parse_url($siteUrl, PHP_URL_HOST);
        if (in_array($host, ['localhost', '127.0.0.1'])) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && $response) {
            $tokens = json_decode($response, true);
            if (isset($tokens['access_token'], $tokens['refresh_token'])) {
                $apiClient->setTokens($tokens['access_token'], $tokens['refresh_token']);
                $this->sendJson(['status' => 'ok', 'message' => 'Tokens received']);
                return;
            }
        }

        $this->sendJson(['status' => 'error', 'message' => 'Token exchange failed'], 500);
    }

    private function handleWebhook(): void
    {
        $body = file_get_contents('php://input');

        // Validate HMAC signature if configured
        if ($this->hmacValidator) {
            $signature = $_SERVER['HTTP_X_WP_APPS_SIGNATURE'] ?? '';
            $timestamp = (int) ($_SERVER['HTTP_X_WP_APPS_TIMESTAMP'] ?? 0);

            if (!$this->hmacValidator->validate($body, $signature, $timestamp)) {
                $this->sendJson(['error' => 'Invalid signature'], 401);
                return;
            }
        }

        $data = json_decode($body, true);
        if (!$data) {
            $this->sendJson(['error' => 'Invalid webhook payload'], 400);
            return;
        }

        $type = $data['type'] ?? '';
        $siteUrl = $data['site']['url'] ?? '';
        $deliveryId = $data['delivery_id'] ?? '';

        $apiClient = new ApiClient($siteUrl, $this->appId, $this->tokenStore);
        $request = new Request($data, $apiClient);

        // Tier 1: Event webhook (async)
        if ($type === 'event') {
            $event = $data['event'] ?? $data['hook'] ?? '';
            if (isset($this->eventHandlers[$event])) {
                $response = ($this->eventHandlers[$event])($request);
                $this->sendJson(array_merge($response->body, ['delivery_id' => $deliveryId]), $response->statusCode);
            } else {
                $this->sendJson(['delivery_id' => $deliveryId, 'status' => 'ok']);
            }
            return;
        }

        // Tier 2: Filter (render-path)
        $hook = $data['hook'] ?? '';

        if ($type === 'filter' && isset($this->filterHandlers[$hook])) {
            $response = ($this->filterHandlers[$hook])($request);
            $this->sendJson(array_merge($response->body, ['delivery_id' => $deliveryId]), $response->statusCode);
        } elseif ($type === 'action') {
            // Legacy action support + event fallback
            $handler = $this->actionHandlers[$hook] ?? $this->eventHandlers[$hook] ?? null;
            if ($handler) {
                $response = $handler($request);
                $this->sendJson(array_merge($response->body, ['delivery_id' => $deliveryId]), $response->statusCode);
            } else {
                $this->sendJson(['delivery_id' => $deliveryId, 'status' => 'ok']);
            }
        } else {
            $this->sendJson([
                'delivery_id' => $deliveryId,
                'status' => 'ok',
            ]);
        }
    }

    private function handleSurface(): void
    {
        $body = file_get_contents('php://input');
        $data = json_decode($body, true);

        if (!$data) {
            $this->sendJson(['error' => 'Invalid surface request'], 400);
            return;
        }

        $surface = $data['surface'] ?? '';
        $siteUrl = $data['context']['site_url'] ?? $data['site']['url'] ?? '';
        $apiClient = new ApiClient($siteUrl, $this->appId, $this->tokenStore);
        $request = new Request($data, $apiClient);

        // Block render
        if ($surface === 'block') {
            $blockName = $data['block_name'] ?? '';
            $handler = $this->blockHandlers[$blockName] ?? null;
            if ($handler) {
                $response = $handler($request);
                $this->sendResponse($response);
            } else {
                $this->sendJson(['error' => 'No handler for block: ' . $blockName], 404);
            }
            return;
        }

        // Named surface (admin page, meta box, etc.)
        $surfaceId = $data['surface_id'] ?? '';
        $action = $data['action'] ?? 'render';

        $handler = $this->surfaceHandlers[$surfaceId][$action] ?? null;
        if (!$handler) {
            $this->sendJson(['error' => 'No handler for this surface'], 404);
            return;
        }

        $response = $handler($request);
        $this->sendResponse($response);
    }

    private function handleLifecycle(string $path): void
    {
        $event = basename($path);
        $handler = match ($event) {
            'install' => $this->installHandler,
            'activate' => $this->activateHandler,
            'deactivate' => $this->deactivateHandler,
            'uninstall' => $this->uninstallHandler,
            default => null,
        };

        if ($handler) {
            $handler();
        }

        $this->sendJson(['status' => 'ok']);
    }

    private function sendResponse(Response $response): void
    {
        $this->sendJson($response->body, $response->statusCode);
    }

    private function sendJson(array $data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
}
