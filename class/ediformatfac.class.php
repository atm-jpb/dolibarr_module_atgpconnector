<?php

dol_include_once('/atgpconnector/class/ediformat.class.php');

class EDIFormatFAC extends EDIFormat
{
	public static $remotePath = '/factures/';
	public static $TSegments = array(
		'ATGP' => array(
			'required' => true
			, 'object' => '$object'
		)
		, 'ENT' => array(
			'required' => true
			, 'object' => '$object'
		)
		, 'DTM' => array(
			'required' => false
			, 'multiple' => true
			, 'object' => '$object->_TDTM'
		)
		, 'PAR' => array(
			'required' => true
			, 'object' => '$object'
		)
		, 'PAD' => array(
			'required' => true
			, 'multiple' => true
			, 'object' => '$object->_TPAD'
		)
		, 'COM' => array(
			'required' => false
			, 'multiple' => true
			, 'object' => '$object->_TCOM'
		)
		, 'LIG' => array(
			'required' => true
			, 'multiple' => true
			, 'object' => '$object->lines'
			, 'LID' => array(
				'required' => false
				, 'object' => '$segmentSubObj'
			)
			, 'FTX' => array(
				'multiple' => true
				, 'object' => '$segmentSubObj->TDesc'
			)
		)
		, 'PIE' => array(
			'required' => true
			, 'object' => '$object'
		)
		, 'TVA' => array(
			'required' => true
			, 'multiple' => true
			, 'object' => '$object->_TTVA'
		)
	);

