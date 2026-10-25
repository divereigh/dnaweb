// The dnaweb origins aren't baked into the manifest (production's hostname
// lives only in its nginx config), so they're added here: each one is a
// runtime host-permission grant plus a dynamically registered content script.

const $ = (id) => document.getElementById(id);

async function origins() {
    return (await chrome.storage.local.get('origins')).origins ?? [];
}

async function register(list) {
    const existing = await chrome.scripting.getRegisteredContentScripts({ ids: ['dnaweb'] });
    if (existing.length) await chrome.scripting.unregisterContentScripts({ ids: ['dnaweb'] });
    if (list.length) {
        await chrome.scripting.registerContentScripts([
            { id: 'dnaweb', matches: list.map((o) => `${o}/*`), js: ['content.js'], runAt: 'document_start', persistAcrossSessions: true },
        ]);
    }
    await chrome.storage.local.set({ origins: list });
}

async function renderSites() {
    const ul = $('sites');
    ul.replaceChildren();
    for (const o of await origins()) {
        const li = document.createElement('li');
        const span = document.createElement('span');
        span.textContent = o;
        const rm = document.createElement('button');
        rm.textContent = 'Remove';
        rm.onclick = async () => {
            await register((await origins()).filter((x) => x !== o));
            await chrome.permissions.remove({ origins: [`${o}/*`] });
            renderSites();
        };
        li.append(span, rm);
        ul.append(li);
    }
    if (!ul.children.length) ul.innerHTML = '<li class="muted">None yet — add your dnaweb address.</li>';
}

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

$('add').onclick = async () => {
    $('err').textContent = '';
    let origin;
    try {
        origin = new URL($('origin').value.trim()).origin;
    } catch {
        $('err').textContent = 'Enter a full address, e.g. https://dnaweb.example.com';
        return;
    }
    // Must run straight from the click: permissions.request needs the user gesture.
    const granted = await chrome.permissions.request({ origins: [`${origin}/*`] });
    if (!granted) return;
    const list = await origins();
    if (!list.includes(origin)) await register([...list, origin]);
    $('origin').value = '';
    renderSites();
};

$('restore').onclick = async () => {
    await chrome.runtime.sendMessage({ type: 'restore' });
    renderStatus();
};

renderSites();
renderStatus();
