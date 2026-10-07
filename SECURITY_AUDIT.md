# Security Audit & Remediation Tracker

> **Repository:** `Meysam-sadeghi/Proxmox-VE-for-WHMCS`  
> **Audit date:** 2026-10-07  
> **Original audit baseline:** `7ff41ccecde7e1d846860e3b24208129ee8fdd42`  
> **Security-hardened source baseline:** `ea807a8f873d82bf225f11d0971bef6895be6a9d`  
> **Module version:** 1.3.5  
> **Companion architecture map:** `SYSTEM_MAP.md`

## Purpose

This file is the authoritative security-review handoff for future remediation work.

**AI / maintainer instruction:** Read `SYSTEM_MAP.md` and this file first. Do not repeat the original full-repository audit unless source code has materially changed. The original audit baseline is `7ff41ccecde7e1d846860e3b24208129ee8fdd42`; all tracked source-level remediations SEC-001 through SEC-016 were merged in PR #2, and SEC-001 was further hardened fail-closed in PR #3 at `ea807a8f873d82bf225f11d0971bef6895be6a9d`. For future work, compare the current HEAD against `ea807a8f873d82bf225f11d0971bef6895be6a9d` and inspect only changed source files plus their direct callers. Documentation-only commits after that merge do not invalidate this security baseline.

When fixing an item:

1. Change its status from `OPEN` to `IN PROGRESS`.
2. Apply the smallest safe fix.
3. Add or run relevant tests.
4. Record the commit SHA and verification notes under the finding.
5. Set status to `FIXED - NEEDS VERIFICATION`.
6. After testing against WHMCS + Proxmox, set status to `VERIFIED`.
7. Do not mark a finding verified only because the code compiles.

### Status values

- `OPEN`
- `IN PROGRESS`
- `FIXED - NEEDS VERIFICATION`
- `VERIFIED`
- `ACCEPTED RISK`
- `NOT APPLICABLE`

### Severity meaning

- **CRITICAL:** compromise can plausibly cross the WHMCS↔Proxmox trust boundary or expose highly privileged credentials.
- **HIGH:** can expose credentials/tickets, enable meaningful unauthorized actions, or materially widen compromise.
- **MEDIUM:** exploitable security weakness with meaningful but more constrained impact.
- **LOW:** hardening/correctness issue with limited direct exploitability.

---

# Executive summary

No evidence of an intentionally inserted backdoor, web shell, hidden command execution, obfuscated remote payload, or deliberate credential-exfiltration endpoint was found in the reviewed source.

At the reviewed code baseline, the fork was byte-for-byte at the same Git commit/tree as upstream `The-Network-Crew/Proxmox-VE-for-WHMCS`. The bundled noVNC directory was independently compared with official noVNC v1.7.0: **208/208 vendored files were hash-identical; 0 were modified**.

The original audit identified material upstream design/implementation risks. The tracked source-level remediations for SEC-001 through SEC-016 have now been implemented on the security-hardening workstream: verified TLS is the default, root-backed PVE credentials are prohibited in favor of scoped API tokens, sensitive logging is removed, noVNC authorization is server-side/nonce-bound, addon mutations are POST+CSRF protected, dynamic output is escaped, the VNC secret is WHMCS-encrypted, CIDR imports are bounded, the applicable noVNC ZRLE issue is locally patched, legacy crypto is removed, and external version checks are bounded.

Items remain **FIXED - NEEDS VERIFICATION** rather than VERIFIED until the relevant acceptance tests run against a real WHMCS + Proxmox VE 9 staging environment.

Static source review cannot prove that a deployed WHMCS or Proxmox host has never been compromised. Runtime incident-response checks are separate from this repository audit.

---

# Historical remediation order

The findings were remediated in this priority order:

1. **SEC-001 — TLS verification**
2. **SEC-002 — privileged PVE API identity**
3. **SEC-003 — sensitive module logging**
4. **SEC-004 — noVNC tickets in URLs**
5. **SEC-005 — noVNC router authorization/session binding**
6. **SEC-006 — console cookie scope**
7. **SEC-007 — CSRF/state-changing GET operations**
8. **SEC-008 — admin/client output escaping**
9. **SEC-009 — plaintext VNC secret**
10. **SEC-010 — IPv4 CIDR resource exhaustion**
11. **SEC-011 — bundled noVNC ZRLE CPU DoS**
12. **SEC-012 — noVNC destination control / allowlisting**
13. Then LOW-severity cleanup items.

A client Reinstall/Rebuild action was added after the original audit. Its ownership, native WHMCS CSRF validation, one-time nonce, confirmation, template allowlisting, concurrency lock and rollback controls are documented below. The surrounding module findings SEC-001 through SEC-016 now have source-level remediations; live WHMCS + Proxmox VE 9 acceptance testing is still required before changing their status from `FIXED - NEEDS VERIFICATION` to `VERIFIED`.

---

## SEC-001 — Proxmox TLS certificate verification disabled

**Severity:** CRITICAL  
**Status:** FIXED - NEEDS VERIFICATION  
**Category:** CWE-295 Improper Certificate Validation  
**Primary file:** `modules/addons/pvewhmcs/proxmox.php`  
**Relevant code:** constructor around lines 43-66; login around 94-101; generic action around 243-249.

### Evidence

