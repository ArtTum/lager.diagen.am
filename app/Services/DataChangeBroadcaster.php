<?php

namespace App\Services;

use App\Events\DataChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Throwable;

class DataChangeBroadcaster
{
    public function broadcast(): void
    {
        try {
            if (DB::transactionLevel() !== 0) {
                return;
            }

            Event::dispatch(new DataChanged);
        } catch (Throwable $exception) {
            // The mutation has already succeeded. Realtime availability must
            // never change its response or cause the client to submit it again.
            try {
                Log::warning('Realtime data update could not be broadcast.', ['exception' => $exception]);
            } catch (Throwable) {
                // Preserve the successful response even when logging is unavailable.
            }
        }
    }
}
