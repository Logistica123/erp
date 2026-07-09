/**
 * Parser de importes tolerante a formatos es-AR y US (mismo criterio que el
 * form de Recibos). Maneja pegados desde PDF/Excel:
 *   "14.782,45" → 14782.45 · "14,782.45" → 14782.45 · "14782,45" → 14782.45
 *   "13.000" → 13000 (miles AR) · "56.78" → 56.78 (decimal)
 */
export function parseMontoEs(s: string | number | null | undefined): number {
  if (s === null || s === undefined || s === '') return 0;
  if (typeof s === 'number') return s;
  const str = String(s).replace(/[\s$ ]/g, '').trim();
  if (!str) return 0;
  const tieneComa = str.includes(',');
  const tienePunto = str.includes('.');

  // Ambos: el separador más a la derecha es el decimal.
  if (tieneComa && tienePunto) {
    const lastComma = str.lastIndexOf(',');
    const lastDot = str.lastIndexOf('.');
    if (lastComma > lastDot) {
      return Number(str.replace(/\./g, '').replace(',', '.')) || 0; // AR
    }
    return Number(str.replace(/,/g, '')) || 0; // US
  }

  // Solo coma: decimal salvo grupos múltiples.
  if (tieneComa) {
    const parts = str.split(',');
    if (parts.length > 2) return Number(str.replace(/,/g, '')) || 0;
    return Number(str.replace(',', '.')) || 0;
  }

  // Solo punto: 2+ puntos = miles; 1 punto con exactamente 3 dígitos atrás = miles AR.
  if (tienePunto) {
    const parts = str.split('.');
    if (parts.length > 2) return Number(str.replace(/\./g, '')) || 0;
    const trailing = parts[1] ?? '';
    if (trailing.length === 3 && /^\d+$/.test(parts[0]) && parts[0].length >= 1) {
      return Number(str.replace('.', '')) || 0;
    }
    return Number(str) || 0;
  }

  return Number(str) || 0;
}