- The original code defaulted `verify_ssl` to false and the generic API request path disabled certificate verification.
- The hardened client now defaults verification to true, uses peer verification plus hostname verification mode `2`, and rejects any explicit attempt to pass `verify_ssl=false`.
- All module callers prefer the configured certificate-valid PVE hostname over a raw IP where available.

### Security impact

A man-in-the-middle on the WHMCS↔PVE path can potentially impersonate the PVE endpoint. Because the module authenticates with powerful PVE credentials and then transports PVE session tickets, a successful MITM has high impact.

### Required remediation

- Default certificate verification to enabled.
- Use `CURLOPT_SSL_VERIFYPEER = true`.
- Use `CURLOPT_SSL_VERIFYHOST = 2`.
- Honor one centrally configured TLS verification setting for both login and subsequent requests.
- If self-signed certificates must be supported, use a configured CA bundle/pinning approach rather than a silent global disable.
- Add connection timeouts and explicit TLS error handling.

### Acceptance criteria

- Valid trusted PVE certificate succeeds.
- Wrong hostname fails.
- Expired/untrusted certificate fails unless a deliberately configured trusted CA is supplied.
- Login and subsequent GET/POST/PUT/DELETE calls use identical verification policy.
- No production default disables verification.

### Remediation applied

- PVE TLS verification now defaults to enabled and explicit attempts to pass `verify_ssl=false` are rejected fail-closed.
- Login and subsequent API calls use peer verification and hostname verification mode 2.
- WHMCS server hostname is preferred over raw IP for certificate validation in provisioning, addon and reinstall paths.
- Existing transport timeouts remain enabled.

### Fix commit / verification

- Commits: `a44b3a8b0d74736bd35130bf2d1b10de79c7ea0e`, `fea0f79cb0f2bc45895025f836190f2fe525c8f3`, `cf820f2f9fd039340fc68fa1743241474f77106d`, `653a43a8883cf49526a368774e82c70df57fd037`, merge follow-up `ea807a8f873d82bf225f11d0971bef6895be6a9d`
- Verification: static code review complete; PHP Syntax Check and Security Regression Checks pass, including a behavioral test proving `verify_ssl=false` is rejected. Test valid, wrong-hostname and untrusted certificates against the deployment before marking VERIFIED.

## SEC-002 — Normal API design uses root-equivalent Proxmox credentials

**Severity:** CRITICAL (architecture / blast-radius risk)  
**Status:** FIXED - NEEDS VERIFICATION  
**Category:** CWE-250 Execution with Unnecessary Privileges  
**Primary files:** `README.md`, `modules/servers/pvewhmcs/pvewhmcs.php`

### Evidence

The README instructs operators to configure a root PVE account in WHMCS for normal module operations. Runtime code authenticates using the server username/password supplied by WHMCS.

### Security impact

Compromise of WHMCS, the database encryption boundary, logging, or the API channel can become compromise of the entire Proxmox environment rather than a limited provisioning account.

### Required remediation

- Create a dedicated PVE service identity or API token.
- Define the minimum PVE privileges required for create/clone/config/start/stop/delete/read operations.
- Scope permissions to required pools/storage/nodes whenever possible.
- Keep the separate restricted console identity.
- Update README and setup validation so root is not the recommended/default credential.

### Acceptance criteria

- Provisioning lifecycle works without `root@pam`.
- The API identity cannot modify unrelated datacenter-level security/configuration.
- Compromise of the module credential has a demonstrably reduced blast radius.

### Remediation applied

- The API client supports Proxmox API tokens via WHMCS Server Username `user@realm!tokenid` and Password = token secret.
- API-token requests use the PVE `Authorization: PVEAPIToken=...` mechanism and do not create login tickets or CSRF tokens.
- Provisioning/management authentication now **requires** an API token by default; ordinary username/password login is rejected even for non-root management identities.
- The only explicit password-login exception is the separate restricted `vnc@pve` console identity, which is instantiated with token enforcement disabled only for console ticket creation.
- `root@pam`, `root` in the PAM realm, and tokens backed by `root@pam` are explicitly rejected.
- README requires a dedicated privilege-separated token and documents scoped ACL guidance.

### Fix commit / verification

- Commits: `aa81f3574b3a426a8d281a298ad070396eaff741`, `5007848389f2d537cd9fd62726684f9ee35640c4`, `101913eeea5be4bd962381f1dc7540e64605f535`
- Verification: Proxmox documents that token permissions are bounded by their backing user and may be privilege-separated. Run the full create/clone/reinstall/power/delete lifecycle with the production ACL set before marking VERIFIED.

## SEC-003 — Sensitive credentials can be written to WHMCS Module Log

**Severity:** HIGH  
**Status:** FIXED - NEEDS VERIFICATION  
**Category:** CWE-532 Insertion of Sensitive Information into Log File  
**Primary file:** `modules/servers/pvewhmcs/pvewhmcs.php`  
**Relevant code:** around lines 172-185, 550-558, 649-657 and other `logModuleCall()` sites.

### Evidence

Examples include:

- Logging `ALL_Custom_Fields => $params['customfields']`.
- Logging the entire `$params` array in error paths.
- TestConnection exception logging also passes `$params`.
- This module uses a custom field named `Password` for LXC/root/cloud-init.
- WHMCS server parameters can include the Proxmox server credential.

### Security impact

Customer/root passwords and/or PVE credentials may persist in the WHMCS Module Log, increasing the impact of an admin compromise, backup leak, support export, or database/log disclosure.

