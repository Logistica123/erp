<?php

namespace App\Erp\Services\Eecc;

use App\Erp\Models\Ejercicio;
use App\Erp\Models\Impuestos\EeccEmision;
use App\Erp\Support\AuditLogger;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;

/**
 * Genera el paquete EECC en PDF usando dompdf + plantilla blade.
 *
 * Guarda el archivo en disk 'local' bajo `eecc/{empresa}/{anio}/EECC-{id}.pdf`
 * y registra la emisión en `erp_eecc_emisiones`.
 */
class EecCPdfService
{
    public function __construct(
        private readonly EecCService $eecc,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param array{
     *   incluir: array<int,string>,
     *   profesional_firmante?: string,
     *   matricula_firmante?: string,
     *   observaciones?: string,
     * } $opciones
     *
     * @return array{path:string, hash:string, emision_id:int}
     */
    public function generar(Ejercicio $ejercicio, User $usuario, array $opciones): array
    {
        $incluir = $opciones['incluir'] ?? ['BG', 'ER', 'EPN', 'EFE', 'NOTAS'];
        if (empty($incluir)) {
            throw new DomainException('EECC_INCLUIR_VACIO');
        }
        foreach ($incluir as $sec) {
            if (! in_array($sec, ['BG', 'ER', 'EPN', 'EFE', 'NOTAS'], true)) {
                throw new DomainException("EECC_SECCION_INVALIDA: {$sec}");
            }
        }

        $paquete = $this->eecc->armar($ejercicio, $incluir);
        $empresa = DB::table('erp_empresas')->where('id', $ejercicio->empresa_id)->first();

        $html = View::make('eecc.paquete', [
            'ejercicio' => $ejercicio,
            'empresa'   => $empresa,
            'paquete'   => $paquete,
            'incluir'   => $incluir,
            'firmante'  => $opciones['profesional_firmante'] ?? null,
            'matricula' => $opciones['matricula_firmante'] ?? null,
        ])->render();

        $pdf = Pdf::loadHTML($html)->setPaper('a4');
        $contenido = $pdf->output();
        $hash = hash('sha256', $contenido);
        $path = $this->pathArchivo($ejercicio);

        Storage::disk('local')->put($path, $contenido);

        $emision = EeccEmision::create([
            'ejercicio_id'         => $ejercicio->id,
            'formato'              => 'PDF',
            'incluir'              => $incluir,
            'path'                 => $path,
            'hash'                 => $hash,
            'profesional_firmante' => $opciones['profesional_firmante'] ?? null,
            'matricula_firmante'   => $opciones['matricula_firmante'] ?? null,
            'observaciones'        => $opciones['observaciones'] ?? null,
            'ajuste_por_inflacion' => (bool) $ejercicio->ajusta_por_inflacion,
            'generado_at'          => now(),
            'generado_user_id'     => $usuario->id,
        ]);

        $this->audit->log('generar_eecc_pdf', $emision, null, [
            'incluir' => $incluir, 'cierra' => $paquete['estado']['cierra'],
            'firmante' => $emision->profesional_firmante,
        ], "EECC PDF generado para ejercicio #{$ejercicio->id} (user #{$usuario->id})");

        return ['path' => $path, 'hash' => $hash, 'emision_id' => $emision->id];
    }

    private function pathArchivo(Ejercicio $ejercicio): string
    {
        $anio = (int) $ejercicio->fecha_cierre->format('Y');
        $ts = now()->format('Ymd_His');
        return "eecc/{$ejercicio->empresa_id}/{$anio}/EECC_{$ts}.pdf";
    }
}
