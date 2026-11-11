// Short words kept lowercase inside a title (articles, conjunctions,
// short prepositions), as in "Returned for Correction" or "Head of Office".
const MINOR_WORDS = new Set(['a', 'an', 'and', 'as', 'at', 'but', 'by', 'for', 'in', 'nor', 'of', 'on', 'or', 'per', 'the', 'to', 'via', 'vs']);

/**
 * Title Case for statuses and other short labels: every word capitalized
 * except minor words that aren't first or last. Words already holding a
 * capital (acronyms such as HR, PDS or IPCR, or names) are left as they are.
 * Hyphenated words are capitalized on each part, minor words aside
 * ("Face-to-Face", "Self-Paced").
 */
export function titleCase(value) {
    if (!value) return value;

    const words = String(value).trim().split(/\s+/);

    return words
        .map((word, index) => {
            if (/[A-Z]/.test(word.slice(1)) || /^[A-Z]{2,}/.test(word)) return word;

            const lower = word.toLowerCase();
            const edge = index === 0 || index === words.length - 1;
            if (!edge && MINOR_WORDS.has(lower)) return lower;

            return lower
                .split('-')
                .map((part, at) => (at > 0 && MINOR_WORDS.has(part) ? part : part.charAt(0).toUpperCase() + part.slice(1)))
                .join('-');
        })
        .join(' ');
}