### Required remediation

- Never pass raw `$params` or all custom fields to logs.
- Use WHMCS `logModuleCall()` replacement/redaction parameters correctly.
- Redact at least: password, serverpassword, vnc_secret, ticket, CSRF token, VNC ticket, Authorization/token values.
- Log only identifiers and non-secret operational metadata.
- Document a one-time purge/rotation procedure for historical logs.

### Acceptance criteria

- Enabling debug mode does not write any credential or ticket.
- Triggered exceptions do not write secrets.
- Automated test/assertion searches generated log payloads for known test secrets and finds none.

### Remediation applied

- Raw provisioning `$params`, all custom fields, server credentials and customer/root passwords are no longer passed to `logModuleCall()`.
- Debug logging uses a minimal non-secret context (service/user/product/server IDs and safe VM metadata).
- Added shared recursive log redaction for password/secret/token/ticket/cookie/authorization/CSRF-like keys and common string encodings.
- Raw PVE success/error responses are no longer written to module logs; logs record bounded response summaries only.
- Full cluster resource/task dumps were removed from addon debug logs.
- Reinstall activity errors are redacted before being written to WHMCS Activity Log.
- The full WHMCS provisioning params array is not exposed to the Smarty client template.

### Fix commit / verification

- Commits: `386e671ee4dcd92f58f937abda64c837dc1ac7f6`, `05d9b95fddef7015bc29a3719dda686fe3a1387c`
- Verification: static sink review complete. Enable Module Log in staging, exercise success/error paths with known test secrets, and confirm those values never appear. Purge historical Module Log entries and rotate credentials that may have been logged before this fix.

## SEC-004 — PVE and VNC bearer tickets transported in query strings

**Severity:** HIGH  
**Status:** FIXED - NEEDS VERIFICATION  
**Category:** CWE-598 Use of GET Request Method With Sensitive Query Strings  
**Primary files:** `modules/servers/pvewhmcs/pvewhmcs.php`, `modules/servers/pvewhmcs/novnc_router.php`  
**Relevant code:** `pvewhmcs_noVNC()` around 1251-1295; router around 39-84.

### Evidence

The module constructs a URL containing:

- `pveticket`
- `vncticket`
- host
- port
- path

The router receives these via `$_GET`; the VNC ticket is then also passed as noVNC's URL `password` parameter.

### Security impact

Sensitive bearer-style material can leak into browser history, reverse-proxy logs, web-server access logs, analytics, screenshots, copied URLs, support records, or referrer-related paths.

### Required remediation

Redesign console bootstrap so raw PVE/VNC tickets are not exposed in browser-visible URLs. Preferred pattern:

- WHMCS creates a short-lived opaque one-time console session ID.
- Ticket material is retained server-side.
- Browser receives only the opaque nonce.
- Nonce is bound to WHMCS client ID + service ID + expiration + one-time use.
- Ticket retrieval/bootstrap occurs server-side or via a protected endpoint.
- Add `Cache-Control: no-store` and an appropriate `Referrer-Policy`.

### Acceptance criteria

- Browser URL/history contains no PVE auth ticket or VNC ticket.
- Access logs contain no ticket.
- Reusing the opaque nonce fails after first use/expiry.
- Nonce for one service/client cannot open another service.

### Remediation applied

- The customer-facing console URL carries only a random one-time session nonce.
- PVE authentication ticket, VNC ticket, destination host, port and path are generated/resolved server-side after authorization.
- Console endpoints send `Cache-Control: no-store` and `Referrer-Policy: no-referrer`.
- A second short-lived one-time nonce transfers authorized runtime state to the minimal noVNC page.
- Outstanding bootstrap/runtime nonce pools are pruned and capped to prevent client-session storage growth.
- The final noVNC handoff revalidates Active service ownership, guest VMID/type mapping, and assigned PVE host/port before exposing runtime state.

### Fix commit / verification

- Commits: `6febca34b884971c1becfaf5c275d30deda727bb`, `10523475516b4417755def832fbbd82573577ebb`, `8fb026d4fb88cca520bb347d79ae737c8e6a3e22`
- Verification: customer/browser page URLs and WHMCS access-log query strings contain no PVE/VNC ticket. The PVE VNC proxy protocol still necessarily uses its short-lived VNC ticket inside the direct PVE WebSocket connection.

## SEC-005 — noVNC router is standalone and not bound to WHMCS session/service authorization

**Severity:** HIGH  
**Status:** FIXED - NEEDS VERIFICATION  
**Category:** CWE-862 Missing Authorization / replay risk  
**Primary file:** `modules/servers/pvewhmcs/novnc_router.php`

### Evidence

The router accepts required GET parameters and proceeds to set the PVE cookie and redirect. It does not bootstrap WHMCS, verify an authenticated client, verify ownership of a service, or validate a server-side one-time nonce.

### Security impact

A leaked valid console URL can be replayed for as long as its underlying tickets remain usable. Authorization depends primarily on possession of the URL.

### Required remediation

Implement the server-side one-time console session described in SEC-004. Verify:

- authenticated WHMCS client,
- service ownership,
- active service status,
- expected PVE host/VMID,
- nonce expiry,
- nonce single use.

### Acceptance criteria

Directly calling the router without a valid authenticated session + nonce fails.
Cross-client and cross-service replay tests fail safely.

### Remediation applied

