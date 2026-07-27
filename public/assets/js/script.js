const MOBILE_BREAKPOINT = '(max-width: 768px)';

function isMobileViewport() {
  return window.matchMedia(MOBILE_BREAKPOINT).matches;
}


// ===== Sidebar collapse toggle (desktop/tablet, >768px) =========
// Collapses/expands the sidebar to an icon rail by flipping
// data-sidebar="collapsed" on <html>. CSS reacts to that attribute
// (see layout/sidebar.css). State is persisted in localStorage so
// it survives page reloads.
// Using an <html> attribute (not a class on .sidebar) means the
// pre-paint snippet in <head> can apply it before .sidebar even
// exists in the DOM — no flash of the wrong state on load.

const SIDEBAR_STORAGE_KEY = 'petorders:sidebar';

function setSidebarState(collapsed) {
  if (collapsed) {
    document.documentElement.dataset.sidebar = 'collapsed';
  } else {
    delete document.documentElement.dataset.sidebar;
    closeSidebarFlyout(); // un-collapsing kills the flyout's reason to exist
  }
  localStorage.setItem(SIDEBAR_STORAGE_KEY, collapsed ? 'collapsed' : 'expanded');
}


// ===== Sidebar mobile off-canvas toggle (≤768px) =================
// Opens/closes the off-canvas sidebar by flipping
// data-sidebar-mobile="open" on <html>. This is a separate
// attribute from the desktop "collapsed" state above — they're
// independent, not two ends of the same spectrum. Not persisted:
// the mobile sidebar always starts closed on a fresh page load.

function setSidebarMobileOpen(open) {
  if (open) {
    document.documentElement.dataset.sidebarMobile = 'open';
  } else {
    delete document.documentElement.dataset.sidebarMobile;
  }
}

function isSidebarMobileOpen() {
  return document.documentElement.dataset.sidebarMobile === 'open';
}


// ===== Shared chevron toggle (.sidebar-toggle) ====================
// Behavior depends on viewport: on mobile it opens/closes the
// off-canvas panel; on desktop/tablet it collapses/expands the
// icon rail. Same physical button, different job per breakpoint.

function initSidebarToggle() {
  const toggleBtn = document.querySelector('.sidebar-toggle');
  if (!toggleBtn) return;

  toggleBtn.addEventListener('click', () => {
    if (isMobileViewport()) {
      setSidebarMobileOpen(!isSidebarMobileOpen());
    } else {
      const isCollapsed = document.documentElement.dataset.sidebar === 'collapsed';
      setSidebarState(!isCollapsed);
    }
  });
}

function initHamburgerToggle() {
  const hamburgerBtn = document.querySelector('.hamburger-toggle');
  if (!hamburgerBtn) return;

  hamburgerBtn.addEventListener('click', () => {
    setSidebarMobileOpen(true);
  });
}

function initSidebarBackdrop() {
  const backdrop = document.querySelector('.sidebar-backdrop');
  if (!backdrop) return;

  backdrop.addEventListener('click', () => {
    setSidebarMobileOpen(false);
  });
}

// Close on Escape, and auto-close if the viewport grows past the
// mobile breakpoint while the panel happens to be open (e.g. a
// tablet rotated or a window resized/un-maximized mid-session).
function initSidebarMobileSafety() {
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && isSidebarMobileOpen()) {
      setSidebarMobileOpen(false);
    }
  });

  window.addEventListener('resize', () => {
    if (!isMobileViewport() && isSidebarMobileOpen()) {
      setSidebarMobileOpen(false);
    }
    // The collapsed-rail flyout is desktop/tablet-only (see
    // toggleSidebarFlyout) — a stale position: fixed panel positioned via
    // a desktop getBoundingClientRect shouldn't be left floating once the
    // viewport shrinks into the mobile off-canvas layout.
    if (isMobileViewport()) {
      closeSidebarFlyout();
    }
  });
}


// ===== Sidebar submenu expand/collapse (Accounts/Catalog/Directory) ==
// Click toggles .is-expanded on the parent <li>, which drives the
// chevron rotation and the submenu's open/close animation entirely
// through CSS (.submenu-wrapper's grid-template-rows 0fr -> 1fr, see
// sidebar.css) — no JS measurement of the submenu's height is needed.
// No persistence — initial state is rendered server-side (see
// layout_admin.php).
//
// Exception: when the sidebar is icon-rail collapsed (desktop/tablet
// only — mobile off-canvas always uses the inline behavior above), the
// inline submenu is display:none (layout/sidebar.css), so the click opens a
// floating flyout instead. Same toggle button, different target
// depending on collapsed state.

// Single place that mutates a submenu's open/closed state, used by the
// toggle click, the accordion close-others sweep, and the bfcache
// re-sync handler below, so all three stay in sync (class + aria).
function setSubmenuExpanded(item, expand) {
  const toggleBtn = item.querySelector(':scope > .menu-link');
  item.classList.toggle('is-expanded', expand);
  if (toggleBtn) toggleBtn.setAttribute('aria-expanded', expand ? 'true' : 'false');
}

