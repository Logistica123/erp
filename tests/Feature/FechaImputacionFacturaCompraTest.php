<?php

namespace Tests\Feature;

use App\Erp\Services\FacturaCompraService;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ADDENDUM v1.9 — Tests críticos FI-01 a FI-05 sobre el helper
 * FacturaCompraService::resolverImputacion().
 */
class FechaImputacionFacturaCompraTest extends TestCase
{
    use DatabaseTransactions;

    private FacturaCompraService $svc;
    private User $userSinPerm;
    private User $userConPerm;
    private int $empresaId = 1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(FacturaCompraService::class);

        // userConPerm = primer user con rol que tenga el permiso.
        $userIdConPerm = DB::table('erp_usuario_rol as ur')
            ->join('erp_rol_permiso as rp', 'rp.rol_id', '=', 'ur.rol_id')
            ->join('erp_permisos as p', 'p.id', '=', 'rp.permiso_id')
            ->join('erp_usuario_perfil as up', 'up.id', '=', 'ur.usuario_perfil_id')
            ->where('p.codigo', 'compras.imputar_periodo_cerrado')
            ->value('up.user_id');
        $this->userConPerm = User::find($userIdConPerm) ?? User::first();

        // userSinPerm = user fresh sin asignación de roles → no tiene el permiso.
        $this->userSinPerm = User::factory()->create();
    }

    public function test_FI_01_misma_fecha_caso_normal(): void
    {
        $r = $this->svc->resolverImputacion('2026-04-15', '2026-04-15', $this->userConPerm);

        $this->assertSame('2026-04-15', $r['fecha_imputacion']);
        $this->assertSame(0, $r['imputacion_diferida']);
        $this->assertNotNull($r['periodo_id']);
    }

    public function test_FI_01_imputacion_null_default_emision(): void
    {
        $r = $this->svc->resolverImputacion('2026-04-15', null, $this->userConPerm);
        $this->assertSame('2026-04-15', $r['fecha_imputacion']);
        $this->assertSame(0, $r['imputacion_diferida']);
    }

    public function test_FI_02_imputacion_diferida_periodo_abierto(): void
    {
        $r = $this->svc->resolverImputacion('2026-04-28', '2026-05-03', $this->userConPerm);

        $this->assertSame('2026-05-03', $r['fecha_imputacion']);
        $this->assertSame(1, $r['imputacion_diferida']);
        $this->assertNotNull($r['periodo_id']);
    }

    public function test_FI_03_imputacion_anterior_a_emision_falla(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/FECHA_IMPUTACION_INVALIDA/');
        $this->svc->resolverImputacion('2026-04-15', '2026-04-10', $this->userConPerm);
    }

    public function test_FI_04_periodo_cerrado_sin_permiso_bloqueado(): void
    {
        // Cierro temporalmente abril para simular el caso.
        $periodoAbril = DB::table('erp_periodos as p')
            ->join('erp_ejercicios as e', 'e.id', '=', 'p.ejercicio_id')
            ->where('e.empresa_id', $this->empresaId)
            ->where('p.anio', 2026)->where('p.mes', 4)
            ->select('p.id', 'p.estado')->first();
        $this->assertNotNull($periodoAbril, 'Necesito período abril 2026 para el test');

        $estadoOrig = $periodoAbril->estado;
        DB::table('erp_periodos')->where('id', $periodoAbril->id)->update(['estado' => 'CERRADO']);

        try {
            $this->expectException(DomainException::class);
            $this->expectExceptionMessageMatches('/PERIODO_CERRADO_SIN_PERMISO/');
            $this->svc->resolverImputacion('2026-04-15', '2026-04-15', $this->userSinPerm);
        } finally {
            DB::table('erp_periodos')->where('id', $periodoAbril->id)->update(['estado' => $estadoOrig]);
        }
    }

    public function test_FI_05_periodo_cerrado_con_permiso_OK(): void
    {
        $periodoAbril = DB::table('erp_periodos as p')
            ->join('erp_ejercicios as e', 'e.id', '=', 'p.ejercicio_id')
            ->where('e.empresa_id', $this->empresaId)
            ->where('p.anio', 2026)->where('p.mes', 4)
            ->select('p.id', 'p.estado')->first();
        $this->assertNotNull($periodoAbril);

        $estadoOrig = $periodoAbril->estado;
        DB::table('erp_periodos')->where('id', $periodoAbril->id)->update(['estado' => 'CERRADO']);

        try {
            $r = $this->svc->resolverImputacion('2026-04-15', '2026-04-15', $this->userConPerm);
            $this->assertSame((int) $periodoAbril->id, (int) $r['periodo_id']);
            $this->assertSame('2026-04-15', $r['fecha_imputacion']);
        } finally {
            DB::table('erp_periodos')->where('id', $periodoAbril->id)->update(['estado' => $estadoOrig]);
        }
    }
}
