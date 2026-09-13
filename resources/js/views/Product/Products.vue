<template>
    <div class="list-page">
        <!-- Title + primary action outside any card. -->
        <div class="page-head">
            <h3 class="page-head-title">{{ __('manage_products') }}</h3>
            <router-link v-if="$can('product_create')" to="/products/create"
                class="btn btn-primary list-add-btn d-inline-flex align-items-center gap-2 text-nowrap">
                <Plus :size="16" /><span>{{ __('add_product') }}</span>
            </router-link>
        </div>

        <!-- Everything (toolbar + filters + grid + pager) inside one card. -->
        <div class="list-surface">
        <!-- Toolbar: Published/Drafts on the LEFT, controls on the RIGHT. -->
        <div class="card-toolbar products-toolbar list-panel-toolbar">
            <div class="btn-group product-tabs" role="group">
                <button type="button" class="btn"
                    :class="isDraftTab === 0 ? 'btn-primary' : 'btn-outline-primary'" @click="setTab(0)">
                    {{ __('published') }}
                </button>
                <button type="button" class="btn"
                    :class="isDraftTab === 1 ? 'btn-primary' : 'btn-outline-primary'" @click="setTab(1)">
                    {{ __('drafts') }}
                    <span class="count-pill">{{ counts.draft }}</span>
                </button>
            </div>

            <div class="products-toolbar-right">
                <AppSelect v-if="czShowCountry" class="cz-sel" v-model="czCountryId" :options="czCountryOptions"
                    :searchable="czCountryOptions.length > 6" :allow-empty="false" label-key="label" track-by="id"
                    :placeholder="__('country')" @update:model-value="czOnCountry">
                    <template #singleLabel="{ option }"><span class="cz-opt"><img v-if="option.logo_url"
                                :src="option.logo_url" class="cz-flag" />{{ option.label }}</span></template>
                    <template #option="{ option }"><span class="cz-opt"><img v-if="option.logo_url"
                                :src="option.logo_url" class="cz-flag" />{{ option.label }}</span></template>
                </AppSelect>
                <AppSelect v-if="czShowZoneDropdown" class="cz-sel" v-model="czZoneId" :options="czZoneOptions"
                    :searchable="false" :allow-empty="false" label-key="label" track-by="id"
                    :placeholder="__('zone')" @update:model-value="czOnZone" />
                <button class="btn" :class="showFilters ? 'btn-primary' : 'btn-outline-primary'"
                    @click="showFilters = !showFilters">
                    <SlidersHorizontal :size="15" /> {{ __('filters') }}
                    <span v-if="activeFilterCount" class="badge bg-light text-dark ms-1">{{ activeFilterCount }}</span>
                </button>
                <AppSelect class="form-select list-select" v-model="sort_by" :options="sort_byOptions" :searchable="false" @update:model-value="onFilterChange" />
                <div class="list-search">
                    <Search class="list-search-icon" />
                    <input type="search" class="form-control" v-model="search"
                        :placeholder="__('search_products')" @input="onFilterChange" />
                </div>
                <button class="list-icon-btn" @click="getRecords" :disabled="isLoading"
                    v-b-tooltip.hover :title="__('refresh')">
                    <RefreshCw :class="{ 'is-spinning': isLoading }" />
                </button>
            </div>
        </div>

        <!-- Collapsible filter panel -->
        <transition name="filter-slide">
        <div v-if="showFilters" class="list-filters">
            <div class="row g-3">
                <div class="col-lg-3 col-md-4 col-6">
                    <label class="flbl">{{ __('categories') }}</label>
                    <AppSelect class="form-select" v-model="category_id" :options="translatedCategories"
                        :placeholder="__('all_categories')" @update:model-value="onFilterChange" />
                </div>
                <div class="col-lg-3 col-md-4 col-6">
                    <label class="flbl">{{ __('brand') }}</label>
                    <AppSelect class="form-select" v-model="brand_id" :options="translatedBrands"
                        :placeholder="__('all_brands')" @update:model-value="onFilterChange" />
                </div>
                <div class="col-lg-2 col-md-4 col-6">
                    <label class="flbl">{{ __('status') }}</label>
                    <AppSelect class="form-select" v-model="status" :options="statusOptions" :searchable="false" @update:model-value="onFilterChange" />
                </div>
                <div class="col-lg-2 col-md-4 col-6">
                    <label class="flbl">{{ __('sales_channel') }}</label>
                    <AppSelect class="form-select" v-model="sales_channel" :options="sales_channelOptions" :searchable="false" @update:model-value="onFilterChange" />
                </div>
                <div class="col-lg-2 col-md-4 col-6">
                    <label class="flbl d-inline-flex align-items-center gap-1">
                        {{ __('listed_only') }}
                        <Info :size="13" class="text-muted" style="cursor:help;" v-b-tooltip.hover :title="__('listed_only_hint')" />
                    </label>
                    <div class="d-flex align-items-center justify-content-between gap-3" style="height:38px;">
                        <div class="form-check form-switch d-flex align-items-center gap-2 m-0 ps-0">
                            <input class="form-check-input m-0 float-none" type="checkbox" role="switch"
                                id="listedOnlyToggle" v-model="listedOnly" @change="onFilterChange">
                            <label class="form-check-label small mb-0 text-nowrap" for="listedOnlyToggle">{{ listedOnly ? __('yes') : __('no') }}</label>
                        </div>
                        <button class="list-icon-btn" @click="resetFilters" :disabled="!activeFilterCount"
                            v-b-tooltip.hover :title="__('clear_filters')">
                            <X :size="15" />
                        </button>
                    </div>
                </div>
            </div>
        </div>
        </transition>

        <!-- Grid -->
            <div class="list-panel-body">
                <div class="card-grid">
                    <!-- Skeleton placeholders while loading (no layout jump). -->
                    <EntityCardSkeleton v-if="isLoading" :count="skeletonCount" />
                    <div v-else-if="products.length === 0" class="card-grid-empty">
                        <Inbox :size="34" />
                        <span>{{ __('no_records_found') }}</span>
                    </div>

            <div v-else v-for="p in translatedProducts" :key="'p-' + p.id" class="entity-card">
                <div class="entity-card-media is-contain">
                    <img :src="p.image_url || placeholderImg" alt="" />
                    <!-- Try & Buy badge - Left Top Corner -->
                    <div v-if="p.try_and_buy" class="try-buy-badge-container">
                        <span class="try-buy-badge">
                            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="try-buy-icon">
                                <circle cx="12" cy="12" r="10"/>
                                <path d="M9 12l2 2 4-4"/>
                            </svg>
                            <span class="try-buy-text">{{ __('try_and_buy') }}</span>
                        </span>
                        <small v-if="p.try_and_buy_text" class="try-buy-description">
                            {{ p.try_and_buy_text }}
                        </small>
                    </div>

                    <!-- Status badge - Right Top Corner (top: 8px) -->
                    <span v-if="p.is_draft" class="status-pill is-warning" style="left: auto; right: 8px; top: 8px;">{{ __('draft') }}</span>
                    <span v-else class="status-pill" :class="p.status ? 'is-active' : 'is-inactive'" style="left: auto; right: 8px; top: 8px;">
                        {{ p.status ? __('active') : __('inactive') }}
                    </span>

                    <!-- Pre-Order Only badge - Right Top Corner (below status, top: 32px) -->
                    <div v-if="p.is_preorder_only" class="try-buy-badge-container" style="left: auto; right: 8px; top: 32px;">
                        <span class="try-buy-badge" style="background-color: #f59e0b;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="try-buy-icon">
                                <circle cx="12" cy="12" r="10"/>
                                <polyline points="12 6 12 12 16 14"/>
                            </svg>
                            <span class="try-buy-text">{{ __('preorder_only') }}</span>
                        </span>
                        <small v-if="p.preorder_info_text" class="try-buy-description">
                            {{ p.preorder_info_text }}
                        </small>
                    </div>
                </div>

                <div class="entity-card-body">
                    <h4 class="entity-card-title text-truncate" :title="p.name">{{ p.name }}</h4>
                    <div class="entity-card-meta">
                        <div class="d-flex align-items-center justify-content-between gap-2">
                            <span class="entity-meta-row text-truncate">{{ p.category ? p.category.name : '—' }}</span>
                            <span class="mode-chip flex-shrink-0">
                                <component :is="(p.sales_channel || 'both') === 'quick' ? 'Zap' : ((p.sales_channel || 'both') === 'ecommerce' ? 'ShoppingBag' : 'Layers')" :size="13" />
                                {{ channelLabel(p) }}
                            </span>
                        </div>
                        <span class="entity-meta-row">
                            <div class="d-flex align-items-center gap-2">
                                <b class="text-primary">{{ displayCurrency }}{{ priceLabel(p) }}</b>
                                <span v-if="p.gst && p.gst.gst_rate > 0" class="gst-badge" :class="p.gst.gst_inclusive ? 'gst-inclusive' : 'gst-exclusive'">
                                    {{ p.gst.gst_rate }}% {{ p.gst.gst_inclusive ? __('incl') : __('excl') }}
                                </span>
                            </div>
                            <span class="ms-auto">{{ p.variants_count }} {{ p.variants_count === 1 ? __('variant') : __('variants') }}</span>
                        </span>
                    </div>

                    <div class="entity-card-foot">
                        <div class="list-actions">
                            <router-link :to="viewRoute(p)" class="list-action-btn is-view"
                                v-if="$can('product_list')" v-b-tooltip.hover :title="__('view')">
                                <Eye :size="15" />
                            </router-link>
                            <router-link :to="editRoute(p)" class="list-action-btn is-edit"
                                v-if="$can('product_update')" v-b-tooltip.hover :title="__('edit')">
                                <Pencil :size="15" />
                            </router-link>
                            <router-link :to="cloneRoute(p)" class="list-action-btn is-edit"
                                v-if="$can('product_create')" v-b-tooltip.hover :title="__('clone_product')">
                                <Copy :size="15" />
                            </router-link>
                            <button class="list-action-btn is-edit" @click="openRecommendations(p)"
                                v-if="$can('product_update')" v-b-tooltip.hover :title="__('recommendations')">
                                <Link :size="15" />
                            </button>
                            <button class="list-action-btn is-delete" @click="deleteRecord(p)"
                                v-if="$can('product_delete')" v-b-tooltip.hover :title="__('delete')">
                                <Trash2 :size="15" />
                            </button>
                        </div>
                    </div>
                </div>
                </div>
            </div>
            </div>

            <!-- Pagination -->
            <div v-if="!isLoading && products.length > 0" class="list-footer">
                <div class="list-perpage">
                    <span>{{ __('per_page') }}</span>
                    <b-form-select v-model="perPage" :options="pageOptions" size="sm"
                        class="form-select" @change="onPerPageChange"></b-form-select>
                    <span class="list-range">{{ __('total_records') }} : {{ totalRows }}</span>
                </div>
                <b-pagination v-model="currentPage" :total-rows="totalRows" :per-page="perPage"
                    size="sm" class="my-0"></b-pagination>
            </div>
        </div>

        <!-- Recommendations (cross-sell / up-sell) modal -->
        <b-modal v-model="reco.show" size="lg" centered :no-footer="true">
            <template #title>
                <span class="d-inline-flex align-items-center gap-2">
                    <Link :size="16" />
                    <span>{{ __('recommendations') }}<span v-if="reco.product"> — {{ reco.product.name }}</span></span>
                </span>
            </template>
            <div v-if="reco.product" class="reco-modal">
                <!-- type toggle (solid / outline buttons) -->
                <div class="d-flex gap-2 mb-3">
                    <button type="button" class="btn flex-fill"
                        :class="reco.activeType === 'cross_sell' ? 'btn-primary' : 'btn-outline-primary'"
                        @click="reco.activeType = 'cross_sell'">
                        <ShoppingCart :size="15" /> {{ __('cross_sell') }}
                        <span class="count-pill">{{ reco.cross_sell.length }}</span>
                    </button>
                    <button type="button" class="btn flex-fill"
                        :class="reco.activeType === 'upsell' ? 'btn-primary' : 'btn-outline-primary'"
                        @click="reco.activeType = 'upsell'">
                        <ArrowUp :size="15" /> {{ __('upsell') }}
                        <span class="count-pill">{{ reco.upsell.length }}</span>
                    </button>
                </div>

                <p class="reco-note">
                    <component :is="reco.activeType === 'upsell' ? 'ArrowUp' : 'ShoppingCart'" :size="14" />
                    <span>{{ recoNote }}</span>
                </p>

                <div v-if="reco.loading" class="text-center py-4">
                    <b-spinner></b-spinner>
                </div>

                <template v-else>
                    <!-- currently added -->
                    <div class="reco-section-label">{{ __('currently_added') }}</div>
                    <div v-if="!activeRecoList.length" class="reco-empty">
                        <PackageOpen :size="26" />
                        <span>{{ __('no_products_added_yet') }}</span>
                    </div>
                    <div v-else class="reco-list reco-list--added mb-3">
                        <div v-for="item in activeRecoList" :key="'sel-' + item.id" class="reco-row">
                            <img :src="item.image_url || placeholderImg" class="reco-thumb" alt="" />
                            <div class="reco-info">
                                <div class="reco-name text-truncate">{{ item.name }}</div>
                                <div class="reco-sub">{{ $currency }}{{ item.price }} · {{ item.sku || '—' }}</div>
                            </div>
                            <button class="reco-remove" @click="removeReco(item)"
                                v-b-tooltip.hover :title="__('remove')">
                                <X :size="15" />
                            </button>
                        </div>
                    </div>

                    <!-- search + add -->
                    <div class="reco-section-label">{{ __('add_products') }}</div>
                    <div class="list-search mb-2">
                        <Search class="list-search-icon" />
                        <input type="search" class="form-control" v-model="reco.search"
                            :placeholder="__('search_by_name_or_sku')" @input="onRecoSearch" />
                    </div>
                    <div v-if="reco.searching" class="text-center py-2">
                        <b-spinner small></b-spinner>
                    </div>
                    <div v-else class="reco-list">
                        <div v-for="item in availableSearchResults" :key="'res-' + item.id" class="reco-row">
                            <img :src="item.image_url || placeholderImg" class="reco-thumb" alt="" />
                            <div class="reco-info">
                                <div class="reco-name text-truncate">{{ item.name }}</div>
                                <div class="reco-sub">{{ $currency }}{{ item.price }} · {{ item.sku || '—' }}</div>
                            </div>
                            <button class="btn btn-sm btn-outline-primary reco-add" @click="addReco(item)">
                                <Plus :size="15" /> {{ __('add') }}
                            </button>
                        </div>
                        <div v-if="reco.search && !availableSearchResults.length" class="reco-empty">
                            <Search :size="24" />
                            <span>{{ __('no_records_found') }}</span>
                        </div>
                        <div v-else-if="!reco.search && !availableSearchResults.length" class="reco-empty">
                            <Search :size="24" />
                            <span>{{ __('search_by_name_or_sku') }}</span>
                        </div>
                    </div>
                </template>

                <div class="reco-foot">
                    <button class="btn btn-outline-secondary" @click="reco.show = false">{{ __('cancel') }}</button>
                    <button class="btn btn-primary" @click="saveReco" :disabled="reco.saving">
                        <b-spinner small v-if="reco.saving"></b-spinner>
                        {{ __('save') }}
                    </button>
                </div>
            </div>
        </b-modal>
    </div>