- `novnc_router.php` boots WHMCS and requires an authenticated client session.
- It consumes the first-stage nonce before privileged work.
- Service ownership, Active status, module guest mapping and user ID are revalidated from the WHMCS database.
- The nonce is client/service bound, expires after about one minute, and outstanding nonce pools are bounded.
- `novnc_client.php` performs a second authorization check immediately before rendering: client/service ownership, Active state, VMID/type mapping and current assigned PVE host/port must still match the server-side runtime session.

### Fix commit / verification

- Commit: `10523475516b4417755def832fbbd82573577ebb`
- Verification: static authorization flow reviewed. Perform cross-client, expired-nonce and replay tests in staging before marking VERIFIED.

## SEC-006 — Broad console authentication cookie with `HttpOnly=false`

**Severity:** HIGH / MEDIUM  
**Status:** FIXED - NEEDS VERIFICATION  
**Category:** CWE-1004 Sensitive Cookie Without HttpOnly Flag / overly broad cookie scope  
**Primary file:** `modules/servers/pvewhmcs/novnc_router.php`  
**Relevant code:** around lines 27-35 and 56-70.

### Evidence

`PVEAuthCookie` is written:

- to the calculated parent domain,
- with `Secure=true`,
- `HttpOnly=false`,
- `SameSite=None`.

### Security impact

Parent-domain scope widens the blast radius to sibling subdomains. JavaScript-readable auth material raises impact of XSS/sibling-origin compromise.

### Required remediation

Prefer a console architecture that does not require propagating a PVE authentication cookie across the customer-facing parent domain. If a cookie remains necessary, narrow domain/path/lifetime as much as the PVE design permits and document the residual risk.

### Acceptance criteria

No long-lived or broadly scoped PVE auth cookie is readable by unrelated application JavaScript/sibling services.

### Remediation applied

- `PVEAuthCookie` is now short-lived (about 120 seconds), `Secure`, `HttpOnly`, `SameSite=Strict`, and scoped to `/api2/json/`.
- JavaScript can no longer read the PVE authentication ticket.
- Cookie-domain calculation is derived from both trusted WHMCS/PVE hostnames instead of blindly using the final two labels.
- Direct console mode requires trusted HTTPS DNS hostnames under a common registrable domain.

### Residual architectural note

Direct browser-to-PVE WebSocket access still requires the PVE cookie to be available to the PVE sibling hostname. Completely eliminating that shared-domain cookie would require a dedicated authenticated WebSocket reverse proxy rather than the current direct-PVE noVNC architecture.

### Fix commit / verification

- Commits: `d0a6f5aa062ea61d33cf17678412ad613e1113c2`, `10523475516b4417755def832fbbd82573577ebb`
- Verification: inspect browser cookie attributes and successful WSS authentication in the production domain topology before marking VERIFIED.

## SEC-007 — State-changing admin operations use GET and forms lack explicit module-level CSRF validation

**Severity:** MEDIUM  
**Status:** FIXED - NEEDS VERIFICATION  
**Category:** CWE-352 Cross-Site Request Forgery  
**Primary file:** `modules/addons/pvewhmcs/pvewhmcs.php`  
**Relevant routing:** around lines 606-725; delete functions around 2102+, 2245+, 2358+.

### Evidence

Examples:

- `action=removeplan&id=...`
- `action=removeippool&id=...`
- `action=removeip&id=...`

perform destructive database changes from GET actions.

POST forms are present for configuration/plan/IP operations, but explicit addon-level token generation/validation was not found in the module source.

### Security impact

An authenticated WHMCS administrator may be induced to trigger a state-changing request. GET-based deletion also violates safe-method expectations and increases accidental-action risk.

### Required remediation

- Convert all mutations/deletes to POST.
- Add WHMCS-supported CSRF token generation/validation for addon forms/actions.
- Validate integer IDs and expected action.
- Prefer POST-Redirect-GET after successful writes.

### Acceptance criteria

- GET cannot modify/delete module state.
- Missing/invalid CSRF token causes rejection.
- Valid admin POST succeeds.

### Remediation applied

- Plan, IP-pool and IP deletion operations are no longer reachable through GET.
- All addon POST mutations require WHMCS token validation.
- Every module POST form emits a WHMCS CSRF token.
- Destructive operations use an allowlisted POST dispatcher and positive integer target validation.
- QEMU/LXC plan updates no longer take the mutation target from `$_GET['id']`; edit forms carry a CSRF-protected hidden `plan_id`, which is positively validated before the update function is called.

### Fix commit / verification

- Commit: `c2de9ac651968c410c15bd369dd3236fbc8500cc`
- Verification: static route/form review complete. Test a valid admin POST, missing token, wrong token and old GET delete URLs in WHMCS before marking VERIFIED.

## SEC-008 — Inconsistent contextual output escaping / stored XSS surface

**Severity:** MEDIUM  
**Status:** FIXED - NEEDS VERIFICATION  
**Category:** CWE-79 Improper Neutralization of Input During Web Page Generation  
**Primary files:** `modules/addons/pvewhmcs/pvewhmcs.php`, `modules/servers/pvewhmcs/clientarea.tpl`

### Evidence

Some PVE/admin values correctly use `htmlspecialchars()`, but others are emitted directly, including stored module-plan/IP-pool values and multiple values from PVE guest configuration in the Smarty client template.

Examples include direct output of plan titles, storage/network values, pool titles/gateways and guest configuration fields.

