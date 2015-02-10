<?php
/**
 * Mit dieser Klasse wird ein PDF-Bericht erstellt
 * 
 * Der Inhalt des Berichtes entspricht der Vorgabe
 * des ASTRAs für Vorvertragsteuerung Infrastruktu
 * rfonds / Kennzahlen Aglomerationsverkehr.
 * Input ist die Erweiterte Vertragsliste als Arra
 * y-Wert, wie er von der Klasse "EVL" ausgegeben
 * werden kann.
 * Pdf-Ersteller ist das Programm "tcpdf"
 */

 // TCPDF laden
include_once('tcpdf/config/lang/ger.php');
include_once('tcpdf/tcpdf.php');

/**
 * die Klasse indexpdf
 * erstellt ein PDF mit dem Bericht; nimmt als input $data mit allen Verträgen
 * als geordnetes Array und de 'stichtag' als Stichtag für den Bericht
 */

class indexpdf {
	var $pdf;								// Das PDF-Objekt
	var $summe_periode = array();			// Die Vertragssummen pro Periode
	var $summe_periode_teuerung = array();	// Die Teuerungs-Summen pro Periode
	var $stichtag = '0000-00-00';			// Stichtag der EVL Tabelle
	var $cur_index = 1;						// Aktueller Index-Zähler
	var $cur_periode = 0;					// Aktueller Perioden-Zähler


