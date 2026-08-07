/**
 * Formats PascalCase workflow states into readable labels.
 * e.g. NoaGenerated → NOA Generated, EdoReleased → EDO Released
 */
export function formatWorkflowState(state: string): string {
  if (!state) return '—';
  const spaced = state
    .replace(/([a-z\d])([A-Z])/g, '$1 $2')
    .replace(/([A-Z]+)([A-Z][a-z])/g, '$1 $2');
  return spaced
    .replace(/\bNoa\b/gi, 'NOA')
    .replace(/\bBl\b/gi, 'BL')
    .replace(/\bEdo\b/gi, 'eDO')
    .replace(/\bCro\b/gi, 'CRO')
    .replace(/\bSas\b/gi, 'SAS')
    .replace(/\bCy\b/gi, 'CY');
}
