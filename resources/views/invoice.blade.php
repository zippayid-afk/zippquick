@php
    $appName = \App\Models\Setting::get_value('app_name');
    if($appName == "" || $appName == null){ $appName = "SnapBuy"; }

    $supportEmail = \App\Models\Setting::get_value('support_email') ?? '';
    $supportNumber = \App\Models\Setting::get_value('support_number') ?? '';

    $logo = \App\Models\Setting::get_value('logo') ?? '';
    // Handle Cloudinary URLs properly - if logo is a full URL, use as-is; otherwise prepend storage path
    // Only set logo_full_path if logo exists
    $logo_full_path = '';
    if ($logo !== '' && $logo !== null) {
        if (preg_match('~^https?://~', $logo)) {
            $logo_full_path = $logo;  // Cloudinary URL - use as-is
        } else {
            $logo_full_path = url('/') . '/storage/' . $logo;  // Local storage path
        }
    }

    $currency = \App\Models\Setting::get_value('currency') ?? '$';

    // Brand / primary colour (admin theme), fallback to default.
    $primary = \App\Models\Setting::get_value('admin_theme_color');
    if(!preg_match('/^#[0-9a-fA-F]{6}$/', (string) $primary)){ $primary = '#435ebe'; }

    $money = fn($v) => $currency.' '.number_format((float) $v, 2);
