=== Klyp CF7 to HubSpot ===
Contributors: klyp
Tags: contact, form, cf7, hubspot
Requires at least: 6.7
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 2.0.0
License: GPL-2.0-only
License URI: https://www.gnu.org/licenses/gpl-2.0.txt

Send Contact Form 7 submissions into a HubSpot form, and optionally raise an associated deal.

== Description ==
Maps Contact Form 7 fields onto the fields of a HubSpot form, submits them through the
HubSpot Forms API, and can create a deal associated with the matching contact.

Authentication uses a HubSpot private app token. HubSpot switched off API key ("hapikey")
authentication on 30 November 2022, so the legacy key mode has been removed.

= Required private app scopes =
* `forms` — read HubSpot form definitions so fields can be mapped
* `crm.objects.contacts.read` — find the contact behind a submission (deals only)
* `crm.objects.deals.write` — create and update deals (deals only)
* `crm.objects.deals.read` — optional; turns the pipeline and stage fields into
  drop-downs instead of free-text IDs

Only `forms` is needed if you are not creating deals.

= Storing the token outside the database =
Define the token in `wp-config.php` and it is never written to the options table:

`define( 'KLYP_CF7_HUBSPOT_TOKEN', 'pat-xx1-...' );`

== Changelog ==

= 2.0.0 - 2026.09.04 =
Rewrite for Contact Form 7 6.x and PHP 8.5.

Breaking:
* Requires PHP 8.0+, WordPress 6.7+ and Contact Form 7 5.8+.
* Removed the public API key ("hapikey") field. HubSpot sunset that auth mode on
  30 November 2022, so it could no longer work. Use a private app token.
* The internal `klypHubspot` class and the `klypCf7Hs*` / `klypCF7ToHubspot*` global
  functions have been replaced by namespaced classes under `Klyp\CF7ToHubspot`.

Fixed:
* Fatal `TypeError: count(): Argument #1 must be of type Countable|array` on PHP 8
  whenever a form had no field mapping or no deal-breaker rules saved.
* `getFormFields()` called `exit()` on any API failure, which killed the Contact Form 7
  edit screen mid-render and left a blank page.
* Per-form settings were written against post ID -1 the first time a new form was
  saved, so the mapping silently vanished. Settings now persist on `wpcf7_after_save`,
  once the form has a real ID.
* Undeclared `$data` dynamic property (deprecated in PHP 8.2, removed in PHP 9).
* Undefined variable/array key warnings in the contact lookup and deal-breaker paths.
* Off-by-one `$i <= count()` loops read one element past the end of every mapping array.

Fixed after review:
* A `type="url"` redirect field inside Contact Form 7's editor (which has no
  `novalidate`) blocked Save on every tab whenever the value was a site-relative
  path. The control is inside a hidden panel, so the browser could not even focus
  it to say why. It is now `type="text"`, validated server-side.
* `stash_settings()` typed its `$context` parameter as `string`, but Contact Form
  7's REST routes pass the raw `context` request param, which is null when
  omitted — that made every `POST /wp-json/contact-form-7/v1/contact-forms/<id>`
  a fatal 500 for every form on the site, HubSpot-connected or not.
* Submissions were gated on both a token and a portal ID, but the Forms endpoint
  is unauthenticated and needs only the portal ID. A 1.x install that never had a
  private app token silently stopped sending anything after upgrading.
* Deal creation looked the contact up immediately after submitting the form, but
  HubSpot processes Forms submissions asynchronously, so a first-time visitor's
  contact did not exist yet and their deal was silently skipped. The CRM work is
  now deferred to a scheduled event, which also removes three blocking HTTP calls
  (up to 45s) from the visitor's request.
* A HubSpot rejection that did not name a mapped field was invisible outside the
  PHP error log. It is now recorded per form and shown in the editor panel, and
  clears itself after a successful submission.
* The "Refresh" link in the editor lost everything after its first `&`, because
  `add_query_arg()` does not encode new values; it landed on the forms list
  instead of returning to the form's HubSpot tab. It also `wp_die()`d for editors,
  who can open the panel but lack `manage_options`.
* "Match fields by name" treated HubSpot field names as already-mapped Contact
  Form 7 names, suppressing genuine matches.
* Panel assets never loaded on the Add New screen, whose hook suffix differs.
* The upgrade routine called `delete_post_meta(-1, ...)`, which `absint()`s to 1
  and so deleted plugin meta from post ID 1 rather than cleaning up anything.
* "Settings saved." and every validation notice appeared twice, because
  wp-admin/options-head.php already prints them for pages under Settings.
* A valid API base URL entered without a scheme, or in capitals, was reported as
  rejected even though it was accepted.
* The token could not be cleared with `wp option update ... ''` or from a deploy
  script: an empty value always restored the stored one.
* A caching miss did a read and a write of an index option and silently dropped
  keys past 200, so "Clear cache" was incomplete. Invalidation is now a single
  generation counter, and the cache key includes the token and host so rotating
  either cannot serve the previous portal's data.
* A network blip was cached as "no fields came back", blaming the form ID or the
  token scope for up to a minute. Only real HTTP answers are cached now.
* `list_forms()` read one page, so an account with more than 100 forms was
  silently truncated. It now follows HubSpot's paging.
* An EU-region portal read its forms from api-eu1.hubapi.com but still posted
  every submission to the NA Forms host. The Forms host is now derived from the
  configured region.
