On linux: /opt/lampp/htdocs/Storage_test /br/
On windows: C:\xampp\htdocs\Storage_test

Required Sub Folder in Storage_test: uploads (case sensitve)

Database Setup
Database: Storage_test
Table: files


Apache Setup
Config Listen 80

Window Firewall Setup
Windows Defender Firewall with Advanced Securit ->  Inbound Rules -> New Rule -> Port -> Select TCP -> enter 80 in the Specific local ports box -> Allow the connection -> (Leave Domain, Private, and Public checked, then click Next) -> 1. Name it XAMPP Apache Port 80 and click Finish.
