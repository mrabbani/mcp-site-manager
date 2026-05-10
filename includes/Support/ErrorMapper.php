<?php
declare(strict_types=1);

namespace Mrabbani\McpSiteManager\Support;

final class ErrorMapper
{
    public const CODE_INVALID_PARAMS = -32602;
    public const CODE_INTERNAL       = -32603;
    public const CODE_FORBIDDEN      = -32001;

    /** @param \WP_Error|\Throwable $error */
    public static function toMcp($error): array
    {
        if ($error instanceof \Throwable) {
            return [
                'code'    => self::CODE_INTERNAL,
                'message' => self::is_debug() ? $error->getMessage() : __('Internal server error', 'site-mcp'),
                'data'    => self::is_debug() ? ['exception' => get_class($error), 'trace' => $error->getTraceAsString()] : new \stdClass(),
            ];
        }

        $data    = is_array($error->get_error_data()) ? $error->get_error_data() : [];
        $status  = isset($data['status']) ? (int) $data['status'] : 500;
        $message = $error->get_error_message() ?: __('Unknown error', 'site-mcp');

        if ($status === 401 || $status === 403) {
            return [
                'code'    => self::CODE_FORBIDDEN,
                'message' => $message,
                'data'    => array_merge(['http_status' => $status], $data),
            ];
        }
        if ($status >= 400 && $status < 500) {
            return [
                'code'    => self::CODE_INVALID_PARAMS,
                'message' => $message,
                'data'    => array_merge(['http_status' => $status], $data),
            ];
        }
        return [
            'code'    => self::CODE_INTERNAL,
            'message' => self::is_debug() ? $message : __('Internal server error', 'site-mcp'),
            'data'    => self::is_debug() ? array_merge(['http_status' => $status], $data) : new \stdClass(),
        ];
    }

    private static function is_debug(): bool
    {
        return defined('WP_DEBUG') && WP_DEBUG;
    }
}
