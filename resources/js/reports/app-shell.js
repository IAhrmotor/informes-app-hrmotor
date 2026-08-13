const shell = document.querySelector('[data-app-shell]');

if (shell) {
    const body = document.body;
    const toggle = shell.querySelector('[data-sidebar-toggle]');
    const sidebar = shell.querySelector('[data-sidebar]');
    const sidebarClose = shell.querySelector('[data-sidebar-close]');
    const overlay = shell.querySelector('[data-sidebar-overlay]');
    const workspace = shell.querySelector('.app-workspace');
    const mobileMedia = window.matchMedia('(max-width: 900px)');
    const storageKey = 'hrmotor-report-sidebar';

    const storedDesktopState = () => {
        try {
            return localStorage.getItem(storageKey);
        } catch (_) {
            return null;
        }
    };

    const persistDesktopState = (state) => {
        try {
            localStorage.setItem(storageKey, state);
        } catch (_) {
            // Storage can be unavailable without affecting navigation.
        }
    };

    const updateButton = (expanded) => {
        toggle?.setAttribute('aria-expanded', String(expanded));
        toggle?.setAttribute('aria-label', expanded ? 'Ocultar navegaci\u00f3n' : 'Mostrar navegaci\u00f3n');
    };

    const setWorkspaceInert = (inert) => {
        if (inert) {
            workspace?.setAttribute('inert', '');
            return;
        }

        workspace?.removeAttribute('inert');
    };

    const closeMobile = (restoreFocus = false) => {
        body.classList.remove('app-sidebar-mobile-open');
        setWorkspaceInert(false);
        updateButton(false);

        if (restoreFocus) {
            toggle?.focus();
        }
    };

    const applyViewportState = () => {
        document.documentElement.classList.remove('app-sidebar-precollapsed');

        if (mobileMedia.matches) {
            body.classList.remove('app-sidebar-collapsed');
            closeMobile();
            return;
        }

        setWorkspaceInert(false);
        const collapsed = storedDesktopState() === 'closed';
        body.classList.toggle('app-sidebar-collapsed', collapsed);
        body.classList.remove('app-sidebar-mobile-open');
        updateButton(!collapsed);
    };

    toggle?.addEventListener('click', () => {
        if (mobileMedia.matches) {
            const willOpen = !body.classList.contains('app-sidebar-mobile-open');
            body.classList.toggle('app-sidebar-mobile-open', willOpen);
            setWorkspaceInert(willOpen);
            updateButton(willOpen);

            if (willOpen) {
                sidebarClose?.focus();
            } else {
                toggle.focus();
            }

            return;
        }

        const collapsed = body.classList.toggle('app-sidebar-collapsed');
        persistDesktopState(collapsed ? 'closed' : 'open');
        updateButton(!collapsed);
    });

    overlay?.addEventListener('click', () => closeMobile(true));
    sidebarClose?.addEventListener('click', () => closeMobile(true));
    sidebar?.addEventListener('click', (event) => {
        if (mobileMedia.matches && event.target.closest('a')) {
            closeMobile();
        }
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && body.classList.contains('app-sidebar-mobile-open')) {
            closeMobile(true);
        }
    });
    mobileMedia.addEventListener('change', applyViewportState);
    applyViewportState();
}
