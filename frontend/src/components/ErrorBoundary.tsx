import { Component, type ErrorInfo, type ReactNode } from 'react';
import { AlertTriangle, RefreshCw } from 'lucide-react';

/**
 * ErrorBoundary global (v1.26).
 *
 * Convierte el "pantalla blanca" silencioso de React (crash post-mount) en un
 * mensaje útil + botón para recargar. El detalle técnico queda en la consola
 * del browser para diagnosticar después.
 *
 * Antes (v1.17 → v1.24): un crash en cualquier componente del árbol dejaba
 * la pantalla completamente en blanco — el usuario solo veía que "la página
 * dejó de funcionar" sin pistas. El caso real que motivó esto fue el form
 * de carga manual de compras post v1.24.
 */
type Props = { children: ReactNode };
type State = { hasError: boolean; error: Error | null };

export class ErrorBoundary extends Component<Props, State> {
  constructor(props: Props) {
    super(props);
    this.state = { hasError: false, error: null };
  }

  static getDerivedStateFromError(error: Error): State {
    return { hasError: true, error };
  }

  componentDidCatch(error: Error, info: ErrorInfo) {
    // eslint-disable-next-line no-console
    console.error('[ErrorBoundary]', error, info.componentStack);
  }

  reset = () => this.setState({ hasError: false, error: null });

  render() {
    if (!this.state.hasError) return this.props.children;

    return (
      <div className="p-6">
        <div className="max-w-2xl mx-auto border border-red-200 bg-red-50 rounded-lg p-6">
          <div className="flex items-start gap-3">
            <AlertTriangle className="w-5 h-5 text-red-700 mt-0.5 flex-shrink-0" />
            <div className="flex-1 space-y-3">
              <div>
                <h2 className="text-[15px] font-bold text-red-900">
                  Algo salió mal en esta pantalla
                </h2>
                <p className="text-[12px] text-red-800 mt-1">
                  Hubo un error inesperado renderizando la página. Tus datos están
                  seguros — no se afectó nada del servidor.
                </p>
              </div>

              {this.state.error && (
                <>
                  {/* Mensaje siempre visible — sin click para verlo */}
                  <div className="text-[11.5px] text-red-900 bg-red-100 border border-red-200 rounded p-2 font-mono">
                    {this.state.error.message}
                  </div>
                  <details className="text-[11px] text-red-900">
                    <summary className="cursor-pointer font-medium">Stack trace completo</summary>
                    <pre className="mt-2 p-2 bg-red-100 rounded overflow-x-auto whitespace-pre-wrap font-mono text-[10.5px]">
                      {this.state.error.stack && this.state.error.stack.split('\n').slice(0, 15).join('\n')}
                    </pre>
                  </details>
                </>
              )}

              <div className="flex gap-2 pt-1">
                <button
                  type="button"
                  onClick={() => window.location.reload()}
                  className="inline-flex items-center gap-1.5 text-[12px] bg-red-600 text-white px-3 py-1.5 rounded hover:bg-red-700"
                >
                  <RefreshCw className="w-3 h-3" /> Recargar
                </button>
                <button
                  type="button"
                  onClick={this.reset}
                  className="text-[12px] text-red-700 px-3 py-1.5 rounded border border-red-300 hover:bg-red-100"
                >
                  Reintentar sin recargar
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
    );
  }
}
