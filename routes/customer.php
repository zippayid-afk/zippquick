<?php

use App\Http\Controllers\API\BlogsApiController;
use App\Http\Controllers\API\CountryApiController;
use App\Http\Controllers\API\Customer\AddressApiController;
use App\Http\Controllers\API\Customer\BasicApiController;
use App\Http\Controllers\API\Customer\CartApiController;
use App\Http\Controllers\API\Customer\ChatApiController as CustomerChatApiController;
use App\Http\Controllers\API\Customer\CustomerAuthController;
use App\Http\Controllers\API\Customer\OrderApiController;
use App\Http\Controllers\WebhookController;
use App\Http\Controllers\API\Customer\ProductsApiController;
use App\Http\Controllers\API\Customer\SettingApiController;
use App\Http\Controllers\API\Customer\HomeLayoutApiController as CustomerHomeLayoutApiController;
use App\Http\Controllers\API\LanguageApiController;
use App\Http\Controllers\API\OrdersApiController;
use App\Http\Controllers\API\GeneralSettingsApiController;
use App\Http\Controllers\API\NotificationPreferencesApiController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

// Map / Places routes (public). Provider-agnostic — pick google vs OSM
Route::get('/places_autocomplete', [GeneralSettingsApiController::class, 'placesAutocomplete']);
Route::get('/places_details', [GeneralSettingsApiController::class, 'placesDetails']);
Route::get('/maps_geocoding', [GeneralSettingsApiController::class, 'mapsGeocoding']);

// Public auth routes (no authentication required)
Route::post('send_sms', [CustomerAuthController::class, 'sendSms']);
Route::post('verify_user', [CustomerAuthController::class, 'verifyContact']);
Route::post('verify_otp_login', [CustomerAuthController::class, 'verifyOtpLogin']);
Route::post('register_with_phone_otp', [CustomerAuthController::class, 'registerWithPhoneOtp']);
Route::post('register', [CustomerAuthController::class, 'register']);
Route::post('verify_email', [CustomerAuthController::class, 'verifyEmail']);
Route::post('verify_user_exist', [CustomerAuthController::class, 'verifyUserExist']);
Route::post('login', [CustomerAuthController::class, 'login']);
Route::get('login', [CustomerAuthController::class, 'notLogin'])->name('login');
Route::post('add_fcm_token', [CustomerAuthController::class, 'addFcmToken']);
Route::post('send_email_forgot_password_otp', [CustomerAuthController::class, 'forgetPasswordOtp']);
Route::post('forgot_password', [CustomerAuthController::class, 'forgotPassword']);

