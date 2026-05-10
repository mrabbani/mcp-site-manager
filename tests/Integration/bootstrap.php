<?php
require_once __DIR__ . '/../../vendor/autoload.php';

if (!getenv('MCPSM_URL'))      putenv('MCPSM_URL=http://localhost:8890/wp-json/mcp/mcp-adapter-default-server');
if (!getenv('MCPSM_USER'))     putenv('MCPSM_USER=admin');
if (!getenv('MCPSM_APP_PW'))   fwrite(STDERR, "Set MCPSM_APP_PW env var before running integration tests.\n");
