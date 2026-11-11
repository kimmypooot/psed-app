/**
 * The one semantic tone palette for badges and pills, so `Badge` (tags,
 * rounded-md) and `StatusBadge` (status pills, rounded-full) can differ in
 * shape but never in what "success" or "danger" looks like.
 */
export const toneClasses = {
    success: 'bg-success-bg text-success ring-1 ring-inset ring-success-border',
    warning: 'bg-warning-bg text-warning ring-1 ring-inset ring-warning-border',
    danger: 'bg-danger-bg text-danger ring-1 ring-inset ring-danger-border',
    info: 'bg-info-bg text-info ring-1 ring-inset ring-info-border',
    neutral: 'bg-surface-muted text-text-muted ring-1 ring-inset ring-border',
};

export const tones = Object.keys(toneClasses);
