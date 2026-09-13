<template>
    <div>
        <div class="list-page">
            <div class="page-head">
                <h3 class="page-head-title">{{ __('media_upload_form') }}</h3>
            </div>

            <div class="list-surface p-3 mb-4">
                <form method="POST" enctype="multipart/form-data">
                    <label style="cursor: pointer; display: block; user-select: none;">
                        <input
                            ref="fileInput"
                            type="file"
                            :accept="dropzoneOptions.acceptedFiles"
                            multiple
                            style="position: absolute; opacity: 0; width: 0; height: 0; pointer-events: none;"
                            @change="handleFileSelect"
                        />
                        <div
                            id="dropzone"
                            class="dropzone-area"
                            @dragover.prevent="handleDragOver"
                            @dragenter.prevent="handleDragEnter"
                            @dragleave.prevent="handleDragLeave"
                            @drop.prevent="handleDrop"
                            :class="{ 'drag-active': isDragging }"
                            style="border: 2px dashed #ccc; padding: 40px; text-align: center; cursor: pointer; border-radius: 8px; transition: all 0.3s ease;"
                            role="button"
                            tabindex="0"
                            @keydown.enter.prevent="() => {}"
                            @keydown.space.prevent="() => {}"
                        >
                            <div class="dropzone-custom-content">
                                <h3 class="dropzone-custom-title d-inline-flex align-items-center gap-2"><Upload :size="18" /> {{ __('drag_and_drop_to_upload_image') }}</h3>
                                <div class="subtitle">{{__('or_click_to_select_a_image_from_your_device')}}</div>
                            </div>
                            <div v-if="queuedFiles.length" class="mt-2">
                                <span class="badge bg-primary">{{ queuedFiles.length }} {{ __('files_selected') || 'file(s) selected' }}</span>
                            </div>
                        </div>
                    </label>
                    <div class="d-flex justify-content-end mt-2">
                        <button type="button" :disabled="submitBtn === true" @click="uploadImage()" class="btn btn-primary list-add-btn">
                            <Upload :size="16" v-if="!isLoading" /> {{ __('upload') }}
                            <b-spinner v-if="isLoading" small label="Spinning"></b-spinner>
                        </button>
                    </div>
                </form>
            </div>

            <div class="page-head">
                <h3 class="page-head-title">{{ __('media_list')}}</h3>
                <div class="page-head-actions">
                    <b-dropdown size="sm" dropright :text="__('actions')" split-variant="outline-primary"
                                variant="primary" :disabled="selectedItems.length === 0">
                        <b-dropdown-item href="javascript:void(0);" @click="multipleDelete"><span
                            class="text-danger d-inline-flex align-items-center gap-1" style="font-weight: bold;"><Trash2 :size="15" /> {{ __('delete_selected_media') }}</span>
                        </b-dropdown-item>
                    </b-dropdown>
                </div>
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

                    <button class="list-icon-btn" v-b-tooltip.hover :title="__('refresh')" @click="getMedia()">
                        <RefreshCw :class="{ 'is-spinning': isLoading }" />
                    </button>
                </div>

                <MazerDatatable
                    responsive="sm"
                    :items="media"
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

                    <template #head(select)="row">
                        <input type="checkbox" v-model="all_select" @click="allSelectCheckBox"
                               class="form-check-input">
                    </template>
                    <template #cell(select)="row">
                        <input type="checkbox" v-model="selectedItems" @change="selectCheckBox"
                               :value="`${row.item.id}`" class="form-check-input">
                    </template>

                    <template #cell(image)="row">
                        <img :src="getMediaUrl(row.item)" class="list-thumb"
                             v-if="row.item.name"/>
                    </template>
                    <template #cell(actions)="row">
                        <div class="list-actions">
                            <button type="button" class="btn btn-sm btn-outline-primary"
                                    @click="copyPath(row.item)">
                                <span v-if="!copies.includes(row.item.id)">
                                    {{ __('copy') }}
                                </span>
                                <span v-if="copies.includes(row.item.id)">Copied!</span>
                            </button>
                            <button type="button" class="list-action-btn is-delete"
                                    v-b-tooltip.hover :title="__('delete')"
                                    @click="deleteMedia(row.index,row.item.id)">
                                <Trash2 :size="15" />
                            </button>
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
                    </div>

                    <b-pagination
                        v-model="currentPage"
                        :total-rows="totalRows"
                        :per-page="perPage"
                        size="sm"
                        class="mb-0 list-pagination"
                    ></b-pagination>
                </div>
            </div>
        </div>
    </div>
