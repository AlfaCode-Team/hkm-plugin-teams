# hkm-plugin-teams

Teams, memberships with roles, invites (accept / decline), per-team preferences
and roster privacy.

Solves `team.management`. Requires `database.management` and
`authorization.policy`.

```php
$teams = $container->make(TeamServiceContract::class);

$team  = $teams->create('Platform');                       // the caller becomes its owner
$sent  = $teams->invite($team->id, 'jane@example.com', ['admin']);
//   $sent->token  -> put it in the email you send; it is NOT recoverable afterwards

$teams->acceptInvite($sent->invite->id, $token);           // as Jane; works once
$teams->changeRoles($team->id, $janeId, ['member']);
$teams->removeMember($team->id, $janeId);                  // or leave: pass your own id
```

---

## The one thing to understand first

**This plugin has no permission system.** There is no `can()`, no role-to-action
map and no permission table here. A member's team roles are handed to
`hkm-plugin-authorization` as `team:<role>`, and *that* plugin's policy answers
"may this role do this".

Changing what an admin may do is a **policy edit**, not a code change, and the
rows that say so ship in [`config/policy.teams.csv`](config/policy.teams.csv).

Two things are not permissions and so live in code: a team never loses its last
owner, and an invite is single-use.

---

## How a team role reaches an authorization decision

There are two paths, and they end in the same place: the Authorization plugin's
`SubjectAuthorizationContract::allows(Subject, 'team', $action)`.

### 1. Inside this plugin's own methods

`Application/Services/TeamService.php`

```
authorize($teamId, $action)
  └─ membership($teamId)        reads the caller's seat from team_memberships (live)
  └─ mayDo($seat, $action)      builds  new Subject($userId, ['team:admin', ...], $tenantId)
       └─ SubjectAuthorizationContract::allows($subject, 'team', $action)
```

`mayDo()` is the only place a decision is requested. The roles are read from the
membership table on every call, so removing a member or demoting them applies on
their very next request.

### 2. From a `can:` route filter on your own routes

`API/TeamSubjectResolver.php`, method `resolve()`.

Authorization's `can:` filter (`PolicyFilterStage`) asks **one**
project-bound `SubjectResolverContract` who the caller is and which roles they
hold *here*. A plugin cannot bind that for the project, so this class is given to
you to **compose** into the resolver you already have:

```php
$container->bind(SubjectResolverContract::class, fn ($c) => new TeamSubjectResolver(
    $c->make(TeamMembershipContract::class),
    $yourExistingStaffResolver,      // optional; its roles are merged in
    'teamId',                        // the route parameter that carries the team id
));
```

```jsonc
{ "method": "PUT", "path": "/teams/{teamId:alphanum}/settings",
  "handler": "App\\Http\\TeamSettings@update",
  "filters": ["auth", "can:team,update"],
  "requires": ["authorization.policy", "team.management"] }
```

On a route carrying `{teamId}` the subject's roles are the caller's roles **in
that team**, read live. A non-member gets an empty role list, which the filter
refuses as `DenialReason::NoRole`. On any other route the resolver defers to the
fallback (or returns `null`, so the filter behaves as if no resolver were bound).

### Why it does not put the role in a session

In one real project no session ever held a plugin's gate (`feedback:manage`), so
every check that read `Identity->permissions` refused every real user. Permissions
copied into a token or session are only as fresh as the token, and nothing in the
sign-in path knows about a team seat that was created after it.

So nothing here reads `Identity->roles` or `Identity->permissions`. A team seat is
looked up in `team_memberships` at the moment of the question — there is no gate
that has to have been put somewhere first.

---

## Policy rows you must load

With **no** rows loaded every guarded action is refused (the failure is closed);
only *create a team* and *leave a team* need none.

```bash
hkm cli authz:seed --file=<path to>/config/policy.teams.csv
# or append the rows to the file AUTHZ_POLICY_FILE points at
```

