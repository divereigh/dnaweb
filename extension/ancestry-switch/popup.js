const $ = (id) => document.getElementById(id);

async function renderStatus() {
    const s = await chrome.runtime.sendMessage({ type: 'status' });
    $('status').textContent = s.borrowed
        ? `Ancestry is logged in as ${s.borrowed} (borrowed).`
        : s.account
          ? 'Ancestry is on your own login.'
          : 'Not logged in to Ancestry.';
    $('restoreWrap').hidden = !s.hasOwn;
    $('signoutWarn').hidden = !s.borrowed;
}

$('restore').onclick = async () => {
    await chrome.runtime.sendMessage({ type: 'restore' });
    renderStatus();
};

renderStatus();
