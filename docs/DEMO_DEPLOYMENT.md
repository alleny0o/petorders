# PETOrders AWS Demo Deployment Notes

Temporary public demo instance for the NIH SIP chief review, built on a
personal AWS account, August 2026. It followed `docs/DEPLOYMENT.md`
end-to-end and served as that guide's first real-world validation pass.

> The RHEL-general fixes found during this run were folded into
> DEPLOYMENT.md itself: SSL vhost sequencing, the SELinux boolean as a
> required step, the schema-load `sudo` redirection fix, and related
> notes.

This file records only what was **specific to the AWS demo**:
infrastructure choices, AWS-only quirks, and the demo's reduced scope.

**This is a demo, not production.** No real data anywhere. Scope was
deliberately reduced: one admin account only, with no seed data, no
synthetic orders, no staff/customer accounts, and no demo banner.

---

## Contents

1. [What's live](#1-whats-live)
2. [AWS infrastructure](#2-aws-infrastructure)
3. [AWS-specific quirks (vs. a NIH RHEL install)](#3-aws-specific-quirks-vs-a-nih-rhel-install)
4. [DNS (Cloudflare)](#4-dns-cloudflare)
5. [HTTP Basic Auth (demo-only layer)](#5-http-basic-auth-demo-only-layer)
6. [Cost](#6-cost)
7. [Teardown](#7-teardown)

---

## 1. What's live

| Item | Value |
| --- | --- |
| URL | `https://demo.ccpetd.com` |
| HTTPS | Let's Encrypt cert via certbot, auto-renewal scheduled |
| HTTP behavior | 301 redirect to HTTPS |
| Access control | HTTP Basic Auth (Apache level) in front of the entire site, then the app's own login |
| Accounts | One admin (`demo.admin@example.com`), created via `tools/bootstrap_admin.php` |
| Data | None: empty schema, no seed data, no orders |
| Credentials | Bitwarden only, shared out-of-band. Nothing committed to git. |

DEPLOYMENT.md's verification checklist passed in full against this
instance (config.php 404, assets 403, HTTP→HTTPS 301, secure cookie
flags, forced password change, dashboard login/logout).

---

## 2. AWS infrastructure

Created via the EC2 console (Launch instance wizard), region
`us-east-1`.

| Setting | Value | Why |
| --- | --- | --- |
| Name | `petorders-demo` | n/a |
| AMI | Red Hat Enterprise Linux 8 (HVM), SSD, `ami-02b9c495c0a5d8d7a`, x86_64 | Matches DEPLOYMENT.md's RHEL 8 target exactly (resolved to RHEL 8.10). Beware: the *other* "RHEL 8" AMI in the catalog bundles SQL Server, so avoid it. |
| Instance type | `t3.small` (2 vCPU, 2 GiB) | Plenty for an empty demo. t3.medium was blocked by the new account's default vCPU quota. If load ever demanded it, a stop → resize → start is the upgrade path. |
| Key pair | `petorders-demo-key`, RSA, `.pem` | Downloaded once at creation, held locally. Login user for this AMI is `ec2-user`. |
| Storage | 20 GiB gp3 | Headroom over the 10 GiB default; disk space should never be a mid-demo failure mode. |

Security group `petorders-demo-sg`, inbound:

| Type | Port | Source | Purpose |
| --- | --- | --- | --- |
| SSH | 22 | Admin's IP only (`/32`) | Server administration |
| HTTPS | 443 | `0.0.0.0/0` | The demo site |
| HTTP | 80 | `0.0.0.0/0` | Required for Let's Encrypt HTTP-01 validation; also serves the 301→HTTPS redirect |

## 3. AWS-specific quirks (vs. a NIH RHEL install)

| Quirk | Detail |
| --- | --- |
| Subscription warnings | Every `dnf` run prints "This system is not registered with an entitlement server." **Harmless**: AWS RHEL AMIs use AWS-hosted RHUI repos, no Red Hat subscription needed. |
| No firewalld | `firewall-cmd: command not found` on this AMI. The AWS security group is the only firewall layer; DEPLOYMENT.md's firewalld step is a no-op here (guide now notes this case). |
| PHP source | RHEL 8 AppStream tops out at PHP 8.2, and the demo intentionally ran PHP **8.3.33** from the Remi repo (`php:remi-8.3` module stream) instead of the 7.4.33 production target. App ran with zero code changes, noted in DEPLOYMENT.md as a version-bump data point. |
| Cert issuance | `certbot --apache` (Let's Encrypt) instead of an IT-issued cert. Required port 80 reachable during issuance; certbot generated its own `:443` vhost (`petorders-le-ssl.conf`) and the HTTP→HTTPS redirect. This path is what surfaced the SSL sequencing trap now documented in DEPLOYMENT.md §5. |
| Internet exposure | Automated bots probed `/.env`, `/.git`, `/.aws`, and cgi-bin paths within minutes of DNS going live. All denied (403/404) by the app's `.htaccess` hardening. This motivated the Basic Auth layer below. |

## 4. DNS (Cloudflare)

One record in the `ccpetd.com` zone:

| Type | Name | Content | Proxy status | TTL |
| --- | --- | --- | --- | --- |
| A | `demo` | `3.83.248.38` | **DNS only (grey cloud)** | Auto |

> Grey cloud matters: DNS-only lets Let's Encrypt's HTTP-01 challenge
> reach the EC2 box directly. Proxying through Cloudflare would add an
> unnecessary layer (and mask visitor IPs) for a disposable demo.

## 5. HTTP Basic Auth (demo-only layer)

Not part of the production design (intranet app); added here because
the demo is internet-reachable.

```bash
sudo htpasswd -c /etc/httpd/.htpasswd-petorders demo
```

Then in the certbot-generated `:443` vhost
(`/etc/httpd/conf.d/petorders-le-ssl.conf`), `Require all granted`
inside the `<Directory>` block was replaced with:

```apache
AuthType Basic
AuthName "Restricted Access"
AuthUserFile /etc/httpd/.htpasswd-petorders
Require valid-user
```

```bash
sudo apachectl configtest && sudo systemctl restart httpd
```

Verified: browser-native auth prompt appears before anything loads;
after Basic Auth, the normal PETOrders login page is served. The
`.htpasswd` file lives outside the document root and can never be
served.

## 6. Cost

| Item | Rate | Monthly (if left running) |
| --- | --- | --- |
| t3.small on-demand, us-east-1, RHEL pricing | ~$0.0496/hr | ~$36 |
| 20 GiB gp3 EBS | ~$0.08/GiB-mo | ~$1.60 |
| Data transfer (demo-scale) | n/a | negligible |
| **Total ceiling** | | **~$37-38/month** |

RHEL AMIs bill a premium over base-Linux rates (base t3.small would be
~$15/mo). Actual spend will be a small fraction of the ceiling, since
the instance is destroyed after the chief review.

## 7. Teardown

- [ ] Terminate EC2 instance `i-03b8b1ab101c4df31` (us-east-1) after the chief review
- [ ] Delete the `demo` A record from the Cloudflare `ccpetd.com` zone
- [ ] Confirm the EBS volume was deleted with the instance (delete-on-termination is the default)
- [ ] Delete the `petorders-demo-key` key pair and the local `.pem` if no longer needed
- [ ] Remove the Bitwarden entries for demo credentials once torn down