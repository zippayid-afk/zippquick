<template>
    <div class="list-page">
        <!-- Title outside the card; the sortable list stays inside it. -->
        <div class="page-head">
            <h3 class="page-head-title">{{ __('main_categories_order_list') }}</h3>
        </div>

        <div class="card">
                        <div class="card-body">
                            <b-row>
                                <b-col md="12">
                                    <div class="mb-2 d-flex justify-content-between align-items-center">
                                        <div class="form-check form-switch">
                                            <label> <input type="checkbox" v-model="editable" class="form-check-input">
                                                {{ __('enable_drag_and_drop') }}</label>
                                        </div>
                                    </div>
                                </b-col>
                            </b-row>
                            <b-row>
                                <b-col md="12" style="overflow-y:scroll; overflow-x:auto; height:600px;">
                                    <ul ref="sortableList" class="list-group">
                                        <li v-for="category in list" :key="category.id" :data-id="category.id"
                                            class="list-group-item d-flex justify-content-between align-items-center">
                                            <span>
                                                <span class="text-left mr-2">{{ category.row_order }}</span>
                                                <span class="text-left mr-2">-</span>
                                                <span class="text-left mr-2">{{ category.id }}</span>
                                                <span class="text-left mr-2"><img :src="category.image_url"
                                                        height="30"></span>
                                                <span class="text-left mr-2">{{ getCategoryName(category) }}</span>
                                                <span v-if="category.all_parents" class="ml-4 all_parents">
                                                    <span class="ml-4"
                                                        v-for="(parent, index) in getParentsList(category.all_parents)"
                                                        :key="index">
                                                        <span class="d-inline-flex align-items-center">
                                                            <ChevronLeft :size="14" class="me-1 text-muted" />
                                                            <span class="text-left"><img :src="parent.image_url"
                                                                    height="20"
                                                                    :title="parent.id + '-' + getCategoryName(parent)"></span>
                                                        </span>
                                                    </span>
                                                </span>
                                            </span>
                                            <span class="cat-order-grip"><Move :size="16" /></span>
                                        </li>
                                    </ul>
                                </b-col>
                            </b-row>
                        </div>
        </div>
    </div>
</template>
<script>
import Sortable from 'sortablejs';
import axios from "axios";
import { ChevronLeft, Move } from 'lucide-vue-next';
export default {
    components: { ChevronLeft, Move },
    data: function () {
        return {
            categories: [],
            list: [],
            editable: true,
            isLoading: false,
            currentLanguageId: null,
            activeLanguages: [],
            sortableInstance: null,
        }
    },
    watch: {
        editable(val) {
            if (this.sortableInstance) {
                this.sortableInstance.option('disabled', !val);
            }
        },
    },
    created: function () {
        this.$eventBus.on('categorySaved', () => {
            this.getCategories();
        });
        this.fetchActiveLanguages().then(() => {
            this.getCategories();
        });
    },
    mounted() {
        this.$nextTick(() => {
            this.initSortable();
        });
    },
    beforeUnmount() {
        if (this.sortableInstance) {
            this.sortableInstance.destroy();
        }
    },
    methods: {
        initSortable() {
            if (!this.$refs.sortableList) return;
            this.sortableInstance = Sortable.create(this.$refs.sortableList, {
                animation: 200,
                ghostClass: 'ghost',
                disabled: !this.editable,
                onEnd: (evt) => {
                    const movedItem = this.list.splice(evt.oldIndex, 1)[0];
                    this.list.splice(evt.newIndex, 0, movedItem);
                    this.updateCategoriesOrder();
                },
            });
        },
        fetchActiveLanguages() {
            return axios.get(this.$apiUrl + '/active_languages')
                .then(response => {
                    if (response.data.data && Array.isArray(response.data.data)) {
                        this.activeLanguages = response.data.data;
                        const appLocale = window.appLocale || 'en';
                        const currentLanguage = this.activeLanguages.find(
                            lang => lang.code === appLocale
                        );
                        if (currentLanguage) {
                            this.currentLanguageId = currentLanguage.id;
                        } else {
                            const defaultLanguage = this.activeLanguages.find(
                                lang => lang.is_default === 1
                            );
                            if (defaultLanguage) {
                                this.currentLanguageId = defaultLanguage.id;
                            }
                        }
                    }
                })
                .catch(error => {
                    console.error('Error loading languages:', error);
                });
        },
        getCategoryName(category) {
            if (!category) return '';
            if (this.currentLanguageId && category.translations && Array.isArray(category.translations)) {
                const translation = category.translations.find(
                    t => t.language_id === this.currentLanguageId
                );
                if (translation && translation.name && translation.name.trim() !== '') {
                    return translation.name;
                }
            }
            return category.name || '';
        },
        getParentsList(parent) {
            let parents = [];
            while (parent) {
                parents.push(parent);
                parent = parent.all_parents;
            }
            return parents;
        },
        updateList() {
            this.list.forEach((category, index) => {
                category.row_order = index + 1;
            });
        },
        getCategories() {
            axios.get(this.$apiUrl + '/categories/row_order')
                .then((response) => {
                    let data = response.data;
                    this.list = data.data.map((category) => {
                        return {
                            id: category.id,
                            name: category.name,
                            row_order: category.row_order,
                            image_url: category.image_url,
                            all_parents: category.all_parents,
                            translations: category.translations,
                            fixed: false
                        };
                    });
                });
        },
        updateCategoriesOrder() {
            this.updateList();
            this.isLoading = true;
            let formData = this.list;
            let url = this.$apiUrl + '/categories/updateOrder';
            axios.post(url, formData).then(res => {
                let data = res.data;
                if (data.status === 1) {
                    this.showMessage("success", data.message);
                    this.isLoading = false;
                    this.getCategories();
                } else {
                    this.showError(data.message);
                    this.isLoading = false;
                }
            }).catch(error => {
                this.isLoading = false;
                if (error.request.statusText) {
                    this.showError(error.request.statusText);
                } else if (error.message) {
                    this.showError(error.message);
                } else {
                    this.showError(__('something_went_wrong'));
                }
            });
        },
    }
};
</script>
<style scoped>
.ghost {
    opacity: 0.5;
    background: #c8ebfb;
}

.list-group {
    min-height: 20px;
}

.list-group-item {
    cursor: move;
}

.list-group-item i {
    cursor: pointer;
}
</style>