### Security impact

A malicious or compromised administrator/PVE configuration, or an unexpected value entering stored module configuration, may become executable HTML/JS in WHMCS admin or client context.

### Required remediation

- Centralize HTML escaping for addon output.
- Escape HTML text and attribute contexts separately.
- In Smarty, use explicit `|escape:'html'` / appropriate context for externally sourced values.
- Validate enum/numeric configuration values on input.
- Never use escaping as a substitute for authorization or validation.

### Acceptance criteria

Test payloads containing HTML/quotes/script-like text render as inert text in both admin and client pages.

### Remediation applied

- Added centralized `pvewhmcs_e()` HTML escaping in the admin addon.
- Plan/pool stored values and editable HTML attributes are contextually escaped.
- Admin exception text is escaped before rendering.
- Client Smarty output explicitly escapes guest/PVE-derived text, attributes, NIC values, SSH keys and statistics attributes.
- Reinstall template uses explicit escaping.
- Security regression CI now rejects bare Smarty interpolation in the client templates and guards the historically vulnerable stored admin fields from being reintroduced without escaping.

### Fix commit / verification

- Commits: `e16f9fd0047508ba04756fa22df06763c0732025`, `12208364cdb401701419354c8d5dc91853e8ed5e`, `2a61169d0d532759522bfc5edfbdf257efda2d9e`
- Verification: inject HTML/quote/script test strings into staging plan/PVE fields and verify inert rendering before marking VERIFIED.

## SEC-009 — VNC secret stored/displayed as plaintext

**Severity:** MEDIUM  
**Status:** FIXED - NEEDS VERIFICATION  
**Category:** CWE-312 Cleartext Storage of Sensitive Information  
**Primary files:** `modules/addons/pvewhmcs/db.sql`, `modules/addons/pvewhmcs/pvewhmcs.php`

### Evidence

`mod_pvewhmcs.vnc_secret` is a normal varchar. Admin configuration reads it back into `<input type="text" ... value="...">`.

### Security impact

Database/admin-page disclosure directly reveals the PVE `vnc@pve` credential.

### Required remediation

- Store using a WHMCS-supported secret/encryption mechanism or migrate to a PVE API/token design that does not need a reusable password.
- Never re-render the existing secret into HTML.
- UI should show a masked/unchanged state and replace only when a new value is supplied.
- Rotate the current VNC credential after migration.

### Acceptance criteria

Database value is not reusable plaintext and admin HTML does not contain the existing secret.

### Remediation applied

- Added WHMCS-backed secret encryption helpers using the supported `EncryptPassword` / `DecryptPassword` Local API.
- Stored VNC secrets are prefixed/encrypted and legacy plaintext is migrated automatically on addon access or secret use.
- Admin UI is now a blank password field with a configured-state placeholder; the existing secret is never rendered into HTML.
- Blank form submission preserves the current secret; replacement values must meet the minimum length.

### Fix commit / verification

- Commits: `d0a6f5aa062ea61d33cf17678412ad613e1113c2`, `013224e6cf6d5a3e632132299d92246baeb189fd`, `0b0021533830d8fd17e6eaf601639cfead144136`
- Verification: verify the DB contains an `enc:` value after upgrade and console access still works. Rotate the old `vnc@pve` password once after deploying this migration.

## SEC-010 — Unbounded IPv4 CIDR expansion can exhaust resources

**Severity:** MEDIUM  
**Status:** FIXED - NEEDS VERIFICATION  
**Category:** CWE-400 Uncontrolled Resource Consumption  
**Primary files:** `modules/addons/pvewhmcs/pvewhmcs.php`, `modules/addons/pvewhmcs/Ipv4/SubnetIterator.php`

### Evidence

`add_ip_2_pool()` parses a submitted CIDR and iterates every host returned by `Ipv4_SubnetIterator`, inserting addresses one-by-one. No maximum prefix/range size is enforced.

### Security impact

A large range can cause excessive PHP execution time, memory/DB work and enormous table growth.

### Required remediation

- Define a maximum import size.
- Calculate host count before iteration.
- Reject ranges above the configured limit.
- Use chunked/bulk insertion for allowed ranges.
- Validate pool ID and IPv4 input strictly.

### Acceptance criteria

Oversized ranges are rejected before enumeration.
Allowed ranges complete within bounded time/query count.

### Remediation applied

- Strictly validates pool ID and IPv4/CIDR input.
- Calculates CIDR size before iteration and rejects more than 4096 addresses (larger than /20).
- Skips pool gateways and existing addresses.
- Performs bounded batch inserts inside a transaction.

### Fix commit / verification

- Commit: `24bb11505c613a0397cba0fd8762e8fabc311970`
- Verification: test /32, /24, /20 and rejected /19-/0 inputs and observe bounded DB/runtime behavior before marking VERIFIED.

## SEC-011 — Bundled noVNC v1.7.0 ZRLE plain-RLE CPU DoS condition

**Severity:** MEDIUM  
**Status:** FIXED - NEEDS VERIFICATION  
**Category:** resource exhaustion / client-side availability  
**Primary file:** `modules/servers/pvewhmcs/novnc/core/decoders/zrle.js`  
**Relevant code:** `_decodeRLETile()` around lines 127-141.  
**Upstream tracking:** `novnc/noVNC#2072`

### Evidence

