/**
 * Behaviour for the HubSpot Integration panel in the Contact Form 7 editor.
 *
 * - Repeaters: the row template is a hidden <tbody> with disabled controls,
 *   because Contact Form 7 runs the editor markup through wp_kses(), which
 *   strips <template>. Cloning re-enables the controls.
 * - "Match fields by name": adds a mapping row for every unmapped CF7 field
 *   whose name (after light normalisation) matches a HubSpot field.
 * - Deal toggle: `data-shows="id"` reveals a block while checked.
 * - Pipeline → stage: hides stage groups that belong to other pipelines.
 */
(function () {
  'use strict';

  const strings = (window.klypCf7HsEditor && window.klypCf7HsEditor.i18n) || {};

  /* ------------------------------------------------------------------ */
  /* Repeaters                                                            */
  /* ------------------------------------------------------------------ */

  function syncEmptyState(repeater) {
    const rows = repeater.querySelector('[data-repeater-rows]');
    const empty = repeater.querySelector('[data-repeater-empty]');

    if (!rows || !empty) {
      return;
    }

    empty.hidden = rows.querySelectorAll(':scope > tr').length > 0;
  }

  /**
   * Clones the template row into the live rows.
   *
   * @param  {HTMLElement} repeater The repeater wrapper.
   * @return {HTMLElement|null}     The new row.
   */
  function addRow(repeater) {
    const template = repeater.querySelector('[data-repeater-template] tr');
    const rows = repeater.querySelector('[data-repeater-rows]');

    if (!template || !rows) {
      return null;
    }

    const row = template.cloneNode(true);

    row.querySelectorAll('[disabled]').forEach(function (control) {
      control.disabled = false;
    });

    rows.appendChild(row);
    syncEmptyState(repeater);

    return row;
  }

  /* ------------------------------------------------------------------ */
  /* Match fields by name                                                 */
  /* ------------------------------------------------------------------ */

  /**
   * Common ways a CF7 author names a field, collapsed onto HubSpot's names.
   * Deliberately conservative: anything not listed only matches on an exact
   * (normalised) name, and the author still reviews before saving.
   */
  const ALIASES = {
    email: 'email', emailaddress: 'email', mail: 'email', youremail: 'email',
    phone: 'phone', tel: 'phone', telephone: 'phone', mobile: 'phone', phonenumber: 'phone',
    firstname: 'firstname', fname: 'firstname', first: 'firstname', givenname: 'firstname',
    lastname: 'lastname', lname: 'lastname', surname: 'lastname', last: 'lastname', familyname: 'lastname',
    company: 'company', organisation: 'company', organization: 'company', business: 'company', companyname: 'company',
    message: 'message', comments: 'message', comment: 'message', enquiry: 'message', inquiry: 'message', yourmessage: 'message',
    subject: 'subject', yoursubject: 'subject',
    website: 'website', url: 'website', web: 'website',
    jobtitle: 'jobtitle', position: 'jobtitle', role: 'jobtitle',
    city: 'city', suburb: 'city',
    state: 'state', region: 'state',
    postcode: 'zip', zip: 'zip', zipcode: 'zip', postalcode: 'zip',
    country: 'country'
  };

  function normalise(name) {
    const key = String(name).toLowerCase().replace(/^your[-_]?/, '').replace(/[^a-z0-9]/g, '');
    return ALIASES[key] || key;
  }

  function optionValues(select) {
    return Array.prototype.slice.call(select.options)
      .map(function (option) { return option.value; })
      .filter(function (value) { return value !== ''; });
  }

  /**
   * Adds rows for every unmapped CF7 field with a same-named HubSpot field.
   *
   * @param {HTMLElement} repeater The mapping repeater.
   * @param {HTMLElement} output   Where to report the outcome.
   */
  function matchByName(repeater, output) {
    const template = repeater.querySelector('[data-repeater-template] tr');

    if (!template) {
      return;
    }

    const selects = template.querySelectorAll('select');
    const cf7Names = optionValues(selects[0]);
    const hsNames = optionValues(selects[1]);

    const hsByKey = {};
    hsNames.forEach(function (name) {
      const key = normalise(name);
      if (!hsByKey[key]) {
        hsByKey[key] = name;
      }
    });

    // Only the first cell of each row holds the Contact Form 7 field. The
    // previous ':first-of-type' half of this selector also matched the HubSpot
    // select (each is the only <select> in its own <td>), so a HubSpot field
    // name counted as an already-mapped CF7 name and suppressed real matches.
    const alreadyMapped = {};
    repeater.querySelectorAll('[data-repeater-rows] > tr > td:first-child select').forEach(function (select) {
      if (select.value) {
        alreadyMapped[select.value] = true;
      }
    });

    let added = 0;

    cf7Names.forEach(function (cf7Name) {
      if (alreadyMapped[cf7Name]) {
        return;
      }

      const hsName = hsByKey[normalise(cf7Name)];

      if (!hsName) {
        return;
      }

      const row = addRow(repeater);

      if (!row) {
        return;
      }

      const rowSelects = row.querySelectorAll('select');
      rowSelects[0].value = cf7Name;
      rowSelects[1].value = hsName;
      row.classList.add('klyp-cf7hs-repeater__row--new');
      added += 1;
    });

    let message;

    if (added === 0) {
      message = strings.matchedNone || 'No matching field names found.';
    } else {
      message = (added === 1 ? strings.matchedOne : strings.matchedMany) || 'Added %d.';
      message = message.replace('%d', String(added));
    }

    output.textContent = message;
    output.classList.toggle('is-empty', added === 0);
  }

  /* ------------------------------------------------------------------ */
  /* Pipeline → stage                                                     */
  /* ------------------------------------------------------------------ */

  /**
   * Shows only the stage group for the chosen pipeline.
   *
   * @param {HTMLSelectElement} pipeline The pipeline select.
   * @param {HTMLSelectElement} stage    The stage select.
   * @param {boolean}           reset    Clear a stage that no longer fits.
   */
  function filterStages(pipeline, stage, reset) {
    const chosen = pipeline.value;

    stage.querySelectorAll('optgroup').forEach(function (group) {
      const hide = chosen !== '' && group.getAttribute('data-group') !== chosen;

      // Safari ignores display on optgroups, so disable as well as hide.
      group.hidden = hide;
      group.disabled = hide;
    });

    if (reset && stage.selectedOptions.length) {
      const current = stage.selectedOptions[0];
      const group = current.parentElement;

      if (group && group.tagName === 'OPTGROUP' && group.disabled) {
        stage.value = '';
      }
    }
  }

  /* ------------------------------------------------------------------ */
  /* Init                                                                 */
  /* ------------------------------------------------------------------ */

  function init() {
    document.querySelectorAll('[data-repeater]').forEach(function (repeater) {
      syncEmptyState(repeater);

      repeater.addEventListener('click', function (event) {
        const target = event.target;

        const addButton = target.closest('[data-repeater-add]');
        if (addButton && repeater.contains(addButton)) {
          event.preventDefault();
          const row = addRow(repeater);
          const first = row && row.querySelector('select, input');
          if (first) {
            first.focus();
          }
          return;
        }

        const matchButton = target.closest('[data-repeater-match]');
        if (matchButton && repeater.contains(matchButton)) {
          event.preventDefault();
          const output = repeater.querySelector('[data-repeater-match-result]');
          if (output) {
            matchByName(repeater, output);
          }
          return;
        }

        const removeButton = target.closest('[data-repeater-remove]');
        if (removeButton && repeater.contains(removeButton)) {
          event.preventDefault();
          const tr = removeButton.closest('tr');
          if (tr) {
            tr.remove();
            syncEmptyState(repeater);
          }
        }
      });
    });

    document.querySelectorAll('[data-shows]').forEach(function (checkbox) {
      const target = document.getElementById(checkbox.getAttribute('data-shows'));

      if (!target) {
        return;
      }

      checkbox.addEventListener('change', function () {
        target.hidden = !checkbox.checked;
      });
    });

    document.querySelectorAll('[data-stage-for]').forEach(function (stage) {
      const pipeline = document.getElementById(stage.getAttribute('data-stage-for'));

      if (!pipeline) {
        return;
      }

      filterStages(pipeline, stage, false);

      pipeline.addEventListener('change', function () {
        filterStages(pipeline, stage, true);
      });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
