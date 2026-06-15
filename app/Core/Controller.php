<?php
namespace App\Core;

abstract class Controller
{
    protected function view(string $template, array $data = [], bool $layout = true): void
    {
        View::show($template, $data, $layout);
    }

    protected function redirect(string $to): void
    {
        header('Location: ' . $to);
        exit;
    }

    protected function back(): void
    {
        $ref = $_SERVER['HTTP_REFERER'] ?? '/';
        $this->redirect($ref);
    }

    protected function input(string $key, $default = null)
    {
        $val = $_POST[$key] ?? $_GET[$key] ?? $default;
        return is_string($val) ? trim($val) : $val;
    }

    protected function json($data, int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    /** Сохранить ввод для повторного показа формы при ошибке. */
    protected function flashOld(): void
    {
        $_SESSION['_old'] = $_POST;
    }

    protected function clearOld(): void
    {
        unset($_SESSION['_old']);
    }
}
