<?php

namespace App\Erp\Support;

use App\Erp\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Centraliza la escritura al log inmutable `erp_audit_log` con hash-chain
 * por empresa (tamper-evident).
 *
 * Cadena por empresa:
 *   hash_actual = SHA-256(hash_prev || payload_json)
 *
 * El hash_prev arranca en 64 ceros para la primera entrada de cada empresa.
 * Verificar integridad = recalcular la cadena de punta a punta.
 */
class AuditLogger
{
    /**
     * Atributos que nunca se persisten en datos_antes / datos_despues.
     */
    private const GLOBAL_REDACTED = [
        'password',
        'remember_token',
        'mfa_secret',
        'hash_integridad', // propio del modelo Asiento; no queremos loop
    ];

    public function log(
        string $accion,
        Model $model,
        ?array $datosAntes = null,
        ?array $datosDespues = null,
        ?string $descripcion = null,
        ?string $moduloOverride = null,
    ): ?AuditLog {
        $modulo = $moduloOverride ?? $this->moduloDe($model);
        $entidad = class_basename($model);
        $empresaId = $this->resolverEmpresaId($model);

        $datosAntes = $datosAntes !== null ? $this->redact($datosAntes) : null;
        $datosDespues = $datosDespues !== null ? $this->redact($datosDespues) : null;

        // Bloqueamos la última fila de la cadena de esta empresa para
        // serializar la construcción del hash y evitar colisiones concurrentes.
        return DB::transaction(function () use (
            $accion, $modulo, $entidad, $empresaId, $model, $datosAntes, $datosDespues, $descripcion
        ) {
            $prev = AuditLog::query()
                ->when($empresaId, fn ($q) => $q->where('empresa_id', $empresaId))
                ->when(! $empresaId, fn ($q) => $q->whereNull('empresa_id'))
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            $hashPrev = $prev?->hash_actual ?? str_repeat('0', 64);
            // Truncar a segundos para que la columna DATETIME persista el mismo
            // valor que se firma y la cadena se pueda recomputar en verificación.
            $ts = now()->startOfSecond();
            $userId = Auth::id();

            $payload = self::payloadParaHash(
                empresaId: $empresaId,
                modulo: $modulo,
                entidad: $entidad,
                entidadId: $model->getKey(),
                accion: $accion,
                datosAntes: $datosAntes,
                datosDespues: $datosDespues,
                userId: $userId,
                ts: $ts->toIso8601String(),
            );

            $hashActual = hash('sha256', $hashPrev.json_encode($payload, JSON_THROW_ON_ERROR));

            return AuditLog::create([
                'empresa_id' => $empresaId,
                'user_id' => $userId,
                'modulo' => $modulo,
                'entidad' => $entidad,
                'entidad_id' => $model->getKey(),
                'accion' => $accion,
                'descripcion' => $descripcion,
                'datos_antes' => $datosAntes,
                'datos_despues' => $datosDespues,
                'ip' => request()?->ip(),
                'user_agent' => substr((string) request()?->userAgent(), 0, 300),
                'hash_prev' => $hashPrev,
                'hash_actual' => $hashActual,
                'created_at' => $ts,
            ]);
        });
    }

