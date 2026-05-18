<?php

namespace App\Http\Controllers;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\Request;
use App\Models\Wallet;

class AuthController extends Controller
{
public function register(Request $request){





    $request->validate([
        "name"=>'required|string|max:255',
        "email"=>'required|string|email|max:255|unique:users',
        'password'=>'required|string|min:8|confirmed'
   ] );

//    User::create($request);

     $user= User::create([
        'name'=>$request->name,
         'email'=>$request->email,
         'password'=>Hash::make($request->password),

    ]);


$token = $user->createToken('auth_token')->plainTextToken;
$user->wallet()->create([
    'balance' => 0
]);
 return response()->json([
    "message" => "=done",
    "user" => $user,
    "token" => $token,
], 201);


  }

  //////////////

    public function login(Request $request){

  $request->validate([

        'email'=>'required|string|email',
        'password'=>'required|string',
   ] );

if (! Auth::attempt($request->only("email","password"))){

 return response()->json([
    "message" => "invalid email or password",
], 401);
}

 $user=User::where('email',$request->email)->firstorfail();
 $token = $user->createToken('auth_token')->plainTextToken;
 return response()->json([
    "message" => "done",
    "user" => $user,
    "token" => $token,
], 200);

  }

  //////////////
    public function logout(){
Auth::user()->currentAccessToken()->delete();
//$request->user()->currentAccessToken()->delete();
 return response()->json([
    "message" => "done",
], 200);

  }

}
