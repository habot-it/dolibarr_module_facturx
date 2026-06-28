<?php
/* Copyright (C) 2026  Alban DEZANDEE  <alban@habot.it>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, version 3 or later.
 */

/**
 *  \file       htdocs/custom/facturx/class/facturxtcpdi.class.php
 *  \ingroup    facturx
 *  \brief      TCPDI subclass enforcing Factur-X PDF/A-3 conformance.
 */

require_once DOL_DOCUMENT_ROOT.'/includes/tcpdi/tcpdi.php';


/**
 *  TCPDI subclass enforcing Factur-X conformance:
 *   - Filespec with /AFRelationship /Alternative + /ModDate + /Desc
 *   - /AF array added in the catalog (PDF/A-3 Associated Files)
 *   - Factur-X XMP schema extension + fx:* description via setExtraXMPRDF()
 *  Also fixes an upstream TCPDF issue where /Subtype overrode /Filter in PDF/A-3.
 */
class FacturxTcpdi extends TCPDI
{
	const FX_NS = 'urn:factur-x:pdfa:CrossIndustryDocument:invoice:1p0#';

	/** @var string */
	private $afRel = '/Alternative';

	/** @var string */
	private $fileDesc = 'Factur-X invoice data';

	public function setFacturxMetadata(array $meta = array())
	{
		$m = $meta + array(
			'afRelationship'   => '/Alternative',
			'documentType'     => 'INVOICE',
			'documentFileName' => 'factur-x.xml',
			'version'          => '1.0',
			'conformanceLevel' => 'EXTENDED',
			'desc'             => 'Factur-X invoice data',
		);
		$this->afRel = $m['afRelationship'];
		$this->fileDesc = $m['desc'];
		$this->setExtraXMPRDF($this->facturxXmpRdf($m));
	}

	private function facturxXmpRdf(array $m)
	{
		$enc = static function ($v) { return htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8'); };
		$props = array(
			'DocumentFileName' => 'The name of the embedded XML invoice file',
			'DocumentType'     => 'INVOICE',
			'Version'          => 'The actual version of the Factur-X XMP metadata schema',
			'ConformanceLevel' => 'The conformance level of the embedded Factur-X data',
		);
		$seq = '';
		foreach ($props as $name => $desc) {
			$seq .= '<rdf:li rdf:parseType="Resource">'
				.'<pdfaProperty:name>'.$enc($name).'</pdfaProperty:name>'
				.'<pdfaProperty:valueType>Text</pdfaProperty:valueType>'
				.'<pdfaProperty:category>external</pdfaProperty:category>'
				.'<pdfaProperty:description>'.$enc($desc).'</pdfaProperty:description>'
				.'</rdf:li>';
		}

		return "\t\t".'<rdf:Description rdf:about="" xmlns:pdfaExtension="http://www.aiim.org/pdfa/ns/extension/" xmlns:pdfaSchema="http://www.aiim.org/pdfa/ns/schema#" xmlns:pdfaProperty="http://www.aiim.org/pdfa/ns/property#">'
			.'<pdfaExtension:schemas><rdf:Bag><rdf:li rdf:parseType="Resource">'
			.'<pdfaSchema:schema>Factur-X PDFA Extension Schema</pdfaSchema:schema>'
			.'<pdfaSchema:namespaceURI>'.$enc(self::FX_NS).'</pdfaSchema:namespaceURI>'
			.'<pdfaSchema:prefix>fx</pdfaSchema:prefix>'
			.'<pdfaSchema:property><rdf:Seq>'.$seq.'</rdf:Seq></pdfaSchema:property>'
			.'</rdf:li></rdf:Bag></pdfaExtension:schemas>'
			.'</rdf:Description>'."\n"
			."\t\t".'<rdf:Description rdf:about="" xmlns:fx="'.$enc(self::FX_NS).'">'
			.'<fx:DocumentType>'.$enc($m['documentType']).'</fx:DocumentType>'
			.'<fx:DocumentFileName>'.$enc($m['documentFileName']).'</fx:DocumentFileName>'
			.'<fx:Version>'.$enc($m['version']).'</fx:Version>'
			.'<fx:ConformanceLevel>'.$enc($m['conformanceLevel']).'</fx:ConformanceLevel>'
			.'</rdf:Description>'."\n";
	}

	protected function _putEmbeddedFiles()
	{
		if ($this->pdfa_mode && $this->pdfa_version != 3) {
			return; // Embedded files forbidden in PDF/A-1 and PDF/A-2
		}
		foreach ($this->embeddedfiles as $filename => $fd) {
			$raw = $this->getCachedFileContents($fd['file']);
			if ($raw === false || $raw === '') {
				continue;
			}
			$this->efnames[$filename] = $fd['f'].' 0 R';

			$nameStr = $this->_datastring($filename, $fd['f']);
			$descStr = $this->_datastring($this->fileDesc, $fd['f']);
			$this->_out($this->_getobj($fd['f'])."\n"
				.'<</Type /Filespec'
				.' /F '.$nameStr.' /UF '.$nameStr
				.' /AFRelationship '.$this->afRel
				.' /Desc '.$descStr
				.' /EF <</F '.$fd['n'].' 0 R /UF '.$fd['n'].' 0 R>>'
				.' >>'."\nendobj");

			$data = $raw;
			$extras = '';
			if ($this->compress) {
				$data = gzcompress($raw);
				$extras .= ' /Filter /FlateDecode';
			}
			if ($this->pdfa_version == 3) {
				$extras .= ' /Subtype /text#2Fxml';
			}
			$stream = $this->_getrawstream($data, $fd['n']);
			$modDate = $this->pdfDate((int) (@filemtime($fd['file']) ?: time()));
			$this->_out($this->_getobj($fd['n'])."\n"
				.'<< /Type /EmbeddedFile'.$extras
				.' /Length '.strlen($stream)
				.' /Params <</Size '.strlen($raw).' /ModDate '.$this->_datastring($modDate, $fd['n']).'>>'
				.' >> stream'."\n".$stream."\nendstream\nendobj");
		}
	}

	/**
	 *  Append /AF in the catalog after parent emits it. Safe because the catalog
	 *  is the last object before xref — startxref is recomputed from bufferlen,
	 *  and no other offsets are affected by our in-place splice.
	 */
	protected function _putcatalog()
	{
		$before = $this->bufferlen;
		$oid = parent::_putcatalog();
		if (empty($this->embeddedfiles)) {
			return $oid;
		}

		$refs = array();
		foreach ($this->embeddedfiles as $fd) {
			$refs[] = $fd['f'].' 0 R';
		}
		$af = ' /AF ['.implode(' ', $refs).']';

		$tail = substr($this->buffer, $before);
		$end = strrpos($tail, 'endobj');
		if ($end === false) {
			return $oid;
		}
		$close = strrpos(rtrim(substr($tail, 0, $end)), '>>');
		if ($close === false) {
			return $oid;
		}

		$this->buffer = substr($this->buffer, 0, $before).substr($tail, 0, $close).$af.substr($tail, $close);
		$this->bufferlen = strlen($this->buffer);
		return $oid;
	}

	private function pdfDate($ts)
	{
		return preg_replace('/([+-]\d{2})(\d{2})$/', "$1'$2'", 'D:'.date('YmdHisO', $ts));
	}
}
