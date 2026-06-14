// Pick black or white text for legibility against a hex background.
// Rec. 601 luma; threshold tuned so mid-tones read with dark text.
// Used by tree pills, the swatch picker, and the tree side panel so
// they all decide contrast the same way.
export function contrastText(hex) {
    const h = (hex || '#ffffff').replace('#', '');
    if (h.length !== 6) return '#1c1917';
    const r = parseInt(h.slice(0, 2), 16);
    const g = parseInt(h.slice(2, 4), 16);
    const b = parseInt(h.slice(4, 6), 16);
    return (0.299 * r + 0.587 * g + 0.114 * b) / 255 > 0.6 ? '#1c1917' : '#ffffff';
}
