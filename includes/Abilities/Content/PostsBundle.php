<?php
declare(strict_types=1);

namespace SiteMcp\Abilities\Content;

use SiteMcp\Abilities\AbilityBundle;
use SiteMcp\Support\SchemaBuilder as S;

final class PostsBundle extends AbilityBundle
{
    private const POST_TYPE = 'posts';

    public function abilities(): array
    {
        return [
            'posts-list' => [
                'label'       => __('List posts', 'site-mcp'),
                'description' => __('List blog posts with filters (status, author, search, paging).', 'site-mcp'),
                'input_schema' => S::object(array_merge(S::paging(), [
                    'status' => S::str('Comma-separated statuses (publish, draft, pending, private, future)'),
                    'author' => S::int('Author user ID'),
                    'orderby'=> S::str('', false, ['date','id','title','modified','menu_order']),
                    'order'  => S::str('', false, ['asc','desc']),
                ])),
                'permission_callback' => self::logged_in(),
                'execute' => fn(array $args) => $this->rest('GET', '/wp/v2/posts', [], $args),
            ],
            'posts-get' => [
                'label'       => __('Get a post', 'site-mcp'),
                'description' => __('Fetch a single post by ID.', 'site-mcp'),
                'input_schema'=> S::object(['id' => S::int('Post ID', true)]),
                'permission_callback' => self::logged_in(),
                'execute' => fn(array $a) => $this->rest('GET', "/wp/v2/posts/{$a['id']}"),
            ],
            'posts-create' => [
                'label'       => __('Create a post', 'site-mcp'),
                'description' => __('Create a new blog post.', 'site-mcp'),
                'input_schema'=> S::object([
                    'title'         => S::str('Post title', true),
                    'content'       => S::str('Post content (HTML or block markup)'),
                    'excerpt'       => S::str('Excerpt'),
                    'status'        => S::str('', false, ['publish','draft','pending','private','future']),
                    'slug'          => S::str('URL slug'),
                    'author'        => S::int('Author user ID'),
                    'categories'    => S::arr(S::int(), 'Category term IDs'),
                    'tags'          => S::arr(S::int(), 'Tag term IDs'),
                    'featured_media'=> S::int('Featured image attachment ID'),
                    'meta'          => ['type' => 'object', 'description' => 'Custom meta fields (must be registered as show_in_rest=true)'],
                ]),
                'permission_callback' => self::logged_in(),
                'execute' => fn(array $a) => $this->rest('POST', '/wp/v2/posts', $a),
            ],
            'posts-update' => [
                'label'       => __('Update a post', 'site-mcp'),
                'description' => __('Partial update of an existing post.', 'site-mcp'),
                'input_schema'=> S::object([
                    'id'            => S::int('Post ID', true),
                    'title'         => S::str('Post title'),
                    'content'       => S::str('Post content'),
                    'excerpt'       => S::str(),
                    'status'        => S::str('', false, ['publish','draft','pending','private','future']),
                    'slug'          => S::str(),
                    'author'        => S::int(),
                    'categories'    => S::arr(S::int()),
                    'tags'          => S::arr(S::int()),
                    'featured_media'=> S::int(),
                    'meta'          => ['type' => 'object'],
                ]),
                'permission_callback' => self::logged_in(),
                'execute' => function (array $a) {
                    $id = $a['id']; unset($a['id']);
                    return $this->rest('POST', "/wp/v2/posts/$id", $a); // POST is what WP uses for updates
                },
            ],
            'posts-delete' => [
                'label'       => __('Delete a post', 'site-mcp'),
                'description' => __('Trash or permanently delete a post.', 'site-mcp'),
                'input_schema'=> S::object([
                    'id'    => S::int('Post ID', true),
                    'force' => S::bool('Skip trash and delete permanently'),
                ]),
                'permission_callback' => self::logged_in(),
                'execute' => function (array $a) {
                    $id = (int) $a['id'];
                    $force = !empty($a['force']);
                    return $this->rest('DELETE', "/wp/v2/posts/$id", [], $force ? ['force' => 'true'] : []);
                },
            ],
        ];
    }
}