The bundled code reads an attacker/server-controlled RLE length and loops for that length without checking `i + length <= tileSize`. The adjacent palette-mode decoder contains such a bound. The bundled file was checked and contains the reported condition.

### Security impact

A malicious VNC server, or a successful MITM capable of injecting VNC framebuffer traffic, can cause severe CPU amplification/freeze in the browser/noVNC client.

### Required remediation

Prefer upgrading to an upstream release containing the fix once available. If an urgent local patch is necessary, mirror the tile-bound validation used by the palette-mode decoder and add a regression test. Record any local vendor modification clearly because it breaks the current "identical to upstream v1.7.0" vendor baseline.

### Acceptance criteria

An RLE run larger than the remaining tile is rejected before the large inner loop.

### Remediation applied

- Added the missing `i + length > tileSize` guard to bundled noVNC v1.7.0 `_decodeRLETile()`.
- Oversized plain-mode RLE runs now throw before the inner pixel loop.
- A CI regression check requires this bound to remain present.

### Vendor note

As of the 2026-10-07 check, noVNC 1.7.0 remains the latest release and upstream issue #2072 remains open. This repository therefore intentionally carries a one-file local security patch and is no longer byte-identical to pristine noVNC v1.7.0.

### Fix commit / verification

- Commits: `60e87af613681bcc6e5e1477e157e6aa62174b47`, `ef724f4af3a4c398c300c156464f45f062c1b073`
- Verification: source bound is present; run the noVNC decoder/browser regression suite where available before marking VERIFIED.

## SEC-012 — noVNC destination can be influenced by URL parameters; module lacks destination allowlisting

**Severity:** MEDIUM  
**Status:** FIXED - NEEDS VERIFICATION  
**Category:** untrusted WebSocket destination / phishing/connection-confusion surface  
**Primary files:** `modules/servers/pvewhmcs/novnc_router.php`, bundled noVNC  
**Upstream tracking:** `novnc/noVNC#2051`

### Evidence

The router accepts `host`, `port`, and `path` from request parameters and passes them into the noVNC URL. Upstream noVNC intentionally supports connection destination parameters and has an open security discussion about allowlisting/CSP.

### Security impact

Without binding destination values to a server-side service record, the trusted WHMCS/noVNC origin can potentially be used to initiate a WebSocket connection toward an attacker-selected endpoint or unexpected target.

### Required remediation

- Do not trust host/port/path from the browser.
- Resolve destination server-side from service ID / PVE server configuration.
- Allowlist expected PVE endpoints.
- Add a restrictive CSP `connect-src` where practical.
- Fold this into the SEC-004/SEC-005 console redesign.

### Acceptance criteria

Client input cannot select an arbitrary WebSocket host/port/path.

### Remediation applied

- Browser input can no longer supply the PVE host, API port, guest path, VMID or node.
- Destination is resolved server-side from the authenticated WHMCS service/server mapping and trusted PVE cluster resources.
- The minimal noVNC page sets CSP `connect-src` to the single resolved PVE WSS origin.
- Runtime state is bound to an authenticated one-time console session.

### Fix commit / verification

- Commits: `10523475516b4417755def832fbbd82573577ebb`, `8fb026d4fb88cca520bb347d79ae737c8e6a3e22`
- Verification: attempt crafted host/port/path query parameters and confirm they cannot affect the WebSocket destination.

## SEC-013 — PVE ticket age check was logically incorrect

**Severity:** LOW  
**Status:** FIXED - NEEDS VERIFICATION  
**Category:** authentication state correctness  
**Primary file:** `modules/addons/pvewhmcs/proxmox.php`  
**Relevant function:** `check_login_ticket()`.

### Original evidence

The old code compared approximately:

`login_ticket_timestamp >= (time() + 7200)`

A timestamp captured in the past would not become greater than the current time plus two hours.

### Remediation applied

The session check now invalidates the local PVE ticket when:

`time() >= login_ticket_timestamp + 7200`

and clears both the ticket and its timestamp.

### Acceptance criteria

Unit/integration tests should verify valid-young and expired-old ticket behavior.

### Remediation applied

The local ticket check now expires PVE login sessions when `time() >= login_ticket_timestamp + 7200` and clears the cached ticket/timestamp.

### Fix commit / verification

- Commit: `8eed834f63e57cb5ac7a636b5d25c1a7b27de40f`
- Verification: code path reviewed; test young and artificially expired tickets in integration before marking VERIFIED.

## SEC-014 — Legacy custom SHA1/MD5/XOR encryption helper remains in source

**Severity:** LOW  
**Status:** FIXED - NEEDS VERIFICATION  
**Category:** obsolete cryptography / unsafe reuse risk  
**Primary file:** `modules/servers/pvewhmcs/pvewhmcs.php`  
**Relevant code:** approximately 830-1020.

### Evidence

The file contains an old custom encryption implementation using SHA1/MD5-derived material, XOR operations, pseudo-random generation based on `rand()`/globals, and legacy WHMCS encryption-hash logic.

The current active client-area password retrieval uses WHMCS `localAPI('DecryptPassword', ...)`; the legacy helper therefore appears to be obsolete/dead in the reviewed flow.

### Required remediation

Confirm no supported path calls `pvewhmcs_get_whmcs_server_password()`; then remove the legacy helper rather than modernizing/reusing it.

### Acceptance criteria

No call sites remain; provisioning/client operations still retrieve credentials through supported WHMCS mechanisms.

