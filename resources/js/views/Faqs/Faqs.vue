<template>
    <div class="list-page">
        <div class="page-head">
            <h3 class="page-head-title">{{ __('faqs_list') }}</h3>

            <button
                class="btn btn-primary list-add-btn d-inline-flex align-items-center gap-2 text-nowrap"
                @click="create_new = true"
                v-if="$can('faq_create')">
                <Plus :size="16" />
                <span>{{ __('add') }}</span>
            </button>
        </div>

        <div class="list-surface">
            <div class="list-toolbar">
                <div class="list-search">
                    <Search class="list-search-icon" />
                    <input
                        id="filter-input"
                        v-model="filter"
                        type="search"
                        class="form-control"
                        :placeholder="__('search')">
                </div>

                <button class="list-icon-btn" v-b-tooltip.hover :title="__('refresh')" @click="getFaqs()">
                    <RefreshCw :class="{ 'is-spinning': isLoading }" />
                </button>
            </div>

            <MazerDatatable responsive :items="translatedFaqs" :fields="fields" :current-page="currentPage"
                :per-page="perPage" :filter="filter" :filter-included-fields="filterOn"
                v-model:sort-by="sortBy" v-model:sort-desc="sortDesc" :sort-direction="sortDirection"
                :busy="isLoading" stacked="md" show-empty small
                @filtered="(items, count) => totalRows = count">
                <template #cell(faqs)="row">
                    <a href="javascript:void(0)" style="color:#435ebe;">{{ row.item.question }}</a>
                    <div class="faq-answer">
                        <p class="mb-0"
                            v-if="!shouldTruncate(row.item.answer) || expandedFaqs[row.item.id]">
                            {{ row.item.answer }}
                        </p>
                        <p class="mb-0" v-else>
                            {{ getTruncatedText(row.item.answer) }}
                        </p>
                        <!-- View More/Less Link -->
                        <a v-if="shouldTruncate(row.item.answer)"
                            @click="toggleFaqExpansion(row.item.id)" href="javascript:void(0)"
                            style="color: #007bff; font-size: 12px; cursor: pointer;">
                            {{ expandedFaqs[row.item.id] ? __('view_less') : __('view_more') }}
                        </a>
                    </div>
                </template>
                <template #cell(actions)="row">
                    <div class="list-actions">
                        <button class="list-action-btn is-edit" @click="edit_record = row.item"
                            v-b-tooltip.hover :title="__('edit')" v-if="$can('faq_update')">
                            <Pencil :size="15" />
                        </button>
                        <button class="list-action-btn is-delete"
                            @click="deleteSocialMedia(row.index, row.item.id)" v-b-tooltip.hover
                            :title="__('delete')" v-if="$can('faq_delete')"><Trash2 :size="15" /></button>
                    </div>
                </template>
            </MazerDatatable>

            <div class="list-footer">
                <div class="list-perpage">
                    <span>{{ __('per_page') }}</span>
                    <b-form-select id="per-page-select" v-model="perPage" :options="pageOptions"
                        size="sm" class="form-select"></b-form-select>
                </div>

                <b-pagination v-model="currentPage" :total-rows="totalRows" :per-page="perPage"
                    size="sm" class="mb-0 list-pagination"></b-pagination>
            </div>
        </div>

        <!-- Add / Edit -->
        <app-edit-record v-if="create_new || edit_record" :record="edit_record"
            @saved="onFaqSaved" @modalClose="hideModal()"></app-edit-record>
    </div>
</template>
<script>
import EditRecord from './Edit.vue';
import axios from "axios";
import { Search, RefreshCw, Plus, Pencil, Trash2 } from 'lucide-vue-next';

export default {
    components: {
        'app-edit-record': EditRecord,
        Search, RefreshCw, Plus, Pencil, Trash2,
    },
    data: function () {
        return {
            fields: [
                { key: 'faqs', label: __('frequently_asked_questions'), sortable: false, class: 'text-center' },
                { key: 'actions', label: __('actions'), sortable: false, class: 'text-center' }
            ],
            totalRows: 1,
            currentPage: 1,
            perPage: this.$perPage,
            pageOptions: this.$pageOptions,
            sortBy: '',
            sortDesc: false,
            sortDirection: 'asc',
filter: null,
            filterOn: ['question', 'answer'],
            page: 1,

            isLoading: false,
            sectionStyle: 'style_1',
            max_visible_units: 12,
            max_col_in_single_row: 3,
            create_new: null,
            edit_record: null,
            faqs: [],
            expandedFaqs: {}, // Track which FAQs are expanded
            maxLength: 200, // Maximum characters to show before truncation
            currentLanguageId: null,
            activeLanguages: [],

        }
    },
    computed: {
        translatedFaqs() {
            const list = Array.isArray(this.faqs) ? this.faqs : [];

            if (!this.currentLanguageId || list.length === 0) {
                return list;
            }

            return list.map(faq => {
                const translatedFaq = { ...faq };

                if (faq.translations && Array.isArray(faq.translations)) {
                    const translation = faq.translations.find(
                        t => t.language_id === this.currentLanguageId
                    );

                    if (translation) {
                        if (translation.question && translation.question.trim() !== '') {
                            translatedFaq.question = translation.question;
                        }

                        if (translation.answer && translation.answer.trim() !== '') {
                            translatedFaq.answer = translation.answer;
                        }
                    }
                }

                return translatedFaq;
            });
        }
        ,
        sortOptions() {
            // Create an options list from our fields
            return this.fields
                .filter(f => f.sortable)
                .map(f => {
                    return { text: f.label, value: f.key }
                })
        }
    },
    mounted() {
        // Set the initial number of items
        this.totalRows = this.faqs.length
    },
    created() {
        this.fetchActiveLanguages().then(() => {
            this.getFaqs();
        });
    },
    methods: {
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
        getFaqs() {
            this.isLoading = true;

            // Client-side paging/search (MazerDatatable), so pull the full list.
            const params = { limit: 0 };

            axios.get(this.$apiUrl + '/faqs', { params })
                .then(response => {
                    const data = response.data || {};
                    this.faqs = Array.isArray(data.data) ? data.data : [];
                    this.totalRows = this.faqs.length;
                    this.isLoading = false;
                })
                .catch(() => {
                    this.faqs = [];
                    this.totalRows = 0;
                    this.isLoading = false;
                });
        },

        deleteSocialMedia(index, id) {
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
                        id: id
                    }
                    axios.post(this.$apiUrl + '/faqs/delete', postData)
                        .then((response) => {
                            this.isLoading = false
                            this.faqs.splice(index, 1)
                            //this.showSuccess(response.data.message)
                            this.showMessage("success", response.data.message);
                        });
                }
            });
        },
        hideModal() {
            this.create_new = false
            this.edit_record = false
        },
        // Component emit (not the global bus) — fires exactly once per save, so
        // the success toast can't double up when the page is revisited.
        onFaqSaved(message) {
            this.showMessage('success', message);
            this.getFaqs();
            this.hideModal();
        },
        // Toggle FAQ expansion state
        toggleFaqExpansion(faqId) {
            this.expandedFaqs[faqId] = !this.expandedFaqs[faqId];
        },
        // Check if FAQ answer should be truncated
        shouldTruncate(answer) {
            return answer && answer.length > this.maxLength;
        },
        // Get truncated text for display
        getTruncatedText(answer) {
            if (!answer) return '';
            return answer.length > this.maxLength ? answer.substring(0, this.maxLength) + '...' : answer;
        },
    }
};
</script>
