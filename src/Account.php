<?php

namespace Stacks\Mpesa;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class Account
{
    protected $key;
    protected $secret;
    protected $store_number;
    protected $shortcode;
    protected $passkey;
    protected $security_credential;
    protected $password;
    private $token;
    private $expires_at;

    public function __construct($key, $secret, $store_number=null, $shortcode=null, $passkey=null,$security_credential=null)
    {
        $this->key = 'Rz5HAtlZJ7bzgKXaFMaQXuMPS5Zbkom5';
        $this->secret = 'ahe83yrPXeTP4Z8H';
        $this->store_number = $store_number;
        $this->shortcode = $shortcode;
        $this->passkey = $passkey;
        $this->password = base64_encode($this->shortcode.$this->passkey.now()->format('YmdHis'));
        $this->security_credential = $security_credential;
        $this->authenticate();
    }

    private function authenticate()
    {
        if(!Cache::has('mpesa_auth')) {
            $response = Http::withHeaders(['Authorization' => 'Basic ' . base64_encode($this->key . ':' . $this->secret)])
                ->get('https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials')->object();
            $this->token = $response->access_token;
            $this->expires_at = now()->addSeconds($response->expires_in);
            Cache::put('mpesa_auth', $this->token, $this->expires_at);
        }else{
            $this->token = Cache::get('mpesa_auth');
        }
    }

    public function register($confirm,$validate,$defaultCommand='Completed')
    {
        $response = Http::withHeaders(['Authorization' => 'Bearer ' . $this->token])
            ->post('https://sandbox.safaricom.co.ke/mpesa/c2b/v1/registerurl', [
                'ShortCode' => $this->shortcode,
                'ResponseType' => $defaultCommand,
                'ConfirmationURL' => $confirm,
                'ValidationURL' => $validate
            ])->object();
        return $response;
    }

    public function stk($phoneNumber,$amount,$reference,$description)
    {
        $response = Http::withHeaders(['Authorization' => 'Bearer ' . $this->token])
            ->post('https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest', [
                'BusinessShortCode' => $this->shortcode,
                'Password' => $this->password,
                'Timestamp' => now()->format('YmdHis'),
                'TransactionType' => 'CustomerPayBillOnline',
                'Amount' => $amount,
                'PartyA' => $phoneNumber,
                'PartyB' => $this->shortcode,
                'PhoneNumber' => $phoneNumber,
                'CallBackURL' => 'https://stacks.dev/api/mpesa/callback',
                'AccountReference' => $reference,
                'TransactionDesc' => $description
            ])->object();
        return $response;
    }

    public function validateStk($checkoutRequestId)
    {
        $response = Http::withHeaders(['Authorization' => 'Bearer ' . $this->token])
            ->post('https://sandbox.safaricom.co.ke/mpesa/stkpushquery/v1/query', [
                'BusinessShortCode' => $this->shortcode,
                'Password' => $this->password,
                'Timestamp' => now()->format('YmdHis'),
                'CheckoutRequestID' => $checkoutRequestId
            ])->object();
        return $response;
    }
}