### Remediation applied

- Removed the entire legacy SHA1/MD5/XOR encryption class and `pvewhmcs_get_whmcs_server_password()`.
- Active code uses the supported WHMCS `DecryptPassword` Local API only.

### Fix commit / verification

- Commit: `9a2617ec809b38f81594bc933eadc50a3e310fd9`
- Verification: static call-site search found no supported dependency on the removed helper; exercise client/power/provisioning actions in staging before marking VERIFIED.

## SEC-015 — Parent-domain derivation for console cookie is fragile

**Severity:** LOW  
**Status:** FIXED - NEEDS VERIFICATION  
**Category:** security configuration correctness  
**Primary file:** `modules/servers/pvewhmcs/novnc_router.php`  
**Relevant code:** around lines 56-57.

### Evidence

The router derives the "main domain" by taking the final two hostname labels. This is wrong for public suffixes such as `.co.uk`; the README itself warns about this limitation.

### Required remediation

Prefer eliminating this cross-subdomain cookie design through SEC-004/SEC-006. If retained temporarily, use a deliberate configured console/PVE domain rather than deriving a registrable domain heuristically.

### Acceptance criteria

Console auth does not depend on incorrect public-suffix assumptions.

### Remediation applied

- Removed the old "last two hostname labels" derivation.
- Secure console derives the common suffix from both actual configured WHMCS and PVE DNS hosts and rejects IP literals and known public-suffix-only results.
- Console no longer accepts a cookie domain/destination from browser input.

### Fix commit / verification

- Commits: `d0a6f5aa062ea61d33cf17678412ad613e1113c2`, `10523475516b4417755def832fbbd82573577ebb`
- Verification: test the real production domain (including multi-label TLD if applicable) and inspect the resulting cookie domain.

## SEC-016 — Remote version check lacks defensive cURL controls

**Severity:** LOW  
**Status:** FIXED - NEEDS VERIFICATION  
**Category:** availability / external dependency hardening  
**Primary file:** `modules/addons/pvewhmcs/pvewhmcs.php`  
**Relevant function:** `get_pvewhmcs_latest_version()` around lines 158-171.

### Evidence

The addon requests the upstream raw GitHub `version` file without explicit connect/request timeout handling.

### Security impact

Network/DNS issues can unnecessarily delay an admin page/request. This is not a backdoor and the response is only used as version data, not executed.

### Required remediation

Add short connect/overall timeouts, validate expected version-string format, and fail closed to "version check unavailable."

### Acceptance criteria

Unavailable GitHub does not materially delay addon page rendering.

### Remediation applied

- Remote version check now has a 3-second connect timeout and 5-second total timeout.
- Redirect following is disabled, HTTPS protocol is required and HTTP errors fail.
- Response must match the expected version-string format.
- Network/validation failures safely fall back to the installed version.

### Fix commit / verification

- Commit: `858e3851ffb0834a0e211e0dab67b5ba90a47ab2`
- Verification: simulate unreachable GitHub/invalid response and confirm admin rendering remains bounded before marking VERIFIED.

# Additional hardening observations

These are not currently ranked above the primary findings but should be considered during refactoring:

- Avoid passing the entire WHMCS `$params` structure into Smarty/client templates when only selected values are required.
- Add strict type/range validation for plan fields (CPU, memory, disk, VLAN, rate, VMID, IDs).
- Add unique/transaction-safe IP allocation and VMID provisioning tests for concurrent orders.
- Add explicit HTTP/network timeouts to all PVE calls.
- Minimize exception details returned to end users.
- Add CSP/security headers for the noVNC surface.
- Add static analysis (PHPStan/Psalm/Semgrep or equivalent) and secret scanning to CI.
- Add tests proving a client action can operate only on the WHMCS service mapped to that authenticated client.
- Keep third-party noVNC pinned to an exact release and re-run hash/vendor review when updating.

---

# Negative findings / things NOT found in reviewed source

At the reviewed source baseline, no evidence was found of:

- `exec()`, `shell_exec()`, `system()`, `passthru()` or similar first-party shell execution;
- a hidden web shell;
- arbitrary user-controlled file upload/write functionality;
- obfuscated payload download-and-execute behavior;
- dynamic user-controlled PHP include/require paths;
- an intentionally hidden credential-exfiltration endpoint;
- an obvious active SQL injection in the core provisioning path (bound parameters/query-builder usage is present);
- fork-specific malicious modifications;
- modified/bundled noVNC files relative to official v1.7.0.

This does **not** establish that a deployed server is clean. Production forensic review must separately inspect runtime files, WHMCS hooks/modules outside this repository, cron jobs, web-server/PHP configuration, database/admin accounts, access logs, WHMCS module logs, Proxmox users/tokens and PVE authentication/audit logs.

---

# Functional capability snapshot relevant to future work

## Automatic provisioning

**Supported in code.** `pvewhmcs_CreateAccount($params)` is implemented and can:

- allocate an IP from a module pool;
- find a free PVE VMID;
- clone a QEMU template;
- create a QEMU VM from plan settings and optionally attach an ISO;
- create an LXC container from a PVE template;
- apply CPU/RAM/disk/network/VLAN settings;
- persist WHMCS service ↔ PVE VM mapping;
- start the guest when the module plan has `onboot` enabled.

Important distinction:

