<?php
// FILE: TiCore/View.php
// TiCore PHP Framework - View Renderer
// Part of iNetPanel | https://github.com/tuxxin/iNetPanel

class View
{
    private string $themePath;

    public function __construct(string $themePath = '')
    {
        $this->themePath = $themePath ?: (defined('THEME_PATH') ? THEME_PATH : '');
    }

    /**
     * Render an admin panel page wrapped in header/footer layout
     */
    public function renderAdmin(string $pageTitle, string $contentFile): void
    {
        if (!file_exists($contentFile)) {
            http_response_code(404);
            $this->error("Content file not found: " . htmlspecialchars($contentFile));
            return;
        }

        $header = $this->themePath . '/header.php';
        $footer = $this->themePath . '/footer.php';

        if (!file_exists($header) || !file_exists($footer)) {
            $this->error("Theme files missing in: " . htmlspecialchars($this->themePath));
            return;
        }

        $GLOBALS['_page_title'] = $pageTitle;
        require $header;
        require $contentFile;
        require $footer;
    }

    /**
     * Render a standalone theme file (e.g. login, 404)
     * $data is extracted into local scope so templates can use $error, etc.
     */
    public function render(string $template, array $data = []): void
    {
        $path = $this->themePath . '/' . $template;
        if (file_exists($path)) {
            extract($data, EXTR_SKIP);
            require $path;
        } else {
            $this->error("Template not found: " . htmlspecialchars($path));
        }
    }

    /**
     * Render raw file path directly
     */
    public function renderFile(string $filePath): void
    {
        if (file_exists($filePath)) {
            require $filePath;
        } else {
            $this->error("File not found: " . htmlspecialchars($filePath));
        }
    }

    private function error(string $message): void
    {
        echo "<h1>View Error</h1><p>$message</p>";
    }
}