function initSidebarSubmenus() {
  // The PHP template renders aria-expanded assuming the inline submenu
  // is what would open (correct when the sidebar starts expanded), but
  // the collapsed state is restored from localStorage pre-paint — if
  // that's the state on load, nothing is actually open yet. (The
  // .is-expanded class itself is left alone: the CSS grid animation
  // handles an SSR-expanded submenu correctly with no JS involvement, and
  // the collapsed-rail media query hides .submenu-wrapper outright
  // regardless of that class.)
  if (document.documentElement.dataset.sidebar === 'collapsed' && !isMobileViewport()) {
    document.querySelectorAll('.menu-item--has-submenu > .menu-link').forEach((toggleBtn) => {
      toggleBtn.setAttribute('aria-expanded', 'false');
    });
  }

  document.querySelectorAll('.menu-item--has-submenu > .menu-link').forEach((toggleBtn) => {
    toggleBtn.addEventListener('click', () => {
      const collapsedDesktop =
        document.documentElement.dataset.sidebar === 'collapsed' && !isMobileViewport();
      if (collapsedDesktop) {
        toggleSidebarFlyout(toggleBtn);
        return;
      }
      const item = toggleBtn.closest('.menu-item--has-submenu');
      const expand = !item.classList.contains('is-expanded');
      setSubmenuExpanded(item, expand);
      // Accordion: expanding one submenu collapses any other open one
      // (the collapsed-rail flyout path already enforces this separately
      // via closeSidebarFlyout()).
      if (expand) {
        document.querySelectorAll('.menu-item--has-submenu.is-expanded').forEach((other) => {
          if (other !== item) setSubmenuExpanded(other, false);
        });
      }
    });
  });
}


// ===== Sidebar collapsed-rail flyout ==============================
// Floating panel for reaching submenu links while the sidebar is an
// icon rail. Appended to <body> (not .sidebar-content, which clips via
// overflow:hidden) and positioned next to the clicked icon.

let activeFlyout = null; // { panel, toggleBtn, defaultAriaControls, outsideClickHandler, keydownHandler, scrollHandler }

function closeSidebarFlyout() {
  if (!activeFlyout) return;
  const { panel, toggleBtn, defaultAriaControls, outsideClickHandler, keydownHandler, scrollHandler } =
    activeFlyout;
  activeFlyout = null;

  document.removeEventListener('mousedown', outsideClickHandler, true);
  document.removeEventListener('keydown', keydownHandler, true);
  const sidebarContent = document.querySelector('.sidebar-content');
  if (sidebarContent) sidebarContent.removeEventListener('scroll', scrollHandler);

  panel.remove();
  toggleBtn.setAttribute('aria-expanded', 'false');
  toggleBtn.setAttribute('aria-controls', defaultAriaControls);
  toggleBtn.focus();
}

function buildSidebarFlyout(item) {
  const labelText = item.querySelector('.menu-label__text')?.textContent.trim() || 'Menu';
  const slug = labelText.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');

  const panel = document.createElement('div');
  panel.className = 'sidebar-flyout';
  panel.id = `${slug}-flyout`;
  panel.setAttribute('role', 'menu');

  const heading = document.createElement('div');
  heading.className = 'sidebar-flyout__title';
  heading.textContent = labelText;
  panel.appendChild(heading);

  const list = document.createElement('ul');
  list.className = 'sidebar-flyout__list';
  item.querySelectorAll('.submenu-link').forEach((link) => {
    const li = document.createElement('li');
    li.appendChild(link.cloneNode(true));
    list.appendChild(li);
  });
  panel.appendChild(list);

  return panel;
}

function toggleSidebarFlyout(toggleBtn) {
  if (activeFlyout && activeFlyout.toggleBtn === toggleBtn) {
    closeSidebarFlyout();
    return;
  }
  closeSidebarFlyout(); // only one flyout open at a time

  const item = toggleBtn.closest('.menu-item--has-submenu');
  const panel = buildSidebarFlyout(item);
  document.body.appendChild(panel);

  const rect = toggleBtn.getBoundingClientRect();
  panel.style.top = `${rect.top}px`;
  panel.style.left = `${rect.right + 8}px`;

  const defaultAriaControls = toggleBtn.getAttribute('aria-controls');
  toggleBtn.setAttribute('aria-expanded', 'true');
  toggleBtn.setAttribute('aria-controls', panel.id);

  const outsideClickHandler = (e) => {
    if (!panel.contains(e.target) && !toggleBtn.contains(e.target)) {
      closeSidebarFlyout();
    }
  };
  const keydownHandler = (e) => {
    if (e.key === 'Escape') closeSidebarFlyout();
  };
  const scrollHandler = () => closeSidebarFlyout();

  document.addEventListener('mousedown', outsideClickHandler, true);
  document.addEventListener('keydown', keydownHandler, true);
  const sidebarContent = document.querySelector('.sidebar-content');
  if (sidebarContent) sidebarContent.addEventListener('scroll', scrollHandler);

  panel.querySelectorAll('a').forEach((link) => {
    link.addEventListener('click', () => closeSidebarFlyout());
  });

  activeFlyout = { panel, toggleBtn, defaultAriaControls, outsideClickHandler, keydownHandler, scrollHandler };
}

// ===== Toasts =====================================================
// Transient feedback, bottom-right. Usage from any page or script:
//   showToast('success', 'Account created.')
// Types: success | error | warning | info. Server-side flashes emit
// this via toast_flash() in src/helpers.php. Removal uses timers,
// not transitionend, so prefers-reduced-motion (which disables all
// transitions) can't strand a toast in the DOM.

const TOAST_DURATION_MS = 4000;
const TOAST_MAX_VISIBLE = 3;

function ensureToastRegion() {
  let region = document.querySelector('.toast-region');
  if (!region) {
    region = document.createElement('div');
    region.className = 'toast-region';
    document.body.appendChild(region);
  }
  return region;
}

function dismissToast(toast) {
  if (toast.dataset.leaving === 'true') return;
  toast.dataset.leaving = 'true';
  toast.classList.add('toast--leaving');
  setTimeout(() => toast.remove(), 220);
}

