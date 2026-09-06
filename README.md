## Klyp Contact Form 7 to HubSpot

Maps Contact Form 7 fields onto the fields of a HubSpot form, submits them through the
HubSpot Forms API, and optionally creates a deal associated with the matching contact.

### Requirements

| | |
|---|---|
| PHP | 8.0+ (tested on 8.5) |
| WordPress | 6.7+ |
| [Contact Form 7](https://en-au.wordpress.org/plugins/contact-form-7/) | 5.8+ (tested on 6.1) |
| HubSpot auth | Private app token |

### Setup

1. **Create a HubSpot private app** — Settings → Integrations → Private Apps. HubSpot
   retired API keys on 30 November 2022, so a private app token is the only option.
2. **Grant these scopes:**
   - `forms` — read HubSpot form definitions so fields can be mapped
   - `crm.objects.contacts.read` — find the contact behind a submission *(deals only)*
   - `crm.objects.deals.write` — create and update deals *(deals only)*
   - `crm.objects.deals.read` — *optional*; turns pipeline and stage into drop-downs

   Only `forms` is needed if you are not creating deals.
3. **Save the token and portal ID** under Settings → CF7 to HubSpot. The status
   banner tests the credentials automatically once the page loads.
4. **Connect a form** — copy a form ID from the *Your HubSpot forms* sidebar, edit a
   Contact Form 7 form, open its *HubSpot Integration* tab, paste the ID, save, then
   map the fields.

#### Keeping the token out of the database

Define it in `wp-config.php` and the settings screen becomes read-only for that field:

```php
define( 'KLYP_CF7_HUBSPOT_TOKEN', 'pat-xx1-...' );
```

### Architecture

```
klyp-cf7-to-hubspot.php        Bootstrap: requirement checks, file loading
inc/class-hubspot-client.php   HubSpot REST client (Forms v3, CRM v3), caching
inc/class-form-settings.php    Per-form post meta: read, sanitise, write
inc/class-settings-page.php    Settings → CF7 to HubSpot
inc/class-form-editor.php      "HubSpot Integration" panel in the CF7 editor
inc/class-submission-handler.php  Runtime: submission → HubSpot → deal → redirect
inc/upgrade.php                One-time data migrations
uninstall.php                  Option cleanup on delete
```

Everything lives under the `Klyp\CF7ToHubspot` namespace.

### HubSpot endpoints used

| Purpose | Endpoint |
|---|---|
| List forms / verify credentials | `GET marketing/v3/forms/?limit=100` |
| Read form definition | `GET marketing/v3/forms/{formId}` |
| Submit a form | `POST api.hsforms.com/submissions/v3/integration/submit/{portalId}/{formId}` (unauthenticated) |
| Find contact by email | `GET crm/v3/objects/contacts/{email}?idProperty=email` |
| List deal pipelines & stages | `GET crm/v3/pipelines/deals` (optional, for the pickers) |
| Create deal | `POST crm/v3/objects/deals` |
| Update deal | `PATCH crm/v3/objects/deals/{dealId}` |

Listing forms doubles as the connection test: it exercises the `forms` scope the
plugin cannot work without, and returns the list the settings sidebar shows.
Deal creation runs from a scheduled event about two minutes after the submission,
not inline: HubSpot creates the contact from a Forms submission asynchronously, so
an immediate lookup found nothing for every first-time visitor. Deferring it also
keeps three HTTP calls out of the visitor's request.


### Contact Form 7 integration points

| Hook | Use |
|---|---|
| `wpcf7_editor_panels` | Adds the HubSpot Integration tab |
| `wpcf7_save_contact_form` | Captures the submitted panel values |
| `wpcf7_after_save` | Writes them once the form has a real post ID |
| `wpcf7_before_send_mail` | Sends the submission to HubSpot; can abort |
| `wpcf7_feedback_response` | Adds the redirect target to the AJAX response |
| `wpcf7_enqueue_scripts` | Loads the redirect listener only where a form renders |

### Local checks

```bash
composer lint          # php -l across every file
```

### Changes

See [readme.txt](readme.txt) for the full changelog.

#### v2.0.0 — 2026.09.04
Rewrite for Contact Form 7 6.x and PHP 8.5. Fixes a PHP 8 fatal on unmapped forms,
removes the retired HubSpot API key auth mode, migrates to the CRM v3 API, replaces
the deprecated `wpcf7_ajax_json_echo` filter, escapes the editor panel output, and
rebuilds the settings screen. **Breaking:** requires PHP 8.0+, WordPress 6.7+ and
Contact Form 7 5.8+; the `klypHubspot` class and `klyp*` global functions are gone.
