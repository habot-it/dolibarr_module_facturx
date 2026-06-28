<?php
/* Copyright (C) 2026  Alban DEZANDEE  <alban@habot.it>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, version 3 or later.
 */

/**
 *  \file       htdocs/custom/facturx/core/modules/facture/doc/pdf_facturx.modules.php
 *  \ingroup    facturx
 *  \brief      Invoice PDF template producing a Factur-X (PDF/A-3 + CII XML EXTENDED) document.
 */

require_once DOL_DOCUMENT_ROOT.'/core/modules/facture/doc/pdf_sponge.modules.php';
dol_include_once('/facturx/class/facturxxml.class.php');
// FacturxTcpdi is loaded on demand in embedFacturxXml() so admin/invoice.php
// can scan this file without requiring TCPDF (not yet loaded at that point).


/**
 *  Factur-X invoice PDF template. Inherits the richer layout from pdf_sponge and
 *  re-wraps the output as PDF/A-3 with the Factur-X CII XML embedded.
 *
 *  The MAIN_ADD_PDF_BACKGROUND pattern is neutralised around parent::write_file()
 *  and re-composited during the PDF/A-3 re-wrap. Reason: sponge measures the
 *  table-title height via startTransaction()/rollbackTransaction(), and the
 *  serialize/unserialize pair corrupts TCPDI's parser state when a background
 *  PDF is loaded, producing spurious AddPage() calls and near-empty continuation
 *  pages. Drawing the background ourselves sidesteps the bug entirely.
 */
class pdf_facturx extends pdf_sponge
{
	/** @var string Factur-X compliance logo path, resolved once at construction. */
	protected $facturxLogoPath;

	public function __construct($db)
	{
		parent::__construct($db);
		global $langs;
		$langs->loadLangs(array('facturx@facturx'));
		$this->name = 'facturx';
		$this->description = $langs->transnoentities('PDFFacturxTemplate');
		$this->facturxLogoPath = dol_buildpath('/facturx/img/factur-x-extended.png');
	}

	protected function _pagehead(&$pdf, $object, $showaddress, $outputlangs, $outputlangsbis = null)
	{
		parent::_pagehead($pdf, $object, $showaddress, $outputlangs, $outputlangsbis);
		if ($pdf->getPage() == 1 && $this->facturxLogoPath && is_readable($this->facturxLogoPath)) {
			// Place the logo immediately to the right of the "Facture FA…" title,
			// in the right-margin strip. Sponge draws the title right-aligned at
			// x = page_largeur - marge_droite, so starting our icon just past it
			// keeps the two elements visually joined.
			$w = 6;
			$pad = 3;
			$x = $this->page_largeur - $w - $pad;
			$y = $pad;
			$pdf->Image($this->facturxLogoPath, $x, $y, $w, 0, 'PNG');
		}
	}

	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
	public function write_file($object, $outputlangs, $srctemplatepath = '', $hidedetails = 0, $hidedesc = 0, $hideref = 0)
	{
		// phpcs:enable
		global $conf;

		$savedBg = $conf->global->MAIN_ADD_PDF_BACKGROUND ?? '';
		$conf->global->MAIN_ADD_PDF_BACKGROUND = '';

		$result = parent::write_file($object, $outputlangs, $srctemplatepath, $hidedetails, $hidedesc, $hideref);

		$conf->global->MAIN_ADD_PDF_BACKGROUND = $savedBg;

		if ($result <= 0 || empty($this->result['fullpath'])) {
			return $result;
		}

		$xml = FacturxXml::buildFromInvoice($object);
		if ($xml === '') {
			$this->error = 'Factur-X XML generation returned empty content';
			return -1;
		}

		return $this->embedFacturxXml($this->result['fullpath'], $xml, $object, $savedBg);
	}

	protected function embedFacturxXml($pdfPath, $xml, $object, $backgroundFilename = '')
	{
		$this->loadTcpdfChain();

		$xmlPath = dirname($pdfPath).'/factur-x.xml';
		if (file_put_contents($xmlPath, $xml) === false) {
			$this->error = 'Cannot write Factur-X XML to '.$xmlPath;
			return -1;
		}

		try {
			$pdf = new FacturxTcpdi('P', 'mm', 'A4', true, 'UTF-8', false, 3);
			$pdf->setPrintHeader(false);
			$pdf->setPrintFooter(false);
			$pdf->SetCreator('Dolibarr '.DOL_VERSION.' - Factur-X');
			$pdf->SetTitle('Factur-X '.$object->ref);
			$pdf->SetSubject('Factur-X invoice');
			$pdf->setFacturxMetadata(array('desc' => 'Factur-X XML invoice ('.$object->ref.')'));

			// Import every invoice page first — templates stay valid across parser swaps.
			$pages = $pdf->setSourceFile($pdfPath);
			$invoiceTpls = array();
			for ($p = 1; $p <= $pages; $p++) {
				$invoiceTpls[$p] = $pdf->importPage($p);
			}

			// Background template (optional). Loaded last so setSourceFile's parser
			// swap doesn't invalidate the already-imported invoice templates.
			$bgTpl = null;
			if ($backgroundFilename !== '') {
				$bgPath = $this->resolveBackgroundPath($object, $backgroundFilename);
				if ($bgPath !== '' && is_readable($bgPath)) {
					$pdf->setSourceFile($bgPath);
					$bgTpl = $pdf->importPage(1);
				}
			}

			foreach ($invoiceTpls as $tpl) {
				$size = $pdf->getTemplateSize($tpl);
				$pdf->AddPage($size['orientation'], array($size['width'], $size['height']));
				if ($bgTpl !== null) {
					$pdf->useTemplate($bgTpl);
				}
				$pdf->useTemplate($tpl);
			}

			$pdf->Annotation(0, 0, 0, 0, 'factur-x.xml', array(
				'Subtype' => 'FileAttachment', 'Name' => 'PushPin',
				'FS' => $xmlPath, 'Contents' => 'Factur-X invoice data',
			));
			$pdf->Output($pdfPath, 'F');
		} catch (\Throwable $e) {
			@unlink($xmlPath);
			$this->error = 'Factur-X embedding failed: '.$e->getMessage();
			return -1;
		}

		@unlink($xmlPath);
		return 1;
	}

	private function resolveBackgroundPath($object, $filename)
	{
		global $conf;
		$dir = (!empty($conf->mycompany->multidir_output[$object->entity]))
			? $conf->mycompany->multidir_output[$object->entity]
			: $conf->mycompany->dir_output;
		return $dir ? rtrim($dir, '/').'/'.$filename : '';
	}

	private function loadTcpdfChain()
	{
		if (!class_exists('TCPDF')) {
			if (!defined('K_TCPDF_EXTERNAL_CONFIG')) {
				define('K_TCPDF_EXTERNAL_CONFIG', 1);
			}
			require_once DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php';
			require_once DOL_DOCUMENT_ROOT.'/includes/tecnickcom/tcpdf/tcpdf.php';
		}
		dol_include_once('/facturx/class/facturxtcpdi.class.php');
	}
}