	public function afterObjectLoaded()
	{
		global $conf, $mysoc;


		// Code service
		$this->parseServiceCode();


		// RIB
		dol_include_once('/compta/bank/class/account.class.php');

		$mysoc->_iban = '';
		$mysoc->_bic = '';

		$bankid = empty($this->object->fk_account) ? $conf->global->FACTURE_RIB_NUMBER : $this->object->fk_account;

		if (!empty($this->object->fk_bank)) // For backward compatibility when object->fk_account is forced with object->fk_bank
		{
			$bankid = $this->object->fk_bank;
		}

		if (!empty($bankid)) {
			$account = new Account($this->object->db);
			$account->fetch($bankid);

			$mysoc->_iban = $account->iban;
			$mysoc->_bic = $account->bic;
		}


		if (! empty($this->object->linkedObjects['commande'])) {
			reset($this->object->linkedObjects['commande']);
			$fkey = key($this->object->linkedObjects['commande']);
			$this->object->order = $this->object->linkedObjects['commande'][$fkey];
		}

		// Partenaires (segments PAD) + Codes GLN (segment PAR)
		$this->object->_TPAD = $this->parsePAD();

		// RCS
		$this->parseRCS();

		// Linked objects & dates
		// Tableau initialisé vide pour n'avoir que les champs existants
		$TDTM = array(
			// '11' => '' // Expédition
			// , '35' => '' // Livraison
			// TODO , '263D' => '' // Début de période de facturation
			// TODO , '263F' => '' // Fin de période de facturation
			// , '69' => '' // Livraison promise
		);

		if (! empty($this->object->linkedObjects['shipping'])) {
			reset($this->object->linkedObjects['shipping']);
			$fkey = key($this->object->linkedObjects['shipping']);
			$this->object->shipment = $this->object->linkedObjects['shipping'][$fkey];
			$this->object->shipment->ref_client = $this->object->shipment->ref_customer;
			$this->object->shipment->date = $this->object->shipment->date_shipping;

			if(! empty($this->object->shipment->date_shipping))
			{
				// Date expédition
				$TDTM['11'] = dol_print_date($this->object->shipment->date_shipping, '%d/%m/%Y');

				// Date livraison prévue
				$TDTM['69'] = dol_print_date($this->object->shipment->date_shipping, '%d/%m/%Y');
			}

			if(! empty($this->object->shipment->date_delivery))
			{
				// Date livraison
				$TDTM['35'] = dol_print_date($this->object->shipment->date_delivery, '%d/%m/%Y');
			}


			$this->object->shipment->fetchObjectLinked();

			if(! empty($this->object->shipment->linkedObjects['delivery']))
			{
				reset($this->object->shipment->linkedObjects['delivery']);
				$fkey = key($this->object->shipment->linkedObjects['delivery']);
				$this->object->delivery = $this->object->shipment->linkedObjects['delivery'][$fkey];
				$this->object->delivery->date = $this->object->delivery->date_delivery;

				// Date livraison
				if(! empty($this->object->delivery->date_delivery))
				{
					$TDTM['35'] = dol_print_date($this->object->delivery->date_delivery, '%d/%m/%Y');
				}
			}
			else
			{
				$this->object->delivery = $this->object->shipment;
			}
		}

		// $this->object->order is populate just before $this->object->_TPAD = $this->parsePAD
		if (! empty($this->object->order)) {

			// Date expédition
			if (! empty($this->object->order->date))
			{
				$TDTM['11'] = dol_print_date($this->object->order->date, '%d/%m/%Y');
			}

			// Date livraison
			if (empty($TDTM['35']) && ! empty($this->object->order->date_livraison))
			{
				$TDTM['35'] = dol_print_date($this->object->order->date_livraison, '%d/%m/%Y');
			}

			// Date livraison prévue
			if (empty($TDTM['69']) && ! empty($this->object->order->date_livraison))
			{
				$TDTM['69'] = dol_print_date($this->object->order->date_livraison, '%d/%m/%Y');
			}

		}

		if (! empty($this->object->linkedObjects['contrat'])) {
			reset($this->object->linkedObjects['contrat']);
			$fkey = key($this->object->linkedObjects['contrat']);
			$this->object->contract = $this->object->linkedObjects['contrat'][$fkey];
			$this->object->contract->ref_client = $this->object->contract->ref_customer;
			$this->object->contract->date = $this->object->contract->date_contrat;
		}

		//$this->object->order is populate just before
		if(! empty($this->object->order)) {
			$this->object->object_chorus = $this->object->order;
		} elseif( ! empty($this->object->contract)) {
			$this->object->object_chorus = $this->object->contract;
		} else {
			$this->object->object_chorus = $this->object;
		}


		// Check required fields
		if (empty($this->object->_TPAD['SU']->context['GLNcode']) || ctype_space($this->object->_TPAD['SU']->context['GLNcode']))
		{
			$this->appendError('ATGPC_ErrorRequiredField', $this->object->ref, 'Code GLN vendeur');
		}

		if (empty($this->object->_TPAD['IV']->context['GLNcode']) || ctype_space($this->object->_TPAD['IV']->context['GLNcode']))
		{
			$this->appendError('ATGPC_ErrorRequiredField', $this->object->ref, 'Code GLN payeur');
		}

		if (empty($this->object->_TPAD['BY']->context['GLNcode']) || ctype_space($this->object->_TPAD['BY']->context['GLNcode']))
		{
			$this->appendError('ATGPC_ErrorRequiredField', $this->object->ref, 'Code GLN acheteur');
		}

		if (empty($this->object->_TPAD['DP']->context['GLNcode']) || ctype_space($this->object->_TPAD['DP']->context['GLNcode']))
		{
			$this->appendError('ATGPC_ErrorRequiredField', $this->object->ref, 'Code GLN destinataire');
		}

		if (empty($this->object->_TPAD['RE']->context['GLNcode']) || ctype_space($this->object->_TPAD['RE']->context['GLNcode']))
		{
			$this->appendError('ATGPC_ErrorRequiredField', $this->object->ref, 'Code GLN encaisseur');
		}

		// TVA

		$TTVA = array();
		$TCOM = array(); // Gestion des titres

		$sign = 1;
		if (isset($this->object->type) && $this->object->type == 2 && !empty($conf->global->INVOICE_POSITIVE_CREDIT_NOTE)) $sign = -1;

		foreach ($this->object->lines as $i => &$line) {
			if ($line->product_type == 9) { // Gestion des titres
				$com = (!empty($line->label)) ? $line->label : '';
				$com .= (!empty($line->label) && (!empty($line->description))) ? ' - ' : '';
				$com .= (!empty($line->description)) ? $line->description : '';
				$com = str_replace(';', ' ', $com); // Suppression caractère ";"
				$com = str_replace("\n", ', ', $com); // Suppression des sauts de ligne
				$TCOM[$i] = new stdClass();
				$TCOM[$i]->commentaire = str_replace(';', ' ', $com);
				unset($this->object->lines[$i]);
				continue;
			} else {
				$line->TDesc = str_split(str_replace(array("\r\n", "\n\r", "\n", "\r"), ' ', strip_tags($line->description)), 350);
				foreach ($line->TDesc as $k => $v) {
					if (ctype_space($v) || empty($v)) unset($line->TDesc[$k]);
				}
				if (!empty($line->date_start) && !empty($line->date_end)) {
					$line->TDesc[] = dol_print_date($line->date_start, '%d/%m/%Y') . ' - ' . dol_print_date($line->date_end, '%d/%m/%Y');
				}
			}

			if ($line->fk_product > 0) {
				$product = new Product($this->object->db);
				$product->fetch(intval($line->fk_product));

				$line->product_barcode = $product->barcode;
			} else {
				$line->product_barcode = str_repeat('9', 13);
			}

			$total_ht = 0;
			if ($line->special_code != 3) {
				$total_ht = $sign * ($conf->multicurrency->enabled && $this->object->multicurrency_tx != 1 ? $line->multicurrency_total_ht : $line->total_ht);
			}

			// Collecte des totaux par valeur de tva dans $this->tva["taux"]=total_tva
			$prev_progress = $line->get_prev_progress($this->object->id);
			if ($prev_progress > 0 && !empty($line->situation_percent)) // Compute progress from previous situation
			{
				if ($conf->multicurrency->enabled && $this->object->multicurrency_tx != 1) $tvaligne = $sign * $line->multicurrency_total_tva * ($line->situation_percent - $prev_progress) / $line->situation_percent;
				else $tvaligne = $sign * $line->total_tva * ($line->situation_percent - $prev_progress) / $line->situation_percent;
			} else {
				if ($conf->multicurrency->enabled && $this->object->multicurrency_tx != 1) $tvaligne = $sign * $line->multicurrency_total_tva;
				else $tvaligne = $sign * $line->total_tva;
			}

			$localtax1ligne = $line->total_localtax1;
			$localtax2ligne = $line->total_localtax2;
			$localtax1_rate = $line->localtax1_tx;
			$localtax2_rate = $line->localtax2_tx;
			$localtax1_type = $line->localtax1_type;
			$localtax2_type = $line->localtax2_type;

			if ($this->object->remise_percent) $tvaligne -= ($tvaligne * $this->object->remise_percent) / 100;
			if ($this->object->remise_percent) $localtax1ligne -= ($localtax1ligne * $this->object->remise_percent) / 100;
			if ($this->object->remise_percent) $localtax2ligne -= ($localtax2ligne * $this->object->remise_percent) / 100;

			$vatrate = (string)$line->tva_tx;

			// Retrieve type from database for backward compatibility with old records
			if ((!isset($localtax1_type) || $localtax1_type == '' || !isset($localtax2_type) || $localtax2_type == '') // if tax type not defined
				&& (!empty($localtax1_rate) || !empty($localtax2_rate))) // and there is local tax
			{
				$localtaxtmp_array = getLocalTaxesFromRate($vatrate, 0, $this->object->thirdparty, $mysoc);
				$localtax1_type = $localtaxtmp_array[0];
				$localtax2_type = $localtaxtmp_array[2];
			}

			// retrieve global local tax
			if ($localtax1_type && $localtax1ligne != 0)
				$this->localtax1[$localtax1_type][$localtax1_rate] += $localtax1ligne;
			if ($localtax2_type && $localtax2ligne != 0)
				$this->localtax2[$localtax2_type][$localtax2_rate] += $localtax2ligne;

			if (($line->info_bits & 0x01) == 0x01) $vatrate .= '*';

			if (!isset($TTVA[$vatrate])) {
				$TTVA[$vatrate] = new stdClass();
				$TTVA[$vatrate]->totalHT = 0;
				$TTVA[$vatrate]->totalTVA = 0;
			}

			$TTVA[$vatrate]->totalHT += $total_ht;
			$TTVA[$vatrate]->totalTVA += $tvaligne;
		}

		$this->object->lines = array_values($this->object->lines); // Pour redémarrer la numérotation des lignes

		$this->object->_TDTM = $TDTM;
		$this->object->_TTVA = $TTVA;
		$this->object->_TCOM = $TCOM;
	}


