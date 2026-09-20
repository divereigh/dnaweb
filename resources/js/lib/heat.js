// Shared-DNA heat bands for the tree view.
//
// The breaks are the ones genealogists already read a cM figure against:
// 1,300+ can only be a parent, child or full sibling, and the bands below are
// the usual cousin ranges. They are deliberately not evenly spaced — shared
// DNA roughly halves with each step of relationship, so even bands would put
// every real relative in one colour.
//
// Band 0 is exactly zero: a kit in this tree that shares no DNA with the
// reference at all. Everything above it takes a fill, so any colour on a row
// means "these two are related".
//
// Colours live in app.css as .heat-0 … .heat-6 — see the note there for why
// they sit outside @layer components, and why band 0 is an outline.
export const HEAT_BANDS = [
    { min: 1300, band: 6, label: '1,300+' },
    { min: 400, band: 5, label: '400–1,299' },
    { min: 200, band: 4, label: '200–399' },
    { min: 90, band: 3, label: '90–199' },
    { min: 40, band: 2, label: '40–89' },
    { min: 1, band: 1, label: '1–39' },
    { min: 0, band: 0, label: '0' },
];

export function heatBand(cm) {
    if (cm == null) return null;
    return HEAT_BANDS.find((b) => cm >= b.min)?.band ?? 0;
}

export function heatClass(cm) {
    return `heat-${heatBand(cm)}`;
}
