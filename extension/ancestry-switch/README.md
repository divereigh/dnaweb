# dnaweb Ancestry Switch (Chrome extension)

Makes dnaweb's Ancestry links (compare "DNA" buttons, tree links) open under the
Ancestry account that can actually see them, whichever account the browser is
logged in as.

On a click it asks dnaweb `GET /ancestry/session?url=…` which session the link
needs (compare links: the from-side kit's `dna_samples.managed`; tree links:
`gedcom_tree.managed`), then replaces the browser's Ancestry cookies with that
session's rows from the `cookie` table before opening the tab. Links that need
no particular account open unchanged.

## Install

1. On the server, put the allowed logins in `.env` and recache:
   `ANCESTRY_SWITCH_EMAILS=you@example.com,them@example.com`, then
   `php artisan config:clear` (or `config:cache` in production).
2. Chrome → `chrome://extensions` → Developer mode → **Load unpacked** → this folder.
3. Reload any open dnaweb tabs.

It runs on the hosts listed under `content_scripts` in `manifest.json`
(production and `localhost:8765`). If dnaweb moves, add the new host there and
reload the extension. A popup that asks for the address at runtime was tried and
dropped: on Linux, Chrome's permission prompt closes the popup, and the grant
never got as far as registering the script.

To check it is running on a page, `document.documentElement.dataset.ancestrySwitch`
in the console prints the extension's version.

## Things to know

- A browser profile has one Ancestry cookie jar. A swap changes the login for
  every open Ancestry tab, not just the new one.
- Your own login is saved before the first swap. Use **Restore my own Ancestry
  login** in the popup to get it back.
- **Don't press Ancestry's "Sign out" while you're on a borrowed login.** It can
  end the session the Perl loaders run on, and they stop until someone runs
  `load-cookie.pl` again.
- If the loaders' session has expired, the borrowed login does too, and Ancestry
  shows its sign-in page.
- The cookies are the whole account. Only list people in `ANCESTRY_SWITCH_EMAILS`
  who are trusted with both accounts. Every handout is logged (`ancestry session handed out`).