	protected function parseRCS()
	{
		foreach($this->object->_TPAD as $type => &$company)
		{
			$rcsRaw = $company->idprof4;
			$TRCSRaw = preg_split("/\s+/", $rcsRaw);

			$rcsCode = '';
			$rcsCodeLength = 0;

			while ($rcsCodeLength < 10 && !empty($TRCSRaw)) {
				$rcsCodeChunk = array_pop($TRCSRaw);
				$rcsCode = $rcsCodeChunk . $rcsCode;
				$rcsCodeLength += strlen($rcsCodeChunk);
			}

			$rcsCity = implode(' ', $TRCSRaw);

			if (strlen($rcsCode) > 10) {
				$TMatches = array();

				preg_match('/^(.+)(.{10})$/', $rcsCode, $TMatches);

				$rcsCity .= ' ' . $TMatches[1];
				$rcsCode = $TMatches[2];
			}

			$company->_rcsCity = $rcsCity;
			$company->_rcsCode = $rcsCode;
		}
	}


	protected function parseServiceCode()
	{
		foreach ($this->object->_TContacts as $contactDescriptor) {
			if ($contactDescriptor['code'] == 'CHORUS_SERVICE') {
				$this->object->thirdparty->_chorusServiceCode = $contactDescriptor['_contact']->array_options['options_service_code'];

				break;
			}
		}
	}


	protected function parsePAD()
	{
		global $mysoc, $conf;

		// Tableau initialisé vide pour n'avoir que les champs existants
		$TPAD = array(
			// 'IV' => null // Facturé à
			// , 'SU' => null // Facturé par
			// , 'BY' => null // Commandé par
			// , 'DP' => null // Livré à
			// TODO , 'UC' => null // Destinataire final
			// TODO , 'CO' => null // Siège social vendeur
			// , 'RE' => null // Régler à
		);

		foreach ($this->object->_TContacts as $contactDescriptor)
		{
			// TODO Voir pour le cas où la société qui établit la facture est différent de celle qui reçoit le paiement
			if ($contactDescriptor['source'] != 'external')
			{
				continue;
			}

			switch($contactDescriptor['code'])
			{
				case 'BILLING':
					$type = 'IV';
					break;

				case 'SHIPPING':
					$type = 'DP';
					break;

				case 'SERVICE':
					$type = 'BY';
					break;

				default:
					// dans un switch, continue est équivalent à break => on passe 2 en paramètre pour continue le foreach
					continue 2;
			}

			if (! empty($TPAD[$type]))
			{
				continue;
			}

			$company = new Societe($this->object->db);
			$company->fetch($contactDescriptor['socid']);

			if (! empty($contactDescriptor['_contact']->array_options['options_GLN_code']))
			{
				$company->context['GLNcode'] = str_replace(' ', '', $contactDescriptor['_contact']->array_options['options_GLN_code']);
			}
			else
			{
				$company->context['GLNcode'] = str_replace(' ', '', $company->barcode);
			}

			$TPAD[$type] = $company;
		}


		$mysoc->context['GLNcode'] = str_replace(' ', '', $conf->global->MAIN_INFO_SOCIETE_GENCOD);

		$TPAD['SU'] = $mysoc; // Facturé par
		$TPAD['RE'] = $mysoc; // Régler à

		$this->object->thirdparty->context['GLNcode'] = str_replace(' ', '', $this->object->thirdparty->barcode);

		if(empty($TPAD['IV'])) // Facturé à
		{
			$TPAD['IV'] = $this->object->thirdparty;
		}


		if(empty($TPAD['BY'])) // Commandé par
		{
			$TPAD['BY'] = $this->object->thirdparty;
		}

		if(empty($TPAD['DP'])) // Livré à
		{
			$TPAD['DP'] = $this->object->thirdparty;
		}


		return $TPAD;
	}


	public function afterCSVGenerated($tmpPath)
	{
		$fileName = basename($tmpPath);

		$this->object->attachedfiles = array(
			'paths' => array($tmpPath)
			, 'names' => array($fileName)
		);
	}
}


class EDIFormatFACSegmentATGP extends EDIFormatSegment
{
	public static $TFields = array(
		1 => array(
			'label' => 'Etiquette de segment "@GP"'
			, 'data' => '"@GP"'
			, 'maxLength' => 3
			, 'required' => true
		)
		, 2 => array(
			'label' => 'Logiciel'
			, 'data' => '"WEB@EDI"'
			, 'maxLength' => 7
			, 'required' => true
		)
		, 3 => array(
			'label' => 'Données contenues'
			, 'data' => '"INVOIC"'
			, 'maxLength' => 6
			, 'required' => true
		)
		, 4 => array(
			'label' => 'Format de fichier'
			, 'data' => '"STANDARD"'
			, 'maxLength' => 8
			, 'required' => true
		)
		, 5 => array(
			'label' => 'Code émetteur'
			, 'data' => 'str_replace(" ", "", $mysoc->idprof2)'
			, 'maxLength' => 14
		)
		, 6 => array(
			'label' => 'Qualifiant émetteur'
			, 'data' => '""'
			, 'maxLength' => 3
		)
		, 7 => array(
			'label' => 'Code destinataire'
			, 'data' => ''
			, 'maxLength' => 14
		)
		, 8 => array(
			'label' => 'Qualifiant émetteur'
			, 'data' => '""'
			, 'maxLength' => 3
		)
	);
}


