# Grav Form plugin (getgrav/grav-plugin-form), for Anthropic's OSS Scanner (https://github.com/anthropics/oss-scanner).
#
# /src                            the plugin checkout (develop)
# /var/www/grav                   a Grav site built from the latest "grav-admin" download (core plus api, admin2,
#                                 login, form, email and flex-objects), the way most sites start
# /var/www/grav/user/plugins/form a symlink to /src, so the site runs this checkout and a patch made in /src is live on
#                                 the next request
#
# The site has an anonymous contact form at /contact (user/pages/90.contact/form.md) that uses the common process
# actions: a file upload field, save, email, message and display. Every email the site sends lands in
# /var/mail-catcher, and saved submissions in /var/www/grav/user/data/contact. Apache serves the site with .htaccess
# enabled. Start it with `grav-start`; the site listens on http://127.0.0.1/.
FROM php:8.4-apache

ENV DEBIAN_FRONTEND=noninteractive \
    COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1 \
    COMPOSER_MEMORY_LIMIT=-1

RUN apt-get update && apt-get install -y --no-install-recommends \
      ca-certificates git unzip curl procps \
      libfreetype6-dev libjpeg62-turbo-dev libpng-dev libwebp-dev libzip-dev libicu-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" gd zip intl exif \
    && pecl install apcu && docker-php-ext-enable apcu \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# Production-like PHP: errors are logged, not shown. OPcache picks up edited files immediately so a patch can be
# checked straight away.
RUN { echo 'memory_limit=256M'; \
      echo 'upload_max_filesize=64M'; \
      echo 'post_max_size=64M'; \
      echo 'error_reporting=E_ALL'; \
      echo 'display_errors=Off'; \
      echo 'log_errors=On'; \
      echo 'error_log=/dev/stderr'; \
      echo 'opcache.validate_timestamps=1'; \
      echo 'opcache.revalidate_freq=0'; \
    } > /usr/local/etc/php/conf.d/oss-scanner.ini

RUN a2enmod rewrite headers expires \
    && sed -ri 's!/var/www/html!/var/www/grav!g' /etc/apache2/sites-available/000-default.conf \
    && printf '<Directory /var/www/grav>\n  Options -MultiViews +FollowSymLinks\n  AllowOverride All\n  Require all granted\n</Directory>\nServerName 127.0.0.1\n' \
         > /etc/apache2/conf-available/grav.conf \
    && a2enconf grav

# grav-start / grav-stop start and stop Apache (there is no init system in the container). grav-cli runs a Grav CLI
# command in the site as www-data, the user Apache runs as:  grav-cli bin/grav clear
# mail-catcher stands in for sendmail: every email the site sends is written to /var/mail-catcher/<timestamp>.eml.
RUN printf '%s\n' '#!/bin/sh' 'pgrep -x apache2 >/dev/null || apachectl start' > /usr/local/bin/grav-start \
    && printf '%s\n' '#!/bin/sh' 'apachectl stop 2>/dev/null; true' > /usr/local/bin/grav-stop \
    && printf '%s\n' '#!/bin/sh' 'cd /var/www/grav && exec setpriv --reuid=www-data --regid=www-data --init-groups env HOME=/tmp php "$@"' \
         > /usr/local/bin/grav-cli \
    && printf '%s\n' '#!/bin/sh' 'cat > "/var/mail-catcher/$(date +%s%N).eml"' > /usr/local/bin/mail-catcher \
    && mkdir -p /var/mail-catcher && chmod 1777 /var/mail-catcher \
    && chmod +x /usr/local/bin/grav-start /usr/local/bin/grav-stop /usr/local/bin/grav-cli /usr/local/bin/mail-catcher

# scanner contract: the plugin checkout lives inside the image, at /src
COPY . /src
WORKDIR /src

# The site, from the current grav-admin release, with its bundled form plugin swapped for this checkout. The plugin
# commits its runtime vendor/, so nothing is installed into /src.
RUN curl -fsSL -o /tmp/grav-admin.zip https://getgrav.org/download/core/grav-admin/latest \
    && unzip -q /tmp/grav-admin.zip -d /opt \
    && mv /opt/grav-admin /var/www/grav \
    && rm /tmp/grav-admin.zip \
    && rm -rf /var/www/grav/user/plugins/form \
    && ln -s /src /var/www/grav/user/plugins/form \
    && mkdir -p /var/www/grav/user/config/plugins \
    && printf '%s\n' 'enabled: true' 'from: site@example.com' 'to: admin@example.com' 'mailer:' '  engine: sendmail' \
         '  sendmail:' "    bin: '/usr/local/bin/mail-catcher -t'" > /var/www/grav/user/config/plugins/email.yaml

