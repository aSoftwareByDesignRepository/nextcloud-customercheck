/**
 * Navigation JavaScript for CustomerCheck App
 * Submenu toggles, keyboard navigation, and workspace switcher (all pages).
 * Mobile drawer uses Nextcloud core (#app-navigation-toggle / body.snapjs-left
 * below 1024px), same as BudgetCheck and DutyCheck.
 *
 * Workspace switching uses a labelled disclosure + links (activation), never a
 * select onchange navigation — WCAG 2.1 AA 3.2.2 On Input.
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

(function() {
    'use strict';

    const APP_ID = 'customercheck';

    function translate(key, fallback) {
        if (typeof window !== 'undefined' && typeof window.t === 'function') {
            const value = window.t(APP_ID, key);
            if (value && value !== key) {
                return value;
            }
        }
        return fallback;
    }

    function parseWorkspaceId(raw) {
        if (raw === null || raw === undefined || raw === '') {
            return 0;
        }
        const n = Number.parseInt(String(raw), 10);
        return Number.isFinite(n) && n > 0 ? n : 0;
    }

    function resolveActiveWorkspaceId(root) {
        const fromUrl = parseWorkspaceId(
            new URLSearchParams(window.location.search).get('workspaceId'),
        );
        if (fromUrl > 0) {
            return fromUrl;
        }
        if (root) {
            const fromAttr = parseWorkspaceId(root.getAttribute('data-crm-active-workspace'));
            if (fromAttr > 0) {
                return fromAttr;
            }
        }
        return 0;
    }

    function withWorkspaceId(baseHref, workspaceId) {
        const id = parseWorkspaceId(workspaceId);
        if (id <= 0 || typeof baseHref !== 'string' || baseHref === '') {
            return '#';
        }
        if (/^[a-z][a-z0-9+.-]*:/i.test(baseHref) || baseHref.startsWith('//')) {
            return '#';
        }
        try {
            const url = new URL(baseHref, window.location.origin);
            if (url.origin !== window.location.origin) {
                return '#';
            }
            url.searchParams.set('workspaceId', String(id));
            return url.pathname + url.search + url.hash;
        } catch (e) {
            return '#';
        }
    }

    function announce(message) {
        const live = document.getElementById('crm-live-region');
        if (live) {
            live.textContent = message;
        }
    }

    /**
     * Progressive enhancement: refresh option list from API when SSR was empty
     * or stale, without replacing the disclosure control with a select.
     */
    async function enrichWorkspaceMenu(root, menu, trigger) {
        const api = window.CustomerCheckApi;
        if (!api || typeof api.get !== 'function' || !menu) {
            return;
        }
        try {
            const payload = await api.get('/workspaces');
            const data = payload && payload.data ? payload.data : payload;
            const list = Array.isArray(data.workspaces) ? data.workspaces : [];
            const current = parseWorkspaceId(
                (data.current && data.current.id) || root.getAttribute('data-crm-active-workspace'),
            );
            if (current > 0) {
                root.setAttribute('data-crm-active-workspace', String(current));
            }
            if (list.length <= 1) {
                return;
            }
            root.classList.add('crm-workspace-switcher--multi');
            root.classList.remove('crm-workspace-switcher--single');
            if (!trigger || trigger.tagName !== 'BUTTON') {
                return;
            }
            const existingIds = Array.from(menu.querySelectorAll('[data-crm-workspace-id]'))
                .map(function (el) { return parseWorkspaceId(el.getAttribute('data-crm-workspace-id')); })
                .filter(function (id) { return id > 0; });
            if (existingIds.length === list.length) {
                return;
            }
            menu.innerHTML = '';
            list.forEach(function (w) {
                const id = parseWorkspaceId(w.id);
                if (id <= 0) {
                    return;
                }
                const li = document.createElement('li');
                const a = document.createElement('a');
                a.className = 'crm-workspace-switcher__option' + (id === current ? ' is-active' : '');
                a.href = withWorkspaceId(window.location.pathname + window.location.search, id);
                a.setAttribute('data-crm-workspace-id', String(id));
                if (id === current) {
                    a.setAttribute('aria-current', 'true');
                }
                const name = document.createElement('span');
                name.className = 'crm-workspace-switcher__option-name';
                name.textContent = w.name || translate('Workspace', 'Workspace');
                a.appendChild(name);
                if (w.archived) {
                    const meta = document.createElement('span');
                    meta.className = 'crm-workspace-switcher__option-meta';
                    meta.textContent = translate('Archived', 'Archived');
                    a.appendChild(meta);
                } else if (id === current) {
                    const meta = document.createElement('span');
                    meta.className = 'crm-workspace-switcher__option-meta';
                    meta.textContent = translate('Current', 'Current');
                    a.appendChild(meta);
                }
                li.appendChild(a);
                menu.appendChild(li);
            });
        } catch (e) {
            /* SSR list remains authoritative when the API is unavailable. */
        }
    }

    function initWorkspaceSwitcher() {
        const root = document.querySelector('[data-crm-workspace-switch]');
        if (!root || root.getAttribute('data-crm-ws-ready') === '1') {
            return;
        }
        root.setAttribute('data-crm-ws-ready', '1');

        const active = resolveActiveWorkspaceId(root);
        if (active > 0) {
            root.setAttribute('data-crm-active-workspace', String(active));
        }

        const trigger = root.querySelector('.crm-workspace-switcher__trigger');
        const menu = root.querySelector('.crm-workspace-switcher__menu');
        if (!trigger || !menu) {
            return;
        }

        function setOpen(open) {
            trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open) {
                menu.removeAttribute('hidden');
            } else {
                menu.setAttribute('hidden', '');
            }
        }

        function close() {
            if (trigger.getAttribute('aria-expanded') !== 'true') {
                return;
            }
            setOpen(false);
            trigger.focus();
        }

        trigger.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            const open = trigger.getAttribute('aria-expanded') !== 'true';
            setOpen(open);
            if (open) {
                const focusTarget = menu.querySelector('.crm-workspace-switcher__option.is-active')
                    || menu.querySelector('.crm-workspace-switcher__option');
                if (focusTarget) {
                    focusTarget.focus();
                }
            }
        });

        trigger.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown' || e.key === 'Enter' || e.key === ' ') {
                if (trigger.getAttribute('aria-expanded') === 'true' && e.key === 'ArrowDown') {
                    e.preventDefault();
                    const first = menu.querySelector('.crm-workspace-switcher__option');
                    if (first) {
                        first.focus();
                    }
                } else if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    setOpen(true);
                    const first = menu.querySelector('.crm-workspace-switcher__option');
                    if (first) {
                        first.focus();
                    }
                }
            } else if (e.key === 'Escape') {
                close();
            }
        });

        menu.addEventListener('keydown', function (e) {
            const options = Array.from(menu.querySelectorAll('.crm-workspace-switcher__option'));
            const index = options.indexOf(document.activeElement);
            if (e.key === 'Escape') {
                e.preventDefault();
                close();
                return;
            }
            if (e.key === 'ArrowDown' && index >= 0) {
                e.preventDefault();
                (options[index + 1] || options[0]).focus();
            } else if (e.key === 'ArrowUp' && index >= 0) {
                e.preventDefault();
                (options[index - 1] || options[options.length - 1]).focus();
            } else if (e.key === 'Home' && options[0]) {
                e.preventDefault();
                options[0].focus();
            } else if (e.key === 'End' && options.length) {
                e.preventDefault();
                options[options.length - 1].focus();
            }
        });

        menu.addEventListener('click', function (e) {
            const link = e.target && e.target.closest
                ? e.target.closest('.crm-workspace-switcher__option')
                : null;
            if (!link) {
                return;
            }
            const id = parseWorkspaceId(link.getAttribute('data-crm-workspace-id'));
            if (id > 0) {
                root.setAttribute('data-crm-active-workspace', String(id));
                const nameEl = link.querySelector('.crm-workspace-switcher__option-name');
                const name = nameEl ? nameEl.textContent.trim() : '';
                if (name) {
                    announce(translate('Working in workspace %s.', 'Working in workspace %s.').replace('%s', name));
                }
            }
        });

        document.addEventListener('click', function (e) {
            if (!root.contains(e.target)) {
                setOpen(false);
            }
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                setOpen(false);
            }
        });

        enrichWorkspaceMenu(root, menu, trigger);
    }

    const Navigation = {
        menuNav: null,

        init() {
            this.menuNav = document.getElementById('app-navigation');
            if (!this.menuNav) {
                return;
            }

            this.setupSubmenuToggles();
            this.setupAccessibility();
            initWorkspaceSwitcher();
        },

        setupSubmenuToggles() {
            const parentToggles = this.menuNav.querySelectorAll('.nav-parent-toggle');
            parentToggles.forEach((toggle) => {
                const submenuId = toggle.getAttribute('aria-controls');
                const submenu = submenuId ? this.menuNav.querySelector(`#${submenuId}`) : null;
                if (!submenu) {
                    return;
                }
                toggle.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const isExpanded = toggle.getAttribute('aria-expanded') === 'true';
                    toggle.setAttribute('aria-expanded', isExpanded ? 'false' : 'true');
                    if (isExpanded) {
                        submenu.setAttribute('hidden', '');
                    } else {
                        submenu.removeAttribute('hidden');
                    }
                });
            });
        },

        setupAccessibility() {
            this.menuNav.setAttribute('role', 'navigation');
            this.menuNav.setAttribute('aria-label',
                translate('Main navigation', 'Main navigation'));
            this.setupKeyboardNavigation();
        },

        setupKeyboardNavigation() {
            const navLinks = Array.from(this.menuNav.querySelectorAll('a, .nav-parent-toggle'))
                .filter((el) => !el.closest('.crm-workspace-switcher'));

            navLinks.forEach((link, index) => {
                link.addEventListener('keydown', (e) => {
                    if (e.key === 'ArrowDown') {
                        e.preventDefault();
                        const nextLink = navLinks[index + 1] || navLinks[0];
                        if (nextLink) {
                            nextLink.focus();
                        }
                    } else if (e.key === 'ArrowUp') {
                        e.preventDefault();
                        const prevLink = navLinks[index - 1] || navLinks[navLinks.length - 1];
                        if (prevLink) {
                            prevLink.focus();
                        }
                    } else if (e.key === 'Home') {
                        e.preventDefault();
                        navLinks[0].focus();
                    } else if (e.key === 'End') {
                        e.preventDefault();
                        navLinks[navLinks.length - 1].focus();
                    }
                });
            });
        },
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            Navigation.init();
        });
    } else {
        Navigation.init();
    }

    if (typeof window !== 'undefined') {
        window.CustomerCheckNavigation = Navigation;
    }
})();
