import { useEffect, useState } from 'react';
import { Building2 } from 'lucide-react';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Field, SelectField, FormError } from '@/components/ui/Field';
import { api } from '@/lib/api';
import { useApi, useApiMutation, useInvalidate, errorMessage } from '@/hooks/useApi';
import { useToast } from '@/hooks/useToast';

/**
 * v1.55 Bloque C — Ficha de la empresa (reemplaza el placeholder).
 * El sistema es mono-empresa: se edita la ficha de la empresa del perfil.
 */

type Empresa = {
  id: number; razon_social: string; nombre_fantasia: string | null; cuit: string;
  condicion_iva: 'RI' | 'MONOTRIBUTO' | 'EXENTO' | 'CF';
  domicilio_fiscal: string | null; iibb_nro: string | null;
  iibb_regimen: 'CM' | 'LOCAL' | null; iibb_jurisdiccion_sede: string | null;
  fecha_inicio_actividades: string | null; moneda_base: string;
  aplica_rt6: boolean | number; activo: boolean | number;
};

export function EmpresaAdminPage() {
  const toast = useToast();
  const invalidate = useInvalidate(['admin-empresa']);
  const { data: empresa, isLoading, error } = useApi<Empresa>(['admin-empresa'], '/api/erp/admin/empresa');

  const [form, setForm] = useState<Partial<Empresa>>({});
  const [err, setErr] = useState<string | null>(null);

  useEffect(() => {
    if (empresa) setForm(empresa);
  }, [empresa]);

  const guardar = useApiMutation<unknown, void>(
    () => api.patch('/api/erp/admin/empresa', {
      razon_social: form.razon_social,
      nombre_fantasia: form.nombre_fantasia || null,
      cuit: form.cuit,
      condicion_iva: form.condicion_iva,
      domicilio_fiscal: form.domicilio_fiscal || null,
      iibb_nro: form.iibb_nro || null,
      iibb_regimen: form.iibb_regimen || null,
      iibb_jurisdiccion_sede: form.iibb_jurisdiccion_sede || null,
      fecha_inicio_actividades: form.fecha_inicio_actividades?.slice(0, 10) || null,
      aplica_rt6: !!form.aplica_rt6,
    }),
    {
      onSuccess: () => { toast.success('Empresa actualizada'); setErr(null); invalidate(); },
      onError: (e) => setErr(errorMessage(e)),
    }
  );

  const set = (k: keyof Empresa, v: unknown) => setForm((f) => ({ ...f, [k]: v }));

  return (
    <div className="max-w-[720px] space-y-4">
      <Card>
        <CardHeader
          title={<span className="flex items-center gap-2"><Building2 className="w-4 h-4" /> Empresa</span>}
          actions={
            <Button variant="primary" disabled={isLoading || guardar.isPending} onClick={() => guardar.mutate()}>
              Guardar cambios
            </Button>
          }
        />
        <CardBody>
          {error && <FormError error={errorMessage(error)} />}
          <FormError error={err} />
          {isLoading ? (
            <div className="text-ink-muted text-[12px]">Cargando…</div>
          ) : (
            <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
              <Field label="Razón social" required value={form.razon_social ?? ''}
                onChange={(e) => set('razon_social', e.target.value)} />
              <Field label="Nombre de fantasía" value={form.nombre_fantasia ?? ''}
                onChange={(e) => set('nombre_fantasia', e.target.value)} />
              <Field label="CUIT" required value={form.cuit ?? ''} maxLength={11}
                hint="11 dígitos sin guiones."
                onChange={(e) => set('cuit', e.target.value.replace(/[^0-9]/g, ''))} />
              <SelectField label="Condición IVA" value={form.condicion_iva ?? 'RI'}
                onChange={(e) => set('condicion_iva', e.target.value)}
                placeholder={null}
                options={[
                  { value: 'RI', label: 'Responsable Inscripto' },
                  { value: 'MONOTRIBUTO', label: 'Monotributo' },
                  { value: 'EXENTO', label: 'Exento' },
                  { value: 'CF', label: 'Consumidor Final' },
                ]} />
              <Field label="Domicilio fiscal" containerClassName="md:col-span-2"
                value={form.domicilio_fiscal ?? ''}
                onChange={(e) => set('domicilio_fiscal', e.target.value)} />
              <Field label="IIBB Nro" value={form.iibb_nro ?? ''}
                onChange={(e) => set('iibb_nro', e.target.value)} />
              <SelectField label="IIBB Régimen" value={form.iibb_regimen ?? ''}
                onChange={(e) => set('iibb_regimen', e.target.value || null)}
                options={[
                  { value: 'CM', label: 'Convenio Multilateral' },
                  { value: 'LOCAL', label: 'Local' },
                ]} />
              <Field label="Jurisdicción sede (código)" value={form.iibb_jurisdiccion_sede ?? ''}
                maxLength={3} hint="Código de 3 dígitos (ej: 902 Buenos Aires)."
                onChange={(e) => set('iibb_jurisdiccion_sede', e.target.value)} />
              <Field label="Inicio de actividades" type="date"
                value={form.fecha_inicio_actividades?.slice(0, 10) ?? ''}
                onChange={(e) => set('fecha_inicio_actividades', e.target.value)} />
              <Field label="Moneda base" value={form.moneda_base ?? 'ARS'} disabled
                hint="No editable: es la moneda de toda la contabilidad." />
              <label className="flex items-center gap-2 cursor-pointer text-[12.5px] mt-5">
                <input type="checkbox" checked={!!form.aplica_rt6}
                  onChange={(e) => set('aplica_rt6', e.target.checked)} />
                Aplica ajuste por inflación (RT6)
              </label>
            </div>
          )}
          <div className="text-[11px] text-ink-muted mt-3">
            Guardar requiere MFA reciente (menos de 15 minutos). Estos datos alimentan encabezados
            de reportes, F.8001 y presentaciones — modificar con cuidado.
          </div>
        </CardBody>
      </Card>
    </div>
  );
}
