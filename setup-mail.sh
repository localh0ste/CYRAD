#!/usr/bin/env bash
# Install only SMTP dependencies, encryption key and worker (safe on updates).
set -euo pipefail
if [[ ${1:-} == --help ]]; then
  echo 'Usage: sudo bash setup-mail.sh [--skip-packages]'
  echo 'CYRAD_DEPLOY_DIR sets the installed web root. Key: /etc/cyrad/mail.key.'
  exit 0
fi
[[ $(id -u) == 0 ]] || { echo 'Run as root.' >&2; exit 1; }
[[ $# == 0 || ( $# == 1 && $1 == --skip-packages ) ]] || { echo 'Unknown argument' >&2; exit 2; }
if [[ ${1:-} != --skip-packages ]]; then
  export DEBIAN_FRONTEND=noninteractive
  apt-get update -y
  apt-get install -y libphp-phpmailer cron openssl
fi
deploy=${CYRAD_DEPLOY_DIR:-/var/www/html/cyrad}
[[ $deploy =~ ^/[a-zA-Z0-9_./-]+$ && -f $deploy/admin/send_guest_ticket_emails.php ]] || { echo 'Deploy mail worker first.' >&2; exit 1; }
install -d -o root -g www-data -m 750 /etc/cyrad
if [[ ! -e /etc/cyrad/mail.key ]]; then
  (umask 077; openssl rand -base64 32 > /etc/cyrad/mail.key)
fi
chown root:www-data /etc/cyrad/mail.key
chmod 640 /etc/cyrad/mail.key
cat > /etc/cron.d/cyrad-guest-mail <<CRON
* * * * * www-data /usr/bin/php $deploy/admin/send_guest_ticket_emails.php >> /var/log/cyrad-guest-mail.log 2>&1
CRON
chmod 644 /etc/cron.d/cyrad-guest-mail
touch /var/log/cyrad-guest-mail.log
chown www-data:adm /var/log/cyrad-guest-mail.log
chmod 640 /var/log/cyrad-guest-mail.log
cat > /etc/logrotate.d/cyrad-guest-mail <<'ROTATE'
/var/log/cyrad-guest-mail.log {
    weekly
    rotate 8
    compress
    missingok
    notifempty
    create 0640 www-data adm
}
ROTATE
systemctl enable --now cron
echo 'SMTP dependency, persistent key and guest email cron installed.'
