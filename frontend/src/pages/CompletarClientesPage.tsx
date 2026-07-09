import { useState } from 'react';
import { UserCheck, Search, Loader2, Check, CheckCircle2, AlertCircle } from 'lucide-react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Modal } from '@/components/ui/Modal';
import { Field, SelectField } from '@/components/ui/Field';
import { api, ApiError } from '@/lib/api';
import { useToast } from '@/hooks/useToast';

type ClientePlataforma = {
  id: number; codigo: string | null; nombre: string;
  documento_fiscal: string | null; direccion: string | null;
  razon_social: string | null; iva_condition: string | null;
  fiscal_address_street: string | null; fiscal_address_number: string | null;
  fiscal_address_floor: string | null; fiscal_address_unit: string | null;
  fiscal_address_locality: string | null; fiscal_address_postal_code: string | null;
  fiscal_address_province: string | null;
  erp_auxiliar_id: number | null; erp_condicion_iva_id: number | null;
  sincronizado_plataforma_at: string | null;
};
type CondicionIva = { id: number; codigo_interno: string; nombre: string };

type FormState = {
  razon_social: string; cuit: string; condicion_iva_id: string;
  domicilio_calle: string; domicilio_nro: string; domicilio_piso: string; domicilio_depto: string;
  localidad: string; provincia: string; cod_postal: string;
};

const FORM_VACIO: FormState = {
  razon_social: '', cuit: '', condicion_iva_id: '',
  domicilio_calle: '', domicilio_nro: '', domicilio_piso: '', domicilio_depto: '',
  localidad: '', provincia: '', cod_postal: '',
};

