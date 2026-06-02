@php
    $fmt = fn ($n) => number_format((float) $n, 2, ',', '.');
    $labels = [
        'corriente' => 'Corriente', '1_30' => '1-30 d',
        '31_60' => '31-60 d', '61_90' => '61-90 d', 'mas_90' => '>90 d',
    ];
    $colorMap = ['mas_90' => 'danger'];
    $total = array_sum(array_map(fn ($b) => $b['total'], $buckets));
@endphp
<table>
    <thead>
        <tr>
            <th>Bucket</th>
            <th class="r">Total</th>
            @if ($verEfectivo)<th class="r">Efectivo</th>@endif
            <th class="r">%</th>
            <th class="r">Qty</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($buckets as $k => $b)
            <tr>
                <td class="{{ $colorMap[$k] ?? '' }}">{{ $labels[$k] ?? $k }}</td>
                <td class="r">{{ $moneda }} ${{ $fmt($b['total']) }}</td>
                @if ($verEfectivo)<td class="r ef-col">${{ $fmt($b['efectivo'] ?? 0) }}</td>@endif
                <td class="r">{{ $b['pct'] ?? 0 }}%</td>
                <td class="r">{{ $b['cantidad'] ?? 0 }}</td>
            </tr>
        @endforeach
        <tr style="font-weight:700; background:#f6f8fb;">
            <td>TOTAL</td>
            <td class="r">{{ $moneda }} ${{ $fmt($total) }}</td>
            @if ($verEfectivo)<td></td>@endif
            <td class="r">100%</td>
            <td></td>
        </tr>
    </tbody>
</table>
