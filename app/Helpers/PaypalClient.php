<?php

namespace App\Helpers;

use App\Models\Setting;

class PaypalClient
{
    private $base_url;
    function __construct()
    {
        Setting::get_value("paystack_secret_key")??'';
        $this->base_url = env('PAYPAL_BASE_URL');
        $this->access_token = $this->getAccessToken();
    }

    public function getAccessToken()
    {
        $clientId = env('PAYPAL_USERNAME');
        $secret = env('PAYPAL_PASSWORD');

        $url = $this->base_url.'oauth2/token';

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERPWD, $clientId . ":" . $secret);
        curl_setopt($ch, CURLOPT_POSTFIELDS, "grant_type=client_credentials");
        $server_output = curl_exec($ch);

        $result = json_decode($server_output);
        return $result->access_token;
    }
}
