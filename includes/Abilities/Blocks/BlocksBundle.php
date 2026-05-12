<?php
declare(strict_types=1);

namespace Mrabbani\McpSiteManager\Abilities\Blocks;

defined('ABSPATH') || exit;

use Mrabbani\McpSiteManager\Abilities\AbilityBundle;
use Mrabbani\McpSiteManager\Support\SchemaBuilder as S;

/**
 * Surface block-editor + FSE design context to MCP clients so they can write
 * valid block markup, pick patterns, and respect theme.json design tokens.
 *
 * All readers go straight to the server-side registries — REST endpoints drop
 * fields an AI client needs (attribute schemas, supports, parent/ancestor).
 */
final class BlocksBundle extends AbilityBundle
{
    public function abilities(): array
    {
        return [
            'blocks-list' => [
                'label'       => __('List registered blocks', 'mcp-site-manager'),
                'description' => __('Server-side registered block types with name, title, category, parent/ancestor constraints, and counts. Set verbose=true for full attributes/supports/variations.', 'mcp-site-manager'),
                'input_schema'=> S::object([
                    'category' => S::str('Filter by block category slug (e.g. "text", "media", "design").'),
                    'search'   => S::str('Substring match against block name and title.'),
                    'verbose'  => S::bool('Include full attributes schema, supports, variations, and example markup.'),
                ]),
                'permission_callback' => self::require_cap('edit_posts'),
                'execute' => fn(array $a) => $this->blocks_list($a),
            ],
            'blocks-get' => [
                'label'       => __('Get a single block type', 'mcp-site-manager'),
                'description' => __('Full metadata for one block: attributes schema with defaults, supports, parent/ancestor, variations, styles, and example markup.', 'mcp-site-manager'),
                'input_schema'=> S::object([
                    'name' => S::str('Fully-qualified block name (e.g. "core/paragraph", "core/group").', true),
                ]),
                'permission_callback' => self::require_cap('edit_posts'),
                'execute' => fn(array $a) => $this->blocks_get($a),
            ],
            'block-categories-list' => [
                'label'       => __('List block categories', 'mcp-site-manager'),
                'description' => __('Block-editor categories used to group blocks in the inserter.', 'mcp-site-manager'),
                'input_schema'=> S::object([]),
                'permission_callback' => self::require_cap('edit_posts'),
                'execute' => fn() => $this->block_categories(),
            ],
            'block-patterns-list' => [
                'label'       => __('List registered block patterns', 'mcp-site-manager'),
                'description' => __('Registered patterns (core + theme + plugin). Use include_content=true to fetch each pattern\'s block markup.', 'mcp-site-manager'),
                'input_schema'=> S::object([
                    'category'        => S::str('Filter by pattern category slug.'),
                    'block_type'      => S::str('Filter to patterns that target this block type (e.g. "core/post-content").'),
                    'search'          => S::str('Substring match against pattern name, title, and description.'),
                    'include_content' => S::bool('Include the block-markup content for each pattern (default false; patterns can be large).'),
                ]),
                'permission_callback' => self::require_cap('edit_posts'),
                'execute' => fn(array $a) => $this->patterns_list($a),
            ],
            'block-pattern-categories-list' => [
                'label'       => __('List block-pattern categories', 'mcp-site-manager'),
                'description' => __('Categories used to group block patterns in the inserter.', 'mcp-site-manager'),
                'input_schema'=> S::object([]),
                'permission_callback' => self::require_cap('edit_posts'),
                'execute' => fn() => $this->pattern_categories(),
            ],
            'templates-list' => [
                'label'       => __('List FSE block templates', 'mcp-site-manager'),
                'description' => __('Block templates (wp_template) from the active block theme. Empty for classic themes.', 'mcp-site-manager'),
                'input_schema'=> S::object([
                    'include_content' => S::bool('Include each template\'s block-markup content (default false).'),
                ]),
                'permission_callback' => self::require_cap('edit_theme_options'),
                'execute' => fn(array $a) => $this->templates_list($a, 'wp_template'),
            ],
            'template-parts-list' => [
                'label'       => __('List FSE block template parts', 'mcp-site-manager'),
                'description' => __('Block template parts (wp_template_part) from the active block theme — header, footer, sidebar regions. Empty for classic themes.', 'mcp-site-manager'),
                'input_schema'=> S::object([
                    'area'            => S::str('Filter by area slug (header, footer, sidebar, uncategorized).'),
                    'include_content' => S::bool('Include each part\'s block-markup content (default false).'),
                ]),
                'permission_callback' => self::require_cap('edit_theme_options'),
                'execute' => fn(array $a) => $this->templates_list($a, 'wp_template_part'),
            ],
            'global-styles-get' => [
                'label'       => __('Get theme.json design tokens', 'mcp-site-manager'),
                'description' => __('Merged theme.json settings: color palette, font sizes, font families, spacing scale, layout sizes. The design vocabulary an AI client should use when generating block markup.', 'mcp-site-manager'),
                'input_schema'=> S::object([]),
                'permission_callback' => self::require_cap('edit_theme_options'),
                'execute' => fn() => $this->global_styles(),
            ],
        ];
    }

