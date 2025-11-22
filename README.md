# Cloud-Hosted Wi-Fi Captive Portal with RADIUS Authentication (CYPRUS-RADIUS)

A production-ready system for managing Wi-Fi access using a captive portal, authenticated via FreeRADIUS, and controlled through a web-based admin panel.

Developed and maintained by **localh0ste** to secure, monitor, and manage access in public or enterprise environments.

---

## 🛠️ System Workflow

### 🛡️ 1. User Connects

* User connects to Wi-Fi (open or WPA2 Enterprise)
* DHCP assigns an IP address

### 🌐 2. Captive Portal Login

* Captive Portal served from `/var/www/html/CYRAD/login.php`
* Login Modes:

  * 📜 Guest Registration
  * 🔐 Authenticated Login

### 📶 3. Authentication Backend

* Login credentials POSTed to PHP backend
* `admin/config/db.php` connects to MariaDB
* PHP sends Access-Request to FreeRADIUS
* FreeRADIUS authenticates using SQL module

### 🔓 4. Access Control

* Success:

  * IP/MAC marked authenticated
  * iptables rules allow internet
  * Session/time/data limits applied
* Failure:

  * Access denied with error message

### 📊 5. Admin Panel (`/admin`)

Admins can:

* 👤 Manage users (add/edit/disable)
* 📄 Import/Export users via CSV
* ⏱ Set session data/time limits
* 📊 Track sessions via interim updates
* ⛔ Control internet access (block/unblock)

All actions sync with MariaDB and FreeRADIUS.

### ♻️ 6. Session Monitoring

* Cron runs `simulate_interim.php`
* Sends Interim-Update to FreeRADIUS
* Used for:

  * Session tracking
  * Logging
  * Auto disconnects

---

## 🔐 System Components

| Component  | Purpose                       |
| ---------- | ----------------------------- |
| NGINX      | Serves portal and admin panel |
| PHP-FPM    | Executes backend logic        |
| FreeRADIUS | Authenticates users           |
| MariaDB    | Stores user/session data      |
| iptables   | Controls internet access      |

---

## 🚀 Key Features

* Captive Portal Login (User/Guest)
* SQL-backed FreeRADIUS Auth
* Web-Based Admin Dashboard
* Per-User Session Data/Time Limits
* CSV Import/Export
* Live Session Updates
* Real-Time Access Control
* Active Session Dashboard
* One-Command Auto Setup Script

---

## 📁 Project Directory Structure

```
/var/www/html/
├── CYRAD/                      # Captive Portal
│   ├── login.php               
│   ├── logout.php              
│   ├── guest_registration.php  
│   └── images/                 
│
├── admin/                      # Admin Panel
│   ├── add_user.php
│   ├── dashboard.php
│   ├── users.php
│   ├── simulate_interim.php
│   ├── config/
│   │   └── db.php
│   └── ... (Other scripts)
│
├── index.html
└── info.php
```

---

## ⚙️ Requirements

* Debian 12+ VPS or Local Machine
* NGINX
* PHP 8.2+ with PHP-FPM
* MariaDB or MySQL
* FreeRADIUS 3.x
* iptables

---
## 🛠️ Auto Installation Guide
### ✅ STEP 1: Connect to Your Cloud Server

```bash
ssh -i ~/Downloads/cyrad.pem admin@<your-cloud-ip>
```
### 📦 STEP 2: Upload Project to Cloud
 Option A: If you have a .zip file locally

```bash
scp -i ~/Downloads/cyrad.pem ~/Downloads/CYRAD-main.zip admin@<your-cloud-ip>:~
```
 Option B: Clone directly from GitHub
```bash
git clone https://github.com/localh0ste/CYRAD.git ~/CYRAD-main
```
### 📁 STEP 3: Deploy the Project on Cloud
```bash
cd /var/www/html
sudo rm -rf *
sudo unzip ~/CYRAD-main.zip -d
# OR
# sudo cp -r ~/CYRAD-main/*
```
### 🔒 STEP 4: Set Permissions
```bash
sudo chown -R www-data:www-data /var/www/html/
sudo chmod -R 755 /var/www/html/
```
### ⚙️ STEP 5: Configure Database Connection
```bash
sudo nano /var/www/html/admin/config/db.php
```
For same-cloud MariaDB, use:
```bash
<?php
$conn = new mysqli("localhost", "radius", "buggy", "radius");
?>
```
   ✅ Use localhost if MySQL is on the same cloud server.

### 🚀 STEP 6: Run Setup Script
```bash
cd /var/www/html/admin
chmod +x setup.sh
./setup.sh
```

## 🛠️ Manual Installation Guide

### STEP 1: System Update

```bash
sudo apt update && sudo apt upgrade -y
```

### STEP 2: Install Dependencies

```bash
sudo apt install nginx php php-fpm php-mysql mariadb-server freeradius freeradius-mysql freeradius-utils unzip curl git -y
```

### STEP 3: Enable and Start Services

```bash
sudo systemctl enable nginx php-fpm mariadb freeradius
sudo systemctl start nginx php-fpm mariadb freeradius
```

### STEP 4: Secure MariaDB

```bash
sudo mysql_secure_installation
```

### STEP 5: Create Database and User

```bash
sudo mariadb
```

Then inside MariaDB:

```sql
CREATE DATABASE radius;
GRANT ALL PRIVILEGES ON radius.* TO 'radius'@'localhost' IDENTIFIED BY 'buggy';
FLUSH PRIVILEGES;
EXIT;
```

### STEP 6: Import FreeRADIUS Schema

> ⚠️ If you already have an exported schema file (like `radius.sql` from the `admin/` directory), use that instead of the default FreeRADIUS schema.

```bash
# If using default FreeRADIUS schema
sudo mysql -u root -p radius < /etc/freeradius/3.0/mods-config/sql/main/mysql/schema.sql

# OR if using your exported schema from the admin directory
sudo mysql -u root -p radius < /var/www/html/admin/radius.sql
sudo mysql -u root -p radius < /path/to/your/radius.sql
```

### STEP 7: Enable and Configure FreeRADIUS SQL Module

```bash
sudo ln -s /etc/freeradius/3.0/mods-available/sql /etc/freeradius/3.0/mods-enabled/
sudo nano /etc/freeradius/3.0/mods-enabled/sql
```

Edit values:

```ini
driver = "rlm_sql_mysql"
dialect = "mysql"
server = "localhost"
login = "radius"
password = "buggy"
radius_db = "radius"
```

Edit `/etc/freeradius/3.0/sites-enabled/default` and uncomment `sql` inside:

* authorize
* accounting
* session
* post-auth

---


### Restart Services

```bash
sudo systemctl restart nginx
sudo systemctl restart php8.2-fpm
sudo systemctl restart freeradius
```

---

## 🌐 Access URLs

| Component       | URL                                                             |
|----------------|------------------------------------------------------------------|
| Admin Panel     | [http://<your_cloud_ip>/admin/login.php](http://<your-cloud-ip>/admin/login.php) |
| Captive Portal  | [http://<your_cloud_ip>/CYRAD/login.php](http://<your-cloud-ip>/CYRAD/login.php) |



---

## ⚠️ Security Recommendations

* Change DB and admin default passwords
* Use HTTPS (via Let's Encrypt) in production
* Monitor logs:

  * /var/log/freeradius/radius.log
  * /var/log/nginx/error.log

---

## 🏢 Developed By

**Cyndia Cyberspace LLP**
🔐 Empowering Secure Network Access & Identity Control
