import { describe, expect, it } from 'vitest';
import { sortByUrgency } from '../../js/utils/urgency.js';

describe('sortByUrgency', () => {
  it('orders lower urgency before higher urgency', () => {
    const sorted = sortByUrgency([
      { urgency: 40, dueDate: '2026-07-30', label: 'later' },
      { urgency: 10, dueDate: '2026-07-26', label: 'soon' },
      { urgency: 30, dueDate: '2026-07-20', label: 'late' },
    ]);
    expect(sorted.map((i) => i.label)).toEqual(['soon', 'late', 'later']);
  });

  it('breaks ties by dueDate ascending', () => {
    const sorted = sortByUrgency([
      { urgency: 10, dueDate: '2026-08-02', label: 'b' },
      { urgency: 10, dueDate: '2026-07-28', label: 'a' },
    ]);
    expect(sorted.map((i) => i.label)).toEqual(['a', 'b']);
  });

  it('does not mutate the input array', () => {
    const input = [
      { urgency: 20, dueDate: '2026-07-29' },
      { urgency: 10, dueDate: '2026-07-28' },
    ];
    const copy = [...input];
    sortByUrgency(input);
    expect(input).toEqual(copy);
  });
});
