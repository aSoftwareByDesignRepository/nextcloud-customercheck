/**
 * Today urgency sort — lower urgency first (matches TodayService PHP usort).
 *
 * @param {Array<{urgency?: number, dueDate?: string|null}>} items
 * @returns {Array<{urgency?: number, dueDate?: string|null}>}
 */
export function sortByUrgency(items) {
  return [...items].sort((a, b) => {
    const u = (a.urgency ?? 0) - (b.urgency ?? 0);
    if (u !== 0) {
      return u;
    }
    return String(a.dueDate ?? '').localeCompare(String(b.dueDate ?? ''));
  });
}