function showToast(type, message, options = {}) {
  const region = ensureToastRegion();

  // Oldest toast makes room once the stack is full
  const visible = region.querySelectorAll('.toast:not(.toast--leaving)');
  if (visible.length >= TOAST_MAX_VISIBLE) {
    dismissToast(visible[0]);
  }

  const toast = document.createElement('div');
  toast.className = 'toast toast--' + type;
  // Errors interrupt screen readers; everything else waits its turn
  toast.setAttribute('role', type === 'error' ? 'alert' : 'status');

  const dot = document.createElement('span');
  dot.className = 'toast__dot';

  const msg = document.createElement('div');
  msg.className = 'toast__msg';
  msg.textContent = message;

  const close = document.createElement('button');
  close.type = 'button';
  close.className = 'toast__close';
  close.setAttribute('aria-label', 'Dismiss notification');
  close.innerHTML = '&times;';
  close.addEventListener('click', () => dismissToast(toast));

  toast.append(dot, msg, close);
  region.appendChild(toast);

  // Auto-dismiss with pause-on-hover: the remaining time is tracked
  // across pointer enter/leave instead of one fixed timeout.
  let remaining = options.duration || TOAST_DURATION_MS;
  let startedAt = Date.now();
  let timer = setTimeout(() => dismissToast(toast), remaining);

  toast.addEventListener('mouseenter', () => {
    clearTimeout(timer);
    remaining -= Date.now() - startedAt;
  });
  toast.addEventListener('mouseleave', () => {
    startedAt = Date.now();
    timer = setTimeout(() => dismissToast(toast), Math.max(remaining, 600));
  });

  return toast;
}

window.showToast = showToast;


// ===== Arrival-flag URL cleanup ====================================
// Strips one-shot PRG arrival-toast query flags (e.g. ?created=1) from
// the URL bar once their toast has been queued server-side, so a reload
// or back-navigation doesn't replay a stale success toast for an action
// that already happened. Separate from the PRG pattern itself -- PRG
// stops the browser's resubmit-form prompt; this only stops the toast
// replay on a plain GET reload/back-nav. Called from each page's own
// inline script with that page's flag list, e.g.
// petordersCleanArrivalFlags(['created', 'updated', 'activated', 'deactivated']).

function petordersCleanArrivalFlags(flags) {
  const urlParams = new URLSearchParams(window.location.search);
  const hasArrivalFlag = flags.some((flag) => urlParams.has(flag));
  if (!hasArrivalFlag) return;

  flags.forEach((flag) => urlParams.delete(flag));
  const cleanedQuery = urlParams.toString();
  const cleanedUrl = window.location.pathname + (cleanedQuery ? '?' + cleanedQuery : '') + window.location.hash;
  history.replaceState(null, '', cleanedUrl);
}

window.petordersCleanArrivalFlags = petordersCleanArrivalFlags;


// ===== Modals =====================================================
// Two entry points share the open/close/focus-trap machinery:
//  1. petordersOpenModal(overlayEl) — opens a modal already in the page
//     markup (e.g. the reject-with-reason form on registrations.php).
//  2. Declarative form confirms — any <form data-confirm="…"> gets
//     its submit intercepted and routed through a built-on-the-fly
//     confirm dialog; confirming re-submits the form natively, so
//     POST semantics are untouched. Optional attributes:
//       data-confirm-title="Deactivate account"
//       data-confirm-verb="Deactivate"   (confirm button label)
//       data-confirm-danger              (solid red confirm button)

const MODAL_FOCUSABLE =
  'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled])';

let activeModal = null; // { overlay, opener, keydownHandler, temporary }

function petordersCloseModal(force = false) {
  if (!activeModal) return;
  // Opt-in veto hook: an overlay may carry a petordersBeforeClose callback
  // (set by its own page script — only the new-order modal does today);
  // returning false aborts the close. Every close path (Esc, backdrop,
  // X, footer Cancel) funnels through here, so one hook covers them
  // all. Callers that pass force=true (e.g. after a confirmed discard)
  // skip the hook. Modals that never set the callback behave exactly
  // as before.
  if (
    !force &&
    typeof activeModal.overlay.petordersBeforeClose === 'function' &&
    activeModal.overlay.petordersBeforeClose() === false
  ) {
    return;
  }
  const { overlay, opener, keydownHandler, temporary } = activeModal;
  activeModal = null;

  document.removeEventListener('keydown', keydownHandler, true);
  delete document.documentElement.dataset.modalOpen;

  if (temporary) {
    overlay.remove();
  } else {
    overlay.hidden = true;
  }
  if (opener && document.contains(opener)) {
    opener.focus();
  }
}

