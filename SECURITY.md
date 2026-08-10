# Security policy

## Supported versions

The current release is supported. Older versions are not patched — please update.

## Reporting a vulnerability

Please do **not** open a public issue.

Email **mymail.anupam@gmail.com** with:

- What the vulnerability is
- How to reproduce it
- What an attacker could achieve
- The plugin, WordPress and PHP versions you tested

You can expect an acknowledgement within a few days. Once there is a fix, it ships as a release and you get credit in the changelog unless you would rather not.

If it affects WordPress.org users at large, please also consider reporting through the [WordPress Plugin Security Team](https://wordpress.org/about/security/) or [Patchstack](https://patchstack.com/database/report), which can coordinate disclosure.

## What is in scope

- Anything letting a user act beyond their capabilities
- SQL injection, XSS, CSRF
- Exposure of attendee personal data to someone who should not see it
- Anything letting an unauthenticated visitor read or change registrations

## What is not

- Missing rate limits beyond the deliberately loose default, which is documented and filterable
- Issues that need an administrator account to exploit — an administrator can already run arbitrary code in WordPress
- Reports from automated scanners with no demonstrated impact

## What this plugin does with your data

It stores the name, email address, phone number and number of places somebody enters when registering for an event. It does not store IP addresses. It sends nothing to any external service. Registration data is covered by WordPress's own privacy export and erasure tools.