class EDIFormatFACSegmentENT extends EDIFormatSegment
{
	public static $TFields = array(
		1 => array(
			'label' => 'Étiquette de segment "ENT"'
			, 'data' => '"ENT"'
			, 'maxLength' => 3
			, 'required' => true
		)
		, 2 => array(
			'label' => 'Numéro de commande du client (Si Chorus : numéro d\'engagement)'
			, 'data' => '$object->object_chorus->ref_client'
			, 'maxLength' => 70
			, 'required' => true
		)
		, 3 => array(
			'label' => 'Date de commande JJ/MM/AAAA'
			, 'data' => 'dol_print_date($object->object_chorus->date, "%d/%m/%Y")'
			, 'maxLength' => 10
			, 'required' => true
		)
		, 4 => array(
			'label' => 'Heure de commande HH:MN'
			, 'data' => 'dol_print_date($object->object_chorus->date, "%H:%M")'
			, 'maxLength' => 5
		)
		, 5 => array(
			'label' => 'Date du message JJ/MM/AAAA'
			, 'data' => ''
			, 'maxLength' => 10
		)
		, 6 => array(
			'label' => 'Heure du message HH:MN'
			, 'data' => ''
			, 'maxLength' => 5
		)
		, 7 => array(
			'label' => 'Date du BL JJ/MM/AAAA'
			, 'data' => 'dol_print_date($object->delivery->date_delivery, "%d/%m/%Y")'
			, 'maxLength' => 10
			, 'required' => true
		)
		, 8 => array(
			'label' => 'Numéro de BL'
			, 'data' => '$object->delivery->ref'
			, 'maxLength' => 35
			, 'required' => true
		)
		, 9 => array(
			'label' => 'Date avis d\'expédition JJ/MM/AAAA'
			, 'data' => 'dol_print_date($object->shipment->date_delivery, "%d/%m/%Y")'
			, 'maxLength' => 10
		)
		, 10 => array(
			'label' => 'Numéro de l\'avis d\'expédition'
			, 'data' => '$object->shipment->ref'
			, 'maxLength' => 35
		)
		, 11 => array(
			'label' => 'Date d\'enlèvement JJ/MM/AAAA'
			, 'data' => ''
			, 'maxLength' => 10
		)
		, 12 => array(
			'label' => 'Heure d\'enlèvement HH:MN'
			, 'data' => ''
			, 'maxLength' => 5
		)
		, 13 => array(
			'label' => 'Numéro de document'
			, 'data' => '$object->ref'
			, 'maxLength' => 35
			, 'required' => true
		)
		, 14 => array(
			'label' => 'Date/heure document JJ/MM/AAAA HH:MN'
			, 'data' => 'dol_print_date($object->date, "%d/%m/%Y %H:%M")'
			, 'maxLength' => 16
			, 'required' => true
		)
		, 15 => array(
			'label' => 'Date d\'échéance JJ/MM/AAAA'
			, 'data' => 'dol_print_date($object->date_lim_reglement, "%d/%m/%Y")'
			, 'maxLength' => 10
			, 'required' => true
		)
		, 16 => array(
			'label' => 'Type de document (Facture/Avoir)'
			, 'data' => '$object->type == Facture::TYPE_CREDIT_NOTE ? "Avoir" : "Facture"'
			, 'maxLength' => 7
			, 'required' => true
		)
		, 17 => array(
			'label' => 'Code monnaie (EUR pour Euro)'
			, 'data' => '! empty($object->multicurrency_code) ? $object->multicurrency_code : $conf->currency'
			, 'maxLength' => 3
			, 'required' => true
		)
		, 18 => array(
			'label' => 'Date d\'échéance pour l\'escompte JJ/MM/AAAA'
			, 'data' => ''
			, 'maxLength' => 10
		)
		, 19 => array(
			'label' => 'Montant de l\'escompte (le pourcentage de l\'escompte est préconisé)'
			, 'data' => ''
			, 'maxLength' => 10
		)
		, 20 => array(
			'label' => 'Numéro de facture (obligatoire si avoir)'
			, 'data' => '' // TODO
			, 'maxLength' => 35
		)
		, 21 => array(
			'label' => 'Date de facture JJ/MM/AAAA (obligatoire si avoir)'
			, 'data' => '' // TODO
			, 'maxLength' => 10
		)
		, 22 => array(
			'label' => 'Pourcentage escompte (préférez le poucentage au montant)'
			, 'data' => ''
			, 'maxLength' => 6
		)
		, 23 => array(
			'label' => 'Nombre de jours escompte'
			, 'data' => ''
			, 'maxLength' => 3
		)
		, 24 => array(
			'label' => 'Pourcentage pénalité'
			, 'data' => ''
			, 'maxLength' => 6
		)
		, 25 => array(
			'label' => 'Nombre de jours pénalité'
			, 'data' => ''
			, 'maxLength' => 3
		)
		, 26 => array(
			'label' => 'Document de test (1/0)'
			, 'data' => '! empty($conf->global->ATGPCONNECTOR_DEV_MODE) ? 1 : 0'
			, 'maxLength' => 1
			, 'required' => true
		)
		, 27 => array(
			'label' => 'Code paiement (cf table ENT.27)'
			, 'data' => '42' // TODO
			, 'maxLength' => 3
			, 'required' => true
		)
		, 28 => array(
			'label' => 'Nature document (MAR pour marchandise et SRV pour service)'
			, 'data' => '"MAR"' // TODO
			, 'maxLength' => 3
			, 'required' => true
		)
		, 29 => array(
			'label' => 'Montant de l\'indemnité forfaitaire'
			, 'data' => ''
			, 'maxLength' => 10
		)
		, 30 => array(
			'label' => 'Sous-type de document (cf table ENT.30)'
			, 'data' => ''
			, 'maxLength' => 35
		)
		, 31 => array(
			'label' => 'Mode de transmission (émis, reçu)'
			, 'data' => ''
			, 'maxLength' => 10
		)
		, 32 => array(
			'label' => 'Version (original, complémentaire)'
			, 'data' => ''
			, 'maxLength' => 35
		)
		, 33 => array(
			'label' => 'Lien URL de la facture sur serveur démat privé enseigne (enseigne seulement)'
			, 'data' => ''
			, 'maxLength' => 100
		)
	);
}


