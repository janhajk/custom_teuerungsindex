<?php
/*
 * Klasse zum EVL Import
 * 
 * Parst eine EVL-Liste im CSV Format und speichert diese in einer
 * Temporären MYSQL Tabelle namens "evl_gesamt"
 * 
 * auf die Mysql-tablle kann während der Scriptausführung darauf zugegriffen werden
 * mit der Methode getArray() kann man die EVL als Array Ausgeben
 * 
 * Folgende Daten, welche nicht in der EVL vorhanden sind werden
 * bei der Ausgabe als Array hinzugefügt:
 *   - MWST je Vertrag anhand der MWST-Entwicklung in der CH
 *   - Total für Gesamtvertrag inkl. ZA's
 *   - Total für alle ZA's
 *   - Total aller bezahlten Rechnungen
 * 
 * Das Array hat die folgende Struktur:
 *  Array (
 *    [v] => array (
 *       [vertrag1] => Array (
 *         [vertragsspalten] => ...,
 *         [v_summe_total] => ...,
 *         [za_summe_total] => ...,
 *         [rechnungs_total] => ...,
 *         [mwst] => ...,
 *         [mehrminderkosten] => ...,
 *         [za] => Array (za's ëinzeln inkl aller Spalten'),
 *         [rechnungen] => Array (Alle Rechnungen inkl aller Spalten),
 *       )
 *       [vertrag2] => etc...
 *    ),
 *    [stichtag] => 2010-07-31
 * )
 * 
 * Die Datenbank speichert alle Spalten, welche im Schema enthalten sind (CreateSchema())
 * Die Schema-Id speichert je ein Schema.
 * Wenn sich die Spalten in der Vorlage für die EVL ändern, muss ein neues
 * Schema erstellt werden, bzw. die Spalten können auch überschrieben werden.
 * So können jederzeit auch alte EVL-Dateien wieder eingelesen werden
 * auch wenn sich das Format verändert hat.
 */
class EVL {
	var $pfad = '';
	var $schemaid = 1;
	var $schema = array();
	var $stichtag = '0000-00-00';
	var $data = array();
	
	public function __construct($pfad, $stichtag='0000-00-00') {
		$this->stichtag = $stichtag;
		$this->pfad = $pfad;
		$this->schemaid = $this->getSchemaIdFromDate($stichtag);
		//cell separator, row separator, value enclosure
		$csv = new CSV(';', "\r\n", '"');	
		//parse the string content
		$csv->setContent(file_get_contents($pfad));	
		//returns an array with the CSV data
		$csvcontent = $csv->getArray();
	  //print '<pre>'.print_r($csvcontent,1).'</pre>';
		$this->CreateSchema($this->schemaid);
		db_query($this->schemaMakeCREATE_TABLE());

		foreach ($csvcontent as $row) {
			if(substr($row[5],0,3) != 'HPL') continue; // Nur Projekt HPL aus Vertragsliste lesen
			
			// Clean the Import-Values
			foreach ($this->schema['evlcols'] as $col) {
				switch ($col['type']) {
					case 'float':
						$row[$col['colnr']] = preg_replace('/[^0-9-\.]/','',$row[$col['colnr']]);break;
					case 'str':
						$row[$col['colnr']] = addslashes(utf8_encode($row[$col['colnr']])); break;
					case 'date':
						$row[$col['colnr']] = $this->DateCsv2Mysql($row[$col['colnr']]);
						break;
				}
			}
			$sql = "INSERT INTO evl_gesamt (".implode(',',$this->getCols()).") VALUES (".implode(',',$this->getValues($row)).")";
			//dsm($sql);
			db_query($sql);
		}
	}	
	
