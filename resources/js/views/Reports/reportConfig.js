/**
 * Column + chart definitions for every admin report.
 * ReportPage.vue is generic and renders whatever it finds here, so adding a report is
 * a backend endpoint + one entry below.
 *
 * col.format: text | money | number | percent | badge | product (image + name)
 */
export const REPORTS = {
    sales: {
        title: 'sales_report',
        subtitle: 'revenue_orders_and_mode_breakdown',
        icon: 'TrendingUp',
        chart: { type: 'area', from: 'series' },
        columns: [
            { key: 'period', label: 'period', format: 'text' },
            { key: 'orders', label: 'orders', format: 'number' },
            { key: 'revenue', label: 'revenue', format: 'money' },
            { key: 'aov', label: 'average_order_value', format: 'money' },
        ],
    },

    orders: {
        title: 'orders_report',
        subtitle: 'status_wise_order_analysis',
        icon: 'ShoppingCart',
        chart: { type: 'donut', labelKey: 'status', valueKey: 'orders' },
        columns: [
            { key: 'status', label: 'status', format: 'text' },
            { key: 'orders', label: 'orders', format: 'number' },
            { key: 'share', label: 'share', format: 'percent' },
        ],
    },

    products: {
        title: 'product_performance',
        subtitle: 'top_products_by_revenue_and_quantity',
        icon: 'Package',
        chart: { type: 'bar', labelKey: 'name', valueKey: 'revenue', limit: 10 },
        columns: [
            { key: 'name', label: 'product', format: 'product' },
            { key: 'units', label: 'units_sold', format: 'number' },
            { key: 'orders', label: 'orders', format: 'number' },
            { key: 'revenue', label: 'revenue', format: 'money' },
            { key: 'cost', label: 'cost', format: 'money' },
            { key: 'profit', label: 'profit', format: 'money' },
            { key: 'margin', label: 'margin', format: 'percent' },
        ],
    },

    customers: {
        title: 'customer_report',
        subtitle: 'top_customers_and_spending_patterns',
        icon: 'Users',
        chart: { type: 'bar', labelKey: 'name', valueKey: 'spent', limit: 10 },
        columns: [
            { key: 'name', label: 'customer', format: 'product' },
            { key: 'mobile', label: 'mobile', format: 'text' },
            { key: 'orders', label: 'orders', format: 'number' },
            { key: 'spent', label: 'total_spent', format: 'money' },
            { key: 'aov', label: 'average_order_value', format: 'money' },
            { key: 'last_order', label: 'last_order', format: 'text' },
        ],
    },

    inventory: {
        title: 'inventory_report',
        subtitle: 'stock_levels_and_low_stock_alerts',
        icon: 'Warehouse',
        pointInTime: true, // stock is "right now", the period filter does not apply
        columns: [
            { key: 'name', label: 'product', format: 'product' },
            { key: 'store', label: 'store', format: 'text' },
            { key: 'available', label: 'available', format: 'number' },
            { key: 'reserved', label: 'reserved', format: 'number' },
            { key: 'min_alert', label: 'min_alert', format: 'number' },
            {
                key: 'state', label: 'status', format: 'badge',
                map: { in_stock: 'success', low_stock: 'warning', out_of_stock: 'danger', unlimited: 'info' },
            },
            { key: 'unit_price', label: 'price', format: 'money' },
            { key: 'stock_value', label: 'stock_value', format: 'money' },
        ],
    },

    returns: {
        title: 'returns_refunds',
        subtitle: 'return_reasons_and_refund_overview',
        icon: 'RotateCcw',
        chart: { type: 'donut', from: 'reasons', labelKey: 'reason', valueKey: 'count' },
        columns: [
            { key: 'id', label: 'id', format: 'number' },
            { key: 'order_id', label: 'order_id', format: 'number' },
            { key: 'customer', label: 'customer', format: 'text' },
            { key: 'product', label: 'product', format: 'text' },
            { key: 'quantity', label: 'quantity', format: 'number' },
            { key: 'reason', label: 'reason', format: 'text' },
            { key: 'status', label: 'status', format: 'text' },
            { key: 'refund_amount', label: 'refund_amount', format: 'money' },
            { key: 'date', label: 'date', format: 'text' },
        ],
    },

    delivery: {
        title: 'delivery_boy_report',
        subtitle: 'delivery_boy_performance_stats',
        icon: 'Truck',
        chart: { type: 'bar', labelKey: 'name', valueKey: 'delivered', limit: 10 },
        columns: [
            { key: 'name', label: 'delivery_boy', format: 'text' },
            { key: 'mobile', label: 'mobile', format: 'text' },
            { key: 'assigned', label: 'assigned', format: 'number' },
            { key: 'delivered', label: 'delivered', format: 'number' },
            { key: 'success_rate', label: 'success_rate', format: 'percent' },
            { key: 'returns', label: 'returns', format: 'number' },
            { key: 'earnings', label: 'earnings', format: 'money' },
            { key: 'cash_collected', label: 'cash_collected', format: 'money' },
            { key: 'cash_in_hand', label: 'cash_in_hand', format: 'money' },
        ],
    },

    payment: {
        title: 'payment_report',
        subtitle: 'payment_methods_and_collection_status',
        icon: 'CreditCard',
        chart: { type: 'donut', labelKey: 'method', valueKey: 'orders' },
        columns: [
            { key: 'method', label: 'payment_method', format: 'text' },
            { key: 'orders', label: 'orders', format: 'number' },
            { key: 'revenue', label: 'revenue', format: 'money' },
            { key: 'share', label: 'share', format: 'percent' },
        ],
    },

    category: {
        title: 'category_report',
        subtitle: 'revenue_and_orders_by_product_category',
        icon: 'Layers',
        chart: { type: 'bar', labelKey: 'category', valueKey: 'revenue', limit: 10 },
        columns: [
            { key: 'category', label: 'category', format: 'text' },
            { key: 'orders', label: 'orders', format: 'number' },
            { key: 'units', label: 'units_sold', format: 'number' },
            { key: 'products', label: 'products', format: 'number' },
            { key: 'revenue', label: 'revenue', format: 'money' },
            { key: 'profit', label: 'profit', format: 'money' },
            { key: 'share', label: 'share', format: 'percent' },
        ],
    },

    promo: {
        title: 'promo_report',
        subtitle: 'coupon_usage_and_discount_totals',
        icon: 'Ticket',
        chart: { type: 'bar', labelKey: 'code', valueKey: 'total_benefit', limit: 10 },
        columns: [
            { key: 'code', label: 'promo_code', format: 'text' },
            { key: 'title', label: 'title', format: 'text' },
            {
                // instant = money off the bill now; wallet = cashback credited after delivery.
                key: 'apply_type', label: 'type', format: 'badge',
                map: { instant: 'info', wallet: 'warning' },
                text: { instant: 'instant_discount', wallet: 'cashback' },
            },
            { key: 'uses', label: 'redemptions', format: 'number' },
            { key: 'users', label: 'customers', format: 'number' },
            { key: 'discount', label: 'total_discount', format: 'money' },
            { key: 'cashback', label: 'cashback', format: 'money' },
            { key: 'cashback_credited', label: 'cashback_credited', format: 'money' },
            { key: 'cashback_pending', label: 'cashback_pending', format: 'money' },
            { key: 'total_benefit', label: 'total_benefit', format: 'money' },
            {
                key: 'active', label: 'status', format: 'badge',
                map: { true: 'success', false: 'secondary' },
                text: { true: 'active', false: 'inactive' },
            },
        ],
    },
};

export const PERIODS = [
    { key: 'today', label: 'today' },
    { key: 'last_7_days', label: 'last_7_days' },
    { key: 'this_month', label: 'this_month' },
    { key: 'last_quarter', label: 'last_quarter' },
    { key: 'all', label: 'all_time' },
];
