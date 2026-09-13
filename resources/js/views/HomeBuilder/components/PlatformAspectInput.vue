<template>
    <div class="hb-tri">
        <label class="hb-lbl mb-1 d-inline-flex align-items-center gap-1">
            {{ label }}
            <Info v-if="hint" :size="13" class="hb-info" v-b-tooltip.hover :title="hint" />
        </label>
        <div class="hb-tri-col">
            <div v-for="p in plats" :key="p.k" class="hb-aspect-row">
                <span class="hb-aspect-plat">{{ p.t }}</span>
                <AppSelect class="form-select form-select-sm" :searchable="false"
                    :options="options"
                    :model-value="valOf(p.k)"
                    @update:model-value="update(p.k, $event)" />
            </div>
        </div>
    </div>
</template>

<script>
import { Info } from 'lucide-vue-next';
import AppSelect from '../../../components/AppSelect.vue';

// Width-independent image sizing: the ratio is applied via CSS aspect-ratio in
// the preview AND by the app/web, so a banner keeps the same proportions on any
// screen width. Value stored as a "w:h" string.
const ASPECT_OPTIONS = [
    // Wide (width > height)
    { id: '21:9', name: '21:9 (Ultra-wide)' },
    { id: '4:1', name: '4:1 (Strip)' },
    { id: '3:1', name: '3:1 (Slim banner)' },
    { id: '2:1', name: '2:1 (Banner)' },
    { id: '16:9', name: '16:9 (Wide)' },
    { id: '3:2', name: '3:2 (Photo)' },
    { id: '4:3', name: '4:3 (Standard)' },
    // Square
    { id: '1:1', name: '1:1 (Square)' },
    // Tall (height > width)
    { id: '3:4', name: '3:4 (Tall)' },
    { id: '2:3', name: '2:3 (Tall photo)' },
    { id: '4:5', name: '4:5 (Portrait)' },
    { id: '9:16', name: '9:16 (Full portrait)' },
];

export default {
    name: 'PlatformAspectInput',
    components: { Info, AppSelect },
    props: {
        label: { type: String, default: '' },
        hint: { type: String, default: '' },
        modelValue: { type: Object, default: () => ({ app: '16:9', web: '16:9', tablet: '16:9' }) },
    },
    emits: ['update:modelValue'],
    data() {
        return {
            options: ASPECT_OPTIONS,
            plats: [
                { k: 'app', t: 'App' },
                { k: 'tablet', t: 'Tablet' },
                { k: 'web', t: 'Web' },
            ],
        };
    },
    methods: {
        valOf(k) {
            return (this.modelValue && this.modelValue[k]) || '16:9';
        },
        update(k, v) {
            const next = { ...(this.modelValue || {}) };
            next[k] = v || '16:9';
            this.$emit('update:modelValue', next);
        },
    },
};
</script>

<style scoped>
.hb-lbl {
    font-size: .72rem;
    color: var(--app-muted);
    margin-bottom: .15rem;
    display: block;
}
.hb-info {
    color: var(--app-muted);
    cursor: help;
    flex-shrink: 0;
}
.hb-info:hover {
    color: var(--bs-primary);
}
.hb-tri-col {
    display: flex;
    flex-direction: column;
    gap: .4rem;
    border: 1px solid var(--app-card-border);
    border-radius: .4rem;
    padding: .5rem;
    background: var(--app-thead-bg);
}
.hb-aspect-row {
    display: flex;
    align-items: center;
    gap: .5rem;
}
.hb-aspect-plat {
    font-size: .72rem;
    color: var(--app-muted);
    width: 48px;
    flex: 0 0 48px;
}
</style>
