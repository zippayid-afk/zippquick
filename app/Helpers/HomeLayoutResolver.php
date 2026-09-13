<?php

namespace App\Helpers;

use App\Helpers\CustomerProductShaper;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Favorite;
use App\Models\HomeLayout;
use App\Models\Product;
use App\Models\RecentlyVisitedProduct;
use App\Services\LanguageService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Resolves the correct published Home Builder layout for a customer request
 * (by zone + channel + category) and populates each section/block with real
 * product / category / banner data ready for the app/web to render.
 */
class HomeLayoutResolver
{

    private int $defaultLangId;
    private ?int $langId;
    /** @var int[] */
    private array $storeIds = [];
    private ?int $userId = null;
    /** @var int[] */
    private array $favoriteIds = [];
    // Zone delivery info — same for every product in the zone (one zone == one store).
    private ?string $storeName = null;
    private int $timeToDeliver = 0;
    // Zone currency — same for every product in the zone.
    private ?array $currency = null;
    /** @var array<int,string> redirect_id => product slug (per-request memo) */
    private array $productSlugCache = [];
    /** @var array<int,string> redirect_id => category slug (per-request memo) */
    private array $categorySlugCache = [];
    /** @var array<int,bool> category id => has child categories (per-request memo) */
    private array $categoryHasChildCache = [];

    public function __construct()
    {
        $defaultLang = (new LanguageService())->getDefaultLanguage();
        $this->defaultLangId = $defaultLang ? (int) $defaultLang->id : 0;
        $this->langId = LanguageService::getCurrentId() ?: $this->defaultLangId;
    }

    /**
     * @param int[] $storeIds zone-scoped store ids (drives PVSS listing + product visibility).
     * @return array{layout: array, home_type: string, category_tabs: array}|null
     */
    public function resolve(string $channel, ?int $zoneId, $categoryId, string $device, array $storeIds = [], ?int $userId = null, ?string $storeName = null, int $timeToDeliver = 0, ?array $currency = null): ?array
    {
        $this->storeIds = array_values(array_filter(array_map('intval', $storeIds)));
        $this->userId    = $userId;
        $this->storeName     = $storeName;
        $this->timeToDeliver = $timeToDeliver;
        $this->currency      = $currency;

        $layout = $this->pickLayout($channel, $zoneId);
        if (!$layout) {
            return null;
        }

        $categoryTabs = [];
        if ($layout->home_type === 'category_wise') {
            $config = $this->pickCategoryLayout($layout, $channel, $categoryId, $categoryTabs, $device);
        } else {
            $config = $layout->published_json ?: ['sections' => []];
            $bg = $config['background_' . $channel] ?? null;
            if (is_array($bg)) {
                $config['background_theme']     = $bg['theme'] ?? 'color';
                $config['background_color']     = $bg['color'] ?? '#ffffffff';
                $config['background_image_url'] = $this->pickImage($bg['image_url'] ?? '', $device);
                $config['text_color']           = $bg['text_color'] ?? '#000000';
            }
        }

        $populated = $this->populate($config, $channel, $device);

        // Stored image values are storage-relative paths; serve absolute URLs.
        $populated = self::absolutizeImageUrls($populated);
        $categoryTabs = self::absolutizeImageUrls($categoryTabs);

        $result = [
            'layout'        => $populated,
            'home_type'     => $layout->home_type,
            'home_layout_name' => $layout->name,
            'category_tabs' => $categoryTabs,
            'layout_mode'   => $layout->mode, // quick | ecommerce
        ];

        return $result;
    }

    /** Resolve the button label of a channel's layout for a location (flattened). */
    public function channelLabel(string $channel, ?int $zoneId): string
    {
        $layout = $this->pickLayout($channel, $zoneId);
        return $layout ? $this->labelOf($layout) : self::defaultChannelLabel($channel);
    }

    /** Layout's button label, falling back to the channel default. */
    private function labelOf(HomeLayout $layout): string
    {
        $label = $this->flattenText($layout->channel_label ?? []);
        return $label !== '' ? $label : self::defaultChannelLabel($layout->mode);
    }

