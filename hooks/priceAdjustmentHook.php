<?php
/**
 * Price Adjustment Hook
 *
 * This Hook Script will check to see if the customer has an active product and if so
 * will set the secondary product recurring price accordingly.
 *
 * Created By: Jeremy Maness
 *
*/

add_hook('PreCronJob', 2, function($vars) {
    // Establish Database Connection
    global $db_host, $db_username, $db_password, $db_name;
    $WHMCS = DB::setup($db_host, $db_username, $db_password, $db_name);

    $debug = false;
    $secondaryProductPrice = '34.95';
    $secondaryProductIds = [
        34, // Secondary Product ID
    ];
    $primaryProductIds = [
        12, // Product 1
        13, // Product 2
        14, // Product 3
    ];

    // Get List of All Active Secondary Product Accounts
    $activeSecondaryProds = $WHMCS->execute("
        SELECT h.id AS hostingId, h.userid, h.packageid, h.domain, h.domainstatus, h.amount, h.nextduedate, h.billingcycle, p.id AS productId, p.name FROM tblhosting AS h
        INNER JOIN tblproducts AS p ON h.packageid = p.id
        WHERE p.id IN (".implode(',', $secondaryProductIds).") AND h.domainstatus = 'Active'");

    // Loop Secondary Products Array and Check For Active Primary Product Accounts
    foreach ($activeSecondaryProds as $secProd) {
        // Start Debug Variables Array
        $debugVars[$secProd['hostingId']]['secProd'] = $secProd;
        $activeCheck = $WHMCS->execute("
            SELECT h.id AS hostingId, h.userid, h.packageid, h.domain, h.domainstatus, p.id AS productId, p.name, pr.* FROM tblhosting AS h
            INNER JOIN tblproducts AS p ON h.packageid = p.id
            INNER JOIN tblpricing AS pr ON p.id = pr.relid
            WHERE h.userid = ? AND p.id IN (".implode(',', $primaryProductIds).") AND pr.type = 'product' AND h.domainstatus = 'Active'", [$secProd['userid']]);

        // Check Number of Active Primary Product Account
        $activeCount = $WHMCS->affectedrows;
        $debugVars[$secProd['hostingId']]['activePriProdsCount'] = $activeCount;
        $debugVars[$secProd['hostingId']]['activePriProds'] = $activeCheck;
        if ($activeCount > 0) {
            // Has Active Primary Product Account
            if ($secProd['amount'] != '0.00') {
                // If Secondary Product price is set to (34.95), Set price to (0.00)
                $WHMCS->execute("UPDATE tblhosting SET amount = '0.00' WHERE id = ? AND userid = ?", [$secProd['hostingId'], $secProd['userid']]);
            }
            $debugVars[$secProd['hostingId']]['newSecPrice'] = '0.00';
            $debugVars[$secProd['hostingId']]['priceSet'] = 'Free';
        } else {
            // No Primary Product Account Found
            // Check for Free Account & One Time
            if ($secProd['billingcycle'] != 'Free Account' && $secProd['billingcycle'] != 'One Time') {
                if ($secProd['amount'] == '0.00') {
                    // If price is set to (0.00), Set price to (34.95)
                    $WHMCS->execute("UPDATE tblhosting SET amount = ? WHERE id = ? AND userid = ?", [$secondaryProductPrice, $secProd['hostingId'], $secProd['userid']]);
                    $debugVars[$secProd['hostingId']]['newSecPrice'] = $secondaryProductPrice;
                    $debugVars[$secProd['hostingId']]['priceSet'] = 'Paid';
                }

            }
        }
    }

    // Debug Log File
    if ($debug == true) {
        print_r($debugVars);
    }

});
?>