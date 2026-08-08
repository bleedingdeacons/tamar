# Tamar — Tamar Telecommunications Driver for Beacon

[![CI](https://github.com/bleedingdeacons/tamar/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/bleedingdeacons/tamar/actions/workflows/ci.yml)
[![Coverage Status](https://coveralls.io/repos/github/bleedingdeacons/tamar/badge.svg?branch=main)](https://coveralls.io/github/bleedingdeacons/tamar?branch=main)
![PHPStan](https://img.shields.io/badge/dynamic/yaml?url=https%3A%2F%2Fraw.githubusercontent.com%2Fbleedingdeacons%2Ftamar%2Fmain%2Fphpstan.neon.dist&query=%24.parameters.level&label=PHPStan&prefix=level%20&color=brightgreen)
![PHPCS](https://img.shields.io/badge/dynamic/xml?url=https%3A%2F%2Fraw.githubusercontent.com%2Fbleedingdeacons%2Ftamar%2Fmain%2F.phpcs.xml.dist&query=%2Fruleset%2Frule%5B1%5D%2F%40ref&label=PHPCS&color=brightgreen)
![Version](https://img.shields.io/badge/version-1.5.7-blue)
![PHP](https://img.shields.io/badge/php-8.1%2B-777bb4)
![Licence](https://img.shields.io/badge/licence-MIT%20(Modified)-green)

Tamar is the implementation half of the Beacon call-forwarding stack. It targets one specific upstream — **Tamar Telecommunications' control panel** (`www.tamartelecommunications.co.uk/phonedivert/...`) — and implements Beacon's `CallForwardingService` contract by reading and writing the hunt-group editor.

## Architecture

```
plugins_loaded ──▶ Beacon ──fires──▶ beacon/loaded ──▶ Tamar
                   (contracts)                        (binds driver)
```

- **Beacon** defines `CallForwardingService` and ships no driver.
- **Tamar** hooks `beacon/loaded` and binds a concrete `HuntgroupCallForwardingService` into Beacon's container. Other code calling `beacon()->get(CallForwardingService::class)` then gets a real, working forwarding service.

Tamar never touches Beacon's namespace internals — it depends on the public interface, the abstract base class, and Beacon's container hook. Swapping Tamar for a different driver (a SIP API client, a vendor SDK wrapper) is just a question of registering a different binding on `beacon/loaded`.

## How the integration works

The upstream is a session-cookie-authenticated HTML admin. There is no public API, so the driver shapes its calls the same way a human admin would point a browser at it:

1. **Login.** POST the credentials to `/customer-login/` (form id `login`). Success/failure is signalled in the post-login URL — `?logged_in=1` on success, `?notify=failedlogin` on a rejected credential — not by the HTTP status, so the driver inspects the redirect rather than the status code. The transport retains the session cookie for subsequent calls.
2. **Read.** GET `/phonedivert/huntgroup?huntgroup=<id>`. Parse out the top-level rota metadata (name, announcement, voicemail, hunting strategy), every `<tr class="huntdest">` rota row, and the available voicemail boxes.
3. **Mutate.** Apply the operator's edit to the parsed state in memory.
4. **Save.** POST the whole rota back, form-urlencoded, to `/phonedivert/huntgroup/update`. The upstream replaces the entire rota with the submitted body, so the driver re-encodes every row, not just the changed one.

There is **no separate apply step** — POSTing the update commits immediately. Beacon's `commit()` is therefore a no-op success on this driver. There is also **no CSRF token** on this form; auth is the session cookie alone.

### Rule mapping

Each `<tr class="huntdest">` becomes one Beacon `ForwardingRule`:

- **Match** is always `time_window`, with the row's checked weekdays in `value.days` (`['sun','mon',...]`), and `value.from` / `value.to` set to the row's start/end time.
- **Target** is synthesised — destinations are free-text on the page, not picked from an enumerable list:
  - `vm:<box-id>` for rows where the voicemail flag is set.
  - `queue:<id>` for queue rows (rare).
  - `num:<digits>` for everything else (digits only, so `01454 898476` and `01454898476` collide to one target).
- **Enabled** mirrors the row's Active checkbox.
- **Priority** is the row's position in the table — the upstream's "Hunt in-order" strategy processes them top-down.

### Rule IDs are positional — read after mutate

The upstream has no stable per-row identifier. The parser uses the row's table ordinal at parse time as the rule's `id`. That means deleting row 3 makes the former row 4 the new row 3, so a sequence like `delete('3')` then `save('4', ...)` will hit the wrong row. **After any mutation, re-fetch before issuing further mutations.** Beacon's higher-level admin UI does this automatically; direct API callers should too.

## Settings

The top-level **Tamar** menu in the WordPress admin (**Tamar → Settings**):

| Field | Notes |
|---|---|
| Base URL | `https://www.tamartelecommunications.co.uk` — no trailing slash. |
| Username / Password | Tamar control-panel login. The password is encrypted at rest with a key derived from `AUTH_KEY`. |
| Hunt-group page path | GET endpoint. Default `/phonedivert/huntgroup`. |
| Login path | Default `/customer-login/`. |
| Update path | POST endpoint that saves the rota. Default `/phonedivert/huntgroup/update`. |
| **Hunt group ID** | The numeric ID from the upstream edit URL — e.g. `157626` from `.../huntgroup?huntgroup=157626`. This is per-client; without it Tamar can't tell which hunt group on the account to edit. |
| Verify TLS certificate | Default on. Disable only for self-signed dev hosts. |
| Timeout | Per-request HTTP timeout in seconds. |

## Hooks

| Hook | Params | When |
|---|---|---|
| `tamar/register_services` | `ContainerInterface` | After Tamar has bound its services. Use to wrap or decorate the driver (caching layer, audit log, etc.). |
| `tamar/loaded` | `ContainerInterface` | After Tamar has finished initialising. |

## Capability requirements

| Capability | Where |
|---|---|
| `beacon_view_forwarding` | Required to see the settings page. |
| `beacon_manage_forwarding` | Required to save settings. |
| `beacon_push_config` | Required to use the "Apply pending changes" button. (Tamar's commit is a no-op, but the capability still gates the button.) |

## File layout

```
tamar/
├── tamar.php                  Bootstrap (hooks beacon/loaded)
├── uninstall.php
├── composer.json
├── src/
│   ├── Plugin.php
│   ├── Admin/
│   │   ├── TamarSettings.php             Reads/writes wp_option, password at rest
│   │   └── SettingsPage.php
│   ├── Core/TamarServiceProvider.php
│   ├── Forwarding/
│   │   ├── HuntgroupPageParser.php          DOM+XPath parse of the editor page
│   │   ├── HuntgroupFormBuilder.php         Re-encodes parsed state as POST body
│   │   └── HuntgroupCallForwardingService.php   Beacon driver
│   ├── Logger/HasLogger.php
│   └── Transport/WpHttpTransport.php      WP HTTP API w/ session cookies
└── tests/
    ├── Fixtures/huntgroup_157626.html    Trimmed real-page sample
    └── Unit/
```

## Updating the fixture

The parser and builder tests run against `tests/Fixtures/huntgroup_157626.html`. If Tamar changes their HTML, regenerate the fixture from a fresh page response — don't hand-edit. Hand-edits drift from reality and tests pass against a page that no longer matches production.

## Requirements

- WordPress 6.1+
- PHP 8.1+
- Beacon plugin (active)
- OpenSSL extension (for password-at-rest; falls back to base64 with a warning if missing)

## Testing

Install the dev dependencies and run the suite from the plugin directory:

```bash
composer install
```

| Command | Description |
|---|---|
| `composer test` | Run the full PHPUnit test suite |
| `composer test:unit` | Run unit tests only |
| `composer test:integration` | Run integration tests only |
| `composer test:coverage` | Generate an HTML coverage report |
| `composer phpstan` | Run PHPStan static analysis |
| `composer cs` | Check coding standards |
| `composer cs:fix` | Auto-fix coding standard violations |
| `composer check` | Run CS + PHPStan + tests in sequence |

Line coverage is reported to [Coveralls](https://coveralls.io/github/bleedingdeacons/tamar?branch=main)
on every CI run — see the coverage badge at the top of this file.

---

## License

GPL-2.0+
