<?php
	require_once('config.inc.php');
	require __DIR__ . '/vendor/autoload.php';
	$site = "all";
	$platform = "all";
	$export = "print";
	$exchange_rate = 1; // this value is updated by excel, cell K4 in sheet "1. Invoice Listing"
	$month_prefix = "XXXXXX"; // this value is updated by excel, cell J14 in sheet "5. Site Balance"
    if(isset($_POST['site']))
    	$site = $_POST['site'];
    if($site == "")
    	$site = "all";
    if(isset($_POST['platform']))
    	$platform = $_POST['platform'];
    if($platform == "")
    	$platform = "all";
    if(isset($_POST['export']))
    	$export = $_POST['export'];
    if($export == "")
    	$export = "print";
	if(isset($_FILES['spreadsheet'])){
		if($_FILES['spreadsheet']['name']){
			$obj_gateway_remote = new \GDS\Gateway\RESTv1('sims-2-140009');
			include_once("./model/site.php");
			$sites = array();
			$debts = array();
			while($arr_page = $site_store->fetchPage(300)) 
		  	{
		    	foreach($arr_page as $entity)
		    	{
					$sites[$entity->site] = $entity->commcare_id;
					$debts[$entity->site] = $entity->balance;
		    	}
		    }
			$file_name = $_FILES['spreadsheet']['name'];
			$temp_name = $_FILES['spreadsheet']['tmp_name'];
	    	if(!$_FILES['spreadsheet']['error']){
		        $inputFile = $_FILES['spreadsheet']['name'];
		        $extension = strtoupper(pathinfo($inputFile, PATHINFO_EXTENSION));
		        if($extension == 'XLSX' || $extension == 'ODS' || $extension == 'XLS'){
		            //Read spreadsheeet workbook
		            try {
		                $inputFileType = PHPExcel_IOFactory::identify($temp_name);
		                $objReader = PHPExcel_IOFactory::createReader($inputFileType);
		                $objPHPExcel = $objReader->load($temp_name);
		            } catch(Exception $e) {
		            	krumo($e);
		                die("error : ".$e->getMessage());
		            }
		            $objPHPExcel->setActiveSheetIndexByName("1. Invoice listing");
					$worksheet = $objPHPExcel->getActiveSheet();
		            $data = convertData($worksheet);
		            $objPHPExcel->setActiveSheetIndexByName("5. Sites Database");
		            $worksheet2 = $objPHPExcel->getActiveSheet();
		            $balance = convertBalanceSheet($worksheet2);
	  
		            if($export == "print")
		            	printPaperInvoices($data, $site, $platform);
		            else if($export == "delivery")
		            	printPaperDelivery($data, $site, $platform);
		            else if($export == "commcare")
						printCC($data, $balance, $site, $platform, $debts, $sites);
					else if($export == "qbo")
						generateQBO($data, $balance, $site, $platform);
					else if($export == "saving")
						printPaperSavings($data, $site, $platform);
					else if($export == "debug"){
						echo "selected platform:";krumo($platform);
			            echo "selected site:";krumo($site);	
						echo "list of sites:";krumo($sites);	
			            echo "list of invoices:";krumo($data);
			            echo "exchange rate:";krumo($exchange_rate);
			            echo "month prefix:";krumo($month_prefix);
			            echo "list of site balances:";krumo($balance);
			            htmlClosers();
					}
	        	}
	        	else{
	            	die("Please upload an XLS, XLSX or ODS file (".$extension." found)");
	        	}
	        }	
	        else {
	        	die("error: ".$_FILES['spreadsheet']['error']);
	   		}
	    }
	}
	else
	{
		htmlHeaders();
		echo "<H1>Transform site database</H1> ";
		echo "<form method='post' enctype='multipart/form-data'>";
		echo "<input type='file' name='spreadsheet'><BR><BR>";
		echo "only one site ? <input type='text' name='site'><BR><BR>";
		echo "only one cashier ? <select name='platform'><option value='all'>all</option><option value='Nou_Chamroeun'>Nou_Chamroeun</option><option value='Mon_Sorvy'>Mon_Sorvy</option><option value='Hon_Sreyrath'>Hon_Sreyrath</option></select><BR><BR>";
		echo "What do you want ? <select name='export'><option value='print'>print invoices</option><option value='delivery'>print delivery</option><option value='saving'>print savings</option><option value='commcare'>generate excel import for Commcare</option><option value='qbo'>generate excel import for Quickbooks</option><option value='debug'>Debug</option></select><BR><BR>";
		echo "<input type='submit' value='Go'></form>";
		htmlClosers();
	}


	function generateQBO($invoices, $balances, $site, $platform)
	{
		global $exchange_rate;
		global $month_prefix;
		$qbo_classes = array(
			'Project' => 'National',
			'BTB' => 'Platform BTB',
			'KC' => 'Platform KC', //Phaline request on 22-Aug-2017
			'PP' => 'Platform PP'
		);

		$qbo_locations = array(
			'BTB' => 'Battambang',
			'KC' => 'Kampong Cham',
			'PP' => 'Phnom Penh',
			'Project' => 'Phnom Penh'
		);

		$transactions = array();
		foreach ($invoices as $invoice) {
			$transactions[] = array(
				'RefNumber' => $invoice['ref'],
				'TxnDate' => $invoice['date'], 
				'PrivateNote' => $invoice['ref'],
				'IsAdjustment' => "FALSE",
				'Account' => "100410",
				'LineAmount' => $invoice['total']/$exchange_rate,
				'LineDesc' => $invoice['ref']." for ".$invoice['site'],
				'Class' => $qbo_classes[$invoice['location']],
				'Location' => $qbo_locations[$invoice['location']],
				'Customer' => $invoice['site']
			);
			if($invoice['type'] == "T1")
				$transactions[] = array(
					'RefNumber' => $invoice['ref'],
					'TxnDate' => $invoice['date'], 
					'PrivateNote' => "Assistant fee ".$invoice['ref'],
					'IsAdjustment' => "FALSE",
					'Account' => "400210",
					'LineAmount' => -1*$invoice['total']/$exchange_rate,
					'LineDesc' => $invoice['ref']." for ".$invoice['site'],
					'Class' => $qbo_classes[$invoice['location']],
					'Location' => $qbo_locations[$invoice['location']],
					'Customer' => $invoice['site']
				);
			else if($invoice['type'] == "C1")
				$transactions[] = array(
					'RefNumber' => $invoice['ref'],
					'TxnDate' => $invoice['date'], 
					'PrivateNote' => "Consumables ".$invoice['ref'],
					'IsAdjustment' => "FALSE",
					'Account' => "400220",
					'LineAmount' => -1*$invoice['total']/$exchange_rate,
					'LineDesc' => $invoice['ref']." for ".$invoice['site'],
					'Class' => $qbo_classes[$invoice['location']],
					'Location' => $qbo_locations[$invoice['location']],
					'Customer' => $invoice['site']
				);
			else if($invoice['type'] == "Tech")
				$transactions[] = array(
					'RefNumber' => $invoice['ref'],
					'TxnDate' => $invoice['date'], 
					'PrivateNote' => "Technical service consumables ".$invoice['ref'],
					'IsAdjustment' => "FALSE",
					'Account' => "400230",
					'LineAmount' => -1*$invoice['total']/$exchange_rate,
					'LineDesc' => $invoice['ref']." for ".$invoice['site'],
					'Class' => $qbo_classes[$invoice['location']],
					'Location' => $qbo_locations[$invoice['location']],
					'Customer' => $invoice['site']
				);
			
			else
				$transactions[] = array(
					'RefNumber' => $invoice['ref'],
					'TxnDate' => $invoice['date'], 
					'PrivateNote' => "ERROR",
					'IsAdjustment' => "FALSE",
					'Account' => "ERROR",
					'LineAmount' => -1*$invoice['total']/$exchange_rate,
					'LineDesc' => $invoice['ref']." for ".$invoice['site'],
					'Class' => $qbo_classes[$invoice['location']],
					'Location' => $qbo_locations[$invoice['location']],
					'Customer' => $invoice['site']
				);
		}

		$cpt = 0;
		foreach ($balances as $balance) {
			if(isset($qbo_classes[$balance['location']]))
			{
				if($balance['school'] > 0)
				{
					$transactions[] = array(
						'RefNumber' => "SP".getRefPrefix($month_prefix)."-".str_pad($cpt,4,'0', STR_PAD_LEFT),
						'TxnDate' => $month_prefix, 
						'PrivateNote' => "school program ".$month_prefix." for site ".$balance['site'],
						'IsAdjustment' => "FALSE",
						'Account' => "100410",
						'LineAmount' => -1*$balance['school']/$exchange_rate,
						'LineDesc' => "school program ".$month_prefix." for site ".$balance['site']." (ID Poor: ".$balance['id_poor'].")",
						'Class' => $qbo_classes[$balance['location']],
						'Location' => $qbo_locations[$balance['location']],
						'Customer' => $balance['site']
					);
					$transactions[] = array(
						'RefNumber' => "SP".getRefPrefix($month_prefix)."-".str_pad($cpt,4,'0', STR_PAD_LEFT),
						'TxnDate' => $month_prefix, 
						'PrivateNote' => "school program ".$month_prefix." for site ".$balance['site'],
						'IsAdjustment' => "FALSE",
						'Account' => "600280",
						'LineAmount' => $balance['school']/$exchange_rate,
						'LineDesc' => "school program ".$month_prefix." for site ".$balance['site']." (ID Poor: ".$balance['id_poor'].")",
						'Class' => 'School program', // Phaline request on 22-aug-2017
						'Location' => $qbo_locations[$balance['location']],
						'Customer' => $balance['site']
					);
					$cpt++;
				}
				if($balance['id_poor'] != 0)
				{
					$transactions[] = array(
						'RefNumber' => "IDP".getRefPrefix($month_prefix)."-".str_pad($cpt,4,'0', STR_PAD_LEFT),
						'TxnDate' => $month_prefix, 
						'PrivateNote' => "ID poor ".$month_prefix." for site ".$balance['site'],
						'IsAdjustment' => "FALSE",
						'Account' => "100410",
						'LineAmount' => -1*$balance['id_poor']/$exchange_rate,
						'LineDesc' => "ID poor ".$month_prefix." for site ".$balance['site']." (ID Poor: ".$balance['id_poor'].")",
						'Class' => $qbo_classes[$balance['location']],
						'Location' => $qbo_locations[$balance['location']],
						'Customer' => $balance['site']
					);
					$transactions[] = array(
						'RefNumber' => "IDP".getRefPrefix($month_prefix)."-".str_pad($cpt,4,'0', STR_PAD_LEFT),
						'TxnDate' => $month_prefix, 
						'PrivateNote' => "school program ".$month_prefix." for site ".$balance['site'],
						'IsAdjustment' => "FALSE",
						'Account' => "600280",
						'LineAmount' => $balance['id_poor']/$exchange_rate,
						'LineDesc' => "ID poor ".$month_prefix." for site ".$balance['site']." (ID Poor: ".$balance['id_poor'].")",
						'Class' => 'ID poor', // Phaline request on 22-aug-2017
						'Location' => $qbo_locations[$balance['location']],
						'Customer' => $balance['site']
					);
					$cpt++;
				}
				if($balance['cash'] != 0)
				{
					$transactions[] = array(
						'RefNumber' => "CN".getRefPrefix($month_prefix)."-".str_pad($cpt,4,'0', STR_PAD_LEFT),
						'TxnDate' => $month_prefix, 
						'PrivateNote' => "Actual net cash ".$month_prefix." for site ".$balance['site'],
						'IsAdjustment' => "FALSE",
						'Account' => "100410",
						'LineAmount' => -1*$balance['cash']/$exchange_rate,
						'LineDesc' => "Actual net cash ".$month_prefix." for site ".$balance['site'],
						'Class' => $qbo_classes[$balance['location']],
						'Location' => $qbo_locations[$balance['location']],
						'Customer' => $balance['site']
					);
					$transactions[] = array(
						'RefNumber' => "CN".getRefPrefix($month_prefix)."-".str_pad($cpt,4,'0', STR_PAD_LEFT),
						'TxnDate' => $month_prefix, 
						'PrivateNote' => "Actual net cash ".$month_prefix." for site ".$balance['site'],
						'IsAdjustment' => "FALSE",
						'Account' => "100260",
						'LineAmount' => $balance['cash']/$exchange_rate,
						'LineDesc' => "Actual net cash ".$month_prefix." for site ".$balance['site'],
						'Class' => $qbo_classes[$balance['location']],
						'Location' => $qbo_locations[$balance['location']],
						'Customer' => $balance['site']
					);
					$cpt++;
				}
			}
		}

		include_once './opentbs/tbs_class.php';
		include_once './opentbs/plugins/tbs_plugin_opentbs.php';
		$TBS = new clsTinyButStrong;
	    $TBS->OtbsMsExcelExplicitRef = false;
	    $TBS->Plugin(TBS_INSTALL, OPENTBS_PLUGIN);
	    $TBS->LoadTemplate("qbo_import_template.xlsx", "UTF-8");
	    $TBS->PlugIn(OPENTBS_SELECT_SHEET, 1);
	    //$TBS->SetOption("noerr", true);
	    $TBS->MergeBlock('data', $transactions);
	    $TBS->Show(OPENTBS_DOWNLOAD, "qbo_import_".date('Y-m-d').".xlsx");	
		
	}

	function convertBalanceSheet($sheet)
	{
		global $month_prefix;
		$month_prefix = substr($sheet->getCell("J14")->getValue(), -10);
		$data = array();
		$highestRow = $sheet->getHighestRow(); 
        $highestColumn = PHPExcel_Cell::columnIndexFromString($sheet->getHighestColumn());
        for ($row = 15; $row <= $highestRow; $row++){ 
			$data[] = array(
				'site' => $sheet->getCellByColumnAndRow(1, $row)->getValue(),
				'location' => ($sheet->getCellByColumnAndRow(3, $row)->getOldCalculatedValue() == null)?$sheet->getCellByColumnAndRow(3, $row)->getValue():$sheet->getCellByColumnAndRow(3, $row)->getOldCalculatedValue(),
				'balance' => $sheet->getCellByColumnAndRow(9, $row)->getOldCalculatedValue(), 
				'school' => $sheet->getCellByColumnAndRow(10, $row)->getOldCalculatedValue(),
				'cash' => $sheet->getCellByColumnAndRow(12, $row)->getOldCalculatedValue(),
				'id_poor' => $sheet->getCellByColumnAndRow(11, $row)->getOldCalculatedValue()
			);
        }
        return $data;
	}

	function convertData($sheet)
	{
		global $sites;
		global $exchange_rate;
		$exchange_rate = $sheet->getCell("K4")->getValue();
		$data = array();
		$highestRow = $sheet->getHighestRow(); 
        $highestColumn = PHPExcel_Cell::columnIndexFromString($sheet->getHighestColumn());
        for ($row = 6; $row <= $highestRow; $row++){ 
        	$invoice_num = $sheet->getCellByColumnAndRow(1, $row)->getFormattedValue();
        	if($invoice_num != "") {
				if(!isset($data[$invoice_num]))
				{
					$data[$invoice_num] = array();
					$data[$invoice_num]['ref'] = $invoice_num;
					$data[$invoice_num]['date'] = $sheet->getCellByColumnAndRow(0, $row)->getFormattedValue();
					$data[$invoice_num]['month'] = substr($sheet->getCellByColumnAndRow(0, $row)->getFormattedValue(),5,2);
					$data[$invoice_num]['year'] = intval(substr($sheet->getCellByColumnAndRow(0, $row)->getFormattedValue(),0,4));
					$data[$invoice_num]['site'] = str_replace(' ', '', $sheet->getCellByColumnAndRow(2, $row)->getFormattedValue());
					$data[$invoice_num]['stock'] = $sheet->getCellByColumnAndRow(3, $row)->getFormattedValue();
					$data[$invoice_num]['cashier'] = $sheet->getCellByColumnAndRow(11, $row)->getFormattedValue();
					$data[$invoice_num]['advisor'] = $sheet->getCellByColumnAndRow(12, $row)->getFormattedValue();
					$data[$invoice_num]['location'] = $sheet->getCellByColumnAndRow(13, $row)->getFormattedValue();
					$data[$invoice_num]['allocation'] = $sheet->getCellByColumnAndRow(14, $row)->getFormattedValue();
					$data[$invoice_num]['quantity'] = 1;
					$data[$invoice_num]['total'] = 0;
					$data[$invoice_num]['owed'] = 0;
					$data[$invoice_num]['parent_id'] = $sites[$data[$invoice_num]['site']];
					$data[$invoice_num]['parent_type'] = "supply-point";
					if(substr($invoice_num,0,2) == "ST") {
						$data[$invoice_num]['name'] = "Stock Invoice";
						$data[$invoice_num]['type'] = "C1";
					}
					else if(substr($invoice_num,0,2) == "AF"){
						$data[$invoice_num]['name'] = "Assistant fee";
						$data[$invoice_num]['type'] = "T1";
					}
					else if(substr($invoice_num,0,2) == "Te"){
						$data[$invoice_num]['name'] = "Technical service";
						$data[$invoice_num]['type'] = "Tech";
					}
					else{
						$data[$invoice_num]['name'] = $data[$invoice_num]['stock'];
						$data[$invoice_num]['type'] = $data[$invoice_num]['stock'];
					}
				}		
				$data[$invoice_num]['lines'][] = array(
					'code' => $sheet->getCellByColumnAndRow(4, $row)->getFormattedValue(),
					'name' => $sheet->getCellByColumnAndRow(5, $row)->getFormattedValue(),
					'quantity' => $sheet->getCellByColumnAndRow(6, $row)->getFormattedValue(),
					'unit' => $sheet->getCellByColumnAndRow(7, $row)->getFormattedValue(),
					'unit_price' => $sheet->getCellByColumnAndRow(8, $row)->getFormattedValue(),
					'total' => $sheet->getCellByColumnAndRow(9, $row)->getFormattedValue()
				);
				$data[$invoice_num]['total'] += $sheet->getCellByColumnAndRow(9, $row)->getCalculatedValue();		
        	}
        }
        return $data;
	}

	function getRefPrefix($string)
	{
		return substr($string, 2,2).substr($string, 5,2);
	}

	function printCC($invoices, $balances, $site, $platform, $debts, $sites){
		foreach ($balances as $balance) {
			if($balance['balance'] < 0){
				$invoice_num = "debts-".date('m-Y')."-".$balance['site'];
				$invoices[$invoice_num] = array();
				$invoices[$invoice_num]['ref'] = $invoice_num;
				$invoices[$invoice_num]['year'] = date('Y');
				$invoices[$invoice_num]['month'] = date('m');
				$invoices[$invoice_num]['site'] = str_replace(' ', '', $balance['site']);
				$invoices[$invoice_num]['stock'] = "debts";
				$invoices[$invoice_num]['quantity'] = 1;
				$invoices[$invoice_num]['total'] = -1*$balance['balance'];
				$invoices[$invoice_num]['owed'] = 0;
				$invoices[$invoice_num]['parent_id'] = $sites[$balance['site']];
				$invoices[$invoice_num]['parent_type'] = "supply-point";
				$invoices[$invoice_num]['name'] = "debts";
				$invoices[$invoice_num]['type'] = "debts";
			}
		}
		$supply_points = array();
		foreach ($balances as $balance) {
			if(isset($sites[$balance['site']]))
				$supply_points[] = array(
					'id' => $sites[$balance['site']],
					'site_name' => $balance['site'],
					'balance' => $balance['balance'],
					'inverted_balance' => -1*$balance['balance']
				);
		}
		include_once './opentbs/tbs_class.php';
		include_once './opentbs/plugins/tbs_plugin_opentbs.php';
		$TBS = new clsTinyButStrong;
	    $TBS->OtbsMsExcelExplicitRef = false;
	    $TBS->Plugin(TBS_INSTALL, OPENTBS_PLUGIN);
	    $TBS->LoadTemplate("invoice_printer_template.xlsx", "UTF-8");
	    $TBS->PlugIn(OPENTBS_SELECT_SHEET, 1);
	    $TBS->MergeBlock('data', $invoices);
	    $TBS->PlugIn(OPENTBS_SELECT_SHEET, 2);
	    $TBS->MergeBlock('sp', $supply_points);
	    $TBS->Show(OPENTBS_DOWNLOAD, "commcare_import_".date('Y-m-d').".xlsx");		
	}


	function printPaperInvoices($invoices, $site, $platform){
		htmlHeaders();
		foreach ($invoices as $invoice) {
			if($site == "all" || $site == $invoice['site']){
				if($platform == "all" || $platform == $invoice['cashier']){
					printInvoice($invoice);
				}
			}
		}		
	}
	function printPaperDelivery($invoices, $site, $platform){
		htmlHeaders();
		foreach ($invoices as $invoice) {
			if($site == "all" || $site == $invoice['site']){
				if($platform == "all" || $platform == $invoice['cashier']){
					printDeliveryNote($invoice);
				}
			}
		}		
	}


	function htmlHeaders(){
		echo "<!DOCTYPE html>";
		echo "<html moznomarginboxes mozdisallowselectionprint>";
		echo "  <HEAD>";
		echo "  	<meta http-equiv=\"Content-Type\" content=\"text/html;charset=UTF-8\">";
		echo "		<TITLE>Invoice printer</TITLE>";
		echo "		<link rel=\"stylesheet\" href=\"css/invoice_printer.css\"></script>";
		echo "  </HEAD>";
		echo "  <BODY>";
	}

	function printPaperSavings($invoices, $site, $platform){
		htmlHeaders();
		foreach ($invoices as $invoice) {
			if($site == "all" || $site == $invoice['site']){
				if($platform == "all" || $platform == $invoice['location']){
					printSaving($invoice);
				}
			}
		}		
	}

	function printInvoice($invoice)
	{
    	for($i = 0; $i < 2; $i++){
        	echo "<div class='invoice'>";
        	generateHeader();        	
        	echo "<table id='invoice_header'><colgroup><col style='width:25%'><col style='width:25%'><col style='width:20%'></colgroup><tr><td>កាលបរិច្ឆេទ/ Date</td><td>".$invoice['date']."</td><td>លេខ / No</td><td>".$invoice['ref']."</td></tr>";
        	echo "<tr><td>ជូន / To</td><td>".$invoice['site']."</td><td>Advisor</td><td>".$invoice['advisor']."</td></tr>";
        	echo "<tr><td>Platform</td><td>".$invoice['location']."</td><td>Cashier</td><td>".$invoice['cashier']."</td></tr></table>";
        	echo "<h2>វិក័យប័ត្រ / INVOICE</h2>";
        	echo "<table id='lines'>";
			echo "<colgroup><col style='width:10%'><col style='width:20%'><col style='width:40%'></colgroup>";
        	echo "<tr><th>ល.រ</th><th>កូដទំនិញ</th><th>បរិយាយ</th><th>បរិមាណ</th><th>តម្លៃឯកតា</th><th>ទឹកប្រាក់</th></tr>";
        	echo "<tr><th>No</th><th>Item No</th><th>Description</th><th>Quantity</th><th>Unit price</th><th>Total</th></tr>";
        	$j = 1;
        	foreach ($invoice['lines'] as $line) {
        		echo "<tr><td>".$j."</td><td>".$line['code']."</td><td>".$line['name']."</td><td>".$line['quantity']."</td><td>".$line['unit_price']."</td><td>".$line['total']."</td></tr>";
        		$j++;
        	}
        	number_format("1000000",2,",",".");
        	echo "<tr><th class='total' colspan='5'>សរុបទឹកប្រាក់ / Amount Due</th><th>".number_format($invoice['total'],0,".",",")."</td></tr>";
        	echo "</table>";
        	generateFooter();
        	if($i == 0)
        		echo "</div><div class='invoice-separator'>.</div>";
        	echo "</div>";
    	}
    	echo "<div class='page-break'></div>";
	}

	function printDeliveryNote($invoice)
	{
    	for($i = 0; $i < 2; $i++){
        	echo "<div class='invoice'>";
        	generateHeader();        	
        	echo "<table id='invoice_header'><colgroup><col style='width:25%'><col style='width:25%'><col style='width:20%'></colgroup><tr><td>កាលបរិច្ឆេទ/ Date</td><td>".$invoice['date']."</td><td>លេខ / No</td><td>".$invoice['ref']."</td></tr>";
        	echo "<tr><td>ជូន / To</td><td>".$invoice['site']."</td><td>Advisor</td><td>".$invoice['advisor']."</td></tr>";
        	echo "<tr><td>Platform</td><td>".$invoice['location']."</td><td>Cashier</td><td>".$invoice['cashier']."</td></tr></table>";
        	echo "<h2>ប័ណ្ណប្រគល់ទំនិញ / Delivery Note</h2>";
        	echo "<table id='lines'>";
			echo "<colgroup><col style='width:10%'><col style='width:20%'><col style='width:40%'></colgroup>";
        	echo "<tr><th>ល.រ</th><th>កូដទំនិញ</th><th>បរិយាយ</th><th>បរិមាណ</th><th>តម្លៃឯកតា</th></tr>";
        	echo "<tr><th>No</th><th>Item No</th><th>Description</th><th>Quantity</th><th>Unit</th></tr>";
        	$j = 1;
        	foreach ($invoice['lines'] as $line) {
        		
        		echo "<tr><td>".$j."</td><td>".$line['code']."</td><td>".$line['name']."</td><td>".$line['quantity']."</td><td>".$line['unit']."</td></tr>";
        		$j++;
        	}
        	number_format("1000000",2,",",".");
        	//echo "<tr><th class='total' colspan='5'>សរុបទឹកប្រាក់ / Amount Due</th><th>".number_format($invoice['total'],0,".",",")."</td></tr>";
        	echo "</table>";
        	generateFooterDelevery();
        	if($i == 0)
        		echo "</div><div class='invoice-separator'>.</div>";
        	echo "</div>";
    	}
    	echo "<div class='page-break'></div>";
	}

	function printSaving($invoice)
	{
    	for($i = 0; $i < 2; $i++){
        	echo "<div class='invoice'>";
        	generateHeader();        	
        	echo "<table id='invoice_header'><colgroup><col style='width:25%'><col style='width:25%'><col style='width:20%'></colgroup><tr><td>កាលបរិច្ឆេទ/ Date</td><td>".$invoice['date']."</td><td>លេខ / No</td><td>".$invoice['ref']."</td></tr>";
        	echo "<tr><td>ជូន / To</td><td>".$invoice['site']."</td><td>Advisor</td><td>".$invoice['advisor']."</td></tr>";
        	echo "<tr><td>Platform</td><td>".$invoice['location']."</td><td>Cashier</td><td>".$invoice['cashier']."</td></tr></table>";
        	echo "<h2>សន្សំ / Saving</h2>";
        	echo "<table id='lines'>";
			echo "<colgroup><col style='width:10%'><col style='width:20%'><col style='width:40%'></colgroup>";
        	echo "<tr><th>ល.រ</th><th>កូដទំនិញ</th><th>បរិយាយ</th><th>បរិមាណ</th><th>តម្លៃឯកតា</th><th>ទឹកប្រាក់</th></tr>";
        	echo "<tr><th>No</th><th>Item No</th><th>Description</th><th>Quantity</th><th>Unit price</th><th>Total</th></tr>";
        	$j = 1;
        	foreach ($invoice['lines'] as $line) {
        		echo "<tr><td>".$j."</td><td>".$line['code']."</td><td>".$line['name']."</td><td>".$line['quantity']."</td><td>".$line['unit_price']."</td><td>".$line['total']."</td></tr>";
        		$j++;
        	}
        	number_format("1000000",2,",",".");
        	echo "<tr><th class='total' colspan='5'>សរុបទឹកប្រាក់ / Amount Due</th><th>".number_format($invoice['total'],0,".",",")."</td></tr>";
        	echo "</table>";
        	generateFooter();
        	if($i == 0)
        		echo "</div><div class='invoice-separator'>.</div>";
        	echo "</div>";
    	}
    	echo "<div class='page-break'></div>";
	}

	function generateHeader(){
		echo "<H3><img src='logo.png'/ style='vertical-align:middle'> អង្គការទឹកស្អាត១០០១</H3>";
		echo "<table id='header'><tr><th>ការិយាល័យភ្នំពេញ</th><th>ការិយាល័យបាត់ដំបង</th></tr>";
		echo "<tr><td>ផ្ទះ៣បេ ផ្លូវ ៤៦៤ សង្កាត់ ទួលទំពូង២ ខណ្ឌ ចំការមន  ភ្នំពេញ ទូរសព្ទទំនាក់ ០២៣ ២១៥ ៤២៧ ។</td><td>ផ្ទះ០៨ ក្រុមទី១  ភូមិ នំគ្រាប សង្កាត់ ព្រែកព្រះស្តេច ក្រុង បាត់ដំបង ខេត្ត បាតដំបង ទូរសព្ទទំនាក់ ០៥៣ ៩៥៣ ១៦១ ។</td></tr>";
		echo "</table>";
	}

	function generateFooter(){
		echo "<table id='footer'>";
		echo "<colgroup><col style='width:33%'><col style='width:33%'><col style='width:33%'></colgroup>";
		echo "<tr><td>អ្នកទិញ  / BUYER</td><td>អ្នកលក់/ SELLER</td><td>បុគ្គលិកទឹកស្អាត១០០១</td></tr>";
		echo "</table>";
	}

	function generateFooterDelevery(){
		echo "<table id='footer'>";
		echo "<colgroup><col style='width:33%'><col style='width:33%'><col style='width:33%'></colgroup>";
		echo "<tr><td>អ្នករៀបចំ / Prepare By</td><td>អ្នកចែកចាយ/ Delivery By</td><td>អ្នកទទួល/ Receiver</td></tr>";
		echo "</table>";
	}

	function htmlClosers(){
		echo "</div></BODY></HTML>";
	}
?>     	