function petordersOpenModal(overlay, options = {}) {
  if (activeModal) {
    petordersCloseModal();
    // A petordersBeforeClose hook may have vetoed that close — never open
    // a second modal on top of one that refused to leave.
    if (activeModal) return;
  }

  overlay.hidden = false;
  document.documentElement.dataset.modalOpen = 'true';

  const keydownHandler = (e) => {
    if (e.key === 'Escape') {
      e.preventDefault();
      petordersCloseModal();
      return;
    }
    if (e.key !== 'Tab') return;

    // Keep Tab cycling inside the dialog
    const focusables = overlay.querySelectorAll(MODAL_FOCUSABLE);
    if (!focusables.length) return;
    const first = focusables[0];
    const last = focusables[focusables.length - 1];
    if (e.shiftKey && document.activeElement === first) {
      e.preventDefault();
      last.focus();
    } else if (!e.shiftKey && document.activeElement === last) {
      e.preventDefault();
      first.focus();
    }
  };
  document.addEventListener('keydown', keydownHandler, true);

  // Close on backdrop click — mousedown must start on the backdrop
  // itself so a drag-select ending outside the card doesn't close it.
  if (overlay.dataset.modalWired !== 'true') {
    overlay.dataset.modalWired = 'true';
    overlay.addEventListener('mousedown', (e) => {
      if (e.target === overlay) petordersCloseModal();
    });
    overlay.querySelectorAll('[data-modal-close]').forEach((el) => {
      el.addEventListener('click', () => petordersCloseModal());
    });
  }

  activeModal = {
    overlay,
    opener: options.opener || document.activeElement,
    keydownHandler,
    temporary: options.temporary === true,
  };

  const focusTarget =
    overlay.querySelector('[data-modal-focus]') ||
    overlay.querySelector(MODAL_FOCUSABLE);
  if (focusTarget) focusTarget.focus();
}

window.petordersOpenModal = petordersOpenModal;
window.petordersCloseModal = petordersCloseModal;

function buildConfirmModal({ title, message, verb, danger }) {
  const overlay = document.createElement('div');
  overlay.className = 'modal-overlay';

  const modal = document.createElement('div');
  modal.className = 'modal';
  modal.setAttribute('role', 'dialog');
  modal.setAttribute('aria-modal', 'true');
  modal.setAttribute('aria-labelledby', 'petorders-confirm-title');

  const body = document.createElement('div');
  body.className = 'modal__body';

  const heading = document.createElement('h2');
  heading.className = 'modal__title';
  heading.id = 'petorders-confirm-title';
  heading.textContent = title;

  const msg = document.createElement('p');
  msg.className = 'modal__message';
  msg.textContent = message;

  body.append(heading, msg);

  const footer = document.createElement('div');
  footer.className = 'modal__footer';

  const cancelBtn = document.createElement('button');
  cancelBtn.type = 'button';
  cancelBtn.className = 'btn btn--ghost';
  cancelBtn.textContent = 'Cancel';
  cancelBtn.setAttribute('data-modal-close', '');

  const confirmBtn = document.createElement('button');
  confirmBtn.type = 'button';
  confirmBtn.className = danger ? 'btn btn--danger-solid' : 'btn btn--primary';
  confirmBtn.textContent = verb;

  footer.append(cancelBtn, confirmBtn);
  modal.append(body, footer);
  overlay.appendChild(modal);

  return { overlay, confirmBtn };
}

// Promise-based confirm dialog that can STACK on top of an open modal.
// Deliberately does NOT go through petordersOpenModal — the modal system is
// strictly single-modal (opening force-closes activeModal), which would
// kill the host modal underneath. Instead this reuses buildConfirmModal's
// DOM and runs its own tiny lifecycle: appended to <body> above the
// standard overlay (.modal-overlay--stacked, modals.css), window-level
// CAPTURE keydown so it wins over the host modal's document-level capture
// handler (window capture fires first), stopPropagation so Esc/Tab never
// reach the host modal's close/focus-trap logic. Esc, backdrop, and
// Cancel resolve false; the confirm button resolves true. Also works with
// no host modal open — it is fully self-contained.
function petordersConfirm({ title, message, verb, danger }) {
  return new Promise((resolve) => {
    const { overlay, confirmBtn } = buildConfirmModal({ title, message, verb, danger });
    overlay.classList.add('modal-overlay--stacked');
    const cancelBtn = overlay.querySelector('[data-modal-close]');
    const previouslyFocused = document.activeElement;

    const settle = (result) => {
      window.removeEventListener('keydown', keydownHandler, true);
      overlay.remove();
      if (previouslyFocused && document.contains(previouslyFocused)) {
        previouslyFocused.focus();
      }
      resolve(result);
    };

    const keydownHandler = (e) => {
      if (e.key === 'Escape') {
        e.preventDefault();
        e.stopPropagation();
        settle(false);
        return;
      }
      if (e.key === 'Tab') {
        // Mini focus trap over the dialog's two buttons. Trapping Tab
        // here (not just Esc) matters: the host modal's own trap is
        // still listening and would otherwise yank focus back into the
        // modal underneath. Two focusables means forward and backward
        // Tab both just swap between them.
        e.preventDefault();
        e.stopPropagation();
        (document.activeElement === confirmBtn ? cancelBtn : confirmBtn).focus();
      }
    };
    window.addEventListener('keydown', keydownHandler, true);

    // Same backdrop semantics as petordersOpenModal: the mousedown must
    // START on the backdrop, so a drag-select ending outside the card
    // doesn't dismiss it.
    overlay.addEventListener('mousedown', (e) => {
      if (e.target === overlay) settle(false);
    });
    cancelBtn.addEventListener('click', () => settle(false));
    confirmBtn.addEventListener('click', () => settle(true));

    document.body.appendChild(overlay);
    confirmBtn.focus();
  });
}

window.petordersConfirm = petordersConfirm;

