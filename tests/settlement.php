<?php
// Run with PHP CLI and ext-dom; no Dolibarr installation or database is needed.
require_once __DIR__.'/../class/facturxxml.class.php';

function getDolGlobalString($name, $default = '')
{
	return $default;
}

$mysoc = (object) array('name' => 'Synthetic seller', 'country_code' => 'DE');

class SettlementInvoice
{
	public $ref = 'TEST-SETTLEMENT';
	public $type = 0;
	public $date = '2026-10-10';
	public $multicurrency_code = 'EUR';
	public $lines = array();
	public $total_ht;
	public $total_tva;
	public $total_ttc;
	private $amounts;

	public function __construct($gross, $amounts)
	{
		$this->total_ttc = $gross;
		$this->total_ht = round($gross / 1.19, 2);
		$this->total_tva = $gross - $this->total_ht;
		$this->amounts = $amounts;
	}

	public function getSommePaiement() { return $this->amounts[0]; }
	public function getSumDepositsUsed() { return $this->amounts[1]; }
	public function getSumCreditNotesUsed() { return $this->amounts[2]; }
}

$cases = array(
	'unpaid invoice' => array(1190, array(0, 0, 0), '0.00', '1190.00'),
	'direct partial payment' => array(1190, array(200, 0, 0), '200.00', '990.00'),
	'paid deposit invoice' => array(357, array(357, 0, 0), '357.00', '0.00'),
	'applied deposit on full final' => array(1190, array(0, 357, 0), '357.00', '833.00'),
	'default negative-line final' => array(833, array(0, null, null), '0.00', '833.00'),
	'applied credit and direct payment' => array(1190, array(200, 0, 119), '319.00', '871.00'),
	'deposit, credit and direct payment' => array(1190, array(200, 357, 119), '676.00', '514.00'),
	'fully settled invoice' => array(1190, array(1190, 0, 0), '1190.00', '0.00'),
	'overpayment' => array(1190, array(1250, 0, 0), '1250.00', '-60.00'),
	'signed credit refund' => array(-119, array(-119.0, null, null), '-119.00', '0.00'),
	'fractional payment' => array(1190, array(0.1, 357, 119), '476.10', '713.90'),
);
foreach ($cases as $name => $case) {
	$doc = new DOMDocument();
	$doc->loadXML(FacturxXml::buildFromInvoice(new SettlementInvoice($case[0], $case[1])));
	$xpath = new DOMXPath($doc);
	$xpath->registerNamespace('ram', FacturxXml::NS_RAM);
	$base = '//ram:SpecifiedTradeSettlementHeaderMonetarySummation/ram:';
	foreach (array('TotalPrepaidAmount' => $case[2], 'DuePayableAmount' => $case[3]) as $field => $expected) {
		$actual = $xpath->evaluate('string('.$base.$field.')');
		if ($actual !== $expected) {
			throw new RuntimeException($name.': '.$field.' expected '.$expected.', got '.$actual);
		}
	}
}

foreach (array(array(-1, 0, 0), array(0, -1, 0), array(0, 0, 'Error: database unavailable')) as $amounts) {
	$failed = false;
	try {
		FacturxXml::buildFromInvoice(new SettlementInvoice(1190, $amounts));
	} catch (RuntimeException $e) {
		$failed = true;
	}
	if (!$failed) {
		throw new RuntimeException('Payment read failure must not produce XML');
	}
}
echo "Settlement regression cases passed.\n";
