<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreTransferRequest;
use App\Services\TransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransferController extends Controller
{
    public function __construct(private readonly TransferService $transfers) {}

    public function store(StoreTransferRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->transfers->create($request->user(), (string) $request->ip(), $request->validated())], 201);
    }

    public function approve(Request $request, string $transfer): JsonResponse
    {
        $message = $this->transfers->approve((int) $transfer, $request->user(), (string) $request->ip());

        return response()->json(['message' => $message]);
    }

    public function ship(Request $request, string $transfer): JsonResponse
    {
        $this->transfers->ship((int) $transfer, $request->user(), (string) $request->ip());

        return response()->json(['message' => 'Տեղափոխումը դուրս գրվեց աղբյուր պահեստից և ուղարկվեց։']);
    }

    public function receive(Request $request, string $transfer): JsonResponse
    {
        $this->transfers->receive((int) $transfer, $request->user(), (string) $request->ip());

        return response()->json(['message' => 'Ապրանքն ընդունվեց ստացող պահեստի մնացորդ։']);
    }
}