* Textarea line breaks were flattened and nested arrays became empty, because the
  value flattener re-implemented Contact Form 7's. It now uses
  `wpcf7_flat_join()`.
* Submission context was rebuilt from the referrer with `url_to_postid()`, which
  returns 0 for archives and the front page and needs an extra query. It now
  reads Contact Form 7's own `url` and `container_post_id` metadata, decodes the
  page title (no more "Sales &#038; Support"), and sends `ipAddress`.
* Redirects are resolved against the site rather than the current request, so a
  relative target no longer produced a /wp-json/... URL during AJAX submissions,
  and a bare path such as `thank-you/` is no longer stored as `http://thank-you/`.
* An off-site redirect is now flagged in the editor instead of being silently
  dropped at runtime.
* Post meta was unslashed twice, so a value containing a backslash lost one level
  of escaping on every save.
* Non-AJAX submissions never honoured the redirect at all.
* Deal creation is stored as an explicit '1'/'0' rather than inferred from
  whether other fields happen to be filled in, so ticking the box and saving
  before choosing the email fields no longer silently reverts. Existing forms are
  migrated on upgrade.
* The field pickers no longer offer tag types Contact Form 7 never posts.
* Only the token, portal ID and base URL are read from hard-coded option names in
  one place; uninstall reads the same constants rather than a second list.

Security:
* HubSpot field labels, Contact Form 7 field names and deal-breaker values are now
  escaped in the editor panel. Previously they were echoed raw into attributes.
* The private app token is rendered as an empty password field with a masked
  placeholder instead of a plain-text input containing the secret.
* The API base URL is constrained to hubapi.com, so the token cannot be sent elsewhere.
* Redirect targets are validated with `wp_validate_redirect()` server-side and
  re-checked as same-origin in the browser.
* All settings are registered with explicit `sanitize_callback`s.
* The token is no longer attached to the unauthenticated Forms submission endpoint.

Changed:
* Migrated to the HubSpot CRM v3 API (`crm/v3/objects/contacts`, `crm/v3/objects/deals`)
  from the legacy `contacts/v1` and `deals/v1` endpoints.
* Replaced the deprecated `wpcf7_ajax_json_echo` filter (deprecated since Contact
  Form 7 5.2) with `wpcf7_feedback_response`.
* Submissions are handled on `wpcf7_before_send_mail` rather than by abusing the
  `wpcf7_spam` filter, so a HubSpot validation failure reports a real error instead
  of flagging the visitor as a spammer.
* HubSpot form definitions are cached for 15 minutes instead of being refetched on
  every admin page load, with a "Clear cache" action on the settings screen.
* The redirect script is enqueued only on pages that render a form; 1.x printed an
  inline `<script>` into the footer of every page on the site.
* Rebuilt the settings screen to match klyp-gf-to-hubspot: a live connection status
  banner that fills in after the page paints (so a slow HubSpot never delays the
  screen), numbered setup cards, a connected-forms overview, a sidebar listing every
  HubSpot form with copy-to-clipboard IDs, a cache control, and the required scopes.
* Rebuilt the editor panel as four numbered steps (HubSpot form → Field mapping →
  After submission → Deals), each in its own card, with a load-status line and
  "Refresh" link under the form ID, wide labels that no longer wrap, and matched
  control widths so mapped pairs read as pairs.
* Pipeline and deal stage are now drop-downs populated from HubSpot (stages grouped
  by pipeline, filtered by the chosen pipeline). They fall back to free-text IDs when
  the token lacks `crm.objects.deals.read`. A stored ID that no longer exists in
  HubSpot is kept as "(no longer available)" rather than silently dropped on save.
* Deal creation sits behind a positive "Create a HubSpot deal for each submission"
  toggle, replacing the double-negative "Never create deals" checkbox. Stored data is
  unchanged (`'true'` still means never), so existing forms behave as before. A form
  that never configured deals starts with the section collapsed.
* "Match fields by name" adds a mapping row for every unmapped Contact Form 7 field
  whose name matches a HubSpot field (`your-email` → `email`, `tel` → `phone`, and so
  on). Rows are highlighted for review; nothing is saved until the form is.
* Repeater row templates are hidden `<tbody>` elements with disabled controls, so
  they survive the `wp_kses()` pass Contact Form 7 applies to the editor (which
  strips `<template>`) while never being submitted — the 1.x hidden `<tfoot>`
  template DID submit, appending a blank row on every save. Also removes the
  duplicate element IDs.
* Dropped the jQuery dependency from both admin scripts.

= 1.0.11 - 2024.10.14 =
Moved the method parameter location on remotePost to be last
Modified all invocations of the function to reflect the new order

= 1.0.10 - 2023.10.17 =
Updated the way we push to hubspot
Check if the submission is spam first
Added custom error message from hubspot

= 1.0.9 - 2022.09.28 =
Added Private api key option
Updated url of forms to be private api key compatible

= 1.0.8 - 2021.08.19 =
Validations mapping

= 1.0.7 - 2021.03.24 =
Added dynamic fields

= 1.0.6 - 2021.03.16 =
Fixed error capture on hubspot

= 1.0.5 - 2021.03.02 =
Allow not creating deal

= 1.0.4 - 2021.02.25 =
Fixed posted array

= 1.0.3 - 2021.02.12 =
Fixed settings form

= 1.0.2 - 2021.01.04 =
Fixed settings form

= 1.0.0 - 2020.12.08 =
Initial release
