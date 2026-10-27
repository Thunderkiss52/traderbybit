/** A successful HTTP status is insufficient without the contracted payload. */
export function requirePayload(value: unknown, fields: string[]): Record<string, any> {
  if (!value || typeof value !== 'object' || Array.isArray(value)
    || fields.some(field => !(field in value) || (value as Record<string, unknown>)[field] == null)) {
    throw new Error('UNAVAILABLE: invalid profitability response payload');
  }
  return value as Record<string, any>;
}
