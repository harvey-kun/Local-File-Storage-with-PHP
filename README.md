# Storage Test Project Setup Guide

This guide provides instructions for setting up the network, environment, database, Apache server, and firewall for the **Storage_test** application.

---

## 📶 1. Network Configuration (Wireless)

To ensure your wireless router always directs incoming traffic to the correct computer, you must assign a static local IP address to your Windows device over Wi-Fi.

### Step A: Find Your Current Network Details
1. Press `Win + R`, type `cmd`, and press Enter to open the Command Prompt.
2. Type `ipconfig` and press Enter.
3. Scroll to the **Wireless LAN adapter Wi-Fi** section and write down the following:
   * **IPv4 Address** (e.g., `192.168.1.50`)
   * **Subnet Mask** (e.g., `255.255.255.0`)
   * **Default Gateway** (e.g., `192.168.1.1`)

### Step B: Assign the Static IP
1. Open Windows **Settings** and navigate to **Network & Internet > Wi-Fi**.
2. Click on **Hardware properties** (or your connected Wi-Fi network name).
3. Next to **IP assignment**, click the **Edit** button.
4. Change the dropdown from *Automatic (DHCP)* to **Manual** and toggle on **IPv4**.
5. Enter your preferred static IP address (use your current IPv4 address from Step A to avoid conflicts).
6. Fill in the **Subnet prefix length** (usually `24` for a `255.255.255.0` mask), your **Default Gateway**, and your preferred **DNS** (e.g., `8.8.8.8`).
7. Click **Save**.

---

## 📂 2. Directory Structure Setup

Place your project in the appropriate web root directory depending on your Operating System.

* **Linux (LAMPP):** `/opt/lampp/htdocs/Storage_test`
* **Windows (XAMPP):** `C:\xampp\htdocs\Storage_test`

### ⚠️ Required Sub-folder
Inside your `Storage_test` directory, you **must** create a sub-folder named exactly as follows (it is case-sensitive):
* `uploads/`

---

## 🗄️ 3. Database Configuration

Set up your local database with the following structure:

* **Database Name:** `Storage_test`
* **Table Name:** `files`

---

## 🌐 4. Apache Server Configuration

Ensure your Apache configuration is listening on the standard HTTP port.

* **Configuration Directive:** `Listen 80`

---

## 🛡️ 5. Windows Firewall Configuration

If you are hosting on Windows and want other devices to access the application, follow these steps to open **Port 80**:

1. Open **Windows Defender Firewall with Advanced Security**.
2. Click on **Inbound Rules** in the left sidebar, then select **New Rule...** in the right Actions pane.
3. Choose **Port** as the Rule Type and click *Next*.
4. Select **TCP** and enter `80` in the **Specific local ports** input field, then click *Next*.
5. Select **Allow the connection** and click *Next*.
6. Leave **Domain**, **Private**, and **Public** checkboxes checked, then click *Next*.
7. Name the rule **XAMPP Apache Port 80** and click **Finish**.
