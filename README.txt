=== Dahu Role Backup ===
Contributors: hugovialjaime
Tags: roles, capabilities, backup, restore, recovery
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.3.1
License: MIT
License URI: https://opensource.org/licenses/MIT

A safety net for WordPress roles and capabilities: timestamped snapshots, a readable diff, and an additive restore.

== Description ==

WordPress keeps every role and capability in a single option. One plugin
writing to it carelessly, one migration, one badly timed update, and a role
that half the site depends on is simply gone — with nothing to put back,
because nobody ever thought to copy it.

Dahu Role Backup copies it. Every day, and again whenever the option changes.
When a role disappears you open the list, compare, and put it back.

= How it works =

* A snapshot is taken **daily**, and again **whenever the roles option is
  written** — so a change is captured as it happens, not the next morning.
* Nothing is written when the roles are identical to the last snapshot, so the
  list stays readable.
* A snapshot that follows a **disappearance** is marked as such and shown
  first: that is the one you will want.
* Restoring is **additive by default**: only the roles missing from the site
  are put back. Nothing existing is overwritten unless you explicitly ask for a
  full restore.
* A snapshot from another installation is **refused**, matched on the table
  prefix and option name, so a file copied between sites cannot wreck the roles
  of the wrong one.

= Retention that does not defeat itself =

Snapshots are capped — thirty daily, thirty on change — but the best recent one
is kept on top of that quota. Without this, a run of repeated disappearances
would rotate the retention until no copy held the lost roles any more: the
safety net would quietly empty itself at exactly the moment it was needed.

= Where snapshots are stored =

Under `wp-content/dahu-role-backups/`, not in the uploads directory, which is
served by the web server and swept up by synchronisation tools. Both an
`.htaccess` and an `index.php` are placed there. On a host where `wp-content/`
is read-only, the plugin falls back to the uploads directory rather than
failing silently, and the location is filterable.

= WP-CLI =

    wp dahu-role-backup list
    wp dahu-role-backup snapshot
    wp dahu-role-backup diff <file>
    wp dahu-role-backup restore <file> [--full]

= External services =

This plugin does not contact any external service. Snapshots stay on your own
server.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install it from the
   Plugins screen.
2. Activate it. A first snapshot is taken immediately — the plugin is useful
   from the second it is switched on, not from tomorrow's cron.
3. The screen lives under Tools > Role snapshots.

== Frequently Asked Questions ==

= Does restoring overwrite my current roles? =

No, not by default. An additive restore only puts back the roles that are
missing. A full restore, which does replace everything, is a separate action
you ask for explicitly.

= Can I restore a snapshot taken on another site? =

No. A snapshot records the table prefix and the option name it came from, and a
restore onto a different installation is refused. Roles mean different things on
different sites.

= What happens to the snapshots if I delete the plugin? =

They are kept. They are the whole point of the plugin, and uninstalling is often
a step in reinstalling. The directory is left in place for you to remove
knowingly; only the plugin's option and its scheduled event are cleaned up.

= Does it slow the site down? =

No. The daily snapshot runs on cron, and the change watcher only acts when the
roles option is actually written, which on a normal site is rare.

== Screenshots ==

1. The list of snapshots.
2. Comparing a snapshot with the current roles.

== Changelog ==

= 2.3.1 =
* The snapshot list now says what will appear there once a snapshot is taken.
* The status line no longer ends on a dash when the roles option cannot be read; it says that it could not be read.

= 2.3.0 =
* The activation screen now opens on the plugin's own mark rather than its initials, and the banner carries the same drawing as a watermark.

= 2.2.0 =
* A restore now refills WordPress's in-memory copy of the roles from the
  database before it writes, and again after a full restore. Every write of
  the roles option rewrites the whole option from that copy, so a copy that
  was out of date did not merely fail to see a role: it erased it — while
  restoring.
* The current roles are read straight from the table rather than through
  get_option(), which on a site with a persistent object cache could answer
  with the stale copy this plugin exists to see past, and freeze a snapshot
  that was already missing a role.

= 2.1.0 =
* Watches for the one way roles disappear without anything failing: a request
  writing back its own older copy of the roles option, which erases a role
  another request created in the meantime. The stored row is read directly,
  because the copy the request holds cannot show it.
* A snapshot taken for that reason is marked as a rescue and names the roles
  that were about to be lost.
* No cost on the frequent case: a write that changes nothing performs no extra
  query.

= 2.0.0 =
* First public release, extracted from a larger plugin.
* Snapshot directory is now filterable, and falls back to the uploads directory
  when wp-content is read-only.
* Own screen under Tools, instead of a tab in another plugin's settings.

== Upgrade Notice ==

= 2.1.0 =
Catches roles erased by a stale copy of the roles option, which nothing else
reports.

= 2.0.0 =
First public release.
