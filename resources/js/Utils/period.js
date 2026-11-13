// A report period as the pages pass it around: a year, a quarter, a month or a range of dates.
// { kind: 'year' | 'quarter' | 'month' | 'custom', year, quarter, month, from, to } (all strings).

const pad = (n) => String(n).padStart(2, '0');

/** A period for `kind` around a date (an ISO string, "2026-11-13"). */
export const defaultPeriod = (iso, kind = 'year') => {
    const d = new Date(`${iso}T00:00:00`);

    return {
        kind,
        year: String(d.getFullYear()),
        quarter: String(Math.floor(d.getMonth() / 3) + 1),
        month: String(d.getMonth() + 1),
        from: `${d.getFullYear()}-01-01`,
        to: iso,
    };
};

/** What the server asks for, as a query string: only the fields the kind uses. */
export const periodParams = (p) => {
    const params = new URLSearchParams({ kind: p.kind });

    if (p.kind === 'custom') {
        params.set('from', p.from);
        params.set('to', p.to);

        return params;
    }

    params.set('year', p.year);
    if (p.kind === 'quarter') params.set('quarter', p.quarter);
    if (p.kind === 'month') params.set('month', p.month);

    return params;
};

/**
 * From the first day of a month to the last day of a month, in one year: the only range a report with a
 * column for each month (the IPMR and MRAP) can show.
 */
export const wholeMonthsInOneYear = (p) => {
    if (p.kind !== 'custom') return true;
    if (!p.from || !p.to) return false;

    const from = new Date(`${p.from}T00:00:00`);
    const to = new Date(`${p.to}T00:00:00`);
    const lastDay = new Date(to.getFullYear(), to.getMonth() + 1, 0).getDate();

    return from.getFullYear() === to.getFullYear() && from.getDate() === 1 && to.getDate() === lastDay && to >= from;
};

export const isoDate = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
