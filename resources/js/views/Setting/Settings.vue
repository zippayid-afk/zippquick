<template>
    <div class="list-page">
        <div class="page-head">
            <h3 class="page-head-title">{{ __('settings') }}</h3>
        </div>

        <!-- Grouped card grid: each card is one settings area. -->
        <template v-for="group in visibleGroups" :key="group.title">
            <div class="set-group-title">{{ group.title }}</div>
            <div class="card-grid set-grid">
                <router-link v-for="item in group.items" :key="item.title" :to="item.to" class="set-card">
                    <span class="set-card-icon" :class="'tone-' + item.tone">
                        <component :is="item.icon" :size="24" />
                    </span>
                    <div class="set-card-meta">
                        <div class="set-card-title">{{ item.title }}</div>
                        <div class="set-card-desc">{{ item.desc }}</div>
                    </div>
                    <div class="set-card-foot">{{ __('go_to_settings') }} <ArrowRight :size="15" /></div>
                </router-link>
            </div>
        </template>
    </div>
</template>

<script>
import { markRaw } from 'vue';
import {
    Store, LogIn, ShoppingCart, Smartphone, Link2, Mail, MessagesSquare,
    KeyRound, Globe, Share2, Search, FileText, Phone, Languages,
    Bell, BellRing, MessageSquareText, RefreshCw, ChevronRight, ArrowRight, Clock, ScrollText, Wrench, Stethoscope,
} from 'lucide-vue-next';

export default {
    name: 'Settings',
    components: { ChevronRight, ArrowRight },
    computed: {
        // Only render a group if at least one card survived the permission filter.
        visibleGroups() {
            return this.groups
                .map(g => ({ ...g, items: g.items.filter(i => !i.permission || this.$can(i.permission)) }))
                .filter(g => g.items.length);
        },
        groups() {
            return [
                {
                    title: __('general'),
                    items: [
                        {
                            title: __('general_settings'), desc: __('store_identity_address_and_branding'),
                            icon: markRaw(Store), tone: 'primary', permission: 'manage_general_settings',
                            to: '/settings/general',
                        },
                        {
                            title: __('login_setting'), desc: __('how_customers_sign_in_and_register'),
                            icon: markRaw(LogIn), tone: 'violet', permission: 'manage_login_settings',
                            to: '/settings/login',
                        },
                        {
                            title: __('cart_setting'), desc: __('cart_rules_and_reminder_notifications'),
                            icon: markRaw(ShoppingCart), tone: 'amber', permission: 'manage_cart_settings',
                            to: '/settings/cart',
                        },
                        {
                            // Lives at /languages, not /settings/languages — the route
                            // predates the hub and is linked to from elsewhere.
                            title: __('languages'), desc: __('panel_and_app_languages'),
                            icon: markRaw(Languages), tone: 'violet', permission: 'language_list',
                            to: '/languages',
                        },
                    ],
                },
                {
                    title: __('communication'),
                    items: [
                        {
                            title: __('notification_settings'), desc: __('notification_settings_desc'),
                            icon: markRaw(BellRing), tone: 'primary', permission: 'manage_notification_templates',
                            to: '/settings/notification_settings',
                        },
                        {
                            title: __('smtp_mail_setting'), desc: __('outgoing_mail_server_credentials'),
                            icon: markRaw(Mail), tone: 'primary', permission: 'manage_smtp_settings',
                            to: '/settings/smtp',
                        },
                        {
                            title: __('chat_setting'), desc: __('realtime_chat_broadcast_driver'),
                            icon: markRaw(MessagesSquare), tone: 'green', permission: 'manage_chat_settings',
                            to: '/settings/chat',
                        },
                        {
                            title: __('firebase_settings'), desc: __('push_notification_credentials'),
                            icon: markRaw(Bell), tone: 'amber', permission: 'manage_firebase_settings',
                            to: '/settings/firebase',
                        },
                        {
                            title: __('notification_templates'), desc: __('push_notification_message_templates'),
                            icon: markRaw(MessageSquareText), tone: 'violet', permission: 'manage_notification_templates',
                            to: '/settings/notification_templates',
                        },
                        {
                            title: __('sms_settings'), desc: __('sms_gateway_credentials'),
                            icon: markRaw(Phone), tone: 'cyan', permission: 'manage_sms_settings',
                            to: '/settings/sms',
                        },
                        {
                            title: __('sms_templates'), desc: __('sms_message_templates'),
                            icon: markRaw(MessageSquareText), tone: 'slate', permission: 'manage_sms_templates',
                            to: '/settings/sms_templates',
                        },
                        {
                            title: __('email_templates'), desc: __('email_message_templates'),
                            icon: markRaw(Mail), tone: 'teal', permission: 'manage_email_templates',
                            to: '/settings/email_templates',
                        },
                    ],
                },
                {
                    // Website and the mobile apps are one "customer-facing channels" group.
                    title: __('website_and_apps'),
                    items: [
                        {
                            title: __('website_settings'), desc: __('storefront_appearance_and_behaviour'),
                            icon: markRaw(Globe), tone: 'primary', permission: 'manage_website_settings',
                            to: '/settings/website',
                        },
                        {
                            title: __('app_setting'), desc: __('customer_and_delivery_app_options'),
                            icon: markRaw(Smartphone), tone: 'violet', permission: 'manage_app_settings',
                            to: '/settings/app',
                        },
                        {
                            title: __('deeplink_setting'), desc: __('app_store_urls_and_deeplink_schema'),
                            icon: markRaw(Link2), tone: 'slate', permission: 'manage_deeplink_settings',
                            to: '/settings/deeplink',
                        },
                        {
                            title: __('social_media'), desc: __('social_profile_links'),
                            icon: markRaw(Share2), tone: 'cyan', permission: 'manage_social_media',
                            to: '/settings/social_media',
                        },
                        {
                            title: __('seo_settings'), desc: __('page_titles_meta_and_keywords'),
                            icon: markRaw(Search), tone: 'green', permission: 'manage_seo_settings',
                            to: '/settings/seo',
                        },
                        {
                            title: __('about_us'), desc: __('about_us_page_content'),
                            icon: markRaw(FileText), tone: 'violet', permission: 'manage_about_us',
                            to: '/settings/about_us',
                        },
                        {
                            title: __('contact_us'), desc: __('contact_page_content'),
                            icon: markRaw(FileText), tone: 'amber', permission: 'manage_contact_us',
                            to: '/settings/contact_us',
                        },
                        {
                            title: __('maintenance_mode'), desc: __('maintenance_mode_desc'),
                            icon: markRaw(Wrench), tone: 'amber', permission: 'manage_app_settings',
                            to: '/settings/maintenance',
                        },
                    ],
                },
                {
                    title: __('advanced'),
                    items: [
                        {
                            title: __('third_party_api_credentials'), desc: __('maps_payments_and_other_api_keys'),
                            icon: markRaw(KeyRound), tone: 'slate', permission: 'manage_api_credentials',
                            to: '/settings/api',
                        },
                        {
                            title: __('activity_logs'), desc: __('audit_trail_of_changes_and_logins'),
                            icon: markRaw(ScrollText), tone: 'slate', permission: 'manage_activity_logs',
                            to: '/settings/activity_logs',
                        },
                        {
                            title: __('cron_jobs'), desc: __('scheduled_tasks_and_queue_setup'),
                            icon: markRaw(Clock), tone: 'amber', permission: 'manage_cron_jobs',
                            to: '/settings/cron_jobs',
                        },
                        {
                            title: __('system_updater'), desc: __('install_the_latest_version'),
                            icon: markRaw(RefreshCw), tone: 'primary', permission: 'manage_system_updater',
                            to: '/settings/system_updater',
                        },
                    ],
                },
                {
                    title: __('healthcare'),
                    items: [
                        {
                            title: __('doctor_management'), desc: __('manage_doctors_clinics_and_appointments'),
                            icon: markRaw(Stethoscope), tone: 'cyan',
                            to: '/doctors',
                        },
                    ],
                },
            ];
        },
    },
};
</script>

