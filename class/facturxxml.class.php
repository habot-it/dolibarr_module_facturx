<?php
/* Copyright (C) 2026  Alban DEZANDEE  <alban@habot.it>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, version 3 or later.
 */

/**
 *  \file       htdocs/custom/facturx/class/facturxxml.class.php
 *  \ingroup    facturx
 *  \brief      Factur-X CII XML builder (profile EXTENDED) from a Dolibarr Facture.
 */


/**
 *  Builds the UN/CEFACT CrossIndustryInvoice (CII) XML payload for a Factur-X
 *  invoice targeting the EXTENDED profile. No external dependency.
 */
class FacturxXml
{
	const GERMAN_KU_REASON = 'Steuerbefreiung für Kleinunternehmer gemäß § 19 UStG.';

	const GUIDELINE_EXTENDED = 'urn:cen.eu:en16931:2017#conformant#urn:factur-x.eu:1p0:extended';

	const NS_RSM = 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100';
	const NS_QDT = 'urn:un:unece:uncefact:data:standard:QualifiedDataType:100';
	const NS_RAM = 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100';
	const NS_UDT = 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100';

	/** Dolibarr c_units.code → UN/ECE Recommendation 20. Covers every unit Dolibarr ships. */
	private static $unitMap = array(
		'P' => 'C62', 'SET' => 'SET',
		'MM' => 'MMT', 'CM' => 'CMT', 'DM' => 'DMT', 'M' => 'MTR',
		'FT' => 'FOT', 'IN' => 'INH',
		'MM2' => 'MMK', 'CM2' => 'CMK', 'DM2' => 'DMK', 'M2' => 'MTK', 'FT2' => 'FTK', 'IN2' => 'INK',
		'MM3' => 'MMQ', 'CM3' => 'CMQ', 'DM3' => 'DMQ', 'M3' => 'MTQ', 'FT3' => 'FTQ', 'IN3' => 'INQ',
		'L' => 'LTR', 'GAL' => 'GLL', 'OZ3' => 'OZA',
		'MG' => 'MGM', 'G' => 'GRM', 'KG' => 'KGM', 'T' => 'TNE', 'LB' => 'LBR', 'OZ' => 'ONZ',
		'S' => 'SEC', 'MI' => 'MIN', 'H' => 'HUR',
		'D' => 'DAY', 'W' => 'WEE', 'MO' => 'MON', 'Y' => 'ANN',
	);

	/** Dolibarr payment mode code → UN/EDIFACT 4461. Unknown modes fall back to 1. */
	private static $paymentMap = array(
		'LIQ' => '10', 'CHQ' => '20', 'VIR' => '30', 'PRE' => '49',
		'CB' => '48', 'VAD' => '48', 'TIP' => '30', 'TRA' => '42', 'FAC' => '97',
	);

	/** ISO-3166-1 alpha-2 → ISO/IEC 6523 scheme ID for the legal-org ID. */
	private static $legalSchemeMap = array(
		'FR' => '0002',
	);

	/** CII TypeCode by Dolibarr Facture::type. */
	private static $typeCodeMap = array(
		2 => '381', // credit note
		3 => '386', // deposit / prepayment
		4 => '325', // proforma
	);

	/** @var DOMDocument */
	private $doc;

	/** @var Facture */
	private $invoice;

	/** @var string */
	private $currency;

	/** @var array<int,string> */
	private $unitCodeCache = array();

	public static function buildFromInvoice($invoice)
	{
		return (new self($invoice))->build();
	}

	private function __construct($invoice)
	{
		$this->invoice = $invoice;
		$this->currency = !empty($invoice->multicurrency_code)
			? $invoice->multicurrency_code
			: (getDolGlobalString('MAIN_MONNAIE') ?: 'EUR');
		$this->doc = new DOMDocument('1.0', 'UTF-8');
		$this->doc->formatOutput = true;
	}

