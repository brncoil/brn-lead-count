# BRN Lead Count

WordPress plugin that counts and logs lead events: phone clicks, WhatsApp clicks, and form submissions.

## Features

- Tracks phone (`tel:`), WhatsApp, and form-submit events on any page.
- Admin dashboard with totals and a detailed event log.
- Configurable log size and enable/disable toggle.
- **Automatic updates from GitHub Releases** — checked once per day.
- Manual "Check for updates now" button in the plugin settings page.

## Installation

1. Download the latest ZIP from the [Releases](https://github.com/brncoil/brn-lead-count/releases) page.
2. In WordPress admin go to **Plugins → Add New → Upload Plugin** and upload the ZIP.
3. Activate the plugin.

Or clone this repository into `wp-content/plugins/brn-lead-count/` and activate from the Plugins screen.

## Releasing a new version

1. Update the `Version:` header in `brn-lead-count.php`.
2. Commit and push.
3. Create a **GitHub Release** tagged `vX.Y.Z` (e.g. `v1.0.1`).
4. Attach an installable ZIP as a release asset:
   - The ZIP **must** contain a single top-level folder named `brn-lead-count/`.
   - Inside that folder `brn-lead-count.php` must be present at the root.
   - Example structure inside the ZIP:
     ```
     brn-lead-count/
       brn-lead-count.php
       assets/
       includes/
       index.php
     ```
5. Sites running the plugin will detect the update within 24 hours and auto-install it.

## Auto-update mechanism

The plugin hooks into WordPress's native plugin-update system (`pre_set_site_transient_update_plugins` and `plugins_api`) so updates appear on the standard Plugins screen. The `auto_update_plugin` filter is used to allow WordPress core to install updates automatically with no user interaction required.

## Requirements

- WordPress 5.6+
- PHP 7.4+

## License

GPL-2.0-or-later
