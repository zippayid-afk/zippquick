// Shared data helpers for the Home Builder module.
// Mirrors the layout shape consumed by the customer API (HomeLayoutResolver).


// Layout JSON stores storage-relative image paths (home_builder/...); legacy rows
// may hold absolute URLs. Resolve either to a displayable URL.
export function resolveImageUrl(v) {
    if (!v) return '';
    if (/^https?:\/\//i.test(v) || v.startsWith('data:')) return v;
    return (window.baseUrl || '') + '/storage/' + v;
}

// Per-platform default image height (px). Applied when a height field is left
// blank — both in the builder placeholder and the backend resolver.
export const IMAGE_ASPECT_DEFAULTS = { app: '16:9', tablet: '16:9', web: '3:1' };

// Section/block types — each section hosts exactly one block of the same type.
// icon = lucide component name (rendered via <component :is>).
export const SECTION_TYPES = [
  { value: 'banner_slider', label: 'Banner Slider', icon: 'Images' },
  { value: 'category_section', label: 'Category Section', icon: 'LayoutGrid' },
  { value: 'product_slider', label: 'Product Slider', icon: 'Package' },
  { value: 'brand_section', label: 'Top Brands', icon: 'Tags' },
  { value: 'grid_banner', label: 'Grid Banner', icon: 'Grid3x3' },
  { value: 'title_image', label: 'Title Image', icon: 'ImageIcon' },
  { value: 'text_section', label: 'Heading / Text', icon: 'Heading' },
];

export const SECTION_TYPE_LABELS = SECTION_TYPES.reduce((acc, t) => {
  acc[t.value] = t.label;
  return acc;
}, {});

let idCounter = 0;
export function genId(prefix) {
  idCounter += 1;
  return `${prefix}-${Date.now().toString(36)}-${idCounter}`;
}

// Header background image is per-platform ({app,web,tablet}), like block images.
// Upgrades an old single-string value to the map so existing layouts keep working.
function toImageMap(v) {
  if (v && typeof v === 'object' && !Array.isArray(v)) return { ...newImage(), ...v };
  const s = typeof v === 'string' ? v : '';
  return { app: s, web: s, tablet: s };
}

function toAspectMap(v) {
  const d = IMAGE_ASPECT_DEFAULTS;
  if (typeof v === 'string' && v.includes(':')) return { app: v, web: v, tablet: v };
  if (v && typeof v === 'object' && !Array.isArray(v)) {
    return {
      app: v.app || d.app,
      web: v.web || d.web,
      tablet: v.tablet || d.tablet,
    };
  }
  return { ...d };
}

function emptyBg() {
  return { theme: 'color', color: '#FFE94B', image_url: newImage(), text_color: '#000000' };
}

function normBg(bg) {
  const b = { ...emptyBg(), ...(bg || {}) };
  b.image_url = toImageMap(bg && bg.image_url);
  return b;
}

export function emptyConfig() {
  return {
    sections: [],
    // category_wise tab bg (flat)
    background_theme: 'color',
    background_color: '#FFE94B',
    background_image_url: newImage(),
    text_color: '#000000',
    header_icon_url: '',
    // single-mode per-channel bg
    background_quick: emptyBg(),
    background_ecommerce: emptyBg(),
  };
}

function newImage() {
  return { app: '', web: '', tablet: '' };
}

// Per-platform numeric map (grid columns / gaps / radii). app/web/tablet.
function numMap(app, web, tablet) {
  return { app, web, tablet };
}

export function newBlockConfig() {
  return {
    data_source: 'manual',          // manual | top_selling | trending | recently_visited | buy_again | most_favorited | category | brand | discounted | best_rated | new_arrivals
    limit: 10,
    category_ids: [],               // category_section
    category_id: '',                // product_slider data_source=category
    brand_ids: [],                  // brand_section + product_slider data_source=brand
    manual_product_ids: [],         // product_slider data_source=manual
    variant: 'default',             // default | with_title | with_background
    section_title: {},              // translatable {langId: text}
    section_subtitle: {},           // translatable subtitle/caption
    text_align: 'left',             // text_section: left|center|right
    text_color: '',
    item_text_color: '',            // category/brand item name label color
    background_color: '',           // text_section bg
    background_image: newImage(),
    image_aspect: { ...IMAGE_ASPECT_DEFAULTS }, // per-device image aspect ratio "w:h" (banner_slider / grid_banner / title_image)
    bg_image_aspect: { ...IMAGE_ASPECT_DEFAULTS }, // per-device aspect for the with_background image (product_slider / grid_banner)
    // Per-platform grid columns (category_section / brand_section / product_slider / grid_banner grid layouts).
    grid_columns: numMap(2, 4, 3),
    item_width: '100%',
    preview_item_count: 5,
    auto_scroll: true,
    speed_ms: 3000,
    infinite_loop: true,
    center_focus: false,
    indicator: 'dots',              // dots | none
    carousel_style: 'full_width',   // full_width | peek | card | story | spotlight
    block_padding: 0,
    block_radius: 0,
    grid_gap: numMap(8, 8, 8),      // grid_banner gap (per-platform)
    // grid_banner layout: 'grid' (wraps by columns) or 'scroll' (fixed rows,
    // scrolls horizontally). grid_rows is per-platform, used by the scroll layout.
    grid_layout_type: 'grid',
    grid_rows: numMap(1, 1, 1),
    tile_radius: 6,
    category_gap: numMap(8, 8, 8),  // category_section gap (per-platform)
    category_radius: numMap(12, 12, 12), // category_section item radius (per-platform)
    brand_gap: numMap(8, 8, 8),     // brand_section gap (per-platform)
    brand_radius: numMap(8, 8, 8),  // brand_section item radius (per-platform)
    product_grid_gap: 8,
    product_card_radius: 0,
    show_name: true,                // brand_section: show brand name below logo
    redirect_type: 'none',          // title_image / text_section: none|product|category|url
    redirect_id: '',
    redirect_url: '',
  };
}

// Default layout per block type — horizontal for sliders, grid for category.
function defaultLayout(type) {
  if (type === 'category_section') return 'horizontal';
  if (type === 'brand_section') return 'horizontal';
  if (type === 'product_slider') return 'horizontal';
  return 'horizontal';
}

export function newBlock(type = 'title_image') {
  return {
    id: genId('blk'),
    type,
    layout: defaultLayout(type),
    image: newImage(),
    config: newBlockConfig(),
    items: [],
  };
}

export function newSection(type = 'banner_slider') {
  return {
    id: genId('sec'),
    type,
    active: true,
    margin_top: 0,
    margin_bottom: 0,
    border_radius: 0,
    blocks: [newBlock(type)],
  };
}

export function newBannerItem() {
  return {
    image: newImage(),
    redirect_type: 'none',   // none | product | category | url
    redirect_id: '',
    redirect_url: '',
  };
}

// Normalize a layout loaded from the API so every expected key exists
// (covers configs saved by an older builder version).
export function normalizeConfig(config) {
  const cfg = config && typeof config === 'object' ? config : emptyConfig();
  const sections = Array.isArray(cfg.sections) ? cfg.sections : [];
  sections.forEach((section) => {
    if (!section.id) section.id = genId('sec');
    section.active = section.active !== false;
    section.margin_bottom = section.margin_bottom || 0;
    section.border_radius = section.border_radius || 0;
    if (!Array.isArray(section.blocks) || !section.blocks.length) {
      section.blocks = [newBlock(section.type)];
    }
    section.blocks.forEach((block) => {
      if (!block.id) block.id = genId('blk');
      block.image = { ...newImage(), ...(block.image || {}) };
      block.config = { ...newBlockConfig(), ...(block.config || {}) };
      // Old layouts used grid_2/3/4 — collapse to a single 'grid' (column count
      // is now a per-platform field).
      if (typeof block.layout === 'string' && block.layout.startsWith('grid_')) {
        block.layout = 'grid';
      }
      // Repair per-platform map fields that may have loaded as old scalars.
      const def = newBlockConfig();
      ['grid_columns', 'grid_gap', 'grid_rows', 'category_gap', 'category_radius', 'brand_gap', 'brand_radius'].forEach((k) => {
        const v = block.config[k];
        if (!v || typeof v !== 'object' || Array.isArray(v)) block.config[k] = def[k];
      });
      // Image sizing is an aspect ratio. Ensure image_aspect exists as a
      // {app,tablet,web} string map; purge any legacy px sizing key.
      block.config.image_aspect = toAspectMap(block.config.image_aspect);
      block.config.bg_image_aspect = toAspectMap(block.config.bg_image_aspect);
      delete block.config.image_height;

      block.config.background_image = toImageMap(block.config.background_image);
      // Backfill grid_banner layout type on old layouts.
      if (block.config.grid_layout_type !== 'scroll') block.config.grid_layout_type = 'grid';
      if (!Array.isArray(block.items)) block.items = [];
    });
  });
  return {
    sections,
    background_theme: cfg.background_theme === 'image' ? 'image' : 'color',
    background_color: cfg.background_color || '#FFE94B',
    background_image_url: toImageMap(cfg.background_image_url),
    text_color: cfg.text_color || '#000000',
    header_icon_url: cfg.header_icon_url || '',
    background_quick: normBg(cfg.background_quick),
    background_ecommerce: normBg(cfg.background_ecommerce),
  };
}

// Deep-clone a section blob and re-stamp every id so paste/insert
// never collides with an existing draft's section/block keys.
export function cloneAndReidSection(section) {
  const clone = JSON.parse(JSON.stringify(section || {}));
  clone.id = genId('sec');
  (clone.blocks || []).forEach((b) => { b.id = genId('blk'); });
  return clone;
}

// Build a {langId: text} map for a translatable field, pre-filled with `text`
// on the active default language only. Falls back to an empty map when no
// language id is known (rare, e.g. multilingual disabled).
function titleMap(defaultLangId, text) {
  if (!text) return {};
  if (defaultLangId == null || defaultLangId === '') return { 0: text };
  return { [defaultLangId]: text };
}

// Built-in section presets. Each `build(defaultLangId)` returns a ready-to-insert
// section object compatible with normalizeConfig + the customer resolver.
// The defaultLangId is used to seed translatable fields (section_title etc.)
// with the template's default English copy.
export const BUILTIN_TEMPLATES = [
  {
    id: 'tpl-hero-banner',
    name: 'Hero Banner Slider',
    description: 'Full-width auto-scrolling banners. Promos & announcements.',
    icon: 'Images',
    section_type: 'banner_slider',
    build: () => ({
      ...newSection('banner_slider'),
      margin_top: 0, margin_bottom: 8, border_radius: 0,
      blocks: [(() => {
        const b = newBlock('banner_slider');
        b.config.auto_scroll = true;
        b.config.speed_ms = 3000;
        b.config.infinite_loop = true;
        b.config.carousel_style = 'full_width';
        b.items = [newBannerItem(), newBannerItem()];
        return b;
      })()],
    }),
  },
  {
    id: 'tpl-category-rail',
    name: 'Category Showcase',
    description: 'Horizontal scrollable category chips for quick navigation.',
    icon: 'LayoutGrid',
    section_type: 'category_section',
    build: () => ({
      ...newSection('category_section'),
      margin_bottom: 8, border_radius: 12,
      blocks: [(() => {
        const b = newBlock('category_section');
        b.layout = 'horizontal';
        b.config.category_gap = { app: 10, web: 10, tablet: 10 };
        b.config.category_radius = { app: 16, web: 16, tablet: 16 };
        return b;
      })()],
    }),
  },
  {
    id: 'tpl-flash-sale',
    name: 'Flash Sale Grid',
    description: '2×2 deal tile grid for limited-time offers.',
    icon: 'Zap',
    section_type: 'grid_banner',
    build: () => ({
      ...newSection('grid_banner'),
      margin_bottom: 12, border_radius: 12,
      blocks: [(() => {
        const b = newBlock('grid_banner');
        b.config.grid_columns = { app: 2, web: 2, tablet: 2 };
        b.config.grid_gap = { app: 8, web: 8, tablet: 8 };
        b.config.tile_radius = 10;
        b.items = [newBannerItem(), newBannerItem(), newBannerItem(), newBannerItem()];
        return b;
      })()],
    }),
  },
  {
    id: 'tpl-featured-products',
    name: 'Featured Products',
    description: 'Horizontally scrollable product carousel with title.',
    icon: 'Star',
    section_type: 'product_slider',
    build: (defaultLangId) => ({
      ...newSection('product_slider'),
      margin_top: 4, margin_bottom: 8,
      blocks: [(() => {
        const b = newBlock('product_slider');
        b.layout = 'horizontal';
        b.config.variant = 'with_title';
        b.config.section_title = titleMap(defaultLangId, 'Featured Products');
        b.config.data_source = 'manual';
        b.config.limit = 10;
        return b;
      })()],
    }),
  },
  {
    id: 'tpl-new-arrivals',
    name: 'New Arrivals Grid',
    description: '2-column product grid. Showcase new stock.',
    icon: 'Award',
    section_type: 'product_slider',
    build: (defaultLangId) => ({
      ...newSection('product_slider'),
      margin_top: 4, margin_bottom: 12,
      blocks: [(() => {
        const b = newBlock('product_slider');
        b.layout = 'grid';
        b.config.variant = 'with_title';
        b.config.section_title = titleMap(defaultLangId, 'New Arrivals');
        b.config.data_source = 'new_arrivals';
        b.config.limit = 6;
        b.config.grid_columns = { app: 2, web: 2, tablet: 2 };
        b.config.product_grid_gap = 10;
        b.config.product_card_radius = 10;
        return b;
      })()],
    }),
  },
  {
    id: 'tpl-best-sellers',
    name: 'Best Sellers Carousel',
    description: 'Top-selling products in a horizontal slider.',
    icon: 'Flame',
    section_type: 'product_slider',
    build: (defaultLangId) => ({
      ...newSection('product_slider'),
      margin_bottom: 8,
      blocks: [(() => {
        const b = newBlock('product_slider');
        b.layout = 'horizontal';
        b.config.variant = 'with_title';
        b.config.section_title = titleMap(defaultLangId, 'Best Sellers');
        b.config.data_source = 'top_selling';
        b.config.limit = 8;
        return b;
      })()],
    }),
  },
  {
    id: 'tpl-promo-banner',
    name: 'Promo Banner Strip',
    description: 'Single full-width promotional banner.',
    icon: 'Gift',
    section_type: 'title_image',
    build: () => ({
      ...newSection('title_image'),
      margin_bottom: 8, border_radius: 16,
      blocks: [newBlock('title_image')],
    }),
  },
  {
    id: 'tpl-bg-product-slider',
    name: 'Products with Background',
    description: 'Product slider over a full-bleed background image.',
    icon: 'ImageIcon',
    section_type: 'product_slider',
    build: (defaultLangId) => ({
      ...newSection('product_slider'),
      margin_bottom: 12,
      blocks: [(() => {
        const b = newBlock('product_slider');
        b.layout = 'horizontal';
        b.config.variant = 'with_background';
        b.config.section_title = titleMap(defaultLangId, 'Top Picks');
        b.config.data_source = 'manual';
        b.config.limit = 10;
        b.config.block_padding = 12;
        return b;
      })()],
    }),
  },
  {
    id: 'tpl-deal-3col',
    name: 'Top Picks Grid (3-col)',
    description: 'Compact 3-column product grid, high density.',
    icon: 'Trophy',
    section_type: 'product_slider',
    build: (defaultLangId) => ({
      ...newSection('product_slider'),
      margin_top: 4, margin_bottom: 12,
      blocks: [(() => {
        const b = newBlock('product_slider');
        b.layout = 'grid';
        b.config.variant = 'with_title';
        b.config.section_title = titleMap(defaultLangId, 'Top Picks');
        b.config.data_source = 'discounted';
        b.config.limit = 9;
        b.config.grid_columns = { app: 3, web: 3, tablet: 3 };
        b.config.product_grid_gap = 8;
        b.config.product_card_radius = 8;
        return b;
      })()],
    }),
  },
  {
    id: 'tpl-brand-strip',
    name: 'Top Brands Strip',
    description: 'Horizontal brand logos with optional name.',
    icon: 'Tags',
    section_type: 'brand_section',
    build: (defaultLangId) => ({
      ...newSection('brand_section'),
      margin_bottom: 8,
      blocks: [(() => {
        const b = newBlock('brand_section');
        b.layout = 'horizontal';
        b.config.section_title = titleMap(defaultLangId, 'Top Brands');
        b.config.brand_gap = { app: 12, web: 12, tablet: 12 };
        b.config.show_name = true;
        return b;
      })()],
    }),
  },
];

// Resolve a translatable {langId: text} map to a single display string.
export function pickText(value, langId, defaultLangId) {
  if (typeof value === 'string') return value;
  if (!value || typeof value !== 'object') return '';
  if (value[langId]) return value[langId];
  if (value[defaultLangId]) return value[defaultLangId];
  const first = Object.values(value).find((v) => v);
  return first || '';
}
