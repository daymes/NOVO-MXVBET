<?php

namespace App\Http\Controllers\Api\Wallet;

use App\Http\Controllers\Controller;
use App\Models\Deposit;
use App\Traits\Gateways\iBPayTrait;
use App\Traits\Gateways\MXVPayTrait;
use Illuminate\Http\Request;

class DepositController extends Controller
{
    use iBPayTrait, MXVPayTrait;

    /**
     * @param Request $request
     * @return array|false[]
     */
    public function submitPayment(Request $request)
    {
        // return BsPayTrait::requestQrcode($request);
        switch ($request->gateway) {
            case 'ibpay':
                return self::requestQrcode($request);

            case 'mxvpay':
                return self::requestQrcodeMXV($request);
        }
        return [];
    }
    
    /**
     * Show the form for creating a new resource.
     */
    public function consultStatusTransactionPix(Request $request)
    {
        return self::consultStatusTransaction($request);
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $deposits = Deposit::select('amount', 'created_at', 'currency', 'id', 'status', 'symbol', 'type', 'updated_at', 'user_id')
            ->whereUserId(auth('api')->id())
            ->orderBy('id', 'desc') // Ordena do maior para o menor com base no id
            ->paginate();
    
        return response()->json(['deposits' => $deposits], 200);
    }

}
