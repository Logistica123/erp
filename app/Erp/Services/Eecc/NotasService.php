<?php

namespace App\Erp\Services\Eecc;

use App\Erp\Models\Ejercicio;
use App\Erp\Models\Impuestos\EeccNota;
use App\Erp\Support\AuditLogger;
use App\Models\User;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Notas estándar a los EECC (RN-62). Diez notas con plantillas iniciales
 * que el revisor puede editar antes de exportar.
 *
 *   Nota 1: Naturaleza jurídica y operaciones
 *   Nota 2: Bases de presentación (incluye RN-63 si ajusta_por_inflacion)
 *   Nota 3: Criterios de valuación
 *   Nota 4: Composición de Caja y Equivalentes
 *   Nota 5: Créditos por ventas (aging)
 *   Nota 6: Bienes de cambio
 *   Nota 7: Bienes de uso (anexo I FACPCE)
 *   Nota 8: Deudas
 *   Nota 9: Contingencias
 *   Nota 10: Cuentas de orden
 */
class NotasService
{
    public const PLANTILLAS = [
        1  => ['titulo' => 'Naturaleza jurídica y operaciones',
               'plantilla' => "La sociedad es una SRL constituida en la República Argentina con domicilio fiscal en {DOMICILIO}. Su actividad principal es {ACTIVIDAD}. Está inscripta ante AFIP bajo CUIT {CUIT}."],
        2  => ['titulo' => 'Bases de presentación',
               'plantilla' => "Los presentes Estados Contables se han preparado de acuerdo con las normas de exposición y valuación contenidas en las Resoluciones Técnicas de la FACPCE vigentes y aplicables a Ciudad Autónoma de Buenos Aires. {NOTA_RT6}"],
        3  => ['titulo' => 'Criterios de valuación',
               'plantilla' => "Los principales criterios de valuación aplicados son: caja y bancos a su valor nominal; créditos y deudas en moneda nacional a su valor nominal; bienes de uso al costo de adquisición ajustado por inflación cuando corresponde, neto de amortizaciones acumuladas."],
        4  => ['titulo' => 'Composición de Caja y Equivalentes',
               'plantilla' => "Caja en pesos: detalle por cuenta. Bancos: detalle por cuenta corriente y caja de ahorros. Inversiones a corto plazo: {DETALLE_INVERSIONES}."],
        5  => ['titulo' => 'Créditos por ventas',
               'plantilla' => "Composición y aging:\n- Corriente: {AGING_CORRIENTE}\n- 1-30 días: {AGING_1_30}\n- 31-60 días: {AGING_31_60}\n- 61-90 días: {AGING_61_90}\n- +90 días: {AGING_91_PLUS}"],
        6  => ['titulo' => 'Bienes de cambio',
               'plantilla' => "La sociedad presta servicios de logística y no posee inventarios significativos al cierre."],
        7  => ['titulo' => 'Bienes de uso',
               'plantilla' => "Composición por rubro al cierre del ejercicio. Vehículos, instalaciones y equipos según anexo I FACPCE — RT 9."],
        8  => ['titulo' => 'Deudas',
               'plantilla' => "Composición por tipo: comerciales, fiscales (IVA, Ganancias, IIBB), remuneraciones, financieras."],
        9  => ['titulo' => 'Contingencias',
               'plantilla' => "Al cierre no existen contingencias relevantes que requieran previsión contable. {NOTA_CONTINGENCIAS_ADIC}"],
        10 => ['titulo' => 'Cuentas de orden',
               'plantilla' => "Garantías otorgadas, valores en custodia y otros compromisos: {DETALLE_CO}."],
    ];

    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Devuelve las 10 notas para el ejercicio. Si no existen, las crea con
     * plantillas iniciales rellenando placeholders simples.
     *
     * @return Collection<int, EeccNota>
     */
    public function paraEjercicio(Ejercicio $ejercicio): Collection
    {
        return DB::transaction(function () use ($ejercicio) {
            $existentes = EeccNota::where('ejercicio_id', $ejercicio->id)
                ->orderBy('numero')->get()->keyBy('numero');

            $empresa = DB::table('erp_empresas')->where('id', $ejercicio->empresa_id)->first();

            foreach (self::PLANTILLAS as $nro => $tpl) {
                if (! $existentes->has($nro)) {
                    $contenido = $this->renderPlantilla($tpl['plantilla'], $ejercicio, $empresa);
                    $nota = EeccNota::create([
                        'ejercicio_id' => $ejercicio->id,
                        'numero'       => $nro,
                        'titulo'       => $tpl['titulo'],
                        'contenido'    => $contenido,
                    ]);
                    $existentes[$nro] = $nota;
                }
            }

            return $existentes->sortKeys()->values();
        });
    }

    public function actualizar(Ejercicio $ejercicio, int $numero, string $contenido, User $usuario): EeccNota
    {
        if (! isset(self::PLANTILLAS[$numero])) {
            throw new DomainException("EECC_NOTA_NUMERO_INVALIDO: 1..10 (recibido {$numero})");
        }

        $nota = EeccNota::where('ejercicio_id', $ejercicio->id)
            ->where('numero', $numero)->first();

        if (! $nota) {
            $nota = EeccNota::create([
                'ejercicio_id' => $ejercicio->id,
                'numero'       => $numero,
                'titulo'       => self::PLANTILLAS[$numero]['titulo'],
                'contenido'    => $contenido,
                'editado_user_id' => $usuario->id,
                'editado_at'   => now(),
            ]);
        } else {
            $nota->update([
                'contenido' => $contenido,
                'editado_user_id' => $usuario->id,
                'editado_at' => now(),
            ]);
        }

        $this->audit->log('editar_eecc_nota', $nota, null, ['numero' => $numero, 'len' => strlen($contenido)],
            "Nota EECC #{$numero} ejercicio #{$ejercicio->id} editada por user #{$usuario->id}");

        return $nota->fresh();
    }

    private function renderPlantilla(string $tpl, Ejercicio $ejercicio, $empresa): string
    {
        return strtr($tpl, [
            '{CUIT}'                       => $empresa?->cuit ?? '—',
            '{DOMICILIO}'                  => $empresa?->domicilio_fiscal ?? '—',
            '{ACTIVIDAD}'                  => 'logística y transporte',
            '{NOTA_RT6}'                   => $ejercicio->ajusta_por_inflacion
                ? 'Los presentes EECC se ajustan por inflación según RT 6 FACPCE.'
                : 'No se aplica ajuste por inflación en este ejercicio.',
            '{AGING_CORRIENTE}'            => '—',
            '{AGING_1_30}'                 => '—',
            '{AGING_31_60}'                => '—',
            '{AGING_61_90}'                => '—',
            '{AGING_91_PLUS}'              => '—',
            '{DETALLE_INVERSIONES}'        => '—',
            '{NOTA_CONTINGENCIAS_ADIC}'    => '',
            '{DETALLE_CO}'                 => '—',
        ]);
    }
}