function initConfirmForms() {
  document.querySelectorAll('form[data-confirm]').forEach((form) => {
    form.addEventListener('submit', (e) => {
      if (form.dataset.confirmed === 'true') return; // user already confirmed

      e.preventDefault();
      const opener = document.activeElement;
      const { overlay, confirmBtn } = buildConfirmModal({
        title: form.dataset.confirmTitle || 'Are you sure?',
        message: form.dataset.confirm,
        verb: form.dataset.confirmVerb || 'Confirm',
        danger: form.dataset.confirmDanger !== undefined,
      });

      confirmBtn.addEventListener('click', () => {
        setButtonLoading(confirmBtn);
        form.dataset.confirmed = 'true';
        if (typeof form.requestSubmit === 'function') {
          form.requestSubmit();
        } else {
          form.submit();
        }
        // An AJAX form (initAjaxForms) has now started its fetch and no
        // page navigation will sweep this dialog away, so close it here
        // — otherwise a 422 would leave it open forever over the field
        // errors. Re-arming the confirm keeps fix-and-resubmit behavior
        // identical to the full-page path, where the re-rendered form
        // starts unconfirmed.
        if (form.hasAttribute('data-ajax-submit')) {
          petordersCloseModal();
          form.dataset.confirmed = 'false';
        }
      });

      document.body.appendChild(overlay);
      petordersOpenModal(overlay, { opener, temporary: true });
    });
  });
}


// ===== Modal dirty-tracking (shared CRUD Add/Edit wiring) =========
// One wiring shared by every CRUD page's Add/Edit modals and the New
// Order modal: a close attempt on a dirty modal is intercepted via the
// overlay's petordersBeforeClose hook and routed through
// petordersConfirm() as a discard prompt.
//
// `snapshot` stays page-supplied because what counts as a field value
// varies per page (labs.php keys checkbox rosters by name+value,
// products.php skips disabled mirror fields; most pages use a plain
// name -> value map). markPristine() must be called every time the
// modal's fields are (re)populated -- on open and on a validation-
// error reopen -- so only edits made AFTER that point count as dirty.
// Returns { markPristine, isDirty }; isDirty backs the New Order
// modal's beforeunload guard (new_order_form.php).
function petordersWireModalDirtyTracking(overlay, form, snapshot, discardCopy, onDiscard) {
  let pristineValues = {};

  function isDirty() {
    const now = snapshot(form);
    return Object.keys(pristineValues).some((name) => now[name] !== pristineValues[name]);
  }

  overlay.petordersBeforeClose = function () {
    if (!isDirty()) return true;
    window.petordersConfirm({
      title: discardCopy.title,
      message: discardCopy.message,
      verb: 'Discard',
      danger: true,
    }).then((discard) => {
      if (!discard) return;
      if (onDiscard) onDiscard();
      window.petordersCloseModal(true);
    });
    return false;
  };

  return {
    markPristine: function () { pristineValues = snapshot(form); },
    isDirty: isDirty,
  };
}
window.petordersWireModalDirtyTracking = petordersWireModalDirtyTracking;


// ===== Form loading / double-submit guard =========================
// Every submitted form marks its submit button as busy and refuses a
// second submission. Uses a class + pointer-events, NOT `disabled`,
// so no field or button value is dropped from the POST. Attached at
// the document level in the bubble phase, so per-form handlers (like
// the confirm interceptor above) run first — if they preventDefault,
// nothing here fires.

function setButtonLoading(btn) {
  if (!btn || btn.classList.contains('is-loading')) return;
  btn.classList.add('is-loading');
  btn.setAttribute('aria-busy', 'true');
  btn.insertAdjacentHTML('afterbegin', '<span class="spinner" aria-hidden="true"></span>');
}

// Inverse of setButtonLoading, for AJAX submits that stay on the page
// after a failure (a native full-page POST never needs this — the
// response replaces the document). Used only by initAjaxForms below;
// the New Order modal rides that same pipeline via data-ajax-submit.
function clearButtonLoading(btn) {
  if (!btn || !btn.classList.contains('is-loading')) return;
  btn.classList.remove('is-loading');
  btn.removeAttribute('aria-busy');
  const spinner = btn.querySelector('.spinner');
  if (spinner) spinner.remove();
}

function initFormLoadingStates() {
  document.addEventListener('submit', (e) => {
    if (e.defaultPrevented) return;
    const form = e.target;
    if (!(form instanceof HTMLFormElement)) return;

    // Opt-out for forms whose response is a file download rather than a
    // page navigation (e.g. admin/reports.php's CSV export) -- a native
    // full-page submit normally resets the button for free when the
    // response replaces the document, but a Content-Disposition:
    // attachment response never unloads the current page, so
    // setButtonLoading() below would never get a matching
    // clearButtonLoading() and the button would spin forever. Re-triggering
    // a GET export is harmless (unlike a mutating POST), so skipping the
    // double-submit guard here costs nothing.
    if (form.dataset.noLoadingGuard !== undefined) return;

    if (form.dataset.submitting === 'true') {
      e.preventDefault(); // double-submit guard
      return;
    }
    form.dataset.submitting = 'true';

    const btn =
      e.submitter && e.submitter.classList && e.submitter.classList.contains('btn')
        ? e.submitter
        : form.querySelector('button[type="submit"], input[type="submit"]');
    if (btn && btn.tagName === 'BUTTON') {
      setButtonLoading(btn);
    }
  });
}


// ===== Order form cascade (nuclide → product → location) ==========
// Shared behavior behind both renders of the customer order fields:
// the new-order modal (src/partials/new_order_form.php) and the
// pending-order edit form (customer/order_detail.php). Filters the
// product select to the chosen nuclide, toggles the delivery-location
// section per the selected product's fixed delivery method, and keeps
// the fulfillment hint in sync. Attaches its own change listeners on
// the two selects (target phase — they always run before any
// form-level delegated listeners) and runs once immediately so the
// initial paint settles: empty in the modal, pre-populated with the
// order's current values in edit mode (the selected product survives
// the first filter because its data-nuclide-id matches the preselected
// nuclide). Returns { refresh } so a caller can re-derive the cascade
// after form.reset().

