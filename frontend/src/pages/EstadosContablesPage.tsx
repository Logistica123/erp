import { useState } from 'react';
import { AlertTriangle, CheckCircle2, Download, Loader2 } from 'lucide-react';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { fmtMoney } from '@/lib/cn';
import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';

type Cuenta = { id: number; codigo: string; nombre: string; saldo: number };
type Subrubro = { codigo: string; nombre: string; cuentas: Cuenta[]; total: number };
type Rubro = { codigo: string; nombre: string; subrubros: Subrubro[]; total: number };

type SPResp = {
  data: {
    al: string;
    activos: Rubro[];
    pasivos: Rubro[];
    patrimonio_neto: Rubro[];
    totales: {
      activo: number;
      pasivo: number;
      patrimonio_neto: number;
      pasivo_mas_pn: number;
      diferencia: number;
    };
    ecuacion_cuadra: boolean;
  };
};

type ERResp = {
  data: {
    rango: { desde: string; hasta: string };
    ingresos: Rubro[];
    egresos: Rubro[];
    totales: {
      ingresos: number;
      egresos: number;
      resultado_neto: number;
      margen_porcentual: number | null;
    };
    tipo_resultado: 'GANANCIA' | 'PERDIDA';
  };
};

function firstOfYear(): string {
  return `${new Date().getFullYear()}-01-01`;
}
function today(): string {
  return new Date().toISOString().slice(0, 10);
}

/** Render recursivo de un rubro → subrubros → cuentas. */
function RubroBloque({ rubro }: { rubro: Rubro }) {
  return (
    <div className="mb-3">
      <div className="grid grid-cols-[1fr_160px] py-[7px] px-[10px] bg-navy-700 text-white font-semibold text-[12px]">
        <div>
          <span className="font-mono text-[11px] opacity-80 mr-2">{rubro.codigo}</span>
          {rubro.nombre}
        </div>
        <div className="text-right tabular">{fmtMoney(rubro.total)}</div>
      </div>
      {rubro.subrubros.map((s) => (
        <div key={s.codigo} className="border-l-2 border-navy-700/30">
          <div className="grid grid-cols-[1fr_160px] py-[5px] px-[10px] pl-5 bg-[#DFE8F2] text-navy-800 font-medium text-[12px]">
            <div>
              <span className="font-mono text-[11px] text-navy-700 mr-2">{s.codigo}</span>
              {s.nombre}
            </div>
            <div className="text-right tabular">{fmtMoney(s.total)}</div>
          </div>
          {s.cuentas.map((c) => (
            <div
              key={c.id}
              className="grid grid-cols-[1fr_160px] py-[5px] px-[10px] pl-10 text-ink-2 text-[12px] border-b border-line"
            >
              <div>
                <span className="font-mono text-[11px] text-navy-700 mr-2">{c.codigo}</span>
                {c.nombre}
              </div>
              <div className={`text-right tabular ${c.saldo < 0 ? 'text-danger' : 'text-ink-2'}`}>
                {fmtMoney(c.saldo)}
              </div>
            </div>
          ))}
        </div>
      ))}
    </div>
  );
}