    private function blocks_list(array $a): array
    {
        $registry = \WP_Block_Type_Registry::get_instance();
        $all = $registry->get_all_registered();

        $category = isset($a['category']) ? (string) $a['category'] : '';
        $search   = isset($a['search']) ? strtolower(trim((string) $a['search'])) : '';
        $verbose  = !empty($a['verbose']);

        $items = [];
        foreach ($all as $name => $type) {
            if ($category !== '' && (string) ($type->category ?? '') !== $category) continue;
            if ($search !== '') {
                $hay = strtolower((string) $name . ' ' . (string) ($type->title ?? ''));
                if (strpos($hay, $search) === false) continue;
            }
            $items[] = $verbose ? $this->block_full($type) : $this->block_short($type);
        }

        usort($items, static fn($x, $y) => strcmp((string) $x['name'], (string) $y['name']));

        return ['items' => $items, 'total' => count($items)];
    }

    private function blocks_get(array $a)
    {
        $name = (string) ($a['name'] ?? '');
        if ($name === '') {
            return new \WP_Error('mcpsm_block_name_required', 'name is required', ['status' => 400]);
        }
        $type = \WP_Block_Type_Registry::get_instance()->get_registered($name);
        if (!$type) {
            return new \WP_Error('mcpsm_block_not_found', "Block type '$name' is not registered", ['status' => 404]);
        }
        return $this->block_full($type);
    }

    private function block_short(\WP_Block_Type $t): array
    {
        $supports = (array) ($t->supports ?? []);
        $attrs    = (array) ($t->attributes ?? []);
        $vars     = is_array($t->variations ?? null) ? $t->variations : [];
        return [
            'name'             => (string) $t->name,
            'title'            => (string) ($t->title ?? ''),
            'category'         => (string) ($t->category ?? ''),
            'description'      => (string) ($t->description ?? ''),
            'keywords'         => array_values(array_map('strval', (array) ($t->keywords ?? []))),
            'parent'           => is_array($t->parent ?? null) ? array_values($t->parent) : null,
            'ancestor'         => is_array($t->ancestor ?? null) ? array_values($t->ancestor) : null,
            'attribute_count'  => count($attrs),
            'variation_count'  => count($vars),
            'supports_keys'    => array_values(array_keys($supports)),
            'textdomain'       => (string) ($t->textdomain ?? ''),
            'api_version'      => (int) ($t->api_version ?? 1),
            'is_dynamic'       => (bool) ($t->render_callback ?? false),
        ];
    }

    private function block_full(\WP_Block_Type $t): array
    {
        $short = $this->block_short($t);
        return $short + [
            'attributes'       => (array) ($t->attributes ?? []),
            'supports'         => (array) ($t->supports ?? []),
            'variations'       => is_array($t->variations ?? null) ? $t->variations : [],
            'styles'           => is_array($t->styles ?? null) ? $t->styles : [],
            'example'          => is_array($t->example ?? null) ? $t->example : null,
            'uses_context'     => array_values(array_map('strval', (array) ($t->uses_context ?? []))),
            'provides_context' => (array) ($t->provides_context ?? []),
            'icon'             => is_string($t->icon ?? null) ? $t->icon : null,
        ];
    }