class EDIFormatFACSegmentDTM extends EDIFormatSegment
{
	public static $TFields = array(
		1 => array(
			'label' => 'Étiquette de segment "DTM"'
			, 'data' => '"DTM"'
			, 'maxLength' => 3
			, 'required' => true
		)
		, 2 => array(
			'label' => 'Code date (cf. table DTM)'
			, 'data' => '$key'
			, 'maxLength' => 4
			, 'required' => true
		)
		, 3 => array(
			'label' => 'Date JJ/MM/AAAA'
			, 'data' => '$object'
			, 'maxLength' => 10
			, 'required' => true
		)
	);
}



class EDIFormatFACSegmentPAR extends EDIFormatSegment
{
	public static $TFields = array(
		1 => array(
			'label' => 'Étiquette de segment "PAR"'
			, 'data' => '"PAR"'
			, 'maxLength' => 3
			, 'required' => true
		)
		, 2 => array(
			'label' => 'Code EAN client (commandé par)'
			, 'data' => '$object->_TPAD["BY"]->context["GLNcode"]'
			, 'maxLength' => 13
			, 'required' => true
		)
		, 3 => array(
			'label' => 'Libellé client'
			, 'data' => '$object->_TPAD["BY"]->name'
			, 'maxLength' => 35
		)
		, 4 => array(
			'label' => 'Code EAN fournisseur (commande à)'
			, 'data' => '$object->_TPAD["SU"]->context["GLNcode"]'
			, 'maxLength' => 13
			, 'required' => true
		)
		, 5 => array(
			'label' => 'Libellé fournisseur'
			, 'data' => '$object->_TPAD["SU"]->name'
			, 'maxLength' => 35
		)
		, 6 => array(
			'label' => 'Code EAN client livré'
			, 'data' => '$object->_TPAD["DP"]->context["GLNcode"]'
			, 'maxLength' => 13
			, 'required' => true
		)
		, 7 => array(
			'label' => 'Libellé client livré'
			, 'data' => '$object->_TPAD["DP"]->name'
			, 'maxLength' => 35
		)
		, 8 => array(
			'label' => 'Code EAN client facturé'
			, 'data' => '$object->_TPAD["IV"]->context["GLNcode"]'
			, 'maxLength' => 13
			, 'required' => true
		)
		, 9 => array(
			'label' => 'Libellé client facturé'
			, 'data' => '$object->_TPAD["IV"]->name'
			, 'maxLength' => 35
		)
		, 10 => array(
			'label' => 'Code EAN factor (obligatoire si factor)'
			, 'data' => ''
			, 'maxLength' => 13
		)
		, 11 => array(
			'label' => 'Libellé alias factor (obligatoire si factor)'
			, 'data' => ''
			, 'maxLength' => 35
		)
		, 12 => array(
			'label' => 'Code EAN régler à'
			, 'data' => '$object->_TPAD["RE"]->context["GLNcode"]'
			, 'maxLength' => 13
			, 'required' => true
		)
		, 13 => array(
			'label' => 'Libellé régler à'
			, 'data' => '$object->_TPAD["RE"]->name'
			, 'maxLength' => 35
		)
		, 14 => array(
			'label' => 'Code EAN siège social vendeur (obligatoire si différent du code EAN vendeur)'
			, 'data' => '' // TODO
			, 'maxLength' => 13
		)
		, 15 => array(
			'label' => 'Libellé siège social vendeur'
			, 'data' => '' // TODO
			, 'maxLength' => 35
		)
		, 16 => array(
			'label' => 'Code client normalisé @GP (obligatoire si codification interne partenaire)'
			, 'data' => ''
			, 'maxLength' => 35
		)
	);
}


class EDIFormatFACSegmentPAD extends EDIFormatSegment
{
	public static $TFields = array(
		1 => array(
			'label' => 'Étiquette de segment "PAD"'
			, 'data' => '"PAD"'
			, 'maxLength' => 3
			, 'required' => true
		)
		, 2 => array(
			'label' => 'Code EAN'
			, 'data' => 'str_replace(" ", "", $object->context["GLNcode"])'
			, 'maxLength' => 13
			, 'required' => true
		)
		, 3 => array(
			'label' => 'Raison sociale'
			, 'data' => '$object->nom'
			, 'maxLength' => 35
			, 'required' => true
		)
		, 4 => array(
			'label' => 'Type de partenaire (cf table PAD.4)'
			, 'data' => '$key'
			, 'maxLength' => 3
			, 'required' => true
		)
		, 5 => array(
			'label' => 'Adresse'
			, 'data' => 'explode("\n", $object->address)[0]'
			, 'maxLength' => 35
			, 'required' => true
		)
		, 6 => array(
			'label' => 'Adresse ligne 2'
			, 'data' => 'explode("\n", $object->address)[1]'
			, 'maxLength' => 35
		)
		, 7 => array(
			'label' => 'Adresse ligne 3'
			, 'data' => 'str_replace("\n", " ", explode("\n", $object->address, 3)[2])'
			, 'maxLength' => 35
		)
		, 8 => array(
			'label' => 'Code postal'
			, 'data' => '$object->zip'
			, 'maxLength' => 9
			, 'required' => true
		)
		, 9 => array(
			'label' => 'Ville'
			, 'data' => '$object->town'
			, 'maxLength' => 35
		)
		, 10 => array(
			'label' => 'Code pays'
			, 'data' => '$object->country_code'
			, 'maxLength' => 3
		)
		, 11 => array(
			'label' => 'SIREN'
			, 'data' => 'str_replace(" ", "", $object->idprof1)'
			, 'maxLength' => 35
		)
		, 12 => array(
			'label' => 'Numéro d\'identification TVA'
			, 'data' => 'str_replace(" ", "", $object->tva_intra)'
			, 'maxLength' => 35
		)
		, 13 => array(
			'label' => 'RCS Ville'
			, 'data' => '$object->_rcsCity'
			, 'maxLength' => 35
		)
		, 14 => array(
			'label' => 'RCS Numéro'
			, 'data' => '$object->_rcsCode'
			, 'maxLength' => 35
		)
		, 15 => array(
			'label' => 'Capital social'
			, 'data' => '$object->capital'
			, 'maxLength' => 35
		)
		, 16 => array(
			'label' => 'Forme juridique'
			, 'data' => '$object->forme_juridique_code > 0 ? $object->forme_juridique : ""'
			, 'maxLength' => 35
		)
		, 17 => array(
			'label' => 'Référence fournisseur chez la centrale'
			, 'data' => '""'
			, 'maxLength' => 35
		)
		, 18 => array(
			'label' => 'Code interne partenaire chez le client'
			, 'data' => '""'
			, 'maxLength' => 35
		)
		, 19 => array(
			'label' => 'Code service'
			, 'data' => '$object->_chorusServiceCode'
			, 'maxLength' => 35
		)
		, 20 => array(
			'label' => 'SIRET'
			, 'data' => 'str_replace(" ", "", $object->idprof2)'
			, 'maxLength' => 35
			, 'required' => true
		)
		, 21 => array(
			'label' => 'IBAN'
			, 'data' => '$object->_iban'
			, 'maxLength' => 35
		)
		, 22 => array(
			'label' => 'SWIFT/BIC'
			, 'data' => '$object->_bic'
			, 'maxLength' => 35
		)
		, 23 => array(
			'label' => 'Téléphone'
			, 'data' => '$object->phone'
			, 'maxLength' => 35
		)
		, 24 => array(
			'label' => 'Fax'
			, 'data' => '$object->fax'
			, 'maxLength' => 35
		)
		, 25 => array(
			'label' => 'Email'
			, 'data' => '$object->email'
			, 'maxLength' => 35
		)
		, 26 => array(
			'label' => 'Libellé code service (Chorus)'
			, 'data' => '""'
			, 'maxLength' => 50
		)
	);
}