	private function build()
	{
		$this->preloadUnitCodes();

		$root = $this->doc->createElementNS(self::NS_RSM, 'rsm:CrossIndustryInvoice');
		foreach (array('qdt' => self::NS_QDT, 'ram' => self::NS_RAM, 'udt' => self::NS_UDT) as $p => $ns) {
			$root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:'.$p, $ns);
		}
		$this->doc->appendChild($root);
		$root->appendChild($this->buildContext());
		$root->appendChild($this->buildDocument());
		$root->appendChild($this->buildTransaction());

		return $this->doc->saveXML();
	}

	private function buildContext()
	{
		$ctx = $this->el('rsm:ExchangedDocumentContext');
		$g = $ctx->appendChild($this->el('ram:GuidelineSpecifiedDocumentContextParameter'));
		$g->appendChild($this->el('ram:ID', self::GUIDELINE_EXTENDED));
		return $ctx;
	}

	private function buildDocument()
	{
		$inv = $this->invoice;
		$doc = $this->el('rsm:ExchangedDocument');
		$doc->appendChild($this->el('ram:ID', (string) $inv->ref));
		$doc->appendChild($this->el('ram:TypeCode', self::$typeCodeMap[(int) $inv->type] ?? '380'));
		$doc->appendChild($this->dateEl('ram:IssueDateTime', $inv->date));

		if (!empty($inv->note_public)) {
			$note = $doc->appendChild($this->el('ram:IncludedNote'));
			$note->appendChild($this->el('ram:Content', (string) $inv->note_public));
		}

		foreach ($this->frenchMandatoryNotes() as $code => $text) {
			$note = $doc->appendChild($this->el('ram:IncludedNote'));
			$note->appendChild($this->el('ram:Content', $text));
			$note->appendChild($this->el('ram:SubjectCode', $code));
		}
		return $doc;
	}

	/**
	 *  French mandatory footer mentions as coded notes (BR-FR-05; at most one note
	 *  per code, BR-FR-06). Emitted whenever the seller is French — the Code de
	 *  commerce requires them regardless of the buyer's country. The defaults match
	 *  the standard boilerplate; companies applying an escompte or a specific
	 *  penalty rate must override them with the constants FACTURX_NOTE_PMD,
	 *  FACTURX_NOTE_PMT, FACTURX_NOTE_AAB, FACTURX_NOTE_TXD (Home → Setup → Other).
	 *
	 *  @return array<string,string>  UNTDID 4451 subject code => note content
	 */
	private function frenchMandatoryNotes()
	{
		global $mysoc;
		if (empty($mysoc) || strtoupper((string) ($mysoc->country_code ?? '')) !== 'FR') {
			return array();
		}
		$notes = array();
		foreach (self::defaultNotes() as $code => $default) {
			$notes[$code] = getDolGlobalString('FACTURX_NOTE_'.$code, $default);
		}
		return array_filter($notes, 'strlen');
	}

	/**
	 *  Default French wording for the coded notes, keyed by UNTDID 4451 subject
	 *  code. Single source of truth shared with the setup page (admin/setup.php),
	 *  which shows these texts as placeholders. TXD (single VAT group) has no
	 *  default on purpose: it only applies to companies in that situation.
	 *
	 *  @return array<string,string>
	 */
	public static function defaultNotes()
	{
		return array(
			'PMD' => "Pénalités de retard : trois fois le taux d'intérêt légal (art. L441-10 du Code de commerce).",
			'PMT' => "Indemnité forfaitaire pour frais de recouvrement en cas de retard de paiement : 40 EUR (art. D441-5 du Code de commerce).",
			'AAB' => "Pas d'escompte pour paiement anticipé.",
			'TXD' => '',
		);
	}

	private function buildTransaction()
	{
		$tx = $this->el('rsm:SupplyChainTradeTransaction');
		$i = 1;
		foreach ((array) $this->invoice->lines as $line) {
			$tx->appendChild($this->buildLine($line, $i++));
		}
		$tx->appendChild($this->buildAgreement());
		$tx->appendChild($this->buildDelivery());
		$tx->appendChild($this->buildSettlement());
		return $tx;
	}