| Role | Gets (object `team`) |
|---|---|
| `team:member` | `read` |
| `team:admin` | `members.read` `update` `invite` `members.update` `members.remove` (+ member) |
| `team:owner` | `delete` `owners.manage` (+ admin) |

| Action | Guards |
|---|---|
| `read` | see the team, its prefs and (public roster) its members |
| `members.read` | the full roster when privacy is `restricted` |
| `update` | rename, change privacy, replace prefs |
| `delete` | delete the team |
| `invite` | list, create, revoke invites |
| `members.update` / `members.remove` | change / remove **another** member |
| `owners.manage` | anything that grants, removes or changes the `owner` role |

A custom role (`billing`) is accepted and grants **nothing** until you add rows for
`team:billing`. Under the domain-aware Authorization model the subject's domain is
the tenant id; under the default model it is ignored.

---

## Routes

All under `/api`, all behind the `auth` filter. Declared in `module.json`.

| | |
|---|---|
| `GET /teams` | the caller's teams |
| `POST /teams` | `{name, prefs?}` |
| `GET` `PATCH` `DELETE /teams/{teamId}` | `PATCH` takes `{name?, privacy?}` |
| `GET` `PUT /teams/{teamId}/prefs` | `PUT` takes `{prefs: {...}}` and replaces the document |
| `GET /teams/{teamId}/members` | |
| `PATCH` `DELETE /teams/{teamId}/members/{userId}` | `PATCH` takes `{roles: [...]}` |
| `POST /teams/{teamId}/leave` | |
| `GET` `POST /teams/{teamId}/invites` | `POST` takes `{email, roles?}` and returns the token once |
| `DELETE /teams/{teamId}/invites/{inviteId}` | revoke |
| `POST /team-invites/{inviteId}/accept` `.../decline` | `{token}` |

Refusals are the kernel's error envelope. `error.code` is a stable code:

| Status | Codes |
|---|---|
| 401 | `team.unauthenticated` |
| 403 | `team.forbidden`, `team.invite.wrong_account` |
| 404 | `team.not_found`, `team.member.not_found`, `team.invite.invalid` |
| 409 | `team.last_owner`, `team.already_member`, `team.invite.not_pending`, `team.invite.already_pending` |
| 410 | `team.invite.expired` |
| 422 | validation of names, roles, prefs, privacy, addresses |

`error.message` is the sentence from `resources/lang/{en,fr}/teams.php` when the
I18n plugin is installed, and the code itself otherwise. A non-member is told
`team.not_found` — the same answer as an id that never existed.

---

## Invites

* The token is 192 random bits. **Only its SHA-256 is stored**, so a database
  read cannot be turned into a working invite, and it appears in **no event
  payload** (a webhook subscriber would otherwise hold a credential). `invite()`
  returns it once; sending it is your job (this plugin sends no mail).
* **Single use.** `accept` moves `pending → accepted` with a conditional
  `UPDATE ... WHERE status = 'pending'`; of two simultaneous accepts, one matches.
  The unique key `(tenant_id, team_id, user_id)` is a second wall.
* A wrong secret and a missing invite are the **same** `404`, so ids cannot be
  probed. An expired invite is `410`, a spent one `409`.
* The accepting account's `Identity->email` must equal the invited address
  (`TEAMS_INVITE_REQUIRE_EMAIL_MATCH`, default on). That trusts your auth layer to
  put a **verified** address there. Turn it off and the secret alone redeems it.

## Privacy

`privacy: restricted` makes a plain member see only their own membership; the
full roster then needs the `members.read` action. It never hides a membership
from the person it belongs to.

## Tenancy

Every table carries `tenant_id`, every repository statement filters on it, and
every unique key leads with it. The tenant on `Identity` is a **hint**: what makes
an answer right is that the lookup is keyed `(tenant, team, user)` in the store
that owns memberships, so a token claiming a tenant the user holds no seat in
finds nothing. This plugin has no seat store of its own for *tenants*, so it
cannot notice that a user left the tenant — whoever issues the `Identity` owns
that.

## Events (integration, after commit)

