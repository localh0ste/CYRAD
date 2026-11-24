-- v6: super-admin Telegram OTP support (forgot-password flow)
-- Adds the super-admin's own Telegram chat id so OTP codes can be delivered.
ALTER TABLE admin_users ADD COLUMN IF NOT EXISTS telegram_uid VARCHAR(64) NOT NULL DEFAULT '' AFTER position;
