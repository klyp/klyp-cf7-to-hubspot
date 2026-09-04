/**
 * Settings screen behaviour.
 *
 * The connection test runs after the page has painted, so a slow or unreachable
 * HubSpot never delays the screen itself. The same request returns the account's
 * form list, which fills the sidebar.
 */
(function () {
  'use strict';

  const config = window.klypCf7HsSettings || {};
  const strings = config.i18n || {};
  const STATES = ['checking', 'connected', 'error', 'unconfigured'];

  let statusEl;
  let listEl;

  /**
   * Paints the status banner.
   *
   * @param {string} state   One of STATES.
   * @param {string} message Text to show.
   */
  function setStatus(state, message) {
    STATES.forEach(function (name) {
      statusEl.classList.remove('klyp-cf7hs-status--' + name);
    });
    statusEl.classList.add('klyp-cf7hs-status--' + state);

    statusEl.querySelector('.klyp-cf7hs-status__text').textContent = message;

    const retry = statusEl.querySelector('.klyp-cf7hs-status__retry');

    if (retry) {
      retry.hidden = state === 'checking';
    }
  }

  /**
   * Replaces the sidebar list with a single muted message.
   *
   * @param {string} message Text to show.
   */
  function setListMessage(message) {
    const p = document.createElement('p');
    p.className = 'klyp-cf7hs-muted';
    p.textContent = message;

    listEl.replaceChildren(p);
  }

  /**
   * Copies text, falling back to a temporary field where the async Clipboard
   * API is unavailable (it needs a secure context).
   *
   * @param {string}   text Text to place on the clipboard.
   * @param {Function} done Called once the copy has been attempted.
   */
  function copy(text, done) {
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(done, function () {
        fallbackCopy(text, done);
      });
      return;
    }

    fallbackCopy(text, done);
  }

  function fallbackCopy(text, done) {
    const field = document.createElement('textarea');
    field.readOnly = true;
    field.value = text;
    field.className = 'klyp-cf7hs-offscreen';
    document.body.appendChild(field);
    field.select();

    try {
      document.execCommand('copy');
      done();
    } catch (error) {
      /* Nothing useful to do; the ID is still visible to select by hand. */
    }

    field.remove();
  }

  /**
   * Builds the sidebar list of HubSpot forms.
   *
   * @param {Array} forms Objects with id and name.
   */
  function renderForms(forms) {
    if (!Array.isArray(forms) || forms.length === 0) {
      setListMessage(strings.noForms || 'No forms found.');
      return;
    }

    const list = document.createElement('ul');

    forms.forEach(function (form) {
      const name = document.createElement('span');
      name.className = 'klyp-cf7hs-form-list__name';
      name.textContent = form.name;

      const id = document.createElement('span');
      id.className = 'klyp-cf7hs-form-list__id';
      id.textContent = form.id;

      const text = document.createElement('span');
      text.append(name, id);

      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'button button-small';
      button.textContent = strings.copy || 'Copy ID';
      button.addEventListener('click', function () {
        copy(form.id, function () {
          button.textContent = strings.copied || 'Copied';
          window.setTimeout(function () {
            button.textContent = strings.copy || 'Copy ID';
          }, 1600);
        });
      });

      const item = document.createElement('li');
      item.append(text, button);
      list.appendChild(item);
    });

    listEl.replaceChildren(list);
  }

  /**
   * Asks the server to test the stored credentials.
   */
  function test() {
    setStatus('checking', strings.checking || 'Checking…');

    const body = new URLSearchParams();
    body.set('action', config.action);
    body.set('nonce', config.nonce);

    window.fetch(config.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString()
    })
      .then(function (response) {
        return response.json();
      })
      .then(function (payload) {
        if (!payload || !payload.success || !payload.data) {
          setStatus('error', strings.failed);
          return;
        }

        const data = payload.data;
        const state = STATES.indexOf(data.state) === -1 ? 'error' : data.state;

        setStatus(state, data.message || strings.failed);

        if (state === 'connected') {
          renderForms(data.forms);
        } else {
          setListMessage(strings.unavail || '');
        }
      })
      .catch(function () {
        setStatus('error', strings.failed || 'Could not reach HubSpot.');
        setListMessage(strings.unavail || '');
      });
  }

  function init() {
    statusEl = document.getElementById('klyp-cf7hs-status');
    listEl = document.getElementById('klyp-cf7hs-form-list');

    document.querySelectorAll('.klyp-cf7hs__reveal').forEach(function (toggle) {
      const input = document.getElementById(toggle.getAttribute('data-target'));

      if (!input) {
        return;
      }

      toggle.addEventListener('click', function () {
        const hidden = input.type === 'password';

        input.type = hidden ? 'text' : 'password';
        toggle.textContent = hidden ? (strings.hide || 'Hide') : (strings.show || 'Show');
        toggle.setAttribute('aria-pressed', hidden ? 'true' : 'false');
        input.focus();
      });
    });

    if (!statusEl || !listEl || !config.ajaxUrl) {
      return;
    }

    const retry = statusEl.querySelector('.klyp-cf7hs-status__retry');

    if (retry) {
      retry.addEventListener('click', test);
    }

    if (config.configured) {
      test();
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
