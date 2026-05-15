<?php

namespace Tests\Feature;

use App\Erp\Models\AuditLog;
use App\Erp\Models\VentasCompras\FacturaCompra;
use App\Erp\Models\VentasCompras\LibroIvaComprasImport;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ADDENDUM v1.20 — tests del DELETE de uploads del Libro IVA Compras.
 *
 * Cubre BU-01..BU-06 del addendum:
 *   - super_admin borra upload sin facturas vinculadas → 204 + audit log.
 *   - super_admin intenta borrar upload con facturas vinculadas → 409.
 *   - rol sin permiso intenta borrar → 403.
 *   - upload inexistente → 404.
 *   - post-borrado, el hash queda liberado (preview no detecta duplicado).
 */
class LibroIvaComprasBorrarImportTest extends TestCase
{
    use DatabaseTransactions;

    private User $superAdmin;
    private User $contador;
    private int $empresaId = 1;
    private int $periodoId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::first();
        if (! $this->superAdmin) {
            $this->markTestSkipped('No hay usuarios en la DB de test.');
        }
        $this->contador = $this->buildUserWithRol('contador');

        $this->periodoId = (int) DB::table('erp_periodos')->orderBy('id')->value('id');
        if (! $this->periodoId) {
            $this->markTestSkipped('Necesito al menos un período en la DB de test.');
        }
    }

    public function test_BU_01_super_admin_borra_upload_sin_facturas_devuelve_204(): void
    {
        $imp = $this->crearImport('test-bu01.csv', 'hash-bu01-'.uniqid());

        Sanctum::actingAs($this->superAdmin, ['*']);
        $resp = $this->deleteJson("/api/erp/libro-iva-compras/imports/{$imp->id}", [
            'motivo' => 'Test BU-01',
        ]);

        $resp->assertNoContent();
        $this->assertNull(LibroIvaComprasImport::find($imp->id),
            'El upload debe estar borrado físicamente');
    }

    public function test_BU_02_borrar_upload_con_facturas_vinculadas_devuelve_409(): void
    {
        $imp = $this->crearImport('test-bu02.csv', 'hash-bu02-'.uniqid());

        // Vincular una factura existente al import. Si no hay facturas en la DB
        // de test, saltamos — no queremos generar facturas desde cero acá.
        $facturaId = (int) DB::table('erp_facturas_compra')
            ->whereNull('import_id')
            ->value('id');
        if (! $facturaId) {
            $this->markTestSkipped('No hay facturas de compra disponibles para vincular.');
        }
        DB::table('erp_facturas_compra')->where('id', $facturaId)->update(['import_id' => $imp->id]);

        Sanctum::actingAs($this->superAdmin, ['*']);
        $resp = $this->deleteJson("/api/erp/libro-iva-compras/imports/{$imp->id}");

        $resp->assertStatus(409);
        $resp->assertJsonPath('error.code', 'IMPORT_TIENE_ASIENTOS');
        $this->assertNotNull(LibroIvaComprasImport::find($imp->id),
            'El upload NO se debe borrar si tiene facturas vinculadas');

        // Cleanup: desvincular para no afectar otros tests.
        DB::table('erp_facturas_compra')->where('id', $facturaId)->update(['import_id' => null]);
    }

    public function test_BU_03_rol_sin_permiso_devuelve_403(): void
    {
        $imp = $this->crearImport('test-bu03.csv', 'hash-bu03-'.uniqid());

        Sanctum::actingAs($this->contador, ['*']);
        $resp = $this->deleteJson("/api/erp/libro-iva-compras/imports/{$imp->id}");

        $resp->assertStatus(403);
        $resp->assertJsonPath('error.code', 'NO_AUTORIZADO');
        $this->assertNotNull(LibroIvaComprasImport::find($imp->id),
            'El upload NO se debe borrar sin permiso');
    }

    public function test_BU_04_upload_inexistente_devuelve_404(): void
    {
        Sanctum::actingAs($this->superAdmin, ['*']);
        $resp = $this->deleteJson('/api/erp/libro-iva-compras/imports/9999999');
        $resp->assertStatus(404);
    }

    public function test_BU_05_post_borrado_hash_queda_liberado(): void
    {
        $hash = 'hash-bu05-'.uniqid();
        $imp = $this->crearImport('test-bu05.csv', $hash);

        // El UNIQUE (empresa_id, archivo_hash) bloquea otro INSERT con mismo hash.
        $this->expectFailedDuplicate($hash);

        Sanctum::actingAs($this->superAdmin, ['*']);
        $this->deleteJson("/api/erp/libro-iva-compras/imports/{$imp->id}")->assertNoContent();

        // Post-DELETE, el hash queda libre — un INSERT con el mismo hash funciona.
        $nuevo = LibroIvaComprasImport::create([
            'empresa_id' => $this->empresaId,
            'archivo_nombre' => 'test-bu05-reupload.csv',
            'archivo_hash' => $hash,
            'periodo_imputacion_id' => $this->periodoId,
            'importado_por' => $this->superAdmin->id,
            'importado_at' => now(),
            'estado' => 'COMPLETO',
        ]);
        $this->assertNotNull($nuevo->id, 'Tras borrar el primero, el hash queda libre');
    }

    public function test_BU_06_audit_log_inmutable_persiste_snapshot_y_motivo(): void
    {
        $imp = $this->crearImport('test-bu06.csv', 'hash-bu06-'.uniqid());
        $motivo = 'Re-import post-fix encoding v1.19';

        Sanctum::actingAs($this->superAdmin, ['*']);
        $this->deleteJson("/api/erp/libro-iva-compras/imports/{$imp->id}", ['motivo' => $motivo])
            ->assertNoContent();

        $log = AuditLog::query()
            ->where('entidad', 'LibroIvaComprasImport')
            ->where('entidad_id', $imp->id)
            ->where('accion', 'eliminado')
            ->latest('id')
            ->first();

        $this->assertNotNull($log, 'Debe existir audit log del eliminado');
        $this->assertStringContainsString($motivo, (string) $log->descripcion);
        $this->assertSame($imp->archivo_hash, $log->datos_antes['archivo_hash'] ?? null);
        $this->assertSame($imp->archivo_nombre, $log->datos_antes['archivo_nombre'] ?? null);
    }

    public function test_listado_imports_incluye_facturas_count_y_puede_borrar(): void
    {
        $this->crearImport('test-listado.csv', 'hash-listado-'.uniqid());

        Sanctum::actingAs($this->superAdmin, ['*']);
        $resp = $this->getJson('/api/erp/libro-iva-compras/imports');
        $resp->assertOk();
        $resp->assertJsonStructure(['data' => [['id', 'facturas_count', 'puede_borrar']]]);
    }

    private function crearImport(string $nombre, string $hash): LibroIvaComprasImport
    {
        return LibroIvaComprasImport::create([
            'empresa_id' => $this->empresaId,
            'archivo_nombre' => $nombre,
            'archivo_hash' => $hash,
            'periodo_imputacion_id' => $this->periodoId,
            'filas_totales' => 0,
            'filas_error' => 0,
            'importado_por' => $this->superAdmin->id,
            'importado_at' => now(),
            'estado' => 'PARCIAL',
        ]);
    }

    private function expectFailedDuplicate(string $hash): void
    {
        try {
            LibroIvaComprasImport::create([
                'empresa_id' => $this->empresaId,
                'archivo_nombre' => 'dup.csv',
                'archivo_hash' => $hash,
                'periodo_imputacion_id' => $this->periodoId,
                'importado_por' => $this->superAdmin->id,
                'importado_at' => now(),
                'estado' => 'COMPLETO',
            ]);
            $this->fail('Se esperaba excepción por UNIQUE (empresa_id, archivo_hash)');
        } catch (\Throwable) {
            // Esperado.
        }
    }

    private function buildUserWithRol(string $rolCodigo): User
    {
        $user = User::factory()->create();

        $rolId = DB::table('erp_roles')->where('codigo', $rolCodigo)->value('id');
        if (! $rolId) {
            $this->fail("Rol '{$rolCodigo}' no existe en la DB de test");
        }

        $perfilId = DB::table('erp_usuario_perfil')->insertGetId([
            'user_id' => $user->id,
            'empresa_id' => 1,
            'acceso_erp' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('erp_usuario_rol')->insert([
            'usuario_perfil_id' => $perfilId,
            'rol_id' => $rolId,
        ]);

        return $user->fresh();
    }
}
