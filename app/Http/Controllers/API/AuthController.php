<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use Validator;

class AuthController extends Controller
{
    // Register
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required',
            'email' => 'required|email|unique:users',
            'password' => 'required',
            'c_password' => 'required|same:password',
        ]);

        if ($validator->fails()) {
            return response()->json(['success'=> false, 'message'=>'Validation Error', 'errors'=>$validator->errors()], 422);
        }

        $input = $request->all();
        $input['password'] = bcrypt($input['password']);
        $user = User::create($input);

        return response()->json(['success'=> true, 'message'=>'User registered successfully', 'data'=>$user]);
    }

    // Login
    public function login(Request $request)
    {
        $credentials = $request->only('email', 'password');

        if (!$token = auth()->attempt($credentials)) {
            return response()->json(['success'=> false, 'message'=>'immigration', 'data'=>['error'=>'Unauthorised']], 401);
        }

        return $this->respondWithToken($token);
    }

    // Profile
    public function profile()
    {
        return response()->json([
            'success' => true,
            'message' => 'User profile fetched successfully',
            'data' => auth()->user()
        ]);
    }

    // Logout
    public function logout()
    {
        auth()->logout();
        return response()->json(['success'=> true, 'message'=>'Successfully logged out']);
    }

    // Refresh
    public function refresh()
    {
        return $this->respondWithToken(auth()->refresh());
    }

    // Réponse JWT
    protected function respondWithToken($token)
    {
        return response()->json([
            'success'=> true,
            'data'=> [
                'access_token' => $token,
                'token_type' => 'bearer',
                'expires_in' => auth()->factory()->getTTL() * 60,
                'user' => auth()->user()
            ],
            'message' => 'User login successfully.'
        ]);
    }

    // Retourne la route dashboard selon rôle
    public function dashboardRedirect()
    {
        $user = auth()->user();
        if (!$user) {
            return response()->json(['success'=> false, 'message'=>'User not authenticated'], 401);
        }

        $route = null;
        if ($user->role === 'admin') {
            $route = 'admin.dashboard';
        } elseif ($user->role === 'supervisor') {
            $route = 'supervisor.dashboard';
        } elseif ($user->role === 'agent') {
            $route = 'agent.scan';
        }

        return response()->json([
            'success'=> true,
            'message'=>'Dashboard route fetched successfully',
            'data'=> ['route' => $route]
        ]);
    }
}
