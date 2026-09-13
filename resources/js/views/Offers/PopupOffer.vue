<template>
    <div>
        <div class="page-heading">
            <div class="page-title">
            </div>
        </div>
        <section class="section">
            <div class="row">
                <div class="col-12 col-md-12 order-md-1 order-last">
                    <form method="post" enctype="multipart/form-data" @submit.prevent="saveRecord">
                        <div class="card">
                            <div class="card-header">
                                <h4 class="card-title">{{ __('popup_offer') }}</h4>
                                <span class="float-end">
                                    <button type="button" class="btn btn-primary btn_refresh" v-b-tooltip.hover
                                        :title="__('refresh')" @click="getPopupData()">
                                        <i class="fa fa-refresh" aria-hidden="true"></i>
                                    </button>
                                </span>
                            </div>
                            <div class="card-body">
                                <div class="row">

                                    <div class="form-group col-md-4">
                                        <div class="form-group">
                                            <label class="control-label"> {{ __('popup_offer_enabled_on_off')
                                                }}</label><br>
                                            <div class="btn-group btn-group-toggle" role="group">
                                                <label class="btn btn-outline-primary" :class="{ active: popup_enabled == 1 }">
                                                    <input type="radio" :value="1" v-model.number="popup_enabled" autocomplete="off"> {{ __('ON') }}
                                                </label>
                                                <label class="btn btn-outline-primary" :class="{ active: popup_enabled == 0 }">
                                                    <input type="radio" :value="0" v-model.number="popup_enabled" autocomplete="off"> {{ __('OFF') }}
                                                </label>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="form-group col-md-4">
                                        <label> {{ __('type') }}</label>
                                        <AppSelect class="form-control form-select" v-model="popup_type" :options="popup_typeOptions" :searchable="false" @update:model-value="popup_type_id = ''" />
                                    </div>
                                    <div class="col-md-6">
                                            <div class="form-group" v-if="popup_type == 'category'">
                                                <label>{{ __('category') }}</label>
                                                <AppSelect class="form-control form-select" v-model="popup_type_id"
                                                    :options="categories" :placeholder="__('select_category')" />
                                            </div>
                                            <div class="form-group" v-if="popup_type == 'product'">
                                                <label> {{ __('products') }}</label>
                                                <AppSelect class="form-control form-select" v-model="popup_type_id"
                                                    :options="products" :placeholder="__('select_product')" />
                                            </div>
                                            <div class="form-group" v-if="popup_type == 'popup_url'">
                                                <label> {{ __('link') }}</label>
                                                <input type="url" class="form-control" v-model="popup_url"
                                                    placeholder="Enter Link">
                                            </div>
                                        </div>
                                    <div class="col-md-12">
                                            <FileUpload v-model="image" :label="__('image')" accept="image/*"
                                                :recommended-text="__('please_choose_square_image_of_larger_than_500_500')"
                                                :max-size-mb="2" :preview-url="image_url" />
                                        </div>
                                    </div>
                                </div>
                            <div class="card-footer">
                                <b-button type="submit" variant="primary" :disabled="isLoading">{{ __('save') }}
                                    <b-spinner v-if="isLoading" small label="Spinning"></b-spinner>
                                </b-button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </section>
    </div>
</template>
<script>
import axios from "axios";

export default {
    data: function () {
        return {
            isLoading: false,
            categories: [],
            products: [],

            popup_enabled: 0,
            popup_always_show_home: 0,
            popup_type: 'default',
            popup_type_id: "",
            popup_slug: "",
            image: "",
            image_url: "",
            popup_url: "",
        };
    },

    created: function () {
        this.getCategories();
        this.getProducts();
        this.getPopupData();
    },
    computed: {
        // Fixed option set — no search box needed.
        popup_typeOptions() {
            return [
                { id: 'default', name: (__('default')) },
                { id: 'category', name: (__('category')) },
                { id: 'product', name: (__('product')) },
                { id: 'popup_url', name: (__('popup_url')) },
            ];
        },
    },
    methods: {
        getPopupData() {
            axios.get(this.$apiUrl + '/popup').then((response) => {
                if (response.data.data) {
                    this.record = response.data.data;
                    this.popup_enabled = this.record.popup_enabled ?? 0;
                    this.popup_always_show_home = this.record.popup_always_show_home ?? 0;
                    this.popup_type = this.record.popup_type;
                    this.popup_type_id = this.record.popup_type_id;
                    this.popup_slug = this.record.popup_slug || "";
                    this.popup_url = this.record.popup_url;
                    this.image_url = this.record.popup_image ?? "";
                }
            }).catch(error => {
                if (error.request.statusText) {
                    this.showError(error.request.statusText);
                } else if (error.message) {
                    this.showError(error.message);
                } else {
                    this.showError(__('something_went_wrong'));
                }
            });
        },
        getCategories() {
            this.isLoading = true
            axios.get(this.$apiUrl + '/categories/active')
                .then((response) => {
                    this.isLoading = false
                    this.categories = response.data.data;
                }).catch(error => {
                    if (error.request.statusText) {
                        this.showError(error.request.statusText);
                    } else if (error.message) {
                        this.showError(error.message);
                    } else {
                        this.showError(__('something_went_wrong'));
                    }
                    this.isLoading = false;
                });
        },
        getProducts() {
            this.isLoading = true
            axios.get(this.$apiUrl + '/products/active')
                .then((response) => {
                    this.isLoading = false
                    this.products = response.data.data;
                }).catch(error => {
                    if (error.request.statusText) {
                        this.showError(error.request.statusText);
                    } else if (error.message) {
                        this.showError(error.message);
                    } else {
                        this.showError(__('something_went_wrong'));
                    }
                    this.isLoading = false;
                });
        },

        saveRecord: function () {
            this.isLoading = true;

            // Resolve slug from selected category/product before sending payload
            let slug = "";
            if (this.popup_type === "category" && this.popup_type_id) {
                const cat = this.categories.find(c => String(c.id) === String(this.popup_type_id));
                slug = cat && cat.slug ? cat.slug : "";
            } else if (this.popup_type === "product" && this.popup_type_id) {
                const prod = this.products.find(p => String(p.id) === String(this.popup_type_id));
                slug = prod && prod.slug ? prod.slug : "";
            }
            if (this.popup_type === "default" || this.popup_type === "popup_url") {
                slug = "";
            }
            this.popup_slug = slug;

            let formData = new FormData();
            formData.append('popup_enabled', this.popup_enabled);
            formData.append('popup_always_show_home', this.popup_always_show_home);
            formData.append('popup_type', this.popup_type);
            formData.append('popup_type_id', this.popup_type_id);
            formData.append('popup_slug', this.popup_slug || "");
            formData.append('popup_image', this.image);
            formData.append('popup_url', this.popup_url);

            let url = this.$apiUrl + '/popup/save';
            let vm = this;

            axios.post(url, formData).then(res => {

                let data = res.data;
                if (data.status === 1) {
                    //this.showSuccess(data.message);
                    this.showMessage("success", data.message);
                    setTimeout(
                        function () {
                            vm.$swal.close();
                            vm.$router.push({ path: '/popup' });
                            vm.getPopupData();
                            vm.isLoading = false;

                        }, 1000);
                } else {
                    vm.showError(data.message);
                    vm.isLoading = false;
                }

            }).catch(error => {
                if (error.request.statusText) {
                    this.showError(error.request.statusText);
                } else if (error.message) {
                    this.showError(error.message);
                } else {
                    this.showError(__('something_went_wrong'));
                }
                vm.isLoading = false;
            });

        }
    }
}
</script>
