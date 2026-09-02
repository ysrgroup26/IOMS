import * as React from 'react';
import { Eye, EyeOff } from 'lucide-react';
import { Input } from '@/Components/ui/input';
import { cn } from '@/lib/utils';

/**
 * v2.33.0 (Phase 4, Security Audit -- Password Visibility). Login.jsx and
 * ResetPassword.jsx already had a show/hide toggle (v2.27.0); this pass's
 * own audit found FIVE other password fields in the app with no toggle
 * at all (Settings' own-password-change form, Settings' Add/Edit User
 * password fields, Platform's Add Tenant admin-password fields) --
 * each one previously reimplementing (or simply lacking) the same small
 * eye-icon interaction. Extracted here as the one shared primitive so
 * every future password field gets it by default instead of copy-pasting
 * a fourth/fifth/sixth local `useState` + button block. Self-contained
 * (its own internal show/hide state) rather than requiring a caller to
 * wire one up -- simplest possible drop-in replacement for a plain
 * `<Input type="password" .../>`.
 *
 * Usage: <PasswordInput value={data.password} onChange={(e) => setData('password', e.target.value)} />
 * Accepts every other <Input> prop (placeholder, className, etc.) verbatim.
 */
const PasswordInput = React.forwardRef(({ className, ...props }, ref) => {
    const [show, setShow] = React.useState(false);

    return (
        <div className="relative">
            <Input ref={ref} type={show ? 'text' : 'password'} className={cn('pr-10', className)} {...props} />
            <button
                type="button"
                onClick={() => setShow((v) => !v)}
                className="absolute right-0 top-0 flex h-9 w-9 items-center justify-center text-graphite-400 transition-colors hover:text-graphite-700"
                aria-label={show ? 'Hide password' : 'Show password'}
                aria-pressed={show}
                tabIndex={-1}
            >
                {show ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
            </button>
        </div>
    );
});
PasswordInput.displayName = 'PasswordInput';

export { PasswordInput };