    /** Default button label per channel when none is set. */
    private static function defaultChannelLabel(string $channel): string
    {
        return $channel === 'ecommerce' ? 'Shop All' : 'Quick';
    }

    /**
     * Most-specific-wins: a zone-scoped layout containing $zoneId beats a global
     * one; ties broken by latest published_at.
     */
    private function pickLayout(string $channel, ?int $zoneId): ?HomeLayout
    {
        if ($zoneId) {
            $zoneLayout = HomeLayout::where('status', 'published')
                ->where('is_active', 1)
                ->where('mode', $channel)
                ->where('zone_scope', 'zone')
                ->where('zone_id', $zoneId)
                ->orderByDesc('published_at')
                ->first();
            if ($zoneLayout) {
                return $zoneLayout;
            }
        }

        // Fallback: the global layout for this channel.
        return HomeLayout::where('status', 'published')
            ->where('is_active', 1)
            ->where('mode', $channel)
            ->where('zone_scope', 'global')
            ->orderByDesc('published_at')
            ->first();
    }

    /**
     * Pulls the per-category LayoutConfig (handling the same/split storage shape)
     * and fills $categoryTabs with the tab list for the rail.
     */
    /** Category of the tab being returned; null on the "All" tab or a single layout. */
    private ?int $activeCategoryId = null;

    /** Cache of root category id => its own id + every descendant id. */
    private static array $subtreeCache = [];

    /**
     * A category id + every active descendant id. Products hang off whichever category
     * they were filed under — a parent for some, a leaf for others — so scoping a parent
     * to an exact category_id would hide everything filed deeper.
     */
    public static function categorySubtreeIds(int $rootId): array
    {
        if (isset(self::$subtreeCache[$rootId])) {
            return self::$subtreeCache[$rootId];
        }

        // The tree is small; one fetch beats a recursive query per level.
        $childrenOf = [];
        foreach (Category::where('status', 1)->get(['id', 'parent_id']) as $c) {
            $childrenOf[(int) $c->parent_id][] = (int) $c->id;
        }

        $ids = [$rootId];
        $queue = [$rootId];
        while ($queue) {
            foreach ($childrenOf[array_shift($queue)] ?? [] as $kid) {
                if (!in_array($kid, $ids, true)) {
                    $ids[] = $kid;
                    $queue[] = $kid;
                }
            }
        }

        return self::$subtreeCache[$rootId] = $ids;
    }

