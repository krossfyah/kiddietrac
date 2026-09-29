# Incident Response Plan

| Field | Value |
|---|---|
| Version | 1.0 (draft, pending management approval) |
| Owner | Anthony Hosein, Incident Lead |
| Criteria | CC7.2, CC7.3, CC7.4, CC7.5 (also CC2.3, P6) |

## 1. Purpose

This plan sets out how KiddieTrac detects, contains, fixes, communicates about and learns from security incidents. That includes breaches of security safeguards under PIPEDA. KiddieTrac had no written plan before this pack.

## 2. Scope

The plan covers any event that threatens the confidentiality, integrity or availability of KiddieTrac systems or customer data. Examples:

- unauthorised access or an attempt at it;
- data exposed across tenants;
- lost or leaked credentials or keys;
- malware on the host;
- data loss;
- a message sent to the wrong recipient;
- an extended outage;
- an incident at a sub-processor that affects KiddieTrac data.

## 3. Definitions

- **Security event:** something observed that may matter for security, such as a single failed login.
- **Security incident:** an event, or a set of events, that is confirmed or strongly suspected to threaten data or systems.
- **Breach of security safeguards (PIPEDA):** loss of, unauthorised access to, or unauthorised disclosure of personal information, caused by a breach of an organisation's security safeguards or by a failure to establish them.
- **RROSH:** real risk of significant harm. It is judged on how sensitive the information is and how likely it is to be misused.

## 4. Severity levels

| Level | Definition | Examples | Response start | Status updates |
|---|---|---|---|---|
| SEV-1 Critical | Personal information confirmed exposed, or platform-wide outage or compromise | Cross-agency data leak; database or host compromise; leaked admin credentials used | Immediately (within 1 hour of detection) | Every 4 hours |
| SEV-2 High | Likely exposure, or one account compromised, or a major feature down | Successful account takeover; a mis-scoped endpoint; backups failing for more than 24 hours | Within 4 hours | Daily |
| SEV-3 Medium | Attack attempt contained with no evidence of access; a partial outage | Credential probing that was blocked; a degraded integration | Within 1 business day | At closure |
| SEV-4 Low | Minor event or policy deviation | A single misdirected low-sensitivity email; a report of a low-severity vulnerability | Within 3 business days | At closure |

Response times are targets. KiddieTrac has one responder and no on-call rotation. See risk R-01.

## 5. Roles

| Role | Held by | Duties |
|---|---|---|
| Incident Lead | Anthony Hosein | Declares the incident, sets severity, directs the response, makes decisions |
| Technical Responder | Anthony Hosein | Investigates, contains and fixes |
| Communications / Privacy | Anthony Hosein (info@kiddietrac.com) | Notifies customers, the OPC and individuals |
| Customer contact | Affected agency's administrator | Receives notices; coordinates notices to families where the agency controls that relationship |
| External support (as needed) | GoDaddy support, sub-processor support, legal counsel | Host-level forensics, legal advice on notification |

**Backup contact:** none today. A named backup person with emergency, break-glass access is a remediation item.

## 6. Detection sources

- **Security alert job**, every 15 minutes. It looks for brute force by IP, credential stuffing, MFA hammering and backup failures, and emails the active platform admins.
- **`audit_logs`**: every API call, plus authentication and security events.
- Failed and slow request logs, and application error logs (kept 14 days).
- Certificate expiry check emails, sent when fewer than 21 days remain.
- Reports from customers, users and security researchers, via the vulnerability-reporting page, `security.txt` and info@kiddietrac.com.
- Notices from sub-processors.

**Known gap:** there is no external uptime monitoring. An outage is noticed only through the `/health` endpoint being checked by hand, or through user reports.

## 7. Response steps

1. **Triage** (at detection)
   - Confirm the event is real.
   - Assign a severity.
   - Open an incident record: date and time, source, systems affected, data affected.
2. **Contain**
   - Deny hostile IPs at the web server.
   - Revoke tokens and force a password reset for affected accounts.
   - Deactivate compromised accounts.
   - Rotate exposed keys.
   - Disable the affected feature or endpoint.
   - Place the site in maintenance mode if needed.
   - Keep the evidence: export the relevant `audit_logs`, web server logs and application logs **before** any cleanup.
3. **Investigate**
   - Work out the scope: which agencies, which people, which data fields, and over what period.
   - Use `audit_logs`, which are agency-stamped. Use `tenant:check` for scoping faults.
