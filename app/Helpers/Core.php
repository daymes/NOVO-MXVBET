<?php


namespace App\Helpers;

use App\Helpers\Core as Helper;
use App\Models\AffiliateHistory;
use App\Models\CustomLayout;
use App\Models\SpinConfigs;
use App\Models\Order;
use App\Models\Setting;
use App\Models\SubAffiliate;
use App\Models\User;
use App\Models\Vip;
use App\Models\VipUser;
use App\Models\Wallet;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Models\Deposit;
use NumberFormatter;
use Illuminate\Support\Facades\Http;

class Core
{
  	public static function GetDefaultGateway(){
        $setting = Setting::first();
        return $setting->default_gateway;
    }
    /**
     * Validate Hash
     * # 1.5.0
     * @return bool
     */
    public static function ValidateHash($hash, $params)
    {
        $generateHash = self::GenerateHash($params);
        if($hash == $generateHash) {
            return true;
        }

        return false;
    }

    /**
     * Metodo responsavel por gerar o hash
     * @param $paramsValue
     * @param $key
     * # 1.5.0
     * @return string
     */
    public static function GenerateHash($paramsValue) {
        return hash('sha256', $paramsValue . env('PRIVATE_KEY'));
    }

    /**
     * @param Wallet $wallet
     * @param $bet
     * @return string
     */
    public static function DiscountBalance(Wallet $wallet, $bet)
    {
        if ($wallet->balance_bonus >= $bet) {
            $wallet->decrement('balance_bonus', $bet);
            $changeBonus = 'balance_bonus';
            

        } elseif ($wallet->balance_bonus > 0) {
            $restante = $bet - $wallet->balance_bonus;
            $wallet->update(['balance_bonus' => 0]);
            $wallet->decrement('balance', $restante);
            $changeBonus = 'balance';
            

        } elseif ($wallet->balance >= $bet) {
            $wallet->decrement('balance', $bet);
            $changeBonus = 'balance';
            
        } elseif ($wallet->balance > 0) {
            $restante = $bet - $wallet->balance;
            $wallet->update(['balance' => 0]);
            $wallet->decrement('balance_withdrawal', $restante);
            $changeBonus = 'balance_withdrawal';
            
        }elseif ($wallet->balance_withdrawal >= $bet) {
            $wallet->decrement('balance_withdrawal', $bet);
            $changeBonus = 'balance_withdrawal';
           

        } elseif ($wallet->balance_bonus + $wallet->balance >= $bet) {
            $restante = $bet - $wallet->balance;
            if ($wallet->balance > 0) {
                $wallet->decrement('balance', $wallet->balance);
                $wallet->decrement('balance_bonus', $restante);
                $changeBonus = 'balance';
               
            } else {
                $wallet->decrement('balance_bonus', $bet);
                $changeBonus = 'balance_bonus';
               
            }
        } elseif ($wallet->balance_withdrawal + $wallet->balance_bonus >= $bet) {
            $restante = $bet - $wallet->balance_withdrawal;
            $wallet->decrement('balance_bonus', $restante);
            $wallet->update(['balance_withdrawal' => 0]);
            $changeBonus = 'balance_withdrawal';

            
        } else {
            \Log::warning('Transação falhou: fundos insuficientes', ['user_id' => $wallet->user_id, 'bet' => $bet]);
            return false;
        }
        
        return $changeBonus;
    }
    


    /**
     * Paga e atualiza o bonus vip
     *
     * @dev mscodex
     * * O melhor gateway de pagamentos para sua plataforma - 084 99624-9982
     * @param Wallet $wallet
     * @param $price
     * @return void
     */
    public static function payBonusVip(Wallet $wallet, $price)
    {
        $setting = Setting::first();

        if($setting->activate_vip_bonus) {
            $wallet->increment('vip_points', ($price * $setting->bonus_vip));

            /// verificar se subiu de level
            $vip = Vip::where('bet_required', '<=', $wallet->vip_points)->first();
            if(!empty($vip)) {

                /// verificar se já subiu pra esse nivel
                $checkVip = VipUser::where('user_id', $wallet->user_id)->where('vip_id', $vip->id)->where('status', 1)->first();
                if(empty($checkVip)) {
                    VipUser::create([
                        'user_id' => $wallet->user_id,
                        'vip_id' => $vip->id,
                        'status' => 1
                    ]);

                    /// atualiza o level vip
                    $wallet->increment('vip_level', 1);
                }
            }
        }

        /// verificar se o cara tem vip pra receber, se receber já paga.
        /// jogar na carteira de jogo "Balance" e não para carteira de saque

    }

