<?php
// FILE: TiCore/App.php
// TiCore PHP Framework - Application Bootstrap
// Part of iNetPanel | https://github.com/tuxxin/iNetPanel

class App
{
    private static ?App $instance = null;
    private Router $router;
    private Config $config;

    private function __construct()
    {
        $this->config = new Config();
        $this->router = new Router();
    }

    public static function getInstance(): App
    {
        if (self::$instance === null) {
            self::$instance = new App();
        }
        return self::$instance;
    }

    public function getRouter(): Router
    {
        return $this->router;
    }

    public function getConfig(): Config
    {
        return $this->config;
    }

    public function run(): void
    {
        $request = $_SERVER['REQUEST_URI'] ?? '/';
        $request = strtok($request, '?');
        $this->router->dispatch($request);
    }
}
