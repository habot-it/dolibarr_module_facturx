<?php
// Exercise the header drawing contract without Dolibarr or a database.
$root = sys_get_temp_dir().'/facturx-logo-'.bin2hex(random_bytes(6));
mkdir($root.'/core/modules/facture/doc', 0700, true);
file_put_contents($root.'/core/modules/facture/doc/pdf_sponge.modules.php', '<?php');
$logo = $root.'/logo.png';
file_put_contents($logo, 'readable logo fixture');
define('DOL_DOCUMENT_ROOT', $root);
function dol_include_once($path) {}
function dol_buildpath($path) { global $logo; return $logo; }
function getDolGlobalInt($name, $default = 0) { global $hide; return $name === 'FACTURX_HIDE_PDF_LOGO' ? $hide : $default; }
class pdf_sponge
{
	public $name;
	public $description;
	public $page_largeur = 210;
	public function __construct($db) {}
	protected function _pagehead(&$pdf, $object, $showaddress, $langs, $langsbis = null)
	{
		$pdf->headers++;
		return array('top_shift' => 12, 'shipp_shift' => 4);
	}
}
$langs = new class {
	public function loadLangs($langs) {}
	public function transnoentities($key) { return $key; }
};
require __DIR__.'/../core/modules/facture/doc/pdf_facturx.modules.php';
class LogoTemplate extends pdf_facturx
{
	public function header(&$pdf) { return $this->_pagehead($pdf, new stdClass(), 1, new stdClass()); }
}
try {
	foreach (array(array(0, 1, 1), array(1, 1, 0), array(0, 2, 0)) as $case) {
		list($hide, $page, $expected) = $case;
		$pdf = new class($page) {
			public $headers = 0;
			public $images = 0;
			private $page;
			public function __construct($page) { $this->page = $page; }
			public function getPage() { return $this->page; }
			public function Image(...$args) { $this->images++; }
		};
		$template = new LogoTemplate(null);
		$layout = $template->header($pdf);
		if ($pdf->images !== $expected || $pdf->headers !== 1 || $layout !== array('top_shift' => 12, 'shipp_shift' => 4)) {
			throw new RuntimeException('Logo visibility or parent layout changed: hide='.$hide.', page='.$page);
		}
	}
	echo "PDF logo visibility cases passed.\n";
} finally {
	unlink($logo);
	unlink($root.'/core/modules/facture/doc/pdf_sponge.modules.php');
	foreach (array('/core/modules/facture/doc', '/core/modules/facture', '/core/modules', '/core', '') as $dir) { rmdir($root.$dir); }
}
