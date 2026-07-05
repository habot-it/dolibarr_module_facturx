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

	/**
	 *  The fx:* values as an extra rdf:Description. The Factur-X *extension schema
	 *  declaration* is NOT emitted here: TCPDF already writes its own
	 *  pdfaExtension:schemas property and XMP forbids the same property twice on
	 *  one subject — a second one makes veraPDF reject the whole XMP packet, lose
	 *  pdfaid:part=3 and fall back to (failing) PDF/A-1 validation. The schema is
	 *  merged into TCPDF's bag by the _putXMP() override below.
	 */
	private function facturxXmpRdf(array $m)
	{
		$enc = static function ($v) { return htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8'); };
		return "\t\t".'<rdf:Description rdf:about="" xmlns:fx="'.$enc(self::FX_NS).'">'
			.'<fx:DocumentType>'.$enc($m['documentType']).'</fx:DocumentType>'
			.'<fx:DocumentFileName>'.$enc($m['documentFileName']).'</fx:DocumentFileName>'
			.'<fx:Version>'.$enc($m['version']).'</fx:Version>'
			.'<fx:ConformanceLevel>'.$enc($m['conformanceLevel']).'</fx:ConformanceLevel>'
			.'</rdf:Description>'."\n";
	}

	/** Factur-X schema declaration as one rdf:li, indented like TCPDF's own items. */
	private function facturxXmpSchemaLi()
	{
		$enc = static function ($v) { return htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8'); };
		$props = array(
			'DocumentFileName' => 'The name of the embedded XML invoice file',
			'DocumentType'     => 'INVOICE',
			'Version'          => 'The actual version of the Factur-X XMP metadata schema',
			'ConformanceLevel' => 'The conformance level of the embedded Factur-X data',
		);
		$li = "\t\t\t\t\t".'<rdf:li rdf:parseType="Resource">'."\n"
			."\t\t\t\t\t\t".'<pdfaSchema:namespaceURI>'.$enc(self::FX_NS).'</pdfaSchema:namespaceURI>'."\n"
			."\t\t\t\t\t\t".'<pdfaSchema:prefix>fx</pdfaSchema:prefix>'."\n"
			."\t\t\t\t\t\t".'<pdfaSchema:schema>Factur-X PDFA Extension Schema</pdfaSchema:schema>'."\n"
			."\t\t\t\t\t\t".'<pdfaSchema:property>'."\n"
			."\t\t\t\t\t\t\t".'<rdf:Seq>'."\n";
		foreach ($props as $name => $desc) {
			$li .= "\t\t\t\t\t\t\t\t".'<rdf:li rdf:parseType="Resource">'."\n"
				."\t\t\t\t\t\t\t\t\t".'<pdfaProperty:category>external</pdfaProperty:category>'."\n"
				."\t\t\t\t\t\t\t\t\t".'<pdfaProperty:description>'.$enc($desc).'</pdfaProperty:description>'."\n"
				."\t\t\t\t\t\t\t\t\t".'<pdfaProperty:name>'.$enc($name).'</pdfaProperty:name>'."\n"
				."\t\t\t\t\t\t\t\t\t".'<pdfaProperty:valueType>Text</pdfaProperty:valueType>'."\n"
				."\t\t\t\t\t\t\t\t".'</rdf:li>'."\n";
		}
		$li .= "\t\t\t\t\t\t\t".'</rdf:Seq>'."\n"
			."\t\t\t\t\t\t".'</pdfaSchema:property>'."\n"
			."\t\t\t\t\t".'</rdf:li>'."\n";
		return $li;
	}

	/**
	 *  Splice the Factur-X schema declaration into TCPDF's single
	 *  pdfaExtension:schemas bag. Buffer surgery is safe here: _putXMP() is the
	 *  first thing _putcatalog() emits, and every later object records its xref
	 *  offset after this method returns. The stream is plain (PDF/A forbids
	 *  compressing the XMP), so only /Length must be re-stamped.
	 */
	protected function _putXMP()
	{
		$before = $this->bufferlen;
		$oid = parent::_putXMP();

		$li = $this->facturxXmpSchemaLi();
		$anchor = "\t\t\t\t".'</rdf:Bag>'."\n"."\t\t\t".'</pdfaExtension:schemas>';
		$tail = substr($this->buffer, $before);
		$p = strpos($tail, $anchor);
		if ($p === false) {
			return $oid;
		}
		$tail = substr($tail, 0, $p).$li.substr($tail, $p);
		$tail = preg_replace_callback(
			'~/Length (\d+) >> stream~',
			static function ($m) use ($li) {
				return '/Length '.((int) $m[1] + strlen($li)).' >> stream';
			},
			$tail,
			1
		);
		$this->buffer = substr($this->buffer, 0, $before).$tail;
		$this->bufferlen = strlen($this->buffer);
		return $oid;
	}

	/**
	 *  Same as TCPDI's method, but guarantees an EOL before each 'endobj':
	 *  pdf_write_value() ends dictionary objects on '>>' with no newline, which
	 *  violates ISO 19005 6.1.8 ("endobj shall be preceded by an EOL marker").
	 */
	public function _putimportedobjects() // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore
	{
		if (is_array($this->parsers) && count($this->parsers) > 0) {
			foreach ($this->parsers as $filename => $p) {
				$this->current_parser = &$this->parsers[$filename];
				if (isset($this->_obj_stack[$filename]) && is_array($this->_obj_stack[$filename])) {
					while (($n = key($this->_obj_stack[$filename])) !== null) {
						$nObj = $this->current_parser->getObjectVal($this->_obj_stack[$filename][$n][1]);
						$this->_newobj($this->_obj_stack[$filename][$n][0]);
						if ($nObj[0] == PDF_TYPE_STREAM) {
							$this->pdf_write_value($nObj);
						} else {
							$this->pdf_write_value($nObj[1]);
						}
						if (substr($this->buffer, -1) !== "\n") {
							$this->_out('');
						}
						$this->_out('endobj');
						$this->_obj_stack[$filename][$n] = null;
						unset($this->_obj_stack[$filename][$n]);
						reset($this->_obj_stack[$filename]);
					}
				}
				$this->current_parser->cleanUp();
				unset($this->parsers[$filename]);
			}
		}
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
