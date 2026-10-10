<!doctype html>
<html lang="hy">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: dejavusans, sans-serif; font-size: 9pt; color: #23324a; }
        table { width: 100%; border-collapse: collapse; }
        td, th { vertical-align: top; }
        h1 { margin: 0 0 7px; font-size: 23pt; color: #253b6a; }
        .order-number { margin: 0 0 19px; font-size: 11pt; color: #61708a; }
        .parties { margin-bottom: 18px; }
        .parties td { padding: 13px 15px; background: #f3f5fa; border: 1px solid #e0e5ee; }
        .label { color: #65718a; font-size: 8pt; }
        .party-name { margin: 6px 0 8px; font-size: 12pt; font-weight: bold; }
        .detail { margin: 4px 0; line-height: 1.5; }
        .metadata { margin-bottom: 19px; }
        .metadata td { padding: 5px 0; line-height: 1.5; }
        .items { table-layout: fixed; }
        .items thead { display: table-header-group; }
        .items th { background: #253b6a; color: #fff; font-size: 8pt; padding: 9px 7px; text-align: left; }
        .items td { border-bottom: 1px solid #dce2ec; padding: 10px 7px; line-height: 1.5; }
        .items tr { page-break-inside: avoid; }
        .items .numeric { text-align: right; white-space: nowrap; }
        .product-code { color: #65718a; font-size: 7.5pt; }
        .summary { margin-top: 15px; page-break-inside: avoid; }
        .summary td { padding: 11px 12px; }
        .total-label, .total-value { background: #eef2fa; font-size: 12pt; font-weight: bold; }
        .total-value { text-align: right; white-space: nowrap; }
        .note { margin-top: 20px; line-height: 1.7; }
        .signatures { margin-top: 32px; page-break-inside: avoid; }
        .signatures td { padding-right: 25px; font-size: 8pt; color: #65718a; }
        .signature-line { margin: 24px 0 8px; border-bottom: 1px solid #a8b3c5; }
    </style>
</head>
<body>
    <h1>Գնման պատվեր</h1>
    <p class="order-number">{{ $document['order_no'] }}</p>
    <table class="parties">
        <tr>
            <td width="48%">
                <div class="label">ՊԱՏՎԻՐԱՏՈՒ</div>
                <div class="party-name">Դիագեն Պլյուս</div>
                <div class="detail">Կենտրոնական պահեստ</div>
                @if ($document['creator'])<div class="detail">Կազմող՝ {{ $document['creator'] }}</div>@endif
            </td>
            <td width="52%">
                <div class="label">ՄԱՏԱԿԱՐԱՐ</div>
                <div class="party-name">{{ $document['supplier']['name'] ?? '-' }}</div>
                @foreach (['tax_id' => 'ՀՎՀՀ', 'address' => 'Հասցե', 'contact_name' => 'Կոնտակտային անձ', 'phone' => 'Հեռախոս', 'email' => 'Էլ. փոստ'] as $key => $label)
                    @if (!empty($document['supplier'][$key]))<div class="detail">{{ $label }}՝ {{ $document['supplier'][$key] }}</div>@endif
                @endforeach
            </td>
        </tr>
    </table>
    <table class="metadata">
        <tr><td width="50%"><span class="label">Պատվերի ամսաթիվ</span><br><b>{{ $document['ordered_on'] ?? '-' }}</b></td><td><span class="label">Սպասվող մուտք</span><br><b>{{ $document['expected_on'] ?? '-' }}</b></td></tr>
        <tr><td><span class="label">Կարգավիճակ</span><br><b>{{ $document['status'] }}</b></td><td><span class="label">Արժույթ</span><br><b>ՀՀ դրամ (AMD)</b></td></tr>
        @if ($document['approved_at'] || !empty($document['supplier']['contract_no']))
            <tr><td>@if ($document['approved_at'])<span class="label">Հաստատվել է</span><br>{{ $document['approved_at'] }}@endif</td><td>@if (!empty($document['supplier']['contract_no']))<span class="label">Պայմանագիր</span><br>{{ $document['supplier']['contract_no'] }}@endif</td></tr>
        @endif
    </table>
    <table class="items">
        <thead><tr><th width="5%">N</th><th width="36%">Ապրանք</th><th width="9%">Միավոր</th><th class="numeric" width="13%">Քանակ</th><th class="numeric" width="18%">Միավորի գին</th><th class="numeric" width="19%">Գումար</th></tr></thead>
        <tbody>
            @forelse ($document['lines'] as $line)
                <tr><td>{{ $loop->iteration }}</td><td><b>{{ $line['name'] }}</b>@if ($line['code'])<br><span class="product-code">{{ $line['code'] }}</span>@endif</td><td>{{ $line['unit'] }}</td><td class="numeric">{{ $number($line['qty'], true) }}</td><td class="numeric">{{ $number($line['unit_cost']) }}</td><td class="numeric">{{ $number($line['amount']) }}</td></tr>
            @empty
                <tr><td colspan="6">Պատվերի ապրանքներ չկան։</td></tr>
            @endforelse
        </tbody>
    </table>
    <table class="summary"><tr><td width="42%" class="label">Ապրանքային տողեր՝ {{ count($document['lines']) }}</td><td width="28%" class="total-label">Ընդհանուր</td><td width="30%" class="total-value">{{ $number($document['total']) }} AMD</td></tr></table>
    @if ($document['note'])<div class="note"><b>Նշում</b><br>{!! nl2br(e($document['note'])) !!}</div>@endif
    <table class="signatures"><tr><td width="50%"><div class="signature-line"></div>Պատվիրատուի ստորագրություն</td><td width="50%"><div class="signature-line"></div>Մատակարարի ստորագրություն</td></tr></table>
</body>
</html>