	/*
	 * Konstruktor
	 * @param array $data enthält im Eingang index 'stichtag' und 'v' mit allen Verträgen
	 */
	function __construct($data) {
		// Der Stichtag des EVL-Liste (wird in Kopfzeile geschrieben)
		$this->stichtag = $data['stichtag'];

		// neues PDF Objekt anlegen
		$this->pdf = new TCPDF('P','mm','A4');
		$this->pdf->getAliasNbPages();  // Muss aufgerufen werden, damit Seitennummerierung erfolgt
		$this->pdf->SetFont('helvetica','',7);
		$this->pdf->SetFillColor(255,255,255);
		$this->pdf->SetMargins(15,110,15);	// Ränder
		$this->pdf->AddPage(); // erste Seite anlegen

		// Index und Perioden Daten
		$astraINDEXE = $this->Indexe();
		$astraperiode = $this->Perioden();

		$this->cur_periode = 0;
		$cur_page = 0;
		// Durch alle HV gehen
		foreach ($data['v'] as $hv) {
			// Wenn auf neuer Seite dann Header-Variablen ausfüüllen
			if ($cur_page != $this->pdf->PageNo()) {
				$this->fillOutHeader();
				$cur_page = $this->pdf->PageNo();
				$this->pdf->SetFont('helvetica','',7);
			}
			$datum = ($hv['angebotsdatum'] != '0000-00-00')?$hv['angebotsdatum']:$hv['datum'];
			if (!isset($this->summe_periode[$this->cur_periode])) $this->summe_periode[$this->cur_periode] = 0;
			if (!isset($this->summe_index[$this->cur_index])) $this->summe_index[$this->cur_index] = 0;
			while ($this->is_new_periode($datum)) {
				$this->LinePeriode($this->cur_periode);
				$this->cur_periode++;
			}
			if ($datum > $astraINDEXE[$this->cur_index]['bis']) { $this->WriteTotalIndex(); $this->pdf->AddPage(); $this->cur_index++; }
			$this->pdf->Cell(16,4,date('d.m.Y',strtotime($datum)),0,0,'L',1);
			$this->pdf->Cell(13,4,$hv['vertragsnummer'],0,0,'L',1);
			$this->pdf->Cell(24,4,$this->cut($hv['kreditor'],35),0,0,'L',1);
			$this->pdf->Cell(34,4,$this->cut($hv['auftragsbezeichnung'],35),0,0,'L',1);
			$this->pdf->Cell(25,4,$this->CHF($hv['v_summe_ohne_mwst']),0,0,'R',1);
			$this->summe_periode[$this->cur_periode] += $this->Rappen($hv['v_summe_ohne_mwst']);
			$this->summe_index[$this->cur_index]     += $this->Rappen($hv['v_summe_ohne_mwst']);
			$this->pdf->Ln();
			// ZA's
			if (count($hv['za'])) {
				$this->pdf->Cell(16,4,'',0,0,'L',1);
				$this->pdf->Cell(13,4,'',0,0,'L',1);
				$this->pdf->Cell(24,4,'Nachtrag',0,0,'L',1);
				$this->pdf->Cell(34,4,'',0,0,'L',1);
				$this->pdf->Cell(25,4,$this->CHF($hv['za_summe_total_ohne_mwst']),0,0,'R',1);
				$this->summe_periode[$this->cur_periode] += $this->Rappen($hv['za_summe_total_ohne_mwst']);
				$this->summe_index[$this->cur_index]     += $this->Rappen($hv['za_summe_total_ohne_mwst']);
				$this->pdf->Ln();
			}
			if (($hv['sr'] != '0000-00-00' && $this->Rappen($hv['mehrminderkosten']) != 0) || $this->Rappen($hv['mehrminderkosten']) < 0) {
				//if ($this->CHF((-$hv['mehrminderkosten_ohne_mwst'])) == '406.55') print_r($hv);
				$this->pdf->Cell(16,4,'',0,0,'L',1);
				$this->pdf->Cell(13,4,'',0,0,'L',1);
				$this->pdf->Cell(24,4,($hv['mehrminderkosten']<0)?'Mehrkosten':'Minderkosten',0,0,'L',1);
				$this->pdf->Cell(34,4,'',0,0,'L',1);
				$this->pdf->Cell(25,4,$this->CHF((-$hv['mehrminderkosten_ohne_mwst'])),0,0,'R',1);
				$this->summe_periode[$this->cur_periode] += $this->Rappen(-$hv['mehrminderkosten_ohne_mwst']);
				$this->summe_index[$this->cur_index] 	 += $this->Rappen(-$hv['mehrminderkosten_ohne_mwst']);
				$this->pdf->Ln();
			}
		}
		$this->LinePeriode($this->cur_periode);
		$this->WriteTotalIndex();
		$this->fillOutHeader();
		$this->pdf->AddPage();
		// KC1 und KC2 ausgeben
        // KC1: Vertragssumme ohne MWST
        // KC2: Vorvertragsteuerung
		$ttotal = 0;
		foreach ($this->summe_index as $sum) {
			$ttotal += $sum;
		}
		$this->pdf->Cell(50,5,'KC1: '.$this->CHF($ttotal));$this->pdf->Ln();
		$ttotal = 0;
		foreach ($this->summe_periode_teuerung as $sum) {
			$ttotal += $sum;
		}
		$this->pdf->Cell(50,5,'KC2: '.$this->CHF($ttotal));$this->pdf->Ln();

		// KC 3
		$this->pdf->Cell(50,5,'KC3: '.$this->CHF($data['rechnungstotal']['ohne_mwst']));$this->pdf->Ln();

		// KC 4
		$this->pdf->Cell(50,5,'KC4: '.$this->CHF($data['bonus']['vertragsteuerung']));$this->pdf->Ln();

		// KC 6
		$this->pdf->Cell(50,5,'KC6: '.$this->CHF($data['rechnungstotal']['mit_mwst']-$data['rechnungstotal']['ohne_mwst']));$this->pdf->Ln(); 
	}

	/*
	 * Diese Methode gibt das PDF aus
	 */
	public function Output() {
		// PDF ausgeben
		$this->pdf->Output('Do_'.date('Y-m-d_H-i',time()).'.pdf','D');
		// Script abbrechen, damit Drupal nicht versucht weiteren Code auszuführen
		$GLOBALS['devel_shutdown'] = FALSE;
		module_invoke_all('exit');
		exit();
	}

