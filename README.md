# IMAP Multi-Domain Login

Nextcloud app that authenticates users against the IMAP server of their email domain. Each email domain can use its own IMAP server, login format, groups and quota. Users are created on their first successful login.

## Requirements

- Nextcloud 32 to 35
- PHP 8.2 or newer with the curl and mbstring extensions

No other app is needed. Users are stored in the app's own table `imap_domain_users`; the user management shows `OCA\UserImapDomains\Backend\ImapUserBackend` as their backend.

## Installation

Copy the app to `apps/user_imap_domains` (or a custom apps directory) and enable it:

```sh
occ app:enable user_imap_domains
```

Release archives contain the built frontend in `js/`. When installing from the source repository, build it first (Node.js 24):

```sh
npm ci
npm run build
```

## How login works

Every domain mapping connects an **email domain** (what users type after the @) with an **IMAP server**. For a login `max.mustermann@example.org` the app:

1. finds the mapping of `example.org`,
2. logs in to its IMAP server as `<IMAP login prefix>max.mustermann@<IMAP login domain>` (the IMAP login domain defaults to the email domain),
3. returns the Nextcloud user ID and, on the first login, creates the user.

| Setting | Nextcloud user ID | Login without @domain |
|---|---|---|
| *User IDs without domain* off (default for new domains) | same as the IMAP login, e.g. `tu-max.mustermann@imap.example.org` | no |
| *User IDs without domain* on (at most one domain) | prefix + local part, e.g. `tu-max.mustermann` | yes, `max.mustermann` |

Users can also log in with their Nextcloud user ID or their email address in Nextcloud.

Each user is stored with the email domain and local part it was created from. If you later change the prefix, the IMAP login domain or *User IDs without domain* of a domain, existing users keep their account and user ID; only the IMAP login uses the new settings.

On the first login a user is added to the groups of the domain plus the global groups. Missing groups are created. The user also gets:

- **Display name:** taken from the local part, e.g. `max.mustermann` → `Max Mustermann`.
- **Email address:** the IMAP login.
- **Quota:** the domain quota, or the default quota if the domain has none.

Later changes of these settings do not affect existing users.

### Security notes

- A login is refused if the resulting user ID already belongs to another user backend (e.g. a local `admin` account), so owning a mailbox `admin@…` does not give access to that account.
- Only the characters `a-z 0-9 _ . ' -` are accepted in the local part.
- Encryption *None* sends passwords in plain text; the setup checks warn about it.
- Wrong passwords are logged with level *info*, connection problems with level *error*. Brute force protection is done by Nextcloud.
- If the user ID for a login is already used by an account created from another domain or local part, the login is refused.

## Configuration

Configure everything in *Administration settings → IMAP Multi-Domain Login*, or with `occ` for automated deployments (e.g. Ansible). All values are lazy loaded app config values:

| Key | Type | Content |
|---|---|---|
| `domain_mappings` | JSON string | List of domain mappings, see below |
| `global_groups` | JSON string | List of groups every new user is added to, e.g. `["members"]` |
| `default_quota` | string | Quota for new users, e.g. `2 GB`, `none` for unlimited; empty for the Nextcloud default |
| `legacy_backend_id` | string | Backend identifier used before this app, for reference only |

Fields of a domain mapping:

| Field | Required | Default | Description |
|---|---|---|---|
| `email_domain` | yes | | Domain users type after the @ (lowercase) |
| `imap_server` | yes | | Hostname or IP address of the IMAP server |
| `imap_sslmode` | | `ssl` | `ssl` (IMAPS), `tls` (STARTTLS) or `none` |
| `imap_port` | | 993 for `ssl`, 143 otherwise | IMAP port |
| `imap_user_prefix` | | empty | Put in front of the local part for the IMAP login (lowercase) |
| `imap_user_domain` | | email domain | Domain of the IMAP login if it differs from the email domain |
| `groups` | | `[]` | Groups new users of this domain are added to |
| `quota` | | empty | Quota for new users of this domain, empty for the default quota |
| `strip_domain` | | `false` | User IDs without domain; allowed for at most one domain |

Example:

```sh
occ config:app:set user_imap_domains domain_mappings --type=string --lazy --value='[
  {"email_domain": "example.org", "imap_server": "imap.example.org", "imap_sslmode": "ssl",
   "groups": ["staff"], "strip_domain": true},
  {"email_domain": "students.example.org", "imap_server": "imap.example.org", "imap_sslmode": "tls",
   "imap_port": 143, "imap_user_prefix": "st-", "imap_user_domain": "example.org", "quota": "5 GB"}
]'
occ config:app:set user_imap_domains global_groups --type=string --lazy --value='["members"]'
occ config:app:set user_imap_domains default_quota --type=string --lazy --value='2 GB'
```

Always pass `--lazy`: with `--no-interaction` and without `--lazy`, `occ` silently switches the value to not lazy. The app still works then, but the value is loaded on every request.

Values set with `occ` are not validated. Check them by opening the settings page and saving once.

## Import from user_external

Users created by the app *External user authentication* (`user_external`), e.g. via a `user_backends` entry in `config.php` (`'arguments' => ['127.0.0.1', 993, 'ssl']`), are stored in its table `users_external` under a backend identifier, usually the first argument. The import moves them to this app:

- User IDs are kept exactly as they are; no domain is added.
- Their rows in `users_external` are removed, so the old backend no longer claims them and the user management shows this app as their backend.
- Users that already exist in this app keep their data here; only their old row is removed.

Steps:

1. Configure the domain mappings. User IDs without domain (e.g. `max` instead of `max@example.com`) need one domain with *User IDs without domain* enabled. The check warns if it is missing, and the setup checks keep warning as long as imported users without domain cannot log in.
2. Check the import in the settings page or with `occ user_imap_domains:migrate-backend 127.0.0.1 --dry-run`. It shows:
   - user IDs that match no domain mapping; these users would get a new, empty account on their next login,
   - users that already exist in this app,
   - whether `config.php` still contains `user_backends`.
3. Remove the `user_backends` entry from `config.php`, otherwise the old backend keeps authenticating users: `occ config:system:delete user_backends`.
4. Move the users: `occ user_imap_domains:migrate-backend 127.0.0.1`.
5. Optional: disable and remove `user_external`.

Exit codes of the command: `0` success, `1` invalid input or no `users_external` table, `2` (only with `--dry-run`) warnings that need attention first.

## Development

- `lib/`: PHP code. `Backend/ImapUserBackend.php` is the user backend, `Db/UserStore.php` its storage, `Model/DomainMapping.php` normalizes all mapping data.
- `src/`: admin settings in Vue 3 with `@nextcloud/vue`, built to `js/` with `npm run build` (or `npm run watch`). The build output in `js/` is not tracked in git.

## License

MIT, see [LICENSE](LICENSE).