	private function buildLine($line, $num)
	{
		$netUnit = (float) $line->subprice * (1 - (float) $line->remise_percent / 100);
		$li = $this->el('ram:IncludedSupplyChainTradeLineItem');

		$assoc = $li->appendChild($this->el('ram:AssociatedDocumentLineDocument'));
		$assoc->appendChild($this->el('ram:LineID', (string) $num));

		$prod = $li->appendChild($this->el('ram:SpecifiedTradeProduct'));
		$prod->appendChild($this->el('ram:Name', $this->productName($line)));

		$ag = $li->appendChild($this->el('ram:SpecifiedLineTradeAgreement'));
		$gross = $ag->appendChild($this->el('ram:GrossPriceProductTradePrice'));
		$gross->appendChild($this->amount('ram:ChargeAmount', (float) $line->subprice));
		$net = $ag->appendChild($this->el('ram:NetPriceProductTradePrice'));
		$net->appendChild($this->amount('ram:ChargeAmount', $netUnit));

		$del = $li->appendChild($this->el('ram:SpecifiedLineTradeDelivery'));
		$qty = $this->el('ram:BilledQuantity', $this->num((float) $line->qty));
		$qty->setAttribute('unitCode', $this->unitCode($line));
		$del->appendChild($qty);

		$stl = $li->appendChild($this->el('ram:SpecifiedLineTradeSettlement'));
		$stl->appendChild($this->taxEl((float) $line->tva_tx));
		$sum = $stl->appendChild($this->el('ram:SpecifiedTradeSettlementLineMonetarySummation'));
		$sum->appendChild($this->amount('ram:LineTotalAmount', (float) $line->total_ht));

		return $li;
	}

	private function productName($line)
	{
		foreach (array($line->product_label ?? null, $line->label ?? null, $line->desc ?? null) as $v) {
			if (!empty($v)) {
				return (string) $v;
			}
		}
		return '.';
	}

	private function buildAgreement()
	{
		global $mysoc;
		$inv = $this->invoice;
		$ag = $this->el('ram:ApplicableHeaderTradeAgreement');

		if (!empty($inv->ref_client)) {
			$ag->appendChild($this->el('ram:BuyerReference', (string) $inv->ref_client));
		}
		$ag->appendChild($this->buildParty('ram:SellerTradeParty', $mysoc));
		if (!empty($inv->thirdparty)) {
			$ag->appendChild($this->buildParty('ram:BuyerTradeParty', $inv->thirdparty));
		}
		if (!empty($inv->ref_client)) {
			$ref = $ag->appendChild($this->el('ram:BuyerOrderReferencedDocument'));
			$ref->appendChild($this->el('ram:IssuerAssignedID', (string) $inv->ref_client));
		}
		foreach (array('commande' => 'ram:SellerOrderReferencedDocument', 'contrat' => 'ram:ContractReferencedDocument') as $element => $tag) {
			$linked = $this->firstLinkedRef($element);
			if ($linked !== '') {
				$ref = $ag->appendChild($this->el($tag));
				$ref->appendChild($this->el('ram:IssuerAssignedID', $linked));
			}
		}
		return $ag;
	}

	private function firstLinkedRef($element)
	{
		if (empty($this->invoice->linkedObjects[$element]) || !is_array($this->invoice->linkedObjects[$element])) {
			return '';
		}
		foreach ($this->invoice->linkedObjects[$element] as $obj) {
			if (!empty($obj->ref)) {
				return (string) $obj->ref;
			}
		}
		return '';
	}

