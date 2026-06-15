<?php
namespace App\Core;

class Router
{
    private array $routes = ['GET' => [], 'POST' => []];

    public function get(string $path, $handler): void
    {
        $this->routes['GET'][$path] = $handler;
    }

    public function post(string $path, $handler): void
    {
        $this->routes['POST'][$path] = $handler;
    }

    public function dispatch(string $method, string $uri): void
    {
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $path = rtrim($path, '/');
        if ($path === '') {
            $path = '/';
        }

        // Точное совпадение.
        if (isset($this->routes[$method][$path])) {
            $this->call($this->routes[$method][$path], []);
            return;
        }

        // Совпадение с параметрами вида /dossiers/{id}.
        foreach ($this->routes[$method] as $route => $handler) {
            if (strpos($route, '{') === false) {
                continue;
            }
            $pattern = preg_replace('#\{[^/]+\}#', '([^/]+)', $route);
            $pattern = '#^' . $pattern . '$#';
            if (preg_match($pattern, $path, $m)) {
                array_shift($m);
                $this->call($handler, $m);
                return;
            }
        }

        http_response_code(404);
        echo View::render('errors/404', ['title' => 'Страница не найдена'], false);
    }

    private function call($handler, array $params): void
    {
        [$class, $action] = $handler;
        $controller = new $class();
        call_user_func_array([$controller, $action], $params);
    }
}
