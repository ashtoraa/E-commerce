<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use App\Models\Wallet ;
use App\Models\User;

class WalletController extends Controller
{
    public function add(Request $request)
{
    $request->validate([
        'amount' => 'required|numeric|min:1'
    ]);

    $wallet = auth()->user()->wallet;

    $wallet->balance += $request->amount;
    $wallet->save();

    return response()->json([
        'message' => 'Balance added',
        'balance' => $wallet->balance
    ]);
}

   public function show()
{
    return response()->json([
        'balance' => auth()->user()->wallet->balance
    ]);
}
}