    /**
     * @dev mscodex
     * * O melhor gateway de pagamentos para sua plataforma - 084 99624-9982
     * @param $userId
     * @param $changeBonus
     * @param $WinAmount
     * @return void
     */
    public static function payWithRollover($userId, $changeBonus, $win, $bet, $type): void
    {
        $setting = Setting::first();

        $wallet = Wallet::where('user_id', $userId)->first();

        if (!empty($wallet)) {
            if ($setting->disable_rollover) {
                $wallet->increment('balance_withdrawal', $win);
            } else {
                if ($type === 'bet') {
                    if ($changeBonus == 'balance_bonus') {
                        if ($wallet->balance_bonus_rollover >= $bet) {
                            $wallet->decrement('balance_bonus_rollover', $bet);
    
                            $ordersCount = Order::where('user_id', $userId)->where('type_money', 'balance_bonus')->count();
    
                            if ($wallet->balance_bonus_rollover === 0 && $ordersCount >= $setting->rollover_protection) {
                                $wallet->increment('balance_withdrawal', $wallet->balance_bonus);
                                $wallet->update(['balance_bonus' => 0]);
                            }
                        } else {
                            $ordersCount = Order::where('user_id', $userId)->where('type_money', 'balance_bonus')->count();
                            
                            if ($ordersCount >= $setting->rollover_protection) {
                                $wallet->update(['balance_bonus_rollover' => 0]);
                                $wallet->increment('balance_withdrawal', $wallet->balance_bonus);
                                $wallet->update(['balance_bonus' => 0]);
                            }
                        }
                    }
    
                    if ($changeBonus == 'balance') {
                        
                        if ($wallet->balance_deposit_rollover >= $bet) {
                            $wallet->decrement('balance_deposit_rollover', $bet);
    
                            if ($wallet->balance_deposit_rollover === 0) {
                                $wallet->increment('balance_withdrawal', $wallet->balance);
                                $wallet->update(['balance' => 0]);
                            }
                        } else {
                            $wallet->update(['balance_deposit_rollover' => 0]);
                            $wallet->increment('balance_withdrawal', $wallet->balance);
                            $wallet->update(['balance' => 0]);
                        }
                    }
                }
    
                if ($type === 'win') {
    
                    if ($changeBonus == 'balance_bonus') {
                        if ($wallet->balance_bonus_rollover <= 0 || empty($wallet->balance_bonus_rollover)) {
                            $wallet->increment('balance_withdrawal', $win);
                        } else {
                            $ordersCount = Order::where('user_id', $userId)->where('type_money', 'balance_bonus')->count();
    
                            if ($wallet->balance_bonus_rollover >= $bet) {
                                $wallet->decrement('balance_bonus_rollover', $bet);
    
                                if ($wallet->balance_bonus_rollover === 0 && $ordersCount >= $setting->rollover_protection) {
                                    $wallet->increment('balance_withdrawal', $wallet->balance_bonus);
                                    $wallet->update(['balance_bonus' => 0]);
                                } else {
                                    $wallet->increment('balance_bonus', $win);
                                }
                            } else {
                                if ($ordersCount >= $setting->rollover_protection) {
                                    $wallet->update(['balance_bonus_rollover' => 0]);
                                    $totalPay = ($wallet->balance_bonus + $win);
                                    $wallet->increment('balance_withdrawal', $totalPay);
                                    $wallet->update(['balance_bonus' => 0]);
                                }
                            }
                        }
                    }
    
                    if (in_array($changeBonus, ['balance', 'balance_withdrawal'])) {
                        if (empty($wallet->balance_deposit_rollover) || $wallet->balance_deposit_rollover <= 0) {
    
                            $wallet->increment('balance_withdrawal', $win);
                        } else {
                            if ($wallet->balance_deposit_rollover >= $bet) {
                                $wallet->decrement('balance_deposit_rollover', $bet);
    
                                if ($wallet->balance_deposit_rollover === 0) {
                                    $wallet->increment('balance_withdrawal', $wallet->balance);
                                    $wallet->update(['balance' => 0]);
                                } else {
                                    $wallet->increment('balance', $win);
                                }
                            } else {
                                $wallet->update(['balance_deposit_rollover' => 0]);
                                $totalPay = ($wallet->balance + $win);
                                $wallet->increment('balance_withdrawal', $totalPay);
                                $wallet->update(['balance' => 0]);
                            }
                        }
                    }
                }
            }
    
        } else {
            \Log::warning("Carteira não encontrada", ['user_id' => $userId]);
        }
    }


    /**
     * Distribuições
     *
     * Pega todas as distribuições
     * @dev mscodex
     * @return string[]
     */
    public static function getDistribution(): array
    {
        return [
            'venix' => 'API GAMES',
            'playconnect' => 'API SPORTS',
        ];
    }


    /**
     * @param string $order
     * @return string
     */
    public static function getTypeTransactionOrder($order)
    {
        switch ($order) {
            case 'balance_bonus':
                return 'Saldo Bônus';

            case 'balance':
                return 'Saldo Depósito';

            case 'balance_withdrawal':
                return 'Saldo de Saque';

            default:
                return 'Tipo de transação desconhecido';
        }
    }


    /**
     * @param $order
     * @return string
     */
    public static function getTypeOrder($order)
    {
        if($order == 'win') {
            return 'Vitória';
        }

        return 'Perda';
    }

    /**
     * Get Ative Wallet
     * Pegar uma carteira ativa
     * @dev mscodex
     * @return null
     */
    public static function getActiveWallet()
    {
        if(auth('api')->check()) {
            return Wallet::where('user_id', auth('api')->id())->where('active', 1)->first();
        }

        return null;
    }

    /**
     * @dev mscodex
     * @return void
     */
    public static function getGoogleFonts()
    {
        $response = Http::get('https://www.googleapis.com/webfonts/v1/webfonts?key=AIzaSyDQCZFgODu0jw7Ez00jgQU04SUsuncY3yQ');
        if($response->successful()) {

        }
    }

    /**
     * @dev mscodex
     * @param $tamanhoCodigo
     * @return string
     */
    public static function generateCode($tamanhoCodigo)
    {
        $caracteresPermitidos = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

        $codigo = '';

        for ($i = 0; $i < $tamanhoCodigo; $i++) {
            $codigo .= $caracteresPermitidos[rand(0, strlen($caracteresPermitidos) - 1)];
        }

        return $codigo;
    }

    /**
     * @dev mscodex
     * @param $odds
     * @return float|int
     */
    public static function calculateProbability($odds)
    {
        $odds = abs($odds);

        $probabilidade = 1 / $odds;
        return $probabilidade;
    }