function petordersInitOrderCascade({ nuclideSelect, productSelect, locationField, locationSelect, deliveryHint }) {
  const productOptions = Array.from(productSelect.querySelectorAll('option[data-nuclide-id]'));

  function updateLocationRequirement() {
    const selected = productSelect.selectedOptions[0];
    const requiresLocation = !!selected && selected.dataset.requiresLocation === '1';
    // Hidden entirely — not shown-as-optional — when the selected
    // product's fixed delivery method doesn't call for a location.
    // Disabled as well as hidden so a stale pick is excluded from
    // both checkValidity() and the POST, while surviving a toggle
    // away and back.
    locationField.hidden = !requiresLocation;
    locationSelect.disabled = !requiresLocation;
    locationSelect.required = requiresLocation;
  }

  function updateDeliveryHint() {
    const selected = productSelect.selectedOptions[0];
    // data-delivery-label is rendered server-side from the one PHP
    // enum->display mapping, so it never gets re-implemented here.
    if (!selected || !selected.dataset.deliveryLabel) {
      deliveryHint.hidden = true;
      deliveryHint.textContent = '';
      return;
    }
    deliveryHint.textContent = 'Fulfillment: ' + selected.dataset.deliveryLabel;
    deliveryHint.hidden = false;
  }

  function filterProducts() {
    const nuclideId = nuclideSelect.value;
    productOptions.forEach((opt) => {
      // Exact match: each flat product row has exactly one nuclide.
      const matches = opt.dataset.nuclideId === nuclideId;
      opt.hidden = !matches;
      opt.disabled = !matches;
    });
    if (productSelect.selectedOptions[0] && productSelect.selectedOptions[0].hidden) {
      productSelect.value = '';
    }
    productSelect.disabled = !nuclideId;
    updateLocationRequirement();
    updateDeliveryHint();
  }

  function onProductChange() {
    updateLocationRequirement();
    updateDeliveryHint();
  }

  nuclideSelect.addEventListener('change', filterProducts);
  productSelect.addEventListener('change', onProductChange);

  filterProducts();

  return { refresh: filterProducts };
}

window.petordersInitOrderCascade = petordersInitOrderCascade;


// ===== Copy to clipboard ==========================================
// <button data-copy-target="#some-id"> copies that element's text.
// Clipboard API needs a secure context (localhost / HTTPS — both
// true here); a text-selection fallback covers anything older.

function initCopyButtons() {
  document.querySelectorAll('[data-copy-target]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const target = document.querySelector(btn.dataset.copyTarget);
      if (!target) return;
      const text = target.textContent.trim();

      const markCopied = () => {
        const original = btn.textContent;
        btn.textContent = 'Copied';
        setTimeout(() => {
          btn.textContent = original;
        }, 1500);
      };

      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(markCopied, () => selectFallback(target));
      } else {
        selectFallback(target);
      }
    });
  });

  function selectFallback(target) {
    // Can't write to the clipboard — select the value so a manual
    // Ctrl/Cmd+C is one keystroke away.
    const range = document.createRange();
    range.selectNodeContents(target);
    const selection = window.getSelection();
    selection.removeAllRanges();
    selection.addRange(range);
  }
}


// ===== Filter/pagination GET forms: drop empty fields on submit ====
// Every list page's Filter/Search bar and the shared pagination footer
// (src/partials/table_pagination.php) are plain <form method="get"> under
// .table-card-controls. Native GET submission always serializes every
// named field regardless of value -- an empty search box, an
// unselected "All ..." <select> (value="" by convention on every one of
// these forms), or a hidden status/page_size carried forward from a
// previous view -- so submitting one of these forms produced URLs like
// ?status=&q=&role=&fulfillment= even when nothing was actually
// filtering away from the default view. This is the form-submission
// counterpart to build_query()'s own empty/default-value omission
// (helpers.php) -- that covers <a href> links built from $_GET, this
// covers the native browser serialization of these forms, which
// build_query() never sees.
// Disabling a field excludes it from the browser's own serialization --
// no value is lost since the page is navigating away regardless.
//
// Also strips a couple of fields that are never empty but are still
// no-op defaults -- the pagination footer's hardcoded page=1 reset and
// a page_size selection that happens to equal the default. Mirrors
// BUILD_QUERY_DEFAULTS in helpers.php -- keep in sync.
const FORM_FIELD_DEFAULTS = { page: '1', page_size: '10' };

function initFilterFormCleanup() {
  document.querySelectorAll('form.table-card-controls').forEach((form) => {
    if (form.method.toLowerCase() !== 'get') return;
    form.addEventListener('submit', (e) => {
      let anyEnabled = false;
      Array.from(form.elements).forEach((el) => {
        if (!el.name || el.type === 'submit' || el.type === 'button') return;
        if (el.value === '' || FORM_FIELD_DEFAULTS[el.name] === el.value) {
          el.disabled = true;
        } else {
          anyEnabled = true;
        }
      });
      // Every field ended up disabled -- a native submit would still land
      // on a trailing bare "?", the form-submission counterpart to the
      // bare-"?" bug build_query() already guards against for links.
      // Skip the browser's own serialization and go straight to the
      // form's path with no query string.
      if (!anyEnabled) {
        e.preventDefault();
        window.location.href = form.action.split('?')[0];
      }
    });
  });
}