	/*
	 * Passt den PDF-Header an
	 *
	 * Da man mit TCPDF nur eine statische Kopfzeile in das PDF
	 * einfügen kann werden die Variablen, welche sich im
	 * Header befinden manuell nach einem Seitenwechsel
	 * in der PDF Kopfzeile eingefügt.
	 */
	private function fillOutHeader() {
		$astraINDEXE = $this->Indexe();
		// Aktuelle Koordinaten
		$x = $this->pdf->getX();
		$y = $this->pdf->getY();

		// Stichag EVL-Liste
		$this->pdf->SetFont('helvetica','B',11);
		$this->pdf->Text(160,37,'Stand: '.$this->DateSql2de($this->stichtag));
		// verwendeter Index
		$this->pdf->setXY(17,61);
		$this->pdf->SetFont('helvetica','',9);
		$this->pdf->Cell(177,8,$astraINDEXE[$this->cur_index]['titel'],0,0,'R');
		// Index Datumsbereich
		$this->pdf->SetTextColor(255, 0, 0); // rot
		$this->pdf->SetFont('helvetica','B',12);
		$text = $this->DateSql2de($astraINDEXE[$this->cur_index]['von']).' bis '.$this->DateSql2de($astraINDEXE[$this->cur_index]['bis']);
		$this->pdf->Text(70,56,$text);
		$this->pdf->SetTextColor(0,0,0);
		// Basisindex
		$this->pdf->SetFont('helvetica','',9);
		$text = sprintf("%01.1f",$astraINDEXE[$this->cur_index]['Basisindex']);
		$this->pdf->Text(129,101,$text);

		// Koordinaten auf Ursprungsposition zurücksetzten
		$this->pdf->setXY($x,$y);
	}

	/*
	 * erstellt das Total je Periode
	 */
	private function LinePeriode($periode) {
		$astraINDEXE = $this->Indexe();
		$astraperiode = $this->Perioden();
		$total = $this->summe_periode[$periode];
		$basisindex = $astraINDEXE[$astraperiode[$periode]['periode']]['Basisindex'];
		$index = $astraperiode[$periode]['index'];
		$delta =$index/$basisindex-1;
		$vorvertragsteuerung = $total*(1-$basisindex/$index);
		$this->pdf->Cell(87,4,"Total Vergaben Periode ".$this->DateSql2de($astraperiode[$periode]['von']).' - '.$this->DateSql2de($astraperiode[$periode]['bis']),0,0,'L',1);
		$this->pdf->Cell(25,4,$this->CHF($total),'T',0,'R',1);
		$this->pdf->Cell(11,4,$astraperiode[$periode]['index'],0,0,'R',1);
		$this->pdf->Cell(18,4,substr($this->DateSql2de($astraperiode[$periode]['von']),3,7),0,0,'R',1);
		$this->pdf->Cell(10,4,round($delta*100,1).'%',0,0,'R',1);
		$this->pdf->Cell(24,4,$this->CHF($vorvertragsteuerung),0,0,'R',1);
		$this->pdf->Ln(6);
		$this->summe_periode_teuerung[$periode] = $vorvertragsteuerung;
	}

