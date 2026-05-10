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
                'description' => __('List attachments in the media library. Supports paging, filtering by media_type (image, video, audio, file), and filtering to attachments belonging to a specific parent post.', 'site-mcp'),
                'input_schema'=> S::object(array_merge(S::paging(), [
                    'media_type' => S::str('Filter by media type.', false, ['image','video','audio','file']),
                    'parent'     => S::int('Only return attachments attached to this post ID.'),
                ])),
                'permission_callback' => self::logged_in(),
                'execute' => fn(array $a) => $this->rest('GET', '/wp/v2/media', [], $a),
            ],
            'media-get' => [
                'label'       => __('Get media item', 'site-mcp'),
                'description' => __('Fetch a single attachment by ID. Returns full media object including source_url, mime_type, alt_text, caption, and media_details (sizes, dimensions).', 'site-mcp'),
                'input_schema'=> S::object(['id' => S::int('Attachment ID.', true)]),
                'permission_callback' => self::logged_in(),
                'execute' => fn(array $a) => $this->rest('GET', "/wp/v2/media/{$a['id']}"),
            ],
            'media-upload' => [
                'label'       => __('Upload media', 'site-mcp'),
                'description' => __(
                    'Upload an image or file to the WordPress media library. Provide EITHER source_url (a publicly reachable HTTP/HTTPS URL the server will download) OR a base64 payload along with filename and mime_type. To attach the result to a post, set parent to the post ID. To also set it as that post\'s featured image, set as_featured=true (requires parent). Returns the created attachment object including its id, source_url, and media_details.',
                    'site-mcp'
                ),
                'input_schema'=> S::object([
                    'source_url'  => S::str('Publicly reachable URL the server will download. Mutually exclusive with base64.'),
                    'base64'      => S::str('Raw base64-encoded file contents (no data: prefix). Required when source_url is not provided.'),
                    'filename'    => S::str('File name with extension, e.g. "cover.png". Required when using base64. If extension is missing it will be derived from mime_type.'),
                    'mime_type'   => S::str('MIME type, e.g. "image/png", "image/jpeg", "application/pdf". Required when using base64.'),
                    'parent'      => S::int('Post ID to attach the media to. Required if as_featured=true.'),
                    'as_featured' => S::bool('When true and parent is set, also assigns this attachment as the parent post\'s featured image (post thumbnail).'),
                    'title'       => S::str('Attachment title. Defaults to the filename.'),
                    'alt_text'    => S::str('Image alt text for accessibility. Recommended for images.'),
                    'caption'     => S::str('Caption shown under the image in themes that display it.'),
                ]),
                'permission_callback' => self::require_cap('upload_files'),
                'execute' => fn(array $a) => $this->upload($a),
            ],
            'media-update' => [
                'label'       => __('Update media metadata', 'site-mcp'),
                'description' => __('Update an existing attachment\'s metadata. Only the fields you provide are changed. The file itself is not replaced — to change the binary, delete and re-upload.', 'site-mcp'),
                'input_schema'=> S::object([
                    'id'          => S::int('Attachment ID to update.', true),
                    'title'       => S::str('New title shown in the media library.'),
                    'alt_text'    => S::str('New alt text for accessibility.'),
                    'caption'     => S::str('New caption.'),
                    'description' => S::str('Long description shown on the attachment page.'),
                ]),
                'permission_callback' => self::logged_in(),
                'execute' => function (array $a) {
                    $id = $a['id']; unset($a['id']);
                    return $this->rest('POST', "/wp/v2/media/$id", $a);
                },
            ],
            'media-delete' => [
                'label'       => __('Delete media', 'site-mcp'),
                'description' => __('Permanently delete an attachment and its files (force-deleted, no trash). If the attachment is set as a featured image on any post, that link is removed automatically. This cannot be undone.', 'site-mcp'),
                'input_schema'=> S::object(['id' => S::int('Attachment ID to delete.', true)]),
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

        $parent      = isset($a['parent']) ? (int) $a['parent'] : 0;
        $as_featured = !empty($a['as_featured']);
        $tmp_path    = null;
        $filename    = null;

        if ($as_featured && !$parent) {
            return new \WP_Error(
                'site_mcp_media_input',
                'as_featured=true requires parent (post ID).',
                ['status' => 400]
            );
        }

        if (!empty($a['source_url'])) {
            $tmp_path = download_url($a['source_url']);
            if (is_wp_error($tmp_path)) return $tmp_path;
            $filename = basename(parse_url($a['source_url'], PHP_URL_PATH) ?: 'upload');
        } elseif (!empty($a['base64']) && !empty($a['filename']) && !empty($a['mime_type'])) {
            $bytes = base64_decode($a['base64'], true);
            if ($bytes === false) {
                return new \WP_Error('site_mcp_media_b64', 'Invalid base64 payload (could not decode).', ['status' => 400]);
            }
            $filename = $a['filename'];
            if (!preg_match('/\.[a-z0-9]+$/i', $filename)) {
                $ext = function_exists('wp_get_default_extension_for_mime_type')
                    ? wp_get_default_extension_for_mime_type($a['mime_type'])
                    : '';
                if ($ext) {
                    $filename .= '.' . $ext;
                }
            }
            $tmp_path = wp_tempnam($filename);
            file_put_contents($tmp_path, $bytes);
        } else {
            return new \WP_Error(
                'site_mcp_media_input',
                'Provide either source_url, OR all three of base64, filename, and mime_type.',
                ['status' => 400]
            );
        }

        $file          = ['name' => $filename, 'tmp_name' => $tmp_path];
        $attachment_id = media_handle_sideload($file, $parent, $a['title'] ?? null);

        if (is_wp_error($attachment_id)) {
            @unlink($tmp_path);
            return $attachment_id;
        }

        if (!empty($a['alt_text'])) update_post_meta($attachment_id, '_wp_attachment_image_alt', $a['alt_text']);
        if (!empty($a['caption']))  wp_update_post(['ID' => $attachment_id, 'post_excerpt' => $a['caption']]);

        if ($as_featured) {
            $ok = set_post_thumbnail($parent, $attachment_id);
            if (!$ok) {
                return new \WP_Error(
                    'site_mcp_media_thumb',
                    sprintf('Uploaded as attachment %d, but failed to set as featured image on post %d. Post type may not support thumbnails.', $attachment_id, $parent),
                    ['status' => 500, 'attachment_id' => $attachment_id]
                );
            }
        }

        $result = $this->rest('GET', "/wp/v2/media/$attachment_id");
        if (is_array($result) && $as_featured) {
            $result['featured_for_post'] = $parent;
        }
        return $result;
    }
}