// ===== Reports form (admin/reports.php) ===========================
// Report Criteria form: pre-fills a last-30-days date range on load, and
// Reset Filters restores that same range plus clears every select back to
// "All" (value ""). No-op on every other page — guarded on #report-form's
// absence, same as the rest of this file's init functions.
//
// #report-form is method="GET" and its target (export_csv.php) streams
// back a CSV file (Content-Disposition: attachment) -- the shared
// data-ajax-submit/initAjaxForms() convention doesn't fit here (it always
// fetch()es via POST and always parses the response as JSON on a 2xx,
// which would swallow the CSV bytes instead of downloading anything), so
// this form carries data-no-loading-guard and is handled on its own.
// export_csv.php's only rule is "both dates present" -- exactly what
// `required` already encodes, and a native <input type="date"> never
// emits a malformed value once non-empty, so there's no case a server
// round-trip would catch that this client-only check doesn't. The form
// also carries novalidate so this renders red banner/field text (the same
// renderFieldErrors()/data-error-banner-for contract every other converted
// form uses) instead of the browser's native validation tooltip; a
// passing submission falls through untouched to the ordinary native GET,
// so the download behaves exactly as it always has.

function initReportsForm() {
  const form = document.getElementById('report-form');
  if (!form) return;

  const startDateInput = document.getElementById('start_date');
  const endDateInput = document.getElementById('end_date');
  const resetBtn = document.getElementById('reset-dates');
  if (!startDateInput || !endDateInput || !resetBtn) return;

  function setDefaultDateRange() {
    const today = new Date();
    const lastMonth = new Date();
    lastMonth.setDate(today.getDate() - 30);
    endDateInput.valueAsDate = today;
    startDateInput.valueAsDate = lastMonth;
  }

  setDefaultDateRange();

  resetBtn.addEventListener('click', () => {
    setDefaultDateRange();
    form.querySelectorAll('select').forEach((select) => {
      select.value = '';
    });
  });

  form.addEventListener('submit', (e) => {
    const errors = {};
    if (!startDateInput.value) {
      errors.start_date = 'From Date is required.';
    }
    if (!endDateInput.value) {
      errors.end_date = 'To Date is required.';
    }
    if (Object.keys(errors).length === 0) return; // both valid, native GET submit proceeds untouched

    e.preventDefault();
    renderFieldErrors(form, errors);
  });
}


// ===== Field errors (shared render/clear + clear-on-fix) ==========
// The app-wide field-error DOM contract: field_class() puts
// field--invalid on the .field wrapper, field_error() appends
// span.field-error inside it. The render/clear pair below injects and
// removes markup byte-compatible with that, so AJAX-injected errors
// are indistinguishable from server-rendered ones. A form's optional
// summary banner is the element carrying
// data-error-banner-for="<form id>" — matched by attribute, not
// containment, because the New Order modal's banner sits outside its
// form element.

function formErrorBanner(form) {
  if (!form || !form.id) return null;
  return document.querySelector('[data-error-banner-for="' + form.id + '"]');
}

function clearFieldErrors(form) {
  const banner = formErrorBanner(form);
  if (banner) banner.hidden = true;
  form.querySelectorAll('.field-error').forEach((el) => el.remove());
  form.querySelectorAll('.field--invalid').forEach((el) => {
    el.classList.remove('field--invalid');
  });
}

// Some forms (e.g. products.php's edit modal) share one name between an
// enabled control and a disabled "locked" mirror -- exactly one enabled
// at a time (see the applyLockState comment there). form.elements[name]
// then returns a RadioNodeList, which has no .closest(); resolve it to
// whichever sharing element is actually enabled so its error still
// renders inline instead of silently falling back to banner-only.
// Separately, a checkbox/multi-select group (e.g. labs.php's PI roster)
// posts under "name[]" while its server error key is the bare "name" --
// falling back to the bracketed form covers that convention too; any
// one checkbox in the group resolves to the same shared .field wrapper.
function resolveNamedFormControl(form, name) {
  let match = form.elements[name];
  if (!match) match = form.elements[name + '[]'];
  if (!match || !(match instanceof RadioNodeList)) return match;
  for (const el of match) {
    if (!el.disabled) return el;
  }
  return match[0];
}

function renderFieldErrors(form, errors) {
  clearFieldErrors(form);
  let firstInvalidControl = null;
  Object.keys(errors).forEach((name) => {
    const control = resolveNamedFormControl(form, name);
    if (!control || !control.closest) return; // unknown key — banner still shows
    const fieldWrap = control.closest('.field');
    if (!fieldWrap) return;
    fieldWrap.classList.add('field--invalid');
    const span = document.createElement('span');
    span.className = 'field-error';
    span.textContent = errors[name];
    fieldWrap.appendChild(span);
    if (!firstInvalidControl) firstInvalidControl = control;
  });
  const banner = formErrorBanner(form);
  if (banner) banner.hidden = false;
  if (firstInvalidControl) firstInvalidControl.focus();
}

// ===== Field-error clearing =======================================
// A field showing a validation error — server-rendered via
// field_class()/field_error() or AJAX-injected — clears it on the
// user's next input/change: drop the wrapper's field--invalid modifier
// and remove its .field-error span(s). Delegated at the document level
// so errors injected after load are covered too, with no per-form
// wiring. Only the edited field clears; sibling errors stay until
// their own edit or the next submit's server verdict — and once the
// form's LAST invalid field clears, its summary banner (if it has
// one) hides too.

