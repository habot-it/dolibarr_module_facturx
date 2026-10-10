<?php
/* Copyright (C) 2026  Alban DEZANDEE  <alban@habot.it>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, version 3 or later.
 */

/**
 *  \file       htdocs/custom/facturx/admin/setup.php
 *  \ingroup    facturx
 *  \brief      FacturX module setup page: French coded notes and seller endpoint.
 */

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
dol_include_once('/facturx/lib/facturx.lib.php');
dol_include_once('/facturx/class/facturxxml.class.php');

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 * @var Societe $mysoc
 */

$langs->loadLangs(array('admin', 'facturx@facturx'));

if (!$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');

// Constant name => GETPOST filter. Empty value removes the constant so the
// code falls back to its built-in default.
$params = array(
	'FACTURX_HIDE_PDF_LOGO' => 'int',
	'FACTURX_NOTE_PMD' => 'alphanohtml',
	'FACTURX_NOTE_PMT' => 'alphanohtml',
	'FACTURX_NOTE_AAB' => 'alphanohtml',
	'FACTURX_NOTE_TXD' => 'alphanohtml',
	'FACTURX_SELLER_ENDPOINT_ID' => 'alphanohtml',
	'FACTURX_SELLER_ENDPOINT_SCHEME' => 'alphanohtml',
);


/*
 * Actions
 */

if ($action == 'update') {
	$error = 0;
	foreach ($params as $const => $filter) {
		$value = trim(GETPOST($const, $filter));
		if ($value !== '') {
			$res = dolibarr_set_const($db, $const, $value, 'chaine', 0, '', $conf->entity);
		} else {
			$res = dolibarr_del_const($db, $const, $conf->entity);
		}
		if ($res < 0) {
			$error++;
		}
	}
	if (!$error) {
		setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
	} else {
		setEventMessages($langs->trans('Error'), null, 'errors');
	}
}


/*
 * View
 */

$defaults = FacturxXml::defaultNotes();

llxHeader('', $langs->trans('FacturXSetup'), '', '', 0, 0, '', '', '', 'mod-facturx page-admin');

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($langs->trans('FacturXSetup'), $linkback, 'title_setup');

$head = facturxAdminPrepareHead();
print dol_get_fiche_head($head, 'settings', $langs->trans('ModuleFacturXName'), -1, 'fa-file-invoice');

print '<span class="opacitymedium">'.$langs->trans('FacturXSetupPageHelp').'</span><br><br>';

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="update">';

print '<label><input type="checkbox" name="FACTURX_HIDE_PDF_LOGO" value="1"'.(getDolGlobalInt('FACTURX_HIDE_PDF_LOGO') ? ' checked' : '').'> '.$langs->trans('FacturXHidePdfLogo').'</label>';
print '<br><span class="opacitymedium small">'.$langs->trans('FacturXHidePdfLogoHelp').'</span><br><br>';

// --- French coded notes (BR-FR-05/06) ---
print load_fiche_titre($langs->trans('FacturXNotesTitle'), '', '');
print '<div class="opacitymedium">'.$langs->trans('FacturXNotesHelp').'</div><br>';

print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('Parameter').'</td><td>'.$langs->trans('Value').'</td></tr>';

foreach (array('PMD', 'PMT', 'AAB', 'TXD') as $code) {
	$const = 'FACTURX_NOTE_'.$code;
	$current = getDolGlobalString($const);
	$placeholder = ($defaults[$code] !== '') ? $defaults[$code] : $langs->trans('FacturXNoteNoDefault');
	print '<tr class="oddeven">';
	print '<td class="titlefieldmiddle">'.$langs->trans('FacturXNote'.$code);
	print '<br><span class="opacitymedium small">'.$langs->trans('FacturXNote'.$code.'Help').'</span></td>';
	print '<td><textarea name="'.$const.'" rows="2" class="quatrevingtpercent" placeholder="'.dol_escape_htmltag($placeholder).'">'.dol_escape_htmltag($current).'</textarea></td>';
	print '</tr>';
}
print '</table>';
print '</div><br>';

// --- Seller electronic address (BT-34) ---
print load_fiche_titre($langs->trans('FacturXEndpointTitle'), '', '');
print '<div class="opacitymedium">'.$langs->trans('FacturXEndpointHelp').'</div><br>';

$sellerSiren = preg_replace('/\D/', '', (string) ($mysoc->idprof1 ?? ''));

print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('Parameter').'</td><td>'.$langs->trans('Value').'</td></tr>';

print '<tr class="oddeven">';
print '<td class="titlefieldmiddle">'.$langs->trans('FacturXSellerEndpointId');
print '<br><span class="opacitymedium small">'.$langs->trans('FacturXSellerEndpointIdHelp').'</span></td>';
print '<td><input type="text" name="FACTURX_SELLER_ENDPOINT_ID" class="minwidth300" value="'.dol_escape_htmltag(getDolGlobalString('FACTURX_SELLER_ENDPOINT_ID')).'" placeholder="'.dol_escape_htmltag($sellerSiren).'"></td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td class="titlefieldmiddle">'.$langs->trans('FacturXSellerEndpointScheme');
print '<br><span class="opacitymedium small">'.$langs->trans('FacturXSellerEndpointSchemeHelp').'</span></td>';
print '<td><input type="text" name="FACTURX_SELLER_ENDPOINT_SCHEME" class="width100" value="'.dol_escape_htmltag(getDolGlobalString('FACTURX_SELLER_ENDPOINT_SCHEME')).'" placeholder="0225"></td>';
print '</tr>';

print '</table>';
print '</div>';

print '<br><div class="center">';
print '<input type="submit" class="button button-save" value="'.$langs->trans('Save').'">';
print '</div>';

print '</form>';

print dol_get_fiche_end();

llxFooter();
$db->close();
