<?php
declare(strict_types=1);

namespace Mrabbani\McpSiteManager\Abilities\Content;

use Mrabbani\McpSiteManager\Abilities\AbilityBundle;
use Mrabbani\McpSiteManager\Support\SchemaBuilder as S;

final class CptBundle extends AbilityBundle
{
    public function abilities(): array
    {
        return [
            'cpt-list-types' => [
                'label'       => __('List custom post types', 'mcp-site-manager'),
                'description' => __('List all post types exposed in the REST API (show_in_rest=true).', 'mcp-site-manager'),
                'input_schema'=> S::object([]),
                'permission_callback' => self::logged_in(),
                'execute' => fn() => $this->rest('GET', '/wp/v2/types'),
            ],
            'cpt-list' => [
                'label'       => __('List CPT entries', 'mcp-site-manager'),
                'description' => __('List entries of a custom post type. Provide post_type slug.', 'mcp-site-manager'),
                'input_schema'=> S::object(array_merge(S::paging(), [
                    'post_type' => S::str('Post type slug (e.g. product, dokan_vendor_request)', true),
                    'status'    => S::str('Comma-separated statuses'),
                ])),
                'permission_callback' => self::logged_in(),
                'execute' => fn(array $a) => $this->dispatch_cpt('GET', $a, []),
            ],
            'cpt-get' => [
                'label'       => __('Get a CPT entry', 'mcp-site-manager'),
                'description' => __('Fetch one custom post type entry by ID.', 'mcp-site-manager'),
                'input_schema'=> S::object([
                    'post_type' => S::str('Post type slug', true),
                    'id'        => S::int('Entry ID', true),
                ]),
                'permission_callback' => self::logged_in(),
                'execute' => fn(array $a) => $this->dispatch_cpt('GET', $a, [], (int) $a['id']),
            ],
            'cpt-create' => [
                'label'       => __('Create a CPT entry', 'mcp-site-manager'),
                'description' => __('Create a new custom post type entry. Body is the REST payload for that type.', 'mcp-site-manager'),
                'input_schema'=> S::object([
                    'post_type' => S::str('Post type slug', true),
                    'title'     => S::str('Title', true),
                    'content'   => S::str('Content'),
                    'status'    => S::str('', false, ['publish','draft','pending','private']),
                    'meta'      => ['type' => 'object', 'description' => 'Registered meta fields'],
                ]),
                'permission_callback' => self::logged_in(),
                'execute' => function (array $a) {
                    $body = $a; unset($body['post_type']);
                    return $this->dispatch_cpt('POST', $a, $body);
                },
            ],
            'cpt-update' => [
                'label'       => __('Update a CPT entry', 'mcp-site-manager'),
                'description' => __('Partial update of an existing CPT entry.', 'mcp-site-manager'),
                'input_schema'=> S::object([
                    'post_type' => S::str('Post type slug', true),
                    'id'        => S::int('Entry ID', true),
                    'title'     => S::str(),
                    'content'   => S::str(),
                    'status'    => S::str(),
                    'meta'      => ['type' => 'object'],
                ]),
                'permission_callback' => self::logged_in(),
                'execute' => function (array $a) {
                    $id = (int) $a['id'];
                    $body = $a; unset($body['post_type'], $body['id']);
                    return $this->dispatch_cpt('POST', $a, $body, $id);
                },
            ],
            'cpt-delete' => [
                'label'       => __('Delete a CPT entry', 'mcp-site-manager'),
                'description' => __('Trash or permanently delete a CPT entry.', 'mcp-site-manager'),
                'input_schema'=> S::object([
                    'post_type' => S::str('Post type slug', true),
                    'id'        => S::int('Entry ID', true),
                    'force'     => S::bool('Skip trash'),
                ]),
                'permission_callback' => self::logged_in(),
                'execute' => function (array $a) {
                    $id = (int) $a['id'];
                    $force = !empty($a['force']);
                    return $this->dispatch_cpt('DELETE', $a, [], $id, $force ? ['force' => 'true'] : []);
                },
            ],
        ];
    }

    private function dispatch_cpt(string $method, array $args, array $body, int $id = 0, array $query = [])
    {
        $type = get_post_type_object((string) $args['post_type']);
        if (!$type || empty($type->show_in_rest)) {
            return new \WP_Error('mcpsm_cpt_not_rest', sprintf('Post type "%s" is not REST-enabled.', $args['post_type']), ['status' => 400]);
        }
        $base = $type->rest_namespace ?? 'wp/v2';
        $rest_base = $type->rest_base ?: $type->name;
        $route = "/$base/$rest_base" . ($id ? "/$id" : '');
        if (!empty($args) && empty($query) && $method === 'GET' && $id === 0) {
            $query = $args; unset($query['post_type']);
        }
        return $this->rest($method, $route, $body, $query);
    }
}
