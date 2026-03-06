<?php
// FILE: TiCore/Config.php
// TiCore PHP Framework - Configuration Loader
// Part of iNetPanel | https://github.com/tuxxin/iNetPanel

class Config
{
    private array $data = [];

    public function __construct()
    {
        $this->loadEnv();
        $this->loadAppConfig();
    }

    /**
     * Load .env file from TiCore directory
     */
    private function loadEnv(): void
    {
        $envFile = dirname(__FILE__) . '/.env';
        if (!file_exists($envFile)) {
            return;
        }

        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || str_starts_with($line, '#')) {
                continue;
            }
            if (str_contains($line, '=')) {
                [$key, $value] = explode('=', $line, 2);
                $key   = trim($key);
                $value = trim($value);
                $this->data[$key] = $value;
                // Also expose to environment
                if (!isset($_ENV[$key])) {
                    $_ENV[$key] = $value;
                    putenv("$key=$value");
                }
            }
        }
    }

    /**
     * Load application config from conf/Config.php if it exports $config array
     */
    private function loadAppConfig(): void
    {
        $appConfig = defined('CONF_PATH') ? CONF_PATH . '/Config.php' : '';
        if ($appConfig && file_exists($appConfig)) {
            $config = [];
            require $appConfig;
            if (!empty($config) && is_array($config)) {
                $this->data = array_merge($this->data, $config);
            }
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function all(): array
    {
        return $this->data;
    }
}