    private function pickCategoryLayout(HomeLayout $layout, string $channel, $categoryId, array &$categoryTabs, string $device = 'app'): array
    {
        $split = $layout->category_scope === 'split';
        $maps = $layout->category_layouts_published ?: [];

        if ($split) {
            $maps = $maps[$channel] ?? [];
            $catIds = $channel === 'quick'
                ? ($layout->category_ids_quick ?: [])
                : ($layout->category_ids_ecommerce ?: []);
        } else {
            $catIds = $layout->category_ids ?: [];
        }

        $tabsDef = (!$split) ? ($layout->category_tabs_published ?: []) : [];

        $bgFrom = fn ($cfg) => [
            'background_theme'     => $cfg['background_theme'] ?? 'color',
            'background_color'     => $cfg['background_color'] ?? '#ffffffff',
            'background_image_url' => $this->pickImage($cfg['background_image_url'] ?? '', $device),
            'text_color'           => $cfg['text_color'] ?? '#000000',
            'header_icon_url'      => $cfg['header_icon_url'] ?? '',
        ];
        // Multilang tab name → the request-locale string.
        $pickName = function ($name) {
            if (is_array($name)) {
                $loc = app()->getLocale();
                return (string) ($name[$loc] ?? (reset($name) ?: ''));
            }
            return (string) $name;
        };

        $orderedKeys = [];

        if (!empty($tabsDef)) {
            foreach ($tabsDef as $tab) {
                $kind = $tab['kind'] ?? 'system';
                if ($kind === 'custom') {
                    $key = (string) ($tab['key'] ?? '');
                    if ($key === '') {
                        continue;
                    }
                    $categoryTabs[] = array_merge([
                        'id'        => $key,               // string key → no numeric category restriction
                        'name'      => $pickName($tab['name'] ?? ''),
                        'image_url' => $this->pickImage($tab['icon_url'] ?? '', $device),
                    ], $bgFrom($maps[$key] ?? []));
                    $orderedKeys[] = $key;
                } else { // system (any legacy 'all' tab is dropped)
                    $catId = (int) ($tab['category_id'] ?? 0);
                    $cat = $catId ? Category::where('id', $catId)->where('status', 1)->first() : null;
                    if (!$cat) {
                        continue;
                    }
                    $categoryTabs[] = array_merge([
                        'id'        => (string) $cat->id,   // string, matching custom tab ids
                        'name'      => $cat->name,
                        'image_url' => $cat->image_url,
                        'slug'      => $cat->slug,
                    ], $bgFrom($maps[(string) $cat->id] ?? []));
                    $orderedKeys[] = (string) $cat->id;
                }
            }
        } else {
            // Legacy: system categories in the categories-table order.
            $orderedCats = Category::whereIn('id', $catIds)->where('status', 1)
                ->orderBy('row_order')->orderBy('id')->get();
            foreach ($orderedCats as $cat) {
                $categoryTabs[] = array_merge([
                    'id'        => (string) $cat->id,
                    'name'      => $cat->name,
                    'image_url' => $cat->image_url,
                    'slug'      => $cat->slug,
                ], $bgFrom($maps[(string) $cat->id] ?? []));
                $orderedKeys[] = (string) $cat->id;
            }
        }

        // Resolve which tab's layout to return (default = first tab).
        $reqKey = ($categoryId !== null && $categoryId !== '') ? (string) $categoryId : '';
        $key = $reqKey !== '' ? $reqKey : (string) ($orderedKeys[0] ?? '');


        $this->activeCategoryId = ((int) $key > 0 && (string) (int) $key === $key) ? (int) $key : null;

        $config = $maps[$key] ?? null;
        return is_array($config) && isset($config['sections']) ? $config : ['sections' => []];
    }

    /* ---------------------------------------------------------------- */

    private function populate(array $config, string $channel, string $device): array
    {
        $sections = [];
        foreach ($config['sections'] ?? [] as $section) {
            if (!filter_var($section['active'] ?? true, FILTER_VALIDATE_BOOLEAN)) {
                continue;
            }
            $blocks = $section['blocks'] ?? [];
            foreach ($blocks as &$block) {
                $this->populateBlock($block, $channel, $device);
            }
            unset($block);

            // Keep only the section root keys the client renders.
            $sections[] = [
                'id'            => $section['id'] ?? null,
                'type'          => $section['type'] ?? '',
                'margin_top'    => $section['margin_top'] ?? 0,
                'margin_bottom' => $section['margin_bottom'] ?? 0,
                'border_radius' => $section['border_radius'] ?? 0,
                'blocks'        => $blocks,
            ];
        }

        // Clean layout root: sections + flattened background only (drop the raw
        // per-channel background_quick / background_ecommerce objects).
        return [
            'sections'             => $sections,
            'background_theme'     => $config['background_theme'] ?? 'color',
            'background_color'     => $config['background_color'] ?? '#ffffffff',
            'background_image_url' => $this->pickImage($config['background_image_url'] ?? '', $device),
            'text_color'           => $config['text_color'] ?? '#000000',
            'header_icon_url'      => $config['header_icon_url'] ?? '',
        ];
    }