	/*
	 * Gibt die EVL als geordnetes Array zurück
	 * macht auch einige Berechnungen und fügt diese
	 * als zusätzliche Spalten ein
	 * -- Hauptvertrag
	 *    -- ZA's
	 *      -- #1 etc.
	 *    -- Rechnungen
	 * 		-- #1 etc.
	 */
	public function getArray($stichtag) {
		
		// Wenn bereits gecached, dann direkt ausgeben
		if (count($this->data)) return $this->data;		
		
		// Offertendatum wenn leer auf Vertragsdatum setzten
		$results = db_query("UPDATE evl_gesamt SET angebotsdatum = datum WHERE zeilenbeschrieb LIKE 'H' AND angebotsdatum = '0000-00-00'");
		// Leere Hauptverträge löschen (es gibt z.T. doppelte HV mit v_summe = 0 welche sonst die richtigen überschreiben)
		//$results = db_query("DELETE FROM evl_gesamt WHERE zeilenbeschrieb = 'H' AND v_summe = '0'");
		// Hauptverträge ohne Vertragsdatum löschen
		$results = db_query("DELETE FROM evl_gesamt WHERE zeilenbeschrieb = 'H' AND datum = '0000-00-00'");
		
		// Alle Eintraege nach Stichtag loeschen
		//
		$v = array();
		// Verträge nach Stichtag
		$results = db_query("SELECT vertragsnummer FROM evl_gesamt WHERE DATEDIFF(datum, '".$stichtag."') > 0 AND zeilenbeschrieb LIKE 'H'");
		while ($r = db_fetch_array($results)) {
		 $v[] = $r['vertragsnummer'];
		}
		//print_r($v);
		// wenn keine Rechnungen vor dem Stichtag, dann kann der Vertrag geloescht werden
		foreach ($v as $vertragsnummer) {
		 $i = false;
		 $results = db_query("SELECT id FROM evl_gesamt WHERE art LIKE 'R' AND rechnungsdatum <= '".$stichtag."'");
		 while ($r = db_fetch_array($results)) {
		 	$i = true;
		 }
		 if (!$i) { db_query("DELETE FROM evl_gesamt WHERE vertragsnummer = '".$vertragsnummer."'");}
		}
		
		// Rechnungen nach Stichtag alle loeschen
		$results = db_query("DELETE FROM evl_gesamt WHERE rechnungsdatum > '".$stichtag."' AND zeilenbeschrieb LIKE 'R'");

    // Rechnungen ohne Weiterleitungsdatum loeschen (Dies betrifft ganz neue Rechnungen)
		//$results = db_query("DELETE FROM evl_gesamt WHERE weiterleitunganrw = '0000-00-00' AND zeilenbeschrieb LIKE 'R'");
		
		// Bestimmte Vertraege loeschen
		$results = db_query("DELETE FROM evl_gesamt WHERE vertragsnummer = 901322 OR vertragsnummer = 901323 OR vertragsnummer = 901134");
    $results = db_query("DELETE FROM evl_gesamt WHERE v_summe < 0 OR rechnungsbetrag < 0");
		
		// Alle Hauptverträge
		$k = array();
		$results = db_query("SELECT * FROM evl_gesamt WHERE v_summe != 0 AND zeilenbeschrieb LIKE 'H' ORDER BY angebotsdatum ASC");
		while ($r = db_fetch_array($results)) {
			$mwst = $this->MWST($r);
			$k[$r['vertragsnummer']]                               = $r;
			$k[$r['vertragsnummer']]['v_summe_total']              = $r['v_summe'];
			$k[$r['vertragsnummer']]['v_summe_ohne_mwst']          = $r['v_summe']/(1+$mwst);
			$k[$r['vertragsnummer']]['za_summe_total']             = 0;
			$k[$r['vertragsnummer']]['rechnungs_total']            = 0;
			$k[$r['vertragsnummer']]['mwst']                       = $mwst;
			$k[$r['vertragsnummer']]['mehrminderkosten']           = $r['v_summe'];
			$k[$r['vertragsnummer']]['v_summe_total_ohne_mwst']    = $r['v_summe']/(1+$mwst);
			$k[$r['vertragsnummer']]['mehrminderkosten_ohne_mwst'] = $r['v_summe']/(1+$mwst);
			$k[$r['vertragsnummer']]['za']                         = array();
			$k[$r['vertragsnummer']]['rechnungen']                 = array();
			
		}
		// Alle Zusatzaufträge
		$results = db_query("SELECT * FROM evl_gesamt WHERE za_summe != 0 AND zeilenbeschrieb LIKE 'ZA' ORDER BY zanr ASC");
		while ($r = db_fetch_array($results)) {
			$mwst = $this->MWST($r);
			$k[$r['vertragsnummer']]['za'][$r['zanr']]['mwst']      = $mwst;
			$k[$r['vertragsnummer']]['za'][$r['zanr']]              = $r;
			$k[$r['vertragsnummer']]['v_summe_total']              += $r['za_summe'];
			$k[$r['vertragsnummer']]['za_summe_total']             += $r['za_summe'];
			$k[$r['vertragsnummer']]['mehrminderkosten']           += $r['za_summe'];
			$k[$r['vertragsnummer']]['v_summe_total_ohne_mwst']    += $r['za_summe']/(1+$mwst);
			$k[$r['vertragsnummer']]['za_summe_total_ohne_mwst']   += $r['za_summe']/(1+$mwst);
			$k[$r['vertragsnummer']]['mehrminderkosten_ohne_mwst'] += $r['za_summe']/(1+$mwst);
		}
		// Alle Rechnungen
		$results = db_query("SELECT * FROM evl_gesamt WHERE rechnungsbetrag != 0 AND zeilenbeschrieb LIKE 'R' ORDER BY rechnungsdatum ASC");
		while($r = db_fetch_array($results)) {
      //print '<div '.($r['mwst']==''?'style="background:\'red\'"':'').'>'.$r['vertragsnummer'].'. MWST: '.$r['mwst'].'</div>';
      $mwst = $this->MWST($r);
			$k[$r['vertragsnummer']]['rechnungen'][$r['id']]         = $r;
			$k[$r['vertragsnummer']]['rechnungen'][$r['id']]['mwst'] = $mwst;
			$k[$r['vertragsnummer']]['rechnungs_total']             += $r['rechnungsbetrag'];
			$k[$r['vertragsnummer']]['mehrminderkosten']            -= $r['rechnungsbetrag'];
			$k[$r['vertragsnummer']]['rechnungs_total_ohne_mwst']   += $r['rechnungsbetrag']/(1+$mwst);
			$k[$r['vertragsnummer']]['mehrminderkosten_ohne_mwst']  -= $r['rechnungsbetrag']/(1+$mwst);
		}
		
		// Rechnungstotal (KC3)
		$rechnungstotal = array('mit_mwst'=>0, 'ohne_mwst'=>0);
		foreach ($k as $vertragsnummer=>$vertrag) {
			$rechnungstotal['mit_mwst']  += $vertrag['rechnungs_total'];
			$rechnungstotal['ohne_mwst'] += $vertrag['rechnungs_total_ohne_mwst'];
		}
		
		// Rechnungs-MwsT (KC6)
		$rechnungstotal['delta'] = $rechnungstotal['mit_mwst'] - $rechnungstotal['ohne_mwst'];
		
		// In Rechnung gestellte Vertragsteuerung exkl Mwst (KC4)
		$vertragsteuerung = 0;
		foreach ($k as $vertragsnummer=>$vertrag) {
			foreach ($vertrag['rechnungen'] as $rechnung) {
				if ($rechnung['rechnungsart'] == 'T') {
					$vertragsteuerung += $rechnung['rechnungsbetrag']/(1+$rechnung['mwst']);
				}
			}
		}

    foreach ($k as $vertragsnummer=>$vertrag) {
      if (!isset($vertrag['vertragsnummer'])) {
        unset($k[$vertragsnummer]);
      }
    }
		
		$bonus = array(
			'vertragsteuerung' => $vertragsteuerung,
		);
		
		$this->data = array('v'=>$k,'stichtag'=>$stichtag, 'rechnungstotal'=>$rechnungstotal, 'bonus'=>$bonus);
		//unset($this->data['v']); print "<pre>"; print_r($this->data); print '</pre>'; exit();
		return $this->data;
	}
	
