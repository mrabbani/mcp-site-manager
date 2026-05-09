<?php
declare(strict_types=1);

namespace SiteMcp\Abilities\Comments;

use SiteMcp\Abilities\AbilityBundle;
use SiteMcp\Support\SchemaBuilder as S;

final class CommentsBundle extends AbilityBundle
{
    public function abilities(): array
    {
        return [
            'comments-list' => [
                'label'       => __('List comments', 'site-mcp'),
                'description' => __('List comments with filters.', 'site-mcp'),
                'input_schema'=> S::object(array_merge(S::paging(), [
                    'post'   => S::int('Filter by post ID'),
                    'status' => S::str('', false, ['approve','hold','spam','trash']),
                    'author' => S::int('Filter by user ID'),
                ])),
                'permission_callback' => self::logged_in(),
                'execute' => fn(array $a) => $this->rest('GET', '/wp/v2/comments', [], $a),
            ],
            'comments-get' => [
                'label'       => __('Get a comment', 'site-mcp'),
                'description' => __('Fetch one comment by ID.', 'site-mcp'),
                'input_schema'=> S::object(['id' => S::int('Comment ID', true)]),
                'permission_callback' => self::logged_in(),
                'execute' => fn(array $a) => $this->rest('GET', "/wp/v2/comments/{$a['id']}"),
            ],
            'comments-create' => [
                'label'       => __('Create a comment', 'site-mcp'),
                'description' => __('Create a comment on a post.', 'site-mcp'),
                'input_schema'=> S::object([
                    'post'    => S::int('Post ID', true),
                    'content' => S::str('Comment HTML', true),
                    'parent'  => S::int('Parent comment ID'),
                    'author_name'  => S::str('For non-logged-in (rare via MCP)'),
                    'author_email' => S::str(),
                ]),
                'permission_callback' => self::logged_in(),
                'execute' => fn(array $a) => $this->rest('POST', '/wp/v2/comments', $a),
            ],
            'comments-update' => [
                'label'       => __('Update a comment', 'site-mcp'),
                'description' => __('Edit comment content.', 'site-mcp'),
                'input_schema'=> S::object([
                    'id'      => S::int('Comment ID', true),
                    'content' => S::str(),
                    'status'  => S::str('', false, ['approve','hold','spam','trash']),
                ]),
                'permission_callback' => self::logged_in(),
                'execute' => function (array $a) {
                    $id = $a['id']; unset($a['id']);
                    return $this->rest('POST', "/wp/v2/comments/$id", $a);
                },
            ],
            'comments-delete' => [
                'label'       => __('Delete a comment', 'site-mcp'),
                'description' => __('Trash or permanently delete a comment.', 'site-mcp'),
                'input_schema'=> S::object([
                    'id'    => S::int('Comment ID', true),
                    'force' => S::bool('Skip trash'),
                ]),
                'permission_callback' => self::logged_in(),
                'execute' => function (array $a) {
                    $id = (int) $a['id'];
                    $force = !empty($a['force']);
                    return $this->rest('DELETE', "/wp/v2/comments/$id", [], $force ? ['force' => 'true'] : []);
                },
            ],
            'comments-moderate' => [
                'label'       => __('Moderate a comment', 'site-mcp'),
                'description' => __('Set comment status (approve/hold/spam/trash/unspam).', 'site-mcp'),
                'input_schema'=> S::object([
                    'id'     => S::int('Comment ID', true),
                    'status' => S::str('Target status', true, ['approve','hold','spam','trash','unspam']),
                ]),
                'permission_callback' => self::require_cap('moderate_comments'),
                'execute' => function (array $a) {
                    $ok = wp_set_comment_status((int) $a['id'], (string) $a['status'], true);
                    if (is_wp_error($ok)) return $ok;
                    if (!$ok) return new \WP_Error('site_mcp_moderate_failed', 'Could not set status', ['status' => 500]);
                    return ['id' => (int) $a['id'], 'status' => (string) $a['status']];
                },
            ],
        ];
    }
}
