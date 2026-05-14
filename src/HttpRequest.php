<?php

declare(strict_types=1);

class HttpRequest
{
    private array $getParams;
    private array $postParams;
    private array $serverParams;
    private array $jsonBody;
    private array $uploadedFiles;

    public function __construct()
    {
        $this->getParams = $_GET;
        $this->postParams = $_POST;
        $this->serverParams = $_SERVER;
        
        $raw = $GLOBALS['HTTP_ROUTER_TEST_INPUT'] ?? file_get_contents('php://input');
        $data = json_decode($raw ?: '', true);
        $this->jsonBody = is_array($data) ? $data : [];
        $this->uploadedFiles = $_FILES;
    }

    public function getFile(string $key): ?array
    {
        return $this->uploadedFiles[$key] ?? null;
    }

    public function getAction(): string
    {
        return $this->getParams['action'] ?? 'view';
    }

    public function getQueryParam(string $key, $default = null)
    {
        return $this->getParams[$key] ?? $default;
    }

    public function getJsonBody(): array
    {
        return $this->jsonBody;
    }

    public function getHeader(string $key, string $default = ''): string
    {
        $serverKey = 'HTTP_' . str_replace('-', '_', strtoupper($key));
        return $this->serverParams[$serverKey] ?? $default;
    }

    public function getClientIp(): string
    {
        $ip = $this->serverParams['REMOTE_ADDR'] ?? '0.0.0.0';
        $forwarded = $this->serverParams['HTTP_X_FORWARDED_FOR'] ?? '';
        
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
            && $forwarded !== '') {
            $parts = explode(',', $forwarded);
            return trim($parts[0]);
        }
        return $ip;
    }
}
