{{--
    v2.128.0 -- THE BOOT SPLASH.

    WHAT WAS THERE BEFORE: nothing. Inertia SSR is off (config/inertia.php),
    so the body the server sends is an empty `<div id="app">`. Between the
    HTML arriving and React mounting the page is BLANK -- and inside an
    installed app, with no address bar and no browser chrome to explain the
    pause, a blank screen reads as a crash. On Android it was worse than
    blank: `theme_color` was the product navy, so the platform splash and
    the status bar drew a dark band above a white app.

    WHAT THIS IS: the first paint. Rendered by the SERVER, in the same
    response as the page, so it is on screen before a single byte of
    JavaScript is parsed -- an overlay React draws later could not be,
    because React is the thing being waited for.

    THE ARTWORK IS OFFICIAL AND INLINE. Inlined rather than linked so it
    paints with the HTML instead of after a second request; the file is the
    stacked official lockup (public/branding/ioms-lockup-stacked.svg),
    which is composed from the owner's own symbol and wordmark paths and is
    not a redrawing of either.

    IT REMOVES ITSELF THREE WAYS, because a splash that outlives its reason
    is a white screen with a logo on it: `app.jsx` removes it on mount (the
    normal path), a timeout removes it if the bundle never arrives, and
    `<noscript>` hides it for a client that will never run either.
--}}
<div id="ioms-boot" role="status" aria-label="Loading IOMS">
    <div class="ioms-boot-mark">
        {!! $iomsBootLockup !!}
    </div>
    <div class="ioms-boot-bar"><span></span></div>
    <p class="ioms-boot-text">Loading…</p>
</div>
