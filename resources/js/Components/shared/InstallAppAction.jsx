import { useEffect, useRef, useState } from 'react';
import { Download, Share, Plus, X } from 'lucide-react';
import { getInstallState, subscribeToInstallState, promptInstall } from '@/lib/pwa';

/**
 * v2.73.0 -- INSTALL IOMS, from the product's own top bar.
 *
 * RENDERS NOTHING unless installation is genuinely possible. Three states,
 * and the distinction between them is the whole component:
 *
 *   - already installed     -> nothing. A permanent "Install" button
 *                              inside an installed app is noise.
 *   - a real prompt is held -> a control that opens the BROWSER'S OWN
 *                              install dialog.
 *   - iOS Safari            -> a short instruction panel, because Apple
 *                              exposes no installation API at all and the
 *                              only route is the Share sheet.
 *
 * WHAT THIS DELIBERATELY IS NOT. It does not download a file, wrap a
 * bookmark, or imitate an installer. If the browser cannot install the
 * app, the honest answer is to say where the control is -- or to say
 * nothing at all.
 *
 * Styled as a bare button rather than the shared <Button>, because it sits
 * on the navy top bar beside the theme toggle and the notifications bell,
 * which are all styled that way for the same reason: the design system's
 * button variants are drawn for light surfaces.
 */
export default function InstallAppAction() {
    // Assume installed until proven otherwise, so nothing flashes into the
    // top bar during the first paint and then disappears.
    const [state, setState] = useState({ installed: true, canPrompt: false, needsManualInstructions: false });
    const [showIosHelp, setShowIosHelp] = useState(false);
    const wrapRef = useRef(null);

    useEffect(() => {
        // Read once on mount: `beforeinstallprompt` may already have fired
        // before this component existed -- lib/pwa.js captures it at boot
        // for exactly that reason.
        setState(getInstallState());
        return subscribeToInstallState(setState);
    }, []);

    // Dismiss the iOS panel on an outside click, the way every other
    // popover in the top bar behaves.
    useEffect(() => {
        if (!showIosHelp) return undefined;

        function onPointerDown(e) {
            if (wrapRef.current && !wrapRef.current.contains(e.target)) setShowIosHelp(false);
        }
        function onKeyDown(e) {
            if (e.key === 'Escape') setShowIosHelp(false);
        }

        document.addEventListener('pointerdown', onPointerDown);
        document.addEventListener('keydown', onKeyDown);

        return () => {
            document.removeEventListener('pointerdown', onPointerDown);
            document.removeEventListener('keydown', onKeyDown);
        };
    }, [showIosHelp]);

    if (state.installed) return null;
    if (!state.canPrompt && !state.needsManualInstructions) return null;

    const triggerClass = 'shrink-0 rounded-md p-2 text-navy-300 transition-colors hover:bg-white/[0.08] hover:text-white';

    if (state.canPrompt) {
        return (
            <button
                type="button"
                onClick={() => promptInstall()}
                className={triggerClass}
                title="Install IOMS as an app"
                aria-label="Install IOMS as an app"
            >
                <Download className="h-[18px] w-[18px]" />
            </button>
        );
    }

    return (
        <div className="relative shrink-0" ref={wrapRef}>
            <button
                type="button"
                onClick={() => setShowIosHelp((v) => !v)}
                className={triggerClass}
                aria-expanded={showIosHelp}
                title="Install IOMS as an app"
                aria-label="Install IOMS as an app"
            >
                <Download className="h-[18px] w-[18px]" />
            </button>

            {showIosHelp && (
                <div
                    role="dialog"
                    aria-label="Install IOMS on iOS"
                    className="absolute right-0 z-[120] mt-2 w-64 rounded-xl border border-steel-200 bg-white p-3 text-left shadow-card-hover dark:border-slate-700 dark:bg-slate-900"
                >
                    <div className="flex items-start justify-between gap-2">
                        <p className="text-[13px] font-semibold text-navy-900 dark:text-slate-100">Install IOMS</p>
                        <button
                            type="button"
                            onClick={() => setShowIosHelp(false)}
                            aria-label="Close"
                            className="rounded p-0.5 text-graphite-400 hover:text-graphite-700 dark:hover:text-slate-200"
                        >
                            <X className="h-3.5 w-3.5" />
                        </button>
                    </div>

                    {/* Instructions, not a button: Safari on iOS offers no
                        API a web page can call, so naming the actual menu
                        items is the only thing that helps. */}
                    <ol className="mt-2 space-y-1.5 text-xs leading-relaxed text-graphite-600 dark:text-slate-400">
                        <li className="flex items-start gap-1.5">
                            <Share className="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            <span>Ketuk tombol <strong>Share</strong> di Safari.</span>
                        </li>
                        <li className="flex items-start gap-1.5">
                            <Plus className="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            <span>Pilih <strong>Add to Home Screen</strong>.</span>
                        </li>
                    </ol>
                </div>
            )}
        </div>
    );
}
