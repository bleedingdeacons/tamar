=== Tamar ===
Contributors: thebleedingdeacons
Tags: call-forwarding, telephony, pbx, beacon, tamar-telecommunications
Requires at least: 6.1
Tested up to: 7.1.1
Stable tag: 3.3.0
Build date: 2026/09/23 03:40:53
Requires PHP: 8.4
License: MIT (Modified — No Resale)

Call forwarding for Tamar Telecommunications' control panel. Reads and writes the hunt-group editor at /phonedivert/huntgroup.

== Description ==

Tamar is the call-forwarding plugin, built on the Beacon library it bundles. It targets one specific upstream — **Tamar Telecommunications' control panel** (`www.tamartelecommunications.co.uk/phonedivert/...`) — and implements Beacon's `CallForwardingService` contract by reading and writing the hunt-group editor.

**How the integration works:**

The upstream is a session-cookie-authenticated HTML admin. There is no public API, so the driver shapes its calls the same way a human admin would point a browser at it:

1. **Login.** POST the credentials to `/customer-login/` (form id `login`). Success/failure is signalled in the post-login URL — `?logged_in=1` on success, `?notify=failedlogin` on a rejected credential — not by the HTTP status, so the driver inspects the redirect rather than the status code. The transport retains the session cookie for subsequent calls.
2. **Read.** GET `/phonedivert/huntgroup?huntgroup=<id>`. Parse out the top-level rota metadata (name, announcement, voicemail, hunting strategy), every `<tr class="huntdest">` rota row, and the available voicemail boxes.
3. **Mutate.** Apply the operator's edit to the parsed state in memory.
4. **Save.** POST the whole rota back, form-urlencoded, to `/phonedivert/huntgroup/update`. The upstream replaces the entire rota with the submitted body, so the driver re-encodes every row, not just the changed one.

There is no separate apply step — POSTing the update commits immediately. Beacon's `commit()` is therefore a no-op success on this driver. There is also no CSRF token on this form; auth is the session cookie alone.

== Installation ==

1. Upload the `tamar` directory to `/wp-content/plugins/`.
2. Activate Tamar through the **Plugins** menu in WordPress. This creates the Forwarding Operator, Dispatcher and Viewer roles.
3. Configure your Tamar Telecommunications credentials under **Settings → Tamar**.

== Frequently Asked Questions ==

= Do I still need the Beacon plugin? =

No. Beacon is now a library bundled inside Tamar. If the old Beacon plugin is still installed, deactivate and delete it; Tamar puts back the forwarding roles that Beacon's deactivation removes.

= How do I disable Tamar without deactivating it? =

Define `TAMAR_KILL` as `true` in `wp-config.php`. Tamar short-circuits before binding its driver, so Trusted sees no forwarding driver.

== Changelog ==

= 1.0.0 =
* Initial release.
