// Minimal bootstrap: Inertia handles most HTTP internally, but some pages
// (Quick Attendance employee fetch) use fetch() directly against JSON
// endpoints, so we ensure the CSRF token is available globally if needed.
window.Laravel = {
    csrfToken: document.querySelector('meta[name="csrf-token"]')?.getAttribute('content'),
};