4. **Eradicate and recover**
   - Fix the root cause, then deploy under the emergency procedure in 03 section 4.5.
   - Restore data from backup if needed (see 05).
   - Confirm the fix with a targeted test against the Test Agency.
5. **Assess notification** (see section 8)
   - Record the RROSH assessment and its reasoning, **even if the decision is not to notify**.
6. **Communicate**
   - Notify affected agencies, the OPC and individuals as required.
7. **Close and review**
   - Hold a post-incident review (section 9).
   - Add remediation items to 12.

## 8. Notification

### 8.1 Customers (agencies)

KiddieTrac notifies the administrators of each affected agency **without undue delay**. The target is within 72 hours of confirming that the incident affects their data. The notice states:

- what happened;
- what data was involved;
- what KiddieTrac has done;
- what the agency should do.

Agencies are usually the organisations that control their families' and staff's information, so KiddieTrac coordinates with them on notices to individuals.

### 8.2 PIPEDA: breach of security safeguards

Where personal information under KiddieTrac's control is involved in a breach of security safeguards, and it is reasonable to believe the breach creates a real risk of significant harm (RROSH):

- **Report to the Office of the Privacy Commissioner of Canada (OPC)** as soon as feasible after determining that the breach occurred, using the OPC's breach report form.
- **Notify affected individuals** as soon as feasible. The notice must describe:
  - the circumstances of the breach;
  - the personal information involved;
  - the steps taken to reduce the risk of harm;
  - steps the individual can take themselves;
  - contact information.

  Notice is given directly unless the regulations allow indirect notice.
- **Notify other organisations or government institutions** that may be able to reduce the risk of harm, such as a payment processor.
- **Keep a record of every breach of security safeguards for 24 months** after the date the breach was determined, **whether or not it reached the RROSH threshold**. Provide the records to the OPC on request.

Where a customer agency is the organisation in control, KiddieTrac gives the agency the facts it needs to meet its own obligations. Provincial privacy laws may also apply to agencies in some provinces, for example Quebec, Alberta and British Columbia. Get legal advice when an incident affects agencies in those provinces.

Information about children and family circumstances is **sensitive**. Treat any confirmed exposure of it as likely to meet RROSH unless there is clear reasoning otherwise.

### 8.3 Breach record

Keep a breach register (spreadsheet or document) with:

- date determined;
- description;
- personal information involved;
- number of individuals;
- the RROSH decision and reasoning;
- the dates of notices to the OPC, individuals and agencies;
- the remediation.

Keep it for at least 24 months per entry. **Status: Gap. The register must be created.**

## 9. Post-incident review

For SEV-1 and SEV-2, hold a review within **10 business days** of closure. For SEV-3, hold one within 30 days. Write down:

- the timeline;
- the root cause;
- what worked and what did not;
- the control changes needed, with an owner and a date.

Record lessons learned in this plan or in the relevant policy, and bring them to the quarterly security review.

## 10. Testing

Run a tabletop exercise of this plan once a year. The first exercise will use a cross-agency data exposure scenario. Record the date, participants and findings. **Status: not yet performed.**

## 11. Worked example: credential probing, 2026-09-20

| Step | What happened |
|---|---|
| Detection | The automated security alert job flagged credential probing against the login endpoint and emailed the platform admin. |
| Triage | Classified as an attack attempt. With no evidence of successful access, it fits **SEV-3**. It would move up to SEV-2 if a login from the probing sources had succeeded. |
| Containment | The source IP addresses were **denied at the web server**. A **forced password reset** was applied to the targeted account, which revoked any existing sessions and made the next login require a new password. |
| Investigation | Review `audit_logs` for `login` and `login_failed` events from the source IPs, and for any successful login on the targeted account during the window. |
| Notification assessment | If no successful access occurred, there was no breach of security safeguards, so no OPC report and no RROSH notice were required. Record the reasoning in the breach register anyway. Tell the targeted user about the reset. |
| Lessons | 1. Detection worked end to end. 2. The alert reached only one person, so if the owner had been unavailable the probe would not have been seen. 3. IP denial was a manual step on the host. 4. The targeted account's MFA status should be checked, and MFA made mandatory on the server for admin roles. |

Keep the evidence for this incident: the alert email, the `audit_logs` export, the web server deny rule and the password-reset record. Before the audit, confirm that this record is complete and file it in the incident register.

## 12. Responsibilities, exceptions and review

- **Responsibilities:** as set out in section 5.
- **Exceptions:** none are allowed to the notification and record-keeping duties in section 8.
- **Review:** this plan is reviewed every year, after every SEV-1 or SEV-2 incident, and after each tabletop exercise.
