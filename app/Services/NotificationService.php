<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\NotificationRepository;
use App\Support\WorkflowStatus;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class NotificationService
{
    public function __construct(private readonly NotificationRepository $notifications) {}

    public function index(User $actor): array
    {
        return $this->feed($actor);
    }

    private function feed(User $actor, bool $keepReadAliases = false): array
    {
        $location = (int) $actor->currentLocationId();
        $items = [];
        $time = static fn ($value): int => $value ? Carbon::parse($value)->getTimestamp() : 0;
        $add = static function (string $title, string $detail, string $link, string $tone = 'amber', ?string $identity = null, int $occurredAt = 0, array $readAliases = []) use (&$items): void {
            $items[] = ['title' => $title, 'detail' => $detail, 'link' => $link, 'tone' => $tone,
                'key' => sha1($identity ?? $title.'|'.$detail.'|'.$link),
                '_priority' => in_array($link, ['/stock', '/expiry'], true) ? 0 : 1,
                '_time' => $occurredAt, '_order' => count($items), '_read_aliases' => $readAliases];
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
        if ($actor->hasPermissionCode('requests.view')) {
            $events = $this->notifications->requestEvents($location, (int) $actor->id);
            $pendingEvents = []; $shippedEvents = [];
            foreach ($events as $event) {
                $status = $event->after_data['status'];
                if (in_array($status, ['sent', 'review'], true)) $pendingEvents[$event->entity_id] = true;
                if ($status === 'shipped') $shippedEvents[$event->entity_id] = true;
                $title = match ($status) {
                    'sent' => $location > 0 ? 'Նոր պահանջագիր Ձեր մասնաճյուղի համար' : 'Նոր մասնաճյուղային պահանջագիր',
                    'review' => 'Պահանջագրի ստուգումը սկսվել է',
                    'approved' => 'Պահանջագիրը հաստատվել է',
                    'partially_approved' => 'Պահանջագիրը մասնակի է հաստատվել',
                    'rejected' => 'Պահանջագիրը մերժվել է',
                    'collecting' => 'Պահանջագրի հավաքումը սկսվել է',
                    'ready_to_ship' => 'Պահանջագիրը պատրաստ է ուղարկման',
                    'shipped' => 'Պահանջագիրն ուղարկվել է',
                    'received' => 'Պահանջագրի ստացումը հաստատվել է',
                    'closed' => 'Պահանջագիրը փակվել է',
                    'cancelled' => 'Պահանջագիրը չեղարկվել է',
                };
                $reason = $status === 'rejected' ? ($event->after_data['rejection_reason'] ?? $event->rejection_reason) : null;
                $detail = $event->request_no.' · '.$event->branch_name.' · '.($reason ?: WorkflowStatus::label('requests', $status));
                $aliases = [];
                if ($status === 'sent') {
                    $oldTitle = $location > 0 ? 'Ձեր պահանջագիրը սպասում է ստուգման' : 'Նոր մասնաճյուղային պահանջագիր';
                    $aliases[] = sha1($oldTitle.'|'.$event->request_no.' · '.$event->branch_name.' · ստուգման սպասող|/requests');
                } elseif (in_array($status, ['approved', 'partially_approved', 'rejected', 'received'], true)) {
                    $oldTitle = $status === 'rejected' ? 'Պահանջագիրը մերժվել է' : ($status === 'received' ? 'Պահանջագրի ընթացքը թարմացվել է' : 'Պահանջագիրը հաստատվել է');
                    $aliases[] = sha1($oldTitle.'|'.$event->request_no.' · '.($reason ?: $status).'|/requests');
                } elseif ($status === 'shipped') {
                    $aliases[] = sha1('Սպասվում է ստացման հաստատում|'.$event->request_no.' · ուղարկվել է '.$event->created_at->format('d.m.Y').'|/requests');
                }
                $add($title, $detail, '/requests', in_array($status, ['rejected', 'cancelled'], true) ? 'red' : ($status === 'shipped' ? 'violet' : 'blue'),
                    'stock_requests|event|'.$event->id, $event->created_at->getTimestamp(), $aliases);
            }
            // Active reminders also cover requests with old or absent audit
            // history, including the sender's own waiting requests.
            $title = $location > 0 ? 'Ձեր պահանջագիրը սպասում է ստուգման' : 'Նոր մասնաճյուղային պահանջագիր';
            foreach ($this->notifications->pendingRequests($location) as $row) {
                if (isset($pendingEvents[$row->id])) continue;
                $add($title, "{$row->request_no} · {$row->branch_name} · ստուգման սպասող", '/requests', 'blue', null, $time($row->notice_updated_at ?? $row->created_at ?? null));
            }
            foreach ($this->notifications->shippedRequests($location) as $row) {
                if (isset($shippedEvents[$row->id])) continue;
                $add('Սպասվում է ստացման հաստատում', $row->request_no.' · ուղարկվել է '.optional($row->sent_at)->format('d.m.Y'), '/requests', 'violet', null, $time($row->sent_at));
            }
        }
        if ($actor->hasPermissionCode('inventory.view')) {
            foreach ($this->notifications->activeInventories($location, $actor->hasPermissionCode('inventory.approve')) as $row) {
                $add($row->status === 'counted' ? 'Հաստատման սպասող գույքագրում' : 'Գույքագրումը դեռ բաց է',
                    $row->inventory_no.' · '.($row->branch_name ?? 'Կենտրոնական պահեստ').' · սկսվել է '.optional($row->started_at)->format('d.m.Y'), '/inventory', $row->status === 'counted' ? 'violet' : 'amber', null, $time($row->notice_updated_at ?? $row->started_at));
            }
        }
        if ($location === 0 && $actor->hasPermissionCode('transfers.view') && $actor->hasPermissionCode('transfers.approve')) {
            foreach ($this->notifications->pendingTransfers() as $row) {
                $add('Հաստատման սպասող տեղափոխում', "{$row->transfer_no} · {$row->from_name} → {$row->to_name}", '/transfers', 'blue', null, $time($row->notice_updated_at ?? $row->created_at ?? null));
            }
        }
        if ($actor->hasPermissionCode('transfers.view')) {
            foreach ($this->notifications->incomingTransfers($location) as $row) {
                $add('Սպասվում է տեղափոխման ընդունում', $row->transfer_no.' · ուղարկել է '.$row->from_name, '/transfers', 'violet', null, $time($row->notice_updated_at ?? $row->created_at ?? null));
            }
        }

        $keys = array_column($items, 'key');
        foreach ($items as $item) $keys = array_merge($keys, $item['_read_aliases']);
        $readKeys = $this->notifications->readKeys((int) $actor->id, array_values(array_unique($keys)));
        $read = array_fill_keys($readKeys, true);
        foreach ($items as &$item) {
            $item['read'] = isset($read[$item['key']]) || count(array_intersect($item['_read_aliases'], $readKeys)) > 0;
        }
        unset($item);
        // New actionable work precedes stock/expiry reminders in both the
        // eight-row bell preview and the bounded full feed.
        usort($items, static fn (array $a, array $b): int => ($a['read'] <=> $b['read'])
            ?: ($b['_priority'] <=> $a['_priority']) ?: ($b['_time'] <=> $a['_time']) ?: ($a['_order'] <=> $b['_order']));
        $items = array_slice($items, 0, 300);
        foreach ($items as &$item) {
            unset($item['_priority'], $item['_time'], $item['_order']);
            if (! $keepReadAliases) unset($item['_read_aliases']);
        }
        unset($item);

        return ['data' => $items, 'unread_count' => count(array_filter($items, static fn (array $item): bool => ! $item['read']))];
    }

    public function markRead(User $actor, string $key): void
    {
        $notice = collect($this->feed($actor, true)['data'])->first(static fn (array $item): bool => $item['key'] === $key || in_array($key, $item['_read_aliases'], true));
        if (! $notice) {
            throw ValidationException::withMessages(['key' => ['Ծանուցումն այլևս ակտիվ չէ։']]);
        }
        // Preserve read state when a seven-day event becomes an active reminder,
        // and allow an already-open old frontend to submit its legacy key.
        foreach (array_unique([$notice['key'], ...$notice['_read_aliases']]) as $readKey) {
            $this->notifications->markRead((int) $actor->id, $readKey);
        }
    }
}