function SituacionPatrimonial({ desde, hasta }: { desde: string; hasta: string }) {
  const { data, isLoading } = useQuery<SPResp>({
    queryKey: ['ec-sp', hasta],
    queryFn: () => api.get<SPResp>(`/api/erp/estados-contables/situacion-patrimonial?al=${hasta}`),
  });

  if (isLoading) return <div className="py-10 text-center text-ink-muted"><Loader2 className="w-4 h-4 animate-spin inline mr-2" />Calculando Situación Patrimonial…</div>;
  if (!data) return null;

  const { activos, pasivos, patrimonio_neto, totales, ecuacion_cuadra } = data.data;
  const hayPn = patrimonio_neto.length > 0;

  return (
    <>
      <div
        className={`mb-4 px-4 py-3 rounded-md text-[12px] border flex items-center gap-2 ${
          ecuacion_cuadra
            ? 'bg-success-bg text-success border-success/30'
            : 'bg-warning-bg text-warning border-warning/30'
        }`}
      >
        {ecuacion_cuadra ? <CheckCircle2 className="w-4 h-4" /> : <AlertTriangle className="w-4 h-4" />}
        <strong>
          {ecuacion_cuadra
            ? 'Ecuación patrimonial cuadrada: Activo = Pasivo + PN.'
            : 'Ecuación patrimonial descuadrada.'}
        </strong>
        <span className="opacity-80">
          A {fmtMoney(totales.activo)} · P + PN {fmtMoney(totales.pasivo_mas_pn)} · diff {fmtMoney(totales.diferencia)}
        </span>
      </div>

      <div className="grid grid-cols-2 gap-4">
        <Card>
          <CardHeader title="ACTIVO" />
          <CardBody>
            {activos.length === 0 && <div className="p-6 text-center text-ink-muted">Sin movimientos.</div>}
            {activos.map((r) => <RubroBloque key={r.codigo} rubro={r} />)}
            {activos.length > 0 && (
              <div className="grid grid-cols-[1fr_160px] py-2 px-[10px] bg-surface-row font-bold border-t-2 border-navy-700 text-[13px]">
                <div>TOTAL ACTIVO</div>
                <div className="text-right tabular">{fmtMoney(totales.activo)}</div>
              </div>
            )}
          </CardBody>
        </Card>

        <Card>
          <CardHeader title="PASIVO + PATRIMONIO NETO" />
          <CardBody>
            <div className="text-[11px] font-semibold uppercase tracking-wider text-ink-muted px-[10px] py-1 mt-1">
              Pasivo
            </div>
            {pasivos.length === 0 && <div className="p-4 text-center text-ink-muted text-[12px]">Sin pasivos.</div>}
            {pasivos.map((r) => <RubroBloque key={r.codigo} rubro={r} />)}

            <div className="text-[11px] font-semibold uppercase tracking-wider text-ink-muted px-[10px] py-1 mt-2">
              Patrimonio Neto
            </div>
            {!hayPn && <div className="p-4 text-center text-ink-muted text-[12px]">Sin patrimonio.</div>}
            {patrimonio_neto.map((r) => <RubroBloque key={r.codigo} rubro={r} />)}

            <div className="grid grid-cols-[1fr_160px] py-2 px-[10px] bg-surface-row font-bold border-t-2 border-navy-700 text-[13px]">
              <div>TOTAL PASIVO + PN</div>
              <div className="text-right tabular">{fmtMoney(totales.pasivo_mas_pn)}</div>
            </div>
          </CardBody>
        </Card>
      </div>
    </>
  );
}

function EstadoResultados({ desde, hasta }: { desde: string; hasta: string }) {
  const { data, isLoading } = useQuery<ERResp>({
    queryKey: ['ec-er', desde, hasta],
    queryFn: () => api.get<ERResp>(`/api/erp/estados-contables/resultados?desde=${desde}&hasta=${hasta}`),
  });

  if (isLoading) return <div className="py-10 text-center text-ink-muted"><Loader2 className="w-4 h-4 animate-spin inline mr-2" />Calculando Estado de Resultados…</div>;
  if (!data) return null;

  const { ingresos, egresos, totales, tipo_resultado } = data.data;

  return (
    <>
      <div
        className={`mb-4 px-4 py-3 rounded-md text-[13px] border flex items-center gap-3 ${
          tipo_resultado === 'GANANCIA'
            ? 'bg-success-bg text-success border-success/30'
            : 'bg-danger-bg text-danger border-danger/30'
        }`}
      >
        <strong className="text-[14px]">
          {tipo_resultado === 'GANANCIA' ? 'GANANCIA' : 'PÉRDIDA'} del período:
        </strong>
        <span className="text-[16px] font-bold tabular">{fmtMoney(Math.abs(totales.resultado_neto))}</span>
        {totales.margen_porcentual !== null && (
          <span className="opacity-80 text-[12px]">
            Margen: <strong className="tabular">{totales.margen_porcentual}%</strong>
          </span>
        )}
      </div>

      <Card>
        <CardHeader title="Ingresos" />
        <CardBody>
          {ingresos.length === 0 && <div className="p-6 text-center text-ink-muted">Sin ingresos.</div>}
          {ingresos.map((r) => <RubroBloque key={r.codigo} rubro={r} />)}
          {ingresos.length > 0 && (
            <div className="grid grid-cols-[1fr_160px] py-2 px-[10px] bg-success-bg font-bold border-t-2 border-success text-success text-[13px]">
              <div>TOTAL INGRESOS</div>
              <div className="text-right tabular">{fmtMoney(totales.ingresos)}</div>
            </div>
          )}
        </CardBody>
      </Card>

      <Card>
        <CardHeader title="Egresos" />
        <CardBody>
          {egresos.length === 0 && <div className="p-6 text-center text-ink-muted">Sin egresos.</div>}
          {egresos.map((r) => <RubroBloque key={r.codigo} rubro={r} />)}
          {egresos.length > 0 && (
            <div className="grid grid-cols-[1fr_160px] py-2 px-[10px] bg-danger-bg font-bold border-t-2 border-danger text-danger text-[13px]">
              <div>TOTAL EGRESOS</div>
              <div className="text-right tabular">{fmtMoney(totales.egresos)}</div>
            </div>
          )}
        </CardBody>
      </Card>

      <Card>
        <CardBody>
          <div className="grid grid-cols-[1fr_160px] py-3 px-[10px] bg-navy-800 text-white font-bold text-[14px]">
            <div>RESULTADO NETO DEL PERÍODO</div>
            <div className={`text-right tabular ${totales.resultado_neto < 0 ? 'text-danger' : 'text-success-bg'}`}>
              {fmtMoney(totales.resultado_neto)}
            </div>
          </div>
        </CardBody>
      </Card>
    </>
  );
}