    private function block_categories(): array
    {
        $cats = function_exists('get_default_block_categories') ? get_default_block_categories() : [];
        $items = [];
        foreach ((array) $cats as $c) {
            $items[] = [
                'slug'  => (string) ($c['slug'] ?? ''),
                'title' => (string) ($c['title'] ?? ''),
                'icon'  => is_string($c['icon'] ?? null) ? $c['icon'] : null,
            ];
        }
        return ['items' => $items, 'total' => count($items)];
    }

    private function patterns_list(array $a): array
    {
        if (!class_exists('\\WP_Block_Patterns_Registry')) {
            return ['items' => [], 'total' => 0];
        }
        $all = \WP_Block_Patterns_Registry::get_instance()->get_all_registered();

        $category   = isset($a['category']) ? (string) $a['category'] : '';
        $block_type = isset($a['block_type']) ? (string) $a['block_type'] : '';
        $search     = isset($a['search']) ? strtolower(trim((string) $a['search'])) : '';
        $with_body  = !empty($a['include_content']);

        $items = [];
        foreach ((array) $all as $p) {
            $cats = array_values(array_map('strval', (array) ($p['categories'] ?? [])));
            $btypes = array_values(array_map('strval', (array) ($p['blockTypes'] ?? [])));
            if ($category !== '' && !in_array($category, $cats, true)) continue;
            if ($block_type !== '' && !in_array($block_type, $btypes, true)) continue;
            if ($search !== '') {
                $hay = strtolower((string) ($p['name'] ?? '') . ' ' . (string) ($p['title'] ?? '') . ' ' . (string) ($p['description'] ?? ''));
                if (strpos($hay, $search) === false) continue;
            }
            $row = [
                'name'           => (string) ($p['name'] ?? ''),
                'title'          => (string) ($p['title'] ?? ''),
                'description'    => (string) ($p['description'] ?? ''),
                'categories'     => $cats,
                'block_types'    => $btypes,
                'keywords'       => array_values(array_map('strval', (array) ($p['keywords'] ?? []))),
                'viewport_width' => isset($p['viewportWidth']) ? (int) $p['viewportWidth'] : null,
                'source'         => isset($p['source']) ? (string) $p['source'] : null,
                'inserter'       => array_key_exists('inserter', (array) $p) ? (bool) $p['inserter'] : true,
            ];
            if ($with_body) {
                $row['content'] = (string) ($p['content'] ?? '');
            }
            $items[] = $row;
        }

        usort($items, static fn($x, $y) => strcmp((string) $x['name'], (string) $y['name']));

        return ['items' => $items, 'total' => count($items)];
    }

    private function pattern_categories(): array
    {
        if (!class_exists('\\WP_Block_Pattern_Categories_Registry')) {
            return ['items' => [], 'total' => 0];
        }
        $all = \WP_Block_Pattern_Categories_Registry::get_instance()->get_all_registered();
        $items = [];
        foreach ((array) $all as $c) {
            $items[] = [
                'name'  => (string) ($c['name'] ?? ''),
                'label' => (string) ($c['label'] ?? ''),
            ];
        }
        return ['items' => $items, 'total' => count($items)];
    }

    /**
     * @param 'wp_template'|'wp_template_part' $type
     */
    private function templates_list(array $a, string $type): array
    {
        $is_block = function_exists('wp_is_block_theme') ? wp_is_block_theme() : false;
        if (!$is_block || !function_exists('get_block_templates')) {
            return ['items' => [], 'total' => 0, 'is_block_theme' => $is_block];
        }

        $query = [];
        if ($type === 'wp_template_part' && !empty($a['area'])) {
            $query['area'] = (string) $a['area'];
        }
        $templates = get_block_templates($query, $type);
        $with_body = !empty($a['include_content']);

        $items = [];
        foreach ((array) $templates as $tpl) {
            $row = [
                'id'             => (string) ($tpl->id ?? ''),
                'slug'           => (string) ($tpl->slug ?? ''),
                'theme'          => (string) ($tpl->theme ?? ''),
                'title'          => is_object($tpl->title ?? null)
                    ? (string) ($tpl->title->rendered ?? '')
                    : (string) ($tpl->title ?? ''),
                'description'    => (string) ($tpl->description ?? ''),
                'status'         => (string) ($tpl->status ?? ''),
                'source'         => (string) ($tpl->source ?? ''),
                'origin'         => isset($tpl->origin) ? (string) $tpl->origin : null,
                'has_theme_file' => (bool) ($tpl->has_theme_file ?? false),
                'is_custom'      => (bool) ($tpl->is_custom ?? false),
                'type'           => (string) ($tpl->type ?? $type),
                'area'           => isset($tpl->area) ? (string) $tpl->area : null,
                'wp_id'          => isset($tpl->wp_id) ? (int) $tpl->wp_id : null,
            ];
            if ($with_body) {
                $row['content'] = (string) ($tpl->content ?? '');
            }
            $items[] = $row;
        }

        return ['items' => $items, 'total' => count($items), 'is_block_theme' => true];
    }

