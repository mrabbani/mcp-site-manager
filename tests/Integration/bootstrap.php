<?php
require_once __DIR__ . '/../../vendor/autoload.php';

if (!getenv('SITE_MCP_URL'))      putenv('SITE_MCP_URL=http://localhost:8890/wp-json/mcp/mcp-adapter-default-server');
if (!getenv('SITE_MCP_USER'))     putenv('SITE_MCP_USER=admin');
if (!getenv('SITE_MCP_APP_PW'))   fwrite(STDERR, "Set SITE_MCP_APP_PW env var before running integration tests.\n");
