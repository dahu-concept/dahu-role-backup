# Dahu Role Backup

A safety net for WordPress roles and capabilities.

Developed and maintained by [Hugo Vial-Jaime — Dahu-Concept](https://dahu-concept.fr).

## The problem

WordPress keeps every role and capability in a single option. One plugin writing
to it carelessly, one migration, one badly timed update, and a role half the site
depends on is gone — with nothing to put back, because nobody ever thought to
copy it.

This plugin copies it. Every day, and again whenever the option changes.

## How it works

- A snapshot is taken **daily**, and again **whenever the roles option is
  written**, so a change is captured as it happens.
- Nothing is written when the roles are identical to the last snapshot.
- A snapshot that follows a **disappearance** is marked and shown first.
- Restoring is **additive by default** — only the missing roles are put back.
  A full restore is a separate, explicit action.
- A snapshot from another installation is **refused**, matched on table prefix
  and option name.

### Retention that does not defeat itself

Snapshots are capped — thirty daily, thirty on change — but the best recent one
is kept on top of that quota. Without the exception, a run of repeated
disappearances would rotate the retention until no copy held the lost roles any
more: the safety net would quietly empty itself at exactly the moment it was
needed.

### The role that disappears without anything going wrong

The usual way roles vanish is not a bug anybody can see. WordPress keeps every
role in one option, and adding a single capability rewrites the whole of it from
the copy the current request loaded when it started. So a request that began
before another one created a role will, the moment it adds any capability,
write its own older copy back over the top — and the new role is gone. Nothing
failed. Nothing was logged.

This plugin watches for exactly that, by reading the stored row directly instead
of trusting the copy the request is holding, which is the only way to see it.
When a write is about to drop roles that are still in the database, a snapshot
is taken **from the database** first, marked as a rescue, naming the roles that
were about to go.

## Storage

Under `wp-content/dahu-role-backups/`, not in uploads, which is served by the
web server and swept up by synchronisation tools. An `.htaccess` and an
`index.php` are placed there. On a host where `wp-content/` is read-only the
plugin falls back to the uploads directory rather than failing silently.

```php
add_filter( 'dahu_role_backup_directory', function () {
    return '/some/path/outside/the/webroot';
} );
```

## WP-CLI

```
wp dahu-role-backup list
wp dahu-role-backup snapshot
wp dahu-role-backup diff <file>
wp dahu-role-backup restore <file> [--full]
```

## Requirements

| | |
|---|---|
| WordPress | 6.0 or later |
| PHP | 7.4 or later |

## Uninstalling

Snapshots are **kept**. They are the whole point of the plugin, and uninstalling
is often a step in reinstalling — a routine that wiped them would be most
destructive exactly when the site had a role problem to fix. The directory is
left for you to remove knowingly.

## External services

None. Snapshots stay on your own server.

## License

MIT — see [LICENSE](LICENSE).