    private function global_styles(): array
    {
        $is_block = function_exists('wp_is_block_theme') ? wp_is_block_theme() : false;
        $has_settings = function_exists('wp_get_global_settings');
        $has_styles   = function_exists('wp_get_global_styles');

        $settings = $has_settings ? (array) wp_get_global_settings() : [];
        $styles   = $has_styles   ? (array) wp_get_global_styles()   : [];

        $palette  = (array) ($settings['color']['palette']      ?? []);
        $gradients = (array) ($settings['color']['gradients']   ?? []);
        $duotone  = (array) ($settings['color']['duotone']      ?? []);
        $font_sizes    = (array) ($settings['typography']['fontSizes']    ?? []);
        $font_families = (array) ($settings['typography']['fontFamilies'] ?? []);
        $spacing_sizes = (array) ($settings['spacing']['spacingSizes']    ?? []);

        return [
            'is_block_theme'    => (bool) $is_block,
            'color' => [
                'palette'   => $this->flatten_tokens($palette),
                'gradients' => $this->flatten_tokens($gradients),
                'duotone'   => $duotone,
                'background' => (bool) ($settings['color']['background'] ?? true),
                'text'       => (bool) ($settings['color']['text'] ?? true),
                'link'       => (bool) ($settings['color']['link'] ?? true),
            ],
            'typography' => [
                'font_sizes'    => $this->flatten_tokens($font_sizes),
                'font_families' => $this->flatten_tokens($font_families),
                'custom_font_size' => (bool) ($settings['typography']['customFontSize'] ?? true),
                'line_height'      => (bool) ($settings['typography']['lineHeight'] ?? false),
                'drop_cap'         => (bool) ($settings['typography']['dropCap'] ?? true),
            ],
            'spacing' => [
                'spacing_sizes' => $this->flatten_tokens($spacing_sizes),
                'padding'       => (bool) ($settings['spacing']['padding'] ?? false),
                'margin'        => (bool) ($settings['spacing']['margin'] ?? false),
                'block_gap'     => (bool) ($settings['spacing']['blockGap'] ?? false),
            ],
            'layout' => [
                'content_size' => (string) ($settings['layout']['contentSize'] ?? ''),
                'wide_size'    => (string) ($settings['layout']['wideSize']    ?? ''),
            ],
            'styles_snapshot' => [
                'color_text'       => $styles['color']['text']       ?? null,
                'color_background' => $styles['color']['background'] ?? null,
                'typography'       => $styles['typography']          ?? null,
            ],
        ];
    }

    /**
     * theme.json token arrays look like [{slug, name, color|size|fontSize|...}].
     * Normalize to a flat shape an MCP client can scan in one pass.
     *
     * @param array<int|string, mixed> $tokens
     */
    private function flatten_tokens(array $tokens): array
    {
        $out = [];
        foreach ($tokens as $t) {
            if (!is_array($t)) continue;
            $row = [
                'slug' => (string) ($t['slug'] ?? ''),
                'name' => (string) ($t['name'] ?? ''),
            ];
            foreach (['color', 'gradient', 'size', 'fontSize', 'fontFamily', 'fontWeight', 'lineHeight', 'fontStyle'] as $k) {
                if (isset($t[$k])) {
                    $row[$k] = is_scalar($t[$k]) ? (string) $t[$k] : $t[$k];
                }
            }
            $out[] = $row;
        }
        return $out;
    }
}
