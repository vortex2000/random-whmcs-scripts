<?php
/**
 * Trial Email Script
 *
 * This Hook Script will send specific emails for trial products at pre-determined times.
 * 
 * Created By: Jeremy Maness
*/

// Includes
require('../init.php');

// Set Variables
$debug = false;
$apiCommand = "sendemail";
$apiUser = "whmcs";
$trialIds = [
    1 => [ // Trial Product 1 - 1 Day Trial
        'first' => 'Trial 1 - Trial First Notice',
        'second' => 'Trial 1 - Trial Second Notice',
        'followup' => 'Trial 1 - Trial Follow Up'
    ],
    2 => [ // Trial Product 2 - 7 Day Trial
        'first' => 'Trial 2 - Trial First Notice',
        'second' => 'Trial 2 - Trial Second Notice',
        'followup' => 'Trial 2 - Trial Follow Up'
    ],
    3 => [ // Trial Product 3 - 15 Day Trial
        'first' => 'Trial 3 - Trial First Notice',
        'second' => 'Trial 3 - Trial Second Notice',
        'followup' => 'Trial 3 - Trial Follow Up'
    ],
];

// Establish Database Connection
$WHMCS = DB::setup($db_host, $db_username, $db_password, $db_name);

// Pull List of Trial Accounts
$accounts = $WHMCS->execute("SELECT * FROM tblhosting WHERE packageid IN ('1', '2', '3') AND domainstatus IN ('Active', 'Suspended')");
foreach ($accounts as $acct) {
    if ($debug == true) { echo "Client ID: {$acct['userid']}\nService: {$acct['id']}\nProduct: {$acct['packageid']}\n"; }
    // Set API Params
    $values["id"] = $acct['userid'];
    $values['messagename'] = $apiResults = '';

    // Start DateTime (2 days, 6 day, 10 days = 2, 4, 4)
    $today = date('Y-m-d');
    $createDate = $acct['regdate']; // Product Registration Date
    $endDate = new DateTime($createDate);
    $firstDate = $endDate->add(new DateInterval('P2D'))->format('Y-m-d');
    $secondDate = $endDate->add(new DateInterval('P4D'))->format('Y-m-d');
    $followupDate = $endDate->add(new DateInterval('P4D'))->format('Y-m-d');
    if ($debug == true) {
        echo "First Notice Date: {$firstDate}\nSecond Notice Date: {$secondDate}\nFollow Up Notice Date: {$followupDate}\n";
    }

    // Active Accounts
    if ($acct['domainstatus'] == 'Active') {
        // First Trial Notice
        if (strtotime($firstDate) == strtotime($today)) {
            if ($debug == true) { echo "Sending First Notice\n"; }
            $values["messagename"] = $trialIds[$acct['packageid']]['first'];
        }

        // Second Trial Notice
        if (strtotime($secondDate) == strtotime($today)) {
            if ($debug == true) { echo "Sending Second Notice\n"; }
            $values["messagename"] = $trialIds[$acct['packageid']]['second'];
        }
    }

    // Suspended Accounts
    if ($acct['domainstatus'] == 'Suspended') {
        // Final Trial Notice
        if (strtotime($followupDate) == strtotime($today)) {
            if ($debug == true) { echo "Sending Follow Up Notice\n"; }
            $values["messagename"] = $trialIds[$acct['packageid']]['followup'];
        }
    }

    // Perform API Call
    if ($values['messagename'] != '') {
        $apiResults = localAPI($apiCommand,$values,$apiUser);
    }

    if ($debug == true) {
        echo "API Values:\n  ID -> {$values['id']}\n  Email -> {$values['messagename']}\n";
        echo "API Results:\n  Result -> {$apiResults['result']}\n  Message -> {$apiResults['message']}\n";
        echo "===============\n\n";
    }
}

?>