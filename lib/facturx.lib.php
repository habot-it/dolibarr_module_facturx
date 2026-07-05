<?php
/* Copyright (C) 2026  Alban DEZANDEE  <alban@habot.it>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, version 3 or later.
 */

/**
 *  \file       htdocs/custom/facturx/lib/facturx.lib.php
 *  \ingroup    facturx
 *  \brief      Library for the FacturX module (admin tabs).
 */


/**
 *  Prepare admin pages header tabs.
 *
 *  @return array<array{0:string,1:string,2:string}>  Tabs definition
 */
function facturxAdminPrepareHead()
{
	global $langs, $conf;

	$langs->load('facturx@facturx');

	$h = 0;
	$head = array();

	$head[$h][0] = dol_buildpath('/facturx/admin/setup.php', 1);
	$head[$h][1] = $langs->trans('Settings');
	$head[$h][2] = 'settings';
	$h++;

	complete_head_from_modules($conf, $langs, null, $head, $h, 'facturx@facturx');
	complete_head_from_modules($conf, $langs, null, $head, $h, 'facturx@facturx', 'remove');

	return $head;
}