<style scoped>
.set-group-title {
    font-size: .72rem;
    font-weight: 700;
    letter-spacing: .06em;
    text-transform: uppercase;
    color: var(--app-muted);
    margin: 1.25rem 0 .6rem;
}
.set-group-title:first-of-type { margin-top: 0; }

/* Fixed column counts rather than auto-fill: the tiles are square, so the count
   per row has to be predictable at each breakpoint. */
.set-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.85rem;
}
@media (min-width: 768px) {
    .set-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
}
@media (min-width: 1200px) {
    .set-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); }
}

.set-card {
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: .5rem;
    aspect-ratio: 2 / 1;
    padding: 1rem;
    border: 1px solid var(--app-card-border);
    border-radius: 12px;
    background: var(--app-card-bg);
    text-decoration: none;
    transition: border-color .18s var(--app-ease), box-shadow .18s var(--app-ease), transform .18s var(--app-ease);
}
.set-card:hover {
    border-color: rgba(var(--bs-primary-rgb), .35);
    box-shadow: 0 6px 20px rgba(16, 24, 40, .1);
    transform: translateY(-2px);
}
.set-card-icon {
    width: 54px;
    height: 54px;
    flex-shrink: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 11px;
    background: rgba(var(--bs-primary-rgb), .1);
    color: var(--bs-primary);
}
.set-card-icon.tone-green { background: rgba(22, 163, 74, .12); color: #16a34a; }
.set-card-icon.tone-amber { background: rgba(245, 158, 11, .14); color: #d97706; }
.set-card-icon.tone-violet { background: rgba(124, 58, 237, .12); color: #7c3aed; }
.set-card-icon.tone-cyan { background: rgba(6, 182, 212, .12); color: #0891b2; }
.set-card-icon.tone-slate { background: rgba(100, 116, 139, .14); color: #64748b; }
.set-card-icon.tone-teal { background: rgba(13, 148, 136, .12); color: #0d9488; }

.set-card-meta { min-width: 0; width: 100%; margin-top: auto; }
.set-card-desc {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.set-card-title {
    font-size: .9rem;
    font-weight: 600;
    color: var(--app-ink);
    line-height: 1.3;
}
.set-card-desc {
    font-size: .75rem;
    color: var(--app-muted);
    line-height: 1.35;
    margin-top: 2px;
}
.set-card-foot {
    display: inline-flex;
    align-items: center;
    gap: .35rem;
    margin-top: .5rem;
    font-size: .8rem;
    font-weight: 600;
    color: var(--bs-primary);
}
.set-card-foot svg { transition: transform .15s var(--app-ease); }
.set-card:hover .set-card-foot svg { transform: translateX(3px); }
</style>
