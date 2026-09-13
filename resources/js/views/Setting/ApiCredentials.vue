<template>
    <div>
        <div class="page-heading">
            <div class="page-head">
                <h3 class="page-head-title">{{ __('third_party_api_credentials') }}</h3>
                <router-link to="/settings"
                    class="btn btn-outline-secondary ms-auto d-inline-flex align-items-center gap-1">
                    <ArrowLeft :size="16" /> {{ __('back') }}
                </router-link>
            </div>

            <section class="section">
                <!-- Maps Section -->
                <div class="card mb-4">
                    <div class="card-body">
                        <form method="post" enctype="multipart/form-data" @submit.prevent="saveMapsSettings">
                            <h5 class="mb-1">{{ __('maps') }}</h5>
                            <p class="text-muted small">{{ __('maps_hint') }}</p>
                            <div class="row">
                                <div class="form-group col-md-6">
                                    <label for="map_provider">
                                        {{ __('map_provider') }}
                                        <i class="fa fa-circle-info text-muted ms-1" v-b-tooltip.hover
                                            :title="__('map_provider_tooltip')"></i>
                                    </label>
                                    <AppSelect class="form-select" v-model="store_settings.map_provider" :options="map_providerOptions" :searchable="false" />
                                    <small class="text-muted">{{ __('map_provider_help') }}</small>
                                </div>
                            </div>
                            <div class="row" v-if="store_settings.map_provider === 'google'">
                                <div class="form-group col-md-6 mt-0">
                                    <label for="google_place_api_key">{{ __('place_api_key')
                                    }}</label>
                                    <input type="text" class="form-control" name="google_place_api_key"
                                        id="google_place_api_key"
                                        :value="shouldHideThirdPartyValues ? '' : store_settings.google_place_api_key"
                                        @input="!shouldHideThirdPartyValues && (store_settings.google_place_api_key = $event.target.value)"
                                        :placeholder="shouldHideThirdPartyValues ? __('demo_mode') : 'Google Place Api Key'"
                                        :readonly="shouldHideThirdPartyValues">
                                    <input type="hidden" class="form-control" name="apiKey" id="apiKey"
                                        v-model="store_settings.apiKey" placeholder="apiKey">

                                </div>
                                <div class="form-group col-md-6">
                                    <label for="google_map_api_key">{{ __('map_api_key')
                                    }}</label>
                                    <input type="text" class="form-control" name="google_map_api_key"
                                        id="google_map_api_key"
                                        :value="shouldHideThirdPartyValues ? '' : store_settings.google_map_api_key"
                                        @input="!shouldHideThirdPartyValues && (store_settings.google_map_api_key = $event.target.value)"
                                        :placeholder="shouldHideThirdPartyValues ? __('demo_mode') : 'Google Map Api Key'"
                                        :readonly="shouldHideThirdPartyValues">
                                    <input type="hidden" class="form-control" name="googleMapApiKey"
                                        id="googleMapApiKey" v-model="store_settings.googleMapApiKey"
                                        placeholder="googleMapApiKey">
                                </div>
                            </div>

                            <div class="row justify-content-end mt-3">
                                <div class="form-group col-auto">
                                    <b-button type="submit" variant="primary"
                                        :disabled="isLoadingMaps || shouldHideThirdPartyValues"
                                        v-if="$can('manage_api_credentials') && ($isDemo != 1 || (login_user && login_user.id === 1))">
                                        {{ __('save_maps_settings') || 'Save Maps Settings' }}
                                        <b-spinner v-if="isLoadingMaps" small label="Spinning"></b-spinner>
                                    </b-button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Payment Gateways Section -->
                <div class="card mb-4">
                    <div class="card-body">
                        <form method="post" enctype="multipart/form-data" @submit.prevent="savePaymentGatewaySettings">
                            <h5 class="mb-1">{{ __('payment_gateways') || 'Payment Gateways' }}</h5>
                            <p class="text-muted small">{{ __('payment_gateways_hint') || 'Configure payment gateway integrations for online payments' }}</p>
                            
                            <!-- Payment Gateway Selection Tabs -->
                            <ul class="nav nav-tabs mb-3" role="tablist">
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link active" id="cashfree-tab" data-bs-toggle="tab" data-bs-target="#cashfree-content" type="button" role="tab" aria-controls="cashfree-content" aria-selected="true">
                                        Cashfree
                                    </button>
                                </li>
                            </ul>

                            <!-- Cashfree Tab Content -->
                            <div class="tab-content" id="paymentGatewayContent">
                                <div class="tab-pane fade show active" id="cashfree-content" role="tabpanel" aria-labelledby="cashfree-tab">
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <div class="form-check form-switch">
                                                <input class="form-check-input" type="checkbox" name="cashfree_status" id="cashfree_status"
                                                    :checked="store_settings.cashfree_status == 1"
                                                    @change="store_settings.cashfree_status = $event.target.checked ? 1 : 0"
                                                    :disabled="shouldHideThirdPartyValues">
                                                <label class="form-check-label" for="cashfree_status">
                                                    {{ store_settings.cashfree_status == 1 ? __('enabled') : __('disabled') }}
                                                </label>
                                            </div>
                                        </div>
                                    </div>

                                    <div v-if="store_settings.cashfree_status == 1" class="card bg-light p-3">
                                        <div class="row">
                                            <div class="form-group col-md-6">
                                                <label for="cashfree_mode">{{ __('environment') || 'Environment' }}</label>
                                                <AppSelect class="form-select" name="cashfree_mode" id="cashfree_mode"
                                                    v-model="store_settings.cashfree_mode"
                                                    :options="cashfreeEnvironmentOptions"
                                                    :searchable="false"
                                                    :disabled="shouldHideThirdPartyValues" />
                                                <small class="text-muted">{{ __('cashfree_mode_help') || 'Select Live or Test environment' }}</small>
                                            </div>
                                        </div>

                                        <div class="row">
                                            <div class="form-group col-md-6">
                                                <label for="cashfree_app_id">{{ __('app_id') || 'App Id' }} <span class="text-danger">*</span></label>
                                                <input type="text" class="form-control" name="cashfree_app_id" id="cashfree_app_id"
                                                    :value="shouldHideThirdPartyValues ? '' : store_settings.cashfree_app_id"
                                                    @input="!shouldHideThirdPartyValues && (store_settings.cashfree_app_id = $event.target.value)"
                                                    :placeholder="shouldHideThirdPartyValues ? __('demo_mode') : 'Enter Cashfree App ID'"
                                                    :readonly="shouldHideThirdPartyValues">
                                                <small class="text-muted">{{ __('cashfree_app_id_help') || 'Your Cashfree App ID from dashboard' }}</small>
                                            </div>
                                            <div class="form-group col-md-6">
                                                <label for="cashfree_secret_key">{{ __('secret_key') || 'Secret Key' }} <span class="text-danger">*</span></label>
                                                <input type="password" class="form-control" name="cashfree_secret_key" id="cashfree_secret_key"
                                                    :value="shouldHideThirdPartyValues ? '' : store_settings.cashfree_secret_key"
                                                    @input="!shouldHideThirdPartyValues && (store_settings.cashfree_secret_key = $event.target.value)"
                                                    :placeholder="shouldHideThirdPartyValues ? __('demo_mode') : 'Enter Secret Key'"
                                                    :readonly="shouldHideThirdPartyValues">
                                                <small class="text-muted">{{ __('cashfree_secret_key_help') || 'Your Cashfree Secret Key from dashboard' }}</small>
                                            </div>
                                        </div>

                                        <div class="row">
                                            <div class="form-group col-md-6">
                                                <label for="cashfree_title">{{ __('payment_gateway_title') || 'Payment Gateway Title' }} <span class="text-danger">*</span></label>
                                                <input type="text" class="form-control" name="cashfree_title" id="cashfree_title"
                                                    v-model="store_settings.cashfree_title"
                                                    placeholder="e.g., Cashfree Payment"
                                                    :readonly="shouldHideThirdPartyValues">
                                                <small class="text-muted">{{ __('payment_gateway_title_help') || 'Title displayed to customers during checkout' }}</small>
                                            </div>
                                            <div class="form-group col-md-6">
                                                <label for="cashfree_logo">{{ __('logo') || 'Logo' }}</label>
                                                <input type="file" class="form-control" name="cashfree_logo" id="cashfree_logo"
                                                    accept="image/*"
                                                    @change="handleCashfreeLogo"
                                                    :disabled="shouldHideThirdPartyValues">
                                                <small class="text-muted">{{ __('logo_help') || 'Upload payment gateway logo (PNG, JPG, etc.)' }}</small>
                                                <div v-if="store_settings.cashfree_logo_preview" class="mt-2">
                                                    <small class="d-block mb-2">New preview:</small>
                                                    <img :src="store_settings.cashfree_logo_preview" alt="Cashfree Logo" style="max-width: 150px; max-height: 100px;">
                                                </div>
                                                <div v-else-if="store_settings.cashfree_logo && store_settings.cashfree_logo.trim()" class="mt-2">
                                                    <small class="d-block mb-2">Current logo:</small>
                                                    <img :src="getCashfreeLogoUrl()" alt="Cashfree Logo" style="max-width: 150px; max-height: 100px;" @error="(e) => e.target.style.display='none'">
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="row justify-content-end mt-3">
                                <div class="form-group col-auto">
                                    <b-button type="submit" variant="primary"
                                        :disabled="isLoadingPayment || shouldHideThirdPartyValues"
                                        v-if="$can('manage_api_credentials') && ($isDemo != 1 || (login_user && login_user.id === 1))">
                                        {{ __('save_payment_gateway_settings') || 'Save Payment Gateway' }}
                                        <b-spinner v-if="isLoadingPayment" small label="Spinning"></b-spinner>
                                    </b-button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Storage Settings Section -->
                <div class="card mb-4">
                    <div class="card-body">
                        <form method="post" enctype="multipart/form-data" @submit.prevent="saveStorageSettings">
                            <h5 class="mb-1">{{ __('storage_settings') || 'Storage Settings' }}</h5>
                            <p class="text-muted small">{{ __('storage_settings_hint') || 'Configure cloud storage providers for images and video files' }}</p>
                            
                            <!-- Storage Provider Selection Tabs -->
                            <ul class="nav nav-tabs mb-3" role="tablist">
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link active" id="cloudinary-tab" data-bs-toggle="tab" data-bs-target="#cloudinary-content" type="button" role="tab" aria-controls="cloudinary-content" aria-selected="true">
                                        Cloudinary
                                    </button>
                                </li>
                            </ul>

                            <!-- Cloudinary Tab Content -->
                            <div class="tab-content" id="storageProviderContent">
                                <div class="tab-pane fade show active" id="cloudinary-content" role="tabpanel" aria-labelledby="cloudinary-tab">
                                    <div class="card bg-light p-3 mb-3">
                                        <div class="row">
                                            <div class="col-12">
                                                <p class="text-muted small mb-2">
                                                    <strong>Cloudinary</strong> is a cloud service that offers a solution to manage images and videos for websites and apps.
                                                    <a href="https://cloudinary.com" target="_blank" rel="noopener noreferrer">Learn More</a>
                                                </p>
                                            </div>
                                        </div>

                                        <div class="row">
                                            <div class="form-group col-md-6">
                                                <label for="cloudinary_cloud_name">{{ __('cloud_name') || 'Cloud Name' }} <span class="text-danger">*</span></label>
                                                <input type="text" class="form-control" name="cloudinary_cloud_name" id="cloudinary_cloud_name"
                                                    :value="shouldHideThirdPartyValues ? '' : store_settings.cloudinary_cloud_name"
                                                    @input="!shouldHideThirdPartyValues && (store_settings.cloudinary_cloud_name = $event.target.value)"
                                                    :placeholder="shouldHideThirdPartyValues ? __('demo_mode') : 'Your Cloudinary cloud name'"
                                                    :readonly="shouldHideThirdPartyValues">
                                                <small class="text-muted">{{ __('cloudinary_cloud_name_help') || 'Your unique Cloudinary account identifier' }}</small>
                                            </div>
                                            <div class="form-group col-md-6">
                                                <label for="cloudinary_api_key">{{ __('api_key') || 'API Key' }} <span class="text-danger">*</span></label>
                                                <input type="text" class="form-control" name="cloudinary_api_key" id="cloudinary_api_key"
                                                    :value="shouldHideThirdPartyValues ? '' : store_settings.cloudinary_api_key"
                                                    @input="!shouldHideThirdPartyValues && (store_settings.cloudinary_api_key = $event.target.value)"
                                                    :placeholder="shouldHideThirdPartyValues ? __('demo_mode') : 'Your Cloudinary API Key'"
                                                    :readonly="shouldHideThirdPartyValues">
                                                <small class="text-muted">{{ __('cloudinary_api_key_help') || 'API Key from your Cloudinary dashboard' }}</small>
                                            </div>
                                        </div>

                                        <div class="row">
                                            <div class="form-group col-md-6">
                                                <label for="cloudinary_api_secret">{{ __('api_secret') || 'API Secret' }} <span class="text-danger">*</span></label>
                                                <input type="password" class="form-control" name="cloudinary_api_secret" id="cloudinary_api_secret"
                                                    :value="shouldHideThirdPartyValues ? '' : store_settings.cloudinary_api_secret"
                                                    @input="!shouldHideThirdPartyValues && (store_settings.cloudinary_api_secret = $event.target.value)"
                                                    :placeholder="shouldHideThirdPartyValues ? __('demo_mode') : 'Your Cloudinary API Secret'"
                                                    :readonly="shouldHideThirdPartyValues">
                                                <small class="text-muted">{{ __('cloudinary_api_secret_help') || 'API Secret from your Cloudinary dashboard' }}</small>
                                            </div>
                                        </div>

                                        <div class="row mt-3">
                                            <div class="col-12">
                                                <h6 class="mb-2">{{ __('supported_formats') || 'Supported Formats' }}</h6>
                                                <div class="alert alert-info mb-0">
                                                    <strong>Images:</strong> PNG, JPEG, JPG, WEBP, GIF, SVG, BMP<br>
                                                    <strong>Videos:</strong> MP4, WEBM, MOV, AVI<br>
                                                    <small class="text-muted d-block mt-2">Cloudinary supports all major image formats and video formats</small>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="row justify-content-end mt-3">
                                <div class="form-group col-auto">
                                    <b-button type="submit" variant="primary"
                                        :disabled="isLoadingStorage || shouldHideThirdPartyValues"
                                        v-if="$can('manage_api_credentials') && ($isDemo != 1 || (login_user && login_user.id === 1))">
                                        {{ __('save_storage_settings') || 'Save Storage Settings' }}
                                        <b-spinner v-if="isLoadingStorage" small label="Spinning"></b-spinner>
                                    </b-button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- AI & Analytics Section (Combined) -->
                <div class="card mb-4">
                    <div class="card-body">
                        <form method="post" enctype="multipart/form-data" @submit.prevent="saveThirdPartyApiSetting">
                            <p class="text-muted small">{{ __('gemini_key_hint') }}</p>
                            <div class="row">
                                <div class="form-group col-md-6">
                                    <label for="text_gen_key ">{{ __('gemini_key') }}</label>
                                    <input type="text" class="form-control" name="text_gen_key" id="text_gen_key"
                                        :value="shouldHideThirdPartyValues ? '' : store_settings.text_gen_key"
                                        @input="!shouldHideThirdPartyValues && (store_settings.text_gen_key = $event.target.value)"
                                        :placeholder="shouldHideThirdPartyValues ? __('demo_mode') : 'Gemini Key'"
                                        :readonly="shouldHideThirdPartyValues">
                                </div>
                            </div>
                            <hr>
                            <h5 class="mb-1">{{ __('microsoft_clarity') }}</h5>
                            <p class="text-muted small">{{ __('microsoft_clarity_hint') }}</p>

                            <!-- One Clarity project per surface — they are tracked separately. -->
                            <div class="row" v-for="s in claritySurfaces" :key="s.key">
                                <div class="form-group col-md-6">
                                    <label :for="'clarity_project_id_' + s.key">{{ s.label }}</label>
                                    <input type="text" class="form-control" :id="'clarity_project_id_' + s.key"
                                        :value="shouldHideThirdPartyValues ? '' : store_settings['clarity_project_id_' + s.key]"
                                        @input="!shouldHideThirdPartyValues && (store_settings['clarity_project_id_' + s.key] = $event.target.value)"
                                        :placeholder="shouldHideThirdPartyValues ? __('demo_mode') : 'abcd1234xy'"
                                        :readonly="shouldHideThirdPartyValues">
                                </div>
                                <div class="form-group col-md-6">
                                    <label class="d-block">{{ __('status') }}</label>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox"
                                            :id="'clarity_status_' + s.key"
                                            :checked="store_settings['clarity_status_' + s.key] == 1"
                                            @change="store_settings['clarity_status_' + s.key] = $event.target.checked ? 1 : 0"
                                            :disabled="shouldHideThirdPartyValues">
                                        <label class="form-check-label" :for="'clarity_status_' + s.key">
                                            {{ store_settings['clarity_status_' + s.key] == 1 ? __('enabled') : __('disabled') }}
                                        </label>
                                    </div>
                                </div>
                            </div>
                            <small class="text-muted d-block mb-3">{{ __('clarity_project_id_hint') }}</small>

                            <div class="row justify-content-end">
                                <div class="form-group col-auto">
                                    <b-button type="submit" variant="primary"
                                        :disabled="isLoading || shouldHideThirdPartyValues"
                                        v-if="$can('manage_api_credentials') && ($isDemo != 1 || (login_user && login_user.id === 1))">{{
                                            __('save_ai_analytics_settings') || 'Save AI & Analytics' }}
                                        <b-spinner v-if="isLoading" small label="Spinning"></b-spinner>
                                    </b-button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </section>
        </div>
    </div>
