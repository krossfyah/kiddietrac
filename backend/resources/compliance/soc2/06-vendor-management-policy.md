# Vendor Management Policy

| Field | Value |
|---|---|
| Version | 1.0 (draft, pending management approval) |
| Owner | Anthony Hosein, Security Officer |
| Criteria | CC9.2 (also CC2.3, C1.1, P6.1) |

## 1. Purpose

This policy makes sure that third parties who host, process or can access KiddieTrac data (sub-processors) are assessed, contracted and monitored in proportion to the risk they carry.

## 2. Scope

It applies to every vendor that stores, processes or transmits customer data, or that provides infrastructure the platform depends on.

## 3. Policy statements

### 3.1 Before onboarding

For each new sub-processor, record:

- what data it will receive;
- where it processes data (region);
- its security assurance, such as a SOC 2 or ISO 27001 report or a security page;
- whether a data processing agreement (DPA) or equivalent terms are in place.

The Security Officer approves the vendor before any production data is sent to it.

### 3.2 Risk tiers

| Tier | Definition | Review |
|---|---|---|
| High | Holds or can read the whole database, or large amounts of personal information | Every year: obtain the SOC 2 or ISO report, or a security attestation; confirm the DPA |
| Medium | Receives a subset of personal information for a specific purpose (messages, payments) | Every year: confirm the DPA and check the security page |
| Low | Receives no personal information, or only minimal technical data | Every 2 years |

### 3.3 Minimising what is shared

Send each vendor only the fields it needs. Integrations that only some agencies use (QuickBooks Online, social sign-in, AI features) are off unless that agency or user turns them on.

### 3.4 Credentials

Vendor API keys and secrets are stored encrypted at the application level (see 10). They are write-only in the portal and are rotated when someone leaves, or when a key is suspected of being exposed.

### 3.5 Customer transparency

The sub-processor list below is published, or made available to customers on request. Customers are told before a new high- or medium-tier sub-processor is added. **Status: Gap. There is no published list yet.**

### 3.6 Offboarding

When a vendor is dropped:

- revoke its keys;
- remove its credentials from the portal;
- request deletion of the data it holds, where applicable;
- record the date.

## 4. Sub-processor register

"DPA on file" is to be completed. A tick means a signed DPA, or accepted online DPA terms, is saved in the compliance folder.

| Vendor | Service | Data shared (high level) | Tier | Region (to confirm) | DPA on file |
|---|---|---|---|---|---|
| GoDaddy | Shared cPanel hosting, MySQL, web server | Everything: full database, uploaded files, logs, backups | High | North America | [ ] |
| GitHub (Microsoft) | Private source code repository | Source code and configuration; no customer data by design (confirm no secrets or data dumps are committed) | High | US | [ ] |
| Let's Encrypt (ISRG) | TLS certificates via acme.sh | Domain names only | Low | US | [ ] n/a (public CA terms) |
| Stripe | Payments | Payer name, email, amounts, invoice references; card data handled by Stripe | Medium | US / Canada | [ ] |
| Helcim | Payments | Payer name, email, amounts, invoice references; card data handled by Helcim | Medium | Canada | [ ] |
| ZumRails | Payments and payouts (EFT) | Payer or payee name, bank details for payouts, amounts | Medium | Canada | [ ] |
| Postmark | Transactional email | Recipient name and email, message content (may include child names and daily notes) | Medium | US | [ ] |
| Resend | Transactional email | As Postmark | Medium | US | [ ] |
| Amazon SES (AWS) | Transactional email | As Postmark | Medium | US / Canada | [ ] |
| Microsoft 365 (Graph API) | Email sending, including agency white-label mailboxes | As Postmark, plus the agency's own mailbox | Medium | Per tenant | [ ] |
| Twilio | SMS and voice | Phone numbers, message and announcement content | Medium | US | [ ] |
| Telnyx | SMS and voice | Phone numbers, message and announcement content | Medium | US | [ ] |
| Anthropic | AI features (for example drafting and translation) | The text a user submits to AI features, which may contain personal information | Medium | US | [ ] |
| Google Firebase Cloud Messaging | Push notifications to the Android app | Device tokens, notification title and body | Medium | US | [ ] |
| Google, Microsoft, Facebook | Optional sign-in (links to existing accounts only) | The sign-in identity (email, provider ID), only for users who choose it | Low | US | [ ] n/a (user-initiated) |
| Intuit QuickBooks Online | Accounting sync, started by the agency | Invoice, payment and customer (family) billing records for agencies that connect it | Medium | US / Canada | [ ] |
| IP geolocation provider (to be named) | Approximate location in security logs | IP addresses of sign-ins and security events | Low | To confirm | [ ] |

**Actions:**

1. Confirm the region, tier and DPA status for each vendor.
2. Name the IP geolocation provider.
3. Collect SOC 2 or ISO reports for the high-tier vendors.
4. Confirm with GoDaddy whether shared MySQL storage is encrypted at rest.
5. Check whether each vendor retains data, especially the AI and email providers, and whether they train on it.

## 5. Responsibilities

- **Security Officer:** keeps the register current, runs the reviews and approves new vendors.
- **Agency administrators:** decide whether to connect the optional integrations (QuickBooks Online, white-label email) and are responsible for their own accounts with those services.

## 6. Exceptions

A vendor may be used before its DPA is on file only with a written exception, and only for 60 days at most.

## 7. Review

The register is reviewed every year, and whenever a vendor is added, removed or changes the scope of what it does.
