const $ = (id) => document.getElementById(id);

async function renderStatus() {
    const s = await chrome.runtime.sendMessage({ type: 'status' });
    $('status').textContent = s.borrowed
        ? `Ancestry is logged in as ${s.borrowed} (borrowed).`
        : s.account
          ? 'Ancestry is on your own login.'
          : 'Not logged in to Ancestry.';
    // With nothing stashed, Restore just empties the jar: that leaves this
    // browser signed out without telling Ancestry, so the loaders' session
    // survives, and you sign back in as yourself.
    $('restoreWrap').hidden = !s.hasOwn && !s.borrowed;
    $('restore').textContent = s.hasOwn
        ? 'Restore my own Ancestry login'
        : 'Leave the borrowed login (then sign in as yourself)';
    $('signoutWarn').hidden = !s.borrowed;
}

$('restore').onclick = async () => {
    await chrome.runtime.sendMessage({ type: 'restore' });
    renderStatus();
};

renderStatus();
