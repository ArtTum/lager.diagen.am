<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\LoginRequest;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(private readonly AuthService $auth) {}

    public function login(LoginRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->auth->login($request->validated())]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->auth->me($request->user())]);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->auth->logout($request->user());

        return response()->json(['message' => 'Դուրս եք եկել համակարգից։']);
    }
}