    /**
     * @dev mscodex
     * @param $golsCasa
     * @param $golsVisitante
     * @param $oddsCasa
     * @param $oddsVisitante
     * @param float $multi
     * @return float|int
     */
    public static function calculateResultOdds($golsCasa, $golsVisitante, $oddsCasa, $oddsVisitante, float $multi = 2): float|int
    {
        // Calcula a média de gols esperada usando uma média ponderada
        $mediaGols = ($golsCasa * $oddsCasa + $golsVisitante * $oddsVisitante) / ($oddsCasa + $oddsVisitante);

        // Calcula a probabilidade de não haver gols
        $probabilidadeZeroGols = exp(-$mediaGols);

        // Calcula o novo odds para 0 gols
        $novoOdds = 1 / $probabilidadeZeroGols;

        return $novoOdds;
    }

    /**
     * @dev mscodex
     * @param $data
     * @return string|void
     */
    public static function getMatcheResult($data)
    {
        switch ($data) {
            case 0:
                return 'Pendente';
            case 1:
                return 'Finalizado';
        }
    }

    /**
     * @dev mscodex
     * @param $key
     * @return string
     */
    public static function checkPixKeyTypeSharkPay($key)
    {
        switch ($key) {
            case self::isCPF($key):
                return 'CPF';
            case self::isCNPJ($key):
                return 'CNPJ';
            case self::isMail($key):
                return 'EMAIL';
            case self::isTelefone($key):
                return 'PHONE';
            default:
                return 'EVP';
        }
    }

