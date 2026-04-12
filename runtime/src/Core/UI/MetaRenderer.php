<?php

declare(strict_types=1);

namespace WPApps\Runtime\Core\UI;

/**
 * Renders app-written post meta in wp_head.
 *
 * Apps write data to post meta via the API (e.g., SEO title, description,
 * schema markup). This class reads that meta and outputs appropriate HTML
 * tags in wp_head. Zero HTTP calls — pure data read from WordPress DB.
 *
 * This is the data-first alternative to runtime filters on wp_head.
 */
class MetaRenderer
{
    /**
     * Known meta key suffixes that trigger automatic rendering.
     * Apps write to {app_prefix}_{suffix}, the renderer outputs the right HTML.
     */
    private const RENDERABLE_META = [
        'seo_title' => 'title',
        'seo_description' => 'meta_description',
        'schema_json' => 'json_ld',
        'og_title' => 'og',
        'og_description' => 'og',
        'og_image' => 'og',
        'canonical_url' => 'link_canonical',
        'robots' => 'meta_robots',
    ];

    public function register(): void
    {
        add_action('wp_head', [$this, 'renderHead'], 1);
        add_filter('document_title_parts', [$this, 'filterTitle'], 99);
    }

    /**
     * Output app-written meta tags in wp_head.
     */
    public function renderHead(): void
    {
        if (!is_singular()) {
            return;
        }

        global $post;
        if (!$post) {
            return;
        }

        $allMeta = get_post_meta($post->ID);
        $rendered = [];

        foreach ($allMeta as $key => $values) {
            // Only process app-namespaced meta (starts with _)
            if (!str_starts_with($key, '_')) {
                continue;
            }

            $value = $values[0] ?? '';
            if ($value === '') {
                continue;
            }

            $value = maybe_unserialize($value);

            // Check each known suffix
            foreach (self::RENDERABLE_META as $suffix => $renderType) {
                if (!str_ends_with($key, "_{$suffix}")) {
                    continue;
                }

                // Avoid duplicate rendering for same type from different apps
                if (isset($rendered[$suffix])) {
                    continue;
                }

                $this->renderMeta($renderType, $suffix, $value);
                $rendered[$suffix] = true;
            }
        }
    }

    /**
     * Override document title if any app has written an seo_title meta.
     */
    public function filterTitle(array $titleParts): array
    {
        if (!is_singular()) {
            return $titleParts;
        }

        global $post;
        if (!$post) {
            return $titleParts;
        }

        $allMeta = get_post_meta($post->ID);

        foreach ($allMeta as $key => $values) {
            if (str_starts_with($key, '_') && str_ends_with($key, '_seo_title')) {
                $seoTitle = $values[0] ?? '';
                if ($seoTitle !== '') {
                    $titleParts['title'] = $seoTitle;
                    break; // First app's title wins
                }
            }
        }

        return $titleParts;
    }

    private function renderMeta(string $type, string $suffix, mixed $value): void
    {
        switch ($type) {
            case 'meta_description':
                printf(
                    '<meta name="description" content="%s" />' . "\n",
                    esc_attr((string) $value)
                );
                break;

            case 'json_ld':
                $json = is_string($value) ? $value : wp_json_encode($value);
                if ($json && json_decode($json) !== null) {
                    printf(
                        '<script type="application/ld+json">%s</script>' . "\n",
                        $json // Already JSON — don't double-encode
                    );
                }
                break;

            case 'og':
                $ogProperty = match ($suffix) {
                    'og_title' => 'og:title',
                    'og_description' => 'og:description',
                    'og_image' => 'og:image',
                    default => null,
                };
                if ($ogProperty) {
                    printf(
                        '<meta property="%s" content="%s" />' . "\n",
                        esc_attr($ogProperty),
                        esc_attr((string) $value)
                    );
                }
                break;

            case 'link_canonical':
                printf(
                    '<link rel="canonical" href="%s" />' . "\n",
                    esc_url((string) $value)
                );
                break;

            case 'meta_robots':
                printf(
                    '<meta name="robots" content="%s" />' . "\n",
                    esc_attr((string) $value)
                );
                break;

            // 'title' is handled by filterTitle(), not here
        }
    }
}
