<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Cierre diario {{ $dia->fecha->format('d/m/Y') }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Helvetica Neue', Arial, sans-serif; font-size: 12px; margin: 24px; color: #1a1f29; }
        h1 { font-size: 16px; margin: 0 0 12px; color: #0a3d62; }
        h2 { font-size: 13px; margin: 16px 0 6px; color: #0a3d62; border-bottom: 1px solid #d6dde6; padding-bottom: 3px; }
        .meta { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px; font-size: 11.5px; }
        .meta strong { display: inline-block; min-width: 130px; color: #6b7588; font-weight: 600; }
        table { width: 100%; border-collapse: collapse; font-size: 11px; }
        th, td { padding: 4px 8px; border-bottom: 1px solid #ecf0f5; text-align: left; }
        th { background: #f6f8fb; font-size: 10px; text-transform: uppercase; color: #6b7588; font-weight: 600; }
        td.r, th.r { text-align: right; }
        .saldos { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin: 14px 0; }
        .saldo { border: 1px solid #d6dde6; padding: 8px; border-radius: 4px; }
        .saldo label { display: block; font-size: 10.5px; text-transform: uppercase; color: #6b7588; margin-bottom: 2px; }
        .saldo .v { font-size: 13px; font-weight: 600; }
        .badge { display: inline-block; padding: 1px 6px; border-radius: 3px; background: #e8eef5; font-size: 9.5px; font-weight: 600; text-transform: uppercase; }
        .b-cer { background: #d4eedc; color: #0e5c2e; }
        .b-pen { background: #fde6c4; color: #7a4a00; }
        .b-ign { background: #e8eef5; color: #6b7588; }
        .b-eti { background: #e1d4f5; color: #4a2680; }
        .b-con { background: #d4eedc; color: #0e5c2e; }
        .footer { margin-top: 28px; font-size: 10.5px; color: #6b7588; text-align: center; padding-top: 10px; border-top: 1px solid #ecf0f5; }
        @media print { body { margin: 12px; } .no-print { display: none; } }
    </style>
</head>
<body>
    <h1>Cierre diario · Logística Argentina SRL · {{ $dia->fecha->format('d/m/Y') }}</h1>
    <div class="meta">
        <div>
            <div><strong>Estado:</strong>
                <span class="badge {{ $dia->estado === 'CERRADO' ? 'b-cer' : ($dia->estado === 'EN_PROCESO' ? 'b-pen' : 'b-ign') }}">{{ $dia->estado }}</span>
            </div>
            @if ($dia->cerrado_at)
                <div><strong>Cerrado:</strong> {{ $dia->cerrado_at->format('d/m/Y H:i') }}</div>
            @endif
            @if ($dia->cerrador)
                <div><strong>Por:</strong> {{ $dia->cerrador->name }}</div>
            @endif
        </div>
        <div>
            <div><strong>Movimientos:</strong> {{ $dia->total_movimientos }}</div>
            <div><strong>Conciliados:</strong> {{ $dia->total_conciliados }}</div>
            <div><strong>Pendientes:</strong> {{ $dia->total_pendientes }}</div>
            <div><strong>Ignorados:</strong> {{ $dia->total_ignorados }}</div>
        </div>
    </div>

    <h2>Saldos por cuenta</h2>
    <div class="saldos">
        @foreach ($cuentas as $c)
            @php
                $apertura = $dia->saldos_apertura[(string) $c->id] ?? $dia->saldos_apertura[$c->id] ?? null;
                $cierre   = $dia->saldos_cierre[(string) $c->id]   ?? $dia->saldos_cierre[$c->id]   ?? null;
                $delta = ($apertura !== null && $cierre !== null) ? round($cierre - $apertura, 2) : null;
            @endphp
            <div class="saldo">
                <label>{{ $c->codigo }} · {{ $c->nombre }}</label>
                <div class="v">${{ $cierre !== null ? number_format($cierre, 2, ',', '.') : '—' }}</div>
                <div style="font-size:10.5px;color:#6b7588;">
                    apertura ${{ $apertura !== null ? number_format($apertura, 2, ',', '.') : '—' }}
                    @if ($delta !== null)
                        · Δ <span style="color: {{ $delta >= 0 ? '#0e5c2e' : '#b00020' }}">${{ number_format($delta, 2, ',', '.') }}</span>
                    @endif
                </div>
            </div>
        @endforeach
    </div>

    <h2>Movimientos del día ({{ $movs->count() }})</h2>
    <table>
        <thead>
            <tr>
                <th style="width:90px">Cuenta</th>
                <th>Concepto</th>
                <th style="width:100px">Comprob.</th>
                <th class="r" style="width:90px">Débito</th>
                <th class="r" style="width:90px">Crédito</th>
                <th class="r" style="width:100px">Saldo</th>
                <th style="width:100px">Estado</th>
                <th style="width:120px">Etiqueta</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($movs as $m)
                <tr>
                    <td><code>{{ $m->cuentaBancaria?->codigo ?? '—' }}</code></td>
                    <td>{{ $m->concepto }}</td>
                    <td style="font-size:10.5px;color:#6b7588">{{ $m->comprobante_banco ?? '' }}</td>
                    <td class="r">{{ $m->debito > 0 ? number_format($m->debito, 2, ',', '.') : '' }}</td>
                    <td class="r">{{ $m->credito > 0 ? number_format($m->credito, 2, ',', '.') : '' }}</td>
                    <td class="r">{{ $m->saldo !== null ? number_format($m->saldo, 2, ',', '.') : '' }}</td>
                    <td>
                        @php $cls = match($m->estado) { 'CONCILIADO' => 'b-con', 'ETIQUETADO' => 'b-eti', 'IGNORADO' => 'b-ign', default => 'b-pen' }; @endphp
                        <span class="badge {{ $cls }}">{{ $m->estado }}</span>
                    </td>
                    <td style="font-size:10.5px;color:#6b7588">{{ $m->etiqueta_sugerida ?? '' }}</td>
                </tr>
            @empty
                <tr><td colspan="8" style="text-align:center;color:#6b7588;padding:20px">Sin movimientos en este día.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">
        Reporte generado el {{ now()->format('d/m/Y H:i') }} — Logística Argentina SRL · CUIT 30-71706098-5
    </div>
</body>
</html>
