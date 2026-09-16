/**
 * SIRE — the host bridge. The only place SIRE's frontend meets yours.
 *
 * THE PROBLEM THIS SOLVES
 *
 * SIRE's components used to import your files directly:
 *
 *     import AsyncButton from '../../../components/ui/AsyncButton';
 *     import { useToast } from '../../../hooks/useToast';
 *     import api from '../../../lib/api';
 *
 * Thirty-six imports across the module, each one a hard requirement that a host
 * own a file with that exact name at that exact depth. A CRM using MUI, or
 * Chakra, or its own kit, or keeping its axios instance somewhere else, could
 * not mount a single SIRE screen without editing SIRE.
 *
 * Now there is one bridge. SIRE imports from here; here resolves to whatever the
 * host registered, and falls back to SIRE's own implementations when the host
 * registered nothing.
 *
 * REGISTER IN ONE PLACE, ONCE:
 *
 *     import { SireHost } from './lib/sire/host';
 *     import api from './lib/api';
 *     import { useToast } from './hooks/useToast';
 *     import { Button, Dialog } from '@mui/material';
 *
 *     SireHost.configure({
 *       api,                                  // any axios-like client
 *       ui: { Button, Modal: Dialog },        // whatever you have; the rest fall back
 *       toast: useToast,
 *       navigate: (path) => router.push(path),
 *       auth: () => currentUser,
 *     });
 *
 * EVERY SLOT IS OPTIONAL. Register nothing and SIRE works, using its own
 * components and a fetch-based client. Register the three that matter — api,
 * toast, ui — and SIRE looks native.
 *
 * WHY A REGISTRY RATHER THAN PROPS
 *
 * SIRE has around forty components, most of them nested several levels below the
 * screens a host mounts. Threading a UI kit through that as props would put a
 * dozen pass-through parameters on every intermediate component, and any host
 * that missed one would get a crash deep in a panel. A registry is read at the
 * leaves, which is where the components actually are.
 */

import { defaultApi } from './defaultApi';
import * as defaultUi from './defaultUi.jsx';

/** Everything a host may override, and what SIRE uses when it does not. */
const registry = {
  api: null,
  ui: {},
  toast: null,
  navigate: null,
  auth: null,
  translate: null,
};

let configured = false;

export const SireHost = {
  /**
   * Point SIRE at your application's building blocks.
   *
   * Partial is expected: register `api` and `ui.Button` and leave the rest.
   * Calling this twice merges rather than replaces, so a host can configure the
   * API client at bootstrap and the UI kit later without losing the first.
   */
  configure(config = {}) {
    if (config.api) registry.api = config.api;
    if (config.toast) registry.toast = config.toast;
    if (config.navigate) registry.navigate = config.navigate;
    if (config.auth) registry.auth = config.auth;
    if (config.translate) registry.translate = config.translate;

    if (config.ui && typeof config.ui === 'object') {
      // Merge, so { ui: { Button } } does not erase a previously registered Modal.
      registry.ui = { ...registry.ui, ...config.ui };
    }

    configured = true;
    return SireHost;
  },

  /** Which slots the host filled. Read by the doctor and the install report. */
  status() {
    return {
      configured,
      api: registry.api !== null,
      toast: registry.toast !== null,
      navigate: registry.navigate !== null,
      auth: registry.auth !== null,
      ui: Object.keys(registry.ui),
    };
  },

  /** Test seam. Never called by SIRE itself. */
  reset() {
    registry.api = null;
    registry.ui = {};
    registry.toast = null;
    registry.navigate = null;
    registry.auth = null;
    registry.translate = null;
    configured = false;
  },
};

/**
 * The HTTP client SIRE talks to its own API with.
 *
 * A host-registered client is used as-is, which means SIRE inherits its base
 * URL, auth header, CSRF token, interceptors and error handling for free —
 * exactly what makes SIRE feel native rather than bolted on.
 */
export const sireHostApi = () => registry.api ?? defaultApi;

/**
 * A UI component by name, falling back to SIRE's own.
 *
 * The fallbacks are deliberately plain: unstyled-but-accessible elements that
 * work anywhere rather than a second design system competing with the host's.
 */
export const sireHostUi = (name) => registry.ui[name] ?? defaultUi[name] ?? defaultUi.Unstyled;

/**
 * Confirmation after an action.
 *
 * The fallback is a no-op, not an alert(). A module that starts throwing browser
 * dialogs into a host's carefully designed flow has made itself unwelcome; a
 * silent success is a smaller failure than a jarring one.
 */
export const sireHostToast = () => registry.toast ?? (() => () => {});

/** Navigation. Falls back to a full page load, which always works. */
export const sireHostNavigate = () =>
  registry.navigate ?? ((path) => { window.location.assign(path); });

/**
 * The current user, if the host exposes one.
 *
 * SIRE does not need this: authorization is decided server-side and the API
 * returns what the caller may see. It is used only to hide controls a user
 * cannot use, which is a courtesy rather than a control.
 */
export const sireHostAuth = () => registry.auth ?? (() => null);

/** Translation, if the host has an i18n layer. Falls back to the string itself. */
export const sireHostTranslate = () => registry.translate ?? ((key) => key);

export default SireHost;
