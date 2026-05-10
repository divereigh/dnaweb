// Cluster ink palette — natural-history pigments. Returns CSS variable names.
// Palette is defined in resources/css/app.css under :root.

export function clusterStyle(cls) {
    if (!cls) return null;
    if (!/^cluster-(\d+)$/.test(cls)) return null;
    return {
        fg: `var(--${cls}-fg, var(--cluster-default-fg))`,
        bg: `var(--${cls}-bg, var(--cluster-default-bg))`,
    };
}
