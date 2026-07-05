<?php
/* Copyright (C) 2026  Alban DEZANDEE  <alban@habot.it>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 *  \defgroup   facturx     Module FacturX
 *  \brief      FacturX module descriptor.
 *
 *  \file       htdocs/custom/facturx/core/modules/modFacturX.class.php
 *  \ingroup    facturx
 *  \brief      Description and activation file for module FacturX
 */
include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';


/**
 *  Description and activation class for module FacturX
 */
class modFacturX extends DolibarrModules
{
	/**
	 * Constructor.
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;

		$this->numero = 469104;
		$this->rights_class = 'facturx';
		$this->family = "financial";
		$this->module_position = '90';
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = "FacturXDescription";
		$this->descriptionlong = "FacturXDescriptionLong";

		$this->editor_name = 'HABOT IT';
		$this->editor_url = 'www.habot.it';

		$this->version = '1.1.0';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'fa-file-invoice';

		$this->module_parts = array(
			'models' => 1,
		);

		$this->dirs = array();
		$this->config_page_url = array("setup.php@facturx");

		$this->hidden = getDolGlobalInt('MODULE_FACTURX_DISABLED');
		$this->depends = array('modFacture');
		$this->requiredby = array();
		$this->conflictwith = array();

		$this->langfiles = array("facturx@facturx");

		$this->phpmin = array(7, 4);
		$this->need_dolibarr_version = array(19, -3);
		$this->need_javascript_ajax = 0;

		$this->const = array();
		$this->tabs = array();
		$this->dictionaries = array();
		$this->boxes = array();
		$this->cronjobs = array();
		$this->rights = array();
		$this->menu = array();
	}

	/**
	 * Function called when module is enabled.
	 * Registers the Factur-X PDF model for customer invoices.
	 *
	 * @param  string    $options  Options when enabling module ('', 'noboxes')
	 * @return int<-1,1>           1 if OK, <=0 if KO
	 */
	public function init($options = '')
	{
		global $conf;

		$this->remove($options);

		$sql = array(
			"DELETE FROM ".$this->db->prefix()."document_model WHERE nom = 'facturx' AND type = 'invoice' AND entity = ".((int) $conf->entity),
			"INSERT INTO ".$this->db->prefix()."document_model (nom, type, entity) VALUES('facturx', 'invoice', ".((int) $conf->entity).")",
		);

		return $this->_init($sql, $options);
	}

	/**
	 * Function called when module is disabled.
	 *
	 * @param  string    $options  Options when disabling module ('', 'noboxes')
	 * @return int<-1,1>           1 if OK, <=0 if KO
	 */
	public function remove($options = '')
	{
		global $conf;

		$sql = array(
			"DELETE FROM ".$this->db->prefix()."document_model WHERE nom = 'facturx' AND type = 'invoice' AND entity = ".((int) $conf->entity),
		);

		return $this->_remove($sql, $options);
	}
}