	/*
	 * erstellt das Total je Index
	 */
	private function WriteTotalIndex() {
		$this->pdf->Ln();
		$total = 0;
		$total_teuerung = 0;

		$astraperiode = $this->Perioden();

		foreach ($astraperiode as $index=>$periode) {
			if($periode['periode'] == $this->cur_index) {
				$total += $this->summe_periode[$index];
				$total_teuerung += $this->summe_periode_teuerung[$index];
}
		}

		// Total-Zeile ungerundet bzw. auf 5-Rappen gerundet
		$y = $this->pdf->GetY();
		$this->pdf->Line(17, $y, 194, $y);
		$this->pdf->Ln(2);
		$this->pdf->Cell(87,4,'Gesamttotal');
		$this->pdf->Cell(25,4,$this->CHF($total),0,0,'R');
		$this->pdf->Cell(11,4,'',0,0,'R');
		$this->pdf->Cell(18,4,'',0,0,'R');
		$this->pdf->Cell(10,4,'',0,0,'R');
		$this->pdf->Cell(24,4,$this->CHF($total_teuerung),0,0,'R');
		$this->pdf->Ln();

		// Total-Zeile gerundet auf volle Franken
		$this->pdf->Cell(87,4,'Gesamttotal gerundet');
		$this->pdf->Cell(25,4,$this->CHF(round($total)),0,0,'R');
		$this->pdf->Cell(11,4,'',0,0,'R');
		$this->pdf->Cell(18,4,'',0,0,'R');
		$this->pdf->Cell(10,4,sprintf('%+01.1f',(round($total_teuerung)/round($total)*100)).'%',0,0,'R');
		$this->pdf->Cell(24,4,$this->CHF(round($total_teuerung)),0,0,'R');
		$this->pdf->Ln();
		$y = $this->pdf->GetY();
		$this->pdf->Line(17, $y, 194, $y);
	}

	private function Indexe() {
		return array(
			1 => array('von'=>'1995-01-01','bis'=>'1998-09-30', 'titel'=>'NEAT Teuerungsindex',                                 'Basisindex'=>119.8),
			2 => array('von'=>'1998-10-01','bis'=>'2005-03-31', 'titel'=>'Schweizer Baupreisindex - Tiefbau - Nordwestschweiz', 'Basisindex'=>98.0),
			3 => array('von'=>'2005-04-01','bis'=>'2099-12-31', 'titel'=>'Schweizer Baupreisindex - Tiefbau - Nordwestschweiz', 'Basisindex'=>100.0),
		);
	}