export function EstadosContablesPage() {
  const [tab, setTab] = useState<'sp' | 'er'>('sp');
  const [desde, setDesde] = useState(firstOfYear());
  const [hasta, setHasta] = useState(today());

  return (
    <>
      <div className="flex items-end justify-between mb-[18px]">
        <div>
          <h1 className="text-xl font-semibold text-navy-800 tracking-tight">Estados Contables</h1>
          <p className="text-[12px] text-ink-muted mt-[2px]">Formato RT 8 / RT 9 FACPCE · período {desde} — {hasta}</p>
        </div>
        <div className="flex gap-2">
          <Button variant="secondary"><Download className="w-3 h-3" /> Exportar Excel</Button>
          <Button variant="secondary"><Download className="w-3 h-3" /> Exportar PDF</Button>
        </div>
      </div>

      <div className="flex gap-[6px] bg-white border border-line p-1 rounded-lg w-fit mb-4">
        <button
          onClick={() => setTab('sp')}
          className={`px-3.5 py-1.5 rounded-md text-[12px] font-medium ${
            tab === 'sp' ? 'bg-navy-700 text-white' : 'text-ink-muted hover:text-navy-700'
          }`}
        >
          Situación Patrimonial
        </button>
        <button
          onClick={() => setTab('er')}
          className={`px-3.5 py-1.5 rounded-md text-[12px] font-medium ${
            tab === 'er' ? 'bg-navy-700 text-white' : 'text-ink-muted hover:text-navy-700'
          }`}
        >
          Estado de Resultados
        </button>
      </div>

      <Card>
        <CardBody className="p-3">
          <div className="flex gap-2 items-center text-[12px]">
            <span className="text-ink-muted">{tab === 'sp' ? 'Al:' : 'Desde:'}</span>
            {tab === 'er' && (
              <>
                <input
                  type="date"
                  value={desde}
                  onChange={(e) => setDesde(e.target.value)}
                  className="px-[9px] py-1 text-[12px] border border-line-strong rounded-md bg-white"
                />
                <span className="text-ink-muted text-[11px]">→</span>
              </>
            )}
            <input
              type="date"
              value={hasta}
              onChange={(e) => setHasta(e.target.value)}
              className="px-[9px] py-1 text-[12px] border border-line-strong rounded-md bg-white"
            />
            <Badge variant="info" className="ml-auto">{tab === 'sp' ? 'Saldos al corte' : 'Flujo del período'}</Badge>
          </div>
        </CardBody>
      </Card>

      {tab === 'sp' && <SituacionPatrimonial desde={desde} hasta={hasta} />}
      {tab === 'er' && <EstadoResultados desde={desde} hasta={hasta} />}
    </>
  );
}
