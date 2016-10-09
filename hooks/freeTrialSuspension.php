<?php
/**
 * Free Trial Suspension Hook
 *
 * This Hook Script will check to see if the service is for a trial product.
 * If a trial product is found, it will attempt to Suspend the service instead of Terminating it.
 * It will also send out an "End of Trial" email if available.
 *
 * Created By: Jeremy Maness
*/

add_hook('PreModuleTerminate', 1, function($vars) {
    $debug = false;
    $trialIds = [
        1, // Trial Product 1 - 1 Day Trial
        2  // Trial Product 2 - 7 Day Trial
    ];

    if (in_array($vars['params']['packageid'], $trialIds)) {
        // Run Module Suspend Command
        $command = "modulesuspend";
        $adminusername = 'whmcs';
        $values["accountid"] = $vars['params']['serviceid'];
        $values["suspendreason"] = "End of Trial";

        $results = localAPI($command, $values, $adminusername);

        // Log Results
        if ($results['message'] != '') { $resultMessage = "-> {$results['message']}"; } else { $resultMessage = ''; }
        localAPI('logactivity', array('description' => "Attempting to Suspend Trial Product: Service ({$vars['params']['serviceid']}), Product ({$vars['params']['packageid']}) - Result: {$results['result']} {$resultMessage}"), $adminusername);

        // Debug Log File
        if ($debug == true) {
            @file_put_contents('/home/user/public_html/crons/debug.log', var_export(['Trial Suspensions', $vars, $results], true), FILE_APPEND);
        }

        return ["abortcmd" => true];
    }
});

add_hook('AfterModuleSuspend', 1, function($vars) {
    // Establish Database Connection
    global $db_host, $db_username, $db_password, $db_name;
    $WHMCS = DB::setup($db_host, $db_username, $db_password, $db_name);

    $debug = false;
    $trialEndEmail = 'Trial Product - Trial Ended';
    $trialIds = [
        1, // Trial Product 1 - 1 Day Trial
        2, // Trial Product 2 - 7 Day Trial
        3  // Trial Product 3 - 15 Day Trial
    ];

    if (in_array($vars['params']['packageid'], $trialIds)) {
        // Set API Params
        $command = "sendemail";
        $adminusername = 'whmcs';
        $values["id"] = $vars['params']['userid'];

        // Get Product Details
        $details = $WHMCS->fetchOneRow("SELECT * FROM tblhosting WHERE id = ?", [$vars['params']['serviceid']]);

        // Start DateTime
        $today = date('Y-m-d');
        $createDate = $details['regdate']; // Product Registration Date
        $endDate = new DateTime($createDate);
        
        // Check Package ID
        if ($vars['params']['packageid'] == 1) { 
            // MyPowerConference - Set End Date & Email Template
            $endDate = $endDate->add(new DateInterval('P16D'))->format('Y-m-d');
            $values['messagename'] = $mconfTrialEndEmail;
        } else { 
            // MyPowerOffice - Set End Date & Email Template
            $endDate = $endDate->add(new DateInterval('P8D'))->format('Y-m-d');
            $values['messagename'] = $mpopTrialEndEmail;
        }

        // Check Dates & Send API Calls
        if (strtotime($endDate) == strtotime($today) && $values['messagename'] != '') {
            $results = localAPI($command, $values, $adminusername);

            // Log Results
            localAPI('logactivity', array('description' => "Sent End of Trial Email For Service ({$vars['params']['serviceid']})"), $adminusername);
        }

        // Debug Log File
        if ($debug == true) {
            @file_put_contents('/home/user/public_html/crons/debug.log', var_export(['End of Trial Email', $vars, $results], true), FILE_APPEND);
        }
    }
});
?>
