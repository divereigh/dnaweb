// Runs on dnaweb pages (the hosts in manifest.json's content_scripts —
// add one there if dnaweb moves). Catches clicks on Ancestry links before the browser follows
// them, asks dnaweb which account the link needs, and hands the lot to the
// background worker, which swaps the Ancestry cookies and only then opens
// the tab — opening first would load the page under the wrong login.
//
// The fetch is same-origin from the page, so it carries the dnaweb login
// cookie and needs no CORS or host permission of its own.

const ANCESTRY = /^https?:\/\/([\w-]+\.)*ancestry\.com(\.au)?\//i;

// Reloading or updating the extension orphans this script in every tab
// that was already open: it keeps running, but chrome.runtime is dead and
// sendMessage throws "Extension context invalidated". Swallowing the click
// and then failing to send left the link doing nothing at all, so an
// orphan stands aside and lets the browser follow links itself.
function orphaned() {
    return !chrome.runtime?.id;
}

function detach() {
    document.removeEventListener('click', handle, true);
    document.removeEventListener('auxclick', handle, true);
}

async function handle(e) {
    if (orphaned()) return detach();
    if (e.defaultPrevented || (e.type === 'auxclick' && e.button !== 1)) return;
    if (e.type === 'click' && e.button !== 0) return;
    const a = e.target.closest?.('a[href]');
    if (!a || !ANCESTRY.test(a.href)) return;

    e.preventDefault();
    e.stopPropagation();
    // Middle-click and ctrl/cmd-click keep their usual "open behind" meaning.
    const active = !(e.button === 1 || e.ctrlKey || e.metaKey);

    let info = null;
    try {
        const r = await fetch('/ancestry/session?url=' + encodeURIComponent(a.href), {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        // 403 = not on ANCESTRY_SWITCH_EMAILS; 401 = logged out. Either way
        // just open the link under whatever login the browser already has.
        if (r.ok) info = await r.json();
    } catch {
        // dnaweb unreachable — same fallback.
    }

    try {
        await chrome.runtime.sendMessage({ type: 'open', url: a.href, active, info });
    } catch {
        // Orphaned while the fetch was in flight: the click is already
        // swallowed, so open the link the plain way.
        detach();
        window.open(a.href, '_blank', 'noopener');
    }
}

// So "is it running on this page?" has an answer: check
// document.documentElement.dataset.ancestrySwitch in the console.
document.documentElement.dataset.ancestrySwitch = chrome.runtime.getManifest().version;

document.addEventListener('click', handle, true);
document.addEventListener('auxclick', handle, true);
