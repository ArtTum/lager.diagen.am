<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\MarkNotificationReadRequest;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->notifications->index($request->user()));
    }

    public function markRead(MarkNotificationReadRequest $request): JsonResponse
    {
        $this->notifications->markRead($request->user(), $request->validated('key'));

        return response()->json(['message' => 'Ծանուցումը նշվեց որպես կարդացված։']);
    }
}
