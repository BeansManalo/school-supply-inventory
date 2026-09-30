# School Supplies

Basic inventory system for school supplies: products, stock in/out, and inventory reports.
HTML/CSS/JS/PHP, no database yet.

## Run (XAMPP)
1. Put this folder in `C:\xampp\htdocs\`.
2. Start Apache in the XAMPP Control Panel.
3. Open http://localhost/school-supplies/

Requires PHP 8.0+ with the `openssl` and `mbstring` extensions (normally enabled in XAMPP).

## Where the data lives
Products and stock movements are saved automatically, encrypted and checksummed, in a hidden folder:
`%LOCALAPPDATA%\sc.INVENT\`

- `inventory.scinvent`: the main save, rewritten on every change.
- `auto1.scinvent` to `auto5.scinvent`: rotating autosaves (at most one per minute).
- `Documents\sc.INVENT backup.scinvent`: a backup copy, refreshed at most once an hour.

If the main save is damaged or edited, the newest good autosave (or the Documents backup) is loaded instead.
A fresh install starts empty and creates these files on the first change.

## Manager (bottom of the sidebar)
- **Save to file**: downloads the whole inventory as one `.scinvent` file.
- **Load from file**: replaces the current inventory with a saved file (checked first; a damaged or edited file is refused) and deletes all autosaves.
- **Delete inventory**: removes all products, history and every save file, the Documents backup included.

A `.scinvent` file made this way loads on any copy of the app.
The saved tables (categories, products, movements) are normalized to 3NF so they can move into SQL later.
Timings and the key live at the top of `core/Store.php`.

## QR codes
The dashboard's **Create QR code** button makes a product QR code you can save as a PNG.
It holds the product code, name, category (optional), stock to add (optional) and a key.
The key is a keyed hash of the other fields (see `Store::sign()`), so a reader can tell the content is exactly what was encoded and was not edited.
Codes made on one copy of the app verify on any other copy.
The QR image is drawn in the browser with [qrcode-generator](https://github.com/kazuhikoarase/qrcode-generator) (MIT), bundled in `lib/qrcode/`; `qr.php` builds the signed text.

## Scanning QR codes
The dashboard's **Scan QR code** button reads a code from a camera or from an image file (for example the PNG saved by Create QR code), then shows what it holds so it can be verified before any stock is added.
- Only untouched codes made by this app are accepted; a foreign or edited code is refused (the key is checked in `Inventory::scan()`).
- What the code holds is locked. Whatever it leaves out is filled in by the user: the stock, and for a new product the category and reorder level.
- A product that is not in the inventory is added first. The stock is recorded as a stock in with the note "Added via QR scan".
- If the code's category differs from the inventory's, the user chooses to keep the inventory's or overwrite it with the code's.
- A critical mismatch (a code that belongs to another product name, or a name that already has another code) adds nothing. The popup says how to resolve it, and the scan can be discarded.
- If the camera cannot be opened (none, blocked, busy, or the page is not on localhost or https) the popup says so, and an image can be used instead.

The code is read in the browser with [jsQR](https://github.com/cozmo/jsQR) (Apache-2.0), bundled in `lib/jsqr/`; `scan.php` checks it and adds the stock.

## Reports
Reports are real PDFs made with [FPDF](http://www.fpdf.org) (MIT license), bundled in `lib/fpdf/`.
Pick the paper size in the Create report popup (A4, Letter, Long bond, Legal, A5, A3); margins, columns and page breaks follow it.
To offer another size, add one line to `includes/paper_sizes.php`.