	private function buildParty($tag, $party)
	{
		$el = $this->el($tag);
		$country = strtoupper((string) ($party->country_code ?? ''));
		if ($tag === 'ram:SellerTradeParty' && $country === 'DE' && !empty($party->idprof1)) {
			$el->appendChild($this->el('ram:ID', (string) $party->idprof1));
		}
		$el->appendChild($this->el('ram:Name', (string) ($party->name ?? '')));
		// German professional ID 1 is the tax number; ID 3 is the commercial register.
		$legalId = $country === 'DE' ? ($party->idprof3 ?? '') : ($party->idprof1 ?? '');
		if (!empty($legalId)) {
			$legal = $el->appendChild($this->el('ram:SpecifiedLegalOrganization'));
			$id = $legal->appendChild($this->el('ram:ID', (string) $legalId));
			if (isset(self::$legalSchemeMap[$country])) {
				$id->setAttribute('schemeID', self::$legalSchemeMap[$country]);
			}
		}

		$contact = $this->buildContact($party);
		if ($contact !== null) {
			$el->appendChild($contact);
		}
		$el->appendChild($this->buildAddress($party));

		// Electronic address / endpoint ID (BT-34 seller, BT-49 buyer — BR-FR-12/13).
		// Must sit between PostalTradeAddress and SpecifiedTaxRegistration (CII order).
		$endpoint = $this->endpointFor($party, $tag === 'ram:SellerTradeParty');
		if ($endpoint !== null) {
			$uri = $el->appendChild($this->el('ram:URIUniversalCommunication'));
			$id = $uri->appendChild($this->el('ram:URIID', $endpoint[1]));
			$id->setAttribute('schemeID', $endpoint[0]);
		}

		if ($country === 'DE' && !empty($party->idprof1)) {
			$reg = $el->appendChild($this->el('ram:SpecifiedTaxRegistration'));
			$id = $reg->appendChild($this->el('ram:ID', (string) $party->idprof1));
			$id->setAttribute('schemeID', 'FC');
		}

		if (!empty($party->tva_intra)) {
			$reg = $el->appendChild($this->el('ram:SpecifiedTaxRegistration'));
			$id = $reg->appendChild($this->el('ram:ID', (string) $party->tva_intra));
			$id->setAttribute('schemeID', 'VA');
		}
		return $el;
	}

	/**
	 *  Resolve the electronic address for a trade party. French parties use the
	 *  2026 CTC scheme 0225 with the SIREN (the PPF/PA directory is keyed on it);
	 *  the seller value can be overridden — e.g. to append a routing code,
	 *  SIREN_XXX — with FACTURX_SELLER_ENDPOINT_ID / FACTURX_SELLER_ENDPOINT_SCHEME.
	 *  Non-French parties fall back to the e-mail address (EAS code EM).
	 *
	 *  @param  Societe	$party     Party (thirdparty or $mysoc)
	 *  @param  bool	$isSeller  True when building SellerTradeParty
	 *  @return array{0:string,1:string}|null  [schemeID, value], or null to omit
	 */
	private function endpointFor($party, $isSeller)
	{
		if ($isSeller && getDolGlobalString('FACTURX_SELLER_ENDPOINT_ID') !== '') {
			return array(getDolGlobalString('FACTURX_SELLER_ENDPOINT_SCHEME', '0225'), getDolGlobalString('FACTURX_SELLER_ENDPOINT_ID'));
		}
		if (strtoupper((string) ($party->country_code ?? '')) === 'FR') {
			$siren = preg_replace('/\D/', '', (string) ($party->idprof1 ?? ''));
			if (strlen($siren) !== 9) {
				$siret = preg_replace('/\D/', '', (string) ($party->idprof2 ?? ''));
				$siren = (strlen($siret) === 14) ? substr($siret, 0, 9) : '';
			}
			if ($siren !== '') {
				return array('0225', $this->routedAddress($party, $siren));
			}
		}
		if (!empty($party->email)) {
			return array('EM', (string) $party->email);
		}
		return null;
	}

