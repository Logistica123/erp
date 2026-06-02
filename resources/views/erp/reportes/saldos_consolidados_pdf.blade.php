<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Saldos consolidados {{ $data['fecha_corte'] }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Helvetica Neue', Arial, sans-serif; font-size: 11px; margin: 16px 20px; color: #1a1f29; }
        h1 { font-size: 16px; margin: 0 0 4px; color: #0a3d62; }
        h2 { font-size: 12px; margin: 14px 0 4px; color: #0a3d62; border-bottom: 1px solid #d6dde6; padding-bottom: 2px; }
        .meta { font-size: 10.5px; color: #6b7588; margin-bottom: 10px; }
        .widgets { display: table; width: 100%; margin: 6px 0 10px; border-collapse: separate; border-spacing: 6px 0; }
        .widget { display: table-cell; border: 1px solid #d6dde6; padding: 8px; border-radius: 4px; width: 33%; text-align: center; vertical-align: top; }
        .widget label { display: block; font-size: 9.5px; text-transform: uppercase; color: #6b7588; margin-bottom: 2px; }
        .widget .v { font-size: 15px; font-weight: 700; }
        .widget .ef { font-size: 9.5px; color: #B45309; margin-top: 4px; padding-top: 4px; border-top: 1px solid #f0e0c0; }
        .pos-neg { color: #c0392b; }
        .pos-pos { color: #1e8449; }
        table { width: 100%; border-collapse: collapse; font-size: 10px; margin-bottom: 6px; }
        th, td { padding: 3px 6px; border-bottom: 1px solid #ecf0f5; text-align: left; }
        th { background: #f6f8fb; font-size: 9.5px; text-transform: uppercase; color: #6b7588; font-weight: 700; }
        td.r, th.r { text-align: right; }
        .grid2 { display: table; width: 100%; border-collapse: separate; border-spacing: 8px 0; }
        .col { display: table-cell; width: 50%; vertical-align: top; }
        .ef-col { color: #B45309; }
        .danger { color: #c0392b; }
        .small { font-size: 9.5px; color: #6b7588; }
    </style>
</head>
<body>
    <h1>Saldos consolidados</h1>
    <div class="meta">
        Fecha de corte: <strong>{{ $data['fecha_corte'] }}</strong> ·
        Moneda: <strong>{{ $data['moneda'] }}</strong> ·
        Calculado: {{ \Carbon\Carbon::parse($data['calculado_at'])->format('d/m/Y H:i') }}
    </div>

    @php
        $w = $data['widgets'];
        $pn = $w['posicion_neta'];
        $fmt = fn ($n) => number_format((float) $n, 2, ',', '.');
    @endphp

    <div class="widgets">
        <div class="widget">
            <label>Deudores por ventas</label>
            <div class="v" style="color:#1e8449">{{ $data['moneda'] }} ${{ $fmt($w['deudores_ventas']['total']) }}</div>
            <div class="small">{{ $w['deudores_ventas']['cantidad_operaciones'] }} operación{{ $w['deudores_ventas']['cantidad_operaciones'] === 1 ? '' : 'es' }}</div>
            @if ($verEfectivo && ($w['deudores_ventas']['efectivo'] ?? 0) > 0)
                <div class="ef">De los cuales en EFECTIVO: ${{ $fmt($w['deudores_ventas']['efectivo']) }} ({{ $w['deudores_ventas']['pct_efectivo'] }}%)</div>
            @endif
        </div>
        <div class="widget">
            <label>Deuda con proveedores</label>
            <div class="v" style="color:#c0392b">{{ $data['moneda'] }} ${{ $fmt($w['deuda_compras']['total']) }}</div>
            <div class="small">{{ $w['deuda_compras']['cantidad_operaciones'] }} operación{{ $w['deuda_compras']['cantidad_operaciones'] === 1 ? '' : 'es' }}</div>
            @if ($verEfectivo && ($w['deuda_compras']['efectivo'] ?? 0) > 0)
                <div class="ef">De los cuales en EFECTIVO: ${{ $fmt($w['deuda_compras']['efectivo']) }} ({{ $w['deuda_compras']['pct_efectivo'] }}%)</div>
            @endif
        </div>
        <div class="widget">
            <label>Posición neta</label>
            <div class="v {{ $pn >= 0 ? 'pos-pos' : 'pos-neg' }}">{{ $pn >= 0 ? '+' : '' }}{{ $data['moneda'] }} ${{ $fmt($pn) }}</div>
            <div class="small">{{ $pn >= 0 ? 'A favor' : 'En contra' }}</div>
        </div>
    </div>

    <div class="grid2">
        <div class="col">
            <h2>Aging deudores (ventas)</h2>
            @include('erp.reportes._aging_pdf', ['buckets' => $data['aging_deudores'], 'verEfectivo' => $verEfectivo, 'moneda' => $data['moneda']])
        </div>
        <div class="col">
            <h2>Aging acreedores (compras)</h2>
            @include('erp.reportes._aging_pdf', ['buckets' => $data['aging_acreedores'], 'verEfectivo' => $verEfectivo, 'moneda' => $data['moneda']])
        </div>
    </div>

    <div class="grid2">
        <div class="col">
            <h2>Top deudores</h2>
            @include('erp.reportes._top_pdf', ['rows' => $data['top_deudores'], 'verEfectivo' => $verEfectivo, 'moneda' => $data['moneda']])
        </div>
        <div class="col">
            <h2>Top acreedores</h2>
            @include('erp.reportes._top_pdf', ['rows' => $data['top_acreedores'], 'verEfectivo' => $verEfectivo, 'moneda' => $data['moneda']])
        </div>
    </div>
</body>
</html>
