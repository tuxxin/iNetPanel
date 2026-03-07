<?php
// FILE: TiCore/Router.php
// TiCore PHP Framework - URL Router
// Part of iNetPanel | https://github.com/tuxxin/iNetPanel

class Router
{
    private array $routes = [];

    public function add(string $path, callable $handler): void
    {
        $this->routes[$path] = $handler;
    }

    public function dispatch(string $request): void
    {
        // Strip .php extension so /api/settings.php matches route /api/settings
        $request = preg_replace('/\.php$/', '', $request);

        if (isset($this->routes[$request])) {
            ($this->routes[$request])();
            return;
        }

        // Check for dynamic routes (pattern matching)
        foreach ($this->routes as $pattern => $handler) {
            if ($pattern[0] === '#') {
                if (preg_match($pattern, $request, $matches)) {
                    $handler($matches);
                    return;
                }
            }
        }

        // No route matched — 404
        http_response_code(404);
        $theme = defined('THEME_PATH') ? THEME_PATH : '';
        if (file_exists($theme . '/404.php')) {
            require $theme . '/404.php';
        } else {
            echo '<h1>404 Not Found</h1>';
        }
    }
}
