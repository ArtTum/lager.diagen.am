<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\TransferRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TransferService
{
    public function __construct(private readonly TransferRepository $transfers) {}

    public function create(User $actor, string $ip, array $data): array
    {
        $from = (int) $data['from_branch'];
        $to = (int) $data['to_branch'];
        abort_unless($actor->currentLocationId() === 0 || (int) $actor->branch_id === $from, 403, 'Կարող եք ուղարկել միայն ձեր պահեստից։');
        if (! $this->transfers->activeBranches([$from, $to])) {
            throw ValidationException::withMessages(['to_branch' => ['Երկու պահեստներն էլ պետք է ակտիվ լինեն։']]);
        }
        foreach ($data['items'] as $line) {
            if (! $this->transfers->activeProduct((int) $line['product_id'])) {
                throw ValidationException::withMessages(['items' => ['Ընտրված ապրանքներից մեկն ապաակտիվացված է։']]);
            }
        }

        return DB::transaction(function () use ($actor, $ip, $data, $from, $to): array {
            $number = 'ՏՂ-'.now()->format('ymd-His').'-'.strtoupper(bin2hex(random_bytes(3)));
            $first = $data['items'][0];
            $transfer = $this->transfers->create([
                'transfer_no' => $number, 'from_branch' => $from, 'to_branch' => $to, 'product_id' => $first['product_id'],
                'qty' => $first['qty'], 'status' => 'pending', 'requested_by' => $actor->id, 'reason' => trim($data['reason']), 'created_at' => now(),
            ]);
            $this->transfers->createItems((int) $transfer->id, $data['items']);
            $this->transfers->audit((int) $actor->id, 'Տեղափոխման հարցում', (int) $transfer->id, null,
                ['transfer_no' => $number, 'from' => $from, 'to' => $to, 'items' => $data['items']], $ip);

            return ['id' => (int) $transfer->id, 'transfer_no' => $number, 'status' => 'pending'];
        });
    }

    public function approve(int $id, User $actor, string $ip): string
    {
        abort_unless($actor->currentLocationId() === 0, 403, 'Տեղափոխումները հաստատվում են կենտրոնական պահեստում։');
        return DB::transaction(function () use ($id, $actor, $ip): string {
            $transfer = $this->transfers->lock($id);
            abort_unless($transfer && in_array($transfer->status, ['pending', 'stock_shortage'], true), 409, 'Տեղափոխումը հաստատման կամ պաշարի համալրման սպասման կարգավիճակում չէ։');
            $previousStatus = (string) $transfer->status;
            $items = $this->transfers->items($id, true);
            abort_if($items->isEmpty(), 409, 'Տեղափոխման ապրանքային տողերը բացակայում են։');
            $shortages = [];
            foreach ($items as $item) {
                $available = $this->transfers->freeStock((int) $transfer->from_branch, (int) $item->product_id, $id);
                if ((float) $item->qty > $available + 0.00001) {
                    $productLabel = trim(($item->product?->code ? $item->product->code.' · ' : '').($item->product?->name ?? 'Ապրանքի ID '.$item->product_id));
                    $shortages[] = '«'.$productLabel.'»՝ պահանջված '.rtrim(rtrim(number_format((float) $item->qty, 3, '.', ''), '0'), '.').', այժմ ազատ '.rtrim(rtrim(number_format($available, 3, '.', ''), '0'), '.');
                }
            }
            if ($shortages !== []) {
                $this->transfers->update($id, ['status' => 'stock_shortage', 'approved_by' => null]);
                $this->transfers->audit((int) $actor->id, 'Տեղափոխումը սպասում է պաշարի համալրմանը', $id,
                    ['status' => $previousStatus], ['status' => 'stock_shortage', 'պակասող_ապրանքներ' => $shortages], $ip);

                return 'Ազատ պաշարը դեռ չի բավարարում՝ '.implode('; ', $shortages).'. Տեղափոխումը տեղափոխվեց «Սպասում է պաշարի համալրման» փուլ։ Պաշարը համալրելուց հետո սեղմեք «Վերաստուգել պաշարը»։';
            }
            $this->transfers->update($id, ['status' => 'approved', 'approved_by' => $actor->id]);
            $this->transfers->audit((int) $actor->id, 'Տեղափոխումը հաստատվեց', $id, ['status' => $previousStatus], ['status' => 'approved'], $ip);

            return 'Տեղափոխումը հաստատվեց։ Այն դեռ պահեստից դուրս չի գրվել։';
        });
    }

    public function ship(int $id, User $actor, string $ip): void
    {
        DB::transaction(function () use ($id, $actor, $ip): void {
            $transfer = $this->transfers->lock($id);
            abort_unless($transfer && $transfer->status === 'approved', 409, 'Ուղարկել կարելի է միայն հաստատված տեղափոխումը։');
            abort_unless(
                $actor->currentLocationId() === 0
                    || (int) $actor->branch_id === (int) $transfer->from_branch,
                403,
                'Կարող եք ուղարկել միայն ձեր աղբյուր պահեստից։',
            );
            $fromLocation = $this->transfers->stockLocation((int) $transfer->from_branch);
            $toLocation = $this->transfers->stockLocation((int) $transfer->to_branch);
            foreach ($this->transfers->items($id, true) as $item) {
                $free = $this->transfers->freeStock((int) $transfer->from_branch, (int) $item->product_id, $id);
                if ((float) $item->qty > $free + 0.00001) {
                    $productLabel = trim(($item->product?->code ? $item->product->code.' · ' : '').($item->product?->name ?? 'Ապրանքի ID '.$item->product_id));
                    throw ValidationException::withMessages(['items' => ['«'.$productLabel.'» ապրանքի ազատ քանակը փոխվել է․ տեղափոխումը չի ուղարկվել։']]);
                }
                $remaining = (float) $item->qty;
                foreach ($this->transfers->availableLots((int) $item->product_id, $fromLocation) as $lot) {
                    if ($remaining <= 0.00001) {
                        break;
                    }
                    $take = min($remaining, (float) $lot->qty);
                    abort_unless($this->transfers->decreaseLot((int) $lot->id, $take) === 1, 409, 'LOT-ի մնացորդը փոխվել է․ տեղափոխումը չի ուղարկվել։');
                    $this->transfers->movement((int) $actor->id, 'transfer_sent', (int) $item->product_id, (int) $lot->id, $fromLocation, $toLocation,
                        $take, (float) $lot->unit_cost, (string) $transfer->transfer_no, (string) $transfer->reason);
                    $remaining -= $take;
                }
                if ($remaining > 0.00001) {
                    $productLabel = trim(($item->product?->code ? $item->product->code.' · ' : '').($item->product?->name ?? 'Ապրանքի ID '.$item->product_id));
                    throw ValidationException::withMessages(['items' => ['«'.$productLabel.'» ապրանքի քանակը բավարար չէ աղբյուր պահեստում։']]);
                }
            }
            $this->transfers->update($id, ['status' => 'shipped', 'shipped_by' => $actor->id]);
            $this->transfers->audit((int) $actor->id, 'Տեղափոխումն ուղարկվեց', $id, ['status' => 'approved'], ['status' => 'shipped'], $ip);
        });
    }

    public function receive(int $id, User $actor, string $ip): void
    {
        DB::transaction(function () use ($id, $actor, $ip): void {
            $transfer = $this->transfers->lock($id);
            abort_unless($transfer && $transfer->status === 'shipped', 409, 'Ստացման սպասող տեղափոխումը չի գտնվել։');
            abort_unless(
                (int) $actor->branch_id === (int) $transfer->to_branch,
                403,
                'Կարող եք ընդունել միայն ձեր պահեստ ուղարկված ապրանքը։',
            );
            $toLocation = $this->transfers->stockLocation((int) $transfer->to_branch);
            $fromLocation = $this->transfers->stockLocation((int) $transfer->from_branch);
            $sent = $this->transfers->sentLines((string) $transfer->transfer_no, $toLocation);
            if ($sent->isEmpty()) {
                abort(409, 'Ուղարկման շարժերի գրառումները բացակայում են։');
            }
            foreach ($sent as $line) {
                $source = $this->transfers->lot((int) $line->lot_id);
                abort_unless($source, 409, 'Սկզբնական LOT-ը չի գտնվել։');
                $destination = $this->transfers->matchingLot((int) $line->product_id, $toLocation, (string) $source->lot_no, $source->expires_on?->format('Y-m-d'));
                if ($destination) {
                    $this->transfers->increaseLot((int) $destination->id, (float) $line->qty);
                    $destinationLotId = (int) $destination->id;
                } else {
                    $destinationLotId = (int) $this->transfers->addLot([
                        'product_id' => $line->product_id, 'location_id' => $toLocation, 'lot_no' => $source->lot_no,
                        'expires_on' => $source->expires_on?->format('Y-m-d'), 'received_on' => now()->toDateString(), 'supplier_id' => $source->supplier_id,
                        'unit_cost' => $line->unit_cost, 'bin_location' => $source->bin_location, 'qty' => $line->qty,
                    ])->id;
                }
                $this->transfers->movement((int) $actor->id, 'branch_transfer', (int) $line->product_id, $destinationLotId, $fromLocation, $toLocation,
                    (float) $line->qty, (float) $line->unit_cost, (string) $transfer->transfer_no, 'Ստացող պահեստը հաստատեց ընդունումը');
            }
            $this->transfers->update($id, ['status' => 'completed', 'received_by' => $actor->id, 'accepted_at' => now()]);
            $this->transfers->audit((int) $actor->id, 'Տեղափոխման ստացումը հաստատվեց', $id, ['status' => 'shipped'], ['status' => 'completed'], $ip);
        });
    }
}