function initFieldErrorClearing() {
  ['input', 'change'].forEach((type) => {
    document.addEventListener(type, (e) => {
      const wrap = e.target.closest && e.target.closest('.field--invalid');
      if (!wrap) return;
      wrap.classList.remove('field--invalid');
      wrap.querySelectorAll('.field-error').forEach((el) => el.remove());
      const form = wrap.closest('form');
      if (form && !form.querySelector('.field--invalid')) {
        const banner = formErrorBanner(form);
        if (banner) banner.hidden = true;
      }
    });
  });
}


// ===== AJAX form submit ===========================================
// Any <form data-ajax-submit> posts via fetch instead of a full-page
// POST — including the New Order modal's form (new_order_form.php,
// which layers its own pre-submit confirm on top via the same
// dataset.confirmed contract initConfirmForms uses): FormData carries
// the CSRF token and matches native submit semantics; the
// X-Requested-With header is what the
// server's request_wants_json() (helpers.php) keys on, so the same
// page keeps working as a normal POST fallback without JS. The JSON
// contract is json_response()'s: {ok:true, redirect} → navigate (the
// page's usual PRG destination, arrival-flag toast included);
// {ok:false, errors} (422) → per-field red text + summary banner via
// renderFieldErrors above; {ok:false, message} → error toast, unless
// the form opts into data-ajax-inline-error, which routes the message
// into its persistent [data-ajax-error] alert instead (login.php —
// same element its no-JS fallback renders into). A form with
// data-reset-on-error additionally clears its username/password
// inputs after a failed attempt so stale credentials never linger.
// Listeners attach per form (target phase), so they run before the
// document-level loading guard — which skips preventDefault-ed
// submits — and loading state is owned here, as in New Order.

function initAjaxForms() {
  document.querySelectorAll('form[data-ajax-submit]').forEach((form) => {
    form.addEventListener('submit', (e) => {
      // A data-confirm form's interceptor (attached first — see the
      // DOMContentLoaded init order) preventDefaults the unconfirmed
      // submit to show its dialog; fetching then would bypass the
      // confirmation. The confirmed requestSubmit() arrives unprevented.
      if (e.defaultPrevented) return;
      e.preventDefault();
      if (form.dataset.submitting === 'true') return;
      form.dataset.submitting = 'true';

      const btn =
        e.submitter && e.submitter.classList && e.submitter.classList.contains('btn')
          ? e.submitter
          : form.querySelector('button[type="submit"]');
      setButtonLoading(btn);

      function finishSubmitAttempt() {
        form.dataset.submitting = 'false';
        clearButtonLoading(btn);
      }

      // getAttribute, NOT form.action: these forms carry a hidden input
      // named "action" (the CRUD verb), and HTMLFormElement's named-control
      // access overrides built-in properties -- form.action would be that
      // input element, not the URL.
      fetch(form.getAttribute('action') || window.location.href, {
        method: 'POST',
        body: new FormData(form),
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      })
        .then((response) => {
          if (response.redirected) {
            // require_role() bounced us (idle timeout, forced password
            // change) — follow its redirect for real.
            window.location.href = response.url;
            return null;
          }
          if (response.ok || response.status === 422) {
            return response.json();
          }
          // CSRF failure (403 text), 500s, anything non-JSON.
          throw new Error('Unexpected response ' + response.status);
        })
        .then((data) => {
          if (!data) return; // already navigating
          if (data.ok) {
            if (data.redirect) {
              // Button stays in its loading state while the browser
              // navigates to the usual PRG destination.
              window.location.href = data.redirect;
              return;
            }
            // No redirect target -- a self-contained detail-page form
            // (account_detail.php/customer_detail.php's Edit forms) that
            // never PRGs even on a full-page POST, just re-renders in
            // place with a success toast. Same visible result, no reload.
            clearFieldErrors(form);
            if (data.message) window.showToast('success', data.message);
            finishSubmitAttempt();
            return;
          }
          if (data.errors) renderFieldErrors(form, data.errors);
          if (data.message) {
            const inlineAlert = form.hasAttribute('data-ajax-inline-error')
              ? form.parentElement.querySelector('[data-ajax-error]')
              : null;
            if (inlineAlert) {
              inlineAlert.textContent = data.message;
              inlineAlert.hidden = false;
            } else {
              window.showToast('error', data.message);
            }
          }
          if (form.hasAttribute('data-reset-on-error')) {
            const usernameInput = form.elements.username;
            const passwordInput = form.elements.password;
            if (usernameInput) usernameInput.value = '';
            if (passwordInput) passwordInput.value = '';
            if (usernameInput) usernameInput.focus();
          }
          finishSubmitAttempt();
        })
        .catch(() => {
          window.showToast('error', 'Something went wrong. Please try again.');
          finishSubmitAttempt();
        });
    });
  });
}


// ===== Init (single entry point — order matters: sidebar first so its
// pre-paint collapsed/submenu state is wired up before anything else
// touches the DOM, then the page-wide confirm/form/copy/dashboard
// behaviors) ========================================================

// ===== bfcache backstop after logout ===============================
// Server sends no-store (see require_role() in src/auth.php), but some
// browsers still restore a bfcache snapshot on back/forward before
// re-requesting. Force a reload on any bfcache restore so a stale
// authenticated page is never shown after logout.
window.addEventListener('pageshow', (e) => {
  if (e.persisted) window.location.reload();
});


document.addEventListener('DOMContentLoaded', () => {
  initSidebarToggle();
  initHamburgerToggle();
  initSidebarBackdrop();
  initSidebarMobileSafety();
  initSidebarSubmenus();

  initConfirmForms();
  initFormLoadingStates();
  initFilterFormCleanup();
  initFieldErrorClearing();
  initAjaxForms();
  initCopyButtons();
  initReportsForm();
});
