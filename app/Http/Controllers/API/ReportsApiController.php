<?php

namespace App\Http\Controllers\API;

use App\Helpers\CommonHelper;
use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Models\DeliveryBoy;
use App\Models\OrderStatusList;
use App\Models\ReturnStatusList;
use App\Models\Setting;
use App\Models\Store;
use App\Models\Zone;
use App\Services\LanguageService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Admin reports. One endpoint per report; all share the header country/zone filter and a
 * period filter (today | last_7_days | this_month | last_quarter | all — default this_month).
 *
 * Channel rule enforced everywhere:
 *   quick     -> money + status on `orders`
 *   ecommerce -> money + status on `order_items`
 *
 * Zone coverage: order-backed reports filter on orders.zone_id; inventory maps zones -> stores.
 * Delivery + promo have no reliable zone link, so they fall back to country only and say so
 * via `zone_supported = false`.
 */
class ReportsApiController extends Controller
{
    private array $langCodeById = [];

    /** report key => [controller method, title lang key]. */
    private const REPORTS = [
        'sales'     => ['sales', 'sales_report'],
        'orders'    => ['orders', 'orders_report'],
        'products'  => ['products', 'product_performance'],
        'customers' => ['customers', 'customer_report'],
        'inventory' => ['inventory', 'inventory_report'],
        'returns'   => ['returns', 'returns_refunds'],
        'delivery'  => ['delivery', 'delivery_report'],
        'payment'   => ['payment', 'payment_report'],
        'category'  => ['category', 'category_report'],
        'promo'     => ['promo', 'promo_report'],
    ];

    /* ===================== export ===================== */

    /**
     * Export any report as csv | xlsx | pdf. Re-runs the report so the file always matches
     * exactly what the screen shows for the same filters.
     */
    public function export(Request $request, string $type)
    {
        if (!isset(self::REPORTS[$type])) {
            return CommonHelper::responseError('invalid_report_type');
        }
        $format = strtolower((string) $request->input('format', 'csv'));
        if (!in_array($format, ['csv', 'xlsx', 'pdf'], true)) {
            return CommonHelper::responseError('invalid_export_format');
        }

        [$method, $titleKey] = self::REPORTS[$type];

        // Reuse the exact report payload (same filters, same numbers as the screen).
        $payload = json_decode($this->{$method}($request)->getContent(), true)['data'] ?? [];
        $rows = $payload['rows'] ?? [];

        $currency = $payload['currency'] ?? '';
        [$headings, $body] = $this->flattenRows($rows, $currency);

        $title = __($titleKey);
        $fileBase = $type . '_report_' . Carbon::now()->format('Y-m-d_His');

        if ($format === 'xlsx') {
            return \Maatwebsite\Excel\Facades\Excel::download(
                new \App\Exports\ReportExport($body, $headings, $title, $this->themeColor()),
                $fileBase . '.xlsx'
            );
        }

        if ($format === 'csv') {
            return $this->csvResponse($headings, $body, $fileBase . '.csv');
        }

        return $this->pdfResponse($payload, $headings, $body, $title, $fileBase . '.pdf');
    }

    /**
     * Rows -> [headings, body]. Drops image columns, renders booleans/arrays as text so a
     * spreadsheet cell never shows "Array" or "1/0".
     */
    private function flattenRows(array $rows, string $currency): array
    {
        if (!$rows) {
            return [[], []];
        }

        $keys = array_values(array_filter(array_keys($rows[0]), fn ($k) => !in_array($k, ['image', 'status_id', 'is_cod'], true)));
        $headings = array_map(fn ($k) => __($k), $keys);

        $body = [];
        foreach ($rows as $row) {
            $line = [];
            foreach ($keys as $k) {
                $v = $row[$k] ?? '';
                if (is_bool($v)) {
                    $v = $v ? __('yes') : __('no');
                } elseif (is_array($v)) {
                    // Translatable name object -> current locale, else first non-empty.
                    $v = $v[app()->getLocale()] ?? (collect($v)->first(fn ($x) => $x !== '' && $x !== null) ?? '');
                } elseif ($v === null) {
                    $v = '';
                }
                $line[] = $v;
            }
            $body[] = $line;
        }

        return [$headings, $body];
    }