</template>

<script>
import axios from "axios";
import Auth from '../../Auth.js';
import CountryZoneFilter from '../../mixins/CountryZoneFilter.js';
import {
    Plus, Search, SlidersHorizontal, RefreshCw, X, Inbox, Eye, Pencil, Copy,
    Link, Trash2, Zap, ShoppingBag, Layers, PackageOpen, ShoppingCart, ArrowUp, Info,
} from 'lucide-vue-next';

export default {
    mixins: [CountryZoneFilter],
    components: {
        Plus, Search, SlidersHorizontal, RefreshCw, X, Inbox, Eye, Pencil, Copy,
        Link, Trash2, Zap, ShoppingBag, Layers, PackageOpen, ShoppingCart, ArrowUp, Info,
    },
    data() {
        return {
            // Products are store/zone-priced with a single response currency → country-locked.
            login_user: Auth.user,
            products: [],
            categories: [],
            brands: [],
            counts: { published: 0, draft: 0 },

            search: '',
            category_id: '',
            brand_id: '',
            status: '',
            sales_channel: '',
            sort_by: 'latest',
            currency: '',
            listedOnly: true,
            showFilters: false,
            isDraftTab: 0,

            currentPage: 1,
            perPage: 30,
            pageOptions: this.$pageOptions,
            totalRows: 0,

            isLoading: true,
            currentLanguageId: null,
            activeLanguages: [],
            defaultLanguageId: null,
            _searchTimer: null,

            // Null-image placeholder: favicon -> logo -> bundled default (all
            // guaranteed to resolve). Broken/404 image urls are handled by the
            // global fallback in app.js, so no per-img @error needed here.
            placeholderImg: window.appFavicon
                || (window.appLogo ? window.baseUrl + '/storage/' + window.appLogo : '')
                || (window.baseUrl + '/images/logo.png'),

            reco: {
                show: false,
                product: null,
                activeType: 'cross_sell',
                cross_sell: [],
                upsell: [],
                search: '',
                searchResults: [],
                loading: false,
                searching: false,
                saving: false,
                _searchTimer: null,
            },
        };
    },
    computed: {
        // Fixed option set — no search box needed.
        sort_byOptions() {
            return [
                { id: 'latest', name: (__('latest')) },
                { id: 'oldest', name: (__('oldest')) },
                { id: 'name_asc', name: (__('name')) + ' ' + 'A-Z' },
                { id: 'name_desc', name: (__('name')) + ' ' + 'Z-A' },
                { id: 'price_low', name: (__('price_low_to_high')) },
                { id: 'price_high', name: (__('price_high_to_low')) },
            ];
        },
        // Fixed option set — no search box needed.
        statusOptions() {
            return [
                { id: '', name: (__('all_statuses')) },
                { id: '1', name: (__('active')) },
                { id: '0', name: (__('inactive')) },
            ];
        },
        // Fixed option set — no search box needed.
        sales_channelOptions() {
            return [
                { id: '', name: (__('all')) },
                { id: 'quick', name: (__('quick')) },
                { id: 'ecommerce', name: (__('ecommerce')) },
                { id: 'both', name: (__('both')) },
            ];
        },
        activeRecoList() {
            return this.reco[this.reco.activeType] || [];
        },
        availableSearchResults() {
            // Hide products already in the active list.
            const added = new Set(this.activeRecoList.map(p => p.id));
            return this.reco.searchResults.filter(p => !added.has(p.id));
        },
        recoNote() {
            const raw = this.reco.activeType === 'upsell' ? __('upsell_note') : __('cross_sell_note');
            // The translation strings lead with an emoji (🛒 / ⬆️); strip it — a lucide
            // icon is rendered in its place in the template.
            return raw.replace(/^[^\p{L}\p{N}]+/u, '').trim();
        },
        activeFilterCount() {
            return [this.category_id, this.brand_id, this.status, this.sales_channel].filter(v => v !== '' && v !== null).length;
        },
        // How many skeleton cards to show while loading: reuse the last-known row
        // count, capped so we don't paint a huge grid.
        skeletonCount() {
            const known = this.products.length || this.perPage || 10;
            return Math.min(known, 12);
        },
        // Brand filter options, translated to the active language.
        translatedBrands() {
            return this.brands.map(b => {
                const out = { ...b };
                const t = this.pickTranslation(b.translations);
                if (t && t.name && t.name.trim()) out.name = t.name;
                return out;
            });
        },
        // Currency of the header-selected country; falls back to the global currency.
        displayCurrency() {
            return this.currency || this.$currency;
        },
        translatedProducts() {
            if (!this.currentLanguageId || this.products.length === 0) return this.products;
            return this.products.map(p => {
                const out = { ...p };
                const t = this.pickTranslation(p.translations);
                if (t && t.name && t.name.trim()) out.name = t.name;
                if (p.category) {
                    out.category = { ...p.category };
                    const ct = this.pickTranslation(p.category.translations);
                    if (ct && ct.name && ct.name.trim()) out.category.name = ct.name;
                }
                if (p.store) {
                    out.store = { ...p.store };
                    const st = this.pickTranslation(p.store.translations);
                    if (st && st.name && st.name.trim()) out.store.name = st.name;
                }
                return out;
            });
        },
        translatedCategories() {
            // Translate names, then flatten into an indented tree. Parent categories
            // (those with children) are shown but disabled — only leaf/child categories
            // are selectable in the filter.
            const list = this.categories.map(c => {
                const out = { ...c, parent_id: Number(c.parent_id || 0) };
                const t = this.pickTranslation(c.translations);
                if (t && t.name && t.name.trim()) out.name = t.name;
                return out;
            });
            // Group children by parent. A child whose parent isn't in the list
            // (e.g. an inactive parent the API omitted) is promoted to a root so it
            // never disappears from the tree.
            const idSet = new Set(list.map(c => c.id));
            const byParent = {};
            list.forEach(c => {
                const pid = idSet.has(c.parent_id) ? c.parent_id : 0;
                (byParent[pid] = byParent[pid] || []).push(c);
            });

            const INDENT = '\u00A0\u00A0\u00A0\u00A0'; // nbsp — plain spaces collapse in the dropdown
            const out = [];
            const walk = (parentId, depth) => {
                (byParent[parentId] || []).forEach(c => {
                    const hasChildren = (byParent[c.id] || []).length > 0;
                    out.push({
                        ...c,
                        name: (depth ? INDENT.repeat(depth) + '↳ ' : '') + c.name,
                        $isDisabled: hasChildren, // parents shown but not selectable
                    });
                    walk(c.id, depth + 1);
                });
            };
            walk(0, 0);
            return out;
        },
    },
    created() {
        if (!this.$can('product_list')) {
            this.showError("You do not have permission to view this page.");
            this.$router.back();
            return;
        }
        this.fetchActiveLanguages();
        this.czLoad(); // sets default country then getRecords via czOnFilter
    },
    watch: {
        currentPage() { this.getRecords(); },
    },
    methods: {
        czOnFilter() { this.currentPage = 1; this.getRecords(); },
        fetchActiveLanguages() {
            return axios.get(this.$apiUrl + '/active_languages').then(r => {
                const langs = r.data.data || [];
                this.activeLanguages = langs;
                const def = langs.find(l => l.is_default === 1);
                this.defaultLanguageId = def ? def.id : null;
                const appLocale = window.appLocale || 'en';
                const cur = langs.find(l => l.code === appLocale);
                this.currentLanguageId = cur ? cur.id : (def ? def.id : null);
            }).catch(() => { });
        },
        pickTranslation(translations) {
            if (!Array.isArray(translations)) return null;
            let t = translations.find(x => Number(x.language_id) === Number(this.currentLanguageId));
            if (!t && this.defaultLanguageId) {
                t = translations.find(x => Number(x.language_id) === Number(this.defaultLanguageId));
            }
            return t || null;
        },
        setTab(tab) {
            if (this.isDraftTab === tab) return;
            this.isDraftTab = tab;
            this.currentPage = 1;
            this.getRecords();
        },
        onFilterChange() {
            if (this._searchTimer) clearTimeout(this._searchTimer);
            this._searchTimer = setTimeout(() => {
                this.currentPage = 1;
                this.getRecords();
            }, 300);
        },
        resetFilters() {
            this.category_id = '';
            this.brand_id = '';
            this.status = '';
            this.sales_channel = '';
            this.currentPage = 1;
            this.getRecords();
        },
        onPerPageChange() {
            this.currentPage = 1;
            this.getRecords();
        },
        getRecords() {
            this.isLoading = true;
            const params = {
                page: this.currentPage,
                per_page: this.perPage,
                search: this.search,
                category_id: this.category_id,
                brand_id: this.brand_id,
                status: this.status,
                sales_channel: this.sales_channel,
                is_draft: this.isDraftTab,
                sort_by: this.sort_by,
                country_id: this.czCountryParam,
                zone_id: this.czZoneParam,
                // Listed-only toggle: only products with a listed store (in the header region).
                listed_only: this.listedOnly ? 1 : 0,
            };
            axios.get(this.$apiUrl + '/products', { params }).then(r => {
                const d = r.data.data || {};
                this.products = d.products || [];
                this.categories = d.categories || [];
                this.brands = d.brands || [];
                this.counts = d.counts || { published: 0, draft: 0 };
                this.currency = d.currency || '';
                this.totalRows = r.data.total || 0;
            }).catch(() => {
                this.products = [];
            }).finally(() => {
                this.isLoading = false;
            });
        },
        channelLabel(p) {
            const c = p.sales_channel || 'both';
            if (c === 'quick') return __('quick');
            if (c === 'ecommerce') return __('ecommerce');
            return __('both');
        },
        priceLabel(p) {
            if (!p.min_price && !p.max_price) return '0';
            if (p.min_price === p.max_price) return Number(p.min_price).toFixed(2);
            return Number(p.min_price).toFixed(2) + ' – ' + Number(p.max_price).toFixed(2);
        },
        viewRoute(p) {
            return { name: 'ViewProduct', params: { id: p.id } };
        },
        editRoute(p) {
            return { name: 'EditProduct', params: { id: p.id } };
        },
        cloneRoute(p) {
            return { name: 'CloneProduct', params: { id: p.id, clone: true } };
        },
        openRecommendations(p) {
            this.reco.product = p;
            this.reco.activeType = 'cross_sell';
            this.reco.cross_sell = [];
            this.reco.upsell = [];
            this.reco.search = '';
            this.reco.searchResults = [];
            this.reco.show = true;
            this.loadRecommendations(p.id);
        },
        loadRecommendations(productId) {
            this.reco.loading = true;
            axios.get(this.$apiUrl + '/products/recommendations', { params: { product_id: productId } })
                .then(r => {
                    const d = r.data.data || {};
                    this.reco.cross_sell = d.cross_sell || [];
                    this.reco.upsell = d.upsell || [];
                }).catch(() => { })
                .finally(() => { this.reco.loading = false; });
        },
        onRecoSearch() {
            if (this.reco._searchTimer) clearTimeout(this.reco._searchTimer);
            this.reco._searchTimer = setTimeout(() => this.runRecoSearch(), 300);
        },
        runRecoSearch() {
            if (!this.reco.product) return;
            this.reco.searching = true;
            axios.get(this.$apiUrl + '/products/recommendations/search', {
                params: { product_id: this.reco.product.id, search: this.reco.search, limit: 20 },
            }).then(r => {
                this.reco.searchResults = r.data.data || [];
            }).catch(() => { this.reco.searchResults = []; })
                .finally(() => { this.reco.searching = false; });
        },
        addReco(item) {
            const list = this.reco[this.reco.activeType];
            if (!list.some(p => p.id === item.id)) list.push(item);
        },
        removeReco(item) {
            const type = this.reco.activeType;
            this.reco[type] = this.reco[type].filter(p => p.id !== item.id);
        },
        saveReco() {
            if (!this.reco.product) return;
            this.reco.saving = true;
            const pid = this.reco.product.id;
            const save = (type) => axios.post(this.$apiUrl + '/products/recommendations/save', {
                product_id: pid,
                type,
                related_product_ids: this.reco[type].map(p => p.id),
            });
            // Sequential (not Promise.all): parallel delete+insert on the same product
            // races on the unique index and can deadlock.
            save('cross_sell')
                .then(() => save('upsell'))
                .then(() => {
                    this.showMessage('success', __('recommendations_saved_successfully'));
                    this.reco.show = false;
                }).catch(() => { this.showError(__('something_went_wrong')); })
                .finally(() => { this.reco.saving = false; });
        },
        deleteRecord(p) {
            this.$swal.fire({
                title: __('are_you_sure'),
                text: __('you_want_be_able_to_revert_this'),
                confirmButtonText: __('yes_sure'),
                cancelButtonText: __('cancel'),
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: window.adminThemeColor || '#435ebe',
                cancelButtonColor: '#d33',
            }).then(result => {
                if (!result.value) return;
                axios.post(this.$apiUrl + '/products/delete', { product_id: p.id }).then(r => {
                    this.showMessage('success', r.data.message);
                    this.getRecords();
                }).catch(() => { });
            });
        },
    },
};
</script>