</template>
<script>
import axios from "axios";
import Auth from '../../Auth.js';
import CryptoJS from "crypto-js";
import { ArrowLeft } from 'lucide-vue-next';
import StoreSettingsPage from '../../mixins/StoreSettingsPage.js';

export default {
    name: 'ApiCredentials',
    mixins: [StoreSettingsPage],
    components: { ArrowLeft },
    data() {
        return {
            login_user: Auth.user,
            isLoading: false,
            isLoadingMaps: false,
            isLoadingPayment: false,
            isLoadingStorage: false,
            store_settings: {
                google_place_api_key: '',
                google_map_api_key: '',
                apiKey: '',
                googleMapApiKey: '',
                text_gen_key: '',
                map_provider: 'osm',
                clarity_project_id_panel: '', clarity_status_panel: 0,
                clarity_project_id_delivery_boy: '', clarity_status_delivery_boy: 0,
                clarity_project_id_web: '', clarity_status_web: 0,
                clarity_project_id_customer: '', clarity_status_customer: 0,
                cashfree_status: 0,
                cashfree_mode: 'test',
                cashfree_app_id: '',
                cashfree_secret_key: '',
                cashfree_title: 'Cashfree Payment',
                cashfree_logo: '',
                cashfree_logo_preview: '',
                cloudinary_cloud_name: '',
                cloudinary_api_key: '',
                cloudinary_api_secret: '',
            },
        };
    },
    computed: {
        // Fixed option set — no search box needed.
        map_providerOptions() {
            return [
                { id: 'osm', name: (__('openstreetmap_free')) },
                { id: 'google', name: (__('google_paid')) },
            ];
        },
        cashfreeEnvironmentOptions() {
            return [
                { id: 'test', name: __('test_mode') || 'Test Mode' },
                { id: 'live', name: __('live_mode') || 'Live Mode' },
            ];
        },
        claritySurfaces() {
            return [
                // The panel is one SPA for admin + delivery boy, so a single project.
                { key: 'panel', label: __('admin_and_delivery_boy_panel') },
                { key: 'web', label: __('website') },
                { key: 'delivery_boy', label: __('delivery_boy_app') },
                { key: 'customer', label: __('customer_app') },
            ];
        },
        // Hide third party credential values in demo mode, except for auth user id 1
        shouldHideThirdPartyValues() {
            return this.$isDemo == 1 && (!this.login_user || this.login_user.id !== 1);
        },
    },
    watch: {
        // The panel keeps a plain copy of each Google key alongside the encrypted one.
        'store_settings.google_place_api_key'(newValue) {
            this.store_settings.apiKey = newValue;
        },
        'store_settings.google_map_api_key'(newValue) {
            this.store_settings.googleMapApiKey = newValue;
        },
    },

    created() {
        this.loadStoreSettings().then(() => this.decryptGoogleKeys());
    },
    methods: {
        /**
         * The two Google keys are stored AES-encrypted, so decrypt them for display.
         * Wrapped per key — decrypt() throws on a value that was never encrypted.
         */
        decryptGoogleKeys() {
            const secretKey = 'ewgrrtoecaemr';
            ['google_place_api_key', 'google_map_api_key'].forEach(field => {
                const value = this.store_settings[field];
                if (!value) return;
                try {
                    const plain = CryptoJS.AES.decrypt(value, secretKey).toString(CryptoJS.enc.Utf8);
                    if (plain) this.store_settings[field] = plain;
                } catch (e) {
                    console.warn(field + ' decrypt failed:', e);
                }
            });
        },

        saveThirdPartyApiSetting() {
            this.isLoading = true;
            const formData = new FormData();
            const apiFields = ['google_place_api_key', 'google_map_api_key', 'apiKey', 'googleMapApiKey', 'text_gen_key', 'map_provider',
                'clarity_project_id_panel', 'clarity_status_panel',
                'clarity_project_id_delivery_boy', 'clarity_status_delivery_boy',
                'clarity_project_id_web', 'clarity_status_web',
                'clarity_project_id_customer', 'clarity_status_customer',
                'cashfree_status', 'cashfree_mode', 'cashfree_app_id', 'cashfree_secret_key', 'cashfree_title',
                'cloudinary_cloud_name', 'cloudinary_api_key', 'cloudinary_api_secret'];

            apiFields.forEach(field => {
                if (this.store_settings[field] === undefined) return;
                let value = this.store_settings[field];
                // Only the two Google keys are stored encrypted.
                if ((field === 'google_place_api_key' || field === 'google_map_api_key') && value) {
                    value = CryptoJS.AES.encrypt(value, 'ewgrrtoecaemr').toString();
                }
                formData.append(field, value);
            });

            // Add logo file if selected - will be uploaded to Cloudinary by the controller
            if (this.store_settings.cashfree_logo_file) {
                formData.append('cashfree_logo', this.store_settings.cashfree_logo_file);
            }

            axios.post(this.$apiUrl + '/store_settings/save_third_party_api_setting', formData)
                .then(res => {
                    if (res.data.status === 1) {
                        this.showMessage('success', res.data.message + (this.store_settings.cashfree_logo_file ? ' Logo uploaded to Cloudinary.' : ''));
                        // Clear the preview and file input after successful save
                        this.store_settings.cashfree_logo_file = null;
                        this.store_settings.cashfree_logo_preview = null;
                        const logoInput = document.getElementById('cashfree_logo');
                        if (logoInput) logoInput.value = '';
                        return this.loadStoreSettings().then(() => this.decryptGoogleKeys());
                    } else {
                        this.showError(res.data.message);
                    }
                })
                .catch(error => {
                    this.showError(error?.response?.data?.message || error.message || __('something_went_wrong'));
                })
                .finally(() => { this.isLoading = false; });
        },
        handleCashfreeLogo(event) {
            const file = event.target.files[0];
            if (file) {
                this.store_settings.cashfree_logo_file = file;
                const reader = new FileReader();
                reader.onload = (e) => {
                    this.store_settings.cashfree_logo_preview = e.target.result;
                };
                reader.readAsDataURL(file);
            }
        },
        getCashfreeLogoUrl() {
            if (!this.store_settings.cashfree_logo) return '';
            // If it's already a full URL (from Cloudinary), use it directly
            if (this.store_settings.cashfree_logo.includes('http')) {
                return this.store_settings.cashfree_logo;
            }
            // Otherwise prepend storage path for local files
            return this.$apiUrl.replace('/api', '') + '/storage/' + this.store_settings.cashfree_logo;
        },

        saveMapsSettings() {
            this.isLoadingMaps = true;
            const formData = new FormData();
            const mapsFields = ['google_place_api_key', 'google_map_api_key', 'apiKey', 'googleMapApiKey', 'map_provider'];

            mapsFields.forEach(field => {
                if (this.store_settings[field] === undefined) return;
                let value = this.store_settings[field];
                // Only the two Google keys are stored encrypted.
                if ((field === 'google_place_api_key' || field === 'google_map_api_key') && value) {
                    value = CryptoJS.AES.encrypt(value, 'ewgrrtoecaemr').toString();
                }
                formData.append(field, value);
            });

            axios.post(this.$apiUrl + '/store_settings/save_maps_settings', formData)
                .then(res => {
                    if (res.data.status === 1) {
                        this.showMessage('success', res.data.message);
                        return this.loadStoreSettings().then(() => this.decryptGoogleKeys());
                    } else {
                        this.showError(res.data.message);
                    }
                })
                .catch(error => {
                    this.showError(error?.response?.data?.message || error.message || __('something_went_wrong'));
                })
                .finally(() => { this.isLoadingMaps = false; });
        },

        savePaymentGatewaySettings() {
            this.isLoadingPayment = true;
            const formData = new FormData();
            const paymentFields = ['cashfree_status', 'cashfree_mode', 'cashfree_app_id', 'cashfree_secret_key', 'cashfree_title'];

            paymentFields.forEach(field => {
                if (this.store_settings[field] !== undefined) {
                    formData.append(field, this.store_settings[field]);
                }
            });

            // Add logo file if selected - will be uploaded to Cloudinary by the controller
            if (this.store_settings.cashfree_logo_file) {
                formData.append('cashfree_logo', this.store_settings.cashfree_logo_file);
            }

            axios.post(this.$apiUrl + '/store_settings/save_payment_gateway_settings', formData)
                .then(res => {
                    if (res.data.status === 1) {
                        this.showMessage('success', res.data.message + (this.store_settings.cashfree_logo_file ? ' Logo uploaded to Cloudinary.' : ''));
                        // Clear the preview and file input after successful save
                        this.store_settings.cashfree_logo_file = null;
                        this.store_settings.cashfree_logo_preview = null;
                        const logoInput = document.getElementById('cashfree_logo');
                        if (logoInput) logoInput.value = '';
                        return this.loadStoreSettings();
                    } else {
                        this.showError(res.data.message);
                    }
                })
                .catch(error => {
                    this.showError(error?.response?.data?.message || error.message || __('something_went_wrong'));
                })
                .finally(() => { this.isLoadingPayment = false; });
        },

        saveStorageSettings() {
            this.isLoadingStorage = true;
            const formData = new FormData();
            const storageFields = ['cloudinary_cloud_name', 'cloudinary_api_key', 'cloudinary_api_secret'];

            storageFields.forEach(field => {
                if (this.store_settings[field] !== undefined) {
                    formData.append(field, this.store_settings[field]);
                }
            });

            axios.post(this.$apiUrl + '/store_settings/save_storage_settings', formData)
                .then(res => {
                    if (res.data.status === 1) {
                        this.showMessage('success', res.data.message);
                        return this.loadStoreSettings();
                    } else {
                        this.showError(res.data.message);
                    }
                })
                .catch(error => {
                    this.showError(error?.response?.data?.message || error.message || __('something_went_wrong'));
                })
                .finally(() => { this.isLoadingStorage = false; });
        },
    },
};
</script>
