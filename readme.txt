=== Tamar ===
Contributors: thebleedingdeacons
Tags: call-forwarding, telephony, pbx, beacon, tamar-telecommunications
Requires at least: 6.1
Tested up to: 6.9
Stable tag: 1.2.16
Build date: 2026/07/31 15:59:34
Requires PHP: 8.1
License: MIT (Modified — No Resale)

Beacon driver for Tamar Telecommunications' control panel. Reads and writes the hunt-group editor at /phonedivert/huntgroup.

== Description ==

Tamar is the implementation half of the Beacon call-forwarding stack. It targets one specific upstream — **Tamar Telecommunications' control panel** (`www.tamartelecommunications.co.uk/phonedivert/...`) — and implements Beacon's `CallForwardingService` contract by reading and writing the hunt-group editor.

**How the integration works:**

The upstream is a session-cookie-authenticated HTML admin. There is no public API, so the driver shapes its calls the same way a human admin would point a browser at it:

1. **Login.** POST the credentials to `/customer-login/` (form id `login`). Success/failure is signalled in the post-login URL — `?logged_in=1` on success, `?notify=failedlogin` on a rejected credential — not by the HTTP status, so the driver inspects the redirect rather than the status code. The transport retains the session cookie for subsequent calls.
2. **Read.** GET `/phonedivert/huntgroup?huntgroup=<id>`. Parse out the top-level rota metadata (name, announcement, voicemail, hunting strategy), every `<tr class="huntdest">` rota row, and the available voicemail boxes.
3. **Mutate.** Apply the operator's edit to the parsed state in memory.
4. **Save.** POST the whole rota back, form-urlencoded, to `/phonedivert/huntgroup/update`. The upstream replaces the entire rota with the submitted body, so the driver re-encodes every row, not just the changed one.

There is no separate apply step — POSTing the update commits immediately. Beacon's `commit()` is therefore a no-op success on this driver. There is also no CSRF token on this form; auth is the session cookie alone.

== Installation ==

1. Install and activate the **Beacon** plugin first — Tamar depends on its contracts.
2. Upload the `tamar` directory to `/wp-content/plugins/`.
3. Activate Tamar through the **Plugins** menu in WordPress.
4. Configure your Tamar Telecommunications credentials under **Settings → Tamar**.

== Frequently Asked Questions ==

= What happens if Beacon is not installed? =

Tamar declares `Requires Plugins: beacon` in its plugin header, so WordPress will prevent activation. If somehow loaded without Beacon, an admin notice will surface and the driver will not bind.

= How do I disable Tamar without deactivating it? =

Define `TAMAR_KILL` as `true` in `wp-config.php`. Tamar short-circuits before binding its driver, so Beacon falls back to its "no driver" notice.

== Changelog ==

= 1.0.0 =
* Initial release.
