
# Storage Test Project Setup Guide

This guide provides instructions for setting up the environment, database, Apache server, and firewall for the **Storage_test** application.

---

## 📂 1. Directory Structure Setup

Place your project in the appropriate web root directory depending on your Operating System.

* **Linux (LAMPP):** `/opt/lampp/htdocs/Storage_test`
* **Windows (XAMPP):** `C:\xampp\htdocs\Storage_test`

### ⚠️ Required Sub-folder
Inside your `Storage_test` directory, you **must** create a sub-folder named exactly as follows (it is case-sensitive):
* `uploads/`

---

## 🗄️ 2. Database Configuration

Set up your local database with the following structure:

* **Database Name:** `Storage_test`
* **Table Name:** `files`

---

## 🌐 3. Apache Server Configuration

Ensure your Apache configuration is listening on the standard HTTP port.

* **Configuration Directive:** `Listen 80`

---

## 🛡️ 4. Windows Firewall Configuration

If you are hosting on Windows and want other devices to access the application, follow these steps to open **Port 80**:

1. Open **Windows Defender Firewall with Advanced Security**.
2. Click on **Inbound Rules** in the left sidebar, then select **New Rule...** in the right Actions pane.
3. Choose **Port** as the Rule Type and click *Next*.
4. Select **TCP** and enter `80` in the **Specific local ports** input field, then click *Next*.
5. Select **Allow the connection** and click *Next*.
6. Leave **Domain**, **Private**, and **Public** checkboxes checked, then click *Next*.
7. Name the rule **XAMPP Apache Port 80** and click **Finish**.
