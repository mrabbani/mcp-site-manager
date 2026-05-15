<?php
declare(strict_types=1);

namespace Mrabbani\McpSiteManager\Abilities\Media;

defined('ABSPATH') || exit;

use Mrabbani\McpSiteManager\Abilities\AbilityBundle;
use Mrabbani\McpSiteManager\Support\SchemaBuilder as S;
use Mrabbani\McpSiteManager\Support\UrlGuard;

final class MediaBundle extends AbilityBundle
{
    public function abilities(): array
    {
        return [
            'media-list' => [
                'label'       => __('List media', 'mcp-site-manager'),
                'description' => __('List attachments in the media library. Supports paging, filtering by media_type (image, video, audio, file), and filtering to attachments belonging to a specific parent post.', 'mcp-site-manager'),
                'input_schema'=> S::object(array_merge(S::paging(), [
                    'media_type' => S::str('Filter by media type.', false, ['image','video','audio','file']),
                    'parent'     => S::int('Only return attachments attached to this post ID.'),
                ])),
                'permission_callback' => self::logged_in(),
                'execute' => fn(array $a) => $this->rest('GET', '/wp/v2/media', [], $a),
            ],
            'media-get' => [
                'label'       => __('Get media item', 'mcp-site-manager'),
                'description' => __('Fetch a single attachment by ID. Returns full media object including source_url, mime_type, alt_text, caption, and media_details (sizes, dimensions).', 'mcp-site-manager'),
                'input_schema'=> S::object(['id' => S::int('Attachment ID.', true)]),
                'permission_callback' => self::logged_in(),
                'execute' => fn(array $a) => $this->rest('GET', "/wp/v2/media/{$a['id']}"),
            ],
            'media-upload' => [
                'label'       => __('Upload media', 'mcp-site-manager'),
                'description' => __(
                    'Upload an image or file to the WordPress Media Library. Pass either source_url (public URL — preferred for files over ~30 KB to avoid base64 overhead) or base64 + filename + mime_type, never both. Optional: title, alt_text, caption, and parent (post ID — sets attachment parent). To set as the post\'s featured image in the same call, pass parent + as_featured=true. Returns attachment ID, source_url, and media_details (auto-generated sizes).',
                    'mcp-site-manager'
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
                'label'       => __('Update media metadata', 'mcp-site-manager'),
                'description' => __('Update an existing attachment\'s metadata. Only the fields you provide are changed. The file itself is not replaced — to change the binary, delete and re-upload.', 'mcp-site-manager'),
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
                'label'       => __('Delete media', 'mcp-site-manager'),
                'description' => __('Permanently delete an attachment and its files (force-deleted, no trash). If the attachment is set as a featured image on any post, that link is removed automatically. This cannot be undone.', 'mcp-site-manager'),
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
                'mcpsm_media_input',
                'as_featured=true requires parent (post ID).',
                ['status' => 400]
            );
        }

        if (!empty($a['source_url'])) {
            $ssrf_check = $this->validate_source_url($a['source_url']);
            if (is_wp_error($ssrf_check)) return $ssrf_check;
            $tmp_path = download_url($a['source_url']);
            if (is_wp_error($tmp_path)) return $tmp_path;
            $filename = basename(wp_parse_url($a['source_url'], PHP_URL_PATH) ?: 'upload');
            if (!preg_match('/\.[a-z0-9]+$/i', $filename)) {
                $mime = function_exists('mime_content_type') ? @mime_content_type($tmp_path) : '';
                $ext  = ($mime && function_exists('wp_get_default_extension_for_mime_type'))
                    ? wp_get_default_extension_for_mime_type($mime)
                    : '';
                if ($ext) {
                    $filename .= '.' . $ext;
                }
            }
        } elseif (!empty($a['base64']) && !empty($a['filename']) && !empty($a['mime_type'])) {
            $bytes = base64_decode($a['base64'], true);
            if ($bytes === false) {
                return new \WP_Error('mcpsm_media_b64', 'Invalid base64 payload (could not decode).', ['status' => 400]);
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
                'mcpsm_media_input',
                'Provide either source_url, OR all three of base64, filename, and mime_type.',
                ['status' => 400]
            );
        }

        $file          = ['name' => $filename, 'tmp_name' => $tmp_path];
        $attachment_id = media_handle_sideload($file, $parent, $a['title'] ?? null);

        if (is_wp_error($attachment_id)) {
            wp_delete_file($tmp_path);
            return $attachment_id;
        }

        if (!empty($a['alt_text'])) update_post_meta($attachment_id, '_wp_attachment_image_alt', $a['alt_text']);
        if (!empty($a['caption']))  wp_update_post(['ID' => $attachment_id, 'post_excerpt' => $a['caption']]);

        if ($as_featured) {
            $ok = set_post_thumbnail($parent, $attachment_id);
            if (!$ok) {
                return new \WP_Error(
                    'mcpsm_media_thumb',
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

    /**
     * SSRF guard for source_url. Defers to UrlGuard with the
     * `mcpsm_media_upload_allowed_hosts` filter so site owners can pin which
     * remote hosts the server will fetch images from.
     */
    private function validate_source_url(string $url)
    {
        /**
         * Filter the host allowlist for media-upload `source_url` fetches.
         *
         * @param string[] $hosts Default empty array (no host restriction).
         */
        $allowed = apply_filters('mcpsm_media_upload_allowed_hosts', []);
        return UrlGuard::validate($url, [
            'allowed_hosts' => is_array($allowed) ? $allowed : [],
            'error_code'    => 'mcpsm_media_ssrf',
        ]);
    }
}
