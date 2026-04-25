<?php

/**
 * BrainCore — Router
 * Server path: /public_html/brain/core/Router.php
 *
 * Matches the incoming HTTP request against the route table
 * and calls the correct static controller method.
 *
 * Design decisions:
 *   - No regex. Exact string match on method + URI.
 *   - Controllers are called as static methods: ControllerClass::action()
 *   - Response::error() is used for all failure paths so the caller
 *     always gets a JSON body, never a raw PHP error page.
 *   - dispatch() always terminates (via exit inside Response or return).
 *     Nothing runs after it.
 *
 * Route table format:
 *   [ 'HTTP_METHOD', '/uri/path', 'ControllerClassName', 'methodName' ]
 */

class Router
{
    /**
     * Match the request and dispatch to the correct controller.
     *
     * @param array  $routes  Route definitions from routes.php
     * @param string $method  HTTP verb: GET, POST, PUT, DELETE, etc.
     * @param string $uri     Cleaned URI path: /api/decision
     */
    public static function dispatch(array $routes, string $method, string $uri): void
    {
        // ── OPTIONS preflight (CORS) ───────────────────────────────────────────
        // Return 200 immediately for all OPTIONS requests so browser
        // preflight checks do not trigger a 404 from the route table.
        if ($method === 'OPTIONS') {
            http_response_code(200);
            header('Content-Type: application/json');
            echo json_encode(['status' => 'ok']);
            exit;
        }

        // ── Route matching ────────────────────────────────────────────────────
        foreach ($routes as [$routeMethod, $routeUri, $controller, $action]) {

            if ($method !== $routeMethod || $uri !== $routeUri) {
                continue;
            }

            // ── Guard: controller class must exist ────────────────────────────
            // autoload.php will have tried to load it already.
            // If it is still missing, the file is absent or misnamed.
            if (!class_exists($controller)) {
                self::abort(
                    "Controller class not found: {$controller}. " .
                    "Check that /api/{$controller}.php exists and the class name matches.",
                    500
                );
            }

            // ── Guard: action method must exist ───────────────────────────────
            if (!method_exists($controller, $action)) {
                self::abort(
                    "Action not found: {$controller}::{$action}(). " .
                    "Check the method name in the controller file.",
                    500
                );
            }

            // ── Dispatch ──────────────────────────────────────────────────────
            $controller::$action();
            return;
        }

        // ── No route matched ──────────────────────────────────────────────────
        self::abort(
            "No route matched: [{$method}] {$uri}",
            404
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Send a JSON error response and terminate.
     *
     * Using this instead of Response::error() directly so the Router
     * does not depend on Response being loaded yet at class-definition time.
     * (Response is autoloaded on first use — this call site guarantees that.)
     */
    private static function abort(string $message, int $code): void
    {
        // Response class is in utils/Response.php — autoloaded by now.
        if (class_exists('Response')) {
            Response::error($message, $code);
        }

        // Fallback if Response itself failed to load
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode([
            'status'  => 'error',
            'message' => $message,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}
