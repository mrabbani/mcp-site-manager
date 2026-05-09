<?php
declare(strict_types=1);

namespace SiteMcp\Abilities\Media;

use SiteMcp\Abilities\AbilityBundle;
use SiteMcp\Support\SchemaBuilder as S;

final class MediaBundle extends AbilityBundle
{
    public function abilities(): array
    {
        return [
            'media-list' => [
                'label'       => __('List media', 'site-mcp'),
                'description' => __('List attachments.', 'site-mcp'),
                'input_schema'=> S::object(array_merge(S::paging(), [
                    'media_type' => S::str('', false, ['image','video','audio','file']),
                    'parent'     => S::int('Attached post ID'),
                ])),
                'permission_callback' => self::logged_in(),
                'execute' => fn(array $a) => $this->rest('GET', '/wp/v2/media', [], $a),
            ],
            'media-get' => [
                'label'       => __('Get media item', 'site-mcp'),
                'description' => __('Fetch a single attachment.', 'site-mcp'),
                'input_schema'=> S::object(['id' => S::int('Attachment ID', true)]),
                'permission_callback' => self::logged_in(),
                'execute' => fn(array $a) => $this->rest('GET', "/wp/v2/media/{$a['id']}"),
            ],
            'media-upload' => [
                'label'       => __('Upload media', 'site-mcp'),
                'description' => __('Upload an image/file from a URL or base64 payload.', 'site-mcp'),
                'input_schema'=> S::object([
                    'source_url' => S::str('Public URL to download (mutually exclusive with base64)'),
                    'base64'     => S::str('Base64-encoded file contents'),
                    'filename'   => S::str('Required when using base64'),
                    'mime_type'  => S::str('Required when using base64'),
                    'parent'     => S::int('Attach to post ID'),
                    'title'      => S::str(),
                    'alt_text'   => S::str(),
                    'caption'    => S::str(),
                ]),
                'permission_callback' => self::require_cap('upload_files'),
                'execute' => fn(array $a) => $this->upload($a),
            ],
            'media-update' => [
                'label'       => __('Update media metadata', 'site-mcp'),
                'description' => __('Update title, alt_text, caption.', 'site-mcp'),
                'input_schema'=> S::object([
                    'id'       => S::int('Attachment ID', true),
                    'title'    => S::str(),
                    'alt_text' => S::str(),
                    'caption'  => S::str(),
                    'description' => S::str(),
                ]),
                'permission_callback' => self::logged_in(),
                'execute' => function (array $a) {
                    $id = $a['id']; unset($a['id']);
                    return $this->rest('POST', "/wp/v2/media/$id", $a);
                },
            ],
            'media-delete' => [
                'label'       => __('Delete media', 'site-mcp'),
                'description' => __('Permanently delete an attachment.', 'site-mcp'),
                'input_schema'=> S::object(['id' => S::int('Attachment ID', true)]),
                'permission_callback' => self::logged_in(),
                'execute' => fn(array $a) => $this->rest('DELETE', "/wp/v2/media/{$a['id']}", [], ['force' => 'true']),
            ],
        ];
    }

    private function upload(array $a)
    {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $parent = isset($a['parent']) ? (int) $a['parent'] : 0;
        $tmp_path = null;
        $filename = null;

        if (!empty($a['source_url'])) {
            $tmp_path = download_url($a['source_url']);
            if (is_wp_error($tmp_path)) return $tmp_path;
            $filename = basename(parse_url($a['source_url'], PHP_URL_PATH) ?: 'upload');
        } elseif (!empty($a['base64']) && !empty($a['filename']) && !empty($a['mime_type'])) {
            $bytes = base64_decode($a['base64'], true);
            if ($bytes === false) return new \WP_Error('site_mcp_media_b64', 'Invalid base64', ['status' => 400]);
            $tmp_path = wp_tempnam($a['filename']);
            file_put_contents($tmp_path, $bytes);
            $filename = $a['filename'];
        } else {
            return new \WP_Error('site_mcp_media_input', 'Provide source_url OR (base64+filename+mime_type)', ['status' => 400]);
        }

        $file = ['name' => $filename, 'tmp_name' => $tmp_path];
        $attachment_id = media_handle_sideload($file, $parent, $a['title'] ?? null);

        if (is_wp_error($attachment_id)) {
            @unlink($tmp_path);
            return $attachment_id;
        }
        if (!empty($a['alt_text']))    update_post_meta($attachment_id, '_wp_attachment_image_alt', $a['alt_text']);
        if (!empty($a['caption']))     wp_update_post(['ID' => $attachment_id, 'post_excerpt' => $a['caption']]);

        return $this->rest('GET', "/wp/v2/media/$attachment_id");
    }
}
