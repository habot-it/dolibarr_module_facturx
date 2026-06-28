# Changelog

All notable changes to this module are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project follows
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