    /**
     * Config keys that are meaningful per block type. Everything else in the stored
     * config (the editor saves one fat config object for all types) is dropped from
     * the API response so app/web only see params relevant to the block they render.
     * Source-id lists (manual_product_ids / category_id / brand_ids / category_ids)
     * are intentionally excluded — the resolved products/categories/brands replace them.
     */
    private const CONFIG_KEYS = [
        'banner_slider'    => ['carousel_style', 'indicator', 'auto_scroll', 'infinite_loop', 'speed_ms', 'image_aspect'],
        'grid_banner'      => ['variant', 'section_title', 'text_color', 'background_color', 'background_image_url', 'bg_image_aspect', 'block_padding', 'grid_columns', 'grid_gap', 'grid_rows', 'grid_layout_type', 'tile_radius', 'image_aspect'],
        'category_section' => ['variant', 'text_color', 'item_text_color', 'background_color', 'background_image_url', 'bg_image_aspect', 'grid_columns', 'category_gap', 'category_radius', 'section_title'],
        'product_slider'   => ['variant', 'section_title', 'data_source', 'limit', 'grid_columns', 'product_grid_gap', 'product_card_radius', 'block_padding', 'background_image_url', 'background_color', 'text_color', 'image_aspect'],
        'brand_section'    => ['variant', 'text_color', 'item_text_color', 'background_color', 'background_image_url', 'bg_image_aspect', 'grid_columns', 'brand_gap', 'brand_radius', 'show_name', 'section_title'],
        'text_section'     => ['text_align', 'text_color', 'background_color', 'section_title', 'section_subtitle'],
        'title_image'      => ['image_aspect'],
    ];

    /** Config keys stored as {app,web,tablet} numeric maps — flattened to the request device. */
    private const DEVICE_FLATTEN_KEYS = ['grid_columns', 'grid_gap', 'grid_rows', 'category_gap', 'category_radius', 'brand_gap', 'brand_radius'];

    /** Per-device fallback for flatten keys left blank in the builder. */
    private const DEVICE_DEFAULTS = [
        'grid_rows'    => ['app' => 1, 'tablet' => 1, 'web' => 1],
    ];

    /** Per-platform default image aspect ratio ("w:h") when none is set. */
    private const IMAGE_ASPECT_DEFAULTS = ['app' => '16:9', 'tablet' => '16:9', 'web' => '3:1'];

    /** Block types whose `layout` is editable in the builder (others have no layout UI). */
    private const LAYOUT_TYPES = ['category_section', 'product_slider', 'brand_section'];

