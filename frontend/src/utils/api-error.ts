/**
 * Pulls a human-readable message out of an API error.
 *
 * The request client throws an Error whose `message` is the raw JSON body
 * returned by the backend (e.g. `{"error":"slot conflict"}`). This unwraps
 * that body to the `error`/`message` field, or returns null when the error
 * is not a structured API error.
 */
export function extractApiError(error: unknown): string | null {
  if (error instanceof Error && error.message) {
    try {
      const parsed = JSON.parse(error.message) as {
        error?: string;
        message?: string;
      };
      if (parsed?.error || parsed?.message) {
        return String(parsed.error ?? parsed.message);
      }
    } catch {
      /* message was not JSON — fall through */
    }
  }
  return null;
}

/** True when an API error looks like a 409 scheduling/slot conflict. */
export function isConflictError(error: unknown): boolean {
  const msg = extractApiError(error) ?? '';
  return /çakış|conflict/i.test(msg);
}