	/**
	 * Gibt die Schema-Id anhand eines Datums zurück
	 * Diese müssen beim Erstellen eines neuen Schemas hier gesetzt werden
	 * 
	 * @param string $date muss vom Format Y-m-d sein (mysql-Format)
	 */
	private function getSchemaIdFromDate($date) {
		$schemas = array (
		      1 => '1971-01-01',
		   // 2 => '2010-12-01',
		   // 3 => Datum ab wann Schema gültig ist
		);
		$date = $this->DateSql2Unix($date);
		for ($i=count($schemas);$i>=1;$i--) {
			if ($this->DateSql2Unix($schemas[$i])<=$date) return $i;
		}
		return $i+1;
	}
	
	/*
	 * gibt die Spalten der EVL zurück
	 * @return array
	 */
	private function getCols () {
		$cols = array ();
		foreach ($this->schema['evlcols'] as $col) {
			$cols[] = $col['title'];
		}
		return $cols;
	}
	
	
	private function getValues($row) {
		$values = array();
		foreach ($this->schema['evlcols'] as $col) {
			$values[] = "'".$row[$col['colnr']]."'";
		}
		return $values;
	}
	
	/*
	 * erstellt das Schema der EVL mit Spalten
	 */
	private function CreateSchema($schemaid=1) {
		// Definiere die Spalten der EVL
		// $this->schema['evlcols'][coltitle] = ...
		$this->schemaAddEVLCol('art',                      'str',   1,     'P');
		$this->schemaAddEVLCol('tbva',                     'int',   11,    0);
		$this->schemaAddEVLCol('zeilenbeschrieb',          'str',   2,     'H');
		$this->schemaAddEVLCol('vertragsnummer',           'int',   11,    0);
		$this->schemaAddEVLCol('zanr',                     'int',   11,    0);
		$this->schemaAddEVLCol('datum',                    'date',  false, '0000-00-00');
    $this->schemaAddEVLCol('konto',                    'str',   255,   '');
		$this->schemaAddEVLCol('kreditor',                 'str',   255,   '');
		$this->schemaAddEVLCol('auftragsbezeichnung',      'str',   255,   '');
		$this->schemaAddEVLCol('v_summe',                  'float', false, 0);
		$this->schemaAddEVLCol('za_summe',                 'float', false, 0);
		$this->schemaAddEVLCol('rechnungsdatum',           'date',  false, '0000-00-00');
		$this->schemaAddEVLCol('weiterleitunganrw',        'date',  false, '0000-00-00');
		$this->schemaAddEVLCol('rechnungsnummer',          'str',   255,   '');
		$this->schemaAddEVLCol('rechnungsart',             'str',   10,    '');
		$this->schemaAddEVLCol('rechnungsbetrag',          'float', false, 0);
		$this->schemaAddEVLCol('vertragserfuellungsdatum', 'date',  false, '0000-00-00');
		$this->schemaAddEVLCol('sr',                       'date',  false, '0000-00-00');
		$this->schemaAddEVLCol('angebotsdatum',            'date',  false, '0000-00-00');
    $this->schemaAddEVLCol('mwst',                     'float', false, '');
		
		switch ($schemaid) {
			case 1:
				$this->schemaAddColId('B','art');
				$this->schemaAddColId('C','tbva');
				$this->schemaAddColId('D','zeilenbeschrieb');
				$this->schemaAddColId('G','vertragsnummer');
				$this->schemaAddColId('H','zanr');
				$this->schemaAddColId('I','datum');
        $this->schemaAddColId('J','konto');
				$this->schemaAddColId('L','kreditor');
				$this->schemaAddColId('M','auftragsbezeichnung');
				$this->schemaAddColId('N','v_summe');
				$this->schemaAddColId('O','za_summe');
				$this->schemaAddColId('Q','rechnungsdatum');
				$this->schemaAddColId('R','weiterleitunganrw');
				$this->schemaAddColId('T','rechnungsnummer');
				$this->schemaAddColId('U','rechnungsart');
				$this->schemaAddColId('V','rechnungsbetrag');
				$this->schemaAddColId('W','vertragserfuellungsdatum');
				$this->schemaAddColId('X','sr');
				$this->schemaAddColId('AB','angebotsdatum');
        $this->schemaAddColId('AC','mwst');
			break;
			case 2:
				// add new Schema here
				// also add a Date to the Schema in function getSchemaIdFromDate()
			break;
		}

	}
	
