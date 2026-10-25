// Swaps the browser's Ancestry cookies between accounts.
//
// A browser profile has one cookie jar, so "be Terrill on Ancestry" means
// replacing every Ancestry cookie with the ones load-cookie.pl stored for
// Terrill's session. Before the first swap away from your own login, that
// login's cookies are stashed in chrome.storage.local so the popup can put
// them back — otherwise getting your own account back means signing out,
// and signing out of a borrowed login would kill the loaders' session.

const DOMAINS = ['ancestry.com.au', 'ancestry.com'];

async function ancestryCookies() {
    const lists = await Promise.all(DOMAINS.map((domain) => chrome.cookies.getAll({ domain })));
    // getAll({domain:'ancestry.com'}) does not match ancestry.com.au, but
    // dedupe anyway in case Chrome ever changes its mind.
    const seen = new Set();
    return lists.flat().filter((c) => {
        const k = `${c.domain}|${c.path}|${c.name}`;
        return seen.has(k) ? false : seen.add(k);
    });
}

function cookieUrl(c) {
    return `http${c.secure ? 's' : ''}://${c.domain.replace(/^\./, '')}${c.path}`;
}

async function clearAncestry() {
    for (const c of await ancestryCookies()) {
        await chrome.cookies.remove({ url: cookieUrl(c), name: c.name, storeId: c.storeId });
    }
}

// LAU holds the logged-in user's UUID; load-cookie.pl keys sessions by it too.
async function currentAccount() {
    const lau = (await ancestryCookies()).find((c) => c.name === 'LAU');
    return lau ? lau.value.toLowerCase() : null;
}

async function switchTo(info) {
    const target = info.session.userUUID;
    const current = await currentAccount();
    if (current === target) return;

    // Stash whatever is in the jar unless it's the login we put there
    // ourselves, so hopping Terrill → Damian → Terrill never overwrites
    // your own saved login. This can't be "unless it's one of the session
    // accounts": your own login usually *is* one of them (Damian browsing
    // as Damian), and that test skipped the stash and left Restore with
    // nothing to put back.
    const { borrowed } = await chrome.storage.local.get('borrowed');
    if (borrowed?.account !== current) {
        await chrome.storage.local.set({ own: { account: current, cookies: await ancestryCookies() } });
    }

    await clearAncestry();
    // Stored as bare name/value pairs; treelib sets them on both domains
    // the same way.
    for (const domain of DOMAINS) {
        for (const c of info.cookies) {
            await chrome.cookies.set({
                url: `https://www.${domain}/`,
                domain: `.${domain}`,
                path: '/',
                name: c.name,
                value: c.value,
                secure: true,
            });
        }
    }
    await chrome.storage.local.set({ borrowed: { name: info.session.name, account: target } });
    scheduleIconUpdate();
}

async function restoreOwn() {
    const { own } = await chrome.storage.local.get('own');
    await clearAncestry();
    for (const c of own?.cookies ?? []) {
        const details = {
            url: cookieUrl(c),
            name: c.name,
            value: c.value,
            path: c.path,
            secure: c.secure,
            httpOnly: c.httpOnly,
            sameSite: c.sameSite,
            storeId: c.storeId,
        };
        if (!c.hostOnly) details.domain = c.domain;
        if (!c.session) details.expirationDate = c.expirationDate;
        try {
            await chrome.cookies.set(details);
        } catch (err) {
            console.warn('could not restore cookie', c.name, err);
        }
    }
    await chrome.storage.local.remove(['own', 'borrowed']);
    scheduleIconUpdate();
}

async function status() {
    const [account, { own, borrowed }] = await Promise.all([
        currentAccount(),
        chrome.storage.local.get(['own', 'borrowed']),
    ]);
    return {
        account,
        borrowed: borrowed && borrowed.account === account ? borrowed.name : null,
        hasOwn: !!own,
    };
}

// The toolbar icon says whose login the browser is on at a glance: teal
// (dnaweb's marine-600) for your own, red with the account's initial for a
// borrowed one — red because it's the state where Ancestry's "Sign out"
// would hurt the loaders — and grey when signed out. Drawn rather than
// shipped as PNGs because the letter depends on the session's name.
const COLOURS = { own: '#0f766e', borrowed: '#dc2626', none: '#9ca3af' };

function drawIcon(size, colour, letter) {
    const canvas = new OffscreenCanvas(size, size);
    const ctx = canvas.getContext('2d');
    const r = size * 0.22;
    ctx.fillStyle = colour;
    ctx.beginPath();
    ctx.roundRect(0, 0, size, size, r);
    ctx.fill();
    ctx.fillStyle = '#fff';
    ctx.font = `bold ${Math.round(size * 0.68)}px system-ui, sans-serif`;
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillText(letter, size / 2, size * 0.54);
    return ctx.getImageData(0, 0, size, size);
}

async function updateIcon() {
    const s = await status();
    const state = s.borrowed ? 'borrowed' : s.account ? 'own' : 'none';
    const letter = s.borrowed ? s.borrowed[0].toUpperCase() : 'A';
    await chrome.action.setIcon({
        imageData: Object.fromEntries([16, 32, 48].map((n) => [n, drawIcon(n, COLOURS[state], letter)])),
    });
    await chrome.action.setTitle({
        title: s.borrowed
            ? `Ancestry: on ${s.borrowed}'s login (borrowed)`
            : s.account
              ? 'Ancestry: on your own login'
              : 'Ancestry: signed out',
    });
}

// A swap removes and sets dozens of cookies, so wait for the jar to settle.
let iconTimer;
function scheduleIconUpdate() {
    clearTimeout(iconTimer);
    iconTimer = setTimeout(updateIcon, 300);
}

// LAU only changes when the login does — a swap, a restore, or you signing
// in or out on Ancestry yourself.
chrome.cookies.onChanged.addListener(({ cookie }) => {
    if (cookie.name === 'LAU' && /(^|\.)ancestry\.com(\.au)?$/.test(cookie.domain)) scheduleIconUpdate();
});
chrome.runtime.onInstalled.addListener(updateIcon);
chrome.runtime.onStartup.addListener(updateIcon);

chrome.runtime.onMessage.addListener((msg, sender, reply) => {
    if (msg.type === 'open') {
        // Answer straight away: content.js awaits this, and an unanswered
        // message rejects there, which it would take for an orphaned
        // context and open the link a second time.
        reply({ ok: true });
        (async () => {
            if (msg.info?.session && msg.info.cookies?.length) {
                await switchTo(msg.info);
            }
            await chrome.tabs.create({
                url: msg.url,
                active: msg.active,
                index: sender.tab ? sender.tab.index + 1 : undefined,
                openerTabId: sender.tab?.id,
            });
        })();
        return false;
    }
    if (msg.type === 'restore') {
        restoreOwn().then(() => reply({ ok: true }));
        return true;
    }
    if (msg.type === 'status') {
        status().then(reply);
        return true;
    }
});