	/**
	 *  Build the party electronic address from the addressing format chosen on
	 *  its card. The SIREN and SIRET are never retyped — they come from the
	 *  standard professional ids — so only the optional routing code is stored
	 *  on the thirdparty:
	 *
	 *   - no format          => SIREN                    (legal entity)
	 *   - SIREN_SIRET        => SIREN_SIRET              (one establishment)
	 *   - SIREN_SIRET_CODE   => SIREN_SIRET_<code>       (service of one establishment)
	 *   - SIREN_CODE         => SIREN_<code>             (free suffix)
	 *
	 *  Every branch degrades to a still-valid address when a part is missing
	 *  (no SIRET on the card, empty routing code).
	 *
	 *  @param  Societe	$party  Party to address
	 *  @param  string	$siren  Nine-digit SIREN, already validated
	 *  @return string          Electronic address for scheme 0225
	 */
	private function routedAddress($party, $siren)
	{
		$options = isset($party->array_options) && is_array($party->array_options) ? $party->array_options : array();

		// An unselected Dolibarr select posts '0', which means "no format" here.
		$format = (string) ($options['options_facturx_address_format'] ?? '');
		if ($format === '' || $format === '0') {
			return $siren;
		}

		// AIFE rule G1.115: a suffix accepts only digits, unaccented latin letters
		// and "-", "_", "." — anything else is dropped rather than shipped in an
		// address the directory would reject.
		$code = preg_replace('/[^0-9A-Za-z._-]/', '', (string) ($options['options_facturx_routing_code'] ?? ''));
		$code = trim($code, '_');
		// Tolerate a whole address pasted into the routing code field. The
		// separator matters: a SIRET also starts with the SIREN.
		if ($code !== '' && strpos($code, $siren.'_') === 0) {
			return $code;
		}

		$parts = array($siren);
		if ($format === 'SIREN_SIRET' || $format === 'SIREN_SIRET_CODE') {
			$siret = preg_replace('/\D/', '', (string) ($party->idprof2 ?? ''));
			if (strlen($siret) === 14) {
				$parts[] = $siret;
			}
		}
		if ($code !== '' && ($format === 'SIREN_SIRET_CODE' || $format === 'SIREN_CODE')) {
			$parts[] = $code;
		}
		return implode('_', $parts);
	}

	private function buildContact($party)
	{
		$person = (string) ($party->civility_name ?? $party->name_alias ?? '');
		$phone  = (string) ($party->phone ?? '');
		$email  = (string) ($party->email ?? '');
		if ($person === '' && $phone === '' && $email === '') {
			return null;
		}

		$c = $this->el('ram:DefinedTradeContact');
		if ($person !== '') {
			$c->appendChild($this->el('ram:PersonName', $person));
		}
		if ($phone !== '') {
			$tel = $c->appendChild($this->el('ram:TelephoneUniversalCommunication'));
			$tel->appendChild($this->el('ram:CompleteNumber', $phone));
		}
		if ($email !== '') {
			// No schemeID here: unlike the endpoint URIID, the trade-contact e-mail
			// URIID takes no scheme in Factur-X (FNFE: "attribute not used in the
			// given context").
			$em = $c->appendChild($this->el('ram:EmailURIUniversalCommunication'));
			$em->appendChild($this->el('ram:URIID', $email));
		}
		return $c;
	}

	private function buildAddress($party)
	{
		$a = $this->el('ram:PostalTradeAddress');
		if (!empty($party->zip)) {
			$a->appendChild($this->el('ram:PostcodeCode', (string) $party->zip));
		}

		$lines = array_values(array_filter(
			array_map('trim', preg_split('/\r\n|\r|\n/', (string) ($party->address ?? ''))),
			'strlen'
		));
		foreach (array('ram:LineOne', 'ram:LineTwo', 'ram:LineThree') as $i => $tag) {
			if (isset($lines[$i])) {
				$a->appendChild($this->el($tag, $lines[$i]));
			}
		}

		if (!empty($party->town)) {
			$a->appendChild($this->el('ram:CityName', (string) $party->town));
		}
		if (!empty($party->country_code)) {
			$a->appendChild($this->el('ram:CountryID', strtoupper((string) $party->country_code)));
		}
		return $a;
	}