	/*
	 * fügt eine Spalte in das Schema ein
	 */
	private function schemaAddEVLCol($title,$type,$length,$default) {
		$this->schema['evlcols'][$title] = array('title'=>$title,'type'=>$type,'length'=>$length, 'default'=>$default, 'colnr'=>0);
		return true;
	}
	
	/*
	 * verbindet eine Spalte mit dem Schema
	 * $colnr ist eine Spalte vom Format A,B,C,...
	 * Achtung: Funktion verarbeitet auch AA,AB,AC !
	 */
	private function schemaAddColId($colnr,$coltitle) {
		$value = 0;
		for ($i=1;$i<=strlen($colnr);$i++) {
			$value += (ord(strtoupper($colnr[$i-1]))-64)*bcpow(26,(strlen($colnr)-$i));
		}
		$this->schema['evlcols'][$coltitle]['colnr'] = $value-1; // Erste Spalte = 0
		return true;
	}
	
	private function schemaMakeCREATE_TABLE() {
		$CREATE = '';
		foreach ($this->schema['evlcols'] as $col) {
			switch ($col['type']) {
				case 'str':
				 $type = 'varchar('.$col['length'].')'; break;
				case 'int':
				 $type = 'int('.$col['length'].')'; break;
				case 'float':
				 $type = 'double(15,3)'; break;
				default:
				 $type = $col['type']; break;
			}
			$CREATE .= "`".$col['title'].'` '.$type.' NOT NULL'.($col['default']?' DEFAULT \''.$col['default'].'\'':'').',';
		}
		$CREATE = "CREATE TEMPORARY TABLE `evl_gesamt` (
									`id` int(11) NOT NULL auto_increment,
									".$CREATE."
		  							PRIMARY KEY (`id`)
									) ENGINE=MyISAM DEFAULT CHARSET=utf8;";
		return $CREATE;
	}
  
  private function MWST($r) {
    $datum = '';
    $mwst  = 0;
    
    if ($r['art'] == 'L') return 0.00000001;                           // Landverträge haben immer 0% MWST
    $e = $this->getMwstExeption($r['vertragsnummer']);                 // Manuelle, explizite Ausnahme MWST
    if ($e) return $e;
    
    switch ($r['zeilenbeschrieb']) {
      case 'R':
        $datum = $r['weiterleitunganrw'] == '' ? $r['rechnungsdatum'] : $r['weiterleitunganrw'];
        $mwst  = $r['mwst'] / 100;
        $mwst_calc = $this->getMwst($datum);
        $mwst == '' && ($mwst = $mwst_calc);                           // wenn keine MWST angegeben, dann ist sie 0
        // MWST wurde mit 7.6% gebucht und Datum liegt vor dem 01/01/2001 -> berechneter Wert
        $mwst == '7.6' && (strtotime($datum)-strtotime(mktime(0,0,0,1,1,2001))<0) && ($mwst = $mwst_calc); 
        break;
      case 'H':
      case 'ZA':
        $datum = $r['angebotsdatum'] == '0000-00-00' ? $r['datum'] : $r['angebotsdatum'];
        $mwst  = $this->getMwst($datum);
        break;
    }
    if ($mwst == 0) {
      $mwst = 0.000000001;
    }
    return $mwst;
  }
	
	
	/**
	 * gibt die MwSt eines Stichtages zurück
 	 */
	private function getMwst($date) {
		$date = strtotime($date);
    
		if     ($date >= mktime(0,0,0,1,1,1995) && $date < mktime(0,0,0,1,1,1999)) {
			return (6.5/100);
		}
		elseif ($date >= mktime(0,0,0,1,1,1999) && $date < mktime(0,0,0,1,1,2001)) {
			return (7.5/100);
		}
		elseif ($date >= mktime(0,0,0,1,1,2001) && $date < mktime(0,0,0,1,1,2011)){
			return (7.6/100);
		}
		elseif ($date >= mktime(0,0,0,1,1,2011)){
			return (8.0/100);
		}
		else { // kleiner als 01/01/1995
			return (6.5/100);
		}
	}

  /**
   *
   * @param <type> $v
   * @return <type>
   */
  /*
   * Zum sortieren des Arrays
   ksort($e);
   foreach ($e as $k=>$m) {
    print "&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;$k => $m,<br />";
   }
   */
  private function getMwstExeption($v) {
    $e = array(
      //205101 => 0,
      500101 => 0.000001,
      500701 => 2.4,
      901190 => 0.000001,
      901328 => 4.31,
      901336 => 0.000001,
      901357 => 2.3,
      901397 => 0.000001,
      901401 => 5,
      901406 => 2.4,
      901434 => 2.4,
      901460 => 2.7,
      901465 => 2.4,
      901485 => 3.2,
      901496 => 0.000001,
      901497 => 2.4,
      901530 => 0.000001,
      901759 => 0.000001,

        
    );
    if (array_key_exists($v,$e)) {
      return ($e[$v]/100);
    }
    else return false;
  }
	
	/*
	 * wandelt ein MYSQL Datum in das deutsche Format um
	 */
	private function DateSql2Unix($date) {
		list($y,$m,$d) = explode('-',$date);
		return mktime(0,0,0,$m,$d,$y);
	}
	
	private function DateCsv2Mysql($date) {
    if (preg_match('|^\d\d\.\d\d\.\d\d\d\d|',$date)) {
      list($d, $m, $y) = explode('.',$date);
      return $y.'-'.$m.'-'.$d;
    }
    elseif (preg_match('|^\d\d\.\d\d\.\d\d|',$date)) {
      list($d, $m, $y) = explode('.',$date);
      $y = ((int)$y > 70)?'19'.$y:'20'.$y;
      return $y.'-'.$m.'-'.$d;
    }
    else {
      return '0000-00-00';
    }
	}
	
}






