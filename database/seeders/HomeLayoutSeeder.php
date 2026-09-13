<?php

namespace Database\Seeders;

use App\Models\HomeLayout;
use App\Models\Language;
use App\Services\LanguageService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Seeds one default home layout so a freshly installed system shows something
 * before any layout is built. It is a `single`, `global`, published layout —
 * the global scope makes it the customer app's fallback.
 */
class HomeLayoutSeeder extends Seeder
{
    public function run(): void
    {
        $langId = (string) ((new LanguageService())->getDefaultLanguage()?->id
            ?: (Language::where('system_type', 4)->value('id') ?: 1));

        $sections = [
            $this->section('text_section', [
                'section_title'    => [$langId => 'Welcome'],
                'section_subtitle' => [$langId => 'Discover our products'],
                'text_align'       => 'center',
            ]),
            $this->section('product_slider', [
                'data_source'   => 'top_selling',
                'limit'         => 10,
                'variant'       => 'default',
                'section_title' => [$langId => 'Popular Products'],
            ]),
            $this->section('product_slider', [
                'data_source'   => 'new_arrivals',
                'limit'         => 10,
                'variant'       => 'default',
                'section_title' => [$langId => 'New Arrivals'],
            ]),
            $this->section('product_slider', [
                'data_source'   => 'recently_visited',
                'limit'         => 10,
                'variant'       => 'default',
                'section_title' => [$langId => 'Recently Visited'],
            ]),
        ];

        $layout = ['sections' => $sections];

        // One global, single, published layout per channel (quick / ecommerce).
        // Idempotent per mode so re-running won't duplicate.
        $modes = [
            'quick'     => 'Default Home Layout (Quick)',
            'ecommerce' => 'Default Home Layout (eCommerce)',
        ];
        foreach ($modes as $mode => $name) {
            if (HomeLayout::where('zone_scope', 'global')->where('mode', $mode)->exists()) {
                continue;
            }
            HomeLayout::create([
                'name'                   => $name,
                'status'                 => 'published',
                'mode'                   => $mode,
                'home_type'              => 'single',
                'zone_scope'             => 'global',
                'category_scope'         => 'same',
                'channel_label'          => [$langId => $mode === 'quick' ? 'Quick' : 'Shop All'],
                'draft_json'             => $layout,
                'published_json'         => $layout,
                'is_active'              => 1,
                'published_at'           => now(),
            ]);
        }
    }

    private function section(string $type, array $configOverrides = []): array
    {
        return [
            'id'            => 'sec-' . Str::random(8),
            'type'          => $type,
            'margin_top'    => 0,
            'margin_bottom' => 0,
            'border_radius' => 0,
            'blocks'        => [$this->block($type, $configOverrides)],
        ];
    }

    private function block(string $type, array $configOverrides = []): array
    {
        $layout = 'horizontal';

        $triNum = fn ($a, $w, $t) => ['app' => $a, 'web' => $w, 'tablet' => $t];

        $config = array_merge([
            'data_source'        => 'manual',
            'limit'              => 10,
            'category_ids'       => [],
            'category_id'        => '',
            'brand_ids'          => [],
            'manual_product_ids' => [],
            'variant'            => 'default',
            'section_title'      => [],
            'section_subtitle'   => [],
            'text_align'         => 'left',
            'text_color'         => '',
            'background_color'   => '',
            'background_image'   => ['app' => '', 'web' => '', 'tablet' => ''],
            'image_aspect'       => ['app' => '16:9', 'web' => '3:1', 'tablet' => '16:9'],
            // Per-platform grid columns (category / brand / product grid / grid_banner).
            'grid_columns'       => $triNum(2, 4, 3),
            'auto_scroll'        => true,
            'speed_ms'           => 3000,
            'infinite_loop'      => true,
            'indicator'          => 'dots',
            'carousel_style'     => 'full_width',
            'block_padding'      => 0,
            'block_radius'       => 0,
            'grid_gap'           => $triNum(8, 8, 8),   // grid_banner gap
            'tile_radius'        => 6,
            'category_gap'       => $triNum(8, 8, 8),
            'category_radius'    => $triNum(12, 12, 12),
            'brand_gap'          => $triNum(8, 8, 8),
            'product_grid_gap'   => 8,
            'product_card_radius' => 0,
            'show_name'          => true,
            'redirect_type'      => 'none',
            'redirect_id'        => null,
            'redirect_url'       => '',
        ], $configOverrides);

        return [
            'id'     => 'blk-' . Str::random(8),
            'type'   => $type,
            'layout' => $layout,
            'image'  => ['app' => '', 'web' => '', 'tablet' => ''],
            'config' => $config,
            'items'  => [],
        ];
    }
}