class EDIFormatFACSegmentLIG extends EDIFormatSegment
{
	public static $TFields = array(
		1 => array(
			'label' => 'Étiquette de segment "LIG"'
			, 'data' => '"LIG"'
			, 'maxLength' => 3
			, 'required' => true
		)
		, 2 => array(
			'label' => 'Numéro de ligne (de 1 à n remis à 0 pour chaque facture)'
			, 'data' => '$key + 1'
			, 'maxLength' => 6
			, 'required' => true
		)
		, 3 => array(
			'label' => 'Code EAN produit'
			, 'data' => '! empty($object->product_barcode) ? $object->product_barcode : $object->ref'
			, 'maxLength' => 14
			, 'required' => true
		)
		, 4 => array(
			'label' => 'Code interne produit chez le fournisseur'
			, 'data' => '$object->ref'
			, 'maxLength' => 35
		)
		, 5 => array(
			'label' => 'Par combien (multiple de commande)'
			, 'data' => '1.000' // TODO
			, 'maxLength' => 10 // 10\3
			, 'maxPrecision' => 3
			, 'required' => true
		)
		, 6 => array(
			'label' => 'Quantité commandée'
			, 'data' => ''
			, 'maxLength' => 10 // 10\3
			, 'maxPrecision' => 3
		)
		, 7 => array(
			'label' => 'Unité de quantité (cf table MEA.4)'
			, 'data' => '"PCE"' // TODO
			, 'maxLength' => 3
			, 'required' => true
		)
		, 8 => array(
			'label' => 'Quantité facturée'
			, 'data' => 'price2num($object->qty)'
			, 'maxLength' => 10 // 10\3
			, 'maxPrecision' => 3
			, 'required' => true
		)
		, 9 => array(
			'label' => 'Prix unitaire net'
			, 'data' => 'price2num($object->qty > 0 ? $object->total_ht / $object->qty : 0)'
			, 'maxLength' => 15 // 15\6
			, 'maxPrecision' => 6
			, 'required' => true
		)
		, 10 => array(
			'label' => 'Code monnaie (EUR = euro)'
			, 'data' => '! empty($object->multicurrency_code) ? $object->multicurrency_code : $conf->currency'
			, 'maxLength' => 3
			, 'required' => true
		)
		, 11 => array(
			'label' => 'non utilisé'
			, 'data' => ''
			, 'maxLength' => 0
		)
		, 12 => array(
			'label' => 'non utilisé'
			, 'data' => ''
			, 'maxLength' => 0
		)
		, 13 => array(
			'label' => 'Taux de TVA (par exemple 19,6)'
			, 'data' => 'price2num($object->tva_tx)'
			, 'maxLength' => 5 // 5\2
			, 'maxPrecision' => 2
			, 'required' => true
		)
		, 14 => array(
			'label' => 'Prix unitaire brut'
			, 'data' => 'price2num($object->subprice)'
			, 'maxLength' => 15 // 15\6
			, 'maxPrecision' => 6
			, 'required' => true
		)
		, 15 => array(
			'label' => 'Poids net total ligne'
			, 'data' => ''
			, 'maxLength' => 9 // 9\3
			, 'maxPrecision' => 3
		)
		, 16 => array(
			'label' => 'Libellé ligne de facture (ou libellé du produit)'
			, 'data' => '$object->libelle'
			, 'maxLength' => 70
			, 'required' => true
		)
		, 17 => array(
			'label' => 'Montant net HT total ligne'
			, 'data' => 'price2num($object->total_ht)'
			, 'maxLength' => 17 // 17\2
			, 'maxPrecision' => 2
			, 'required' => true
		)
		, 18 => array(
			'label' => 'Code interne produit chez le client'
			, 'data' => ''
			, 'maxLength' => 35
		)
		, 19 => array(
			'label' => 'Prix net ristournable (obligatoire si TPF et/ou pour la centrale COMAFRANC)'
			, 'data' => ''
			, 'maxLength' => 15 // 15\6
			, 'maxPrecision' => 6
		)
		, 20 => array(
			'label' => 'Montant net ristournable (obligatoire si TPF et/ou pour la centrale COMAFRANC)'
			, 'data' => ''
			, 'maxLength' => 17 // 17\2
			, 'maxPrecision' => 2
		)
		, 21 => array(
			'label' => 'Référence ligne du composé (obligatoire si c\'est un composant)'
			, 'data' => ''
			, 'maxLength' => 35
		)
		, 22 => array(
			'label' => 'Type de sous-ligne (A = Ajouté, I = Inclus) (obligatoire si c\'est un composant)'
			, 'data' => ''
			, 'maxLength' => 5
		)
		, 23 => array(
			'label' => 'Identification de la description en code (obligatoire si c\'est un composé)'
			, 'data' => ''
			, 'maxLength' => 35
		)
		, 24 => array(
			'label' => 'Code EAN de l\'article remplacé'
			, 'data' => ''
			, 'maxLength' => 35
		)
		, 25 => array(
			'label' => 'Unité du par combien (multiple de la commande, table MEA.4)'
			, 'data' => ''
			, 'maxLength' => 3
		)
		, 26 => array(
			'label' => 'Nombre de ligne commande d\'origine'
			, 'data' => ''
			, 'maxLength' => 6
		)
		, 27 => array(
			'label' => 'Prix public TTC (secteur livre)'
			, 'data' => ''
			, 'maxLength' => 15 // 15\6
			, 'maxPrecision' => 6
		)
		, 28 => array(
			'label' => 'Numéro commande d\'origine'
			, 'data' => ''
			, 'maxLength' => 35
		)
		, 29 => array(
			'label' => 'Numéro BL d\'origine'
			, 'data' => ''
			, 'maxLength' => 35
		)
		, 30 => array(
			'label' => 'Numéro ligne BL d\'origine'
			, 'data' => ''
			, 'maxLength' => 6
		)
		, 31 => array(
			'label' => 'Prix brut remisé'
			, 'data' => ''
			, 'maxLength' => 15 // 15\6
			, 'maxPrecision' => 6
		)
		, 32 => array(
			'label' => 'Montant brut remisé'
			, 'data' => ''
			, 'maxLength' => 17 // 17\2
			, 'maxPrecision' => 2
		)
		, 33 => array(
			'label' => 'Date commande d\'origin JJ/MM/AAAA'
			, 'data' => ''
			, 'maxLength' => 10
		)
		, 34 => array(
			'label' => 'Date BL d\'origine JJ/MM/AAAA'
			, 'data' => ''
			, 'maxLength' => 10
		)
		, 35 => array(
			'label' => 'Base Prix unitaire net'
			, 'data' => ''
			, 'maxLength' => 17 // 17\6
			, 'maxPrecision' => 6
		)
		, 36 => array(
			'label' => 'Date début livraison JJ/MM/AAAA'
			, 'data' => ''
			, 'maxLength' => 10
		)
		, 37 => array(
			'label' => 'Date fin livraison JJ/MM/AAAA'
			, 'data' => ''
			, 'maxLength' => 10
		)
		, 38 => array(
			'label' => 'Numéro commande fournisseur'
			, 'data' => ''
			, 'maxLength' => 35
		)
		, 39 => array(
			'label' => 'Date commande fournisseur JJ/MM/AAAA'
			, 'data' => ''
			, 'maxLength' => 10
		)
		, 40 => array(
			'label' => 'Sous-ligne (composant, origine) (obligatoire si composant ou consigne)'
			, 'data' => ''
			, 'maxLength' => 35
		)
		, 41 => array(
			'label' => 'Quantité livrée'
			, 'data' => ''
			, 'maxLength' => 10 // 10\3
			, 'maxPrecision' => 3
		)
		, 42 => array(
			'label' => 'Unité de quantité livrée (cf table MEA.4)'
			, 'data' => ''
			, 'maxLength' => 3
		)
		, 43 => array(
			'label' => 'Code type utilisation'
			, 'data' => ''
			, 'maxLength' => 35
		)
	);
}