    /**
     * Reconstruye la cadena de hashes de una empresa y devuelve la primera
     * inconsistencia (o null si toda la cadena es válida).
     *
     * Verificación estricta: recomputa hash_actual = SHA-256(hash_prev || json(payload))
     * usando el created_at persistido como 'ts'. Si alguien modificó un campo
     * incluido en el payload, el hash recomputado no va a coincidir.
     */
    public function verificarCadena(?int $empresaId): ?array
    {
        $query = AuditLog::query()
            ->when($empresaId, fn ($q) => $q->where('empresa_id', $empresaId))
            ->when(! $empresaId, fn ($q) => $q->whereNull('empresa_id'))
            ->orderBy('id');

        $prevHash = str_repeat('0', 64);

        foreach ($query->cursor() as $row) {
            if ($row->hash_prev !== $prevHash) {
                return [
                    'ok' => false,
                    'razon' => 'hash_prev_mismatch',
                    'audit_log_id' => $row->id,
                    'esperado' => $prevHash,
                    'encontrado' => $row->hash_prev,
                ];
            }

            $payload = self::payloadParaHash(
                empresaId: $row->empresa_id,
                modulo: $row->modulo,
                entidad: $row->entidad,
                entidadId: $row->entidad_id,
                accion: $row->accion,
                datosAntes: $row->datos_antes,
                datosDespues: $row->datos_despues,
                userId: $row->user_id,
                ts: $row->created_at->toIso8601String(),
            );
            $calc = hash('sha256', $prevHash.json_encode($payload, JSON_THROW_ON_ERROR));

            if ($calc !== $row->hash_actual) {
                return [
                    'ok' => false,
                    'razon' => 'hash_actual_mismatch',
                    'audit_log_id' => $row->id,
                    'esperado' => $calc,
                    'encontrado' => $row->hash_actual,
                ];
            }

            $prevHash = $row->hash_actual;
        }

        return null; // ok
    }

    /**
     * Estructura canónica del payload que se firma (tanto al escribir como al
     * verificar). Mantener el orden de las claves estable es crítico porque
     * json_encode preserva orden y el hash cambia si difiere.
     */
    private static function payloadParaHash(
        ?int $empresaId,
        string $modulo,
        string $entidad,
        mixed $entidadId,
        string $accion,
        ?array $datosAntes,
        ?array $datosDespues,
        ?int $userId,
        string $ts,
    ): array {
        return [
            'empresa_id' => $empresaId,
            'modulo' => $modulo,
            'entidad' => $entidad,
            'entidad_id' => $entidadId,
            'accion' => $accion,
            'datos_antes' => $datosAntes,
            'datos_despues' => $datosDespues,
            'user_id' => $userId,
            'ts' => $ts,
        ];
    }

    /**
     * Escribe un evento de auditoría "manual" sin tener un modelo asociado.
     * Útil para login, logout, cambio de rol, etc.
     */
    public function logEvento(
        string $accion,
        string $modulo,
        string $descripcion,
        ?int $empresaId = null,
    ): AuditLog {
        $dummy = new class extends Model
        {
            public function getKey() { return null; }
        };

        // Fix G-04 (2026-07-13): el parámetro $modulo se ignoraba — todos
        // los eventos quedaban etiquetados por moduloDe(dummy) = 'otros'.
        return $this->log($accion, $dummy, null, null, $descripcion, $modulo);
    }

    private function redact(array $data): array
    {
        foreach (self::GLOBAL_REDACTED as $k) {
            if (array_key_exists($k, $data)) {
                $data[$k] = '[REDACTED]';
            }
        }

        return $data;
    }

    private function resolverEmpresaId(Model $model): ?int
    {
        if (isset($model->empresa_id)) {
            return (int) $model->empresa_id;
        }

        // Modelos globales (Permiso, Moneda) no tienen empresa_id.
        return null;
    }

    private function moduloDe(Model $model): string
    {
        $ns = get_class($model);

        if (str_contains($ns, '\\Models\\')) {
            $base = class_basename($ns);
            return match (true) {
                in_array($base, ['Asiento', 'MovimientoAsiento', 'CuentaContable', 'Diario', 'Auxiliar', 'AsientoPlantilla', 'SaldoCuenta', 'MapeoEtiquetaCuenta'], true) => 'contabilidad',
                in_array($base, ['Periodo', 'Ejercicio'], true) => 'ejercicios',
                in_array($base, ['Rol', 'Permiso', 'UsuarioPerfil', 'Sesion'], true) => 'seguridad',
                in_array($base, ['Empresa', 'Config', 'Moneda', 'Cotizacion', 'CentroCosto'], true) => 'core',
                default => 'otros',
            };
        }

        return 'otros';
    }
}