//+ Jonas Raoni Soares Silva
//@ http://jsfromhell.com
class CSV{
	var $cellDelimiter;
	var $valueEnclosure;
	var $rowDelimiter;

	function CSV($cellDelimiter, $rowDelimiter, $valueEnclosure){
		$this->cellDelimiter = $cellDelimiter;
		$this->valueEnclosure = $valueEnclosure;
		$this->rowDelimiter = $rowDelimiter;
		$this->o = array();
	}
	function getArray(){
		return $this->o;
	}
	function setArray($o){
		$this->o = $o;
	}
	function getContent(){
		if(!(($bl = strlen($b = $this->rowDelimiter)) && ($dl = strlen($d = $this->cellDelimiter)) && ($ql = strlen($q = $this->valueEnclosure))))
			return '';
		for($o = $this->o, $i = -1; ++$i < count($o);){
			for($e = 0, $j = -1; ++$j < count($o[$i]);)
				(($e = strpos($o[$i][$j], $q) !== false) || strpos($o[$i][$j], $b) !== false || strpos($o[$i][$j], $d) !== false)
				&& $o[$i][$j] = $q . ($e ? str_replace($q, $q . $q, $o[$i][$j]) : $o[$i][$j]) . $q;
			$o[$i] = implode($d, $o[$i]);
		}
		return implode($b, $o);
	}
	function setContent($s){
		$this->o = array();
		if(!strlen($s))
			return true;
		if(!(($bl = strlen($b = $this->rowDelimiter)) && ($dl = strlen($d = $this->cellDelimiter)) && ($ql = strlen($q = $this->valueEnclosure))))
			return false;
		for($o = array(array('')), $this->o = &$o, $e = $r = $c = 0, $i = -1, $l = strlen($s); ++$i < $l;){
			if(!$e && substr($s, $i, $bl) == $b){
				$o[++$r][$c = 0] = '';
				$i += $bl - 1;
			}
			elseif(substr($s, $i, $ql) == $q){
				$e ? (substr($s, $i + $ql, $ql) == $q ?
				$o[$r][$c] .= substr($s, $i += $ql, $ql) : $e = 0)
				: (strlen($o[$r][$c]) == 0 ? $e = 1 : $o[$r][$c] .= substr($s, $i, $ql));
				$i += $ql - 1;
			}
			elseif(!$e && substr($s, $i, $dl) == $d){
				$o[$r][++$c] = '';
				$i += $dl - 1;
			}
			else
				$o[$r][$c] .= $s[$i];
		}
		return true;
	}
}