class EDIFormatFACSegmentLID extends EDIFormatSegment
{
	public static $TFields = array(
		1 => array(
			'label' => 'Étiquette de segment "LID"'
			, 'data' => '"LID"'
			, 'maxLength' => 3
			, 'required' => true
		)
		, 2 => array(
			'label' => 'Numéro de ligne'
			, 'data' => '$key + 1'
			, 'maxLength' => 6
			, 'required' => true
		)
		, 3 => array(
			'label' => 'Numéro de séquence de calcul'
			, 'data' => '$key + 1' // variable qui contiendra finalement le texte
			, 'maxLength' => 1
			, 'required' => true
		)
		, 4 => array(
			'label' => 'Qualifiant de dégression tarifaire (cf. table LID.4)'
			, 'data' => '"TD"' // TODO en dur
			, 'maxLength' => 10
			, 'required' => true
		)
		, 5 => array(
			'label' => 'Libellé descriptif'
			, 'data' => '"Remise"' // TODO en dur
			, 'maxLength' => 10
			, 'required' => true
		)
		, 6 => array(
			'label' => 'Pourcentage ou montant unitaire'
			, 'data' => 'price2num($object->remise_percent)'
			, 'maxLength' => 12 // 12\6
			, 'maxPrecision' => 6
			, 'required' => true
		)
		, 7 => array(
			'label' => 'Unité de quantité (EUR ou PCT)'
			, 'data' => '"PCT"' // TODO en dur
			, 'maxLength' => 3
			, 'required' => true
		)
		, 8 => array(
			'label' => 'Code EAN de la taxe parafiscale (cf table LID.8)'
			, 'data' => '""'
			, 'maxLength' => 14
		)
		, 9 => array(
			'label' => 'Taux de TVA de la dégression tarifaire ou taxe parafiscale'
			, 'data' => 'price2num($object->tva_tx)'
			, 'maxLength' => 5 // 5\2
			, 'maxPrecision' => 2
			, 'required' => true
		)
		, 10 => array(
			'label' => 'Montant de la dégression, unitaire (si pourcentage indiqué en position 6)'
			, 'data' => 'price2num($object->subprice * $object->remise_percent / 100)'
			, 'maxLength' => 12 // 12\6
			, 'maxPrecision' => 6
		)
		, 11 => array(
			'label' => 'Montant base de calcul (montant sur lequel est appliqué la dégression)'
			, 'data' => 'price2num($object->subprice)'
			, 'maxLength' => 12 // 12\6
			, 'maxPrecision' => 6
			, 'required' => true
		)
		, 12 => array(
			'label' => 'Code règlement (cf table LID.12)'
			, 'data' => '"2"' // 1 => Hors facture, 2 => Déduit de la facture
			, 'maxLength' => 1
			, 'required' => true
		)
	);
}


