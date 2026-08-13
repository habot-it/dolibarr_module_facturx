# Changelog

All notable changes to this module are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project follows
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.2.1] - 2026-08-13

### Fixed

- `Factur-X embedding failed: Call to undefined method
  FacturxTcpdi::getCachedFileContents()` on installations whose bundled TCPDF
  predates that helper (it is only a memoizing wrapper around
  `TCPDF_STATIC::fileGetContents`). The attachment is now read through whichever
  API the bundled TCPDF offers, falling back to `file_get_contents()`.
- The Factur-X XMP schema was spliced in by matching TCPDF's exact indentation,
  and a miss was ignored silently — which would have produced a PDF without the
  `fx:` schema declaration, i.e. not Factur-X, with no error shown. The anchor is
  now whitespace-tolerant and an unexpected TCPDF layout raises a clear error
  instead.

## [1.2.0] - 2026-08-03

### Added

- Buyer electronic address (BT-49) can now target an establishment or a service,
  through two thirdparty extra attributes created at module activation:
  **`facturx_address_format`** (list: `SIREN_SIRET`, `SIREN_SIRET_CODE`,
  `SIREN_CODE`) and **`facturx_routing_code`**. The SIREN and SIRET are never
  retyped — they are read from the professional ids of the card — so only the
  optional routing code is entered. An empty format keeps the previous behaviour
  (SIREN alone, legal entity). Each branch degrades to a still-valid address
  when a part is missing, and a whole address pasted into the routing code field
  is accepted as-is. Both attributes are kept when the module is disabled, so
  what the user entered is never lost.
- Routing codes are filtered per AIFE rule G1.115 (digits, unaccented latin
  letters and `-`, `_`, `.`), so a stray character never ends up in an address
  the directory would reject.

## [1.1.0] - 2026-07-05

### Fixed

- PDF validated as (failing) PDF/A-1 instead of PDF/A-3 by veraPDF/FNFE: the XMP
  contained the `pdfaExtension:schemas` property twice (TCPDF's own block plus
  the injected Factur-X one), which invalidates the whole XMP packet and hides
  `pdfaid:part 3`. The Factur-X extension schema is now merged into TCPDF's
  single schemas bag (`_putXMP()` override, in-place buffer splice with
  `/Length` re-stamp).
- ISO 19005 6.1.8 violations: TCPDI wrote imported dictionary objects with
  `endobj` not preceded by an end-of-line marker (`_putimportedobjects()`
  override).
- Non-embedded Helvetica in the page content (forbidden in PDF/A): the sponge
  layout is now rendered with DejaVu Sans (embedded TrueType bundled with
  Dolibarr) by routing `MAIN_PDF_FORCE_FONT` during `write_file()`; set
  `FACTURX_PDF_FONT` to choose another embeddable font, or define
  `MAIN_PDF_FORCE_FONT` globally to take full control.
- FNFE Schematron findings: no more `schemeID` on the trade-contact e-mail
  `URIID` ("attribute not used in this context"); `ram:TotalPrepaidAmount`
  (BT-113) is emitted so the BR-FXEXT-CO-16 arithmetic holds for partially
  paid invoices; `ram:ApplicableHeaderTradeDelivery` is never empty anymore
  (PEPPOL-EN16931-R008) — the delivery date falls back to the invoice date.

- Invalid CII element order in the document-level `ram:ApplicableTradeTax`:
  `ram:BasisAmount` is now emitted before `ram:CategoryCode` as required by the
  official XSD (validated against Factur-X 1.09 EXTENDED); the previous order
  failed schema validation.
- FNFE-MPE validator rejections BR-FR-12 / BR-FR-13 (BT-49 / BT-34 empty):
  seller and buyer electronic addresses are now emitted as
  `ram:URIUniversalCommunication/ram:URIID`. French parties use scheme `0225`
  with their SIREN (from prof. id 1, or derived from the SIRET); other parties
  fall back to their e-mail address (EAS `EM`). The seller value can be
  overridden with `FACTURX_SELLER_ENDPOINT_ID` / `FACTURX_SELLER_ENDPOINT_SCHEME`
  (e.g. to append a routing code, `SIREN_XXX`).

### Added

- French mandatory footer mentions as coded `ram:IncludedNote` entries
  (BR-FR-05, one per code per BR-FR-06), emitted when the selling company is
  French: late-payment penalties (`PMD`), 40 EUR recovery indemnity (`PMT`),
  discount policy (`AAB`), plus the optional single-VAT-group mention (`TXD`,
  no default). Standard defaults ship in French; override them with the
  constants `FACTURX_NOTE_PMD`, `FACTURX_NOTE_PMT`, `FACTURX_NOTE_AAB`,
  `FACTURX_NOTE_TXD` (Home → Setup → Other setup).
- Payment terms label (BT-20) emitted as
  `ram:SpecifiedTradePaymentTerms/ram:Description`, translated like the PDF
  templates do.
- Setup page (`admin/setup.php`, reachable from the module list gear icon):
  edit the four coded notes (defaults shown as placeholders, empty = default,
  TXD empty = omitted) and the seller electronic address value/scheme, without
  touching hidden constants.

## [1.0.0] - 2026-04-24

### Added

- Initial release of the `facturx` invoice PDF template (extends `pdf_sponge`).
- `MAIN_ADD_PDF_BACKGROUND` re-composited during the PDF/A-3 re-wrap instead
  of being applied natively, to avoid a TCPDI-parser serialization bug that
  corrupts sponge's page-break calculations and produces empty continuation
  pages.
- Factur-X EXTENDED compliance logo drawn on page 1 top-right.
- `FacturxTcpdi` TCPDI subclass producing strict PDF/A-3 output:
  - `/AFRelationship /Alternative` and `/ModDate` + `/Desc` on the embedded
    `factur-x.xml` Filespec.
  - `/AF` entry in the document catalog (PDF/A-3 Associated Files).
  - XMP extension schema and `fx:` metadata (`DocumentType`,
    `DocumentFileName`, `Version`, `ConformanceLevel = EXTENDED`).
  - Fixes an upstream TCPDF issue where `/Subtype` overrode `/Filter` when the
    output was compressed in PDF/A-3 mode.
- `FacturxXml` CII XML builder (profile EXTENDED), no external dependency:
  - Seller and buyer parties with multi-line address, trade contact
    (phone + SMTP email), legal org ID with country-aware `schemeID` and VAT
    registration.
  - Buyer reference, buyer/seller order and contract references from
    `fetchObjectLinked()`.
  - Line items with UN/ECE Rec 20 unit codes resolved in a single query against
    `llx_c_units` (covers every unit Dolibarr ships).
  - Payment means mapped from `mode_reglement_code` to UN/EDIFACT 4461, with
    IBAN and BIC resolved from `fk_account`.
  - Document-level `SpecifiedTradeAllowanceCharge` when `remise_absolue` is set.
  - `InvoiceReferencedDocument` for credit notes (type 2) pointing back to the
    source invoice.
  - `TypeCode` mapping: 380 (standard), 381 (credit note), 386 (deposit),
    325 (proforma).
