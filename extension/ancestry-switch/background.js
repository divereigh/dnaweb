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
}

chrome.runtime.onMessage.addListener((msg, sender, reply) => {
    if (msg.type === 'open') {
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
        Promise.all([currentAccount(), chrome.storage.local.get(['own', 'borrowed'])]).then(
            ([account, { own, borrowed }]) =>
                reply({
                    account,
                    borrowed: borrowed && borrowed.account === account ? borrowed.name : null,
                    hasOwn: !!own,
                }),
        );
        return true;
    }
});
