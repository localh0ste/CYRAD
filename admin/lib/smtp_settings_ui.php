<?php
/**
 * Shared "Email / SMTP settings" card and POST handler for ONE organization.
 *
 * Used by the super-admin Edit Organization page and by the org-admin Guest
 * Tickets page, so either scope configures the same organization_smtp row.
 * Requires a logged-in session and an open mysqli connection.
 */
require_once __DIR__ . '/guest_mail.php';

if (!function_exists('smtp_settings_handle')) {
    /**
     * Handle a smtp_action=save|test POST.
     * @return array{0: array, 1: bool, 2: string} [config, handled, html message]
     */
    function smtp_settings_handle(mysqli $db, int $org, array $smtp, bool $csrf_ok, string $org_name): array {
        $action = is_string($_POST['smtp_action'] ?? null) ? $_POST['smtp_action'] : '';
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $action === '') return [$smtp, false, ''];
        try {
            if (!$csrf_ok) throw new RuntimeException('Invalid form token. Reload and try again.');
            if ($action === 'save') {
                $smtp = guest_mail_validate($_POST['smtp'] ?? [], $smtp);
                guest_mail_save($db, $org, $smtp);
                return [$smtp, true, "<div class='msg ok'>SMTP settings saved.</div>"];
            }
            if ($action === 'test') {
                if (!$smtp['enabled']) throw new RuntimeException('Save and enable SMTP before sending a test.');
                if (time() - (int)($_SESSION['smtp_test_at'] ?? 0) < 30) throw new RuntimeException('Wait 30 seconds between test emails.');
                $recipient = guest_mail_email((string)($_POST['test_email'] ?? ''));
                $_SESSION['smtp_test_at'] = time();
                guest_mail_send($smtp, $recipient, guest_mail_render($smtp, [
                    'organization_name' => $org_name, 'username' => 'sample_guest',
                    'password' => 'SAMPLE-NOT-VALID', 'expires_at' => 'Sample only', 'data_limit' => 'Unlimited',
                ]));
                return [$smtp, true, "<div class='msg ok'>Test email accepted by the SMTP server. Check the recipient inbox and spam folder.</div>"];
            }
            return [$smtp, false, ''];
        } catch (Throwable $e) {
            return [$smtp, true, "<div class='msg err'>" . htmlspecialchars($e->getMessage()) . "</div>"];
        }
    }
}

if (!function_exists('smtp_settings_form')) {
    /** Render the collapsible settings form. $csrf must be the session token. */
    function smtp_settings_form(array $smtp, string $org_name, string $csrf): string {
        $preview = guest_mail_render($smtp, [
            'organization_name' => $org_name, 'username' => 'sample_guest',
            'password' => 'SAMPLE-NOT-VALID', 'expires_at' => gmdate('Y-m-d H:i:s', time() + 86400), 'data_limit' => 'Unlimited',
        ])[1];
        ob_start(); ?>
        <details <?= $smtp['enabled'] ? '' : 'open' ?>>
          <summary style="cursor:pointer"><b>Email / SMTP settings</b> —
            <?= $smtp['enabled'] ? '<span class="pill on">enabled</span>' : '<span class="pill off">disabled</span>' ?>
          </summary>
          <p style="color:var(--muted);font-size:0.85rem;margin-top:10px">
            Guest tickets can email credentials when an address is supplied at mint time.
            These settings apply only to <b><?= htmlspecialchars($org_name) ?></b>. Save before sending a test.
          </p>
          <form method="POST" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="smtp_action" value="save">
            <label><input type="checkbox" name="smtp[enabled]" value="1" <?= $smtp['enabled'] ? 'checked' : '' ?>> Enable guest email</label>
            <div style="display:flex;gap:14px;flex-wrap:wrap">
              <div style="flex:2;min-width:220px"><label>SMTP host</label>
                <input name="smtp[host]" maxlength="255" value="<?= htmlspecialchars($smtp['host']) ?>" placeholder="smtp.example.com"></div>
              <div style="flex:1;min-width:110px"><label>Port</label>
                <input name="smtp[port]" type="number" min="1" max="65535" required value="<?= (int)$smtp['port'] ?>"></div>
              <div style="flex:2;min-width:200px"><label>Encryption</label>
                <select name="smtp[encryption]">
                  <?php foreach (['tls' => 'STARTTLS (usually 587)', 'ssl' => 'Implicit TLS (usually 465)', 'none' => 'None (no-auth trusted relay only)'] as $value => $label): ?>
                    <option value="<?= $value ?>" <?= $smtp['encryption'] === $value ? 'selected' : '' ?>><?= $label ?></option>
                  <?php endforeach; ?>
                </select></div>
            </div>
            <label><input name="smtp[auth_enabled]" type="checkbox" value="1" <?= $smtp['auth_enabled'] ? 'checked' : '' ?>> SMTP authentication</label>
            <div style="display:flex;gap:14px;flex-wrap:wrap">
              <div style="flex:1;min-width:200px"><label>SMTP username</label>
                <input name="smtp[username]" maxlength="255" value="<?= htmlspecialchars($smtp['username']) ?>"></div>
              <div style="flex:1;min-width:200px"><label>SMTP password / app password</label>
                <input name="smtp[password]" type="password" autocomplete="new-password"
                       placeholder="<?= $smtp['password_enc'] !== '' ? 'Saved — leave blank to keep' : 'Not configured' ?>"></div>
            </div>
            <label><input type="checkbox" name="smtp[clear_password]" value="1"> Clear saved password</label>
            <div style="display:flex;gap:14px;flex-wrap:wrap">
              <div style="flex:1;min-width:200px"><label>From email address</label>
                <input name="smtp[from_address]" type="email" maxlength="254" value="<?= htmlspecialchars($smtp['from_address']) ?>"></div>
              <div style="flex:1;min-width:160px"><label>From display name</label>
                <input name="smtp[from_name]" maxlength="100" value="<?= htmlspecialchars($smtp['from_name']) ?>"></div>
              <div style="flex:1;min-width:200px"><label>Reply-to (optional)</label>
                <input name="smtp[reply_to]" type="email" maxlength="254" value="<?= htmlspecialchars($smtp['reply_to']) ?>"></div>
            </div>
            <label>Email subject</label>
            <input name="smtp[subject]" maxlength="255" required value="<?= htmlspecialchars($smtp['subject']) ?>">
            <label>Email template (plain text; HTML is auto-escaped)</label>
            <textarea name="smtp[template]" rows="8" maxlength="20000" required><?= htmlspecialchars($smtp['template']) ?></textarea>
            <p style="color:var(--muted);font-size:0.82rem">Placeholders:
              <code>{{organization_name}}</code> <code>{{username}}</code> <code>{{password}}</code>
              <code>{{expires_at}}</code> <code>{{data_limit}}</code></p>
            <button type="submit" class="btn"><i class="fas fa-floppy-disk"></i> Save SMTP settings</button>
          </form>
          <details style="margin:14px 0"><summary>Preview saved template (sample ticket)</summary>
            <pre style="white-space:pre-wrap"><?= htmlspecialchars($preview) ?></pre></details>
          <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="smtp_action" value="test">
            <label>Test recipient</label>
            <div style="display:flex;gap:10px;flex-wrap:wrap">
              <input name="test_email" type="email" required placeholder="you@example.com" style="flex:1;min-width:220px">
              <button class="btn ghost" type="submit"><i class="fas fa-paper-plane"></i> Send test email</button>
            </div>
          </form>
        </details>
        <?php return (string)ob_get_clean();
    }
}