- **QEMU template clone + cloud-init:** can be a true ready-to-use automatic VPS delivery flow when the template is prepared correctly.
- **LXC template:** can be automatically created as a usable container.
- **QEMU + ISO:** the VM is automatically created and the ISO attached, but this module does not automate the operating-system installer. This is not equivalent to automatic OS installation.

Whether WHMCS calls `CreateAccount` automatically is controlled by the WHMCS product's Module Settings / Automation Settings (for example, setup after first successful payment).

## Current client-area actions

Implemented buttons:

- Start
- Reboot
- Power Off / graceful shutdown
- Hard Stop
- Statistics
- Check Status
- noVNC console
- Reinstall OS

## Reinstall / rebuild

**Implemented after the original audit baseline; source reviewed through `8eed834f63e57cb5ac7a636b5d25c1a7b27de40f`.**

Primary file: `modules/servers/pvewhmcs/reinstall.php`.

Security-relevant design:

- Requires the WHMCS service to be Active and owned by the invoking client.
- Requires the module VM mapping to belong to the same client and rejects duplicate-VMID ownership ambiguity.
- Requires Proxmox VE major version 9+ at runtime.
- Accepts only allowlisted QEMU/LXC template values from the product/template configuration; arbitrary client-supplied VMIDs/volume paths are rejected.
- QEMU sources must be actual PVE templates and must contain Cloud-Init.
- Uses the native WHMCS client-area CSRF token plus a cryptographically random one-time per-service session nonce, explicit destructive confirmation and the literal confirmation phrase `REINSTALL`.
- Uses a dedicated `reinstall.tpl` custom-action page returned through WHMCS `templatefile + vars`, avoiding theme-dependent raw HTML action output.
- Uses a per-service MySQL advisory lock against simultaneous reinstall requests.
- Uses a replacement-first cutover: build new VM/CT under a new VMID before stopping the current guest.
- Starts the replacement before the WHMCS mapping is switched.
- On startup or other pre-mapping cutover failure, the new guest is removed and the previous guest is best-effort restarted if it was previously running.
- Mapping/template state is moved in a database transaction.
- A new cryptographically random guest password is generated; the reinstall workflow does not write that password to module logs.
- The new password is stored via WHMCS `UpdateClientProduct`; failure to persist it is surfaced to the client without rolling back an otherwise successful replacement.
- Old-guest deletion is performed only after the replacement is running and mapped; a cleanup failure leaves a warning rather than deleting the working replacement.

Residual/security dependencies:

- The reinstall path still relies on the shared `PVE2_API` transport, so **SEC-001 (TLS verification disabled)** remains applicable.
- It still relies on the configured normal PVE service credential, so **SEC-002 (over-privileged/root identity)** remains applicable until that architecture is hardened.
- The rest of the module's existing logging and client/admin output issues remain open.
- GitHub Actions PHP syntax validation passed for the complete PHP source tree at source commit `8eed834f63e57cb5ac7a636b5d25c1a7b27de40f` (run `37682601499`). No live WHMCS + Proxmox VE 9 integration environment was available during this code change; production enablement still requires runtime verification on a non-production service first.

---

# Audit maintenance history

- **2026-10-07:** PR #2 merged at `d4b61805d5d566220ebcf3bbd8239a78ca5a3651`; all tracked source-level remediations SEC-001 through SEC-016 are now on `master`. PHP Syntax Check passed on the merge commit; Security Regression checks passed all substantive security steps on the merged source.
- **2026-10-07:** Security hardening implemented for SEC-001 through SEC-016; source-level fixes are awaiting live WHMCS + PVE 9 acceptance verification.
- **2026-10-07:** Added security-regression CI to prevent reintroduction of disabled TLS, root credentials, ticket-bearing console links, GET deletes and the noVNC ZRLE bound regression.
- **2026-10-07:** Initial full static review documented.
- Reviewed code baseline: `7ff41ccecde7e1d846860e3b24208129ee8fdd42`.
- Fork/upstream tree equality verified at baseline.
- noVNC v1.7.0 vendor integrity verified: 208/208 bundled files identical to official release.
- `SYSTEM_MAP.md` created as architecture/context handoff.
- **2026-10-07:** Added client-side Proxmox VE 9+ Reinstall OS workflow in `modules/servers/pvewhmcs/reinstall.php` and exposed it through the WHMCS client custom-button mechanism.
- Reinstall implementation commits reviewed: `52e41c649df43ac4adbe6bae692f81a41ead0d45`, `fa8736ca8064c14221531170228e36b2d0a49d75`, `054d8da0a2c7d0beee1aee1c28f1f01fa214075f`.
- PVE API transport response handling updated for protocol-independent HTTP status/header parsing through `8eed834f63e57cb5ac7a636b5d25c1a7b27de40f`; the same commit fixed local ticket-expiry logic and made all API calls honor the configured TLS verification flag. TLS verification still defaults off, so SEC-001 remains OPEN.
- Existing security findings were **not** marked fixed by the Reinstall work; SEC-001 through SEC-016 retain their previous statuses unless separately remediated and verified.
- Added `.github/workflows/php-lint.yml` so future PHP-changing pushes/PRs have a repository-level syntax check. GitHub Actions run `37682601499` completed successfully against source commit `8eed834f63e57cb5ac7a636b5d25c1a7b27de40f`; its `Lint PHP sources` step passed. This validates PHP syntax only, not live WHMCS/PVE behavior.
