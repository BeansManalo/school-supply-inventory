# School Supplies

Basic inventory system for school supplies: products, stock in/out, and inventory reports.
HTML/CSS/JS/PHP with a MariaDB database (XAMPP).

## First-time setup (XAMPP)
Follow these steps in order, starting with XAMPP completely off (nothing running). XAMPP installs MariaDB open to anyone, with no password, so steps 2 to 5 lock it down *before* any data goes in. Don't skip or reorder them.

Below, **`<XAMPP>`** is the folder XAMPP is installed in (usually `C:\xampp`). Anything in `<angle brackets>`, and anything called an example, is a placeholder: swap in your own value, don't type it literally.

**Three passwords, three different jobs**

| Password | Who sets it | What it is for |
|---|---|---|
| MariaDB `root` | You, in step 4 | You only: the installer, phpMyAdmin and the commands below. The app never uses it. |
| The app's two database accounts | `install.php`, at random, stored in `config.local.php` | The app only. You never type them. |
| The app's `admin` login | Starts as `1234`; you replace it in step 8 | Signing in to the app. |

For the passwords you choose, use letters, numbers and dashes only (example: `maple-orbit-quartz-lantern-42`); quotes, `%`, `^` and backslashes break the commands below. Keep them in a password manager and never paste them into chat. Use a different one for each row.

### 1. Put the files in place
Unzip so that `<XAMPP>\htdocs\school-supplies\install.php` exists.
Coming from the older file-based version with data in it? First start Apache, open that version, press **Manager > Save**, keep the `.scinvent` file, then stop Apache. You load it back in step 10.

### 2. Keep MySQL off the network
In the XAMPP Control Panel, click **Config** on the MySQL row and choose `my.ini`. Under `[mysqld]` add this line (if a `bind-address` line is already there, change it instead), then save:
```
bind-address=127.0.0.1
```

### 3. Start MySQL only
Click **Start** on the MySQL row. Leave Apache off for now. If Windows Firewall asks about `mysqld`, click **Cancel** (allow no network).
Then click **Shell** and run `netstat -an | findstr 3306`. You want `127.0.0.1:3306` with `LISTENING`. If you see `0.0.0.0:3306`, step 2 did not apply: stop MySQL, recheck `my.ini`, start it again.

### 4. Give `root` a password
In the Shell run `mysql -u root` (there is no password yet), then:
```sql
SELECT user, host FROM mysql.user;
```
For **every row whose user is `root`**, run one line with *your* password, the same on every row. There are usually three rows, so usually:
```sql
ALTER USER 'root'@'localhost' IDENTIFIED BY 'maple-orbit-quartz-lantern-42';
ALTER USER 'root'@'127.0.0.1' IDENTIFIED BY 'maple-orbit-quartz-lantern-42';
ALTER USER 'root'@'::1' IDENTIFIED BY 'maple-orbit-quartz-lantern-42';
```
(`maple-orbit-...` is only an example, make up your own; keep the single quotes.) For every row with an **empty user name**, run `DROP USER ''@'<that host>';`. Leave a `pma` row alone. Type `exit`.

Check it worked: both `mysql -u root` and `mysql -u root -h 127.0.0.1` must now answer `Access denied`. The second one matters most, because the app connects over `127.0.0.1`; if it still gets in, a `root` row was missed.

