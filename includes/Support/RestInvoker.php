<?php
declare(strict_types=1);

namespace SiteMcp\Support;

final class RestInvoker
{
    /**
     * Dispatch an internal REST request and return either the response data array
     * or a WP_Error if the route reported failure.
     *
     * @param array<string, mixed> $body
     * @param array<string, mixed> $query
     * @return array<string, mixed>|\WP_Error
     */
    public static function dispatch(string $method, string $route, array $body = [], array $query = [])
    {
        $request = new \WP_REST_Request($method, $route);
        if (!empty($query)) {
            $request->set_query_params($query);
        }
        if (!empty($body)) {
            $request->set_body_params($body);
            $request->set_param('context', $request->get_param('context') ?? 'edit');
        }

        $response = rest_do_request($request);

        if ($response->is_error()) {
            return $response->as_error();
        }
        $data = $response->get_data();
        return is_array($data) ? $data : ['result' => $data];
    }
}
