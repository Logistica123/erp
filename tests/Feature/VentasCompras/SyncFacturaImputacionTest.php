<?php

namespace Tests\Feature\VentasCompras;

use App\Erp\Services\Integracion\SyncFacturaCompraDistriAppService;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

/**
 * v1.56 — regla del período de imputación en el sync DistriApp→ERP:
 * mes de la fecha de emisión, salvo período CERRADO/BLOQUEADO → primer
 * día del primer mes siguiente ABIERTO.
 */
class SyncFacturaImputacionTest extends TestCase
{
    use DatabaseTransactions;

    private function resolver(string $fecha): string
    {
        $svc = app(SyncFacturaCompraDistriAppService::class);
        $m = new ReflectionMethod($svc, 'resolverFechaImputacion');
        $m->setAccessible(true);

        return $m->invoke($svc, $fecha);
    }

    private function setEstadoPeriodo(int $anio, int $mes, string $estado): void
    {
        $ejercicioId = DB::table('erp_ejercicios')->orderByDesc('id')->value('id');
        $inicio = sprintf('%04d-%02d-01', $anio, $mes);
        DB::table('erp_periodos')->updateOrInsert(
            ['anio' => $anio, 'mes' => $mes],
            [
                'estado' => $estado, 'ejercicio_id' => $ejercicioId,
                'fecha_inicio' => $inicio,
                'fecha_fin' => date('Y-m-t', strtotime($inicio)),
            ],
        );
    }

    public function test_periodo_abierto_mantiene_fecha_emision(): void
    {
        $this->setEstadoPeriodo(2026, 5, 'ABIERTO');
        $this->assertSame('2026-05-14', $this->resolver('2026-05-14'));
    }

    public function test_periodo_cerrado_corre_al_mes_siguiente_abierto(): void
    {
        $this->setEstadoPeriodo(2026, 5, 'CERRADO');
        $this->setEstadoPeriodo(2026, 6, 'ABIERTO');
        $this->assertSame('2026-06-01', $this->resolver('2026-05-14'));
    }

    public function test_saltea_varios_meses_cerrados(): void
    {
        $this->setEstadoPeriodo(2026, 4, 'CERRADO');
        $this->setEstadoPeriodo(2026, 5, 'BLOQUEADO');
        $this->setEstadoPeriodo(2026, 6, 'CERRADO');
        $this->setEstadoPeriodo(2026, 7, 'ABIERTO');
        $this->assertSame('2026-07-01', $this->resolver('2026-04-20'));
    }

    public function test_cruza_el_anio_a_periodo_sin_generar(): void
    {
        // Diciembre cerrado y enero del año siguiente sin fila en
        // erp_periodos → se considera abierto.
        $this->setEstadoPeriodo(2026, 12, 'CERRADO');
        DB::table('erp_periodos')->where('anio', 2027)->where('mes', 1)->delete();
        $this->assertSame('2027-01-01', $this->resolver('2026-12-15'));
    }

    public function test_sin_periodo_abierto_en_12_meses_lanza(): void
    {
        for ($m = 1; $m <= 12; $m++) {
            $this->setEstadoPeriodo(2026, $m, 'CERRADO');
        }
        for ($m = 1; $m <= 12; $m++) {
            $this->setEstadoPeriodo(2027, $m, 'CERRADO');
        }

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/SIN_PERIODO_ABIERTO/');
        $this->resolver('2026-06-10');
    }
}
