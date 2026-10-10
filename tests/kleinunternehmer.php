<?php
// PHP CLI with ext-dom; synthetic data, no Dolibarr/database required.
require_once __DIR__.'/../class/facturxxml.class.php';
function getDolGlobalString($name, $default = '')
{
	global $kuFlag;
	return $name === 'FACTURX_GERMAN_KLEINUNTERNEHMER' ? $kuFlag : $default;
}
$cases = array(
	'German KU no legal registration' => array('DE', 0, '1', 0, 'E'),
	'German KU' => array('DE', 0, '1', 0, 'E'),
	'string zero' => array('de', '0', '1', 0, 'E'),
	'no opt-in' => array('DE', 0, '', 0, 'Z'),
	'explicitly disabled' => array('DE', 0, '0', 0, 'Z'),
	'German VAT seller' => array('DE', 1, '1', 0, 'Z'),
	'French non-VAT seller' => array('FR', 0, '1', 0, 'Z'),
	'missing VAT status' => array('DE', null, '1', 0, 'Z'),
	'positive VAT' => array('DE', 0, '1', 19, 'S'),
);
foreach ($cases as $name => $case) {
	list($country, $vatStatus, $kuFlag, $rate, $category) = $case;
	$mysoc = (object) array('name' => 'Synthetic seller', 'country_code' => $country, 'idprof1' => '12/345/67890', 'idprof3' => 'HRB-TEST');
	if ($name === 'German KU no legal registration') { unset($mysoc->idprof3); }
	if ($vatStatus !== null) { $mysoc->tva_assuj = $vatStatus; }
	$line = (object) array('subprice' => 100, 'remise_percent' => 0, 'qty' => 1,
		'label' => 'Synthetic service', 'tva_tx' => $rate, 'total_ht' => 100, 'total_tva' => $rate);
	$invoice = (object) array('ref' => 'TEST-KU', 'type' => 0, 'date' => '2026-10-10',
		'lines' => array($line), 'total_ht' => 90, 'total_tva' => $rate * .9,
		'total_ttc' => 90 + $rate * .9, 'remise_absolue' => 10);
	$doc = new DOMDocument();
	$doc->loadXML(FacturxXml::buildFromInvoice($invoice));
	$xpath = new DOMXPath($doc);
	$xpath->registerNamespace('ram', FacturxXml::NS_RAM);
	$fc = $xpath->evaluate('string(//ram:SellerTradeParty/ram:SpecifiedTaxRegistration/ram:ID[@schemeID="FC"])');
	$legal = $xpath->evaluate('string(//ram:SellerTradeParty/ram:SpecifiedLegalOrganization/ram:ID)');
	if ($fc !== (strtoupper($country) === 'DE' ? '12/345/67890' : '')
		|| $legal !== (strtoupper($country) === 'DE' ? ($mysoc->idprof3 ?? '') : '12/345/67890')) {
		throw new RuntimeException($name.': incorrect German tax/legal registration mapping');
	}
	$identifier = $xpath->evaluate('string(//ram:SellerTradeParty/ram:ID)');
	if ($identifier !== (strtoupper($country) === 'DE' ? '12/345/67890' : '')) { throw new RuntimeException($name.': missing seller identifier'); }
	$categories = $xpath->query('//ram:CategoryCode');
	if ($categories->length !== 3) { throw new RuntimeException($name.': missing tax categories'); }
	foreach ($categories as $node) {
		if ($node->textContent !== $category) { throw new RuntimeException($name.': unexpected category '.$node->textContent); }
	}
	$reasons = $xpath->query('//ram:ExemptionReason');
	if ($reasons->length !== ($category === 'E' ? 3 : 0)) { throw new RuntimeException($name.': unexpected exemption reason'); }
	foreach ($reasons as $reason) {
		if ($reason->textContent !== FacturxXml::GERMAN_KU_REASON) { throw new RuntimeException($name.': inconsistent § 19 reason'); }
	}
}
echo "Kleinunternehmer regression cases passed.\n";