export default function CompletarClientesPage() {
  const qc = useQueryClient();
  const toast = useToast();
  const [q, setQ] = useState('');
  const [buscado, setBuscado] = useState('');
  const [sel, setSel] = useState<ClientePlataforma | null>(null);
  const [form, setForm] = useState<FormState>(FORM_VACIO);

  const { data: lista, isFetching } = useQuery<{ data: ClientePlataforma[] }>({
    queryKey: ['clientes-plataforma', buscado],
    queryFn: () => api.get(`/api/erp/integracion/distriapp/clientes-plataforma?q=${encodeURIComponent(buscado)}`),
  });
  const { data: condiciones } = useQuery<{ data: CondicionIva[] }>({
    queryKey: ['condiciones-iva'],
    queryFn: () => api.get('/api/erp/integracion/distriapp/condiciones-iva'),
  });
  const clientes = lista?.data ?? [];
  const condIva = condiciones?.data ?? [];

  const abrir = (c: ClientePlataforma) => {
    setSel(c);
    // Pre-cargar con lo que ya haya (tax_profile o los campos de fantasía).
    const docDigits = (c.documento_fiscal ?? '').replace(/[^0-9]/g, '');
    setForm({
      razon_social: c.razon_social || c.nombre || '',
      cuit: docDigits.length === 11 ? docDigits : '',
      condicion_iva_id: c.erp_condicion_iva_id ? String(c.erp_condicion_iva_id) : '',
      domicilio_calle: c.fiscal_address_street || '',
      domicilio_nro: c.fiscal_address_number || '',
      domicilio_piso: c.fiscal_address_floor || '',
      domicilio_depto: c.fiscal_address_unit || '',
      localidad: c.fiscal_address_locality || '',
      provincia: c.fiscal_address_province || '',
      cod_postal: c.fiscal_address_postal_code || '',
    });
  };

  const guardar = useMutation({
    mutationFn: () => api.post(`/api/erp/integracion/distriapp/clientes-plataforma/${sel!.id}/completar`, {
      razon_social: form.razon_social.trim(),
      cuit: form.cuit.replace(/[^0-9]/g, '') || null,
      condicion_iva_id: form.condicion_iva_id ? Number(form.condicion_iva_id) : null,
      domicilio_calle: form.domicilio_calle.trim() || null,
      domicilio_nro: form.domicilio_nro.trim() || null,
      domicilio_piso: form.domicilio_piso.trim() || null,
      domicilio_depto: form.domicilio_depto.trim() || null,
      localidad: form.localidad.trim() || null,
      provincia: form.provincia.trim() || null,
      cod_postal: form.cod_postal.trim() || null,
    }),
    onSuccess: () => {
      toast.success('Cliente completado', 'Se actualizó en el ERP y en la plataforma.');
      setSel(null);
      qc.invalidateQueries({ queryKey: ['clientes-plataforma'] });
    },
    onError: (e: ApiError) => toast.error('No se pudo completar', e.message),
  });

  const cuitInvalido = form.cuit !== '' && form.cuit.replace(/[^0-9]/g, '').length !== 11;
  const puedeGuardar = form.razon_social.trim() !== '' && !cuitInvalido && !guardar.isPending;

  return (
    <div className="p-6 space-y-4">
      <Card>
        <CardHeader title={
          <div className="flex items-center gap-2"><UserCheck className="w-4 h-4 text-azure" /> Completar clientes de la plataforma</div>
        } />
        <CardBody className="p-4 space-y-3">
          <div className="text-[12.5px] text-ink-2">
            Los clientes creados "de fantasía" en la plataforma se completan acá con los datos fiscales
            reales (razón social, CUIT/CUIL, domicilio, condición IVA). Al guardar, el dato se corrige
            también en la plataforma <strong>sin afectar pre-altas, altas ni liquidaciones</strong> (se
            actualiza el mismo registro por su id, nunca se crea uno nuevo).
          </div>

          <form className="flex gap-2" onSubmit={(e) => { e.preventDefault(); setBuscado(q.trim()); }}>
            <input
              value={q} onChange={(e) => setQ(e.target.value)}
              placeholder="Buscar por nombre de fantasía, código o CUIT…"
              className="flex-1 px-3 py-1.5 text-[13px] border border-line-strong rounded bg-white" />
            <Button type="submit" variant="primary" size="sm">
              {isFetching ? <Loader2 className="w-3 h-3 animate-spin" /> : <Search className="w-3 h-3" />} Buscar
            </Button>
          </form>

          <table className="w-full text-[12px]">
            <thead><tr className="text-left text-[11px] uppercase text-ink-2 border-b border-line">
              <th className="py-1">Estado</th><th>Nombre en plataforma</th><th>Código</th>
              <th>Documento</th><th>Razón social (fiscal)</th><th></th>
            </tr></thead>
            <tbody>
              {clientes.length === 0 ? (
                <tr><td colSpan={6} className="text-center text-ink-2 py-4">
                  {buscado ? 'Sin resultados.' : 'Buscá un cliente para empezar.'}
                </td></tr>
              ) : clientes.map((c, i) => (
                <tr key={c.id} className={i % 2 ? 'bg-surface-row' : ''}>
                  <td className="py-1">
                    {c.sincronizado_plataforma_at
                      ? <span className="inline-flex items-center gap-1 text-success text-[11px]"><CheckCircle2 className="w-3.5 h-3.5" /> Completado</span>
                      : <span className="inline-flex items-center gap-1 text-warning text-[11px]"><AlertCircle className="w-3.5 h-3.5" /> Pendiente</span>}
                  </td>
                  <td className="font-semibold">{c.nombre}</td>
                  <td className="tabular text-ink-2">{c.codigo ?? '—'}</td>
                  <td className="tabular">{c.documento_fiscal ?? '—'}</td>
                  <td className="max-w-[220px] truncate" title={c.razon_social ?? ''}>{c.razon_social ?? '—'}</td>
                  <td className="text-right">
                    <Button variant="secondary" size="sm" onClick={() => abrir(c)}>
                      {c.sincronizado_plataforma_at ? 'Editar' : 'Completar'}
                    </Button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </CardBody>
      </Card>

      {sel && (
        <Modal open onClose={() => setSel(null)} title={`Completar: ${sel.nombre}`} size="lg">
          <div className="space-y-3 p-1">
            <div className="text-[11.5px] text-ink-2 bg-azure-soft/10 border border-azure-soft rounded p-2">
              Cliente de la plataforma #{sel.id} ({sel.codigo ?? 's/código'}). Los datos se escriben en el
              ERP y se reflejan en la plataforma sin tocar su id ni sus referencias.
            </div>
            <div className="grid grid-cols-2 gap-2 text-[12px]">
              <div className="col-span-2">
                <Field label="Razón social / nombre real *" value={form.razon_social}
                  onChange={(e) => setForm({ ...form, razon_social: e.target.value })} />
              </div>
              <Field label="CUIT / CUIL" value={form.cuit}
                onChange={(e) => setForm({ ...form, cuit: e.target.value })}
                placeholder="11 dígitos"
                hint={cuitInvalido ? 'Debe tener 11 dígitos.' : undefined} />
              <SelectField label="Condición IVA" value={form.condicion_iva_id}
                onChange={(e) => setForm({ ...form, condicion_iva_id: e.target.value })}
                options={[{ value: '', label: '—' },
                  ...condIva.map((c) => ({ value: String(c.id), label: c.nombre }))]} />
            </div>
            <div className="text-[11px] font-semibold text-ink-2 uppercase pt-1">Domicilio fiscal</div>
            <div className="grid grid-cols-4 gap-2 text-[12px]">
              <div className="col-span-2">
                <Field label="Calle" value={form.domicilio_calle}
                  onChange={(e) => setForm({ ...form, domicilio_calle: e.target.value })} />
              </div>
              <Field label="Número" value={form.domicilio_nro}
                onChange={(e) => setForm({ ...form, domicilio_nro: e.target.value })} />
              <Field label="Piso" value={form.domicilio_piso}
                onChange={(e) => setForm({ ...form, domicilio_piso: e.target.value })} />
              <Field label="Depto" value={form.domicilio_depto}
                onChange={(e) => setForm({ ...form, domicilio_depto: e.target.value })} />
              <Field label="Localidad" value={form.localidad}
                onChange={(e) => setForm({ ...form, localidad: e.target.value })} />
              <Field label="Provincia" value={form.provincia}
                onChange={(e) => setForm({ ...form, provincia: e.target.value })} />
              <Field label="Cód. postal" value={form.cod_postal}
                onChange={(e) => setForm({ ...form, cod_postal: e.target.value })} />
            </div>
            <div className="flex justify-end gap-2 pt-2">
              <Button variant="secondary" size="sm" onClick={() => setSel(null)}>Cancelar</Button>
              <Button variant="primary" size="sm" disabled={!puedeGuardar} onClick={() => guardar.mutate()}>
                {guardar.isPending ? <Loader2 className="w-3 h-3 animate-spin" /> : <Check className="w-3 h-3" />}
                Guardar y reflejar en plataforma
              </Button>
            </div>
          </div>
        </Modal>
      )}
    </div>
  );
}
