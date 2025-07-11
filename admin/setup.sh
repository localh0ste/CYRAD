#!/bin/bash

echo -e "\n🚀 Starting CYRAD Full Auto Setup...\n"

# === Step 1: Update & Install Required Packages ===
sudo apt update && sudo apt upgrade -y
sudo apt install -y nginx php php-fpm php-mysql mariadb-server unzip git curl php-cli php-mbstring php-xml php-curl
sudo apt install -y freeradius freeradius-mysql

# === Step 2: Enable & Start Services ===
sudo systemctl enable nginx
sudo systemctl enable php8.2-fpm || sudo systemctl enable php8.4-fpm
sudo systemctl enable mariadb
sudo systemctl enable freeradius

sudo systemctl start nginx
sudo systemctl start php8.2-fpm || sudo systemctl start php8.4-fpm
sudo systemctl start mariadb
sudo systemctl start freeradius

# === Step 3: Setup MariaDB & Create radius DB ===
echo -e "\n🛠️ Configuring MariaDB..."

DB_USER="radius"
DB_PASS="buggy"
DB_NAME="radius"

sudo mysql -u root <<EOF
CREATE DATABASE IF NOT EXISTS $DB_NAME;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON $DB_NAME.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
EOF

echo "✅ Database '$DB_NAME' and user '$DB_USER' setup completed."

# === Step 4: Import SQL File ===
if [ -f "/var/www/html/admin/radius.sql" ]; then
    echo "📥 Importing radius.sql..."
    sudo mysql -u root $DB_NAME < /var/www/html/admin/radius.sql
    echo "✅ radius.sql imported successfully."
else
    echo "⚠️ radius.sql not found in /var/www/html/admin. Skipping import!"
fi

# === Step 5: Set Correct Permissions ===
sudo chown -R www-data:www-data /var/www/html
sudo chmod -R 755 /var/www/html

# === Step 6: Show Access Info ===
echo -e "\n🎉 Setup Completed!"
echo "🔑 Admin Login Page: http://<localhost>/admin/login.php"
echo "🧑 Default Username: admin"
echo "🔐 Default Password: cyndia"