`team.created` `team.deleted` `team.member.added` `team.member.removed`
`team.member.roles_changed` `team.invite.created` `team.invite.accepted`
`team.invite.declined` `team.invite.revoked`. Primitives only; payload always has
`teamId`, `tenantId`, `occurredAt`. The plugin subscribes to nothing.

## Published contracts

* `TeamServiceContract` — the operations above.
* `TeamMembershipContract` — live `rolesIn()`, `policyRolesIn()`,
  `membershipsOf()`, `teamExists()`. An **input** to a decision (used by
  `TeamSubjectResolver` and `hkm-plugin-acl`); it decides nothing and takes no
  permission check, so do not expose it to clients.

## Configuration

| Key | Default | |
|---|---|---|
| `TEAMS_INVITE_TTL_SECONDS` | `604800` | invite lifetime (min 60) |
| `TEAMS_MAX_PREFS_BYTES` | `16384` | size of the JSON prefs document |
| `TEAMS_INVITE_REQUIRE_EMAIL_MATCH` | `true` | see Invites |

Migration: `database/migrations/2026_11_11_000001_create_team_tables.php` (LetMigrate, with `down()`).

## Not in this plugin, on purpose

* **A permission table or `can()`** — see the top.
* **Sending the invitation email** — you hold the token; use your mailer.
* **Per-resource permissions** — that is `hkm-plugin-acl`.
* **A user directory.** Members are user ids; names and addresses are not stored.
* **Reacting to a user being deleted.** Remove their seats from the handler that deletes the user.

---

## Verified / NOT verified

**Verified** — by running it, on SQLite (a real migrated database), against the
real Authorization engine reading the bundled policy, and against the real
kernel (Ground boots both plugins and serves the routes):

* Non-member refused (`404 team.not_found`); member with the wrong role refused
  (`403 team.forbidden`) with nothing written; admin/owner boundaries
  (`owners.manage`); last owner cannot leave/be demoted/be removed.
* A team in tenant A is invisible in tenant B; a seat in A grants nothing under a
  forged tenant claim; an invite from another tenant does not exist.
* Removal and demotion apply on the very next call.
* An invite cannot be accepted twice (in-memory check, conditional UPDATE, and
  the unique key, each exercised); wrong secret, wrong account, expired, revoked,
  declined all refused with their own code; the secret is in no row and no event.
* A failed write rolls back, leaves the collector empty and publishes no event.
* The `team:<role>` → Subject → policy path, **through the real `can:` filter**:
  a project route `PUT /team-settings/{teamId}` behind `can:team,update`, with
  `TeamSubjectResolver` bound the way the README above tells a project to. Owner
  and admin pass; a plain member and a stranger get 403; the same user under
  another tenant gets 403; removing the admin through Teams makes the very next
  request 403. The `Identity` in those requests carries no roles and no
  permissions.
* Every error code thrown has an `en` and `fr` sentence.

**NOT verified:**

* **A project's real resolver.** The filter was driven with `TeamSubjectResolver`
  alone (and its merge with a fallback resolver is unit-tested), not with a
  production staff resolver composed in.
* **Authorization without the I18n helpers.** `PolicyFilterStage` calls the I18n
  plugin's global `trans_or()` unguarded when it refuses; with the I18n domain
  loaded but its helpers absent a refusal is a 500, not a 403. The tests define
  a stand-in. (Authorization's own behaviour; not changed here.)
* **MySQL / PostgreSQL / SQL Server.** SQL is portable by construction (named
  params, `upsert()`, no vendor functions) but has only run on SQLite. In
  particular `upsert(..., [])` as insert-if-absent and the `lockTeam()` row lock
  (what serialises two concurrent "remove an owner" calls) were never run on a
  server database; on SQLite writers are serialised anyway, so the **concurrency
  guarantee for the last-owner rule is unproven**.
* **A real browser / FPM / Swoole**, and the `auth` filter (stubbed by Ground).
* Authorization is evaluated when a call starts; only the owner-escalation
  checks are repeated under the team's row lock.