<style scoped>
/* Filter labels (same as Orders.vue): block display keeps the control below. */
.flbl { font-size: .72rem; color: #6c757d; margin-bottom: .2rem; display: block; }

/* GST Badge Styling */
.gst-badge {
    display: inline-flex;
    align-items: center;
    padding: 2px 6px;
    font-size: 0.65rem;
    font-weight: 600;
    border-radius: 3px;
    text-transform: uppercase;
    white-space: nowrap;
}
.gst-badge.gst-inclusive {
    background-color: #d1f4e0;
    color: #0f6938;
    border: 1px solid #9de3c0;
}
.gst-badge.gst-exclusive {
    background-color: #fff3cd;
    color: #856404;
    border: 1px solid #ffeaa7;
}

/* Product-card / grid-column styles removed — the page now uses the shared
   .card-grid / .entity-card system in common.css. Only the recommendations
   modal's own bits remain. */
/* ---- Toolbar: tabs left, controls right ---- */
.products-toolbar {
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 0.75rem;
}
/* When the toolbar/filters live INSIDE the card, they get padding + a divider
   instead of the standalone bottom margin. */
.list-panel-toolbar {
    margin-bottom: 0;
    padding: 0.85rem 1rem;
    border-bottom: 1px solid var(--app-card-border);
}
.list-panel-filters {
    margin-bottom: 0;
    border: 0;
    border-radius: 0;
    border-bottom: 1px solid var(--app-card-border);
}
.product-tabs {
    flex: 0 0 auto;
}
.products-toolbar-right {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    flex: 1 1 420px;
    justify-content: flex-end;
    flex-wrap: wrap;
}
/* The .list-search input is fixed 220px globally — give it a bounded flex here
   so it never greedily swallows the toolbar's leftover width. */
.products-toolbar-right .list-search {
    flex: 0 1 240px;
    min-width: 160px;
}

/* Small screens: stack the toolbar; search takes its own full row, the other
   controls share one row. */
@media (max-width: 767.98px) {
    .products-toolbar-right {
        flex: 1 1 100%;
        justify-content: flex-start;
    }
    .products-toolbar-right .list-search {
        order: 10;
        flex: 1 1 100%;
        min-width: 0;
        max-width: none;
    }
    .products-toolbar-right .form-select {
        flex: 1 1 auto;
        max-width: none;
    }
    .product-tabs {
        width: 100%;
    }
    .product-tabs .btn {
        flex: 1 1 50%;
    }
}
.products-toolbar-right .list-search .form-control {
    width: 100%;
}

/* Count pill inside a toggle button — readable on BOTH the solid and outline
   states (currentColor adapts: white on active, primary on inactive). */
.count-pill {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 18px;
    height: 18px;
    padding: 0 6px;
    margin-inline-start: 6px;
    border-radius: 999px;
    background-color: rgba(0, 0, 0, 0.16);
    font-size: 0.7rem;
    font-weight: 700;
    line-height: 1;
}
/* Active button = white count on the solid fill; inactive = black (not primary). */
.btn-primary .count-pill {
    color: #fff;
}
.btn-outline-primary .count-pill {
    color: var(--app-ink);
    background-color: rgba(0, 0, 0, 0.1);
}

/* ---- Recommendations modal ---- */
.reco-note {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    font-size: 0.8rem;
    color: var(--app-muted);
    margin-bottom: 1rem;
}
.reco-note svg {
    flex-shrink: 0;
    color: var(--bs-primary);
}
.reco-section-label {
    font-size: 0.6875rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    color: var(--app-muted);
    margin-bottom: 0.5rem;
}
.reco-list {
    max-height: 260px;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.reco-row {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    padding: 0.5rem 0.6rem;
    border: 1px solid var(--app-card-border);
    border-radius: 10px;
    background: var(--app-card-bg);
    transition: border-color 0.15s ease, background-color 0.15s ease;
}
.reco-row:hover {
    border-color: rgba(var(--bs-primary-rgb), 0.35);
    background: var(--app-hover);
}
.reco-thumb {
    width: 44px;
    height: 44px;
    object-fit: contain;
    background: var(--app-thead-bg);
    border: 1px solid var(--app-card-border);
    border-radius: 8px;
    flex: none;
    padding: 3px;
}
.reco-info {
    min-width: 0;
    flex: 1;
    text-align: start;
}
.reco-name {
    font-size: 0.85rem;
    font-weight: 600;
    color: var(--app-ink);
}
.reco-sub {
    font-size: 0.75rem;
    color: var(--app-muted);
}
.reco-remove {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 30px;
    height: 30px;
    flex-shrink: 0;
    border: 1px solid var(--app-card-border);
    border-radius: 7px;
    background: transparent;
    color: var(--app-muted);
    transition: all 0.15s ease;
}
.reco-remove:hover {
    border-color: #dc3545;
    background: rgba(220, 53, 69, 0.1);
    color: #dc3545;
}
.reco-add {
    flex-shrink: 0;
    border-radius: 7px;
}
.reco-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.4rem;
    padding: 1.5rem 1rem;
    color: var(--app-muted);
    font-size: 0.8rem;
}
.reco-empty svg {
    opacity: 0.45;
}
.reco-foot {
    display: flex;
    justify-content: flex-end;
    gap: 0.5rem;
    margin-top: 1.25rem;
    padding-top: 1rem;
    border-top: 1px solid var(--app-card-border);
}

.min-w-0 {
    min-width: 0;
}

/* ---- Try & Buy Badge Styling ---- */
.try-buy-badge-container {
    position: absolute;
    top: 8px;
    left: 8px;
    z-index: 10;
    max-width: 160px;
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.try-buy-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    background: linear-gradient(135deg, var(--primary-color, #4f46e5) 0%, #6366f1 100%);
    border: 2px solid rgba(255, 255, 255, 0.4);
    border-radius: 8px;
    box-shadow: 0 3px 12px rgba(0, 0, 0, 0.2), 0 1px 4px rgba(0, 0, 0, 0.1);
    font-size: 11px;
    font-weight: 600;
    color: #ffffff;
    line-height: 1;
    white-space: nowrap;
    transition: all 0.2s ease;
}

.try-buy-badge:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.25), 0 2px 6px rgba(0, 0, 0, 0.15);
}

.try-buy-icon {
    flex-shrink: 0;
    width: 12px;
    height: 12px;
    stroke-width: 2.5;
}

.try-buy-text {
    display: inline-block;
}

.try-buy-description {
    display: block;
    padding: 6px 8px;
    background: rgba(255, 255, 255, 0.98);
    color: #1f2937;
    font-size: 10px;
    line-height: 1.4;
    font-weight: 500;
    border-radius: 6px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.12);
    border: 1px solid rgba(0, 0, 0, 0.06);
    max-width: 160px;
    word-wrap: break-word;
}

/* Responsive adjustments for smaller screens */
@media (max-width: 767.98px) {
    .try-buy-badge-container {
        max-width: 140px;
    }
    
    .try-buy-badge {
        padding: 5px 10px;
        font-size: 10px;
        gap: 4px;
    }
    
    .try-buy-icon {
        width: 10px;
        height: 10px;
    }
    
    .try-buy-description {
        font-size: 9px;
        padding: 5px 6px;
    }
}
</style>
