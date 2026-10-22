# Threat model: Grav Form plugin

The scanner reads this file before it starts. It covers the Grav Form plugin (`getgrav/grav-plugin-form`). Severity follows Grav's own policy, [SECURITY.md](https://github.com/getgrav/grav/blob/develop/SECURITY.md) in core, which rates an issue by **whether it crosses a trust boundary**, not by what a CVSS calculator says.

## What this project does and where untrusted input enters

The Form plugin renders and processes front-end forms that site authors define in page frontmatter (`form:` or `forms:`): contact forms, sign-ups, surveys, file uploads. A submission runs the form's `process:` actions in order: `upload`, `save` (write the data to a file under `user/data`), `email` (sent by the Email plugin), `message`, `redirect`, `display`, `reset`, captcha checks and others. The Login plugin's registration and profile forms also run through it.

Forms are public by design, so treat everything a visitor sends as untrusted: field values (`data[...]`), the hidden fields (`__form-name__`, `__unique_form_id__`, `form-nonce`), file uploads and their names, the AJAX endpoints on `.json` URLs (`task=file-upload`, `file-remove`, `store-state`, `clear-state`), `task=get-nonce` and `task=keep-alive`, the captcha endpoints (`/forms-basic-captcha-image.jpg`, `/forms-cap/challenge`, `/forms-cap/redeem`), and the captcha tokens themselves.

The form **definition** (fields, validation rules, process actions and their Twig templates) is written by a site publisher or admin and is trusted within that author's scope.

### Where the code is

| Path | What it is |
| --- | --- |
| `/src` | The plugin checkout (`develop`). Patches belong here. |
| `/src/form.php` | Form discovery, submission routing, the `.json` task dispatcher, and every `process:` action handler (`onFormProcessed`). |
| `/src/classes/Form.php` | Form state, validation, AJAX file upload (`uploadFiles`), file copy on submit, the JSON responses. |
| `/src/classes/Captcha/` | reCAPTCHA, Turnstile, basic captcha and Cap providers. |
| `/src/templates/` | Form, field and submitted-data templates (`forms/data.html.twig`, `data.txt.twig`, `data.save.txt.twig`). |
| `/src/app/`, `/src/assets/` | First-party front-end JavaScript (built bundles plus standalone handlers). |
| `/var/www/grav` | A working Grav site built from the latest `grav-admin` release, served by Apache with `.htaccess` enabled. |
| `/var/www/grav/user/plugins/form` | A symlink to `/src`, so the site runs this checkout and edits are live immediately. |
| `/var/www/grav/user/pages/90.contact/form.md` | An anonymous contact form at `/contact` with a multi-file upload field (`destination: self@`, images and PDFs), `upload`, `email`, `save`, `message` and `display` actions. |

Grav core, the Email plugin and the other bundled plugins are in the site but live in their own repositories. If a bug is in their code, say which repository owns it.

### Trust model

