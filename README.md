# Factur-X for Dolibarr

Generate customer invoices as **Factur-X** (PDF/A-3 with embedded CII XML, profile
**EXTENDED**) directly from Dolibarr, without any external dependency.

The module adds a new invoice PDF template — `facturx` — that inherits the visual
layout of the standard `sponge` template and post-processes the output to produce
a Factur-X 1.0 compliant document: strict PDF/A-3, Associated File dictionary in the
catalog, `/AFRelationship /Alternative` on the filespec, and the XMP extension schema
declaring the `fx:` metadata (`DocumentType`, `DocumentFileName`, `Version`,
`ConformanceLevel`).

**Background PDF handling.** `MAIN_ADD_PDF_BACKGROUND` is neutralised around
`parent::write_file()` and re-composited during the PDF/A-3 re-wrap. Sponge's
native background handling interacts poorly with TCPDF transactions and leaves
near-empty continuation pages; applying the background ourselves sidesteps the
bug and works identically regardless of the base template.

## Requirements

- Dolibarr ≥ 19
- PHP ≥ 7.4
- Dolibarr-bundled TCPDF and TCPDI (no Composer install required)

## Install

1. Clone the module into Dolibarr's `custom/` directory. **The target folder must be
   named `facturx`** — the module id is hard-wired to that path (`dol_buildpath('/facturx/…')`),
   so cloning under the repository name would break it:

   ```sh
   cd /path/to/dolibarr/htdocs/custom
   git clone https://github.com/habot-it/dolibarr_module_facturx.git facturx
   ```

   Then hand the files to the web-server user so Dolibarr can read them (Debian/Ubuntu
   use `www-data`; adjust to `apache`/`nginx` on other distros):

   ```sh
   chown -R www-data:www-data facturx
   ```

   (Alternatively, download the release zip and unpack it as `htdocs/custom/facturx/`.)
2. Log in as admin, open **Home → Setup → Modules/Applications**, find **Factur-X**
   and enable it.
3. Open **Financial → Invoices → Setup**, section *Invoice PDF templates*, and set
   `facturx` as the active template (or allow it alongside others and pick it per
   invoice from the invoice card).

## Usage

Generate a customer invoice PDF as usual. The resulting file is a PDF/A-3 with
`factur-x.xml` attached. Tools such as FNFE-MPE's validator, Chorus Pro and most
Factur-X readers will pick up the embedded invoice data automatically.

## Scope and known limitations

- Only customer invoices (`Facture`) are wired up. Supplier invoices and other
  document types are out of scope.
- Line-level discounts are folded into `NetPriceProductTradePrice` rather than
  emitted as a separate `SpecifiedTradeAllowanceCharge` block. This is valid
  EN 16931 but some strict receivers prefer the explicit form.
- Invoice `TypeCode` mapping covers standard (380), credit note (381),
  deposit (386) and proforma (325). Situation invoices (Dolibarr type 5) fall
  back to 380.
- No configuration page yet — conformance level, XML filename and
  `/AFRelationship` value are currently set in code (see `pdf_facturx.modules.php`).

## Architecture

- `core/modules/modFacturX.class.php` — module descriptor; registers the
  `facturx` entry in `llx_document_model` at activation.
- `core/modules/facture/doc/pdf_facturx.modules.php` — PDF template; extends
  `pdf_sponge`, calls its `write_file()` (with `MAIN_ADD_PDF_BACKGROUND`
  temporarily suppressed), then wraps the output through `FacturxTcpdi` with
  the generated XML and composites the background on top.
- `class/facturxtcpdi.class.php` — TCPDI subclass enforcing Factur-X PDF/A-3
  requirements (Filespec, catalog `/AF`, XMP extension).
- `class/facturxxml.class.php` — from-scratch CII XML builder (profile
  EXTENDED) mapping `Facture` / `FactureLigne` / `Societe` fields to
  UN/CEFACT CrossIndustryInvoice.

## License

GPLv3 or later — see [`COPYING`](COPYING).

## Author

HABOT IT — <https://www.habot.it>
