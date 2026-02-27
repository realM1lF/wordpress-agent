<?php

namespace Levi\Agent\AI\Tools;

class DiscoverRestApiTool implements ToolInterface {

    public function getName(): string {
        return 'discover_rest_api';
    }

    public function getDescription(): string {
        return 'Discover registered WordPress REST API routes and endpoints. Useful to find available APIs from other plugins (WooCommerce, Yoast, etc.) and understand what data is accessible via REST.';
    }

    public function getParameters(): array {
        return [
            'namespace' => [
                'type' => 'string',
                'description' => 'Filter by namespace (e.g. "wc/v3", "wp/v2", "yoast"). Leave empty to list all namespaces.',
            ],
            'search' => [
                'type' => 'string',
                'description' => 'Search routes by keyword (e.g. "product", "order", "user")',
            ],
        ];
    }

    public function checkPermission(): bool {
        return current_user_can('manage_options');
    }

    public function execute(array $params): array {
        $namespace = trim((string) ($params['namespace'] ?? ''));
        $search = mb_strtolower(trim((string) ($params['search'] ?? '')));

        $server = rest_get_server();
        $routes = $server->get_routes();

        if ($namespace === '' && $search === '') {
            return $this->listNamespaces($server);
        }

        $result = [];

        foreach ($routes as $route => $handlers) {
            if ($namespace !== '' && !str_starts_with(ltrim($route, '/'), $namespace)) {
                continue;
            }

            if ($search !== '' && !str_contains(mb_strtolower($route), $search)) {
                continue;
            }

            $methods = [];
            foreach ($handlers as $handler) {
                $handlerMethods = (array) ($handler['methods'] ?? []);
                $methods = array_merge($methods, array_keys(array_filter($handlerMethods)));
            }

            $result[] = [
                'route' => $route,
                'methods' => array_values(array_unique($methods)),
            ];
        }

        if (count($result) > 200) {
            $result = array_slice($result, 0, 200);
            $truncated = true;
        } else {
            $truncated = false;
        }

        return [
            'success' => true,
            'total' => count($result),
            'truncated' => $truncated,
            'namespace' => $namespace ?: null,
            'search' => $search ?: null,
            'routes' => $result,
        ];
    }

    private function listNamespaces($server): array {
        $namespaces = $server->get_namespaces();

        $routesByNs = [];
        foreach ($server->get_routes() as $route => $_) {
            foreach ($namespaces as $ns) {
                if (str_starts_with(ltrim($route, '/'), $ns)) {
                    $routesByNs[$ns] = ($routesByNs[$ns] ?? 0) + 1;
                    break;
                }
            }
        }

        $result = [];
        foreach ($namespaces as $ns) {
            $result[] = [
                'namespace' => $ns,
                'route_count' => $routesByNs[$ns] ?? 0,
            ];
        }

        usort($result, fn($a, $b) => strcmp($a['namespace'], $b['namespace']));

        return [
            'success' => true,
            'total' => count($result),
            'namespaces' => $result,
            'hint' => 'Use namespace parameter to list routes for a specific namespace.',
        ];
    }
}
