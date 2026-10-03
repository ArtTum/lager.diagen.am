<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\NotificationRepository;
use App\Support\WorkflowStatus;
use Illuminate\Validation\ValidationException;

class NotificationService
{
    public function __construct(private readonly NotificationRepository $notifications) {}

    public function index(User $actor): array
    {
        $location = (int) $actor->currentLocationId();
        $items = [];
        $add = static function (string $title, string $detail, string $link, string $tone = 'amber', ?string $identity = null) use (&$items): void {
            $items[] = ['title' => $title, 'detail' => $detail, 'link' => $link, 'tone' => $tone,
                'key' => sha1($identity ?? $title.'|'.$detail.'|'.$link)];
        };

        if ($actor->hasPermissionCode('stock.view')) {
            foreach ($this->notifications->currentStock($location) as $row) {
                $qty = (float) $row->qty;
                if ($qty <= (float) $row->min_qty) {
                    $add($qty <= 0 ? 'Ապրանքը զրոյական մնացորդ ունի' : 'Պահանջվում է գնում կատարել',
                        "{$row->code} · {$row->name} · մնացորդ՝ {$qty} / MIN {$row->min_qty}", '/stock', $qty <= 0 ? 'red' : 'amber');
                } else {
                    $add('Պաշարը գերազանցում է առավելագույն շեմը', "{$row->code} · {$row->name} · մնացորդ՝ {$qty} / MAX {$row->max_qty}", '/stock', 'violet');
                }
            }
        }
        if ($actor->hasPermissionCode('expiry.view')) {
            foreach ($this->notifications->expiringLots($location) as $row) {
                $days = now()->startOfDay()->diffInDays($row->expires_on, false);
                $label = $days < 0 ? 'Ժամկետանց ապրանք' : ($days <= 7 ? 'Պիտանելիության ժամկետին մնացել է մինչև 7 օր' : ($days <= 30 ? 'Պիտանելիության ժամկետին մնացել է մինչև 30 օր' : ($days <= 60 ? 'Պիտանելիության ժամկետին մնացել է մինչև 60 օր' : ($days <= 90 ? 'Պիտանելիության ժամկետին մնացել է մինչև 90 օր' : 'Պիտանելիության ժամկետին մնացել է մինչև 180 օր'))));
                $add($label, "{$row->code} · {$row->name} · LOT {$row->lot_no} · ".($row->branch_name ?? 'Կենտրոնական պահեստ')." · {$row->qty} {$row->unit}", '/expiry', $days < 0 ? 'red' : ($days <= 30 ? 'amber' : 'blue'));
            }
        }
        if ($actor->hasPermissionCode('requests.view') && ($location > 0 || $actor->hasPermissionCode('requests.approve'))) {
            $title = $location > 0 ? 'Ձեր պահանջագիրը սպասում է ստուգման' : 'Նոր մասնաճյուղային պահանջագիր';
            foreach ($this->notifications->pendingRequests($location) as $row) {
                $add($title, "{$row->request_no} · {$row->branch_name} · ստուգման սպասող", '/requests', 'blue');
            }
        }
        if ($actor->hasPermissionCode('requests.view')) {
            foreach ($this->notifications->shippedRequests($location) as $row) {
                $add('Սպասվում է ստացման հաստատում', $row->request_no.' · ուղարկվել է '.optional($row->sent_at)->format('d.m.Y'), '/requests', 'violet');
            }
            foreach ($this->notifications->recentlyUpdatedRequests($location) as $row) {
                // Keep the original identity so wording changes preserve read state and sound deduplication.
                $legacyTitle = $row->status === 'rejected' ? 'Պահանջագիրը մերժվել է' : (in_array($row->status, ['approved', 'partially_approved'], true) ? 'Պահանջագիրը հաստատվել է' : 'Պահանջագրի ընթացքը թարմացվել է');
                $legacyDetail = $row->request_no.' · '.($row->rejection_reason ?: $row->status);
                $title = $row->status === 'partially_approved' ? 'Պահանջագիրը մասնակի է հաստատվել' : $legacyTitle;
                $detail = $row->request_no.' · '.($row->rejection_reason ?: WorkflowStatus::label('requests', $row->status));
                $add($title, $detail, '/requests', $row->status === 'rejected' ? 'red' : 'green', $legacyTitle.'|'.$legacyDetail.'|/requests');
            }
        }
        if ($actor->hasPermissionCode('inventory.view')) {
            foreach ($this->notifications->activeInventories($location, $actor->hasPermissionCode('inventory.approve')) as $row) {
                $add($row->status === 'counted' ? 'Հաստատման սպասող գույքագրում' : 'Գույքագրումը դեռ բաց է',
                    $row->inventory_no.' · '.($row->branch_name ?? 'Կենտրոնական պահեստ').' · սկսվել է '.optional($row->started_at)->format('d.m.Y'), '/inventory', $row->status === 'counted' ? 'violet' : 'amber');
            }
        }
        if ($location === 0 && $actor->hasPermissionCode('transfers.view') && $actor->hasPermissionCode('transfers.approve')) {
            foreach ($this->notifications->pendingTransfers() as $row) {
                $add('Հաստատման սպասող տեղափոխում', "{$row->transfer_no} · {$row->from_name} → {$row->to_name}", '/transfers', 'blue');
            }
        }
        if ($actor->hasPermissionCode('transfers.view')) {
            foreach ($this->notifications->incomingTransfers($location) as $row) {
                $add('Սպասվում է տեղափոխման ընդունում', $row->transfer_no.' · ուղարկել է '.$row->from_name, '/transfers', 'violet');
            }
        }

        $items = array_slice($items, 0, 300);
        $readKeys = $this->notifications->readKeys((int) $actor->id, array_column($items, 'key'));
        $read = array_fill_keys($readKeys, true);
        foreach ($items as &$item) {
            $item['read'] = isset($read[$item['key']]);
        }
        unset($item);

        return ['data' => $items, 'unread_count' => count(array_filter($items, static fn (array $item): bool => ! $item['read']))];
    }

    public function markRead(User $actor, string $key): void
    {
        $notice = collect($this->index($actor)['data'])->firstWhere('key', $key);
        if (! $notice) {
            throw ValidationException::withMessages(['key' => ['Ծանուցումն այլևս ակտիվ չէ։']]);
        }
        $this->notifications->markRead((int) $actor->id, $key);
    }
}
