<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Recibo {{ $emp->legajo }} — {{ $liq->periodo }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Helvetica Neue', Arial, sans-serif; font-size: 12px; margin: 24px; color: #1a1f29; }
        h1 { font-size: 16px; margin: 0 0 12px; color: #0a3d62; }
        h2 { font-size: 13px; margin: 16px 0 6px; color: #0a3d62; border-bottom: 1px solid #d6dde6; padding-bottom: 3px; }
        .meta { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px; }
        .meta div { font-size: 11.5px; }
        .meta strong { display: inline-block; min-width: 90px; color: #6b7588; font-weight: 600; }
        table { width: 100%; border-collapse: collapse; font-size: 11px; }
        th, td { padding: 4px 8px; border-bottom: 1px solid #ecf0f5; text-align: left; }
        th { background: #f6f8fb; font-size: 10px; text-transform: uppercase; color: #6b7588; font-weight: 600; }
        td.r, th.r { text-align: right; }
        tr.haber td { color: #0a3d62; }
        tr.desc td { color: #b00020; }
        .totales { margin-top: 14px; display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
        .kpi { border: 1px solid #d6dde6; padding: 8px 10px; border-radius: 4px; }
        .kpi label { display: block; font-size: 10px; text-transform: uppercase; color: #6b7588; margin-bottom: 2px; }
        .kpi .v { font-size: 14px; font-weight: 600; }
        .neto { background: #0a3d62; color: white; }
        .neto label, .neto .v { color: white; }
        .footer { margin-top: 28px; font-size: 10.5px; color: #6b7588; text-align: center; padding-top: 10px; border-top: 1px solid #ecf0f5; }
        .badge { display: inline-block; padding: 1px 6px; border-radius: 3px; background: #e8eef5; font-size: 9.5px; font-weight: 600; text-transform: uppercase; }
        .badge.formal { background: #d4eedc; color: #0e5c2e; }
        .badge.efectivo { background: #fde6c4; color: #7a4a00; }
        .badge.mt { background: #e1d4f5; color: #4a2680; }
        @media print {
            body { margin: 12px; }
            .no-print { display: none; }
        }
    </style>
</head>
<body>
    <h1>Recibo de Sueldo — Logística Argentina SRL</h1>
    <div class="meta">
        <div>
            <div><strong>Empleado:</strong> {{ $emp->apellido }}, {{ $emp->nombre }}</div>
            <div><strong>Legajo:</strong> {{ $emp->legajo }}</div>
            <div><strong>CUIL:</strong> {{ $emp->cuil ?? '—' }}</div>
            <div><strong>Categoría:</strong> {{ $emp->categoria?->nombre ?? '—' }}</div>
            <div><strong>Convenio:</strong> {{ $emp->categoria?->convenio?->nombre ?? $emp->convenio?->nombre ?? '—' }}</div>
        </div>
        <div>
            <div><strong>Período:</strong> {{ $liq->periodo }}</div>
            <div><strong>Tipo:</strong> {{ $liq->tipo }}</div>
            <div><strong>Estado:</strong> <span class="badge">{{ $liq->estado }}</span></div>
            <div><strong>Liquidación:</strong> #{{ $liq->id }}</div>
            <div><strong>Régimen:</strong> {{ $emp->regimen }}</div>
        </div>
    </div>

    <h2>Detalle</h2>
    <table>
        <thead>
            <tr>
                <th style="width:90px">Concepto</th>
                <th>Descripción</th>
                <th style="width:80px">Componente</th>
                <th class="r" style="width:60px">Cant.</th>
                <th class="r" style="width:90px">Unitario</th>
                <th class="r" style="width:90px">Base</th>
                <th class="r" style="width:100px">Haber</th>
                <th class="r" style="width:100px">Descuento</th>
            </tr>
        </thead>
        <tbody>
        @forelse ($items as $it)
            @php($signoH = $it->concepto->signo === 'HABER')
            <tr class="{{ $signoH ? 'haber' : 'desc' }}">
                <td><code>{{ $it->concepto->codigo }}</code></td>
                <td>{{ $it->concepto->nombre }} @if ($it->observaciones) <em style="color:#6b7588">— {{ $it->observaciones }}</em> @endif</td>
                <td><span class="badge {{ strtolower($it->componente) }}">{{ $it->componente }}</span></td>
                <td class="r">{{ $it->cantidad ? number_format($it->cantidad, 2, ',', '.') : '' }}</td>
                <td class="r">{{ $it->importe_unitario !== null ? number_format($it->importe_unitario, 2, ',', '.') : '' }}</td>
                <td class="r" style="color:#6b7588">{{ $it->base_calculo !== null ? number_format($it->base_calculo, 2, ',', '.') : '' }}</td>
                <td class="r">{{ $signoH ? number_format($it->importe, 2, ',', '.') : '' }}</td>
                <td class="r">{{ ! $signoH ? number_format($it->importe, 2, ',', '.') : '' }}</td>
            </tr>
        @empty
            <tr><td colspan="8" style="text-align:center; color:#6b7588; padding:20px">Sin ítems para este empleado.</td></tr>
        @endforelse
        </tbody>
    </table>

    <h2>Totales</h2>
    <div class="totales">
        <div class="kpi"><label>Haberes</label><div class="v">$ {{ number_format($totales['haberes'], 2, ',', '.') }}</div></div>
        <div class="kpi"><label>Descuentos</label><div class="v">$ {{ number_format($totales['descuentos'], 2, ',', '.') }}</div></div>
        <div class="kpi neto"><label>Neto a Pagar</label><div class="v">$ {{ number_format($totales['neto'], 2, ',', '.') }}</div></div>
    </div>

    <div class="totales" style="margin-top:8px">
        <div class="kpi"><label>Componente Formal (F.931)</label><div class="v">$ {{ number_format($totales['formal'], 2, ',', '.') }}</div></div>
        @if ($verEfectivos)
            <div class="kpi"><label>Componente Efectivo</label><div class="v">$ {{ number_format($totales['efectivo'], 2, ',', '.') }}</div></div>
        @else
            <div class="kpi" style="opacity:.5"><label>Componente Efectivo</label><div class="v" style="font-size:10px;font-weight:400">— oculto —</div></div>
        @endif
        <div class="kpi"><label>Componente MT</label><div class="v">$ {{ number_format($totales['mt'], 2, ',', '.') }}</div></div>
    </div>

    <div class="footer">
        Recibo generado el {{ now()->format('d/m/Y H:i') }} — Liquidación #{{ $liq->id }} · {{ $liq->estado }}
        <br>Logística Argentina SRL · CUIT 30-71706098-5
    </div>
</body>
</html>
