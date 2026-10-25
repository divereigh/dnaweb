<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class AncestrySessionController extends Controller
{
    /**
     * Which loader session an Ancestry link has to be opened under, with
     * that session's cookies. Called by the Ancestry Switch extension
     * (extension/ancestry-switch) when one of our ancestry.com.au links
     * is clicked: it swaps the browser's Ancestry cookies for these, so
     * a compare page for Terrill's kit renders even when the browser was
     * logged in as Damian, and vice versa.
     *
     * The answer comes from the URL, not from the page, so every link in
     * the app is covered without each one having to carry a session id:
     *
     *   discoveryui-matches/…/{dnaUUID}/…  → the first kit's dna_samples.managed
     *                                        (the compare from-side, which is
     *                                        the kit that must have the session)
     *   family-tree/tree/{atreeid}/…       → gedcom_tree.managed
     *   anything else                      → no session; open as-is
     *
     * These are the same joins treelib's openAncestrySessionDnaUUID() and
     * openAncestrySessionTree() use to pick a session for the loaders.
     *
     * The cookies are the loaders' own (load-cookie.pl), not a copy, so
     * whoever gets them is the account — hence the allow-list in
     * config/ancestry.php, and the log line.
     */
    public function show(Request $request): JsonResponse
    {
        Gate::authorize('switch-ancestry');
        $email = strtolower((string) $request->user()->email);

        $url = (string) $request->query('url', '');
        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');
        $path = $parts['path'] ?? '';

        $sessionId = null;
        if (preg_match('/(^|\.)ancestry\.com(\.au)?$/', $host)) {
            if (preg_match('#^/discoveryui-matches/[\w-]+/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})#i', $path, $m)) {
                $sessionId = DB::scalar('SELECT managed FROM dna_samples WHERE dnaUUID = ?', [strtolower($m[1])]);
            } elseif (preg_match('#^/family-tree/tree/(\d+)#', $path, $m)) {
                $sessionId = DB::scalar('SELECT managed FROM gedcom_tree WHERE atreeid = ?', [(int) $m[1]]);
            }
        }

        $session = $sessionId
            ? DB::selectOne('SELECT id, name, userUUID FROM session WHERE id = ?', [$sessionId])
            : null;

        $cookies = $session
            ? DB::select('SELECT cookie AS name, value FROM cookie WHERE userUUID = ?', [$session->userUUID])
            : [];

        if ($session && $cookies) {
            Log::info('ancestry session handed out', [
                'user' => $email,
                'session' => $session->name,
                'url' => $url,
            ]);
        }

        return response()
            ->json([
                'session' => $session && $cookies ? [
                    'id' => (int) $session->id,
                    'name' => $session->name,
                    'userUUID' => strtolower($session->userUUID),
                ] : null,
                'cookies' => $cookies,
            ])
            ->header('Cache-Control', 'no-store');
    }
}