	private function buildDelivery()
	{
		// The element itself is XSD-mandatory but must not stay empty
		// (PEPPOL-EN16931-R008): fall back to the invoice date as the delivery
		// date (BT-72) when Dolibarr has no delivery date.
		$d = $this->el('ram:ApplicableHeaderTradeDelivery');
		$date = !empty($this->invoice->date_delivery) ? $this->invoice->date_delivery : $this->invoice->date;
		if (!empty($date)) {
			$evt = $d->appendChild($this->el('ram:ActualDeliverySupplyChainEvent'));
			$evt->appendChild($this->dateEl('ram:OccurrenceDateTime', $date));
		}
		return $d;
	}

	private function buildSettlement()
	{
		$inv = $this->invoice;
		$s = $this->el('ram:ApplicableHeaderTradeSettlement');
		$s->appendChild($this->el('ram:InvoiceCurrencyCode', $this->currency));

		$pm = $this->buildPaymentMeans();
		if ($pm !== null) {
			$s->appendChild($pm);
		}

		$dominantRate = 0.0;
		foreach ($this->aggregateTaxes() as $rate => $amounts) {
			$dominantRate = max($dominantRate, (float) $rate);
			// CII TradeTaxType sequence: CalculatedAmount, TypeCode, BasisAmount,
			// CategoryCode, RateApplicablePercent — BasisAmount before CategoryCode.
			$t = $s->appendChild($this->el('ram:ApplicableTradeTax'));
			$t->appendChild($this->amount('ram:CalculatedAmount', $amounts['vat']));
			$t->appendChild($this->el('ram:TypeCode', 'VAT'));
			if ($this->taxCategory((float) $rate) === 'E') {
				$t->appendChild($this->el('ram:ExemptionReason', self::GERMAN_KU_REASON));
			}
			$t->appendChild($this->amount('ram:BasisAmount', $amounts['base']));
			$t->appendChild($this->el('ram:CategoryCode', $this->taxCategory((float) $rate)));
			$t->appendChild($this->el('ram:RateApplicablePercent', $this->num((float) $rate)));
		}

		$allow = $this->buildDocAllowance($dominantRate);
		if ($allow !== null) {
			$s->appendChild($allow);
		}

		$termsLabel = $this->paymentTermsLabel();
		if ($termsLabel !== '' || !empty($inv->date_lim_reglement)) {
			$terms = $s->appendChild($this->el('ram:SpecifiedTradePaymentTerms'));
			if ($termsLabel !== '') {
				$terms->appendChild($this->el('ram:Description', $termsLabel));
			}
			if (!empty($inv->date_lim_reglement)) {
				$terms->appendChild($this->dateEl('ram:DueDateDateTime', $inv->date_lim_reglement));
			}
		}

		$sum = $s->appendChild($this->el('ram:SpecifiedTradeSettlementHeaderMonetarySummation'));
		$linesHT = 0.0;
		foreach ((array) $inv->lines as $l) {
			$linesHT += (float) $l->total_ht;
		}
		$paid = method_exists($inv, 'getSommePaiement') ? (float) $inv->getSommePaiement() : 0.0;
		$sum->appendChild($this->amount('ram:LineTotalAmount', $linesHT));
		$sum->appendChild($this->amount('ram:TaxBasisTotalAmount', (float) $inv->total_ht));
		$taxTotal = $sum->appendChild($this->amount('ram:TaxTotalAmount', (float) $inv->total_tva));
		$taxTotal->setAttribute('currencyID', $this->currency);
		$sum->appendChild($this->amount('ram:GrandTotalAmount', (float) $inv->total_ttc));
		// BT-113: required for the BR-FXEXT-CO-16 arithmetic
		// (DuePayable = GrandTotal - TotalPrepaid).
		$sum->appendChild($this->amount('ram:TotalPrepaidAmount', $paid));
		$sum->appendChild($this->amount('ram:DuePayableAmount', (float) $inv->total_ttc - $paid));

		$creditRef = $this->buildCreditNoteReference();
		if ($creditRef !== null) {
			$s->appendChild($creditRef);
		}
		return $s;
	}

