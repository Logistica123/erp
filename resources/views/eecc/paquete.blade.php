<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>EECC — Ejercicio {{ $ejercicio->numero }}</title>
<style>
    @page { margin: 50px 40px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 10pt; color: #1d2533; }
    h1 { font-size: 16pt; color: #1e3a5f; border-bottom: 2px solid #1e3a5f; padding-bottom: 4px; }
    h2 { font-size: 12pt; color: #1e3a5f; margin-top: 20px; padding: 4px 0; border-bottom: 1px solid #1e3a5f; }
    h3 { font-size: 11pt; color: #2d5489; margin-top: 14px; margin-bottom: 4px; }
    .meta { font-size: 9pt; color: #555; margin-bottom: 12px; }
    table { width: 100%; border-collapse: collapse; margin-top: 4px; }
    th, td { padding: 3px 6px; }
    th { background: #eef3f8; text-align: left; border-bottom: 1px solid #c8d3df; }
    .num { text-align: right; font-variant-numeric: tabular-nums; }
    .total td { font-weight: bold; border-top: 1px solid #1e3a5f; background: #f0f5fb; }
    .nota-titulo { font-weight: bold; margin-top: 10px; }
    .firma { margin-top: 60px; border-top: 1px solid #555; padding-top: 6px; font-size: 9pt; }
    .alerta { background: #fff7e0; border: 1px solid #d4a017; padding: 8px; margin: 12px 0; font-size: 9pt; }
    .pagebreak { page-break-after: always; }
</style>
</head>
<body>

<h1>Estados Contables</h1>
<div class="meta">
    <strong>{{ $empresa->razon_social ?? '—' }}</strong><br>
    CUIT: {{ $empresa->cuit ?? '—' }}<br>
    Domicilio fiscal: {{ $empresa->domicilio_fiscal ?? '—' }}<br>
    Ejercicio Nº {{ $ejercicio->numero }} —
    Período: {{ $ejercicio->fecha_inicio }} al {{ $ejercicio->fecha_cierre }}<br>
    @if ($ejercicio->ajusta_por_inflacion)
        <em>Ajustados por inflación según RT 6 FACPCE.</em>
    @endif
</div>

@if (! ($paquete['estado'] ?? null) || ! $paquete['estado']['cierra'])
<div class="alerta">
    BORRADOR — NO CIERRA. {{ $paquete['estado']['motivo'] ?? '' }}
</div>
@endif

@if (in_array('BG', $incluir))
<h2>Balance General</h2>
@php $bg = $paquete['bg']; @endphp

<h3>Activo</h3>
<table>
    @foreach ($bg['activo']['rubros'] as $r)
    <tr><th colspan="2">{{ $r['rubro'] }}</th><th class="num">{{ number_format($r['total'], 2, ',', '.') }}</th></tr>
    @foreach ($r['cuentas'] as $c)
    <tr><td style="padding-left: 16px">{{ $c['codigo'] }}</td><td>{{ $c['nombre'] }}</td><td class="num">{{ number_format($c['saldo'], 2, ',', '.') }}</td></tr>
    @endforeach
    @endforeach
    <tr class="total"><td colspan="2">Total Activo</td><td class="num">{{ number_format($bg['activo']['total'], 2, ',', '.') }}</td></tr>
</table>

<h3>Pasivo</h3>
<table>
    @foreach ($bg['pasivo']['rubros'] as $r)
    <tr><th colspan="2">{{ $r['rubro'] }}</th><th class="num">{{ number_format($r['total'], 2, ',', '.') }}</th></tr>
    @foreach ($r['cuentas'] as $c)
    <tr><td style="padding-left: 16px">{{ $c['codigo'] }}</td><td>{{ $c['nombre'] }}</td><td class="num">{{ number_format($c['saldo'], 2, ',', '.') }}</td></tr>
    @endforeach
    @endforeach
    <tr class="total"><td colspan="2">Total Pasivo</td><td class="num">{{ number_format($bg['pasivo']['total'], 2, ',', '.') }}</td></tr>
</table>

<h3>Patrimonio Neto</h3>
<table>
    @foreach ($bg['patrimonio']['rubros'] as $r)
    <tr><th colspan="2">{{ $r['rubro'] }}</th><th class="num">{{ number_format($r['total'], 2, ',', '.') }}</th></tr>
    @foreach ($r['cuentas'] as $c)
    <tr><td style="padding-left: 16px">{{ $c['codigo'] }}</td><td>{{ $c['nombre'] }}</td><td class="num">{{ number_format($c['saldo'], 2, ',', '.') }}</td></tr>
    @endforeach
    @endforeach
    <tr class="total"><td colspan="2">Total Patrimonio Neto</td><td class="num">{{ number_format($bg['patrimonio']['total'], 2, ',', '.') }}</td></tr>
    <tr class="total"><td colspan="2">Total Pasivo + PN</td>
        <td class="num">{{ number_format($bg['pasivo']['total'] + $bg['patrimonio']['total'], 2, ',', '.') }}</td></tr>
</table>
@if (! $bg['verificacion']['cierra'])
<div class="alerta">Diferencia A − (P+PN): {{ number_format($bg['verificacion']['diferencia'], 2, ',', '.') }}</div>
@endif
<div class="pagebreak"></div>
@endif

@if (in_array('ER', $incluir))
<h2>Estado de Resultados</h2>
@php $er = $paquete['er']; @endphp
<table>
    <tr><th colspan="2">Ingresos</th><th class="num">{{ number_format($er['ingresos']['total'], 2, ',', '.') }}</th></tr>
    @foreach ($er['ingresos']['rubros'] as $r)
        <tr><td colspan="2" style="padding-left: 16px">{{ $r['rubro'] }}</td><td class="num">{{ number_format($r['total'], 2, ',', '.') }}</td></tr>
    @endforeach
    <tr><th colspan="2">Egresos</th><th class="num">({{ number_format($er['egresos']['total'], 2, ',', '.') }})</th></tr>
    @foreach ($er['egresos']['rubros'] as $r)
        <tr><td colspan="2" style="padding-left: 16px">{{ $r['rubro'] }}</td><td class="num">({{ number_format($r['total'], 2, ',', '.') }})</td></tr>
    @endforeach
    <tr class="total"><td colspan="2">Resultado bruto del ejercicio</td><td class="num">{{ number_format($er['resultado_bruto'], 2, ',', '.') }}</td></tr>
    <tr><td colspan="2" style="padding-left: 16px">Impuesto a las Ganancias</td><td class="num">({{ number_format($er['impuesto_ganancias'], 2, ',', '.') }})</td></tr>
    <tr class="total"><td colspan="2">Resultado del ejercicio</td><td class="num">{{ number_format($er['resultado_ejercicio'], 2, ',', '.') }}</td></tr>
</table>
<div class="pagebreak"></div>
@endif

@if (in_array('EPN', $incluir))
<h2>Estado de Evolución del Patrimonio Neto</h2>
@php $epn = $paquete['epn']; @endphp
<table>
    <thead>
        <tr><th>Cuenta</th><th class="num">Saldo inicial</th><th class="num">Aumentos</th><th class="num">Disminuciones</th><th class="num">Saldo final</th></tr>
    </thead>
    <tbody>
        @foreach ($epn['filas'] as $f)
        <tr>
            <td>{{ $f['codigo'] }} {{ $f['nombre'] }}</td>
            <td class="num">{{ number_format($f['saldo_inicial'], 2, ',', '.') }}</td>
            <td class="num">{{ number_format($f['aumentos'], 2, ',', '.') }}</td>
            <td class="num">{{ number_format($f['disminuciones'], 2, ',', '.') }}</td>
            <td class="num">{{ number_format($f['saldo_final'], 2, ',', '.') }}</td>
        </tr>
        @endforeach
        <tr class="total">
            <td>Totales</td>
            <td class="num">{{ number_format($epn['totales']['saldo_inicial'], 2, ',', '.') }}</td>
            <td class="num">{{ number_format($epn['totales']['aumentos'], 2, ',', '.') }}</td>
            <td class="num">{{ number_format($epn['totales']['disminuciones'], 2, ',', '.') }}</td>
            <td class="num">{{ number_format($epn['totales']['saldo_final'], 2, ',', '.') }}</td>
        </tr>
    </tbody>
</table>
<div class="pagebreak"></div>
@endif

@if (in_array('EFE', $incluir))
<h2>Estado de Flujo de Efectivo (método indirecto)</h2>
@php $efe = $paquete['efe']; @endphp
<table>
    <tr><td>Caja inicial</td><td class="num">{{ number_format($efe['caja_inicial'], 2, ',', '.') }}</td></tr>
    <tr><th colspan="2">Actividades operativas</th></tr>
    <tr><td style="padding-left: 16px">Resultado contable</td><td class="num">{{ number_format($efe['actividades_operativas']['resultado_contable'], 2, ',', '.') }}</td></tr>
    <tr><td style="padding-left: 16px">+ Amortizaciones</td><td class="num">{{ number_format($efe['actividades_operativas']['amortizaciones'], 2, ',', '.') }}</td></tr>
    <tr><td style="padding-left: 16px">− Variación de créditos</td><td class="num">({{ number_format($efe['actividades_operativas']['var_creditos'], 2, ',', '.') }})</td></tr>
    <tr><td style="padding-left: 16px">− Variación de bienes de cambio</td><td class="num">({{ number_format($efe['actividades_operativas']['var_bienes_cambio'], 2, ',', '.') }})</td></tr>
    <tr><td style="padding-left: 16px">+ Variación de deudas</td><td class="num">{{ number_format($efe['actividades_operativas']['var_deudas'], 2, ',', '.') }}</td></tr>
    <tr class="total"><td>Flujo neto operativo</td><td class="num">{{ number_format($efe['actividades_operativas']['flujo'], 2, ',', '.') }}</td></tr>
    <tr><td>Flujo neto inversión</td><td class="num">{{ number_format($efe['actividades_inversion']['flujo'], 2, ',', '.') }}</td></tr>
    <tr><td>Flujo neto financiación</td><td class="num">{{ number_format($efe['actividades_financiacion']['flujo'], 2, ',', '.') }}</td></tr>
    <tr class="total"><td>Variación de caja</td><td class="num">{{ number_format($efe['variacion_caja'], 2, ',', '.') }}</td></tr>
    <tr><td>Caja final</td><td class="num">{{ number_format($efe['caja_final'], 2, ',', '.') }}</td></tr>
</table>
<div class="pagebreak"></div>
@endif

@if (in_array('NOTAS', $incluir))
<h2>Notas a los Estados Contables</h2>
@foreach ($paquete['notas'] as $nota)
<p class="nota-titulo">Nota {{ $nota->numero }} — {{ $nota->titulo }}</p>
<p style="white-space: pre-wrap">{{ $nota->contenido }}</p>
@endforeach
@endif

@if (! empty($paquete['anexo_inflacion']) && ($paquete['anexo_inflacion']['aplica'] ?? false))
<h2>Anexo — Mecánica del ajuste por inflación (RT 6)</h2>
<table>
    <tr><td>Índice de cierre</td><td class="num">{{ $paquete['anexo_inflacion']['indice_cierre'] }}</td></tr>
    <tr><td>Coeficiente aplicado</td><td class="num">{{ $paquete['anexo_inflacion']['coeficiente'] }}</td></tr>
    <tr><td>Método</td><td>{{ $paquete['anexo_inflacion']['metodo'] }}</td></tr>
</table>
<p style="font-size: 9pt; color: #555">{{ $paquete['anexo_inflacion']['observaciones'] }}</p>
@endif

<div class="firma">
    @if (! empty($firmante))
        <p><strong>Profesional firmante:</strong> {{ $firmante }} — Matr. {{ $matricula ?? '—' }}</p>
    @endif
    <p style="font-size: 8pt; color: #888">
        Generado por ERP Logística Argentina SRL el {{ now()->format('d/m/Y H:i') }} hs.
    </p>
</div>

</body>
</html>
