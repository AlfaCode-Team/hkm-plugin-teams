# Changelog

All notable changes to `hkm-plugin-teams` are documented here. The format is based
on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **First release: teams, memberships with roles, invites, per-team preferences and
  roster privacy.** Solves `team.management`. Requires `database.management` and
  `authorization.policy`.

- **No permission system of its own.** A member's team roles reach
  `hkm-plugin-authorization` as `team:<role>` and its policy decides, both inside
  the service (`TeamService::mayDo()`) and, for a project's own routes, through
  `API/TeamSubjectResolver`, which composes into the project's single
  `SubjectResolverContract` for the `can:` filter. The roles are read from the
  membership table on every call, never from `Identity->roles` or a session.
  `config/policy.teams.csv` ships the rows (member / admin / owner with
  inheritance) and is loaded with `authz:seed`.

- **Published contracts:** `TeamServiceContract` and `TeamMembershipContract`
  (live `rolesIn`, `policyRolesIn`, `membershipsOf`, `teamExists`) — the input
  `hkm-plugin-acl` builds team and team-role grants from.

- **Single-use invites.** Only the SHA-256 of the 192-bit token is stored and the
  token is in no event payload. Accepting is a conditional
  `UPDATE ... WHERE status = 'pending'` plus a unique `(tenant, team, user)` key.
  A wrong secret and a missing invite are the same 404; expired is 410; spent is
  409. The accepting account's email must match the invited one
  (`TEAMS_INVITE_REQUIRE_EMAIL_MATCH`).

- **Rules that are not permissions:** a team never loses its last owner; anything
  involving the `owner` role needs the policy action `owners.manage`; a non-member
  gets `404 team.not_found`, indistinguishable from a team that never existed.

- **Tenant scoping on every statement** (`tenant_id` in every table and every
  unique key). Soft delete for teams; memberships are hard-deleted so a removed
  member stops being one in the same statement.

- **Nine integration events** after commit, primitives only. `en` and `fr` sentences
  for every error code.

- Routes under `/api` (declared in `module.json`), LetMigrate migration with `down()`.