    private function populateBlock(array &$block, string $channel, string $device): void
    {
        $type = $block['type'] ?? '';
        $cfg = is_array($block['config'] ?? null) ? $block['config'] : [];

        // Flatten translatable text fields.
        foreach (['section_title', 'section_subtitle'] as $tk) {
            if (isset($cfg[$tk])) {
                $cfg[$tk] = $this->flattenText($cfg[$tk]);
            }
        }
        // Resolve any background image carried in config.
        if (isset($cfg['background_image'])) {
            $cfg['background_image_url'] = $this->pickImage($cfg['background_image'], $device);
        }
        // Image aspect ratio ("w:h"): flatten the per-platform map to this device.
        // The app/web derives height = renderedWidth / ratio, matching the preview
        // at any screen width.
        if (in_array($type, ['banner_slider', 'grid_banner', 'title_image', 'product_slider'], true)) {
            $cfg['image_aspect'] = $this->pickAspect($cfg['image_aspect'] ?? null, $device);
        }

        if (in_array($type, ['grid_banner', 'category_section', 'brand_section'], true)) {
            $cfg['bg_image_aspect'] = $this->pickAspect($cfg['bg_image_aspect'] ?? null, $device);
        }

        // Trim config down to the keys this block type actually uses.
        $allowed = self::CONFIG_KEYS[$type] ?? [];
        $cleanCfg = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $cfg)) {
                $cleanCfg[$key] = $cfg[$key];
            }
        }
        // Flatten per-platform {app,web,tablet} maps to the requested device value.
        foreach (self::DEVICE_FLATTEN_KEYS as $dk) {
            if (isset($cleanCfg[$dk]) && is_array($cleanCfg[$dk])) {
                $cleanCfg[$dk] = $this->pickDeviceNum($cleanCfg[$dk], $device, self::DEVICE_DEFAULTS[$dk] ?? null);
            }
        }

        // Rebuild a clean block: only the root keys this type needs.
        $clean = [
            'id'   => $block['id'] ?? null,
            'type' => $type,
        ];
        // layout only for types with a layout selector in the builder.
        if (in_array($type, self::LAYOUT_TYPES, true)) {
            $clean['layout'] = $block['layout'] ?? null;
        }
        // config only when this type actually has config fields.
        if (!empty($cleanCfg)) {
            $clean['config'] = $cleanCfg;
        }
        if (isset($block['caption'])) {
            $clean['caption'] = $this->flattenText($block['caption']);
        }

        if ($type === 'product_slider') {
            $resolved = $this->resolveProducts($cfg, $channel);
            $clean['products'] = $resolved['products'];
            $clean['viewMorePreviewImages'] = $resolved['viewMorePreviewImages'];
            // Echo back the source id(s) for the active data_source so the app
            // knows which selection drives a manual/category/brand slider.
            $source = $cfg['data_source'] ?? 'manual';
            if ($source === 'manual') {
                $clean['manual_product_ids'] = implode(',', array_map('intval', $cfg['manual_product_ids'] ?? []));
            } elseif ($source === 'category') {
                $clean['category_id'] = isset($cfg['category_id']) && $cfg['category_id'] !== '' ? (int) $cfg['category_id'] : null;
            } elseif ($source === 'brand') {
                $clean['brand_ids'] = implode(',', array_map('intval', $cfg['brand_ids'] ?? []));
            }
        } elseif ($type === 'category_section') {
            $clean['categories'] = $this->resolveCategories($cfg, $channel);
        } elseif ($type === 'brand_section') {
            $clean['brands'] = $this->resolveBrands($cfg);
        } elseif (in_array($type, ['banner_slider', 'grid_banner'], true)) {
            $clean['items'] = $this->resolveItems($block['items'] ?? [], $device);
        } elseif ($type === 'title_image') {
            $clean['image_url']     = $this->pickImage($block['image'] ?? [], $device);
            $clean['images']        = $this->deviceImages($block['image'] ?? []);
            $clean['redirect_type'] = $cfg['redirect_type'] ?? 'none';
            $clean['redirect_id']   = $cfg['redirect_id'] ?? null;
            $clean['redirect_slug'] = $this->resolveRedirectSlug($clean['redirect_type'], $clean['redirect_id']);
            $clean['has_child']     = $this->redirectHasChild($clean['redirect_type'], $clean['redirect_id']);
            $clean['redirect_url']  = $cfg['redirect_url'] ?? '';
        } elseif ($type === 'text_section') {
            $clean['redirect_type'] = $cfg['redirect_type'] ?? 'none';
            $clean['redirect_id']   = $cfg['redirect_id'] ?? null;
            $clean['redirect_slug'] = $this->resolveRedirectSlug($clean['redirect_type'], $clean['redirect_id']);
            $clean['has_child']     = $this->redirectHasChild($clean['redirect_type'], $clean['redirect_id']);
            $clean['redirect_url']  = $cfg['redirect_url'] ?? '';
        }

        $block = $clean;
    }

    private function resolveProducts(array $cfg, string $channel): array
    {
        $empty = ['products' => [], 'viewMorePreviewImages' => []];
        $limit = (int) ($cfg['limit'] ?? 10);
        $limit = $limit > 0 ? min($limit, 50) : 10;
        $source = $cfg['data_source'] ?? 'manual';

        $storeIds = $this->storeIds;
        $query = Product::query()
            ->where('status', 1)
            ->where('is_draft', 0)
            ->whereIn('sales_channel', [$channel, 'both']);

        // Same visibility gate as the customer products listing: at least one
        // variant must have a listed PVSS row in a zone store. Applied even with no
        // zone stores — an empty store list must show nothing, not everything.
        $query->whereExists(function ($q) use ($storeIds) {
            $q->select(DB::raw(1))
                ->from('product_variants as pv')
                ->join('product_variant_store_stocks as pvss', 'pvss.product_variant_id', '=', 'pv.id')
                ->whereColumn('pv.product_id', 'products.id')
                ->whereIn('pvss.store_id', $storeIds ?: [0])
                ->where('pvss.is_listed', 1);
        });

        $explicitSources = ['manual', 'category', 'brand'];
        if ($this->activeCategoryId && !in_array($source, $explicitSources, true)) {
            $query->whereIn('category_id', self::categorySubtreeIds($this->activeCategoryId));
        }

        if ($source === 'manual') {
            $ids = array_map('intval', $cfg['manual_product_ids'] ?? []);
            if (empty($ids)) {
                return $empty;
            }
            $query->whereIn('id', $ids)
                ->orderByRaw('FIELD(id,' . implode(',', $ids) . ')');
        } elseif ($source === 'category' && !empty($cfg['category_id'])) {
            $query->whereIn('category_id', self::categorySubtreeIds((int) $cfg['category_id']))
                ->orderByDesc('id');
        } elseif ($source === 'brand') {
            $brandIds = array_map('intval', $cfg['brand_ids'] ?? []);
            if (empty($brandIds)) {
                return $empty;
            }
            $query->whereIn('brand_id', $brandIds)->orderByDesc('id');
        } elseif ($source === 'new_arrivals') {
            $query->orderByDesc('id');
        } elseif ($source === 'best_rated') {
            if (Schema::hasColumn('products', 'rating')) {
                $query->orderByDesc('rating');
            } else {
                $query->orderByDesc('id');
            }
        } elseif (in_array($source, ['discounted', 'offer'], true)) {
            // Price is per-store (PVSS): "on discount" is evaluated in the zone's stores.
            $query->whereHas('variants.storeStocks', function ($q) use ($storeIds) {
                $q->whereIn('store_id', $storeIds ?: [0])->where('is_listed', 1)
                    ->whereColumn('discounted_price', '<', 'price')
                    ->where('discounted_price', '>', 0);
            })->orderByDesc('id');
        } elseif ($source === 'recently_visited') {
            $userId = $this->userId;
            if (!$userId) {
                return $empty;
            }
            $ids = RecentlyVisitedProduct::where('user_id', $userId)
                ->orderByDesc('visited_at')
                ->orderByDesc('created_at')
                ->limit($limit)
                ->pluck('product_id')
                ->all();
            if (empty($ids)) {
                return $empty;
            }
            $query->whereIn('id', $ids)
                ->orderByRaw('FIELD(id,' . implode(',', $ids) . ')');
        } elseif ($source === 'buy_again') {
            $userId = $this->userId;
            if (!$userId) {
                return $empty;
            }
            $ids = DB::table('order_items as oi')
                ->join('product_variants as pv', 'oi.product_variant_id', '=', 'pv.id')
                ->where('oi.user_id', $userId)
                ->orderByDesc('oi.created_at')
                ->pluck('pv.product_id')
                ->unique()
                ->values()
                ->all();
            if (empty($ids)) {
                return $empty;
            }
            $query->whereIn('id', $ids)
                ->orderByRaw('FIELD(id,' . implode(',', $ids) . ')');
        } elseif ($source === 'trending') {
            // Most ordered in the last 30 days (windowed top_selling).
            $query->orderByDesc(DB::raw('(
                SELECT COUNT(*) FROM order_items oi
                JOIN product_variants pv ON oi.product_variant_id = pv.id
                WHERE pv.product_id = products.id
                  AND oi.created_at >= (NOW() - INTERVAL 30 DAY)
            )'));
        } elseif ($source === 'most_favorited') {
            $query->orderByDesc(DB::raw('(
                SELECT COUNT(*) FROM favorites WHERE favorites.product_id = products.id
            )'));
        } else {
            // top_selling / fallback — newest as a safe default.
            $query->orderByDesc('id');
        }

        // Fetch limit + 3 so the overflow can drive a "view more" preview.
        $products = $query->with([
            'variants',
            'variants.images',
            'variants.storeStocks' => fn ($q) => $q->whereIn('store_id', $storeIds ?: [0]),
            'variants.attributeValues.attribute.translations',
            'variants.attributeValues.attributeValue.translations',
            'brand.translations',
            'category.translations',
            'tax',
            'ratings',
            'translations',
        ])->limit($limit + 3)->get();

        $this->loadFavorites($products->pluck('id')->all());

        $cards = $products->take($limit)
            ->map(fn (Product $p) => CustomerProductShaper::shapeCard($p, $this->favoriteIds, $this->timeToDeliver, $this->storeName, $this->currency))
            ->values()->all();

        // Up to 3 preview images from the products BEYOND the limit (the "view more" set).
        $viewMore = [];
        foreach ($products->slice($limit, 3) as $p) {
            $shaped = CustomerProductShaper::shapeCard($p, $this->favoriteIds, $this->timeToDeliver, $this->storeName, $this->currency);
            $img = $shaped['images'][0]['image_url'] ?? '';
            if ($img !== '') {
                $viewMore[] = $img;
            }
        }

        return ['products' => $cards, 'viewMorePreviewImages' => $viewMore];
    }

    private function loadFavorites(array $productIds): void
    {
        if (!$this->userId || empty($productIds)) {
            $this->favoriteIds = [];
            return;
        }
        $this->favoriteIds = Favorite::where('user_id', $this->userId)
            ->whereIn('product_id', $productIds)
            ->pluck('product_id')
            ->all();
    }

    private function resolveBrands(array $cfg): array
    {
        $ids = array_map('intval', $cfg['brand_ids'] ?? []);
        if (empty($ids)) {
            return [];
        }
        return Brand::whereIn('id', $ids)
            ->where('status', 1)
            ->orderByRaw('FIELD(id,' . implode(',', $ids) . ')')
            ->get()
            ->map(fn ($b) => [
                'id'        => $b->id,
                'name'      => $b->name,
                'image_url' => $b->image_url,
            ])->all();
    }

    private function resolveCategories(array $cfg, string $channel): array
    {
        $ids = array_map('intval', $cfg['category_ids'] ?? []);
        if (empty($ids)) {
            return [];
        }

        $withProducts = array_flip(CommonHelper::categoryIdsWithProducts($this->storeIds, $channel));
        $ids = array_values(array_filter($ids, fn ($id) => isset($withProducts[$id])));
        if (empty($ids)) {
            return [];
        }
        // Which of these categories have at least one active child (single query).
        $parentsWithChild = Category::whereIn('parent_id', $ids)
            ->where('status', 1)
            ->distinct()
            ->pluck('parent_id')
            ->map(fn ($v) => (int) $v)
            ->all();
        $parentsWithChild = array_flip($parentsWithChild);

        return Category::whereIn('id', $ids)
            ->where('status', 1)
            ->orderByRaw('FIELD(id,' . implode(',', $ids) . ')')
            ->get()
            ->map(fn ($c) => [
                'id'        => $c->id,
                'name'      => $c->name,
                'slug'      => $c->slug,
                'image_url' => $c->image_url,
                'has_child' => isset($parentsWithChild[(int) $c->id]),
            ])->all();
    }

    private function resolveItems(array $items, string $device): array
    {
        return array_values(array_map(fn ($item) => [
            // image_url = device-picked default; images = all three device variants.
            'image_url'     => $this->pickImage($item['image'] ?? [], $device),
            'images'        => $this->deviceImages($item['image'] ?? []),
            'redirect_type' => $item['redirect_type'] ?? 'none',
            'redirect_id'   => $item['redirect_id'] ?? null,
            'redirect_slug' => $this->resolveRedirectSlug($item['redirect_type'] ?? 'none', $item['redirect_id'] ?? null),
            'has_child'     => $this->redirectHasChild($item['redirect_type'] ?? 'none', $item['redirect_id'] ?? null),
            'redirect_url'  => $item['redirect_url'] ?? '',
        ], $items));
    }

    /**
     * Slug of the redirect target. Only product/category redirects carry a slug;
     * url (and none) return ''. Per-request memoized to avoid N+1 lookups.
     */
    private function resolveRedirectSlug($type, $id): string
    {
        if (empty($id) || !in_array($type, ['product', 'category'], true)) {
            return '';
        }
        $id = (int) $id;
        if ($type === 'product') {
            if (!array_key_exists($id, $this->productSlugCache)) {
                $this->productSlugCache[$id] = (string) (Product::where('id', $id)->value('slug') ?? '');
            }
            return $this->productSlugCache[$id];
        }
        if (!array_key_exists($id, $this->categorySlugCache)) {
            $this->categorySlugCache[$id] = (string) (Category::where('id', $id)->value('slug') ?? '');
        }
        return $this->categorySlugCache[$id];
    }

    private function redirectHasChild($type, $id): bool
    {
        if ($type !== 'category' || empty($id)) {
            return false;
        }
        $id = (int) $id;
        if (!array_key_exists($id, $this->categoryHasChildCache)) {
            $this->categoryHasChildCache[$id] = Category::where('parent_id', $id)
                ->where('status', 1)
                ->exists();
        }
        return $this->categoryHasChildCache[$id];
    }

    /**
     * Recursively convert storage-relative upload paths (home_builder/...) to
     * absolute URLs. Legacy absolute URLs pass through untouched.
     */
    public static function absolutizeImageUrls($value)
    {
        if (is_array($value)) {
            return array_map([self::class, 'absolutizeImageUrls'], $value);
        }
        if (is_string($value) && str_starts_with($value, 'home_builder/')) {
            return asset('storage/' . $value);
        }
        return $value;
    }

    /**
     * Returns all per-device image URLs (app / web / tablet) for a stored image map.
     */
    private function deviceImages($image): array
    {
        $image = is_array($image) ? $image : [];
        return [
            'app'    => (string) ($image['app'] ?? ''),
            'web'    => (string) ($image['web'] ?? ''),
            'tablet' => (string) ($image['tablet'] ?? ''),
        ];
    }

    /**
     * Resolve a {app,web,tablet} numeric map to the request device, with app->web->tablet
     * fallback. Returns an int, or null when nothing is set.
     */
    private function pickDeviceNum(array $map, string $device, ?array $defaults = null): ?int
    {
        // Per-device defaults (e.g. grid_rows) differ by device, so use the
        // device's own value or its default — no cross-device fallback.
        if ($defaults !== null) {
            $v = $map[$device] ?? null;
            if ($v !== '' && $v !== null && is_numeric($v)) {
                return (int) $v;
            }
            return $defaults[$device] ?? $defaults['app'] ?? null;
        }
        foreach ([$device, 'app', 'web', 'tablet'] as $key) {
            $v = $map[$key] ?? null;
            if ($v !== '' && $v !== null && is_numeric($v)) {
                return (int) $v;
            }
        }
        return null;
    }

    /**
     * Picks the device-specific image URL with app -> web fallback.
     */
    /**
     * Resolve a per-platform aspect map ({app,web,tablet} of "w:h") to the device,
     * or accept a single string. Falls back to the per-device default.
     */
    private function pickAspect($aspect, string $device): string
    {
        $default = self::IMAGE_ASPECT_DEFAULTS[$device] ?? '16:9';
        if (is_string($aspect) && str_contains($aspect, ':')) {
            return $aspect;
        }
        if (is_array($aspect)) {
            foreach ([$device, 'app', 'web', 'tablet'] as $key) {
                if (!empty($aspect[$key]) && is_string($aspect[$key]) && str_contains($aspect[$key], ':')) {
                    return $aspect[$key];
                }
            }
        }
        return $default;
    }

    private function pickImage($image, string $device): string
    {
        if (!is_array($image)) {
            return is_string($image) ? $image : '';
        }
        foreach ([$device, 'app', 'web', 'tablet'] as $key) {
            if (!empty($image[$key])) {
                return $image[$key];
            }
        }
        return '';
    }

    /**
     * Flattens a {langId: text} translatable map to the request language,
     * falling back to default language then any non-empty value.
     */
    private function flattenText($value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (!is_array($value)) {
            return '';
        }
        if (!empty($value[$this->langId])) {
            return $value[$this->langId];
        }
        if (!empty($value[$this->defaultLangId])) {
            return $value[$this->defaultLangId];
        }
        foreach ($value as $text) {
            if (!empty($text)) {
                return $text;
            }
        }
        return '';
    }
}