class EDIFormatFACSegmentFTX extends EDIFormatSegment
{
	public static $TFields = array(
		1 => array(
			'label' => 'Étiquette de segment "FTX"'
			, 'data' => '"FTX"'
			, 'maxLength' => 3
			, 'required' => true
		)
		, 2 => array(
			'label' => 'Code commentaire'
			, 'data' => '"PRD"'
			, 'maxLength' => 3
			, 'required' => true
		)
		, 3 => array(
			'label' => 'Commentaire'
			, 'data' => '$object' // variable qui contiendra finalement le texte
			, 'maxLength' => 350
			, 'required' => true
		)
		, 4 => array(
			'label' => 'Commetaire en code'
			, 'data' => ''
			, 'maxLength' => 10
		)

	);
}

class EDIFormatFACSegmentTVA extends EDIFormatSegment
{
	public static $TFields = array(
		1 => array(
			'label' => 'Etiquette de segment "TVA"'
			, 'data' => '"TVA"'
			, 'maxLength' => 3
			, 'required' => true
		)
		, 2 => array(
			'label' => 'Taux de TVA'
			, 'data' => 'price2num($key)'
			, 'maxLength' => 5 // 5\2
			, 'maxPrecision' => 2
			, 'required' => true
		)
		, 3 => array(
			'label' => 'Montant total soumis à TVA'
			, 'data' => 'price2num($object->totalHT)'
			, 'maxLength' => 10 // 10\2
			, 'maxPrecision' => 2
			, 'required' => true
		)
		, 4 => array(
			'label' => 'Montant de la TVA'
			, 'data' => 'price2num($object->totalTVA)'
			, 'maxLength' => 10 // 10\2
			, 'maxPrecision' => 2
			, 'required' => true
		)
		, 5 => array(
			'label' => 'Code TVA'
			, 'data' => ''
			, 'maxLength' => 35
		)
		, 6 => array(
			'label' => 'Libellé du code TVA'
			, 'data' => ''
			, 'maxLength' => 35
		)
		, 7 => array(
			'label' => 'Identification de compte'
			, 'data' => ''
			, 'maxLength' => 35
		)
	);
}


class EDIFormatFACSegmentPIE extends EDIFormatSegment
{
	public static $TFields = array(
		1 => array(
			'label' => 'Etiquette de segment "PIE"'
			, 'data' => '"PIE"'
			, 'maxLength' => 3
			, 'required' => true
		)
		, 2 => array(
			'label' => 'Montant total hors taxes'
			, 'data' => 'price2num($object->total_ht)'
			, 'maxLength' => 10 // 10\2
			, 'maxPrecision' => 2
			, 'required' => true
		)
		, 3 => array(
			'label' => 'Montant total TVA'
			, 'data' => 'price2num($object->total_tva)'
			, 'maxLength' => 10 // 10\2
			, 'maxPrecision' => 2
			, 'required' => true
		)
		, 4 => array(
			'label' => 'Montant total toutes taxes comprises'
			, 'data' => 'price2num($object->total_ttc)'
			, 'maxLength' => 10 // 10\2
			, 'maxPrecision' => 2
			, 'required' => true
		)
		, 5 => array(
			'label' => 'Montant total net ristournable (obligatoire si TPF)'
			, 'data' => ''
			, 'maxLength' => 10 // 10\2
			, 'maxPrecision' => 2
		)
		, 6 => array(
			'label' => 'Montant total TPF (obligatoire si TPF)'
			, 'data' => ''
			, 'maxLength' => 10 // 10\2
			, 'maxPrecision' => 2
		)
		, 7 => array(
			'label' => 'Montant total des taxes (obligatoire si TPF)'
			, 'data' => ''
			, 'maxLength' => 10 // 10\2
			, 'maxPrecision' => 2
		)
		, 8 => array(
			'label' => 'Montant Total Ristournable Ligne (obligatoire pour la centrale COMAFRANC)'
			, 'data' => ''
			, 'maxLength' => 10 // 10\2
			, 'maxPrecision' => 2
		)
		, 9 => array(
			'label' => 'Montant Total Consigne (obligatoire si gestion consigne)'
			, 'data' => ''
			, 'maxLength' => 10 // 10\2
			, 'maxPrecision' => 2
		)
		, 10 => array(
			'label' => 'Montant Acompte'
			, 'data' => '' // TODO
			, 'maxLength' => 10 // 10\2
			, 'maxPrecision' => 2
		)
		, 11 => array(
			'label' => 'Montant Payable (obligatoire si gestion acompte)'
			, 'data' => '' // TODO
			, 'maxLength' => 10 // 10\2
			, 'maxPrecision' => 2
		)
	);
}

class EDIFormatFACSegmentCOM extends EDIFormatSegment
{
	public static $TFields = array(
		1 => array(
			'label' => 'Etiquette de segment "COM"'
			, 'data' => '"COM"'
			, 'maxLength' => 3
			, 'required' => true
		)
		, 2 => array(
			'label' => 'Qualifiant commentaire (cf table COM.2)'
			, 'data' => '"AAI"'
			, 'maxLength' => 3
			, 'required' => true
		)
		, 3 => array(
			'label' => 'Commentaire'
			, 'data' => '$object->commentaire'
			, 'maxLength' => 350
			, 'required' => true
		)
		, 4 => array(
			'label' => 'Commentaire en code'
			, 'data' => ''
			, 'maxLength' => 10
		)
	);
}
