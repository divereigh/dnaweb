// Put text on the clipboard, resolving true when it actually landed.
//
// Two paths on purpose. navigator.clipboard is the real one, but it
// needs a secure context and a permission the browser can refuse, so it
// is absent over plain http and can reject even where it exists. The
// hidden-textarea trick is deprecated and synchronous, but it works in
// both of those cases — and it costs a dozen lines to not have a copy
// button that silently does nothing.
//
// Callers get a boolean rather than a thrown error because the only
// sensible response to a failed copy is "don't show the tick".
export async function copyText(value) {
    const text = String(value ?? '');
    if (! text) return false;

    try {
        await navigator.clipboard.writeText(text);

        return true;
    } catch {
        return legacyCopy(text);
    }
}

function legacyCopy(text) {
    const el = document.createElement('textarea');
    el.value = text;
    el.setAttribute('readonly', '');
    // Off-screen but still focusable — display:none or visibility:hidden
    // would make select() a no-op. `fixed` keeps it from scrolling the
    // page when focus lands on it.
    el.style.position = 'fixed';
    el.style.top = '0';
    el.style.opacity = '0';
    el.style.pointerEvents = 'none';

    document.body.appendChild(el);
    el.select();

    let ok = false;
    try {
        ok = document.execCommand('copy');
    } catch {
        ok = false;
    }
    el.remove();

    return ok;
}