	/** Payment terms label (BT-20), translated the same way the PDF templates do. */
	private function paymentTermsLabel()
	{
		global $langs;
		$inv = $this->invoice;
		$code = (string) ($inv->cond_reglement_code ?? '');
		if ($code !== '' && is_object($langs)) {
			$trans = $langs->transnoentities('PaymentCondition'.$code);
			if ($trans !== 'PaymentCondition'.$code) {
				return $trans;
			}
		}
		return (string) ($inv->cond_reglement_doc ?? ($inv->cond_reglement_label ?? ''));
	}

	private function buildPaymentMeans()
	{
		$code = strtoupper((string) ($this->invoice->mode_reglement_code ?? ''));
		$modeCode = $code !== '' ? (self::$paymentMap[$code] ?? '1') : '';
		$account = $this->fetchBankAccount();
		if ($modeCode === '' && $account === null) {
			return null;
		}

		$m = $this->el('ram:SpecifiedTradeSettlementPaymentMeans');
		$m->appendChild($this->el('ram:TypeCode', $modeCode !== '' ? $modeCode : '1'));

		if ($account !== null && !empty($account->iban)) {
			$payee = $m->appendChild($this->el('ram:PayeePartyCreditorFinancialAccount'));
			$payee->appendChild($this->el('ram:IBANID', (string) $account->iban));
			if (!empty($account->owner_name)) {
				$payee->appendChild($this->el('ram:AccountName', (string) $account->owner_name));
			}
		}
		if ($account !== null && !empty($account->bic)) {
			$inst = $m->appendChild($this->el('ram:PayeeSpecifiedCreditorFinancialInstitution'));
			$inst->appendChild($this->el('ram:BICID', (string) $account->bic));
		}
		return $m;
	}

	private function fetchBankAccount()
	{
		if (empty($this->invoice->fk_account) || empty($this->invoice->db)) {
			return null;
		}
		require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
		$a = new Account($this->invoice->db);
		return $a->fetch((int) $this->invoice->fk_account) > 0 ? $a : null;
	}

	private function buildDocAllowance($dominantRate)
	{
		$amount = (float) ($this->invoice->remise_absolue ?? 0);
		if ($amount <= 0) {
			return null;
		}
		$a = $this->el('ram:SpecifiedTradeAllowanceCharge');
		$ind = $a->appendChild($this->el('ram:ChargeIndicator'));
		$ind->appendChild($this->doc->createElementNS(self::NS_UDT, 'udt:Indicator', 'false'));
		$a->appendChild($this->amount('ram:ActualAmount', $amount));
		$a->appendChild($this->el('ram:Reason', 'Document-level discount'));

		$t = $a->appendChild($this->el('ram:CategoryTradeTax'));
		$t->appendChild($this->el('ram:TypeCode', 'VAT'));
		if ($this->taxCategory($dominantRate) === 'E') {
			$t->appendChild($this->el('ram:ExemptionReason', self::GERMAN_KU_REASON));
		}
		$t->appendChild($this->el('ram:CategoryCode', $this->taxCategory($dominantRate)));
		$t->appendChild($this->el('ram:RateApplicablePercent', $this->num($dominantRate)));
		return $a;
	}

	private function buildCreditNoteReference()
	{
		$inv = $this->invoice;
		if ((int) ($inv->type ?? 0) !== 2 || empty($inv->fk_facture_source) || empty($inv->db)) {
			return null;
		}
		require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
		$src = new Facture($inv->db);
		if ($src->fetch((int) $inv->fk_facture_source) <= 0 || empty($src->ref)) {
			return null;
		}
		$ref = $this->el('ram:InvoiceReferencedDocument');
		$ref->appendChild($this->el('ram:IssuerAssignedID', (string) $src->ref));
		if (!empty($src->date)) {
			$ref->appendChild($this->dateEl('ram:FormattedIssueDateTime', $src->date, true));
		}
		return $ref;
	}