Route::group(['middleware' => ['auth.customer']], function () {

    // Guest
    Route::get('categories', [BasicApiController::class, 'getCategories']);
    Route::get('categories/get_seo', [BasicApiController::class, 'getSeoThings']);
    Route::get('home_layout', [CustomerHomeLayoutApiController::class, 'getHomeLayout']);
    Route::get('brands', [BasicApiController::class, 'getBrands']);
    Route::get('countries', [CountryApiController::class, 'active']);
    Route::get('cart/guest_cart', [CartApiController::class, 'getGuestCart']);

    Route::group(['prefix' => 'products'], function () {
        Route::post('/', [ProductsApiController::class, 'getProducts']);
        Route::post('filters', [ProductsApiController::class, 'getProductFilters']);
        Route::get('ratings_list', [ProductsApiController::class, 'productRatingsList']);
        Route::post('rating/image_list', [ProductsApiController::class, 'productRatingImageList']);
        Route::get('get_seo', [ProductsApiController::class, 'getSeoThings']);
    });
    Route::post('product_by_id', [ProductsApiController::class, 'getProduct']);
    Route::get('cart/recommendations', [CartApiController::class, 'getCartRecommendations']);
    Route::get('faqs', [BasicApiController::class, 'getFaqs']);
    Route::get('social_media', [BasicApiController::class, 'getSocialMedia']);

    // Settings
    Route::group(['prefix' => 'settings'], function () {
        Route::get('/', [SettingApiController::class, 'getSettings']);
        Route::get('payment_methods', [SettingApiController::class, 'getPaymentMethods']);
        Route::get('get_seo_settings', [SettingApiController::class, 'getSeoSettings']);
        Route::get('sms', [SettingApiController::class, 'getSmsSettings']);
    });
    
    Route::get('country_setting', [SettingApiController::class, 'getCountrySetting']);
    Route::get('blogs', [BasicApiController::class, 'getBlogs']);
    Route::get('blog_categories', [BasicApiController::class, 'getBlogCategories']);
    Route::post('blog_view_count', [BlogsApiController::class, 'trackBlogView']);
    Route::get('blogs/most_viewed', [BlogsApiController::class, 'getMostViewedBlogs']);
    Route::get('blog_popular_tags', [BlogsApiController::class, 'getPopularBlogTags']);

    //Languages
    Route::get('system_languages', [LanguageApiController::class, 'getSystemLanguages']);

    // zone deliverable
    Route::get('zone', [BasicApiController::class, 'getZone']);
    Route::get('zones', [BasicApiController::class, 'getZones']);

    Route::get('notifications', [BasicApiController::class, 'getNotifications']);

    /***********************************************************************************************/
    // API After Login here
    Route::group(['middleware' => ['auth:api-customers', 'customer.active']], function () {

        // Token-authenticated broadcasting auth (Echo private channels for customers).
        Route::post('broadcasting/auth', fn(Request $request) => Broadcast::auth($request));

        // User
        Route::post('logout', [CustomerAuthController::class, 'logout']);
        Route::post('delete_account', [CustomerAuthController::class, 'deleteAccount']);
        Route::post('edit_profile', [CustomerAuthController::class, 'editProfile']);
        Route::post('reset_password', [CustomerAuthController::class, 'ResetPassword']);
        Route::post('update_fcm_token', [CustomerAuthController::class, 'updateFcmToken']);
        Route::get('user_details', [CustomerAuthController::class, 'getLoginUserDetails']);
        Route::get('notification_preferences', [NotificationPreferencesApiController::class, 'customerIndex']);
        Route::post('notification_preferences', [NotificationPreferencesApiController::class, 'customerSave']);

        // Transactions
        Route::get('get_user_transactions', [BasicApiController::class, 'getUserTransactions']);

        // Address
        Route::group(['prefix' => 'address'], function () {
            Route::get('/', [AddressApiController::class, 'getAddress']);
            Route::post('add', [AddressApiController::class, 'save']);
            Route::post('update', [AddressApiController::class, 'update']);
            Route::post('delete', [AddressApiController::class, 'delete']);
        });

        // Favorites
        Route::group(['prefix' => 'favorites'], function () {
            Route::get('/', [BasicApiController::class, 'getFavorites']);
            Route::post('add', [BasicApiController::class, 'addToFavorite']);
            Route::post('remove', [BasicApiController::class, 'removeFromFavorite']);
        });

        // Carts
        Route::group(['prefix' => 'cart'], function () {
            Route::get('/', [CartApiController::class, 'getUserCart']);
            Route::post('add', [CartApiController::class, 'addToCart']);
            Route::post('remove', [CartApiController::class, 'removeFromCart']);
            Route::post('bulk_add_to_cart_items', [CartApiController::class, 'BulkAddToCartItems']);
        });

        // Promo Code
        Route::group(['prefix' => 'promo_code'], function () {
            Route::get('/', [BasicApiController::class, 'getPromoCode']);
            Route::post('validate', [BasicApiController::class, 'validatePromoCode']);
        });

        // Chat
        Route::group(['prefix' => 'chat'], function () {
            Route::post('start_admin', [CustomerChatApiController::class, 'startAdmin']);
            Route::post('start_order_delivery_boy', [CustomerChatApiController::class, 'startOrderDeliveryBoy']);
            Route::post('start_order_admin', [CustomerChatApiController::class, 'startOrderAdmin']);
            Route::get('messages', [CustomerChatApiController::class, 'messages']);
            Route::post('mark_read', [CustomerChatApiController::class, 'markRead']);
            Route::post('send', [CustomerChatApiController::class, 'send'])->name('customer.chat.send');
        });

        Route::group(['prefix' => 'products'], function () {
            Route::post('rating/add', [ProductsApiController::class, 'productRatingSave']);
            Route::post('rating/edit', [ProductsApiController::class, 'productRatingEdit']);
            Route::post('rating/update', [ProductsApiController::class, 'productRatingUpdate']);
            Route::get('recently_visited', [ProductsApiController::class, 'getRecentlyVisitedProducts']);
            Route::post('add_recently_visited_product', [ProductsApiController::class, 'addRecentlyVisitedProduct']);
        });

        // order
        Route::get('orders', [OrderApiController::class, 'getOrders']); //Quick orders
        Route::get('ecom_orders', [OrderApiController::class, 'getEcomOrders']); //Ecommerce orders
        Route::get('order_status_lists', [BasicApiController::class, 'getOrderStatusLists']);
        Route::get('live_tracking', [OrderApiController::class, 'getLiveTrackingDetails']);

        //Checkout
        Route::post('place_order', [OrderApiController::class, 'placeOrder']);
        Route::post('initiate_transaction', [OrderApiController::class, 'initiateTransaction']);
        Route::post('add_transaction', [OrderApiController::class, 'addTransaction']);
        Route::post('update_order_status', [OrderApiController::class, 'updateOrderStatus']);
        Route::post('delete_order', [OrderApiController::class, 'deletePaymentPendingOrder']);

        //Phonepe
        Route::get('order_status_phonepe', [WebhookController::class, 'getOrderStatusPhonepe']);

        // Invoice endpoints
        Route::post('invoice_download', [OrderApiController::class, 'downloadOrderInvoice']);
        Route::post('item_invoice_download', [OrdersApiController::class, 'downloadOrderItemInvoice']);
    });

    //Paypal
    Route::get('paypal_payment_url', [OrderApiController::class, 'paypalPaymentUrl']);
    Route::match(array('GET', 'POST'), 'paypal_redirect/success', [OrderApiController::class, 'paypalRedirect']);
    Route::match(array('GET', 'POST'), 'paypal_redirect/fail', [OrderApiController::class, 'paypalRedirect']);
    Route::match(array('GET', 'POST'), 'paypal_redirect/pending', [OrderApiController::class, 'paypalRedirect']);
});

// Invoice endpoints - authenticated but separate from other authed routes
Route::middleware(['auth:api-customers', 'customer.active'])->group(function () {
    Route::post('invoice_download', [OrderApiController::class, 'downloadOrderInvoice']);
    Route::post('item_invoice_download', [OrdersApiController::class, 'downloadOrderItemInvoice']);
});
