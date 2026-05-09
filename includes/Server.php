<?php
declare(strict_types=1);

namespace SiteMcp;

use WP\MCP\Core\McpAdapter;
use WP\MCP\Transport\HttpTransport;
use WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler;
use WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler;

final class Server
{
    public const ID = 'site-mcp/v1';

    public static function register(McpAdapter $adapter, array $ability_names): void
    {
        $adapter->create_server(
            server_id:                 self::ID,
            server_route_namespace:    'site-mcp/v1',
            server_route:              '/mcp',
            server_name:               __('Site MCP', 'site-mcp'),
            server_description:        __('WordPress site management for AI agents.', 'site-mcp'),
            server_version:            SITE_MCP_VERSION,
            mcp_transports:            [HttpTransport::class],
            error_handler:             ErrorLogMcpErrorHandler::class,
            observability_handler:     NullMcpObservabilityHandler::class,
            tools:                     $ability_names,
            resources:                 [],
            prompts:                   [],
        );
    }
}