	/** @return array<string,array{base:float,vat:float}> */
	private function aggregateTaxes()
	{
		$buckets = array();
		foreach ((array) $this->invoice->lines as $l) {
			$rate = (string) (float) $l->tva_tx;
			if (!isset($buckets[$rate])) {
				$buckets[$rate] = array('base' => 0.0, 'vat' => 0.0);
			}
			$buckets[$rate]['base'] += (float) $l->total_ht;
			$buckets[$rate]['vat']  += (float) $l->total_tva;
		}
		return $buckets;
	}

	private function preloadUnitCodes()
	{
		$ids = array();
		foreach ((array) $this->invoice->lines as $l) {
			if (!empty($l->fk_unit)) {
				$ids[(int) $l->fk_unit] = true;
			}
		}
		if (empty($ids) || empty($this->invoice->db)) {
			return;
		}
		$db = $this->invoice->db;
		$sql = 'SELECT rowid, code FROM '.$db->prefix().'c_units WHERE rowid IN ('.implode(',', array_map('intval', array_keys($ids))).')';
		$res = $db->query($sql);
		if (!$res) {
			return;
		}
		while ($row = $db->fetch_object($res)) {
			$this->unitCodeCache[(int) $row->rowid] = self::$unitMap[(string) $row->code] ?? 'C62';
		}
		$db->free($res);
	}

	private function unitCode($line)
	{
		if (!empty($line->fk_unit) && isset($this->unitCodeCache[(int) $line->fk_unit])) {
			return $this->unitCodeCache[(int) $line->fk_unit];
		}
		return 'C62';
	}

	/** § 19 requires an explicit opt-in: non-VAT sellers can have other exemptions. */
	private function taxCategory($rate)
	{
		global $mysoc;
		if ($rate > 0) {
			return 'S';
		}
		if (getDolGlobalString('FACTURX_GERMAN_KLEINUNTERNEHMER') === '1'
			&& strtoupper((string) ($mysoc->country_code ?? '')) === 'DE'
			&& isset($mysoc->tva_assuj) && (string) $mysoc->tva_assuj === '0') {
			return 'E';
		}
		return 'Z';
	}

	private function taxEl($rate)
	{
		$t = $this->el('ram:ApplicableTradeTax');
		$t->appendChild($this->el('ram:TypeCode', 'VAT'));
		if ($this->taxCategory($rate) === 'E') {
			$t->appendChild($this->el('ram:ExemptionReason', self::GERMAN_KU_REASON));
		}
		$t->appendChild($this->el('ram:CategoryCode', $this->taxCategory((float) $rate)));
		$t->appendChild($this->el('ram:RateApplicablePercent', $this->num($rate)));
		return $t;
	}

	/** Create an rsm:* or ram:* element (namespace inferred from prefix) with optional text. */
	private function el($name, $text = null)
	{
		$ns = strpos($name, 'rsm:') === 0 ? self::NS_RSM : self::NS_RAM;
		$el = $this->doc->createElementNS($ns, $name);
		if ($text !== null && $text !== '') {
			$el->appendChild($this->doc->createTextNode((string) $text));
		}
		return $el;
	}

	/** Wraps a date in ram:X > udt:DateTimeString format="102". Use $qdt=true for qdt:DateTimeString. */
	private function dateEl($tag, $date, $qdt = false)
	{
		$ns = $qdt ? self::NS_QDT : self::NS_UDT;
		$prefix = $qdt ? 'qdt' : 'udt';
		$parent = $this->el($tag);
		$ds = $this->doc->createElementNS($ns, $prefix.':DateTimeString', $this->formatDate102($date));
		$ds->setAttribute('format', '102');
		$parent->appendChild($ds);
		return $parent;
	}

	private function amount($name, $value)
	{
		return $this->el($name, $this->num((float) $value));
	}

	private function num($n)
	{
		return number_format((float) $n, 2, '.', '');
	}

	private function formatDate102($date)
	{
		if (empty($date)) {
			return date('Ymd');
		}
		if (is_numeric($date)) {
			return dol_print_date((int) $date, '%Y%m%d');
		}
		$ts = strtotime((string) $date);
		return $ts ? date('Ymd', $ts) : date('Ymd');
	}
}
