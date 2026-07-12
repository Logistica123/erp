import { useMemo, useState } from 'react';
import { Settings, Pencil, Lock } from 'lucide-react';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Modal } from '@/components/ui/Modal';
import { Field, SelectField, TextareaField, FormError } from '@/components/ui/Field';
import { api } from '@/lib/api';
import { useApi, useApiMutation, useInvalidate, errorMessage } from '@/hooks/useApi';
import { useToast } from '@/hooks/useToast';

/**
 * v1.55 Bloque C — Configuración general (reemplaza el placeholder).
 * Las claves se seedean por migración; acá solo se editan valores
 * (PATCH /config/{clave}, requiere super_admin + MFA fresco).
 */

type ConfigItem = {
  clave: string; categoria: string; tipo: string;
  valor: unknown; valor_raw: string | null;
  editable: boolean | number; descripcion: string | null;
};

export function ConfiguracionAdminPage() {
  const [editar, setEditar] = useState<ConfigItem | null>(null);
  const invalidate = useInvalidate(['admin-config']);
  const { data: items, isLoading, error } = useApi<ConfigItem[]>(['admin-config'], '/api/erp/config');

  const porCategoria = useMemo(() => {
    const grupos = new Map<string, ConfigItem[]>();
    for (const it of items ?? []) {
      if (!grupos.has(it.categoria)) grupos.set(it.categoria, []);
      grupos.get(it.categoria)!.push(it);
    }
    return [...grupos.entries()].sort(([a], [b]) => a.localeCompare(b));
  }, [items]);

  const renderValor = (it: ConfigItem) => {
    if (it.valor === null || it.valor === undefined || it.valor === '') {
      return <span className="text-ink-muted">(vacío)</span>;
    }
    if (it.tipo === 'JSON') return <code className="text-[11px]">{JSON.stringify(it.valor)}</code>;
    if (typeof it.valor === 'boolean' || it.tipo === 'BOOL' || it.tipo === 'BOOLEAN') {
      const v = it.valor === true || it.valor === 1 || it.valor === '1' || it.valor === 'true';
      return v ? <Badge variant="success">SÍ</Badge> : <Badge variant="neutral">NO</Badge>;
    }
    return <span className="tabular">{String(it.valor)}</span>;
  };

  return (
    <div className="space-y-4">
      <Card>
        <CardHeader title={<span className="flex items-center gap-2"><Settings className="w-4 h-4" /> Configuración</span>} />
        <CardBody>
          {error && <FormError error={errorMessage(error)} />}
          {isLoading && <div className="text-ink-muted text-[12px]">Cargando…</div>}
          <div className="space-y-4">
            {porCategoria.map(([categoria, claves]) => (
              <div key={categoria} className="border border-line rounded-md">
                <div className="px-3 py-1.5 bg-surface-hover/60 border-b border-line text-[12px] font-semibold uppercase tracking-wide">
                  {categoria}
                </div>
                <div className="divide-y divide-line/60">
                  {claves.map((it) => (
                    <div key={it.clave} className="flex items-center gap-3 px-3 py-2">
                      <div className="flex-1 min-w-0">
                        <code className="text-[11.5px] text-azure">{it.clave}</code>
                        <span className="ml-2 text-[10px] px-1 rounded bg-line">{it.tipo}</span>
                        {it.descripcion && (
                          <div className="text-[10.5px] text-ink-muted truncate">{it.descripcion}</div>
                        )}
                      </div>
                      <div className="text-[12px] max-w-[280px] truncate">{renderValor(it)}</div>
                      {it.editable ? (
                        <button onClick={() => setEditar(it)} title="Editar"
                          className="p-1 opacity-60 hover:opacity-100 hover:text-azure">
                          <Pencil className="w-3 h-3" />
                        </button>
                      ) : (
                        <span title="No editable" className="p-1 opacity-40"><Lock className="w-3 h-3" /></span>
                      )}
                    </div>
                  ))}
                </div>
              </div>
            ))}
            {!isLoading && !porCategoria.length && (
              <div className="text-ink-muted text-[12px]">Sin claves de configuración.</div>
            )}
          </div>
          <div className="text-[11px] text-ink-muted mt-3">
            Editar configuración requiere MFA reciente (menos de 15 minutos). Las claves nuevas se
            crean por migración, no desde acá.
          </div>
        </CardBody>
      </Card>

      {editar && (
        <EditarConfigModal item={editar} onClose={() => setEditar(null)}
          onSuccess={() => { setEditar(null); invalidate(); }} />
      )}
    </div>
  );
}

function EditarConfigModal({ item, onClose, onSuccess }: {
  item: ConfigItem; onClose: () => void; onSuccess: () => void;
}) {
  const toast = useToast();
  const invalidate = useInvalidate(['admin-config']);
  const esBool = item.tipo === 'BOOL' || item.tipo === 'BOOLEAN';
  const inicial = item.valor_raw ?? (item.valor === null || item.valor === undefined ? '' : String(item.valor));
  const [valor, setValor] = useState(inicial);
  const [err, setErr] = useState<string | null>(null);

  const guardar = useApiMutation<unknown, void>(
    () => {
      let v: unknown = valor;
      if (esBool) v = valor === '1';
      else if (item.tipo === 'INT') v = Number(valor);
      else if (item.tipo === 'DECIMAL') v = Number(valor);
      else if (item.tipo === 'JSON') v = JSON.parse(valor);
      return api.patch(`/api/erp/config/${encodeURIComponent(item.clave)}`, { valor: v });
    },
    {
      onSuccess: () => { toast.success('Configuración guardada'); invalidate(); onSuccess(); },
      onError: (e) => setErr(errorMessage(e)),
    }
  );

  const submit = () => {
    if (item.tipo === 'JSON') {
      try { JSON.parse(valor); } catch { setErr('JSON inválido'); return; }
    }
    setErr(null);
    guardar.mutate();
  };

  return (
    <Modal open onClose={onClose} title={`Editar · ${item.clave}`} size="md"
      footer={<>
        <Button variant="secondary" onClick={onClose}>Cancelar</Button>
        <Button variant="primary" disabled={guardar.isPending} onClick={submit}>Guardar</Button>
      </>}>
      <div className="space-y-3">
        <FormError error={err} />
        {item.descripcion && <div className="text-[12px] text-ink-muted">{item.descripcion}</div>}
        {esBool ? (
          <SelectField label={`Valor (${item.tipo})`} value={valor === '1' || valor === 'true' ? '1' : '0'}
            onChange={(e) => setValor(e.target.value)}
            placeholder={null}
            options={[{ value: '1', label: 'SÍ' }, { value: '0', label: 'NO' }]} />
        ) : item.tipo === 'JSON' ? (
          <TextareaField label="Valor (JSON)" rows={5} value={valor}
            className="font-mono text-[11.5px]"
            onChange={(e) => setValor(e.target.value)} />
        ) : (
          <Field label={`Valor (${item.tipo})`}
            type={item.tipo === 'INT' || item.tipo === 'DECIMAL' ? 'number' : item.tipo === 'DATE' ? 'date' : 'text'}
            step={item.tipo === 'DECIMAL' ? '0.01' : undefined}
            value={valor} onChange={(e) => setValor(e.target.value)} />
        )}
        <div className="text-[11px] text-ink-muted">Requiere MFA reciente (menos de 15 minutos).</div>
      </div>
    </Modal>
  );
}
