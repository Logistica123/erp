<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v1.42 Fase A — 3 tablas nuevas:
 *   - erp_arqueos_caja_denominaciones: grilla billete a billete por arqueo.
 *   - erp_caja_denominaciones_catalogo: catálogo configurable de billetes.
 *   - erp_cajas_operadores: lista explícita de usuarios autorizados a operar
 *     cada caja (D-42-2). FK a erp_cajas (no erp_cuentas_bancarias como
 *     supone el spec — el ERP real tiene tabla erp_cajas separada).
 *
 * Seed inicial del catálogo: denominaciones ARS de mayor a menor (D-42-5).
 *
 * Idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('erp_arqueos_caja_denominaciones')) {
            DB::unprepared(<<<'SQL'
                CREATE TABLE erp_arqueos_caja_denominaciones (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    arqueo_id BIGINT UNSIGNED NOT NULL,
                    valor_billete DECIMAL(10,2) NOT NULL,
                    cantidad INT UNSIGNED NOT NULL DEFAULT 0,
                    subtotal DECIMAL(18,2) NOT NULL,
                    PRIMARY KEY (id),
                    UNIQUE KEY uk_arq_denom (arqueo_id, valor_billete),
                    KEY idx_arq_denom_arqueo (arqueo_id),
                    CONSTRAINT fk_arq_denom_arqueo FOREIGN KEY (arqueo_id)
                        REFERENCES erp_arqueos_caja(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
        }

        if (! Schema::hasTable('erp_caja_denominaciones_catalogo')) {
            DB::unprepared(<<<'SQL'
                CREATE TABLE erp_caja_denominaciones_catalogo (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    moneda CHAR(3) NOT NULL DEFAULT 'ARS',
                    valor DECIMAL(10,2) NOT NULL,
                    descripcion VARCHAR(50) NOT NULL,
                    activa TINYINT(1) NOT NULL DEFAULT 1,
                    orden_presentacion INT NOT NULL DEFAULT 0,
                    PRIMARY KEY (id),
                    UNIQUE KEY uk_moneda_valor (moneda, valor)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

            // Seed ARS — orden de presentación de mayor a menor.
            DB::table('erp_caja_denominaciones_catalogo')->insert([
                ['moneda' => 'ARS', 'valor' => 20000.00, 'descripcion' => '$20.000', 'orden_presentacion' => 10],
                ['moneda' => 'ARS', 'valor' => 10000.00, 'descripcion' => '$10.000', 'orden_presentacion' => 20],
                ['moneda' => 'ARS', 'valor' =>  2000.00, 'descripcion' => '$2.000',  'orden_presentacion' => 30],
                ['moneda' => 'ARS', 'valor' =>  1000.00, 'descripcion' => '$1.000',  'orden_presentacion' => 40],
                ['moneda' => 'ARS', 'valor' =>   500.00, 'descripcion' => '$500',    'orden_presentacion' => 50],
                ['moneda' => 'ARS', 'valor' =>   200.00, 'descripcion' => '$200',    'orden_presentacion' => 60],
                ['moneda' => 'ARS', 'valor' =>   100.00, 'descripcion' => '$100',    'orden_presentacion' => 70],
                ['moneda' => 'ARS', 'valor' =>    50.00, 'descripcion' => '$50',     'orden_presentacion' => 80],
                ['moneda' => 'ARS', 'valor' =>    20.00, 'descripcion' => '$20',     'orden_presentacion' => 90],
                ['moneda' => 'ARS', 'valor' =>    10.00, 'descripcion' => '$10',     'orden_presentacion' => 100],
            ]);
        }

        if (! Schema::hasTable('erp_cajas_operadores')) {
            DB::unprepared(<<<'SQL'
                CREATE TABLE erp_cajas_operadores (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    caja_id INT UNSIGNED NOT NULL,
                    user_id BIGINT UNSIGNED NOT NULL,
                    fecha_alta DATE NOT NULL,
                    fecha_baja DATE NULL,
                    motivo_alta VARCHAR(200) NULL,
                    motivo_baja VARCHAR(200) NULL,
                    autorizado_por_user_id BIGINT UNSIGNED NOT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY idx_op_caja (caja_id),
                    KEY idx_op_user (user_id),
                    CONSTRAINT fk_op_caja FOREIGN KEY (caja_id) REFERENCES erp_cajas(id),
                    CONSTRAINT fk_op_user FOREIGN KEY (user_id) REFERENCES users(id),
                    CONSTRAINT fk_op_autorizador FOREIGN KEY (autorizado_por_user_id) REFERENCES users(id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS erp_cajas_operadores');
        DB::unprepared('DROP TABLE IF EXISTS erp_caja_denominaciones_catalogo');
        DB::unprepared('DROP TABLE IF EXISTS erp_arqueos_caja_denominaciones');
    }
};
