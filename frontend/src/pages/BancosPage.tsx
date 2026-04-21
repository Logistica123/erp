import { AlertCircle, ArrowDownRight, ArrowUpRight, Landmark, Loader2 } from 'lucide-react';
import { useNavigate } from 'react-router-dom';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { fmtMoney } from '@/lib/cn';
import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';

type CuentaBancaria = {
  id: number;
  codigo: string;
  nombre: string;
  tipo: string;
  saldo_actual: string;
  fecha_ultimo_movimiento: string | null;
  banco: { codigo: string; nombre: string };
  moneda: { codigo: string };
};

type Caja = {
  id: number;
  codigo: string;
  nombre: string;
  saldo_actual: string;
  moneda: { codigo: string };
};

type MovimientoBancario = {
  id: number;
  fecha: string;
  concepto: string;
  debito: string;
  credito: string;
  estado: 'PENDIENTE' | 'ETIQUETADO' | 'CONCILIADO' | 'IGNORADO';
  cuenta_bancaria: { codigo: string; nombre: string };
};

export function BancosPage() {
  const navigate = useNavigate();

  const { data: cuentas, isLoading } = useQuery<{ data: CuentaBancaria[] }>({
    queryKey: ['cuentas-bancarias'],
    queryFn: () => api.get('/api/erp/cuentas-bancarias'),
  });

  const { data: cajas } = useQuery<{ data: Caja[] }>({
    queryKey: ['cajas'],
    queryFn: () => api.get('/api/erp/cajas'),
  });

  const { data: pendientes } = useQuery<{ data: MovimientoBancario[] }>({
    queryKey: ['mov-banc', 'pendientes'],
    queryFn: () => api.get('/api/erp/movimientos-bancarios?estado=PENDIENTE'),
  });

  const totalCuentas = cuentas?.data.reduce((s, c) => s + Number(c.saldo_actual), 0) ?? 0;
  const totalCajas = cajas?.data.reduce((s, c) => s + Number(c.saldo_actual), 0) ?? 0;
  const totalDisponible = totalCuentas + totalCajas;

  return (
    <>
      <div className="flex items-end justify-between mb-[18px]">
        <div>
          <h1 className="text-xl font-semibold text-navy-800 tracking-tight">Tesorería · Bancos y cajas</h1>
          <p className="text-[12px] text-ink-muted mt-[2px]">
            Saldos consolidados · {pendientes?.data.length ?? 0} movimientos sin conciliar
          </p>
        </div>
        <div className="flex gap-2">
          <Button variant="secondary" onClick={() => navigate('/erp/conciliacion')}>
            Ir a conciliación
          </Button>
          <Button variant="primary" onClick={() => navigate('/erp/ordenes-pago')}>
            Órdenes de pago
          </Button>
        </div>
      </div>

      {/* Saldo bar */}
      <div className="bg-gradient-to-br from-navy-800 to-navy-600 text-white rounded-lg p-[18px_22px] mb-[18px] grid grid-cols-3 gap-5">
        <div>
          <div className="text-[10px] opacity-70 uppercase tracking-wider font-semibold">Total disponible</div>
          <div className="text-[22px] font-semibold tabular mt-1">{fmtMoney(totalDisponible)}</div>
          <div className="text-[11px] opacity-65 mt-[3px]">{(cuentas?.data.length ?? 0) + (cajas?.data.length ?? 0)} cuentas activas</div>
        </div>
        <div className="pl-[18px] border-l border-white/15">
          <div className="text-[10px] opacity-70 uppercase tracking-wider font-semibold">Saldo bancos</div>
          <div className="text-[22px] font-semibold tabular mt-1">{fmtMoney(totalCuentas)}</div>
          <div className="text-[11px] opacity-65 mt-[3px]">{cuentas?.data.length ?? 0} cuentas bancarias</div>
        </div>
        <div className="pl-[18px] border-l border-white/15">
          <div className="text-[10px] opacity-70 uppercase tracking-wider font-semibold">Caja</div>
          <div className="text-[22px] font-semibold tabular mt-1">{fmtMoney(totalCajas)}</div>
          <div className="text-[11px] opacity-65 mt-[3px]">{cajas?.data.length ?? 0} cajas</div>
        </div>
      </div>

      <div className="grid grid-cols-2 gap-4">
        <Card>
          <CardHeader title="Cuentas bancarias" />
          <CardBody>
            {isLoading && <div className="p-6 text-center text-ink-muted"><Loader2 className="w-4 h-4 animate-spin inline mr-2" />Cargando…</div>}
            {cuentas?.data.map((c, i) => (
              <div
                key={c.id}
                className={`flex items-center justify-between px-4 py-3 border-b border-line last:border-b-0 text-[12px] ${
                  i % 2 ? 'bg-surface-row' : ''
                }`}
              >
                <div className="flex items-center gap-3">
                  <div className="w-8 h-8 rounded-lg bg-navy-700/10 flex items-center justify-center text-navy-700">
                    <Landmark className="w-4 h-4" />
                  </div>
                  <div>
                    <div className="font-medium text-navy-800">{c.nombre}</div>
                    <div className="text-[11px] text-ink-muted font-mono">{c.banco.codigo} · {c.tipo}</div>
                  </div>
                </div>
                <div className="text-right">
                  <div className={`tabular font-semibold ${Number(c.saldo_actual) < 0 ? 'text-danger' : 'text-navy-800'}`}>
                    {fmtMoney(Number(c.saldo_actual))}
                  </div>
                  <div className="text-[10px] text-ink-muted">
                    {c.fecha_ultimo_movimiento ? `Últ. mov: ${c.fecha_ultimo_movimiento.slice(0, 10)}` : 'sin movimientos'}
                  </div>
                </div>
              </div>
            ))}
          </CardBody>
        </Card>

        <Card>
          <CardHeader
            title={
              <div className="flex items-center gap-2">
                Movimientos pendientes
                {pendientes && pendientes.data.length > 0 && (
                  <Badge variant="warning">{pendientes.data.length}</Badge>
                )}
              </div>
            }
            actions={<Button size="sm" variant="secondary" onClick={() => navigate('/erp/conciliacion')}>Ver todos</Button>}
          />
          <CardBody>
            {pendientes?.data.length === 0 && (
              <div className="p-6 text-center text-ink-muted text-[12px]">
                <AlertCircle className="w-4 h-4 inline mr-1" /> No hay movimientos pendientes de conciliar.
              </div>
            )}
            {pendientes?.data.slice(0, 8).map((m, i) => (
              <div
                key={m.id}
                className={`flex items-center justify-between px-4 py-2.5 border-b border-line last:border-b-0 text-[12px] ${
                  i % 2 ? 'bg-surface-row' : ''
                }`}
              >
                <div className="flex items-center gap-2 min-w-0">
                  {Number(m.credito) > 0 ? (
                    <ArrowDownRight className="w-4 h-4 text-success shrink-0" />
                  ) : (
                    <ArrowUpRight className="w-4 h-4 text-danger shrink-0" />
                  )}
                  <div className="min-w-0">
                    <div className="text-ink-2 truncate">{m.concepto}</div>
                    <div className="text-[10px] text-ink-muted font-mono">
                      {m.cuenta_bancaria.codigo} · {m.fecha.slice(0, 10)}
                    </div>
                  </div>
                </div>
                <div className={`tabular font-medium shrink-0 ${Number(m.credito) > 0 ? 'text-success' : 'text-danger'}`}>
                  {fmtMoney(Number(m.credito) > 0 ? Number(m.credito) : -Number(m.debito))}
                </div>
              </div>
            ))}
          </CardBody>
        </Card>
      </div>

      {cajas && cajas.data.length > 0 && (
        <Card className="mt-4">
          <CardHeader title="Cajas" />
          <CardBody>
            {cajas.data.map((c) => (
              <div
                key={c.id}
                className="flex items-center justify-between px-4 py-3 border-b border-line last:border-b-0 text-[12px]"
              >
                <div>
                  <div className="font-medium text-navy-800">{c.nombre}</div>
                  <div className="text-[11px] text-ink-muted font-mono">{c.codigo} · {c.moneda.codigo}</div>
                </div>
                <div className="tabular font-semibold text-navy-800">{fmtMoney(Number(c.saldo_actual))}</div>
              </div>
            ))}
          </CardBody>
        </Card>
      )}
    </>
  );
}
