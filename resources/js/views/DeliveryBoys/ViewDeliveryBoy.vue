<template>
    <div class="page-heading">
        <div class="page-title">
            <div class="row align-items-center">
                <div class="col-12 d-flex align-items-center gap-2 justify-content-end">
                    <button class="btn btn-sm btn-outline-secondary" @click="$router.back()">
                        <component :is="icons.back" :size="14" /> {{ __('back') }}
                    </button>
                </div>
            </div>
        </div>

        <div v-if="isLoading" class="text-center py-5"><b-spinner></b-spinner></div>

        <template v-else-if="boy">
            <!-- Header -->
            <div class="card mb-3">
                <div class="card-body">
                    <div class="d-flex flex-wrap align-items-start gap-3">
                        <div class="boy-avatar">
                            <img v-if="boy.profile_url" :src="boy.profile_url" alt="Profile">
                            <template v-else>{{ initials }}</template>
                        </div>
                        <div class="flex-grow-1">
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <h4 class="mb-0 fw-bold">{{ boy.name || '—' }}</h4>
                                <span class="badge" :class="statusBadge">{{ statusLabelText }}</span>
                            </div>
                            <div class="small mt-2 d-flex flex-wrap align-items-center gap-3">
                                <span v-if="boy.mobile" class="d-inline-flex align-items-center gap-1"><Phone :size="14" /> {{ (boy.country_code ? boy.country_code + ' ' : '') + boy.mobile }}</span>
                                <span v-if="boy.email" class="d-inline-flex align-items-center gap-1"><Mail :size="14" /> {{ boy.email }}</span>
                                <span v-if="boy.country" class="d-inline-flex align-items-center gap-1"><Globe :size="14" /> {{ countryName }}</span>
                            </div>
                            <div class="small">{{ __('joined') }} {{ $filters.formatDateTime(boy.created_at) }} · ID #{{ boy.id }}</div>
                            <div class="text-danger small" v-if="boy.remark">{{ __('remark') }}: {{ boy.remark }}</div>
                        </div>
                        <div class="text-end small">
                            <div><b>{{ __('bonus') }}:</b> {{ bonusText(boy.bonus) }}</div>
                            <div><b>{{ __('return_bonus') }}:</b> {{ bonusText(boy.return_bonus) }}</div>
                            <div class="mt-1">
                                <a v-if="boy.driving_license_url" :href="boy.driving_license_url" target="_blank" class="me-2">{{ __('driving_license') }}</a>
                                <a v-if="boy.national_identity_card_url" :href="boy.national_identity_card_url" target="_blank">{{ __('national_identity_card') }}</a>
                            </div>
                        </div>
                    </div>
                    <!-- Bank -->
                    <div class="border-top mt-3 pt-2 small d-flex flex-wrap gap-3" v-if="boy.bank && (boy.bank.account_number || boy.bank.bank_name)">
                        <span><b>{{ __('bank_name') }}:</b> {{ boy.bank.bank_name || '—' }}</span>
                        <span><b>{{ __('account_name') }}:</b> {{ boy.bank.account_name || '—' }}</span>
                        <span><b>{{ __('account_number') }}:</b> {{ boy.bank.account_number || '—' }}</span>
                        <span><b>IFSC:</b> {{ boy.bank.ifsc || '—' }}</span>
                    </div>
                </div>
            </div>

            <!-- Stat cards -->
            <div class="row g-3 mb-3">
                <div class="col-6 col-md" v-for="c in statCards" :key="c.key">
                    <div class="stat-card">
                        <span class="stat-icon" :class="'tone-' + c.key"><component :is="c.icon" :size="20" /></span>
                        <div class="stat-meta">
                            <div class="stat-label">{{ c.label }}</div>
                            <div class="stat-num">{{ c.value }}</div>
                            <div class="tiny text-muted" v-if="c.sub">{{ c.sub }}</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Orders workload -->
            <div class="row g-3 mb-3">
                <div class="col-md-4" v-for="w in workload" :key="w.key">
                    <div class="card"><div class="card-body d-flex align-items-center justify-content-between">
                        <span class="fw-semibold d-inline-flex align-items-center gap-1"><component :is="w.icon" :size="16" class="wl-ic" :class="'wl-' + w.key" /> {{ w.label }}</span>
                        <h4 class="mb-0 fw-bold">{{ w.value }}</h4>
                    </div></div>
                </div>
            </div>

            <!-- Tabs -->
            <ul class="nav nav-tabs boy-tabs mb-3">
                <li class="nav-item" v-for="t in tabs" :key="t.key">
                    <a class="nav-link d-inline-flex align-items-center gap-1" :class="{ active: tab === t.key }" href="#" @click.prevent="tab = t.key"><component :is="t.icon" :size="14" /> {{ t.label }}</a>
                </li>
            </ul>

            <!-- Settlement history -->
            <div v-if="tab === 'wallet'" class="list-surface">
                <div class="table-responsive">
                    <table class="table align-middle mb-0 boy-table">
                        <thead><tr>
                            <th>{{ __('id') }}</th><th>{{ __('type') }}</th><th>{{ __('amount') }}</th>
                            <th>{{ __('closing_balance') }}</th>
                            <th>{{ __('message') }}</th><th>{{ __('date') }}</th>
                        </tr></thead>
                        <tbody>
                            <tr v-if="!settlements.length"><td colspan="6" class="text-center py-4 text-muted">{{ __('no_records_found') }}</td></tr>
                            <tr v-for="t in settlements" :key="t.id">
                                <td>{{ t.id }}</td>
                                <td><span class="badge" :class="entryTypeBadge(t.entry_type)">{{ entryTypeLabels[t.entry_type] || t.entry_type }}</span></td>
                                <td class="fw-bold" :class="t.type === 'credit' ? 'text-success' : 'text-danger'">
                                    {{ (t.type === 'credit' ? '+' : '-') + (t.currency || cur) + t.amount }}</td>
                                <td><span v-if="t.closing_balance === null">—</span><span v-else>{{ (t.currency || cur) + t.closing_balance }}</span></td>
                                <td class="small">{{ t.message }}</td>
                                <td class="small">{{ $filters.formatDateTime(t.created_at) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Cash collections -->
            <div v-else-if="tab === 'cash'" class="list-surface">
                <div class="table-responsive">
                    <table class="table align-middle mb-0 boy-table">
                        <thead><tr>
                            <th>{{ __('id') }}</th><th>{{ __('order_id') }}</th><th>{{ __('type') }}</th>
                            <th>{{ __('amount') }}</th><th>{{ __('message') }}</th><th>{{ __('date') }}</th>
                        </tr></thead>
                        <tbody>
                            <tr v-if="!cashCollections.length"><td colspan="6" class="text-center py-4 text-muted">{{ __('no_records_found') }}</td></tr>
                            <tr v-for="t in cashCollections" :key="t.id">
                                <td>#{{ t.id }}</td>
                                <td>{{ t.order_number || (t.order_id ? '#' + t.order_id : '—') }}</td>
                                <td><span class="badge bg-warning text-dark">COD</span></td>
                                <td class="fw-bold">{{ t.currency || cur }}{{ t.amount }}</td>
                                <td class="small">{{ t.message }}</td>
                                <td class="small">{{ $filters.formatDateTime(t.transaction_date || t.created_at) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Withdrawals -->
            <div v-else-if="tab === 'withdrawals'" class="list-surface">
                <div class="table-responsive">
                    <table class="table align-middle mb-0 boy-table">
                        <thead><tr>
                            <th>{{ __('id') }}</th><th>{{ __('amount') }}</th><th>{{ __('status') }}</th>
                            <th>{{ __('message') }}</th><th>{{ __('remark') }}</th><th>{{ __('date') }}</th>
                        </tr></thead>
                        <tbody>
                            <tr v-if="!withdrawals.length"><td colspan="6" class="text-center py-4 text-muted">{{ __('no_records_found') }}</td></tr>
                            <tr v-for="w in withdrawals" :key="w.id">
                                <td>#{{ w.id }}</td>
                                <td class="fw-bold">{{ w.currency || cur }}{{ w.amount }}</td>
                                <td><span class="badge" :class="w.status === 1 ? 'bg-success' : (w.status === 2 ? 'bg-danger' : 'bg-warning text-dark')">
                                    {{ w.status === 1 ? __('approved') : (w.status === 2 ? __('rejected') : __('pending')) }}</span></td>
                                <td class="small">{{ w.message }}</td>
                                <td class="small">{{ w.remark }}</td>
                                <td class="small">{{ $filters.formatDateTime(w.created_at) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Salary -->
            <div v-else-if="tab === 'salary'" class="list-surface">
                <div class="table-responsive">
                    <table class="table align-middle mb-0 boy-table">
                        <thead><tr>
                            <th>{{ __('id') }}</th><th>{{ __('amount') }}</th><th>{{ __('paid_on') }}</th><th>{{ __('note') }}</th>
                        </tr></thead>
                        <tbody>
                            <tr v-if="!salaryTransactions.length"><td colspan="4" class="text-center py-4 text-muted">{{ __('no_records_found') }}</td></tr>
                            <tr v-for="s in salaryTransactions" :key="s.id">
                                <td>#{{ s.id }}</td>
                                <td class="fw-bold">{{ cur }}{{ s.amount }}</td>
                                <td>{{ $filters.formatDate ? $filters.formatDate(s.paid_on) : s.paid_on }}</td>
                                <td class="small">{{ s.note }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Recent orders -->
            <div v-else-if="tab === 'orders'" class="list-surface">
                <div class="table-responsive">
                    <table class="table align-middle mb-0 boy-table">
                        <thead><tr>
                            <th>{{ __('order_no') }}</th><th>{{ __('mode') }}</th><th>{{ __('amount') }}</th>
                            <th>{{ __('status') }}</th><th>{{ __('date') }}</th>
                        </tr></thead>
                        <tbody>
                            <tr v-if="!recentOrders.length"><td colspan="5" class="text-center py-4 text-muted">{{ __('no_records_found') }}</td></tr>
                            <tr v-for="(o, i) in recentOrders" :key="i">
                                <td class="fw-bold">{{ o.order_number || ('#' + o.id) }}</td>
                                <td>{{ o.channel === 'quick' ? __('quick') : __('ecommerce') }}</td>
                                <td class="fw-bold">{{ o.currency || cur }}{{ o.final_total }}</td>
                                <td><span class="badge" :class="getStatusBadgeClass(o.active_status)">{{ statusLabel(o.active_status) }}</span></td>
                                <td class="small">{{ $filters.formatDateTime(o.created_at) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </template>
    </div>
</template>

<script>
import { markRaw } from 'vue';
import axios from 'axios';
import {
    ArrowLeft, Phone, Mail, Globe, Wallet, DollarSign, Landmark, Banknote,
    Handshake, Truck, CircleCheck, Undo2, ShoppingCart,
} from 'lucide-vue-next';

export default {
    name: 'ViewDeliveryBoy',
    components: { Phone, Mail, Globe },
    data() {
        return {
            icons: { back: markRaw(ArrowLeft) },
            id: this.$route.params.id,
            isLoading: false,
            boy: null,
            stats: {},
            settlements: [],
            cashCollections: [],
            withdrawals: [],
            salaryTransactions: [],
            recentOrders: [],
            tab: 'wallet',
        };
    },
    computed: {
        entryTypeLabels() {
            return {
                delivery_commission: __('delivery_commission'),
                return_commission: __('return_commission'),
                withdrawal: __('withdrawal'),
                cash_deposit_cash: __('cash_deposit_cash'),
                cash_deposit_wallet: __('cash_deposit_wallet'),
            };
        },
        cur() { return (this.boy && this.boy.country && this.boy.country.currency) || this.$currency; },
        countryName() {
            const c = this.boy && this.boy.country;
            if (!c) return '';
            if (typeof c.name === 'string') return c.name;
            return c.name ? (Object.values(c.name)[0] || '') : '';
        },
        initials() {
            const n = (this.boy && this.boy.name) ? this.boy.name.trim() : '';
            if (!n) return '?';
            return n.split(/\s+/).slice(0, 2).map(w => w[0]).join('').toUpperCase();
        },
        statusBadge() {
            const s = Number(this.boy.status);
            return { 0: 'bg-warning text-dark', 1: 'bg-success', 2: 'bg-danger', 3: 'bg-secondary', 4: 'bg-danger' }[s] || 'bg-secondary';
        },
        statusLabelText() {
            const map = { 0: 'registered', 1: 'active', 2: 'rejected', 3: 'deactivated', 4: 'blocked', 7: 'removed' };
            const k = map[Number(this.boy.status)];
            return k ? this.__(k) : String(this.boy.status);
        },
        statCards() {
            const w = this.stats.wallet || {}, c = this.stats.cash || {}, s = this.stats.salary || {};
            return [
                { key: 'bal', icon: markRaw(Wallet), value: this.cur + this.num(w.balance), label: __('wallet_balance'), sub: __('pending') + ': ' + this.cur + this.num(w.pending_withdrawals) },
                { key: 'earned', icon: markRaw(DollarSign), value: this.cur + this.num(w.total_earned), label: __('total_earned'), sub: __('salary') + ': ' + this.cur + this.num(s.total) },
                { key: 'withdrawn', icon: markRaw(Landmark), value: this.cur + this.num(w.total_withdrawn), label: __('total_withdrawn') },
                { key: 'cash', icon: markRaw(Banknote), value: this.cur + this.num(c.in_hand), label: __('cash_in_hand'), sub: __('collected') + ': ' + this.cur + this.num(c.total_collected) },
                { key: 'deposited', icon: markRaw(Handshake), value: this.cur + this.num(c.total_deposited), label: __('cash_deposited') },
            ];
        },
        workload() {
            const o = this.stats.orders || { quick: {}, ecommerce: {} };
            const r = this.stats.returns || {};
            return [
                { key: 'remaining', icon: markRaw(Truck), label: __('remaining_to_deliver'), value: o.remaining_to_deliver || 0 },
                { key: 'delivered', icon: markRaw(CircleCheck), label: __('delivered'), value: o.total_delivered || 0 },
                { key: 'returns', icon: markRaw(Undo2), label: __('returns_completed'), value: (r.completed || 0) + ' / ' + (r.assigned || 0) },
            ];
        },
        tabs() {
            return [
                { key: 'wallet', icon: markRaw(Wallet), label: __('settlement_history') + ' (' + this.settlements.length + ')' },
                { key: 'cash', icon: markRaw(Banknote), label: __('cash_collection') + ' (' + this.cashCollections.length + ')' },
                { key: 'withdrawals', icon: markRaw(Landmark), label: __('withdrawal_requests') + ' (' + this.withdrawals.length + ')' },
                { key: 'salary', icon: markRaw(DollarSign), label: __('salary_transactions') + ' (' + this.salaryTransactions.length + ')' },
                { key: 'orders', icon: markRaw(ShoppingCart), label: __('recent_orders') },
            ];
        },
    },
    created() {
        this.getDetail();
    },
    methods: {
        entryTypeBadge(t) {
            return {
                delivery_commission: 'bg-success',
                return_commission: 'bg-info',
                withdrawal: 'bg-warning text-dark',
                cash_deposit_cash: 'bg-primary',
                cash_deposit_wallet: 'bg-secondary',
                credit: 'bg-success',
                debit: 'bg-danger',
            }[t] || 'bg-secondary';
        },
        num(n) { return new Intl.NumberFormat().format(Number(n || 0)); },
        bonusText(b) {
            if (!b) return '—';
            if (Number(b.type) === 1) {
                let t = b.percentage + '%';
                if (Number(b.min)) t += ' min ' + b.min;
                if (Number(b.max)) t += ' max ' + b.max;
                return t;
            }
            return this.__('fixed');
        },
        getDetail() {
            this.isLoading = !this.boy;
            axios.get(this.$apiUrl + '/delivery_boys/detail/' + this.id).then((res) => {
                this.isLoading = false;
                const d = res.data;
                if (d.status === 1) {
                    this.boy = d.data.delivery_boy;
                    this.stats = d.data.stats || {};
                    this.settlements = d.data.settlement_history || [];
                    this.cashCollections = d.data.cash_collections || [];
                    this.withdrawals = d.data.withdrawals || [];
                    this.salaryTransactions = d.data.salary_transactions || [];
                    this.recentOrders = d.data.recent_orders || [];
                } else {
                    this.showError(d.message);
                    this.$router.back();
                }
            }).catch(() => {
                this.isLoading = false;
                this.showError(__('something_went_wrong'));
            });
        },
        statusLabel(id) {
            const all = { 1: 'payment_pending', 2: 'received', 3: 'processed', 4: 'shipped', 5: 'outForDelivery', 6: 'delivered', 7: 'cancelled', 8: 'returned', 9: 'preparing', 10: 'ready_for_pickup', 11: 'picked_up' };
            return all[Number(id)] ? this.__(all[Number(id)]) : String(id ?? '');
        },
        getStatusBadgeClass(id) {
            const n = Number(id);
            if (n === 1) return 'bg-secondary';
            if (n === 2) return 'bg-primary';
            if (n === 3 || n === 9) return 'bg-info';
            if (n === 4 || n === 5 || n === 10 || n === 11) return 'bg-warning';
            if (n === 6) return 'bg-success';
            if (n === 7 || n === 8) return 'bg-danger';
            return 'bg-secondary';
        },
    },
};
</script>

<style scoped>
.boy-avatar {
    width: 64px; height: 64px; border-radius: 50%; background: var(--bs-primary, #435ebe); color: var(--app-card-bg);
    display: flex; align-items: center; justify-content: center; font-size: 1.4rem; font-weight: 700;
    overflow: hidden; flex-shrink: 0;
}
.boy-avatar img { width: 100%; height: 100%; object-fit: cover; }
.stat-card {
    display: flex; align-items: center; gap: .75rem;
    background: var(--app-card-bg); border: 1px solid var(--app-card-border); border-radius: 12px;
    padding: .9rem 1rem; height: 100%;
}
.stat-icon {
    width: 44px; height: 44px; flex: none;
    display: inline-flex; align-items: center; justify-content: center;
    border-radius: 12px;
    background: rgba(var(--bs-primary-rgb), .1); color: var(--bs-primary);
}
.stat-icon.tone-bal { background: rgba(var(--bs-primary-rgb), .1); color: var(--bs-primary); }
.stat-icon.tone-earned { background: rgba(34, 197, 94, .12); color: #16a34a; }
.stat-icon.tone-withdrawn { background: rgba(139, 92, 246, .12); color: #7c3aed; }
.stat-icon.tone-cash { background: rgba(6, 182, 212, .12); color: #0891b2; }
.stat-icon.tone-deposited { background: rgba(245, 158, 11, .14); color: #d97706; }
.stat-meta { min-width: 0; }
.stat-num { font-size: 1.3rem; font-weight: 800; line-height: 1.2; color: var(--app-ink, var(--app-ink)); }
.stat-label { font-size: .72rem; letter-spacing: .04em; text-transform: uppercase; color: var(--app-muted); }
.tiny { font-size: .68rem; }
.boy-tabs .nav-link { color: var(--app-muted); font-weight: 600; }
.boy-tabs .nav-link.active { color: var(--bs-primary, #435ebe); }
/* Table header/rows now come from .list-surface (common.css). Keep only the
   cell font-size tweak. */
.boy-table td { font-size: .85rem; }
/* Colored workload icons. */
.wl-ic { flex-shrink: 0; }
.wl-remaining { color: #f59e0b; }
.wl-delivered { color: #16a34a; }
.wl-returns { color: #7c3aed; }
.gap-2 { gap: .5rem; } .gap-3 { gap: 1rem; }
</style>
