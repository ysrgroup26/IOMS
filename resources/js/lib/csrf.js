/**
 * v2.130.0 -- THE TOKEN THAT IS STILL VALID, NOT THE ONE IN THE DOCUMENT.
 *
 * =============================================================================
 * THE DEFECT THIS EXISTS BECAUSE OF
 * =============================================================================
 *
 * Marking a notification read answered 419 for anybody who had signed in
 * during that visit, which is almost everybody -- so the badge never went
 * down, which is exactly what was reported.
 *
 * `<meta name="csrf-token">` is rendered once, with the document. Signing in
 * REGENERATES the session (and its token) -- and Inertia does not reload the
 * document when it navigates: it swaps the page component inside the SAME
 * document. So after login the meta tag still carries the token from the
 * login page, which the session no longer accepts. Every request that read
 * the meta was signing with a token that had been replaced seconds earlier.
 *
 * It passed every server-side test because Laravel disables CSRF
 * verification in tests, and it passed code review because reading the meta
 * tag is what every tutorial says to do. It failed the moment somebody
 * clicked a notification in a browser.
 *
 * =============================================================================
 * WHY THE COOKIE IS THE RIGHT SOURCE
 * =============================================================================
 *
 * Laravel sets `XSRF-TOKEN` on EVERY response, so it is current by
 * construction -- a regenerated session writes a new one on its way out.
 * `VerifyCsrfToken` accepts it as the `X-XSRF-TOKEN` header, and it is what
 * axios (and therefore Inertia itself) uses, which is why Inertia's own
 * requests kept working while these did not.
 *
 * The meta tag stays as the fallback for the one case the cookie cannot
 * cover: a browser with cookies partitioned or blocked for this origin,
 * where the document's own token is the only one there is.
 */

function readCookie(name) {
    if (typeof document === 'undefined') return null;

    const match = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/([.*+?^${}()|[\]\\])/g, '\\$1') + '=([^;]*)'));

    return match ? decodeURIComponent(match[1]) : null;
}

/**
 * Headers that let a same-origin POST through `VerifyCsrfToken`.
 *
 * ONE TOKEN, NOT BOTH, and the order matters more than it looks.
 * `VerifyCsrfToken::getTokenFromRequest()` reads `X-CSRF-TOKEN` FIRST and
 * only falls back to `X-XSRF-TOKEN` when it finds nothing -- so sending
 * both means the meta tag always wins, which is precisely the stale value
 * this module exists to stop using. Measured: with both headers the request
 * still answered 419; with the cookie alone it answered 200.
 *
 * So the cookie is used when there is one, and the meta tag only when there
 * is not -- a browser that will not hand the cookie back, where the
 * document's own token is the only one available.
 */
export function csrfHeaders() {
    const headers = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    };

    const cookie = readCookie('XSRF-TOKEN');

    if (cookie) {
        headers['X-XSRF-TOKEN'] = cookie;

        return headers;
    }

    const meta = typeof document !== 'undefined'
        ? document.querySelector('meta[name="csrf-token"]')?.content
        : null;

    if (meta) headers['X-CSRF-TOKEN'] = meta;

    return headers;
}