    private static function isMail($email)
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return true;
        }

        return false;
    }

    /**
     * @dev mscodex
     * @param $key
     * @return string
     */
    public static function checkPixKeyTypeSharkConnect($key)
    {
        switch ($key) {
            case self::isCPF($key):
            case self::isCNPJ($key):
                return 'document';
            case self::isTelefone($key):
                return 'phoneNumber';
            default:
                return 'randomKey';
        }
    }

    /**
     * @dev mscodex
     * @param $key
     * @return string|void
     */
    public static function formatPixType($key)
    {

        switch ($key) {
            case 'document':
                return 'Documento';
            case 'phoneNumber':
                return 'Telefone';
            case 'email':
                return 'E-mail';
            case 'randomKey':
                return 'Chave Aleatória';
            default:
                return $key;
        }
    }

    /**
     * @dev mscodex
     * @param $string
     * @return bool
     */
    private static function isTelefone($valor)
    {
        //processa a string mantendo apenas números no valor de entrada.
        $valor = preg_replace("/[^0-9]/", "", $valor);

        $lenValor = strlen($valor);

        //validando a quantidade de caracteres de telefone fixo ou celular.
        if($lenValor != 10 && $lenValor != 11) {
            return false;
        }


        //DD e número de telefone não podem começar com zero.
        if($valor[0] == "0" || $valor[2] == "0") {
            return false;
        }


        return true;
    }

    /**
     * @dev mscodex
     * @param $string
     * @return bool
     */
    private static function isCNPJ($string)
    {
        // Remove caracteres não numéricos
        $cnpj = preg_replace('/[^0-9]/', '', $string);

        // Verifica se a string tem 14 caracteres numéricos
        if (strlen($cnpj) !== 14) {
            return false;
        }

        return true; // Retorne true se for um CNPJ válido
    }

    /**
     * @dev mscodex
     * @param $string
     * @return bool
     */
    private static function isCPF($cpf)
    {
        // Extrai somente os números
        $cpf = preg_replace( '/[^0-9]/is', '', $cpf );

        // Verifica se foi informado todos os digitos corretamente
        if (strlen($cpf) != 11) {
            return false;
        }

        // Verifica se foi informada uma sequência de digitos repetidos. Ex: 111.111.111-11
        if (preg_match('/(\d)\1{10}/', $cpf)) {
            return false;
        }

        // Faz o calculo para validar o CPF
        for ($t = 9; $t < 11; $t++) {
            for ($d = 0, $c = 0; $c < $t; $c++) {
                $d += $cpf[$c] * (($t + 1) - $c);
            }
            $d = ((10 * $d) % 11) % 10;
            if ($cpf[$c] != $d) {
                return false;
            }
        }
        return true;
    }

    /**
     * @dev mscodex
     * @param $nomeCompleto
     * @return string
     *
     */
    public static function hideString($nomeCompleto)
    {
        $primeiraParteNome = substr($nomeCompleto, 0, 2);
        $asteriscos = str_repeat('*', 4);
        $nomeOculto = $primeiraParteNome . $asteriscos;  // Neste caso, "Vic******"

        return $nomeOculto;
    }


    /**
     * @dev mscodex
     * @param $controllerName
     * @return mixed
     * @throws \Exception
     */
    public static function createController($controllerName)
    {
        $fullControllerName = "App\Http\Controllers\Games\\" . ucfirst($controllerName) . "Controller";

        if (class_exists($fullControllerName)) {
            return new $fullControllerName();
        } else {
            // Caso a classe não exista, você pode lidar com isso aqui
            throw new \Exception("Controller não encontrado: $fullControllerName");
        }
    }

    /**
     * Generate Game History
     * Metodo responsavel pelo historico dos jogos, e também controle de  ganhos de afiliados
     * Como revshare e CPA.
     *
     * @dev mscodex
     * @param $userId
     * @param $type
     * @param $amount
     * @param $nameGame
     * @param $gameId
     * @param $changeBonus
     * @return mixed
     */
    public static function generateGameHistory($userId, $type, $win, $bet, $changeBonus, $tx)
    {

        $user = User::find($userId);

        /// pagar afiliado
        if($type == 'bet' && !empty($user->inviter)) {
            $affiliate = User::find($user->inviter);
            self::PayLoss($user, $affiliate, $bet, $changeBonus);
        }

        if($type == 'win' && !empty($user->inviter)) {
            $affiliate  = User::find($user->inviter);
            self::PayWin($user, $affiliate, $type, $win, $changeBonus);
        }

        if($type == 'win') {
            $transaction = Order::where('transaction_id', $tx)->where('type', 'check')->where('status', 0)->first();

            if(!empty($transaction)) {
                if($bet == 0) {
                    $bet = $transaction->amount;
                }

                $transaction->update(['status' => 1, 'type' => $type, 'amount' => $win]);
            }
        }

        if($type == 'bet') {
            $transaction = Order::where('transaction_id', $tx)->where('type', 'check')->where('status', 0)->first();
            
            if(!empty($transaction)) {
                $transaction->update(['status' => 1, 'type' => $type, 'amount' => $bet]);
            }
        }

        /// pagando o premio
        self::payWithRollover($userId, $changeBonus, $win, $bet, $type); /// verifica o rollover
        return true;
    }

    /**
     * PayLoss
     *
     * @param $user
     * @param $affiliate
     * @param $bet
     * @param $changeBonus
     * @return float|int
     */
    private static function PayLoss($user, $affiliate, $bet, $changeBonus)
    {
        // Função responsável por calcular e distribuir comissões de perdas para afiliados
        
        // Obtém configurações gerais
        $setting = self::getSetting();
        
        if(!empty($affiliate)) {
            // Busca histórico de revshare do usuário
            $affHistoryRevshare = AffiliateHistory::where('user_id', $user->id)
                ->where('commission_type', 'revshare')
                ->first();

            // Verifica se existe histórico e se é uma transação válida
            if(!empty($affHistoryRevshare) && in_array($changeBonus, ['balance', 'balance_withdrawal'])) {

                // Define percentual de revshare do afiliado
                $revshare = $affiliate->affiliate_revenue_share;

                // Calcula comissão bruta baseada na aposta
                $lossPercentage = self::porcentagem_xn($revshare, $bet);
                
                // Calcula NGR (Net Gaming Revenue)
                $ngr = self::porcentagem_xn($setting->ngr_percent, $lossPercentage);

                // Comissão final após NGR
                $commissionPay = ($lossPercentage - $ngr);

                // Processa comissões para sub-afiliados em 3 níveis
                if(!empty($affiliate->inviter)) {
                    $affiliatelv1 = User::find($affiliate->inviter);
                    if(!empty($affiliatelv1)) {
                        // Nível 1
                        $percentageLvl1 = self::porcentagem_xn($setting->perc_sub_lv1, $commissionPay);
                        $affiliatelv1->wallet->increment('refer_rewards', $percentageLvl1);
                        $commissionPay -= $percentageLvl1;

                        if(!empty($affiliatelv1->inviter)) {
                            $affiliatelv2 = User::find($affiliatelv1->inviter);
                            if(!empty($affiliatelv2)) {
                                // Nível 2
                                $percentageLvl2 = self::porcentagem_xn($setting->perc_sub_lv2, $commissionPay);
                                $affiliatelv2->wallet->increment('refer_rewards', $percentageLvl2);
                                $commissionPay -= $percentageLvl2;

                                // Nível 3
                                $affiliatelv3 = User::find($affiliatelv2->inviter);
                                if(!empty($affiliatelv3)) {
                                    $percentageLvl3 = self::porcentagem_xn($setting->perc_sub_lv3, $commissionPay);
                                    $affiliatelv3->wallet->increment('refer_rewards', $percentageLvl3);
                                    $commissionPay -= $percentageLvl3;
                                }
                            }
                        }
                    }
                }

                // Paga comissão restante ao afiliado principal
                $affiliate->wallet->increment('refer_rewards', $commissionPay);
                
                // Atualiza métricas do histórico
                $affHistoryRevshare->increment('commission', $commissionPay);
                $affHistoryRevshare->increment('commission_paid', $commissionPay);
                $affHistoryRevshare->increment('losses', 1);
                $affHistoryRevshare->increment('losses_amount', $bet);

                return ($bet - $commissionPay);
            }
        }
        
        return $bet;
    }

    /**
     * @param $user
     * @param $affiliate
     * @param $type
     * @param $amount
     * @param $changeBonus
     * @return float|int|void
     */
    private static function PayWin($user, $affiliate, $type, $amount, $changeBonus)
    {
        // Log do início da função e dos parâmetros recebidos
        \Log::info('Iniciando PayWin', [
            'user_id' => $user->id,
            'inviter_id' => $user->inviter,
            'affiliate_id' => $affiliate->id ?? null,
            'type' => $type,
            'amount' => $amount,
            'changeBonus' => $changeBonus
        ]);

        $wallet = Wallet::where('user_id', $user->inviter)->where('active', 1)->first();
        $setting = self::getSetting();

        // Log das configurações obtidas
        \Log::info('Configurações obtidas', ['revshare_reverse' => $setting->revshare_reverse]);

        // Verificação se revshare reverse está ativo
        if ($setting->revshare_reverse) {
            if ($type == 'win' && !empty($user->inviter) && in_array($changeBonus, ['balance', 'balance_withdrawal'])) {
                if (!empty($affiliate)) {
                    $affHistoryRevshare = AffiliateHistory::where('user_id', $user->id)
                        ->where('commission_type', 'revshare')
                        ->where('status', 0)
                        ->first();

                    // Log do histórico de afiliados encontrado
                    \Log::info('Histórico de afiliados encontrado', [
                        'affHistoryRevshare_id' => $affHistoryRevshare->id ?? 'Nenhum histórico encontrado'
                    ]);

                    if (!empty($affHistoryRevshare)) {
                        // Seleção do revshare correto
                        $revshare = $affiliate->affiliate_revenue_share_fake ?? $affiliate->affiliate_revenue_share;
                        \Log::info('Revshare selecionado', ['revshare' => $revshare]);

                        // Cálculo da comissão
                        $commissionSub = -abs(self::porcentagem_xn($revshare, $amount)); // Forçar o valor como negativo
                        \Log::info('Comissão calculada (commissionSub)', [
                            'revshare_percent' => $revshare,
                            'amount' => $amount,
                            'commissionSub' => $commissionSub
                        ]);

                        // Aplicar decremento no wallet
                        $wallet->decrement('refer_rewards', abs($commissionSub)); // Decrementa o valor absoluto
                        \Log::info('Wallet decrementado', [
                            'user_id' => $user->inviter,
                            'refer_rewards_decrementado' => abs($commissionSub)
                        ]);

                        // Aplicar decremento no histórico de afiliados
                        $affHistoryRevshare->decrement('commission', abs($commissionSub));
                        $affHistoryRevshare->decrement('commission_paid', abs($commissionSub));
                        \Log::info('Histórico de afiliado decrementado', [
                            'commission' => $affHistoryRevshare->commission,
                            'commission_paid' => $affHistoryRevshare->commission_paid
                        ]);

                        // Incrementar histórico de valores depositados
                        $affHistoryRevshare->increment('deposited', 1);
                        $affHistoryRevshare->increment('deposited_amount', $amount);
                        \Log::info('Valores de depósito atualizados no histórico de afiliados', [
                            'deposited' => $affHistoryRevshare->deposited,
                            'deposited_amount' => $affHistoryRevshare->deposited_amount
                        ]);

                        \Log::info('Processo PayWin concluído com sucesso');
                        return $amount;
                    }

                    \Log::info('Nenhum histórico de afiliado encontrado para subtração de ganhos');
                    return $amount;
                }

                \Log::info('Nenhum afiliado encontrado para o usuário');
                return $amount;
            }

            \Log::info('Condição de pagamento revshare reverse não satisfeita');
            return $amount;
        }

        \Log::info('Revshare reverse não ativo nas configurações');
        return $amount;
    }

    /**
     * @dev mscodex
     * @param $arr
     * @return int|mixed
     */
    public static function CountScatter($arr)
    {
        $count_scarter = array_count_values($arr);

        if (isset($count_scarter['Symbol_1'])) {
            return $count_scarter['Symbol_1'];
        }

        return 0;
    }

    /**
     * @dev mscodex
     * @param $drops
     * @return int[]
     */
    public static function MultiplyCount($drops)
    {
        global $multiples;
        if ($drops > 3) {
            $drops = 3;
        }
        return $multiples[$drops] ?? null;
    }

    /**
     * @dev mscodex
     * @param $val
     * @param $digits
     * @return float
     */
    public static function ToFloat($val, $digits = 2) {
        return (float)number_format($val, $digits, '.', '');
    }

    /**
     * @dev mscodex
     * @param $lines
     * @return float|int|mixed
     */
    public static function CalcWinActiveLine($lines) {
        $aux = 0;

        if (sizeof($lines) > 0) {
            foreach($lines as $line) {
                $aux = $aux + ($line['payout'] * $line['multiply']);
            }
        }

        return $aux;
    }

    /**
     * @dev mscodex
     * @param $drops
     * @param $mult
     * @return array
     */
    public static function CalcWinDropLine($drops, $mult) {
        $total = 0;
        foreach($drops as $drop) {
            $amout = self::CalcWinActiveLine($drop['ActiveLines']);
            $total = $total + $amout;
            // $drop['ActiveLines']['win_amount'] = $amout;
        }
        $total = $total * $mult;
        return compact(['drops', 'total']);
    }


    /**
     * @dev mscodex
     * @param $data
     */
    public static function arrayToObject($data)
    {
        $collection = collect($data);

        $objects = $collection->map(function ($item) {
            return array_combine(range(1, count($item)), $item);
        });

        return $objects;
    }


    /**
     * @dev mscodex
     * @return null
     */
    public static function getToken()
    {
        if(auth()->check()) {
            $token = \Helper::MakeToken([
                'id' => auth()->id()
            ]);

            return $token;
        }

        return null;
    }

    /**
     * @dev mscodex
     * @return float
     */
    public static function getBalance()
    {
        if(auth()->check()) {
            return self::amountFormatDecimal(auth()->user()->wallet->balance + auth()->user()->wallet->balance_bonus);
        }else{
            return self::amountFormatDecimal(0.00);
        }
    }

    /**
     * @dev mscodex
     * Get Settings
     * @return \Illuminate\Cache\
     */
    public static function getCustom()
    {
        $custom = CustomLayout::first();
        return $custom;
    }

    /**
     * @dev mscodex
     * Get Settings
     * @return \Illuminate\Cache\
     */
    public static function getSetting()
    {
        $setting = Setting::select(
                'software_name',
                'software_description',
        
                /// logos e background
                'software_favicon',
                'software_logo_white',
                'software_logo_black',
                'software_background',
        
                'currency_code',
                'decimal_format',
                'currency_position',
                'prefix',
                'storage',
                'min_deposit',
                'max_deposit',
                'min_withdrawal',
                'max_withdrawal',
        
                /// vip
                'bonus_vip',
                'activate_vip_bonus',
        
                // Percent
                'ngr_percent',
                'revshare_percentage',
                'revshare_reverse',
                'cpa_value',
                'cpa_baseline',
        
                /// soccer
                'soccer_percentage',
                'turn_on_football',
        
                'initial_bonus',
        
                'suitpay_is_enable',
                'stripe_is_enable',
                'bspay_is_enable',
                'mercadopago_is_enable',
                'sharkpay_is_enable',
                'digitopay_is_enable',
        
                /// withdrawal limit
                'withdrawal_limit',
                'withdrawal_period',
        
                'disable_spin',
        
        
                /// sub afiliado
                'perc_sub_lv1',
                'perc_sub_lv2',
                'perc_sub_lv3',
        
                /// campos do rollover
                'rollover',
                'rollover_deposit',
                'disable_rollover',
                'rollover_protection',
        
        
                'default_gateway',
                'ezzebank_is_enable'
    
                )->first();

                Cache::put('setting', $setting);  
                return $setting;
            }
        
            /**
             * @dev mscodex
             * @param $bytes
             * @return string
             */
            public static function bytesToHuman($bytes)
            {
                $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB'];
        
                for ($i = 0; $bytes > 1024; $i++) {
                    $bytes /= 1024;
                }
        
                return round($bytes, 2) . ' ' . $units[$i];
            }

    /**
     * Amount Format Decimal
     * @dev mscodex
     * * O melhor gateway de pagamentos para sua plataforma - 084 99624-9982
     *
     * @param $value
     * @return string
     */
    public static function amountFormatDecimalAdmin($value)
    {
        if(auth()->check()) {
            $wallet = Wallet::whereUserId(auth()->user()->id)->first();

            $formatter = new NumberFormatter(app()->getLocale(), NumberFormatter::CURRENCY);
            return $formatter->formatCurrency(floatval($value), $wallet->currency);
        }

        return 0;
    }

    /**
     * @dev mscodex
     * Amount Format Decimal
     *
     * @param $value
     * @return string
     */
    public static function amountFormatApi($value)
    {
        if(auth('api')->check()) {
            $wallet = Wallet::whereUserId(auth('api')->user()->id)->first();

            $formatter = new NumberFormatter(app()->getLocale(), NumberFormatter::CURRENCY);
            return $formatter->formatCurrency(floatval($value), $wallet->currency);
        }

        return 0;
    }

    /**
     * @dev mscodex
     * Amount Format Decimal
     *
     * @param $value
     * @return string
     */
    public static function amountFormatDecimal($value)
    {
        $settings = self::getSetting();

        if ($settings->currency_code == 'JPY') {
            return $settings->currency_symbol.number_format($value);
        }

        if ($settings->decimal_format == 'dot') {
            $decimalDot = ',';
            $decimalComma = '.';
        } else {
            $decimalDot = '.';
            $decimalComma = ',';

        }

        if ($settings->currency_position == 'left') {
            $amount = ($settings->prefix ?? 'R$').number_format(floatval($value), 2, $decimalDot, $decimalComma);
        } elseif ($settings->currency_position == 'right') {
            $amount = number_format(floatval($value), 2, $decimalDot, $decimalComma).($settings->prefix ?? 'R$');
        } else {
            $amount = $settings->prefix.number_format(floatval($value), 2, $decimalDot, $decimalComma);
        }

        return $amount;
    }

    /**
     * Days In Month
     * @dev mscodex
     *
     * @param $month
     * @param $year
     * @return int
     */
    public static function daysInMonth($month, $year)
    {
        return $month == 2 ? ($year % 4 ? 28 : ($year % 100 ? 29 : ($year % 400 ? 28 : 29))) : (($month - 1) % 7 % 2 ? 30 : 31);
    }

    /**
     * @dev mscodex
     * @param $date
     * @return array|string|string[]
     */
    public static function formatDateToHumman($date)
    {
        $created_at = Carbon::parse($date)->diffForHumans();
        $created_at = str_replace([' seconds', ' second'], ' sec', $created_at);
        $created_at = str_replace([' minutes', ' minute'], ' min', $created_at);
        $created_at = str_replace([' hours', ' hour'], ' h', $created_at);
        $created_at = str_replace([' months', ' month'], ' m', $created_at);

        if(preg_match('(years|year)', $created_at)){
            $created_at = Carbon::parse($date)->toFormattedDateString();
        }

        return $created_at;
    }

    /**
     * @dev mscodex
     * @param $string
     * @return mixed
     */
    public static function getFirstUrl($string)
    {
        preg_match_all('#\bhttps?://[^\s()<>]+(?:\([\w\d]+\)|([^[:punct:]\s]|/))#', $string, $_matches);
        $firstURL = $_matches[0][0] ?? false;
        if ($firstURL) {
            return $firstURL;
        }
    }

    /**
     * @dev mscodex
     * @param $url
     * @return string
     */
    public static function videoUrl($url)
    {
        $urlValid = filter_var($url, FILTER_VALIDATE_URL) ? true : false;

        if ($urlValid) {
            $parse = parse_url($url);
            $host  = strtolower($parse['host']);

            if ($host) {
                if (in_array($host, array(
                    'youtube.com',
                    'www.youtube.com',
                    'youtu.be',
                    'www.youtu.be',
                    'vimeo.com',
                    'player.vimeo.com'))) {
                    return $host;
                }
            }
        }
    }


    /**
     * @dev mscodex
     * Upload
     *
     * @param $file
     * @return array
     */
    public static function upload($file)
    {
        $extension  = $file->extension();
        $size       = $file->getSize();
        $path       = Storage::disk('public')->putFile('uploads', $file, 'public');
        $name       = explode('uploads/', $path);

        if($path && isset($name[1])) {
            return [
                'path'      => $path,
                'name'      => $name[1],
                'extension' => $extension,
                'size'      => $size
            ];
        }else{
            return false;
        }
    }

    /**
     * @dev mscodex
     * Format Number
     *
     * @param $number
     * @return mixed|string
     */
    public static function formatNumber( $number )
    {
        if( $number >= 1000 &&  $number < 1000000 ) {
            return number_format( $number/1000, 1 ). "k";
        } else if( $number >= 1000000 ) {
            return number_format( $number/1000000, 1 ). "M";
        } else {
            return $number;
        }
    }

    /**
     * @dev mscodex
     * Check Text
     */
    public static function checkText($str, $url = null)
    {
        if(mb_strlen($str, 'utf8') < 1) {
            return false;
        }

        $str = str_replace($url, '', $str);

        $str = trim($str);
        $str = nl2br(e($str));
        $str = str_replace(array(chr(10), chr(13) ), '' , $str);
        $url = preg_replace('#^https?://#', '', url('').'/');

        $regex = "~([@])([^\s@!\"\$\%&\'\(\)\*\+\,\-./\:\;\<\=\>?\[/\/\/\\]\^\`\{\|\}\~]+)~";
        $str = preg_replace($regex, '<a href="//'.$url.'$2">$0</a>', $str);

        $str = stripslashes($str);
        return $str;
    }

    /**
     * @dev mscodex
     * @param $path
     * @return string
     */
    public static function getFile($path)
    {
        return url($path);
    }

    /**
     * @dev mscodex
     * Prepare Fields Array
     *
     * @param $data
     * @return array
     */
    public static function prepareFieldsArray($data)
    {
        return array_filter($data);
    }

    /**
     * @dev mscodex
     * @param $bytes
     * @param int $precision
     * @return string
     */
    public static function formatBytes($bytes, $precision = 2) {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        // Uncomment one of the following alternatives
        // $bytes /= pow(1024, $pow);
        // $bytes /= (1 << (10 * $pow));

        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    /**
     * @dev mscodex
     * @param $extension
     * @return string|null
     */
    public static function fileTypeUpload($extension)
    {
        switch ($extension) {
            case 'jpeg':
            case 'bmp':
            case 'png':
            case 'gif':
            case 'jfif':
            case 'jpg':
            case 'svg':
                return 'image';
                break;

            case 'doc':
            case 'pdf':
            case 'docx':
            case 'txt':
                return 'document';
                break;

            case 'mp3':
            case 'wav':
                return 'audio';
                break;

            case 'rar':
            case 'zip':
                return 'file';
                break;

            case 'mov':
            case 'ts':
            case 'm3u8':
            case 'flv':
            case '3gp':
            case 'ogg':
            case 'mp4':
            case 'avi':
                return 'video';
                break;
            default:
                return 'image';
                break;
        }
    }

    /**
     * @dev mscodex
     * @param $country
     * @return bool
     */
    public static function getCountry($country)
    {
        if(!is_null($country)) {
            $country = \DB::table('countries')->where('iso', strtoupper($country))->first();
            if(!is_null($country)) {
                return $country->name;
            }

            return $country;
        }
        return 'US';
    }

    /**
     * @dev mscodex
     * @param $country
     * @return bool
     */
    public static function getCountryByCode($country)
    {
        if(!is_null($country)) {
            $country = \DB::table('countries')->where('iso', strtoupper($country))->first();
            if(!is_null($country)) {
                return $country->name;
            }

            return $country;
        }
        return 'US';
    }


    /**
     * @dev mscodex
     * Format Checkbox
     * @param $value
     * @return int
     */
    public static function formatCheckBox($value)
    {
        return ($value == 'yes' ? 1 : 0);
    }

    /**
     *
     * Função de porcentagem: Quanto é X% de N?
     * // Utilização
    echo "Quanto é 11% de 22: <b>" . porcentagem_xn(11, 22) . "</b> rn <br>";
    echo "Quanto é 22% de 11: <b>" . porcentagem_xn(22, 11) . "</b> rn <br>";
    echo "Quanto é 99% de 100: <b>" . porcentagem_xn(99, 100) . "</b> rn <br>";
    echo "Quanto é 99% de 105: <b>" . porcentagem_xn(99, 105) . "</b> rn <br>";
    echo "Quanto é 201% de 105: <b>" . porcentagem_xn(201, 105) . "</b> rn <br>";
     * @param $porcentagem
     * @param $total
     * @return float|int
     */
    public static function porcentagem_xn( $porcentagem, $total )
    {
        return ( $porcentagem / 100 ) * $total;

    }

    /**
     * Função de porcentagem: N é X% de N
     *
    echo "2.42 é <b>" . porcentagem_nx(2.42, 22) . "%</b> de 22.rn <br>";
    echo "2.42 é <b>" . porcentagem_nx(2.42, 11) . "%</b> de 11.rn <br>";
    echo "99 é <b>" . porcentagem_nx(99, 100) . "%</b> de 100.rn <br>";
    echo "103.95 é <b>" . porcentagem_nx(103.95, 105) . "%</b> de 105.rn <br>";
    echo "211.05 é <b>" . porcentagem_nx(211.05, 105) . "%</b> de 105.rn <br>";
     * @param $parcial
     * @param $total
     * @return float|int
     */
    public static function porcentagem_nx( $parcial, $total ) {
        if(!empty($parcial) && !empty($total)) {
            return ( $parcial * 100 ) / $total;
        }else{
            return 0;
        }
    }

    /**
     * Função de porcentagem: N é N% de X
     * // Utilização
    echo "2.42 é 11% de <b>" . porcentagem_nnx ( 2.42, 11 ) . "</b></b>.rn <br>";
    echo "2.42 é  22% de <b>" . porcentagem_nnx ( 2.42, 22 ) . "</b></b>.rn <br>";
    echo "99 é 100% de <b>" . porcentagem_nnx ( 99, 100 ) . "</b></b>.rn <br>";
    echo "103.95 é  99% de <b>" . porcentagem_nnx ( 103.95, 99 ) . "</b></b>.rn <br>";
    echo "2.42 é 11% de <b>" . porcentagem_nnx ( 211.05, 201 ) . "</b></b>.rn <br>";
    echo "337799 é 70% de <b>" . porcentagem_nnx ( 337799, 70 ) . "</b></b>.rn <br>";
     * @param $parcial
     * @param $porcentagem
     * @return float|int
     */
    function  porcentagem_nnx( $parcial, $porcentagem ) {
        return ( $parcial / $porcentagem ) * 100;
    }

    /**
     * @dev mscodex
     * @param $value
     * @return mixed
     */
    public static function formatCurrencyByRegion($amount, $currency = 'BRL'): mixed
    {
        $locale = str_replace('_', '-', app()->getLocale()); // Substitua pelo código do país/região desejado

        // Crie um objeto NumberFormatter para a região desejada
        $formatter = new NumberFormatter($locale, NumberFormatter::CURRENCY);

        // Formate o valor da moeda usando o NumberFormatter
        $formattedCurrency = $formatter->formatCurrency($amount, $currency); // Substitua 'BRL' pelo código da moeda desejada

        // Retorne o valor formatado
        return $formattedCurrency;
    }

    /**
     * @dev mscodex
     * @param $str
     * @return null|string|string[]
     */
    public static function soNumero($str) {
        return preg_replace("/[^0-9]/", "", $str);
    }

    /**
     * @dev mscodex
     * Amount Prepare
     * @param $float_dollar_amount
     * @return string
     */
    public static function amountPrepare($float_dollar_amount)
    {
        $separators_only = preg_filter( '/[^,\.]/i', '', $float_dollar_amount );

        if ( strlen( $separators_only ) > 1 ) {
            if ( substr( $separators_only, 0, 1) == '.' ) {
                $float_dollar_amount = str_replace( '.', '', $float_dollar_amount );
                $float_dollar_amount = str_replace( ',', '.', $float_dollar_amount );

            } else if ( substr( $separators_only, 0, 1) == ',' ) {
                $float_dollar_amount = str_replace( ',', '', $float_dollar_amount );
            }

        } else if ( strlen( $separators_only ) == 1 && $separators_only == ',' ) {
            $float_dollar_amount = str_replace( ',', '.', $float_dollar_amount );
        }

        return $float_dollar_amount;
    }

    /**
     * @dev mscodex
     * @param $currency
     * @return string
     */
    public static function checkPrefixCurrency($currency)
    {
        switch ($currency) {
            case '$':
                return 'USD';
                break;
            case 'R$':
                return 'BRL';
                break;
            default:
                return 'USD';
        }
    }


    /**
     * @dev mscodex
     * @param $array
     * @return mixed
     */
    public static function MakeToken($array){
        if(is_array($array)){
            $output =  '{"status": true';
            $interacao = 0;
            foreach ($array as $key => $value){
                $output .=  ',"' .$key . '"' . ': "' . $value . '"';
            }
            $output .= "}";
        }else{
            $er_txt = self::Decode('QVakfW0DwcOie2aD9kog9oRx81VtX73oY1Vn91o7YVamZVa2eVaxYkwofGadZGadfGope2aB9zJgbVapYXJgX5R6YWJgeGgg9h');
            $output = str_replace('_', '&nbsp;', $er_txt);
            exit($output);
        }
        return self::Encode($output);
    }


    /**
     * @dev mscodex
     * @param $token
     * @return mixed|string
     */
    public static function DecToken($token){
        $json = self::Decode($token);
        if(is_numeric($json)){
            return $token;
        }else if(self::isJson($json)){
            $json = str_replace("{\"email", "{\"status\":true ,\"email", $json);
            return json_decode($json, true);
        }else{
            return array("status"=>false, "messase"=>"invalid token");
        }
    }

    /**
     * @dev mscodex
     * @param $string
     * @return bool
     */
    private static function isJson($string){
        json_decode($string);
        return (json_last_error() == JSON_ERROR_NONE);
    }

    /**
     * @dev mscodex
     * @param $texto
     * @return string
     */
    public static function Encode($texto){
        $retorno = "";
        $saidaSubs = "";
        $texto = base64_encode($texto);
        $busca0 = array("a","b","c","d","e","f","g","h","i","j","k","l","m","n","o","p","q","r","s","t","u","v","x","w","y","z","0","1","2","3","4","5","6","7","8","9","=");
        $subti0 = array("8","e","9","f","b","d","h","g","j","i","m","o","k","z","l","w","4","s","r","u","t","x","v","p","6","n","7","2","1","5","q","3","y","0","c","a","");

        for($i=0;$i<strlen($texto);$i++){
            $ti = array_search($texto[$i], $busca0);
            if($busca0[$ti] == $texto[$i]){
                $saidaSubs .= $subti0[$ti];
            }else{
                $saidaSubs .= $texto[$i];
            }
        }
        $retorno = $saidaSubs;

        return $retorno;
    }

    /**
     * @dev mscodex
     * @param $texto
     * @return string
     */
    public static function Decode($texto){
        $retorno = "";
        $saidaSubs = "";
        $busca0 = array("8","e","9","f","b","d","h","g","j","i","m","o","k","z","l","w","4","s","r","u","t","x","v","p","6","n","7","2","1","5","q","3","y","0","c","a");
        $subti0 = array("a","b","c","d","e","f","g","h","i","j","k","l","m","n","o","p","q","r","s","t","u","v","x","w","y","z","0","1","2","3","4","5","6","7","8","9");

        for($i=0;$i<strlen($texto);$i++){
            $ti = array_search($texto[$i], $busca0);
            if($busca0[$ti] == $texto[$i]){
                $saidaSubs .= $subti0[$ti];
            }else{
                $saidaSubs .= $texto[$i];
            }
        }

        $retorno = base64_decode($saidaSubs);

        return $retorno;
    }

    /**
     * @dev mscodex
     * @return mixed
     */
    public static function WheelPrizes() {
        $key = 'spin:config:prizes';
        $cached = Cache::get($key);
        $config = NULL;

        if(!$cached) {
            $c = SpinConfigs::latest()->first();
            $str = $c->prizes;
            Cache::set($key, $str);
            $config = json_decode($str);
        } else {
            $config = json_decode($cached);
        }

        return $config;
    }
}
?>
