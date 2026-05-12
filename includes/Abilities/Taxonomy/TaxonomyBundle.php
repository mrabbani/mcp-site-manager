<?php
declare(strict_types=1);

namespace Mrabbani\McpSiteManager\Abilities\Taxonomy;

defined('ABSPATH') || exit;

use Mrabbani\McpSiteManager\Abilities\AbilityBundle;
use Mrabbani\McpSiteManager\Support\SchemaBuilder as S;

final class TaxonomyBundle extends AbilityBundle
{
    public function abilities(): array
    {
        return [
            'taxonomies-list' => [
                'label'       => __('List taxonomies', 'mcp-site-manager'),
                'description' => __('List taxonomies exposed in the REST API.', 'mcp-site-manager'),
                'input_schema'=> S::object([]),
                'permission_callback' => self::logged_in(),
                'execute' => fn() => $this->rest('GET', '/wp/v2/taxonomies'),
            ],
            'terms-list' => [
                'label'       => __('List terms', 'mcp-site-manager'),
                'description' => __('List terms of a taxonomy (e.g. category, post_tag, product_cat).', 'mcp-site-manager'),
                'input_schema'=> S::object(array_merge(S::paging(), [
                    'taxonomy' => S::str('Taxonomy slug', true),
                    'parent'   => S::int('Parent term ID'),
                    'orderby'  => S::str('', false, ['name','id','slug','count','term_order']),
                    'order'    => S::str('', false, ['asc','desc']),
                    'hide_empty'=> S::bool(),
                ])),
                'permission_callback' => self::logged_in(),
                'execute' => fn(array $a) => $this->dispatch('GET', $a, []),
            ],
            'terms-get' => [
                'label'       => __('Get a term', 'mcp-site-manager'),
                'description' => __('Fetch one term by ID.', 'mcp-site-manager'),
                'input_schema'=> S::object([
                    'taxonomy' => S::str('Taxonomy slug', true),
                    'id'       => S::int('Term ID', true),
                ]),
                'permission_callback' => self::logged_in(),
                'execute' => fn(array $a) => $this->dispatch('GET', $a, [], (int) $a['id']),
            ],
            'terms-create' => [
                'label'       => __('Create a term', 'mcp-site-manager'),
                'description' => __('Create a new taxonomy term.', 'mcp-site-manager'),
                'input_schema'=> S::object([
                    'taxonomy'    => S::str('Taxonomy slug', true),
                    'name'        => S::str('Term name', true),
                    'slug'        => S::str('URL slug'),
                    'description' => S::str(),
                    'parent'      => S::int('Parent term ID (hierarchical only)'),
                    'meta'        => ['type' => 'object'],
                ]),
                'permission_callback' => self::logged_in(),
                'execute' => function (array $a) {
                    $body = $a; unset($body['taxonomy']);
                    return $this->dispatch('POST', $a, $body);
                },
            ],
            'terms-update' => [
                'label'       => __('Update a term', 'mcp-site-manager'),
                'description' => __('Partial update of an existing term.', 'mcp-site-manager'),
                'input_schema'=> S::object([
                    'taxonomy'    => S::str('Taxonomy slug', true),
                    'id'          => S::int('Term ID', true),
                    'name'        => S::str(),
                    'slug'        => S::str(),
                    'description' => S::str(),
                    'parent'      => S::int(),
                    'meta'        => ['type' => 'object'],
                ]),
                'permission_callback' => self::logged_in(),
                'execute' => function (array $a) {
                    $id = (int) $a['id'];
                    $body = $a; unset($body['taxonomy'], $body['id']);
                    return $this->dispatch('POST', $a, $body, $id);
                },
            ],
            'terms-delete' => [
                'label'       => __('Delete a term', 'mcp-site-manager'),
                'description' => __('Permanently delete a term.', 'mcp-site-manager'),
                'input_schema'=> S::object([
                    'taxonomy' => S::str('Taxonomy slug', true),
                    'id'       => S::int('Term ID', true),
                ]),
                'permission_callback' => self::logged_in(),
                'execute' => fn(array $a) => $this->dispatch('DELETE', $a, [], (int) $a['id'], ['force' => 'true']),
            ],
        ];
    }

    private function dispatch(string $method, array $args, array $body, int $id = 0, array $query = [])
    {
        $tax = get_taxonomy((string) $args['taxonomy']);
        if (!$tax || empty($tax->show_in_rest)) {
            return new \WP_Error('mcpsm_tax_not_rest', sprintf('Taxonomy "%s" is not REST-enabled.', $args['taxonomy']), ['status' => 400]);
        }
        $base = $tax->rest_namespace ?? 'wp/v2';
        $rest_base = $tax->rest_base ?: $tax->name;
        $route = "/$base/$rest_base" . ($id ? "/$id" : '');
        if (!empty($args) && empty($query) && $method === 'GET' && $id === 0) {
            $query = $args; unset($query['taxonomy']);
        }
        return $this->rest($method, $route, $body, $query);
    }
}
