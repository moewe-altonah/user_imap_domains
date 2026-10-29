# Changelog

## 1.0.0

First release.

- User backend that authenticates users against the IMAP server of their email domain; no other app needed
- Domain mappings with IMAP server, encryption, login prefix, login domain, groups, quota and optional user IDs without domain
- Users are created on their first login with display name, email address (the IMAP login), groups and quota
- Existing users keep their account and user ID when the settings of their domain change
- Logins whose user ID belongs to another account or backend are refused
- Admin settings page with login preview, validation and setup checks
- Import of users from `user_external` (`occ user_imap_domains:migrate-backend`), keeping their user IDs
- Configuration via `occ config:app:set` for automated deployments