    private function csvResponse(array $headings, array $rows, string $filename)
    {
        $out = fopen('php://temp', 'r+');
        // BOM so Excel opens UTF-8 (₹, é, …) correctly instead of mojibake.
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $headings);
        foreach ($rows as $r) {
            fputcsv($out, $r);
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    private function pdfResponse(array $payload, array $headings, array $rows, string $title, string $filename)
    {
        // KPI cards, rendered as label/value text.
        $kpis = [];
        foreach ($payload['summary'] ?? [] as $k) {
            $val = $k['value'];
            if (($k['type'] ?? '') === 'money') {
                $val = ($payload['currency'] ?? '') . number_format((float) $val, 2);
            } elseif (($k['type'] ?? '') === 'percent') {
                $val = $val . '%';
            } elseif (is_numeric($val)) {
                $val = number_format((float) $val, is_float($val + 0) && fmod((float) $val, 1) != 0 ? 2 : 0);
            } elseif (is_array($val)) {
                $val = $val[app()->getLocale()] ?? (collect($val)->first() ?? '');
            }
            $kpis[] = ['label' => __($k['label']), 'value' => (string) $val];
        }

        $range = null;
        if (!empty($payload['range']['start']) && !empty($payload['range']['end'])) {
            $range = Carbon::parse($payload['range']['start'])->format('d M Y')
                . ' - ' . Carbon::parse($payload['range']['end'])->format('d M Y');
        }

        $html = view('reports.pdf', [
            'title'        => $title,
            'app_name'     => $this->appName(),
            'scope'        => $this->scopeLabel(request()),
            // A custom range has no preset name — the date range beside it already says it all.
            'period_label' => ($payload['period'] ?? '') === 'custom'
                ? __('custom_range')
                : __($payload['period'] ?? 'this_month'),
            'range'        => $range,
            'generated_at' => Carbon::now()->format('d M Y, H:i'),
            'kpis'         => $kpis,
            'headings'     => $headings,
            'rows'         => $rows,
            'currency'     => $payload['currency'] ?? '',
            'theme'        => $this->themeColor(),
        ])->render();

        $mpdf = new \Mpdf\Mpdf([
            'mode'        => 'utf-8',
            // Wide tables need landscape; A4-L keeps 10+ columns readable.
            'format'      => 'A4-L',
            'margin_top'  => 10,
            'margin_bottom' => 10,
            'margin_left' => 8,
            'margin_right' => 8,
        ]);
        $mpdf->WriteHTML($html);

        return response($mpdf->Output($filename, \Mpdf\Output\Destination::STRING_RETURN), 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /**
     * Panel theme colour, so exported files match the admin's branding instead of a
     * hardcoded blue. Falls back to the default when the setting is missing/malformed.
     */
    private function themeColor(): string
    {
        $c = (string) (Setting::get_value('admin_theme_color') ?: '');
        return preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? $c : '#435ebe';
    }

    /** app_name setting; it may be a per-language JSON blob. */
    private function appName(): string
    {
        $raw = Setting::get_value('app_name') ?: (string) config('app.name');
        $arr = json_decode((string) $raw, true);
        if (is_array($arr)) {
            return (string) ($arr[app()->getLocale()] ?? $arr['en'] ?? reset($arr) ?: '');
        }
        return (string) $raw;
    }

    /** "Country / Zone" label for the export header. */
    private function scopeLabel(Request $request): string
    {
        $parts = [];
        if ($cid = (int) $request->input('country_id', 0)) {
            $parts[] = (string) (Country::where('id', $cid)->value('name') ?: '');
        }
        if ($zid = (int) $request->input('zone_id', 0)) {
            $parts[] = (string) (Zone::where('id', $zid)->value('name') ?: '');
        }
        return implode(' / ', array_filter($parts));
    }

    /* ===================== plumbing ===================== */

    /** country_id / zone_id / period / [start,end,prevStart,prevEnd,gran] for a request. */
    private function scope(Request $request): array
    {
        $countryId = (int) $request->input('country_id', 0) ?: null;
        $zoneId    = (int) $request->input('zone_id', 0) ?: null;
        $period    = $this->resolvePeriod($request->input('period'));

        // A custom range needs both ends; without them fall back to the default preset.
        if ($period === 'custom') {
            $range = $this->customRange($request->input('start_date'), $request->input('end_date'));
            if ($range === null) {
                $period = 'this_month';
            } else {
                [$s, $e, $ps, $pe, $gran] = $range;
                return compact('countryId', 'zoneId', 'period', 's', 'e', 'ps', 'pe', 'gran');
            }
        }

        [$s, $e, $ps, $pe, $gran] = $this->periodRange($period);

        return compact('countryId', 'zoneId', 'period', 's', 'e', 'ps', 'pe', 'gran');
    }

    /**
     * [start, end, prevStart, prevEnd, granularity] for a user-picked range.
     * The previous window is the same length immediately before it, so the trend arrows
     * still compare like with like. Bucket size adapts to the span so a long range doesn't
     * render hundreds of points. Returns null when the dates are missing/unparseable.
     */
    private function customRange($start, $end): ?array
    {
        if (!$start || !$end) {
            return null;
        }
        try {
            $s = Carbon::parse($start)->startOfDay();
            $e = Carbon::parse($end)->endOfDay();
        } catch (\Throwable $ex) {
            return null;
        }
        if ($e->lt($s)) {
            [$s, $e] = [$e->copy()->startOfDay(), $s->copy()->endOfDay()];
        }

        $days = $s->diffInDays($e) + 1;
        $gran = $days <= 2 ? 'hour' : ($days <= 92 ? 'day' : 'month');

        // Same-length window ending right before the selected one.
        $prevEnd   = $s->copy()->subSecond();
        $prevStart = $s->copy()->subDays($days);

        return [$s, $e, $prevStart, $prevEnd, $gran];
    }

    private function loadLangs(): void
    {
        if ($this->langCodeById) return;
        foreach (app(LanguageService::class)->getActiveLanguages() as $lang) {
            if (!empty($lang->code)) $this->langCodeById[(int) $lang->id] = (string) $lang->code;
        }
    }

    /** id => ['en' => name, ...] from a *_translations table. */
    private function transMap(string $table, string $fk, array $ids): array
    {
        if (!$ids) return [];
        $this->loadLangs();
        $map = [];
        foreach (DB::table($table)->whereIn($fk, $ids)->get([$fk, 'language_id', 'name']) as $r) {
            $code = $this->langCodeById[$r->language_id] ?? null;
            if ($code !== null && $r->name !== null && $r->name !== '') {
                $map[$r->$fk][$code] = $r->name;
            }
        }
        return $map;
    }

    private function localized(array $map, $id, ?string $base): array|string
    {
        $obj = $map[$id] ?? null;
        return (is_array($obj) && $obj) ? $obj : ($base ?? '');
    }

    /** Common envelope every report returns. */
    private function envelope(array $sc, array $payload, bool $zoneSupported = true): array
    {
        return array_merge([
            'currency'       => $this->currency($sc['countryId']),
            'period'         => $sc['period'],
            'zone_supported' => $zoneSupported,
            'range'          => [
                'start' => $sc['s'] ? $sc['s']->toDateString() : null,
                'end'   => $sc['e'] ? $sc['e']->toDateString() : null,
            ],
        ], $payload);
    }

    /** KPI card shape. */
    private function kpi(string $label, $value, string $type = 'number', ?array $trend = null): array
    {
        return array_filter([
            'label' => $label,
            'value' => $value,
            'type'  => $type, // number | money | percent
            'trend' => $trend,
        ], fn ($v) => $v !== null);
    }

    /* ===================== 1. SALES ===================== */

    public function sales(Request $request)
    {
        $sc = $this->scope($request);
        [$c, $z, $s, $e, $ps, $pe] = [$sc['countryId'], $sc['zoneId'], $sc['s'], $sc['e'], $sc['ps'], $sc['pe']];

        // Each of these hits the DB, so every value below is computed exactly once and reused:
        //   $mode    -> the two revenue sums; total revenue is just their sum (no re-query)
        //   $charges -> ONE pass over the charge columns for delivery + additional + breakdown
        //   $series  -> ONE bucketed query set, feeding BOTH the chart and the table rows
        $mode    = $this->revenueByChannel($c, $z, $s, $e);
        $rev     = $mode['quick'] + $mode['ecommerce'];
        $revPrev = $this->revenue($c, $z, $ps, $pe);

        $orders  = (int) $this->ordersQuery($c, $z, $s, $e)->count();
        $ordPrev = (int) $this->ordersQuery($c, $z, $ps, $pe)->count();

        $pl      = $this->costAndProfit($c, $z, $s, $e);
        $charges = $this->chargeSummary($c, $z, $s, $e);
        $series  = $this->revenueSeries($c, $z, $s, $e, $sc['gran']);

        $modeOrders = $this->ordersQuery($c, $z, $s, $e)
            ->selectRaw('channel, COUNT(*) as c')->groupBy('channel')->pluck('c', 'channel');

        return CommonHelper::responseWithData($this->envelope($sc, [
            'summary' => [
                $this->kpi('total_revenue', round($rev, 2), 'money', $this->trend($rev, $revPrev)),
                $this->kpi('total_orders', $orders, 'number', $this->trend($orders, $ordPrev)),
                $this->kpi('average_order_value', $orders > 0 ? round($rev / $orders, 2) : 0, 'money'),
                $this->kpi('delivery_earned', $charges['delivery_earned'], 'money'),
                $this->kpi('additional_charges', $charges['additional'], 'money'),
                $this->kpi('tax_collected', round($this->tax($c, $z, $s, $e), 2), 'money'),
                $this->kpi('discount_given', round($this->discount($c, $z, $s, $e), 2), 'money'),
                $this->kpi('refunds', round($this->refunds($c, $z, $s, $e), 2), 'money'),
                $this->kpi('gross_profit', $pl['profit'], 'money'),
            ],
            'mode_breakdown' => [
                ['mode' => 'quick', 'orders' => (int) ($modeOrders['quick'] ?? 0), 'revenue' => $mode['quick']],
                ['mode' => 'ecommerce', 'orders' => (int) ($modeOrders['ecommerce'] ?? 0), 'revenue' => $mode['ecommerce']],
            ],
            'series' => $series,
            'rows'   => $this->salesRows($series),
        ]));
    }

    /** Revenue + orders bucketed over the period (quick order-level + ecom item-level). */
    private function revenueSeries(?int $c, ?int $z, $s, $e, string $gran): array
    {
        if (!$s) { // all-time -> last 12 months
            $s = Carbon::now()->startOfMonth()->subMonths(11);
            $e = Carbon::now()->endOfMonth();
            $gran = 'month';
        }
        [$sqlFmt, $keyFmt, $step] = $this->seriesFormat($gran);

        $quick = $this->ordersQuery($c, $z, $s, $e)
            ->where('orders.channel', 'quick')->whereIn('orders.active_status', self::REVENUE_STATUSES)
            ->selectRaw("DATE_FORMAT(orders.created_at, '$sqlFmt') as k")
            ->selectRaw('SUM(' . self::ORDER_REVENUE_SQL . ') as v')
            ->groupBy('k')->pluck('v', 'k');

        $ecom = $this->items($c, $z, $s, $e)
            ->where('o.channel', 'ecommerce')->whereIn('oi.active_status', self::REVENUE_STATUSES)
            ->selectRaw("DATE_FORMAT(o.created_at, '$sqlFmt') as k")
            ->selectRaw('SUM(' . self::ITEM_REVENUE_SQL . ') as v')
            ->groupBy('k')->pluck('v', 'k');

        $ordersBy = $this->ordersQuery($c, $z, $s, $e)
            ->selectRaw("DATE_FORMAT(orders.created_at, '$sqlFmt') as k")->selectRaw('COUNT(*) as c')
            ->groupBy('k')->pluck('c', 'k');

        $labels = $rev = $ord = [];
        $cur = $s->copy();
        $guard = 0;
        while ($cur <= $e && $guard++ < 400) {
            $k = $cur->format($keyFmt);
            $labels[] = $this->seriesLabel($cur, $gran);
            $rev[] = round((float) ($quick[$k] ?? 0) + (float) ($ecom[$k] ?? 0), 2);
            $ord[] = (int) ($ordersBy[$k] ?? 0);
            $cur->{$step}();
        }
        return ['labels' => $labels, 'revenue' => $rev, 'orders' => $ord];
    }

    /**
     * Per-bucket table for the sales report. Takes the ALREADY-computed series so the
     * bucketed queries run once for the chart + table instead of twice.
     */
    private function salesRows(array $series): array
    {
        $out = [];
        foreach ($series['labels'] as $i => $label) {
            $orders = $series['orders'][$i];
            $revenue = $series['revenue'][$i];
            $out[] = [
                'period'  => $label,
                'orders'  => $orders,
                'revenue' => $revenue,
                'aov'     => $orders > 0 ? round($revenue / $orders, 2) : 0,
            ];
        }
        return $out;
    }

    /* ===================== 2. ORDERS ===================== */

    public function orders(Request $request)
    {
        $sc = $this->scope($request);
        [$c, $z, $s, $e] = [$sc['countryId'], $sc['zoneId'], $sc['s'], $sc['e']];

        // Order counts are order-level for BOTH channels (an order is one order).
        $counts = $this->ordersQuery($c, $z, $s, $e)
            ->selectRaw('orders.active_status as st, COUNT(*) as c')->groupBy('st')->pluck('c', 'st');

        $total = (int) array_sum($counts->all());

        $rows = [];
        foreach (OrderStatusList::orderBy('id')->get() as $st) {
            $n = (int) ($counts[$st->id] ?? 0);
            $rows[] = [
                'status_id' => (int) $st->id,
                'status'    => OrderStatusList::getTranslatedName((int) $st->id),
                'orders'    => $n,
                'share'     => $total > 0 ? round(($n / $total) * 100, 1) : 0,
            ];
        }

        $delivered = (int) ($counts[OrderStatusList::$delivered] ?? 0);
        $cancelled = (int) ($counts[OrderStatusList::$cancelled] ?? 0);
        $returned  = (int) ($counts[OrderStatusList::$returned] ?? 0);
        $pending   = $total - $delivered - $cancelled - $returned;

        return CommonHelper::responseWithData($this->envelope($sc, [
            'summary' => [
                $this->kpi('total_orders', $total),
                $this->kpi('delivered', $delivered),
                $this->kpi('in_progress', max($pending, 0)),
                $this->kpi('cancelled', $cancelled),
                $this->kpi('returned', $returned),
                $this->kpi('fulfilment_rate', $total > 0 ? round(($delivered / $total) * 100, 1) : 0, 'percent'),
            ],
            'rows' => $rows,
        ]));
    }

    /* ===================== 3. PRODUCT PERFORMANCE ===================== */

    public function products(Request $request)
    {
        $sc = $this->scope($request);
        [$c, $z, $s, $e] = [$sc['countryId'], $sc['zoneId'], $sc['s'], $sc['e']];

        $rows = $this->items($c, $z, $s, $e)
            ->join('product_variants as v', 'oi.product_variant_id', '=', 'v.id')
            ->join('products as p', 'v.product_id', '=', 'p.id')
            ->whereRaw($this->confirmedItemsRaw())
            ->groupBy('p.id', 'p.image')
            ->selectRaw('p.id, p.image, MIN(v.id) as variant_id')
            // revenue is the gross (tax-inclusive) product value; net_sales strips the tax out
            // so profit/margin are measured on what the business actually keeps.
            ->selectRaw('SUM(oi.sub_total) as revenue')
            ->selectRaw('SUM(' . self::ITEM_NET_SALES_SQL . ') as net_sales')
            ->selectRaw('SUM(oi.quantity) as units')
            ->selectRaw('COUNT(DISTINCT oi.order_id) as orders')
            ->selectRaw('SUM(' . self::ITEM_COST_SQL . ') as cost')
            ->selectRaw('SUM(' . self::ITEM_PROFIT_SQL . ') as profit')
            ->orderByDesc('revenue')
            ->limit(100)->get();

        $names = $this->transMap('product_variant_translations', 'product_variant_id', $rows->pluck('variant_id')->filter()->all());

        $list = $rows->map(function ($r) use ($names) {
            $rev = round((float) $r->revenue, 2);
            $net = (float) $r->net_sales;
            $profit = round((float) $r->profit, 2);
            return [
                'name'    => $this->localized($names, $r->variant_id, null) ?: ('#' . $r->id),
                'image'   => $r->image ? asset('storage/' . $r->image) : '',
                'units'   => (int) $r->units,
                'orders'  => (int) $r->orders,
                'revenue' => $rev,
                'cost'    => round((float) $r->cost, 2),
                'profit'  => $profit,
                'margin'  => $net > 0 ? round(($profit / $net) * 100, 1) : 0,
            ];
        })->all();

        $pl = $this->costAndProfit($c, $z, $s, $e);

        return CommonHelper::responseWithData($this->envelope($sc, [
            'summary' => [
                $this->kpi('products_sold', count($list)),
                $this->kpi('units_sold', $this->unitsSold($c, $z, $s, $e)),
                $this->kpi('product_revenue', round(array_sum(array_column($list, 'revenue')), 2), 'money'),
                $this->kpi('gross_profit', $pl['profit'], 'money'),
                $this->kpi('margin', $pl['margin'], 'percent'),
            ],
            'rows' => $list,
        ]));
    }

    /* ===================== 4. CUSTOMERS ===================== */

    public function customers(Request $request)
    {
        $sc = $this->scope($request);
        [$c, $z, $s, $e] = [$sc['countryId'], $sc['zoneId'], $sc['s'], $sc['e']];

        $base = $this->ordersQuery($c, $z, $s, $e)->whereNotNull('orders.user_id');

        $agg = (clone $base)
            ->selectRaw('orders.user_id, COUNT(*) as orders, MAX(orders.created_at) as last_order')
            ->groupBy('orders.user_id')->get();

        $userIds = $agg->pluck('user_id')->all();
        if (!$userIds) {
            return CommonHelper::responseWithData($this->envelope($sc, [
                'summary' => [$this->kpi('customers', 0), $this->kpi('total_spent', 0, 'money')],
                'rows'    => [],
            ]));
        }

        // Spend is channel-aware, exactly like revenue.
        $quickSpend = $this->ordersQuery($c, $z, $s, $e)
            ->where('orders.channel', 'quick')->whereIn('orders.active_status', self::REVENUE_STATUSES)
            ->whereIn('orders.user_id', $userIds)
            ->selectRaw('orders.user_id, SUM(' . self::ORDER_REVENUE_SQL . ') as v')
            ->groupBy('orders.user_id')->pluck('v', 'user_id');

        $ecomSpend = $this->items($c, $z, $s, $e)
            ->where('o.channel', 'ecommerce')->whereIn('oi.active_status', self::REVENUE_STATUSES)
            ->whereIn('o.user_id', $userIds)
            ->selectRaw('o.user_id as user_id, SUM(' . self::ITEM_REVENUE_SQL . ') as v')
            ->groupBy('o.user_id')->pluck('v', 'user_id');

        $users = DB::table('users')->whereIn('id', $userIds)
            ->get(['id', 'name', 'email', 'mobile', 'country_code', 'profile'])->keyBy('id');

        $rows = $agg->map(function ($r) use ($users, $quickSpend, $ecomSpend) {
            $u = $users[$r->user_id] ?? null;
            $spent = (float) ($quickSpend[$r->user_id] ?? 0) + (float) ($ecomSpend[$r->user_id] ?? 0);
            $orders = (int) $r->orders;
            $p = $u->profile ?? '';
            return [
                'name'       => $u->name ?? ('#' . $r->user_id),
                'email'      => $u->email ?? '',
                'mobile'     => $u ? trim(($u->country_code ?? '') . ' ' . ($u->mobile ?? '')) : '',
                'image'      => $p ? (str_contains($p, '://') ? $p : asset('storage/' . $p)) : '',
                'orders'     => $orders,
                'spent'      => round($spent, 2),
                'aov'        => $orders > 0 ? round($spent / $orders, 2) : 0,
                'last_order' => $r->last_order ? Carbon::parse($r->last_order)->format('d M Y') : '',
            ];
        })->sortByDesc('spent')->values()->all();

        $totalSpent = round(array_sum(array_column($rows, 'spent')), 2);
        $totalOrders = array_sum(array_column($rows, 'orders'));

        return CommonHelper::responseWithData($this->envelope($sc, [
            'summary' => [
                $this->kpi('customers', count($rows)),
                $this->kpi('total_spent', $totalSpent, 'money'),
                $this->kpi('average_order_value', $totalOrders > 0 ? round($totalSpent / $totalOrders, 2) : 0, 'money'),
                $this->kpi('orders_per_customer', count($rows) > 0 ? round($totalOrders / count($rows), 1) : 0),
            ],
            'rows' => array_slice($rows, 0, 100),
        ]));
    }

    /* ===================== 5. INVENTORY ===================== */

    public function inventory(Request $request)
    {
        $sc = $this->scope($request);
        // Inventory is a point-in-time snapshot: the period filter does NOT apply.
        $zoneIds  = $this->zoneIds($sc['countryId'], $sc['zoneId']);
        $storeIds = $this->storeIds($zoneIds);

        if (!$storeIds) {
            return CommonHelper::responseWithData($this->envelope($sc, [
                'summary' => [$this->kpi('total_stock_items', 0)],
                'rows'    => [],
                'point_in_time' => true,
            ]));
        }

        $base = DB::table('product_variant_store_stocks as pvss')
            ->join('product_variants as v', 'pvss.product_variant_id', '=', 'v.id')
            ->join('products as p', 'v.product_id', '=', 'p.id')
            ->leftJoin('stores as st', 'pvss.store_id', '=', 'st.id')
            ->whereIn('pvss.store_id', $storeIds)
            ->where('pvss.is_listed', 1)
            ->whereNull('v.deleted_at');

        $rows = (clone $base)
            ->select([
                'pvss.product_variant_id as variant_id', 'pvss.store_id', 'p.image',
                'pvss.available', 'pvss.reserved', 'pvss.min_alert', 'pvss.stock_status',
                'pvss.is_unlimited_stock',
                'pvss.price', 'pvss.discounted_price', 'pvss.purchase_price',
                'st.name as store_name',
            ])
            ->orderBy('pvss.available')
            ->limit(300)->get();

        $names     = $this->transMap('product_variant_translations', 'product_variant_id', $rows->pluck('variant_id')->all());
        $storeName = $this->transMap('store_translations', 'store_id', $rows->pluck('store_id')->unique()->all());

        $list = $rows->map(function ($r) use ($names, $storeName) {
            $available = (int) $r->available;
            $minAlert  = (int) $r->min_alert;
            $unit      = ((float) $r->discounted_price > 0) ? (float) $r->discounted_price : (float) $r->price;

            // Unlimited stores don't track stock — they're never low or out.
            $unlimited = (int) $r->is_unlimited_stock === 1;
            if ($unlimited) {
                $state = 'unlimited';
            } elseif ((int) $r->stock_status === 0 || $available <= 0) {
                $state = 'out_of_stock';
            } elseif ($minAlert > 0 && $available <= $minAlert) {
                $state = 'low_stock';
            } else {
                $state = 'in_stock';
            }

            return [
                'name'        => $this->localized($names, $r->variant_id, null) ?: ('#' . $r->variant_id),
                'image'       => $r->image ? asset('storage/' . $r->image) : '',
                'store'       => $this->localized($storeName, $r->store_id, $r->store_name),
                'available'   => $available,
                'reserved'    => (int) $r->reserved,
                'min_alert'   => $minAlert,
                'state'       => $state,
                'is_unlimited_stock' => $unlimited ? 1 : 0,
                'unit_price'  => round($unit, 2),
                // An unlimited row has no real quantity, so it holds no stock value.
                'stock_value' => $unlimited ? 0.0 : round($available * (float) ($r->purchase_price ?: 0), 2),
            ];
        })->all();

        // Unlimited rows always count as in stock, never as low/out, and contribute
        // no stock value (their `available` is not a tracked quantity).
        $in  = (int) (clone $base)->where(function ($w) {
            $w->where('pvss.is_unlimited_stock', 1)
                ->orWhere(fn ($x) => $x->where('pvss.stock_status', 1)->where('pvss.available', '>', 0));
        })->count();
        $out = (int) (clone $base)->where('pvss.is_unlimited_stock', 0)
            ->where(fn ($w) => $w->where('pvss.stock_status', 0)->orWhere('pvss.available', '<=', 0))->count();
        $low = (int) (clone $base)->where('pvss.is_unlimited_stock', 0)
            ->where('pvss.stock_status', 1)->where('pvss.available', '>', 0)
            ->where('pvss.min_alert', '>', 0)->whereColumn('pvss.available', '<=', 'pvss.min_alert')->count();
        $stockValue = (float) (clone $base)->where('pvss.is_unlimited_stock', 0)
            ->sum(DB::raw('COALESCE(pvss.available,0) * COALESCE(pvss.purchase_price,0)'));

        return CommonHelper::responseWithData($this->envelope($sc, [
            'summary' => [
                $this->kpi('total_stock_items', (int) (clone $base)->count()),
                $this->kpi('in_stock', $in),
                $this->kpi('low_stock', $low),
                $this->kpi('out_of_stock', $out),
                $this->kpi('stock_value', round($stockValue, 2), 'money'),
            ],
            'rows'          => $list,
            'point_in_time' => true,
        ]));
    }

    /* ===================== 6. RETURNS & REFUNDS ===================== */

    public function returns(Request $request)
    {
        $sc = $this->scope($request);
        [$c, $z, $s, $e] = [$sc['countryId'], $sc['zoneId'], $sc['s'], $sc['e']];

        $base = DB::table('return_requests as rr')->join('orders as o', 'rr.order_id', '=', 'o.id');
        if ($c) $base->where('o.country_id', $c);
        if ($z) $base->where('o.zone_id', $z);
        if ($s) $base->where('rr.created_at', '>=', $s);
        if ($e) $base->where('rr.created_at', '<=', $e);

        $total    = (int) (clone $base)->count();
        $refunded = (int) (clone $base)->where('rr.status', ReturnStatusList::$rRefundCompleted)->count();
        $rejected = (int) (clone $base)->where('rr.status', ReturnStatusList::$rRejected)->count();
        $pending  = $total - $refunded - $rejected;

        // Refund money actually booked on the returned items.
        $refundAmount = (float) (clone $base)
            ->join('order_items as oi', 'rr.order_item_id', '=', 'oi.id')
            ->sum('oi.refund_amount');

        // Reason breakdown (free-text reason; grouped as stored).
        $reasons = (clone $base)
            ->selectRaw('COALESCE(NULLIF(TRIM(rr.return_reason), ""), "not_specified") as reason, COUNT(*) as c')
            ->groupBy('reason')->orderByDesc('c')->get()
            ->map(fn ($r) => [
                'reason' => $r->reason,
                'count'  => (int) $r->c,
                'share'  => $total > 0 ? round(((int) $r->c / $total) * 100, 1) : 0,
            ])->all();

        // Status breakdown across the whole return flow.
        $byStatus = (clone $base)->selectRaw('rr.status, COUNT(*) as c')->groupBy('rr.status')->pluck('c', 'status');
        $statusRows = [];
        foreach (ReturnStatusList::getAllStatuses() as $id => $_name) {
            $statusRows[] = [
                'status_id' => (int) $id,
                'status'    => ReturnStatusList::getTranslatedName((int) $id),
                'count'     => (int) ($byStatus[$id] ?? 0),
            ];
        }

        // Detail rows.
        $rows = (clone $base)
            ->leftJoin('users as u', 'rr.user_id', '=', 'u.id')
            ->leftJoin('order_items as oi2', 'rr.order_item_id', '=', 'oi2.id')
            ->orderByDesc('rr.id')->limit(200)
            ->get([
                'rr.id', 'rr.order_id', 'rr.return_reason', 'rr.status', 'rr.reject_reason', 'rr.created_at',
                'u.name as customer', 'oi2.product_name', 'oi2.quantity', 'oi2.refund_amount',
            ])
            ->map(fn ($r) => [
                'id'            => (int) $r->id,
                'order_id'      => (int) $r->order_id,
                'customer'      => $r->customer ?: '',
                'product'       => $r->product_name ?: '',
                'quantity'      => (int) $r->quantity,
                'reason'        => $r->return_reason ?: '',
                'status'        => ReturnStatusList::getTranslatedName((int) $r->status),
                'reject_reason' => $r->reject_reason ?: '',
                'refund_amount' => round((float) $r->refund_amount, 2),
                'date'          => $r->created_at ? Carbon::parse($r->created_at)->format('d M Y') : '',
            ])->all();

        $ordersInPeriod = (int) $this->ordersQuery($c, $z, $s, $e)->count();

        return CommonHelper::responseWithData($this->envelope($sc, [
            'summary' => [
                $this->kpi('total_returns', $total),
                $this->kpi('pending', max($pending, 0)),
                $this->kpi('refund_completed', $refunded),
                $this->kpi('rejected', $rejected),
                $this->kpi('refund_amount', round($refundAmount, 2), 'money'),
                $this->kpi('return_rate', $ordersInPeriod > 0 ? round(($total / $ordersInPeriod) * 100, 1) : 0, 'percent'),
            ],
            'reasons'          => $reasons,
            'status_breakdown' => $statusRows,
            'rows'             => $rows,
        ]));
    }

    /* ===================== 7. DELIVERY ===================== */

    public function delivery(Request $request)
    {
        $sc = $this->scope($request);
        // Delivery boys are bound to a country, not a zone -> country scope only.
        [$c, $s, $e] = [$sc['countryId'], $sc['s'], $sc['e']];

        $boys = DeliveryBoy::query();
        if ($c) $boys->where('country_id', $c);
        $boys = $boys->get(['id', 'name', 'mobile', 'status', 'balance', 'cash_received']);
        $boyIds = $boys->pluck('id')->all();

        if (!$boyIds) {
            return CommonHelper::responseWithData($this->envelope($sc, [
                'summary' => [$this->kpi('delivery_staff', 0)],
                'rows'    => [],
            ], false));
        }

        $dateWin = function ($q, $col) use ($s, $e) {
            if ($s) $q->where($col, '>=', $s);
            if ($e) $q->where($col, '<=', $e);
            return $q;
        };

        // Quick = order-level assignment; ecommerce = item-level. Count both.
        $quick = $dateWin(DB::table('orders')->whereNull('deleted_at')
            ->where('channel', 'quick')->whereIn('delivery_boy_id', $boyIds), 'created_at')
            ->selectRaw('delivery_boy_id, COUNT(*) as assigned')
            ->selectRaw('SUM(CASE WHEN active_status = ' . OrderStatusList::$delivered . ' THEN 1 ELSE 0 END) as delivered')
            ->selectRaw('SUM(COALESCE(delivery_boy_bonus_amount,0)) as bonus')
            ->groupBy('delivery_boy_id')->get()->keyBy('delivery_boy_id');

        $ecom = $dateWin(DB::table('order_items as oi')->join('orders as o', 'oi.order_id', '=', 'o.id')
            ->whereNull('o.deleted_at')->whereNull('oi.deleted_at')
            ->where('o.channel', 'ecommerce')->whereIn('oi.delivery_boy_id', $boyIds), 'o.created_at')
            ->selectRaw('oi.delivery_boy_id, COUNT(*) as assigned')
            ->selectRaw('SUM(CASE WHEN oi.active_status = ' . OrderStatusList::$delivered . ' THEN 1 ELSE 0 END) as delivered')
            ->selectRaw('SUM(COALESCE(oi.delivery_boy_bonus_amount,0)) as bonus')
            ->groupBy('oi.delivery_boy_id')->get()->keyBy('delivery_boy_id');

        // COD cash physically collected in the window.
        $cash = $dateWin(DB::table('delivery_boy_cash_collections')
            ->whereIn('delivery_boy_id', $boyIds)->where('type', 'COD'), 'transaction_date')
            ->selectRaw('delivery_boy_id, SUM(amount) as amt')
            ->groupBy('delivery_boy_id')->pluck('amt', 'delivery_boy_id');

        // Return pickups handled.
        $rets = $dateWin(DB::table('return_requests')->whereIn('delivery_boy_id', $boyIds), 'created_at')
            ->selectRaw('delivery_boy_id, COUNT(*) as c')
            ->groupBy('delivery_boy_id')->pluck('c', 'delivery_boy_id');

        $names = $this->transMap('delivery_boy_translations', 'delivery_boy_id', $boyIds);

        $rows = $boys->map(function ($b) use ($quick, $ecom, $cash, $rets, $names) {
            $assigned  = (int) (($quick[$b->id]->assigned ?? 0) + ($ecom[$b->id]->assigned ?? 0));
            $delivered = (int) (($quick[$b->id]->delivered ?? 0) + ($ecom[$b->id]->delivered ?? 0));
            $bonus     = (float) (($quick[$b->id]->bonus ?? 0) + ($ecom[$b->id]->bonus ?? 0));

            return [
                'name'         => $this->localized($names, $b->id, $b->getAttributeValue('name')),
                'mobile'       => $b->mobile ?? '',
                'assigned'     => $assigned,
                'delivered'    => $delivered,
                'success_rate' => $assigned > 0 ? round(($delivered / $assigned) * 100, 1) : 0,
                'returns'      => (int) ($rets[$b->id] ?? 0),
                'earnings'     => round($bonus, 2),
                'cash_collected' => round((float) ($cash[$b->id] ?? 0), 2),
                'balance'      => round((float) $b->balance, 2),
                'cash_in_hand' => round((float) $b->cash_received, 2),
                'active'       => (int) $b->status === DeliveryBoy::$statusActive,
            ];
        })->sortByDesc('delivered')->values()->all();

        $totAssigned  = array_sum(array_column($rows, 'assigned'));
        $totDelivered = array_sum(array_column($rows, 'delivered'));

        return CommonHelper::responseWithData($this->envelope($sc, [
            'summary' => [
                $this->kpi('delivery_staff', count($rows)),
                $this->kpi('orders_assigned', $totAssigned),
                $this->kpi('orders_delivered', $totDelivered),
                $this->kpi('success_rate', $totAssigned > 0 ? round(($totDelivered / $totAssigned) * 100, 1) : 0, 'percent'),
                $this->kpi('total_earnings', round(array_sum(array_column($rows, 'earnings')), 2), 'money'),
                $this->kpi('cash_collected', round(array_sum(array_column($rows, 'cash_collected')), 2), 'money'),
            ],
            'rows' => $rows,
        ], false));
    }

    /* ===================== 8. PAYMENT ===================== */

    public function payment(Request $request)
    {
        $sc = $this->scope($request);
        [$c, $z, $s, $e] = [$sc['countryId'], $sc['zoneId'], $sc['s'], $sc['e']];

        // Payment method lives on the order for both channels; revenue stays channel-aware.
        $methods = $this->ordersQuery($c, $z, $s, $e)
            ->selectRaw('COALESCE(NULLIF(orders.payment_method, ""), "unknown") as method, COUNT(*) as orders')
            ->groupBy('method')->orderByDesc('orders')->get();

        $rows = [];
        $totalOrders = 0;
        foreach ($methods as $m) {
            $quickRev = (float) $this->ordersQuery($c, $z, $s, $e)
                ->where('orders.payment_method', $m->method === 'unknown' ? '' : $m->method)
                ->where('orders.channel', 'quick')->whereIn('orders.active_status', self::REVENUE_STATUSES)
                ->sum(DB::raw(self::ORDER_REVENUE_SQL));

            $ecomRev = (float) $this->items($c, $z, $s, $e)
                ->where('o.payment_method', $m->method === 'unknown' ? '' : $m->method)
                ->where('o.channel', 'ecommerce')->whereIn('oi.active_status', self::REVENUE_STATUSES)
                ->sum(DB::raw(self::ITEM_REVENUE_SQL));

            $totalOrders += (int) $m->orders;
            $rows[] = [
                'method'  => $m->method,
                'orders'  => (int) $m->orders,
                'revenue' => round($quickRev + $ecomRev, 2),
                'is_cod'  => strtolower($m->method) === 'cod',
            ];
        }
        foreach ($rows as &$r) {
            $r['share'] = $totalOrders > 0 ? round(($r['orders'] / $totalOrders) * 100, 1) : 0;
        }
        unset($r);

        // COD collection status: what the boys actually handed back vs still in hand.
        $codOrders = (int) $this->ordersQuery($c, $z, $s, $e)->where('orders.payment_method', 'COD')->count();
        $codRevenue = array_sum(array_map(fn ($r) => $r['is_cod'] ? $r['revenue'] : 0, $rows));

        $cashQ = DB::table('delivery_boy_cash_collections');
        if ($s) $cashQ->where('transaction_date', '>=', $s);
        if ($e) $cashQ->where('transaction_date', '<=', $e);
        $collected = (float) (clone $cashQ)->where('type', 'COD')->sum('amount');
        $deposited = (float) (clone $cashQ)->where('type', 'delivery_boy_cash_collection')->sum('amount');

        $boyCash = DeliveryBoy::query();
        if ($c) $boyCash->where('country_id', $c);
        $inHand = (float) $boyCash->sum('cash_received');

        return CommonHelper::responseWithData($this->envelope($sc, [
            'summary' => [
                $this->kpi('total_orders', $totalOrders),
                $this->kpi('total_collected', round(array_sum(array_column($rows, 'revenue')), 2), 'money'),
                $this->kpi('cod_orders', $codOrders),
                $this->kpi('cod_value', round($codRevenue, 2), 'money'),
                $this->kpi('cash_collected', round($collected, 2), 'money'),
                $this->kpi('cash_deposited', round($deposited, 2), 'money'),
                $this->kpi('cash_in_hand', round($inHand, 2), 'money'),
            ],
            'rows' => $rows,
        ]));
    }

    /* ===================== 9. CATEGORY ===================== */

    public function category(Request $request)
    {
        $sc = $this->scope($request);
        [$c, $z, $s, $e] = [$sc['countryId'], $sc['zoneId'], $sc['s'], $sc['e']];

        $rows = $this->items($c, $z, $s, $e)
            ->join('product_variants as v', 'oi.product_variant_id', '=', 'v.id')
            ->join('products as p', 'v.product_id', '=', 'p.id')
            ->join('categories as cat', 'p.category_id', '=', 'cat.id')
            ->whereRaw($this->confirmedItemsRaw())
            ->groupBy('cat.id', 'cat.name')
            ->selectRaw('cat.id as category_id, cat.name as name')
            ->selectRaw('SUM(oi.sub_total) as revenue')
            ->selectRaw('SUM(oi.quantity) as units')
            ->selectRaw('COUNT(DISTINCT oi.order_id) as orders')
            ->selectRaw('COUNT(DISTINCT p.id) as products')
            ->selectRaw('SUM(' . self::ITEM_PROFIT_SQL . ') as profit')
            ->orderByDesc('revenue')->get();

        $names = $this->transMap('category_translations', 'category_id', $rows->pluck('category_id')->all());
        $totalRev = (float) $rows->sum('revenue');

        $list = $rows->map(function ($r) use ($names, $totalRev) {
            $rev = round((float) $r->revenue, 2);
            return [
                'category' => $this->localized($names, $r->category_id, $r->name),
                'orders'   => (int) $r->orders,
                'units'    => (int) $r->units,
                'products' => (int) $r->products,
                'revenue'  => $rev,
                'profit'   => round((float) $r->profit, 2),
                'share'    => $totalRev > 0 ? round(($rev / $totalRev) * 100, 1) : 0,
            ];
        })->all();

        return CommonHelper::responseWithData($this->envelope($sc, [
            'summary' => [
                $this->kpi('categories_sold', count($list)),
                $this->kpi('total_revenue', round($totalRev, 2), 'money'),
                $this->kpi('units_sold', (int) $rows->sum('units')),
                $this->kpi('top_category', $list[0]['category'] ?? '-', 'text'),
            ],
            'rows' => $list,
        ]));
    }

    /* ===================== 10. PROMO / COUPONS ===================== */

    public function promo(Request $request)
    {
        $sc = $this->scope($request);
        // Promo codes are configured per country (country_ids json) -> country scope only.
        [$c, $z, $s, $e] = [$sc['countryId'], $sc['zoneId'], $sc['s'], $sc['e']];

        $used = $this->ordersQuery($c, $z, $s, $e)
            ->whereNotNull('orders.promo_code_id')->where('orders.promo_code_id', '>', 0);

        /*
         * A promo pays out in one of two ways (promo_codes.discount_apply_type):
         *   instant -> money comes off the bill now  -> promo_discount
         *              (quick: on the order; ecommerce: distributed onto the items)
         *   wallet  -> CASHBACK, promised now and credited to the wallet only after the order
         *              is delivered -> orders.cashback_amount, with cashback_credited = 1 once
         *              it actually landed in the wallet, 0 while still owed.
         * A cashback promo therefore has promo_discount = 0, so counting only the discount
         * would make it look like it gave away nothing.
         */
        $agg = (clone $used)
            ->selectRaw('orders.promo_code_id as pid')
            ->selectRaw('COUNT(*) as uses')
            ->selectRaw('COUNT(DISTINCT orders.user_id) as users')
            ->selectRaw('SUM(CASE WHEN orders.channel = "quick" THEN COALESCE(orders.promo_discount,0) ELSE 0 END) as quick_discount')
            ->selectRaw('SUM(COALESCE(orders.cashback_amount,0)) as cashback')
            ->selectRaw('SUM(CASE WHEN orders.cashback_credited = 1 THEN COALESCE(orders.cashback_amount,0) ELSE 0 END) as cashback_credited')
            ->groupBy('pid')->get();

        $pids = $agg->pluck('pid')->all();

        // Ecommerce promo money is distributed onto the items.
        $ecomDiscount = $pids
            ? $this->items($c, $z, $s, $e)
                ->where('o.channel', 'ecommerce')->whereIn('o.promo_code_id', $pids)
                ->selectRaw('o.promo_code_id as pid, SUM(COALESCE(oi.promo_discount,0)) as v')
                ->groupBy('pid')->pluck('v', 'pid')
            : collect();

        $codes = DB::table('promo_codes')->whereIn('id', $pids)
            ->get(['id', 'title', 'promo_code', 'discount_type', 'discount_apply_type', 'discount', 'status', 'total_usage_limit'])
            ->keyBy('id');

        $rows = $agg->map(function ($r) use ($codes, $ecomDiscount) {
            $pc = $codes[$r->pid] ?? null;

            $discount  = (float) $r->quick_discount + (float) ($ecomDiscount[$r->pid] ?? 0);
            $cashback  = (float) $r->cashback;
            $credited  = (float) $r->cashback_credited;
            $pending   = max($cashback - $credited, 0);
            $uses      = (int) $r->uses;

            // What the promo actually cost the business: instant discount + cashback promised.
            $benefit = $discount + $cashback;

            return [
                'code'             => $pc->promo_code ?? ('#' . $r->pid),
                'title'            => $pc->title ?? '',
                'apply_type'       => $pc->discount_apply_type ?? '',
                'uses'             => $uses,
                'users'            => (int) $r->users,
                'discount'         => round($discount, 2),
                'cashback'         => round($cashback, 2),
                'cashback_credited' => round($credited, 2),
                'cashback_pending' => round($pending, 2),
                'total_benefit'    => round($benefit, 2),
                'avg_benefit'      => $uses > 0 ? round($benefit / $uses, 2) : 0,
                'active'           => $pc ? (int) $pc->status === 1 : false,
            ];
        })->sortByDesc('total_benefit')->values()->all();

        $totalUses     = array_sum(array_column($rows, 'uses'));
        $totalDiscount = round(array_sum(array_column($rows, 'discount')), 2);
        $totalCashback = round(array_sum(array_column($rows, 'cashback')), 2);
        $pendingCash   = round(array_sum(array_column($rows, 'cashback_pending')), 2);
        $totalBenefit  = round($totalDiscount + $totalCashback, 2);
        $ordersInPeriod = (int) $this->ordersQuery($c, $z, $s, $e)->count();

        return CommonHelper::responseWithData($this->envelope($sc, [
            'summary' => [
                $this->kpi('coupons_used', count($rows)),
                $this->kpi('total_redemptions', $totalUses),
                $this->kpi('total_discount', $totalDiscount, 'money'),
                $this->kpi('total_cashback', $totalCashback, 'money'),
                // Promised on delivery but not yet in the customer's wallet — a real liability.
                $this->kpi('cashback_pending', $pendingCash, 'money'),
                $this->kpi('total_benefit', $totalBenefit, 'money'),
                $this->kpi('average_benefit', $totalUses > 0 ? round($totalBenefit / $totalUses, 2) : 0, 'money'),
                $this->kpi('orders_with_coupon', $ordersInPeriod > 0 ? round(($totalUses / $ordersInPeriod) * 100, 1) : 0, 'percent'),
            ],
            'rows' => $rows,
        ]));
    }

    /* ===================== scoping + money (was ReportService) ===================== */

    /** Confirmed-revenue statuses (excludes payment-pending / cancelled / returned). */
    private const REVENUE_STATUSES = [2, 3, 4, 5, 6, 9, 10, 11];

    private const ORDER_REVENUE_SQL = 'COALESCE(orders.final_total,0) + COALESCE(orders.wallet_balance,0)';
    private const ITEM_REVENUE_SQL  = 'COALESCE(oi.final_total,0) + COALESCE(oi.wallet_balance,0)';

    /**
     * Effective selling price per unit: discounted_price when set, else price.
     * NOTE: this is TAX-INCLUSIVE — the catalogue stores tax-inclusive prices
     * (verified: tax_amount = sub_total * pct / (100 + pct)), and sub_total = unit * qty.
     */
    private const ITEM_UNIT_PRICE_SQL = '(CASE WHEN oi.discounted_price > 0 THEN oi.discounted_price ELSE oi.price END)';

    /**
     * Net sales = what the business actually keeps from the sale: the tax-inclusive
     * sub_total with the tax carved back out. Tax is collected on behalf of the government,
     * so it is NOT margin and must be removed before subtracting cost.
     */
    private const ITEM_NET_SALES_SQL = '(COALESCE(oi.sub_total,0) - COALESCE(oi.tax_amount,0))';

    /** purchase_price is the per-unit wholesale cost snapshot taken at order time. */
    private const ITEM_COST_SQL   = '(COALESCE(oi.purchase_price,0) * oi.quantity)';
    private const ITEM_PROFIT_SQL = '(' . self::ITEM_NET_SALES_SQL . ' - ' . self::ITEM_COST_SQL . ')';

    /** 'custom' is driven by start_date/end_date instead of a preset window. */
    private const PERIODS = ['today', 'last_7_days', 'this_month', 'last_quarter', 'all', 'custom'];

    /* ===================== period ===================== */

    /**
     * [start, end, prevStart, prevEnd, granularity].
     * Null start/end = unbounded (all time). The previous window is the same length
     * immediately before, so trend % compares like with like.
     */
    private function periodRange(string $period): array
    {
        $now = Carbon::now();

        switch ($period) {
            case 'today':
                $s = $now->copy()->startOfDay();
                $e = $now->copy()->endOfDay();
                return [$s, $e, $s->copy()->subDay(), $s->copy()->subSecond(), 'hour'];

            case 'last_7_days':
                $s = $now->copy()->subDays(6)->startOfDay();
                $e = $now->copy()->endOfDay();
                return [$s, $e, $s->copy()->subDays(7), $s->copy()->subSecond(), 'day'];

            case 'last_quarter':
                // The previous COMPLETE calendar quarter (not a rolling 3 months).
                $s = $now->copy()->subQuarter()->firstOfQuarter()->startOfDay();
                $e = $now->copy()->subQuarter()->lastOfQuarter()->endOfDay();
                return [$s, $e, $s->copy()->subQuarter(), $s->copy()->subSecond(), 'month'];

            case 'all':
                return [null, null, null, null, 'month'];

            case 'this_month':
            default:
                $s = $now->copy()->startOfMonth();
                $e = $now->copy()->endOfMonth();
                return [$s, $e, $s->copy()->subMonth(), $s->copy()->subSecond(), 'day'];
        }
    }

    private function resolvePeriod(?string $period): string
    {
        return in_array($period, self::PERIODS, true) ? $period : 'this_month';
    }

    /* ===================== scoping ===================== */

    /** Currency symbol for the selected country (falls back to the global setting). */
    private function currency(?int $countryId): string
    {
        return $countryId
            ? (Country::where('id', $countryId)->value('currency') ?: '')
            : (Setting::get_value('currency') ?: '');
    }

    /** Zones in scope: the picked zone, else every zone of the picked country, else null (all). */
    private function zoneIds(?int $countryId, ?int $zoneId): ?array
    {
        if ($zoneId) {
            return [$zoneId];
        }
        return $countryId ? Zone::where('country_id', $countryId)->pluck('id')->all() : null;
    }

    /** Active stores serving those zones (a store can serve a quick zone and/or an ecom zone). */
    private function storeIds(?array $zoneIds): array
    {
        $q = Store::where('status', Store::$statusActive);
        if ($zoneIds !== null) {
            $q->whereIn('zone_id', $zoneIds);
        }
        return $q->pluck('id')->all();
    }

    /** Orders scoped to country/zone + created_at window. */
    private function ordersQuery(?int $countryId, ?int $zoneId, $start = null, $end = null)
    {
        $q = DB::table('orders')->whereNull('orders.deleted_at');
        if ($countryId) $q->where('orders.country_id', $countryId);
        if ($zoneId)    $q->where('orders.zone_id', $zoneId);
        if ($start)     $q->where('orders.created_at', '>=', $start);
        if ($end)       $q->where('orders.created_at', '<=', $end);
        return $q;
    }

    /** order_items joined to their order, scoped to country/zone + the ORDER's created_at. */
    private function items(?int $countryId, ?int $zoneId, $start = null, $end = null)
    {
        $q = DB::table('order_items as oi')
            ->join('orders as o', 'oi.order_id', '=', 'o.id')
            ->whereNull('o.deleted_at')
            ->whereNull('oi.deleted_at');
        if ($countryId) $q->where('o.country_id', $countryId);
        if ($zoneId)    $q->where('o.zone_id', $zoneId);
        if ($start)     $q->where('o.created_at', '>=', $start);
        if ($end)       $q->where('o.created_at', '<=', $end);
        return $q;
    }

    /**
     * "Is this item confirmed?" — channel-aware, because the authoritative status lives on the
     * order for quick and on the item for ecommerce.
     */
    private function confirmedItemsRaw(): string
    {
        $l = implode(',', self::REVENUE_STATUSES);
        return "((o.channel = 'quick' AND o.active_status IN ($l)) OR (o.channel = 'ecommerce' AND oi.active_status IN ($l)))";
    }

    /* ===================== money ===================== */

    /** Revenue = confirmed quick orders + confirmed ecommerce items (wallet added back). */
    private function revenue(?int $countryId, ?int $zoneId, $start, $end): float
    {
        // Same two sums as revenueByChannel — defined once so the two can never drift.
        $m = $this->revenueByChannel($countryId, $zoneId, $start, $end);
        return $m['quick'] + $m['ecommerce'];
    }

    /** Revenue split by channel (same rules, reported separately). */
    private function revenueByChannel(?int $countryId, ?int $zoneId, $start, $end): array
    {
        $quick = (float) $this->ordersQuery($countryId, $zoneId, $start, $end)
            ->where('orders.channel', 'quick')
            ->whereIn('orders.active_status', self::REVENUE_STATUSES)
            ->sum(DB::raw(self::ORDER_REVENUE_SQL));

        $ecom = (float) $this->items($countryId, $zoneId, $start, $end)
            ->where('o.channel', 'ecommerce')
            ->whereIn('oi.active_status', self::REVENUE_STATUSES)
            ->sum(DB::raw(self::ITEM_REVENUE_SQL));

        return ['quick' => round($quick, 2), 'ecommerce' => round($ecom, 2)];
    }

    /** Tax collected across confirmed items (tax always lives on the item, both channels). */
    private function tax(?int $countryId, ?int $zoneId, $start, $end): float
    {
        return (float) $this->items($countryId, $zoneId, $start, $end)
            ->whereRaw($this->confirmedItemsRaw())
            ->sum('oi.tax_amount');
    }

    /**
     * Sum a charge-list JSON column.
     * `additional_charges` = [{name, amount}], `surge_charges` = [{label, charge}] — so both
     * key names are accepted. Legacy rows can hold a bare string/null, hence the guards.
     */
    private static function sumChargeJson($json): float
    {
        $arr = is_array($json) ? $json : json_decode((string) $json, true);
        if (!is_array($arr)) {
            return 0.0;
        }
        $t = 0.0;
        foreach ($arr as $c) {
            if (is_array($c)) {
                $t += (float) ($c['charge'] ?? $c['amount'] ?? 0);
            }
        }
        return $t;
    }


    /** Rows carrying charge columns for confirmed quick orders + confirmed ecommerce items. */
    private function chargeRows(?int $countryId, ?int $zoneId, $start, $end): array
    {
        $quick = $this->ordersQuery($countryId, $zoneId, $start, $end)
            ->where('orders.channel', 'quick')
            ->whereIn('orders.active_status', self::REVENUE_STATUSES)
            ->get(['orders.delivery_charge', 'orders.surge_charges', 'orders.additional_charges']);

        $ecom = $this->items($countryId, $zoneId, $start, $end)
            ->where('o.channel', 'ecommerce')
            ->whereIn('oi.active_status', self::REVENUE_STATUSES)
            ->get(['oi.delivery_charge', 'oi.surge_charges', 'oi.additional_charges']);

        return [$quick, $ecom];
    }

    /**
     * Every charge figure in ONE pass over the charge columns:
     *   delivery_earned -> delivery_charge + surge (quick at order level, ecom per item)
     *   additional      -> packing / handling / platform fee …
     *
     * All of these are already baked into final_total, so they are reported for visibility
     * and never added on top of revenue.
     */
    private function chargeSummary(?int $countryId, ?int $zoneId, $start, $end): array
    {
        [$quick, $ecom] = $this->chargeRows($countryId, $zoneId, $start, $end);

        $deliveryCharge = 0.0;
        $surge = 0.0;
        $additional = 0.0;

        foreach ($quick->concat($ecom) as $r) {
            $deliveryCharge += (float) $r->delivery_charge;
            $surge          += self::sumChargeJson($r->surge_charges);
            $additional     += self::sumChargeJson($r->additional_charges);
        }

        return [
            'delivery_earned' => round($deliveryCharge + $surge, 2),
            'additional'      => round($additional, 2),
        ];
    }

    /**
     * Discount given away = promo discount actually applied.
     * Quick carries it on the order; ecommerce carries it per item.
     */
    private function discount(?int $countryId, ?int $zoneId, $start, $end): float
    {
        $quick = (float) $this->ordersQuery($countryId, $zoneId, $start, $end)
            ->where('orders.channel', 'quick')
            ->whereIn('orders.active_status', self::REVENUE_STATUSES)
            ->sum('orders.promo_discount');

        $ecom = (float) $this->items($countryId, $zoneId, $start, $end)
            ->where('o.channel', 'ecommerce')
            ->whereIn('oi.active_status', self::REVENUE_STATUSES)
            ->sum('oi.promo_discount');

        return $quick + $ecom;
    }

    /** Refunds paid out (cancelled/returned money) — item level for both channels. */
    private function refunds(?int $countryId, ?int $zoneId, $start, $end): float
    {
        return (float) $this->items($countryId, $zoneId, $start, $end)->sum('oi.refund_amount');
    }

    /**
     * Cost + profit over confirmed items.
     * NOTE: `oi.purchase_price` is a snapshot taken at order time. Rows created before cost
     * tracking existed carry 0, so their profit reads as the full sale value.
     */
    private function costAndProfit(?int $countryId, ?int $zoneId, $start, $end): array
    {
        $row = $this->items($countryId, $zoneId, $start, $end)
            ->whereRaw($this->confirmedItemsRaw())
            ->selectRaw('SUM(' . self::ITEM_COST_SQL . ') as cost')
            ->selectRaw('SUM(' . self::ITEM_PROFIT_SQL . ') as profit')
            ->selectRaw('SUM(' . self::ITEM_NET_SALES_SQL . ') as net_sales')
            ->first();

        $cost     = round((float) ($row->cost ?? 0), 2);
        $profit   = round((float) ($row->profit ?? 0), 2);
        $netSales = (float) ($row->net_sales ?? 0);

        return [
            'cost'      => $cost,
            'profit'    => $profit,
            'net_sales' => round($netSales, 2),
            // Margin is against NET sales (tax removed) — margin on a tax-inclusive figure
            // would be understated and is not a meaningful business number.
            'margin'    => $netSales > 0 ? round(($profit / $netSales) * 100, 1) : 0.0,
        ];
    }

    /** Units sold across confirmed items. */
    private function unitsSold(?int $countryId, ?int $zoneId, $start, $end): int
    {
        return (int) $this->items($countryId, $zoneId, $start, $end)
            ->whereRaw($this->confirmedItemsRaw())
            ->sum('oi.quantity');
    }

    /* ===================== misc ===================== */

    private function trend(float $cur, float $prev): array
    {
        if ($prev <= 0) {
            return ['pct' => $cur > 0 ? 100.0 : 0.0, 'up' => $cur > 0];
        }
        $pct = round((($cur - $prev) / $prev) * 100, 1);
        return ['pct' => abs($pct), 'up' => $pct >= 0];
    }

    /** Bucket format + step for a time series over the period. */
    private function seriesFormat(string $gran): array
    {
        return [
            ['hour' => '%Y-%m-%d %H:00', 'day' => '%Y-%m-%d', 'month' => '%Y-%m'][$gran],
            ['hour' => 'Y-m-d H:00', 'day' => 'Y-m-d', 'month' => 'Y-m'][$gran],
            ['hour' => 'addHour', 'day' => 'addDay', 'month' => 'addMonth'][$gran],
        ];
    }

    private function seriesLabel(Carbon $c, string $gran): string
    {
        return match ($gran) {
            'hour'  => $c->format('H:00'),
            'month' => $c->format('M y'),
            default => $c->format('d M'),
        };
    }
}