	private function Perioden() {
		return array (
			0  => array('von'=>'1995-01-01', 'bis'=>'1995-12-31', 'index'=>102.5, 'periode'=>1),
			1  => array('von'=>'1996-01-01', 'bis'=>'1996-12-31', 'index'=>105.4, 'periode'=>1),
			2  => array('von'=>'1997-01-01', 'bis'=>'1997-12-31', 'index'=>105.7, 'periode'=>1),
			3  => array('von'=>'1998-01-01', 'bis'=>'1998-09-30', 'index'=>105.9, 'periode'=>1),
			4  => array('von'=>'1998-10-01', 'bis'=>'1999-03-31', 'index'=>100.0, 'periode'=>2),
			5  => array('von'=>'1999-04-01', 'bis'=>'1999-09-30', 'index'=> 99.9, 'periode'=>2),
			6  => array('von'=>'1999-10-01', 'bis'=>'2000-03-31', 'index'=>101.5, 'periode'=>2),
			7  => array('von'=>'2000-04-01', 'bis'=>'2000-09-30', 'index'=>103.7, 'periode'=>2),
			8  => array('von'=>'2000-10-01', 'bis'=>'2001-03-31', 'index'=>110.0, 'periode'=>2),
			9  => array('von'=>'2001-04-01', 'bis'=>'2001-09-30', 'index'=>107.3, 'periode'=>2),
			10 => array('von'=>'2001-10-01', 'bis'=>'2002-03-31', 'index'=>101.8, 'periode'=>2),
			11 => array('von'=>'2002-04-01', 'bis'=>'2002-09-30', 'index'=> 95.0, 'periode'=>2),
			12 => array('von'=>'2002-10-01', 'bis'=>'2003-03-31', 'index'=> 95.3, 'periode'=>2),
			13 => array('von'=>'2003-04-01', 'bis'=>'2003-09-30', 'index'=> 91.6, 'periode'=>2),
			14 => array('von'=>'2003-10-01', 'bis'=>'2004-03-31', 'index'=> 95.9, 'periode'=>2),
			15 => array('von'=>'2004-04-01', 'bis'=>'2004-09-30', 'index'=> 93.8, 'periode'=>2),
			16 => array('von'=>'2004-10-01', 'bis'=>'2005-03-31', 'index'=> 99.3, 'periode'=>2),
			17 => array('von'=>'2005-04-01', 'bis'=>'2005-09-30', 'index'=>100.0, 'periode'=>3),
			18 => array('von'=>'2005-10-01', 'bis'=>'2006-03-31', 'index'=>105.31, 'periode'=>3),
			19 => array('von'=>'2006-04-01', 'bis'=>'2006-09-30', 'index'=>106.22, 'periode'=>3),
			20 => array('von'=>'2006-10-01', 'bis'=>'2007-03-31', 'index'=>110.00, 'periode'=>3),
			21 => array('von'=>'2007-04-01', 'bis'=>'2007-09-30', 'index'=>113.67, 'periode'=>3),
			22 => array('von'=>'2007-10-01', 'bis'=>'2008-03-31', 'index'=>113.57, 'periode'=>3),
			23 => array('von'=>'2008-04-01', 'bis'=>'2008-09-30', 'index'=>111.84, 'periode'=>3),
			24 => array('von'=>'2008-10-01', 'bis'=>'2009-03-31', 'index'=>113.06, 'periode'=>3),
			25 => array('von'=>'2009-04-01', 'bis'=>'2009-09-30', 'index'=>107.76, 'periode'=>3),
			26 => array('von'=>'2009-10-01', 'bis'=>'2010-03-31', 'index'=>106.02, 'periode'=>3),
            27 => array('von'=>'2010-04-01', 'bis'=>'2010-09-30', 'index'=>106.02, 'periode'=>3),
            28 => array('von'=>'2010-10-01', 'bis'=>'2011-03-31', 'index'=>109.29, 'periode'=>3),
            29 => array('von'=>'2011-04-01', 'bis'=>'2011-09-30', 'index'=>108.47, 'periode'=>3),
            30 => array('von'=>'2011-10-01', 'bis'=>'2012-03-31', 'index'=>108.27, 'periode'=>3),
            31 => array('von'=>'2012-04-01', 'bis'=>'2012-09-30', 'index'=>109.39, 'periode'=>3),
            32 => array('von'=>'2012-10-01', 'bis'=>'2013-03-31', 'index'=>111.63, 'periode'=>3),
            33 => array('von'=>'2013-04-01', 'bis'=>'2013-09-30', 'index'=>112.45, 'periode'=>3),
            34 => array('von'=>'2013-10-01', 'bis'=>'2014-03-31', 'index'=>114.08, 'periode'=>3),
            35 => array('von'=>'2014-04-01', 'bis'=>'2014-09-30', 'index'=>116.22, 'periode'=>3),
            36 => array('von'=>'2014-10-01', 'bis'=>'2015-12-31', 'index'=>117.45, 'periode'=>3),
        );
    }

	/*
	 * prüft, ob eine neue Periode kommt
	 */
	private function is_new_periode($datum) {
		$astraperiode = $this->Perioden();
		if ($datum > $astraperiode[$this->cur_periode]['bis']) {
			return true;
		}
		else {
			return false;
		}
	}

	/*
	 * Formatiert ein Float Wert als CHF-Währung
	 * Rundet auf 5-er Rappen, zwei Nachkommastellen
	 */
	private function CHF($number) {
		return number_format($this->Rappen($number),2,".","'");
	}

	// Rundet eine Zahl auf 5-er Rappen
	private function Rappen($number) {
		return (float) round($number*100/5)*5/100;
	}

	/*
	 * wandelt ein MYSQL Datum in das deutsche Format um
	 * "YYYY-mm-dd" > "dd.mm.YYYY"
	 */
	private function DateSql2de($date) {
		list($year,$month,$day) = explode('-',$date);
		return $day.'.'.$month.'.'.$year;
	}

