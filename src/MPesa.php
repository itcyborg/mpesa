<?php


    namespace Stacks\Mpesa;

    use Carbon\Carbon;
    use Exception;
    use Illuminate\Support\Facades\Http;
    use Throwable;
    use function openssl_public_encrypt;

    /**
     * Class MPesa
     *
     * @package App\MPesa
     * @author  isaac
     */
    class MPesa
    {
        /**
         * @param        $phone
         * @param        $amount
         * @param        $reference
         * @param        $description
         * @param string $account
         *
         * @return mixed
         * @throws Exception
         * @author isaac
         */
        public static function STK($phone,$amount,$reference,$description,$account='default')
        {
            $token=json_decode(self::authenticate($account))->access_token;
            $account=Vault::get('paybills',$account)->data->data;
            $url = trim(env('SAFARICOM_BASE_ENDPOINT','https://api.safaricom.co.ke'),'/').'/mpesa/stkpush/v1/processrequest';
            $headers=[
                "Content-Type"=>'application/json',
                'Authorization'=>'Bearer '.$token
            ];
            $timestamp=Carbon::now()->format('Ymdhis');

            $command="CustomerPayBillOnline";
            $storenumber=$account->shortcode;
            if(isset($account->type)) {
                if ($account->type == 'Till Number') {
                    $command = "CustomerBuyGoodsOnline";
                    $storenumber = $account->storenumber;
                }
            }


            $password=self::generatePassword($storenumber,$account->passkey,$timestamp);
            $callback=env('SAFARICOM_STK_CALLBACK');

            $curl_post_data = array(
                //Fill in the request parameters with valid values
                'BusinessShortCode' =>$account->storenumber ?? $account->shortcode,
                'Password' => $password,
                'Timestamp' => $timestamp,
                'TransactionType' => $command,
                'Amount' => $amount,
                'PartyA' => $phone,
                'PartyB' => $account->shortcode,
                'PhoneNumber' => $phone,
                'CallBackURL' =>$callback,
                'AccountReference' => $reference,
                'TransactionDesc' => $description
            );
//            dd($curl_post_data);
            $response=json_decode(GuzzleClient::postJson($url,$headers,$curl_post_data));
            return $response;
        }

        /**
         * @param          $phone
         * @param          $amount
         * @param          $account
         * @param          $remarks
         * @param          $callbacks
         * @param int|null $user_id
         *
         * @return array
         * @author isaac
         */
        public static function B2C($phone, $amount, $account, $remarks, $callbacks, ?int $user_id)
        {
            try {
                $token = json_decode(self::authenticate($account))->access_token;
                $account = Vault::get('paybills', $account)->data->data;
                $url = env('SAFARICOM_B2C_ENDPOINT');

                $headers = [
                    "Content-Type" => 'application/json',
                    'Authorization' => 'Bearer ' . $token
                ];

                $security_credential=null;
                if(isset($account->security_credential) && $account->security_credential!==""){
                    $security_credential=$account->security_credential;
                }else{
                    $security_credential=self::encrypt($account->initiator,'mpesa_test');
                }

                $curl_post_data = array(
                    //Fill in the request parameters with valid values
                    'InitiatorName' => $account->initiator,
                    'SecurityCredential' => $security_credential,
                    'CommandID' => $account->command_id,
                    'Amount' => $amount,
                    'PartyA' => $account->paybill,
                    'PartyB' => $phone,
                    'Remarks' => $remarks,
                    'QueueTimeOutURL' => env('SAFARICOM_B2C_QUEUE_TIMEOUT'),
                    'ResultURL' => env('SAFARICOM_B2C_RESULT_URL'),
                    'Occasion' => 'test'
                );


                $response = GuzzleClient::postJson($url, $headers, $curl_post_data);
                $response = json_decode($response, false);

                $b2cRequest = B2CRequests::create([
                    'ConversationID' => $response->ConversationID,
                    'OriginatorConversationID' => $response->OriginatorConversationID,
                    'ResponseCode' => $response->ResponseCode,
                    'ResponseDescription' => $response->ResponseDescription,
                    'phone' => $phone,
                    'amount' => $amount,
                    'commandID' => $curl_post_data['CommandID'],
                    'paybill' => $curl_post_data['PartyA'],
                    'remarks' => $curl_post_data['Remarks'],
                    'occasion' => $curl_post_data['Occasion'],
                    'user_id' => $user_id
                ]);

                $responseData=[
                    'mpesa_response'=>$response,
                    'b2cResponse'=>$b2cRequest
                ];

                return $responseData;
            }catch (Throwable $e){
                dd($e);
            }
        }

        /**
         * @author isaac
         */
        public static function B2B()
        {

        }

        /**
         * @param $account
         *
         * @return string
         * @throws Exception
         * @author isaac
         */
        private static function authenticate($account)
        {
            $endpoint=env('SAFARICOM_BASE_ENDPOINT','https://api.safaricom.co.ke').'/oauth/v1/generate?grant_type=client_credentials';
            $credentials=Vault::get('paybills',$account);
            if($credentials){
                $credentials=$credentials->data->data;
            }
            try {
                $secret=base64_encode($credentials->consumer_key . ':' . $credentials->consumer_secret);
                $headers=[
                    'Authorization'=> 'Basic '.$secret
                ];
                $response=GuzzleClient::request('GET',$endpoint,$headers);
                return $response;
            }catch (Throwable $e){
                report($e);
                dd($e);
            }
        }

        /**
         * @param $shortcode
         * @param $passkey
         * @param $timestamp
         *
         * @return string
         * @author isaac
         */
        private static function generatePassword($shortcode,$passkey,$timestamp){
            return base64_encode($shortcode.$passkey.$timestamp);
        }

        /**
         * @param string $unencrypted
         * @param        $certificatePath
         * @param int    $padding
         * @param string $driver
         *
         * @return string
         * @throws Exception
         * @author isaac
         */
        private static function encrypt(string $unencrypted,$certificatePath,$padding=OPENSSL_PKCS1_PADDING,$driver='vault'){
          $encrypted=false;
          if($driver=='vault'){
            $key=Vault::get('certificates',$certificatePath)->data->data->cert;
            openssl_public_encrypt($unencrypted,$encrypted,$key,$padding);
            return base64_encode($encrypted);
          }
        }

        /**
         * @param        $checkoutRequestId
         * @param string $account
         *
         * @return mixed
         * @throws Exception
         * @author isaac
         */
        public static function validateSTK($checkoutRequestId,$account='default')
        {
            $token=json_decode(self::authenticate($account))->access_token;
            $account=Vault::get('paybills',$account)->data->data;
            $url = trim(env('SAFARICOM_BASE_ENDPOINT','https://api.safaricom.co.ke'),'/').'/mpesa/stkpushquery/v1/query';
            $headers=[
                "Content-Type"=>'application/json',
                'Authorization'=>'Bearer '.$token
            ];
            $timestamp=Carbon::now()->format('Ymdhis');
            $password=self::generatePassword($account->shortcode,$account->passkey,$timestamp);
            $callback=env('SAFARICOM_STK_CALLBACK');

            $curl_post_data = array(
                //Fill in the request parameters with valid values
                'Password' => $password,
                'Timestamp' => $timestamp,
                'CheckoutRequestID'=>$checkoutRequestId,
                'BusinessShortCode'=>$account->shortcode
            );
            $response=Http::withoutVerifying()->withHeaders($headers)->post($url,$curl_post_data)->json();
            return $response;
        }

        /**
         * @param string $account
         *
         * @return mixed
         * @throws Exception
         * @author isaac
         */
        public static function register($account='default')
        {
            $token=json_decode(self::authenticate($account))->access_token;
            $account=Vault::get('paybills',$account)->data->data;
            $url = trim(env('SAFARICOM_BASE_ENDPOINT','https://api.safaricom.co.ke'),'/').'/mpesa/c2b/v1/registerurl';
            $headers=[
                "Content-Type"=>'application/json',
                'Authorization'=>'Bearer '.$token
            ];

            $curl_post_data = array(
                //Fill in the request parameters with valid values
                "ConfirmationURL"=> config('app.mpesa_c2b_callback').'/'.$account->shortcode ?? $account->paybill,
	            "ValidationURL"=> env('SAFARICOM_STK_CALLBACK').'/validate',
                'ResponseType'=>'Completed',
                'ShortCode'=>$account->shortcode ?? $account->paybill
            );
            dd($curl_post_data);


            $response=json_decode(GuzzleClient::postJson($url,$headers,$curl_post_data));
            return $response;
        }

        /**
         * @param      $account
         * @param null $startDate
         * @param null $endDate
         *
         * @author isaac
         */
        public function pullTransactions($account, $startDate=null, $endDate=null)
        {
            // TODO Verify and validate with production urls/ paybill
            try{

                if($startDate==null){
                    $startDate=Carbon::now()->startOfDay();
                }
                if($endDate==null){
                    $startDate=Carbon::now()->endOfDay();
                }
                $token = json_decode(self::authenticate($account))->access_token;

                $account=Vault::get('paybills',$account)->data->data;
                $url = trim(env('SAFARICOM_BASE_ENDPOINT','https://sandbox.safaricom.co.ke'),'/').'/pulltransactions/v1/query';
                $headers=[
                    "Content-Type"=>'application/json',
                    'Authorization'=>'Bearer '.$token
                ];
                $payload=[
                    'ShortCode'=>$account->shortcode,
                    'StartDate'=>$startDate,
                    'EndDate'=>$endDate,
                    'OffSetValue'=>'0'
                ];
                $response=Http::withoutVerifying()->withHeaders($headers)->post($url,$payload);
            }catch (Throwable $e){
                report($e);
            }
        }

        /**
         * @param $account
         *
         * @author isaac
         */
        public function registerPullRequest($account)
        {
            // TODO Verify and validate with production urls/ paybill
            try{
                $token = json_decode(self::authenticate($account))->access_token;

                $account=Vault::get('paybills',$account)->data->data;
                $url = trim(env('SAFARICOM_BASE_ENDPOINT','https://sandbox.safaricom.co.ke'),'/').'/pulltransactions/v1/register';
                $headers=[
                    "Content-Type"=>'application/json',
                    'Authorization'=>'Bearer '.$token
                ];
                $payload=[
                    'ShortCode'=>$account->shortcode,
                    'RequestType'=>'Pull',
                    'NominatedNumber'=>$account->nominated,
                    'CallBackURL'=>env('APP_URL').'/api/payments/pulltransactions'
                ];
                $response=Http::withoutVerifying()->withHeaders($headers)->post($url,$payload);
            }catch (Throwable $e){
                report($e);
            }
        }

        /**
         * @throws Exception
         */
        public static function queryTransaction($transaction_id, $account='default')
        {
            $token=json_decode(self::authenticate($account))->access_token;
            $account=Vault::get('paybills',$account)->data->data;
            $url = trim(env('SAFARICOM_BASE_ENDPOINT','https://api.safaricom.co.ke'),'/').'/mpesa/stkpush/v1/processrequest';
            $headers=[
                "Content-Type"=>'application/json',
                'Authorization'=>'Bearer '.$token
            ];
            $timestamp=Carbon::now()->format('Ymdhis');

            $securityCredential=self::encrypt();
            $callback=env('SAFARICOM_STK_CALLBACK');

            $curl_post_data = array(
                //Fill in the request parameters with valid values
                'CommandID' =>'TransactionStatusQuery',
                'PartyA' => $account->shortcode,
                'IdentifierType' => 'MSISDN',
                'Remarks' => 'Check transaction',
                'Initiator' => $account->initiator,
                'SecurityCredential' => $securityCredential,
                'QueueTimeOutURL' => env('APP_URL'),
                'ResultURL' => env('APP_URL'),
                'TransactionID' =>$transaction_id,
                'Occasion' => 'Check transaction',
            );

            $response=json_decode(GuzzleClient::postJson($url,$headers,$curl_post_data));
            return $response;
        }
    }
