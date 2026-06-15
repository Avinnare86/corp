<?php
namespace App\Core;

class View
{
    private static array $shared = [];

    public static function share(string $key, $value): void
    {
        self::$shared[$key] = $value;
    }

    /**
     * Рендер шаблона. По умолчанию оборачивает в layout.
     */
    public static function render(string $template, array $data = [], bool $layout = true): string
    {
        $data = array_merge(self::$shared, $data);
        $file = __DIR__ . '/../Views/' . $template . '.php';
        if (!is_file($file)) {
            return "Шаблон не найден: {$template}";
        }

        extract($data, EXTR_SKIP);
        ob_start();
        include $file;
        $content = ob_get_clean();

        if ($layout) {
            $layoutFile = __DIR__ . '/../Views/layout.php';
            ob_start();
            include $layoutFile;
            return ob_get_clean();
        }
        return $content;
    }

    public static function show(string $template, array $data = [], bool $layout = true): void
    {
        echo self::render($template, $data, $layout);
    }
}
