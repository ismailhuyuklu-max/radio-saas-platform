/**
 * Faz H2-2 — API zarf normalleştirici.
 *
 * Backend'in bazı endpoint'leri tarihsel olarak düz dizi/obje döndürür
 * (örn. `GET /users` → `User[]`, `GET /audit/logs` → `AuditLogItem[]`),
 * bazıları `{code, result, message}` ile zarflar (örn. `GET /portal/me`).
 * Frontend kontrat kırılganlığını ortadan kaldırmak için **her dönüşü tek
 * helper'dan geçir**: zarflı geldiyse `result`'u çıkar, düz geldiyse
 * olduğu gibi döndür.
 *
 * Hatalı/eksik (HTML 200, undefined, null) yanıtları yakalamaz — bu
 * `request.ts`'in işi (Faz H1-2). Burası sadece şeklini normalleştirir.
 */

/** Backend zarfı: `{code: number, result: T, message?: string}` */
export interface Envelope<T> {
  code: number;
  result: T;
  message?: string;
}

/** Zarflı obje şekli mi? — `code` ve `result` alanlarının ikisi de varsa. */
function isEnvelope<T>(value: unknown): value is Envelope<T> {
  return (
    !!value
    && typeof value === 'object'
    && 'code' in (value as Record<string, unknown>)
    && 'result' in (value as Record<string, unknown>)
  );
}

/**
 * Eğer yanıt `{code,result,message}` ise `result`'u, değilse yanıtın
 * kendisini döndür. Düz dizi / obje endpoint'leri olduğu gibi geçer.
 */
export function unwrap<T>(response: unknown): T {
  if (isEnvelope<T>(response)) {
    return response.result;
  }
  return response as T;
}

/**
 * Liste endpoint'leri için katı normalleştirici: zarflıysa `result.X`'i
 * (örn. `result.logs`, `result.items`) çıkar, dizi değilse `[]` döndür.
 *
 *   normalizeList<User>(res, 'users')
 *
 * Backend zarflı `{code:0, result:{users:[]}}` veya düz `User[]` her ikisi
 * de aynı sonucu verir.
 */
export function normalizeList<T>(response: unknown, key?: string): T[] {
  if (Array.isArray(response)) {
    return response as T[];
  }
  // Zarflı obje
  if (response && typeof response === 'object' && 'result' in (response as Record<string, unknown>)) {
    const inner = (response as Envelope<unknown>).result;
    if (Array.isArray(inner)) {
      return inner as T[];
    }
    if (key && inner && typeof inner === 'object' && key in (inner as Record<string, unknown>)) {
      const arr = (inner as Record<string, unknown>)[key];
      if (Array.isArray(arr)) return arr as T[];
    }
  }
  // Zarfsız obje + tek key (örn. backend `{logs:[...]}` döndürdü)
  if (key && response && typeof response === 'object' && key in (response as Record<string, unknown>)) {
    const arr = (response as Record<string, unknown>)[key];
    if (Array.isArray(arr)) return arr as T[];
  }
  return [];
}