# The contact form, written the way the Grav docs show it.
RUN mkdir -p /var/www/grav/user/pages/90.contact/thankyou \
    && printf '%s\n' \
      '---' \
      'title: Contact' \
      'form:' \
      '  name: contact' \
      '  fields:' \
      '    name:' \
      '      label: Name' \
      '      type: text' \
      '      validate:' \
      '        required: true' \
      '    email:' \
      '      label: Email' \
      '      type: email' \
      '      validate:' \
      '        required: true' \
      '    message:' \
      '      label: Message' \
      '      type: textarea' \
      '    attachments:' \
      '      label: Attachments' \
      '      type: file' \
      '      multiple: true' \
      '      destination: self@' \
      '      accept:' \
      '        - image/*' \
      '        - application/pdf' \
      '    honeypot:' \
      '      type: honeypot' \
      '  buttons:' \
      '    submit:' \
      '      type: submit' \
      '      value: Send' \
      '  process:' \
      '    upload: true' \
      '    email:' \
      "      subject: '[Contact] {{ form.value.name|e }}'" \
      "      body: '{% include \"forms/data.html.twig\" %}'" \
      '    save:' \
      '      fileprefix: contact-' \
      '      dateformat: Ymd-His-u' \
      '      extension: txt' \
      "      body: '{% include \"forms/data.txt.twig\" %}'" \
      '    message: Thank you for getting in touch!' \
      '    display: thankyou' \
      '---' \
      '' \
      '# Contact us' \
      > /var/www/grav/user/pages/90.contact/form.md \
    && printf '%s\n' '---' 'title: Thank you' 'cache_enable: false' 'process:' '  twig: true' '---' '' \
         '## Thank you' > /var/www/grav/user/pages/90.contact/thankyou/formdata.md

# Three accounts for access-control reproductions:
#   admin  / Admin-pass-1    super user: admin2 and the API with every permission
#   editor / Editor-pass-1   publisher: API access with pages and media read/write only, no config, users or system
#   member / Member-pass-1   front-end login only, no admin or API access
RUN php -r ' \
      $accounts = [ \
        "admin"  => ["Admin-pass-1",  "Site Admin",  "access:\n  admin:\n    login: true\n    super: true\n  api:\n    access: true\n    super: true\n  site:\n    login: true\n"], \
        "editor" => ["Editor-pass-1", "Page Editor", "access:\n  api:\n    access: true\n    pages:\n      read: true\n      write: true\n    media:\n      read: true\n      write: true\n  site:\n    login: true\n"], \
        "member" => ["Member-pass-1", "Site Member", "access:\n  site:\n    login: true\n"], \
      ]; \
      foreach ($accounts as $user => [$password, $name, $access]) { \
        file_put_contents("/var/www/grav/user/accounts/$user.yaml", \
          "state: enabled\nemail: $user@example.com\nfullname: \x27$name\x27\nlanguage: en\n" . $access \
          . "hashed_password: " . password_hash($password, PASSWORD_DEFAULT) . "\n"); \
      }' \
    && chown -R www-data:www-data /var/www/grav /src

# Smoke-test the contact form end to end (nonce, submission, saved data and email) and one deny rule. The plugin has no
# automated test suite.
RUN grav-start \
    && curl -fsS -o /dev/null http://127.0.0.1/ \
    && curl -fsS -c /tmp/jar -o /tmp/contact.html http://127.0.0.1/contact \
    && nonce="$(sed -n 's/.*name="form-nonce" value="\([^"]*\)".*/\1/p' /tmp/contact.html | head -n1)" \
    && uid="$(sed -n 's/.*name="__unique_form_id__" value="\([^"]*\)".*/\1/p' /tmp/contact.html | head -n1)" \
    && test -n "$nonce" \
    && curl -fsS -b /tmp/jar -o /dev/null \
         --data-urlencode 'data[name]=Smoke Test' --data-urlencode 'data[email]=smoke@example.com' \
         --data-urlencode 'data[message]=Hello' --data-urlencode '__form-name__=contact' \
         --data-urlencode "__unique_form_id__=$uid" --data-urlencode "form-nonce=$nonce" \
         http://127.0.0.1/contact \
    && ls /var/www/grav/user/data/contact/contact-*.txt \
    && ls /var/mail-catcher/*.eml \
    && test "$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1/user/accounts/admin.yaml)" = 403 \
    && grav-stop \
    && rm -rf /tmp/jar /tmp/contact.html /var/www/grav/user/data/contact /var/mail-catcher/*.eml

# /src belongs to www-data so the site can write through it; let root's git work in it too. Keep build products out
# of the checkout's `git status`.
RUN git config --system --add safe.directory '*' \
    && (printf '.oss-scanner/\n' >> /src/.git/info/exclude || true)

WORKDIR /src
