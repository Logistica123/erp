import { useState, useMemo, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { FileUp, ArrowLeft, SkipForward, CheckCircle2, FileText } from 'lucide-react';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { FacturaVentaForm, type FacturaVentaFormValues } from '@/components/forms/FacturaVentaForm';

/**
 * v1.39 — Wizard de importación batch de PDFs de Ventas AFIP.
 *
 * Flujo:
 *   Step 0 — multi-file picker (acepta N PDFs)
 *   Step 1..N — para cada PDF, muestra el form de carga manual con el PDF
 *               embebido en un iframe al costado. Botones:
 *                 · "Guardar y siguiente" → registra + avanza
 *                 · "Saltar este PDF" → marca como omitido + avanza
 *   Step final — resumen: N cargadas, M saltadas.
 *
 * Decisión de scope (2026-05-19): no parseamos el PDF. El operador llena el
 * form a mano mirando el PDF al costado. Reduce fragilidad ante cambios de
 * formato AFIP y permite arrancar a usar el feature hoy mismo.
 */

type Estado = 'pendiente' | 'cargado' | 'saltado';

type Item = {
  file: File;
  estado: Estado;
  facturaId?: number;
  // Preservados entre PDFs si el usuario los confirmó:
  preservados?: Partial<FacturaVentaFormValues>;
};

export function ImportVentasPdfWizardPage() {
  const navigate = useNavigate();
  const [items, setItems] = useState<Item[]>([]);
  const [currentIdx, setCurrentIdx] = useState(0);
  const [finished, setFinished] = useState(false);

  // ObjectURL del PDF actual — se revoca al cambiar/desmontar para no leakear memoria.
  const currentItem = items[currentIdx];
  const currentPdfUrl = useMemo(() => {
    if (!currentItem) return null;
    return URL.createObjectURL(currentItem.file);
  }, [currentItem]);

  useEffect(() => {
    return () => {
      if (currentPdfUrl) URL.revokeObjectURL(currentPdfUrl);
    };
  }, [currentPdfUrl]);

  const onPickFiles = (files: FileList | null) => {
    if (!files) return;
    const pdfs = Array.from(files).filter((f) => f.type === 'application/pdf' || f.name.toLowerCase().endsWith('.pdf'));
    setItems(pdfs.map((file) => ({ file, estado: 'pendiente' as Estado })));
    setCurrentIdx(0);
    setFinished(false);
  };

  const advance = () => {
    const next = currentIdx + 1;
    if (next >= items.length) {
      setFinished(true);
    } else {
      setCurrentIdx(next);
    }
  };

  const onFormSuccess = (facturaId: number, values: FacturaVentaFormValues) => {
    setItems((prev) => prev.map((it, i) =>
      i === currentIdx
        ? { ...it, estado: 'cargado', facturaId, preservados: {
            tipo_comprobante_id: values.tipo_comprobante_id,
            fecha_emision: values.fecha_emision,
            punto_venta: values.punto_venta,
          } }
        : it
    ));
    advance();
  };

  const onSkipCurrent = () => {
    setItems((prev) => prev.map((it, i) =>
      i === currentIdx ? { ...it, estado: 'saltado' } : it
    ));
    advance();
  };

  const stats = useMemo(() => ({
    total: items.length,
    cargados: items.filter((i) => i.estado === 'cargado').length,
    saltados: items.filter((i) => i.estado === 'saltado').length,
    pendientes: items.filter((i) => i.estado === 'pendiente').length,
  }), [items]);

  // Step 0 — picker.
  if (items.length === 0) {
    return (
      <div className="p-6 max-w-3xl space-y-4">
        <div className="flex items-center gap-2 text-[13px] text-ink-muted">
          <button onClick={() => navigate('/erp/facturas-venta')} className="hover:text-ink-2 flex items-center gap-1">
            <ArrowLeft className="w-3 h-3" /> Facturación
          </button>
          <span>›</span>
          <span className="text-ink-2 font-medium">Importar PDFs AFIP</span>
        </div>

        <Card>
          <CardHeader title={
            <div className="flex items-center gap-2">
              <FileUp className="w-4 h-4 text-azure" /> Importar facturas de venta desde PDFs de AFIP
            </div>
          } />
          <CardBody className="p-6 space-y-4">
            <div className="border border-info/30 bg-info-bg/20 rounded-md p-3 text-[12px]">
              <strong>Cómo funciona</strong>: seleccioná varios PDFs descargados del portal AFIP
              (Mis Comprobantes › Emitidos). Para cada uno se va a abrir el formulario de carga
              manual con el PDF al costado para que llenes los datos mirándolo. Las facturas se
              registran con <code>origen=MANUAL</code> y el PDF queda como adjunto.
              <div className="mt-2 text-ink-muted">
                Si el CUIT del cliente no existe, se crea automáticamente. No baja stock ni
                impacta DistriApp — solo alimenta la CC del cliente y el Libro IVA Ventas.
              </div>
            </div>

            <label className="block">
              <span className="text-[12px] font-semibold text-ink-2">Seleccionar PDFs</span>
              <input
                type="file"
                accept=".pdf,application/pdf"
                multiple
                onChange={(e) => onPickFiles(e.target.files)}
                className="mt-2 block w-full text-[12px] file:mr-3 file:py-2 file:px-4 file:border-0
                  file:rounded-md file:bg-azure file:text-white file:cursor-pointer
                  file:hover:bg-azure-dark cursor-pointer"
              />
            </label>
          </CardBody>
        </Card>
      </div>
    );
  }

  // Step final — resumen.
  if (finished) {
    return (
      <div className="p-6 max-w-3xl space-y-4">
        <Card>
          <CardHeader title={
            <div className="flex items-center gap-2">
              <CheckCircle2 className="w-4 h-4 text-success" /> Importación finalizada
            </div>
          } />
          <CardBody className="p-6 space-y-4">
            <div className="grid grid-cols-3 gap-3 text-center">
              <div className="bg-surface-row border border-line rounded-md p-3">
                <div className="text-[24px] font-bold text-navy-800">{stats.total}</div>
                <div className="text-[11px] text-ink-muted uppercase tracking-wide">PDFs totales</div>
              </div>
              <div className="bg-success-bg/20 border border-success/30 rounded-md p-3">
                <div className="text-[24px] font-bold text-success">{stats.cargados}</div>
                <div className="text-[11px] text-ink-muted uppercase tracking-wide">Cargadas</div>
              </div>
              <div className="bg-warning-bg/20 border border-warning/30 rounded-md p-3">
                <div className="text-[24px] font-bold text-warning">{stats.saltados}</div>
                <div className="text-[11px] text-ink-muted uppercase tracking-wide">Saltadas</div>
              </div>
            </div>

            <div className="border-t border-line pt-3">
              <h4 className="text-[12px] font-semibold mb-2">Detalle</h4>
              <div className="space-y-1 text-[11.5px] max-h-64 overflow-y-auto">
                {items.map((it, i) => (
                  <div key={i} className="flex items-center justify-between border-b border-line/40 py-1">
                    <div className="flex items-center gap-2 truncate">
                      <FileText className="w-3.5 h-3.5 text-ink-muted shrink-0" />
                      <span className="truncate">{it.file.name}</span>
                    </div>
                    <span className={`text-[10.5px] px-2 py-0.5 rounded uppercase ${
                      it.estado === 'cargado' ? 'bg-success/10 text-success' :
                      it.estado === 'saltado' ? 'bg-warning/10 text-warning' :
                      'bg-ink-muted/10 text-ink-muted'
                    }`}>
                      {it.estado === 'cargado' ? `OK #${it.facturaId}` :
                       it.estado === 'saltado' ? 'Saltada' : 'Pendiente'}
                    </span>
                  </div>
                ))}
              </div>
            </div>

            <div className="flex justify-end gap-2 border-t border-line pt-3">
              <Button variant="outline" onClick={() => { setItems([]); setFinished(false); setCurrentIdx(0); }}>
                Importar más PDFs
              </Button>
              <Button variant="primary" onClick={() => navigate('/erp/facturas-venta')}>
                Ir a Facturas de Venta
              </Button>
            </div>
          </CardBody>
        </Card>
      </div>
    );
  }

  // Steps 1..N — form + iframe.
  const prevPreservados = currentIdx > 0 ? items[currentIdx - 1]?.preservados : undefined;

  return (
    <div className="p-4 space-y-3">
      <div className="flex items-center gap-2 text-[13px] text-ink-muted">
        <button onClick={() => navigate('/erp/facturas-venta')} className="hover:text-ink-2 flex items-center gap-1">
          <ArrowLeft className="w-3 h-3" /> Facturación
        </button>
        <span>›</span>
        <span className="text-ink-2 font-medium">
          Importar PDFs · {currentIdx + 1} de {items.length}
        </span>
        <span className="ml-auto text-[11.5px]">
          <span className="text-success">{stats.cargados} OK</span>
          {' · '}
          <span className="text-warning">{stats.saltados} saltadas</span>
          {' · '}
          <span className="text-ink-muted">{stats.pendientes} pendientes</span>
        </span>
      </div>

      <div className="grid grid-cols-2 gap-3" style={{ minHeight: 'calc(100vh - 140px)' }}>
        <Card className="overflow-hidden">
          <CardHeader title={
            <div className="flex items-center gap-2 truncate">
              <FileText className="w-4 h-4 text-azure shrink-0" />
              <span className="truncate text-[12.5px]">{currentItem?.file.name}</span>
            </div>
          } />
          <CardBody className="p-0">
            {currentPdfUrl && (
              <iframe
                src={currentPdfUrl}
                title="PDF AFIP"
                className="w-full border-0"
                style={{ height: 'calc(100vh - 200px)' }}
              />
            )}
          </CardBody>
        </Card>

        <Card>
          <CardHeader title={
            <div className="text-[12.5px]">
              Cargá los datos mirando el PDF a la izquierda
            </div>
          } />
          <CardBody className="p-3 overflow-y-auto" style={{ maxHeight: 'calc(100vh - 200px)' }}>
            <FacturaVentaForm
              key={currentIdx}
              pdfFile={currentItem?.file}
              initialValues={prevPreservados}
              showCargarOtro={false}
              submitLabel="Guardar y siguiente"
              onSuccess={onFormSuccess}
              extraActions={
                <Button variant="secondary" onClick={onSkipCurrent}>
                  <SkipForward className="w-3.5 h-3.5" /> Saltar este PDF
                </Button>
              }
            />
          </CardBody>
        </Card>
      </div>
    </div>
  );
}
