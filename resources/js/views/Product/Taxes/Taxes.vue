<template>
    <div>
        <div class="list-page">
            <div class="page-head">
                <h3 class="page-head-title">{{ __('taxes') }}</h3>
                <button v-if="$can('tax_create')"
                    class="btn btn-primary list-add-btn d-inline-flex align-items-center gap-2 text-nowrap"
                    @click="edit_record=true">
                    <Plus :size="16" />
                    <span>{{ __('add_tax') }}</span>
                </button>
            </div>

            <div class="list-surface">
                <div class="list-toolbar">
                    <AppSelect class="form-select list-select list-toolbar-start" v-model="statusFilter" :options="statusFilterOptions" :searchable="false" />
                    <div class="list-search">
                        <Search class="list-search-icon" />
                        <input
                            id="filter-input"
                            v-model="filter"
                            type="search"
                            class="form-control"
                            :placeholder="__('search')">
                    </div>

                    <button class="list-icon-btn" v-b-tooltip.hover :title="__('refresh')" @click="getRecords()">
                        <RefreshCw :class="{ 'is-spinning': isLoading }" />
                    </button>
                </div>

                <MazerDatatable responsive
                    :items="filteredTaxes"
                    :fields="fields"
                    :current-page="currentPage"
                    :per-page="perPage"
                    :filter="filter"
                    :filter-included-fields="filterOn"
                    v-model:sort-by="sortBy"
                    v-model:sort-desc="sortDesc"
                    :sort-direction="sortDirection"

                    :busy="isLoading"
                    stacked="md"
                    show-empty
                    small>
                    <template #cell(id)="row">
                        {{ row.item.id }}
                    </template>

                    <template #cell(image)="row">
                        <p v-if="row.item.image ===''">No Image</p>
                        <img :src="$storageUrl + row.item.image" class="list-thumb" v-else/>
                    </template>

                    <template #cell(status)="row">
                        <span v-if="row.item.status == 1" class="status-pill is-active">{{ __('active') }}</span>
                        <span v-else class="status-pill is-inactive">{{ __('deactive') }}</span>
                    </template>

                    <template #cell(actions)="row">
                        <div class="list-actions">
                            <span v-if="!$can('tax_update') && !$can('tax_delete')" class="text-muted small">—</span>
                            <button v-if="$can('tax_update')" class="list-action-btn is-edit" @click="edit_record = row.item" v-b-tooltip.hover :title="__('edit')"><Pencil :size="15" /></button>
                            <button v-if="$can('tax_delete')" class="list-action-btn is-delete" @click="deleteRecord(row.index,row.item.id)" v-b-tooltip.hover :title="__('delete')"><Trash2 :size="15" /></button>
                        </div>
                    </template>
                </MazerDatatable>

                <div class="list-footer">
                    <div class="list-perpage">
                        <span>{{ __('per_page') }}</span>
                        <b-form-select
                            id="per-page-select"
                            v-model="perPage"
                            :options="pageOptions"
                            size="sm"
                            class="form-select"
                        ></b-form-select>
                        <span class="list-range">{{__('total_records')}} : {{ filteredTaxes.length }}</span>
                    </div>

                    <b-pagination
                        v-model="currentPage"
                        :total-rows="filteredTaxes.length"
                        :per-page="perPage"
                        size="sm"
                        class="mb-0 list-pagination"
                    ></b-pagination>
                </div>
            </div>
        </div>

        <!-- Add / Edit -->
        <app-edit-record
            v-if="edit_record"
            :record="edit_record" 
            @modalClose="edit_record = null"
        ></app-edit-record>
    </div>

</template>
<script>
import EditRecord from './Edit.vue';
import { Search, RefreshCw, Plus, Pencil, Trash2 } from 'lucide-vue-next';


export default {
    components: {
            'app-edit-record' : EditRecord,
            Search, RefreshCw, Plus, Pencil, Trash2,
    },
    data: function() {
        return {
            fields: [
                { key: 'id', label: __('id'), class: 'text-center', sortable: true, sortDirection: 'desc' },
                { key: 'title', label: __('title'),  class: 'text-center', sortable: false },
                { key: 'percentage', label: __('percentage'),  class: 'text-center' },
                { key: 'status', label: __('status'),  class: 'text-center' },
                { key: 'actions', label: __('actions'), sortable: false }
            ],
            totalRows: 1,
            currentPage: 1,
            perPage: this.$perPage,
            pageOptions: this.$pageOptions,
            sortBy: '',
            sortDesc: false,
            sortDirection: 'asc',
            filter: null,
            statusFilter: '',
            filterOn: [],
            page: 1,

            sectionStyle : 'style_1',
            max_visible_units : 12,
            max_col_in_single_row : 3,

            taxes: [],
            isLoading: false,
            create_new : null,
            edit_record : null,
                        settingModalShow:false,
            currentLanguageId: null,
            activeLanguages: []

        }
    },
    computed: {
        // Fixed option set — no search box needed.
        statusFilterOptions() {
            return [
                { id: '', name: (__('all_statuses') || 'All statuses') },
                { id: '1', name: (__('active')) },
                { id: '0', name: (__('deactive')) },
            ];
        },
    // Status filter applied on top of the translated list (data is client-side).
    filteredTaxes() {
        if (this.statusFilter === '') return this.translatedTaxes;
        return this.translatedTaxes.filter(t => String(t.status) === String(this.statusFilter));
    },
    translatedTaxes() {
        if (!this.currentLanguageId || this.taxes.length === 0) {
            return this.taxes;
        }

        return this.taxes.map(tax => {
            const translatedTax = { ...tax };

            if (tax.translations && Array.isArray(tax.translations)) {
                const translation = tax.translations.find(
                    t => t.language_id === this.currentLanguageId
                );

                if (translation && translation.title && translation.title.trim() !== '') {
                    translatedTax.title = translation.title;
                }
            }

            return translatedTax;
        });
    }
}
,   
    created: function() {
        this._recordSavedHandler = (message) => {
            this.showMessage('success', message);
            this.getRecords();
        };
        this.$eventBus.on('recordSaved', this._recordSavedHandler);

        this.fetchActiveLanguages().then(() => {
            this.getRecords();
        });
    },
    beforeUnmount: function() {
        this.$eventBus.off('recordSaved', this._recordSavedHandler);
    },
    methods: {
                fetchActiveLanguages() {
            return axios.get(this.$apiUrl + '/active_languages')
                .then(response => {
                    if (response.data.data && Array.isArray(response.data.data)) {
                        this.activeLanguages = response.data.data;
                        
                        const appLocale = window.appLocale || 'en';
                        
                        // Find language ID for current app_locale code
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

        getRecords(){
            this.isLoading = true
            axios.get(this.$apiUrl + '/products/taxes')
                .then((response) => {
                    this.isLoading = false
                    let data = response.data;
                    this.taxes = data.data;
                    this.totalRows = this.taxes.length
                });
        },
        deleteRecord(index, id){
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

                if (result.value) {
                    this.isLoading = true
                    let postData = {
                        id : id
                    }
                    axios.post(this.$apiUrl + '/products/taxes/delete',postData)
                        .then((response) => {
                            this.isLoading = false
                            let data = response.data;
                            this.taxes.splice(index, 1)
                            //this.showSuccess(data.message);
                            this.showMessage('success', data.message);
                        });
                }
            });
        },
    }
};
</script>