- **Anonymous visitors** are untrusted. Their submitted values must never be evaluated as Twig or PHP, must be escaped wherever they are rendered as HTML (the thank-you page, emails, the admin's view of saved data), and must not steer where files are written or which form, page or action runs.
- **Uploads** from visitors must stay within the form field's `accept`, size and destination rules, must not land anywhere executable or outside the configured destination, and must not overwrite existing files. Grav core sanitizes SVG uploads; a path that skips that is in scope.
- **Logged-in members** must not reach forms on pages their access does not allow (`getForm()` looks forms up across pages by name).
- **Publishers and admins** who write form definitions are trusted within their role. Twig they put in a `message`, `redirect` or `save` template runs as them; that is the feature. A visitor's value turning into Twig inside such a template is not.
- **The operator** is trusted: `user/config/**`, the environment and the filesystem.

## Components that matter most / least

Highest priority:

1. File uploads: `Form::uploadFiles()` and the copy on submit, filename handling, the extension denylist and `accept` matching, destination resolution (`self@`, `page@:`, `theme@:`, streams), the per-session temporary storage keyed by `__unique_form_id__`, and file removal.
2. Template injection: submitted values reaching `Twig::processString()` through the `message`, `redirect`, `save` (filename and body) and `email` actions, or any template rendered with autoescaping off.
3. The `save` action: path traversal or file-type control through the filename template, folder, extension or submitted data; writing outside `user/data`.
4. Nonce and CSRF handling on submissions and the `.json` tasks.
5. Stored XSS: submitted data shown later to an admin (saved files viewed in Admin2, email bodies) or reflected back on the page.
6. Captcha bypasses that let automated submissions through a form that requires one.
7. Open redirects through the `redirect` action.
8. Cross-page form lookup and its access check.

Lower priority or out of scope:

- `vendor/` (google/recaptcha, trilbymedia/cap-php) and the vendored front-end libraries in `assets/` (FilePond, Dropzone, signature_pad and similar). Report upstream unless this plugin uses them unsafely.
- `test-captcha.php` (a local developer script that the web-server rules block).
- Grav core, the Email plugin and the other plugins in `/var/www/grav`.

## How to exercise it

- Run `grav-start` first (there is no init system). The site is at `http://127.0.0.1/`, the contact form at `/contact`. `grav-stop` stops Apache. Grav's log is `/var/www/grav/logs/grav.log`.
- Submitting from the command line: fetch `/contact` with a cookie jar, read the `form-nonce` and `__unique_form_id__` hidden fields, then POST `data[name]`, `data[email]`, `data[message]`, `__form-name__=contact`, `__unique_form_id__` and `form-nonce` back to `/contact` with the same jar. AJAX uploads are a multipart POST to `/contact.json` with the same cookie jar and `task=file-upload`, `name=attachments`, `__form-name__=contact`, `__unique_form_id__`, `form-nonce` and the file as `data[attachments]`. Pending uploads wait in `/var/www/grav/tmp/forms/<session>/<unique id>/` until the form is submitted.
- Saved submissions land in `/var/www/grav/user/data/contact/`, uploaded files in the page folder `/var/www/grav/user/pages/90.contact/`, and every email the site sends in `/var/mail-catcher/*.eml`.
- Add more test pages under `/var/www/grav/user/pages/` (a folder with a `form.md`) to try other field types and actions; the report should then say which account could have written that definition.
- Accounts: `admin` / `Admin-pass-1` (super admin), `editor` / `Editor-pass-1` (page and media editing through the API), `member` / `Member-pass-1` (front-end login only), in `/var/www/grav/user/accounts/`.
- Run Grav CLI commands as the web server user with `grav-cli`, for example `grav-cli bin/grav clear`.
- The plugin has no automated test suite.

## How you rate severity

| Severity | When to use |
| --- | --- |
| **Critical** | An **unauthenticated** attacker gets remote code execution (for example by uploading a file the server will execute, or getting a submitted value evaluated as Twig), reads site data that is not public, or writes files outside the form's configured destination. No Grav account needed. |
| **High** | A **cross-trust-boundary** issue: an anonymous submission that runs script in an admin's session (stored XSS through saved data or email), or a member reaching a form on a page they cannot access and acting through it. |
| **Moderate** | An authenticated user does something **outside the documented scope of their role** with impact limited to their own session or tier, or an anonymous visitor bypasses a captcha or nonce on a form with no further impact. |
| **Low** | An admin or publisher doing something within the capabilities they already hold. Grav does not publish advisories for these; report them only as hardening. |

Rate by the starting access and the boundary crossed, not by the CVSS calculator, which assumes `PR:N` far too often.

## Anything to leave alone

These are closed without an advisory under Grav's policy, so please do not report them as vulnerabilities:

- Twig, HTML or callables that a **form author** writes into the form definition running as that author.
- Bypasses of core's `Security::detectXss()` denylist. Values that render **unescaped at an output sink** are in scope; show the sink.
- Plain-text saved data (`.txt` output) containing whatever the visitor typed; it is a text file, not HTML.
- Self-XSS, or anything that needs the victim to paste a payload into their own browser.
- Missing hardening with no demonstrated exploit: absent security headers, missing rate limits on submissions, spam that gets through a form with no captcha configured.
- Dependency CVEs without a reachable call path from this plugin.

## How we would like reports

Each report should state the access the attacker starts with, what they end up with, and which trust boundary is crossed. Include a reproducer that runs against this image (curl commands with a cookie jar are ideal) and a patch against `/src`. If the root cause is in Grav core, the Email plugin or another plugin, name that repository.
