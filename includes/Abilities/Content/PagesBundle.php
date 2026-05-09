<?php
declare(strict_types=1);

namespace SiteMcp\Abilities\Content;

use SiteMcp\Abilities\AbilityBundle;
use SiteMcp\Support\SchemaBuilder as S;

final class PagesBundle extends AbilityBundle
{
    public function abilities(): array
    {
        return [
            'pages-list' => [
                'label'       => __('List pages', 'site-mcp'),
                'description' => __('List pages with filters (status, parent, search, paging).', 'site-mcp'),
                'input_schema'=> S::object(array_merge(S::paging(), [
                    'status' => S::str('Comma-separated statuses'),
                    'parent' => S::int('Parent page ID'),
                    'orderby'=> S::str('', false, ['date','id','title','modified','menu_order']),
                    'order'  => S::str('', false, ['asc','desc']),
                ])),
                'permission_callback' => self::logged_in(),
                'execute' => fn(array $a) => $this->rest('GET', '/wp/v2/pages', [], $a),
            ],
            'pages-get' => [
                'label'       => __('Get a page', 'site-mcp'),
                'description' => __('Fetch a single page by ID.', 'site-mcp'),
                'input_schema'=> S::object(['id' => S::int('Page ID', true)]),
                'permission_callback' => self::logged_in(),
                'execute' => fn(array $a) => $this->rest('GET', "/wp/v2/pages/{$a['id']}"),
            ],
            'pages-create' => [
                'label'       => __('Create a page', 'site-mcp'),
                'description' => __('Create a new page.', 'site-mcp'),
                'input_schema'=> S::object([
                    'title'   => S::str('Page title', true),
                    'content' => S::str('Page content'),
                    'status'  => S::str('', false, ['publish','draft','pending','private']),
                    'slug'    => S::str(),
                    'parent'  => S::int(),
                    'menu_order' => S::int(),
                    'template'   => S::str('Page template slug'),
                    'featured_media' => S::int(),
                ]),
                'permission_callback' => self::logged_in(),
                'execute' => fn(array $a) => $this->rest('POST', '/wp/v2/pages', $a),
            ],
            'pages-update' => [
                'label'       => __('Update a page', 'site-mcp'),
                'description' => __('Partial update of an existing page.', 'site-mcp'),
                'input_schema'=> S::object([
                    'id'      => S::int('Page ID', true),
                    'title'   => S::str(),
                    'content' => S::str(),
                    'status'  => S::str('', false, ['publish','draft','pending','private']),
                    'slug'    => S::str(),
                    'parent'  => S::int(),
                    'menu_order' => S::int(),
                    'template'   => S::str(),
                    'featured_media' => S::int(),
                ]),
                'permission_callback' => self::logged_in(),
                'execute' => function (array $a) {
                    $id = $a['id']; unset($a['id']);
                    return $this->rest('POST', "/wp/v2/pages/$id", $a);
                },
            ],
            'pages-delete' => [
                'label'       => __('Delete a page', 'site-mcp'),
                'description' => __('Trash or permanently delete a page.', 'site-mcp'),
                'input_schema'=> S::object([
                    'id'    => S::int('Page ID', true),
                    'force' => S::bool('Skip trash and delete permanently'),
                ]),
                'permission_callback' => self::logged_in(),
                'execute' => function (array $a) {
                    $id = (int) $a['id'];
                    $force = !empty($a['force']);
                    return $this->rest('DELETE', "/wp/v2/pages/$id", [], $force ? ['force' => 'true'] : []);
                },
            ],
        ];
    }
}
