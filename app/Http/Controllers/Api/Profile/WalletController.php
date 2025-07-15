<?php

namespace App\Http\Controllers\Api\Profile;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdrawal;
use App\Notifications\NewWithdrawalNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class WalletController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $wallet = Wallet::whereUserId(auth('api')->id())->where('active', 1)->first();
        return response()->json(['wallet' => $wallet], 200);
    }

    /**
     * @return \Illuminate\Http\JsonResponse
     */
    public function myWallet()
    {
        $wallets = Wallet::whereUserId(auth('api')->id())->get();
        return response()->json(['wallets' => $wallets], 200);
    }

    /**
     * @param $id
     * @return \Illuminate\Http\JsonResponse|void
     */
    public function setWalletActive($id)
    {
        $checkWallet = Wallet::whereUserId(auth('api')->id())->where('active', 1)->first();
        if(!empty($checkWallet)) {
            $checkWallet->update(['active' => 0]);
        }

        $wallet = Wallet::find($id);
        if(!empty($wallet)) {
            $wallet->update(['active' => 1]);
            return response()->json(['wallet' => $wallet], 200);
        }
    }

    /**
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function requestWithdrawal(Request $request)
{
    \Log::info('REQUEST ' . json_encode($request->all()));

    $setting = Setting::first();

    // Verificar se o usuário está autenticado
    if (auth('api')->check()) {
        
        // Definir as regras de validação para o tipo PIX
        if ($request->type === 'pix') {
            $rules = [
                'amount'        => ['required', 'numeric', 'min:' . $setting->min_withdrawal, 'max:' . $setting->max_withdrawal],
                'pix_type'      => 'required|string',
                'pix_key'       => 'required|string',
                'accept_terms'  => 'required|boolean|accepted',
            ];

            // Definir a validação de `pix_key` conforme o tipo
            switch ($request->pix_type) {
                case 'document':
                    $rules['pix_key'] = 'required|cpf_ou_cnpj';
                    break;
                case 'email':
                    $rules['pix_key'] = 'required|email';
                    break;
                default:
                    $rules['pix_key'] = 'required|string';
                    break;
            }

            // Realizar a validação
            $validator = Validator::make($request->all(), $rules);
            if ($validator->fails()) {
                return response()->json($validator->errors(), 400);
            }
        }

        // Verificar limite mínimo de saque
        if ($request->amount < 0) {
            return response()->json(['error' => 'Você tem que solicitar um valor válido'], 400);
        }

        // Verificar limite de saques por período
        if (!empty($setting->withdrawal_limit) && !empty($setting->withdrawal_period)) {
            $withdrawalsQuery = Withdrawal::where('user_id', auth('api')->user()->id);

            switch ($setting->withdrawal_period) {
                case 'daily':
                    $withdrawalsQuery->whereDate('created_at', now()->toDateString());
                    break;
                case 'weekly':
                    $withdrawalsQuery->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()]);
                    break;
                case 'monthly':
                    $withdrawalsQuery->whereYear('created_at', now()->year)->whereMonth('created_at', now()->month);
                    break;
                case 'yearly':
                    $withdrawalsQuery->whereYear('created_at', now()->year);
                    break;
            }

            if ($withdrawalsQuery->count() >= $setting->withdrawal_limit) {
                return response()->json(['error' => trans('Você atingiu o limite de saques para este período')], 400);
            }
        }

        // Verificar valor máximo de saque permitido
        if ($request->amount > $setting->max_withdrawal) {
            return response()->json(['error' => 'Você excedeu o limite máximo permitido de: ' . $setting->max_withdrawal], 400);
        }

        // Verificar saldo suficiente e aceitação dos termos
        if ($request->accept_terms) {
            $walletBalance = floatval(auth('api')->user()->wallet->balance_withdrawal);
            if (floatval($request->amount) > $walletBalance) {
                return response()->json(['error' => 'Você não tem saldo suficiente'], 400);
            }

            // Preparar dados para inserção no banco
            $data = [
                'user_id'   => auth('api')->user()->id,
                'amount'    => \Helper::amountPrepare($request->amount),
                'type'      => $request->type,
                'currency'  => $request->currency,
                'symbol'    => $request->symbol,
                'status'    => 0,
            ];

            if ($request->type === 'pix') {
                $data['pix_key'] = $request->pix_key;
                $data['pix_type'] = $request->pix_type;
            } elseif ($request->type === 'bank') {
                $data['bank_info'] = $request->bank_info;
            }

            // Criar solicitação de saque
            $withdrawal = Withdrawal::create($data);

            if ($withdrawal) {
                // Atualizar saldo do usuário
                $wallet = Wallet::where('user_id', auth('api')->id())->first();
                $wallet->decrement('balance_withdrawal', floatval($request->amount));

                // Notificar administradores
                $admins = User::where('role_id', 0)->get();
                foreach ($admins as $admin) {
                    $admin->notify(new NewWithdrawalNotification(auth('api')->user()->name, $request->amount));
                }

                return response()->json([
                    'status' => true,
                    'message' => 'Saque realizado com sucesso',
                ], 200);
            }
        } else {
            return response()->json(['error' => 'Você precisa aceitar os termos'], 400);
        }
    }

    return response()->json(['error' => 'Erro ao realizar o saque'], 400);
}


}
