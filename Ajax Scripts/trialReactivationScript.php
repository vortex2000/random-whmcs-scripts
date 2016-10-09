<?php
/**
 * Free Trial Re-Activate Script
 *
 * This file will attempt to UnSuspend Free Trial accounts using
 * the WHMCS internal API.
 * Reactivations will be denied after X attempts.
 * This file needs to be placed in the public_html directory of WHMCS
 *
 * Created By: Jeremy Maness
  */
/**
 * Accompanying Database Table Schema
 *
 * CREATE TABLE `trial_reactivations` (
`service_id` int(11) DEFAULT NULL,
`attempts` int(11) DEFAULT NULL,
`last_update` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=latin1
 */

// Include WHMCS Init File
require('init.php');

// Set Variables
$debug = false;
$sendErrorEmail = false;
$trialIds = [
    1,  // Trial Product 1 - 1 Day Trial
    2, // Trial Product 2 - 7 Day Trial
    3  // Trial Product 3 - 15 Day Trial
];
$attemptLimit = 5;
$accountID = $_POST['sid'];
$apiCommand = "moduleunsuspend";
$apiUser = "whmcs";

// Establish Database Connection
$WHMCS = DB::setup($db_host, $db_username, $db_password, $db_name);

// Perform Checks
if (is_numeric($accountID)) {
    // Fetch Service Information From DB
    $results = $WHMCS->fetchOneRow("
        SELECT h.packageid, h.domainstatus, tr.attempts
        FROM tblhosting AS h
        LEFT JOIN trial_reactivations AS tr ON h.id = tr.service_id
        WHERE h.id = ? AND domainstatus = 'Suspended'", [$accountID]
    );
    if ($WHMCS->affectedrows) {
        // Check if Product is a Trial
        if (in_array($results['packageid'], $trialIds)) {
            // Check Attempts
            if ($results['attempts'] > $attemptLimit) {
                echo json_encode(['success' => false, 'response' => 'Maximum Re-Activation Attempts Exceeded.']);
            } else {
                // Attempt UnSuspension
                $values["accountid"] = $accountID;
                $apiResults = localAPI($apiCommand,$values,$apiUser);

                // Return Status and Redirect
                if ($apiResults['result'] == 'success') {
                    // Add Re-Activation Attempt
                    $newAttempts = $results['attempts'] + 1;
                    if ($results['attempts'] == NULL || $results['attempts'] == 0) {
                        $WHMCS->execute("INSERT INTO trial_reactivations (service_id, attempts) VALUES (?, ?)", [$accountID, $newAttempts]);
                    } else {
                        $WHMCS->execute("UPDATE trial_reactivations SET service_id = ?, attempts = ?", [$accountID, $newAttempts]);
                    }
                    echo json_encode(['success' => true, 'response' => 'Trial Account Unsuspended Successfully.']);
                } else {
                    echo json_encode(['success' => false, 'response' => $apiResults['message']]);
                }
            }
        } else {
            echo json_encode(['success' => false, 'response' => 'Product is not a Trial.']);
        }
    } else {
        // Database Results Empty
        echo json_encode(['success' => false, 'response' => 'There were errors while retrieving product information from the database.']);
    }
} else {
    // Service ID is Not Numeric
    echo json_encode(['success' => false, 'response' => 'Product ID is not numeric.']);
}
?>