	/*
	 * kürzt einen String auf $len
	 * @param string $string String welcher gekrzt werden soll
	 * @param integer $len länge, auf welche $string gekürzt werden soll, default = 55
	 */
	private function cut($string, $len=55) {
		return (strlen($string)>$len) ? substr($string,0,$len-3).'...':$string;
	}

}

/*
 * Fügt die Funktionen für Kopf- und Fusszeile an die PDF Klasse an
 */
class teuerungsindex_PDF extends TCPDF {
	// Kopfzeile
	function header() {
		$this->Image((dirname(__FILE__)).'/chlogo.png',17,5,70,0,'PNG','','T',true);
		$this->SetFont('helvetica','B',14);$this->Text(17,30,'Infrastrukturfonds');
		$this->SetFont('helvetica','B',11);$this->Text(17,39,'Berechnung / Nachweis Vergaben + Vorvertragssteuerung');
		$this->SetFont('helvetica','B',11);$this->Text(17,47,'Kennzahl KC1 / KC2 aus Kennzahlenreporting Teil C Kostencontrolling');
		$this->SetFont('helvetica','B',10);$this->Text(17,56,'H2 Pratteln - Liestal');
		$this->SetFont('helvetica','',9);  $this->Text(17,64,'Anzuwendender Index');
		$this->SetFont('helvetica','',8);  $this->Text(116,10,'Eidgenössisches Departement für');
		$this->SetFont('helvetica','',8);  $this->Text(116,13,'Umwelt, Verkehr, Energie und Kommunikation UVEK');
		$this->SetFont('helvetica','B',8); $this->Text(116,20,'Bundesamt für Strassen ASTRA');
		$this->SetFont('helvetica','B',14);$this->Text(108,30,'Kennzahlen Agglomerationsverkehr');
		$this->SetFont('helvetica','B',10);$this->Text(160,56,'Projekt-Nr. 11320012');
		$this->SetLineWidth(0.2);$this->Line(17,70,194,70);
		$this->SetLineWidth(0.2);$this->Line(17,95,194,95);
		$this->SetFont('helvetica','',9);
		$this->SetXY(17,70);  $this->MultiCell(16,25,"Datum\nStichtag: Termin Angebotseingabe",0,'L');
		$this->SetXY(33,70);  $this->MultiCell(13,25,'Werkvertrag WV',0,'L');
		$this->SetXY(46,70);  $this->MultiCell(24,25,'Firma',0,'L');
		$this->SetXY(70,70);  $this->MultiCell(34,25,'Gegenstand',0,'L');
		$this->SetXY(104,70); $this->MultiCell(25,25,'Vergaben KC1 ohne MWST CHF',0,'R');
		$this->SetXY(129,70); $this->MultiCell(11,25,"Index\ngem. Indexreihe BFS",0,'L');
		$this->SetXY(140,70); $this->MultiCell(18,25,'Stand',0,'L');
		$this->SetXY(158,70); $this->MultiCell(10,25,'Delta',0,'L');
		$this->SetXY(168,70); $this->MultiCell(24,25,'Vorvertragsteuerung KC2 CHF',0,'R');
		$this->SetFont('helvetica','',9);$this->Text(17,101,'Basiszeitpunkt');
		$this->SetFont('helvetica','',9);$this->Text(140,101,'Apr. 2005');
	}

    // Fusszeile
	function footer() {
		// Dokumentenname
		$this->SetFont('helvetica','',8);
        $this->SetXY(17,286);
        $this->MultiCell(90,5,'Do_'.date("Y-m-d_H-i",time()).'.pdf',0,'L');

		// Druckdatum, Seite von / bis
		$this->SetFont('helvetica','',8);
        $this->SetXY(107,286);
        $this->MultiCell(88,5,'Druckdatum: '.date('d.m.Y',time()).'          Seite '.$this->PageNo().'/{nb}',0,'R');
	}
}