### 5. Make phpMyAdmin ask for a login
By default phpMyAdmin signs in as root by itself, so anyone who can open the page would have full control. Open `<XAMPP>\phpMyAdmin\config.inc.php` (copy it somewhere as a backup first; if it won't save, open your editor as administrator).
Make a secret for the login cookie: in the Shell run `php -r "echo bin2hex(random_bytes(16));"` (if `php` is not recognised, use `<XAMPP>\php\php.exe` instead). It prints 32 characters. Then set these three lines, keeping the quotes and semicolons exactly:
```php
$cfg['blowfish_secret'] = 'paste the 32 characters here';
$cfg['Servers'][$i]['auth_type'] = 'cookie';
$cfg['Servers'][$i]['AllowNoPassword'] = false;
```
Leave the `user` and `password` lines as they are, and never put the root password in this file.

### 6. Create the databases
In the Shell:
```
cd htdocs\school-supplies
php install.php
```
(`php install.php 3307` if MySQL is on port 3307 rather than 3306; if `php` is not recognised use `<XAMPP>\php\php.exe install.php`.) Enter the root password from step 4; it shows as you type, so run `cls` afterwards. A good run ends with:
```
Created the super admin account: admin / 1234. Replace that password (README).
Done. config.local.php is written; open the app and sign in.
```
It creates the `sc_inventory` and `sc_accounts` databases, one limited account for each with a random password, the `admin` user, and `config.local.php`. It refuses to run while root has no password, and prints `Setup stopped: ...` if anything else is wrong (see Troubleshooting). It is safe to run again: all data stays, only the two random passwords are renewed.

### 7. Start Apache and check the secrets are hidden
Click **Start** on the Apache row. If the firewall asks, click **Cancel**: the site then answers only on this PC, which is what you want until step 8 is done.
In a browser, `http://localhost/school-supplies/config.local.php` and `http://localhost/school-supplies/database/inventory.sql` must both say **Forbidden**. If either shows text or a blank page, stop: the `.htaccess` in this folder is not being applied (in `<XAMPP>\apache\conf\httpd.conf` the `htdocs` folder needs `AllowOverride All`, which is XAMPP's default).
Then open `http://localhost/school-supplies/`: you get the sign-in page.

### 8. Replace the admin password
Sign in once as `admin` / `1234` to see that everything works. Then, because there is no change-password page yet, do it by hand. Choose a new password (not the root one), and in the Shell make its hash:
```
php -r "echo password_hash('your new password', PASSWORD_DEFAULT);"
```
It prints one line starting with `$2y$`, 60 characters long (a new one every time, which is normal). Select it with the mouse and press Enter to copy, without the prompt after it. Then run `mysql -u root -p`, enter the root password, and run (hash pasted between the quotes):
```sql
UPDATE sc_accounts.users SET password_hash = '<paste the whole hash>' WHERE username = 'admin';
```
You should see `Rows matched: 1  Changed: 1`. Type `exit`, press **Sign out** in the app, check that `1234` is now refused and the new password works. (Root is needed because the app's own database account can read password hashes but is deliberately not allowed to change them.)
Only after this should the site be reachable from other computers.

### 9. Look at the databases (optional)
With Apache and MySQL running, open `http://localhost/phpmyadmin`. You get a login form: username `root`, and the root password. `sc_inventory` and `sc_accounts` are in the left list; click a table, then **Browse**. The app's own accounts and the `admin` login do not work here.
Look, don't edit: a hand-edit that breaks a rule (for example stock below zero) makes the app refuse to load with "Invalid save data". Use the app, or take a **Manager > Save** first.

### 10. Restore your old data (if you had some)
Sign in, open **Manager > Load from file** and pick the `.scinvent` file from step 1.

### 11. Try it
Add a product in a new category; record a stock-in, then a stock-out bigger than the stock (it must be refused); delete the product; try **Manager > Save** and **Load**.

### Every day
Start MySQL, then Apache. When you are done, press **Stop** on both before shutting Windows down: a MySQL that is cut off can leave a system table marked as crashed (see below).

### Troubleshooting
- **`install.php`: "root account has no password"**: step 4 missed a `root` row, usually the `127.0.0.1` one.
- **`install.php`: "Could not sign in as root"**: wrong password, MySQL is not running, or it is on another port (add the port after `install.php`).
- **"Table '...' is marked as crashed" (for example `tables_priv`)**: MariaDB was not shut down cleanly. In the Shell run `mysqlcheck -u root -p --auto-repair --databases mysql`, then `install.php` again. If that is not enough: stop MySQL, run `aria_chk -r "<XAMPP>\mysql\data\mysql\tables_priv"` (or `myisamchk -r` if the table's files end in `.MYI`/`.MYD`), start MySQL, and run `install.php` again.
- **Browser: "config.local.php is missing"**: step 6 did not finish.
- **Browser: "The database is not available, or something went wrong"**: MySQL is stopped, or look at `<XAMPP>\php\logs\php_error_log` and `<XAMPP>\apache\logs\error.log` for the real message (the page never shows it on purpose).
- **Account locked** (5 wrong passwords in a row, 15 minutes): wait, or as root run `UPDATE sc_accounts.users SET failed_attempts = 0, locked_until = NULL WHERE username = 'admin';`
- **phpMyAdmin opens without a login form**: `auth_type` in step 5 did not save. **"Access denied for user 'root'"**: wrong password, or one `root` row still has the old one (repeat the two checks in step 4). **A message about `blowfish_secret`**: it is shorter than 32 characters. **A blank page**: a quote or semicolon was lost in `config.inc.php`; restore your backup copy. **"No connection could be made" / error 2002**: MySQL is not running.
- **Deleting a product fails with a permission error on `movements`**: add `GRANT DELETE ON sc_inventory.movements TO 'sc_inventory_app'@'127.0.0.1';` to the end of `database/inventory.sql` and run `install.php` again.

## Where the data lives
In the `sc_inventory` MariaDB database; the tables are in `database/inventory.sql`. Every change is written to it straight away, and the pages read it on every request, so several people can use the app at once. If MySQL is stopped the app says so and nothing can be changed.
- `categories`, `products` and `movements` are the same three tables a save file holds, in 3NF. Stock levels are not stored: a product's stock is the sum of its movements.
- Values are checked twice: by the app, and by the database (foreign keys, `CHECK` limits, strict mode).
- The app signs in to MariaDB as `sc_inventory_app`, which can read and write these tables and nothing else (`SHOW GRANTS FOR 'sc_inventory_app'@'127.0.0.1';`). It cannot change the schema, cannot edit or delete a single movement (a product's history only goes when the product is deleted), and cannot see the accounts.
- A backup copy, encrypted and checksummed, is refreshed at most once an hour in `Documents\sc.INVENT backup.scinvent`. It is never read back by itself; restore it with **Load from file**.
- Moving over from the older, file-based version: setup steps 1 and 10. The old hidden folder `%LOCALAPPDATA%\sc.INVENT\` is no longer used and can be deleted.

## Manager (bottom of the sidebar, super admin only)
- **Save to file**: downloads the whole inventory as one `.scinvent` file.
- **Load from file**: replaces the whole inventory in the database with a saved file (checked first; a damaged or edited file is refused) and refreshes the Documents backup.
- **Delete inventory**: removes all products, history and categories from the database, and the Documents backup.

A `.scinvent` file made this way loads on any copy of the app.
The key and the backup timing are at the top of `core/Store.php`. Other roles do not see the Manager, and `manager.php` refuses them too.

## Signing in
Every page and endpoint needs a signed-in account, and the inventory is not even read before that. If the accounts database cannot be read, nobody gets in. The page shows nothing from the inventory.
- Accounts are in the `sc_accounts` database (`database/accounts.sql`): `users` and `roles`, with only a `password_hash` stored. The app signs in to MariaDB for this as `sc_accounts_app`, a different account that cannot touch the inventory; it can read accounts and update only `failed_attempts`, `locked_until` and `last_login_at`.
- After 5 wrong passwords in a row an account is locked for 15 minutes (`MAX_FAILS` and `LOCK_MINUTES` in `core/Auth.php`). A wrong username, a wrong password and a locked account all look the same on the sign-in page.
- Over the LAN, sign in on the `https://` address, since the password is sent as typed; over https the session cookie is also marked secure.
- The super admin created by `install.php` is `admin` / `1234`, a placeholder. Replace it right away (setup step 8); until there is a page to change passwords, that is done by hand.
- To add more roles or users for now, do it as root in the `sc_accounts` database; when a page for it exists, widen the grants in `database/accounts.sql` on purpose. If the users table is ever emptied, running `install.php` again adds `admin` back.

## QR codes
The dashboard's **Create QR code** button makes a QR code for one product or for as many as you like, and you can save it as a PNG.
Pick **Single product** or **Multiple products** at the top of the popup, then fill in a product (code, name, category optional, stock to add optional).
- **Single product**: press **Create QR code**. The queue, if there is one, is left as it is.
- **Multiple products**: press **Add Product** to put the product in the queue; repeat for each one, remove any from the list (or **Clear all**), then press **Create QR code**. A product still typed in the form when you press Create is added too, and the queue is kept if the popup is closed by accident.

Codes and names must each appear once per QR code.

To fit as many products as possible the text is packed in four steps (`Inventory::qrPayload()`, `Store::seal()`):
1. the fields are written column by column (all codes, then all names, ...) with control characters as separators, which compresses better than JSON;
2. that is deflated (zlib);
3. it is encrypted with AES-256-GCM, the same key as the save files, so the code is unreadable without a copy of this app and an edited code fails the check;
4. the bytes are written in radix 44, using only the characters of the QR *alphanumeric* mode (11 bits per 2 characters, about 30% denser than base64 in byte mode).

With ordinary product names about 150 products fit in one code (version 40, level M); the code falls back to level L for about 200. Before, about 30 products would have fit, had the code held more than one.
If the products do not fit, the popup says so; remove some and make a second code.
The QR image is drawn in the browser with [qrcode-generator](https://github.com/kazuhikoarase/qrcode-generator) (MIT), bundled in `lib/qrcode/`; `qr.php` builds the encrypted text.

## Scanning QR codes
The dashboard's **Scan QR code** button reads a code from the live camera, from a photo taken on the spot (**Take photo**, phones only) or from an image file (for example the PNG saved by Create QR code), then lists every product in it so they can be verified before any stock is added.
- Only untouched codes made by this app are accepted; a foreign or edited code is refused (decryption fails in `Inventory::scan()`).
- What the code holds is shown as text and is locked. Whatever it leaves out is filled in by the user: the stock, and for a new product the category. A new product can also be given a restock level (optional, 0 if left empty); it is flagged Low at or below it.
- Every product is checked on its own. A product that is not in the inventory is added first. The stock is recorded as a stock in with the note "Added via QR scan". Everything is saved together, or nothing is.
- If a code's category differs from the inventory's, the user chooses, for that product, to keep the inventory's or overwrite it with the code's.
- A critical mismatch (a code that belongs to another product name, or a name that already has another code) adds nothing for that product. The popup says how to resolve it; the other products in the code still go through, and the whole scan can be discarded.

The code is read in the browser with [zxing-wasm](https://github.com/Sec-ant/zxing-wasm) (MIT; ZXing-C++ compiled to WebAssembly), bundled in `lib/zxing/`. It copes with the tilt, blur, screen moire and noise of camera photos, so a photo is read at full size (only one over 4096 px on its longest side is shrunk). `scan.php` checks the code and adds the stock.

### On a phone
- **Take photo** and **Choose image** work on any page. **Use camera** (the live view) only works on `https://` pages or `localhost`: browsers hide the camera from `http://192.168...`-style addresses, and the popup says so. XAMPP also serves the site over https on port 443 with a self-signed certificate, so `https://<this computer's address>/school-supplies/` works once you accept the browser's certificate warning.
- For a dense code, fill the frame with the code, hold still and let the camera focus. Saving the PNG and scanning it from a printout or a second screen is more reliable than scanning a phone screen.

## Reports
Reports are real PDFs made with [FPDF](http://www.fpdf.org) (MIT license), bundled in `lib/fpdf/`.
Pick the paper in the Create report popup: about 25 ready-made sizes (A, B, JIS B, North American, bond paper and more) or **Custom size**, where the width and height are typed in in, cm, mm, pt or pc.
Each side of a custom size must be 130 to 450 mm (5.1 to 17.7 in); anything else is refused, so the table always has room.
Portrait (vertical) is the default. **Landscape** (horizontal) turns the page and uses a wider layout: the product and its note share the free width, so a long entry stays on one or two lines.
The Products list in the popup's Advanced options has a search box (name or product code); ticked products stay ticked while the list is filtered.
Margins, columns and page breaks follow the paper. To offer another size, add one line to `includes/paper_sizes.php`; the custom limits and units are there too.
