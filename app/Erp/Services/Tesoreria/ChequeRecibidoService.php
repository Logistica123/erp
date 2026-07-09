<?php

namespace App\Erp\Services\Tesoreria;

use App\Erp\Models\Tesoreria\ChequeRecibido;
use App\Erp\Services\AsientoService;
use App\Erp\Support\AuditLogger;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ChequeRecibidoService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly AsientoService $asientos,
    ) {}

    // Cuentas del descuento de cheques (por código, resueltas en runtime).
    private const CUENTA_VALORES_AL_COBRO = '1.1.4.04'; // sale el cheque (haber)
    private const CUENTA_INTERESES = '5.4.01';          // Intereses Pagados
    private const CUENTA_COMISIONES = '5.4.02';         // Gastos y Comisiones Bancarias
    private const CUENTA_IVA_CF_21 = '1.1.6.01.21';     // IVA Crédito Fiscal 21%
    private const CUENTA_SELLADO = '5.5.08';            // Impuesto de Sellos
    private const CUENTA_PERC_IVA = '1.1.6.04';         // Percepciones IVA Sufridas
    private const CUENTA_PERC_IIBB = '1.1.6.15';        // Percepciones IIBB Sufridas (agregada)
    private const CUENTA_OTROS_IMP = '5.5.07';          // Otros Impuestos

    /**
     * Crea un cheque asociado a un recibo emitido. Llamado por ReciboService
     * cuando el medio de cobro es `CHEQUES_CARTERA`.
     *
     * @param  array{
     *   numero_cheque:string, banco_emisor:string, cuit_librador?:?string,
     *   librador_nombre?:?string, fecha_emision:string, fecha_pago:string,
     *   importe:float|string, observaciones?:?string,
     * }  $data
     */
    public function crearDesdeRecibo(int $reciboId, int $empresaId, array $data, int $userId): ChequeRecibido
    {
        foreach (['numero_cheque', 'banco_emisor', 'fecha_emision', 'fecha_pago', 'importe'] as $k) {
            if (empty($data[$k])) {
                throw new DomainException("CHEQUE_CAMPO_REQUERIDO: {$k}");
            }
        }
        $cheque = ChequeRecibido::create([
            'empresa_id' => $empresaId,
            'recibo_id' => $reciboId,
            'numero_cheque' => trim((string) $data['numero_cheque']),
            'banco_emisor' => trim((string) $data['banco_emisor']),
            'cuit_librador' => ! empty($data['cuit_librador'])
                ? preg_replace('/[^0-9]/', '', (string) $data['cuit_librador'])
                : null,
            'librador_nombre' => $data['librador_nombre'] ?? null,
            'fecha_emision' => $data['fecha_emision'],
            'fecha_pago' => $data['fecha_pago'],
            'importe' => round((float) $data['importe'], 2),
            'estado' => ChequeRecibido::ESTADO_EN_CARTERA,
            'observaciones' => $data['observaciones'] ?? null,
            'created_by_user_id' => $userId,
        ]);
        $this->audit->logEvento(
            accion: 'CHEQUE_RECIBIDO',
            modulo: 'tesoreria',
            descripcion: sprintf('Cheque %s · banco %s · $%.2f · vto %s · recibo #%d',
                $cheque->numero_cheque, $cheque->banco_emisor, $cheque->importe,
                (string) $cheque->fecha_pago, $reciboId),
            empresaId: $empresaId,
        );
        return $cheque;
    }

    public function listar(array $filtros): array
    {
        $q = DB::table('erp_cheques_recibidos as c')
            ->leftJoin('erp_recibos as r', 'r.id', '=', 'c.recibo_id')
            ->leftJoin('erp_auxiliares as a', 'a.id', '=', 'r.cliente_auxiliar_id')
            ->leftJoin('erp_cuentas_bancarias as cb', 'cb.id', '=', 'c.cuenta_bancaria_deposito_id')
            ->select([
                'c.*',
                'r.numero_correlativo as recibo_numero',
                'a.nombre as cliente_nombre',
                'cb.nombre as cuenta_deposito_nombre',
            ])
            // Orden: primero los NO cobrados (en cartera / depositados / vencidos),
            // después los resueltos (cobrados / rechazados). Dentro de cada grupo,
            // por vencimiento ascendente (los más viejos arriba).
            ->orderByRaw("(c.estado IN ('COBRADO', 'RECHAZADO', 'DESCONTADO', 'ENDOSADO')) asc")
            ->orderBy('c.fecha_pago')->orderBy('c.id');
        if (! empty($filtros['estado'])) $q->where('c.estado', $filtros['estado']);
        if (! empty($filtros['desde'])) $q->where('c.fecha_pago', '>=', $filtros['desde']);
        if (! empty($filtros['hasta'])) $q->where('c.fecha_pago', '<=', $filtros['hasta']);
        if (! empty($filtros['numero'])) $q->where('c.numero_cheque', 'like', '%' . $filtros['numero'] . '%');
        if (! empty($filtros['solo_vencidos_sin_cobrar'])) {
            $q->where('c.estado', ChequeRecibido::ESTADO_EN_CARTERA)
              ->where('c.fecha_pago', '<', today());
        }
        return $q->paginate((int) ($filtros['per_page'] ?? 50))->toArray();
    }

    /**
     * Cheques pendientes de cobro a una fecha de corte. Un cheque estaba
     * pendiente a la fecha X si:
     *   1) Ya existía: fecha de EMISIÓN del cheque <= X. (No se usa la fecha
     *      del recibo: muchos recibos se cargaron retroactivamente y dejaban
     *      afuera cheques que físicamente estaban en cartera — caso #5652.)
     *   2) Su fecha de cobro EFECTIVA es posterior a X:
     *      - cobrado/descontado/endosado → fecha real (fecha_acreditacion);
     *      - aún sin cobrar → estimada = vencimiento + 1 día.
     * Los RECHAZADOS se excluyen (no son cobrables).
     *
     * @return array{cheques: list<array<string,mixed>>, cant:int, total:float, fecha:string}
     */
    public function pendientesAFecha(string $fecha): array
    {
        $rows = DB::table('erp_cheques_recibidos as c')
            ->leftJoin('erp_recibos as r', 'r.id', '=', 'c.recibo_id')
            ->leftJoin('erp_auxiliares as a', 'a.id', '=', 'r.cliente_auxiliar_id')
            ->where('c.estado', '!=', ChequeRecibido::ESTADO_RECHAZADO)
            ->where('c.fecha_emision', '<=', $fecha)
            ->whereRaw('COALESCE(c.fecha_acreditacion, DATE_ADD(c.fecha_pago, INTERVAL 1 DAY)) > ?', [$fecha])
            ->orderBy('c.fecha_pago')->orderBy('c.id')
            ->get([
                'c.id', 'c.numero_cheque', 'c.banco_emisor', 'c.importe', 'c.estado',
                'c.fecha_emision', 'c.fecha_pago', 'c.fecha_acreditacion',
                'r.punto_venta as recibo_pv', 'r.numero as recibo_numero',
                'a.nombre as cliente_nombre',
            ]);

        $cheques = $rows->map(fn ($c) => [
            'id' => (int) $c->id,
            'numero_cheque' => $c->numero_cheque,
            'banco_emisor' => $c->banco_emisor,
            'cliente_nombre' => $c->cliente_nombre,
            'recibo' => $c->recibo_pv ? sprintf('%s-%s', $c->recibo_pv, $c->recibo_numero) : null,
            'importe' => (float) $c->importe,
            'fecha_recepcion' => substr((string) $c->fecha_emision, 0, 10),
            'fecha_vencimiento' => substr((string) $c->fecha_pago, 0, 10),
            // Cómo salió (o va a salir) de cartera, para contexto del corte.
            'fecha_cobro_efectiva' => $c->fecha_acreditacion
                ? substr((string) $c->fecha_acreditacion, 0, 10)
                : date('Y-m-d', strtotime($c->fecha_pago.' +1 day')),
            'cobro_es_estimado' => $c->fecha_acreditacion === null,
            'estado_actual' => $c->estado,
        ])->all();

        return [
            'fecha' => $fecha,
            'cheques' => $cheques,
            'cant' => count($cheques),
            'total' => round(array_sum(array_column($cheques, 'importe')), 2),
        ];
    }

    public function alertasVencidos(): array
    {
        return DB::table('erp_cheques_recibidos as c')
            ->leftJoin('erp_recibos as r', 'r.id', '=', 'c.recibo_id')
            ->leftJoin('erp_auxiliares as a', 'a.id', '=', 'r.cliente_auxiliar_id')
            ->where('c.estado', ChequeRecibido::ESTADO_EN_CARTERA)
            ->where('c.fecha_pago', '<', today())
            ->orderBy('c.fecha_pago')
            ->select([
                'c.id', 'c.numero_cheque', 'c.banco_emisor', 'c.importe', 'c.fecha_pago',
                'a.nombre as cliente_nombre', 'r.numero_correlativo as recibo_numero',
                DB::raw('DATEDIFF(CURRENT_DATE, c.fecha_pago) as dias_vencido'),
            ])
            ->limit(100)
            ->get()
            ->all();
    }

    /**
     * Registra el cobro del cheque (depósito + acreditación en un paso). Captura
     * la cuenta y la fecha de cobro real (los cheques acreditan al día siguiente
     * del vencimiento). Deja el cheque COBRADO.
     */
    public function depositar(int $chequeId, int $cuentaBancariaId, string $fechaCobro, int $userId, ?string $obs = null): ChequeRecibido
    {
        $cheque = ChequeRecibido::findOrFail($chequeId);
        if (! in_array($cheque->estado, [ChequeRecibido::ESTADO_EN_CARTERA, ChequeRecibido::ESTADO_VENCIDO], true)) {
            throw new DomainException("CHEQUE_ESTADO_INVALIDO: estado actual {$cheque->estado}, no se puede cobrar.");
        }
        $cheque->update([
            'estado' => ChequeRecibido::ESTADO_COBRADO,
            'cuenta_bancaria_deposito_id' => $cuentaBancariaId,
            'fecha_deposito' => $fechaCobro,
            'fecha_acreditacion' => $fechaCobro,
            'observaciones' => ($obs !== null && $obs !== '') ? $obs : $cheque->observaciones,
        ]);
        $this->audit->logEvento(
            accion: 'CHEQUE_COBRADO',
            modulo: 'tesoreria',
            descripcion: "Cheque #{$cheque->id} {$cheque->numero_cheque} cobrado en cuenta #{$cuentaBancariaId} ({$fechaCobro})",
            empresaId: $cheque->empresa_id,
        );
        return $cheque->fresh();
    }

    /**
     * Edita los datos del cobro ya registrado (fecha real de cobro, cuenta,
     * observación) sin cambiar el estado. Para corregir cargas erróneas.
     *
     * @param  array{fecha_cobro?:?string, cuenta_bancaria_id?:?int, observaciones?:?string}  $data
     */
    public function editarCobro(int $chequeId, array $data, int $userId): ChequeRecibido
    {
        $cheque = ChequeRecibido::findOrFail($chequeId);
        if (! in_array($cheque->estado, [ChequeRecibido::ESTADO_COBRADO, ChequeRecibido::ESTADO_DEPOSITADO], true)) {
            throw new DomainException("CHEQUE_ESTADO_INVALIDO: solo se editan cheques cobrados/depositados (actual {$cheque->estado}).");
        }
        $upd = [];
        if (! empty($data['fecha_cobro'])) {
            $upd['fecha_acreditacion'] = $data['fecha_cobro'];
            $upd['fecha_deposito'] = $data['fecha_cobro'];
        }
        if (! empty($data['cuenta_bancaria_id'])) {
            $upd['cuenta_bancaria_deposito_id'] = (int) $data['cuenta_bancaria_id'];
        }
        if (array_key_exists('observaciones', $data)) {
            $upd['observaciones'] = $data['observaciones'];
        }
        if ($upd) $cheque->update($upd);
        $this->audit->logEvento(
            accion: 'CHEQUE_COBRO_EDITADO',
            modulo: 'tesoreria',
            descripcion: "Cheque #{$cheque->id} {$cheque->numero_cheque}: editado cobro",
            empresaId: $cheque->empresa_id,
        );
        return $cheque->fresh();
    }

    /**
     * Descuenta el cheque (lo "vende" con quita): se cobra un neto menor al
     * importe porque descuentan intereses, IVA y comisión. Genera el asiento:
     *   D banco (neto) + D 5.4.01 intereses + D 5.4.02 comisión
     *   + D 1.1.6.01.21 IVA CF  /  H 1.1.4.04 Valores al Cobro (importe).
     * Siempre cuadra porque neto = importe − intereses − iva − comisión.
     *
     * @param  array{cuenta_bancaria_id:int, fecha:string, intereses:float|string,
     *               iva?:float|string, comision?:float|string, observaciones?:?string}  $data
     */
    public function descontar(int $chequeId, array $data, int $userId): ChequeRecibido
    {
        $cheque = ChequeRecibido::findOrFail($chequeId);
        if (! in_array($cheque->estado, [ChequeRecibido::ESTADO_EN_CARTERA, ChequeRecibido::ESTADO_VENCIDO], true)) {
            throw new DomainException("CHEQUE_ESTADO_INVALIDO: estado actual {$cheque->estado}, no se puede descontar.");
        }

        $importe = round((float) $cheque->importe, 2);
        $intereses = round((float) ($data['intereses'] ?? 0), 2);
        $iva = round((float) ($data['iva'] ?? 0), 2);
        $comision = round((float) ($data['comision'] ?? 0), 2);
        $sellado = round((float) ($data['sellado'] ?? 0), 2);
        $percIva = round((float) ($data['percepcion_iva'] ?? 0), 2);
        $percIibb = round((float) ($data['percepcion_iibb'] ?? 0), 2);
        $otros = round((float) ($data['otros'] ?? 0), 2);
        $entidad = trim((string) ($data['entidad'] ?? '')) ?: null;
        if (min($intereses, $iva, $comision, $sellado, $percIva, $percIibb, $otros) < 0) {
            throw new DomainException('DESCUENTO_INVALIDO: los conceptos de la quita no pueden ser negativos.');
        }
        $quita = round($intereses + $iva + $comision + $sellado + $percIva + $percIibb + $otros, 2);
        $neto = round($importe - $quita, 2);
        if ($neto <= 0) {
            throw new DomainException(sprintf(
                'DESCUENTO_EXCESIVO: la quita ($%.2f) no puede igualar o superar el importe del cheque ($%.2f).',
                $quita, $importe,
            ));
        }

        $empresaId = (int) $cheque->empresa_id;
        $ctaBancoId = (int) DB::table('erp_cuentas_bancarias')->where('id', (int) $data['cuenta_bancaria_id'])->value('cuenta_contable_id');
        if (! $ctaBancoId) throw new DomainException('CUENTA_SIN_CONTABLE: la cuenta bancaria no tiene cuenta contable.');

        $cta = function (string $codigo) use ($empresaId): int {
            $id = (int) DB::table('erp_cuentas_contables')
                ->where('empresa_id', $empresaId)->where('codigo', $codigo)->value('id');
            if (! $id) throw new DomainException("CUENTA_NO_EXISTE: falta la cuenta {$codigo}.");
            return $id;
        };
        $admiteCc = fn (int $id) => (bool) DB::table('erp_cuentas_contables')->where('id', $id)->value('admite_cc');

        $diarioId = DB::table('erp_diarios')->where('empresa_id', $empresaId)->where('codigo', 'TES')->value('id')
            ?? DB::table('erp_diarios')->where('empresa_id', $empresaId)->where('codigo', 'GEN')->value('id');
        if (! $diarioId) throw new DomainException('DIARIO_NO_EXISTE: falta el diario TES/GEN.');
        $ccGeneral = DB::table('erp_centros_costo')->where('empresa_id', $empresaId)->where('codigo', 'GENERAL')->value('id');

        $ctaValoresId = $cta(self::CUENTA_VALORES_AL_COBRO);
        $auxCliente = $cheque->recibo_id
            ? DB::table('erp_recibos')->where('id', $cheque->recibo_id)->value('cliente_auxiliar_id')
            : null;
        // Si la cuenta exige auxiliar (admite_auxiliar=1 es obligatorio en el
        // validador de asientos) y el tipo es compatible (Cliente o libre),
        // pasamos el cliente del cheque como trazabilidad.
        $auxPara = function (int $cuentaId) use ($auxCliente): ?int {
            $meta = DB::table('erp_cuentas_contables')->where('id', $cuentaId)->first(['admite_auxiliar', 'tipo_auxiliar']);
            if (! $meta || (int) $meta->admite_auxiliar !== 1) return null;
            $tipo = trim((string) ($meta->tipo_auxiliar ?? ''));
            if ($tipo !== '' && $tipo !== 'Cliente') return null;
            return $auxCliente ? (int) $auxCliente : null;
        };

        return DB::transaction(function () use ($cheque, $data, $userId, $empresaId, $diarioId, $ccGeneral, $cta, $admiteCc, $auxPara, $ctaBancoId, $ctaValoresId, $importe, $intereses, $iva, $comision, $sellado, $percIva, $percIibb, $otros, $entidad, $neto) {
            $glosaBase = sprintf('Descuento cheque #%s %s', $cheque->numero_cheque, $cheque->banco_emisor)
                .($entidad ? ' en '.$entidad : '');
            $movs = [[
                'cuenta_id' => $ctaBancoId,
                'centro_costo_id' => $admiteCc($ctaBancoId) ? $ccGeneral : null,
                'auxiliar_id' => $auxPara($ctaBancoId),
                'debe' => $neto, 'haber' => 0,
                'glosa' => $glosaBase.' — neto acreditado',
            ]];
            $conceptos = [
                [$intereses, self::CUENTA_INTERESES, 'intereses'],
                [$comision, self::CUENTA_COMISIONES, 'comisión'],
                [$iva, self::CUENTA_IVA_CF_21, 'IVA crédito fiscal'],
                [$sellado, self::CUENTA_SELLADO, 'sellado'],
                [$percIva, self::CUENTA_PERC_IVA, 'percepción IVA'],
                [$percIibb, self::CUENTA_PERC_IIBB, 'percepción IIBB'],
                [$otros, self::CUENTA_OTROS_IMP, 'otros impuestos'.(! empty($data['observaciones']) ? ' ('.$data['observaciones'].')' : '')],
            ];
            foreach ($conceptos as [$monto, $codigo, $etiqueta]) {
                if ($monto <= 0) continue;
                $id = $cta($codigo);
                $movs[] = ['cuenta_id' => $id, 'centro_costo_id' => $admiteCc($id) ? $ccGeneral : null,
                    'auxiliar_id' => $auxPara($id), 'debe' => $monto, 'haber' => 0, 'glosa' => $glosaBase.' — '.$etiqueta];
            }
            $movs[] = [
                'cuenta_id' => $ctaValoresId,
                'centro_costo_id' => $admiteCc($ctaValoresId) ? $ccGeneral : null,
                'auxiliar_id' => $auxPara($ctaValoresId),
                'debe' => 0, 'haber' => $importe,
                'glosa' => $glosaBase.' — sale de cartera',
            ];

            $asiento = $this->asientos->crearBorrador([
                'empresa_id' => $empresaId,
                'diario_id' => $diarioId,
                'fecha' => $data['fecha'],
                'glosa' => $glosaBase.sprintf(' (importe $%s, neto $%s)',
                    number_format($importe, 2, ',', '.'), number_format($neto, 2, ',', '.')),
                'origen' => 'COBRO',
                'origen_id' => $cheque->id,
                'origen_tabla' => 'erp_cheques_recibidos',
                'usuario_id' => $userId,
                'movimientos' => $movs,
            ]);
            $asiento = $this->asientos->contabilizar($asiento);

            $cheque->update([
                'estado' => ChequeRecibido::ESTADO_DESCONTADO,
                'cuenta_bancaria_deposito_id' => (int) $data['cuenta_bancaria_id'],
                'fecha_deposito' => $data['fecha'],
                'fecha_acreditacion' => $data['fecha'],
                'descuento_entidad' => $entidad,
                'descuento_intereses' => $intereses,
                'descuento_iva' => $iva,
                'descuento_comision' => $comision,
                'descuento_sellado' => $sellado,
                'descuento_percepcion_iva' => $percIva,
                'descuento_percepcion_iibb' => $percIibb,
                'descuento_otros' => $otros,
                'descuento_neto' => $neto,
                'asiento_id' => $asiento->id,
                'observaciones' => ($data['observaciones'] ?? null) ?: $cheque->observaciones,
            ]);

            $this->audit->logEvento(
                accion: 'CHEQUE_DESCONTADO',
                modulo: 'tesoreria',
                descripcion: sprintf('Cheque #%d %s descontado%s: importe $%.2f, neto $%.2f (int $%.2f, IVA $%.2f, com $%.2f, sell $%.2f, perc IIBB $%.2f, otros $%.2f), asiento #%d',
                    $cheque->id, $cheque->numero_cheque, $entidad ? ' en '.$entidad : '', $importe, $neto,
                    $intereses, $iva, $comision, $sellado, $percIibb, $otros, $asiento->id),
                empresaId: $empresaId,
            );
            return $cheque->fresh();
        });
    }

    /**
     * Endosa el cheque a un proveedor como pago de facturas de compra. El
     * cheque se entrega por su valor TOTAL, imputado contra una o más facturas
     * (la suma de imputaciones debe igualar el importe del cheque; una factura
     * puede quedar con pago parcial). Genera:
     *   - OP local PAGADA (medio CHEQUE_ENDOSADO) + op_items por factura → el
     *     saldo de la CC de proveedores baja por el circuito normal.
     *   - Asiento: D 2.1.1.01 Proveedores (aux proveedor) / H 1.1.4.04 Valores
     *     al Cobro (sale el cheque de cartera).
     *
     * @param  array{proveedor_auxiliar_id:int, fecha:string,
     *               imputaciones:list<array{factura_compra_id:int, importe:float|string}>,
     *               observaciones?:?string}  $data
     */
    public function endosar(int $chequeId, array $data, int $userId): ChequeRecibido
    {
        $cheque = ChequeRecibido::findOrFail($chequeId);
        if (! in_array($cheque->estado, [ChequeRecibido::ESTADO_EN_CARTERA, ChequeRecibido::ESTADO_VENCIDO], true)) {
            throw new DomainException("CHEQUE_ESTADO_INVALIDO: estado actual {$cheque->estado}, no se puede endosar.");
        }
        $empresaId = (int) $cheque->empresa_id;
        $importe = round((float) $cheque->importe, 2);

        $proveedor = DB::table('erp_auxiliares')->where('id', (int) $data['proveedor_auxiliar_id'])
            ->where('empresa_id', $empresaId)->first();
        if (! $proveedor) throw new DomainException('PROVEEDOR_NO_ENCONTRADO');

        $imputaciones = array_values($data['imputaciones'] ?? []);
        if (! $imputaciones) throw new DomainException('SIN_IMPUTACIONES: indicá contra qué facturas se endosa.');
        $suma = round(array_sum(array_map(fn ($i) => (float) $i['importe'], $imputaciones)), 2);
        if (abs($suma - $importe) > 0.01) {
            throw new DomainException(sprintf(
                'ENDOSO_DESCUADRA: el cheque se endosa por su total. Imputaciones $%s ≠ importe del cheque $%s.',
                number_format($suma, 2, ',', '.'), number_format($importe, 2, ',', '.'),
            ));
        }

        // Validar facturas: del proveedor, pagables y con saldo suficiente.
        // Nota: el circuito real de compras carga facturas en RECIBIDA y marca
        // pagos informalmente con op_externa/fecha_pago — por eso RECIBIDA es
        // pagable acá, pero se rechaza si ya está marcada como pagada afuera.
        foreach ($imputaciones as $i) {
            $f = DB::table('erp_facturas_compra')->where('id', (int) $i['factura_compra_id'])
                ->where('empresa_id', $empresaId)
                ->first(['id', 'auxiliar_id', 'estado', 'imp_total', 'numero', 'op_externa', 'fecha_pago']);
            if (! $f) throw new DomainException('FACTURA_NO_ENCONTRADA: #'.$i['factura_compra_id']);
            if ((int) $f->auxiliar_id !== (int) $proveedor->id) {
                throw new DomainException("FACTURA_DE_OTRO_PROVEEDOR: la factura #{$f->id} no es de {$proveedor->nombre}.");
            }
            if (! in_array($f->estado, ['RECIBIDA', 'CONTROLADA', 'PAGO_PARCIAL'], true)) {
                throw new DomainException("FACTURA_NO_PAGABLE: #{$f->id} está {$f->estado}.");
            }
            if ($f->op_externa || $f->fecha_pago) {
                throw new DomainException("FACTURA_YA_PAGADA: la factura #{$f->id} ya figura pagada (OP externa / fecha de pago).");
            }
            $pagado = (float) DB::table('erp_op_items as oi')
                ->join('erp_ordenes_pago as op', 'op.id', '=', 'oi.op_id')
                ->where('oi.tipo_item', 'FACTURA_COMPRA')
                ->where('oi.comprobante_id', $f->id)
                ->whereNotIn('op.estado', ['ANULADA', 'RECHAZADA'])
                ->sum('oi.importe');
            $saldo = round((float) $f->imp_total - $pagado, 2);
            if ((float) $i['importe'] > $saldo + 0.01) {
                throw new DomainException(sprintf(
                    'IMPUTA_MAS_QUE_SALDO: factura #%d tiene saldo $%s y se intenta imputar $%s.',
                    $f->id, number_format($saldo, 2, ',', '.'), number_format((float) $i['importe'], 2, ',', '.'),
                ));
            }
        }

        // Cuentas del asiento.
        $codigoProv = in_array(strtoupper((string) $proveedor->tipo), ['DISTRIBUIDOR', 'PERSONA', 'EMPLEADO'], true)
            ? '2.1.1.03' : '2.1.1.01';
        $ctaProvId = (int) DB::table('erp_cuentas_contables')->where('empresa_id', $empresaId)->where('codigo', $codigoProv)->value('id');
        $ctaValoresId = (int) DB::table('erp_cuentas_contables')->where('empresa_id', $empresaId)->where('codigo', self::CUENTA_VALORES_AL_COBRO)->value('id');
        if (! $ctaProvId || ! $ctaValoresId) throw new DomainException('CUENTA_NO_EXISTE: falta '.$codigoProv.' o '.self::CUENTA_VALORES_AL_COBRO.'.');
        $diarioId = DB::table('erp_diarios')->where('empresa_id', $empresaId)->where('codigo', 'TES')->value('id')
            ?? DB::table('erp_diarios')->where('empresa_id', $empresaId)->where('codigo', 'GEN')->value('id');
        if (! $diarioId) throw new DomainException('DIARIO_NO_EXISTE');
        $ccGeneral = DB::table('erp_centros_costo')->where('empresa_id', $empresaId)->where('codigo', 'GENERAL')->value('id');
        $admiteCc = fn (int $id) => (bool) DB::table('erp_cuentas_contables')->where('id', $id)->value('admite_cc');
        $auxCliente = $cheque->recibo_id
            ? DB::table('erp_recibos')->where('id', $cheque->recibo_id)->value('cliente_auxiliar_id')
            : null;
        $metaValores = DB::table('erp_cuentas_contables')->where('id', $ctaValoresId)->first(['admite_auxiliar', 'tipo_auxiliar']);
        $auxValores = ($metaValores && (int) $metaValores->admite_auxiliar === 1)
            ? ($auxCliente ? (int) $auxCliente : null) : null;

        return DB::transaction(function () use ($cheque, $data, $userId, $empresaId, $proveedor, $imputaciones, $importe, $ctaProvId, $ctaValoresId, $diarioId, $ccGeneral, $admiteCc, $auxValores) {
            $glosaBase = sprintf('Endoso cheque #%s %s a %s', $cheque->numero_cheque, $cheque->banco_emisor, $proveedor->nombre);

            // Asiento del endoso.
            $asiento = $this->asientos->crearBorrador([
                'empresa_id' => $empresaId,
                'diario_id' => $diarioId,
                'fecha' => $data['fecha'],
                'glosa' => $glosaBase,
                'origen' => 'PAGO',
                'origen_id' => $cheque->id,
                'origen_tabla' => 'erp_cheques_recibidos',
                'usuario_id' => $userId,
                'movimientos' => [
                    [
                        'cuenta_id' => $ctaProvId,
                        'centro_costo_id' => $admiteCc($ctaProvId) ? $ccGeneral : null,
                        'auxiliar_id' => (int) $proveedor->id,
                        'debe' => $importe, 'haber' => 0,
                        'glosa' => $glosaBase.' — cancela proveedor',
                    ],
                    [
                        'cuenta_id' => $ctaValoresId,
                        'centro_costo_id' => $admiteCc($ctaValoresId) ? $ccGeneral : null,
                        'auxiliar_id' => $auxValores,
                        'debe' => 0, 'haber' => $importe,
                        'glosa' => $glosaBase.' — sale de cartera',
                    ],
                ],
            ]);
            $asiento = $this->asientos->contabilizar($asiento);

            // OP local PAGADA con medio CHEQUE_ENDOSADO (baja el saldo de las
            // facturas por el circuito normal de la CC de proveedores).
            $ultimo = DB::table('erp_ordenes_pago')->where('empresa_id', $empresaId)->orderByDesc('id')->value('numero');
            $numero = 'OP-'.str_pad((string) (($ultimo ? (int) str_replace(['OP-', '-'], '', $ultimo) : 0) + 1), 6, '0', STR_PAD_LEFT);
            $opId = DB::table('erp_ordenes_pago')->insertGetId([
                'empresa_id' => $empresaId,
                'origen' => 'LOCAL',
                'numero' => $numero,
                'fecha' => $data['fecha'],
                'tipo' => 'PROVEEDOR',
                'auxiliar_id' => (int) $proveedor->id,
                'moneda_id' => 1, // ARS
                'cotizacion' => 1,
                'importe' => $importe,
                'importe_bruto' => $importe,
                'total_retenciones' => 0,
                'estado' => 'PAGADA',
                'fecha_pago' => $data['fecha'].' 00:00:00',
                'medio_pago' => 'CHEQUE_ENDOSADO',
                'concepto' => $glosaBase,
                'observaciones' => $data['observaciones'] ?? null,
                'creado_por_user_id' => $userId,
                'asiento_id' => $asiento->id,
                'contabilizada' => 1,
                'fecha_contabilizada' => now(),
                'contabilizada_por_user_id' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            foreach ($imputaciones as $idx => $i) {
                $nro = DB::table('erp_facturas_compra')->where('id', (int) $i['factura_compra_id'])->value('numero');
                DB::table('erp_op_items')->insert([
                    'op_id' => $opId,
                    'orden' => $idx + 1,
                    'tipo_item' => 'FACTURA_COMPRA',
                    'comprobante_id' => (int) $i['factura_compra_id'],
                    'concepto' => 'Endoso cheque #'.$cheque->numero_cheque.' — FC '.$nro,
                    'importe' => round((float) $i['importe'], 2),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $cheque->update([
                'estado' => ChequeRecibido::ESTADO_ENDOSADO,
                'fecha_acreditacion' => $data['fecha'],
                'asiento_id' => $asiento->id,
                'endoso_op_id' => $opId,
                'observaciones' => ($data['observaciones'] ?? null) ?: $cheque->observaciones,
            ]);

            $this->audit->logEvento(
                accion: 'CHEQUE_ENDOSADO',
                modulo: 'tesoreria',
                descripcion: sprintf('Cheque #%d %s endosado a %s por $%.2f (OP %s, asiento #%d, %d factura/s)',
                    $cheque->id, $cheque->numero_cheque, $proveedor->nombre, $importe, $numero, $asiento->id, count($imputaciones)),
                empresaId: $empresaId,
            );
            return $cheque->fresh();
        });
    }

    /**
     * Facturas de compra del proveedor contra las que se puede endosar un
     * cheque: RECIBIDA/CONTROLADA/PAGO_PARCIAL, sin marca de pago externo
     * (op_externa/fecha_pago) y con saldo formal (imp_total − op_items) > 0.
     *
     * @return list<array<string,mixed>>
     */
    public function facturasEndosables(int $empresaId, int $proveedorId): array
    {
        $facturas = DB::table('erp_facturas_compra as f')
            ->join('erp_tipos_comprobante as tc', 'tc.id', '=', 'f.tipo_comprobante_id')
            ->where('f.empresa_id', $empresaId)
            ->where('f.auxiliar_id', $proveedorId)
            ->whereIn('f.estado', ['RECIBIDA', 'CONTROLADA', 'PAGO_PARCIAL'])
            ->whereNull('f.op_externa')
            ->whereNull('f.fecha_pago')
            ->where('tc.signo', 1)
            ->orderBy('f.fecha_emision')
            ->limit(300)
            ->get(['f.id', 'tc.codigo_interno', 'tc.letra', 'f.punto_venta', 'f.numero',
                'f.fecha_emision', 'f.imp_total', 'f.estado']);

        $out = [];
        foreach ($facturas as $f) {
            $pagado = (float) DB::table('erp_op_items as oi')
                ->join('erp_ordenes_pago as op', 'op.id', '=', 'oi.op_id')
                ->where('oi.tipo_item', 'FACTURA_COMPRA')
                ->where('oi.comprobante_id', $f->id)
                ->whereNotIn('op.estado', ['ANULADA', 'RECHAZADA'])
                ->sum('oi.importe');
            $saldo = round((float) $f->imp_total - $pagado, 2);
            if ($saldo <= 0.009) continue;
            $out[] = [
                'factura_id' => (int) $f->id,
                'tipo' => trim($f->codigo_interno.' '.($f->letra ?? '')),
                'pto_vta' => (int) $f->punto_venta,
                'numero' => (int) $f->numero,
                'fecha_emision' => (string) $f->fecha_emision,
                'imp_total' => (float) $f->imp_total,
                'aplicado' => $pagado,
                'saldo' => $saldo,
            ];
        }
        return $out;
    }

    /**
     * Anula el cobro/depósito/descuento/rechazo y devuelve el cheque a
     * EN_CARTERA. Si fue DESCONTADO, revierte el asiento del descuento
     * (asiento espejo con debe/haber intercambiados).
     */
    public function anularCobro(int $chequeId, int $userId): ChequeRecibido
    {
        $cheque = ChequeRecibido::findOrFail($chequeId);
        if ($cheque->estado === ChequeRecibido::ESTADO_EN_CARTERA) {
            throw new DomainException("CHEQUE_YA_EN_CARTERA: el cheque ya está en cartera.");
        }
        DB::transaction(function () use ($cheque, $userId) {
            // Descuento y endoso generan asiento: al anular se revierte con un
            // asiento espejo (debe/haber intercambiados).
            $conAsiento = in_array($cheque->estado, [ChequeRecibido::ESTADO_DESCONTADO, ChequeRecibido::ESTADO_ENDOSADO], true);
            if ($conAsiento && $cheque->asiento_id) {
                $original = DB::table('erp_movimientos_asiento')->where('asiento_id', $cheque->asiento_id)->get();
                $origCab = DB::table('erp_asientos')->where('id', $cheque->asiento_id)->first(['diario_id']);
                $tipoOp = $cheque->estado === ChequeRecibido::ESTADO_ENDOSADO ? 'endoso' : 'descuento';
                $reversa = $this->asientos->crearBorrador([
                    'empresa_id' => (int) $cheque->empresa_id,
                    'diario_id' => (int) $origCab->diario_id,
                    'fecha' => today()->toDateString(),
                    'glosa' => sprintf('Reversa %s cheque #%s %s', $tipoOp, $cheque->numero_cheque, $cheque->banco_emisor),
                    'origen' => 'COBRO',
                    'origen_id' => $cheque->id,
                    'origen_tabla' => 'erp_cheques_recibidos',
                    'usuario_id' => $userId,
                    'movimientos' => $original->map(fn ($m) => [
                        'cuenta_id' => (int) $m->cuenta_id,
                        'centro_costo_id' => $m->centro_costo_id,
                        'auxiliar_id' => $m->auxiliar_id,
                        'debe' => (float) $m->haber, // intercambiados
                        'haber' => (float) $m->debe,
                        'glosa' => 'Reversa: '.$m->glosa,
                    ])->all(),
                ]);
                $this->asientos->contabilizar($reversa);
            }

            // Si fue endoso, anular la OP para que el saldo de las facturas de
            // compra vuelva a crecer (la CC de proveedores excluye OP ANULADA).
            if ($cheque->endoso_op_id) {
                DB::table('erp_ordenes_pago')->where('id', $cheque->endoso_op_id)->update([
                    'estado' => 'ANULADA',
                    'motivo_anulacion' => 'Endoso de cheque anulado',
                    'updated_at' => now(),
                ]);
            }

            $cheque->update([
                'estado' => ChequeRecibido::ESTADO_EN_CARTERA,
                'cuenta_bancaria_deposito_id' => null,
                'fecha_deposito' => null,
                'fecha_acreditacion' => null,
                'fecha_rechazo' => null,
                'motivo_rechazo' => null,
                'mov_bancario_id' => null,
                'descuento_entidad' => null,
                'descuento_intereses' => null,
                'descuento_iva' => null,
                'descuento_comision' => null,
                'descuento_sellado' => null,
                'descuento_percepcion_iva' => null,
                'descuento_percepcion_iibb' => null,
                'descuento_otros' => null,
                'descuento_neto' => null,
                'asiento_id' => null,
                'endoso_op_id' => null,
            ]);
        });
        $this->audit->logEvento(
            accion: 'CHEQUE_COBRO_ANULADO',
            modulo: 'tesoreria',
            descripcion: "Cheque #{$cheque->id} {$cheque->numero_cheque}: cobro anulado, vuelve a cartera",
            empresaId: $cheque->empresa_id,
        );
        return $cheque->fresh();
    }

    public function cobrar(int $chequeId, string $fechaAcreditacion, int $userId, ?int $movBancarioId = null): ChequeRecibido
    {
        $cheque = ChequeRecibido::findOrFail($chequeId);
        if (! in_array($cheque->estado, [ChequeRecibido::ESTADO_DEPOSITADO, ChequeRecibido::ESTADO_EN_CARTERA, ChequeRecibido::ESTADO_VENCIDO], true)) {
            throw new DomainException("CHEQUE_ESTADO_INVALIDO: estado actual {$cheque->estado}, no se puede cobrar.");
        }
        $cheque->update([
            'estado' => ChequeRecibido::ESTADO_COBRADO,
            'fecha_acreditacion' => $fechaAcreditacion,
            'mov_bancario_id' => $movBancarioId,
        ]);
        $this->audit->logEvento(
            accion: 'CHEQUE_COBRADO',
            modulo: 'tesoreria',
            descripcion: "Cheque #{$cheque->id} {$cheque->numero_cheque} cobrado/acreditado",
            empresaId: $cheque->empresa_id,
        );
        return $cheque->fresh();
    }

    public function rechazar(int $chequeId, string $motivo, int $userId): ChequeRecibido
    {
        $cheque = ChequeRecibido::findOrFail($chequeId);
        if ($cheque->estado === ChequeRecibido::ESTADO_COBRADO) {
            throw new DomainException("CHEQUE_YA_COBRADO: no se puede rechazar un cheque ya acreditado.");
        }
        if (strlen(trim($motivo)) < 5) {
            throw new DomainException("MOTIVO_CORTO: el motivo de rechazo debe tener al menos 5 caracteres.");
        }
        $cheque->update([
            'estado' => ChequeRecibido::ESTADO_RECHAZADO,
            'fecha_rechazo' => today(),
            'motivo_rechazo' => trim($motivo),
        ]);
        $this->audit->logEvento(
            accion: 'CHEQUE_RECHAZADO',
            modulo: 'tesoreria',
            descripcion: "Cheque #{$cheque->id} {$cheque->numero_cheque} rechazado: {$motivo}",
            empresaId: $cheque->empresa_id,
        );
        return $cheque->fresh();
    }

    /**
     * Marca como VENCIDO_NO_COBRADO los cheques EN_CARTERA con fecha_pago < hoy.
     * Para correr por cron diario. Devuelve la cantidad de cheques afectados.
     */
    public function marcarVencidos(): int
    {
        return DB::table('erp_cheques_recibidos')
            ->where('estado', ChequeRecibido::ESTADO_EN_CARTERA)
            ->where('fecha_pago', '<', today())
            ->update([
                'estado' => ChequeRecibido::ESTADO_VENCIDO,
                'updated_at' => now(),
            ]);
    }
}
