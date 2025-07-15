<?php

namespace App\Traits\Gateways;

use App\Models\AffiliateHistory;
use App\Models\Deposit;
use App\Models\GamesKey;
use App\Models\Gateway;
use App\Models\Setting;
use App\Models\iBPayPayment;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Notifications\NewDepositNotification;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use App\Helpers\Core as Helper;


trait MXVPayTrait

{
    /**
     * @var $MXVUri
     * @var $MXVToken
     * @var $MXVSecret
     */

    public static string  $MXVUri;
    public static string  $MXVToken;
    public static string  $MXVSecret;

    /**
     * Generate Credentials
     * Metodo para gerar credenciais
     * @dev mscodex 
     * @return void
     */
    private static function generateTokensMXV()
    {
        $setting = Gateway::first();
        if (!empty($setting)) {
            // Obtém os valores diretamente do registro do banco de dados
            self::$MXVUri = $setting->url_mxv; // Define um valor padrão caso 'uri' seja nulo
            self::$MXVToken = $setting->token_mxv; // Valor padrão para o token
            self::$MXVSecret = $setting->secret_mxv; // Valor padrão para o segredo
        }
    }

    /**
     * Request QRCODE
     * Metodo para solicitar uma QRCODE PIX
     * @dev mscodex 
     * @return array
     */
    public static function requestQrcodeMXV($request)
    {
        try {
            
            $setting = \Helper::getSetting();
    
            if (\Helper::amountPrepare($request->amount) < max(5, $setting->min_deposit)) {
                return [
                    'status' => false,
                ];
            }

            if ($request->cpf == ''){
                return [
                    'status' => false,
                    'message' => 'CPF do usuário é inválido.'
                ];
            }
    
            self::generateTokensMXV();
    
            $response = Http::withHeaders([])->post(self::$MXVUri . '/api/qrcode-pix', [
                "token" => self::$MXVToken,
                "secret" => self::$MXVSecret,
                "amount" => \Helper::amountPrepare($request->amount),
                "cpf" => \Helper::soNumero($request->cpf),
                "paymentType" => $request->paymentType,
                "url_callback" => url('/') . '/mxv/callback'
                
                // ,"splits" => [
                //     [
                //         "email" => "split@gmail.com",
                //         "split" => "10"
                //     ],
                //     [
                //         "email" => "split1@gmail.com",
                //         "split" => "20"
                //     ]
                // ]
            ]);
    

            if ($response->successful()) {
                $responseData = $response->json();
                
                // \Log::info('Resposta da API MXV:', $responseData);

                if ($responseData['status'] == false){
                    return [
                        'status' => false,
                        'message' => 'Ocorreu um erro inesperado. Por favor, tente novamente mais tarde.'
                    ];
                }
                
                $transaction = self::generateTransaction(
                    $responseData['idTransaction'], 
                    \Helper::amountPrepare($request->amount), 
                    $request->accept_bonus
                );
                self::generateDeposit($responseData['idTransaction'], \Helper::amountPrepare($request->amount));
    
                return [
                    'status' => true,
                    'idTransaction' => $transaction->id,
                    'qrcode' => $responseData['qrcode']
                ];
            }
    
            // Log de falha no sucesso da resposta
            \Log::warning('Resposta da API MXV não foi bem-sucedida.', [
                'status' => $response->status(),
                'message' => $response->body(),
            ]);
    
            return [
                'status' => false,
            ];
        } catch (\Exception $e) {
            // Log de exceção
            \Log::error('Erro durante a solicitação para a API MXV:', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
    
            return [
                'status' => false,
                'message' => 'Ocorreu um erro inesperado. Por favor, tente novamente mais tarde.'
            ];
        }
    }
    

    /**
     * Consult Status Transaction
     * Consultar o status da transação
     * @dev mscodex 
     *
     * @param $request
     * @return \Illuminate\Http\JsonResponse
     */
    public static function consultStatusTransactionMXV($request)
    {

        $transaction = Transaction::where('id', $request->idTransaction)->first();

        if($transaction->status == 1){
            return response()->json(['status' => 'PAID']);
        }else{
            return response()->json(['status' => 'NO_PAID'], 400);
       }
    }

    /**
     * @param $idTransaction
     * @dev mscodex 
     * @return bool
     */

    public static function finalizePaymentMXV($idTransaction) : bool
    {

        $transaction = Transaction::where('payment_id', $idTransaction)->where('status', 0)->first();
        $setting = \Helper::getSetting();

        if(!empty($transaction)) {
            $user = User::find($transaction->user_id);
            $wallet = Wallet::where('user_id', $transaction->user_id)->first();
            if(!empty($wallet)) {

                $checkTransactions = Transaction::where('user_id', $transaction->user_id)
                    ->where('status', 1)
                    ->count();


                if ($checkTransactions == 0 || empty($checkTransactions)) {
                    if ($transaction->accept_bonus) {
                        $bonus = Helper::porcentagem_xn($setting->initial_bonus, $transaction->price);
                        $wallet->update(['balance_bonus' => 0]);
                        $wallet->increment('balance_bonus', $bonus);


                        if (!$setting->disable_rollover) {
                            $wallet->update(['balance_bonus_rollover' => 0]);
                            $wallet->update(['balance_bonus_rollover' => $bonus * $setting->rollover]);
                        }
                    }
                }

                if(!$setting->disable_rollover) {
                    $wallet->update(['balance_deposit_rollover' => 0]);
                    $wallet->increment('balance_deposit_rollover', ($transaction->price * intval($setting->rollover_deposit)));

                }

                Helper::payBonusVip($wallet, $transaction->price);

                
                if($setting->disable_rollover) {
                    $wallet->increment('balance_withdrawal', $transaction->price);

                } else {
                    $wallet->increment('balance', $transaction->price);
                }

                if($transaction->update(['status' => 1])) {

                    $deposit = Deposit::where('payment_id', $idTransaction)->where('status', 0)->first();
                    if(!empty($deposit)) {

                        $affHistoryCPA = AffiliateHistory::where('user_id', $user->id)
                            ->where('commission_type', 'cpa')
                            ->first();

                        if(!empty($affHistoryCPA)) {
                            $affHistoryCPA->increment('deposited_amount', $transaction->price);

                            $sponsorCpa = User::find($user->inviter);

                            if(!empty($sponsorCpa) && $affHistoryCPA->status == 'pendente') {
                                if($affHistoryCPA->deposited_amount >= $sponsorCpa->affiliate_baseline || $deposit->amount >= $sponsorCpa->affiliate_baseline) {
                                    $walletCpa = Wallet::where('user_id', $affHistoryCPA->inviter)->first();
                                    if(!empty($walletCpa)) {
                                        $walletCpa->increment('refer_rewards', $sponsorCpa->affiliate_cpa);
                                        $affHistoryCPA->update(['status' => 1, 'commission_paid' => $sponsorCpa->affiliate_cpa]);
                                    }
                                }
                            }
                        }

                        if($deposit->update(['status' => 1])) {
                            return true;
                        }
                        \Log::error('Falha ao atualizar o status do depósito');
                        return false;
                    }
                    \Log::error('Depósito não encontrado');
                    return false;
                }

                \Log::error('Falha ao atualizar o status da transação');
                return false;
            }
            \Log::error('Carteira do usuário não encontrada');
            return false;
        }

        \Log::error('Transação não encontrada');
        return false;
    }


    /**
     * @param $idTransaction
     * @param $amount
     * @dev mscodex 
     * @return void
     */
    private static function generateDepositMXV($idTransaction, $amount)
    {
        $userId = auth('api')->user()->id;
        $wallet = Wallet::where('user_id', $userId)->first();

        Deposit::create([
            'payment_id'=> $idTransaction,
            'user_id'   => $userId,
            'amount'    => $amount,
            'type'      => 'pix',
            'currency'  => $wallet->currency,
            'symbol'    => $wallet->symbol,
            'status'    => 0
        ]);
    }

    /**
     * @param $idTransaction
     * @param $amount
     * @dev mscodex 
     * @return void
     */
    private static function generateTransactionMXV($idTransaction, $amount, $accept_bonus): Transaction
    {
        $setting = \Helper::getSetting();

        return Transaction::create([
            'payment_id' => $idTransaction,
            'user_id' => auth('api')->user()->id,
            'payment_method' => 'pix',
            'price' => $amount,
            'currency' => $setting->currency_code,
            'accept_bonus' => $accept_bonus,
            'status' => 0
        ]);
    }

    /**
     * @param $request
     * @dev mscodex 
     * @return \Illuminate\Http\JsonResponse|void
     */
    public static function pixCashOutMXV(array $array): bool
    {
        \Log::info('Dados recebidos no método pixCashOutMXV:', $array);


        $user = User::where('id', $array['user_id'])->first();
        
        if ($user) {
        } else {
            return false;
        }

        self::generateTokensMXV();

        $response = Http::withHeaders([])->post(self::$MXVUri.'/api/withdrawl-balance', [
            "token" => self::$MXVToken,
            "secret" => self::$MXVSecret,
            "cpf" => $user->cpf,
            "url" => url('/'),
            "key" => $array['pix_key'],
            "typeKey" => $array['pix_type'],
            "amount" => $array['amount']
        ]);

        if($response->successful()) {
            $responseData = $response->json();

            if($responseData['status']) {
                $iBPayPayment = iBPayPayment::lockForUpdate()->find($array['ibpayment_id']);
                if(!empty($iBPayPayment)) {
                    if($iBPayPayment->update(['status' => 1])) {
                        return true;
                    }
                    return false;
                }
                return false;
            }
            return false;
        }
        return false;
    }
}
