<?php
declare(strict_types=1);

namespace Mrabbani\McpSiteManager\Abilities\Users;

use Mrabbani\McpSiteManager\Abilities\AbilityBundle;
use Mrabbani\McpSiteManager\Support\SchemaBuilder as S;

final class UsersBundle extends AbilityBundle
{
    public function abilities(): array
    {
        return [
            'users-list' => [
                'label'       => __('List users', 'site-mcp'),
                'description' => __('List site users.', 'site-mcp'),
                'input_schema'=> S::object(array_merge(S::paging(), [
                    'roles' => S::str('Comma-separated role slugs'),
                ])),
                'permission_callback' => self::logged_in(),
                'execute' => fn(array $a) => $this->rest('GET', '/wp/v2/users', [], $a),
            ],
            'users-get' => [
                'label'       => __('Get a user', 'site-mcp'),
                'description' => __('Fetch a single user by ID.', 'site-mcp'),
                'input_schema'=> S::object(['id' => S::int('User ID', true)]),
                'permission_callback' => self::logged_in(),
                'execute' => fn(array $a) => $this->rest('GET', "/wp/v2/users/{$a['id']}"),
            ],
            'users-me' => [
                'label'       => __('Get current user', 'site-mcp'),
                'description' => __('Return the authenticated user.', 'site-mcp'),
                'input_schema'=> S::object([]),
                'permission_callback' => self::logged_in(),
                'execute' => fn() => $this->rest('GET', '/wp/v2/users/me'),
            ],
            'users-create' => [
                'label'       => __('Create a user', 'site-mcp'),
                'description' => __('Create a new user account.', 'site-mcp'),
                'input_schema'=> S::object([
                    'username' => S::str('Login name', true),
                    'email'    => S::str('Email address', true),
                    'password' => S::str('Password', true),
                    'name'     => S::str('Display name'),
                    'first_name' => S::str(),
                    'last_name'  => S::str(),
                    'roles'    => S::arr(S::str(), 'Role slugs'),
                ]),
                'permission_callback' => self::logged_in(),
                'execute' => fn(array $a) => $this->rest('POST', '/wp/v2/users', $a),
            ],
            'users-update' => [
                'label'       => __('Update a user', 'site-mcp'),
                'description' => __('Edit a user account.', 'site-mcp'),
                'input_schema'=> S::object([
                    'id'    => S::int('User ID', true),
                    'email' => S::str(),
                    'password' => S::str(),
                    'name'  => S::str(),
                    'first_name' => S::str(),
                    'last_name'  => S::str(),
                    'roles' => S::arr(S::str()),
                ]),
                'permission_callback' => self::logged_in(),
                'execute' => function (array $a) {
                    $id = $a['id']; unset($a['id']);
                    return $this->rest('POST', "/wp/v2/users/$id", $a);
                },
            ],
            'users-delete' => [
                'label'       => __('Delete a user', 'site-mcp'),
                'description' => __('Delete a user; reassign content to another user ID.', 'site-mcp'),
                'input_schema'=> S::object([
                    'id'         => S::int('User ID to delete', true),
                    'reassign'   => S::int('User ID inheriting their content', true),
                    'force'      => S::bool('Required true to confirm', true),
                ]),
                'permission_callback' => self::logged_in(),
                'execute' => fn(array $a) => $this->rest('DELETE', "/wp/v2/users/{$a['id']}", [], [
                    'reassign' => (string) (int) $a['reassign'],
                    'force'    => !empty($a['force']) ? 'true' : 'false',
                ]),
            ],
        ];
    }
}
