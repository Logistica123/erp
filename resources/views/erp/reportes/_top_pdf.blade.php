@php $fmt = fn ($n) => number_format((float) $n, 2, ',', '.'); @endphp
@if (count($rows) === 0)
    <div class="small" style="padding:8px;">Sin saldos abiertos.</div>
@else
    <table>
        <thead>
            <tr>
                <th>Auxiliar</th>
                <th class="r">Saldo total</th>
                @if ($verEfectivo)<th class="r">Efectivo</th>@endif
                <th class="r">Vencido</th>
                <th class="r">Ops</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $r)
                <tr>
                    <td>
                        <div>{{ $r['nombre'] ?? '—' }}</div>
                        @if (!empty($r['cuit']))
                            <div class="small" style="font-family:monospace;">{{ $r['cuit'] }}</div>
                        @endif
                    </td>
                    <td class="r"><strong>{{ $moneda }} ${{ $fmt($r['saldo_total']) }}</strong></td>
                    @if ($verEfectivo)<td class="r ef-col">${{ $fmt($r['saldo_efectivo'] ?? 0) }}</td>@endif
                    <td class="r {{ ($r['saldo_vencido'] ?? 0) > 0 ? 'danger' : '' }}">${{ $fmt($r['saldo_vencido'] ?? 0) }}</td>
                    <td class="r">{{ $r['cantidad'] ?? 0 }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
