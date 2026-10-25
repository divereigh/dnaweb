// Runs on dnaweb pages (registered from the popup for whichever origins you
// add there). Catches clicks on Ancestry links before the browser follows
// them, asks dnaweb which account the link needs, and hands the lot to the
// background worker, which swaps the Ancestry cookies and only then opens
// the tab — opening first would load the page under the wrong login.
//
// The fetch is same-origin from the page, so it carries the dnaweb login
// cookie and needs no CORS or host permission of its own.

const ANCESTRY = /^https?:\/\/([\w-]+\.)*ancestry\.com(\.au)?\//i;

async function handle(e) {
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

    chrome.runtime.sendMessage({ type: 'open', url: a.href, active, info });
}

document.addEventListener('click', handle, true);
document.addEventListener('auxclick', handle, true);
