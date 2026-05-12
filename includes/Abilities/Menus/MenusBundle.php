<?php
declare(strict_types=1);

namespace Mrabbani\McpSiteManager\Abilities\Menus;

defined('ABSPATH') || exit;

use Mrabbani\McpSiteManager\Abilities\AbilityBundle;
use Mrabbani\McpSiteManager\Support\SchemaBuilder as S;

final class MenusBundle extends AbilityBundle
{
    public function abilities(): array
    {
        return [
            'menus-list' => [
                'label'       => __('List nav menus', 'mcp-site-manager'),
                'description' => __('List navigation menus.', 'mcp-site-manager'),
                'input_schema'=> S::object(S::paging()),
                'permission_callback' => self::logged_in(),
                'execute' => fn(array $a) => $this->rest('GET', '/wp/v2/menus', [], $a),
            ],
            'menus-get' => [
                'label'       => __('Get a nav menu', 'mcp-site-manager'),
                'input_schema'=> S::object(['id' => S::int('Menu ID', true)]),
                'description' => __('Fetch one nav menu.', 'mcp-site-manager'),
                'permission_callback' => self::logged_in(),
                'execute' => fn(array $a) => $this->rest('GET', "/wp/v2/menus/{$a['id']}"),
            ],
            'menus-create' => [
                'label'       => __('Create a nav menu', 'mcp-site-manager'),
                'description' => __('Create a new menu.', 'mcp-site-manager'),
                'input_schema'=> S::object([
                    'name'        => S::str('Menu name', true),
                    'description' => S::str(),
                    'locations'   => S::arr(S::str(), 'Location slugs to assign'),
                ]),
                'permission_callback' => self::logged_in(),
                'execute' => fn(array $a) => $this->rest('POST', '/wp/v2/menus', $a),
            ],
            'menus-update' => [
                'label'       => __('Update a nav menu', 'mcp-site-manager'),
                'description' => __('Update menu name/description/locations.', 'mcp-site-manager'),
                'input_schema'=> S::object([
                    'id'   => S::int('Menu ID', true),
                    'name' => S::str(),
                    'description' => S::str(),
                    'locations'   => S::arr(S::str()),
                ]),
                'permission_callback' => self::logged_in(),
                'execute' => function (array $a) {
                    $id = $a['id']; unset($a['id']);
                    return $this->rest('POST', "/wp/v2/menus/$id", $a);
                },
            ],
            'menus-delete' => [
                'label'       => __('Delete a nav menu', 'mcp-site-manager'),
                'description' => __('Delete a navigation menu.', 'mcp-site-manager'),
                'input_schema'=> S::object(['id' => S::int('Menu ID', true)]),
                'permission_callback' => self::logged_in(),
                'execute' => fn(array $a) => $this->rest('DELETE', "/wp/v2/menus/{$a['id']}", [], ['force' => 'true']),
            ],
            'menu-items-list' => [
                'label'       => __('List menu items', 'mcp-site-manager'),
                'description' => __('List items in nav menus.', 'mcp-site-manager'),
                'input_schema'=> S::object(array_merge(S::paging(), [
                    'menus' => S::int('Filter by menu ID'),
                ])),
                'permission_callback' => self::logged_in(),
                'execute' => fn(array $a) => $this->rest('GET', '/wp/v2/menu-items', [], $a),
            ],
            'menu-items-get' => [
                'label'       => __('Get menu item', 'mcp-site-manager'),
                'description' => __('Fetch one menu item.', 'mcp-site-manager'),
                'input_schema'=> S::object(['id' => S::int('Item ID', true)]),
                'permission_callback' => self::logged_in(),
                'execute' => fn(array $a) => $this->rest('GET', "/wp/v2/menu-items/{$a['id']}"),
            ],
            'menu-items-create' => [
                'label'       => __('Create menu item', 'mcp-site-manager'),
                'description' => __('Add a new item to a menu.', 'mcp-site-manager'),
                'input_schema'=> S::object([
                    'menus' => S::int('Menu ID', true),
                    'title' => S::str('Display label', true),
                    'type'  => S::str('Item type', false, ['custom','post_type','taxonomy','post_type_archive']),
                    'object'=> S::str('Object type slug for type=post_type/taxonomy'),
                    'object_id' => S::int('Linked object ID'),
                    'url'   => S::str('URL for type=custom'),
                    'parent'=> S::int('Parent menu item ID'),
                    'menu_order' => S::int(),
                ]),
                'permission_callback' => self::logged_in(),
                'execute' => fn(array $a) => $this->rest('POST', '/wp/v2/menu-items', $a),
            ],
            'menu-items-update' => [
                'label'       => __('Update menu item', 'mcp-site-manager'),
                'description' => __('Edit a menu item.', 'mcp-site-manager'),
                'input_schema'=> S::object([
                    'id'    => S::int('Item ID', true),
                    'title' => S::str(),
                    'type'  => S::str(),
                    'object'=> S::str(),
                    'object_id' => S::int(),
                    'url'   => S::str(),
                    'parent'=> S::int(),
                    'menu_order' => S::int(),
                    'menus' => S::int(),
                ]),
                'permission_callback' => self::logged_in(),
                'execute' => function (array $a) {
                    $id = $a['id']; unset($a['id']);
                    return $this->rest('POST', "/wp/v2/menu-items/$id", $a);
                },
            ],
            'menu-items-delete' => [
                'label'       => __('Delete menu item', 'mcp-site-manager'),
                'description' => __('Delete a menu item.', 'mcp-site-manager'),
                'input_schema'=> S::object(['id' => S::int('Item ID', true)]),
                'permission_callback' => self::logged_in(),
                'execute' => fn(array $a) => $this->rest('DELETE', "/wp/v2/menu-items/{$a['id']}", [], ['force' => 'true']),
            ],
            'menu-locations-list' => [
                'label'       => __('List menu locations', 'mcp-site-manager'),
                'description' => __('Theme-defined navigation menu locations.', 'mcp-site-manager'),
                'input_schema'=> S::object([]),
                'permission_callback' => self::logged_in(),
                'execute' => fn() => $this->rest('GET', '/wp/v2/menu-locations'),
            ],
        ];
    }
}