@endphp
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Invoice #{{ $order->order_id }} - {{ $appName }}</title>
    <style>
        body { font-family: sans-serif; color:#2b3445; font-size:12px; background:#f4f6fb; margin:0; }
        .invoice { background:#fff; max-width:900px; margin:24px auto; border:1px solid #eceff4; border-radius:12px; padding:26px 30px; }
        .brand-name { font-size:20px; font-weight:bold; }
        .inv-label { font-size:11px; font-weight:bold; color:{{ $primary }}; text-transform:uppercase; letter-spacing:.5px; }
        .card { background:#f8fafc; border:1px solid #eef1f6; border-radius:6px; padding:10px 12px; }
        .card .nm { font-weight:bold; font-size:12.5px; }
        .card .sub { color:#5b6473; font-size:11.5px; line-height:1.5; }
        table.items { width:100%; border-collapse:collapse; font-size:11.5px; }
        table.items thead th { background:{{ $primary }}; color:#fff; padding:8px 6px; font-weight:bold; }
        table.items tbody td { padding:7px 6px; border-bottom:1px solid #eef1f6; }
        table.items tbody tr:nth-child(even) td { background:#fafbfd; }
        table.items tfoot td { padding:8px 6px; font-weight:bold; border-top:2px solid #e7ebf2; }
        table.totals { border-collapse:collapse; font-size:12px; min-width:240px; }
        table.totals td { padding:7px 10px; border-bottom:1px solid #eef1f6; }
        .ic { color:{{ $primary }}; }
        .grand-k { background:{{ $primary }}; color:#fff; font-weight:bold; padding:8px 10px; }
        .grand-v { background:{{ $primary }}; color:#fff; font-weight:bold; padding:8px 10px; text-align:right; }
        .pay-tag { color:{{ $primary }}; font-weight:bold; }
        .badge-st { display:inline-block; padding:2px 8px; border-radius:10px; font-size:10px; font-weight:bold; }
        .b-cancel { background:#fdecea; color:#c0392b; }
        .b-return { background:#fff4e5; color:#b9770e; }
        .banner { padding:9px 13px; border-radius:6px; font-weight:bold; margin-bottom:12px; font-size:13px; }
        .banner-cancel { background:#fdecea; color:#c0392b; border:1px solid #f5c6cb; }
        .sect-title { font-size:12px; font-weight:bold; color:#c0392b; margin:14px 0 6px; }
        .refund-row td { color:#c0392b; font-weight:bold; }
        table.ritems { width:100%; border-collapse:collapse; font-size:11.5px; }
        table.ritems thead th { background:#c0392b; color:#fff; padding:7px 6px; font-weight:bold; }
        table.ritems tbody td { padding:7px 6px; border-bottom:1px solid #f3d6d3; }
    </style>
</head>
<body>
    <section class="invoice" id="printMe">

        {{-- Header --}}
        <table width="100%" style="border-bottom:3px solid {{ $primary }}; padding-bottom:8px;">
            <tr>
                <td width="60%" style="vertical-align:middle;">
                    @if($logo_full_path)
                        <img src="{{ $logo_full_path }}" height="42" style="vertical-align:middle;">
                        &nbsp;
                    @endif
                    <span class="brand-name" style="vertical-align:middle; color:{{ $primary }};">{{ $appName }}</span>
                </td>
                <td width="40%" style="vertical-align:middle; text-align:right;">
                    <span style="font-size:22px; font-weight:bold; color:{{ $primary }};">INVOICE</span>
                </td>
            </tr>
        </table>
        <br>

        {{-- Parties --}}
        @php 
            $addr = \App\Helpers\CommonHelper::addressObject($order->address ?? null) ?? [];
            $companyGSTIN = $order->company_gstin ?? \App\Models\Setting::get_value('company_gstin') ?? '';
            $companyPAN = $order->company_pan ?? \App\Models\Setting::get_value('company_pan') ?? '';
            $companyState = $order->company_state ?? \App\Models\Setting::get_value('company_state') ?? 'Maharashtra';
            $customerState = $addr['state'] ?? 'Maharashtra';
            $isIntraState = $order->is_intra_state ?? (strtolower(trim($companyState)) === strtolower(trim($customerState)));
        @endphp
        <table width="100%" cellspacing="6">
            <tr>
                {{-- From + Sold By --}}
                <td width="32%" class="card" style="vertical-align:top;">
                    <div class="inv-label">From</div>
                    <div class="nm">{{ $appName }}</div>
                    <div class="sub">
                        @if($supportEmail)<span class="ic" style="font-weight:bold;">&#64;</span> {{ $supportEmail }}<br>@endif
                        @if($supportNumber)<span class="ic">&#9742;</span> {{ $supportNumber }}<br>@endif
                        @if($companyGSTIN)<b>GSTIN:</b> {{ $companyGSTIN }}<br>@endif
                        @if($companyPAN)<b>PAN:</b> {{ $companyPAN }}@endif
                    </div><br>
                    <div class="inv-label" style="margin-top:10px;">Sold By</div>
                    <div class="nm">{{ $order->store_name ?? $order->seller_name ?? '' }}</div>
                    <div class="sub">
                        @if(!empty($order->seller_email))<span class="ic" style="font-weight:bold;">&#64;</span> {{ $order->seller_email }}<br>@endif
                        @if(!empty($order->seller_mobile))<span class="ic">&#9742;</span> {{ $order->seller_mobile }}<br>@endif
                    </div>
                </td>
                <td width="2%"></td>
                {{-- Customer --}}
                <td width="32%" class="card" style="vertical-align:top;">
                    <div class="inv-label">Bill / Ship To</div>
                    <div class="nm" style="font-weight:bold;">{{ $order->user_name ?? '' }}</div>
                    <div class="sub">
                        {{ $addr['address'] ?? '' }}<br>
                        @php $custMobile = trim(($order->user_country_code ?? '').' '.($order->user_mobile ?? '')); @endphp
                        @if($custMobile)<span class="ic">&#9742;</span> {{ $custMobile }}<br>@endif
                        @if(!empty($order->user_email))<span class="ic" style="font-weight:bold;">&#64;</span> {{ $order->user_email }}@endif
                    </div>
                </td>
                <td width="2%"></td>
                {{-- Invoice meta --}}
                <td width="32%" class="card" style="vertical-align:top;">
                    <div class="inv-label">Invoice</div>
                    <div class="sub">
                        <b>Invoice No:</b> {{ $order->invoice_number ?? ('#' . $order->order_id) }}<br>
                        <b>Invoice Date:</b> {{ $order->orders_created_at_local ?? $order->orders_created_at }}
                    </div>
                </td>
            </tr>
        </table>
        <br>

        {{-- Split active vs cancelled/returned items (7=cancelled, 8=returned). --}}
        @php
            $allItems = collect($order_items);
            $activeItems = $allItems->filter(fn($i) => !in_array((int) $i->active_status, [7, 8]))->values();
            $refundedItems = $allItems->filter(fn($i) => in_array((int) $i->active_status, [7, 8]))->values();
            $refundedTotal = (float) $refundedItems->sum('refund_amount');
            $orderStatus = (int) ($order->active_status ?? 0);
            
            // Initialize GST totals - will be recalculated from items
            $totalCGST = 0;
            $totalSGST = 0;
            $totalIGST = 0;
            $totalBasePrice = 0;
            $totalGSTAmount = 0;
            $isIntraState = ($order->is_intra_state ?? true);
        @endphp

        @if($orderStatus === 7)
            <div class="banner banner-cancel">&#10006; This order has been cancelled. Refunded: {{ $money($order->refund_amount ?? $refundedTotal) }}</div>
        @elseif($orderStatus === 8)
            <div class="banner banner-cancel">&#8634; This order has been returned. Refunded: {{ $money($order->refund_amount ?? $refundedTotal) }}</div>
        @endif

        {{-- Active items --}}
        @php 
            $tt = 0; $tq = 0; $ts = 0; $totalBasePrice = 0; $accumulatedCGST = 0; $accumulatedSGST = 0; $accumulatedIGST = 0;
            
            // Pre-fetch HSN codes for all items to avoid N+1 queries
            $variantIds = $activeItems->pluck('product_variant_id')->filter()->unique();
            $hsnCodeMap = [];
            if ($variantIds->isNotEmpty()) {
                $variants = \App\Models\ProductVariant::whereIn('id', $variantIds)->with('product:id,hsn_code')->get();
                foreach ($variants as $v) {
                    if ($v->product && !empty($v->product->hsn_code)) {
                        // Use both integer and string keys to avoid type issues
                        $hsnCodeMap[(int)$v->id] = $v->product->hsn_code;
                        $hsnCodeMap[(string)$v->id] = $v->product->hsn_code;
                    }
                }
            }
        @endphp
        @if($activeItems->count())
        <table class="items">
            <thead>
                <tr>
                    <th align="center" width="4%">#</th>
                    <th align="left" width="28%">Product</th>
                    <th align="right" width="10%">Unit Price</th>
                    <th align="center" width="6%">Qty</th>
                    <th align="right" width="10%">Taxable Amt</th>
                    <th align="right" width="7%">GST%</th>
                    @if($isIntraState)
                        <th align="right" width="10%">CGST</th>
                        <th align="right" width="10%">SGST</th>
                    @else
                        <th align="right" colspan="2" width="20%">IGST</th>
                    @endif
                    <th align="right" width="15%">Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach($activeItems as $index => $item)
                    @php
                        // Since getOrderDetails adds tax_amount to price, we need to extract the base price
                        $storedPrice = ($item->discounted_price != 0 && $item->discounted_price != "") ? $item->discounted_price : $item->price;
                        $tax_amount = (float) ($item->tax_amount ?? 0);
                        
                        // Extract the base price (before GST was added by getOrderDetails)
                        $price = $storedPrice - $tax_amount;
                        
                        // Get HSN code from stored value or pre-fetched map
                        // Use intermediate variable to avoid null coalescing issues
                        $hsnCode = '';
                        if (!empty($item->hsn_code)) {
                            $hsnCode = $item->hsn_code;
                        } else {
                            $hsnCode = $hsnCodeMap[$item->product_variant_id] ?? '';
                        }
                        
                        // Use STORED GST data from OrderItem (historical accuracy)
                        // Don't refetch product, as it may have been edited after order
                        // IMPORTANT: Use tax_percentage (what was actually charged) NOT gst_rate (product's current rate)
                        $taxPercentage = (float) ($item->tax_percentage ?? $item->gst_rate ?? 0);
                        $gstInclusive = (bool) ($item->gst_inclusive ?? false);
                        
                        // For invoice display, use the STORED GST amounts from the database
                        // This ensures historical accuracy and avoids recalculation errors
                        $storedCGST = (float) ($item->cgst_amount ?? 0);
                        $storedSGST = (float) ($item->sgst_amount ?? 0);
                        $storedIGST = (float) ($item->igst_amount ?? 0);
                        
                        // Check if we have valid stored GST amounts
                        $hasValidStoredGST = ($storedCGST + $storedSGST + $storedIGST) > 0;
                        
                        if ($hasValidStoredGST && abs(($storedCGST + $storedSGST + $storedIGST) - $tax_amount) < 0.01) {
                            // Use stored GST amounts (they match expected tax_amount)
                            $itemTaxableAmount = $price * $item->quantity;
                            $itemTotalGST = $tax_amount * $item->quantity;
                            $itemTotal = $storedPrice * $item->quantity;
                            
                            if ($isIntraState) {
                                $cgst = $storedCGST * $item->quantity;
                                $sgst = $storedSGST * $item->quantity;
                                $igst = 0;
                            } else {
                                $cgst = 0;
                                $sgst = 0;
                                $igst = $storedIGST * $item->quantity;
                            }
                        } else {
                            // Fallback: Recalculate if stored amounts are invalid or don't match
                            /**
                             * GST Calculation Logic:
                             * 
                             * GST Inclusive (price includes GST):
                             *   taxable_value = price / (1 + rate/100)
                             *   gst_amount = price - taxable_value
                             *   item_total = price * quantity
                             * 
                             * GST Exclusive (price excludes GST):
                             *   taxable_value = price
                             *   gst_amount = price * rate / 100
                             *   item_total = (price + gst_amount) * quantity
                             */
                            
                            if ($gstInclusive) {
                                // Inclusive: extract taxable value from gross price
                                $taxablePerUnit = $taxPercentage > 0 ? $price / (1 + ($taxPercentage / 100)) : $price;
                                $gstPerUnit = $price - $taxablePerUnit;
                                $itemTaxableAmount = $taxablePerUnit * $item->quantity;
                                $itemTotalGST = $gstPerUnit * $item->quantity;
                                $itemTotal = $price * $item->quantity; // Gross price * qty
                            } else {
                                // Exclusive: add GST to base price
                                $taxablePerUnit = $price;
                                $gstPerUnit = ($price * $taxPercentage) / 100;
                                $itemTaxableAmount = $taxablePerUnit * $item->quantity;
                                $itemTotalGST = $gstPerUnit * $item->quantity;
                                $itemTotal = ($price + $gstPerUnit) * $item->quantity; // (base + GST) * qty
                            }
                            
                            // Split GST based on transaction type
                            if ($isIntraState) {
                                $cgst = round($itemTotalGST / 2, 2);
                                $sgst = $itemTotalGST - $cgst; // Ensure exact total
                                $igst = 0;
                            } else {
                                $cgst = 0;
                                $sgst = 0;
                                $igst = round($itemTotalGST, 2);
                            }
                        }
                        
                        // ACCUMULATE for invoice totals
                        $accumulatedCGST += $cgst;
                        $accumulatedSGST += $sgst;
                        $accumulatedIGST += $igst;
                        
                        $tt += $itemTotalGST; 
                        $tq += $item->quantity; 
                        $totalBasePrice += $itemTaxableAmount;
                        $ts += $itemTotal;
                    @endphp
                    <tr>
                        <td align="center">{{ $index+1 }}</td>
                        <td align="left">
                            {{ $item->product_name }}
                            @if(!empty($hsnCode))
                                <br><small style="color:#777; font-size:10px;"><b>HSN:</b> {{ $hsnCode }}</small>
                            @endif
                        </td>
                        <td align="right">{{ $money($price) }}</td>
                        <td align="center">{{ $item->quantity }}</td>
                        <td align="right">{{ $money($itemTaxableAmount) }}</td>
                        <td align="right">{{ number_format($taxPercentage, 1) }}%</td>
                        @if($isIntraState)
                            <td align="right">{{ $money($cgst) }}</td>
                            <td align="right">{{ $money($sgst) }}</td>
                        @else
                            <td align="right" colspan="2">{{ $money($igst) }}</td>
                        @endif
                        <td align="right">{{ $money($itemTotal) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="4" align="right"><b>Total</b></td>
                    <td align="right"><b>{{ $money($totalBasePrice) }}</b></td>
                    <td align="right"></td>
                    @if($isIntraState)
                        <td align="right"><b>{{ $money($accumulatedCGST) }}</b></td>
                        <td align="right"><b>{{ $money($accumulatedSGST) }}</b></td>
                    @else
                        <td align="right" colspan="2"><b>{{ $money($accumulatedIGST) }}</b></td>
                    @endif
                    <td align="right"><b>{{ $money($ts) }}</b></td>
                </tr>
            </tfoot>
        </table>
        @endif

        {{-- Cancelled / Returned items (refunded) --}}
        @if($refundedItems->count())
        @php
            // Pre-fetch HSN codes for refunded items
            $refundedVariantIds = $refundedItems->pluck('product_variant_id')->filter()->unique();
            $refundedHsnMap = [];
            if ($refundedVariantIds->isNotEmpty()) {
                $refundedVariants = \App\Models\ProductVariant::whereIn('id', $refundedVariantIds)->with('product:id,hsn_code')->get();
                foreach ($refundedVariants as $v) {
                    if ($v->product && !empty($v->product->hsn_code)) {
                        // Use both integer and string keys to avoid type issues
                        $refundedHsnMap[(int)$v->id] = $v->product->hsn_code;
                        $refundedHsnMap[(string)$v->id] = $v->product->hsn_code;
                    }
                }
            }
        @endphp
        <div class="sect-title">Cancelled / Returned Items</div>
        <table class="ritems">
            <thead>
                <tr>
                    <th align="center" width="5%">#</th>
                    <th align="left" width="47%">Product</th>
                    <th align="center" width="18%">Status</th>
                    <th align="center" width="8%">Qty</th>
                    <th align="right" width="22%">Refund</th>
                </tr>
            </thead>
            <tbody>
                @foreach($refundedItems as $index => $item)
                    @php
                        // Get HSN code from stored value or pre-fetched map
                        $refundedHsnCode = '';
                        if (!empty($item->hsn_code)) {
                            $refundedHsnCode = $item->hsn_code;
                        } else {
                            $refundedHsnCode = $refundedHsnMap[$item->product_variant_id] ?? '';
                        }
                    @endphp
                    <tr>
                        <td align="center">{{ $index+1 }}</td>
                        <td align="left">{{ $item->product_name }}@if(!empty($refundedHsnCode))<br><small style="color:#777;">HSN: {{ $refundedHsnCode }}</small>@endif</td>
                        <td align="center">
                            <span class="badge-st {{ (int)$item->active_status === 8 ? 'b-return' : 'b-cancel' }}">
                                {{ (int)$item->active_status === 8 ? 'Returned' : 'Cancelled' }}
                            </span>
                        </td>
                        <td align="center">{{ $item->quantity }}</td>
                        <td align="right">{{ $money($item->refund_amount ?? 0) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        @endif
        <br><br>

        {{-- Payment + totals --}}
        <table width="100%">
            <tr>
                <td width="50%" style="vertical-align:bottom;">
                    <p>Payment Method: <span class="pay-tag">{{ strtoupper($order->payment_method) }}</span></p>
                    @if($isIntraState)
                        <p style="font-size:11px; color:#666; margin-top:8px;">
                        </p>
                    @else
                        <p style="font-size:11px; color:#666; margin-top:8px;">
                            Company: {{ $companyState }} | Customer: {{ $customerState }}<br>
                        </p>
                    @endif
                    <br>
                    @if($logo_full_path)
                        <p><img src="{{ $logo_full_path }}" height="34"></p>
                    @endif
                    <p style="font-weight:bold;">Thank you for shopping with {{ $appName }}.</p>
                </td>
                <td width="50%" style="text-align:right; vertical-align:top;">
                    <table class="totals" align="right">
                        <tr><td align="left">Order Price</td><td align="right">{{ $money($totalBasePrice) }}</td></tr>
                        @php
                            // Calculate GST percentage from base price using RECALCULATED values
                            $totalRecalculatedGST = $accumulatedCGST + $accumulatedSGST + $accumulatedIGST;
                            $gstPercentage = $totalBasePrice > 0 ? ($totalRecalculatedGST / $totalBasePrice) * 100 : 0;
                        @endphp
                        @if($isIntraState && ($accumulatedCGST > 0 || $accumulatedSGST > 0))
                            <tr><td align="left">CGST ({{ number_format($gstPercentage/2, 1) }}%)</td><td align="right">{{ $money($accumulatedCGST) }}</td></tr>
                            <tr><td align="left">SGST ({{ number_format($gstPercentage/2, 1) }}%)</td><td align="right">{{ $money($accumulatedSGST) }}</td></tr>
                        @elseif(!$isIntraState && $accumulatedIGST > 0)
                            <tr><td align="left">IGST ({{ number_format($gstPercentage, 1) }}%)</td><td align="right">{{ $money($accumulatedIGST) }}</td></tr>
                        @endif
                        @if($accumulatedCGST > 0 || $accumulatedSGST > 0 || $accumulatedIGST > 0)
                            <tr><td align="left">Total GST</td><td align="right">{{ $money($totalRecalculatedGST) }}</td></tr>
                        @endif
                        <tr><td align="left">Delivery Charge</td><td align="right">{{ $money($order->delivery_charge) }}</td></tr>
                        @if(!empty($order->additional_charges) && is_array($order->additional_charges))
                            @foreach($order->additional_charges as $charge)
                                @if(floatval($charge['amount'] ?? 0) > 0)
                                    <tr><td align="left">{{ $charge['title'] ?? $charge['name'] ?? 'Additional Charge' }}</td><td align="right">{{ $money($charge['amount'] ?? 0) }}</td></tr>
                                @endif
                            @endforeach
                        @endif
                        @if(floatval($order->promo_discount) > 0)
                            <tr><td align="left">Promo @if($order->promo_code)({{ $order->promo_code }})@endif</td><td align="right">- {{ $money($order->promo_discount) }}</td></tr>
                        @endif
                        @if(floatval($order->wallet_balance) > 0)
                            <tr><td align="left">Wallet Used</td><td align="right">- {{ $money($order->wallet_balance) }}</td></tr>
                        @endif
                        <tr><td class="grand-k">Final Total</td><td class="grand-v">{{ $money($order->remaining_final) }}</td></tr>
                        @if($refundedTotal > 0)
                            <tr class="refund-row"><td align="left">Refunded</td><td align="right">{{ $money($refundedTotal) }}</td></tr>
                        @endif
                    </table>
                </td>
            </tr>
        </table>
    </section>
</body>
</html>