</template>
<script>
import axios from "axios";
import Auth from '../../Auth.js';
import { Search, RefreshCw, Upload, Trash2 } from 'lucide-vue-next';

export default {
    components: {
        Search, RefreshCw, Upload, Trash2,
    },
    data: function () {
        return {
            login_user: Auth.user,
            queuedFiles: [],
            isDragging: false,

            dropzoneOptions: {
                url: this.$apiUrl + '/media/save',
                thumbnailWidth: 175,
                maxFilesize: 0.5,
                addRemoveLinks: true,
                paramName: "files",
                autoProcessQueue: false,
                parallelUploads: 10,
                autoDiscover: false,
                dictResponseError: 'Error',
                uploadMultiple: true,
                headers: {"My-Awesome-Header": "header value", "Authorization": 'Bearer ' +Auth.token},
                acceptedFiles: 'image/jpeg,image/png,image/jpg,image/gif,image/webp,image/svg+xml', // images + svg only, no video
                dictFileTooBig: 'File is too big ({{filesize}}MB). Max file size: {{maxFilesize}}MB.', // Custom error message for file size
                dictInvalidFileType: 'Invalid file type. Only image and SVG files are allowed.', // Custom error message for file type
            },
            submitBtn: false,
            fields: [
                {key: 'select', label: '', sortable: false, class: 'text-center'},
                {key: 'id', label: __('id'), sortable: true, sortDirection: 'desc', class: 'text-center'},
                {key: 'image', label: __('image'), sortable: false, class: 'text-center'},
                {key: 'name', label: __('name'), sortable: false, class: 'text-center'},
                {key: 'extension', label: __('extension'), sortable: true, class: 'text-center'},
                {key: 'type', label: __('type'), sortable: true, class: 'text-center'},
                {key: 'sub_directory', label: __('sub_directory'), sortable: false, class: 'text-center'},
                {key: 'size', label: __('size'), sortable: true, class: 'text-center'},
                {key: 'actions', label: __('actions'), sortable: false}
            ],
            totalRows: 1,
            currentPage: 1,
            perPage: this.$perPage,
            pageOptions: this.$pageOptions,
            sortBy: '',
            sortDesc: false,
            sortDirection: 'asc',
            filter: null,
            filterOn: [],
            page: 1,

            isLoading: false,
            sectionStyle: 'style_1',
            max_visible_units: 12,
            max_col_in_single_row: 3,
            media: [],
            copyIcon: true,
            copies: [],
            selectedItems: [],
            all_select: false,
        }
    },
    computed: {
        sortOptions() {
            // Create an options list from our fields
            return this.fields
                .filter(f => f.sortable)
                .map(f => {
                    return {text: f.label, value: f.key}
                })
        },
    },
    mounted() {
        // Set the initial number of items
        this.totalRows = this.media.length
    },
    created: function () {
        this.getMedia();
    },
    methods: {
        getMediaUrl(item) {
            // If sub_directory is a Cloudinary URL, return it directly
            if (item.sub_directory && /^https?:\/\//.test(item.sub_directory)) {
                return item.sub_directory;
            }
            // Otherwise, concatenate storage URL with directory and name
            return this.$storageUrl + item.sub_directory + item.name;
        },
        getMedia() {
            this.isLoading = true;
            axios.get(this.$apiUrl + '/media')
                .then((response) => {
                    this.media = response.data.data;
                    this.totalRows = this.media.length;
                    this.isLoading = false;
                });
        },
        handleFileSelect(event) {
            const files = Array.from(event.target.files);
            this.queuedFiles = files;
            console.log('Files selected:', files.length);
        },
        handleDragOver(e) {
            e.preventDefault();
            e.stopPropagation();
        },
        handleDragEnter(e) {
            e.preventDefault();
            e.stopPropagation();
            this.isDragging = true;
        },
        handleDragLeave(e) {
            e.preventDefault();
            e.stopPropagation();
            // Only set to false if we're actually leaving the dropzone
            if (e.target.id === 'dropzone' || e.target.closest('#dropzone') === null) {
                this.isDragging = false;
            }
        },
        handleDrop(event) {
            event.preventDefault();
            event.stopPropagation();
            this.isDragging = false;
            
            const files = Array.from(event.dataTransfer.files);
            console.log('Files dropped:', files.length);
            
            // Validate files
            const validFiles = files.filter(file => {
                const isImage = file.type.startsWith('image/');
                if (!isImage) {
                    console.warn('Rejected non-image file:', file.name);
                }
                return isImage;
            });
            
            if (validFiles.length === 0) {
                this.showError('Please drop only image files');
                return;
            }
            
            if (validFiles.length !== files.length) {
                this.showWarning(`${files.length - validFiles.length} non-image file(s) were skipped`);
            }
            
            this.queuedFiles = validFiles;
            console.log('Valid files queued:', this.queuedFiles.length);
        },
        uploadImage() {
            this.isLoading = true;
            if (this.queuedFiles.length === 0) {
                this.isLoading = false;
                this.showError("Select at least one image or selected image is too large!");
                return;
            }
            if (this.queuedFiles.length > 10) {
                this.isLoading = false;
                this.showError("You can upload 10 medias file at a time");
                return false;
            }
            
            let formData = new FormData();
            this.queuedFiles.forEach((file) => {
                formData.append('files[]', file);
            });
            
            console.log('Uploading files:', this.queuedFiles.length);
            console.log('API URL:', this.$apiUrl + '/media/save');
            
            axios.post(this.$apiUrl + '/media/save', formData, {
                headers: {
                    'Content-Type': 'multipart/form-data',
                    'Authorization': 'Bearer ' + Auth.token,
                }
            }).then((response) => {
                console.log('Upload response:', response);
                this.uploadSuccess(response.data);
            }).catch((error) => {
                this.isLoading = false;
                console.error('Upload error:', error);
                console.error('Error response:', error.response);
                console.error('Error message:', error.message);
                
                // Better error reporting
                let errorMsg = 'Upload failed';
                if (error.response?.data?.message) {
                    errorMsg = error.response.data.message;
                } else if (error.response?.statusText) {
                    errorMsg = `Server error: ${error.response.statusText}`;
                } else if (error.message) {
                    errorMsg = error.message;
                }
                
                this.showError(errorMsg);
            });
        },
        uploadSuccess(response) {
            if (response.status === 1) {
                this.getMedia();
                this.isLoading = false;
                this.showMessage("success", response.message);
                this.queuedFiles = [];
                if (this.$refs.fileInput) this.$refs.fileInput.value = '';
            } else {
                this.isLoading = false;
                this.showError(response.message);
            }
        },
        deleteMedia(index, id) {
            this.$swal.fire({
                title: "Are you Sure?",
                text: "You won't be able to revert this",
                confirmButtonText: "Yes, Delete It",
                cancelButtonText: "Cancel",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: window.adminThemeColor || '#435ebe',
                cancelButtonColor: '#d33',
            }).then(result => {
                if (result.isConfirmed) {
                    this.isLoading = true;
                    let postData = {
                        id: id
                    };
                    
                    console.log('Deleting media with ID:', id);
                    
                    axios.post(this.$apiUrl + '/media/delete', postData)
                        .then((response) => {
                            this.isLoading = false;
                            console.log('Delete response:', response.data);
                            
                            if (response.data.status === 1 || response.data.error === false) {
                                // Remove from local array
                                this.media.splice(index, 1);
                                this.totalRows = this.media.length;
                                
                                // Remove from selected items if it was selected
                                this.selectedItems = this.selectedItems.filter(itemId => itemId != id);
                                
                                this.showMessage('success', response.data.message || 'Media deleted successfully');
                            } else {
                                this.showError(response.data.message || 'Failed to delete media');
                            }
                        })
                        .catch((error) => {
                            this.isLoading = false;
                            console.error('Delete error:', error);
                            const errorMsg = error.response?.data?.message || 'Failed to delete media';
                            this.showError(errorMsg);
                        });
                }
            });
        },
        copyPath(item) {
            // Copy the path or URL:
            // - For Cloudinary: copy the full URL
            // - For local storage: copy the relative path (e.g. products/media/x.jpg)
            let path = '';
            if (item.sub_directory && /^https?:\/\//.test(item.sub_directory)) {
                // It's a Cloudinary URL
                path = item.sub_directory;
            } else {
                // It's a local storage path
                path = item.sub_directory + item.name;
            }
            
            navigator.clipboard.writeText(path).catch(() => {
                // http / older browsers: clipboard API unavailable — fall back.
                const ta = document.createElement('textarea');
                ta.value = path;
                document.body.appendChild(ta);
                ta.select();
                document.execCommand('copy');
                ta.remove();
            });
            this.copies.push(item.id);
            setTimeout(() => { this.copies = this.copies.filter(i => i !== item.id); }, 1500);
        },

        allSelectCheckBox() {
            if (this.all_select == false) {
                this.all_select = true
                this.media.forEach(media => {
                    this.selectedItems.push(media.id)
                });
            } else {
                this.all_select = false
                this.selectedItems = []
            }
        },
        selectCheckBox() {
            let uniqueSelectedItems = [...new Set(this.selectedItems)];
            if (this.media.length === uniqueSelectedItems.length) {
                this.all_select = true
            } else {
                this.all_select = false
            }
        },
        multipleDelete() {
            let uniqueSelectedItems = [...new Set(this.selectedItems)];
            if (uniqueSelectedItems.length !== 0) {
                this.$swal.fire({
                    title: "Are you Sure?",
                    text: "You won't be able to revert this",
                    confirmButtonText: "Yes, Delete It",
                    cancelButtonText: "Cancel",
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: window.adminThemeColor || '#435ebe',
                    cancelButtonColor: '#d33',
                }).then(result => {
                    if (result.isConfirmed) {
                        let ids = uniqueSelectedItems.toString();
                        this.isLoading = true;
                        let postData = {
                            ids: ids
                        };
                        axios.post(this.$apiUrl + '/media/multiple_delete', postData)
                            .then((response) => {
                                this.isLoading = false;
                                let data = response.data;
                                this.getMedia();
                                this.selectedItems = [];
                                this.all_select = false;
                                this.showMessage("success", data.message || 'Media deleted successfully');
                            })
                            .catch((error) => {
                                this.isLoading = false;
                                const errorMsg = error.response?.data?.message || 'Failed to delete media';
                                this.showError(errorMsg);
                            });
                    }
                });
            } else {
                this.showWarning("Select at least one record!");
            }
        }
    }
};
</script>

<style scoped>
/* Drag and drop styling */
.dropzone-area {
    position: relative;
}

.dropzone-area.drag-active {
    border-color: #4CAF50 !important;
    background-color: #f0f8f0 !important;
}

.dropzone-area.drag-active::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(76, 175, 80, 0.1);
    pointer-events: none;
    border-radius: 8px;
}
</style>
<style>
.vue-dropzone>.dz-preview .dz-error-message{
    top: 1px !important;
}
</style>
